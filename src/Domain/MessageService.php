<?php
declare(strict_types=1);

namespace App\Domain;

use App\Core\Db;
use App\Integrations\Integrations;
use App\Integrations\WhatsAppWebhook;
use DateTimeImmutable;
use PDOException;

/**
 * Mensagens pelo WhatsApp (API oficial), com fila de saída (outbox):
 * - Avisos da reserva (categoria "transactional"): confirmação, lembrete, orientações,
 *   agradecimento/avaliação, cancelamento. Enfileirados na mesma transação da mudança de
 *   status; nunca duplicados (dedupe_key).
 * - Campanhas (categoria "marketing"): SOMENTE para clientes com consentimento ativo,
 *   conferido ao enfileirar e de novo no momento do envio.
 * O envio real é feito pelo worker (bin/worker.php) ou pelo botão "Processar fila" no painel.
 */
final class MessageService
{
    public const MAX_ATTEMPTS = 3;

    public const STATUS_LABELS = [
        'queued' => 'Na fila', 'sent' => 'Enviada', 'delivered' => 'Entregue', 'read' => 'Lida',
        'failed' => 'Falhou', 'skipped' => 'Não enviada', 'cancelled' => 'Cancelada',
    ];

    /** Avisos agendados em relação ao horário do atendimento. */
    private const BEFORE = ['pre_care', 'reminder'];

    public static function enabled(): bool
    {
        return Settings::int('messaging_enabled', 1) === 1;
    }

    public function template(string $key): ?array
    {
        return Db::one('SELECT * FROM message_templates WHERE template_key = ?', [$key]);
    }

    public static function render(string $text, array $vars): string
    {
        return preg_replace_callback('/\{\{(\w+)\}\}/', static fn ($m) => (string) ($vars[$m[1]] ?? ''), $text);
    }

    /** Variáveis disponíveis nos modelos para uma reserva. */
    public function bookingVars(array $b): array
    {
        $start = new DateTimeImmutable($b['starts_at']);
        $local = $b['location_type'] === 'client'
            ? (string) $b['address']
            : 'Estúdio' . (setting('studio_address') ? ' - ' . setting('studio_address') : '');
        $client = ConsentService::ensureTokens((int) $b['client_id']);
        return [
            'nome' => strtok(trim((string) $b['client_name']), ' ') ?: $b['client_name'],
            'servico' => $b['kind'] === 'event' ? $b['event_name'] : $b['service_name'],
            'data' => $start->format('d/m/Y'),
            'hora' => $start->format('H:i'),
            'local' => $local,
            'profissional' => $b['professional_name'],
            'link' => absolute_url('/reserva/' . $b['public_code']),
            'link_avaliacao' => absolute_url('/avaliar/' . $b['public_code']),
            'link_indicacao' => absolute_url('/?ref=' . $client['referral_code']),
        ];
    }

    /** Enfileira um aviso de reserva. Retorna o id, ou null se desativado/duplicado. */
    public function enqueueForBooking(int $bookingId, string $key, ?DateTimeImmutable $at = null, ?string $dedupe = null): ?int
    {
        if (!self::enabled()) {
            return null;
        }
        $tpl = $this->template($key);
        $b = (new BookingService())->find($bookingId);
        if (!$tpl || !$tpl['active'] || !$b || $tpl['category'] !== 'transactional') {
            return null;
        }
        return $this->insert($tpl, (int) $b['client_id'], $b['client_phone'], $this->bookingVars($b), $at, $dedupe ?? "b{$bookingId}:{$key}", $bookingId, null);
    }

    /** Enfileira uma mensagem de campanha — só com consentimento ativo. */
    public function enqueueMarketing(int $clientId, string $key, array $extraVars, ?int $campaignId, ?string $dedupe = null): ?int
    {
        if (!self::enabled()) {
            return null;
        }
        $tpl = $this->template($key);
        $c = Db::one('SELECT * FROM clients WHERE id = ?', [$clientId]);
        if (!$tpl || !$tpl['active'] || $tpl['category'] !== 'marketing' || !$c || !(int) $c['marketing_opt_in']) {
            return null;
        }
        $vars = ['nome' => strtok(trim($c['name']), ' ') ?: $c['name']] + $extraVars;
        return $this->insert($tpl, $clientId, $c['phone'], $vars, null, $dedupe, null, $campaignId);
    }

    private function insert(array $tpl, int $clientId, string $phone, array $vars, ?DateTimeImmutable $at, ?string $dedupe, ?int $bookingId, ?int $campaignId): ?int
    {
        $order = array_values(array_filter(array_map('trim', explode(',', $tpl['param_order']))));
        $params = array_map(static fn ($k) => (string) ($vars[$k] ?? ''), $order);
        try {
            return Db::insert('messages', [
                'category' => $tpl['category'],
                'template_key' => $tpl['template_key'],
                'client_id' => $clientId,
                'booking_id' => $bookingId,
                'campaign_id' => $campaignId,
                'to_phone' => $phone,
                'params' => json_encode($params, JSON_UNESCAPED_UNICODE),
                'body' => self::render($tpl['preview'], $vars),
                'scheduled_at' => ($at ?? Clock::now())->format('Y-m-d H:i:s'),
                'dedupe_key' => $dedupe,
            ]);
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) { // já enfileirada (dedupe)
                return null;
            }
            throw $e;
        }
    }

    // ------------------------------------------------------------------ gatilhos da reserva

    public function onStatusChanged(int $bookingId, string $from, string $to): void
    {
        if ($to === 'confirmed') {
            $this->enqueueForBooking($bookingId, 'booking_confirmed');
        } elseif ($to === 'cancelled') {
            $this->cancelPending($bookingId);
            // Só avisa cancelamento de reserva que já tinha sido aceita.
            if (in_array($from, ['awaiting_deposit', 'confirmed'], true)) {
                $this->enqueueForBooking($bookingId, 'booking_cancelled');
            }
        } elseif ($to === 'completed') {
            $this->cancelPending($bookingId);
            $tpl = $this->template('thanks_review');
            $this->enqueueForBooking($bookingId, 'thanks_review', Clock::now()->modify('+' . (int) ($tpl['offset_hours'] ?? 0) . ' hours'));
        } elseif ($to === 'no_show') {
            $this->cancelPending($bookingId);
        }
    }

    /** Após reagendar: descarta lembretes do horário antigo (os novos são gerados pelo agendador). */
    public function onRescheduled(int $bookingId): void
    {
        Db::exec(
            "UPDATE messages SET status = 'cancelled', last_error = 'Reserva reagendada' WHERE booking_id = ? AND status = 'queued' AND template_key IN ('reminder','pre_care')",
            [$bookingId]
        );
    }

    private function cancelPending(int $bookingId): void
    {
        Db::exec(
            "UPDATE messages SET status = 'cancelled', last_error = 'Status da reserva mudou' WHERE booking_id = ? AND status = 'queued' AND template_key IN ('reminder','pre_care','booking_confirmed')",
            [$bookingId]
        );
    }

    /**
     * Agendador: cria lembretes e orientações para reservas aceitas cujo momento chegou
     * (horário − antecedência do modelo), até 1 hora antes do atendimento.
     * @return int mensagens criadas
     */
    public function scheduleDue(): int
    {
        if (!self::enabled()) {
            return 0;
        }
        $now = Clock::now();
        $created = 0;
        foreach (self::BEFORE as $key) {
            $tpl = $this->template($key);
            if (!$tpl || !$tpl['active']) {
                continue;
            }
            $hours = max(1, (int) $tpl['offset_hours']);
            $rows = Db::all(
                "SELECT id, starts_at FROM bookings WHERE status IN ('confirmed','awaiting_deposit') AND starts_at > ? AND starts_at <= ?",
                [$now->modify('+1 hour')->format('Y-m-d H:i:s'), $now->modify("+{$hours} hours")->format('Y-m-d H:i:s')]
            );
            foreach ($rows as $r) {
                $dedupe = "b{$r['id']}:{$key}:" . str_replace([' ', ':', '-'], '', $r['starts_at']);
                if ($this->enqueueForBooking((int) $r['id'], $key, $now, $dedupe)) {
                    $created++;
                }
            }
        }
        return $created;
    }

    // ------------------------------------------------------------------ envio

    /** @return array{sent:int, failed:int, skipped:int, retry:int} */
    public function dispatchDue(int $limit = 50): array
    {
        $count = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'retry' => 0];
        $now = Clock::now()->format('Y-m-d H:i:s');
        $provider = Integrations::whatsapp();
        $due = Db::all("SELECT * FROM messages WHERE status = 'queued' AND scheduled_at <= ? ORDER BY scheduled_at, id LIMIT " . (int) $limit, [$now]);

        foreach ($due as $m) {
            // "Reserva" atômica da linha: se outro processo pegou, pula.
            if (Db::exec("UPDATE messages SET attempts = attempts + 1 WHERE id = ? AND status = 'queued' AND attempts = ?", [$m['id'], $m['attempts']]) !== 1) {
                continue;
            }
            $attempts = (int) $m['attempts'] + 1;

            $skip = $this->skipReason($m);
            if ($skip !== null) {
                Db::update('messages', ['status' => 'skipped', 'last_error' => $skip], 'id = ?', [$m['id']]);
                $count['skipped']++;
                continue;
            }

            $tpl = $this->template($m['template_key']);
            $result = $provider->sendTemplate('55' . $m['to_phone'], $tpl['name'], $tpl['language'], json_decode($m['params'], true) ?: [], $m['body']);
            if ($result['ok']) {
                Db::update('messages', [
                    'status' => 'sent', 'provider' => $provider->name(), 'provider_message_id' => $result['id'],
                    'sent_at' => Clock::now()->format('Y-m-d H:i:s'), 'last_error' => null,
                ], 'id = ?', [$m['id']]);
                $count['sent']++;
            } elseif (!empty($result['retryable']) && $attempts < self::MAX_ATTEMPTS) {
                Db::update('messages', [
                    'last_error' => $result['error'] ?? 'erro',
                    'scheduled_at' => Clock::now()->modify('+' . (5 * $attempts) . ' minutes')->format('Y-m-d H:i:s'),
                ], 'id = ?', [$m['id']]);
                $count['retry']++;
            } else {
                Db::update('messages', ['status' => 'failed', 'provider' => $provider->name(), 'last_error' => $result['error'] ?? 'erro'], 'id = ?', [$m['id']]);
                $count['failed']++;
            }
        }
        return $count;
    }

    /** Regras conferidas no momento do envio (a situação pode ter mudado desde o enfileiramento). */
    private function skipReason(array $m): ?string
    {
        if ($m['category'] === 'marketing') {
            $opt = Db::value('SELECT marketing_opt_in FROM clients WHERE id = ?', [$m['client_id']]);
            if (!(int) $opt) {
                return 'Cliente sem consentimento para campanhas';
            }
        }
        if ($m['booking_id']) {
            $status = Db::value('SELECT status FROM bookings WHERE id = ?', [$m['booking_id']]);
            $allowed = match ($m['template_key']) {
                'reminder', 'pre_care', 'booking_confirmed' => ['confirmed', 'awaiting_deposit'],
                'booking_cancelled' => ['cancelled'],
                'thanks_review' => ['completed'],
                default => null,
            };
            if ($allowed !== null && !in_array($status, $allowed, true)) {
                return 'A reserva mudou de status';
            }
        }
        if (!preg_match('/^\d{10,11}$/', (string) $m['to_phone'])) {
            return 'Telefone inválido';
        }
        return null;
    }

    // ------------------------------------------------------------------ retorno do WhatsApp

    public function applyStatus(string $providerId, string $status, ?string $error): void
    {
        $now = Clock::now()->format('Y-m-d H:i:s');
        match ($status) {
            'delivered' => Db::exec("UPDATE messages SET status = 'delivered', delivered_at = ? WHERE provider_message_id = ? AND status IN ('sent')", [$now, $providerId]),
            'read' => Db::exec("UPDATE messages SET status = 'read', read_at = ?, delivered_at = COALESCE(delivered_at, ?) WHERE provider_message_id = ? AND status IN ('sent','delivered')", [$now, $now, $providerId]),
            'failed' => Db::exec("UPDATE messages SET status = 'failed', last_error = ? WHERE provider_message_id = ?", [mb_substr((string) $error, 0, 250), $providerId]),
            default => 0,
        };
    }

    /** Resposta da cliente: "SAIR" revoga o consentimento de campanhas. @return bool se revogou */
    public function handleInbound(string $fromE164, string $text): bool
    {
        if (!WhatsAppWebhook::isOptOut($text)) {
            return false;
        }
        $phone = ClientService::normalizePhone($fromE164);
        $id = Db::value('SELECT id FROM clients WHERE phone = ?', [$phone]);
        return $id ? ConsentService::set((int) $id, false, 'whatsapp', 'Respondeu: ' . mb_substr($text, 0, 60)) : false;
    }
}
