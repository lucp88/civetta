<?php
require_once '../admin/config.php';

function refundLog($message) {
    $logFile = __DIR__ . '/webhook-debug.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] [REFUND] $message\n", FILE_APPEND);
}

function createMollieRefund($paymentId, $amount, $description) {
    $mollieApiKey = getenv('MOLLIE_API_KEY') ?: '';
    
    refundLog("=== START REFUND ===");
    refundLog("Payment ID: $paymentId, Amount: $amount, Description: $description");
    refundLog("API Key prefix: " . substr($mollieApiKey, 0, 10) . "...");
    
    if (!$mollieApiKey) {
        refundLog("ERROR: Geen MOLLIE_API_KEY");
        return ['success' => false, 'error' => 'Mollie API key niet geconfigureerd'];
    }
    
    if (!$paymentId) {
        refundLog("ERROR: Geen payment ID");
        return ['success' => false, 'error' => 'Geen betaling gevonden'];
    }
    
    $refundData = [
        'amount' => [
            'currency' => 'EUR',
            'value' => number_format($amount, 2, '.', '')
        ],
        'description' => $description
    ];
    
    refundLog("Refund aanmaken voor payment $paymentId: " . json_encode($refundData));
    
    $ch = curl_init("https://api.mollie.com/v2/payments/$paymentId/refunds");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($refundData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $mollieApiKey,
        'Content-Type: application/json'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        refundLog("CURL ERROR: $curlError");
        return ['success' => false, 'error' => 'Verbindingsfout met Mollie'];
    }
    
    $result = json_decode($response, true);
    
    refundLog("Mollie response (HTTP $httpCode): " . $response);
    
    if ($httpCode >= 200 && $httpCode < 300) {
        refundLog("Refund succesvol aangemaakt: " . ($result['id'] ?? 'unknown'));
        return [
            'success' => true,
            'refund_id' => $result['id'] ?? null,
            'status' => $result['status'] ?? 'pending',
            'amount' => $result['amount']['value'] ?? $amount
        ];
    }
    
    $errorMessage = $result['detail'] ?? $result['title'] ?? 'Onbekende fout bij terugbetaling';
    $errorExtra = isset($result['extra']) ? json_encode($result['extra']) : '';
    refundLog("Refund FAILED: $errorMessage - Extra: $errorExtra - Full response: " . json_encode($result));
    
    return ['success' => false, 'error' => $errorMessage];
}

function canRefundOrder($pdo, $orderId) {
    $stmt = $pdo->prepare("
        SELECT mollie_payment_id, payment_status, payment_type, total_amount, is_cancelled 
        FROM business_orders 
        WHERE id = ?
    ");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    
    if (!$order) {
        return ['can_refund' => false, 'reason' => 'Bestelling niet gevonden'];
    }
    
    if (intval($order['is_cancelled']) === 1) {
        return ['can_refund' => false, 'reason' => 'Bestelling is al geannuleerd'];
    }
    
    if ($order['payment_status'] !== 'paid') {
        return ['can_refund' => false, 'reason' => 'Bestelling is niet betaald'];
    }
    
    if (!in_array($order['payment_type'], ['ideal', 'mollie_direct'])) {
        return ['can_refund' => false, 'reason' => 'Alleen iDEAL betalingen kunnen worden terugbetaald'];
    }
    
    if (empty($order['mollie_payment_id'])) {
        return ['can_refund' => false, 'reason' => 'Geen Mollie betaling gevonden'];
    }
    
    return [
        'can_refund' => true,
        'mollie_payment_id' => $order['mollie_payment_id'],
        'amount' => floatval($order['total_amount'])
    ];
}
