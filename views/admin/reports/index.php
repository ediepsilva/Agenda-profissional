<?php
use App\Domain\BookingService;
use App\Domain\FinanceService;

$pct = static fn (float $v) => number_format($v * 100, 1, ',', '') . '%';
$q = ['de' => $from, 'ate' => $to] + ($proFilter ? ['profissional' => $proFilter] : []);
$now = new DateTimeImmutable();
$presets = [
    'Este mês' => [$now->format('Y-m-01'), $now->format('Y-m-t')],
    'Mês passado' => [$now->modify('first day of last month')->format('Y-m-d'), $now->modify('last day of last month')->format('Y-m-d')],
    'Este ano' => [$now->format('Y-01-01'), $now->format('Y-12-31')],
];
// Visão de uma profissional (a própria, ou filtrada pela dona): mostra o que é dela, não os totais do negócio.
$proView = $scoped || $proFilter;
$mine = $proView ? (array_values($r['by_professional'])[0] ?? null) : null;
?>
<header class="page-header">
    <h1><?= e($pageTitle) ?></h1>
    <a class="btn btn-ghost" href="<?= e(url('/admin/relatorios/exportar', $q)) ?>">Exportar CSV</a>
</header>

<form method="get" action="<?= e(url('/admin/relatorios')) ?>" class="filters panel">
    <div><label for="de">De</label><input type="date" id="de" name="de" value="<?= e($from) ?>"></div>
    <div><label for="ate">Até</label><input type="date" id="ate" name="ate" value="<?= e($to) ?>"></div>
    <?php if (!$scoped && count($professionals) > 1): ?>
    <div>
        <label for="f-prof">Profissional</label>
        <select id="f-prof" name="profissional">
            <option value="">Todas (negócio)</option>
            <?php foreach ($professionals as $p): ?><option value="<?= (int) $p['id'] ?>"<?= selected($proFilter, $p['id']) ?>><?= e($p['name']) ?></option><?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
    <div class="filters-actions"><button class="btn btn-primary">Aplicar</button></div>
</form>
<p class="small">
    <?php foreach ($presets as $label => [$a, $b]): ?>
        <a class="btn btn-ghost btn-sm" href="<?= e(url('/admin/relatorios', ['de' => $a, 'ate' => $b] + ($proFilter ? ['profissional' => $proFilter] : []))) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
    <span class="muted">Período: <?= e(date_br($from)) ?> a <?= e(date_br($to)) ?></span>
</p>

<?php if ($proView): ?>
<div class="stats">
    <div class="stat"><span class="stat-n"><?= (int) ($mine['completed'] ?? 0) ?></span><span>Atendimentos concluídos</span></div>
    <div class="stat"><span class="stat-n stat-money"><?= e(money((int) ($mine['attributed'] ?? 0))) ?></span><span>Valor atribuído<?= $scoped ? ' a mim' : '' ?></span></div>
    <div class="stat"><span class="stat-n stat-money"><?= e(money((int) ($mine['commission'] ?? 0))) ?></span><span><?= $scoped ? 'Minhas comissões' : 'Comissões' ?></span></div>
    <div class="stat"><span class="stat-n"><?= (int) (($mine['cancelled'] ?? 0) + ($mine['no_show'] ?? 0)) ?></span><span>Cancelamentos / faltas</span></div>
</div>
<?php else: ?>
<div class="stats">
    <div class="stat"><span class="stat-n stat-money"><?= e(money($r['revenue'])) ?></span><span>Faturamento (concluídos)</span></div>
    <div class="stat"><span class="stat-n stat-money"><?= e(money($r['received'])) ?></span><span>Recebido no período</span></div>
    <div class="stat"><span class="stat-n stat-money"><?= e(money($r['commissions'] + $r['expenses'])) ?></span><span>Comissões + despesas</span></div>
    <div class="stat"><span class="stat-n stat-money <?= $r['result'] >= 0 ? 'text-ok' : 'text-danger' ?>"><?= e(money($r['result'])) ?></span><span>Resultado</span></div>
</div>
<div class="stats">
    <div class="stat"><span class="stat-n"><?= $r['total'] ?></span><span>Reservas no período</span></div>
    <div class="stat"><span class="stat-n"><?= $r['counts']['completed'] ?></span><span>Concluídas</span></div>
    <div class="stat"><span class="stat-n"><?= e($pct($r['cancel_rate'])) ?></span><span>Cancelamentos + faltas (<?= $r['counts']['cancelled'] + $r['counts']['no_show'] ?>)</span></div>
    <div class="stat"><span class="stat-n"><?= $r['clients_recurring'] ?> / <?= $r['clients_total'] ?></span><span>Clientes recorrentes / total</span></div>
</div>
<?php endif; ?>

<div class="grid-2 gap-lg">
    <section class="panel">
        <h2><?= $proView ? 'Resumo' : 'Desempenho da equipe' ?></h2>
        <?php if (!$r['by_professional']): ?><p class="muted">Sem atendimentos no período.</p><?php else: ?>
        <div class="table-wrap">
        <table class="table table-compact">
            <thead><tr><th>Profissional</th><th>Atend.</th><th>Concl.</th><th>Canc./faltas</th><th>Valor atribuído</th><th>Comissão</th></tr></thead>
            <tbody>
            <?php foreach ($r['by_professional'] as $p): ?>
                <tr>
                    <td data-label="Profissional"><?= e($p['name']) ?></td>
                    <td data-label="Atendimentos"><?= $p['total'] ?></td>
                    <td data-label="Concluídos"><?= $p['completed'] ?></td>
                    <td data-label="Canc./faltas"><?= $p['cancelled'] + $p['no_show'] ?></td>
                    <td data-label="Valor atribuído"><?= e(money($p['attributed'])) ?></td>
                    <td data-label="Comissão"><?= e(money($p['commission'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </section>

    <?php if (!$proView): ?>
    <section class="panel">
        <h2>Reservas por status</h2>
        <ul class="bar-list">
            <?php foreach ($r['counts'] as $st => $n): $w = $r['total'] ? round($n / $r['total'] * 100) : 0; ?>
                <li><span class="bar-label"><?= e(BookingService::label($st)) ?></span><span class="bar"><span class="bar-fill badge-<?= e($st) ?>" style="width: <?= $w ?>%"></span></span><span class="bar-value"><?= $n ?></span></li>
            <?php endforeach; ?>
        </ul>
    </section>

    <section class="panel">
        <h2>Serviços mais vendidos</h2>
        <?php if (!$r['by_service']): ?><p class="muted">Sem atendimentos concluídos.</p><?php else: ?>
        <ul class="bar-list">
            <?php $max = max(array_column($r['by_service'], 'revenue')) ?: 1; foreach ($r['by_service'] as $label => $s): ?>
                <li><span class="bar-label"><?= e($label) ?> <span class="muted small">(<?= $s['count'] ?>)</span></span><span class="bar"><span class="bar-fill" style="width: <?= round($s['revenue'] / $max * 100) ?>%"></span></span><span class="bar-value"><?= e(money($s['revenue'])) ?></span></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </section>

    <section class="panel">
        <h2>Como as clientes conheceram</h2>
        <p class="muted small"><?= $r['clients_new'] ?> nova(s) e <?= $r['clients_recurring'] ?> recorrente(s) no período.</p>
        <?php if (!$r['origin']): ?><p class="muted">Sem dados no período.</p><?php else: ?>
        <ul class="bar-list">
            <?php $max = max($r['origin']) ?: 1; foreach ($r['origin'] as $label => $n): ?>
                <li><span class="bar-label"><?= e($label) ?></span><span class="bar"><span class="bar-fill" style="width: <?= round($n / $max * 100) ?>%"></span></span><span class="bar-value"><?= $n ?></span></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </section>

    <section class="panel">
        <h2>Despesas por categoria</h2>
        <?php if (!$r['expenses_by_category']): ?><p class="muted">Nenhuma despesa no período.</p><?php else: ?>
        <ul class="bar-list">
            <?php $max = max(array_map('intval', array_column($r['expenses_by_category'], 'total'))) ?: 1; foreach ($r['expenses_by_category'] as $e): ?>
                <li><span class="bar-label"><?= e(FinanceService::EXPENSE_CATEGORIES[$e['category']] ?? $e['category']) ?></span><span class="bar"><span class="bar-fill bar-warn" style="width: <?= round((int) $e['total'] / $max * 100) ?>%"></span></span><span class="bar-value"><?= e(money((int) $e['total'])) ?></span></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </section>
    <?php endif; ?>
</div>

<section class="panel">
    <h2>Resultado por atendimento concluído</h2>
    <?php if (!$r['rows']): ?><p class="muted">Nenhum atendimento concluído no período.</p><?php else: ?>
    <div class="table-wrap">
    <table class="table table-compact">
        <thead><tr><th>Data</th><th>Cliente</th><th>Serviço / evento</th><?php if (!$proView): ?><th>Valor</th><?php endif; ?><th><?= $scoped ? 'Minha comissão' : ($proView ? 'Comissão' : 'Comissões') ?></th><?php if (!$proView): ?><th>Despesas</th><th>Resultado</th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($r['rows'] as $row): ?>
            <tr>
                <td data-label="Data"><a href="<?= e(url('/admin/reservas/' . $row['id'])) ?>"><?= e(date_br($row['date'])) ?></a></td>
                <td data-label="Cliente"><?= e($row['client']) ?></td>
                <td data-label="Serviço"><?= e($row['label']) ?></td>
                <?php if (!$proView): ?><td data-label="Valor"><?= e(money($row['price'])) ?></td><?php endif; ?>
                <td data-label="Comissão"><?= e(money($row['commission'])) ?></td>
                <?php if (!$proView): ?>
                <td data-label="Despesas"><?= e(money($row['expenses'])) ?></td>
                <td data-label="Resultado" class="<?= $row['result'] >= 0 ? '' : 'text-danger' ?>"><?= e(money($row['result'])) ?></td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</section>
<p class="muted small">Faturamento = valor dos atendimentos concluídos com data no período. Recebido = pagamentos registrados no período. Resultado = faturamento − comissões − despesas do período.</p>
