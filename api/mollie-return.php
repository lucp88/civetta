<?php
session_start();
require_once '../admin/config.php';

$orderId = $_GET['order_id'] ?? '';

if (!$orderId) {
    header('Location: ../zakelijk-dashboard.html');
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT status, mollie_status FROM business_orders WHERE id = ?");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    
    if ($order) {
        $isPaid = ($order['status'] === 'paid' || $order['mollie_status'] === 'paid');
        $isCancelled = ($order['status'] === 'cancelled' || in_array($order['mollie_status'], ['failed', 'canceled', 'expired']));
        
        if ($isPaid) {
            header("Location: ../bedankt-bestelling.html?status=paid&order_id=$orderId");
        } elseif ($isCancelled) {
            header("Location: ../bedankt-bestelling.html?status=failed&order_id=$orderId");
        } else {
            header("Location: ../bedankt-bestelling.html?status=pending&order_id=$orderId");
        }
    } else {
        header('Location: ../zakelijk-dashboard.html');
    }
} catch (PDOException $e) {
    header('Location: ../zakelijk-dashboard.html');
}
?>
