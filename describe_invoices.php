<?php
require_once __DIR__ . '/../backend/config/db.php';
$tbl = 'invoices';
$stmt = $pdo->query("DESCRIBE $tbl");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $row) {
    echo $row['Field'] . ' | ' . $row['Type'] . ' | ' . $row['Null'] . ' | ' . $row['Key'] . "\n";
}
