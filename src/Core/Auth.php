<?php
declare(strict_types=1);

namespace App\Core;

use App\Domain\Permissions;

final class Auth
{
    public const MAX_FAILS_PER_EMAIL = 5;
    public const MAX_FAILS_PER_IP = 20;
    public const LOCK_MINUTES = 15;
    private const IDLE_TIMEOUT = 8 * 3600;

    private static ?array $user = null;

    /** @return array{ok:bool,error?:string} */
    public static function attempt(string $email, string $password, string $ip): array
    {
        $email = mb_strtolower(trim($email));
        if (self::isLocked($email, $ip)) {
            return ['ok' => false, 'error' => 'Muitas tentativas. Aguarde ' . self::LOCK_MINUTES . ' minutos e tente novamente.'];
        }

        $user = Db::one('SELECT * FROM users WHERE email = ? AND active = 1', [$email]);
        // password_verify é executado mesmo sem usuário para não revelar e-mails por tempo de resposta.
        $hash = $user['password_hash'] ?? '$2y$10$CvhTalWjZuRSfn1r3KcXo.0h6aJFHxTDkGn7Wnozz8Vtfe.RLmZGW';
        $ok = password_verify($password, $hash) && $user !== null;

        Db::insert('login_attempts', ['email' => $email, 'ip' => $ip, 'success' => $ok ? 1 : 0]);

        if (!$ok) {
            return ['ok' => false, 'error' => 'E-mail ou senha inválidos.'];
        }

        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            Db::update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], 'id = ?', [$user['id']]);
        }
        Db::update('users', ['last_login_at' => date('Y-m-d H:i:s')], 'id = ?', [$user['id']]);

        Session::regenerate();
        Session::set('user_id', (int) $user['id']);
        Session::set('last_seen', time());
        self::$user = null;
        return ['ok' => true];
    }

    public static function isLocked(string $email, string $ip): bool
    {
        $since = date('Y-m-d H:i:s', time() - self::LOCK_MINUTES * 60);
        $byEmail = (int) Db::value('SELECT COUNT(*) FROM login_attempts WHERE email = ? AND success = 0 AND attempted_at >= ?', [$email, $since]);
        $byIp = (int) Db::value('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND success = 0 AND attempted_at >= ?', [$ip, $since]);
        return $byEmail >= self::MAX_FAILS_PER_EMAIL || $byIp >= self::MAX_FAILS_PER_IP;
    }

    public static function user(): ?array
    {
        if (self::$user !== null) {
            return self::$user;
        }
        $id = Session::get('user_id');
        if (!$id) {
            return null;
        }
        if (time() - (int) Session::get('last_seen', 0) > self::IDLE_TIMEOUT) {
            self::logout();
            return null;
        }
        Session::set('last_seen', time());
        $user = Db::one(
            'SELECT u.id, u.name, u.email, u.role, p.id AS professional_id
             FROM users u LEFT JOIN professionals p ON p.user_id = u.id
             WHERE u.id = ? AND u.active = 1',
            [$id]
        );
        if (!$user) {
            self::logout();
            return null;
        }
        return self::$user = $user;
    }

    public static function can(string $permission): bool
    {
        $u = self::user();
        return $u !== null && Permissions::allows($u['role'], $permission);
    }

    /** @param string[] $permissions */
    public static function canAny(array $permissions): bool
    {
        foreach ($permissions as $p) {
            if (self::can($p)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Escopo de profissional para dados "próprios":
     * null = pode ver tudo ($fullPermission); int = só a profissional vinculada (0 se não tiver agenda).
     */
    public static function professionalScope(string $fullPermission): ?int
    {
        if (self::can($fullPermission)) {
            return null;
        }
        return (int) (self::user()['professional_id'] ?? 0);
    }

    public static function logout(): void
    {
        self::$user = null;
        Session::destroy();
    }

    /** Só para testes/CLI. */
    public static function clearCache(): void
    {
        self::$user = null;
    }
}
