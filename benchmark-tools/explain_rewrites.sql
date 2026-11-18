-- AFTER: rewritten queries exactly as sent by the refactored PHP (bound values inlined).
USE vijay_billing;
-- ===== Q1 (rewritten): sargable half-open range =====
EXPLAIN SELECT o.order_id, c.name, i.invoice_number FROM orders o
JOIN customers c ON c.customer_id = o.customer_id JOIN invoices i ON i.order_id = o.order_id
WHERE o.status = 'paid' AND o.created_at >= '2026-10-15 00:00:00' AND o.created_at < '2026-10-16 00:00:00';
EXPLAIN ANALYZE SELECT o.order_id, c.name, i.invoice_number FROM orders o
JOIN customers c ON c.customer_id = o.customer_id JOIN invoices i ON i.order_id = o.order_id
WHERE o.status = 'paid' AND o.created_at >= '2026-10-15 00:00:00' AND o.created_at < '2026-10-16 00:00:00';
-- ===== Q2 (grouped-join alternative, for comparison) =====
EXPLAIN SELECT o.order_id, SUM(oi.quantity*oi.unit_price_paise) AS recalculated_paise
FROM orders o LEFT JOIN order_items oi ON oi.order_id=o.order_id WHERE o.status='paid' GROUP BY o.order_id;
EXPLAIN ANALYZE SELECT o.order_id, SUM(oi.quantity*oi.unit_price_paise) AS recalculated_paise
FROM orders o LEFT JOIN order_items oi ON oi.order_id=o.order_id WHERE o.status='paid' GROUP BY o.order_id;
-- ===== E1 (refactored CheckoutRepository::fetchOrder, order_id=150) =====
EXPLAIN SELECT o.order_id, o.status, o.total_paise, c.email AS customer_email, i.invoice_number,
 (SELECT SUM(oi.quantity * oi.unit_price_paise) FROM order_items oi WHERE oi.order_id = 150) AS computed_paise
FROM orders o JOIN customers c ON c.customer_id = o.customer_id
LEFT JOIN invoices i ON i.order_id = o.order_id WHERE o.order_id = 150;
EXPLAIN ANALYZE SELECT o.order_id, o.status, o.total_paise, c.email AS customer_email, i.invoice_number,
 (SELECT SUM(oi.quantity * oi.unit_price_paise) FROM order_items oi WHERE oi.order_id = 150) AS computed_paise
FROM orders o JOIN customers c ON c.customer_id = o.customer_id
LEFT JOIN invoices i ON i.order_id = o.order_id WHERE o.order_id = 150;
-- ===== E2 (refactored CheckoutRepository::listPaidByDay, 2026-10-15) =====
EXPLAIN SELECT o.order_id, o.total_paise, c.email AS customer_email FROM orders o
JOIN customers c ON c.customer_id = o.customer_id
WHERE o.status = 'paid' AND o.created_at >= '2026-10-15 00:00:00' AND o.created_at < '2026-10-16 00:00:00'
ORDER BY o.order_id LIMIT 1001;
EXPLAIN ANALYZE SELECT o.order_id, o.total_paise, c.email AS customer_email FROM orders o
JOIN customers c ON c.customer_id = o.customer_id
WHERE o.status = 'paid' AND o.created_at >= '2026-10-15 00:00:00' AND o.created_at < '2026-10-16 00:00:00'
ORDER BY o.order_id LIMIT 1001;
