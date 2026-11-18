<?php

/**
 * Front controller for the billing API.
 *
 * Routes:
 *   GET /api/checkout?order_id=<positive int>
 *   GET /api/paid-orders?date=<YYYY-MM-DD>
 * Auth: X-API-Token header, compared with the API_ACCESS_TOKEN environment variable.
 */

declare(strict_types=1);

use VijayBilling\ApiController;
use VijayBilling\Database;

$startNs = hrtime(true);

// Minimal PSR-4 style autoloader for the VijayBilling\ namespace (no Composer needed).
spl_autoload_register(static function (string $class): void {
    $prefix = 'VijayBilling\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/../src/' . substr($class, strlen($prefix)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

// Never print PHP warnings/traces into the JSON body.
ini_set('display_errors', '0');

$controller = new ApiController(
    static fn (): PDO => Database::connect(),
    (string) (getenv('API_ACCESS_TOKEN') ?: ''),
);

$token = $_SERVER['HTTP_X_API_TOKEN'] ?? null;

$response = $controller->handle(
    (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
    (string) ($_SERVER['REQUEST_URI'] ?? '/'),
    is_string($token) ? $token : null,
    $_GET,
    $startNs,
);

http_response_code($response['status']);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
foreach ($response['headers'] as $name => $value) {
    header($name . ': ' . $value);
}

echo json_encode($response['body'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
