<?php

declare(strict_types=1);

namespace VijayBilling;

use PDO;

/**
 * Read-only data access for the checkout and paid-orders endpoints.
 *
 * Every query:
 *  - uses PDO prepared statements with bound, typed parameters (no string
 *    concatenation of request data into SQL);
 *  - is a single round trip per request (no N+1 or follow-up lookups);
 *  - is written so MySQL can use the indexes added by optimize.sql.
 *
 * JSON field names and value types are kept identical to the original
 * implementation for client compatibility.
 */
final class CheckoutRepository
{
    /** Upper bound on rows returned by listPaidByDay (safe response size). */
    public const PAID_LIST_LIMIT = 1000;

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * One order with customer email, invoice number and the recomputed item
     * total, or null when the order does not exist.
     *
     * Change vs. original: the item total was a correlated (DEPENDENT)
     * subquery referencing o.order_id. It now binds the validated order id
     * directly, so MySQL plans it as an independent SUBQUERY that is
     * "run only once" and answered entirely from the covering index
     * idx_order_items_order_qty_price (no item row reads). A derived-table
     * variant was also measured and rejected: it added
     * "Using temporary; Using filesort" to the plan.
     * Still exactly one database round trip per request.
     *
     * @return array{order_id:int, status:string, total_paise:int,
     *     customer_email:string, invoice_number:?string, computed_paise:?string}|null
     */
    public function fetchOrder(int $orderId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT o.order_id, o.status, o.total_paise,
                    c.email AS customer_email, i.invoice_number,
                    (SELECT SUM(oi.quantity * oi.unit_price_paise)
                       FROM order_items oi
                      WHERE oi.order_id = :items_order_id) AS computed_paise
               FROM orders o
               JOIN customers c ON c.customer_id = o.customer_id
               LEFT JOIN invoices i ON i.order_id = o.order_id
              WHERE o.order_id = :order_id'
        );
        $stmt->bindValue('items_order_id', $orderId, PDO::PARAM_INT);
        $stmt->bindValue('order_id', $orderId, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Paid orders created on one calendar day.
     *
     * Change vs. original: DATE(o.created_at) = :day forced a full table scan
     * (the function hides the column from every index). The half-open range
     * created_at >= start AND created_at < end returns exactly the same rows
     * and lets MySQL range-scan idx_orders_status_created_at (status, created_at).
     * Results are ordered by order_id (stable output) and capped at
     * PAID_LIST_LIMIT rows; one extra row is fetched to detect truncation.
     *
     * @param array{start: string, end: string} $range From RequestValidator::dayRange().
     * @return array{rows: list<array{order_id:int, total_paise:int, customer_email:string}>, truncated: bool}
     */
    public function listPaidByDay(array $range): array
    {
        $stmt = $this->db->prepare(
            "SELECT o.order_id, o.total_paise, c.email AS customer_email
               FROM orders o
               JOIN customers c ON c.customer_id = o.customer_id
              WHERE o.status = 'paid'
                AND o.created_at >= :day_start
                AND o.created_at <  :day_end
              ORDER BY o.order_id
              LIMIT :row_limit"
        );
        $stmt->bindValue('day_start', $range['start'], PDO::PARAM_STR);
        $stmt->bindValue('day_end', $range['end'], PDO::PARAM_STR);
        $stmt->bindValue('row_limit', self::PAID_LIST_LIMIT + 1, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll();
        $truncated = count($rows) > self::PAID_LIST_LIMIT;
        if ($truncated) {
            array_pop($rows);
        }

        return ['rows' => $rows, 'truncated' => $truncated];
    }
}
