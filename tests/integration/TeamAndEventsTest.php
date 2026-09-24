<?php
declare(strict_types=1);

use App\Core\Db;
use App\Domain\BookingService;
use App\Domain\Clock;
use App\Domain\TeamService;
use App\Domain\ValidationException;
use Tests\DbTestCase;
use Tests\Fixtures as F;

/**
 * Equipe: distribuição de atendimentos, troca/inclusão de profissionais,
 * eventos com várias profissionais e cadastro da equipe.
 * "Agora" = terça 01/01/2030 08:00; data-alvo = terça 08/01/2030.
 */
final class TeamAndEventsTest extends DbTestCase
{
    private const DAY = '2030-01-08';
    private int $ana;
    private int $bia;
    private int $svc;
    private BookingService $bs;

    public function setUp(): void
    {
        parent::setUp();
        Clock::freeze(new DateTimeImmutable('2030-01-01 08:00'));
        $this->ana = F::professional(null, 'Ana');
        $this->bia = F::professional(null, 'Bia');
        Db::update('professionals', ['commission_percent' => 40], 'id = ?', [$this->bia]);
        $this->svc = F::service($this->ana, 60);
        Db::insert('professional_services', ['professional_id' => $this->bia, 'service_id' => $this->svc]);
        foreach ([$this->ana, $this->bia] as $p) {
            F::availability($p, 2, [['09:00', '18:00']]);
        }
        $this->bs = new BookingService();
    }

    private function book(string $time, string $phone, array $extra = [], string $channel = 'public'): array
    {
        return $this->bs->create(F::bookingInput($this->svc, self::DAY, $time, $phone, $extra), $channel);
    }

    private function event(array $pros, string $time = '09:00', int $duration = 240, array $extra = []): array
    {
        return $this->bs->createEvent($extra + [
            'service_id' => $this->svc, 'event_name' => 'Casamento Teste', 'date' => self::DAY, 'time' => $time,
            'duration_minutes' => $duration, 'people_count' => 5, 'location_type' => 'studio', 'price' => '2.000,00',
            'professional_ids' => $pros, 'name' => 'Noiva', 'phone' => '11990000000', 'status' => 'confirmed',
        ], null);
    }

    // ---------- distribuição

    public function testAutomaticAssignmentBalancesLoad(): void
    {
        $first = $this->book('09:00', '11990000001');
        $second = $this->book('14:00', '11990000002');
        $this->assertTrue((int) $first['professional_id'] !== (int) $second['professional_id'], 'O segundo atendimento vai para quem tem menos carga no dia');
    }

    public function testFallsBackToOtherProfessionalWhenFirstIsBusy(): void
    {
        $a = $this->book('10:00', '11990000001');
        $b = $this->book('10:00', '11990000002');
        $this->assertTrue((int) $a['professional_id'] !== (int) $b['professional_id']);
        $this->assertNotContains('10:00', array_keys($this->bs->availableSlots($this->svc, self::DAY, 'studio', null)), 'Com as duas ocupadas, o horário some');
        $this->assertContains('11:30', array_keys($this->bs->availableSlots($this->svc, self::DAY, 'studio', null)), '10:00 + 60 min + 30 de intervalo');
    }

    public function testSuggestionsListFreeProfessionalsFirst(): void
    {
        $this->book('10:00', '11990000001', ['professional_id' => $this->ana], 'admin');
        $list = $this->bs->suggestProfessionals($this->svc, self::DAY, '10:00');
        $this->assertSame($this->bia, $list[0]['id']);
        $this->assertTrue($list[0]['free']);
        $this->assertFalse($list[1]['free']);
    }

    public function testReassignChecksAgendaOfNewProfessional(): void
    {
        $x = $this->book('10:00', '11990000001', ['professional_id' => $this->ana], 'admin');
        $this->book('10:00', '11990000002', ['professional_id' => $this->bia], 'admin');
        $this->assertThrows(DomainException::class, fn () => $this->bs->reassign((int) $x['id'], $this->ana, $this->bia, null));

        $y = $this->book('15:00', '11990000003', ['professional_id' => $this->ana], 'admin');
        $this->bs->reassign((int) $y['id'], $this->ana, $this->bia, null);
        $this->assertSame($this->bia, (int) Db::value('SELECT professional_id FROM bookings WHERE id = ?', [$y['id']]));
        $this->assertSame([$this->bia], $this->bs->allocatedIds((int) $y['id']));
        $this->assertSame('40.00', (string) Db::value('SELECT commission_percent FROM booking_allocations WHERE booking_id = ?', [$y['id']]), 'Comissão passa a ser a da nova profissional');
        $this->assertContains('15:00', array_keys($this->bs->availableSlots($this->svc, self::DAY, 'studio', null)), 'Agenda da Ana foi liberada');
    }

    public function testAreaRestrictedToOneProfessional(): void
    {
        $area = F::area(30);
        Db::update('service_areas', ['professional_id' => $this->bia], 'id = ?', [$area]);
        $b = $this->book('10:00', '11990000001', ['location_type' => 'client', 'service_area_id' => $area, 'address' => 'Rua X, 10']);
        $this->assertSame($this->bia, (int) $b['professional_id'], 'Só a Bia atende essa área');
        $this->assertNotContains('10:00', array_keys($this->bs->availableSlots($this->svc, self::DAY, 'client', $area)), 'Ana não cobre a área, então o horário fica indisponível');
    }

    // ---------- eventos

    public function testEventAllocatesAllProfessionalsWithEqualShares(): void
    {
        $e = $this->event([$this->ana, $this->bia]);
        $this->assertSame('event', $e['kind']);
        $this->assertSame(200000, (int) $e['price_cents']);
        $this->assertSame(self::DAY . ' 13:00:00', $e['ends_at']);
        $allocs = $this->bs->allocations((int) $e['id']);
        $this->assertSame(2, count($allocs));
        $this->assertSame('lead', $allocs[0]['role']);
        $this->assertSame('50.00', (string) $allocs[0]['share_percent']);
        // Evento 09:00–13:00 + 30 min de intervalo: nenhuma das duas fica livre antes das 13:30.
        $slots = array_keys($this->bs->availableSlots($this->svc, self::DAY, 'studio', null));
        $this->assertSame([], array_values(array_filter($slots, static fn ($t) => $t < '13:30')));
        $this->assertContains('13:30', $slots);
    }

    public function testEventRejectedWhenAnyProfessionalIsBusy(): void
    {
        $this->book('11:00', '11990000001', ['professional_id' => $this->bia], 'admin');
        $e = $this->assertThrows(ValidationException::class, fn () => $this->event([$this->ana, $this->bia]));
        $this->assertTrue(str_contains($e->errors['professional_ids'], 'Bia'), 'Mensagem diz quem está ocupada');
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM bookings'));
    }

    public function testEventRescheduleMovesWholeTeamAndChecksEveryone(): void
    {
        $e = $this->event([$this->ana, $this->bia], '09:00', 120);
        $this->book('15:00', '11990000001', ['professional_id' => $this->bia], 'admin');
        $this->assertThrows(ValidationException::class, fn () => $this->bs->reschedule((int) $e['id'], self::DAY, '14:00', null));
        $this->bs->reschedule((int) $e['id'], self::DAY, '12:00', null);
        foreach ($this->bs->allocations((int) $e['id']) as $a) {
            $this->assertSame(self::DAY . ' 12:00:00', $a['block_start']);
        }
    }

    public function testAddAndRemoveProfessionals(): void
    {
        $c = F::professional(null, 'Carla');
        F::availability($c, 2, [['09:00', '18:00']]);
        $e = $this->event([$this->ana], '09:00', 120);
        $this->bs->addProfessional((int) $e['id'], $this->bia, null);
        $this->bs->addProfessional((int) $e['id'], $c, null);
        $shares = array_map(static fn ($a) => (float) $a['share_percent'], $this->bs->allocations((int) $e['id']));
        $this->assertSame(100.0, round(array_sum($shares), 2), 'Divisão soma 100%');
        $this->assertThrows(DomainException::class, fn () => $this->bs->addProfessional((int) $e['id'], $c, null), 'Não duplica');

        // Removendo a responsável, a próxima assume.
        $this->bs->removeProfessional((int) $e['id'], $this->ana, null);
        $allocs = $this->bs->allocations((int) $e['id']);
        $this->assertSame('lead', $allocs[0]['role']);
        $this->assertSame((int) $allocs[0]['professional_id'], (int) Db::value('SELECT professional_id FROM bookings WHERE id = ?', [$e['id']]));
        $this->bs->removeProfessional((int) $e['id'], $c, null);
        $this->assertThrows(DomainException::class, fn () => $this->bs->removeProfessional((int) $e['id'], $this->bia, null), 'Não fica sem ninguém');
    }

    public function testAddProfessionalRespectsHerAgenda(): void
    {
        $e = $this->event([$this->ana], '09:00', 120);
        $this->book('10:00', '11990000001', ['professional_id' => $this->bia], 'admin');
        $this->assertThrows(DomainException::class, fn () => $this->bs->addProfessional((int) $e['id'], $this->bia, null));
    }

    public function testAllocationTermsMustSumHundred(): void
    {
        $e = $this->event([$this->ana, $this->bia]);
        $this->assertThrows(ValidationException::class, fn () => $this->bs->updateAllocationTerms((int) $e['id'], [
            $this->ana => ['share' => '70', 'commission' => '0'], $this->bia => ['share' => '20', 'commission' => '40'],
        ], null));
        $this->bs->updateAllocationTerms((int) $e['id'], [
            $this->ana => ['share' => '70', 'commission' => '0'], $this->bia => ['share' => '30', 'commission' => '45,5'],
        ], null);
        $this->assertSame('45.50', (string) Db::value('SELECT commission_percent FROM booking_allocations WHERE booking_id = ? AND professional_id = ?', [$e['id'], $this->bia]));
    }

    // ---------- cadastro da equipe

    public function testCreateMemberWithAgendaAndLogin(): void
    {
        $owner = F::user('owner', 'dona@teste.com');
        $r = (new TeamService())->save(null, [
            'name' => 'Duda', 'has_agenda' => '1', 'has_login' => '1', 'active' => '1', 'color' => '#336699',
            'commission_percent' => '35', 'services' => [$this->svc], 'email' => 'Duda@Teste.com', 'role' => 'artist',
            'password' => 'senha-da-duda-123', 'accepts_online_booking' => '1',
        ], $owner);
        $pro = Db::one('SELECT * FROM professionals WHERE id = ?', [$r['professional_id']]);
        $this->assertSame((int) $r['user_id'], (int) $pro['user_id']);
        $this->assertSame('duda@teste.com', Db::value('SELECT email FROM users WHERE id = ?', [$r['user_id']]));
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM professional_services WHERE professional_id = ?', [$r['professional_id']]));
    }

    public function testTeamValidationRules(): void
    {
        $owner = F::user('owner', 'dona@teste.com');
        $team = new TeamService();
        $base = ['name' => 'X', 'has_login' => '1', 'active' => '1', 'role' => 'artist', 'email' => 'dona@teste.com', 'password' => 'curta'];
        $e = $this->assertThrows(ValidationException::class, fn () => $team->save(null, $base, $owner));
        $this->assertTrue(isset($e->errors['name'], $e->errors['email'], $e->errors['password']));

        $e = $this->assertThrows(ValidationException::class, fn () => $team->save(null, ['name' => 'Sem nada', 'active' => '1'], $owner));
        $this->assertTrue(isset($e->errors['has_agenda']));
    }

    public function testCannotRemoveLastOwnerOrChangeOwnRole(): void
    {
        $owner = F::user('owner', 'dona@teste.com');
        $team = new TeamService();
        $member = $team->find(null, $owner);
        $in = ['name' => 'Dona', 'has_login' => '1', 'active' => '1', 'role' => 'manager', 'email' => 'dona@teste.com'];
        $this->assertThrows(ValidationException::class, fn () => $team->save($member, $in, $owner), 'Não muda o próprio papel');

        $other = F::user('owner', 'outra@teste.com');
        // Outra dona tentando rebaixar a única dona ativa restante (após desativar a si mesma não é possível; aqui rebaixa a primeira).
        Db::update('users', ['active' => 0], 'id = ?', [$other]);
        $this->assertThrows(ValidationException::class, fn () => $team->save($member, $in, $other), 'Precisa sobrar uma dona ativa');
    }

    public function testDeactivatingProfessionalWarnsAboutFutureBookings(): void
    {
        $owner = F::user('owner', 'dona@teste.com');
        $this->book('10:00', '11990000001', ['professional_id' => $this->bia], 'admin');
        $team = new TeamService();
        $r = $team->save($team->find($this->bia, null), ['name' => 'Bia', 'has_agenda' => '1', 'active' => '', 'color' => '#123456', 'commission_percent' => '40'], $owner);
        $this->assertSame(1, count($r['warnings']));
        $this->assertSame(0, (int) Db::value('SELECT active FROM professionals WHERE id = ?', [$this->bia]));
        $this->assertSame([$this->ana], array_values(array_unique(array_merge(...array_values($this->bs->availableSlots($this->svc, self::DAY, 'studio', null))))), 'Profissional inativa não recebe mais reservas');
    }
}
