<?php
session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once '../admin/config.php';

if (!isset($_SESSION['business_logged_in']) || !$_SESSION['business_logged_in']) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Niet ingelogd']);
    exit;
}

$accountId = $_SESSION['business_account_id'];
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        try {
            $stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'btw_tarief'");
            $btwTarief = floatval($stmt->fetchColumn() ?: 9);
            
            $stmt = $pdo->prepare("
                SELECT id, delivery_date, status, total_amount, notes, created_at, mollie_payment_id, mollie_status 
                FROM business_orders 
                WHERE account_id = ? 
                ORDER BY created_at DESC
            ");
            $stmt->execute([$accountId]);
            $orders = $stmt->fetchAll();
            
            foreach ($orders as &$order) {
                $stmt = $pdo->prepare("
                    SELECT boi.product_name, boi.quantity, boi.unit_price, 
                           (boi.quantity * boi.unit_price) as subtotal,
                           p.foto as product_image
                    FROM business_order_items boi
                    LEFT JOIN products p ON LOWER(p.naam) = LOWER(boi.product_name)
                    WHERE boi.order_id = ?
                ");
                $stmt->execute([$order['id']]);
                $order['items'] = $stmt->fetchAll();
                
                $totalInclBtw = floatval($order['total_amount']);
                $btwBedrag = $totalInclBtw - ($totalInclBtw / (1 + $btwTarief / 100));
                $order['btw_tarief'] = $btwTarief;
                $order['btw_bedrag'] = round($btwBedrag, 2);
                $order['excl_btw'] = round($totalInclBtw - $btwBedrag, 2);
                
                if ($order['status'] === 'pending' && $order['mollie_payment_id']) {
                    $mollieApiKey = getenv('MOLLIE_API_KEY') ?: '';
                    if ($mollieApiKey) {
                        $ch = curl_init("https://api.mollie.com/v2/payments/{$order['mollie_payment_id']}");
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $mollieApiKey]);
                        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
                        $response = curl_exec($ch);
                        curl_close($ch);
                        $payment = json_decode($response, true);
                        if (isset($payment['_links']['checkout']['href'])) {
                            $order['payment_url'] = $payment['_links']['checkout']['href'];
                        }
                    }
                }
            }
            unset($order);
            
            echo json_encode(['success' => true, 'orders' => $orders, 'btw_tarief' => $btwTarief]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Database fout']);
        }
        break;
        
    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        
        $items = $data['items'] ?? [];
        $deliveryDate = $data['delivery_date'] ?? '';
        $notes = trim($data['notes'] ?? '');
        $totalAmount = $data['total_amount'] ?? 0;
        
        if (empty($items) || !$deliveryDate) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Selecteer minimaal één product en een leverdatum']);
            exit;
        }
        
        $minDate = date('Y-m-d', strtotime('+2 days'));
        if ($deliveryDate < $minDate) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Leverdatum moet minimaal 2 dagen in de toekomst liggen']);
            exit;
        }
        
        try {
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("
                INSERT INTO business_orders (account_id, delivery_date, status, total_amount, notes, created_at)
                VALUES (?, ?, 'pending', ?, ?, NOW())
            ");
            $stmt->execute([$accountId, $deliveryDate, $totalAmount, $notes]);
            $orderId = $pdo->lastInsertId();
            
            $stmt = $pdo->prepare("
                INSERT INTO business_order_items (order_id, product_name, quantity, unit_price)
                VALUES (?, ?, ?, ?)
            ");
            
            foreach ($items as $item) {
                $stmt->execute([
                    $orderId,
                    $item['product_name'],
                    $item['quantity'],
                    $item['unit_price']
                ]);
            }
            
            $pdo->commit();
            
            $stmt = $pdo->prepare("SELECT bedrijfsnaam, contactpersoon, email FROM business_accounts WHERE id = ?");
            $stmt->execute([$accountId]);
            $account = $stmt->fetch();
            
            $itemsList = "";
            foreach ($items as $item) {
                $itemsList .= "- {$item['quantity']}x {$item['product_name']} (€" . number_format($item['unit_price'], 2, ',', '.') . " p/st)\n";
            }
            
            $to = "laurens@bakkerij-civetta.nl";
            $subject = "Nieuwe bestelling van {$account['bedrijfsnaam']} (#$orderId)";
            $body = "Er is een nieuwe bestelling geplaatst!\n\n";
            $body .= "Bestelling #$orderId\n";
            $body .= "Bedrijf: {$account['bedrijfsnaam']}\n";
            $body .= "Contactpersoon: {$account['contactpersoon']}\n";
            $body .= "E-mail: {$account['email']}\n\n";
            $body .= "Gewenste leverdatum: " . date('d-m-Y', strtotime($deliveryDate)) . "\n\n";
            $body .= "Producten:\n$itemsList\n";
            $body .= "Totaalbedrag: €" . number_format($totalAmount, 2, ',', '.') . "\n\n";
            if ($notes) {
                $body .= "Opmerkingen: $notes\n\n";
            }
            $body .= "Status: In afwachting van betaling\n";
            $body .= "\nBekijk de bestelling in het admin panel.";
            
            $headers = "From: noreply@bakkerij-civetta.nl\r\n";
            $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
            
            @mail($to, $subject, $body, $headers);
            
            $mollieApiKey = getenv('MOLLIE_API_KEY') ?: '';
            
            if ($mollieApiKey && $totalAmount > 0) {
                $mollieData = [
                    'amount' => [
                        'currency' => 'EUR',
                        'value' => number_format($totalAmount, 2, '.', '')
                    ],
                    'description' => "Bakkerij Civetta - Bestelling #$orderId",
                    'redirectUrl' => "https://bakkerij-civetta.nl/api/mollie-return.php?order_id=$orderId",
                    'webhookUrl' => "https://bakkerij-civetta.nl/api/mollie-webhook.php",
                    'metadata' => [
                        'order_id' => $orderId
                    ]
                ];
                
                $ch = curl_init('https://api.mollie.com/v2/payments');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($mollieData));
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Authorization: Bearer ' . $mollieApiKey,
                    'Content-Type: application/json'
                ]);
                
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                
                if ($httpCode >= 200 && $httpCode < 300) {
                    $mollieResponse = json_decode($response, true);
                    
                    if (isset($mollieResponse['id'])) {
                        $stmt = $pdo->prepare("UPDATE business_orders SET mollie_payment_id = ? WHERE id = ?");
                        $stmt->execute([$mollieResponse['id'], $orderId]);
                    }
                    
                    if (isset($mollieResponse['_links']['checkout']['href'])) {
                        echo json_encode([
                            'success' => true, 
                            'order_id' => $orderId,
                            'payment_url' => $mollieResponse['_links']['checkout']['href']
                        ]);
                        exit;
                    }
                }
            }
            
            echo json_encode([
                'success' => true, 
                'order_id' => $orderId,
                'message' => 'Bestelling geplaatst. U ontvangt een bevestiging per e-mail.'
            ]);
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Database fout bij plaatsen bestelling']);
        }
        break;
        
    default:
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
}
?>
