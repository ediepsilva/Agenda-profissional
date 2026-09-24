<?php
use App\Core\Auth;
use App\Domain\BookingService;

$link = static fn (array $over) => url('/admin/reservas', array_filter($over + ['status' => $status ?: null, 'q' => $q ?: null, 'periodo' => $period, 'profissional' => $proFilter ?: null], static fn ($v) => $v !== null && $v !== ''));
?>
<header class="page-header">
    <h1><?= $scoped ? 'Minhas reservas' : 'Reservas' ?></h1>
    <?php if (Auth::can('bookings.manage')): ?><div class="header-actions">
        <a class="btn btn-ghost" href="<?= e(url('/admin/eventos/novo')) ?>">+ Evento</a>
        <a class="btn btn-primary" href="<?= e(url('/admin/reservas/nova')) ?>">+ Nova reserva</a>
    </div><?php endif; ?>
</header>

<form method="get" action="<?= e(url('/admin/reservas')) ?>" class="filters panel">
    <div>
        <label for="f-q">Cliente</label>
        <input type="search" id="f-q" name="q" value="<?= e($q) ?>" placeholder="Nome, WhatsApp ou evento">
    </div>
    <div>
        <label for="f-status">Status</label>
        <select id="f-status" name="status">
            <option value="">Todos</option>
            <?php foreach (BookingService::STATUS_LABELS as $k => $label): ?>
                <option value="<?= e($k) ?>"<?= selected($status, $k) ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label for="f-periodo">Período</label>
        <select id="f-periodo" name="periodo">
            <option value="proximas"<?= selected($period, 'proximas') ?>>A partir de hoje</option>
            <option value="passadas"<?= selected($period, 'passadas') ?>>Anteriores</option>
            <option value="todas"<?= selected($period, 'todas') ?>>Todas</option>
        </select>
    </div>
    <?php if (!$scoped && count($professionals) > 1): ?>
    <div>
        <label for="f-prof">Profissional</label>
        <select id="f-prof" name="profissional">
            <option value="">Todas</option>
            <?php foreach ($professionals as $p): ?><option value="<?= (int) $p['id'] ?>"<?= selected($proFilter, $p['id']) ?>><?= e($p['name']) ?></option><?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
    <div class="filters-actions"><button class="btn btn-primary">Filtrar</button></div>
</form>

<p class="muted small"><?= $total ?> reserva(s)</p>

<?php if (!$rows): ?>
    <p class="muted">Nenhuma reserva encontrada.</p>
<?php else: ?>
<div class="table-wrap">
<table class="table">
    <thead><tr><th>Data</th><th>Cliente</th><th>Serviço</th><th>Profissional</th><th>Local</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $b): ?>
        <tr>
            <td data-label="Data"><a href="<?= e(url('/admin/reservas/' . $b['id'])) ?>"><?= e(datetime_br($b['starts_at'])) ?></a></td>
            <td data-label="Cliente"><?= e($b['client_name']) ?><br><span class="muted small"><?= e(phone_br($b['client_phone'])) ?></span></td>
            <td data-label="Serviço"><?= $b['kind'] === 'event' ? '<span class="badge badge-event">Evento</span> ' . e($b['event_name']) : e($b['service_name']) ?></td>
            <td data-label="Profissional"><span class="dot" style="background: <?= e($b['professional_color']) ?>"></span> <?= e($b['professional_name']) ?><?= (int) $b['team_size'] > 1 ? ' <span class="muted small">+' . ((int) $b['team_size'] - 1) . '</span>' : '' ?></td>
            <td data-label="Local"><?= $b['location_type'] === 'client' ? 'Cliente' : 'Estúdio' ?></td>
            <td data-label="Status"><?= status_badge($b['status']) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php if ($pages > 1): ?>
    <nav class="pager" aria-label="Paginação">
        <?php if ($page > 1): ?><a class="btn btn-ghost btn-sm" href="<?= e($link(['pagina' => $page - 1])) ?>">‹ Anterior</a><?php endif; ?>
        <span class="muted small">Página <?= $page ?> de <?= $pages ?></span>
        <?php if ($page < $pages): ?><a class="btn btn-ghost btn-sm" href="<?= e($link(['pagina' => $page + 1])) ?>">Próxima ›</a><?php endif; ?>
    </nav>
<?php endif; ?>
<?php endif; ?>
