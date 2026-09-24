<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Executa os arquivos database/migrations/*.sql em ordem, registrando os já aplicados.
 * Convenção: cada comando termina com ";" no fim da linha.
 */
final class Migrator
{
    public function __construct(private string $dir)
    {
    }

    public static function ensureDatabase(): void
    {
        $name = Env::get('DB_NAME', 'agenda_profissional');
        if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            throw new RuntimeException('DB_NAME inválido.');
        }
        Db::connect(null)->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }

    public function dropAll(): void
    {
        if (Env::isProduction()) {
            throw new RuntimeException('Operação bloqueada em produção.');
        }
        $pdo = Db::pdo();
        $tables = $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $table) {
            $pdo->exec("DROP TABLE `$table`");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /** @return string[] migrações aplicadas nesta execução */
    public function migrate(): array
    {
        $pdo = Db::pdo();
        $pdo->exec('CREATE TABLE IF NOT EXISTS migrations (
            name VARCHAR(190) PRIMARY KEY,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

        $applied = $pdo->query('SELECT name FROM migrations')->fetchAll(\PDO::FETCH_COLUMN);
        $files = glob($this->dir . '/*.sql');
        sort($files);

        $ran = [];
        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $applied, true)) {
                continue;
            }
            foreach ($this->statements((string) file_get_contents($file)) as $sql) {
                $pdo->exec($sql);
            }
            Db::insert('migrations', ['name' => $name]);
            $ran[] = $name;
        }
        return $ran;
    }

    /** @return string[] */
    private function statements(string $sql): array
    {
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);
        $parts = preg_split('/;\s*(\r?\n|$)/', $sql);
        return array_values(array_filter(array_map('trim', $parts), static fn ($s) => $s !== ''));
    }
}
