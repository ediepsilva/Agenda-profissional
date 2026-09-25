<?php
use App\Domain\BookingService;
use App\Domain\CampaignService;

$aud = old('audience', 'all');
?>
<header class="page-header"><h1>Campanhas</h1></header>
<p class="muted">Campanhas são ofertas e novidades — separadas dos avisos da reserva. Elas só são enviadas para clientes que <strong>aceitaram receber ofertas</strong> (<?= $optedIn ?> de <?= $totalClients ?> clientes). Toda mensagem oferece a opção de responder SAIR.</p>

<section class="panel">
    <h2>Nova campanha</h2>
    <?php if (!$templates): ?><p class="alert alert-warning">Nenhum modelo de campanha ativo. Ative um em Mensagens → Modelos.</p><?php endif; ?>
    <form method="post" action="<?= e(url('/admin/campanhas')) ?>" class="form">
        <?= csrf_field() ?>
        <div class="grid-2">
            <div><label for="c-name">Nome interno</label><input type="text" id="c-name" name="name" maxlength="120" required value="<?= e(old('name')) ?>" placeholder="Ex.: Promoção de maio"<?= invalid_attr($errors, 'name') ?>><?= field_error($errors, 'name') ?></div>
            <div>
                <label for="c-tpl">Modelo</label>
                <select id="c-tpl" name="template_key">
                    <?php foreach ($templates as $t): ?><option value="<?= e($t['template_key']) ?>"<?= selected(old('template_key'), $t['template_key']) ?>><?= e($t['label']) ?></option><?php endforeach; ?>
                </select>
                <?= field_error($errors, 'template_key') ?>
            </div>
            <div>
                <label for="c-aud">Público</label>
                <select id="c-aud" name="audience">
                    <?php foreach (CampaignService::AUDIENCES as $k => $l): ?><option value="<?= $k ?>"<?= selected($aud, $k) ?>><?= e($l) ?></option><?php endforeach; ?>
                </select>
                <?= field_error($errors, 'audience') ?>
            </div>
            <div>
                <label for="c-param">Filtro do público</label>
                <input type="text" id="c-param" name="audience_param" value="<?= e(old('audience_param')) ?>" placeholder="origem (ex.: instagram), dias (ex.: 90) ou mês (1–12)"<?= invalid_attr($errors, 'audience_param') ?>>
                <span class="muted small">Origem: <?= e(implode(', ', array_keys(BookingService::SOURCES))) ?>. Reativação: dias sem atendimento. Aniversariantes: número do mês.</span>
                <?= field_error($errors, 'audience_param') ?>
            </div>
        </div>
        <label for="c-offer">Oferta (entra no lugar de {{oferta}})</label>
        <input type="text" id="c-offer" name="offer_text" maxlength="190" required value="<?= e(old('offer_text')) ?>" placeholder="Ex.: Em maio, maquiagem social com 15% de desconto de terça a quinta."<?= invalid_attr($errors, 'offer_text') ?>>
        <?= field_error($errors, 'offer_text') ?>
        <button class="btn btn-primary" type="submit">Criar rascunho</button>
    </form>
</section>

<?php if ($campaigns): ?>
<div class="table-wrap">
<table class="table">
    <thead><tr><th>Campanha</th><th>Público</th><th>Situação</th><th>Resultados</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($campaigns as $c): ?>
        <tr>
            <td data-label="Campanha"><strong><?= e($c['name']) ?></strong><br><span class="muted small"><?= e($c['template_label']) ?> · <?= e($c['offer_text']) ?></span></td>
            <td data-label="Público"><?= e($c['audience_label']) ?><?php if ($c['preview_count'] !== null): ?><br><span class="muted small"><?= $c['preview_count'] ?> cliente(s) com consentimento</span><?php endif; ?></td>
            <td data-label="Situação"><?= $c['status'] === 'draft' ? '<span class="badge badge-cancelled">Rascunho</span>' : '<span class="badge badge-confirmed">Enviada</span><br><span class="muted small">' . e(datetime_br($c['queued_at'])) . '</span>' ?></td>
            <td data-label="Resultados"><?= $c['status'] === 'draft' ? '—' : (int) $c['recipients_count'] . ' na fila · ' . (int) $c['sent'] . ' enviadas · ' . (int) $c['read_count'] . ' lidas · <strong>' . (int) $c['bookings'] . ' reserva(s)</strong>' ?></td>
            <td class="actions">
                <?php if ($c['status'] === 'draft'): ?>
                <form method="post" action="<?= e(url('/admin/campanhas/' . $c['id'] . '/enviar')) ?>" data-confirm="Enviar para <?= (int) $c['preview_count'] ?> cliente(s)?"><?= csrf_field() ?><button class="btn btn-primary btn-sm">Enviar</button></form>
                <form method="post" action="<?= e(url('/admin/campanhas/' . $c['id'] . '/excluir')) ?>" data-confirm="Excluir o rascunho?"><?= csrf_field() ?><button class="btn btn-ghost btn-sm">Excluir</button></form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
