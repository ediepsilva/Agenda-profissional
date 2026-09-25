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
    <h1><?= e($b['kind'] === 'event' ? $b['event_name'] : $b['service_name']) ?></h1>
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

    <?php if ($canPayOnline || $showPix): ?>
    <div class="card pay-box">
        <h2><?= $due['kind'] === 'deposit' ? 'Pagamento do sinal' : 'Pagamento do saldo' ?>: <?= e(money($due['cents'])) ?></h2>
        <?php if ($canPayOnline): ?>
            <form method="post" action="<?= e(url('/reserva/' . $b['public_code'] . '/pagar')) ?>">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-primary">Pagar online (Pix ou cartão)</button>
            </form>
            <p class="muted small">Pagamento processado pelo Mercado Pago. A reserva é atualizada automaticamente após a aprovação.</p>
        <?php endif; ?>
        <?php if ($showPix): ?>
            <p><?= $canPayOnline ? 'Ou pague' : 'Pague' ?> por Pix: <strong><?= e(setting('pix_key')) ?></strong><?= setting('pix_holder') ? ' (' . e(setting('pix_holder')) . ')' : '' ?>
                <button type="button" class="btn btn-ghost btn-sm" data-copy-text="<?= e(setting('pix_key')) ?>">Copiar chave</button></p>
            <p class="muted small">Depois de pagar, envie o comprovante pelo WhatsApp para confirmarmos.</p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($canReview): ?>
        <p><a class="btn btn-primary" href="<?= e(url('/avaliar/' . $b['public_code'])) ?>">Avaliar meu atendimento ⭐</a></p>
    <?php endif; ?>

    <?php if (setting('whatsapp')): ?>
        <p><a class="btn btn-ghost" target="_blank" rel="noopener" href="<?= e(whatsapp_link(setting('whatsapp'), 'Olá! Tenho uma reserva de ' . ($b['kind'] === 'event' ? $b['event_name'] : $b['service_name']) . ' em ' . datetime_br($b['starts_at']) . '.')) ?>">Falar pelo WhatsApp</a></p>
    <?php endif; ?>

    <?php if (in_array($b['status'], ['confirmed', 'completed'], true)): ?>
    <div class="card referral-box">
        <h2>Indique uma amiga 💕</h2>
        <?php if (setting('referral_reward_text')): ?><p><?= e(setting('referral_reward_text')) ?></p><?php endif; ?>
        <div class="copy-row">
            <input type="text" readonly value="<?= e($referralLink) ?>" id="ref-link" aria-label="Seu link de indicação">
            <button type="button" class="btn btn-ghost" data-copy="#ref-link">Copiar</button>
        </div>
        <p><a class="btn btn-ghost btn-sm" target="_blank" rel="noopener" href="https://wa.me/?text=<?= e(rawurlencode('Olha que maquiadora incrível! Agende pelo meu link: ' . $referralLink)) ?>">Compartilhar no WhatsApp</a></p>
    </div>
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
    <p class="muted small">Guarde o link desta página para acompanhar sua reserva. <a href="<?= e($preferencesLink) ?>">Preferências de mensagens</a></p>
</div>
</section>
