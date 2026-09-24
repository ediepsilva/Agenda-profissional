<?php
use App\Domain\BookingService;

$oldClient = old('client_id', $preClient ?: 'nova');
$oldPros = array_map('intval', (array) old('professional_ids', []));
?>
<header class="page-header">
    <div>
        <p class="eyebrow">Casamentos, formaturas, produções</p>
        <h1>Novo evento</h1>
    </div>
    <a class="btn btn-ghost" href="<?= e(url('/admin/reservas')) ?>">Voltar</a>
</header>
<p class="muted">Um evento reúne várias pessoas atendidas e pode ter mais de uma profissional. A agenda de todas as profissionais escolhidas fica ocupada no período (mais deslocamento e intervalo).</p>

<?php if ($errors): ?><div class="alert alert-error" role="alert"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>

<form method="post" action="<?= e(url('/admin/eventos')) ?>" class="panel form" id="event-form"
      data-suggest-url="<?= e(url('/admin/reservas/sugestao')) ?>">
    <?= csrf_field() ?>
    <h2>Evento</h2>
    <div class="grid-2">
        <div>
            <label for="event_name">Nome do evento</label>
            <input type="text" id="event_name" name="event_name" required maxlength="160" placeholder="Casamento Ana &amp; Pedro" value="<?= e(old('event_name')) ?>"<?= invalid_attr($errors, 'event_name') ?>>
            <?= field_error($errors, 'event_name') ?>
        </div>
        <div>
            <label for="service_id">Serviço principal</label>
            <select name="service_id" id="service_id" required>
                <option value="">Selecione…</option>
                <?php foreach ($services as $s): ?>
                    <option value="<?= (int) $s['id'] ?>"<?= selected(old('service_id'), $s['id']) ?>><?= e($s['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <?= field_error($errors, 'service_id') ?>
        </div>
        <div>
            <label for="people_count">Pessoas atendidas</label>
            <input type="number" id="people_count" name="people_count" min="1" max="200" value="<?= e(old('people_count', '1')) ?>"<?= invalid_attr($errors, 'people_count') ?>>
            <?= field_error($errors, 'people_count') ?>
        </div>
        <div>
            <label for="duration_minutes">Duração total (minutos)</label>
            <input type="number" id="duration_minutes" name="duration_minutes" min="30" max="1440" step="15" value="<?= e(old('duration_minutes', '240')) ?>"<?= invalid_attr($errors, 'duration_minutes') ?>>
            <?= field_error($errors, 'duration_minutes') ?>
        </div>
        <div>
            <label for="date">Data</label>
            <input type="date" id="date" name="date" required value="<?= e(old('date', $preDate)) ?>"<?= invalid_attr($errors, 'date') ?>>
            <?= field_error($errors, 'date') ?>
        </div>
        <div>
            <label for="time">Início</label>
            <input type="time" id="time" name="time" required value="<?= e(old('time', '08:00')) ?>"<?= invalid_attr($errors, 'time') ?>>
            <?= field_error($errors, 'time') ?>
        </div>
        <div>
            <label for="location_type">Local</label>
            <select name="location_type" id="location_type">
                <option value="client"<?= selected(old('location_type', 'client'), 'client') ?>>Local do evento</option>
                <option value="studio"<?= selected(old('location_type', 'client'), 'studio') ?>>Estúdio</option>
            </select>
        </div>
        <div>
            <label for="service_area_id">Região</label>
            <select name="service_area_id" id="service_area_id"<?= invalid_attr($errors, 'service_area_id') ?>>
                <option value="">—</option>
                <?php foreach ($areas as $a): ?><option value="<?= (int) $a['id'] ?>"<?= selected(old('service_area_id'), $a['id']) ?>><?= e($a['name']) ?> (<?= (int) $a['travel_minutes'] ?> min<?= (int) $a['travel_fee_cents'] ? ', taxa ' . e(money((int) $a['travel_fee_cents'])) : '' ?>)</option><?php endforeach; ?>
            </select>
            <?= field_error($errors, 'service_area_id') ?>
        </div>
    </div>
    <label for="address">Endereço do evento</label>
    <input type="text" id="address" name="address" maxlength="255" value="<?= e(old('address')) ?>"<?= invalid_attr($errors, 'address') ?>>
    <?= field_error($errors, 'address') ?>

    <h2>Equipe</h2>
    <p class="muted small">A primeira marcada será a responsável. <button type="button" class="btn btn-ghost btn-sm" id="check-team">Ver quem está livre</button></p>
    <div class="team-pick" id="team-pick">
        <?php foreach ($professionals as $p): ?>
            <label class="check" data-pro="<?= (int) $p['id'] ?>">
                <input type="checkbox" name="professional_ids[]" value="<?= (int) $p['id'] ?>"<?= checked(in_array((int) $p['id'], $oldPros, true)) ?>>
                <span><span class="dot" style="background: <?= e($p['color']) ?>"></span> <?= e($p['name']) ?> <span class="muted small pro-status"></span></span>
            </label>
        <?php endforeach; ?>
    </div>
    <?= field_error($errors, 'professional_ids') ?>

    <h2>Valores</h2>
    <div class="grid-2">
        <div>
            <label for="price">Valor total combinado (R$)</label>
            <input type="text" id="price" name="price" inputmode="decimal" required value="<?= e(old('price')) ?>"<?= invalid_attr($errors, 'price') ?>>
            <span class="muted small">A taxa de deslocamento da região é somada automaticamente.</span>
            <?= field_error($errors, 'price') ?>
        </div>
        <div>
            <label for="deposit">Sinal (R$) <span class="muted">vazio = <?= e(setting('deposit_percent')) ?>%</span></label>
            <input type="text" id="deposit" name="deposit" inputmode="decimal" value="<?= e(old('deposit')) ?>"<?= invalid_attr($errors, 'deposit') ?>>
            <?= field_error($errors, 'deposit') ?>
        </div>
    </div>

    <h2>Contratante</h2>
    <label for="client_id">Cliente</label>
    <select name="client_id" id="client_id" data-toggle-new-client>
        <option value="nova"<?= selected($oldClient, 'nova') ?>>+ Nova cliente</option>
        <?php foreach ($clients as $c): ?>
            <option value="<?= (int) $c['id'] ?>"<?= selected($oldClient, $c['id']) ?>><?= e($c['name']) ?> — <?= e(phone_br($c['phone'])) ?></option>
        <?php endforeach; ?>
    </select>
    <?= field_error($errors, 'client_id') ?>
    <div id="new-client" class="grid-2">
        <div><label for="name">Nome</label><input type="text" name="name" id="name" maxlength="120" value="<?= e(old('name')) ?>"<?= invalid_attr($errors, 'name') ?>><?= field_error($errors, 'name') ?></div>
        <div><label for="phone">WhatsApp</label><input type="tel" name="phone" id="phone" value="<?= e(old('phone')) ?>"<?= invalid_attr($errors, 'phone') ?>><?= field_error($errors, 'phone') ?></div>
        <div><label for="email">E-mail (opcional)</label><input type="email" name="email" id="email" value="<?= e(old('email')) ?>"><?= field_error($errors, 'email') ?></div>
        <div>
            <label for="source">Como conheceu</label>
            <select name="source" id="source">
                <option value="">—</option>
                <?php foreach (BookingService::SOURCES as $k => $label): ?><option value="<?= e($k) ?>"<?= selected(old('source'), $k) ?>><?= e($label) ?></option><?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="grid-2">
        <div>
            <label for="status">Status inicial</label>
            <select name="status" id="status">
                <?php foreach (['confirmed', 'awaiting_deposit', 'requested'] as $st): ?><option value="<?= $st ?>"<?= selected(old('status', 'awaiting_deposit'), $st) ?>><?= e(BookingService::label($st)) ?></option><?php endforeach; ?>
            </select>
        </div>
    </div>
    <label for="client_notes">Observações</label>
    <textarea name="client_notes" id="client_notes" rows="3" maxlength="1000" placeholder="Cronograma, pessoas (noiva, mãe, madrinhas), referências…"><?= e(old('client_notes')) ?></textarea>
    <label class="check"><input type="checkbox" name="allow_outside_hours" value="1"<?= checked((bool) old('allow_outside_hours')) ?>> Permitir fora do horário de atendimento (conflitos continuam bloqueados)</label>

    <button type="submit" class="btn btn-primary">Criar evento</button>
</form>
