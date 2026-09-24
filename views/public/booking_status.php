<?php
use App\Domain\BookingService;

$open = in_array($b['status'], BookingService::OPEN, true);
$messages = [
    'requested' => 'Recebemos sua solicitação. Você será avisada assim que o horário for confirmado.',
    'awaiting_deposit' => 'Horário pré-aprovado! Para garantir a reserva, realize o pagamento do sinal.',
    'confirmed' => 'Tudo certo! Seu horário está confirmado.',
    'completed' => 'Atendimento concluído. Obrigada pela preferência!',
    'cancelled' => 'Esta reserva foi cancelada.',
    'no_show' => 'Esta reserva foi registrada como não comparecimento.',
];
?>
<section class="section">
<div class="wrap narrow">
    <p class="eyebrow">Sua reserva</p>
    <h1><?= e($b['service_name']) ?></h1>
    <p><?= status_badge($b['status']) ?></p>
    <p><?= e($messages[$b['status']] ?? '') ?></p>

    <div class="card">
        <dl class="details">
            <div><dt>Data</dt><dd><?= e(date_long_br($b['starts_at'])) ?></dd></div>
            <div><dt>Horário</dt><dd><?= e(time_br($b['starts_at'])) ?> às <?= e(time_br($b['ends_at'])) ?></dd></div>
            <div><dt>Local</dt><dd><?= $b['location_type'] === 'client' ? e($b['address'] . ($b['area_name'] ? ' — ' . $b['area_name'] : '')) : 'Estúdio' . (setting('studio_address') ? ': ' . e(setting('studio_address')) : '') ?></dd></div>
            <div><dt>Nome</dt><dd><?= e($b['client_name']) ?></dd></div>
            <div><dt>Valor a partir de</dt><dd><?= e(money((int) $b['price_cents'])) ?><?= (int) $b['travel_fee_cents'] > 0 ? ' (inclui deslocamento ' . e(money((int) $b['travel_fee_cents'])) . ')' : '' ?></dd></div>
            <?php if ((int) $b['deposit_cents'] > 0): ?><div><dt>Sinal</dt><dd><?= e(money((int) $b['deposit_cents'])) ?></dd></div><?php endif; ?>
        </dl>
    </div>

    <?php if (setting('whatsapp')): ?>
        <p><a class="btn btn-primary" target="_blank" rel="noopener" href="<?= e(whatsapp_link(setting('whatsapp'), 'Olá! Tenho uma reserva de ' . $b['service_name'] . ' em ' . datetime_br($b['starts_at']) . '.')) ?>">Falar pelo WhatsApp</a></p>
    <?php endif; ?>

    <?php if (setting('cancellation_policy')): ?><p class="muted small"><strong>Cancelamento:</strong> <?= e(setting('cancellation_policy')) ?></p><?php endif; ?>

    <?php if ($open): ?>
    <details class="danger-zone">
        <summary>Preciso cancelar</summary>
        <form method="post" action="<?= e(url('/reserva/' . $b['public_code'] . '/cancelar')) ?>" data-confirm="Tem certeza que deseja cancelar esta reserva?">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-danger">Cancelar minha reserva</button>
        </form>
    </details>
    <?php endif; ?>
    <p class="muted small">Guarde o link desta página para acompanhar sua reserva.</p>
</div>
</section>
