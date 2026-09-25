<section class="section">
<div class="wrap narrow">
    <p class="eyebrow">Avaliação</p>
    <h1>Como foi seu atendimento?</h1>
    <p class="muted"><?= e($b['kind'] === 'event' ? $b['event_name'] : $b['service_name']) ?> · <?= e(date_br($b['starts_at'])) ?></p>

    <?php if (!$canReview): ?>
        <div class="alert alert-info"><?= $b['status'] === 'completed' ? 'Você já avaliou este atendimento. Obrigada!' : 'A avaliação fica disponível depois do atendimento.' ?></div>
        <p><a class="btn btn-ghost" href="<?= e(url('/reserva/' . $b['public_code'])) ?>">Voltar para a reserva</a></p>
    <?php else: ?>
    <form method="post" action="<?= e(url('/avaliar/' . $b['public_code'])) ?>" class="card">
        <?= csrf_field() ?>
        <fieldset class="stars" aria-describedby="err-rating">
            <legend>Sua nota</legend>
            <?php for ($i = 5; $i >= 1; $i--): ?>
                <input type="radio" id="star<?= $i ?>" name="rating" value="<?= $i ?>"<?= checked((int) old('rating') === $i) ?> required>
                <label for="star<?= $i ?>" title="<?= $i ?> estrela(s)"><span class="sr-only"><?= $i ?> estrela(s)</span>★</label>
            <?php endfor; ?>
        </fieldset>
        <?= field_error($errors, 'rating') ?>
        <label for="comment">Conte como foi <span class="muted">(opcional)</span></label>
        <textarea id="comment" name="comment" rows="4" maxlength="1000"><?= e(old('comment')) ?></textarea>
        <?= field_error($errors, 'comment') ?>
        <p class="muted small">Sua avaliação pode aparecer na página com seu primeiro nome e a inicial do sobrenome.</p>
        <button type="submit" class="btn btn-primary btn-block">Enviar avaliação</button>
    </form>
    <?php endif; ?>
</div>
</section>
