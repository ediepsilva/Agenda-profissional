<?php
use App\Domain\FinanceService;
?>
<header class="page-header">
    <h1>Despesas</h1>
    <a class="btn btn-ghost" href="<?= e(url('/admin/relatorios', ['de' => $from, 'ate' => $to])) ?>">Ver relatório do período</a>
</header>

<form method="get" action="<?= e(url('/admin/financeiro/despesas')) ?>" class="filters panel">
    <div><label for="de">De</label><input type="date" id="de" name="de" value="<?= e($from) ?>"></div>
    <div><label for="ate">Até</label><input type="date" id="ate" name="ate" value="<?= e($to) ?>"></div>
    <div class="filters-actions"><button class="btn btn-primary">Filtrar</button></div>
</form>

<section class="panel">
    <h2>Nova despesa</h2>
    <p class="muted small">Despesas de um atendimento específico podem ser lançadas direto na página da reserva.</p>
    <form method="post" action="<?= e(url('/admin/financeiro/despesas')) ?>" class="form">
        <?= csrf_field() ?>
        <div class="grid-4">
            <div><label for="e-amount">Valor (R$)</label><input type="text" id="e-amount" name="amount" inputmode="decimal" required value="<?= e(old('amount')) ?>"<?= invalid_attr($errors, 'amount') ?>><?= field_error($errors, 'amount') ?></div>
            <div><label for="e-cat">Categoria</label><select id="e-cat" name="category"><?php foreach (FinanceService::EXPENSE_CATEGORIES as $k => $l): ?><option value="<?= $k ?>"<?= selected(old('category'), $k) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
            <div><label for="e-date">Data</label><input type="date" id="e-date" name="spent_on" required value="<?= e(old('spent_on', date('Y-m-d'))) ?>"<?= invalid_attr($errors, 'spent_on') ?>><?= field_error($errors, 'spent_on') ?></div>
            <div><label for="e-desc">Descrição</label><input type="text" id="e-desc" name="description" maxlength="190" required value="<?= e(old('description')) ?>"<?= invalid_attr($errors, 'description') ?>><?= field_error($errors, 'description') ?></div>
        </div>
        <button class="btn btn-primary" type="submit">Registrar despesa</button>
    </form>
</section>

<section class="panel">
    <h2>Despesas de <?= e(date_br($from)) ?> a <?= e(date_br($to)) ?> · <?= e(money($total)) ?></h2>
    <?php if (!$rows): ?><p class="muted">Nenhuma despesa no período.</p><?php else: ?>
    <div class="table-wrap">
    <table class="table">
        <thead><tr><th>Data</th><th>Descrição</th><th>Categoria</th><th>Atendimento</th><th>Valor</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td data-label="Data"><?= e(date_br($r['spent_on'])) ?></td>
                <td data-label="Descrição"><?= e($r['description']) ?></td>
                <td data-label="Categoria"><?= e(FinanceService::EXPENSE_CATEGORIES[$r['category']] ?? $r['category']) ?></td>
                <td data-label="Atendimento"><?= $r['booking_id'] ? '<a href="' . e(url('/admin/reservas/' . $r['booking_id'])) . '">#' . (int) $r['booking_id'] . ' ' . e($r['event_name'] ?: $r['client_name']) . '</a>' : '<span class="muted">Geral</span>' ?></td>
                <td data-label="Valor"><?= e(money((int) $r['amount_cents'])) ?></td>
                <td class="actions">
                    <form method="post" action="<?= e(url('/admin/despesas/' . $r['id'] . '/excluir')) ?>" data-confirm="Remover esta despesa?"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit">Remover</button></form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</section>
