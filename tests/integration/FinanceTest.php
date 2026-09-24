<?php
declare(strict_types=1);

use App\Core\Db;
use App\Domain\BookingService;
use App\Domain\Clock;
use App\Domain\FinanceService;
use Tests\DbTestCase;
use Tests\Fixtures as F;

/** Pagamentos, comissões, resultado por atendimento e relatórios. */
final class FinanceTest extends DbTestCase
{
    private const DAY = '2030-01-08';
    private int $ana;
    private int $bia;
    private int $svc;
    private BookingService $bs;
    private FinanceService $fin;

    public function setUp(): void
    {
        parent::setUp();
        Clock::freeze(new DateTimeImmutable('2030-01-01 08:00'));
        $this->ana = F::professional(null, 'Ana');
        $this->bia = F::professional(null, 'Bia');
        Db::update('professionals', ['commission_percent' => 40], 'id = ?', [$this->bia]);
        $this->svc = F::service($this->ana, 60, 'both', 20000);
        Db::insert('professional_services', ['professional_id' => $this->bia, 'service_id' => $this->svc]);
        foreach ([$this->ana, $this->bia] as $p) {
            F::availability($p, 2, [['08:00', '20:00']]);
        }
        $this->bs = new BookingService();
        $this->fin = new FinanceService();
    }

    private function completed(array $b): array
    {
        if ($b['status'] === 'requested') {
            $this->bs->changeStatus((int) $b['id'], 'confirmed', null);
        }
        $this->bs->changeStatus((int) $b['id'], 'completed', null);
        return Db::one('SELECT * FROM bookings WHERE id = ?', [$b['id']]);
    }

    public function testCommissionExcludesTravelFeeAndUsesShare(): void
    {
        $area = F::area(20, 5000);
        $b = $this->bs->create(F::bookingInput($this->svc, self::DAY, '10:00', '11990000001', [
            'professional_id' => $this->bia, 'location_type' => 'client', 'service_area_id' => $area, 'address' => 'Rua A, 1',
        ]), 'admin');
        $s = $this->fin->bookingSummary($b);
        $this->assertSame(25000, $s['price']);
        $this->assertSame(20000, $s['base'], 'Taxa de deslocamento fora da base');
        $this->assertSame(8000, $s['commissions'], '40% de 200,00');
        $this->assertSame(17000, $s['result']);
    }

    public function testEventCommissionSplitBetweenProfessionals(): void
    {
        $e = $this->bs->createEvent([
            'service_id' => $this->svc, 'event_name' => 'Formatura', 'date' => self::DAY, 'time' => '09:00', 'duration_minutes' => 180,
            'people_count' => 4, 'location_type' => 'studio', 'price' => '1.000,00', 'professional_ids' => [$this->ana, $this->bia],
            'name' => 'Turma', 'phone' => '11990000009', 'status' => 'confirmed',
        ], null);
        $s = $this->fin->bookingSummary($e);
        $this->assertSame(20000, $s['commissions'], 'Bia: 40% sobre 50% de 1.000,00; Ana (dona) 0%');
        $this->assertSame(80000, $s['result']);
    }

    public function testPaymentsBalanceAndDeposit(): void
    {
        $b = $this->bs->create(F::bookingInput($this->svc, self::DAY, '10:00', '11990000001', ['status' => 'awaiting_deposit']), 'admin');
        $this->assertSame(6000, (int) $b['deposit_cents']);
        $errors = $this->fin->addPayment((int) $b['id'], ['amount' => '0', 'kind' => 'x', 'method' => 'y', 'paid_on' => '2030-02-30'], null);
        $this->assertTrue(isset($errors['amount'], $errors['kind'], $errors['method'], $errors['paid_on']));

        $this->fin->addPayment((int) $b['id'], ['amount' => '60,00', 'kind' => 'deposit', 'method' => 'pix', 'paid_on' => '2030-01-02'], null);
        $s = $this->fin->bookingSummary($b);
        $this->assertTrue($s['deposit_ok']);
        $this->assertSame(14000, $s['balance']);
        $this->fin->addPayment((int) $b['id'], ['amount' => '140', 'kind' => 'balance', 'method' => 'dinheiro', 'paid_on' => '2030-01-08'], null);
        $this->assertSame(0, $this->fin->bookingSummary($b)['balance']);
    }

    public function testExpenseReducesBookingResult(): void
    {
        $b = $this->bs->create(F::bookingInput($this->svc, self::DAY, '10:00', '11990000001', ['professional_id' => $this->ana]), 'admin');
        $this->assertSame([], $this->fin->addExpense((int) $b['id'], ['amount' => '35,50', 'category' => 'material', 'description' => 'Cílios', 'spent_on' => self::DAY], null));
        $this->assertSame(20000 - 3550, $this->fin->bookingSummary($b)['result']);
        $this->assertTrue(isset($this->fin->addExpense(null, ['amount' => '10', 'category' => 'inexistente', 'description' => 'x', 'spent_on' => self::DAY], null)['category']));
    }

    public function testReportTotals(): void
    {
        // Cliente recorrente: atendida antes do período.
        Clock::freeze(new DateTimeImmutable('2029-11-01 08:00'));
        F::availability($this->ana, 5, [['08:00', '20:00']]);
        $old = $this->bs->create(F::bookingInput($this->svc, '2029-12-07', '10:00', '11990000001', ['professional_id' => $this->ana, 'source' => 'instagram']), 'admin');
        $this->completed($old);
        Clock::freeze(new DateTimeImmutable('2030-01-01 08:00'));

        $a = $this->completed($this->bs->create(F::bookingInput($this->svc, self::DAY, '09:00', '11990000001', ['professional_id' => $this->ana]), 'admin'));
        $b = $this->completed($this->bs->create(F::bookingInput($this->svc, self::DAY, '11:00', '11990000002', ['professional_id' => $this->bia, 'source' => 'indicacao']), 'admin'));
        $c = $this->bs->create(F::bookingInput($this->svc, self::DAY, '14:00', '11990000003', ['professional_id' => $this->bia]), 'admin');
        $this->bs->changeStatus((int) $c['id'], 'cancelled', null);
        $this->fin->addExpense((int) $a['id'], ['amount' => '20', 'category' => 'material', 'description' => 'Produto', 'spent_on' => self::DAY], null);
        $this->fin->addExpense(null, ['amount' => '100', 'category' => 'marketing', 'description' => 'Anúncio', 'spent_on' => '2030-01-15'], null);
        $this->fin->addPayment((int) $b['id'], ['amount' => '200', 'kind' => 'balance', 'method' => 'pix', 'paid_on' => self::DAY], null);

        $r = $this->fin->report('2030-01-01', '2030-01-31');
        $this->assertSame(3, $r['total']);
        $this->assertSame(2, $r['counts']['completed']);
        $this->assertSame(1, $r['counts']['cancelled']);
        $this->assertSame(40000, $r['revenue']);
        $this->assertSame(8000, $r['commissions'], 'Só a Bia tem comissão');
        $this->assertSame(12000, $r['expenses']);
        $this->assertSame(40000 - 8000 - 12000, $r['result']);
        $this->assertSame(20000, $r['received']);
        $this->assertSame(1, $r['clients_recurring']);
        $this->assertSame(1, $r['clients_new'], 'Cliente cancelada não conta');
        $this->assertSame(1, $r['origin']['Instagram']);
        $this->assertSame(1, $r['by_professional'][$this->bia]['completed']);
        $this->assertSame(1, $r['by_professional'][$this->bia]['cancelled']);
        $this->assertSame(8000, $r['by_professional'][$this->bia]['commission']);

        $mine = $this->fin->report('2030-01-01', '2030-01-31', $this->bia);
        $this->assertSame([$this->bia], array_keys($mine['by_professional']), 'Filtro por profissional mostra só ela');
        $this->assertSame(1, count($mine['rows']));
        $this->assertSame(0, $mine['expenses'], 'Despesas do negócio não aparecem no escopo da profissional');
    }
}
