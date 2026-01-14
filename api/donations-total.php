<?php
require_once '../admin/config.php';
require_once 'cors.php';

header('Content-Type: application/json');
setCorsHeaders();

try {
    $stmt = $pdo->query("SELECT COALESCE(SUM(amount), 0) as total FROM donations WHERE status = 'paid'");
    $result = $stmt->fetch();
    echo json_encode([
        'success' => true,
        'total' => floatval($result['total'])
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'total' => 0]);
}
