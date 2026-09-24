<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Core\Response;
use App\Core\Session;
use App\Domain\TeamService;
use App\Domain\ValidationException;

final class TeamController extends Controller
{
    public function index(): void
    {
        $this->view('admin/team/index', [
            'pageTitle' => 'Equipe',
            'members' => (new TeamService())->members(),
            'canManage' => Auth::can('team.manage'),
        ]);
    }

    public function create(): void
    {
        $this->form(null);
    }

    public function edit(): void
    {
        $member = (new TeamService())->find(
            ($_GET['p'] ?? '') !== '' ? (int) $_GET['p'] : null,
            ($_GET['u'] ?? '') !== '' ? (int) $_GET['u'] : null,
        );
        if (!$member) {
            $this->notFound();
            return;
        }
        $this->form($member);
    }

    public function store(): void
    {
        $this->save(null, '/admin/equipe/novo');
    }

    public function update(): void
    {
        $team = new TeamService();
        $p = $this->input('p');
        $u = $this->input('u');
        $member = $team->find($p !== '' ? (int) $p : null, $u !== '' ? (int) $u : null);
        if (!$member) {
            $this->notFound();
            return;
        }
        $back = '/admin/equipe/editar?' . ($member['pro'] ? 'p=' . $member['pro']['id'] : 'u=' . $member['user']['id']);
        $this->save($member, $back);
    }

    private function save(?array $member, string $back): void
    {
        try {
            $r = (new TeamService())->save($member, $_POST, (int) $this->userId());
        } catch (ValidationException $e) {
            Response::back($back, $e->errors, $_POST);
            return;
        }
        Session::flash('success', $member ? 'Dados atualizados.' : 'Membro cadastrado.');
        foreach ($r['warnings'] as $w) {
            Session::flash('warning', $w);
        }
        Response::redirect('/admin/equipe');
    }

    private function form(?array $member): void
    {
        $this->view('admin/team/form', [
            'pageTitle' => $member ? 'Editar membro' : 'Novo membro da equipe',
            'm' => $member,
            'services' => Db::all('SELECT id, name, active FROM services ORDER BY active DESC, sort_order, name'),
            'isSelf' => $member && $member['user'] && (int) $member['user']['id'] === (int) $this->userId(),
        ]);
    }
}
