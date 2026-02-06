<?php
require_once '../admin/config.php';
require_once __DIR__ . '/../lib/factuur/functions.php';
require_once __DIR__ . '/email-templates.php';
require_once 'eboekhouden.php';

function webhookLog($message) {
    $logFile = __DIR__ . '/webhook-debug.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
    error_log($message);
}

webhookLog("=== Mollie Webhook Called ===");

$mollieApiKey = getenv('MOLLIE_API_KEY') ?: '';

if (!$mollieApiKey) {
    webhookLog("ERROR: Geen MOLLIE_API_KEY");
    http_response_code(500);
    exit;
}
webhookLog("Mollie API key gevonden");

$paymentId = $_POST['id'] ?? '';
webhookLog("Payment ID: $paymentId");

if (!$paymentId) {
    webhookLog("ERROR: Geen payment ID");
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
$status = $payment['status'] ?? '';

if (isset($payment['metadata']['type']) && $payment['metadata']['type'] === 'crowdfunding') {
    webhookLog("Donation payment - Status: $status");
    try {
        $stmt = $pdo->prepare("SELECT id FROM donations WHERE mollie_payment_id = ?");
        $stmt->execute([$paymentId]);
        $exists = $stmt->fetch();
        
        if (!$exists) {
            $amount = $payment['amount']['value'] ?? 0;
            $name = $payment['metadata']['name'] ?? null;
            $message = $payment['metadata']['message'] ?? null;
            
            $stmt = $pdo->prepare("INSERT INTO donations (mollie_payment_id, amount, donor_name, message, status) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$paymentId, $amount, $name, $message, $status]);
            webhookLog("Donation record aangemaakt via webhook (was niet in DB): $paymentId, bedrag: $amount");
        } else {
            $stmt = $pdo->prepare("UPDATE donations SET status = ? WHERE mollie_payment_id = ?");
            $stmt->execute([$status, $paymentId]);
            webhookLog("Donation status updated: $paymentId -> $status");
        }
        
        if ($status === 'paid') {
            $stmt = $pdo->prepare("UPDATE donations SET paid_at = NOW() WHERE mollie_payment_id = ?");
            $stmt->execute([$paymentId]);
            webhookLog("Donation marked as paid: $paymentId");
        }
        
        http_response_code(200);
    } catch (PDOException $e) {
        webhookLog("Donation error: " . $e->getMessage());
        http_response_code(500);
    }
    exit;
}

if (isset($payment['metadata']['type']) && $payment['metadata']['type'] === 'invoice_payment') {
    $orderId = $payment['metadata']['order_id'] ?? 0;
    webhookLog("Invoice payment - Order ID: $orderId, Status: $status");
    
    if (!$orderId) {
        webhookLog("ERROR: Geen order_id in invoice_payment metadata");
        http_response_code(400);
        exit;
    }
    
    try {
        if ($status === 'paid') {
            $stmt = $pdo->prepare("UPDATE business_orders SET payment_status = 'paid', mollie_payment_id = ?, mollie_status = ?, mollie_status_updated_at = NOW() WHERE id = ?");
            $stmt->execute([$paymentId, $status, $orderId]);
            webhookLog("Invoice payment marked as paid for order $orderId");
        }
        http_response_code(200);
    } catch (PDOException $e) {
        webhookLog("Invoice payment error: " . $e->getMessage());
        http_response_code(500);
    }
    exit;
}

if (!$payment || !isset($payment['metadata']['order_id'])) {
    http_response_code(400);
    exit;
}

$orderId = $payment['metadata']['order_id'];
webhookLog("Order ID: $orderId, Status: $status");

try {
    $stmt = $pdo->prepare("UPDATE business_orders SET mollie_status = ?, mollie_status_updated_at = NOW() WHERE id = ? AND mollie_payment_id = ?");
    $stmt->execute([$status, $orderId, $paymentId]);

    if ($status === 'paid') {
        $stmt = $pdo->prepare("UPDATE business_orders SET payment_status = 'paid', invoice_status = 'gefactureerd', delivery_status = 'geplaatst' WHERE id = ? AND mollie_payment_id = ?");
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
            
            $bedrijf = getBedrijfsGegevens($pdo);

            $stmt2 = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'btw_tarief'");
            $btwTarief = floatval($stmt2->fetchColumn() ?: 9);

            $order['payment_status'] = 'paid';
            $htmlBody = buildOrderConfirmationEmail($order, $items, $bedrijf, $btwTarief);

            $bestelbonNummer = $order['bestelbon_number'] ?? 'B' . date('Y') . '-' . str_pad($orderId, 4, '0', STR_PAD_LEFT);
            $subject = "Betalingsbevestiging $bestelbonNummer - Bakkerij Civetta";

            sendHtmlEmail($order['email'], $subject, $htmlBody, [], 'laurens@bakkerij-civetta.nl');
            
            $eboekhoudenSettings = getEBoekhoudenSettings($pdo);
            
            webhookLog("e-Boekhouden settings voor order $orderId: " . json_encode($eboekhoudenSettings));
            
            if ($eboekhoudenSettings['facturatie_systeem'] === 'eboekhouden' && $eboekhoudenSettings['eboekhouden_api_token']) {
                webhookLog("e-Boekhouden integratie actief - start factuur aanmaken");
                try {
                    $stmt = $pdo->prepare("
                        SELECT ba.*, bo.delivery_date FROM business_accounts ba 
                        JOIN business_orders bo ON bo.account_id = ba.id 
                        WHERE bo.id = ?
                    ");
                    $stmt->execute([$orderId]);
                    $accountData = $stmt->fetch();
                    $accountData['btw_tarief'] = floatval($eboekhoudenSettings['btw_tarief'] ?? 9);
                    
                    $client = new EBoekhoudenClient($eboekhoudenSettings['eboekhouden_api_token']);
                    
                    $btwCode = 'LAAG_VERK_9';
                    if (floatval($eboekhoudenSettings['btw_tarief']) == 21) {
                        $btwCode = 'HOOG_VERK_21';
                    }
                    
                    $templateId = $eboekhoudenSettings['eboekhouden_template_id_betaald'];
                    
                    $result = $client->createFullInvoice(
                        $accountData,
                        $items,
                        $templateId,
                        $eboekhoudenSettings['eboekhouden_ledger_id'],
                        $btwCode,
                        true,
                        $eboekhoudenSettings['eboekhouden_debiteuren_ledger_id'] ?? null
                    );
                    
                    $stmt = $pdo->prepare("
                        UPDATE business_orders 
                        SET eboekhouden_invoice_id = ?, 
                            eboekhouden_factuurnummer = ?, 
                            eboekhouden_pdf_url = ?,
                            facturatie_systeem = 'eboekhouden',
                            invoice_status = 'gefactureerd'
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $result['id'],
                        $result['invoiceNumber'],
                        $result['pdfUrl'],
                        $orderId
                    ]);
                    
                webhookLog("e-Boekhouden factuur succesvol aangemaakt voor order $orderId: " . json_encode($result));
                    
                } catch (Exception $e) {
                    webhookLog("e-Boekhouden factuur fout voor order $orderId: " . $e->getMessage());
                    sendFactuurEmail($pdo, $orderId);
                    $stmt = $pdo->prepare("UPDATE business_orders SET facturatie_systeem = 'eigen' WHERE id = ?");
                    $stmt->execute([$orderId]);
                }
            } else {
                webhookLog("Eigen facturatie gebruikt voor order $orderId (systeem: {$eboekhoudenSettings['facturatie_systeem']}, token: " . (!empty($eboekhoudenSettings['eboekhouden_api_token']) ? 'aanwezig' : 'leeg') . ")");
                sendFactuurEmail($pdo, $orderId);
                $stmt = $pdo->prepare("UPDATE business_orders SET facturatie_systeem = 'eigen' WHERE id = ?");
                $stmt->execute([$orderId]);
            }
            
            $adminSubject = "Betaling ontvangen - Bestelling #$orderId";
            $adminBody = "Betaling ontvangen voor bestelling #$orderId van {$order['bedrijfsnaam']}.\n\n";
            $adminBody .= "Totaalbedrag: €" . number_format($order['total_amount'], 2, ',', '.') . "\n";
            $adminBody .= "Leverdatum: " . date('d-m-Y', strtotime($order['delivery_date'])) . "\n\n";
            $adminBody .= "De klant is per e-mail op de hoogte gesteld.";
            
            @mail("laurens@bakkerij-civetta.nl", $adminSubject, $adminBody, $headers);
        }
    } elseif (in_array($status, ['failed', 'canceled', 'expired'])) {
        $stmt = $pdo->prepare("UPDATE business_orders SET is_cancelled = 1 WHERE id = ? AND mollie_payment_id = ?");
        $stmt->execute([$orderId, $paymentId]);
    }
    
    http_response_code(200);
} catch (PDOException $e) {
    http_response_code(500);
}
?>
