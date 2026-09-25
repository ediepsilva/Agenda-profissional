<?php
declare(strict_types=1);

namespace App\Domain;

use App\Core\Db;

/**
 * Consentimento para campanhas (ofertas/promoções). Independente dos avisos da
 * reserva (confirmação, lembrete...), que são parte do serviço contratado.
 * Cada mudança fica registrada em consent_log (quando, por qual canal, por quem).
 */
final class ConsentService
{
    public const CHANNELS = [
        'formulario_publico' => 'Formulário de agendamento',
        'pagina_preferencias' => 'Página de preferências',
        'whatsapp' => 'Resposta no WhatsApp',
        'painel' => 'Registrado no painel',
    ];

    /** @return bool true se houve mudança */
    public static function set(int $clientId, bool $optIn, string $channel, ?string $note = null, ?int $userId = null, ?string $ip = null): bool
    {
        $current = Db::value('SELECT marketing_opt_in FROM clients WHERE id = ?', [$clientId]);
        if ($current === null || (bool) (int) $current === $optIn) {
            return false;
        }
        Db::update('clients', [
            'marketing_opt_in' => $optIn ? 1 : 0,
            'marketing_updated_at' => Clock::now()->format('Y-m-d H:i:s'),
        ], 'id = ?', [$clientId]);
        Db::insert('consent_log', [
            'client_id' => $clientId,
            'purpose' => 'marketing',
            'action' => $optIn ? 'opt_in' : 'opt_out',
            'channel' => $channel,
            'note' => $note !== null ? mb_substr($note, 0, 190) : null,
            'ip' => $ip,
            'created_by' => $userId,
        ]);
        if (!$optIn) {
            // Revogação vale imediatamente: nenhuma oferta pendente é enviada.
            Db::exec("UPDATE messages SET status = 'cancelled', last_error = 'Consentimento revogado' WHERE client_id = ? AND category = 'marketing' AND status = 'queued'", [$clientId]);
        }
        return true;
    }

    /** Garante os códigos da cliente (link de preferências e código de indicação). */
    public static function ensureTokens(int $clientId): array
    {
        $c = Db::one('SELECT id, preferences_token, referral_code FROM clients WHERE id = ?', [$clientId]);
        $data = [];
        if (!$c['preferences_token']) {
            $data['preferences_token'] = bin2hex(random_bytes(16));
        }
        if (!$c['referral_code']) {
            $data['referral_code'] = ReferralService::newCode();
        }
        if ($data) {
            Db::update('clients', $data, 'id = ?', [$clientId]);
            $c = array_merge($c, $data);
        }
        return $c;
    }

    public static function findByToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }
        return Db::one('SELECT * FROM clients WHERE preferences_token = ?', [$token]);
    }

    public static function history(int $clientId): array
    {
        return Db::all('SELECT l.*, u.name AS user_name FROM consent_log l LEFT JOIN users u ON u.id = l.created_by WHERE l.client_id = ? ORDER BY l.id DESC', [$clientId]);
    }
}
