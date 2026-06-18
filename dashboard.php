<?php
require_once __DIR__ . '/../config/db.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

$userId = getCurrentUserId();
if (!$userId) {
    jsonResponse(['error' => 'Unauthorized'], 401);
}

$summary = [
    'clients' => 0,
    'invoices' => 0,
    'paidInvoices' => 0,
    'totalRevenue' => 0,
    'overdueInvoices' => 0,
    'outstandingAmount' => 0,
    'topClients' => [],
];

$stmt = $pdo->prepare('SELECT COUNT(*) AS count FROM clients WHERE user_id = ?');
$stmt->execute([$userId]);
$summary['clients'] = (int) $stmt->fetch()['count'];

$stmt = $pdo->prepare('SELECT COUNT(*) AS count, IFNULL(SUM(total), 0) AS total FROM invoices WHERE user_id = ?');
$stmt->execute([$userId]);
$invoiceRow = $stmt->fetch();
$summary['invoices'] = (int) $invoiceRow['count'];
$summary['totalRevenue'] = (float) $invoiceRow['total'];

$stmt = $pdo->prepare("SELECT COUNT(*) AS count FROM invoices WHERE status = 'paid' AND user_id = ?");
$stmt->execute([$userId]);
$summary['paidInvoices'] = (int) $stmt->fetch()['count'];

$stmt = $pdo->prepare('SELECT COUNT(*) AS count FROM invoices WHERE user_id = ? AND status != ? AND due_date < ?');
$stmt->execute([$userId, 'paid', date('Y-m-d')]);
$summary['overdueInvoices'] = (int) $stmt->fetch()['count'];

$stmt = $pdo->prepare('SELECT IFNULL(SUM(total), 0) AS outstanding FROM invoices WHERE user_id = ? AND status != ?');
$stmt->execute([$userId, 'paid']);
$summary['outstandingAmount'] = (float) $stmt->fetch()['outstanding'];

$stmt = $pdo->prepare(
    'SELECT c.name, c.company, IFNULL(SUM(i.total), 0) AS total
     FROM invoices i
     JOIN clients c ON c.id = i.client_id
     WHERE i.user_id = ?
     GROUP BY c.id
     ORDER BY total DESC
     LIMIT 3'
);
$stmt->execute([$userId]);
$summary['topClients'] = $stmt->fetchAll();

jsonResponse($summary);
