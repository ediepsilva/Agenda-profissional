<?php $on = (bool) (int) $c['marketing_opt_in']; ?>
<section class="section">
<div class="wrap narrow">
    <p class="eyebrow">Preferências de mensagens</p>
    <h1>Olá, <?= e(strtok($c['name'], ' ')) ?>!</h1>
    <div class="card">
        <h2>Avisos das suas reservas</h2>
        <p>Confirmação, lembrete, orientações antes do atendimento e agradecimento fazem parte do serviço e continuam sendo enviados enquanto você tiver reservas.</p>
        <h2>Novidades e ofertas</h2>
        <p>Situação atual: <strong><?= $on ? 'você recebe ofertas' : 'você não recebe ofertas' ?></strong>.</p>
        <form method="post" action="<?= e(url('/preferencias/' . $token)) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="marketing" value="<?= $on ? '0' : '1' ?>">
            <button type="submit" class="btn <?= $on ? 'btn-ghost' : 'btn-primary' ?>"><?= $on ? 'Não quero mais receber ofertas' : 'Quero receber novidades e ofertas' ?></button>
        </form>
        <p class="muted small">Você também pode responder SAIR a qualquer mensagem de oferta.</p>
    </div>
</div>
</section>
