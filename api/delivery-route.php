<?php
require_once __DIR__ . '/../admin/config.php';
require_once __DIR__ . '/email-templates.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    
    if ($action === 'start_route') {
        $orderIds = $input['order_ids'] ?? [];
        $sendEmails = $input['send_emails'] ?? true;
        
        if (empty($orderIds)) {
            echo json_encode(['success' => false, 'error' => 'Geen bestellingen geselecteerd']);
            exit;
        }
        
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        $stmt = $pdo->prepare("
            SELECT bo.*, ba.bedrijfsnaam, ba.contactpersoon, ba.email, ba.telefoon, 
                   ba.adres, ba.postcode, ba.plaats,
                   ba.delivery_same_as_business, ba.delivery_adres, ba.delivery_postcode, ba.delivery_plaats
            FROM business_orders bo
            JOIN business_accounts ba ON bo.account_id = ba.id
            WHERE bo.id IN ($placeholders)
            ORDER BY FIELD(bo.id, $placeholders)
        ");
        $params = array_merge($orderIds, $orderIds);
        $stmt->execute($params);
        $orders = $stmt->fetchAll();
        
        if (empty($orders)) {
            echo json_encode(['success' => false, 'error' => 'Geen bestellingen gevonden']);
            exit;
        }
        
        $stmt = $pdo->prepare("UPDATE business_orders SET delivery_status = 'onderweg' WHERE id IN ($placeholders)");
        $stmt->execute($orderIds);
        
        $bedrijfSettings = [];
        $settingsStmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'bedrijf_%'");
        while ($row = $settingsStmt->fetch()) {
            $bedrijfSettings[$row['setting_key']] = $row['setting_value'];
        }
        
        $emailsSent = 0;
        $emailErrors = [];
        $deliveryCount = count($orders);
        
        if ($sendEmails) {
            foreach ($orders as $position => $order) {
                $itemsStmt = $pdo->prepare("SELECT product_name, quantity, unit_price FROM business_order_items WHERE order_id = ?");
                $itemsStmt->execute([$order['id']]);
                $items = $itemsStmt->fetchAll();
                
                $emailBody = buildDeliveryOnRouteEmail($order, $items, $bedrijfSettings, $deliveryCount, $position + 1);
                
                $sent = sendHtmlEmail(
                    $order['email'],
                    'Uw bestelling van Bakkerij Civetta is onderweg! 🚚',
                    $emailBody
                );
                
                if ($sent) {
                    $emailsSent++;
                } else {
                    $emailErrors[] = $order['bedrijfsnaam'];
                }
            }
        }
        
        $response = [
            'success' => true,
            'message' => count($orderIds) . ' bestellingen op onderweg gezet',
            'updated_count' => count($orderIds),
            'emails_sent' => $emailsSent
        ];
        
        if (!empty($emailErrors)) {
            $response['email_errors'] = $emailErrors;
        }
        
        echo json_encode($response);
        exit;
    }
    
    if ($action === 'mark_delivered') {
        $orderId = intval($input['order_id'] ?? 0);
        
        if (!$orderId) {
            echo json_encode(['success' => false, 'error' => 'Geen bestelling ID']);
            exit;
        }
        
        $stmt = $pdo->prepare("UPDATE business_orders SET delivery_status = 'afgeleverd' WHERE id = ?");
        $stmt->execute([$orderId]);
        
        echo json_encode(['success' => true, 'message' => 'Bestelling gemarkeerd als afgeleverd']);
        exit;
    }
    
    if ($action === 'update_order') {
        $orderId = intval($input['order_id'] ?? 0);
        $position = intval($input['position'] ?? 0);
        
        echo json_encode(['success' => true]);
        exit;
    }
    
    echo json_encode(['success' => false, 'error' => 'Onbekende actie']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $date = $_GET['date'] ?? date('Y-m-d');
    
    $stmt = $pdo->prepare("
        SELECT 
            bo.*, 
            ba.bedrijfsnaam, 
            ba.contactpersoon, 
            ba.email, 
            ba.telefoon, 
            ba.adres, 
            ba.postcode, 
            ba.plaats,
            ba.delivery_same_as_business,
            ba.delivery_adres,
            ba.delivery_postcode,
            ba.delivery_plaats
        FROM business_orders bo
        JOIN business_accounts ba ON bo.account_id = ba.id
        WHERE bo.delivery_date = ?
        AND bo.is_cancelled = 0
        ORDER BY bo.id ASC
    ");
    $stmt->execute([$date]);
    $orders = $stmt->fetchAll();
    
    foreach ($orders as &$order) {
        $itemsStmt = $pdo->prepare("SELECT product_name, quantity, unit_price FROM business_order_items WHERE order_id = ?");
        $itemsStmt->execute([$order['id']]);
        $order['items'] = $itemsStmt->fetchAll();
        
        if ($order['delivery_same_as_business'] || empty($order['delivery_adres'])) {
            $order['full_delivery_address'] = $order['adres'] . ', ' . $order['postcode'] . ' ' . $order['plaats'];
        } else {
            $order['full_delivery_address'] = $order['delivery_adres'] . ', ' . $order['delivery_postcode'] . ' ' . $order['delivery_plaats'];
        }
    }
    unset($order);
    
    $bakkerijAdres = '';
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('bedrijf_adres', 'bedrijf_postcode', 'bedrijf_plaats')");
    $settings = [];
    while ($row = $stmt->fetch()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    if (!empty($settings)) {
        $bakkerijAdres = trim(($settings['bedrijf_adres'] ?? '') . ', ' . ($settings['bedrijf_postcode'] ?? '') . ' ' . ($settings['bedrijf_plaats'] ?? ''));
    }
    
    echo json_encode([
        'success' => true,
        'date' => $date,
        'orders' => $orders,
        'start_address' => $bakkerijAdres
    ]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Methode niet ondersteund']);
