<?php
declare(strict_types=1);

use App\Core\Db;
use App\Domain\BookingService;
use App\Domain\Clock;
use App\Domain\ConsentService;
use App\Domain\OnlinePaymentService;
use App\Domain\ReferralService;
use App\Domain\ReviewService;
use App\Domain\Settings;
use App\Domain\ValidationException;
use App\Integrations\Integrations;
use Tests\DbTestCase;
use Tests\FakeHttp;
use Tests\Fixtures as F;

/** Indicações, origem, consentimento no formulário, avaliações e pagamento online. */
final class GrowthPaymentTest extends DbTestCase
{
    private const DAY = '2030-01-08';
    private int $pro;
    private int $svc;
    private BookingService $bs;

    public function setUp(): void
    {
        parent::setUp();
        Clock::freeze(new DateTimeImmutable('2030-01-01 08:00'));
        $this->pro = F::professional(null, 'Ana');
        $this->svc = F::service($this->pro, 60, 'both', 20000);
        F::availability($this->pro, 2, [['08:00', '20:00']]);
        $this->bs = new BookingService();
    }

    private function mp(FakeHttp $http): void
    {
        putenv('MERCADOPAGO_ACCESS_TOKEN=token-mp');
        putenv('MERCADOPAGO_WEBHOOK_SECRET=segredo-mp');
        Integrations::useHttpClient($http);
    }

    private function signed(string $paymentId): array
    {
        $ts = '1700000000';
        return ['x-signature' => "ts=$ts,v1=" . hash_hmac('sha256', "id:$paymentId;request-id:r1;ts:$ts;", 'segredo-mp'), 'x-request-id' => 'r1'];
    }

    // ---------- captação

    public function testReferralAndOriginAreRecorded(): void
    {
        $referrer = Db::insert('clients', ['name' => 'Madrinha', 'phone' => '11911110001']);
        $code = ConsentService::ensureTokens($referrer)['referral_code'];
        $b = $this->bs->create(F::bookingInput($this->svc, self::DAY, '10:00', '11922220002', ['ref' => strtolower($code), 'origem' => 'instagram-bio']));
        $this->assertSame($referrer, (int) $b['referred_by_client_id']);
        $this->assertSame('instagram-bio', $b['origin']);
        $new = Db::one('SELECT * FROM clients WHERE id = ?', [$b['client_id']]);
        $this->assertSame($referrer, (int) $new['referred_by_client_id']);
        $this->assertSame('indicacao', $new['source']);
        $this->assertSame(1, (int) ReferralService::ranking()[0]['referred_clients']);
    }

    public function testSelfReferralAndInvalidOriginIgnored(): void
    {
        $me = Db::insert('clients', ['name' => 'Eu Mesma', 'phone' => '11911110001']);
        $code = ConsentService::ensureTokens($me)['referral_code'];
        $b = $this->bs->create(F::bookingInput($this->svc, self::DAY, '10:00', '11911110001', ['ref' => $code, 'origem' => '<script>']));
        $this->assertSame(null, $b['referred_by_client_id']);
        $this->assertSame(null, $b['origin']);
    }

    public function testMarketingCheckboxRecordsConsentButNeverRevokes(): void
    {
        $b = $this->bs->create(F::bookingInput($this->svc, self::DAY, '10:00', '11911110001', ['marketing' => '1']));
        $this->assertSame(1, (int) Db::value('SELECT marketing_opt_in FROM clients WHERE id = ?', [$b['client_id']]));
        $this->assertSame('formulario_publico', Db::value('SELECT channel FROM consent_log WHERE client_id = ?', [$b['client_id']]));
        $this->bs->create(F::bookingInput($this->svc, self::DAY, '14:00', '11911110001'));
        $this->assertSame(1, (int) Db::value('SELECT marketing_opt_in FROM clients WHERE id = ?', [$b['client_id']]), 'Não marcar de novo não revoga');
        $c = $this->bs->create(F::bookingInput($this->svc, self::DAY, '16:00', '11911110009'));
        $this->assertSame(0, (int) Db::value('SELECT marketing_opt_in FROM clients WHERE id = ?', [$c['client_id']]), 'Padrão é não receber');
    }

    // ---------- avaliações

    public function testReviewFlow(): void
    {
        $b = $this->bs->create(F::bookingInput($this->svc, self::DAY, '10:00', '11911110001', ['name' => 'Maria da Silva Souza', 'status' => 'confirmed']), 'admin');
        $rs = new ReviewService();
        $this->assertThrows(DomainException::class, fn () => $rs->submit($this->bs->find((int) $b['id']), ['rating' => 5]), 'Antes de concluir');
        $this->bs->changeStatus((int) $b['id'], 'completed', null);
        $done = $this->bs->find((int) $b['id']);
        $this->assertThrows(ValidationException::class, fn () => $rs->submit($done, ['rating' => 6]));
        $id = $rs->submit($done, ['rating' => 5, 'comment' => 'Maravilhosa!']);
        $this->assertThrows(DomainException::class, fn () => $rs->submit($done, ['rating' => 4]), 'Só uma vez');
        $r = Db::one('SELECT * FROM reviews WHERE id = ?', [$id]);
        $this->assertSame('pending', $r['status']);
        $this->assertSame('Maria S.', $r['display_name'], 'Privacidade: primeiro nome + inicial');
        $this->assertSame([], $rs->published());
        $rs->moderate($id, 'approved', null);
        $this->assertSame(['count' => 1, 'average' => 5.0], $rs->stats());
    }

    public function testReviewsCanBeAutoApproved(): void
    {
        Settings::set('reviews_auto_approve', '1');
        $b = $this->bs->create(F::bookingInput($this->svc, self::DAY, '10:00', '11911110001', ['status' => 'confirmed']), 'admin');
        $this->bs->changeStatus((int) $b['id'], 'completed', null);
        (new ReviewService())->submit($this->bs->find((int) $b['id']), ['rating' => 4]);
        $this->assertSame(1, count((new ReviewService())->published()));
    }

    // ---------- pagamento online

    public function testCheckoutRequiresConfigurationAndAcceptedBooking(): void
    {
        $b = $this->bs->create(F::bookingInput($this->svc, self::DAY, '10:00', '11911110001'));
        $this->assertFalse((new OnlinePaymentService())->available(), 'Sem credenciais');
        $this->mp(new FakeHttp());
        $this->assertThrows(DomainException::class, fn () => (new OnlinePaymentService())->checkoutUrl($this->bs->find((int) $b['id'])), 'Reserva ainda não aceita');
    }

    public function testCheckoutCreatedForDepositAndReused(): void
    {
        $http = (new FakeHttp())->push(201, ['id' => 'pref-1', 'init_point' => 'https://mp.test/checkout/pref-1']);
        $this->mp($http);
        $b = $this->bs->create(F::bookingInput($this->svc, self::DAY, '10:00', '11911110001', ['status' => 'awaiting_deposit']), 'admin');
        $svc = new OnlinePaymentService();
        $url = $svc->checkoutUrl($this->bs->find((int) $b['id']));
        $this->assertSame('https://mp.test/checkout/pref-1', $url);
        $this->assertSame(60.0, (float) $http->lastJson()['items'][0]['unit_price'], 'Sinal de 30% de R$ 200,00');
        $this->assertTrue(str_contains($http->lastJson()['notification_url'], '/webhooks/mercadopago'));
        $this->assertSame($url, $svc->checkoutUrl($this->bs->find((int) $b['id'])), 'Reaproveita o link pendente (sem nova chamada à API)');
        $this->assertSame(1, count($http->requests));
    }

    public function testApprovedNotificationRegistersPaymentOnceAndConfirms(): void
    {
        $http = (new FakeHttp())->push(201, ['id' => 'pref-1', 'init_point' => 'https://mp.test/x']);
        $this->mp($http);
        $b = $this->bs->create(F::bookingInput($this->svc, self::DAY, '10:00', '11911110001', ['status' => 'awaiting_deposit']), 'admin');
        $svc = new OnlinePaymentService();
        $svc->checkoutUrl($this->bs->find((int) $b['id']));
        $ref = Db::value('SELECT external_reference FROM payment_intents');

        $this->assertSame('invalid_signature', $svc->handleNotification(['x-signature' => 'ts=1,v1=abc', 'x-request-id' => 'r1'], '555'));

        $approved = ['status' => 'approved', 'external_reference' => $ref, 'transaction_amount' => 60, 'payment_type_id' => 'bank_transfer', 'date_approved' => '2030-01-02T09:00:00.000-03:00'];
        $http->push(200, $approved)->push(200, $approved);
        $this->assertSame('registered', $svc->handleNotification($this->signed('555'), '555'));
        $this->assertSame('duplicate', $svc->handleNotification($this->signed('555'), '555'), 'Notificação repetida');
        $pays = Db::all('SELECT * FROM booking_payments');
        $this->assertSame(1, count($pays));
        $this->assertSame('deposit', $pays[0]['kind']);
        $this->assertSame('pix', $pays[0]['method']);
        $this->assertSame('mercadopago:555', $pays[0]['external_id']);
        $this->assertSame('confirmed', Db::value('SELECT status FROM bookings WHERE id = ?', [$b['id']]), 'Sinal pago confirma a reserva');
        $this->assertSame('approved', Db::value('SELECT status FROM payment_intents'));
        $this->assertSame(1, (int) Db::value("SELECT COUNT(*) FROM messages WHERE booking_id = ? AND template_key = 'booking_confirmed'", [$b['id']]));
    }

    public function testRejectedAndUnknownNotifications(): void
    {
        $http = (new FakeHttp())->push(201, ['id' => 'pref-1', 'init_point' => 'https://mp.test/x']);
        $this->mp($http);
        $b = $this->bs->create(F::bookingInput($this->svc, self::DAY, '10:00', '11911110001', ['status' => 'awaiting_deposit']), 'admin');
        $svc = new OnlinePaymentService();
        $svc->checkoutUrl($this->bs->find((int) $b['id']));
        $ref = Db::value('SELECT external_reference FROM payment_intents');
        $http->push(200, ['status' => 'rejected', 'external_reference' => $ref, 'transaction_amount' => 60])
             ->push(200, ['status' => 'approved', 'external_reference' => 'nao-existe', 'transaction_amount' => 60]);
        $this->assertSame('updated', $svc->handleNotification($this->signed('1'), '1'));
        $this->assertSame('ignored', $svc->handleNotification($this->signed('2'), '2'));
        $this->assertSame('rejected', Db::value('SELECT status FROM payment_intents'));
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM booking_payments'));
        $this->assertSame('awaiting_deposit', Db::value('SELECT status FROM bookings WHERE id = ?', [$b['id']]));
    }
}
