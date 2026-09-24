<?php
use App\Domain\BookingService;

$oldClient = old('client_id', $preClient ?: 'nova');
?>
<header class="page-header">
    <h1>Nova reserva</h1>
    <a class="btn btn-ghost" href="<?= e(url('/admin/reservas')) ?>">Voltar</a>
</header>

<?php if ($errors): ?><div class="alert alert-error" role="alert"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>

<form method="post" action="<?= e(url('/admin/reservas')) ?>" class="panel form" id="admin-booking-form" data-slots-url="<?= e(url('/admin/reservas/horarios')) ?>">
    <?= csrf_field() ?>
    <h2>Cliente</h2>
    <label for="client_id">Cliente</label>
    <select name="client_id" id="client_id" data-toggle-new-client>
        <option value="nova"<?= selected($oldClient, 'nova') ?>>+ Nova cliente</option>
        <?php foreach ($clients as $c): ?>
            <option value="<?= (int) $c['id'] ?>"<?= selected($oldClient, $c['id']) ?>><?= e($c['name']) ?> — <?= e(phone_br($c['phone'])) ?></option>
        <?php endforeach; ?>
    </select>
    <?= field_error($errors, 'client_id') ?>
    <div id="new-client" class="grid-2">
        <div>
            <label for="name">Nome</label>
            <input type="text" name="name" id="name" maxlength="120" value="<?= e(old('name')) ?>"<?= invalid_attr($errors, 'name') ?>>
            <?= field_error($errors, 'name') ?>
        </div>
        <div>
            <label for="phone">WhatsApp</label>
            <input type="tel" name="phone" id="phone" value="<?= e(old('phone')) ?>"<?= invalid_attr($errors, 'phone') ?>>
            <?= field_error($errors, 'phone') ?>
        </div>
        <div>
            <label for="email">E-mail (opcional)</label>
            <input type="email" name="email" id="email" value="<?= e(old('email')) ?>">
            <?= field_error($errors, 'email') ?>
        </div>
        <div>
            <label for="source">Como conheceu</label>
            <select name="source" id="source">
                <option value="">—</option>
                <?php foreach (BookingService::SOURCES as $k => $label): ?><option value="<?= e($k) ?>"<?= selected(old('source'), $k) ?>><?= e($label) ?></option><?php endforeach; ?>
            </select>
        </div>
    </div>

    <h2>Atendimento</h2>
    <div class="grid-2">
        <div>
            <label for="service_id">Serviço</label>
            <select name="service_id" id="service_id" required>
                <option value="">Selecione…</option>
                <?php foreach ($services as $s): ?>
                    <option value="<?= (int) $s['id'] ?>"<?= selected(old('service_id'), $s['id']) ?>><?= e($s['name']) ?> (<?= e(duration_br((int) $s['duration_minutes'])) ?>)</option>
                <?php endforeach; ?>
            </select>
            <?= field_error($errors, 'service_id') ?>
        </div>
        <div>
            <label for="professional_id">Profissional</label>
            <select name="professional_id" id="professional_id">
                <option value="">Distribuir automaticamente (livre e com menor carga)</option>
                <?php foreach ($professionals as $p): ?><option value="<?= (int) $p['id'] ?>"<?= selected(old('professional_id'), $p['id']) ?>><?= e($p['name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="location_type">Local</label>
            <select name="location_type" id="location_type">
                <option value="studio"<?= selected(old('location_type'), 'studio') ?>>Estúdio</option>
                <option value="client"<?= selected(old('location_type'), 'client') ?>>Local da cliente</option>
            </select>
            <?= field_error($errors, 'location_type') ?>
        </div>
        <div>
            <label for="service_area_id">Região (se no local da cliente)</label>
            <select name="service_area_id" id="service_area_id">
                <option value="">—</option>
                <?php foreach ($areas as $a): ?><option value="<?= (int) $a['id'] ?>"<?= selected(old('service_area_id'), $a['id']) ?>><?= e($a['name']) ?> (<?= (int) $a['travel_minutes'] ?> min)</option><?php endforeach; ?>
            </select>
            <?= field_error($errors, 'service_area_id') ?>
        </div>
    </div>
    <label for="address">Endereço (se no local da cliente)</label>
    <input type="text" name="address" id="address" maxlength="255" value="<?= e(old('address')) ?>">
    <?= field_error($errors, 'address') ?>

    <div class="grid-2">
        <div>
            <label for="date">Data</label>
            <input type="date" name="date" id="date" required value="<?= e(old('date', $preDate)) ?>">
            <?= field_error($errors, 'date') ?>
        </div>
        <div>
            <label for="time">Horário</label>
            <input type="time" name="time" id="time" required step="300" value="<?= e(old('time')) ?>">
            <?= field_error($errors, 'time') ?>
        </div>
    </div>
    <div id="admin-slots" class="slots slots-sm" aria-live="polite"></div>
    <label class="check"><input type="checkbox" name="allow_outside_hours" value="1"<?= checked((bool) old('allow_outside_hours')) ?>> Permitir fora do horário de atendimento (conflitos continuam bloqueados)</label>

    <div class="grid-2">
        <div>
            <label for="status">Status inicial</label>
            <select name="status" id="status">
                <?php foreach (['confirmed', 'awaiting_deposit', 'requested'] as $st): ?><option value="<?= $st ?>"<?= selected(old('status', 'confirmed'), $st) ?>><?= e(BookingService::label($st)) ?></option><?php endforeach; ?>
            </select>
        </div>
    </div>
    <label for="client_notes">Observações da cliente</label>
    <textarea name="client_notes" id="client_notes" rows="2"><?= e(old('client_notes')) ?></textarea>

    <button type="submit" class="btn btn-primary">Salvar reserva</button>
</form>
