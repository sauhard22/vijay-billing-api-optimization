<?php

declare(strict_types=1);

namespace VijayBilling;

use PDO;

/**
 * Builds the PDO connection from environment variables only.
 *
 * No credential has a hard-coded fallback: if DB_USER or DB_PASSWORD is not
 * set, the connection is refused with a ConfigurationException instead of
 * silently trying an empty or default password.
 */
final class Database
{
    public static function connect(): PDO
    {
        $host = self::env('DB_HOST', 'db');
        $port = self::env('DB_PORT', '3306');
        $name = self::env('DB_NAME', 'vijay_billing');
        $user = self::requiredEnv('DB_USER');
        $password = self::requiredEnv('DB_PASSWORD');

        if (preg_match('/^[0-9]{1,5}$/', $port) !== 1) {
            throw new ConfigurationException('DB_PORT must be numeric');
        }

        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name);

        return new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Native server-side prepared statements: values never get
            // interpolated into the SQL text, even by the driver.
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 3,
        ]);
    }

    private static function env(string $key, string $default): string
    {
        $value = getenv($key);

        return ($value === false || $value === '') ? $default : $value;
    }

    private static function requiredEnv(string $key): string
    {
        $value = getenv($key);
        if ($value === false || $value === '') {
            throw new ConfigurationException("Missing required environment variable {$key}");
        }

        return $value;
    }
}
