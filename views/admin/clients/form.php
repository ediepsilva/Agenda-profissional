<?php
use App\Domain\BookingService;

$v = static fn (string $f) => old($f, $c[$f] ?? '');
$action = $c ? url('/admin/clientes/' . $c['id']) : url('/admin/clientes');
?>
<header class="page-header">
    <h1><?= e($pageTitle) ?></h1>
    <a class="btn btn-ghost" href="<?= e($c ? url('/admin/clientes/' . $c['id']) : url('/admin/clientes')) ?>">Voltar</a>
</header>
<p class="muted small">Registre só o necessário para o atendimento (LGPD). Evite dados sensíveis que não sejam úteis ao serviço.</p>

<form method="post" action="<?= e($action) ?>" class="panel form">
    <?= csrf_field() ?>
    <div class="grid-2">
        <div><label for="name">Nome</label><input type="text" id="name" name="name" required maxlength="120" value="<?= e($v('name')) ?>"<?= invalid_attr($errors, 'name') ?>><?= field_error($errors, 'name') ?></div>
        <div><label for="phone">WhatsApp</label><input type="tel" id="phone" name="phone" required value="<?= e($errors ? old('phone') : phone_br($c['phone'] ?? '')) ?>"<?= invalid_attr($errors, 'phone') ?>><?= field_error($errors, 'phone') ?></div>
        <div><label for="email">E-mail</label><input type="email" id="email" name="email" maxlength="190" value="<?= e($v('email')) ?>"<?= invalid_attr($errors, 'email') ?>><?= field_error($errors, 'email') ?></div>
        <div><label for="instagram">Instagram</label><input type="text" id="instagram" name="instagram" maxlength="31" placeholder="@usuario" value="<?= e($v('instagram')) ?>"<?= invalid_attr($errors, 'instagram') ?>><?= field_error($errors, 'instagram') ?></div>
        <div><label for="birth_date">Aniversário</label><input type="date" id="birth_date" name="birth_date" value="<?= e($v('birth_date')) ?>"<?= invalid_attr($errors, 'birth_date') ?>><?= field_error($errors, 'birth_date') ?></div>
        <div>
            <label for="source">Como conheceu</label>
            <select id="source" name="source">
                <option value="">—</option>
                <?php foreach (BookingService::SOURCES as $k => $label): ?><option value="<?= e($k) ?>"<?= selected($v('source'), $k) ?>><?= e($label) ?></option><?php endforeach; ?>
            </select>
        </div>
    </div>
    <label for="preferences">Preferências <span class="muted">(tipo de pele, estilo, produtos preferidos)</span></label>
    <textarea id="preferences" name="preferences" rows="3" maxlength="2000"><?= e($v('preferences')) ?></textarea>
    <?= field_error($errors, 'preferences') ?>
    <label for="notes">Observações internas</label>
    <textarea id="notes" name="notes" rows="3" maxlength="2000"><?= e($v('notes')) ?></textarea>
    <?= field_error($errors, 'notes') ?>
    <button type="submit" class="btn btn-primary">Salvar</button>
</form>
