<?php
declare(strict_types=1);

namespace App\Domain;

use App\Core\Db;

/** Indicação de clientes: cada cliente tem um código; o link leva à página pública. */
final class ReferralService
{
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; // sem caracteres ambíguos (0/O, 1/I/L)

    public static function newCode(): string
    {
        do {
            $code = '';
            for ($i = 0; $i < 7; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
        } while (Db::value('SELECT id FROM clients WHERE referral_code = ?', [$code]));
        return $code;
    }

    public static function normalizeCode(?string $code): ?string
    {
        $c = strtoupper(trim((string) $code));
        return preg_match('/^[A-Z0-9]{4,12}$/', $c) ? $c : null;
    }

    public static function resolve(?string $code): ?int
    {
        $c = self::normalizeCode($code);
        if (!$c) {
            return null;
        }
        $id = Db::value('SELECT id FROM clients WHERE referral_code = ?', [$c]);
        return $id ? (int) $id : null;
    }

    public static function link(int $clientId): string
    {
        $c = ConsentService::ensureTokens($clientId);
        return absolute_url('/?ref=' . $c['referral_code']);
    }

    /** Ranking de quem mais indicou: clientes indicadas e quantas já foram atendidas. */
    public static function ranking(int $limit = 50): array
    {
        return Db::all(
            "SELECT r.id, r.name, r.phone, r.referral_code,
                    COUNT(DISTINCT c.id) AS referred_clients,
                    COUNT(DISTINCT CASE WHEN b.status = 'completed' THEN c.id END) AS converted
             FROM clients r
             JOIN clients c ON c.referred_by_client_id = r.id
             LEFT JOIN bookings b ON b.client_id = c.id
             GROUP BY r.id, r.name, r.phone, r.referral_code
             ORDER BY converted DESC, referred_clients DESC
             LIMIT " . (int) $limit
        );
    }
}
