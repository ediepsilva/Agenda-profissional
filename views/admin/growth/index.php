<?php
use App\Controllers\GrowthController;
use App\Core\Auth;
use App\Domain\ReviewService;

$pctOpt = $totalClients ? round($optedIn / $totalClients * 100) : 0;
?>
<header class="page-header"><h1>Captação</h1></header>

<div class="stats">
    <div class="stat"><span class="stat-n"><?= e(number_format($publishedStats['average'], 1, ',', '')) ?> ★</span><span><?= $publishedStats['count'] ?> avaliação(ões) publicadas</span></div>
    <div class="stat"><span class="stat-n"><?= $reviewStats['count'] - $publishedStats['count'] ?></span><span>Avaliações a moderar/ocultas</span></div>
    <div class="stat"><span class="stat-n"><?= $referredBookings ?></span><span>Reservas por indicação (90 dias)</span></div>
    <div class="stat"><span class="stat-n"><?= $pctOpt ?>%</span><span>Clientes que aceitam ofertas (<?= $optedIn ?>)</span></div>
</div>

<section class="panel">
    <h2>Links de divulgação</h2>
    <p class="muted small">Use um link diferente em cada canal para saber de onde vêm as reservas.</p>
    <?php foreach ($links as $key => $link): ?>
        <div class="link-row">
            <label for="lk-<?= e($key) ?>"><?= e(GrowthController::LINK_ORIGINS[$key]) ?></label>
            <div class="copy-row">
                <input type="text" readonly id="lk-<?= e($key) ?>" value="<?= e($link) ?>">
                <button type="button" class="btn btn-ghost btn-sm" data-copy="#lk-<?= e($key) ?>">Copiar</button>
            </div>
        </div>
    <?php endforeach; ?>
</section>

<div class="grid-2 gap-lg">
    <section class="panel">
        <h2>Reservas por origem do link (90 dias)</h2>
        <?php if (!$byOrigin): ?><p class="muted">Sem reservas no período.</p><?php else: ?>
        <ul class="bar-list">
            <?php $max = max(array_map('intval', array_column($byOrigin, 'total'))) ?: 1; foreach ($byOrigin as $o): ?>
                <li><span class="bar-label"><?= e(GrowthController::LINK_ORIGINS[$o['origin']] ?? $o['origin']) ?></span><span class="bar"><span class="bar-fill" style="width: <?= round((int) $o['total'] / $max * 100) ?>%"></span></span><span class="bar-value"><?= (int) $o['total'] ?> (<?= (int) $o['completed'] ?> concl.)</span></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </section>
    <section class="panel">
        <h2>Quem mais indica</h2>
        <?php if (setting('referral_reward_text')): ?><p class="muted small">Recompensa: <?= e(setting('referral_reward_text')) ?></p><?php endif; ?>
        <?php if (!$ranking): ?><p class="muted">Nenhuma indicação ainda. O link de indicação de cada cliente vai na mensagem de agradecimento e aparece na página da reserva.</p><?php else: ?>
        <table class="table table-compact">
            <thead><tr><th>Cliente</th><th>Indicadas</th><th>Já atendidas</th></tr></thead>
            <tbody>
            <?php foreach ($ranking as $r): ?>
                <tr><td data-label="Cliente"><a href="<?= e(url('/admin/clientes/' . $r['id'])) ?>"><?= e($r['name']) ?></a></td><td data-label="Indicadas"><?= (int) $r['referred_clients'] ?></td><td data-label="Atendidas"><?= (int) $r['converted'] ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </section>
</div>

<section class="panel" id="avaliacoes">
    <h2>Avaliações</h2>
    <?php if (!$reviews): ?><p class="muted">Nenhuma avaliação ainda. O pedido de avaliação é enviado automaticamente após o atendimento concluído.</p><?php else: ?>
    <ul class="review-admin">
        <?php foreach ($reviews as $r): ?>
        <li class="review-<?= e($r['status']) ?>">
            <div>
                <span class="stars-static"><?= str_repeat('★', (int) $r['rating']) ?><span class="stars-off"><?= str_repeat('★', 5 - (int) $r['rating']) ?></span></span>
                <strong><?= e($r['client_name']) ?></strong> <span class="muted small">(aparece como "<?= e($r['display_name']) ?>") · <?= e(date_br($r['created_at'])) ?><?= $r['professional_name'] ? ' · ' . e($r['professional_name']) : '' ?></span>
                <?php if ($r['comment']): ?><p><?= nl2br(e($r['comment'])) ?></p><?php endif; ?>
                <span class="badge <?= $r['status'] === 'approved' ? 'badge-confirmed' : ($r['status'] === 'pending' ? 'badge-awaiting_deposit' : 'badge-cancelled') ?>"><?= e(ReviewService::STATUS_LABELS[$r['status']]) ?></span>
            </div>
            <?php if (Auth::can('reviews.manage')): ?>
            <div class="team-actions">
                <?php foreach (['approved' => 'Publicar', 'hidden' => 'Ocultar'] as $st => $label): if ($r['status'] === $st) continue; ?>
                <form method="post" action="<?= e(url('/admin/avaliacoes/' . $r['id'])) ?>"><?= csrf_field() ?><input type="hidden" name="status" value="<?= $st ?>"><button class="btn btn-ghost btn-sm"><?= $label ?></button></form>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</section>
