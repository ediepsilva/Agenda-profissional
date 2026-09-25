<?php
declare(strict_types=1);

namespace App\Domain;

use App\Core\Db;
use DomainException;

/** Campanhas de oferta: separadas dos avisos da reserva e SÓ para clientes com consentimento. */
final class CampaignService
{
    public const AUDIENCES = [
        'all' => 'Todas as clientes que aceitaram ofertas',
        'source' => 'Por origem (como conheceu)',
        'inactive' => 'Sem atendimento há X dias (reativação)',
        'birthday_month' => 'Aniversariantes do mês',
    ];

    /** @return array<int,array{id:int,name:string,phone:string}> */
    public function recipients(string $audience, ?string $param): array
    {
        $base = 'SELECT c.id, c.name, c.phone FROM clients c WHERE c.marketing_opt_in = 1';
        return match ($audience) {
            'all' => Db::all("$base ORDER BY c.name"),
            'source' => Db::all("$base AND c.source = ? ORDER BY c.name", [(string) $param]),
            'inactive' => Db::all(
                "$base AND EXISTS (SELECT 1 FROM bookings b WHERE b.client_id = c.id AND b.status = 'completed')
                 AND NOT EXISTS (SELECT 1 FROM bookings b WHERE b.client_id = c.id AND b.status IN ('completed','confirmed','awaiting_deposit','requested') AND b.starts_at >= ?)
                 ORDER BY c.name",
                [Clock::now()->modify('-' . max(1, (int) $param) . ' days')->format('Y-m-d H:i:s')]
            ),
            'birthday_month' => Db::all("$base AND MONTH(c.birth_date) = ? ORDER BY DAY(c.birth_date), c.name", [max(1, min(12, (int) $param))]),
            default => [],
        };
    }

    /** @throws ValidationException */
    public function create(array $in, ?int $userId): int
    {
        $errors = [];
        $name = trim((string) ($in['name'] ?? ''));
        if (mb_strlen($name) < 3 || mb_strlen($name) > 120) {
            $errors['name'] = 'Dê um nome à campanha.';
        }
        $tpl = Db::one("SELECT * FROM message_templates WHERE template_key = ? AND category = 'marketing' AND active = 1", [(string) ($in['template_key'] ?? '')]);
        if (!$tpl) {
            $errors['template_key'] = 'Escolha um modelo de campanha ativo.';
        }
        $audience = (string) ($in['audience'] ?? '');
        if (!isset(self::AUDIENCES[$audience])) {
            $errors['audience'] = 'Público inválido.';
        }
        $param = trim((string) ($in['audience_param'] ?? ''));
        if ($audience === 'source' && !isset(BookingService::SOURCES[$param])) {
            $errors['audience_param'] = 'Escolha a origem.';
        }
        if ($audience === 'inactive' && ((int) $param < 15 || (int) $param > 1000)) {
            $errors['audience_param'] = 'Informe de 15 a 1000 dias.';
        }
        if ($audience === 'birthday_month' && ((int) $param < 1 || (int) $param > 12)) {
            $errors['audience_param'] = 'Escolha o mês.';
        }
        $offer = trim((string) ($in['offer_text'] ?? ''));
        if (mb_strlen($offer) < 5 || mb_strlen($offer) > 190) {
            $errors['offer_text'] = 'Escreva a oferta (5 a 190 caracteres).';
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        return Db::insert('campaigns', [
            'name' => $name, 'template_key' => $tpl['template_key'], 'audience' => $audience,
            'audience_param' => $param !== '' ? $param : null, 'offer_text' => $offer, 'created_by' => $userId,
        ]);
    }

    /** Enfileira a campanha para o público (com consentimento). @return int mensagens criadas */
    public function queue(int $campaignId): int
    {
        return Db::transaction(function () use ($campaignId) {
            $c = Db::one('SELECT * FROM campaigns WHERE id = ? FOR UPDATE', [$campaignId]);
            if (!$c || $c['status'] !== 'draft') {
                throw new DomainException('Esta campanha já foi enviada.');
            }
            $svc = new MessageService();
            $link = absolute_url('/agendar?origem=campanha-' . $campaignId);
            $n = 0;
            foreach ($this->recipients($c['audience'], $c['audience_param']) as $r) {
                if ($svc->enqueueMarketing((int) $r['id'], $c['template_key'], ['oferta' => $c['offer_text'], 'link' => $link], $campaignId, "c{$campaignId}:{$r['id']}")) {
                    $n++;
                }
            }
            Db::update('campaigns', ['status' => 'queued', 'recipients_count' => $n, 'queued_at' => Clock::now()->format('Y-m-d H:i:s')], 'id = ?', [$campaignId]);
            return $n;
        });
    }

    public function audienceLabel(array $c): string
    {
        $label = self::AUDIENCES[$c['audience']] ?? $c['audience'];
        return match ($c['audience']) {
            'source' => 'Origem: ' . (BookingService::SOURCES[$c['audience_param']] ?? $c['audience_param']),
            'inactive' => 'Sem atendimento há ' . (int) $c['audience_param'] . ' dias',
            'birthday_month' => 'Aniversariantes de ' . month_name((int) $c['audience_param']),
            default => $label,
        };
    }
}
