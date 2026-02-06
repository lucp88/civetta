<?php
require_once '../admin/config.php';
require_once 'cors.php';
require_once 'delivery-status.php';
require_once __DIR__ . '/../lib/bestelbon/functions.php';
require_once 'mollie-refund.php';
require_once __DIR__ . '/../lib/web-push.php';

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
            
            $stmt = $pdo->prepare("SELECT adres, postcode, plaats, delivery_same_as_business, delivery_adres, delivery_postcode, delivery_plaats FROM business_accounts WHERE id = ?");
            $stmt->execute([$accountId]);
            $account = $stmt->fetch();
            
            if (!empty($account['delivery_same_as_business']) || $account['delivery_same_as_business'] === null) {
                $accountAddress = trim(($account['adres'] ?? '') . ', ' . ($account['postcode'] ?? '') . ' ' . ($account['plaats'] ?? ''));
            } else {
                $accountAddress = trim(($account['delivery_adres'] ?? '') . ', ' . ($account['delivery_postcode'] ?? '') . ' ' . ($account['delivery_plaats'] ?? ''));
            }
            $accountAddress = trim($accountAddress, ', ');
            
            $stmt = $pdo->prepare("SELECT * FROM business_orders WHERE account_id = ? ORDER BY created_at DESC");
            $stmt->execute([$accountId]);
            $orders = $stmt->fetchAll();
            
            foreach ($orders as &$order) {
                if (empty($order['delivery_address'])) {
                    $order['delivery_address'] = $accountAddress;
                }
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
                if ($deliveryDate) {
                    $order['delivery_status'] = updateDeliveryStatusIfNeeded(
                        $pdo,
                        $order['id'],
                        $deliveryDate,
                        $order['delivery_status'] ?? 'geplaatst',
                        (bool)($order['is_cancelled'] ?? false)
                    );
                }
                
                $isPaid = ($order['payment_status'] === 'paid');
                $hasInvoice = ($order['payment_type'] === 'invoice' && !empty($order['eboekhouden_pdf_url']));
                
                if ($isPaid || $hasInvoice) {
                    if (!empty($order['eboekhouden_pdf_url'])) {
                        $order['factuur_url'] = $order['eboekhouden_pdf_url'];
                        $order['factuur_nummer'] = $order['eboekhouden_factuurnummer'] ?? '';
                    } else {
                        $order['factuur_url'] = '/api/factuur.php?order_id=' . $order['id'] . '&action=view';
                        $order['factuur_nummer'] = 'F' . date('Y') . '-' . str_pad($order['id'], 4, '0', STR_PAD_LEFT);
                    }
                }
                
                $order['is_recurring'] = (bool)intval($order['is_recurring'] ?? 0);
                $order['is_cancelled'] = intval($order['is_cancelled'] ?? 0);
                
                $order['can_edit'] = canOrderBeEdited($pdo, $order['id']);
                $deadlineHours = getWijzigDeadlineUren($pdo);
                if ($order['delivery_date']) {
                    $editDeadline = calculateEditDeadline($order['delivery_date'], $deadlineHours);
                    $order['edit_deadline'] = $editDeadline->format('Y-m-d H:i:s');
                    $order['edit_deadline_formatted'] = $editDeadline->format('d-m-Y H:i');
                }
                
                if (!empty($order['bestelbon_number'])) {
                    $order['bestelbon_url'] = '/api/bestelbon.php?order_id=' . $order['id'] . '&action=view';
                }
                
                if ($order['is_recurring'] && !empty($order['recurring_group_id']) && !empty($order['recurring_confirmed_until'])) {
                    $confirmedUntil = new DateTime($order['recurring_confirmed_until']);
                    $twoWeeksBefore = (clone $confirmedUntil)->modify('-14 days');
                    $now = new DateTime();
                    
                    $order['can_renew'] = ($now >= $twoWeeksBefore);
                    $order['renewal_available_from'] = $twoWeeksBefore->format('Y-m-d');
                    $order['renewal_available_from_formatted'] = $twoWeeksBefore->format('d-m-Y');
                    
                    if ($order['recurring_parent_id'] == $order['id'] || $order['id'] == $order['recurring_parent_id']) {
                        $order['is_recurring_parent'] = true;
                    }
                }
            }
            unset($order);
            
            $bankgegevens = null;
            $stmtBank = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('bedrijf_iban', 'bedrijf_bic', 'bedrijf_rekeninghouder')");
            $bankRows = $stmtBank->fetchAll(PDO::FETCH_KEY_PAIR);
            if (!empty($bankRows['bedrijf_iban'])) {
                $bankgegevens = [
                    'iban' => $bankRows['bedrijf_iban'] ?? '',
                    'bic' => $bankRows['bedrijf_bic'] ?? '',
                    'naam' => $bankRows['bedrijf_rekeninghouder'] ?? ''
                ];
            }
            
            echo json_encode(['success' => true, 'orders' => $orders, 'btw_tarief' => $btwTarief, 'bankgegevens' => $bankgegevens]);
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
                $recurringGroupId = 'REC-' . time() . '-' . $accountId;
                $allDeliveryDates = calculateAllDeliveryDates($deliveryDate, $recurringFrequency, $recurringEndDate);
                $confirmedUntil = end($allDeliveryDates);
                
                $orderIds = [];
                $parentOrderId = null;
                
                $stmt = $pdo->prepare("
                    INSERT INTO business_orders (account_id, delivery_date, payment_status, total_amount, notes, payment_type, is_recurring, recurring_name, recurring_frequency, recurring_day, recurring_end_date, recurring_group_id, recurring_confirmed_until, recurring_parent_id, invoice_status, delivery_status, created_at)
                    VALUES (?, ?, 'pending', ?, ?, 'invoice', 1, ?, ?, ?, ?, ?, ?, ?, 'bestelbon', 'geplaatst', NOW())
                ");
                
                $itemStmt = $pdo->prepare("
                    INSERT INTO business_order_items (order_id, product_name, quantity, unit_price)
                    VALUES (?, ?, ?, ?)
                ");
                
                foreach ($allDeliveryDates as $index => $date) {
                    $stmt->execute([
                        $accountId,
                        $date,
                        $totalAmount,
                        $notes,
                        $recurringName ?: null,
                        $recurringFrequency,
                        $recurringDay,
                        $recurringEndDate,
                        $recurringGroupId,
                        $confirmedUntil,
                        $parentOrderId
                    ]);
                    $newOrderId = $pdo->lastInsertId();
                    $orderIds[] = $newOrderId;
                    
                    if ($index === 0) {
                        $parentOrderId = $newOrderId;
                        $pdo->prepare("UPDATE business_orders SET recurring_parent_id = ? WHERE id = ?")->execute([$parentOrderId, $parentOrderId]);
                    }
                    
                    foreach ($items as $item) {
                        $itemStmt->execute([
                            $newOrderId,
                            $item['product_name'],
                            $item['quantity'],
                            $item['unit_price']
                        ]);
                    }
                }
                
                $orderId = $parentOrderId;
                
            } else {
                $dbPaymentType = ($paymentType === 'later') ? 'factuur' : 'ideal';
                
                $stmt = $pdo->prepare("
                    INSERT INTO business_orders (account_id, delivery_date, payment_status, total_amount, notes, payment_type, is_recurring, invoice_status, delivery_status, created_at)
                    VALUES (?, ?, 'pending', ?, ?, ?, 0, 'bestelbon', 'geplaatst', NOW())
                ");
                $stmt->execute([
                    $accountId, 
                    $deliveryDate, 
                    $totalAmount, 
                    $notes, 
                    $dbPaymentType
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
                $orderCount = count($orderIds);
                
                $subject = "🔄 Nieuwe TERUGKERENDE bestelling van {$account['bedrijfsnaam']} ({$orderCount} leveringen)";
                $body = "Er is een nieuwe TERUGKERENDE bestelling geplaatst!\n\n";
                $body .= "══════════════════════════════════════\n";
                $body .= "TERUGKERENDE BESTELLING\n";
                $body .= "══════════════════════════════════════\n\n";
                $body .= "Groep ID: $recurringGroupId\n";
                $body .= "Aantal leveringen: $orderCount\n";
                if ($recurringName) {
                    $body .= "Naam: $recurringName\n";
                }
                $body .= "Frequentie: $frequencyLabel\n";
                $body .= "Eerste levering: " . date('d-m-Y', strtotime($deliveryDate)) . "\n";
                $body .= "Laatste levering: " . date('d-m-Y', strtotime($confirmedUntil)) . "\n";
                if ($recurringEndDate) {
                    $body .= "Einddatum serie: " . date('d-m-Y', strtotime($recurringEndDate)) . "\n";
                } else {
                    $body .= "Serie: Doorlopend (3 maanden ingepland)\n";
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
            if (!$isRecurring) {
                $body .= "Gewenste leverdatum: " . date('d-m-Y', strtotime($deliveryDate)) . "\n\n";
            }
            $body .= "Producten per levering:\n$itemsList\n";
            $body .= "Bedrag per levering: €" . number_format($totalAmount, 2, ',', '.') . "\n";
            if ($isRecurring) {
                $body .= "Totaal ingepland: €" . number_format($totalAmount * $orderCount, 2, ',', '.') . "\n\n";
            } else {
                $body .= "\n";
            }
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

            try {
                $pushTitle = 'Nieuwe bestelling';
                $pushBody = $account['bedrijfsnaam'] . ' - €' . number_format($totalAmount, 2, ',', '.') . ' (' . date('d-m-Y', strtotime($deliveryDate)) . ')';
                sendPushNotification($pdo, $pushTitle, $pushBody);
            } catch (\Throwable $e) {
                error_log('Push notification fout: ' . $e->getMessage());
            }

            if ($isRecurring) {
                sendRecurringBestelbonEmail($pdo, $recurringGroupId);
                
                echo json_encode([
                    'success' => true,
                    'order_id' => $orderId,
                    'recurring_group_id' => $recurringGroupId,
                    'orders_created' => count($orderIds),
                    'confirmed_until' => $confirmedUntil,
                    'payment_type' => 'recurring',
                    'message' => 'Terugkerende bestelling geplaatst. ' . count($orderIds) . ' leveringen ingepland tot ' . date('d-m-Y', strtotime($confirmedUntil)) . '. U ontvangt een overzicht per e-mail.'
                ]);
                exit;
            }
            
            if ($paymentType === 'later') {
                $bestelbonNumber = generateBestelbonNumber($pdo, $orderId);
                $deadlineHours = getWijzigDeadlineUren($pdo);
                $editDeadline = calculateEditDeadline($deliveryDate, $deadlineHours);
                
                $stmt = $pdo->prepare("
                    UPDATE business_orders 
                    SET bestelbon_number = ?,
                        order_status = 'geplaatst',
                        payment_type = 'factuur',
                        invoice_status = 'bestelbon',
                        delivery_status = 'geplaatst'
                    WHERE id = ?
                ");
                $stmt->execute([$bestelbonNumber, $orderId]);
                
                sendBestelbonEmail($pdo, $orderId);
                
                echo json_encode([
                    'success' => true, 
                    'order_id' => $orderId,
                    'payment_type' => 'factuur',
                    'bestelbon_number' => $bestelbonNumber,
                    'edit_deadline' => $editDeadline->format('Y-m-d H:i:s'),
                    'message' => 'Bestelling geplaatst. U ontvangt een bestelbon per e-mail. De factuur volgt na levering.'
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
        
    case 'PUT':
        $data = json_decode(file_get_contents('php://input'), true);
        $action = $data['action'] ?? 'update';
        
        if ($action === 'renew_recurring') {
            $recurringGroupId = $data['recurring_group_id'] ?? '';
            
            if (!$recurringGroupId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'recurring_group_id ontbreekt']);
                exit;
            }
            
            $stmt = $pdo->prepare("
                SELECT bo.*, ba.bedrijfsnaam, ba.contactpersoon, ba.email 
                FROM business_orders bo
                JOIN business_accounts ba ON bo.account_id = ba.id
                WHERE bo.recurring_group_id = ? AND bo.account_id = ?
                ORDER BY bo.delivery_date DESC
                LIMIT 1
            ");
            $stmt->execute([$recurringGroupId, $accountId]);
            $lastOrder = $stmt->fetch();
            
            if (!$lastOrder) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Terugkerende bestelling niet gevonden']);
                exit;
            }
            
            $stmt = $pdo->prepare("SELECT * FROM business_order_items WHERE order_id = ?");
            $stmt->execute([$lastOrder['id']]);
            $items = $stmt->fetchAll();
            
            if (empty($items)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Geen producten gevonden in deze bestelling']);
                exit;
            }
            
            try {
                $pdo->beginTransaction();
                
                $lastDeliveryDate = new DateTime($lastOrder['delivery_date']);
                $frequency = $lastOrder['recurring_frequency'];
                
                if ($frequency === 'weekly') {
                    $firstNewDate = (clone $lastDeliveryDate)->modify('+1 week');
                } elseif ($frequency === 'biweekly') {
                    $firstNewDate = (clone $lastDeliveryDate)->modify('+2 weeks');
                } else {
                    $firstNewDate = (clone $lastDeliveryDate)->modify('+1 month');
                }
                
                $maxDate = (clone $firstNewDate)->modify('+3 months');
                $newDeliveryDates = [];
                $current = clone $firstNewDate;
                
                while ($current <= $maxDate) {
                    $newDeliveryDates[] = $current->format('Y-m-d');
                    
                    if ($frequency === 'weekly') {
                        $current->modify('+1 week');
                    } elseif ($frequency === 'biweekly') {
                        $current->modify('+2 weeks');
                    } else {
                        $current->modify('+1 month');
                    }
                }
                
                $newConfirmedUntil = end($newDeliveryDates);
                
                $stmt = $pdo->prepare("
                    UPDATE business_orders 
                    SET recurring_confirmed_until = ?
                    WHERE recurring_group_id = ?
                ");
                $stmt->execute([$newConfirmedUntil, $recurringGroupId]);
                
                $orderStmt = $pdo->prepare("
                    INSERT INTO business_orders (account_id, delivery_date, payment_status, total_amount, notes, payment_type, is_recurring, recurring_name, recurring_frequency, recurring_day, recurring_end_date, recurring_group_id, recurring_confirmed_until, recurring_parent_id, invoice_status, delivery_status, created_at)
                    VALUES (?, ?, 'pending', ?, ?, 'invoice', 1, ?, ?, ?, ?, ?, ?, ?, 'bestelbon', 'geplaatst', NOW())
                ");
                
                $itemStmt = $pdo->prepare("
                    INSERT INTO business_order_items (order_id, product_name, quantity, unit_price)
                    VALUES (?, ?, ?, ?)
                ");
                
                $newOrderIds = [];
                foreach ($newDeliveryDates as $date) {
                    $orderStmt->execute([
                        $accountId,
                        $date,
                        $lastOrder['total_amount'],
                        $lastOrder['notes'],
                        $lastOrder['recurring_name'],
                        $lastOrder['recurring_frequency'],
                        $lastOrder['recurring_day'],
                        $lastOrder['recurring_end_date'],
                        $recurringGroupId,
                        $newConfirmedUntil,
                        $lastOrder['recurring_parent_id']
                    ]);
                    $newOrderId = $pdo->lastInsertId();
                    $newOrderIds[] = $newOrderId;
                    
                    foreach ($items as $item) {
                        $itemStmt->execute([
                            $newOrderId,
                            $item['product_name'],
                            $item['quantity'],
                            $item['unit_price']
                        ]);
                    }
                }
                
                $pdo->commit();
                
                sendRecurringBestelbonEmail($pdo, $recurringGroupId);
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Terugkerende bestelling verlengd met ' . count($newOrderIds) . ' leveringen tot ' . date('d-m-Y', strtotime($newConfirmedUntil)),
                    'new_orders' => count($newOrderIds),
                    'confirmed_until' => $newConfirmedUntil
                ]);
                exit;
                
            } catch (PDOException $e) {
                $pdo->rollBack();
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'Database fout bij verlengen bestelling']);
                exit;
            }
        }
        
        $orderId = intval($data['order_id'] ?? 0);
        
        if (!$orderId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Order ID ontbreekt']);
            exit;
        }
        
        $stmt = $pdo->prepare("SELECT * FROM business_orders WHERE id = ? AND account_id = ?");
        $stmt->execute([$orderId, $accountId]);
        $order = $stmt->fetch();
        
        if (!$order) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Bestelling niet gevonden']);
            exit;
        }
        
        if (intval($order['is_cancelled']) === 1) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Geannuleerde bestellingen kunnen niet worden aangepast']);
            exit;
        }
        
        $inBereiding = in_array($order['delivery_status'], ['wordt_bereid', 'onderweg', 'afgeleverd']);
        if ($inBereiding) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Bestellingen in bereiding kunnen niet worden geannuleerd of aangepast']);
            exit;
        }
        
        $isMolliePaid = $order['payment_status'] === 'paid' && 
                        in_array($order['payment_type'], ['ideal', 'mollie_direct']);
        
        try {
            if ($action === 'cancel') {
                $refundResult = null;
                $refundCheck = canRefundOrder($pdo, $orderId);
                
                if ($refundCheck['can_refund']) {
                    $refundResult = createMollieRefund(
                        $refundCheck['mollie_payment_id'],
                        $refundCheck['amount'],
                        "Annulering bestelling #$orderId"
                    );
                    
                    if ($refundResult['success']) {
                        $stmt = $pdo->prepare("
                            UPDATE business_orders 
                            SET is_cancelled = 1,
                                mollie_refund_id = ?,
                                mollie_refund_status = ?
                            WHERE id = ?
                        ");
                        $stmt->execute([
                            $refundResult['refund_id'],
                            $refundResult['status'],
                            $orderId
                        ]);
                    } else {
                        echo json_encode([
                            'success' => false, 
                            'error' => 'Terugbetaling mislukt: ' . $refundResult['error']
                        ]);
                        exit;
                    }
                } else {
                    $stmt = $pdo->prepare("UPDATE business_orders SET is_cancelled = 1 WHERE id = ?");
                    $stmt->execute([$orderId]);
                }
                
                sendCancellationEmail($pdo, $orderId);
                
                $message = 'Bestelling geannuleerd. U ontvangt een bevestiging per e-mail.';
                if ($refundResult && $refundResult['success']) {
                    $message = 'Bestelling geannuleerd. Het bedrag van €' . number_format($refundCheck['amount'], 2, ',', '.') . ' wordt binnen enkele werkdagen teruggestort. U ontvangt een bevestiging per e-mail.';
                }
                
                echo json_encode([
                    'success' => true, 
                    'message' => $message,
                    'refunded' => $refundResult ? $refundResult['success'] : false
                ]);
                exit;
            }
            
            if ($isMolliePaid) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Betaalde bestellingen kunnen niet worden aangepast']);
                exit;
            }
            
            if (!canOrderBeEdited($pdo, $orderId)) {
                $deadlineHours = getWijzigDeadlineUren($pdo);
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => "De wijzigingsdeadline ($deadlineHours uur voor levering) is verstreken. Deze bestelling kan niet meer worden aangepast."]);
                exit;
            }
            
            $pdo->beginTransaction();
            
            $notes = trim($data['notes'] ?? $order['notes']);
            $deliveryAddress = trim($data['delivery_address'] ?? $order['delivery_address'] ?? '');
            $items = $data['items'] ?? null;
            
            if ($items !== null && !empty($items)) {
                $totalAmount = 0;
                foreach ($items as $item) {
                    $totalAmount += floatval($item['quantity']) * floatval($item['unit_price']);
                }
                
                $stmt = $pdo->prepare("DELETE FROM business_order_items WHERE order_id = ?");
                $stmt->execute([$orderId]);
                
                $stmt = $pdo->prepare("
                    INSERT INTO business_order_items (order_id, product_name, quantity, unit_price)
                    VALUES (?, ?, ?, ?)
                ");
                
                foreach ($items as $item) {
                    $stmt->execute([
                        $orderId,
                        $item['product_name'],
                        intval($item['quantity']),
                        floatval($item['unit_price'])
                    ]);
                }
                
                $stmt = $pdo->prepare("UPDATE business_orders SET notes = ?, delivery_address = ?, total_amount = ? WHERE id = ?");
                $stmt->execute([$notes, $deliveryAddress, $totalAmount, $orderId]);
            } else {
                $stmt = $pdo->prepare("UPDATE business_orders SET notes = ?, delivery_address = ? WHERE id = ?");
                $stmt->execute([$notes, $deliveryAddress, $orderId]);
            }
            
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Bestelling bijgewerkt']);
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Database fout bij bijwerken bestelling']);
        }
        break;
        
    default:
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
}

function calculateAllDeliveryDates($firstDeliveryDate, $frequency, $endDate = null) {
    $dates = [];
    $current = new DateTime($firstDeliveryDate);
    
    if ($endDate) {
        $maxDate = new DateTime($endDate);
    } else {
        $maxDate = (clone $current)->modify('+3 months');
    }
    
    $threeMonthsFromNow = (new DateTime())->modify('+3 months');
    if ($maxDate > $threeMonthsFromNow && !$endDate) {
        $maxDate = $threeMonthsFromNow;
    }
    
    while ($current <= $maxDate) {
        $dates[] = $current->format('Y-m-d');
        
        if ($frequency === 'weekly') {
            $current->modify('+1 week');
        } elseif ($frequency === 'biweekly') {
            $current->modify('+2 weeks');
        } else {
            $current->modify('+1 month');
        }
    }
    
    return $dates;
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
