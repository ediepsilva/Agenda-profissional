<?php
use App\Core\Auth;
use App\Domain\BookingService;
?>
<header class="page-header">
    <h1>Clientes</h1>
    <?php if (Auth::can('clients.manage')): ?><a class="btn btn-primary" href="<?= e(url('/admin/clientes/nova')) ?>">+ Nova cliente</a><?php endif; ?>
</header>

<form method="get" action="<?= e(url('/admin/clientes')) ?>" class="filters panel">
    <div>
        <label for="q">Buscar</label>
        <input type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="Nome, WhatsApp ou e-mail">
    </div>
    <div class="filters-actions"><button class="btn btn-primary">Buscar</button></div>
</form>

<p class="muted small"><?= $total ?> cliente(s)</p>
<?php if (!$rows): ?>
    <p class="muted">Nenhuma cliente encontrada.</p>
<?php else: ?>
<div class="table-wrap">
<table class="table">
    <thead><tr><th>Nome</th><th>WhatsApp</th><th>Origem</th><th>Reservas</th><th>Último atendimento</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $c): ?>
        <tr>
            <td data-label="Nome"><a href="<?= e(url('/admin/clientes/' . $c['id'])) ?>"><strong><?= e($c['name']) ?></strong></a></td>
            <td data-label="WhatsApp"><?= e(phone_br($c['phone'])) ?></td>
            <td data-label="Origem"><?= e(BookingService::SOURCES[$c['source']] ?? '—') ?></td>
            <td data-label="Reservas"><?= (int) $c['bookings_count'] ?></td>
            <td data-label="Último"><?= $c['last_booking'] ? e(date_br($c['last_booking'])) : '—' ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php if ($pages > 1): ?>
    <nav class="pager" aria-label="Paginação">
        <?php if ($page > 1): ?><a class="btn btn-ghost btn-sm" href="<?= e(url('/admin/clientes', ['q' => $q, 'pagina' => $page - 1])) ?>">‹ Anterior</a><?php endif; ?>
        <span class="muted small">Página <?= $page ?> de <?= $pages ?></span>
        <?php if ($page < $pages): ?><a class="btn btn-ghost btn-sm" href="<?= e(url('/admin/clientes', ['q' => $q, 'pagina' => $page + 1])) ?>">Próxima ›</a><?php endif; ?>
    </nav>
<?php endif; ?>
<?php endif; ?>
