<?php
use App\Core\Auth;
use App\Domain\MessageService;
?>
<header class="page-header">
    <h1>Mensagens</h1>
    <form method="post" action="<?= e(url('/admin/mensagens/processar')) ?>">
        <?= csrf_field() ?>
        <button class="btn btn-primary" type="submit">Processar fila agora</button>
    </form>
</header>

<?php if (!$configured): ?>
<div class="alert alert-warning">
    <strong>Modo simulado:</strong> o WhatsApp oficial ainda não está configurado, então nada é enviado de verdade — as mensagens ficam registradas aqui como "Enviada (simulado)" para você conferir os textos e os fluxos.
    Para ativar o envio real, veja "Como conectar o WhatsApp" no fim desta página.
</div>
<?php endif; ?>
<?php if (!$enabled): ?><div class="alert alert-info">Os avisos automáticos estão desligados em Configurações.</div><?php endif; ?>

<div class="stats">
    <?php foreach (['queued' => 'Na fila', 'sent' => 'Enviadas', 'read' => 'Lidas', 'failed' => 'Falharam'] as $k => $l): ?>
        <a class="stat" href="<?= e(url('/admin/mensagens', ['status' => $k])) ?>"><span class="stat-n"><?= (int) ($counts[$k] ?? 0) ?></span><span><?= e($l) ?></span></a>
    <?php endforeach; ?>
</div>

<form method="get" action="<?= e(url('/admin/mensagens')) ?>" class="filters panel">
    <div>
        <label for="f-status">Situação</label>
        <select id="f-status" name="status"><option value="">Todas</option>
            <?php foreach (MessageService::STATUS_LABELS as $k => $l): ?><option value="<?= $k ?>"<?= selected($status, $k) ?>><?= e($l) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div>
        <label for="f-tipo">Tipo</label>
        <select id="f-tipo" name="tipo"><option value="">Todos</option>
            <option value="transactional"<?= selected($category, 'transactional') ?>>Avisos da reserva</option>
            <option value="marketing"<?= selected($category, 'marketing') ?>>Campanhas</option>
        </select>
    </div>
    <div class="filters-actions"><button class="btn btn-primary">Filtrar</button></div>
</form>

<?php if (!$rows): ?><p class="muted">Nenhuma mensagem.</p><?php else: ?>
<div class="table-wrap">
<table class="table">
    <thead><tr><th>Quando</th><th>Cliente</th><th>Mensagem</th><th>Situação</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $m): ?>
        <tr>
            <td data-label="Quando"><?= e(datetime_br($m['sent_at'] ?: $m['scheduled_at'])) ?></td>
            <td data-label="Cliente"><a href="<?= e(url('/admin/clientes/' . $m['client_id'])) ?>"><?= e($m['client_name']) ?></a><br><span class="muted small"><?= e(phone_br($m['to_phone'])) ?></span></td>
            <td data-label="Mensagem"><span class="badge <?= $m['category'] === 'marketing' ? 'badge-event' : 'badge-requested' ?>"><?= e($m['template_label']) ?></span>
                <?php if ($m['booking_id']): ?> <a class="small" href="<?= e(url('/admin/reservas/' . $m['booking_id'])) ?>">reserva #<?= (int) $m['booking_id'] ?></a><?php endif; ?>
                <p class="msg-body"><?= e($m['body']) ?></p></td>
            <td data-label="Situação"><span class="badge msg-<?= e($m['status']) ?>"><?= e(MessageService::STATUS_LABELS[$m['status']]) ?><?= $m['provider'] === 'simulado' ? ' (simulado)' : '' ?></span>
                <?php if ($m['last_error']): ?><br><span class="muted small"><?= e($m['last_error']) ?></span><?php endif; ?></td>
            <td class="actions">
                <?php if ($m['status'] === 'queued'): ?>
                <form method="post" action="<?= e(url('/admin/mensagens/' . $m['id'] . '/cancelar')) ?>"><?= csrf_field() ?><button class="btn btn-ghost btn-sm">Cancelar</button></form>
                <?php elseif ($m['status'] === 'failed'): ?>
                <form method="post" action="<?= e(url('/admin/mensagens/' . $m['id'] . '/reenviar')) ?>"><?= csrf_field() ?><button class="btn btn-ghost btn-sm">Tentar de novo</button></form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php if ($pages > 1): ?>
    <nav class="pager" aria-label="Paginação">
        <?php if ($page > 1): ?><a class="btn btn-ghost btn-sm" href="<?= e(url('/admin/mensagens', array_filter(['status' => $status, 'tipo' => $category, 'pagina' => $page - 1]))) ?>">‹ Anterior</a><?php endif; ?>
        <span class="muted small">Página <?= $page ?> de <?= $pages ?></span>
        <?php if ($page < $pages): ?><a class="btn btn-ghost btn-sm" href="<?= e(url('/admin/mensagens', array_filter(['status' => $status, 'tipo' => $category, 'pagina' => $page + 1]))) ?>">Próxima ›</a><?php endif; ?>
    </nav>
<?php endif; ?>
<?php endif; ?>

<section class="panel" id="modelos">
    <h2>Modelos de mensagem</h2>
    <p class="muted small">No WhatsApp oficial, mensagens enviadas pela empresa precisam de <strong>modelos aprovados pela Meta</strong>. Cadastre cada modelo no WhatsApp Manager com o mesmo nome e as variáveis {{1}}, {{2}}… na ordem indicada. Avisos da reserva usam a categoria "Utilidade"; campanhas, "Marketing".
        Variáveis disponíveis: nome, servico, data, hora, local, profissional, link, link_avaliacao, link_indicacao, oferta.</p>
    <?php foreach ($templates as $t): $canEdit = Auth::can('settings.manage'); ?>
        <form method="post" action="<?= e(url('/admin/mensagens/modelos/' . $t['template_key'])) ?>" class="template-row">
            <?= csrf_field() ?>
            <h3><?= e($t['label']) ?> <span class="badge <?= $t['category'] === 'marketing' ? 'badge-event' : 'badge-requested' ?>"><?= $t['category'] === 'marketing' ? 'Campanha' : 'Aviso da reserva' ?></span></h3>
            <div class="grid-4">
                <div><label for="n-<?= e($t['template_key']) ?>">Nome do modelo na Meta</label><input type="text" id="n-<?= e($t['template_key']) ?>" name="name" value="<?= e($t['name']) ?>"<?= $canEdit ? '' : ' disabled' ?>></div>
                <div><label for="l-<?= e($t['template_key']) ?>">Idioma</label><input type="text" id="l-<?= e($t['template_key']) ?>" name="language" value="<?= e($t['language']) ?>"<?= $canEdit ? '' : ' disabled' ?>></div>
                <div><label for="p-<?= e($t['template_key']) ?>">Variáveis ({{1}}, {{2}}…)</label><input type="text" id="p-<?= e($t['template_key']) ?>" name="param_order" value="<?= e($t['param_order']) ?>"<?= $canEdit ? '' : ' disabled' ?>></div>
                <div><label for="o-<?= e($t['template_key']) ?>"><?= in_array($t['template_key'], ['reminder', 'pre_care'], true) ? 'Horas antes' : ($t['template_key'] === 'thanks_review' ? 'Horas depois' : 'Horas') ?></label><input type="number" id="o-<?= e($t['template_key']) ?>" name="offset_hours" min="0" max="720" value="<?= (int) $t['offset_hours'] ?>"<?= $canEdit && in_array($t['template_key'], ['reminder', 'pre_care', 'thanks_review'], true) ? '' : ' disabled' ?>><?php if (!in_array($t['template_key'], ['reminder', 'pre_care', 'thanks_review'], true)): ?><input type="hidden" name="offset_hours" value="<?= (int) $t['offset_hours'] ?>"><?php endif; ?></div>
            </div>
            <label for="t-<?= e($t['template_key']) ?>">Texto (como a cliente vê)</label>
            <textarea id="t-<?= e($t['template_key']) ?>" name="preview" rows="2"<?= $canEdit ? '' : ' disabled' ?>><?= e($t['preview']) ?></textarea>
            <?php if ($canEdit): ?>
            <label class="check"><input type="checkbox" name="active" value="1"<?= checked((bool) $t['active']) ?>> Ativo</label>
            <button class="btn btn-ghost btn-sm" type="submit">Salvar modelo</button>
            <?php endif; ?>
        </form>
    <?php endforeach; ?>
</section>

<section class="panel">
    <h2>Como conectar o WhatsApp (API oficial)</h2>
    <ol class="steps">
        <li>Crie uma conta no <strong>Meta for Developers</strong> e um app do tipo "Empresa" com o produto <strong>WhatsApp</strong>; vincule (ou crie) sua conta do <strong>WhatsApp Business</strong> e verifique o número que vai enviar as mensagens.</li>
        <li>Em "Configuração da API", copie o <strong>Phone number ID</strong> e gere um <strong>token permanente</strong> (usuário do sistema no Gerenciador de Negócios).</li>
        <li>No arquivo <code>.env</code>, preencha <code>WHATSAPP_TOKEN</code>, <code>WHATSAPP_PHONE_NUMBER_ID</code>, <code>WHATSAPP_APP_SECRET</code> (chave secreta do app) e invente um <code>WHATSAPP_VERIFY_TOKEN</code>.</li>
        <li>Em "Webhooks", informe a URL <code><?= e($webhookUrl) ?></code> (precisa ser <strong>HTTPS público</strong>) com o mesmo verify token, e assine os campos <strong>messages</strong>.
            <?= $verifyTokenSet ? '✔ verify token definido.' : '✘ verify token não definido.' ?> <?= $secretSet ? '✔ app secret definido.' : '✘ app secret não definido.' ?></li>
        <li>Cadastre os modelos acima no WhatsApp Manager e aguarde a aprovação.</li>
        <li>Agende <code>php bin/worker.php</code> a cada 5 minutos (Agendador de Tarefas do Windows ou cron).</li>
    </ol>
</section>
