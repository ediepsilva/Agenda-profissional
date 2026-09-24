<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use Throwable;

/**
 * Acesso ao banco via PDO com prepared statements (sem emulação).
 */
final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = self::connect(Env::get('DB_NAME', 'agenda_profissional'));
        }
        return self::$pdo;
    }

    public static function connect(?string $database): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;charset=utf8mb4%s',
            Env::get('DB_HOST', '127.0.0.1'),
            Env::get('DB_PORT', '3306'),
            $database ? ';dbname=' . $database : ''
        );
        $pdo = new PDO($dsn, Env::get('DB_USER', 'root'), Env::get('DB_PASS', ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        // READ COMMITTED: após obter um lock (SELECT ... FOR UPDATE), as leituras seguintes
        // enxergam o que outras transações já gravaram — essencial para a checagem de conflito.
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        return $pdo;
    }

    public static function reset(): void
    {
        self::$pdo = null;
    }

    public static function all(string $sql, array $params = []): array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();
        return $value === false ? null : $value;
    }

    public static function exec(string $sql, array $params = []): int
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            implode(', ', array_map(static fn ($c) => "`$c`", $cols)),
            implode(', ', array_fill(0, count($cols), '?'))
        );
        self::exec($sql, array_values($data));
        return (int) self::pdo()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $set = implode(', ', array_map(static fn ($c) => "`$c` = ?", array_keys($data)));
        return self::exec("UPDATE `$table` SET $set WHERE $where", [...array_values($data), ...$whereParams]);
    }

    /**
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
