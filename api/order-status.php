<?php
require_once '../admin/config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$orderId = $_GET['order_id'] ?? '';

if (!$orderId || !is_numeric($orderId)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Geen geldige order_id']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT id, status, mollie_status, total_amount, delivery_date, 
               eboekhouden_pdf_url, eboekhouden_factuurnummer
        FROM business_orders 
        WHERE id = ?
    ");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    
    if (!$order) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Order niet gevonden']);
        exit;
    }
    
    $isPaid = ($order['status'] === 'paid' || $order['mollie_status'] === 'paid');
    $isFailed = ($order['status'] === 'cancelled' || in_array($order['mollie_status'], ['failed', 'canceled', 'expired']));
    
    $displayStatus = 'pending';
    if ($isPaid) {
        $displayStatus = 'paid';
    } elseif ($isFailed) {
        $displayStatus = 'failed';
    }
    
    $response = [
        'success' => true,
        'order' => [
            'id' => $order['id'],
            'status' => $order['status'],
            'mollie_status' => $order['mollie_status'],
            'display_status' => $displayStatus,
            'total_amount' => $order['total_amount'],
            'delivery_date' => $order['delivery_date']
        ]
    ];
    
    if ($isPaid && !empty($order['eboekhouden_pdf_url'])) {
        $response['order']['factuur_url'] = $order['eboekhouden_pdf_url'];
        $response['order']['factuur_nummer'] = $order['eboekhouden_factuurnummer'];
    }
    
    echo json_encode($response);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database fout']);
}
