<?php

declare(strict_types=1);

final class Database
{
    private static ?PDO $connection = null;

    public static function connect(array $config): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config['host'],
            (int) $config['port'],
            $config['name']
        );
        self::$connection = new PDO($dsn, $config['user'], $config['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        return self::$connection;
    }

    public static function pdo(): PDO
    {
        if (!self::$connection) {
            throw new RuntimeException('Database is not connected.');
        }
        return self::$connection;
    }

    public static function fetch(string $sql, array $params = []): ?array
    {
        $statement = self::pdo()->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch();
        return $row === false ? null : $row;
    }

    public static function fetchAll(string $sql, array $params = []): array
    {
        $statement = self::pdo()->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll();
    }

    public static function execute(string $sql, array $params = []): int
    {
        $statement = self::pdo()->prepare($sql);
        $statement->execute($params);
        return $statement->rowCount();
    }

    public static function id(): int
    {
        return (int) self::pdo()->lastInsertId();
    }

    public static function upgradeSchema(): void
    {
        $column = self::fetch("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='plans' AND COLUMN_NAME='router_id'");
        if (!$column) {
            self::execute('ALTER TABLE plans ADD COLUMN router_id BIGINT UNSIGNED NULL AFTER id, ADD KEY idx_plan_router (router_id)');
            $routers = self::fetchAll('SELECT id FROM routers ORDER BY id LIMIT 2');
            if (count($routers) === 1) {
                self::execute('UPDATE plans SET router_id=? WHERE router_id IS NULL', [(int) $routers[0]['id']]);
            }
        }
    }
}
