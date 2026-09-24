<?php
use App\Core\Session;

$businessName = setting('business_name', 'Studio de Maquiagem');
$title = isset($pageTitle) ? $pageTitle . ' · ' . $businessName : $businessName . ' · ' . setting('tagline');
?><!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?= e($title) ?></title>
    <meta name="description" content="<?= e(setting('tagline')) ?>">
    <meta name="theme-color" content="#7d3f53">
    <link rel="manifest" href="<?= e(url('/manifest.webmanifest')) ?>">
    <link rel="icon" href="<?= e(asset('/assets/icons/icon.svg')) ?>" type="image/svg+xml">
    <link rel="apple-touch-icon" href="<?= e(asset('/assets/icons/icon-192.png')) ?>">
    <link rel="stylesheet" href="<?= e(asset('/assets/css/app.css')) ?>">
    <script src="<?= e(asset('/assets/js/app.js')) ?>" defer></script>
</head>
<body class="public" data-base="<?= e(base_path_url()) ?>">
<a class="skip-link" href="#conteudo">Pular para o conteúdo</a>
<header class="site-header">
    <div class="wrap site-header-inner">
        <a class="brand" href="<?= e(url('/')) ?>"><?= e($businessName) ?></a>
        <nav aria-label="Principal">
            <a href="<?= e(url('/') . '#servicos') ?>">Serviços</a>
            <a class="btn btn-primary btn-sm" href="<?= e(url('/agendar')) ?>">Agendar</a>
        </nav>
    </div>
</header>
<main id="conteudo">
    <?php foreach (Session::takeFlash() as $f): ?>
        <div class="wrap"><div class="alert alert-<?= e($f['type']) ?>" role="status"><?= e($f['message']) ?></div></div>
    <?php endforeach; ?>
    <?= $content ?>
</main>
<footer class="site-footer">
    <div class="wrap">
        <p><strong><?= e($businessName) ?></strong><?= setting('city') ? ' · ' . e(setting('city')) : '' ?></p>
        <p>
            <?php if (setting('whatsapp')): ?><a href="<?= e(whatsapp_link(setting('whatsapp'))) ?>" rel="noopener" target="_blank">WhatsApp</a> · <?php endif; ?>
            <?php if (setting('instagram')): ?><a href="https://instagram.com/<?= e(setting('instagram')) ?>" rel="noopener" target="_blank">Instagram</a> · <?php endif; ?>
            <a href="<?= e(url('/privacidade')) ?>">Privacidade</a> ·
            <a href="<?= e(url('/admin/login')) ?>">Área da profissional</a>
        </p>
    </div>
</footer>
</body>
</html>
