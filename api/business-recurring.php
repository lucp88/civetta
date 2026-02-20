<?php
require_once '../admin/config.php';
require_once 'cors.php';
require_once 'delivery-status.php';
require_once __DIR__ . '/../lib/bestelbon/functions.php';

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
            $stmt = $pdo->prepare("
                SELECT 
                    recurring_group_id,
                    MAX(recurring_name) as name,
                    MAX(recurring_frequency) as frequency,
                    MAX(recurring_day) as delivery_day,
                    MAX(recurring_end_date) as end_date,
                    MAX(recurring_confirmed_until) as confirmed_until,
                    MIN(delivery_date) as first_delivery,
                    MAX(delivery_date) as last_delivery,
                    COUNT(*) as total_orders,
                    SUM(CASE WHEN payment_status = 'paid' THEN 1 ELSE 0 END) as completed_orders,
                    SUM(CASE WHEN is_cancelled = 1 THEN 1 ELSE 0 END) as cancelled_orders,
                    SUM(CASE WHEN payment_status = 'pending' AND is_cancelled = 0 AND delivery_date >= CURDATE() THEN 1 ELSE 0 END) as upcoming_orders,
                    SUM(CASE WHEN payment_status = 'pending' AND is_cancelled = 0 AND COALESCE(is_paused, 0) = 1 AND delivery_date >= CURDATE() THEN 1 ELSE 0 END) as paused_orders,
                    ROUND(AVG(total_amount), 2) as amount_per_delivery,
                    MIN(created_at) as created_at
                FROM business_orders 
                WHERE account_id = ? 
                AND is_recurring = 1 
                AND recurring_group_id IS NOT NULL
                GROUP BY recurring_group_id
                ORDER BY MIN(created_at) DESC
            ");
            $stmt->execute([$accountId]);
            $groups = $stmt->fetchAll();
            
            foreach ($groups as &$group) {
                $group['is_active'] = $group['upcoming_orders'] > 0;
                $group['is_paused'] = intval($group['paused_orders'] ?? 0) > 0 && intval($group['paused_orders']) == intval($group['upcoming_orders']);
                $group['needs_renewal'] = $group['confirmed_until'] && 
                    strtotime($group['confirmed_until']) <= strtotime('+14 days') &&
                    $group['is_active'];
                
                $stmt = $pdo->prepare("
                    SELECT id, delivery_date, payment_status, payment_type, is_cancelled, total_amount, delivery_status, 
                           COALESCE(is_paused, 0) as is_paused, invoice_status, eboekhouden_pdf_url
                    FROM business_orders 
                    WHERE recurring_group_id = ? 
                    AND delivery_date >= CURDATE()
                    AND is_cancelled = 0
                    ORDER BY delivery_date ASC
                    LIMIT 10
                ");
                $stmt->execute([$group['recurring_group_id']]);
                $upcomingDeliveries = $stmt->fetchAll();
                
                foreach ($upcomingDeliveries as &$delivery) {
                    if ($delivery['delivery_date']) {
                        $delivery['delivery_status'] = updateDeliveryStatusIfNeeded(
                            $pdo,
                            $delivery['id'],
                            $delivery['delivery_date'],
                            $delivery['delivery_status'] ?? 'geplaatst',
                            (bool)($delivery['is_cancelled'] ?? false)
                        );
                    }
                    
                    if ($delivery['invoice_status'] === 'gefactureerd') {
                        if (!empty($delivery['eboekhouden_pdf_url'])) {
                            $delivery['factuur_url'] = $delivery['eboekhouden_pdf_url'];
                        } else {
                            $delivery['factuur_url'] = '/api/factuur.php?order_id=' . $delivery['id'] . '&action=view';
                        }
                    }
                    
                    $delivery['bestelbon_url'] = '/api/bestelbon.php?order_id=' . $delivery['id'];
                }
                unset($delivery);
                $group['upcoming_deliveries'] = $upcomingDeliveries;
                
                $stmt = $pdo->prepare("
                    SELECT ti.product_name, ti.quantity, ti.unit_price 
                    FROM recurring_group_template_items ti
                    JOIN recurring_group_templates t ON ti.template_id = t.id
                    WHERE t.recurring_group_id = ?
                ");
                $stmt->execute([$group['recurring_group_id']]);
                $group['items'] = $stmt->fetchAll();
                
                if (empty($group['items'])) {
                    $stmt = $pdo->prepare("
                        SELECT product_name, quantity, unit_price 
                        FROM business_order_items 
                        WHERE order_id = (
                            SELECT id FROM business_orders 
                            WHERE recurring_group_id = ? 
                            AND delivery_date >= CURDATE()
                            AND is_cancelled = 0
                            ORDER BY delivery_date ASC LIMIT 1
                        )
                    ");
                    $stmt->execute([$group['recurring_group_id']]);
                    $group['items'] = $stmt->fetchAll();
                }
                
                $availableProducts = $pdo->query("SELECT naam FROM products")->fetchAll(PDO::FETCH_COLUMN);
                $availableProductsLower = array_map('strtolower', $availableProducts);
                
                $hasUnavailable = false;
                foreach ($group['items'] as &$item) {
                    $isAvailable = in_array(strtolower($item['product_name']), $availableProductsLower);
                    $item['is_available'] = $isAvailable;
                    if (!$isAvailable) {
                        $hasUnavailable = true;
                    }
                }
                unset($item);
                $group['has_unavailable_products'] = $hasUnavailable;
                
                $stmt = $pdo->prepare("
                    SELECT id, bestelbon_number FROM business_orders 
                    WHERE recurring_group_id = ? 
                    ORDER BY id ASC LIMIT 1
                ");
                $stmt->execute([$group['recurring_group_id']]);
                $firstOrder = $stmt->fetch();
                $group['first_order_id'] = $firstOrder ? $firstOrder['id'] : null;
                $group['bestelbon_number'] = $firstOrder ? $firstOrder['bestelbon_number'] : null;
            }
            unset($group);
            
            echo json_encode([
                'success' => true, 
                'recurring_groups' => $groups
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Database fout: ' . $e->getMessage()]);
        }
        break;
        
    case 'PUT':
        $data = json_decode(file_get_contents('php://input'), true);
        $groupId = $data['recurring_group_id'] ?? null;
        $action = $data['action'] ?? 'update';
        
        if (!$groupId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'recurring_group_id ontbreekt']);
            exit;
        }
        
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM business_orders WHERE recurring_group_id = ? AND account_id = ?");
        $stmt->execute([$groupId, $accountId]);
        if ($stmt->fetchColumn() == 0) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Recurring groep niet gevonden']);
            exit;
        }
        
        try {
            if ($action === 'stop') {
                $stmt = $pdo->prepare("
                    SELECT id FROM business_orders 
                    WHERE recurring_group_id = ? 
                    AND account_id = ?
                    AND delivery_date > CURDATE()
                    AND is_cancelled = 0
                    AND payment_status = 'pending'
                ");
                $stmt->execute([$groupId, $accountId]);
                $orderIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
                
                $stmt = $pdo->prepare("
                    UPDATE business_orders 
                    SET is_cancelled = 1 
                    WHERE recurring_group_id = ? 
                    AND account_id = ?
                    AND delivery_date > CURDATE()
                    AND is_cancelled = 0
                    AND payment_status = 'pending'
                ");
                $stmt->execute([$groupId, $accountId]);
                $cancelled = $stmt->rowCount();
                
                if ($cancelled > 0 && count($orderIds) > 0) {
                    sendCancellationEmail($pdo, $orderIds[0]);
                }
                
                echo json_encode([
                    'success' => true, 
                    'message' => "Terugkerende bestelling gestopt. $cancelled toekomstige leveringen geannuleerd."
                ]);
                
            } elseif ($action === 'pause') {
                $deadlineHours = getWijzigDeadlineUren($pdo);
                
                $stmt = $pdo->prepare("
                    SELECT bo.id, bo.delivery_date, ba.email, ba.contactpersoon, ba.bedrijfsnaam,
                           bo.recurring_name, bo.recurring_frequency, bo.total_amount
                    FROM business_orders bo
                    JOIN business_accounts ba ON bo.account_id = ba.id
                    WHERE bo.recurring_group_id = ? 
                    AND bo.account_id = ?
                    AND bo.delivery_date > CURDATE()
                    AND bo.is_cancelled = 0
                    AND COALESCE(bo.is_paused, 0) = 0
                    AND bo.payment_status = 'pending'
                    ORDER BY bo.delivery_date ASC
                ");
                $stmt->execute([$groupId, $accountId]);
                $ordersToCheck = $stmt->fetchAll();
                
                $ordersToPause = [];
                $ordersInBereiding = [];
                $accountInfo = null;
                
                foreach ($ordersToCheck as $order) {
                    if (!$accountInfo) {
                        $accountInfo = [
                            'email' => $order['email'],
                            'contactpersoon' => $order['contactpersoon'],
                            'bedrijfsnaam' => $order['bedrijfsnaam'],
                            'recurring_name' => $order['recurring_name'],
                            'recurring_frequency' => $order['recurring_frequency']
                        ];
                    }
                    
                    $editDeadline = calculateEditDeadline($order['delivery_date'], $deadlineHours);
                    if (new DateTime() < $editDeadline) {
                        $ordersToPause[] = $order;
                    } else {
                        $ordersInBereiding[] = $order;
                    }
                }
                
                if (empty($ordersToPause)) {
                    echo json_encode([
                        'success' => false, 
                        'error' => 'Geen bestellingen kunnen worden gepauzeerd. Alle bestellingen zijn al in bereiding.'
                    ]);
                    exit;
                }
                
                $pauseIds = array_column($ordersToPause, 'id');
                $placeholders = implode(',', array_fill(0, count($pauseIds), '?'));
                
                $stmt = $pdo->prepare("UPDATE business_orders SET is_paused = 1 WHERE id IN ($placeholders)");
                $stmt->execute($pauseIds);
                $paused = $stmt->rowCount();
                
                if ($paused > 0 && $accountInfo) {
                    sendRecurringPauseEmail($pdo, $accountInfo, $ordersToPause, $ordersInBereiding, true);
                }
                
                $message = "$paused levering(en) gepauzeerd.";
                if (count($ordersInBereiding) > 0) {
                    $message .= " " . count($ordersInBereiding) . " levering(en) zijn al in bereiding en gaan door.";
                }
                
                echo json_encode([
                    'success' => true, 
                    'message' => $message,
                    'paused_count' => $paused,
                    'in_bereiding_count' => count($ordersInBereiding)
                ]);
                
            } elseif ($action === 'resume') {
                $deadlineHours = getWijzigDeadlineUren($pdo);
                
                $stmt = $pdo->prepare("
                    SELECT bo.id, bo.delivery_date, ba.email, ba.contactpersoon, ba.bedrijfsnaam,
                           bo.recurring_name, bo.recurring_frequency, bo.total_amount
                    FROM business_orders bo
                    JOIN business_accounts ba ON bo.account_id = ba.id
                    WHERE bo.recurring_group_id = ? 
                    AND bo.account_id = ?
                    AND bo.delivery_date > CURDATE()
                    AND bo.is_cancelled = 0
                    AND bo.is_paused = 1
                    AND bo.payment_status = 'pending'
                    ORDER BY bo.delivery_date ASC
                ");
                $stmt->execute([$groupId, $accountId]);
                $pausedOrders = $stmt->fetchAll();
                
                $ordersToResume = [];
                $ordersMissed = [];
                $accountInfo = null;
                
                foreach ($pausedOrders as $order) {
                    if (!$accountInfo) {
                        $accountInfo = [
                            'email' => $order['email'],
                            'contactpersoon' => $order['contactpersoon'],
                            'bedrijfsnaam' => $order['bedrijfsnaam'],
                            'recurring_name' => $order['recurring_name'],
                            'recurring_frequency' => $order['recurring_frequency']
                        ];
                    }
                    
                    $editDeadline = calculateEditDeadline($order['delivery_date'], $deadlineHours);
                    if (new DateTime() < $editDeadline) {
                        $ordersToResume[] = $order;
                    } else {
                        $ordersMissed[] = $order;
                    }
                }
                
                $resumed = 0;
                $cancelled = 0;
                
                if (!empty($ordersToResume)) {
                    $resumeIds = array_column($ordersToResume, 'id');
                    $placeholders = implode(',', array_fill(0, count($resumeIds), '?'));
                    $stmt = $pdo->prepare("UPDATE business_orders SET is_paused = 0 WHERE id IN ($placeholders)");
                    $stmt->execute($resumeIds);
                    $resumed = $stmt->rowCount();
                }
                
                if (!empty($ordersMissed)) {
                    $missedIds = array_column($ordersMissed, 'id');
                    $placeholders = implode(',', array_fill(0, count($missedIds), '?'));
                    $stmt = $pdo->prepare("UPDATE business_orders SET is_paused = 0, is_cancelled = 1 WHERE id IN ($placeholders)");
                    $stmt->execute($missedIds);
                    $cancelled = $stmt->rowCount();
                }
                
                if (($resumed > 0 || $cancelled > 0) && $accountInfo) {
                    sendRecurringPauseEmail($pdo, $accountInfo, $ordersToResume, $ordersMissed, false);
                }
                
                $message = "$resumed levering(en) hervat.";
                if ($cancelled > 0) {
                    $message .= " $cancelled levering(en) konden niet meer worden hervat (deadline verstreken) en zijn geannuleerd.";
                }
                
                echo json_encode([
                    'success' => true, 
                    'message' => $message,
                    'resumed_count' => $resumed,
                    'cancelled_count' => $cancelled
                ]);
                
            } elseif ($action === 'extend') {
                $stmt = $pdo->prepare("
                    SELECT * FROM business_orders 
                    WHERE recurring_group_id = ? AND account_id = ?
                    ORDER BY delivery_date DESC LIMIT 1
                ");
                $stmt->execute([$groupId, $accountId]);
                $lastOrder = $stmt->fetch();
                
                if (!$lastOrder) {
                    throw new Exception('Geen bestaande orders gevonden');
                }
                
                $stmt = $pdo->prepare("SELECT * FROM business_order_items WHERE order_id = ?");
                $stmt->execute([$lastOrder['id']]);
                $items = $stmt->fetchAll();
                
                $lastDate = new DateTime($lastOrder['delivery_date']);
                $frequency = $lastOrder['recurring_frequency'];
                
                if ($frequency === 'weekly') {
                    $lastDate->modify('+1 week');
                } elseif ($frequency === 'biweekly') {
                    $lastDate->modify('+2 weeks');
                } else {
                    $lastDate->modify('+1 month');
                }
                
                $endDate = $lastOrder['recurring_end_date'];
                $newDates = calculateExtensionDates($lastDate->format('Y-m-d'), $frequency, $endDate);
                
                if (empty($newDates)) {
                    echo json_encode([
                        'success' => false, 
                        'error' => 'Kan niet verlengen: einddatum is bereikt of geen nieuwe datums mogelijk.'
                    ]);
                    exit;
                }
                
                $newConfirmedUntil = end($newDates);
                $orderIds = [];
                
                $pdo->beginTransaction();
                
                $stmt = $pdo->prepare("
                    INSERT INTO business_orders (account_id, delivery_date, payment_status, total_amount, notes, payment_type, is_recurring, recurring_name, recurring_frequency, recurring_day, recurring_end_date, recurring_group_id, recurring_confirmed_until, recurring_parent_id, invoice_status, delivery_status, created_at)
                    VALUES (?, ?, 'pending', ?, ?, 'invoice', 1, ?, ?, ?, ?, ?, ?, ?, 'bestelbon', 'geplaatst', NOW())
                ");
                
                $itemStmt = $pdo->prepare("
                    INSERT INTO business_order_items (order_id, product_name, quantity, unit_price, variant_id, product_id)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");

                foreach ($newDates as $date) {
                    $stmt->execute([
                        $accountId,
                        $date,
                        $lastOrder['total_amount'],
                        $lastOrder['notes'],
                        $lastOrder['recurring_name'],
                        $lastOrder['recurring_frequency'],
                        $lastOrder['recurring_day'],
                        $lastOrder['recurring_end_date'],
                        $groupId,
                        $newConfirmedUntil,
                        $lastOrder['recurring_parent_id']
                    ]);
                    $newOrderId = $pdo->lastInsertId();
                    $orderIds[] = $newOrderId;

                    foreach ($items as $item) {
                        $itemStmt->execute([
                            $newOrderId,
                            $item['product_name'],
                            $item['quantity'],
                            $item['unit_price'],
                            $item['variant_id'] ?? null,
                            $item['product_id'] ?? null
                        ]);
                    }
                }
                
                $pdo->prepare("
                    UPDATE business_orders 
                    SET recurring_confirmed_until = ? 
                    WHERE recurring_group_id = ? AND account_id = ?
                ")->execute([$newConfirmedUntil, $groupId, $accountId]);
                
                $pdo->commit();
                
                echo json_encode([
                    'success' => true,
                    'message' => count($orderIds) . ' nieuwe leveringen ingepland tot ' . date('d-m-Y', strtotime($newConfirmedUntil)),
                    'orders_created' => count($orderIds),
                    'confirmed_until' => $newConfirmedUntil
                ]);
                
            } elseif ($action === 'update') {
                $newItems = $data['items'] ?? null;
                $newNotes = $data['notes'] ?? null;
                $newName = $data['name'] ?? null;
                $newFrequency = $data['frequency'] ?? null;
                
                $oldItemsForEmail = [];
                if ($newItems !== null) {
                    $stmt = $pdo->prepare("SELECT id FROM recurring_group_templates WHERE recurring_group_id = ? AND account_id = ?");
                    $stmt->execute([$groupId, $accountId]);
                    $existingTemplateId = $stmt->fetchColumn();
                    
                    if ($existingTemplateId) {
                        $stmt = $pdo->prepare("SELECT product_name, quantity, unit_price FROM recurring_group_template_items WHERE template_id = ?");
                        $stmt->execute([$existingTemplateId]);
                        $oldItemsForEmail = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    } else {
                        $stmt = $pdo->prepare("
                            SELECT product_name, quantity, unit_price 
                            FROM business_order_items 
                            WHERE order_id = (
                                SELECT id FROM business_orders 
                                WHERE recurring_group_id = ? 
                                AND delivery_date >= CURDATE()
                                AND is_cancelled = 0
                                ORDER BY delivery_date ASC LIMIT 1
                            )
                        ");
                        $stmt->execute([$groupId]);
                        $oldItemsForEmail = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    }
                }
                
                $pdo->beginTransaction();
                
                $updateFields = [];
                $updateParams = [];
                
                if ($newNotes !== null) {
                    $updateFields[] = 'notes = ?';
                    $updateParams[] = $newNotes;
                }
                
                if ($newName !== null) {
                    $updateFields[] = 'recurring_name = ?';
                    $updateParams[] = $newName;
                }
                
                if ($newFrequency !== null) {
                    $updateFields[] = 'recurring_frequency = ?';
                    $updateParams[] = $newFrequency;
                }
                
                if (!empty($updateFields)) {
                    $sql = "UPDATE business_orders SET " . implode(', ', $updateFields) . "
                            WHERE recurring_group_id = ? 
                            AND account_id = ?
                            AND delivery_date > CURDATE()
                            AND is_cancelled = 0
                            AND payment_status = 'pending'";
                    $updateParams[] = $groupId;
                    $updateParams[] = $accountId;
                    $pdo->prepare($sql)->execute($updateParams);
                }
                
                if ($newItems !== null) {
                    $stmt = $pdo->prepare("SELECT id FROM recurring_group_templates WHERE recurring_group_id = ? AND account_id = ?");
                    $stmt->execute([$groupId, $accountId]);
                    $templateId = $stmt->fetchColumn();
                    
                    $oldTemplateItems = [];
                    if ($templateId) {
                        $stmt = $pdo->prepare("SELECT product_name, quantity, unit_price FROM recurring_group_template_items WHERE template_id = ? ORDER BY product_name");
                        $stmt->execute([$templateId]);
                        $oldTemplateItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        
                        $pdo->prepare("DELETE FROM recurring_group_template_items WHERE template_id = ?")->execute([$templateId]);
                        $itemStmt = $pdo->prepare("INSERT INTO recurring_group_template_items (template_id, product_name, quantity, unit_price) VALUES (?, ?, ?, ?)");
                        foreach ($newItems as $item) {
                            $itemStmt->execute([$templateId, $item['product_name'], $item['quantity'], $item['unit_price']]);
                        }
                    } else {
                        $stmt = $pdo->prepare("
                            INSERT INTO recurring_group_templates (recurring_group_id, account_id, name, frequency, delivery_day)
                            SELECT recurring_group_id, account_id, recurring_name, recurring_frequency, recurring_day
                            FROM business_orders WHERE recurring_group_id = ? AND account_id = ? LIMIT 1
                        ");
                        $stmt->execute([$groupId, $accountId]);
                        $templateId = $pdo->lastInsertId();
                        
                        $itemStmt = $pdo->prepare("INSERT INTO recurring_group_template_items (template_id, product_name, quantity, unit_price) VALUES (?, ?, ?, ?)");
                        foreach ($newItems as $item) {
                            $itemStmt->execute([$templateId, $item['product_name'], $item['quantity'], $item['unit_price']]);
                        }
                        
                        $oldTemplateItems = null;
                    }
                    
                    $stmt = $pdo->prepare("
                        SELECT id FROM business_orders 
                        WHERE recurring_group_id = ? 
                        AND account_id = ?
                        AND delivery_date > CURDATE()
                        AND is_cancelled = 0
                        AND payment_status = 'pending'
                    ");
                    $stmt->execute([$groupId, $accountId]);
                    $futureOrderIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    
                    $getOrderItems = $pdo->prepare("SELECT product_name, quantity, unit_price, variant_id, product_id FROM business_order_items WHERE order_id = ? ORDER BY product_name");
                    $totalAmount = array_reduce($newItems, fn($sum, $i) => $sum + ($i['quantity'] * $i['unit_price']), 0);
                    $itemStmt = $pdo->prepare("INSERT INTO business_order_items (order_id, product_name, quantity, unit_price, variant_id, product_id) VALUES (?, ?, ?, ?, ?, ?)");

                    foreach ($futureOrderIds as $orderId) {
                        $shouldUpdate = false;

                        if ($oldTemplateItems === null || empty($oldTemplateItems)) {
                            $shouldUpdate = true;
                        } else {
                            $getOrderItems->execute([$orderId]);
                            $orderItems = $getOrderItems->fetchAll(PDO::FETCH_ASSOC);
                            $shouldUpdate = itemsMatch($orderItems, $oldTemplateItems);
                        }

                        if ($shouldUpdate) {
                            $pdo->prepare("DELETE FROM business_order_items WHERE order_id = ?")->execute([$orderId]);
                            foreach ($newItems as $item) {
                                $itemStmt->execute([$orderId, $item['product_name'], $item['quantity'], $item['unit_price'], !empty($item['variant_id']) ? intval($item['variant_id']) : null, !empty($item['product_id']) ? intval($item['product_id']) : null]);
                            }
                            $pdo->prepare("UPDATE business_orders SET total_amount = ? WHERE id = ?")->execute([$totalAmount, $orderId]);
                        }
                    }
                }
                
                if ($newName !== null || $newNotes !== null) {
                    $stmt = $pdo->prepare("SELECT id FROM recurring_group_templates WHERE recurring_group_id = ? AND account_id = ?");
                    $stmt->execute([$groupId, $accountId]);
                    $templateId = $stmt->fetchColumn();
                    
                    if ($templateId) {
                        $templateUpdates = [];
                        $templateParams = [];
                        if ($newName !== null) {
                            $templateUpdates[] = 'name = ?';
                            $templateParams[] = $newName;
                        }
                        if ($newNotes !== null) {
                            $templateUpdates[] = 'notes = ?';
                            $templateParams[] = $newNotes;
                        }
                        if (!empty($templateUpdates)) {
                            $templateParams[] = $templateId;
                            $pdo->prepare("UPDATE recurring_group_templates SET " . implode(', ', $templateUpdates) . " WHERE id = ?")->execute($templateParams);
                        }
                    }
                }
                
                $pdo->commit();
                
                if ($newItems !== null && !empty($oldItemsForEmail)) {
                    sendRecurringUpdateEmail($pdo, $groupId, $oldItemsForEmail, $newItems);
                }
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Toekomstige leveringen bijgewerkt'
                ]);
                
            } elseif ($action === 'update_single') {
                $orderId = $data['order_id'] ?? null;
                $newItems = $data['items'] ?? null;
                $newNotes = $data['notes'] ?? null;
                $skip = $data['skip'] ?? false;
                
                if (!$orderId) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'order_id ontbreekt']);
                    exit;
                }
                
                $stmt = $pdo->prepare("SELECT * FROM business_orders WHERE id = ? AND account_id = ? AND recurring_group_id = ?");
                $stmt->execute([$orderId, $accountId, $groupId]);
                $order = $stmt->fetch();
                
                if (!$order) {
                    http_response_code(404);
                    echo json_encode(['success' => false, 'error' => 'Bestelling niet gevonden']);
                    exit;
                }
                
                if (strtotime($order['delivery_date']) <= strtotime('today')) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'Kan alleen toekomstige leveringen aanpassen']);
                    exit;
                }
                
                $pdo->beginTransaction();
                
                if ($skip) {
                    $pdo->prepare("UPDATE business_orders SET is_cancelled = 1 WHERE id = ?")->execute([$orderId]);
                    $pdo->commit();
                    echo json_encode(['success' => true, 'message' => 'Levering overgeslagen']);
                    exit;
                }
                
                if ($newNotes !== null) {
                    $pdo->prepare("UPDATE business_orders SET notes = ? WHERE id = ?")->execute([$newNotes, $orderId]);
                }
                
                if ($newItems !== null) {
                    $pdo->prepare("DELETE FROM business_order_items WHERE order_id = ?")->execute([$orderId]);
                    
                    $totalAmount = array_reduce($newItems, fn($sum, $i) => $sum + ($i['quantity'] * $i['unit_price']), 0);
                    $itemStmt = $pdo->prepare("INSERT INTO business_order_items (order_id, product_name, quantity, unit_price, variant_id, product_id) VALUES (?, ?, ?, ?, ?, ?)");

                    foreach ($newItems as $item) {
                        $itemStmt->execute([
                            $orderId,
                            $item['product_name'],
                            $item['quantity'],
                            $item['unit_price'],
                            !empty($item['variant_id']) ? intval($item['variant_id']) : null,
                            !empty($item['product_id']) ? intval($item['product_id']) : null
                        ]);
                    }
                    
                    $pdo->prepare("UPDATE business_orders SET total_amount = ? WHERE id = ?")->execute([$totalAmount, $orderId]);
                }
                
                $pdo->commit();
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Levering bijgewerkt'
                ]);
                
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Onbekende actie: ' . $action]);
            }
            
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Fout: ' . $e->getMessage()]);
        }
        break;
        
    default:
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
}

function itemsMatch($items1, $items2) {
    if (count($items1) !== count($items2)) return false;
    
    $normalize = function($items) {
        $result = [];
        foreach ($items as $item) {
            $key = strtolower($item['product_name']);
            $result[$key] = [
                'quantity' => (int)$item['quantity'],
                'unit_price' => round((float)$item['unit_price'], 2)
            ];
        }
        ksort($result);
        return $result;
    };
    
    return $normalize($items1) === $normalize($items2);
}

function calculateExtensionDates($startDate, $frequency, $endDate = null) {
    $dates = [];
    $current = new DateTime($startDate);
    
    if ($endDate) {
        $maxDate = new DateTime($endDate);
    } else {
        $maxDate = (clone $current)->modify('+3 months');
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
?>
