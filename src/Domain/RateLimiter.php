<?php
declare(strict_types=1);

namespace App\Domain;

use App\Core\Db;

final class RateLimiter
{
    /** Registra uma tentativa e informa se ainda está dentro do limite. */
    public static function hit(string $bucket, string $ip, int $max, int $windowMinutes): bool
    {
        $since = date('Y-m-d H:i:s', time() - $windowMinutes * 60);
        $count = (int) Db::value('SELECT COUNT(*) FROM rate_limits WHERE bucket = ? AND ip = ? AND created_at >= ?', [$bucket, $ip, $since]);
        if ($count >= $max) {
            return false;
        }
        Db::insert('rate_limits', ['bucket' => $bucket, 'ip' => $ip]);
        if (random_int(1, 50) === 1) {
            Db::exec('DELETE FROM rate_limits WHERE created_at < ?', [date('Y-m-d H:i:s', time() - 86400)]);
        }
        return true;
    }
}
