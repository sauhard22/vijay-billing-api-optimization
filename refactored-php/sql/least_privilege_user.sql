-- Least-privilege runtime account for the billing API (read-only).
-- The API only ever SELECTs; it needs no INSERT/UPDATE/DELETE/DDL rights.
-- Run once as an administrator. Replace the placeholder password with a
-- secret from your secret store and put the same value in the API's
-- DB_PASSWORD environment variable. Never commit the real value.
CREATE USER IF NOT EXISTS 'billing_api_ro'@'%' IDENTIFIED BY 'REPLACE_WITH_SECRET_FROM_ENV';
GRANT SELECT ON vijay_billing.orders      TO 'billing_api_ro'@'%';
GRANT SELECT ON vijay_billing.customers   TO 'billing_api_ro'@'%';
GRANT SELECT ON vijay_billing.invoices    TO 'billing_api_ro'@'%';
GRANT SELECT ON vijay_billing.order_items TO 'billing_api_ro'@'%';
-- Restrict '%' to the API host/subnet in production (e.g. 'billing_api_ro'@'10.0.%').
