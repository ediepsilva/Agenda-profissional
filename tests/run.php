<?php
declare(strict_types=1);

/*
 * Executor de testes (sem dependências).
 *   php tests/run.php            todos (unitários, integração e ponta a ponta)
 *   php tests/run.php unit       só uma pasta
 *   php tests/run.php Booking    filtra por nome de arquivo/classe
 *   php tests/run.php Booking Concurrent   filtra também pelo nome do método
 *
 * Usa um banco SEPARADO: DB_NAME + "_test" (recriado a cada execução).
 */

$mainDb = null;
foreach (file(dirname(__DIR__) . '/.env', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    if (str_starts_with(trim($line), 'DB_NAME=')) {
        $mainDb = trim(substr(trim($line), 8), " \"'");
    }
}
$testDb = (getenv('DB_NAME') ?: ($mainDb ?: 'agenda_profissional')) . '_test';
if (!str_ends_with($testDb, '_test')) {
    exit("Banco de teste precisa terminar em _test.\n");
}
putenv("DB_NAME=$testDb");
putenv('APP_ENV=testing');

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/TestCase.php';
require __DIR__ . '/Fixtures.php';

use App\Core\Migrator;

Migrator::ensureDatabase();
$migrator = new Migrator(BASE_PATH . '/database/migrations');
$migrator->dropAll();
$migrator->migrate();

$filter = $argv[1] ?? '';
$methodFilter = $argv[2] ?? '';
$files = array_merge(glob(__DIR__ . '/unit/*Test.php'), glob(__DIR__ . '/integration/*Test.php'), glob(__DIR__ . '/e2e/*Test.php'));
$passed = 0;
$failed = [];
$start = microtime(true);

foreach ($files as $file) {
    if ($filter !== '' && !str_contains($file, $filter)) {
        continue;
    }
    $before = get_declared_classes();
    require_once $file;
    $classes = array_filter(array_diff(get_declared_classes(), $before), static fn ($c) => is_subclass_of($c, Tests\TestCase::class));
    foreach ($classes as $class) {
        $group = basename(dirname($file));
        echo "\n[$group] " . $class . "\n";
        foreach (get_class_methods($class) as $method) {
            if (!str_starts_with($method, 'test') || ($methodFilter !== '' && !str_contains($method, $methodFilter))) {
                continue;
            }
            $t = new $class();
            try {
                $t->setUp();
                $t->$method();
                $passed++;
                echo "  ✔ $method\n";
            } catch (Throwable $e) {
                $failed[] = "$class::$method";
                echo "  ✘ $method\n      " . str_replace("\n", "\n      ", $e->getMessage()) . "\n      em " . basename($e->getFile()) . ':' . $e->getLine() . "\n";
            } finally {
                try {
                    $t->tearDown();
                } catch (Throwable) {
                }
            }
        }
    }
}

printf("\n%d passaram, %d falharam (%.1fs)\n", $passed, count($failed), microtime(true) - $start);
if ($failed) {
    echo "Falhas:\n  - " . implode("\n  - ", $failed) . "\n";
}
exit($failed ? 1 : 0);
