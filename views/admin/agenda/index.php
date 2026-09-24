<?php
use App\Core\Auth;

$q = static fn (array $over = []) => url('/admin/agenda', array_filter($over + [
    'visao' => $viewMode,
    'data' => $date->format('Y-m-d'),
    'profissional' => $proFilter ?: null,
    'canceladas' => $showCancelled ? '1' : null,
], static fn ($v) => $v !== null));

$title = match ($viewMode) {
    'dia' => date_long_br($date),
    'semana' => date_br($from) . ' a ' . date_br($to->modify('-1 day')),
    'mes' => ucfirst(month_name((int) $date->format('n'))) . ' de ' . $date->format('Y'),
};

$what = static fn (array $b) => $b['kind'] === 'event' ? '★ ' . $b['event_name'] : $b['service_name'];
$chip = static function (array $b, bool $long = false) use ($what, $team): string {
    $cls = 'chip-booking status-' . $b['status'];
    $n = count($team[(int) $b['id']] ?? []);
    $label = time_br($b['starts_at']) . ' ' . ($b['kind'] === 'event' && !$long ? '★ ' : '') . $b['client_name'] . ($long ? ' — ' . $what($b) : '') . ($n > 1 ? " ($n prof.)" : '');
    return '<a class="' . e($cls) . '" style="border-left-color:' . e($b['professional_color']) . '" href="' . e(url('/admin/reservas/' . $b['id'])) . '" title="' . e($b['service_name'] . ' · ' . $b['professional_name'] . ' · ' . App\Domain\BookingService::label($b['status'])) . '">' . e($label) . '</a>';
};
$blockChip = static fn (array $bl) => '<span class="chip-block" title="' . e($bl['professional_name']) . '">⛔ ' . e(time_br($bl['starts_at']) . '–' . time_br($bl['ends_at'])) . ($bl['reason'] ? ' · ' . e($bl['reason']) : '') . '</span>';
?>
<header class="page-header">
    <h1>Agenda</h1>
    <?php if (Auth::can('bookings.manage')): ?><div class="header-actions">
        <a class="btn btn-ghost" href="<?= e(url('/admin/eventos/novo', ['data' => $date->format('Y-m-d')])) ?>">+ Evento</a>
        <a class="btn btn-primary" href="<?= e(url('/admin/reservas/nova', ['data' => $date->format('Y-m-d')])) ?>">+ Nova reserva</a>
    </div><?php endif; ?>
</header>

<div class="toolbar">
    <nav class="tabs" aria-label="Visualização">
        <?php foreach (['dia' => 'Dia', 'semana' => 'Semana', 'mes' => 'Mês'] as $k => $label): ?>
            <a href="<?= e($q(['visao' => $k])) ?>"<?= $viewMode === $k ? ' aria-current="page" class="active"' : '' ?>><?= $label ?></a>
        <?php endforeach; ?>
    </nav>
    <div class="pager">
        <a class="btn btn-ghost btn-sm" href="<?= e($q(['data' => $prev->format('Y-m-d')])) ?>" aria-label="Anterior">‹</a>
        <a class="btn btn-ghost btn-sm" href="<?= e($q(['data' => $today])) ?>">Hoje</a>
        <a class="btn btn-ghost btn-sm" href="<?= e($q(['data' => $next->format('Y-m-d')])) ?>" aria-label="Próximo">›</a>
    </div>
    <form method="get" action="<?= e(url('/admin/agenda')) ?>" class="filters" data-autosubmit>
        <?php if (!$scoped): ?>
        <input type="hidden" name="visao" value="<?= e($viewMode) ?>">
        <input type="hidden" name="data" value="<?= e($date->format('Y-m-d')) ?>">
        <label class="sr-only" for="f-prof">Profissional</label>
        <select name="profissional" id="f-prof">
            <option value="">Todas as profissionais</option>
            <?php foreach ($professionals as $p): ?>
                <option value="<?= (int) $p['id'] ?>"<?= selected($proFilter, $p['id']) ?>><?= e($p['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <label class="check"><input type="checkbox" name="canceladas" value="1"<?= checked($showCancelled) ?>> Mostrar canceladas</label>
        <noscript><button class="btn btn-sm">Filtrar</button></noscript>
    </form>
</div>

<h2 class="agenda-title"><?= e($title) ?></h2>

<?php if ($viewMode === 'dia' && !$proFilter && count($professionals) > 1):
    $d = $byDay[$date->format('Y-m-d')] ?? ['bookings' => [], 'blocks' => []]; ?>
    <div class="team-day">
    <?php foreach ($professionals as $p): ?>
        <section class="team-col" style="border-top-color: <?= e($p['color']) ?>">
            <h3><span class="dot" style="background: <?= e($p['color']) ?>"></span> <?= e($p['name']) ?></h3>
            <?php
            $mine = array_filter($d['bookings'], static fn ($b) => in_array((int) $p['id'], array_map('intval', array_column($team[(int) $b['id']] ?? [], 'professional_id')), true));
            $myBlocks = array_filter($d['blocks'], static fn ($bl) => (int) $bl['professional_id'] === (int) $p['id']);
            foreach ($myBlocks as $bl) echo $blockChip($bl);
            foreach ($mine as $b) echo $chip($b, true);
            if (!$mine && !$myBlocks): ?><p class="muted small">Livre</p><?php endif; ?>
        </section>
    <?php endforeach; ?>
    </div>

<?php elseif ($viewMode === 'dia'):
    $d = $byDay[$date->format('Y-m-d')] ?? ['bookings' => [], 'blocks' => []]; ?>
    <div class="panel">
        <?php foreach ($d['blocks'] as $bl): ?><p><?= $blockChip($bl) ?></p><?php endforeach; ?>
        <?php if (!$d['bookings']): ?>
            <p class="muted">Nenhum atendimento neste dia.</p>
        <?php else: ?>
            <ul class="day-list">
            <?php foreach ($d['bookings'] as $b): ?>
                <li class="day-item status-<?= e($b['status']) ?>" style="border-left-color: <?= e($b['professional_color']) ?>">
                    <a href="<?= e(url('/admin/reservas/' . $b['id'])) ?>">
                        <span class="day-time"><?= e(time_br($b['starts_at'])) ?>–<?= e(time_br($b['ends_at'])) ?></span>
                        <span><strong><?= e($b['client_name']) ?></strong> · <?= e($what($b)) ?></span>
                        <span class="muted small"><?= e(implode(', ', array_column($team[(int) $b['id']] ?? [], 'name'))) ?> · <?= $b['location_type'] === 'client' ? 'No local da cliente' . ((int) $b['travel_minutes'] ? ' (+' . (int) $b['travel_minutes'] . ' min deslocamento)' : '') : 'Estúdio' ?></span>
                        <?= status_badge($b['status']) ?>
                    </a>
                </li>
            <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

<?php elseif ($viewMode === 'semana'): ?>
    <div class="week-grid">
        <?php foreach ($byDay as $day => $d): $dt = new DateTimeImmutable($day); ?>
            <section class="week-day<?= $day === $today ? ' is-today' : '' ?>">
                <h3><a href="<?= e($q(['visao' => 'dia', 'data' => $day])) ?>"><?= e(weekday_name((int) $dt->format('w'), true)) ?> <span><?= e($dt->format('d/m')) ?></span></a></h3>
                <?php foreach ($d['blocks'] as $bl) echo $blockChip($bl); ?>
                <?php foreach ($d['bookings'] as $b) echo $chip($b, true); ?>
                <?php if (!$d['bookings'] && !$d['blocks']): ?><p class="muted small">Livre</p><?php endif; ?>
            </section>
        <?php endforeach; ?>
    </div>

<?php else: ?>
    <div class="month-grid" role="grid">
        <?php for ($i = 0; $i < 7; $i++): ?><div class="month-head" role="columnheader"><?= e(weekday_name($i, true)) ?></div><?php endfor; ?>
        <?php foreach ($byDay as $day => $d): $dt = new DateTimeImmutable($day); $inMonth = $dt->format('m') === $date->format('m'); ?>
            <div class="month-cell<?= $inMonth ? '' : ' out' ?><?= $day === $today ? ' is-today' : '' ?>" role="gridcell">
                <a class="month-day" href="<?= e($q(['visao' => 'dia', 'data' => $day])) ?>"><?= e($dt->format('j')) ?></a>
                <?php if ($d['blocks']): ?><span class="chip-block">⛔ Bloqueio</span><?php endif; ?>
                <?php foreach (array_slice($d['bookings'], 0, 3) as $b) echo $chip($b); ?>
                <?php if (count($d['bookings']) > 3): ?><a class="more" href="<?= e($q(['visao' => 'dia', 'data' => $day])) ?>">+<?= count($d['bookings']) - 3 ?> mais</a><?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
