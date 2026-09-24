<?php
declare(strict_types=1);

use App\Domain\AvailabilityCalculator as Calc;
use Tests\TestCase;

final class AvailabilityCalculatorTest extends TestCase
{
    private function day(): DateTimeImmutable
    {
        return new DateTimeImmutable('2030-01-08');
    }

    private function at(string $hm): DateTimeImmutable
    {
        return new DateTimeImmutable('2030-01-08 ' . $hm);
    }

    /** @param DateTimeImmutable[] $slots */
    private function times(array $slots): array
    {
        return array_map(static fn ($s) => $s->format('H:i'), $slots);
    }

    public function testSlotsFillWindowRespectingDuration(): void
    {
        $slots = Calc::slots($this->day(), [['09:00', '12:00']], [], 60, 0, 0, 30);
        $this->assertSame(['09:00', '09:30', '10:00', '10:30', '11:00'], $this->times($slots));
    }

    public function testMultipleWindowsDoNotSpanTheGap(): void
    {
        $slots = $this->times(Calc::slots($this->day(), [['09:00', '12:00'], ['13:00', '15:00']], [], 60, 0, 0, 30));
        $this->assertNotContains('11:30', $slots, 'Atendimento de 11:30 terminaria no intervalo de almoço');
        $this->assertNotContains('12:00', $slots);
        $this->assertSame(['13:00', '13:30', '14:00'], array_values(array_slice($slots, -3)));
    }

    public function testTravelTimeMustFitInsideWorkingWindow(): void
    {
        // 30 min de deslocamento de ida e de volta.
        $slots = Calc::slots($this->day(), [['09:00', '12:00']], [], 60, 30, 0, 30);
        $this->assertSame(['09:30', '10:00', '10:30'], $this->times($slots));
    }

    public function testBusyIntervalAndBufferRemoveOverlappingSlots(): void
    {
        $busy = [[$this->at('10:00'), $this->at('11:30')]];
        $slots = Calc::slots($this->day(), [['09:00', '13:00']], $busy, 60, 0, 30, 30);
        // 09:00 ocuparia até 10:30 (60 + 30 de intervalo) e colidiria.
        $this->assertSame(['11:30', '12:00'], $this->times($slots));
    }

    public function testAdjacentAppointmentsAreNotAConflict(): void
    {
        $busy = [[$this->at('09:00'), $this->at('10:00')]];
        $slots = $this->times(Calc::slots($this->day(), [['09:00', '12:00']], $busy, 60, 0, 0, 30));
        $this->assertContains('10:00', $slots);
        $this->assertNotContains('09:30', $slots);
    }

    public function testTravelOfNewBookingCollidesWithPreviousAppointment(): void
    {
        // Compromisso até 10:00; novo atendimento com 40 min de deslocamento só pode começar às 10:40 → 11:00 na grade.
        $busy = [[$this->at('08:00'), $this->at('10:00')]];
        $slots = $this->times(Calc::slots($this->day(), [['08:00', '14:00']], $busy, 60, 40, 0, 30));
        $this->assertSame('11:00', $slots[0]);
    }

    public function testEarliestStartRespectsMinimumAdvance(): void
    {
        $slots = Calc::slots($this->day(), [['09:00', '12:00']], [], 60, 0, 0, 30, $this->at('10:15'));
        $this->assertSame(['10:30', '11:00'], $this->times($slots));
    }

    public function testLatestLimitStopsSlots(): void
    {
        $slots = Calc::slots($this->day(), [['09:00', '12:00']], [], 60, 0, 0, 30, null, $this->at('09:45'));
        $this->assertSame(['09:00', '09:30'], $this->times($slots));
    }

    public function testSlotGridIsAlignedToStep(): void
    {
        $slots = Calc::slots($this->day(), [['09:10', '11:00']], [], 30, 0, 0, 30);
        $this->assertSame(['09:30', '10:00', '10:30'], $this->times($slots));
    }

    public function testNoWindowsMeansNoSlots(): void
    {
        $this->assertSame([], Calc::slots($this->day(), [], [], 60, 0, 0, 30));
    }

    public function testOverlapIsHalfOpen(): void
    {
        $this->assertFalse(Calc::overlaps($this->at('09:00'), $this->at('10:00'), $this->at('10:00'), $this->at('11:00')));
        $this->assertTrue(Calc::overlaps($this->at('09:00'), $this->at('10:01'), $this->at('10:00'), $this->at('11:00')));
        $this->assertTrue(Calc::overlaps($this->at('09:00'), $this->at('12:00'), $this->at('10:00'), $this->at('11:00')));
    }

    public function testOccupationIncludesTravelAndBuffer(): void
    {
        [$a, $b] = Calc::occupation($this->at('10:00'), 60, 20, 30);
        $this->assertSame('09:40', $a->format('H:i'));
        $this->assertSame('11:50', $b->format('H:i'));
    }
}
