<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\Response;
use App\Core\Session;

final class ServiceController extends Controller
{
    public const LOCATION_MODES = [
        'both' => 'No estúdio ou no local da cliente',
        'studio' => 'Somente no estúdio',
        'client' => 'Somente no local da cliente',
    ];

    public function index(): void
    {
        $this->view('admin/services/index', [
            'pageTitle' => 'Serviços',
            'services' => Db::all(
                'SELECT s.*, (SELECT COUNT(*) FROM bookings b WHERE b.service_id = s.id) AS bookings_count
                 FROM services s ORDER BY s.active DESC, s.sort_order, s.name'
            ),
        ]);
    }

    public function create(): void
    {
        $this->form(null);
    }

    public function edit(string $id): void
    {
        $s = Db::one('SELECT * FROM services WHERE id = ?', [(int) $id]);
        if (!$s) {
            $this->notFound();
            return;
        }
        $this->form($s);
    }

    public function store(): void
    {
        [$data, $pros, $errors] = $this->validated();
        if ($errors) {
            Response::back('/admin/servicos/novo', $errors, $_POST);
            return;
        }
        Db::transaction(function () use ($data, $pros) {
            $id = Db::insert('services', $data);
            $this->syncProfessionals($id, $pros);
        });
        Session::flash('success', 'Serviço "' . $data['name'] . '" criado.');
        Response::redirect('/admin/servicos');
    }

    public function update(string $id): void
    {
        $id = (int) $id;
        if (!Db::one('SELECT id FROM services WHERE id = ?', [$id])) {
            $this->notFound();
            return;
        }
        [$data, $pros, $errors] = $this->validated();
        if ($errors) {
            Response::back("/admin/servicos/$id/editar", $errors, $_POST);
            return;
        }
        Db::transaction(function () use ($id, $data, $pros) {
            Db::update('services', $data, 'id = ?', [$id]);
            $this->syncProfessionals($id, $pros);
        });
        Session::flash('success', 'Serviço atualizado.');
        Response::redirect('/admin/servicos');
    }

    public function destroy(string $id): void
    {
        $id = (int) $id;
        $used = (int) Db::value('SELECT COUNT(*) FROM bookings WHERE service_id = ?', [$id]);
        if ($used > 0) {
            Db::update('services', ['active' => 0], 'id = ?', [$id]);
            Session::flash('info', 'O serviço tem reservas no histórico, por isso foi apenas desativado.');
        } else {
            Db::exec('DELETE FROM services WHERE id = ?', [$id]);
            Session::flash('success', 'Serviço excluído.');
        }
        Response::redirect('/admin/servicos');
    }

    private function form(?array $service): void
    {
        $selected = $service
            ? array_map('intval', array_column(Db::all('SELECT professional_id FROM professional_services WHERE service_id = ?', [$service['id']]), 'professional_id'))
            : array_map(static fn ($p) => (int) $p['id'], $this->professionals());
        $this->view('admin/services/form', [
            'pageTitle' => $service ? 'Editar serviço' : 'Novo serviço',
            's' => $service,
            'professionals' => $this->professionals(),
            'selectedPros' => $selected,
        ]);
    }

    /** @return array{0:array,1:int[],2:array} */
    private function validated(): array
    {
        $errors = [];
        $name = $this->input('name');
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            $errors['name'] = 'Informe o nome (2 a 120 caracteres).';
        }
        $duration = (int) $this->input('duration_minutes');
        if ($duration < 15 || $duration > 720) {
            $errors['duration_minutes'] = 'A duração deve ficar entre 15 e 720 minutos.';
        }
        $price = parse_money($this->input('price'));
        if ($price === null) {
            $errors['price'] = 'Informe o preço inicial (ex.: 250,00).';
        }
        $depositRaw = $this->input('deposit');
        $deposit = $depositRaw === '' ? null : parse_money($depositRaw);
        if ($depositRaw !== '' && $deposit === null) {
            $errors['deposit'] = 'Valor de sinal inválido.';
        }
        $mode = $this->input('location_mode', 'both');
        if (!isset(self::LOCATION_MODES[$mode])) {
            $errors['location_mode'] = 'Opção inválida.';
        }
        $valid = array_map(static fn ($p) => (int) $p['id'], $this->professionals());
        $pros = array_values(array_intersect(array_map('intval', (array) ($_POST['professionals'] ?? [])), $valid));
        if (!$pros) {
            $errors['professionals'] = 'Selecione ao menos uma profissional que realiza o serviço.';
        }
        $description = $this->input('description');
        $category = $this->input('category');

        return [[
            'name' => $name,
            'description' => $description !== '' ? mb_substr($description, 0, 2000) : null,
            'category' => $category !== '' ? mb_substr($category, 0, 80) : null,
            'duration_minutes' => $duration,
            'price_cents' => $price ?? 0,
            'deposit_cents' => $deposit,
            'location_mode' => $mode,
            'active' => !empty($_POST['active']) ? 1 : 0,
            'sort_order' => (int) $this->input('sort_order', '0'),
        ], $pros, $errors];
    }

    private function syncProfessionals(int $serviceId, array $pros): void
    {
        Db::exec('DELETE FROM professional_services WHERE service_id = ?', [$serviceId]);
        foreach ($pros as $p) {
            Db::insert('professional_services', ['professional_id' => $p, 'service_id' => $serviceId]);
        }
    }
}
