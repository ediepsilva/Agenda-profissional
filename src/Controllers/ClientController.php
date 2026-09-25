<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Core\Response;
use App\Core\Session;
use App\Domain\ClientService;
use App\Domain\ConsentService;
use App\Domain\ReferralService;

final class ClientController extends Controller
{
    private const PER_PAGE = 30;

    public function index(): void
    {
        $q = trim((string) ($_GET['q'] ?? ''));
        $page = max(1, (int) ($_GET['pagina'] ?? 1));
        $where = '1=1';
        $params = [];
        if ($q !== '') {
            $digits = preg_replace('/\D/', '', $q);
            $where = $digits !== '' ? '(c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ?)' : '(c.name LIKE ? OR c.email LIKE ?)';
            $params = $digits !== '' ? ["%$q%", "%$digits%", "%$q%"] : ["%$q%", "%$q%"];
        }
        // Escopo "próprio": apenas clientes com atendimento alocado à profissional.
        $scope = Auth::professionalScope('clients.view');
        if ($scope !== null) {
            $where .= ' AND EXISTS (SELECT 1 FROM bookings sb JOIN booking_allocations sa ON sa.booking_id = sb.id WHERE sb.client_id = c.id AND sa.professional_id = ?)';
            $params[] = $scope;
        }
        $total = (int) Db::value("SELECT COUNT(*) FROM clients c WHERE $where", $params);
        $offset = ($page - 1) * self::PER_PAGE;
        $rows = Db::all(
            "SELECT c.*,
                (SELECT COUNT(*) FROM bookings b WHERE b.client_id = c.id AND b.status NOT IN ('cancelled')) AS bookings_count,
                (SELECT MAX(b.starts_at) FROM bookings b WHERE b.client_id = c.id AND b.status NOT IN ('cancelled')) AS last_booking
             FROM clients c WHERE $where ORDER BY c.name LIMIT " . self::PER_PAGE . " OFFSET $offset",
            $params
        );
        $this->view('admin/clients/index', [
            'pageTitle' => 'Clientes',
            'rows' => $rows,
            'q' => $q,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / self::PER_PAGE)),
            'total' => $total,
        ]);
    }

    public function show(string $id): void
    {
        $c = Db::one('SELECT * FROM clients WHERE id = ?', [(int) $id]);
        $scope = Auth::professionalScope('clients.view');
        if (!$c || ($scope !== null && !Db::value('SELECT 1 FROM bookings b JOIN booking_allocations a ON a.booking_id = b.id WHERE b.client_id = ? AND a.professional_id = ? LIMIT 1', [$c['id'], $scope]))) {
            $this->notFound();
            return;
        }
        [$scopeSql, $scopeParams] = $this->bookingScopeSql($scope);
        $bookings = Db::all(
            'SELECT b.*, s.name AS service_name, p.name AS professional_name
             FROM bookings b JOIN services s ON s.id = b.service_id JOIN professionals p ON p.id = b.professional_id
             WHERE b.client_id = ?' . $scopeSql . ' ORDER BY b.starts_at DESC',
            [$c['id'], ...$scopeParams]
        );
        $completed = array_filter($bookings, static fn ($b) => $b['status'] === 'completed');
        $this->view('admin/clients/show', [
            'pageTitle' => $c['name'],
            'c' => $c,
            'bookings' => $bookings,
            'stats' => [
                'total' => count(array_filter($bookings, static fn ($b) => $b['status'] !== 'cancelled')),
                'completed' => count($completed),
                'cancelled' => count(array_filter($bookings, static fn ($b) => in_array($b['status'], ['cancelled', 'no_show'], true))),
                'spent' => array_sum(array_map(static fn ($b) => (int) $b['price_cents'], $completed)),
            ],
            'canManage' => Auth::can('clients.manage'),
            'consent' => ConsentService::history((int) $c['id']),
            'messages' => Auth::can('messages.manage') ? Db::all('SELECT m.*, t.label FROM messages m JOIN message_templates t ON t.template_key = m.template_key WHERE m.client_id = ? ORDER BY m.id DESC LIMIT 30', [$c['id']]) : [],
            'referralLink' => ReferralService::link((int) $c['id']),
            'referredCount' => (int) Db::value('SELECT COUNT(*) FROM clients WHERE referred_by_client_id = ?', [$c['id']]),
            'referrer' => $c['referred_by_client_id'] ? Db::one('SELECT id, name FROM clients WHERE id = ?', [$c['referred_by_client_id']]) : null,
        ]);
    }

    /** Registro manual de consentimento (ex.: a cliente pediu pessoalmente). */
    public function consent(string $id): void
    {
        $c = Db::one('SELECT id FROM clients WHERE id = ?', [(int) $id]);
        if (!$c) {
            $this->notFound();
            return;
        }
        $optIn = ($_POST['marketing'] ?? '') === '1';
        $note = mb_substr($this->input('note'), 0, 190) ?: null;
        ConsentService::set((int) $c['id'], $optIn, 'painel', $note, $this->userId(), client_ip());
        Session::flash('success', $optIn ? 'Consentimento para ofertas registrado.' : 'Consentimento revogado: ela não receberá mais ofertas.');
        Response::redirect('/admin/clientes/' . $c['id']);
    }

    public function create(): void
    {
        $this->view('admin/clients/form', ['pageTitle' => 'Nova cliente', 'c' => null]);
    }

    public function edit(string $id): void
    {
        $c = Db::one('SELECT * FROM clients WHERE id = ?', [(int) $id]);
        if (!$c) {
            $this->notFound();
            return;
        }
        $this->view('admin/clients/form', ['pageTitle' => 'Editar cliente', 'c' => $c]);
    }

    public function store(): void
    {
        $errors = ClientService::validate($_POST, full: true);
        $data = ClientService::fromInput($_POST);
        if (!$errors && Db::value('SELECT id FROM clients WHERE phone = ?', [$data['phone']])) {
            $errors['phone'] = 'Já existe uma cliente com este WhatsApp.';
        }
        if ($errors) {
            Response::back('/admin/clientes/nova', $errors, $_POST);
            return;
        }
        $id = Db::insert('clients', $data);
        Session::flash('success', 'Cliente cadastrada.');
        Response::redirect('/admin/clientes/' . $id);
    }

    public function update(string $id): void
    {
        $id = (int) $id;
        $errors = ClientService::validate($_POST, full: true);
        $data = ClientService::fromInput($_POST);
        if (!$errors && Db::value('SELECT id FROM clients WHERE phone = ? AND id <> ?', [$data['phone'], $id])) {
            $errors['phone'] = 'Já existe outra cliente com este WhatsApp.';
        }
        if ($errors) {
            Response::back("/admin/clientes/$id/editar", $errors, $_POST);
            return;
        }
        Db::update('clients', $data, 'id = ?', [$id]);
        Session::flash('success', 'Dados da cliente atualizados.');
        Response::redirect('/admin/clientes/' . $id);
    }
}
