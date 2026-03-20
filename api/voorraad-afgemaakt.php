<?php
require_once '../admin/config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Niet ingelogd']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $pdo->query("SELECT * FROM finished_products ORDER BY location, production_date DESC, id DESC");
    echo json_encode(['success' => true, 'items' => $stmt->fetchAll()]);
    exit;
}

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $action = $data['action'] ?? '';

    if ($action === 'create') {
        $name = trim($data['product_name'] ?? '');
        $qty = floatval($data['quantity'] ?? 1);
        $unit = trim($data['unit'] ?? 'stuks');
        $location = $data['location'] ?? 'kast';
        $notes = trim($data['notes'] ?? '');
        $date = $data['production_date'] ?? date('Y-m-d');

        if (!$name || !in_array($location, ['kast', 'koelkast', 'vriezer'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Ongeldige gegevens']);
            exit;
        }

        $stmt = $pdo->prepare("INSERT INTO finished_products (product_name, quantity, unit, location, status, notes, production_date) VALUES (?, ?, ?, ?, 'beschikbaar', ?, ?)");
        $stmt->execute([$name, $qty, $unit, $location, $notes ?: null, $date]);
        echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
    } elseif ($action === 'delete') {
        $id = intval($data['id'] ?? 0);
        if (!$id) { http_response_code(400); echo json_encode(['success' => false, 'error' => 'Geen id']); exit; }
        $stmt = $pdo->prepare("DELETE FROM finished_products WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['success' => true]);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Onbekende actie']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed']);
