<?php
use App\Domain\Permissions;
use App\Domain\TeamService;

$pro = $m['pro'] ?? null;
$user = $m['user'] ?? null;
$hasOld = (bool) $errors;
$val = static fn (string $k, mixed $default) => $hasOld ? old($k, '') : $default;
$chk = static fn (string $k, bool $default) => $hasOld ? (bool) old($k) : $default;

$hasAgenda = $chk('has_agenda', $m ? ($pro !== null && (int) $pro['active'] === 1) : true);
$hasLogin = $chk('has_login', $m ? ($user !== null && (int) $user['active'] === 1) : true);
$active = $chk('active', $m ? (bool) (($pro['active'] ?? null) ?? ($user['active'] ?? 1)) : true);
$selServices = $hasOld ? array_map('intval', (array) old('services', [])) : ($m ? $m['services'] : array_map(static fn ($s) => (int) $s['id'], array_filter($services, static fn ($s) => $s['active'])));
$role = $val('role', $user['role'] ?? 'artist');
?>
<header class="page-header">
    <h1><?= e($pageTitle) ?></h1>
    <a class="btn btn-ghost" href="<?= e(url('/admin/equipe')) ?>">Voltar</a>
</header>

<?php if ($errors): ?><div class="alert alert-error" role="alert"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>

<form method="post" action="<?= e(url($m ? '/admin/equipe/salvar' : '/admin/equipe')) ?>" class="form">
    <?= csrf_field() ?>
    <?php if ($m): ?>
        <input type="hidden" name="p" value="<?= e($pro['id'] ?? '') ?>">
        <input type="hidden" name="u" value="<?= e($user['id'] ?? '') ?>">
    <?php endif; ?>

    <section class="panel">
        <label for="name">Nome</label>
        <input type="text" id="name" name="name" required maxlength="120" value="<?= e($val('name', $pro['name'] ?? $user['name'] ?? '')) ?>"<?= invalid_attr($errors, 'name') ?>>
        <?= field_error($errors, 'name') ?>
        <label class="check"><input type="checkbox" name="active" value="1"<?= checked($active) ?><?= $isSelf ? ' disabled' : '' ?>> Ativo</label>
        <?php if ($isSelf): ?><input type="hidden" name="active" value="1"><p class="muted small">Você não pode desativar o próprio acesso.</p><?php endif; ?>
    </section>

    <section class="panel">
        <label class="check"><input type="checkbox" name="has_agenda" value="1"<?= checked($hasAgenda) ?>> <strong>Atende clientes</strong> (tem agenda própria)</label>
        <?= field_error($errors, 'has_agenda') ?>
        <div class="grid-3">
            <div>
                <label for="color">Cor na agenda</label>
                <input type="color" id="color" name="color" value="<?= e($val('color', $pro['color'] ?? '#b0677a')) ?>">
                <?= field_error($errors, 'color') ?>
            </div>
            <div>
                <label for="commission_percent">Comissão (%)</label>
                <input type="text" id="commission_percent" name="commission_percent" inputmode="decimal" value="<?= e($val('commission_percent', isset($pro['commission_percent']) ? rtrim(rtrim(number_format((float) $pro['commission_percent'], 2, ',', ''), '0'), ',') : '0')) ?>"<?= invalid_attr($errors, 'commission_percent') ?>>
                <span class="muted small">Sobre a parte do valor atribuída a ela (sem taxa de deslocamento). Use 0 para a dona.</span>
                <?= field_error($errors, 'commission_percent') ?>
            </div>
            <div>
                <label class="check" style="margin-top: 2.2rem"><input type="checkbox" name="accepts_online_booking" value="1"<?= checked($chk('accepts_online_booking', $pro ? (bool) $pro['accepts_online_booking'] : true)) ?>> Recebe agendamentos pela página pública</label>
            </div>
        </div>
        <label for="bio">Apresentação <span class="muted">(opcional)</span></label>
        <textarea id="bio" name="bio" rows="2" maxlength="2000"><?= e($val('bio', $pro['bio'] ?? '')) ?></textarea>
        <fieldset>
            <legend class="small">Serviços que realiza</legend>
            <div class="check-grid">
            <?php foreach ($services as $s): ?>
                <label class="check"><input type="checkbox" name="services[]" value="<?= (int) $s['id'] ?>"<?= checked(in_array((int) $s['id'], $selServices, true)) ?>> <?= e($s['name']) ?><?= $s['active'] ? '' : ' <span class="muted small">(inativo)</span>' ?></label>
            <?php endforeach; ?>
            </div>
        </fieldset>
        <?php if ($pro): ?><p class="small"><a href="<?= e(url('/admin/disponibilidade', ['profissional' => $pro['id']])) ?>">Configurar disponibilidade e bloqueios desta profissional →</a></p><?php endif; ?>
    </section>

    <section class="panel">
        <label class="check"><input type="checkbox" name="has_login" value="1"<?= checked($hasLogin) ?><?= $isSelf ? ' disabled' : '' ?>> <strong>Acessa o painel</strong> (tem login)</label>
        <?php if ($isSelf): ?><input type="hidden" name="has_login" value="1"><?php endif; ?>
        <div class="grid-3">
            <div>
                <label for="email">E-mail de acesso</label>
                <input type="email" id="email" name="email" maxlength="190" autocomplete="off" value="<?= e($val('email', $user['email'] ?? '')) ?>"<?= invalid_attr($errors, 'email') ?>>
                <?= field_error($errors, 'email') ?>
            </div>
            <div>
                <label for="role">Papel</label>
                <select id="role" name="role"<?= $isSelf ? ' disabled' : '' ?>>
                    <?php foreach (Permissions::ROLES as $k => $label): ?><option value="<?= e($k) ?>"<?= selected($role, $k) ?>><?= e($label) ?></option><?php endforeach; ?>
                </select>
                <?php if ($isSelf): ?><input type="hidden" name="role" value="<?= e($user['role']) ?>"><?php endif; ?>
                <?= field_error($errors, 'role') ?>
            </div>
            <div>
                <label for="password"><?= $user ? 'Nova senha' : 'Senha inicial' ?> <span class="muted">(mín. <?= TeamService::MIN_PASSWORD ?>)</span></label>
                <input type="password" id="password" name="password" autocomplete="new-password" minlength="<?= TeamService::MIN_PASSWORD ?>"<?= invalid_attr($errors, 'password') ?>>
                <?php if ($user): ?><span class="muted small">Deixe em branco para manter a atual.</span><?php endif; ?>
                <?= field_error($errors, 'password') ?>
            </div>
        </div>
        <dl class="details small">
            <?php foreach (Permissions::ROLE_DESCRIPTIONS as $k => $desc): ?><div><dt><?= e(Permissions::roleLabel($k)) ?></dt><dd><?= e($desc) ?></dd></div><?php endforeach; ?>
        </dl>
    </section>

    <button type="submit" class="btn btn-primary">Salvar</button>
</form>
