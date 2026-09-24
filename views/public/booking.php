<?php
use App\Domain\BookingService;

$oldLocation = old('location_type', 'studio');
?>
<section class="section">
<div class="wrap narrow">
    <h1>Agendar horário</h1>
    <p class="muted">Leva menos de 2 minutos. Você não precisa criar conta.</p>

    <?php if (isset($errors['form'])): ?><div class="alert alert-error" role="alert"><?= e($errors['form']) ?></div><?php endif; ?>
    <?php if ($errors && !isset($errors['form'])): ?>
        <div class="alert alert-error" role="alert">Confira os campos destacados abaixo.</div>
    <?php endif; ?>

    <noscript><div class="alert alert-warning">Ative o JavaScript para ver os horários disponíveis<?= setting('whatsapp') ? ', ou fale pelo WhatsApp' : '' ?>.</div></noscript>

    <?php if (!$services): ?>
        <p>Nenhum serviço disponível para agendamento no momento.</p>
    <?php else: ?>
    <form method="post" action="<?= e(url('/agendar')) ?>" id="booking-form" class="booking-form"
          data-slots-url="<?= e(url('/api/horarios')) ?>" data-days-url="<?= e(url('/api/dias')) ?>"
          data-old-date="<?= e(old('date')) ?>" data-old-time="<?= e(old('time')) ?>" novalidate>
        <?= csrf_field() ?>
        <div class="hp" aria-hidden="true"><label>Não preencha <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>

        <fieldset class="step">
            <legend><span class="step-n">1</span> Serviço</legend>
            <div class="option-list">
                <?php foreach ($services as $s): ?>
                <label class="option">
                    <input type="radio" name="service_id" value="<?= (int) $s['id'] ?>" required
                           data-mode="<?= e($s['location_mode']) ?>" data-duration="<?= (int) $s['duration_minutes'] ?>"
                           data-name="<?= e($s['name']) ?>" data-price="<?= e(money((int) $s['price_cents'])) ?>"<?= checked((int) $selected === (int) $s['id']) ?>>
                    <span class="option-body">
                        <strong><?= e($s['name']) ?></strong>
                        <span class="muted"><?= e(duration_br((int) $s['duration_minutes'])) ?> · a partir de <?= e(money((int) $s['price_cents'])) ?></span>
                    </span>
                </label>
                <?php endforeach; ?>
            </div>
            <?= field_error($errors, 'service_id') ?>
        </fieldset>

        <fieldset class="step">
            <legend><span class="step-n">2</span> Local</legend>
            <div class="option-list option-inline">
                <label class="option" data-loc="studio">
                    <input type="radio" name="location_type" value="studio"<?= checked($oldLocation === 'studio') ?>>
                    <span class="option-body"><strong>No estúdio</strong><?php if (setting('studio_address')): ?><span class="muted"><?= e(setting('studio_address')) ?></span><?php endif; ?></span>
                </label>
                <label class="option" data-loc="client">
                    <input type="radio" name="location_type" value="client"<?= checked($oldLocation === 'client') ?><?= $areas ? '' : ' disabled' ?>>
                    <span class="option-body"><strong>No meu endereço</strong><span class="muted"><?= $areas ? 'Pode haver taxa de deslocamento' : 'Indisponível no momento' ?></span></span>
                </label>
            </div>
            <?= field_error($errors, 'location_type') ?>
            <div class="client-location" id="client-location" hidden>
                <label for="service_area_id">Região</label>
                <select name="service_area_id" id="service_area_id"<?= invalid_attr($errors, 'service_area_id') ?>>
                    <option value="">Selecione…</option>
                    <?php foreach ($areas as $a): ?>
                        <option value="<?= (int) $a['id'] ?>"<?= selected(old('service_area_id'), $a['id']) ?>><?= e($a['name']) ?><?= (int) $a['travel_fee_cents'] > 0 ? ' (taxa ' . e(money((int) $a['travel_fee_cents'])) . ')' : '' ?></option>
                    <?php endforeach; ?>
                </select>
                <?= field_error($errors, 'service_area_id') ?>
                <label for="address">Endereço do atendimento</label>
                <input type="text" name="address" id="address" maxlength="255" autocomplete="street-address" value="<?= e(old('address')) ?>"<?= invalid_attr($errors, 'address') ?>>
                <?= field_error($errors, 'address') ?>
            </div>
        </fieldset>

        <fieldset class="step">
            <legend><span class="step-n">3</span> Data e horário</legend>
            <input type="hidden" name="date" id="date" value="<?= e(old('date')) ?>">
            <input type="hidden" name="time" id="time" value="<?= e(old('time')) ?>">
            <div id="calendar" class="calendar" aria-live="polite"><p class="muted">Escolha um serviço para ver as datas disponíveis.</p></div>
            <div id="slots" class="slots" aria-live="polite"></div>
            <?= field_error($errors, 'date') ?><?= field_error($errors, 'time') ?>
        </fieldset>

        <fieldset class="step">
            <legend><span class="step-n">4</span> Seus dados</legend>
            <div class="grid-2">
                <div>
                    <label for="name">Nome</label>
                    <input type="text" name="name" id="name" required maxlength="120" autocomplete="name" value="<?= e(old('name')) ?>"<?= invalid_attr($errors, 'name') ?>>
                    <?= field_error($errors, 'name') ?>
                </div>
                <div>
                    <label for="phone">WhatsApp (com DDD)</label>
                    <input type="tel" name="phone" id="phone" required inputmode="tel" autocomplete="tel" placeholder="(11) 91234-5678" value="<?= e(old('phone')) ?>"<?= invalid_attr($errors, 'phone') ?>>
                    <?= field_error($errors, 'phone') ?>
                </div>
                <div>
                    <label for="email">E-mail <span class="muted">(opcional)</span></label>
                    <input type="email" name="email" id="email" maxlength="190" autocomplete="email" value="<?= e(old('email')) ?>"<?= invalid_attr($errors, 'email') ?>>
                    <?= field_error($errors, 'email') ?>
                </div>
                <div>
                    <label for="source">Como conheceu meu trabalho?</label>
                    <select name="source" id="source">
                        <option value="">Prefiro não dizer</option>
                        <?php foreach (BookingService::SOURCES as $k => $label): ?>
                            <option value="<?= e($k) ?>"<?= selected(old('source'), $k) ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <label for="client_notes">Observações <span class="muted">(opcional: ocasião, alergias, referências)</span></label>
            <textarea name="client_notes" id="client_notes" rows="3" maxlength="1000"><?= e(old('client_notes')) ?></textarea>
            <?= field_error($errors, 'client_notes') ?>
            <label class="check">
                <input type="checkbox" name="privacy" value="1" required<?= checked((bool) old('privacy')) ?>>
                <span>Concordo com o uso dos meus dados para organizar este atendimento, conforme a <a href="<?= e(url('/privacidade')) ?>" target="_blank">política de privacidade</a>.</span>
            </label>
            <?= field_error($errors, 'privacy') ?>
        </fieldset>

        <div class="summary" id="summary" hidden></div>
        <button type="submit" class="btn btn-primary btn-lg btn-block">Enviar solicitação</button>
        <p class="muted small center">Sua reserva fica como <strong>solicitada</strong> até a confirmação da profissional.</p>
    </form>
    <script src="<?= e(asset('/assets/js/booking.js')) ?>" defer></script>
    <?php endif; ?>
</div>
</section>
