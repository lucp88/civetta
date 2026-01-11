<?php
require_once '../admin/config.php';

$mollieApiKey = getenv('MOLLIE_API_KEY') ?: '';

if (empty($mollieApiKey)) {
    http_response_code(500);
    exit;
}

$input = file_get_contents('php://input');
parse_str($input, $data);
$paymentId = $data['id'] ?? '';

if (empty($paymentId)) {
    http_response_code(400);
    exit;
}

$ch = curl_init('https://api.mollie.com/v2/payments/' . $paymentId);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $mollieApiKey
    ]
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    http_response_code(500);
    exit;
}

$payment = json_decode($response, true);

if (!$payment || !isset($payment['status'])) {
    http_response_code(500);
    exit;
}

$status = $payment['status'];
$amount = floatval($payment['amount']['value']);
$donorName = $payment['metadata']['name'] ?? null;
$message = $payment['metadata']['message'] ?? null;

$stmt = $pdo->prepare("SELECT id FROM donations WHERE mollie_payment_id = ?");
$stmt->execute([$paymentId]);
$existing = $stmt->fetch();

if ($existing) {
    $stmt = $pdo->prepare("UPDATE donations SET status = ?, paid_at = ? WHERE mollie_payment_id = ?");
    $paidAt = ($status === 'paid') ? date('Y-m-d H:i:s') : null;
    $stmt->execute([$status, $paidAt, $paymentId]);
} else {
    $stmt = $pdo->prepare("INSERT INTO donations (mollie_payment_id, amount, donor_name, message, status, paid_at) VALUES (?, ?, ?, ?, ?, ?)");
    $paidAt = ($status === 'paid') ? date('Y-m-d H:i:s') : null;
    $stmt->execute([$paymentId, $amount, $donorName, $message, $status, $paidAt]);
}

http_response_code(200);
