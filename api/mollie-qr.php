<?php
header('Content-Type: application/json');
require_once 'cors.php';
require_once '../admin/config.php';

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$mollieApiKey = getenv('MOLLIE_API_KEY') ?: '';

if (empty($mollieApiKey)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Mollie API key niet geconfigureerd']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$orderId = intval($data['order_id'] ?? 0);
$amount = floatval($data['amount'] ?? 0);
$reference = trim($data['reference'] ?? '');
$bankId = trim($data['bank_id'] ?? '');

if ($orderId <= 0 || $amount <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Ongeldige bestelling of bedrag']);
    exit;
}

$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$baseUrl = $protocol . '://' . $host;

$mollieIssuers = [
    'rabobank' => 'ideal_RABONL2U',
    'abnamro' => 'ideal_ABNANL2A',
    'ing' => 'ideal_INGBNL2A',
    'triodos' => 'ideal_TRIONL2U',
    'bunq' => 'ideal_BUNQNL2A',
    'knab' => 'ideal_KNABNL2H',
    'asn' => 'ideal_ASNBNL21',
    'sns' => 'ideal_SNSBNL2A',
    'regiobank' => 'ideal_RBRBNL21',
    'revolut' => 'ideal_REVOLT21',
    'n26' => 'ideal_NTSBDEB1'
];

$paymentData = [
    'amount' => [
        'currency' => 'EUR',
        'value' => number_format($amount, 2, '.', '')
    ],
    'description' => 'Factuur ' . $reference,
    'redirectUrl' => $baseUrl . '/mijn-bestellingen.html?betaald=' . $orderId,
    'webhookUrl' => $baseUrl . '/api/mollie-webhook.php',
    'method' => 'ideal',
    'metadata' => [
        'type' => 'invoice_payment',
        'order_id' => $orderId,
        'reference' => $reference
    ]
];

if ($bankId && isset($mollieIssuers[$bankId])) {
    $paymentData['issuer'] = $mollieIssuers[$bankId];
}

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

if ($httpCode >= 200 && $httpCode < 300 && isset($result['id'])) {
    $paymentId = $result['id'];
    
    try {
        $stmt = $pdo->prepare("UPDATE business_orders SET mollie_payment_id = ? WHERE id = ?");
        $stmt->execute([$paymentId, $orderId]);
    } catch (PDOException $e) {
        error_log("Kon mollie_payment_id niet opslaan: " . $e->getMessage());
    }
    
    $checkoutUrl = $result['_links']['checkout']['href'] ?? null;
    
    if ($checkoutUrl) {
        echo json_encode([
            'success' => true,
            'checkout_url' => $checkoutUrl
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'checkout_url' => $result['_links']['dashboard']['href'] ?? ''
        ]);
    }
} else {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Kon betaling niet aanmaken'
    ]);
}
?>
