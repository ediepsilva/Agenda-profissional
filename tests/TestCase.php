<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Db;
use App\Domain\Clock;
use App\Domain\Settings;
use RuntimeException;

final class AssertionFailed extends RuntimeException
{
}

abstract class TestCase
{
    public function setUp(): void
    {
    }

    public function tearDown(): void
    {
        Clock::freeze(null);
        \App\Integrations\Integrations::useHttpClient(null);
        foreach (['WHATSAPP_TOKEN', 'WHATSAPP_PHONE_NUMBER_ID', 'MERCADOPAGO_ACCESS_TOKEN', 'MERCADOPAGO_WEBHOOK_SECRET'] as $k) {
            putenv("$k="); // vazio = não configurado (nunca cai no .env durante os testes)
        }
    }

    protected function assertTrue(mixed $v, string $msg = ''): void
    {
        if ($v !== true) {
            throw new AssertionFailed($msg ?: 'Esperado true, obtido ' . var_export($v, true));
        }
    }

    protected function assertFalse(mixed $v, string $msg = ''): void
    {
        if ($v !== false) {
            throw new AssertionFailed($msg ?: 'Esperado false, obtido ' . var_export($v, true));
        }
    }

    protected function assertSame(mixed $expected, mixed $actual, string $msg = ''): void
    {
        if ($expected !== $actual) {
            throw new AssertionFailed(($msg ? "$msg\n" : '') . 'Esperado ' . var_export($expected, true) . ', obtido ' . var_export($actual, true));
        }
    }

    protected function assertContains(mixed $needle, array $haystack, string $msg = ''): void
    {
        if (!in_array($needle, $haystack, true)) {
            throw new AssertionFailed($msg ?: var_export($needle, true) . ' não está em ' . json_encode($haystack));
        }
    }

    protected function assertNotContains(mixed $needle, array $haystack, string $msg = ''): void
    {
        if (in_array($needle, $haystack, true)) {
            throw new AssertionFailed($msg ?: var_export($needle, true) . ' não deveria estar em ' . json_encode($haystack));
        }
    }

    /** @param class-string<\Throwable> $class */
    protected function assertThrows(string $class, callable $fn, string $msg = ''): \Throwable
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            if ($e instanceof $class) {
                return $e;
            }
            throw new AssertionFailed(($msg ? "$msg\n" : '') . "Esperada $class, obtida " . $e::class . ': ' . $e->getMessage());
        }
        throw new AssertionFailed($msg ?: "Esperada exceção $class, nenhuma lançada");
    }
}

/** Base para testes que usam o banco de teste; zera os dados a cada teste. */
abstract class DbTestCase extends TestCase
{
    public function setUp(): void
    {
        $pdo = Db::pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['messages', 'campaigns', 'reviews', 'payment_intents', 'consent_log', 'booking_payments', 'expenses', 'booking_status_history', 'booking_allocations', 'bookings', 'clients', 'schedule_blocks', 'availability_rules',
            'service_areas', 'professional_services', 'services', 'professionals', 'login_attempts', 'rate_limits', 'users'] as $t) {
            $pdo->exec("DELETE FROM `$t`"); // DELETE é bem mais rápido que TRUNCATE em tabelas pequenas
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        $defaults = ['min_advance_hours' => '24', 'max_advance_days' => '90', 'slot_step_minutes' => '30',
            'buffer_minutes' => '30', 'hold_pending_requests' => '1', 'deposit_percent' => '30', 'cancellation_min_hours' => '48',
            'messaging_enabled' => '1', 'reviews_auto_approve' => '0', 'online_payment_enabled' => '1'];
        foreach ($defaults as $k => $v) {
            Settings::set($k, $v);
        }
        Settings::flush();
    }
}
