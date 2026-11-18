<?php

declare(strict_types=1);

namespace VijayBilling;

use DateTimeImmutable;

/**
 * Strict, typed validation of query-string input.
 *
 * Values are validated as raw strings BEFORE any cast, so inputs such as
 * "150 OR 1=1", "1e3", "-5", "150abc" or order_id[]=150 are rejected with 400
 * instead of being silently coerced into a different, valid-looking value.
 */
final class RequestValidator
{
    /** orders.order_id is a signed MySQL INT. */
    private const MAX_ORDER_ID = 2147483647;

    /**
     * @param array<string, mixed> $query Usually $_GET.
     */
    public static function orderId(array $query): int
    {
        $raw = $query['order_id'] ?? null;

        // Positive integer, no sign, no leading zero, no spaces, at most 10 digits.
        if (!is_string($raw) || preg_match('/^[1-9][0-9]{0,9}$/', $raw) !== 1) {
            throw new HttpException(400, 'invalid_order_id');
        }

        $id = (int) $raw;
        if ($id > self::MAX_ORDER_ID) {
            throw new HttpException(400, 'invalid_order_id');
        }

        return $id;
    }

    /**
     * Validates an ISO-8601 calendar date (YYYY-MM-DD) and returns the
     * half-open range [day 00:00:00, next day 00:00:00) used by the
     * index-friendly query.
     *
     * @param array<string, mixed> $query Usually $_GET.
     * @return array{start: string, end: string}
     */
    public static function dayRange(array $query): array
    {
        $raw = $query['date'] ?? null;

        if (!is_string($raw) || preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/', $raw, $m) !== 1) {
            throw new HttpException(400, 'invalid_date');
        }

        // Reject impossible dates such as 2026-02-30 or 2026-13-01.
        if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || (int) $m[1] < 1000) {
            throw new HttpException(400, 'invalid_date');
        }

        $day = new DateTimeImmutable($raw . ' 00:00:00');

        return [
            'start' => $day->format('Y-m-d H:i:s'),
            'end' => $day->modify('+1 day')->format('Y-m-d H:i:s'),
        ];
    }
}
