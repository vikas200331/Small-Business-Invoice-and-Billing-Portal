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

    if ($id) {
        $stmt = $pdo->prepare('SELECT id, name, email, company, phone FROM clients WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);
        $client = $stmt->fetch();

        if (!$client) {
            jsonResponse(['error' => 'Client not found'], 404);
        }

        jsonResponse($client);
    }

    $stmt = $pdo->prepare('SELECT id, name, email, company, phone FROM clients WHERE user_id = ? ORDER BY name');
    $stmt->execute([$userId]);
    $clients = $stmt->fetchAll();
    jsonResponse($clients);
}

if ($method === 'POST') {
    $name = trim($input['name'] ?? '');
    $email = trim($input['email'] ?? '');
    $company = trim($input['company'] ?? '');
    $phone = trim($input['phone'] ?? '');

    if (!$name || !$email) {
        jsonResponse(['error' => 'Name and email are required'], 422);
    }

    try {
        $stmt = $pdo->prepare('INSERT INTO clients (user_id, name, email, company, phone) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$userId, $name, $email, $company, $phone]);
        jsonResponse(['message' => 'Client added successfully', 'id' => $pdo->lastInsertId()], 201);
    } catch (PDOException $e) {
        jsonResponse(['error' => 'Database error while adding client'], 500);
    }
}

if ($method === 'PUT') {
    $id = $_GET['id'] ?? $input['id'] ?? null;
    $name = trim($input['name'] ?? '');
    $email = trim($input['email'] ?? '');
    $company = trim($input['company'] ?? '');
    $phone = trim($input['phone'] ?? '');

    if (!$id || !$name || !$email) {
        jsonResponse(['error' => 'Client id, name, and email are required'], 422);
    }

    $stmt = $pdo->prepare('SELECT id FROM clients WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $userId]);
    if (!$stmt->fetch()) {
        jsonResponse(['error' => 'Client not found'], 404);
    }

    try {
        $stmt = $pdo->prepare('UPDATE clients SET name = ?, email = ?, company = ?, phone = ? WHERE id = ? AND user_id = ?');
        $stmt->execute([$name, $email, $company, $phone, $id, $userId]);
        jsonResponse(['message' => 'Client updated successfully']);
    } catch (PDOException $e) {
        jsonResponse(['error' => 'Database error while updating client'], 500);
    }
}

if ($method === 'DELETE') {
    $id = $_GET['id'] ?? $input['id'] ?? null;

    if (!$id) {
        jsonResponse(['error' => 'Client id is required'], 422);
    }

    $stmt = $pdo->prepare('SELECT id FROM clients WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $userId]);
    if (!$stmt->fetch()) {
        jsonResponse(['error' => 'Client not found'], 404);
    }

    try {
        $stmt = $pdo->prepare('DELETE FROM clients WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);
        jsonResponse(['message' => 'Client deleted successfully']);
    } catch (PDOException $e) {
        jsonResponse(['error' => 'Database error while deleting client'], 500);
    }
}

jsonResponse(['error' => 'Method not allowed'], 405);
