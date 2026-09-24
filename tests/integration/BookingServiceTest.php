<?php
declare(strict_types=1);

use App\Core\Db;
use App\Domain\BookingService;
use App\Domain\Clock;
use App\Domain\Settings;
use App\Domain\ValidationException;
use Tests\DbTestCase;
use Tests\Fixtures as F;

/**
 * Regras críticas de reserva contra o banco de teste.
 * "Agora" congelado em terça 01/01/2030 08:00; a data-alvo é terça 08/01/2030.
 */
final class BookingServiceTest extends DbTestCase
{
    private const DAY = '2030-01-08';
    private int $pro;
    private int $svc;
    private BookingService $bs;

    public function setUp(): void
    {
        parent::setUp();
        Clock::freeze(new DateTimeImmutable('2030-01-01 08:00'));
        $this->pro = F::professional();
        $this->svc = F::service($this->pro, 60);
        F::availability($this->pro, 2, [['09:00', '12:00'], ['13:00', '18:00']]);
        $this->bs = new BookingService();
    }

    private function slots(?int $area = null, string $loc = 'studio', ?int $svc = null): array
    {
        return array_keys($this->bs->availableSlots($svc ?? $this->svc, self::DAY, $loc, $area));
    }

    private function book(string $time, string $phone = '11987654321', array $extra = [], string $channel = 'public', bool $override = false): array
    {
        return $this->bs->create(F::bookingInput($extra['service_id'] ?? $this->svc, $extra['date'] ?? self::DAY, $time, $phone, $extra), $channel, null, $override);
    }

    public function testPublicBookingIsCreatedAsRequestedWithAllocation(): void
    {
        $b = $this->book('10:00');
        $this->assertSame('requested', $b['status']);
        $this->assertSame(self::DAY . ' 10:00:00', $b['starts_at']);
        $this->assertSame(self::DAY . ' 11:00:00', $b['ends_at']);
        $alloc = Db::one('SELECT * FROM booking_allocations WHERE booking_id = ?', [$b['id']]);
        $this->assertSame(self::DAY . ' 11:30:00', $alloc['block_end'], 'Bloco inclui o intervalo de 30 min');
        $this->assertSame(24, strlen($b['public_code']));
        $this->assertSame(6000, (int) $b['deposit_cents'], 'Sinal padrão de 30%');
    }

    public function testSameSlotCannotBeBookedTwice(): void
    {
        $this->book('10:00');
        $e = $this->assertThrows(ValidationException::class, fn () => $this->book('10:00', '11911112222'));
        $this->assertTrue(isset($e->errors['time']));
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM bookings'));
    }

    public function testOverlappingBookingIsRejected(): void
    {
        $this->book('10:00');
        $this->assertThrows(ValidationException::class, fn () => $this->book('10:30', '11911112222'));
        $this->assertThrows(ValidationException::class, fn () => $this->book('09:30', '11911112223'));
    }

    public function testBufferBetweenAppointmentsIsEnforced(): void
    {
        $this->book('09:00');
        $slots = $this->slots();
        $this->assertNotContains('10:00', $slots, '10:00 fica dentro do intervalo de 30 min');
        $this->assertContains('10:30', $slots);
        $this->book('10:30', '11911112222');
    }

    public function testBookedSlotDisappearsFromAvailability(): void
    {
        $this->assertContains('10:00', $this->slots());
        $this->book('10:00');
        $slots = $this->slots();
        // 09:00 ocuparia até 10:30 (60 min + 30 de intervalo) e colide com a reserva das 10:00.
        foreach (['09:00', '09:30', '10:00', '10:30', '11:00'] as $t) {
            $this->assertNotContains($t, $slots);
        }
        $this->assertContains('13:00', $slots);
    }

    public function testCancelledBookingFreesTheSlot(): void
    {
        $b = $this->book('10:00');
        $this->bs->changeStatus((int) $b['id'], 'cancelled', null, 'teste');
        $this->assertContains('10:00', $this->slots());
        $this->book('10:00', '11911112222');
    }

    public function testScheduleBlockHidesSlotsAndRejectsBooking(): void
    {
        Db::insert('schedule_blocks', ['professional_id' => $this->pro, 'starts_at' => self::DAY . ' 13:00:00', 'ends_at' => self::DAY . ' 15:00:00']);
        $slots = $this->slots();
        $this->assertNotContains('13:00', $slots);
        $this->assertNotContains('14:00', $slots);
        $this->assertContains('15:00', $slots);
        $this->assertThrows(ValidationException::class, fn () => $this->book('14:00'));
    }

    public function testTravelTimeBlocksAgendaAroundClientLocationBooking(): void
    {
        $area = F::area(30, 5000);
        $b = $this->book('14:00', '11987654321', ['location_type' => 'client', 'service_area_id' => $area, 'address' => 'Rua das Flores, 100']);
        $this->assertSame(25000, (int) $b['price_cents'], 'Preço inclui a taxa de deslocamento');
        $alloc = Db::one('SELECT * FROM booking_allocations WHERE booking_id = ?', [$b['id']]);
        $this->assertSame(self::DAY . ' 13:30:00', $alloc['block_start']);
        $this->assertSame(self::DAY . ' 16:00:00', $alloc['block_end'], '15:00 + 30 volta + 30 intervalo');
        $slots = $this->slots();
        $this->assertNotContains('13:00', $slots, 'Estúdio 13:00–14:00 + intervalo invade o deslocamento');
        $this->assertNotContains('15:30', $slots);
        $this->assertContains('16:00', $slots);
    }

    public function testClientLocationRequiresAreaAndAddress(): void
    {
        $e = $this->assertThrows(ValidationException::class, fn () => $this->book('10:00', '11987654321', ['location_type' => 'client']));
        $this->assertTrue(isset($e->errors['service_area_id']));
        $this->assertTrue(isset($e->errors['address']));
    }

    public function testServiceLocationModeIsEnforced(): void
    {
        $studioOnly = F::service($this->pro, 60, 'studio');
        $area = F::area(20);
        $this->assertThrows(ValidationException::class, fn () => $this->book('10:00', '11987654321', [
            'service_id' => $studioOnly, 'location_type' => 'client', 'service_area_id' => $area, 'address' => 'Rua A, 1',
        ]));
        $this->assertSame([], $this->slots($area, 'client', $studioOnly));
    }

    public function testMinimumAdvanceAppliesToPublicButNotToAdmin(): void
    {
        Clock::freeze(new DateTimeImmutable(self::DAY . ' 08:00'));
        $this->assertNotContains('10:00', $this->slots(), 'Com 24h de antecedência, nada no mesmo dia');
        $this->assertThrows(ValidationException::class, fn () => $this->book('10:00'));
        $b = $this->book('10:00', '11987654321', ['status' => 'confirmed'], 'admin');
        $this->assertSame('confirmed', $b['status']);
    }

    public function testMaximumAdvanceIsEnforced(): void
    {
        Settings::set('max_advance_days', '5');
        $this->assertSame([], $this->slots());
    }

    public function testOutsideWorkingHoursRejectedUnlessAdminOverrideButConflictsAlwaysBlocked(): void
    {
        $this->assertThrows(ValidationException::class, fn () => $this->book('19:00'));
        $this->assertThrows(ValidationException::class, fn () => $this->book('19:00', '11987654321', [], 'admin'));
        $b = $this->book('19:00', '11987654321', [], 'admin', true);
        $this->assertSame('19:00', substr($b['starts_at'], 11, 5));
        // Mesmo com a permissão de fora do horário, conflito continua proibido.
        $this->assertThrows(ValidationException::class, fn () => $this->book('19:30', '11911112222', [], 'admin', true));
    }

    public function testWithoutHoldingRequestsOnlyOneConflictingBookingCanBeConfirmed(): void
    {
        Settings::set('hold_pending_requests', '0');
        $a = $this->book('10:00', '11911110001');
        $b = $this->book('10:00', '11911110002');
        $this->bs->changeStatus((int) $a['id'], 'confirmed', null);
        $this->assertNotContains('10:00', $this->slots(), 'Depois de confirmar, o horário fica indisponível');
        $e = $this->assertThrows(DomainException::class, fn () => $this->bs->changeStatus((int) $b['id'], 'confirmed', null));
        $this->assertTrue(str_contains($e->getMessage(), 'Conflito'));
        $this->assertSame('requested', Db::value('SELECT status FROM bookings WHERE id = ?', [$b['id']]));
    }

    public function testConfirmationRechecksBlocksAddedAfterRequest(): void
    {
        $b = $this->book('10:00');
        Db::insert('schedule_blocks', ['professional_id' => $this->pro, 'starts_at' => self::DAY . ' 09:00:00', 'ends_at' => self::DAY . ' 12:00:00']);
        $this->assertThrows(DomainException::class, fn () => $this->bs->changeStatus((int) $b['id'], 'confirmed', null));
    }

    public function testFullStatusFlowAndHistory(): void
    {
        $b = $this->book('10:00');
        $id = (int) $b['id'];
        $this->bs->changeStatus($id, 'awaiting_deposit', null);
        $this->bs->changeStatus($id, 'confirmed', null);
        $this->bs->changeStatus($id, 'completed', null);
        $row = Db::one('SELECT status, confirmed_at FROM bookings WHERE id = ?', [$id]);
        $this->assertSame('completed', $row['status']);
        $this->assertTrue($row['confirmed_at'] !== null);
        $this->assertSame(4, (int) Db::value('SELECT COUNT(*) FROM booking_status_history WHERE booking_id = ?', [$id]));
    }

    public function testInvalidTransitionsAreRejected(): void
    {
        $b = $this->book('10:00');
        $this->assertThrows(DomainException::class, fn () => $this->bs->changeStatus((int) $b['id'], 'completed', null));
        $this->bs->changeStatus((int) $b['id'], 'cancelled', null);
        $this->assertThrows(DomainException::class, fn () => $this->bs->changeStatus((int) $b['id'], 'confirmed', null));
        $this->assertThrows(DomainException::class, fn () => $this->bs->changeStatus((int) $b['id'], 'status_inventado', null));
    }

    public function testRescheduleMovesAllocationAndChecksConflicts(): void
    {
        $a = $this->book('10:00', '11911110001');
        $this->book('14:00', '11911110002');
        $this->assertThrows(ValidationException::class, fn () => $this->bs->reschedule((int) $a['id'], self::DAY, '14:30', null));
        $this->bs->reschedule((int) $a['id'], self::DAY, '16:00', null);
        $alloc = Db::one('SELECT * FROM booking_allocations WHERE booking_id = ?', [$a['id']]);
        $this->assertSame(self::DAY . ' 16:00:00', $alloc['block_start']);
        $this->assertContains('10:00', $this->slots(), 'Horário antigo foi liberado');
        // Reagendar para o próprio horário atual não conflita consigo mesma.
        $this->bs->reschedule((int) $a['id'], self::DAY, '16:00', null);
    }

    public function testClientIsReusedByPhoneRegardlessOfFormat(): void
    {
        $this->book('10:00', '(11) 98765-4321');
        $this->book('14:00', '+55 11 98765-4321');
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM clients'));
        $this->assertSame('11987654321', Db::value('SELECT phone FROM clients'));
    }

    public function testPublicBookingRequiresPrivacyConsentAndValidPhone(): void
    {
        $in = F::bookingInput($this->svc, self::DAY, '10:00', '1234');
        unset($in['privacy']);
        $e = $this->assertThrows(ValidationException::class, fn () => $this->bs->create($in, 'public'));
        $this->assertTrue(isset($e->errors['privacy']));
        $this->assertTrue(isset($e->errors['phone']));
    }

    public function testClientCanCancelOnlyBeforeDeadline(): void
    {
        $b = $this->book('10:00');
        $this->bs->changeStatus((int) $b['id'], 'confirmed', null);
        Clock::freeze(new DateTimeImmutable('2030-01-07 12:00')); // 22h antes; prazo é 48h
        $this->assertThrows(DomainException::class, fn () => $this->bs->cancelByClient($b['public_code']));
        Clock::freeze(new DateTimeImmutable('2030-01-05 12:00'));
        $this->bs->cancelByClient($b['public_code']);
        $this->assertSame('cancelled', Db::value('SELECT status FROM bookings WHERE id = ?', [$b['id']]));
    }

    public function testInactiveServiceCannotBeBooked(): void
    {
        Db::update('services', ['active' => 0], 'id = ?', [$this->svc]);
        $this->assertSame([], $this->slots());
        $this->assertThrows(ValidationException::class, fn () => $this->book('10:00'));
    }

    public function testConcurrentRequestsForSameSlotOnlyOneSucceeds(): void
    {
        $worker = __DIR__ . '/../concurrency_worker.php';
        $go = microtime(true) + 1.0;
        $procs = [];
        for ($i = 0; $i < 4; $i++) {
            $cmd = sprintf('"%s" "%s" %d %s 10:00 1199999000%d %F', PHP_BINARY, $worker, $this->svc, self::DAY, $i, $go);
            $procs[] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
        }
        $out = [];
        foreach ($procs as $i => $p) {
            $out[] = trim(stream_get_contents($pipes[$i][1]) . stream_get_contents($pipes[$i][2]));
            proc_close($p);
        }
        $ok = count(array_filter($out, static fn ($o) => $o === 'OK'));
        $this->assertSame(1, $ok, 'Exatamente 1 deve conseguir. Saídas: ' . json_encode($out));
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM bookings'));
    }
}
