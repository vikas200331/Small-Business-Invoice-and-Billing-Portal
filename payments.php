<?php
require_once __DIR__ . '/../config/db.php';

$method = $_SERVER['REQUEST_METHOD'];
$userId = getCurrentUserId();
$input = json_decode(file_get_contents('php://input'), true) ?: [];

if (!$userId) {
    jsonResponse(['error' => 'Unauthorized'], 401);
}

if ($method === 'GET') {
    $invoiceId = $_GET['invoice_id'] ?? null;

    if ($invoiceId) {
        $validate = $pdo->prepare('SELECT id FROM invoices WHERE id = ? AND user_id = ?');
        $validate->execute([$invoiceId, $userId]);
        if (!$validate->fetch()) {
            jsonResponse(['error' => 'Invoice not found'], 404);
        }

        $stmt = $pdo->prepare('SELECT id, invoice_id, amount, payment_date AS paid_at FROM payments WHERE invoice_id = ? ORDER BY payment_date DESC');
        $stmt->execute([$invoiceId]);
        $payments = $stmt->fetchAll();
        jsonResponse($payments);
    }

    $stmt = $pdo->prepare(
        'SELECT p.id, p.invoice_id, p.amount, p.payment_date AS paid_at
         FROM payments p
         JOIN invoices i ON i.id = p.invoice_id
         WHERE i.user_id = ?
         ORDER BY p.payment_date DESC'
    );
    $stmt->execute([$userId]);
    $payments = $stmt->fetchAll();
    jsonResponse($payments);
}

if ($method === 'POST') {
    $invoice_id = $input['invoice_id'] ?? null;
    $amount = $input['amount'] ?? null;
    $paid_at = trim($input['paid_at'] ?? date('Y-m-d'));

    if (!$invoice_id || $amount === null) {
        jsonResponse(['error' => 'Invoice and amount are required'], 422);
    }

    $amountValue = floatval($amount);
    if ($amountValue <= 0) {
        jsonResponse(['error' => 'Payment amount must be greater than zero'], 422);
    }

    $stmt = $pdo->prepare('SELECT id, total, status FROM invoices WHERE id = ? AND user_id = ?');
    $stmt->execute([$invoice_id, $userId]);
    $invoice = $stmt->fetch();

    if (!$invoice) {
        jsonResponse(['error' => 'Invoice not found'], 404);
    }

    $paymentSumStmt = $pdo->prepare('SELECT IFNULL(SUM(amount), 0) AS total_paid FROM payments WHERE invoice_id = ?');
    $paymentSumStmt->execute([$invoice_id]);
    $paidTotal = (float) $paymentSumStmt->fetch()['total_paid'];
    $remainingBalance = max(0, floatval($invoice['total']) - $paidTotal);

    if ($amountValue > $remainingBalance) {
        jsonResponse(['error' => 'Payment exceeds the remaining balance'], 422);
    }

    try {
        $stmt = $pdo->prepare('INSERT INTO payments (invoice_id, amount, payment_date) VALUES (?, ?, ?)');
        $stmt->execute([$invoice_id, $amountValue, $paid_at]);

        $paymentSumStmt->execute([$invoice_id]);
        $paidTotal = (float) $paymentSumStmt->fetch()['total_paid'];

        if ($paidTotal >= floatval($invoice['total'])) {
            $updateStmt = $pdo->prepare('UPDATE invoices SET status = ? WHERE id = ? AND user_id = ?');
            $updateStmt->execute(['paid', $invoice_id, $userId]);
        }

        jsonResponse(['message' => 'Payment recorded', 'id' => $pdo->lastInsertId()], 201);
    } catch (PDOException $e) {
        jsonResponse(['error' => 'Unable to record payment'], 500);
    }
}

jsonResponse(['error' => 'Method not allowed'], 405);
