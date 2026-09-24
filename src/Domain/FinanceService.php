<?php
declare(strict_types=1);

namespace App\Domain;

use App\Core\Db;
use DateTimeImmutable;

/**
 * Financeiro: pagamentos (sinal/saldo), despesas, comissões e relatórios.
 *
 * Regras:
 * - Base da comissão = valor do atendimento − taxa de deslocamento (a taxa cobre o transporte).
 * - Comissão de cada profissional = base × divisão (%) × comissão (%).
 * - Resultado do atendimento = valor − comissões − despesas ligadas a ele.
 * - Faturamento do período = valor dos atendimentos CONCLUÍDOS com data no período.
 * - Recebido do período = pagamentos registrados com data no período (regime de caixa).
 */
final class FinanceService
{
    public const PAYMENT_KINDS = ['deposit' => 'Sinal', 'balance' => 'Saldo', 'other' => 'Outro'];
    public const METHODS = [
        'pix' => 'Pix', 'dinheiro' => 'Dinheiro', 'cartao_credito' => 'Cartão de crédito',
        'cartao_debito' => 'Cartão de débito', 'transferencia' => 'Transferência', 'outro' => 'Outro',
    ];
    public const EXPENSE_CATEGORIES = [
        'material' => 'Material/produtos', 'transporte' => 'Transporte', 'equipe' => 'Pagamento de equipe',
        'estudio' => 'Estúdio/aluguel', 'marketing' => 'Marketing', 'cursos' => 'Cursos', 'taxas' => 'Taxas e impostos', 'outro' => 'Outro',
    ];

    public static function commissionBase(array $booking): int
    {
        return max(0, (int) $booking['price_cents'] - (int) $booking['travel_fee_cents']);
    }

    public static function commission(int $base, float $sharePercent, float $commissionPercent): int
    {
        return (int) round($base * $sharePercent / 100 * $commissionPercent / 100);
    }

    /** Resumo financeiro de uma reserva. */
    public function bookingSummary(array $booking): array
    {
        $id = (int) $booking['id'];
        $payments = Db::all('SELECT p.*, u.name AS user_name FROM booking_payments p LEFT JOIN users u ON u.id = p.created_by WHERE p.booking_id = ? ORDER BY p.paid_on, p.id', [$id]);
        $expenses = Db::all('SELECT * FROM expenses WHERE booking_id = ? ORDER BY spent_on, id', [$id]);
        $base = self::commissionBase($booking);
        $allocs = Db::all(
            "SELECT a.*, p.name, p.color FROM booking_allocations a JOIN professionals p ON p.id = a.professional_id
             WHERE a.booking_id = ? ORDER BY a.role = 'lead' DESC, a.id",
            [$id]
        );
        foreach ($allocs as &$a) {
            $a['attributed_cents'] = (int) round($base * (float) $a['share_percent'] / 100);
            $a['commission_cents'] = self::commission($base, (float) $a['share_percent'], (float) $a['commission_percent']);
        }
        unset($a);

        $paid = array_sum(array_map(static fn ($p) => (int) $p['amount_cents'], $payments));
        $depositPaid = array_sum(array_map(static fn ($p) => $p['kind'] === 'deposit' ? (int) $p['amount_cents'] : 0, $payments));
        $expensesTotal = array_sum(array_map(static fn ($e) => (int) $e['amount_cents'], $expenses));
        $commissions = array_sum(array_column($allocs, 'commission_cents'));
        $price = (int) $booking['price_cents'];

        return [
            'price' => $price,
            'base' => $base,
            'paid' => $paid,
            'balance' => max(0, $price - $paid),
            'deposit' => (int) $booking['deposit_cents'],
            'deposit_paid' => $depositPaid,
            'deposit_ok' => $depositPaid >= (int) $booking['deposit_cents'],
            'payments' => $payments,
            'expenses' => $expenses,
            'expenses_total' => $expensesTotal,
            'allocations' => $allocs,
            'commissions' => $commissions,
            'result' => $price - $commissions - $expensesTotal,
        ];
    }

    /** @return array<string,string> erros */
    public function addPayment(int $bookingId, array $in, ?int $userId): array
    {
        $errors = [];
        $amount = parse_money((string) ($in['amount'] ?? ''));
        if (!$amount) {
            $errors['amount'] = 'Informe um valor maior que zero.';
        }
        $kind = (string) ($in['kind'] ?? '');
        if (!isset(self::PAYMENT_KINDS[$kind])) {
            $errors['kind'] = 'Tipo inválido.';
        }
        $method = (string) ($in['method'] ?? '');
        if (!isset(self::METHODS[$method])) {
            $errors['method'] = 'Forma de pagamento inválida.';
        }
        $date = self::date((string) ($in['paid_on'] ?? ''));
        if (!$date) {
            $errors['paid_on'] = 'Data inválida.';
        }
        if ($errors) {
            return $errors;
        }
        Db::insert('booking_payments', [
            'booking_id' => $bookingId,
            'kind' => $kind,
            'amount_cents' => $amount,
            'method' => $method,
            'paid_on' => $date,
            'note' => mb_substr(trim((string) ($in['note'] ?? '')), 0, 190) ?: null,
            'created_by' => $userId,
        ]);
        return [];
    }

    /** @return array<string,string> erros */
    public function addExpense(?int $bookingId, array $in, ?int $userId): array
    {
        $errors = [];
        $amount = parse_money((string) ($in['amount'] ?? ''));
        if (!$amount) {
            $errors['amount'] = 'Informe um valor maior que zero.';
        }
        $cat = (string) ($in['category'] ?? '');
        if (!isset(self::EXPENSE_CATEGORIES[$cat])) {
            $errors['category'] = 'Categoria inválida.';
        }
        $desc = trim((string) ($in['description'] ?? ''));
        if (mb_strlen($desc) < 2 || mb_strlen($desc) > 190) {
            $errors['description'] = 'Descreva a despesa.';
        }
        $date = self::date((string) ($in['spent_on'] ?? ''));
        if (!$date) {
            $errors['spent_on'] = 'Data inválida.';
        }
        if ($errors) {
            return $errors;
        }
        Db::insert('expenses', [
            'booking_id' => $bookingId,
            'category' => $cat,
            'description' => $desc,
            'amount_cents' => $amount,
            'spent_on' => $date,
            'created_by' => $userId,
        ]);
        return [];
    }

    /**
     * Relatório do período [de, até] (datas inclusivas).
     * $proId: restringe a uma profissional (usado também no escopo "somente as minhas").
     */
    public function report(string $from, string $to, ?int $proId = null): array
    {
        $f = $from . ' 00:00:00';
        $t = (new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
        $proSql = $proId ? ' AND EXISTS (SELECT 1 FROM booking_allocations x WHERE x.booking_id = b.id AND x.professional_id = ' . (int) $proId . ')' : '';

        $bookings = Db::all(
            "SELECT b.*, c.name AS client_name, c.source AS client_source, s.name AS service_name
             FROM bookings b JOIN clients c ON c.id = b.client_id JOIN services s ON s.id = b.service_id
             WHERE b.starts_at >= ? AND b.starts_at < ? $proSql ORDER BY b.starts_at",
            [$f, $t]
        );
        $ids = array_map(static fn ($b) => (int) $b['id'], $bookings);
        $allocsByBooking = [];
        $expByBooking = [];
        if ($ids) {
            $in = implode(',', $ids);
            foreach (Db::all("SELECT a.*, p.name FROM booking_allocations a JOIN professionals p ON p.id = a.professional_id WHERE a.booking_id IN ($in)") as $a) {
                $allocsByBooking[(int) $a['booking_id']][] = $a;
            }
            foreach (Db::all("SELECT booking_id, SUM(amount_cents) AS total FROM expenses WHERE booking_id IN ($in) GROUP BY booking_id") as $e) {
                $expByBooking[(int) $e['booking_id']] = (int) $e['total'];
            }
        }

        $counts = array_fill_keys(array_keys(BookingService::STATUS_LABELS), 0);
        $revenue = 0;
        $commissions = 0;
        $byPro = [];
        $byService = [];
        $rows = [];
        foreach ($bookings as $b) {
            $counts[$b['status']]++;
            $base = self::commissionBase($b);
            foreach ($allocsByBooking[(int) $b['id']] ?? [] as $a) {
                $pid = (int) $a['professional_id'];
                if ($proId && $pid !== $proId) {
                    continue;
                }
                $byPro[$pid] ??= ['name' => $a['name'], 'total' => 0, 'completed' => 0, 'cancelled' => 0, 'no_show' => 0, 'attributed' => 0, 'commission' => 0];
                $byPro[$pid]['total']++;
                if ($b['status'] === 'cancelled' || $b['status'] === 'no_show') {
                    $byPro[$pid][$b['status']]++;
                }
                if ($b['status'] === 'completed') {
                    $byPro[$pid]['completed']++;
                    $byPro[$pid]['attributed'] += (int) round($base * (float) $a['share_percent'] / 100);
                    $byPro[$pid]['commission'] += self::commission($base, (float) $a['share_percent'], (float) $a['commission_percent']);
                }
            }
            if ($b['status'] !== 'completed') {
                continue;
            }
            $bookingCommission = 0;
            foreach ($allocsByBooking[(int) $b['id']] ?? [] as $a) {
                if ($proId && (int) $a['professional_id'] !== $proId) {
                    continue;
                }
                $bookingCommission += self::commission($base, (float) $a['share_percent'], (float) $a['commission_percent']);
            }
            $exp = $proId ? 0 : ($expByBooking[(int) $b['id']] ?? 0);
            $revenue += (int) $b['price_cents'];
            $commissions += $bookingCommission;
            $label = $b['kind'] === 'event' ? 'Evento: ' . $b['event_name'] : $b['service_name'];
            $byService[$label] ??= ['count' => 0, 'revenue' => 0];
            $byService[$label]['count']++;
            $byService[$label]['revenue'] += (int) $b['price_cents'];
            $rows[] = [
                'id' => (int) $b['id'], 'date' => $b['starts_at'], 'client' => $b['client_name'], 'label' => $label,
                'price' => (int) $b['price_cents'], 'commission' => $bookingCommission, 'expenses' => $exp,
                'result' => (int) $b['price_cents'] - $bookingCommission - $exp,
            ];
        }
        uasort($byPro, static fn ($a, $b) => $b['attributed'] <=> $a['attributed']);
        uasort($byService, static fn ($a, $b) => $b['revenue'] <=> $a['revenue']);

        $expensesTotal = $proId ? 0 : (int) Db::value('SELECT COALESCE(SUM(amount_cents),0) FROM expenses WHERE spent_on BETWEEN ? AND ?', [$from, $to]);
        $received = $proId ? 0 : (int) Db::value('SELECT COALESCE(SUM(amount_cents),0) FROM booking_payments WHERE paid_on BETWEEN ? AND ?', [$from, $to]);
        $expensesByCategory = $proId ? [] : Db::all('SELECT category, SUM(amount_cents) AS total FROM expenses WHERE spent_on BETWEEN ? AND ? GROUP BY category ORDER BY total DESC', [$from, $to]);

        // Clientes atendidas/agendadas no período: novas × recorrentes e origem.
        $clients = [];
        foreach ($bookings as $b) {
            if ($b['status'] !== 'cancelled') {
                $clients[(int) $b['client_id']] = $b['client_source'];
            }
        }
        $recurring = 0;
        $origin = [];
        foreach ($clients as $cid => $source) {
            $before = (int) Db::value("SELECT COUNT(*) FROM bookings WHERE client_id = ? AND status = 'completed' AND starts_at < ?", [$cid, $f]);
            if ($before > 0) {
                $recurring++;
            }
            $key = BookingService::SOURCES[$source] ?? 'Não informado';
            $origin[$key] = ($origin[$key] ?? 0) + 1;
        }
        arsort($origin);

        $total = count($bookings);
        return [
            'counts' => $counts,
            'total' => $total,
            'cancel_rate' => $total ? ($counts['cancelled'] + $counts['no_show']) / $total : 0,
            'revenue' => $revenue,
            'received' => $received,
            'commissions' => $commissions,
            'expenses' => $expensesTotal,
            'expenses_by_category' => $expensesByCategory,
            'result' => $revenue - $commissions - $expensesTotal,
            'by_professional' => $byPro,
            'by_service' => $byService,
            'clients_total' => count($clients),
            'clients_recurring' => $recurring,
            'clients_new' => count($clients) - $recurring,
            'origin' => $origin,
            'rows' => $rows,
        ];
    }

    private static function date(string $d): ?string
    {
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $d);
        return $dt && $dt->format('Y-m-d') === $d ? $d : null;
    }
}
