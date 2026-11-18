<?php

/**
 * SQL-level timing: runs each audited query through PDO prepared statements,
 * 5 warmups + 30 measured executions (fetching all rows), and appends samples to a CSV.
 * Usage: php sql_bench.php <db_name> <phase> <out.csv> <invoice_number>
 */

declare(strict_types=1);

[$self, $db, $phase, $out, $invoiceNo] = $argv;
$pdo = new PDO(
    "mysql:host=127.0.0.1;port=3306;dbname={$db};charset=utf8mb4",
    getenv('DB_USER') ?: 'billing_app',
    getenv('DB_PASSWORD') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);

$queries = [
    'Q1-original' => ["SELECT o.order_id, c.name, i.invoice_number FROM orders o
        JOIN customers c ON o.customer_id=c.customer_id JOIN invoices i ON i.order_id=o.order_id
        WHERE DATE(o.created_at)=:d AND o.status='paid'", ['d' => '2026-10-15']],
    'Q1-rewritten' => ["SELECT o.order_id, c.name, i.invoice_number FROM orders o
        JOIN customers c ON o.customer_id=c.customer_id JOIN invoices i ON i.order_id=o.order_id
        WHERE o.status='paid' AND o.created_at >= :s AND o.created_at < :e",
        ['s' => '2026-10-15 00:00:00', 'e' => '2026-10-16 00:00:00']],
    'Q2-original' => ["SELECT o.order_id, (SELECT SUM(oi.quantity*oi.unit_price_paise)
        FROM order_items oi WHERE oi.order_id=o.order_id) AS recalculated_paise
        FROM orders o WHERE o.status=:st", ['st' => 'paid']],
    'Q2-rewritten' => ["SELECT o.order_id, SUM(oi.quantity*oi.unit_price_paise) AS recalculated_paise
        FROM orders o LEFT JOIN order_items oi ON oi.order_id=o.order_id
        WHERE o.status=:st GROUP BY o.order_id", ['st' => 'paid']],
    'Q3' => ["SELECT invoice_id, order_id, amount_paise FROM invoices WHERE invoice_number=:n", ['n' => $invoiceNo]],
];

$fh = fopen($out, 'a');
if (ftell($fh) === 0) {
    fputcsv($fh, ['query_id', 'db', 'phase', 'iteration', 'rows', 'elapsed_ms'], ',', '"', '');
}
$summary = [];
foreach ($queries as $id => [$sql, $params]) {
    $stmt = $pdo->prepare($sql);
    $lat = [];
    for ($i = -4; $i <= 30; $i++) {
        $t0 = hrtime(true);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        $ms = (hrtime(true) - $t0) / 1e6;
        if ($i >= 1) {
            $lat[] = $ms;
            fputcsv($fh, [$id, $db, $phase, $i, count($rows), sprintf('%.3f', $ms)], ',', '"', '');
        }
    }
    sort($lat);
    $summary[] = sprintf(
        '%-13s %-20s %-7s rows=%-7d p50=%9.3f ms  p95=%9.3f ms',
        $id,
        $db,
        $phase,
        count($rows),
        $lat[14],
        $lat[(int) ceil(0.95 * count($lat)) - 1]
    );
}
fclose($fh);
echo implode(PHP_EOL, $summary), PHP_EOL;
