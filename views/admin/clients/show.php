<?php
use App\Core\Auth;
use App\Domain\BookingService;
?>
<header class="page-header">
    <div>
        <p class="eyebrow">Cliente</p>
        <h1><?= e($c['name']) ?></h1>
    </div>
    <div class="header-actions">
        <?php if (Auth::can('bookings.manage')): ?><a class="btn btn-primary" href="<?= e(url('/admin/reservas/nova', ['cliente' => $c['id']])) ?>">+ Reserva</a><?php endif; ?>
        <?php if ($canManage): ?><a class="btn btn-ghost" href="<?= e(url('/admin/clientes/' . $c['id'] . '/editar')) ?>">Editar</a><?php endif; ?>
    </div>
</header>

<div class="stats">
    <div class="stat"><span class="stat-n"><?= $stats['total'] ?></span><span>Reservas</span></div>
    <div class="stat"><span class="stat-n"><?= $stats['completed'] ?></span><span>Concluídas</span></div>
    <div class="stat"><span class="stat-n"><?= $stats['cancelled'] ?></span><span>Canceladas / faltas</span></div>
    <div class="stat"><span class="stat-n stat-money"><?= e(money($stats['spent'])) ?></span><span>Em atendimentos concluídos</span></div>
</div>

<div class="grid-2 gap-lg">
    <section class="panel">
        <h2>Dados</h2>
        <dl class="details">
            <div><dt>WhatsApp</dt><dd><a href="<?= e(whatsapp_link($c['phone'])) ?>" target="_blank" rel="noopener"><?= e(phone_br($c['phone'])) ?></a></dd></div>
            <?php if ($c['email']): ?><div><dt>E-mail</dt><dd><?= e($c['email']) ?></dd></div><?php endif; ?>
            <?php if ($c['instagram']): ?><div><dt>Instagram</dt><dd>@<?= e($c['instagram']) ?></dd></div><?php endif; ?>
            <?php if ($c['birth_date']): ?><div><dt>Aniversário</dt><dd><?= e(date_br($c['birth_date'])) ?></dd></div><?php endif; ?>
            <div><dt>Como conheceu</dt><dd><?= e(BookingService::SOURCES[$c['source']] ?? '—') ?></dd></div>
            <div><dt>Cliente desde</dt><dd><?= e(date_br($c['created_at'])) ?></dd></div>
            <div><dt>Consentimento de dados</dt><dd><?= $c['privacy_accepted_at'] ? e(datetime_br($c['privacy_accepted_at'])) : '—' ?></dd></div>
        </dl>
        <?php if ($c['preferences']): ?><h3>Preferências</h3><p><?= nl2br(e($c['preferences'])) ?></p><?php endif; ?>
        <?php if ($c['notes']): ?><h3>Observações</h3><p><?= nl2br(e($c['notes'])) ?></p><?php endif; ?>
    </section>
    <section class="panel">
        <h2>Histórico de atendimentos</h2>
        <?php if (!$bookings): ?><p class="muted">Nenhuma reserva ainda.</p><?php else: ?>
        <ul class="booking-list">
            <?php foreach ($bookings as $b): ?>
                <li class="booking-row"><a href="<?= e(url('/admin/reservas/' . $b['id'])) ?>">
                    <span class="booking-when"><?= e(datetime_br($b['starts_at'])) ?></span>
                    <span class="booking-what"><?= e($b['service_name']) ?> · <?= e(money((int) $b['price_cents'])) ?></span>
                    <?= status_badge($b['status']) ?>
                </a></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </section>
</div>
