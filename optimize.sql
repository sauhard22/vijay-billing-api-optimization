-- =============================================================================
-- optimize.sql  -  Vijay Mishra billing / checkout query optimisation
-- Job: rkGmI2UioRXvzqgpCOEH          Target: MySQL 8.0 (tested on 8.0.46)
--
-- NON-DESTRUCTIVE: this script only ADDS secondary indexes. It does not drop,
-- rename or alter any column, table, constraint or existing index, and it does
-- not modify any row. Every index is created only if it is not already present,
-- so the script is safe to re-run.
--
-- Each index below is justified by the EXPLAIN evidence in
-- EXPLAIN_Before_After.txt (sections Q1, Q2, Q3, E1, E2).
--
-- Rollback (only if ever needed):
--   ALTER TABLE orders      DROP INDEX idx_orders_status_created_at;
--   ALTER TABLE order_items DROP INDEX idx_order_items_order_qty_price;
--   ALTER TABLE invoices    DROP INDEX uq_invoices_invoice_number;
--
-- Run:  mysql -u <admin_user> -p vijay_billing < optimize.sql
-- Needs ALTER/INDEX privilege; the API's runtime account does not.
-- =============================================================================

USE vijay_billing;

-- -----------------------------------------------------------------------------
-- Helper: create an index only when no index with that name exists yet.
-- MySQL 8.0 has no "CREATE INDEX IF NOT EXISTS", so we check
-- information_schema and run the DDL through a prepared statement.
-- ALGORITHM=INPLACE, LOCK=NONE keeps the table readable and writable while the
-- index is built (online DDL), so checkout traffic is not blocked.
-- -----------------------------------------------------------------------------

-- =============================================================================
-- 1) orders (status, created_at)
--    Fixes Q1 and the /api/paid-orders endpoint (E2); also serves the
--    status='paid' filter in Q2.
--    BEFORE: type=ALL, full table scan of orders (600 rows on the seed,
--            500,000 on the scale test) because DATE(created_at) hides the
--            column from any index and there is no index on status.
--    AFTER : type=range on this index; only the paid rows of the requested
--            day are read (7 on the seed, 411 on the scale test).
--    Column order: equality column (status) first, range column
--    (created_at) second, so one index range covers "paid AND within day".
--    The query must also be rewritten from DATE(created_at) = :day to a
--    half-open range (see the rewritten queries at the end of this file).
-- =============================================================================
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'orders'
      AND index_name = 'idx_orders_status_created_at'
);
SET @ddl := IF(
    @idx_exists = 0,
    'ALTER TABLE orders ADD INDEX idx_orders_status_created_at (status, created_at), ALGORITHM=INPLACE, LOCK=NONE',
    'SELECT ''idx_orders_status_created_at already present - skipped'' AS info'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =============================================================================
-- 2) order_items (order_id, quantity, unit_price_paise)  - covering index
--    Fixes Q2 and the computed_paise part of /api/checkout (E1).
--    BEFORE: per order, MySQL looked up order_items through fk_item_order
--            (order_id only) and then read every full item row to get
--            quantity and unit_price_paise (Q2 repeats this 360 times on
--            the seed, 300,029 times on the scale test).
--    AFTER : "Covering index lookup" - SUM(quantity * unit_price_paise) is
--            answered from the index alone, with no row reads.
--    The existing fk_item_order index is left in place (non-destructive);
--    it is now redundant and could be dropped in a later, reviewed change.
-- =============================================================================
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'order_items'
      AND index_name = 'idx_order_items_order_qty_price'
);
SET @ddl := IF(
    @idx_exists = 0,
    'ALTER TABLE order_items ADD INDEX idx_order_items_order_qty_price (order_id, quantity, unit_price_paise), ALGORITHM=INPLACE, LOCK=NONE',
    'SELECT ''idx_order_items_order_qty_price already present - skipped'' AS info'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =============================================================================
-- 3) invoices (invoice_number)  - UNIQUE
--    Fixes Q3 (invoice lookup by number).
--    BEFORE: type=ALL, full scan of invoices for a single row.
--    AFTER : type=const, a single unique-index probe.
--    Why UNIQUE rather than a plain index: an invoice number identifies
--    exactly one invoice (the fixtures confirm 600 distinct numbers for
--    600 invoices). UNIQUE lets the optimiser treat the lookup as const and
--    protects data integrity.
--    SAFETY: if duplicates already exist, adding UNIQUE would fail. The
--    guard below counts duplicates first and, if any are found, SKIPS the
--    index and prints a message instead of failing half-way. Resolve the
--    duplicates, then re-run this script.
--    Compatibility note: after this index exists, an INSERT that reuses an
--    existing invoice_number is rejected (error 1062). That is the intended
--    business rule, but check that no writer relies on duplicates.
-- =============================================================================
SET @dupes := (
    SELECT COUNT(*) FROM (
        SELECT invoice_number FROM invoices
        GROUP BY invoice_number HAVING COUNT(*) > 1
    ) d
);
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'invoices'
      AND index_name = 'uq_invoices_invoice_number'
);
SET @ddl := CASE
    WHEN @idx_exists > 0 THEN
        'SELECT ''uq_invoices_invoice_number already present - skipped'' AS info'
    WHEN @dupes > 0 THEN
        CONCAT('SELECT ''SKIPPED uq_invoices_invoice_number: ', @dupes,
               ' duplicated invoice_number value(s) must be resolved first'' AS warning')
    ELSE
        'ALTER TABLE invoices ADD UNIQUE INDEX uq_invoices_invoice_number (invoice_number), ALGORITHM=INPLACE, LOCK=NONE'
END;
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Refresh index statistics so the optimiser sees the new indexes immediately.
-- (ANALYZE TABLE only updates statistics; it does not change data.)
ANALYZE TABLE orders, order_items, invoices;

-- =============================================================================
-- REWRITTEN READ QUERIES (read-only; these are what the refactored PHP sends)
-- Parameters shown as :name are bound by PDO prepared statements in
-- CheckoutRepository.php. The EXPLAIN lines let a reviewer confirm the plans.
-- =============================================================================

-- Q1 rewritten: sargable half-open date range instead of DATE(created_at).
--   :day_start = '2026-10-15 00:00:00', :day_end = '2026-10-16 00:00:00'
--   Same rows as the original: DATE(x) = d  <=>  d 00:00:00 <= x < d+1 00:00:00
EXPLAIN
SELECT o.order_id, c.name, i.invoice_number
FROM orders o
JOIN customers c ON c.customer_id = o.customer_id
JOIN invoices  i ON i.order_id    = o.order_id
WHERE o.status = 'paid'
  AND o.created_at >= '2026-10-15 00:00:00'
  AND o.created_at <  '2026-10-16 00:00:00';

-- E2 rewritten (/api/paid-orders): same range predicate, deterministic order,
-- and a row cap enforced in PHP (LIMIT :limit_plus_one).
EXPLAIN
SELECT o.order_id, o.total_paise, c.email AS customer_email
FROM orders o
JOIN customers c ON c.customer_id = o.customer_id
WHERE o.status = 'paid'
  AND o.created_at >= '2026-10-15 00:00:00'
  AND o.created_at <  '2026-10-16 00:00:00'
ORDER BY o.order_id
LIMIT 1001;

-- Q2: the correlated subquery is KEPT, served by the covering index.
-- The grouped-join rewrite asked about in the brief was measured on both the
-- seed and the 500k-order scale data (see Performance_Benchmarks.csv,
-- sql-level rows Q2-original vs Q2-rewritten); it is listed here for review
-- but was not adopted because it was not faster after indexing.
EXPLAIN
SELECT o.order_id,
       (SELECT SUM(oi.quantity * oi.unit_price_paise)
        FROM order_items oi
        WHERE oi.order_id = o.order_id) AS recalculated_paise
FROM orders o
WHERE o.status = 'paid';

-- Q2 alternative (grouped join), measured, not adopted:
-- SELECT o.order_id, SUM(oi.quantity * oi.unit_price_paise) AS recalculated_paise
-- FROM orders o LEFT JOIN order_items oi ON oi.order_id = o.order_id
-- WHERE o.status = 'paid' GROUP BY o.order_id;

-- Q3: unchanged SQL; now a const lookup on uq_invoices_invoice_number.
EXPLAIN
SELECT invoice_id, order_id, amount_paise
FROM invoices
WHERE invoice_number = 'INV-2026-00150';

-- E1 rewritten (/api/checkout): one round trip; the item total no longer
-- references o.order_id (correlated) but the bound order id, so MySQL runs it
-- once ("SUBQUERY", not "DEPENDENT SUBQUERY") from the covering index.
--   :order_id and :items_order_id are both bound to the validated order id.
EXPLAIN
SELECT o.order_id, o.status, o.total_paise,
       c.email AS customer_email, i.invoice_number,
       (SELECT SUM(oi.quantity * oi.unit_price_paise)
        FROM order_items oi
        WHERE oi.order_id = 150) AS computed_paise
FROM orders o
JOIN customers c ON c.customer_id = o.customer_id
LEFT JOIN invoices i ON i.order_id = o.order_id
WHERE o.order_id = 150;
