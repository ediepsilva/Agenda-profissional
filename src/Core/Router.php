<?php
declare(strict_types=1);

namespace App\Core;

final class Router
{
    /** @var array<int,array{method:string,regex:string,handler:array,permission:?string}> */
    private array $routes = [];

    public function get(string $pattern, array $handler, ?string $permission = null): void
    {
        $this->add('GET', $pattern, $handler, $permission);
    }

    public function post(string $pattern, array $handler, ?string $permission = null): void
    {
        $this->add('POST', $pattern, $handler, $permission);
    }

    /**
     * $permission: null = público; 'auth' = qualquer usuário logado; outro = permissão específica.
     */
    private function add(string $method, string $pattern, array $handler, ?string $permission): void
    {
        $regex = preg_replace_callback(
            '#\{(\w+)\}#',
            static fn ($m) => '(?P<' . $m[1] . '>' . ($m[1] === 'id' ? '\d+' : '[^/]+') . ')',
            rtrim($pattern, '/') ?: '/'
        );
        $regex = '#^' . $regex . '$#';
        $this->routes[] = compact('method', 'regex', 'handler', 'permission');
    }

    public function dispatch(string $method, string $path): void
    {
        $path = rtrim($path, '/') ?: '/';
        $allowed = false;

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $m)) {
                continue;
            }
            $allowed = true;
            if ($route['method'] !== $method) {
                continue;
            }

            if ($method === 'POST' && !Csrf::valid($_POST['_csrf'] ?? null)) {
                Response::error(419, 'Sessão expirada', 'O formulário expirou. Volte, recarregue a página e tente novamente.');
                return;
            }

            if ($route['permission'] !== null) {
                if (!Auth::user()) {
                    if ($method === 'GET') {
                        Session::set('_intended', $_SERVER['REQUEST_URI'] ?? null);
                    }
                    Response::redirect('/admin/login');
                    return;
                }
                if ($route['permission'] !== 'auth' && !Auth::can($route['permission'])) {
                    Response::error(403, 'Acesso negado', 'Seu perfil não tem permissão para acessar esta área.');
                    return;
                }
            }

            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            [$class, $action] = $route['handler'];
            (new $class())->$action(...array_values($params));
            return;
        }

        if ($allowed) {
            Response::error(405, 'Método não permitido', 'Esta ação não é permitida.');
            return;
        }
        Response::error(404, 'Página não encontrada', 'O endereço acessado não existe.');
    }
}
