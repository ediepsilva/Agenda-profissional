<?php
declare(strict_types=1);

namespace Tests;

use CurlHandle;

/** Cliente HTTP mínimo com cookies (uma "aba de navegador" por instância). */
final class Browser
{
    private CurlHandle $ch;
    public int $status = 0;
    public string $body = '';
    public ?string $location = null;

    public function __construct(private string $base)
    {
        $this->ch = curl_init();
        curl_setopt_array($this->ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEFILE => '', // cookies em memória
            CURLOPT_TIMEOUT => 30,
        ]);
    }

    public function get(string $path): self
    {
        curl_setopt($this->ch, CURLOPT_HTTPGET, true);
        return $this->send($path);
    }

    public function post(string $path, array $data): self
    {
        curl_setopt($this->ch, CURLOPT_POST, true);
        curl_setopt($this->ch, CURLOPT_POSTFIELDS, http_build_query($data));
        return $this->send($path);
    }

    public function json(string $path): array
    {
        $this->get($path);
        return json_decode($this->body, true) ?? [];
    }

    public function csrf(string $path): string
    {
        $this->get($path);
        if (!preg_match('/name="_csrf" value="([a-f0-9]+)"/', $this->body, $m)) {
            throw new AssertionFailed("Token CSRF não encontrado em $path (HTTP {$this->status})");
        }
        return $m[1];
    }

    private function send(string $path): self
    {
        $this->location = null;
        curl_setopt($this->ch, CURLOPT_URL, $this->base . $path);
        curl_setopt($this->ch, CURLOPT_HEADERFUNCTION, function ($ch, $h) {
            if (stripos($h, 'Location:') === 0) {
                $this->location = trim(substr($h, 9));
            }
            return strlen($h);
        });
        $this->body = (string) curl_exec($this->ch);
        $this->status = (int) curl_getinfo($this->ch, CURLINFO_RESPONSE_CODE);
        return $this;
    }
}

/**
 * Sobe um servidor PHP embutido apontando para o banco de teste durante cada teste ponta a ponta.
 */
abstract class HttpTestCase extends DbTestCase
{
    private const PORT = 8099;
    /** @var resource|null */
    private $server = null;
    protected string $base;

    public function setUp(): void
    {
        parent::setUp();
        $this->startServer();
    }

    private function startServer(): void
    {
        $this->base = 'http://127.0.0.1:' . self::PORT;
        $root = dirname(__DIR__);
        // O servidor herda DB_NAME do banco de teste (definido em tests/run.php).
        $this->server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::PORT, '-t', $root . '/public', $root . '/public/index.php'],
            [1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']],
            $pipes,
            $root
        );
        for ($i = 0; $i < 50; $i++) {
            $sock = @fsockopen('127.0.0.1', self::PORT, $errno, $errstr, 0.2);
            if ($sock) {
                fclose($sock);
                return;
            }
            usleep(100000);
        }
        throw new AssertionFailed('Servidor de teste não iniciou.');
    }

    public function tearDown(): void
    {
        parent::tearDown();
        if ($this->server) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
    }

    protected function assertStatus(int $expected, Browser $b, string $what): void
    {
        if ($b->status !== $expected) {
            throw new AssertionFailed("$what: esperado HTTP $expected, obtido {$b->status}. Trecho: " . mb_substr(strip_tags($b->body), 0, 300));
        }
    }

    protected function login(string $email, string $password): Browser
    {
        $b = new Browser($this->base);
        $token = $b->csrf('/admin/login');
        $b->post('/admin/login', ['_csrf' => $token, 'email' => $email, 'password' => $password]);
        $this->assertStatus(303, $b, "Login de $email");
        $this->assertSame('/admin', $b->location);
        return $b;
    }

}
