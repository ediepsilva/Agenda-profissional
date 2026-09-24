<?php
use App\Domain\BookingService;

$actionLabels = [
    'awaiting_deposit' => ['Aguardar sinal', 'btn-ghost'],
    'confirmed' => ['Confirmar', 'btn-primary'],
    'completed' => ['Marcar como concluída', 'btn-primary'],
    'no_show' => ['Não compareceu', 'btn-ghost'],
    'cancelled' => ['Cancelar reserva', 'btn-danger'],
];
$open = in_array($b['status'], BookingService::OPEN, true);
$msg = 'Olá, ' . strtok($b['client_name'], ' ') . '! Sobre sua reserva de ' . $b['service_name'] . ' em ' . datetime_br($b['starts_at']) . ': ' . $publicLink;
?>
<header class="page-header">
    <div>
        <p class="eyebrow">Reserva #<?= (int) $b['id'] ?> · <?= $b['channel'] === 'public' ? 'pela página pública' : 'lançada no painel' ?></p>
        <h1><?= e($b['client_name']) ?> — <?= e($b['service_name']) ?></h1>
        <p><?= status_badge($b['status']) ?></p>
    </div>
    <a class="btn btn-ghost" href="<?= e(url('/admin/reservas')) ?>">Voltar</a>
</header>

<div class="grid-2 gap-lg">
    <section class="panel">
        <h2>Detalhes</h2>
        <dl class="details">
            <div><dt>Data</dt><dd><?= e(date_long_br($b['starts_at'])) ?></dd></div>
            <div><dt>Horário</dt><dd><?= e(time_br($b['starts_at'])) ?> às <?= e(time_br($b['ends_at'])) ?><?php if ((int) $b['travel_minutes']): ?><br><span class="muted small">Agenda ocupada das <?= e(time_br((new DateTimeImmutable($b['starts_at']))->modify('-' . (int) $b['travel_minutes'] . ' minutes'))) ?> (deslocamento de <?= (int) $b['travel_minutes'] ?> min por trecho)</span><?php endif; ?></dd></div>
            <div><dt>Profissional</dt><dd><span class="dot" style="background: <?= e($b['professional_color']) ?>"></span> <?= e($b['professional_name']) ?></dd></div>
            <div><dt>Local</dt><dd><?= $b['location_type'] === 'client' ? e($b['address']) . ($b['area_name'] ? '<br><span class="muted small">' . e($b['area_name']) . '</span>' : '') : 'Estúdio' ?></dd></div>
            <div><dt>Cliente</dt><dd><a href="<?= e(url('/admin/clientes/' . $b['client_id'])) ?>"><?= e($b['client_name']) ?></a><br><?= e(phone_br($b['client_phone'])) ?><?= $b['client_email'] ? '<br>' . e($b['client_email']) : '' ?></dd></div>
            <div><dt>Valor</dt><dd><?= e(money((int) $b['price_cents'])) ?><?= (int) $b['travel_fee_cents'] ? ' <span class="muted small">(inclui deslocamento ' . e(money((int) $b['travel_fee_cents'])) . ')</span>' : '' ?></dd></div>
            <div><dt>Sinal previsto</dt><dd><?= e(money((int) $b['deposit_cents'])) ?></dd></div>
            <?php if ($b['client_notes']): ?><div><dt>Observações da cliente</dt><dd><?= nl2br(e($b['client_notes'])) ?></dd></div><?php endif; ?>
            <?php if ($b['cancel_reason']): ?><div><dt>Motivo do cancelamento</dt><dd><?= e($b['cancel_reason']) ?></dd></div><?php endif; ?>
        </dl>
        <p>
            <a class="btn btn-ghost btn-sm" target="_blank" rel="noopener" href="<?= e(whatsapp_link($b['client_phone'], $msg)) ?>">Abrir conversa no WhatsApp</a>
            <button type="button" class="btn btn-ghost btn-sm" data-copy-text="<?= e($publicLink) ?>">Copiar link da cliente</button>
        </p>
    </section>

    <div>
        <?php if ($canManage && $transitions): ?>
        <section class="panel">
            <h2>Ações</h2>
            <?php foreach ($transitions as $to): [$label, $cls] = $actionLabels[$to]; ?>
                <form method="post" action="<?= e(url('/admin/reservas/' . $b['id'] . '/status')) ?>" class="inline-action"<?= $to === 'cancelled' ? ' data-confirm="Cancelar esta reserva? O horário será liberado."' : '' ?>>
                    <?= csrf_field() ?>
                    <input type="hidden" name="to" value="<?= e($to) ?>">
                    <?php if ($to === 'cancelled'): ?>
                        <label class="sr-only" for="note-cancel">Motivo</label>
                        <input type="text" name="note" id="note-cancel" placeholder="Motivo (opcional)" maxlength="255">
                    <?php endif; ?>
                    <button type="submit" class="btn <?= $cls ?>"><?= e($label) ?></button>
                </form>
            <?php endforeach; ?>
        </section>
        <?php endif; ?>

        <?php if ($canManage && $open): ?>
        <section class="panel">
            <h2>Reagendar</h2>
            <form method="post" action="<?= e(url('/admin/reservas/' . $b['id'] . '/reagendar')) ?>" class="form">
                <?= csrf_field() ?>
                <div class="grid-2">
                    <div><label for="r-date">Nova data</label><input type="date" id="r-date" name="date" required value="<?= e(substr($b['starts_at'], 0, 10)) ?>"></div>
                    <div><label for="r-time">Novo horário</label><input type="time" id="r-time" name="time" required value="<?= e(time_br($b['starts_at'])) ?>"></div>
                </div>
                <label class="check"><input type="checkbox" name="allow_outside_hours" value="1"> Permitir fora do horário de atendimento</label>
                <button type="submit" class="btn btn-ghost">Reagendar</button>
            </form>
        </section>
        <?php endif; ?>

        <section class="panel">
            <h2>Observações internas</h2>
            <?php if ($canManage): ?>
            <form method="post" action="<?= e(url('/admin/reservas/' . $b['id'] . '/notas')) ?>">
                <?= csrf_field() ?>
                <label class="sr-only" for="internal_notes">Observações internas</label>
                <textarea name="internal_notes" id="internal_notes" rows="3" maxlength="2000" placeholder="Visível apenas para a equipe"><?= e($b['internal_notes']) ?></textarea>
                <button type="submit" class="btn btn-ghost btn-sm">Salvar</button>
            </form>
            <?php else: ?><p><?= nl2br(e($b['internal_notes'] ?: '—')) ?></p><?php endif; ?>
        </section>

        <section class="panel">
            <h2>Histórico</h2>
            <ol class="timeline">
                <?php foreach ($history as $h): ?>
                    <li><span class="muted small"><?= e(datetime_br($h['created_at'])) ?></span>
                        <?= $h['from_status'] && $h['from_status'] !== $h['to_status'] ? e(BookingService::label($h['from_status'])) . ' → ' : '' ?><strong><?= e(BookingService::label($h['to_status'])) ?></strong>
                        <?= $h['note'] ? '· ' . e($h['note']) : '' ?>
                        <?= $h['user_name'] ? '<span class="muted small">(' . e($h['user_name']) . ')</span>' : '' ?>
                    </li>
                <?php endforeach; ?>
            </ol>
        </section>
    </div>
</div>
