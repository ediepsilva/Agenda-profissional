<section class="wrap narrow error-page">
    <p class="eyebrow">Erro <?= e($status) ?></p>
    <h1><?= e($title) ?></h1>
    <p><?= e($message) ?></p>
    <p><a class="btn btn-primary" href="<?= e(url('/')) ?>">Ir para o início</a></p>
</section>
