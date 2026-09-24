<section class="section">
<div class="wrap auth-box">
    <h1>Área da profissional</h1>
    <?php if (isset($errors['login'])): ?><div class="alert alert-error" role="alert"><?= e($errors['login']) ?></div><?php endif; ?>
    <form method="post" action="<?= e(url('/admin/login')) ?>" class="card">
        <?= csrf_field() ?>
        <label for="email">E-mail</label>
        <input type="email" name="email" id="email" required autocomplete="username" value="<?= e(old('email')) ?>" autofocus>
        <label for="password">Senha</label>
        <input type="password" name="password" id="password" required autocomplete="current-password">
        <button type="submit" class="btn btn-primary btn-block">Entrar</button>
    </form>
</div>
</section>
