<?php
declare(strict_types=1);

use App\Core\Db;
use Tests\Browser;
use Tests\Fixtures as F;
use Tests\HttpTestCase;

/** Fase 2 via HTTP: permissões por papel/escopo, equipe, eventos, pagamentos e relatórios. */
final class TeamFlowTest extends HttpTestCase
{
    private int $ownerPro;
    private int $artistPro;
    private int $svc;
    private int $ownBooking;
    private int $otherBooking;

    public function setUp(): void
    {
        parent::setUp();
        $owner = F::user('owner', 'dona@teste.com', 'senha-segura-123');
        $artist = F::user('artist', 'maqui@teste.com', 'senha-maqui-123');
        F::user('manager', 'gerente@teste.com', 'senha-gerente-123');
        $this->ownerPro = F::professional($owner, 'Dona');
        $this->artistPro = F::professional($artist, 'Maqui');
        Db::update('professionals', ['commission_percent' => 40], 'id = ?', [$this->artistPro]);
        $this->svc = F::service($this->ownerPro);
        Db::insert('professional_services', ['professional_id' => $this->artistPro, 'service_id' => $this->svc]);
        $c1 = Db::insert('clients', ['name' => 'Cliente Da Maqui', 'phone' => '11911110001']);
        $c2 = Db::insert('clients', ['name' => 'Cliente Da Dona', 'phone' => '11911110002']);
        $this->ownBooking = $this->booking($c1, $this->artistPro, '2030-01-09 10:00:00', 'confirmed');
        $this->otherBooking = $this->booking($c2, $this->ownerPro, '2030-01-09 10:00:00', 'confirmed');
    }

    private function booking(int $client, int $pro, string $start, string $status): int
    {
        $end = (new DateTimeImmutable($start))->modify('+60 minutes')->format('Y-m-d H:i:s');
        $id = Db::insert('bookings', [
            'public_code' => bin2hex(random_bytes(12)), 'client_id' => $client, 'service_id' => $this->svc, 'professional_id' => $pro,
            'status' => $status, 'starts_at' => $start, 'ends_at' => $end, 'price_cents' => 20000, 'deposit_cents' => 6000,
        ]);
        Db::insert('booking_allocations', [
            'booking_id' => $id, 'professional_id' => $pro, 'block_start' => $start, 'block_end' => $end,
            'commission_percent' => Db::value('SELECT commission_percent FROM professionals WHERE id = ?', [$pro]),
        ]);
        return $id;
    }

    private function token(Browser $b, string $path): string
    {
        return $b->csrf($path);
    }

    public function testArtistSeesOnlyHerOwnData(): void
    {
        $m = $this->login('maqui@teste.com', 'senha-maqui-123');

        $m->get('/admin/reservas?periodo=todas');
        $this->assertStatus(200, $m, 'Minhas reservas');
        $this->assertTrue(str_contains($m->body, 'Cliente Da Maqui'));
        $this->assertFalse(str_contains($m->body, 'Cliente Da Dona'), 'Não vê reservas de outra profissional');

        $m->get('/admin/agenda?visao=dia&data=2030-01-09&profissional=' . $this->ownerPro);
        $this->assertStatus(200, $m, 'Agenda');
        $this->assertTrue(str_contains($m->body, 'Cliente Da Maqui'));
        $this->assertFalse(str_contains($m->body, 'Cliente Da Dona'), 'Filtro forjado na URL é ignorado');

        $m->get('/admin/reservas/' . $this->ownBooking);
        $this->assertStatus(200, $m, 'Própria reserva');
        $this->assertTrue(str_contains($m->body, 'Minha comissão'));
        $this->assertTrue(str_contains($m->body, 'R$ 80,00'), '40% de R$ 200,00');
        $this->assertFalse(str_contains($m->body, 'Registrar pagamento'), 'Sem acesso ao financeiro');
        $m->get('/admin/reservas/' . $this->otherBooking);
        $this->assertStatus(404, $m, 'Reserva de outra profissional');

        $m->get('/admin/clientes');
        $this->assertTrue(str_contains($m->body, 'Cliente Da Maqui'));
        $this->assertFalse(str_contains($m->body, 'Cliente Da Dona'));
        $otherClient = Db::value("SELECT id FROM clients WHERE name = 'Cliente Da Dona'");
        $m->get('/admin/clientes/' . $otherClient);
        $this->assertStatus(404, $m, 'Cliente de outra profissional');

        foreach (['/admin/servicos', '/admin/equipe', '/admin/configuracoes', '/admin/financeiro/despesas', '/admin/reservas/nova', '/admin/eventos/novo'] as $p) {
            $m->get($p);
            $this->assertStatus(403, $m, "Maquiadora em $p");
        }

        $m->get('/admin/relatorios');
        $this->assertStatus(200, $m, 'Meu desempenho');
        $this->assertTrue(str_contains($m->body, 'Minhas comissões'));

        // Disponibilidade: só a própria, mesmo pedindo outra na URL
        $m->get('/admin/disponibilidade?profissional=' . $this->ownerPro);
        $token = $this->token($m, '/admin/disponibilidade?profissional=' . $this->ownerPro);
        $m->post('/admin/disponibilidade/horarios', ['_csrf' => $token, 'professional_id' => $this->ownerPro, 'rules' => [3 => [['start' => '09:00', 'end' => '12:00']]]]);
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM availability_rules WHERE professional_id = ?', [$this->ownerPro]), 'Não altera a agenda da dona');
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM availability_rules WHERE professional_id = ?', [$this->artistPro]));
    }

    public function testArtistCanOnlyCompleteOrMarkNoShowOnOwnBookings(): void
    {
        $m = $this->login('maqui@teste.com', 'senha-maqui-123');
        $token = $this->token($m, '/admin/reservas/' . $this->ownBooking);

        $m->post('/admin/reservas/' . $this->ownBooking . '/status', ['_csrf' => $token, 'to' => 'cancelled']);
        $this->assertStatus(403, $m, 'Maquiadora cancelando');
        $m->post('/admin/reservas/' . $this->otherBooking . '/status', ['_csrf' => $token, 'to' => 'completed']);
        $this->assertStatus(404, $m, 'Maquiadora concluindo reserva de outra');
        $this->assertSame('confirmed', Db::value('SELECT status FROM bookings WHERE id = ?', [$this->otherBooking]));

        $m->post('/admin/reservas/' . $this->ownBooking . '/status', ['_csrf' => $token, 'to' => 'completed']);
        $this->assertStatus(303, $m, 'Maquiadora concluindo a própria');
        $this->assertSame('completed', Db::value('SELECT status FROM bookings WHERE id = ?', [$this->ownBooking]));
    }

    public function testManagerPermissions(): void
    {
        $g = $this->login('gerente@teste.com', 'senha-gerente-123');
        foreach (['/admin/equipe', '/admin/relatorios', '/admin/financeiro/despesas', '/admin/eventos/novo', '/admin/reservas/' . $this->otherBooking] as $p) {
            $g->get($p);
            $this->assertStatus(200, $g, "Gerente em $p");
        }
        foreach (['/admin/equipe/novo', '/admin/configuracoes'] as $p) {
            $g->get($p);
            $this->assertStatus(403, $g, "Gerente em $p");
        }
        $token = $this->token($g, '/admin/equipe');
        $g->post('/admin/equipe', ['_csrf' => $token, 'name' => 'Intrusa', 'has_login' => '1', 'active' => '1', 'role' => 'owner', 'email' => 'x@x.com', 'password' => 'senha-intrusa-1']);
        $this->assertStatus(403, $g, 'Gerente criando dona');
        $this->assertSame(0, (int) Db::value("SELECT COUNT(*) FROM users WHERE email = 'x@x.com'"));
    }

    public function testOwnerManagesTeamEventsPaymentsAndReports(): void
    {
        $o = $this->login('dona@teste.com', 'senha-segura-123');

        // Novo membro com agenda e login
        $token = $this->token($o, '/admin/equipe/novo');
        $o->post('/admin/equipe', [
            '_csrf' => $token, 'name' => 'Nova Maquiadora', 'has_agenda' => '1', 'has_login' => '1', 'active' => '1',
            'color' => '#227755', 'commission_percent' => '30', 'services' => [$this->svc], 'accepts_online_booking' => '1',
            'email' => 'nova@teste.com', 'role' => 'artist', 'password' => 'senha-nova-12345',
        ]);
        $this->assertStatus(303, $o, 'Cadastrar membro');
        $newPro = (int) Db::value("SELECT id FROM professionals WHERE name = 'Nova Maquiadora'");
        $this->assertTrue($newPro > 0);
        F::availability($newPro, 3, [['08:00', '20:00']]);
        $this->login('nova@teste.com', 'senha-nova-12345'); // login funciona

        // Evento com duas profissionais (fora do expediente da dona, que não tem regras cadastradas)
        $token = $this->token($o, '/admin/eventos/novo');
        $o->post('/admin/eventos', [
            '_csrf' => $token, 'event_name' => 'Casamento Teste', 'service_id' => $this->svc, 'people_count' => '6',
            'duration_minutes' => '240', 'date' => '2030-01-09', 'time' => '13:00', 'location_type' => 'studio',
            'professional_ids' => [$newPro, $this->ownerPro], 'price' => '3.000,00', 'deposit' => '900,00',
            'client_id' => 'nova', 'name' => 'Noiva Teste', 'phone' => '11977770000', 'status' => 'awaiting_deposit',
            'allow_outside_hours' => '1',
        ]);
        $this->assertStatus(303, $o, 'Criar evento');
        $event = Db::one("SELECT * FROM bookings WHERE kind = 'event'");
        $this->assertSame('awaiting_deposit', $event['status']);
        $this->assertSame(2, (int) Db::value('SELECT COUNT(*) FROM booking_allocations WHERE booking_id = ?', [$event['id']]));

        $o->get('/admin/reservas/' . $event['id']);
        $this->assertStatus(200, $o, 'Página do evento');
        $this->assertTrue(str_contains($o->body, 'Casamento Teste') && str_contains($o->body, 'Registrar pagamento'));

        // Pagamento do sinal confirma o evento automaticamente
        $token = $this->token($o, '/admin/reservas/' . $event['id']);
        $o->post('/admin/reservas/' . $event['id'] . '/pagamentos', [
            '_csrf' => $token, 'amount' => '900,00', 'kind' => 'deposit', 'method' => 'pix', 'paid_on' => '2030-01-02', 'auto_confirm' => '1',
        ]);
        $this->assertStatus(303, $o, 'Registrar sinal');
        $this->assertSame('confirmed', Db::value('SELECT status FROM bookings WHERE id = ?', [$event['id']]));

        // Despesa, conclusão e relatório
        $o->post('/admin/reservas/' . $event['id'] . '/despesas', ['_csrf' => $token, 'amount' => '150', 'category' => 'transporte', 'description' => 'Uber', 'spent_on' => '2030-01-09']);
        $o->post('/admin/reservas/' . $event['id'] . '/status', ['_csrf' => $token, 'to' => 'completed']);
        $this->assertSame('completed', Db::value('SELECT status FROM bookings WHERE id = ?', [$event['id']]));

        $o->get('/admin/relatorios?de=2030-01-01&ate=2030-01-31');
        $this->assertStatus(200, $o, 'Relatórios');
        $this->assertTrue(str_contains($o->body, 'R$ 3.000,00'), 'Faturamento do evento no relatório');
        $this->assertTrue(str_contains($o->body, 'R$ 450,00'), 'Comissão: 30% de 50% de 3.000');

        $o->get('/admin/relatorios/exportar?de=2030-01-01&ate=2030-01-31');
        $this->assertStatus(200, $o, 'CSV');
        $this->assertTrue(str_contains($o->body, 'Evento: Casamento Teste') && str_contains($o->body, '3000,00;450,00;150,00;2400,00'), 'CSV com resultado: ' . substr($o->body, 0, 300));

        foreach (['/admin/equipe', '/admin/equipe/editar?p=' . $newPro, '/admin/financeiro/despesas', '/admin/agenda?visao=dia&data=2030-01-09',
            '/admin/relatorios?profissional=' . $newPro, '/admin/reservas/sugestao?servico=' . $this->svc . '&data=2030-01-09&hora=10:00', '/'] as $p) {
            $o->get($p);
            $this->assertStatus(200, $o, $p);
        }
    }
}
