<?php

declare(strict_types=1);

namespace SupportSla\Support;

use PDO;
use PDOException;

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $host = (string) Env::get('DB_HOST', '127.0.0.1');
        $port = (string) Env::get('DB_PORT', '3306');
        $name = (string) Env::get('DB_DATABASE', 'support_sla');
        $user = (string) Env::get('DB_USERNAME', 'root');
        $password = (string) Env::get('DB_PASSWORD', '');

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
        try {
            self::$connection = new PDO($dsn, $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $exception) {
            $detail = Env::bool('APP_DEBUG', false) ? ' Detalhe: ' . $exception->getMessage() : '';
            throw new PDOException('Não foi possível conectar ao MySQL. Confira o arquivo .env e o ServBay.' . $detail, (int) $exception->getCode(), $exception);
        }

        return self::$connection;
    }

    public static function transaction(callable $callback): mixed
    {
        $db = self::connection();
        $db->beginTransaction();
        try {
            $result = $callback($db);
            $db->commit();
            return $result;
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $exception;
        }
    }
}

