<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Domain\Clock;
use DateTimeImmutable;

final class AgendaController extends Controller
{
    public function index(): void
    {
        $view = in_array($_GET['visao'] ?? '', ['dia', 'semana', 'mes'], true) ? $_GET['visao'] : 'semana';
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($_GET['data'] ?? '')) ?: Clock::now()->setTime(0, 0);
        $proFilter = (int) ($_GET['profissional'] ?? 0);
        $showCancelled = ($_GET['canceladas'] ?? '') === '1';
        // Maquiadora (escopo "próprio") vê apenas a própria agenda, independentemente do filtro.
        $scope = Auth::professionalScope('agenda.view');
        if ($scope !== null) {
            $proFilter = $scope ?: -1;
        }

        [$from, $to, $prev, $next] = match ($view) {
            'dia' => [$date, $date->modify('+1 day'), $date->modify('-1 day'), $date->modify('+1 day')],
            'semana' => (function () use ($date) {
                $start = $date->modify('-' . $date->format('w') . ' days');
                return [$start, $start->modify('+7 days'), $date->modify('-7 days'), $date->modify('+7 days')];
            })(),
            'mes' => (function () use ($date) {
                $first = $date->modify('first day of this month');
                $gridStart = $first->modify('-' . $first->format('w') . ' days');
                $last = $date->modify('last day of this month');
                $gridEnd = $last->modify('+' . (7 - (int) $last->format('w')) . ' days');
                return [$gridStart, $gridEnd, $first->modify('-1 month'), $first->modify('+1 month')];
            })(),
        };

        $params = [$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')];
        $where = 'b.starts_at >= ? AND b.starts_at < ?';
        if (!$showCancelled) {
            $where .= " AND b.status <> 'cancelled'";
        }
        if ($proFilter) {
            $where .= ' AND EXISTS (SELECT 1 FROM booking_allocations a WHERE a.booking_id = b.id AND a.professional_id = ?)';
            $params[] = $proFilter;
        }
        $bookings = Db::all(
            "SELECT b.*, c.name AS client_name, s.name AS service_name, p.name AS professional_name, p.color AS professional_color
             FROM bookings b
             JOIN clients c ON c.id = b.client_id
             JOIN services s ON s.id = b.service_id
             JOIN professionals p ON p.id = b.professional_id
             WHERE $where ORDER BY b.starts_at",
            $params
        );

        $blockParams = [$to->format('Y-m-d H:i:s'), $from->format('Y-m-d H:i:s')];
        $blockWhere = 'sb.starts_at < ? AND sb.ends_at > ?';
        if ($proFilter) {
            $blockWhere .= ' AND sb.professional_id = ?';
            $blockParams[] = $proFilter;
        }
        $blocks = Db::all(
            "SELECT sb.*, p.name AS professional_name FROM schedule_blocks sb JOIN professionals p ON p.id = sb.professional_id WHERE $blockWhere ORDER BY sb.starts_at",
            $blockParams
        );

        // Equipe de cada atendimento (eventos podem ter várias profissionais).
        $team = [];
        if ($bookings) {
            $ids = implode(',', array_map(static fn ($b) => (int) $b['id'], $bookings));
            foreach (Db::all("SELECT a.booking_id, a.professional_id, p.name, p.color FROM booking_allocations a JOIN professionals p ON p.id = a.professional_id WHERE a.booking_id IN ($ids) ORDER BY a.role = 'lead' DESC, a.id") as $a) {
                $team[(int) $a['booking_id']][] = $a;
            }
        }

        // Agrupa por dia (bloqueios aparecem em todos os dias que atingem).
        $byDay = [];
        for ($d = $from; $d < $to; $d = $d->modify('+1 day')) {
            $byDay[$d->format('Y-m-d')] = ['bookings' => [], 'blocks' => []];
        }
        foreach ($bookings as $b) {
            $byDay[substr($b['starts_at'], 0, 10)]['bookings'][] = $b;
        }
        foreach ($blocks as $bl) {
            $s = new DateTimeImmutable($bl['starts_at']);
            $e = new DateTimeImmutable($bl['ends_at']);
            for ($d = $s->setTime(0, 0); $d < $e; $d = $d->modify('+1 day')) {
                $k = $d->format('Y-m-d');
                if (isset($byDay[$k])) {
                    $byDay[$k]['blocks'][] = $bl;
                }
            }
        }

        $this->view('admin/agenda/index', [
            'pageTitle' => 'Agenda',
            'viewMode' => $view,
            'date' => $date,
            'from' => $from,
            'to' => $to,
            'prev' => $prev,
            'next' => $next,
            'byDay' => $byDay,
            'proFilter' => $proFilter,
            'showCancelled' => $showCancelled,
            'professionals' => $scope === null ? $this->professionals() : [],
            'scoped' => $scope !== null,
            'team' => $team,
            'today' => Clock::now()->format('Y-m-d'),
        ]);
    }
}
