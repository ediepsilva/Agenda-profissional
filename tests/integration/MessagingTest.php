<?php
declare(strict_types=1);

use App\Core\Db;
use App\Domain\BookingService;
use App\Domain\CampaignService;
use App\Domain\Clock;
use App\Domain\ConsentService;
use App\Domain\MessageService;
use App\Domain\Settings;
use App\Integrations\Integrations;
use Tests\DbTestCase;
use Tests\FakeHttp;
use Tests\Fixtures as F;

/** Avisos da reserva, lembretes automáticos, campanhas com consentimento e envio pela API oficial. */
final class MessagingTest extends DbTestCase
{
    private const DAY = '2030-01-08';
    private int $pro;
    private int $svc;
    private BookingService $bs;
    private MessageService $ms;

    public function setUp(): void
    {
        parent::setUp();
        Clock::freeze(new DateTimeImmutable('2030-01-01 08:00'));
        $this->pro = F::professional(null, 'Ana');
        $this->svc = F::service($this->pro, 60);
        F::availability($this->pro, 2, [['08:00', '20:00']]);
        $this->bs = new BookingService();
        $this->ms = new MessageService();
    }

    private function messages(?int $bookingId = null): array
    {
        return $bookingId
            ? Db::all('SELECT * FROM messages WHERE booking_id = ? ORDER BY id', [$bookingId])
            : Db::all('SELECT * FROM messages ORDER BY id');
    }

    private function keys(array $msgs, ?string $status = null): array
    {
        return array_values(array_map(static fn ($m) => $m['template_key'], array_filter($msgs, static fn ($m) => $status === null || $m['status'] === $status)));
    }

    private function configureCloud(FakeHttp $http): void
    {
        putenv('WHATSAPP_TOKEN=token-teste');
        putenv('WHATSAPP_PHONE_NUMBER_ID=999');
        Integrations::useHttpClient($http);
    }

    public function testConfirmationQueuedOnceWithRenderedText(): void
    {
        $b = $this->bs->create(F::bookingInput($this->svc, self::DAY, '10:00', '11987654321', ['name' => 'Maria Souza']));
        $this->assertSame([], $this->messages(), 'Solicitação ainda não gera mensagem');
        $this->bs->changeStatus((int) $b['id'], 'confirmed', null);
        $m = $this->messages((int) $b['id']);
        $this->assertSame(['booking_confirmed'], $this->keys($m));
        $this->assertSame('transactional', $m[0]['category']);
        $this->assertTrue(str_contains($m[0]['body'], 'Olá, Maria!') && str_contains($m[0]['body'], '08/01/2030 às 10:00'), $m[0]['body']);
        $params = json_decode($m[0]['params'], true);
        $this->assertSame(['Maria', 'Serviço 60', '08/01/2030', '10:00'], array_slice($params, 0, 4), 'Parâmetros na ordem do modelo');
    }

    public function testAdminConfirmedBookingQueuesConfirmation(): void
    {
        $b = $this->bs->create(F::bookingInput($this->svc, self::DAY, '10:00', '11987654321', ['status' => 'confirmed']), 'admin');
        $this->assertSame(['booking_confirmed'], $this->keys($this->messages((int) $b['id'])));
    }

    public function testRemindersScheduledAtTheRightTimeAndOnlyOnce(): void
    {
        $b = $this->bs->create(F::bookingInput($this->svc, self::DAY, '10:00', '11987654321', ['status' => 'confirmed']), 'admin');
        $this->assertSame(0, $this->ms->scheduleDue(), 'Uma semana antes: nada');
        Clock::freeze(new DateTimeImmutable('2030-01-06 11:00')); // 47h antes
        $this->assertSame(1, $this->ms->scheduleDue(), 'Orientações (48h)');
        Clock::freeze(new DateTimeImmutable('2030-01-07 10:30')); // 23,5h antes
        $this->assertSame(1, $this->ms->scheduleDue(), 'Lembrete (24h)');
        $this->assertSame(0, $this->ms->scheduleDue(), 'Sem duplicar');
        $this->assertSame(['booking_confirmed', 'pre_care', 'reminder'], $this->keys($this->messages((int) $b['id'])));
    }

    public function testNoRemindersForUnconfirmedOrCancelledBookings(): void
    {
        $this->bs->create(F::bookingInput($this->svc, self::DAY, '10:00', '11911110001'));
        $c = $this->bs->create(F::bookingInput($this->svc, self::DAY, '14:00', '11911110002', ['status' => 'confirmed']), 'admin');
        $this->bs->changeStatus((int) $c['id'], 'cancelled', null);
        Clock::freeze(new DateTimeImmutable('2030-01-07 12:00'));
        $this->assertSame(0, $this->ms->scheduleDue());
        $this->assertSame(['booking_cancelled'], $this->keys($this->messages((int) $c['id']), 'queued'), 'Confirmação pendente foi cancelada; aviso de cancelamento enfileirado');
    }

    public function testCancellingARequestDoesNotNotify(): void
    {
        $b = $this->bs->create(F::bookingInput($this->svc, self::DAY, '10:00', '11987654321'));
        $this->bs->changeStatus((int) $b['id'], 'cancelled', null);
        $this->assertSame([], $this->messages());
    }

    public function testRescheduleReplacesPendingReminders(): void
    {
        $b = $this->bs->create(F::bookingInput($this->svc, self::DAY, '10:00', '11987654321', ['status' => 'confirmed']), 'admin');
        Clock::freeze(new DateTimeImmutable('2030-01-07 11:00'));
        $this->ms->scheduleDue();
        $this->bs->reschedule((int) $b['id'], self::DAY, '16:00', null, true);
        $this->assertSame(['pre_care', 'reminder'], $this->keys($this->messages((int) $b['id']), 'cancelled'));
        $this->assertSame(2, count(array_filter($this->messages((int) $b['id']), static fn ($m) => $m['status'] === 'cancelled')), 'Lembretes do horário antigo cancelados');
        // Novo horário 08/01 16:00: faltam 29h → só as orientações (48h) já estão no prazo.
        $this->assertSame(1, $this->ms->scheduleDue(), 'Orientações para o novo horário');
        Clock::freeze(new DateTimeImmutable('2030-01-07 16:30'));
        $this->assertSame(1, $this->ms->scheduleDue(), 'Lembrete 24h antes do novo horário');
        $reminder = Db::one("SELECT body FROM messages WHERE booking_id = ? AND template_key = 'reminder' AND status = 'queued'", [$b['id']]);
        $this->assertTrue(str_contains($reminder['body'], '16:00'));
    }

    public function testThankYouScheduledAfterCompletionAndSentInSimulation(): void
    {
        $b = $this->bs->create(F::bookingInput($this->svc, self::DAY, '10:00', '11987654321', ['status' => 'confirmed']), 'admin');
        $this->ms->dispatchDue();
        Clock::freeze(new DateTimeImmutable(self::DAY . ' 11:30'));
        $this->bs->changeStatus((int) $b['id'], 'completed', null);
        $thanks = Db::one("SELECT * FROM messages WHERE template_key = 'thanks_review'");
        $this->assertSame(self::DAY . ' 14:30:00', $thanks['scheduled_at'], '3 horas depois');
        $this->assertTrue(str_contains($thanks['body'], '/avaliar/' . $b['public_code']));
        $this->assertTrue(str_contains($thanks['body'], '/?ref='), 'Inclui o link de indicação');
        $this->assertSame(0, $this->ms->dispatchDue()['sent'], 'Ainda não é hora');
        Clock::freeze(new DateTimeImmutable(self::DAY . ' 15:00'));
        $this->assertSame(1, $this->ms->dispatchDue()['sent']);
        $sent = Db::one('SELECT * FROM messages WHERE id = ?', [$thanks['id']]);
        $this->assertSame('sent', $sent['status']);
        $this->assertSame('simulado', $sent['provider']);
    }

    public function testCloudDispatchStoresProviderIdAndHandlesErrors(): void
    {
        $http = (new FakeHttp())
            ->push(200, ['messages' => [['id' => 'wamid.OK']]])
            ->push(503, ['error' => ['message' => 'indisponível']])
            ->push(503, ['error' => ['message' => 'indisponível']])
            ->push(503, ['error' => ['message' => 'indisponível']]);
        $this->configureCloud($http);
        $a = $this->bs->create(F::bookingInput($this->svc, self::DAY, '10:00', '11911110001', ['status' => 'confirmed']), 'admin');
        $b = $this->bs->create(F::bookingInput($this->svc, self::DAY, '14:00', '11911110002', ['status' => 'confirmed']), 'admin');

        $r = $this->ms->dispatchDue();
        $this->assertSame(1, $r['sent']);
        $this->assertSame(1, $r['retry']);
        $this->assertSame('5511911110001', $http->requests[0]['body'] ? json_decode($http->requests[0]['body'], true)['to'] : '');
        $this->assertSame('wamid.OK', Db::value('SELECT provider_message_id FROM messages WHERE booking_id = ?', [$a['id']]));

        for ($i = 1; $i <= 2; $i++) {
            Clock::freeze(Clock::now()->modify('+30 minutes'));
            $this->ms->dispatchDue();
        }
        $failed = Db::one('SELECT * FROM messages WHERE booking_id = ?', [$b['id']]);
        $this->assertSame('failed', $failed['status'], 'Falha definitiva após 3 tentativas');
        $this->assertSame(3, (int) $failed['attempts']);

        // Retorno da Meta: entregue → lida
        $this->ms->applyStatus('wamid.OK', 'delivered', null);
        $this->ms->applyStatus('wamid.OK', 'read', null);
        $this->ms->applyStatus('wamid.OK', 'delivered', null); // atrasado: não rebaixa
        $this->assertSame('read', Db::value("SELECT status FROM messages WHERE provider_message_id = 'wamid.OK'"));
    }

    public function testStatusChangeBeforeSendingSkipsMessage(): void
    {
        $b = $this->bs->create(F::bookingInput($this->svc, self::DAY, '10:00', '11987654321', ['status' => 'confirmed']), 'admin');
        Db::update('bookings', ['status' => 'cancelled'], 'id = ?', [$b['id']]); // mudança "por fora"
        $r = $this->ms->dispatchDue();
        $this->assertSame(1, $r['skipped']);
        $this->assertSame('skipped', Db::value('SELECT status FROM messages WHERE booking_id = ?', [$b['id']]));
    }

    public function testCampaignOnlyReachesConsentedClientsAndOptOutStopsIt(): void
    {
        $yes = Db::insert('clients', ['name' => 'Aceita Sim', 'phone' => '11911110001', 'source' => 'instagram']);
        $no = Db::insert('clients', ['name' => 'Não Aceita', 'phone' => '11911110002', 'source' => 'instagram']);
        $later = Db::insert('clients', ['name' => 'Vai Sair', 'phone' => '11911110003', 'source' => 'instagram']);
        ConsentService::set($yes, true, 'painel');
        ConsentService::set($later, true, 'painel');

        $this->assertSame(null, $this->ms->enqueueMarketing($no, 'campaign_offer', ['oferta' => 'x', 'link' => 'y'], null), 'Sem consentimento não enfileira');

        $camp = new CampaignService();
        $id = $camp->create(['name' => 'Promo', 'template_key' => 'campaign_offer', 'audience' => 'source', 'audience_param' => 'instagram', 'offer_text' => '15% de desconto'], null);
        $this->assertSame(2, count($camp->recipients('source', 'instagram')));
        $this->assertSame(2, $camp->queue($id));
        $this->assertThrows(DomainException::class, fn () => $camp->queue($id), 'Não envia duas vezes');

        // Responde SAIR antes do envio: a oferta pendente é cancelada.
        $this->assertTrue($this->ms->handleInbound('5511911110003', 'SAIR'));
        $this->assertSame('cancelled', Db::value('SELECT status FROM messages WHERE client_id = ?', [$later]));
        $this->assertSame('opt_out', Db::value('SELECT action FROM consent_log WHERE client_id = ? ORDER BY id DESC LIMIT 1', [$later]));

        $r = $this->ms->dispatchDue();
        $this->assertSame(1, $r['sent']);
        $body = Db::value('SELECT body FROM messages WHERE client_id = ?', [$yes]);
        $this->assertTrue(str_contains($body, '15% de desconto') && str_contains($body, 'origem=campanha-' . $id) && str_contains($body, 'SAIR'));
    }

    public function testConsentRecheckedAtSendTime(): void
    {
        $c = Db::insert('clients', ['name' => 'Cliente', 'phone' => '11911110001']);
        ConsentService::set($c, true, 'painel');
        $this->ms->enqueueMarketing($c, 'campaign_offer', ['oferta' => 'x', 'link' => 'y'], null);
        Db::update('clients', ['marketing_opt_in' => 0], 'id = ?', [$c]); // revogado sem passar pelo serviço
        $this->assertSame(1, $this->ms->dispatchDue()['skipped']);
    }

    public function testMessagingCanBeDisabled(): void
    {
        Settings::set('messaging_enabled', '0');
        $this->bs->create(F::bookingInput($this->svc, self::DAY, '10:00', '11987654321', ['status' => 'confirmed']), 'admin');
        Clock::freeze(new DateTimeImmutable('2030-01-07 12:00'));
        $this->assertSame(0, $this->ms->scheduleDue());
        $this->assertSame([], $this->messages());
    }

    public function testConsentChangesAreLogged(): void
    {
        $c = Db::insert('clients', ['name' => 'Cliente', 'phone' => '11911110001']);
        $this->assertTrue(ConsentService::set($c, true, 'formulario_publico', null, null, '10.0.0.1'));
        $this->assertFalse(ConsentService::set($c, true, 'painel'), 'Sem mudança, sem novo registro');
        $this->assertTrue(ConsentService::set($c, false, 'pagina_preferencias'));
        $log = ConsentService::history($c);
        $this->assertSame(['opt_out', 'opt_in'], array_column($log, 'action'));
        $this->assertSame('10.0.0.1', $log[1]['ip']);
    }
}
