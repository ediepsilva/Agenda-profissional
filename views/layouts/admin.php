<?php
use App\Core\Auth;
use App\Core\Session;
use App\Domain\Permissions;

$user = Auth::user();
$businessName = setting('business_name', 'Studio de Maquiagem');
?><!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e(($pageTitle ?? 'Painel') . ' · ' . $businessName) ?></title>
    <meta name="theme-color" content="#7d3f53">
    <link rel="manifest" href="<?= e(url('/manifest.webmanifest')) ?>">
    <link rel="icon" href="<?= e(asset('/assets/icons/icon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset('/assets/css/app.css')) ?>">
    <script src="<?= e(asset('/assets/js/app.js')) ?>" defer></script>
</head>
<body class="admin" data-base="<?= e(base_path_url()) ?>">
<a class="skip-link" href="#conteudo">Pular para o conteúdo</a>
<?php if ($user): ?>
<aside class="sidebar">
    <div class="sidebar-brand">
        <a href="<?= e(url('/admin')) ?>"><?= e($businessName) ?></a>
        <small><?= e($user['name']) ?> · <?= e(Permissions::roleLabel($user['role'])) ?></small>
    </div>
    <nav class="sidebar-nav" aria-label="Painel">
        <?php if (Auth::can('dashboard.view')): ?><a href="<?= e(url('/admin')) ?>"<?= nav_active('/admin') ?>>Visão geral</a><?php endif; ?>
        <?php if (Auth::can('agenda.view')): ?><a href="<?= e(url('/admin/agenda')) ?>"<?= nav_active('/admin/agenda') ?>>Agenda</a><?php endif; ?>
        <?php if (Auth::can('bookings.view')): ?><a href="<?= e(url('/admin/reservas')) ?>"<?= nav_active('/admin/reservas') ?>>Reservas</a><?php endif; ?>
        <?php if (Auth::can('clients.view')): ?><a href="<?= e(url('/admin/clientes')) ?>"<?= nav_active('/admin/clientes') ?>>Clientes</a><?php endif; ?>
        <?php if (Auth::can('services.manage')): ?><a href="<?= e(url('/admin/servicos')) ?>"<?= nav_active('/admin/servicos') ?>>Serviços</a><?php endif; ?>
        <?php if (Auth::can('availability.manage')): ?><a href="<?= e(url('/admin/disponibilidade')) ?>"<?= nav_active('/admin/disponibilidade') ?>>Disponibilidade</a><?php endif; ?>
        <?php if (Auth::can('areas.manage')): ?><a href="<?= e(url('/admin/areas')) ?>"<?= nav_active('/admin/areas') ?>>Áreas atendidas</a><?php endif; ?>
        <?php if (Auth::can('settings.manage')): ?><a href="<?= e(url('/admin/configuracoes')) ?>"<?= nav_active('/admin/configuracoes') ?>>Configurações</a><?php endif; ?>
        <a href="<?= e(url('/')) ?>" target="_blank" rel="noopener">Ver página pública ↗</a>
    </nav>
    <form method="post" action="<?= e(url('/admin/logout')) ?>" class="sidebar-logout">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-ghost btn-sm">Sair</button>
    </form>
</aside>
<?php endif; ?>
<main id="conteudo" class="admin-main">
    <?php foreach (Session::takeFlash() as $f): ?>
        <div class="alert alert-<?= e($f['type']) ?>" role="status"><?= e($f['message']) ?></div>
    <?php endforeach; ?>
    <?= $content ?>
</main>
</body>
</html>
