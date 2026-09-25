<?php
use App\Core\Auth;
use App\Domain\BookingService;
use App\Domain\FinanceService;

$actionLabels = [
    'awaiting_deposit' => ['Aguardar sinal', 'btn-ghost'],
    'confirmed' => ['Confirmar', 'btn-primary'],
    'completed' => ['Marcar como concluída', 'btn-primary'],
    'no_show' => ['Não compareceu', 'btn-ghost'],
    'cancelled' => ['Cancelar reserva', 'btn-danger'],
];
$isEvent = $b['kind'] === 'event';
$open = in_array($b['status'], BookingService::OPEN, true);
$what = $isEvent ? $b['event_name'] : $b['service_name'];
$msg = 'Olá, ' . strtok($b['client_name'], ' ') . '! Sobre sua reserva de ' . $what . ' em ' . datetime_br($b['starts_at']) . ': ' . $publicLink;
$showMoney = $canManage || $finance !== null;
$today = date('Y-m-d');
$id = (int) $b['id'];
?>
<header class="page-header">
    <div>
        <p class="eyebrow"><?= $isEvent ? 'Evento' : 'Reserva' ?> #<?= $id ?> · <?= $b['channel'] === 'public' ? 'pela página pública' : 'lançada no painel' ?></p>
        <h1><?= $isEvent ? e($b['event_name']) : e($b['client_name']) . ' — ' . e($b['service_name']) ?></h1>
        <p><?= status_badge($b['status']) ?><?php if ($isEvent): ?> <span class="badge badge-event"><?= (int) $b['people_count'] ?> pessoa(s) · <?= count($allocations) ?> profissional(is)</span><?php endif; ?></p>
    </div>
    <a class="btn btn-ghost" href="<?= e(url('/admin/reservas')) ?>">Voltar</a>
</header>

<div class="grid-2 gap-lg">
    <div>
    <section class="panel">
        <h2>Detalhes</h2>
        <dl class="details">
            <div><dt>Data</dt><dd><?= e(date_long_br($b['starts_at'])) ?></dd></div>
            <div><dt>Horário</dt><dd><?= e(time_br($b['starts_at'])) ?> às <?= e(time_br($b['ends_at'])) ?><?php if ((int) $b['travel_minutes']): ?><br><span class="muted small">Agenda ocupada desde <?= e(time_br((new DateTimeImmutable($b['starts_at']))->modify('-' . (int) $b['travel_minutes'] . ' minutes'))) ?> (deslocamento de <?= (int) $b['travel_minutes'] ?> min por trecho)</span><?php endif; ?></dd></div>
            <?php if ($isEvent): ?><div><dt>Serviço principal</dt><dd><?= e($b['service_name']) ?></dd></div><?php endif; ?>
            <div><dt>Local</dt><dd><?= $b['location_type'] === 'client' ? e($b['address']) . ($b['area_name'] ? '<br><span class="muted small">' . e($b['area_name']) . '</span>' : '') : 'Estúdio' ?></dd></div>
            <div><dt>Cliente</dt><dd><?php if (Auth::canAny(['clients.view', 'clients.view.own'])): ?><a href="<?= e(url('/admin/clientes/' . $b['client_id'])) ?>"><?= e($b['client_name']) ?></a><?php else: ?><?= e($b['client_name']) ?><?php endif; ?><br><?= e(phone_br($b['client_phone'])) ?><?= $b['client_email'] ? '<br>' . e($b['client_email']) : '' ?></dd></div>
            <?php if ($b['client_preferences']): ?><div><dt>Preferências</dt><dd><?= nl2br(e($b['client_preferences'])) ?></dd></div><?php endif; ?>
            <?php if ($showMoney): ?>
                <div><dt>Valor</dt><dd><?= e(money((int) $b['price_cents'])) ?><?= (int) $b['travel_fee_cents'] ? ' <span class="muted small">(inclui deslocamento ' . e(money((int) $b['travel_fee_cents'])) . ')</span>' : '' ?></dd></div>
                <div><dt>Sinal previsto</dt><dd><?= e(money((int) $b['deposit_cents'])) ?></dd></div>
            <?php endif; ?>
            <?php if ($ownCommission): ?>
                <div><dt>Minha comissão</dt><dd><?= e(money($ownCommission['cents'])) ?> <span class="muted small">(<?= e(number_format($ownCommission['percent'], 1, ',', '')) ?>% sobre <?= e(number_format($ownCommission['share'], 1, ',', '')) ?>% do valor)</span></dd></div>
            <?php endif; ?>
            <?php if ($b['client_notes']): ?><div><dt>Observações da cliente</dt><dd><?= nl2br(e($b['client_notes'])) ?></dd></div><?php endif; ?>
            <?php if ($b['cancel_reason']): ?><div><dt>Motivo do cancelamento</dt><dd><?= e($b['cancel_reason']) ?></dd></div><?php endif; ?>
        </dl>
        <p>
            <a class="btn btn-ghost btn-sm" target="_blank" rel="noopener" href="<?= e(whatsapp_link($b['client_phone'], $msg)) ?>">Abrir conversa no WhatsApp</a>
            <button type="button" class="btn btn-ghost btn-sm" data-copy-text="<?= e($publicLink) ?>">Copiar link da cliente</button>
        </p>
    </section>

    <section class="panel">
        <h2>Equipe do atendimento</h2>
        <ul class="team-list">
            <?php foreach ($allocations as $a): ?>
                <li>
                    <span><span class="dot" style="background: <?= e($a['color']) ?>"></span> <strong><?= e($a['name']) ?></strong> <span class="muted small"><?= $a['role'] === 'lead' ? 'responsável' : 'apoio' ?></span></span>
                    <?php if ($canManage && $open): ?>
                    <span class="team-actions">
                        <?php if (count($allocations) > 1): ?>
                        <form method="post" action="<?= e(url("/admin/reservas/$id/equipe/remover")) ?>" data-confirm="Remover <?= e($a['name']) ?> deste atendimento?">
                            <?= csrf_field() ?><input type="hidden" name="professional_id" value="<?= (int) $a['professional_id'] ?>">
                            <button class="btn btn-ghost btn-sm" type="submit">Remover</button>
                        </form>
                        <?php endif; ?>
                    </span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>

        <?php if ($canManage && $open && $suggestions): ?>
            <h3>Profissionais para este horário</h3>
            <p class="muted small">Ordenadas por disponibilidade e menor carga no dia.</p>
            <ul class="suggest-list">
                <?php foreach ($suggestions as $s): ?>
                <li class="<?= $s['free'] ? '' : 'busy' ?>">
                    <span><span class="dot" style="background: <?= e($s['color']) ?>"></span> <?= e($s['name']) ?>
                        <span class="muted small">· <?= $s['free'] ? 'livre' : 'ocupada/fora do expediente' ?> · <?= $s['day_load'] ?> no dia<?= $s['offers_service'] ? '' : ' · não faz este serviço' ?></span></span>
                    <span class="team-actions">
                        <form method="post" action="<?= e(url("/admin/reservas/$id/equipe/trocar")) ?>" data-confirm="Trocar <?= e($allocations[0]['name']) ?> por <?= e($s['name']) ?>?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="from" value="<?= (int) $allocations[0]['professional_id'] ?>">
                            <input type="hidden" name="to" value="<?= (int) $s['id'] ?>">
                            <?php if (!$s['free']): ?><input type="hidden" name="allow_outside_hours" value="1"><?php endif; ?>
                            <button class="btn btn-ghost btn-sm" type="submit"<?= $s['free'] ? '' : ' title="Só funciona se o problema for o expediente; conflitos continuam bloqueados"' ?>>Trocar responsável</button>
                        </form>
                        <form method="post" action="<?= e(url("/admin/reservas/$id/equipe/adicionar")) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="professional_id" value="<?= (int) $s['id'] ?>">
                            <?php if (!$s['free']): ?><input type="hidden" name="allow_outside_hours" value="1"><?php endif; ?>
                            <button class="btn btn-ghost btn-sm" type="submit">+ Incluir</button>
                        </form>
                    </span>
                </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <?php if ($finance): $f = $finance; ?>
    <section class="panel">
        <h2>Financeiro</h2>
        <div class="fin-grid">
            <div><span class="muted small">Valor</span><strong><?= e(money($f['price'])) ?></strong></div>
            <div><span class="muted small">Recebido</span><strong><?= e(money($f['paid'])) ?></strong></div>
            <div><span class="muted small">Saldo a receber</span><strong class="<?= $f['balance'] > 0 ? 'text-warn' : 'text-ok' ?>"><?= e(money($f['balance'])) ?></strong></div>
            <div><span class="muted small">Sinal</span><strong><?= e(money($f['deposit_paid'])) ?> / <?= e(money($f['deposit'])) ?></strong></div>
            <div><span class="muted small">Comissões</span><strong><?= e(money($f['commissions'])) ?></strong></div>
            <div><span class="muted small">Despesas</span><strong><?= e(money($f['expenses_total'])) ?></strong></div>
            <div class="fin-result"><span class="muted small">Resultado do atendimento</span><strong class="<?= $f['result'] >= 0 ? 'text-ok' : 'text-danger' ?>"><?= e(money($f['result'])) ?></strong></div>
        </div>

        <details>
            <summary>Alterar valor / sinal</summary>
            <form method="post" action="<?= e(url("/admin/reservas/$id/valores")) ?>" class="form">
                <?= csrf_field() ?>
                <div class="grid-2">
                    <div><label for="v-price">Valor total (R$)</label><input type="text" id="v-price" name="price" inputmode="decimal" value="<?= e(money_input($f['price'])) ?>" required></div>
                    <div><label for="v-dep">Sinal (R$)</label><input type="text" id="v-dep" name="deposit" inputmode="decimal" value="<?= e(money_input($f['deposit'])) ?>"></div>
                </div>
                <button class="btn btn-ghost btn-sm" type="submit">Salvar valores</button>
            </form>
        </details>

        <h3>Divisão e comissões</h3>
        <p class="muted small">Base da comissão: <?= e(money($f['base'])) ?> (valor sem a taxa de deslocamento).</p>
        <form method="post" action="<?= e(url("/admin/reservas/$id/equipe/divisao")) ?>">
            <?= csrf_field() ?>
            <div class="table-wrap">
            <table class="table table-compact">
                <thead><tr><th>Profissional</th><th>Divisão %</th><th>Comissão %</th><th>Comissão</th></tr></thead>
                <tbody>
                <?php foreach ($f['allocations'] as $a): $pid = (int) $a['professional_id']; ?>
                    <tr>
                        <td data-label="Profissional"><span class="dot" style="background: <?= e($a['color']) ?>"></span> <?= e($a['name']) ?></td>
                        <td data-label="Divisão %"><input type="text" inputmode="decimal" class="input-pct" name="terms[<?= $pid ?>][share]" value="<?= e(rtrim(rtrim(number_format((float) $a['share_percent'], 2, ',', ''), '0'), ',')) ?>" aria-label="Divisão de <?= e($a['name']) ?>"></td>
                        <td data-label="Comissão %"><input type="text" inputmode="decimal" class="input-pct" name="terms[<?= $pid ?>][commission]" value="<?= e(rtrim(rtrim(number_format((float) $a['commission_percent'], 2, ',', ''), '0'), ',')) ?>" aria-label="Comissão de <?= e($a['name']) ?>"></td>
                        <td data-label="Comissão"><?= e(money($a['commission_cents'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <button class="btn btn-ghost btn-sm" type="submit">Salvar divisão</button>
        </form>

        <h3>Pagamento online</h3>
        <?php if ($paymentLink): ?>
            <div class="copy-row">
                <input type="text" readonly id="pay-link" value="<?= e($paymentLink) ?>" aria-label="Link de pagamento">
                <button type="button" class="btn btn-ghost btn-sm" data-copy="#pay-link">Copiar</button>
            </div>
            <p><a class="btn btn-ghost btn-sm" target="_blank" rel="noopener" href="<?= e(whatsapp_link($b['client_phone'], 'Olá, ' . strtok($b['client_name'], ' ') . '! Segue o link para pagamento: ' . $paymentLink)) ?>">Enviar pelo WhatsApp</a></p>
        <?php elseif ($onlinePayments): ?>
            <form method="post" action="<?= e(url("/admin/reservas/$id/link-pagamento")) ?>"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit">Gerar link de pagamento (Pix/cartão)</button></form>
        <?php else: ?>
            <p class="muted small">Pagamento online desativado ou não configurado (Mercado Pago). A cliente vê a chave Pix manual, se cadastrada em Configurações.</p>
        <?php endif; ?>
        <?php if ($intents): ?>
            <ul class="money-list">
                <?php foreach ($intents as $pi): ?><li><span><?= e(datetime_br($pi['created_at'])) ?> · <?= e(money((int) $pi['amount_cents'])) ?> · <?= e(['pending' => 'aguardando', 'approved' => 'aprovado', 'rejected' => 'recusado', 'cancelled' => 'cancelado'][$pi['status']]) ?></span></li><?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <h3>Pagamentos</h3>
        <?php if ($f['payments']): ?>
        <ul class="money-list">
            <?php foreach ($f['payments'] as $p): ?>
                <li>
                    <span><?= e(date_br($p['paid_on'])) ?> · <strong><?= e(money((int) $p['amount_cents'])) ?></strong> · <?= e(FinanceService::PAYMENT_KINDS[$p['kind']]) ?> · <?= e(FinanceService::METHODS[$p['method']]) ?><?= $p['note'] ? ' · ' . e($p['note']) : '' ?></span>
                    <form method="post" action="<?= e(url('/admin/pagamentos/' . $p['id'] . '/excluir')) ?>" data-confirm="Remover este pagamento?"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit">Remover</button></form>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php else: ?><p class="muted small">Nenhum pagamento registrado.</p><?php endif; ?>
        <form method="post" action="<?= e(url("/admin/reservas/$id/pagamentos")) ?>" class="form inline-form">
            <?= csrf_field() ?>
            <div class="grid-4">
                <div><label for="p-amount">Valor (R$)</label><input type="text" id="p-amount" name="amount" inputmode="decimal" required value="<?= e(money_input(!$f['deposit_ok'] ? $f['deposit'] - $f['deposit_paid'] : $f['balance'])) ?>"></div>
                <div><label for="p-kind">Tipo</label><select id="p-kind" name="kind"><?php foreach (FinanceService::PAYMENT_KINDS as $k => $l): ?><option value="<?= $k ?>"<?= selected(!$f['deposit_ok'] ? 'deposit' : 'balance', $k) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
                <div><label for="p-method">Forma</label><select id="p-method" name="method"><?php foreach (FinanceService::METHODS as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
                <div><label for="p-date">Data</label><input type="date" id="p-date" name="paid_on" value="<?= e($today) ?>" required></div>
            </div>
            <label for="p-note">Observação</label><input type="text" id="p-note" name="note" maxlength="190">
            <?php if ($b['status'] === 'awaiting_deposit'): ?><label class="check"><input type="checkbox" name="auto_confirm" value="1" checked> Confirmar a reserva quando o sinal estiver pago</label><?php endif; ?>
            <button class="btn btn-primary btn-sm" type="submit">Registrar pagamento</button>
        </form>

        <h3>Despesas do atendimento</h3>
        <?php if ($f['expenses']): ?>
        <ul class="money-list">
            <?php foreach ($f['expenses'] as $ex): ?>
                <li>
                    <span><?= e(date_br($ex['spent_on'])) ?> · <strong><?= e(money((int) $ex['amount_cents'])) ?></strong> · <?= e(FinanceService::EXPENSE_CATEGORIES[$ex['category']] ?? $ex['category']) ?> · <?= e($ex['description']) ?></span>
                    <form method="post" action="<?= e(url('/admin/despesas/' . $ex['id'] . '/excluir')) ?>" data-confirm="Remover esta despesa?"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit">Remover</button></form>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
        <details>
            <summary>Adicionar despesa (material, transporte, assistente…)</summary>
            <form method="post" action="<?= e(url("/admin/reservas/$id/despesas")) ?>" class="form">
                <?= csrf_field() ?>
                <div class="grid-3">
                    <div><label for="e-amount">Valor (R$)</label><input type="text" id="e-amount" name="amount" inputmode="decimal" required></div>
                    <div><label for="e-cat">Categoria</label><select id="e-cat" name="category"><?php foreach (FinanceService::EXPENSE_CATEGORIES as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
                    <div><label for="e-date">Data</label><input type="date" id="e-date" name="spent_on" value="<?= e($today) ?>" required></div>
                </div>
                <label for="e-desc">Descrição</label><input type="text" id="e-desc" name="description" maxlength="190" required>
                <button class="btn btn-ghost btn-sm" type="submit">Adicionar despesa</button>
            </form>
        </details>
    </section>
    <?php endif; ?>
    </div>

    <div>
        <?php if ($transitions): ?>
        <section class="panel">
            <h2>Ações</h2>
            <?php foreach ($transitions as $to): [$label, $cls] = $actionLabels[$to]; ?>
                <form method="post" action="<?= e(url("/admin/reservas/$id/status")) ?>" class="inline-action"<?= $to === 'cancelled' ? ' data-confirm="Cancelar esta reserva? O horário será liberado."' : '' ?>>
                    <?= csrf_field() ?>
                    <input type="hidden" name="to" value="<?= e($to) ?>">
                    <?php if ($to === 'cancelled'): ?>
                        <label class="sr-only" for="note-cancel">Motivo</label>
                        <input type="text" name="note" id="note-cancel" placeholder="Motivo (opcional)" maxlength="255">
                    <?php endif; ?>
                    <button type="submit" class="btn <?= $cls ?>"><?= e($label) ?></button>
                </form>
            <?php endforeach; ?>
        </section>
        <?php endif; ?>

        <?php if ($canManage && $open): ?>
        <section class="panel">
            <h2>Reagendar</h2>
            <form method="post" action="<?= e(url("/admin/reservas/$id/reagendar")) ?>" class="form">
                <?= csrf_field() ?>
                <div class="grid-2">
                    <div><label for="r-date">Nova data</label><input type="date" id="r-date" name="date" required value="<?= e(substr($b['starts_at'], 0, 10)) ?>"></div>
                    <div><label for="r-time">Novo horário</label><input type="time" id="r-time" name="time" required value="<?= e(time_br($b['starts_at'])) ?>"></div>
                </div>
                <label class="check"><input type="checkbox" name="allow_outside_hours" value="1"> Permitir fora do horário de atendimento</label>
                <button type="submit" class="btn btn-ghost">Reagendar<?= count($allocations) > 1 ? ' (toda a equipe)' : '' ?></button>
            </form>
        </section>
        <?php endif; ?>

        <section class="panel">
            <h2>Observações internas</h2>
            <?php if ($canManage): ?>
            <form method="post" action="<?= e(url("/admin/reservas/$id/notas")) ?>">
                <?= csrf_field() ?>
                <label class="sr-only" for="internal_notes">Observações internas</label>
                <textarea name="internal_notes" id="internal_notes" rows="3" maxlength="2000" placeholder="Visível apenas para a equipe"><?= e($b['internal_notes']) ?></textarea>
                <button type="submit" class="btn btn-ghost btn-sm">Salvar</button>
            </form>
            <?php else: ?><p><?= nl2br(e($b['internal_notes'] ?: '—')) ?></p><?php endif; ?>
        </section>

        <?php if ($messages): ?>
        <section class="panel">
            <h2>Mensagens</h2>
            <ul class="money-list">
                <?php foreach ($messages as $m): ?>
                    <li><span><strong><?= e($m['label']) ?></strong> · <?= e(App\Domain\MessageService::STATUS_LABELS[$m['status']]) ?><?= $m['provider'] === 'simulado' ? ' (simulado)' : '' ?> · <span class="muted small"><?= e(datetime_br($m['sent_at'] ?: $m['scheduled_at'])) ?></span></span></li>
                <?php endforeach; ?>
            </ul>
            <p class="small"><a href="<?= e(url('/admin/mensagens')) ?>">Ver fila de mensagens →</a></p>
        </section>
        <?php endif; ?>

        <section class="panel">
            <h2>Histórico</h2>
            <ol class="timeline">
                <?php foreach ($history as $h): ?>
                    <li><span class="muted small"><?= e(datetime_br($h['created_at'])) ?></span>
                        <?= $h['from_status'] && $h['from_status'] !== $h['to_status'] ? e(BookingService::label($h['from_status'])) . ' → ' : '' ?><strong><?= e(BookingService::label($h['to_status'])) ?></strong>
                        <?= $h['note'] ? '· ' . e($h['note']) : '' ?>
                        <?= $h['user_name'] ? '<span class="muted small">(' . e($h['user_name']) . ')</span>' : '' ?>
                    </li>
                <?php endforeach; ?>
            </ol>
        </section>
    </div>
</div>
