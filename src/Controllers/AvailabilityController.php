<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\Response;
use App\Core\Session;
use App\Domain\BookingService;
use App\Domain\Clock;
use DateTimeImmutable;

final class AvailabilityController extends Controller
{
    public const MAX_WINDOWS_PER_DAY = 3;

    public function index(): void
    {
        $proId = $this->currentProfessionalId();
        $rules = [];
        foreach (Db::all('SELECT * FROM availability_rules WHERE professional_id = ? ORDER BY weekday, start_time', [$proId ?? 0]) as $r) {
            $rules[(int) $r['weekday']][] = [substr($r['start_time'], 0, 5), substr($r['end_time'], 0, 5)];
        }
        $blocks = Db::all(
            'SELECT * FROM schedule_blocks WHERE professional_id = ? AND ends_at >= ? ORDER BY starts_at',
            [$proId ?? 0, Clock::now()->format('Y-m-d H:i:s')]
        );
        $this->view('admin/availability/index', [
            'pageTitle' => 'Disponibilidade',
            'professionals' => $this->professionals(),
            'proId' => $proId,
            'rules' => $rules,
            'blocks' => $blocks,
        ]);
    }

    public function saveRules(): void
    {
        $proId = $this->currentProfessionalId();
        $back = '/admin/disponibilidade?profissional=' . $proId;
        $input = (array) ($_POST['rules'] ?? []);
        $clean = [];
        $errors = [];

        for ($wd = 0; $wd <= 6; $wd++) {
            $windows = [];
            foreach ((array) ($input[$wd] ?? []) as $w) {
                $s = trim((string) ($w['start'] ?? ''));
                $e = trim((string) ($w['end'] ?? ''));
                if ($s === '' && $e === '') {
                    continue;
                }
                if (!self::validTime($s) || !self::validTime($e) || $e <= $s) {
                    $errors[] = weekday_name($wd) . ': horário inválido (o fim deve ser depois do início).';
                    continue;
                }
                $windows[] = [$s, $e];
            }
            usort($windows, static fn ($a, $b) => strcmp($a[0], $b[0]));
            for ($i = 1; $i < count($windows); $i++) {
                if ($windows[$i][0] < $windows[$i - 1][1]) {
                    $errors[] = weekday_name($wd) . ': os períodos se sobrepõem.';
                }
            }
            $clean[$wd] = array_slice($windows, 0, self::MAX_WINDOWS_PER_DAY);
        }

        if ($errors) {
            Session::flash('error', implode(' ', array_unique($errors)));
            Response::redirect($back);
            return;
        }

        Db::transaction(function () use ($proId, $clean) {
            Db::exec('DELETE FROM availability_rules WHERE professional_id = ?', [$proId]);
            foreach ($clean as $wd => $windows) {
                foreach ($windows as [$s, $e]) {
                    Db::insert('availability_rules', [
                        'professional_id' => $proId,
                        'weekday' => $wd,
                        'start_time' => $s . ':00',
                        'end_time' => ($e === '24:00' ? '23:59' : $e) . ':00',
                    ]);
                }
            }
        });
        Session::flash('success', 'Horários de atendimento salvos.');
        Response::redirect($back);
    }

    public function addBlock(): void
    {
        $proId = $this->currentProfessionalId();
        $back = '/admin/disponibilidade?profissional=' . $proId;
        $allDay = !empty($_POST['all_day']);
        $sd = $this->input('start_date');
        $ed = $this->input('end_date') ?: $sd;
        $st = $allDay ? '00:00' : $this->input('start_time');
        $et = $allDay ? '00:00' : $this->input('end_time');

        $start = DateTimeImmutable::createFromFormat('!Y-m-d H:i', "$sd $st");
        $end = DateTimeImmutable::createFromFormat('!Y-m-d H:i', "$ed $et");
        if ($start && $end && $allDay) {
            $end = $end->modify('+1 day');
        }
        if (!$start || !$end || $end <= $start) {
            Session::flash('error', 'Informe um período válido para o bloqueio.');
            Response::redirect($back);
            return;
        }

        Db::insert('schedule_blocks', [
            'professional_id' => $proId,
            'starts_at' => $start->format('Y-m-d H:i:s'),
            'ends_at' => $end->format('Y-m-d H:i:s'),
            'reason' => mb_substr($this->input('reason'), 0, 190) ?: null,
            'created_by' => $this->userId(),
        ]);

        $statuses = BookingService::blockingStatuses();
        $in = implode(',', array_fill(0, count($statuses), '?'));
        $overlap = (int) Db::value(
            "SELECT COUNT(*) FROM booking_allocations a JOIN bookings b ON b.id = a.booking_id
             WHERE a.professional_id = ? AND b.status IN ($in) AND a.block_start < ? AND a.block_end > ?",
            [$proId, ...$statuses, $end->format('Y-m-d H:i:s'), $start->format('Y-m-d H:i:s')]
        );
        Session::flash('success', 'Bloqueio adicionado.');
        if ($overlap > 0) {
            Session::flash('warning', "Atenção: há $overlap reserva(s) ativa(s) nesse período. Elas não foram alteradas — reagende ou cancele se necessário.");
        }
        Response::redirect($back);
    }

    public function deleteBlock(string $id): void
    {
        $block = Db::one('SELECT professional_id FROM schedule_blocks WHERE id = ?', [(int) $id]);
        Db::exec('DELETE FROM schedule_blocks WHERE id = ?', [(int) $id]);
        Session::flash('success', 'Bloqueio removido.');
        Response::redirect('/admin/disponibilidade?profissional=' . ($block['professional_id'] ?? ''));
    }

    private static function validTime(string $t): bool
    {
        return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t);
    }
}
