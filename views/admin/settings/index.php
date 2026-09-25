<?php
$v = static fn (string $k) => old($k, $s[$k] ?? '');
$num = static function (string $k, string $label, string $hint = '') use ($v, $errors): string {
    return '<div><label for="' . e($k) . '">' . e($label) . ($hint ? ' <span class="muted">' . e($hint) . '</span>' : '') . '</label>'
        . '<input type="number" id="' . e($k) . '" name="' . e($k) . '" min="0" value="' . e($v($k)) . '"' . invalid_attr($errors, $k) . '>'
        . field_error($errors, $k) . '</div>';
};
?>
<header class="page-header"><h1>Configurações</h1></header>

<form method="post" action="<?= e(url('/admin/configuracoes')) ?>" class="form">
    <?= csrf_field() ?>
    <section class="panel">
        <h2>Negócio e página pública</h2>
        <div class="grid-2">
            <div><label for="business_name">Nome do negócio</label><input type="text" id="business_name" name="business_name" required value="<?= e($v('business_name')) ?>"<?= invalid_attr($errors, 'business_name') ?>><?= field_error($errors, 'business_name') ?></div>
            <div><label for="tagline">Frase de apresentação</label><input type="text" id="tagline" name="tagline" value="<?= e($v('tagline')) ?>"></div>
            <div><label for="whatsapp">WhatsApp do negócio</label><input type="tel" id="whatsapp" name="whatsapp" value="<?= e($v('whatsapp')) ?>"></div>
            <div><label for="instagram">Instagram</label><input type="text" id="instagram" name="instagram" placeholder="usuario" value="<?= e($v('instagram')) ?>"></div>
            <div><label for="city">Cidade</label><input type="text" id="city" name="city" value="<?= e($v('city')) ?>"></div>
            <div><label for="studio_address">Endereço do estúdio</label><input type="text" id="studio_address" name="studio_address" value="<?= e($v('studio_address')) ?>"></div>
        </div>
        <label for="about">Sobre</label>
        <textarea id="about" name="about" rows="3"><?= e($v('about')) ?></textarea>
        <label for="service_area_text">Texto sobre a área atendida</label>
        <textarea id="service_area_text" name="service_area_text" rows="2"><?= e($v('service_area_text')) ?></textarea>
    </section>

    <section class="panel">
        <h2>Regras de agendamento</h2>
        <div class="grid-3">
            <?= $num('min_advance_hours', 'Antecedência mínima', '(horas)') ?>
            <?= $num('max_advance_days', 'Agendar com até', '(dias de antecedência)') ?>
            <?= $num('slot_step_minutes', 'Intervalo entre opções de horário', '(min)') ?>
            <?= $num('buffer_minutes', 'Intervalo entre atendimentos', '(min)') ?>
            <?= $num('deposit_percent', 'Sinal padrão', '(% do valor)') ?>
            <?= $num('cancellation_min_hours', 'Cancelamento pela cliente até', '(horas antes)') ?>
            <?= $num('reschedule_min_hours', 'Reagendamento até', '(horas antes)') ?>
        </div>
        <label class="check">
            <input type="hidden" name="hold_pending_requests" value="0">
            <input type="checkbox" name="hold_pending_requests" value="1"<?= checked($v('hold_pending_requests') === '1') ?>>
            Solicitações ainda não confirmadas já reservam o horário (evita dois pedidos para o mesmo horário)
        </label>
        <p class="muted small">Se desmarcado, várias clientes podem solicitar o mesmo horário; ao confirmar uma, as demais não poderão ser confirmadas (conflito).</p>
    </section>

    <section class="panel">
        <h2>Mensagens, avaliações e pagamentos</h2>
        <?php foreach ([
            'messaging_enabled' => 'Enviar avisos automáticos pelo WhatsApp (confirmação, lembrete, orientações, agradecimento)',
            'reviews_auto_approve' => 'Publicar avaliações automaticamente (sem aprovação prévia)',
            'online_payment_enabled' => 'Oferecer pagamento online do sinal (quando o Mercado Pago estiver configurado)',
        ] as $k => $label): ?>
        <label class="check">
            <input type="hidden" name="<?= $k ?>" value="0">
            <input type="checkbox" name="<?= $k ?>" value="1"<?= checked($v($k) === '1') ?>>
            <?= e($label) ?>
        </label>
        <?php endforeach; ?>
        <div class="grid-2">
            <div><label for="pix_key">Chave Pix (pagamento manual do sinal)</label><input type="text" id="pix_key" name="pix_key" maxlength="120" value="<?= e($v('pix_key')) ?>"></div>
            <div><label for="pix_holder">Nome do titular do Pix</label><input type="text" id="pix_holder" name="pix_holder" maxlength="120" value="<?= e($v('pix_holder')) ?>"></div>
        </div>
        <label for="referral_reward_text">Recompensa por indicação (exibida às clientes)</label>
        <textarea id="referral_reward_text" name="referral_reward_text" rows="2"><?= e($v('referral_reward_text')) ?></textarea>
        <p class="muted small">Horários dos lembretes e textos das mensagens: menu <a href="<?= e(url('/admin/mensagens')) ?>#modelos">Mensagens</a>.</p>
    </section>

    <section class="panel">
        <h2>Políticas exibidas às clientes</h2>
        <label for="deposit_policy">Sinal</label>
        <textarea id="deposit_policy" name="deposit_policy" rows="2"><?= e($v('deposit_policy')) ?></textarea>
        <label for="cancellation_policy">Cancelamento</label>
        <textarea id="cancellation_policy" name="cancellation_policy" rows="2"><?= e($v('cancellation_policy')) ?></textarea>
        <label for="reschedule_policy">Reagendamento</label>
        <textarea id="reschedule_policy" name="reschedule_policy" rows="2"><?= e($v('reschedule_policy')) ?></textarea>
    </section>

    <button type="submit" class="btn btn-primary">Salvar configurações</button>
</form>
