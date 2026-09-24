<?php
use App\Core\Auth;
use App\Core\View;

$row = static fn (array $b, bool $showDate = true): string => View::partial("booking_row", ["b" => $b, "showDate" => $showDate]);
?>
<header class="page-header">
    <h1>Visão geral</h1>
    <?php if (Auth::can('bookings.manage')): ?><a class="btn btn-primary" href="<?= e(url('/admin/reservas/nova')) ?>">+ Nova reserva</a><?php endif; ?>
</header>

<div class="stats">
    <a class="stat" href="<?= e(url('/admin/reservas', ['status' => 'requested'])) ?>"><span class="stat-n"><?= $stats['requested'] ?></span><span>Solicitações pendentes</span></a>
    <a class="stat" href="<?= e(url('/admin/reservas', ['status' => 'awaiting_deposit'])) ?>"><span class="stat-n"><?= $stats['awaiting_deposit'] ?></span><span>Aguardando sinal</span></a>
    <a class="stat" href="<?= e(url('/admin/agenda', ['visao' => 'dia'])) ?>"><span class="stat-n"><?= $stats['today'] ?></span><span>Atendimentos hoje</span></a>
    <a class="stat" href="<?= e(url('/admin/agenda', ['visao' => 'semana'])) ?>"><span class="stat-n"><?= $stats['week'] ?></span><span>Próximos 7 dias</span></a>
</div>

<div class="grid-2 gap-lg">
    <section class="panel">
        <h2>Precisam de resposta</h2>
        <?php if (!$pending): ?><p class="muted">Nenhuma solicitação pendente. 🎉</p><?php else: ?>
            <ul class="booking-list"><?php foreach ($pending as $b) echo $row($b); ?></ul>
        <?php endif; ?>
    </section>
    <section class="panel">
        <h2>Hoje</h2>
        <?php if (!$todayList): ?><p class="muted">Nenhum atendimento hoje.</p><?php else: ?>
            <ul class="booking-list"><?php foreach ($todayList as $b) echo $row($b, false); ?></ul>
        <?php endif; ?>
        <h2>Próximos dias</h2>
        <?php if (!$upcoming): ?><p class="muted">Nada confirmado para os próximos 7 dias.</p><?php else: ?>
            <ul class="booking-list"><?php foreach ($upcoming as $b) echo $row($b); ?></ul>
        <?php endif; ?>
    </section>
</div>

<section class="panel">
    <h2>Link de agendamento</h2>
    <p>Compartilhe no Instagram, WhatsApp e cartões:</p>
    <div class="copy-row">
        <input type="text" readonly value="<?= e($publicLink) ?>" id="public-link" aria-label="Link público de agendamento">
        <button type="button" class="btn btn-ghost" data-copy="#public-link">Copiar</button>
    </div>
</section>
