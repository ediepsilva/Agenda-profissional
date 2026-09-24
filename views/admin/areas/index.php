<header class="page-header">
    <h1>Áreas atendidas</h1>
</header>
<p class="muted">Uma área pode valer para todas as profissionais ou só para uma (ex.: quem mora naquela região). Regiões para atendimento no local da cliente. O deslocamento (em minutos, por trecho) bloqueia a agenda antes e depois do atendimento; a taxa é somada ao valor.</p>

<section class="panel">
    <h2>Nova área</h2>
    <form method="post" action="<?= e(url('/admin/areas')) ?>" class="form">
        <?= csrf_field() ?>
        <div class="grid-4">
            <div><label for="a-name">Nome (bairro, cidade ou região)</label><input type="text" id="a-name" name="name" required maxlength="120" value="<?= e(old('name')) ?>"<?= invalid_attr($errors, 'name') ?>><?= field_error($errors, 'name') ?></div>
            <div><label for="a-travel">Deslocamento (min)</label><input type="number" id="a-travel" name="travel_minutes" min="0" max="480" step="5" value="<?= e(old('travel_minutes', '30')) ?>"><?= field_error($errors, 'travel_minutes') ?></div>
            <div><label for="a-fee">Taxa (R$)</label><input type="text" id="a-fee" name="travel_fee" inputmode="decimal" value="<?= e(old('travel_fee', '0,00')) ?>"><?= field_error($errors, 'travel_fee') ?></div>
            <div><label for="a-pro">Profissional</label><select id="a-pro" name="professional_id"><option value="0">Todas</option><?php foreach ($professionals as $p): ?><option value="<?= (int) $p['id'] ?>"<?= selected(old('professional_id', '0'), $p['id']) ?>><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
            <div><label for="a-order">Ordem</label><input type="number" id="a-order" name="sort_order" value="<?= e(old('sort_order', '0')) ?>"></div>
        </div>
        <input type="hidden" name="active" value="1">
        <button type="submit" class="btn btn-primary">Adicionar</button>
    </form>
</section>

<?php if ($areas): ?>
<section class="panel">
    <h2>Áreas cadastradas</h2>
    <?php foreach ($areas as $a): ?>
        <form method="post" action="<?= e(url('/admin/areas/' . $a['id'])) ?>" class="area-row<?= $a['active'] ? '' : ' inactive' ?>">
            <?= csrf_field() ?>
            <div><label for="n<?= $a['id'] ?>">Nome</label><input type="text" id="n<?= $a['id'] ?>" name="name" value="<?= e($a['name']) ?>" required></div>
            <div><label for="t<?= $a['id'] ?>">Desloc. (min)</label><input type="number" id="t<?= $a['id'] ?>" name="travel_minutes" min="0" max="480" step="5" value="<?= (int) $a['travel_minutes'] ?>"></div>
            <div><label for="f<?= $a['id'] ?>">Taxa (R$)</label><input type="text" id="f<?= $a['id'] ?>" name="travel_fee" value="<?= e(money_input((int) $a['travel_fee_cents'])) ?>"></div>
            <div><label for="p<?= $a['id'] ?>">Profissional</label><select id="p<?= $a['id'] ?>" name="professional_id"><option value="0">Todas</option><?php foreach ($professionals as $p): ?><option value="<?= (int) $p['id'] ?>"<?= selected((int) $a['professional_id'], $p['id']) ?>><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
            <div><label for="o<?= $a['id'] ?>">Ordem</label><input type="number" id="o<?= $a['id'] ?>" name="sort_order" value="<?= (int) $a['sort_order'] ?>"></div>
            <label class="check"><input type="checkbox" name="active" value="1"<?= checked((bool) $a['active']) ?>> Ativa</label>
            <div class="area-actions">
                <button type="submit" class="btn btn-ghost btn-sm">Salvar</button>
                <button type="submit" class="btn btn-ghost btn-sm" formaction="<?= e(url('/admin/areas/' . $a['id'] . '/excluir')) ?>" data-confirm="Excluir esta área?">Excluir</button>
            </div>
        </form>
    <?php endforeach; ?>
</section>
<?php endif; ?>
