<?php
header('Content-Type: application/json');
require_once '../admin/config.php';

function donationLog($message) {
    $logFile = __DIR__ . '/webhook-debug.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] [Donation] $message\n", FILE_APPEND);
}

$mollieApiKey = getenv('MOLLIE_API_KEY') ?: '';

if (empty($mollieApiKey)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Mollie API key niet geconfigureerd']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$amount = floatval($data['amount'] ?? 0);
$name = trim($data['name'] ?? '');
$message = trim($data['message'] ?? '');

if ($amount < 1) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Minimaal bedrag is €1']);
    exit;
}

$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$baseUrl = $protocol . '://' . $host;

$metadata = ['type' => 'crowdfunding'];
if ($name) {
    $metadata['name'] = $name;
}
if ($message) {
    $metadata['message'] = $message;
}

$paymentData = [
    'amount' => [
        'currency' => 'EUR',
        'value' => number_format($amount, 2, '.', '')
    ],
    'description' => 'Crowdfunding Bakkerij Civetta',
    'redirectUrl' => $baseUrl . '/bedankt-donatie.html',
    'webhookUrl' => $baseUrl . '/api/mollie-webhook.php',
    'metadata' => $metadata
];

$ch = curl_init('https://api.mollie.com/v2/payments');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($paymentData),
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $mollieApiKey,
        'Content-Type: application/json'
    ]
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$result = json_decode($response, true);

if ($httpCode >= 200 && $httpCode < 300 && isset($result['_links']['checkout']['href'])) {
    $paymentId = $result['id'];
    
    try {
        $stmt = $pdo->prepare("INSERT INTO donations (mollie_payment_id, amount, donor_name, message, status) VALUES (?, ?, ?, ?, 'pending')");
        $stmt->execute([$paymentId, $amount, $name ?: null, $message ?: null]);
        donationLog("Donatie aangemaakt in database: $paymentId, bedrag: $amount");
    } catch (PDOException $e) {
        donationLog("ERROR: Kon donatie niet opslaan in database: " . $e->getMessage() . " | Payment ID: $paymentId");
    }
    
    $paymentData['redirectUrl'] = $baseUrl . '/bedankt-donatie.html?payment_id=' . $paymentId;
    
    $ch = curl_init('https://api.mollie.com/v2/payments/' . $paymentId);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'PATCH',
        CURLOPT_POSTFIELDS => json_encode(['redirectUrl' => $paymentData['redirectUrl']]),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $mollieApiKey,
            'Content-Type: application/json'
        ]
    ]);
    curl_exec($ch);
    curl_close($ch);
    
    echo json_encode([
        'success' => true,
        'checkoutUrl' => $result['_links']['checkout']['href']
    ]);
} else {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Kon betaling niet aanmaken'
    ]);
}
?>
