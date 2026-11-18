-- Plans captured for every query on the checkout/paid-orders paths.
-- Q1-Q3 are taken verbatim from INPUT_FILES/db/queries_to_audit.sql.
-- E1/E2 are the exact SQL the starter PHP endpoints send (bound values inlined).
USE vijay_billing;
SELECT VERSION() AS mysql_version, @@innodb_buffer_pool_size AS buffer_pool, NOW() AS captured_at;
SHOW INDEX FROM orders; SHOW INDEX FROM order_items; SHOW INDEX FROM invoices; SHOW INDEX FROM customers;
ANALYZE TABLE customers, orders, order_items, invoices;

-- ===== Q1 (original): paid orders for a day =====
EXPLAIN SELECT o.order_id, c.name, i.invoice_number FROM orders o
JOIN customers c ON o.customer_id=c.customer_id JOIN invoices i ON i.order_id=o.order_id
WHERE DATE(o.created_at)='2026-10-15' AND o.status='paid';
EXPLAIN ANALYZE SELECT o.order_id, c.name, i.invoice_number FROM orders o
JOIN customers c ON o.customer_id=c.customer_id JOIN invoices i ON i.order_id=o.order_id
WHERE DATE(o.created_at)='2026-10-15' AND o.status='paid';

-- ===== Q2 (original): correlated total per paid order =====
EXPLAIN SELECT o.order_id, (SELECT SUM(oi.quantity*oi.unit_price_paise)
FROM order_items oi WHERE oi.order_id=o.order_id) AS recalculated_paise
FROM orders o WHERE o.status='paid';
EXPLAIN ANALYZE SELECT o.order_id, (SELECT SUM(oi.quantity*oi.unit_price_paise)
FROM order_items oi WHERE oi.order_id=o.order_id) AS recalculated_paise
FROM orders o WHERE o.status='paid';

-- ===== Q3 (original): invoice lookup by number =====
EXPLAIN SELECT invoice_id, order_id, amount_paise FROM invoices WHERE invoice_number='INV-2026-00150';
EXPLAIN ANALYZE SELECT invoice_id, order_id, amount_paise FROM invoices WHERE invoice_number='INV-2026-00150';

-- ===== E1 (starter CheckoutRepository::fetchOrder, order_id=150) =====
EXPLAIN SELECT o.order_id, o.status, o.total_paise,
  c.email AS customer_email, i.invoice_number,
  (SELECT SUM(oi.quantity * oi.unit_price_paise) FROM order_items oi WHERE oi.order_id=o.order_id) AS computed_paise
  FROM orders o JOIN customers c ON c.customer_id=o.customer_id
  LEFT JOIN invoices i ON i.order_id=o.order_id WHERE o.order_id=150;
EXPLAIN ANALYZE SELECT o.order_id, o.status, o.total_paise,
  c.email AS customer_email, i.invoice_number,
  (SELECT SUM(oi.quantity * oi.unit_price_paise) FROM order_items oi WHERE oi.order_id=o.order_id) AS computed_paise
  FROM orders o JOIN customers c ON c.customer_id=o.customer_id
  LEFT JOIN invoices i ON i.order_id=o.order_id WHERE o.order_id=150;

-- ===== E2 (starter CheckoutRepository::listPaidByDay, date=2026-10-15) =====
EXPLAIN SELECT o.order_id, o.total_paise, c.email AS customer_email FROM orders o
  JOIN customers c ON c.customer_id=o.customer_id
  WHERE DATE(o.created_at)='2026-10-15' AND o.status='paid';
EXPLAIN ANALYZE SELECT o.order_id, o.total_paise, c.email AS customer_email FROM orders o
  JOIN customers c ON c.customer_id=o.customer_id
  WHERE DATE(o.created_at)='2026-10-15' AND o.status='paid';
