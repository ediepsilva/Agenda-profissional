<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\Response;
use App\Core\Session;
use App\Domain\BookingService;
use App\Domain\Clock;
use App\Domain\FinanceService;
use App\Domain\ValidationException;
use DomainException;

final class FinanceController extends Controller
{
    public function addPayment(string $id): void
    {
        $booking = Db::one('SELECT * FROM bookings WHERE id = ?', [(int) $id]);
        if (!$booking) {
            $this->notFound();
            return;
        }
        $fin = new FinanceService();
        $errors = $fin->addPayment((int) $id, $_POST, $this->userId());
        if ($errors) {
            Session::flash('error', implode(' ', $errors));
            Response::redirect('/admin/reservas/' . (int) $id);
            return;
        }
        Session::flash('success', 'Pagamento registrado.');

        // Sinal quitado: confirma automaticamente a reserva que aguardava o sinal.
        $summary = $fin->bookingSummary($booking);
        if ($booking['status'] === 'awaiting_deposit' && $summary['deposit_ok'] && !empty($_POST['auto_confirm'])) {
            try {
                (new BookingService())->changeStatus((int) $id, 'confirmed', $this->userId(), 'Confirmada após pagamento do sinal');
                Session::flash('success', 'Sinal quitado: reserva confirmada.');
            } catch (DomainException $e) {
                Session::flash('warning', 'Pagamento salvo, mas a reserva não pôde ser confirmada: ' . $e->getMessage());
            }
        }
        Response::redirect('/admin/reservas/' . (int) $id);
    }

    public function deletePayment(string $id): void
    {
        $p = Db::one('SELECT booking_id FROM booking_payments WHERE id = ?', [(int) $id]);
        if (!$p) {
            $this->notFound();
            return;
        }
        Db::exec('DELETE FROM booking_payments WHERE id = ?', [(int) $id]);
        Session::flash('success', 'Pagamento removido.');
        Response::redirect('/admin/reservas/' . $p['booking_id']);
    }

    public function addBookingExpense(string $id): void
    {
        if (!Db::value('SELECT id FROM bookings WHERE id = ?', [(int) $id])) {
            $this->notFound();
            return;
        }
        $errors = (new FinanceService())->addExpense((int) $id, $_POST, $this->userId());
        Session::flash($errors ? 'error' : 'success', $errors ? implode(' ', $errors) : 'Despesa registrada.');
        Response::redirect('/admin/reservas/' . (int) $id);
    }

    public function deleteExpense(string $id): void
    {
        $e = Db::one('SELECT booking_id FROM expenses WHERE id = ?', [(int) $id]);
        if (!$e) {
            $this->notFound();
            return;
        }
        Db::exec('DELETE FROM expenses WHERE id = ?', [(int) $id]);
        Session::flash('success', 'Despesa removida.');
        Response::redirect($e['booking_id'] ? '/admin/reservas/' . $e['booking_id'] : '/admin/financeiro/despesas');
    }

    /** Valor final (desconto/acréscimo) e sinal combinado. */
    public function bookingValues(string $id): void
    {
        $price = parse_money($this->input('price'));
        $deposit = parse_money($this->input('deposit', '0') ?: '0');
        if ($price === null || $deposit === null || $deposit > $price) {
            Session::flash('error', 'Valores inválidos (o sinal não pode ser maior que o valor total).');
            Response::redirect('/admin/reservas/' . (int) $id);
            return;
        }
        $b = Db::one('SELECT * FROM bookings WHERE id = ?', [(int) $id]);
        if (!$b) {
            $this->notFound();
            return;
        }
        Db::update('bookings', ['price_cents' => $price, 'deposit_cents' => $deposit], 'id = ?', [(int) $id]);
        Db::insert('booking_status_history', [
            'booking_id' => (int) $id, 'from_status' => $b['status'], 'to_status' => $b['status'], 'changed_by' => $this->userId(),
            'note' => 'Valor alterado de ' . money((int) $b['price_cents']) . ' para ' . money($price),
        ]);
        Session::flash('success', 'Valores atualizados.');
        Response::redirect('/admin/reservas/' . (int) $id);
    }

    public function allocationTerms(string $id): void
    {
        try {
            (new BookingService())->updateAllocationTerms((int) $id, (array) ($_POST['terms'] ?? []), $this->userId());
            Session::flash('success', 'Divisão e comissões atualizadas.');
        } catch (ValidationException | DomainException $e) {
            Session::flash('error', $e->getMessage());
        }
        Response::redirect('/admin/reservas/' . (int) $id);
    }

    public function expenses(): void
    {
        [$from, $to] = self::period();
        $rows = Db::all(
            'SELECT e.*, b.event_name, c.name AS client_name FROM expenses e
             LEFT JOIN bookings b ON b.id = e.booking_id LEFT JOIN clients c ON c.id = b.client_id
             WHERE e.spent_on BETWEEN ? AND ? ORDER BY e.spent_on DESC, e.id DESC',
            [$from, $to]
        );
        $this->view('admin/finance/expenses', [
            'pageTitle' => 'Despesas',
            'rows' => $rows,
            'total' => array_sum(array_map(static fn ($r) => (int) $r['amount_cents'], $rows)),
            'from' => $from,
            'to' => $to,
        ]);
    }

    public function addExpense(): void
    {
        $errors = (new FinanceService())->addExpense(null, $_POST, $this->userId());
        if ($errors) {
            Response::back('/admin/financeiro/despesas', $errors, $_POST);
            return;
        }
        Session::flash('success', 'Despesa registrada.');
        Response::redirect('/admin/financeiro/despesas');
    }

    /** Período do filtro (padrão: mês atual). @return array{0:string,1:string} */
    public static function period(): array
    {
        $now = Clock::now();
        $valid = static fn ($d) => is_string($d) && ($x = \DateTimeImmutable::createFromFormat('!Y-m-d', $d)) && $x->format('Y-m-d') === $d;
        $from = $valid($_GET['de'] ?? null) ? $_GET['de'] : $now->format('Y-m-01');
        $to = $valid($_GET['ate'] ?? null) ? $_GET['ate'] : $now->format('Y-m-t');
        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }
        return [$from, $to];
    }
}
