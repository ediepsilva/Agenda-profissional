<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Core\Response;
use App\Core\View;

abstract class Controller
{
    protected function view(string $template, array $data = [], string $layout = 'admin'): void
    {
        View::render($template, $data, $layout);
    }

    protected function input(string $key, string $default = ''): string
    {
        $v = $_POST[$key] ?? $_GET[$key] ?? $default;
        return is_string($v) ? trim($v) : $default;
    }

    protected function notFound(): void
    {
        Response::error(404, 'Não encontrado', 'O registro solicitado não existe.');
    }

    protected function userId(): ?int
    {
        return isset(Auth::user()['id']) ? (int) Auth::user()['id'] : null;
    }

    /** Profissionais ativas (para filtros e seletores). */
    protected function professionals(): array
    {
        return Db::all('SELECT id, name, color FROM professionals WHERE active = 1 ORDER BY id');
    }

    /**
     * Filtro SQL de escopo "somente as minhas": restringe reservas às que têm a profissional alocada.
     * $scope null = sem restrição.
     * @return array{0:string,1:array}
     */
    protected function bookingScopeSql(?int $scope, string $alias = 'b'): array
    {
        if ($scope === null) {
            return ['', []];
        }
        return [" AND EXISTS (SELECT 1 FROM booking_allocations sa WHERE sa.booking_id = $alias.id AND sa.professional_id = ?)", [$scope]];
    }

    /**
     * Profissional em foco: a da querystring, a do usuário logado ou a primeira ativa.
     * Quem só tem permissão ".own" fica sempre restrita à própria agenda.
     */
    protected function currentProfessionalId(?string $fullPermission = null): ?int
    {
        if ($fullPermission !== null && !Auth::can($fullPermission)) {
            $own = (int) (Auth::user()['professional_id'] ?? 0);
            return $own ?: null;
        }
        $ids = array_map(static fn ($p) => (int) $p['id'], $this->professionals());
        $wanted = (int) ($_GET['profissional'] ?? $_POST['professional_id'] ?? 0);
        if ($wanted && in_array($wanted, $ids, true)) {
            return $wanted;
        }
        $own = (int) (Auth::user()['professional_id'] ?? 0);
        if ($own && in_array($own, $ids, true)) {
            return $own;
        }
        return $ids[0] ?? null;
    }
}
