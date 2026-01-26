<?php
require_once '../admin/config.php';
require_once 'cors.php';
require_once 'delivery-status.php';
require_once 'bestelbon.php';

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
                $group['needs_renewal'] = $group['confirmed_until'] && 
                    strtotime($group['confirmed_until']) <= strtotime('+14 days') &&
                    $group['is_active'];
                
                $stmt = $pdo->prepare("
                    SELECT id, delivery_date, payment_status, payment_type, is_cancelled, total_amount, delivery_status
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
                }
                unset($delivery);
                $group['upcoming_deliveries'] = $upcomingDeliveries;
                
                $stmt = $pdo->prepare("
                    SELECT product_name, quantity, unit_price 
                    FROM business_order_items 
                    WHERE order_id = (
                        SELECT id FROM business_orders 
                        WHERE recurring_group_id = ? 
                        ORDER BY id ASC LIMIT 1
                    )
                ");
                $stmt->execute([$group['recurring_group_id']]);
                $group['items'] = $stmt->fetchAll();
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
                
                echo json_encode([
                    'success' => true, 
                    'message' => "Terugkerende bestelling gestopt. $cancelled toekomstige leveringen geannuleerd."
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
                    INSERT INTO business_order_items (order_id, product_name, quantity, unit_price)
                    VALUES (?, ?, ?, ?)
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
                            $item['unit_price']
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
                    
                    if (!empty($futureOrderIds)) {
                        $placeholders = implode(',', array_fill(0, count($futureOrderIds), '?'));
                        $pdo->prepare("DELETE FROM business_order_items WHERE order_id IN ($placeholders)")->execute($futureOrderIds);
                        
                        $totalAmount = array_reduce($newItems, fn($sum, $i) => $sum + ($i['quantity'] * $i['unit_price']), 0);
                        $itemStmt = $pdo->prepare("INSERT INTO business_order_items (order_id, product_name, quantity, unit_price) VALUES (?, ?, ?, ?)");
                        
                        foreach ($futureOrderIds as $orderId) {
                            foreach ($newItems as $item) {
                                $itemStmt->execute([
                                    $orderId,
                                    $item['product_name'],
                                    $item['quantity'],
                                    $item['unit_price']
                                ]);
                            }
                        }
                        
                        $pdo->prepare("UPDATE business_orders SET total_amount = ? WHERE id IN ($placeholders)")->execute(array_merge([$totalAmount], $futureOrderIds));
                    }
                }
                
                $pdo->commit();
                
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
                    $itemStmt = $pdo->prepare("INSERT INTO business_order_items (order_id, product_name, quantity, unit_price) VALUES (?, ?, ?, ?)");
                    
                    foreach ($newItems as $item) {
                        $itemStmt->execute([
                            $orderId,
                            $item['product_name'],
                            $item['quantity'],
                            $item['unit_price']
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
