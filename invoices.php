<?php
require_once __DIR__ . '/../config/db.php';

$method = $_SERVER['REQUEST_METHOD'];
$userId = getCurrentUserId();
$input = json_decode(file_get_contents('php://input'), true) ?: [];

if (!$userId) {
    jsonResponse(['error' => 'Unauthorized'], 401);
}

if ($method === 'GET') {
    $id = $_GET['id'] ?? null;
    $status = trim($_GET['status'] ?? '');
    $query = trim($_GET['query'] ?? '');

    $normalizeInvoiceStatus = function (array $invoice): array {
        $invoice['overdue'] = $invoice['status'] !== 'paid' && $invoice['due_date'] < date('Y-m-d');
        $invoice['status'] = $invoice['overdue'] ? 'past_due' : $invoice['status'];

        return $invoice;
    };

    if ($id) {
        $stmt = $pdo->prepare(
            'SELECT i.*, c.name AS client_name, c.company AS client_company, c.email AS client_email, c.phone AS client_phone
             FROM invoices i
             LEFT JOIN clients c ON c.id = i.client_id
             WHERE i.id = ? AND i.user_id = ?'
        );
        $stmt->execute([$id, $userId]);
        $invoice = $stmt->fetch();

        if (!$invoice) {
            jsonResponse(['error' => 'Invoice not found'], 404);
        }

        $paymentStmt = $pdo->prepare('SELECT IFNULL(SUM(amount), 0) AS paid_total FROM payments WHERE invoice_id = ?');
        $paymentStmt->execute([$id]);
        $paidTotal = (float) $paymentStmt->fetch()['paid_total'];

        $invoice['paid_total'] = $paidTotal;
        $invoice['balance_due'] = max(0, floatval($invoice['total']) - $paidTotal);

        // Fetch associated invoice items
        $itemsStmt = $pdo->prepare('SELECT * FROM invoice_items WHERE invoice_id = ?');
        $itemsStmt->execute([$id]);
        $invoice['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $invoice = $normalizeInvoiceStatus($invoice);

        jsonResponse($invoice);
    }

    $sql = 'SELECT i.*, c.name AS client_name, c.company AS client_company FROM invoices i LEFT JOIN clients c ON c.id = i.client_id WHERE i.user_id = ?';
    $params = [$userId];

    if ($status) {
        if ($status === 'past_due') {
            $sql .= ' AND i.status != ? AND i.due_date < ?';
            $params[] = 'paid';
            $params[] = date('Y-m-d');
        } else {
            $sql .= ' AND i.status = ?';
            $params[] = $status;
        }
    }

    if ($query) {
        $like = '%' . $query . '%';
        $sql .= ' AND (i.invoice_number LIKE ? OR c.name LIKE ? OR c.company LIKE ?)';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $sql .= ' ORDER BY i.created_at DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $invoices = $stmt->fetchAll();

    foreach ($invoices as &$invoice) {
        $invoice = $normalizeInvoiceStatus($invoice);
    }

    jsonResponse($invoices);
}

if ($method === 'POST') {
    $client_id = $input['client_id'] ?? null;
    $status = trim($input['status'] ?? 'draft');
    $due_date = trim($input['due_date'] ?? '');
    $invoice_number = trim($input['invoice_number'] ?? '');
    $items = $input['items'] ?? [];

    if (!$client_id || !$due_date) {
        jsonResponse(['error' => 'Client and due date are required'], 422);
    }

    $stmt = $pdo->prepare('SELECT id FROM clients WHERE id = ? AND user_id = ?');
    $stmt->execute([$client_id, $userId]);
    if (!$stmt->fetch()) {
        jsonResponse(['error' => 'Client not found'], 404);
    }

    $invoiceNumber = $invoice_number ?: 'INV-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    $issueDate = date('Y-m-d');
    $taxRate = 0.00;

    // Calculate subtotal and total from items if provided, otherwise legacy amount
    $subtotal = 0.00;
    $hasItems = is_array($items) && !empty($items);

    if ($hasItems) {
        foreach ($items as $item) {
            $qty = intval($item['quantity'] ?? 0);
            $price = floatval($item['unit_price'] ?? 0);
            $subtotal += ($qty * $price);
        }
    } else {
        $amount = $input['amount'] ?? null;
        if ($amount === null) {
            jsonResponse(['error' => 'Amount or items are required'], 422);
        }
        $subtotal = floatval($amount);
    }
    $total = $subtotal;

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('INSERT INTO invoices (user_id, client_id, invoice_number, issue_date, due_date, status, subtotal, tax_rate, total, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
        $stmt->execute([$userId, $client_id, $invoiceNumber, $issueDate, $due_date, $status, $subtotal, $taxRate, $total]);
        $invoiceId = $pdo->lastInsertId();

        if ($hasItems) {
            $itemStmt = $pdo->prepare('INSERT INTO invoice_items (invoice_id, description, quantity, unit_price, subtotal) VALUES (?, ?, ?, ?, ?)');
            foreach ($items as $item) {
                $desc = trim($item['description'] ?? '');
                $qty = intval($item['quantity'] ?? 0);
                $price = floatval($item['unit_price'] ?? 0);
                $itemSubtotal = $qty * $price;
                $itemStmt->execute([$invoiceId, $desc, $qty, $price, $itemSubtotal]);
            }
        }

        $pdo->commit();
        jsonResponse(['message' => 'Invoice created', 'id' => $invoiceId], 201);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        jsonResponse(['error' => 'Unable to create invoice'], 500);
    }
}

if ($method === 'PUT') {
    $id = $_GET['id'] ?? $input['id'] ?? null;
    $client_id = $input['client_id'] ?? null;
    $amount = $input['amount'] ?? null;
    $status = trim($input['status'] ?? '');
    $due_date = trim($input['due_date'] ?? '');
    $invoice_number = trim($input['invoice_number'] ?? '');

    if (!$id) {
        jsonResponse(['error' => 'Invoice id is required'], 422);
    }

    $stmt = $pdo->prepare('SELECT id FROM invoices WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $userId]);
    if (!$stmt->fetch()) {
        jsonResponse(['error' => 'Invoice not found'], 404);
    }

    $fields = [];
    $params = [];

    if ($client_id !== null) {
        $stmt = $pdo->prepare('SELECT id FROM clients WHERE id = ? AND user_id = ?');
        $stmt->execute([$client_id, $userId]);
        if (!$stmt->fetch()) {
            jsonResponse(['error' => 'Client not found'], 404);
        }
        $fields[] = 'client_id = ?';
        $params[] = $client_id;
    }

    if ($amount !== null) {
        $fields[] = 'subtotal = ?';
        $fields[] = 'total = ?';
        $params[] = floatval($amount);
        $params[] = floatval($amount);
    }

    if ($due_date !== '') {
        $fields[] = 'due_date = ?';
        $params[] = $due_date;
    }

    if ($status !== '') {
        $fields[] = 'status = ?';
        $params[] = $status;
    }

    if ($invoice_number !== '') {
        $fields[] = 'invoice_number = ?';
        $params[] = $invoice_number;
    }

    if (empty($fields)) {
        jsonResponse(['error' => 'No invoice fields provided to update'], 422);
    }

    $params[] = $id;
    $params[] = $userId;
    $sql = 'UPDATE invoices SET ' . implode(', ', $fields) . ' WHERE id = ? AND user_id = ?';

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        jsonResponse(['message' => 'Invoice updated successfully']);
    } catch (PDOException $e) {
        jsonResponse(['error' => 'Unable to update invoice'], 500);
    }
}

if ($method === 'DELETE') {
    $id = $_GET['id'] ?? $input['id'] ?? null;

    if (!$id) {
        jsonResponse(['error' => 'Invoice id is required'], 422);
    }

    $stmt = $pdo->prepare('SELECT id FROM invoices WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $userId]);
    if (!$stmt->fetch()) {
        jsonResponse(['error' => 'Invoice not found'], 404);
    }

    try {
        $stmt = $pdo->prepare('DELETE FROM invoices WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);
        jsonResponse(['message' => 'Invoice deleted successfully']);
    } catch (PDOException $e) {
        jsonResponse(['error' => 'Unable to delete invoice'], 500);
    }
}

jsonResponse(['error' => 'Method not allowed'], 405);
