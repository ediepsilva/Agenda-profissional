<?php
use App\Domain\Permissions;
?>
<header class="page-header">
    <h1>Equipe</h1>
    <?php if ($canManage): ?><a class="btn btn-primary" href="<?= e(url('/admin/equipe/novo')) ?>">+ Novo membro</a><?php endif; ?>
</header>
<p class="muted">Cada membro pode <strong>atender clientes</strong> (tem agenda, disponibilidade, serviços e comissão) e/ou <strong>acessar o painel</strong> (tem login com um papel).</p>

<div class="table-wrap">
<table class="table">
    <thead><tr><th>Nome</th><th>Agenda</th><th>Acesso ao painel</th><th>Comissão</th><th>Situação</th><?php if ($canManage): ?><th></th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($members as $m):
        $active = $m['professional_id'] ? (bool) $m['pro_active'] : (bool) $m['user_active'];
        $editQ = $m['professional_id'] ? ['p' => $m['professional_id']] : ['u' => $m['user_id']]; ?>
        <tr<?= $active ? '' : ' class="inactive"' ?>>
            <td data-label="Nome"><?php if ($m['color']): ?><span class="dot" style="background: <?= e($m['color']) ?>"></span> <?php endif; ?><strong><?= e($m['name']) ?></strong></td>
            <td data-label="Agenda"><?= $m['professional_id'] ? 'Atende · ' . (int) $m['services_count'] . ' serviço(s)' . ($m['accepts_online_booking'] ? '' : ' · <span class="muted small">sem agendamento online</span>') : '<span class="muted">—</span>' ?></td>
            <td data-label="Acesso"><?= $m['user_id'] ? e(Permissions::roleLabel($m['role'])) . '<br><span class="muted small">' . e($m['email']) . ($m['user_active'] ? '' : ' · bloqueado') . '</span>' : '<span class="muted">Sem login</span>' ?></td>
            <td data-label="Comissão"><?= $m['professional_id'] ? e(number_format((float) $m['commission_percent'], 1, ',', '')) . '%' : '—' ?></td>
            <td data-label="Situação"><?= $active ? '<span class="badge badge-confirmed">Ativo</span>' : '<span class="badge badge-cancelled">Inativo</span>' ?></td>
            <?php if ($canManage): ?><td class="actions"><a class="btn btn-ghost btn-sm" href="<?= e(url('/admin/equipe/editar', $editQ)) ?>">Editar</a></td><?php endif; ?>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<section class="panel">
    <h2>Papéis</h2>
    <dl class="details">
        <?php foreach (Permissions::ROLE_DESCRIPTIONS as $role => $desc): ?>
            <div><dt><?= e(Permissions::roleLabel($role)) ?></dt><dd><?= e($desc) ?></dd></div>
        <?php endforeach; ?>
    </dl>
</section>
