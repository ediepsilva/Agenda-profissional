<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\Env;
use App\Core\Response;
use App\Core\Session;
use App\Domain\MessageService;
use App\Integrations\Integrations;

final class MessageController extends Controller
{
    private const PER_PAGE = 50;

    public function index(): void
    {
        $status = (string) ($_GET['status'] ?? '');
        $category = (string) ($_GET['tipo'] ?? '');
        $where = ['1=1'];
        $params = [];
        if (isset(MessageService::STATUS_LABELS[$status])) {
            $where[] = 'm.status = ?';
            $params[] = $status;
        }
        if (in_array($category, ['transactional', 'marketing'], true)) {
            $where[] = 'm.category = ?';
            $params[] = $category;
        }
        $page = max(1, (int) ($_GET['pagina'] ?? 1));
        $w = implode(' AND ', $where);
        $total = (int) Db::value("SELECT COUNT(*) FROM messages m WHERE $w", $params);
        $rows = Db::all(
            "SELECT m.*, c.name AS client_name, t.label AS template_label FROM messages m
             JOIN clients c ON c.id = m.client_id JOIN message_templates t ON t.template_key = m.template_key
             WHERE $w ORDER BY m.id DESC LIMIT " . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE),
            $params
        );
        $counts = [];
        foreach (Db::all('SELECT status, COUNT(*) AS n FROM messages GROUP BY status') as $r) {
            $counts[$r['status']] = (int) $r['n'];
        }
        $this->view('admin/messages/index', [
            'pageTitle' => 'Mensagens',
            'rows' => $rows,
            'counts' => $counts,
            'status' => $status,
            'category' => $category,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / self::PER_PAGE)),
            'templates' => Db::all('SELECT * FROM message_templates ORDER BY category DESC, label'),
            'configured' => Integrations::whatsappConfigured(),
            'webhookUrl' => absolute_url('/webhooks/whatsapp'),
            'verifyTokenSet' => Env::get('WHATSAPP_VERIFY_TOKEN', '') !== '',
            'secretSet' => Env::get('WHATSAPP_APP_SECRET', '') !== '',
            'enabled' => MessageService::enabled(),
        ]);
    }

    /** Roda o agendador e envia a fila agora (o mesmo que o worker faz periodicamente). */
    public function process(): void
    {
        $svc = new MessageService();
        $created = $svc->scheduleDue();
        $r = $svc->dispatchDue(100);
        Session::flash('success', sprintf('Fila processada: %d lembrete(s) criado(s), %d enviada(s), %d falha(s), %d não enviada(s), %d para nova tentativa.', $created, $r['sent'], $r['failed'], $r['skipped'], $r['retry']));
        Response::redirect('/admin/mensagens');
    }

    public function updateTemplate(string $key): void
    {
        $tpl = Db::one('SELECT * FROM message_templates WHERE template_key = ?', [$key]);
        if (!$tpl) {
            $this->notFound();
            return;
        }
        $name = $this->input('name');
        $lang = $this->input('language', 'pt_BR');
        $order = preg_replace('/\s+/', '', $this->input('param_order'));
        $preview = mb_substr($this->input('preview'), 0, 1024);
        $offset = (int) $this->input('offset_hours', '0');
        if (!preg_match('/^[a-z0-9_]{1,100}$/', $name) || !preg_match('/^[a-z]{2}(_[A-Z]{2})?$/', $lang)
            || !preg_match('/^([a-z_]+(,[a-z_]+)*)?$/', $order) || mb_strlen($preview) < 5 || $offset < 0 || $offset > 720) {
            Session::flash('error', 'Dados inválidos. O nome do modelo usa só letras minúsculas, números e "_", igual ao cadastrado na Meta.');
            Response::redirect('/admin/mensagens#modelos');
            return;
        }
        Db::update('message_templates', [
            'name' => $name, 'language' => $lang, 'param_order' => $order, 'preview' => $preview,
            'offset_hours' => $offset, 'active' => !empty($_POST['active']) ? 1 : 0,
        ], 'template_key = ?', [$key]);
        Session::flash('success', 'Modelo "' . $tpl['label'] . '" atualizado.');
        Response::redirect('/admin/mensagens#modelos');
    }

    public function cancel(string $id): void
    {
        Db::exec("UPDATE messages SET status = 'cancelled', last_error = 'Cancelada no painel' WHERE id = ? AND status = 'queued'", [(int) $id]);
        Session::flash('success', 'Mensagem cancelada.');
        Response::redirect('/admin/mensagens');
    }

    public function retry(string $id): void
    {
        Db::exec("UPDATE messages SET status = 'queued', attempts = 0, scheduled_at = NOW(), last_error = NULL WHERE id = ? AND status = 'failed'", [(int) $id]);
        Session::flash('success', 'Mensagem colocada de volta na fila.');
        Response::redirect('/admin/mensagens');
    }
}
