<?php
declare(strict_types=1);

/*
 * Inicialização comum (web, CLI e testes): autoload, variáveis de ambiente,
 * fuso horário e helpers.
 */

define('BASE_PATH', __DIR__);

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $file = BASE_PATH . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

App\Core\Env::load(BASE_PATH . '/.env');

date_default_timezone_set(App\Core\Env::get('APP_TIMEZONE', 'America/Sao_Paulo'));
mb_internal_encoding('UTF-8');

require BASE_PATH . '/src/helpers.php';
