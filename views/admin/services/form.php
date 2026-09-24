<?php
use App\Controllers\ServiceController;

$v = static fn (string $field, mixed $fromModel) => old($field, $fromModel);
$action = $s ? url('/admin/servicos/' . $s['id']) : url('/admin/servicos');
$oldPros = old('professionals', null);
$pros = $oldPros !== null ? array_map('intval', (array) $oldPros) : $selectedPros;
$active = $errors ? (bool) old('active') : ($s ? (bool) $s['active'] : true);
?>
<header class="page-header">
    <h1><?= e($pageTitle) ?></h1>
    <a class="btn btn-ghost" href="<?= e(url('/admin/servicos')) ?>">Voltar</a>
</header>

<form method="post" action="<?= e($action) ?>" class="panel form">
    <?= csrf_field() ?>
    <div class="grid-2">
        <div>
            <label for="name">Nome do serviço</label>
            <input type="text" id="name" name="name" required maxlength="120" value="<?= e($v('name', $s['name'] ?? '')) ?>"<?= invalid_attr($errors, 'name') ?>>
            <?= field_error($errors, 'name') ?>
        </div>
        <div>
            <label for="category">Categoria <span class="muted">(opcional)</span></label>
            <input type="text" id="category" name="category" maxlength="80" placeholder="Noivas, Social, Artística…" value="<?= e($v('category', $s['category'] ?? '')) ?>">
        </div>
    </div>
    <label for="description">Descrição <span class="muted">(aparece na página pública)</span></label>
    <textarea id="description" name="description" rows="3" maxlength="2000"><?= e($v('description', $s['description'] ?? '')) ?></textarea>

    <div class="grid-3">
        <div>
            <label for="duration_minutes">Duração (minutos)</label>
            <input type="number" id="duration_minutes" name="duration_minutes" required min="15" max="720" step="5" value="<?= e($v('duration_minutes', $s['duration_minutes'] ?? 60)) ?>"<?= invalid_attr($errors, 'duration_minutes') ?>>
            <?= field_error($errors, 'duration_minutes') ?>
        </div>
        <div>
            <label for="price">Preço a partir de (R$)</label>
            <input type="text" id="price" name="price" required inputmode="decimal" placeholder="250,00" value="<?= e($v('price', money_input(isset($s['price_cents']) ? (int) $s['price_cents'] : null))) ?>"<?= invalid_attr($errors, 'price') ?>>
            <?= field_error($errors, 'price') ?>
        </div>
        <div>
            <label for="deposit">Sinal (R$) <span class="muted">vazio = <?= e(setting('deposit_percent')) ?>% do valor</span></label>
            <input type="text" id="deposit" name="deposit" inputmode="decimal" value="<?= e($v('deposit', money_input(isset($s['deposit_cents']) ? (int) $s['deposit_cents'] : null))) ?>"<?= invalid_attr($errors, 'deposit') ?>>
            <?= field_error($errors, 'deposit') ?>
        </div>
    </div>

    <div class="grid-2">
        <div>
            <label for="location_mode">Onde é realizado</label>
            <select id="location_mode" name="location_mode">
                <?php foreach (ServiceController::LOCATION_MODES as $k => $label): ?>
                    <option value="<?= e($k) ?>"<?= selected($v('location_mode', $s['location_mode'] ?? 'both'), $k) ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="sort_order">Ordem de exibição</label>
            <input type="number" id="sort_order" name="sort_order" value="<?= e($v('sort_order', $s['sort_order'] ?? 0)) ?>">
        </div>
    </div>

    <fieldset>
        <legend>Profissionais que realizam</legend>
        <?php foreach ($professionals as $p): ?>
            <label class="check"><input type="checkbox" name="professionals[]" value="<?= (int) $p['id'] ?>"<?= checked(in_array((int) $p['id'], $pros, true)) ?>> <?= e($p['name']) ?></label>
        <?php endforeach; ?>
        <?= field_error($errors, 'professionals') ?>
    </fieldset>

    <label class="check"><input type="checkbox" name="active" value="1"<?= checked($active) ?>> Ativo (visível para agendamento)</label>

    <button type="submit" class="btn btn-primary">Salvar serviço</button>
</form>
