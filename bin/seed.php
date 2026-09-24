<?php
declare(strict_types=1);

/*
 * Popula o banco com dados de exemplo (somente se ainda não houver usuárias).
 * Uso: php bin/seed.php            dona + serviços, áreas, clientes e reservas de exemplo
 *      php bin/seed.php --minimo   apenas a dona e a disponibilidade padrão
 */

require dirname(__DIR__) . '/bootstrap.php';
require BASE_PATH . '/database/seeds/DevSeeder.php';

use App\Core\Db;

if (PHP_SAPI !== 'cli') {
    exit('Somente via linha de comando.');
}

if ((int) Db::value('SELECT COUNT(*) FROM users') > 0) {
    echo "O banco já tem usuárias; nada foi feito. Use 'php bin/migrate.php --fresh' para recomeçar do zero.\n";
    exit(0);
}

$r = Db::transaction(static fn () => DevSeeder::run(!in_array('--minimo', $argv, true)));

echo "Dados criados.\n";
echo "Login: {$r['email']}\n";
echo $r['generated']
    ? "Senha gerada (anote agora, não será exibida de novo): {$r['password']}\n"
    : "Senha: a definida em ADMIN_PASSWORD no arquivo .env\n";
