<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Core\Response;
use App\Core\Session;
use App\Domain\BookingService;
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

        $where = ['1=1'];
        $params = [];
        if (isset(BookingService::STATUS_LABELS[$status])) {
            $where[] = 'b.status = ?';
            $params[] = $status;
        }
        if ($q !== '') {
            $digits = preg_replace('/\D/', '', $q);
            $where[] = $digits !== '' ? '(c.name LIKE ? OR c.phone LIKE ?)' : 'c.name LIKE ?';
            $params[] = '%' . $q . '%';
            if ($digits !== '') {
                $params[] = '%' . $digits . '%';
            }
        }
        $today = date('Y-m-d');
        if ($period === 'proximas') {
            $where[] = 'b.starts_at >= ?';
            $params[] = $today;
        } elseif ($period === 'passadas') {
            $where[] = 'b.starts_at < ?';
            $params[] = $today;
        }
        $whereSql = implode(' AND ', $where);
        $order = $period === 'proximas' ? 'ASC' : 'DESC';

        $total = (int) Db::value("SELECT COUNT(*) FROM bookings b JOIN clients c ON c.id = b.client_id WHERE $whereSql", $params);
        $offset = ($page - 1) * self::PER_PAGE;
        $rows = Db::all(
            "SELECT b.*, c.name AS client_name, c.phone AS client_phone, s.name AS service_name, p.name AS professional_name, p.color AS professional_color
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
        ]);
    }

    public function create(): void
    {
        $this->view('admin/bookings/create', [
            'pageTitle' => 'Nova reserva',
            'services' => Db::all('SELECT * FROM services WHERE active = 1 ORDER BY sort_order, name'),
            'areas' => Db::all('SELECT * FROM service_areas WHERE active = 1 ORDER BY sort_order, name'),
            'clients' => Db::all('SELECT id, name, phone FROM clients ORDER BY name'),
            'professionals' => $this->professionals(),
            'preClient' => (int) ($_GET['cliente'] ?? 0),
            'preDate' => (string) ($_GET['data'] ?? ''),
        ]);
    }

    public function store(): void
    {
        $in = $_POST;
        if (($in['client_id'] ?? '') === 'nova') {
            unset($in['client_id']);
        }
        try {
            $b = (new BookingService())->create($in, 'admin', $this->userId(), !empty($_POST['allow_outside_hours']));
        } catch (ValidationException $e) {
            Response::back('/admin/reservas/nova', $e->errors, $_POST);
            return;
        }
        Session::flash('success', 'Reserva criada.');
        Response::redirect('/admin/reservas/' . $b['id']);
    }

    /** JSON: horários livres de uma profissional (sem regra de antecedência). */
    public function slots(): void
    {
        $slots = (new BookingService())->adminSlots(
            (int) $this->input('servico'),
            (int) $this->input('profissional'),
            $this->input('data'),
            $this->input('local', 'studio'),
            $this->input('area') !== '' ? (int) $this->input('area') : null,
        );
        Response::json(['slots' => $slots]);
    }

    public function show(string $id): void
    {
        $svc = new BookingService();
        $b = $svc->find((int) $id);
        if (!$b) {
            $this->notFound();
            return;
        }
        $history = Db::all(
            'SELECT h.*, u.name AS user_name FROM booking_status_history h LEFT JOIN users u ON u.id = h.changed_by WHERE h.booking_id = ? ORDER BY h.id',
            [$b['id']]
        );
        $this->view('admin/bookings/show', [
            'pageTitle' => 'Reserva #' . $b['id'],
            'b' => $b,
            'history' => $history,
            'transitions' => BookingService::TRANSITIONS[$b['status']] ?? [],
            'canManage' => Auth::can('bookings.manage'),
            'publicLink' => absolute_url('/reserva/' . $b['public_code']),
        ]);
    }

    public function status(string $id): void
    {
        $to = $this->input('to');
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

    public function notes(string $id): void
    {
        $notes = mb_substr($this->input('internal_notes'), 0, 2000);
        Db::update('bookings', ['internal_notes' => $notes !== '' ? $notes : null], 'id = ?', [(int) $id]);
        Session::flash('success', 'Observações salvas.');
        Response::redirect('/admin/reservas/' . (int) $id);
    }
}
