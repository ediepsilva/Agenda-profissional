<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Db;
use App\Core\Session;
use Tests\DbTestCase;
use Tests\Fixtures as F;

final class AuthTest extends DbTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        Auth::clearCache();
        F::user('owner', 'dona@teste.com', 'senha-segura-123');
    }

    public function testValidCredentialsLogIn(): void
    {
        $r = Auth::attempt('Dona@Teste.com ', 'senha-segura-123', '10.0.0.1');
        $this->assertTrue($r['ok']);
        $this->assertTrue(Session::get('user_id') > 0);
        $this->assertSame('owner', Auth::user()['role']);
    }

    public function testWrongPasswordFailsWithGenericMessage(): void
    {
        $r = Auth::attempt('dona@teste.com', 'errada', '10.0.0.1');
        $this->assertFalse($r['ok']);
        $r2 = Auth::attempt('naoexiste@teste.com', 'errada', '10.0.0.1');
        $this->assertSame($r['error'], $r2['error'], 'Mensagem não revela se o e-mail existe');
    }

    public function testAccountIsLockedAfterRepeatedFailures(): void
    {
        for ($i = 0; $i < Auth::MAX_FAILS_PER_EMAIL; $i++) {
            Auth::attempt('dona@teste.com', 'errada', '10.0.0.' . $i);
        }
        $r = Auth::attempt('dona@teste.com', 'senha-segura-123', '10.0.0.99');
        $this->assertFalse($r['ok'], 'Mesmo com a senha certa, fica bloqueado temporariamente');
        $this->assertTrue(str_contains($r['error'], 'Muitas tentativas'));
    }

    public function testInactiveUserCannotLogIn(): void
    {
        Db::exec('UPDATE users SET active = 0');
        $this->assertFalse(Auth::attempt('dona@teste.com', 'senha-segura-123', '10.0.0.1')['ok']);
    }

    public function testPasswordIsStoredHashed(): void
    {
        $hash = Db::value('SELECT password_hash FROM users');
        $this->assertFalse(str_contains($hash, 'senha-segura-123'));
        $this->assertTrue(password_verify('senha-segura-123', $hash));
    }
}
