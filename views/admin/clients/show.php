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

        <h3>Ofertas e novidades</h3>
        <p><?= (int) $c['marketing_opt_in'] ? '<span class="badge badge-confirmed">Aceita receber ofertas</span>' : '<span class="badge badge-cancelled">Não recebe ofertas</span>' ?>
            <span class="muted small">Avisos das reservas são enviados em qualquer caso.</span></p>
        <?php if ($canManage): ?>
        <details>
            <summary><?= (int) $c['marketing_opt_in'] ? 'Registrar revogação' : 'Registrar consentimento' ?></summary>
            <form method="post" action="<?= e(url('/admin/clientes/' . $c['id'] . '/consentimento')) ?>" class="form">
                <?= csrf_field() ?>
                <input type="hidden" name="marketing" value="<?= (int) $c['marketing_opt_in'] ? '0' : '1' ?>">
                <label for="consent-note">Como a cliente pediu</label>
                <input type="text" id="consent-note" name="note" maxlength="190" required placeholder="Ex.: pediu pessoalmente no atendimento de 10/05">
                <button class="btn btn-ghost btn-sm" type="submit">Salvar</button>
            </form>
        </details>
        <?php endif; ?>
        <?php if ($consent): ?>
            <ul class="timeline small">
                <?php foreach ($consent as $l): ?>
                    <li><?= e(datetime_br($l['created_at'])) ?> · <strong><?= $l['action'] === 'opt_in' ? 'Aceitou' : 'Revogou' ?></strong> · <?= e(App\Domain\ConsentService::CHANNELS[$l['channel']] ?? $l['channel']) ?><?= $l['note'] ? ' · ' . e($l['note']) : '' ?><?= $l['user_name'] ? ' (' . e($l['user_name']) . ')' : '' ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <h3>Indicações</h3>
        <?php if ($referrer): ?><p>Indicada por <a href="<?= e(url('/admin/clientes/' . $referrer['id'])) ?>"><?= e($referrer['name']) ?></a>.</p><?php endif; ?>
        <p>Indicou <strong><?= $referredCount ?></strong> cliente(s). Link dela:</p>
        <div class="copy-row">
            <input type="text" readonly id="ref-link" value="<?= e($referralLink) ?>" aria-label="Link de indicação">
            <button type="button" class="btn btn-ghost btn-sm" data-copy="#ref-link">Copiar</button>
        </div>
    </section>
    <div>
    <?php if ($messages): ?>
    <section class="panel">
        <h2>Mensagens</h2>
        <ul class="money-list">
            <?php foreach ($messages as $m): ?>
                <li><span><strong><?= e($m['label']) ?></strong> · <?= e(App\Domain\MessageService::STATUS_LABELS[$m['status']]) ?> · <span class="muted small"><?= e(datetime_br($m['sent_at'] ?: $m['scheduled_at'])) ?></span></span></li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>
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
</div>
