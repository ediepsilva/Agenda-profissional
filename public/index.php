<?php
declare(strict_types=1);

// Servidor embutido do PHP: entrega arquivos estáticos diretamente.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (is_file($file) && !str_ends_with($file, '.php')) {
        return false;
    }
}

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Env;
use App\Core\Response;
use App\Core\Session;

set_exception_handler(static function (Throwable $e): void {
    $line = sprintf("[%s] %s: %s em %s:%d\n%s\n", date('c'), $e::class, $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
    @file_put_contents(BASE_PATH . '/storage/logs/app.log', $line, FILE_APPEND);
    if (!headers_sent()) {
        http_response_code(500);
    }
    $msg = Env::get('APP_DEBUG') === '1' ? $e->getMessage() : 'Ocorreu um erro inesperado. Tente novamente em instantes.';
    try {
        Response::error(500, 'Erro interno', $msg);
    } catch (Throwable) {
        echo 'Erro interno.';
    }
});

Session::start();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");

$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
$base = base_path_url();
if ($base !== '' && str_starts_with($path, $base)) {
    $path = substr($path, strlen($base));
}

/** @var App\Core\Router $router */
$router = require BASE_PATH . '/routes.php';
$router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $path === '' ? '/' : $path);
