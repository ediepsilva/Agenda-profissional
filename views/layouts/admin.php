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
        <?php
        $nav = [
            ['/admin', '/admin', 'Visão geral', ['dashboard.view']],
            ['/admin/agenda', '/admin/agenda', 'Agenda', ['agenda.view', 'agenda.view.own']],
            ['/admin/reservas', '/admin/reservas', Auth::can('bookings.view') ? 'Reservas' : 'Minhas reservas', ['bookings.view', 'bookings.view.own']],
            ['/admin/clientes', '/admin/clientes', Auth::can('clients.view') ? 'Clientes' : 'Minhas clientes', ['clients.view', 'clients.view.own']],
            ['/admin/servicos', '/admin/servicos', 'Serviços', ['services.manage']],
            ['/admin/disponibilidade', '/admin/disponibilidade', Auth::can('availability.manage') ? 'Disponibilidade' : 'Minha disponibilidade', ['availability.manage', 'availability.manage.own']],
            ['/admin/equipe', '/admin/equipe', 'Equipe', ['team.view']],
            ['/admin/areas', '/admin/areas', 'Áreas atendidas', ['areas.manage']],
            ['/admin/financeiro', '/admin/financeiro/despesas', 'Despesas', ['finance.manage']],
            ['/admin/relatorios', '/admin/relatorios', Auth::can('reports.view') ? 'Relatórios' : 'Meu desempenho', ['reports.view', 'reports.view.own']],
            ['/admin/configuracoes', '/admin/configuracoes', 'Configurações', ['settings.manage']],
        ];
        foreach ($nav as [$prefix, $href, $label, $perms]):
            if (!Auth::canAny($perms)) {
                continue;
            } ?>
            <a href="<?= e(url($href)) ?>"<?= nav_active($prefix) ?>><?= e($label) ?></a>
        <?php endforeach; ?>
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
