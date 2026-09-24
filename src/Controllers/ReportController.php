<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Domain\FinanceService;

final class ReportController extends Controller
{
    public function index(): void
    {
        [$from, $to] = FinanceController::period();
        $scope = Auth::professionalScope('reports.view');
        $proFilter = $scope ?? ((int) ($_GET['profissional'] ?? 0) ?: null);
        $this->view('admin/reports/index', [
            'pageTitle' => $scope !== null ? 'Meu desempenho' : 'Relatórios',
            'r' => (new FinanceService())->report($from, $to, $scope !== null ? ($scope ?: -1) : $proFilter),
            'from' => $from,
            'to' => $to,
            'scoped' => $scope !== null,
            'proFilter' => $scope === null ? (int) $proFilter : 0,
            'professionals' => $scope === null ? $this->professionals() : [],
        ]);
    }

    /** CSV (Excel pt-BR: separador ";" e vírgula decimal) dos atendimentos concluídos no período. */
    public function export(): void
    {
        [$from, $to] = FinanceController::period();
        $scope = Auth::professionalScope('reports.view');
        $proFilter = $scope ?? ((int) ($_GET['profissional'] ?? 0) ?: null);
        $r = (new FinanceService())->report($from, $to, $scope !== null ? ($scope ?: -1) : $proFilter);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="atendimentos_' . $from . '_a_' . $to . '.csv"');
        header('Cache-Control: no-store');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        $num = static fn (int $c) => number_format($c / 100, 2, ',', '');
        // Evita injeção de fórmulas ao abrir no Excel/Planilhas.
        $text = static fn (string $v) => preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
        fputcsv($out, ['Reserva', 'Data', 'Cliente', 'Serviço/Evento', 'Valor', 'Comissões', 'Despesas', 'Resultado'], ';');
        foreach ($r['rows'] as $row) {
            fputcsv($out, [
                $row['id'], datetime_br($row['date']), $text($row['client']), $text($row['label']),
                $num($row['price']), $num($row['commission']), $num($row['expenses']), $num($row['result']),
            ], ';');
        }
        fclose($out);
    }
}
