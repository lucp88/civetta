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
            $stmt = $pdo->prepare("
                SELECT 
                    id,
                    recurring_name as name,
                    recurring_frequency as frequency,
                    recurring_day as delivery_day,
                    delivery_date as next_delivery_date,
                    recurring_end_date as end_date,
                    total_amount,
                    status,
                    notes,
                    created_at
                FROM business_orders 
                WHERE account_id = ? 
                AND is_recurring = 1 
                AND status NOT IN ('cancelled', 'invoiced')
                ORDER BY delivery_date ASC
            ");
            $stmt->execute([$accountId]);
            $recurring = $stmt->fetchAll();
            
            foreach ($recurring as &$order) {
                $stmt = $pdo->prepare("
                    SELECT product_name, quantity, unit_price 
                    FROM business_order_items 
                    WHERE order_id = ?
                ");
                $stmt->execute([$order['id']]);
                $order['items'] = $stmt->fetchAll();
                
                if ($order['status'] === 'recurring_pending') {
                    $order['status'] = 'active';
                }
            }
            unset($order);
            
            $debugStmt = $pdo->prepare("SELECT id, status, is_recurring, recurring_name FROM business_orders WHERE account_id = ?");
            $debugStmt->execute([$accountId]);
            $debugOrders = $debugStmt->fetchAll();
            
            echo json_encode([
                'success' => true, 
                'recurring_orders' => $recurring, 
                'debug_account_id' => $accountId,
                'debug_all_orders' => $debugOrders
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Database fout: ' . $e->getMessage()]);
        }
        break;
        
    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        
        $name = trim($data['name'] ?? '');
        $frequency = $data['frequency'] ?? 'weekly';
        $deliveryDay = intval($data['delivery_day'] ?? 1);
        $items = $data['items'] ?? [];
        $notes = trim($data['notes'] ?? '');
        $endDate = $data['end_date'] ?? null;
        
        if (empty($name) || empty($items)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Naam en producten zijn verplicht']);
            exit;
        }
        
        if (!in_array($frequency, ['weekly', 'biweekly', 'monthly'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Ongeldige frequentie']);
            exit;
        }
        
        $nextDeliveryDate = calculateNextDeliveryDate($frequency, $deliveryDay);
        
        try {
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("
                INSERT INTO business_recurring_orders 
                (account_id, name, frequency, delivery_day, next_delivery_date, end_date, notes, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'active')
            ");
            $stmt->execute([
                $accountId, 
                $name, 
                $frequency, 
                $deliveryDay, 
                $nextDeliveryDate,
                $endDate ?: null,
                $notes
            ]);
            $recurringId = $pdo->lastInsertId();
            
            $stmt = $pdo->prepare("
                INSERT INTO business_recurring_order_items 
                (recurring_order_id, product_id, product_name, quantity, unit_price)
                VALUES (?, ?, ?, ?, ?)
            ");
            
            foreach ($items as $item) {
                $stmt->execute([
                    $recurringId,
                    $item['product_id'] ?? null,
                    $item['product_name'],
                    $item['quantity'],
                    $item['unit_price']
                ]);
            }
            
            $pdo->commit();
            
            echo json_encode([
                'success' => true, 
                'recurring_order_id' => $recurringId,
                'next_delivery_date' => $nextDeliveryDate
            ]);
        } catch (PDOException $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Database fout']);
        }
        break;
        
    case 'PUT':
        $data = json_decode(file_get_contents('php://input'), true);
        $recurringId = $data['id'] ?? null;
        $action = $data['action'] ?? 'update';
        
        if (!$recurringId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'ID ontbreekt']);
            exit;
        }
        
        $stmt = $pdo->prepare("SELECT * FROM business_orders WHERE id = ? AND account_id = ? AND is_recurring = 1");
        $stmt->execute([$recurringId, $accountId]);
        $existing = $stmt->fetch();
        
        if (!$existing) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Terugkerende bestelling niet gevonden']);
            exit;
        }
        
        try {
            if ($action === 'pause') {
                $stmt = $pdo->prepare("UPDATE business_orders SET status = 'recurring_paused' WHERE id = ?");
                $stmt->execute([$recurringId]);
                echo json_encode(['success' => true, 'message' => 'Bestelling gepauzeerd']);
                
            } elseif ($action === 'resume') {
                $nextDate = calculateNextDeliveryDate($existing['recurring_frequency'], $existing['recurring_day']);
                $stmt = $pdo->prepare("UPDATE business_orders SET status = 'recurring_pending', delivery_date = ? WHERE id = ?");
                $stmt->execute([$nextDate, $recurringId]);
                echo json_encode(['success' => true, 'message' => 'Bestelling hervat', 'next_delivery_date' => $nextDate]);
                
            } elseif ($action === 'cancel') {
                $stmt = $pdo->prepare("UPDATE business_orders SET status = 'cancelled' WHERE id = ?");
                $stmt->execute([$recurringId]);
                echo json_encode(['success' => true, 'message' => 'Bestelling geannuleerd']);
                
            } else {
                $name = trim($data['name'] ?? $existing['recurring_name']);
                $frequency = $data['frequency'] ?? $existing['recurring_frequency'];
                $deliveryDay = intval($data['delivery_day'] ?? $existing['recurring_day']);
                $notes = trim($data['notes'] ?? $existing['notes']);
                $endDate = $data['end_date'] ?? $existing['recurring_end_date'];
                $items = $data['items'] ?? null;
                
                $pdo->beginTransaction();
                
                $recalculate = ($frequency !== $existing['recurring_frequency'] || $deliveryDay !== intval($existing['recurring_day']));
                $nextDate = $recalculate ? calculateNextDeliveryDate($frequency, $deliveryDay) : $existing['delivery_date'];
                
                $stmt = $pdo->prepare("
                    UPDATE business_orders 
                    SET recurring_name = ?, recurring_frequency = ?, recurring_day = ?, delivery_date = ?, recurring_end_date = ?, notes = ?
                    WHERE id = ?
                ");
                $stmt->execute([$name, $frequency, $deliveryDay, $nextDate, $endDate ?: null, $notes, $recurringId]);
                
                if ($items !== null) {
                    $pdo->prepare("DELETE FROM business_order_items WHERE order_id = ?")->execute([$recurringId]);
                    
                    $totalAmount = 0;
                    $stmt = $pdo->prepare("
                        INSERT INTO business_order_items 
                        (order_id, product_id, product_name, quantity, unit_price)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    
                    foreach ($items as $item) {
                        $stmt->execute([
                            $recurringId,
                            $item['product_id'] ?? null,
                            $item['product_name'],
                            $item['quantity'],
                            $item['unit_price']
                        ]);
                        $totalAmount += $item['quantity'] * $item['unit_price'];
                    }
                    
                    $pdo->prepare("UPDATE business_orders SET total_amount = ? WHERE id = ?")->execute([$totalAmount, $recurringId]);
                }
                
                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Bestelling bijgewerkt', 'next_delivery_date' => $nextDate]);
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Database fout']);
        }
        break;
        
    case 'DELETE':
        $recurringId = $_GET['id'] ?? null;
        
        if (!$recurringId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'ID ontbreekt']);
            exit;
        }
        
        try {
            $stmt = $pdo->prepare("UPDATE business_recurring_orders SET status = 'cancelled' WHERE id = ? AND account_id = ?");
            $stmt->execute([$recurringId, $accountId]);
            
            echo json_encode(['success' => true, 'message' => 'Terugkerende bestelling geannuleerd']);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Database fout']);
        }
        break;
        
    default:
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
}

function calculateNextDeliveryDate($frequency, $deliveryDay) {
    $today = new DateTime();
    $minDate = (clone $today)->modify('+2 days');
    
    $daysOfWeek = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
    $targetDay = $daysOfWeek[$deliveryDay] ?? 'monday';
    
    $next = (clone $today)->modify("next $targetDay");
    
    if ($next <= $minDate) {
        if ($frequency === 'weekly') {
            $next->modify('+1 week');
        } elseif ($frequency === 'biweekly') {
            $next->modify('+2 weeks');
        } else {
            $next->modify('+1 month');
        }
    }
    
    return $next->format('Y-m-d');
}
?>
