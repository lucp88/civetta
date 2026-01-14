<?php
require_once '../admin/config.php';
require_once 'factuur.php';

$mollieApiKey = getenv('MOLLIE_API_KEY') ?: '';

if (!$mollieApiKey) {
    http_response_code(500);
    exit;
}

$paymentId = $_POST['id'] ?? '';

if (!$paymentId) {
    http_response_code(400);
    exit;
}

$ch = curl_init("https://api.mollie.com/v2/payments/$paymentId");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $mollieApiKey
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    http_response_code(500);
    exit;
}

$payment = json_decode($response, true);

if (!$payment || !isset($payment['metadata']['order_id'])) {
    http_response_code(400);
    exit;
}

$orderId = $payment['metadata']['order_id'];
$status = $payment['status'];

try {
    $stmt = $pdo->prepare("UPDATE business_orders SET mollie_status = ?, mollie_status_updated_at = NOW() WHERE id = ? AND mollie_payment_id = ?");
    $stmt->execute([$status, $orderId, $paymentId]);

    if ($status === 'paid') {
        $stmt = $pdo->prepare("UPDATE business_orders SET status = 'paid' WHERE id = ? AND mollie_payment_id = ?");
        $stmt->execute([$orderId, $paymentId]);
        
        $stmt = $pdo->prepare("
            SELECT bo.*, ba.bedrijfsnaam, ba.contactpersoon, ba.email 
            FROM business_orders bo 
            JOIN business_accounts ba ON bo.account_id = ba.id 
            WHERE bo.id = ?
        ");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();
        
        if ($order) {
            $stmt = $pdo->prepare("SELECT product_name, quantity, unit_price FROM business_order_items WHERE order_id = ?");
            $stmt->execute([$orderId]);
            $items = $stmt->fetchAll();
            
            $itemsList = "";
            foreach ($items as $item) {
                $itemsList .= "- {$item['quantity']}x {$item['product_name']}\n";
            }
            
            $to = $order['email'];
            $subject = "Bevestiging bestelling #$orderId - Bakkerij Civetta";
            $body = "Beste {$order['contactpersoon']},\n\n";
            $body .= "Bedankt voor uw bestelling! Uw betaling is succesvol ontvangen.\n\n";
            $body .= "Bestelling #$orderId\n";
            $body .= "Bedrijf: {$order['bedrijfsnaam']}\n\n";
            $body .= "Gewenste leverdatum: " . date('d-m-Y', strtotime($order['delivery_date'])) . "\n\n";
            $body .= "Producten:\n$itemsList\n";
            $body .= "Totaalbedrag: €" . number_format($order['total_amount'], 2, ',', '.') . "\n\n";
            $body .= "We nemen contact met u op om de levering te bevestigen.\n\n";
            $body .= "Met vriendelijke groet,\n";
            $body .= "Bakkerij Civetta\n";
            $body .= "laurens@bakkerij-civetta.nl";
            
            $headers = "From: noreply@bakkerij-civetta.nl\r\n";
            $headers .= "Reply-To: laurens@bakkerij-civetta.nl\r\n";
            $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
            
            @mail($to, $subject, $body, $headers);
            
            sendFactuurEmail($pdo, $orderId);
            
            $adminSubject = "Betaling ontvangen - Bestelling #$orderId";
            $adminBody = "Betaling ontvangen voor bestelling #$orderId van {$order['bedrijfsnaam']}.\n\n";
            $adminBody .= "Totaalbedrag: €" . number_format($order['total_amount'], 2, ',', '.') . "\n";
            $adminBody .= "Leverdatum: " . date('d-m-Y', strtotime($order['delivery_date'])) . "\n\n";
            $adminBody .= "De klant is per e-mail op de hoogte gesteld.";
            
            @mail("laurens@bakkerij-civetta.nl", $adminSubject, $adminBody, $headers);
        }
    } elseif (in_array($status, ['failed', 'canceled', 'expired'])) {
        $stmt = $pdo->prepare("UPDATE business_orders SET status = 'cancelled' WHERE id = ? AND mollie_payment_id = ?");
        $stmt->execute([$orderId, $paymentId]);
    }
    
    http_response_code(200);
} catch (PDOException $e) {
    http_response_code(500);
}
?>
