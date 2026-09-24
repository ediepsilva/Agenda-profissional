<?php
declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;

/**
 * Cálculo puro (sem banco) dos horários viáveis de um dia.
 *
 * Regra de ocupação de um atendimento que começa em S e dura D minutos:
 *   [S - deslocamento, S + D + deslocamento + intervalo]
 * - O deslocamento (ida e volta) precisa caber na janela de trabalho.
 * - O intervalo entre atendimentos só precisa estar livre de outros compromissos
 *   (pode ultrapassar o fim do expediente).
 * - Ocupações existentes (reservas e bloqueios) não podem se sobrepor.
 */
final class AvailabilityCalculator
{
    /**
     * @param array<int,array{0:string,1:string}> $windows janelas do dia, ex.: [['09:00','12:00'], ['13:00','19:00']]
     * @param array<int,array{0:DateTimeImmutable,1:DateTimeImmutable}> $busy intervalos já ocupados
     * @param ?DateTimeImmutable $earliest primeiro início permitido (antecedência mínima); null = sem limite
     * @param ?DateTimeImmutable $latest último instante permitido para o início; null = sem limite
     * @return DateTimeImmutable[] inícios possíveis, em ordem
     */
    public static function slots(
        DateTimeImmutable $day,
        array $windows,
        array $busy,
        int $durationMinutes,
        int $travelMinutes,
        int $bufferMinutes,
        int $stepMinutes,
        ?DateTimeImmutable $earliest = null,
        ?DateTimeImmutable $latest = null,
    ): array {
        $midnight = $day->setTime(0, 0);
        $step = max(5, $stepMinutes);
        $found = [];

        foreach ($windows as [$from, $to]) {
            $w0 = self::at($midnight, $from);
            $w1 = self::at($midnight, $to);
            if ($w1 <= $w0) {
                continue;
            }
            // Primeiro início alinhado à grade (ex.: 09:00, 09:30...) que comporta o deslocamento de ida.
            $minOffset = intdiv($w0->getTimestamp() - $midnight->getTimestamp(), 60) + $travelMinutes;
            $offset = (int) (ceil($minOffset / $step) * $step);

            for (; ; $offset += $step) {
                $start = $midnight->modify("+{$offset} minutes");
                $serviceEnd = $start->modify("+{$durationMinutes} minutes");
                if ($serviceEnd->modify("+{$travelMinutes} minutes") > $w1) {
                    break;
                }
                if ($earliest !== null && $start < $earliest) {
                    continue;
                }
                if ($latest !== null && $start > $latest) {
                    break;
                }
                [$o0, $o1] = self::occupation($start, $durationMinutes, $travelMinutes, $bufferMinutes);
                if (!self::conflicts($o0, $o1, $busy)) {
                    $found[$start->format('H:i')] = $start;
                }
            }
        }

        ksort($found);
        return array_values($found);
    }

    /** @return array{0:DateTimeImmutable,1:DateTimeImmutable} */
    public static function occupation(DateTimeImmutable $start, int $duration, int $travel, int $buffer): array
    {
        return [
            $start->modify("-{$travel} minutes"),
            $start->modify('+' . ($duration + $travel + $buffer) . ' minutes'),
        ];
    }

    /** @param array<int,array{0:DateTimeImmutable,1:DateTimeImmutable}> $busy */
    public static function conflicts(DateTimeImmutable $a0, DateTimeImmutable $a1, array $busy): bool
    {
        foreach ($busy as [$b0, $b1]) {
            if (self::overlaps($a0, $a1, $b0, $b1)) {
                return true;
            }
        }
        return false;
    }

    /** Intervalos semiabertos: encostar (fim = início) não é conflito. */
    public static function overlaps(DateTimeImmutable $a0, DateTimeImmutable $a1, DateTimeImmutable $b0, DateTimeImmutable $b1): bool
    {
        return $a0 < $b1 && $b0 < $a1;
    }

    private static function at(DateTimeImmutable $midnight, string $hhmm): DateTimeImmutable
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm));
        return $midnight->modify('+' . ($h * 60 + $m) . ' minutes');
    }
}
