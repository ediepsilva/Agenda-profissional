<?php
declare(strict_types=1);

/*
 * Uso:
 *   php bin/migrate.php          aplica migrações pendentes (cria o banco se não existir)
 *   php bin/migrate.php --fresh  APAGA todas as tabelas e recria (bloqueado em produção)
 */

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Env;
use App\Core\Migrator;

if (PHP_SAPI !== 'cli') {
    exit('Somente via linha de comando.');
}

Migrator::ensureDatabase();
$m = new Migrator(BASE_PATH . '/database/migrations');

if (in_array('--fresh', $argv, true)) {
    $m->dropAll();
    echo "Tabelas removidas do banco '" . Env::get('DB_NAME', 'agenda_profissional') . "'.\n";
}

$ran = $m->migrate();
echo $ran ? 'Migrações aplicadas: ' . implode(', ', $ran) . "\n" : "Nenhuma migração pendente.\n";
