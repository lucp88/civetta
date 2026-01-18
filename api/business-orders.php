<?php
require_once '../admin/config.php';
require_once 'cors.php';

header('Content-Type: application/json');
setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

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
            
            $stmt = $pdo->prepare("SELECT * FROM business_orders WHERE account_id = ? ORDER BY created_at DESC");
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
                
                $deliveryDate = $order['delivery_date'] ?? null;
                $isPastDelivery = $deliveryDate && strtotime($deliveryDate) < strtotime('today');
                
                if ($isPastDelivery && !in_array($order['status'], ['delivered', 'cancelled'])) {
                    $order['status'] = 'delivered';
                    $pdo->prepare("UPDATE business_orders SET status = 'delivered' WHERE id = ? AND status NOT IN ('delivered', 'cancelled')")
                        ->execute([$order['id']]);
                }
                
                $isPaid = ($order['status'] === 'paid' || ($order['mollie_status'] ?? '') === 'paid');
                $hasInvoice = ($order['status'] === 'pending_invoice' && !empty($order['eboekhouden_pdf_url']));
                
                if ($isPaid || $hasInvoice || $order['status'] === 'delivered') {
                    if (!empty($order['eboekhouden_pdf_url'])) {
                        $order['factuur_url'] = $order['eboekhouden_pdf_url'];
                        $order['factuur_nummer'] = $order['eboekhouden_factuurnummer'] ?? '';
                    } else {
                        $order['factuur_url'] = '/api/factuur.php?order_id=' . $order['id'] . '&action=view';
                        $order['factuur_nummer'] = 'F' . date('Y') . '-' . str_pad($order['id'], 4, '0', STR_PAD_LEFT);
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
        $paymentType = $data['payment_type'] ?? 'direct';
        $saveAsFavorite = $data['save_as_favorite'] ?? false;
        $favoriteName = trim($data['favorite_name'] ?? '');
        $isRecurring = $data['is_recurring'] ?? false;
        $recurringName = trim($data['recurring_name'] ?? '');
        $recurringFrequency = $data['recurring_frequency'] ?? 'weekly';
        $recurringDay = intval($data['recurring_day'] ?? 1);
        $recurringEndDate = $data['recurring_end_date'] ?? null;
        
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
            
            if ($isRecurring) {
                $initialStatus = 'recurring_pending';
            } elseif ($paymentType === 'later') {
                $initialStatus = 'pending_invoice';
            } else {
                $initialStatus = 'pending';
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO business_orders (account_id, delivery_date, status, total_amount, notes, payment_type, is_recurring, recurring_name, recurring_frequency, recurring_day, recurring_end_date, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $accountId, 
                $deliveryDate, 
                $initialStatus, 
                $totalAmount, 
                $notes, 
                $isRecurring ? 'later' : $paymentType,
                $isRecurring ? 1 : 0,
                $recurringName ?: null,
                $recurringFrequency,
                $recurringDay,
                $recurringEndDate
            ]);
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
            
            if ($saveAsFavorite && $favoriteName) {
                try {
                    $stmt = $pdo->prepare("INSERT INTO business_favorites (account_id, naam, created_at) VALUES (?, ?, NOW())");
                    $stmt->execute([$accountId, $favoriteName]);
                    $favoriteId = $pdo->lastInsertId();
                    
                    $stmt = $pdo->prepare("INSERT INTO business_favorite_items (favorite_id, product_id, product_name, quantity, unit_price) VALUES (?, ?, ?, ?, ?)");
                    foreach ($items as $item) {
                        $stmt->execute([
                            $favoriteId,
                            $item['product_id'] ?? null,
                            $item['product_name'],
                            $item['quantity'],
                            $item['unit_price']
                        ]);
                    }
                } catch (PDOException $e) {
                    error_log("Kon favoriet niet opslaan: " . $e->getMessage());
                }
            }
            
            if ($isRecurring) {
                $pdo->prepare("UPDATE business_orders SET is_recurring = 1 WHERE id = ?")->execute([$orderId]);
            }
            
            $stmt = $pdo->prepare("SELECT bedrijfsnaam, contactpersoon, email FROM business_accounts WHERE id = ?");
            $stmt->execute([$accountId]);
            $account = $stmt->fetch();
            
            $itemsList = "";
            foreach ($items as $item) {
                $itemsList .= "- {$item['quantity']}x {$item['product_name']} (€" . number_format($item['unit_price'], 2, ',', '.') . " p/st)\n";
            }
            
            $to = "laurens@bakkerij-civetta.nl";
            
            if ($isRecurring) {
                $frequencyLabels = ['weekly' => 'Wekelijks', 'biweekly' => 'Tweewekelijks', 'monthly' => 'Maandelijks'];
                $frequencyLabel = $frequencyLabels[$recurringFrequency] ?? 'Wekelijks';
                
                $subject = "🔄 Nieuwe TERUGKERENDE bestelling van {$account['bedrijfsnaam']} (#$orderId)";
                $body = "Er is een nieuwe TERUGKERENDE bestelling geplaatst!\n\n";
                $body .= "══════════════════════════════════════\n";
                $body .= "TERUGKERENDE BESTELLING\n";
                $body .= "══════════════════════════════════════\n\n";
                $body .= "Bestelling #$orderId\n";
                if ($recurringName) {
                    $body .= "Naam: $recurringName\n";
                }
                $body .= "Frequentie: $frequencyLabel\n";
                if ($recurringEndDate) {
                    $body .= "Einddatum: " . date('d-m-Y', strtotime($recurringEndDate)) . "\n";
                } else {
                    $body .= "Einddatum: Doorlopend\n";
                }
                $body .= "\n";
            } else {
                $subject = "Nieuwe bestelling van {$account['bedrijfsnaam']} (#$orderId)";
                $body = "Er is een nieuwe bestelling geplaatst!\n\n";
                $body .= "Bestelling #$orderId\n";
            }
            
            $body .= "Bedrijf: {$account['bedrijfsnaam']}\n";
            $body .= "Contactpersoon: {$account['contactpersoon']}\n";
            $body .= "E-mail: {$account['email']}\n\n";
            $body .= "Gewenste leverdatum: " . date('d-m-Y', strtotime($deliveryDate)) . "\n\n";
            $body .= "Producten:\n$itemsList\n";
            $body .= "Totaalbedrag: €" . number_format($totalAmount, 2, ',', '.') . "\n\n";
            if ($notes) {
                $body .= "Opmerkingen: $notes\n\n";
            }
            if ($isRecurring) {
                $body .= "Facturatie: Maandelijks gebundeld\n";
            } else {
                $body .= "Status: In afwachting van betaling\n";
            }
            $body .= "\nBekijk de bestelling in het admin panel.";
            
            $headers = "From: noreply@bakkerij-civetta.nl\r\n";
            $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
            
            @mail($to, $subject, $body, $headers);
            
            if ($isRecurring) {
                $frequencyLabels = ['weekly' => 'Wekelijks', 'biweekly' => 'Tweewekelijks', 'monthly' => 'Maandelijks'];
                $dayLabels = [0 => 'zondag', 1 => 'maandag', 2 => 'dinsdag', 3 => 'woensdag', 4 => 'donderdag', 5 => 'vrijdag', 6 => 'zaterdag'];
                $frequencyLabelCustomer = $frequencyLabels[$recurringFrequency] ?? 'Wekelijks';
                $dayLabel = $dayLabels[$recurringDay] ?? 'maandag';
                
                $customerSubject = "Bevestiging terugkerende bestelling - Bakkerij Civetta";
                $customerBody = "Beste {$account['contactpersoon']},\n\n";
                $customerBody .= "Bedankt voor uw terugkerende bestelling bij Bakkerij Civetta!\n\n";
                $customerBody .= "══════════════════════════════════════\n";
                $customerBody .= "TERUGKERENDE BESTELLING\n";
                $customerBody .= "══════════════════════════════════════\n\n";
                if ($recurringName) {
                    $customerBody .= "Naam: $recurringName\n";
                }
                $customerBody .= "Frequentie: $frequencyLabelCustomer\n";
                $customerBody .= "Bezorgdag: " . ucfirst($dayLabel) . "\n";
                $customerBody .= "Eerste levering: " . date('d-m-Y', strtotime($deliveryDate)) . "\n";
                if ($recurringEndDate) {
                    $customerBody .= "Einddatum: " . date('d-m-Y', strtotime($recurringEndDate)) . "\n";
                } else {
                    $customerBody .= "Looptijd: Doorlopend (tot opzegging)\n";
                }
                $customerBody .= "\n";
                $customerBody .= "Producten per levering:\n$itemsList\n";
                $customerBody .= "Bedrag per levering: €" . number_format($totalAmount, 2, ',', '.') . "\n\n";
                $customerBody .= "══════════════════════════════════════\n";
                $customerBody .= "FACTURATIE\n";
                $customerBody .= "══════════════════════════════════════\n\n";
                $customerBody .= "Uw leveringen worden maandelijks gebundeld gefactureerd.\n";
                $customerBody .= "Aan het einde van elke maand ontvangt u een verzamelfactuur\n";
                $customerBody .= "voor alle leveringen van die maand.\n\n";
                $customerBody .= "Heeft u vragen? Neem gerust contact met ons op.\n\n";
                $customerBody .= "Met vriendelijke groet,\n";
                $customerBody .= "Bakkerij Civetta\n";
                $customerBody .= "laurens@bakkerij-civetta.nl";
                
                @mail($account['email'], $customerSubject, $customerBody, $headers);
                
                echo json_encode([
                    'success' => true,
                    'order_id' => $orderId,
                    'payment_type' => 'recurring',
                    'message' => 'Terugkerende bestelling geplaatst.'
                ]);
                exit;
            }
            
            if ($paymentType === 'later') {
                require_once 'eboekhouden.php';
                $eboekhoudenSettings = getEBoekhoudenSettings($pdo);
                
                if ($eboekhoudenSettings['facturatie_systeem'] === 'eboekhouden' && $eboekhoudenSettings['eboekhouden_api_token']) {
                    try {
                        $stmt = $pdo->prepare("SELECT * FROM business_accounts WHERE id = ?");
                        $stmt->execute([$accountId]);
                        $accountData = $stmt->fetch();
                        
                        $client = new EBoekhoudenClient($eboekhoudenSettings['eboekhouden_api_token']);
                        
                        $btwCode = 'LAAG_VERK_9';
                        if (floatval($eboekhoudenSettings['btw_tarief']) == 21) {
                            $btwCode = 'HOOG_VERK_21';
                        }
                        
                        $templateId = $eboekhoudenSettings['eboekhouden_template_id_openstaand'];
                        
                        $result = $client->createFullInvoice(
                            $accountData,
                            $items,
                            $templateId,
                            $eboekhoudenSettings['eboekhouden_ledger_id'],
                            $btwCode,
                            true
                        );
                        
                        $stmt = $pdo->prepare("
                            UPDATE business_orders 
                            SET eboekhouden_invoice_id = ?, 
                                eboekhouden_factuurnummer = ?, 
                                eboekhouden_pdf_url = ?,
                                facturatie_systeem = 'eboekhouden',
                                status = 'pending_invoice'
                            WHERE id = ?
                        ");
                        $stmt->execute([
                            $result['id'],
                            $result['invoiceNumber'],
                            $result['pdfUrl'],
                            $orderId
                        ]);
                        
                    } catch (Exception $e) {
                        error_log("e-Boekhouden factuur fout voor order $orderId (betaal later): " . $e->getMessage());
                    }
                }
                
                echo json_encode([
                    'success' => true, 
                    'order_id' => $orderId,
                    'payment_type' => 'later',
                    'message' => 'Bestelling geplaatst. U ontvangt de factuur per e-mail.'
                ]);
                exit;
            }
            
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

function calculateNextRecurringDate($frequency, $deliveryDay, $currentDeliveryDate) {
    $current = new DateTime($currentDeliveryDate);
    $daysOfWeek = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
    $targetDay = $daysOfWeek[$deliveryDay] ?? 'monday';
    
    if ($frequency === 'weekly') {
        $next = (clone $current)->modify('+1 week');
    } elseif ($frequency === 'biweekly') {
        $next = (clone $current)->modify('+2 weeks');
    } else {
        $next = (clone $current)->modify('+1 month');
        $next->modify("next $targetDay");
        if ($next->format('d') < 7) {
            $next->modify("previous $targetDay");
        }
    }
    
    while ($next->format('w') != $deliveryDay) {
        $next->modify('+1 day');
    }
    
    return $next->format('Y-m-d');
}
?>
