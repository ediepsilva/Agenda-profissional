<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Core\Response;
use App\Core\Session;
use App\Domain\BookingService;
use App\Domain\Clock;
use App\Domain\FinanceService;
use App\Domain\ValidationException;
use DomainException;

final class BookingController extends Controller
{
    private const PER_PAGE = 30;

    public function index(): void
    {
        $status = (string) ($_GET['status'] ?? '');
        $q = trim((string) ($_GET['q'] ?? ''));
        $period = in_array($_GET['periodo'] ?? '', ['proximas', 'passadas', 'todas'], true) ? $_GET['periodo'] : 'proximas';
        $page = max(1, (int) ($_GET['pagina'] ?? 1));
        $scope = Auth::professionalScope('bookings.view');
        $proFilter = $scope ?? (int) ($_GET['profissional'] ?? 0);

        $where = ['1=1'];
        $params = [];
        if (isset(BookingService::STATUS_LABELS[$status])) {
            $where[] = 'b.status = ?';
            $params[] = $status;
        }
        if (($_GET['tipo'] ?? '') === 'evento') {
            $where[] = "b.kind = 'event'";
        }
        if ($q !== '') {
            $digits = preg_replace('/\D/', '', $q);
            $where[] = $digits !== '' ? '(c.name LIKE ? OR c.phone LIKE ? OR b.event_name LIKE ?)' : '(c.name LIKE ? OR b.event_name LIKE ?)';
            $params[] = '%' . $q . '%';
            if ($digits !== '') {
                $params[] = '%' . $digits . '%';
            }
            $params[] = '%' . $q . '%';
        }
        $today = Clock::now()->format('Y-m-d');
        if ($period === 'proximas') {
            $where[] = 'b.starts_at >= ?';
            $params[] = $today;
        } elseif ($period === 'passadas') {
            $where[] = 'b.starts_at < ?';
            $params[] = $today;
        }
        [$scopeSql, $scopeParams] = $this->bookingScopeSql($proFilter ?: null);
        $whereSql = implode(' AND ', $where) . $scopeSql;
        $params = [...$params, ...$scopeParams];
        $order = $period === 'proximas' ? 'ASC' : 'DESC';

        $total = (int) Db::value("SELECT COUNT(*) FROM bookings b JOIN clients c ON c.id = b.client_id WHERE $whereSql", $params);
        $offset = ($page - 1) * self::PER_PAGE;
        $rows = Db::all(
            "SELECT b.*, c.name AS client_name, c.phone AS client_phone, s.name AS service_name, p.name AS professional_name, p.color AS professional_color,
                    (SELECT COUNT(*) FROM booking_allocations x WHERE x.booking_id = b.id) AS team_size
             FROM bookings b
             JOIN clients c ON c.id = b.client_id
             JOIN services s ON s.id = b.service_id
             JOIN professionals p ON p.id = b.professional_id
             WHERE $whereSql ORDER BY b.starts_at $order LIMIT " . self::PER_PAGE . " OFFSET $offset",
            $params
        );

        $this->view('admin/bookings/index', [
            'pageTitle' => 'Reservas',
            'rows' => $rows,
            'status' => $status,
            'q' => $q,
            'period' => $period,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / self::PER_PAGE)),
            'total' => $total,
            'scoped' => $scope !== null,
            'proFilter' => $scope === null ? $proFilter : 0,
            'professionals' => $scope === null ? $this->professionals() : [],
        ]);
    }

    public function create(): void
    {
        $this->view('admin/bookings/create', $this->formData('Nova reserva', true));
    }

    public function createEvent(): void
    {
        $this->view('admin/bookings/event_create', $this->formData('Novo evento', false));
    }

    private function formData(string $title, bool $onlyActiveServices): array
    {
        return [
            'pageTitle' => $title,
            'services' => Db::all('SELECT * FROM services' . ($onlyActiveServices ? ' WHERE active = 1' : '') . ' ORDER BY active DESC, sort_order, name'),
            'areas' => Db::all('SELECT * FROM service_areas WHERE active = 1 ORDER BY sort_order, name'),
            'clients' => Db::all('SELECT id, name, phone FROM clients ORDER BY name'),
            'professionals' => $this->professionals(),
            'preClient' => (int) ($_GET['cliente'] ?? 0),
            'preDate' => (string) ($_GET['data'] ?? ''),
        ];
    }

    public function store(): void
    {
        $in = $this->clientChoice($_POST);
        try {
            $b = (new BookingService())->create($in, 'admin', $this->userId(), !empty($_POST['allow_outside_hours']));
        } catch (ValidationException $e) {
            Response::back('/admin/reservas/nova', $e->errors, $_POST);
            return;
        }
        Session::flash('success', 'Reserva criada com ' . $b['professional_name'] . '.');
        Response::redirect('/admin/reservas/' . $b['id']);
    }

    public function storeEvent(): void
    {
        $in = $this->clientChoice($_POST);
        try {
            $b = (new BookingService())->createEvent($in, $this->userId(), !empty($_POST['allow_outside_hours']));
        } catch (ValidationException $e) {
            Response::back('/admin/eventos/novo', $e->errors, $_POST);
            return;
        }
        Session::flash('success', 'Evento criado.');
        Response::redirect('/admin/reservas/' . $b['id']);
    }

    private function clientChoice(array $in): array
    {
        if (($in['client_id'] ?? '') === 'nova') {
            unset($in['client_id']);
        }
        return $in;
    }

    /** JSON: horários livres (sem regra de antecedência). Profissional vazia = qualquer uma. */
    public function slots(): void
    {
        $duration = (int) $this->input('duracao');
        $slots = (new BookingService())->adminSlots(
            (int) $this->input('servico'),
            (int) $this->input('profissional'),
            $this->input('data'),
            $this->input('local', 'studio'),
            $this->input('area') !== '' ? (int) $this->input('area') : null,
            $duration > 0 ? $duration : null,
        );
        Response::json(['slots' => $slots]);
    }

    /** JSON: profissionais sugeridas para um horário (livres e com menor carga primeiro). */
    public function suggest(): void
    {
        $duration = (int) $this->input('duracao');
        $list = (new BookingService())->suggestProfessionals(
            (int) $this->input('servico'),
            $this->input('data'),
            $this->input('hora'),
            $duration > 0 ? $duration : null,
            $this->input('local', 'studio'),
            $this->input('area') !== '' ? (int) $this->input('area') : null,
            $this->input('reserva') !== '' ? (int) $this->input('reserva') : null,
        );
        Response::json(['professionals' => $list]);
    }

    public function show(string $id): void
    {
        $svc = new BookingService();
        $b = $svc->find((int) $id);
        if (!$b || !$this->canSee($b)) {
            $this->notFound();
            return;
        }
        $history = Db::all(
            'SELECT h.*, u.name AS user_name FROM booking_status_history h LEFT JOIN users u ON u.id = h.changed_by WHERE h.booking_id = ? ORDER BY h.id',
            [$b['id']]
        );
        $canManage = Auth::can('bookings.manage');
        $transitions = BookingService::TRANSITIONS[$b['status']] ?? [];
        if (!$canManage) {
            $transitions = Auth::can('bookings.status.own') ? array_values(array_intersect($transitions, BookingService::OWN_TRANSITIONS)) : [];
        }
        $finance = Auth::can('finance.manage') ? (new FinanceService())->bookingSummary($b) : null;
        $allocations = $svc->allocations((int) $b['id']);
        $suggestions = [];
        if ($canManage && in_array($b['status'], BookingService::OPEN, true)) {
            $start = new \DateTimeImmutable($b['starts_at']);
            $suggestions = $svc->suggestProfessionals(
                (int) $b['service_id'], $start->format('Y-m-d'), $start->format('H:i'),
                (int) round((strtotime($b['ends_at']) - strtotime($b['starts_at'])) / 60),
                $b['location_type'], $b['service_area_id'] !== null ? (int) $b['service_area_id'] : null, (int) $b['id']
            );
            $allocated = array_map(static fn ($a) => (int) $a['professional_id'], $allocations);
            $suggestions = array_values(array_filter($suggestions, static fn ($s) => !in_array($s['id'], $allocated, true)));
        }
        $this->view('admin/bookings/show', [
            'pageTitle' => ($b['kind'] === 'event' ? 'Evento' : 'Reserva') . ' #' . $b['id'],
            'b' => $b,
            'history' => $history,
            'transitions' => $transitions,
            'canManage' => $canManage,
            'allocations' => $allocations,
            'suggestions' => $suggestions,
            'finance' => $finance,
            'ownCommission' => $finance === null ? $this->ownCommission($b, $allocations) : null,
            'publicLink' => absolute_url('/reserva/' . $b['public_code']),
        ]);
    }

    public function status(string $id): void
    {
        $to = $this->input('to');
        $b = Db::one('SELECT * FROM bookings WHERE id = ?', [(int) $id]);
        if (!$b || !$this->canSee($b)) {
            $this->notFound();
            return;
        }
        if (!Auth::can('bookings.manage') && !in_array($to, BookingService::OWN_TRANSITIONS, true)) {
            Response::error(403, 'Acesso negado', 'Seu perfil só pode marcar atendimentos como concluídos ou não comparecimento.');
            return;
        }
        try {
            (new BookingService())->changeStatus((int) $id, $to, $this->userId(), $this->input('note') ?: null);
            Session::flash('success', 'Status alterado para "' . BookingService::label($to) . '".');
        } catch (DomainException $e) {
            Session::flash('error', $e->getMessage());
        }
        Response::redirect('/admin/reservas/' . (int) $id);
    }

    public function reschedule(string $id): void
    {
        try {
            (new BookingService())->reschedule((int) $id, $this->input('date'), $this->input('time'), $this->userId(), !empty($_POST['allow_outside_hours']));
            Session::flash('success', 'Reserva reagendada.');
        } catch (ValidationException $e) {
            Session::flash('error', implode(' ', $e->errors));
        } catch (DomainException $e) {
            Session::flash('error', $e->getMessage());
        }
        Response::redirect('/admin/reservas/' . (int) $id);
    }

    public function reassign(string $id): void
    {
        $this->teamAction($id, fn (BookingService $s) => $s->reassign((int) $id, (int) $this->input('from'), (int) $this->input('to'), $this->userId(), !empty($_POST['allow_outside_hours'])), 'Profissional trocada.');
    }

    public function addProfessional(string $id): void
    {
        $this->teamAction($id, fn (BookingService $s) => $s->addProfessional((int) $id, (int) $this->input('professional_id'), $this->userId(), !empty($_POST['allow_outside_hours'])), 'Profissional incluída no atendimento.');
    }

    public function removeProfessional(string $id): void
    {
        $this->teamAction($id, fn (BookingService $s) => $s->removeProfessional((int) $id, (int) $this->input('professional_id'), $this->userId()), 'Profissional removida do atendimento.');
    }

    private function teamAction(string $id, callable $fn, string $ok): void
    {
        try {
            $fn(new BookingService());
            Session::flash('success', $ok);
        } catch (DomainException | ValidationException $e) {
            Session::flash('error', $e->getMessage());
        }
        Response::redirect('/admin/reservas/' . (int) $id);
    }

    public function notes(string $id): void
    {
        $notes = mb_substr($this->input('internal_notes'), 0, 2000);
        Db::update('bookings', ['internal_notes' => $notes !== '' ? $notes : null], 'id = ?', [(int) $id]);
        Session::flash('success', 'Observações salvas.');
        Response::redirect('/admin/reservas/' . (int) $id);
    }

    /** Escopo "somente as minhas": só vê reservas em que está alocada. */
    private function canSee(array $b): bool
    {
        $scope = Auth::professionalScope('bookings.view');
        return $scope === null || (new BookingService())->isAllocated((int) $b['id'], $scope);
    }

    /** Comissão da própria maquiadora (quem não vê o financeiro completo). */
    private function ownCommission(array $b, array $allocations): ?array
    {
        $own = (int) (Auth::user()['professional_id'] ?? 0);
        foreach ($allocations as $a) {
            if ((int) $a['professional_id'] === $own) {
                $base = FinanceService::commissionBase($b);
                return [
                    'share' => (float) $a['share_percent'],
                    'percent' => (float) $a['commission_percent'],
                    'cents' => FinanceService::commission($base, (float) $a['share_percent'], (float) $a['commission_percent']),
                ];
            }
        }
        return null;
    }
}
