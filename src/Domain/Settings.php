<?php
declare(strict_types=1);

namespace App\Domain;

use App\Core\Db;

final class Settings
{
    private static ?array $cache = null;

    /** Chaves numéricas e seus limites aceitos na tela de configurações. */
    public const NUMERIC = [
        'min_advance_hours' => [0, 720],
        'max_advance_days' => [1, 730],
        'slot_step_minutes' => [5, 120],
        'buffer_minutes' => [0, 240],
        'deposit_percent' => [0, 100],
        'cancellation_min_hours' => [0, 720],
        'reschedule_min_hours' => [0, 720],
        'hold_pending_requests' => [0, 1],
    ];

    public const TEXT = [
        'business_name', 'tagline', 'about', 'city', 'studio_address', 'whatsapp', 'instagram',
        'service_area_text', 'deposit_policy', 'cancellation_policy', 'reschedule_policy',
    ];

    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = [];
            foreach (Db::all('SELECT setting_key, setting_value FROM settings') as $row) {
                self::$cache[$row['setting_key']] = $row['setting_value'];
            }
        }
        return self::$cache;
    }

    public static function get(string $key, string $default = ''): string
    {
        return self::all()[$key] ?? $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        $v = self::all()[$key] ?? null;
        return $v === null || $v === '' ? $default : (int) $v;
    }

    public static function set(string $key, string $value): void
    {
        Db::exec(
            'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
            [$key, $value]
        );
        self::$cache = null;
    }

    public static function flush(): void
    {
        self::$cache = null;
    }
}
