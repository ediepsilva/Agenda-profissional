<?php
declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;

/** Relógio substituível nos testes. */
final class Clock
{
    private static ?DateTimeImmutable $fixed = null;

    public static function now(): DateTimeImmutable
    {
        return self::$fixed ?? new DateTimeImmutable('now');
    }

    public static function freeze(?DateTimeImmutable $at): void
    {
        self::$fixed = $at;
    }
}
