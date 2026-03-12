<?php
header('Content-Type: application/json');
require_once '../admin/config.php';

$paymentId = $_GET['payment_id'] ?? '';

if (!$paymentId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Geen payment_id']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT status, amount, donor_name FROM donations WHERE mollie_payment_id = ?");
    $stmt->execute([$paymentId]);
    $donation = $stmt->fetch();
    
    if (!$donation) {
        $mollieApiKey = getenv('MOLLIE_API_KEY') ?: '';
        if ($mollieApiKey) {
            $ch = curl_init("https://api.mollie.com/v2/payments/$paymentId");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $mollieApiKey
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200) {
                $payment = json_decode($response, true);
                echo json_encode([
                    'success' => true,
                    'status' => $payment['status'] ?? 'unknown'
                ]);
                exit;
            }
        }
        
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Donatie niet gevonden']);
        exit;
    }
    
    echo json_encode([
        'success' => true,
        'status' => $donation['status'],
        'amount' => $donation['amount'],
        'name' => $donation['donor_name']
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database fout']);
}
?>
