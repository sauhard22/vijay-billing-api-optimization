<?php

/**
 * Dependency-free unit tests (no Composer/PHPUnit needed):
 *   php tests/UnitTest.php
 * Exercises RequestValidator and ApiController with a stub connection factory,
 * so the HTTP contract for 400/401/404/405/500 is verified without a database.
 * Exit code 0 = all passed.
 */

declare(strict_types=1);

use VijayBilling\ApiController;
use VijayBilling\HttpException;
use VijayBilling\RequestValidator;

spl_autoload_register(static function (string $class): void {
    $prefix = 'VijayBilling\\';
    if (str_starts_with($class, $prefix)) {
        require __DIR__ . '/../src/' . substr($class, strlen($prefix)) . '.php';
    }
});

$results = [];

$check = static function (string $id, string $name, bool $ok, string $detail = '') use (&$results): void {
    $results[] = [$id, $name, $ok, $detail];
};

/** True when $fn($query) throws HttpException 400 with the given error code. */
$rejects = static function (callable $fn, array $query, string $code): bool {
    try {
        $fn($query);
    } catch (HttpException $e) {
        return $e->status() === 400 && $e->errorCode() === $code;
    }

    return false;
};

$orderId = [RequestValidator::class, 'orderId'];
$dayRange = [RequestValidator::class, 'dayRange'];
$tok = 'secret-token';

// ---- RequestValidator::orderId ----
$check('U01', 'orderId accepts 150', RequestValidator::orderId(['order_id' => '150']) === 150);
$check('U02', 'orderId accepts INT max', RequestValidator::orderId(['order_id' => '2147483647']) === 2147483647);
$badIds = ['', '0', '-5', '007', '1e3', '150 OR 1=1', '150abc', ' 150', '1.5', '2147483648', '99999999999'];
foreach ($badIds as $i => $bad) {
    $check(sprintf('U03.%02d', $i), "orderId rejects '{$bad}'", $rejects(
        $orderId,
        ['order_id' => $bad],
        'invalid_order_id'
    ));
}
$check('U04', 'orderId rejects array input', $rejects($orderId, ['order_id' => ['150']], 'invalid_order_id'));
$check('U05', 'orderId rejects missing', $rejects($orderId, [], 'invalid_order_id'));

// ---- RequestValidator::dayRange ----
$r = RequestValidator::dayRange(['date' => '2026-10-15']);
$expected = ['start' => '2026-10-15 00:00:00', 'end' => '2026-10-16 00:00:00'];
$check('U06', 'dayRange half-open range', $r === $expected, json_encode($r));
$r = RequestValidator::dayRange(['date' => '2026-12-31']);
$check('U07', 'dayRange crosses year end', $r['end'] === '2027-01-01 00:00:00', $r['end']);
$r = RequestValidator::dayRange(['date' => '2028-02-29']);
$check('U08', 'dayRange accepts leap day', $r['end'] === '2028-03-01 00:00:00', $r['end']);
$badDates = [
    'not-a-date', '2026-02-30', '2026-13-01', '2026-1-5', '15-10-2026',
    "2026-10-15' OR '1'='1", '2026-10-15T00:00', '0000-01-01', '',
];
foreach ($badDates as $i => $bad) {
    $check(sprintf('U09.%02d', $i), "dayRange rejects '{$bad}'", $rejects(
        $dayRange,
        ['date' => $bad],
        'invalid_date'
    ));
}
$check('U10', 'dayRange rejects array input', $rejects($dayRange, ['date' => ['2026-10-15']], 'invalid_date'));

// ---- ApiController (DB must NOT be touched for rejected requests) ----
$dbTouched = false;
$noDb = static function () use (&$dbTouched): PDO {
    $dbTouched = true;
    throw new RuntimeException('database should not be reached');
};
$api = new ApiController($noDb, 'secret-token');
$t0 = hrtime(true);

$res = $api->handle('POST', '/api/checkout?order_id=150', $tok, ['order_id' => '150'], $t0);
$check('U11', 'POST -> 405 + Allow: GET', $res['status'] === 405
    && $res['body'] === ['error' => 'method_not_allowed'] && $res['headers'] === ['Allow' => 'GET']);

$res = $api->handle('GET', '/api/checkout?order_id=150', null, ['order_id' => '150'], $t0);
$check('U12', 'missing token -> 401', $res['status'] === 401 && $res['body'] === ['error' => 'unauthorized']);

$res = $api->handle('GET', '/api/checkout?order_id=150', 'wrong', ['order_id' => '150'], $t0);
$check('U13', 'wrong token -> 401', $res['status'] === 401);

$res = (new ApiController($noDb, ''))->handle('GET', '/api/checkout', '', [], $t0);
$check('U14', 'unset server token never authorises', $res['status'] === 401);

$res = $api->handle('GET', '/api/nope', $tok, [], $t0);
$check('U15', 'unknown route -> 404', $res['status'] === 404 && $res['body'] === ['error' => 'not_found']);

$res = $api->handle('GET', '/api/checkout?order_id=abc', $tok, ['order_id' => 'abc'], $t0);
$check('U16', 'bad order_id -> 400 invalid_order_id', $res['status'] === 400
    && $res['body'] === ['error' => 'invalid_order_id']);

$res = $api->handle('GET', '/api/paid-orders?date=x', $tok, ['date' => 'x'], $t0);
$check('U17', 'bad date -> 400 invalid_date', $res['status'] === 400 && $res['body'] === ['error' => 'invalid_date']);

$check('U18', 'no DB connection opened for rejected requests', $dbTouched === false);

// DB failure on a valid request -> generic 500, no internal detail leaked.
ini_set('error_log', '/dev/null');
$res = $api->handle('GET', '/api/checkout?order_id=150', $tok, ['order_id' => '150'], $t0);
$check('U19', 'DB failure -> 500 internal_error, no leak', $res['status'] === 500
    && $res['body'] === ['error' => 'internal_error']);

// ---- report ----
$failed = 0;
foreach ($results as [$id, $name, $ok, $detail]) {
    $failed += $ok ? 0 : 1;
    printf("%-7s %-4s %s%s\n", $id, $ok ? 'PASS' : 'FAIL', $name, $ok || $detail === '' ? '' : "  ({$detail})");
}
printf("%d/%d unit tests passed\n", count($results) - $failed, count($results));
exit($failed === 0 ? 0 : 1);
