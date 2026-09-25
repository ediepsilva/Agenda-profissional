<?php
declare(strict_types=1);

use App\Core\Db;
use App\Domain\ConsentService;
use Tests\Browser;
use Tests\Fixtures as F;
use Tests\HttpTestCase;

/** Fase 3 via HTTP: indicação, consentimento, avaliação, webhooks, campanhas e worker. */
final class GrowthFlowTest extends HttpTestCase
{
    private int $pro;
    private int $svc;

    public function setUp(): void
    {
        // Segredos só para o servidor de teste (webhook do WhatsApp).
        putenv('WHATSAPP_VERIFY_TOKEN=verifica-teste');
        putenv('WHATSAPP_APP_SECRET=segredo-teste');
        parent::setUp();
        $owner = F::user('owner', 'dona@teste.com', 'senha-segura-123');
        $this->pro = F::professional($owner, 'Dona');
        $this->svc = F::service($this->pro);
        for ($wd = 0; $wd <= 6; $wd++) {
            F::availability($this->pro, $wd, [['08:00', '20:00']]);
        }
    }

    public function tearDown(): void
    {
        putenv('WHATSAPP_VERIFY_TOKEN=');
        putenv('WHATSAPP_APP_SECRET=');
        parent::tearDown();
    }

    private function firstSlot(Browser $b): array
    {
        $month = new DateTimeImmutable('first day of this month');
        foreach ([$month, $month->modify('+1 month')] as $m) {
            $days = $b->json("/api/dias?servico={$this->svc}&mes=" . $m->format('Y-m') . '&local=studio')['days'] ?? [];
            if ($days) {
                $slots = $b->json("/api/horarios?servico={$this->svc}&data={$days[0]}&local=studio")['slots'];
                return [$days[0], $slots[0]];
            }
        }
        throw new \Tests\AssertionFailed('Sem horários');
    }

    public function testReferralConsentReviewAndPreferences(): void
    {
        $madrinha = Db::insert('clients', ['name' => 'Madrinha', 'phone' => '11911110001']);
        $code = ConsentService::ensureTokens($madrinha)['referral_code'];

        // Chega pelo link de indicação com origem rastreada
        $c = new Browser($this->base);
        $c->get('/?ref=' . $code . '&origem=instagram-bio');
        $this->assertTrue(str_contains($c->body, 'indicação'), 'Aviso de indicação na página');
        [$date, $time] = $this->firstSlot($c);
        $token = $c->csrf('/agendar');
        $this->assertTrue(str_contains($c->body, 'name="ref" value="' . $code . '"'), 'Código de indicação vai no formulário');
        $c->post('/agendar', [
            '_csrf' => $token, 'service_id' => $this->svc, 'location_type' => 'studio', 'date' => $date, 'time' => $time,
            'name' => 'Noiva Indicada', 'phone' => '11977770000', 'privacy' => '1', 'marketing' => '1', 'ref' => $code, 'origem' => 'instagram-bio',
        ]);
        $this->assertStatus(303, $c, 'Solicitação');
        $b = Db::one('SELECT * FROM bookings');
        $this->assertSame($madrinha, (int) $b['referred_by_client_id']);
        $this->assertSame('instagram-bio', $b['origin']);
        $client = Db::one('SELECT * FROM clients WHERE id = ?', [$b['client_id']]);
        $this->assertSame(1, (int) $client['marketing_opt_in']);

        // Dona confirma e conclui (a mensagem de agradecimento é enfileirada)
        $o = $this->login('dona@teste.com', 'senha-segura-123');
        $t = $o->csrf('/admin/reservas/' . $b['id']);
        $o->post('/admin/reservas/' . $b['id'] . '/status', ['_csrf' => $t, 'to' => 'confirmed']);
        $o->post('/admin/reservas/' . $b['id'] . '/status', ['_csrf' => $t, 'to' => 'completed']);
        $this->assertSame(['booking_confirmed', 'thanks_review'], array_column(Db::all('SELECT template_key FROM messages WHERE booking_id = ? ORDER BY id', [$b['id']]), 'template_key'));

        // Cliente avalia pelo link
        $t = $c->csrf('/avaliar/' . $b['public_code']);
        $c->post('/avaliar/' . $b['public_code'], ['_csrf' => $t, 'rating' => '5', 'comment' => 'Amei a maquiagem!']);
        $this->assertStatus(303, $c, 'Enviar avaliação');
        $rid = (int) Db::value('SELECT id FROM reviews');
        $c->get('/');
        $this->assertFalse(str_contains($c->body, 'Amei a maquiagem!'), 'Não publica antes da aprovação');

        $t = $o->csrf('/admin/captacao');
        $o->post('/admin/avaliacoes/' . $rid, ['_csrf' => $t, 'status' => 'approved']);
        $c->get('/');
        $this->assertTrue(str_contains($c->body, 'Amei a maquiagem!') && str_contains($c->body, 'Noiva I.'), 'Publicada com nome abreviado');

        // Preferências: cancela ofertas pelo link pessoal
        $pref = Db::value('SELECT preferences_token FROM clients WHERE id = ?', [$client['id']]);
        $t = $c->csrf('/preferencias/' . $pref);
        $c->post('/preferencias/' . $pref, ['_csrf' => $t, 'marketing' => '0']);
        $this->assertSame(0, (int) Db::value('SELECT marketing_opt_in FROM clients WHERE id = ?', [$client['id']]));
        $c->get('/preferencias/' . str_repeat('0', 32));
        $this->assertStatus(404, $c, 'Token inválido');

        // Página da reserva concluída mostra o link de indicação dela
        $c->get('/reserva/' . $b['public_code']);
        $this->assertTrue(str_contains($c->body, '/?ref='), 'Link de indicação');
    }

    public function testWhatsAppWebhookVerificationStatusAndOptOut(): void
    {
        $w = new Browser($this->base);
        $w->get('/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=verifica-teste&hub.challenge=12345');
        $this->assertStatus(200, $w, 'Verificação');
        $this->assertSame('12345', $w->body);
        $w->get('/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=errado&hub.challenge=1');
        $this->assertStatus(403, $w, 'Verify token errado');

        $cid = Db::insert('clients', ['name' => 'Cliente', 'phone' => '11987654321']);
        ConsentService::set($cid, true, 'painel');
        Db::insert('messages', ['category' => 'transactional', 'template_key' => 'reminder', 'client_id' => $cid, 'to_phone' => '11987654321',
            'params' => '[]', 'body' => 'x', 'status' => 'sent', 'scheduled_at' => '2030-01-01 00:00:00', 'provider_message_id' => 'wamid.T1']);

        $payload = json_encode(['entry' => [['changes' => [['value' => [
            'statuses' => [['id' => 'wamid.T1', 'status' => 'read']],
            'messages' => [['from' => '5511987654321', 'type' => 'text', 'text' => ['body' => 'Sair']]],
        ]]]]]]);
        $post = static function (string $body, string $sig) {
            $ch = curl_init('http://127.0.0.1:8099/webhooks/whatsapp');
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Hub-Signature-256: ' . $sig]]);
            $out = curl_exec($ch);
            return [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $out];
        };
        [$status] = $post($payload, 'sha256=' . hash_hmac('sha256', $payload, 'outro-segredo'));
        $this->assertSame(401, $status, 'Assinatura inválida é recusada');
        $this->assertSame('sent', Db::value("SELECT status FROM messages WHERE provider_message_id = 'wamid.T1'"));

        [$status, $out] = $post($payload, 'sha256=' . hash_hmac('sha256', $payload, 'segredo-teste'));
        $this->assertSame(200, $status, 'Webhook válido: ' . $out);
        $this->assertSame('read', Db::value("SELECT status FROM messages WHERE provider_message_id = 'wamid.T1'"));
        $this->assertSame(0, (int) Db::value('SELECT marketing_opt_in FROM clients WHERE id = ?', [$cid]), 'SAIR revoga ofertas');
        $this->assertSame('whatsapp', Db::value('SELECT channel FROM consent_log WHERE client_id = ? ORDER BY id DESC LIMIT 1', [$cid]));

        // Mercado Pago sem configuração/assinatura: recusado
        $ch = curl_init('http://127.0.0.1:8099/webhooks/mercadopago?type=payment&data.id=123');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => '{}', CURLOPT_RETURNTRANSFER => true]);
        curl_exec($ch);
        $this->assertSame(401, (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE));
    }

    public function testAdminMessagingCampaignsAndWorker(): void
    {
        $o = $this->login('dona@teste.com', 'senha-segura-123');
        $a = Db::insert('clients', ['name' => 'Aceita', 'phone' => '11911110001', 'source' => 'instagram']);
        Db::insert('clients', ['name' => 'Não Aceita', 'phone' => '11911110002', 'source' => 'instagram']);
        ConsentService::set($a, true, 'painel');

        foreach (['/admin/mensagens', '/admin/campanhas', '/admin/captacao', '/admin/clientes/' . $a, '/admin/configuracoes'] as $p) {
            $o->get($p);
            $this->assertStatus(200, $o, $p);
        }
        $this->assertTrue(str_contains($o->body, 'Mensagens, avaliações e pagamentos'));

        $t = $o->csrf('/admin/campanhas');
        $o->post('/admin/campanhas', ['_csrf' => $t, 'name' => 'Promo de teste', 'template_key' => 'campaign_offer', 'audience' => 'all', 'offer_text' => 'Desconto especial de 10%']);
        $cid = (int) Db::value('SELECT id FROM campaigns');
        $o->get('/admin/campanhas');
        $this->assertTrue(str_contains($o->body, '1 cliente(s) com consentimento'), 'Prévia conta só quem aceitou');
        $o->post('/admin/campanhas/' . $cid . '/enviar', ['_csrf' => $t]);
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM messages WHERE campaign_id = ?', [$cid]));

        // Worker pela linha de comando (modo simulado)
        $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/bin/worker.php') . ' 2>&1');
        $this->assertTrue(str_contains((string) $out, 'enviadas: 1'), 'Worker: ' . $out);
        $this->assertSame('sent', Db::value('SELECT status FROM messages WHERE campaign_id = ?', [$cid]));
        $o->get('/admin/mensagens');
        $this->assertTrue(str_contains($o->body, 'Desconto especial de 10%') && str_contains($o->body, '(simulado)'));

        // Consentimento registrado manualmente no painel
        $t = $o->csrf('/admin/clientes/' . $a);
        $o->post('/admin/clientes/' . $a . '/consentimento', ['_csrf' => $t, 'marketing' => '0', 'note' => 'Pediu pessoalmente']);
        $this->assertSame(0, (int) Db::value('SELECT marketing_opt_in FROM clients WHERE id = ?', [$a]));
    }

    public function testRolesCannotReachMarketingAreas(): void
    {
        F::user('artist', 'maqui@teste.com', 'senha-maqui-123');
        F::user('assistant', 'assist@teste.com', 'senha-assist-123');
        foreach (['maqui@teste.com' => 'senha-maqui-123', 'assist@teste.com' => 'senha-assist-123'] as $email => $pass) {
            $u = $this->login($email, $pass);
            foreach (['/admin/mensagens', '/admin/campanhas', '/admin/captacao'] as $p) {
                $u->get($p);
                $this->assertStatus(403, $u, "$email em $p");
            }
        }
    }
}
