<section class="hero">
    <div class="wrap hero-inner">
        <p class="eyebrow">Maquiagem artística</p>
        <h1><?= e(setting('business_name')) ?></h1>
        <p class="lead"><?= e(setting('tagline')) ?></p>
        <div class="hero-actions">
            <a class="btn btn-primary btn-lg" href="<?= e(url('/agendar')) ?>">Agendar horário</a>
            <a class="btn btn-ghost btn-lg" href="#servicos">Ver serviços</a>
        </div>
    </div>
</section>

<?php if (setting('about')): ?>
<section class="section">
    <div class="wrap narrow">
        <h2>Sobre</h2>
        <p class="about"><?= nl2br(e(setting('about'))) ?></p>
    </div>
</section>
<?php endif; ?>

<section class="section section-alt" id="servicos">
    <div class="wrap">
        <h2>Serviços</h2>
        <?php if (!$services): ?>
            <p class="muted">Os serviços serão publicados em breve.</p>
        <?php else: ?>
        <div class="cards">
            <?php foreach ($services as $s): ?>
            <article class="card service-card">
                <?php if ($s['category']): ?><p class="eyebrow"><?= e($s['category']) ?></p><?php endif; ?>
                <h3><?= e($s['name']) ?></h3>
                <?php if ($s['description']): ?><p><?= nl2br(e($s['description'])) ?></p><?php endif; ?>
                <dl class="service-meta">
                    <div><dt>Duração</dt><dd><?= e(duration_br((int) $s['duration_minutes'])) ?></dd></div>
                    <div><dt>A partir de</dt><dd><?= e(money((int) $s['price_cents'])) ?></dd></div>
                    <div><dt>Local</dt><dd><?= e(['both' => 'Estúdio ou a domicílio', 'studio' => 'No estúdio', 'client' => 'A domicílio'][$s['location_mode']]) ?></dd></div>
                </dl>
                <a class="btn btn-primary" href="<?= e(url('/agendar', ['servico' => $s['id']])) ?>">Agendar este serviço</a>
            </article>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</section>

<?php if ($areas || setting('service_area_text') || setting('studio_address')): ?>
<section class="section">
    <div class="wrap narrow">
        <h2>Onde atendo</h2>
        <?php if (setting('studio_address')): ?>
            <p><strong>Estúdio:</strong> <?= e(setting('studio_address')) ?></p>
        <?php endif; ?>
        <?php if (setting('service_area_text')): ?><p><?= nl2br(e(setting('service_area_text'))) ?></p><?php endif; ?>
        <?php if ($areas): ?>
            <p>Atendimento a domicílio nas regiões:</p>
            <ul class="chips">
                <?php foreach ($areas as $a): ?>
                    <li><?= e($a['name']) ?><?= (int) $a['travel_fee_cents'] > 0 ? ' · taxa ' . e(money((int) $a['travel_fee_cents'])) : '' ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<?php if (setting('instagram')): ?>
<section class="section section-alt">
    <div class="wrap narrow center">
        <h2>Portfólio</h2>
        <p>Veja trabalhos recentes, noivas e produções no Instagram.</p>
        <a class="btn btn-ghost" href="https://instagram.com/<?= e(setting('instagram')) ?>" target="_blank" rel="noopener">@<?= e(setting('instagram')) ?></a>
    </div>
</section>
<?php endif; ?>

<section class="section">
    <div class="wrap narrow">
        <h2>Como funciona</h2>
        <ol class="steps">
            <li>Escolha o serviço, o local, a data e um horário livre.</li>
            <li>Envie a solicitação com seu nome e WhatsApp.</li>
            <li>Você recebe a confirmação e as orientações para o atendimento.</li>
        </ol>
        <?php foreach (['deposit_policy' => 'Sinal', 'cancellation_policy' => 'Cancelamento', 'reschedule_policy' => 'Reagendamento'] as $k => $label): ?>
            <?php if (setting($k)): ?><p><strong><?= e($label) ?>:</strong> <?= e(setting($k)) ?></p><?php endif; ?>
        <?php endforeach; ?>
        <p class="center"><a class="btn btn-primary btn-lg" href="<?= e(url('/agendar')) ?>">Agendar agora</a></p>
    </div>
</section>
