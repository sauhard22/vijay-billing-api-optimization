<?php

declare(strict_types=1);

namespace VijayBilling;

use Closure;
use PDO;
use Throwable;

/**
 * Routes a request, enforces method and token checks, and builds the JSON
 * response. Pure request-in / response-out so it can be tested without a
 * web server.
 *
 * Order of checks (unchanged from the original):
 *   1. method must be GET          -> 405 method_not_allowed (with Allow header)
 *   2. X-API-Token must match env  -> 401 unauthorized
 *   3. route lookup                -> 404 not_found
 *   4. input validation            -> 400 invalid_order_id / invalid_date
 *   5. data lookup                 -> 404 not_found (unknown order) or 200
 * Anything unexpected               -> 500 internal_error (details logged only)
 */
final class ApiController
{
    /**
     * @param Closure(): PDO $connect Lazily opens the DB connection, so 401/404/405
     *                                and validation errors never touch the database.
     */
    public function __construct(
        private readonly Closure $connect,
        private readonly string $apiToken,
    ) {
    }

    /**
     * @param array<string, mixed> $query
     * @return array{status:int, headers:array<string,string>, body:array<string,mixed>}
     */
    public function handle(string $method, string $uri, ?string $token, array $query, int $startNs): array
    {
        try {
            if ($method !== 'GET') {
                throw new HttpException(405, 'method_not_allowed', ['Allow' => 'GET']);
            }

            // Constant-time comparison; an unset server token never authorises.
            if ($this->apiToken === '' || $token === null || !hash_equals($this->apiToken, $token)) {
                throw new HttpException(401, 'unauthorized');
            }

            $path = parse_url($uri, PHP_URL_PATH);

            return match ($path) {
                '/api/checkout' => $this->checkout($query, $startNs),
                '/api/paid-orders' => $this->paidOrders($query, $startNs),
                default => throw new HttpException(404, 'not_found'),
            };
        } catch (HttpException $e) {
            return self::response($e->status(), ['error' => $e->errorCode()], $e->headers());
        } catch (Throwable $e) {
            // Log class and message only (no stack trace, no SQL, no credentials).
            error_log(sprintf('API failure: %s: %s', $e::class, $e->getMessage()));

            return self::response(500, ['error' => 'internal_error']);
        }
    }

    /**
     * @param array<string, mixed> $query
     * @return array{status:int, headers:array<string,string>, body:array<string,mixed>}
     */
    private function checkout(array $query, int $startNs): array
    {
        $orderId = RequestValidator::orderId($query);
        $order = $this->repository()->fetchOrder($orderId);
        if ($order === null) {
            throw new HttpException(404, 'not_found');
        }

        return self::response(200, ['order' => $order, 'duration_ms' => self::elapsedMs($startNs)]);
    }

    /**
     * @param array<string, mixed> $query
     * @return array{status:int, headers:array<string,string>, body:array<string,mixed>}
     */
    private function paidOrders(array $query, int $startNs): array
    {
        $range = RequestValidator::dayRange($query);
        $result = $this->repository()->listPaidByDay($range);

        // items/count/duration_ms are the original fields; "truncated" is additive.
        return self::response(200, [
            'items' => $result['rows'],
            'count' => count($result['rows']),
            'truncated' => $result['truncated'],
            'duration_ms' => self::elapsedMs($startNs),
        ]);
    }

    private function repository(): CheckoutRepository
    {
        return new CheckoutRepository(($this->connect)());
    }

    private static function elapsedMs(int $startNs): float
    {
        return round((hrtime(true) - $startNs) / 1e6, 3);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     * @return array{status:int, headers:array<string,string>, body:array<string,mixed>}
     */
    private static function response(int $status, array $body, array $headers = []): array
    {
        return ['status' => $status, 'headers' => $headers, 'body' => $body];
    }
}
