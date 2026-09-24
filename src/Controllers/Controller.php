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

    /** Profissional em foco: a da querystring, a do usuário logado ou a primeira ativa. */
    protected function currentProfessionalId(): ?int
    {
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
