<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\Response;
use App\Core\Session;

/** Áreas atendidas: definem deslocamento e taxa para atendimentos no local da cliente. */
final class AreaController extends Controller
{
    public function index(): void
    {
        $this->view('admin/areas/index', [
            'pageTitle' => 'Áreas atendidas',
            'areas' => Db::all('SELECT * FROM service_areas ORDER BY active DESC, sort_order, name'),
            'professionals' => $this->professionals(),
        ]);
    }

    public function store(): void
    {
        [$data, $errors] = $this->validated();
        if ($errors) {
            Response::back('/admin/areas', $errors, $_POST);
            return;
        }
        Db::insert('service_areas', $data);
        Session::flash('success', 'Área adicionada.');
        Response::redirect('/admin/areas');
    }

    public function update(string $id): void
    {
        [$data, $errors] = $this->validated();
        if ($errors) {
            Session::flash('error', implode(' ', $errors));
            Response::redirect('/admin/areas');
            return;
        }
        Db::update('service_areas', $data, 'id = ?', [(int) $id]);
        Session::flash('success', 'Área atualizada.');
        Response::redirect('/admin/areas');
    }

    public function destroy(string $id): void
    {
        $used = (int) Db::value('SELECT COUNT(*) FROM bookings WHERE service_area_id = ?', [(int) $id]);
        if ($used) {
            Db::update('service_areas', ['active' => 0], 'id = ?', [(int) $id]);
            Session::flash('info', 'A área tem reservas no histórico, por isso foi apenas desativada.');
        } else {
            Db::exec('DELETE FROM service_areas WHERE id = ?', [(int) $id]);
            Session::flash('success', 'Área excluída.');
        }
        Response::redirect('/admin/areas');
    }

    private function validated(): array
    {
        $errors = [];
        $name = $this->input('name');
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            $errors['name'] = 'Informe o nome da área/bairro/cidade.';
        }
        $travel = (int) $this->input('travel_minutes', '0');
        if ($travel < 0 || $travel > 480) {
            $errors['travel_minutes'] = 'Deslocamento entre 0 e 480 minutos.';
        }
        $fee = parse_money($this->input('travel_fee', '0') ?: '0');
        if ($fee === null) {
            $errors['travel_fee'] = 'Taxa inválida.';
        }
        $pro = (int) $this->input('professional_id', '0');
        if ($pro && !in_array($pro, array_map(static fn ($p) => (int) $p['id'], $this->professionals()), true)) {
            $errors['professional_id'] = 'Profissional inválida.';
        }
        return [[
            'name' => $name,
            'professional_id' => $pro ?: null,
            'travel_minutes' => $travel,
            'travel_fee_cents' => $fee ?? 0,
            'active' => !empty($_POST['active']) ? 1 : 0,
            'sort_order' => (int) $this->input('sort_order', '0'),
        ], $errors];
    }
}
