-- SUPPLEMENTAL, SYNTHETIC: builds vijay_billing_scale with the SAME schema as INPUT_FILES/db/schema.sql
-- and deterministic generated rows (20k customers, 500k orders, ~1.25M items, 500k invoices) so index
-- effects that are invisible at 600 rows can be measured. Not client data; results reported separately.
DROP DATABASE IF EXISTS vijay_billing_scale;
CREATE DATABASE vijay_billing_scale CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
USE vijay_billing_scale;
CREATE TABLE customers (customer_id INT PRIMARY KEY, name VARCHAR(100) NOT NULL, email VARCHAR(180) NOT NULL);
CREATE TABLE products (product_id INT PRIMARY KEY, title VARCHAR(120) NOT NULL, unit_price_paise INT NOT NULL);
CREATE TABLE orders (order_id INT PRIMARY KEY, customer_id INT NOT NULL, created_at DATETIME NOT NULL, status VARCHAR(20) NOT NULL, total_paise BIGINT NOT NULL, CONSTRAINT fk_order_customer FOREIGN KEY(customer_id) REFERENCES customers(customer_id));
CREATE TABLE order_items (item_id INT PRIMARY KEY, order_id INT NOT NULL, product_id INT NOT NULL, quantity INT NOT NULL, unit_price_paise INT NOT NULL, CONSTRAINT fk_item_order FOREIGN KEY(order_id) REFERENCES orders(order_id), CONSTRAINT fk_item_product FOREIGN KEY(product_id) REFERENCES products(product_id));
CREATE TABLE invoices (invoice_id INT PRIMARY KEY, order_id INT NOT NULL, invoice_number VARCHAR(40) NOT NULL, issued_on DATE NOT NULL, amount_paise BIGINT NOT NULL, status VARCHAR(20) NOT NULL, CONSTRAINT fk_invoice_order FOREIGN KEY(order_id) REFERENCES orders(order_id));

SET SESSION cte_max_recursion_depth = 1000000;
CREATE TABLE tmp_seq (n INT PRIMARY KEY);
INSERT INTO tmp_seq WITH RECURSIVE s(n) AS (SELECT 1 UNION ALL SELECT n+1 FROM s WHERE n < 500000) SELECT n FROM s;

INSERT INTO products SELECT product_id, title, unit_price_paise FROM vijay_billing.products;
INSERT INTO customers SELECT n, CONCAT('Account ', LPAD(n,5,'0')), CONCAT('account', LPAD(n,5,'0'), '@example.test') FROM tmp_seq WHERE n <= 20000;

-- 1-4 items per order (avg 2.5); item_id = (order_id-1)*4 + k
CREATE TABLE tmp_items AS
SELECT (s.n-1)*4 + k.k AS item_id, s.n AS order_id, 1 + ((s.n*7 + k.k*13) % 24) AS product_id, 1 + ((s.n + k.k) % 3) AS quantity
FROM tmp_seq s JOIN (SELECT 1 k UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4) k ON k.k <= 1 + (s.n % 4);

-- 500k orders over 730 days, 60% paid / 20% pending / 20% failed (same mix as seed)
INSERT INTO orders (order_id, customer_id, created_at, status, total_paise)
SELECT s.n, 1 + (s.n*37 % 20000),
       TIMESTAMP('2025-01-01') + INTERVAL (s.n % 730) DAY + INTERVAL ((s.n*97) % 86400) SECOND,
       ELT(1 + ((s.n DIV 730) % 5), 'paid','paid','paid','pending','failed'),
       0
FROM tmp_seq s;
INSERT INTO order_items (item_id, order_id, product_id, quantity, unit_price_paise)
SELECT t.item_id, t.order_id, t.product_id, t.quantity, p.unit_price_paise FROM tmp_items t JOIN products p USING (product_id);
UPDATE orders o JOIN (SELECT order_id, SUM(quantity*unit_price_paise) s FROM order_items GROUP BY order_id) x USING (order_id) SET o.total_paise = x.s;
INSERT INTO invoices (invoice_id, order_id, invoice_number, issued_on, amount_paise, status)
SELECT o.order_id, o.order_id, CONCAT('INV-2026-', LPAD(o.order_id, 6, '0')), DATE(o.created_at), o.total_paise, 'issued' FROM orders o;
DROP TABLE tmp_items; DROP TABLE tmp_seq;
ANALYZE TABLE customers, orders, order_items, invoices;
SELECT (SELECT COUNT(*) FROM customers) customers,(SELECT COUNT(*) FROM orders) orders,(SELECT COUNT(*) FROM order_items) items,(SELECT COUNT(*) FROM invoices) invoices;
