<?php
declare(strict_types=1);

use Tests\TestCase;

/** Montagem de links (inclui o caso sem APP_URL, usado no servidor sem configuração). */
final class HelpersTest extends TestCase
{
    private array $server;

    public function setUp(): void
    {
        $this->server = $_SERVER;
        $_SERVER['HTTP_HOST'] = 'localhost';
        putenv('APP_URL=');
    }

    public function tearDown(): void
    {
        $_SERVER = $this->server;
        putenv('APP_URL=http://127.0.0.1:8099');
        parent::tearDown();
    }

    public function testUrlKeepsQueryStringAndFragment(): void
    {
        $this->assertTrue(str_ends_with(url('/?ref=ABC123'), '/?ref=ABC123'), url('/?ref=ABC123'));
        $this->assertTrue(str_ends_with(url('/agendar?origem=instagram-bio'), '/agendar?origem=instagram-bio'));
        $this->assertTrue(str_ends_with(url('/admin/mensagens#modelos'), '/admin/mensagens#modelos'));
        $this->assertTrue(str_ends_with(url('/agendar', ['servico' => 3]), '/agendar?servico=3'));
    }

    public function testAbsoluteUrlWithoutAppUrl(): void
    {
        $u = absolute_url('/?ref=ABC123');
        $this->assertSame('http://localhost/?ref=ABC123', $u);
    }

    public function testMoneyAndPhoneFormatting(): void
    {
        $this->assertSame('R$ 1.234,56', money(123456));
        $this->assertSame(123456, parse_money('1.234,56'));
        $this->assertSame(15050, parse_money('150.5'));
        $this->assertSame(null, parse_money('-3'));
        $this->assertSame('(11) 98765-4321', phone_br('11987654321'));
    }
}
