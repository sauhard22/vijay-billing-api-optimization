# Billing API (refactored)

PHP 8.3 / MySQL 8 checkout and paid-orders API for Vijay Mishra (job `rkGmI2UioRXvzqgpCOEH`).
This folder replaces `INPUT_FILES/starter/` from the brief.

## Endpoints (unchanged contract)

| Route | Success | Errors |
|---|---|---|
| `GET /api/checkout?order_id=<1..2147483647>` | `200 {"order":{order_id,status,total_paise,customer_email,invoice_number,computed_paise},"duration_ms"}` | 400 `invalid_order_id`, 404 `not_found` |
| `GET /api/paid-orders?date=YYYY-MM-DD` | `200 {"items":[{order_id,total_paise,customer_email}],"count","truncated","duration_ms"}` | 400 `invalid_date` |

All routes: header `X-API-Token` must equal `API_ACCESS_TOKEN` (otherwise 401 `unauthorized`), method must be GET (otherwise 405 `method_not_allowed` with `Allow: GET`), unexpected failures return 500 `internal_error`.
`truncated` is the only new field (additive); the list is capped at 1,000 rows.

## Configuration (environment only)

`DB_HOST` (default `db`), `DB_PORT` (3306), `DB_NAME` (vijay_billing), `DB_USER` (required), `DB_PASSWORD` (required), `API_ACCESS_TOKEN` (required for any request to succeed).
Copy `.env.example` to `.env` for local use. `.env` is git-ignored; never commit it.

## Database

1. Apply indexes once with an admin account: `mysql -u root vijay_billing < ../optimize.sql`
   (idempotent, non-destructive; the Docker Compose file mounts it as `03_optimize.sql`).
2. Recommended: run the API as the read-only account from `sql/least_privilege_user.sql` (set a real secret first).

## Run and test

```bash
php -S 127.0.0.1:8087 -t public          # with the env vars above exported
php tests/UnitTest.php                    # 37 unit tests, no Composer needed
phpcs --standard=PSR12 public src tests   # PSR-12 check
```

With Docker (as in the brief, from the repository layout where `../db` and `../optimize.sql` exist):

```bash
cp .env.example .env
docker compose up --build -d
```

The Docker path was not executed during this engagement (Docker was unavailable on the test machine); everything else above was run.

## Layout

```
public/index.php              front controller + autoloader
src/ApiController.php         routing, auth, method check, error mapping
src/RequestValidator.php      strict order_id / date validation
src/CheckoutRepository.php    the two prepared, index-friendly queries
src/Database.php              env-based PDO connection (native prepares)
src/HttpException.php, src/ConfigurationException.php
sql/least_privilege_user.sql  SELECT-only runtime account
tests/UnitTest.php            unit tests
```
