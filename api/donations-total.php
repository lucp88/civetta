<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once '../admin/config.php';

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
