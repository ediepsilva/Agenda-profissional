<header class="page-header">
    <h1>Serviços</h1>
    <a class="btn btn-primary" href="<?= e(url('/admin/servicos/novo')) ?>">+ Novo serviço</a>
</header>

<?php if (!$services): ?>
    <div class="panel empty">
        <p>Nenhum serviço cadastrado ainda.</p>
        <a class="btn btn-primary" href="<?= e(url('/admin/servicos/novo')) ?>">Cadastrar o primeiro serviço</a>
    </div>
<?php else: ?>
<div class="table-wrap">
<table class="table">
    <thead><tr><th>Serviço</th><th>Duração</th><th>A partir de</th><th>Sinal</th><th>Local</th><th>Situação</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($services as $s): ?>
        <tr<?= $s['active'] ? '' : ' class="inactive"' ?>>
            <td data-label="Serviço"><strong><?= e($s['name']) ?></strong><?= $s['category'] ? '<br><span class="muted small">' . e($s['category']) . '</span>' : '' ?></td>
            <td data-label="Duração"><?= e(duration_br((int) $s['duration_minutes'])) ?></td>
            <td data-label="A partir de"><?= e(money((int) $s['price_cents'])) ?></td>
            <td data-label="Sinal"><?= $s['deposit_cents'] !== null ? e(money((int) $s['deposit_cents'])) : '<span class="muted">' . e(setting('deposit_percent')) . '%</span>' ?></td>
            <td data-label="Local"><?= e(App\Controllers\ServiceController::LOCATION_MODES[$s['location_mode']]) ?></td>
            <td data-label="Situação"><?= $s['active'] ? '<span class="badge badge-confirmed">Ativo</span>' : '<span class="badge badge-cancelled">Inativo</span>' ?></td>
            <td class="actions">
                <a class="btn btn-ghost btn-sm" href="<?= e(url('/admin/servicos/' . $s['id'] . '/editar')) ?>">Editar</a>
                <form method="post" action="<?= e(url('/admin/servicos/' . $s['id'] . '/excluir')) ?>" data-confirm="<?= (int) $s['bookings_count'] ? 'Este serviço tem reservas e será apenas desativado. Continuar?' : 'Excluir este serviço?' ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-ghost btn-sm"><?= (int) $s['bookings_count'] ? 'Desativar' : 'Excluir' ?></button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
