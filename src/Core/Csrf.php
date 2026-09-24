<?php
declare(strict_types=1);

namespace App\Core;

final class Csrf
{
    public static function token(): string
    {
        $t = Session::get('_csrf');
        if (!$t) {
            $t = bin2hex(random_bytes(32));
            Session::set('_csrf', $t);
        }
        return $t;
    }

    public static function valid(?string $token): bool
    {
        $t = Session::get('_csrf');
        return is_string($t) && is_string($token) && hash_equals($t, $token);
    }
}
