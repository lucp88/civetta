<?php
header('Content-Type: application/json');

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
