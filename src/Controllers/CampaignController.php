<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\Response;
use App\Core\Session;
use App\Domain\CampaignService;
use App\Domain\ValidationException;
use DomainException;

final class CampaignController extends Controller
{
    public function index(): void
    {
        $svc = new CampaignService();
        $campaigns = Db::all(
            "SELECT c.*, t.label AS template_label,
                (SELECT COUNT(*) FROM messages m WHERE m.campaign_id = c.id AND m.status IN ('sent','delivered','read')) AS sent,
                (SELECT COUNT(*) FROM messages m WHERE m.campaign_id = c.id AND m.status = 'read') AS read_count,
                (SELECT COUNT(*) FROM bookings b WHERE b.origin = CONCAT('campanha-', c.id)) AS bookings
             FROM campaigns c JOIN message_templates t ON t.template_key = c.template_key ORDER BY c.id DESC"
        );
        foreach ($campaigns as &$c) {
            $c['audience_label'] = $svc->audienceLabel($c);
            $c['preview_count'] = $c['status'] === 'draft' ? count($svc->recipients($c['audience'], $c['audience_param'])) : null;
        }
        unset($c);
        $this->view('admin/campaigns/index', [
            'pageTitle' => 'Campanhas',
            'campaigns' => $campaigns,
            'templates' => Db::all("SELECT * FROM message_templates WHERE category = 'marketing' AND active = 1 ORDER BY label"),
            'optedIn' => (int) Db::value('SELECT COUNT(*) FROM clients WHERE marketing_opt_in = 1'),
            'totalClients' => (int) Db::value('SELECT COUNT(*) FROM clients'),
        ]);
    }

    public function store(): void
    {
        try {
            (new CampaignService())->create($_POST, $this->userId());
        } catch (ValidationException $e) {
            Response::back('/admin/campanhas', $e->errors, $_POST);
            return;
        }
        Session::flash('success', 'Campanha criada como rascunho. Confira o público e clique em "Enviar".');
        Response::redirect('/admin/campanhas');
    }

    public function queue(string $id): void
    {
        try {
            $n = (new CampaignService())->queue((int) $id);
            Session::flash('success', "Campanha enviada para a fila: $n mensagem(ns). O envio acontece no próximo processamento da fila.");
        } catch (DomainException $e) {
            Session::flash('error', $e->getMessage());
        }
        Response::redirect('/admin/campanhas');
    }

    public function destroy(string $id): void
    {
        Db::exec("DELETE FROM campaigns WHERE id = ? AND status = 'draft'", [(int) $id]);
        Session::flash('success', 'Rascunho removido.');
        Response::redirect('/admin/campanhas');
    }
}
