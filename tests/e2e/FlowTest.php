<?php
declare(strict_types=1);

use App\Core\Db;
use Tests\AssertionFailed;
use Tests\DbTestCase;
use Tests\Fixtures as F;

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

final class FlowTest extends DbTestCase
{
    private const PORT = 8099;
    /** @var resource|null */
    private $server = null;
    private string $base;

    public function setUp(): void
    {
        parent::setUp();
        $this->base = 'http://127.0.0.1:' . self::PORT;
        $root = dirname(__DIR__, 2);
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

    private function assertStatus(int $expected, Browser $b, string $what): void
    {
        if ($b->status !== $expected) {
            throw new AssertionFailed("$what: esperado HTTP $expected, obtido {$b->status}. Trecho: " . mb_substr(strip_tags($b->body), 0, 300));
        }
    }

    private function login(string $email, string $password): Browser
    {
        $b = new Browser($this->base);
        $token = $b->csrf('/admin/login');
        $b->post('/admin/login', ['_csrf' => $token, 'email' => $email, 'password' => $password]);
        $this->assertStatus(303, $b, "Login de $email");
        $this->assertSame('/admin', $b->location);
        return $b;
    }

    /** Fluxo pedido: criar serviço → disponibilidade → cliente solicita → dona confirma → horário indisponível. */
    public function testCompleteBookingFlow(): void
    {
        $owner = F::user('owner', 'dona@teste.com', 'senha-segura-123');
        $pro = F::professional($owner, 'Dona');

        $admin = $this->login('dona@teste.com', 'senha-segura-123');

        // 1. Criar serviço pelo painel
        $token = $admin->csrf('/admin/servicos/novo');
        $admin->post('/admin/servicos', [
            '_csrf' => $token, 'name' => 'Maquiagem social', 'category' => 'Social', 'duration_minutes' => '60',
            'price' => '180,00', 'deposit' => '', 'location_mode' => 'both', 'sort_order' => '1',
            'professionals' => [$pro], 'active' => '1',
        ]);
        $this->assertStatus(303, $admin, 'Criar serviço');
        $service = Db::one('SELECT * FROM services');
        $this->assertSame('Maquiagem social', $service['name']);
        $this->assertSame(18000, (int) $service['price_cents']);
        $sid = (int) $service['id'];

        // 2. Configurar disponibilidade: todos os dias 09:00–18:00
        $token = $admin->csrf('/admin/disponibilidade');
        $rules = [];
        for ($wd = 0; $wd <= 6; $wd++) {
            $rules[$wd] = [['start' => '09:00', 'end' => '18:00']];
        }
        $admin->post('/admin/disponibilidade/horarios', ['_csrf' => $token, 'professional_id' => $pro, 'rules' => $rules]);
        $this->assertStatus(303, $admin, 'Salvar disponibilidade');
        $this->assertSame(7, (int) Db::value('SELECT COUNT(*) FROM availability_rules'));

        // 3. Cliente (sem login) consulta datas e horários
        $client = new Browser($this->base);
        $client->get('/');
        $this->assertStatus(200, $client, 'Página pública');
        $this->assertTrue(str_contains($client->body, 'Maquiagem social'), 'Serviço aparece na página pública');

        $month = new DateTimeImmutable('first day of this month');
        $days = $client->json("/api/dias?servico=$sid&mes=" . $month->format('Y-m') . '&local=studio')['days'] ?? [];
        if (!$days) {
            $days = $client->json("/api/dias?servico=$sid&mes=" . $month->modify('+1 month')->format('Y-m') . '&local=studio')['days'] ?? [];
        }
        $this->assertTrue(count($days) > 0, 'Há datas disponíveis');
        $date = $days[0];
        $slots = $client->json("/api/horarios?servico=$sid&data=$date&local=studio")['slots'];
        $this->assertContains('09:00', $slots);
        $time = $slots[0];

        // 4. Cliente solicita o horário
        $token = $client->csrf('/agendar?servico=' . $sid);
        $client->post('/agendar', [
            '_csrf' => $token, 'website' => '', 'service_id' => $sid, 'location_type' => 'studio',
            'date' => $date, 'time' => $time, 'name' => 'Maria Cliente', 'phone' => '(11) 98888-7777',
            'email' => 'maria@exemplo.com', 'source' => 'instagram', 'client_notes' => 'Casamento de amiga', 'privacy' => '1',
        ]);
        $this->assertStatus(303, $client, 'Enviar solicitação');
        $this->assertTrue((bool) preg_match('#/reserva/([a-f0-9]{24})$#', (string) $client->location, $m), 'Redireciona para a página da reserva: ' . $client->location);
        $client->get('/reserva/' . $m[1]);
        $this->assertStatus(200, $client, 'Página da reserva');
        $this->assertTrue(str_contains($client->body, 'Solicitada'));

        $booking = Db::one('SELECT * FROM bookings');
        $this->assertSame('requested', $booking['status']);
        $this->assertSame("$date $time:00", $booking['starts_at']);
        $this->assertSame('instagram', Db::value('SELECT source FROM clients'));

        // 5. Dona vê a solicitação e confirma
        $admin->get('/admin');
        $this->assertTrue(str_contains($admin->body, 'Maria Cliente'), 'Solicitação aparece no painel');
        $token = $admin->csrf('/admin/reservas/' . $booking['id']);
        $admin->post('/admin/reservas/' . $booking['id'] . '/status', ['_csrf' => $token, 'to' => 'confirmed']);
        $this->assertStatus(303, $admin, 'Confirmar reserva');
        $this->assertSame('confirmed', Db::value('SELECT status FROM bookings WHERE id = ?', [$booking['id']]));

        // 6. O horário fica indisponível para outra cliente
        $slotsAfter = $client->json("/api/horarios?servico=$sid&data=$date&local=studio")['slots'];
        $this->assertNotContains($time, $slotsAfter, 'Horário confirmado não é mais oferecido');

        $other = new Browser($this->base);
        $token = $other->csrf('/agendar');
        $other->post('/agendar', [
            '_csrf' => $token, 'service_id' => $sid, 'location_type' => 'studio', 'date' => $date, 'time' => $time,
            'name' => 'Outra Cliente', 'phone' => '11977776666', 'privacy' => '1',
        ]);
        $this->assertStatus(303, $other, 'Tentativa de reservar horário ocupado');
        $this->assertTrue(str_ends_with((string) $other->location, '/agendar'), 'Volta para o formulário');
        $other->get('/agendar');
        $this->assertTrue(str_contains($other->body, 'não está mais disponível'), 'Mostra aviso de horário indisponível');
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM bookings'));

        // 7. Agenda mostra o atendimento nas três visões
        foreach (['dia', 'semana', 'mes'] as $v) {
            $admin->get("/admin/agenda?visao=$v&data=$date");
            $this->assertStatus(200, $admin, "Agenda ($v)");
            $this->assertTrue(str_contains($admin->body, 'Maria Cliente'), "Atendimento aparece na visão $v");
        }
    }

    public function testAllAdminPagesRenderForOwner(): void
    {
        $owner = F::user('owner', 'dona@teste.com', 'senha-segura-123');
        $pro = F::professional($owner);
        $sid = F::service($pro);
        F::availability($pro, 3, [['09:00', '18:00']]);
        $cid = Db::insert('clients', ['name' => 'Cliente X', 'phone' => '11955554444']);
        $admin = $this->login('dona@teste.com', 'senha-segura-123');

        $bid = Db::insert('bookings', [
            'public_code' => str_repeat('a', 24), 'client_id' => $cid, 'service_id' => $sid, 'professional_id' => $pro,
            'starts_at' => '2030-01-09 10:00:00', 'ends_at' => '2030-01-09 11:00:00',
        ]);
        $pages = ['/admin', '/admin/agenda', '/admin/agenda?visao=dia', '/admin/agenda?visao=mes', '/admin/reservas',
            '/admin/reservas?periodo=todas&status=requested&q=Cliente', '/admin/reservas/nova', "/admin/reservas/$bid",
            '/admin/servicos', '/admin/servicos/novo', "/admin/servicos/$sid/editar", '/admin/disponibilidade', '/admin/areas',
            '/admin/clientes', '/admin/clientes?q=119555', '/admin/clientes/nova', "/admin/clientes/$cid", "/admin/clientes/$cid/editar",
            '/admin/configuracoes', "/admin/reservas/horarios?servico=$sid&profissional=$pro&data=2030-01-09&local=studio"];
        foreach ($pages as $p) {
            $admin->get($p);
            $this->assertStatus(200, $admin, $p);
        }
        $this->assertContains('09:00', json_decode($admin->body, true)['slots']);
    }

    public function testSecurityCsrfAuthenticationAndPermissions(): void
    {
        $owner = F::user('owner', 'dona@teste.com', 'senha-segura-123');
        $pro = F::professional($owner);
        $sid = F::service($pro);
        F::user('assistant', 'assistente@teste.com', 'senha-assistente-1');
        $cid = Db::insert('clients', ['name' => 'Cliente Y', 'phone' => '11944443333']);
        $bid = Db::insert('bookings', [
            'public_code' => str_repeat('b', 24), 'client_id' => $cid, 'service_id' => $sid, 'professional_id' => $pro,
            'starts_at' => '2030-01-09 10:00:00', 'ends_at' => '2030-01-09 11:00:00',
        ]);

        // Sem login: painel redireciona para o login
        $anon = new Browser($this->base);
        $anon->get('/admin/reservas');
        $this->assertStatus(303, $anon, 'Painel sem login');
        $this->assertTrue(str_ends_with((string) $anon->location, '/admin/login'));

        // POST sem token CSRF é recusado
        $anon->post('/agendar', ['service_id' => $sid]);
        $this->assertStatus(419, $anon, 'POST sem CSRF');

        // Senha errada não entra
        $bad = new Browser($this->base);
        $token = $bad->csrf('/admin/login');
        $bad->post('/admin/login', ['_csrf' => $token, 'email' => 'dona@teste.com', 'password' => 'errada']);
        $this->assertTrue(str_ends_with((string) $bad->location, '/admin/login'));

        // Assistente: pode ver a agenda, não pode gerenciar
        $asst = $this->login('assistente@teste.com', 'senha-assistente-1');
        $asst->get('/admin/agenda');
        $this->assertStatus(200, $asst, 'Assistente vê a agenda');
        foreach (['/admin/servicos', '/admin/configuracoes', '/admin/clientes', '/admin/reservas/nova'] as $p) {
            $asst->get($p);
            $this->assertStatus(403, $asst, "Assistente em $p");
        }
        $asst->get("/admin/reservas/$bid");
        $this->assertStatus(200, $asst, 'Assistente vê a reserva');
        $this->assertFalse(str_contains($asst->body, 'Confirmar</button>'), 'Botões de ação escondidos');
        preg_match('/name="_csrf" value="([a-f0-9]+)"/', $asst->body, $m);
        // Mesmo forjando o POST, o servidor recusa
        $asst->post("/admin/reservas/$bid/status", ['_csrf' => $m[1], 'to' => 'confirmed']);
        $this->assertStatus(403, $asst, 'Assistente tentando confirmar');
        $this->assertSame('requested', Db::value('SELECT status FROM bookings WHERE id = ?', [$bid]));
        $asst->post('/admin/servicos', ['_csrf' => $m[1], 'name' => 'Hack', 'duration_minutes' => '60', 'price' => '1']);
        $this->assertStatus(403, $asst, 'Assistente tentando criar serviço');
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM services'));

        // Página pública da reserva exige código válido
        $anon->get('/reserva/' . str_repeat('0', 24));
        $this->assertStatus(404, $anon, 'Código de reserva inexistente');

        // Cabeçalhos de segurança
        $ch = curl_init($this->base . '/');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_NOBODY => true]);
        $headers = (string) curl_exec($ch);
        $this->assertTrue(stripos($headers, 'Content-Security-Policy') !== false, 'CSP presente');
        $this->assertTrue(stripos($headers, 'X-Frame-Options: DENY') !== false, 'Anti-clickjacking');
        $this->assertTrue(stripos($headers, 'HttpOnly') !== false, 'Cookie de sessão HttpOnly');
    }
}
