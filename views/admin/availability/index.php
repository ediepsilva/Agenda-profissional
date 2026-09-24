<?php
use App\Controllers\AvailabilityController;

$max = AvailabilityController::MAX_WINDOWS_PER_DAY;
?>
<header class="page-header">
    <h1>Disponibilidade</h1>
</header>

<?php if (count($professionals) > 1): ?>
<form method="get" action="<?= e(url('/admin/disponibilidade')) ?>" class="filters" data-autosubmit>
    <label for="prof">Profissional</label>
    <select name="profissional" id="prof">
        <?php foreach ($professionals as $p): ?><option value="<?= (int) $p['id'] ?>"<?= selected($proId, $p['id']) ?>><?= e($p['name']) ?></option><?php endforeach; ?>
    </select>
    <noscript><button class="btn btn-sm">Ver</button></noscript>
</form>
<?php endif; ?>

<?php if (!$proId): ?>
    <p class="alert alert-warning">Nenhuma profissional ativa cadastrada.</p>
<?php else: ?>
<section class="panel">
    <h2>Horário de atendimento semanal</h2>
    <p class="muted small">Até <?= $max ?> períodos por dia (ex.: 09:00–12:00 e 13:00–19:00). Deixe em branco os dias sem atendimento.</p>
    <form method="post" action="<?= e(url('/admin/disponibilidade/horarios')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="professional_id" value="<?= (int) $proId ?>">
        <div class="week-rules">
        <?php for ($wd = 0; $wd <= 6; $wd++): $list = $rules[$wd] ?? []; ?>
            <fieldset class="rule-day">
                <legend><?= e(weekday_name($wd)) ?></legend>
                <?php for ($i = 0; $i < $max; $i++): $w = $list[$i] ?? ['', '']; ?>
                    <div class="rule-row">
                        <label class="sr-only" for="r<?= $wd . $i ?>s"><?= e(weekday_name($wd)) ?> período <?= $i + 1 ?> início</label>
                        <input type="time" id="r<?= $wd . $i ?>s" name="rules[<?= $wd ?>][<?= $i ?>][start]" value="<?= e($w[0]) ?>">
                        <span>às</span>
                        <label class="sr-only" for="r<?= $wd . $i ?>e"><?= e(weekday_name($wd)) ?> período <?= $i + 1 ?> fim</label>
                        <input type="time" id="r<?= $wd . $i ?>e" name="rules[<?= $wd ?>][<?= $i ?>][end]" value="<?= e($w[1]) ?>">
                    </div>
                <?php endfor; ?>
            </fieldset>
        <?php endfor; ?>
        </div>
        <button type="submit" class="btn btn-primary">Salvar horários</button>
    </form>
</section>

<section class="panel">
    <h2>Bloqueios de agenda</h2>
    <p class="muted small">Folgas, compromissos e viagens. Horários bloqueados não aparecem para as clientes.</p>
    <form method="post" action="<?= e(url('/admin/disponibilidade/bloqueios')) ?>" class="form block-form">
        <?= csrf_field() ?>
        <input type="hidden" name="professional_id" value="<?= (int) $proId ?>">
        <div class="grid-4">
            <div><label for="b-sd">Início</label><input type="date" id="b-sd" name="start_date" required></div>
            <div data-time-field><label for="b-st">Hora inicial</label><input type="time" id="b-st" name="start_time"></div>
            <div><label for="b-ed">Fim</label><input type="date" id="b-ed" name="end_date"></div>
            <div data-time-field><label for="b-et">Hora final</label><input type="time" id="b-et" name="end_time"></div>
        </div>
        <label class="check"><input type="checkbox" name="all_day" value="1" id="b-allday"> Dia(s) inteiro(s)</label>
        <label for="b-reason">Motivo <span class="muted">(opcional, só a equipe vê)</span></label>
        <input type="text" id="b-reason" name="reason" maxlength="190">
        <button type="submit" class="btn btn-primary">Adicionar bloqueio</button>
    </form>

    <?php if ($blocks): ?>
        <ul class="block-list">
            <?php foreach ($blocks as $bl): ?>
                <li>
                    <span><strong><?= e(datetime_br($bl['starts_at'])) ?></strong> até <strong><?= e(datetime_br($bl['ends_at'])) ?></strong><?= $bl['reason'] ? ' · ' . e($bl['reason']) : '' ?></span>
                    <form method="post" action="<?= e(url('/admin/disponibilidade/bloqueios/' . $bl['id'] . '/excluir')) ?>" data-confirm="Remover este bloqueio?">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-ghost btn-sm">Remover</button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <p class="muted">Nenhum bloqueio futuro.</p>
    <?php endif; ?>
</section>
<?php endif; ?>
