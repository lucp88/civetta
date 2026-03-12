<?php
/**
 * Delivery Status Helper Functions
 * 
 * Centrale plek voor delivery status logica.
 * 
 * Statussen:
 * - geplaatst: Bestelling is geplaatst, nog niet in bereiding
 * - wordt_bereid: Binnen deadline uren voor levering (standaard 48u)
 * - onderweg: Op de leverdag (vanaf 00:00)
 * - afgeleverd: Na 17:00 op leverdag
 */

function getWijzigDeadlineUren($pdo) {
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'bestel_wijzig_deadline_uren'");
    $stmt->execute();
    return intval($stmt->fetchColumn() ?: 48);
}

function calculateDeliveryStatus($deliveryDate, $deadlineHours) {
    $now = new DateTime();
    $delivery = new DateTime($deliveryDate);
    $deliveryEnd = (clone $delivery)->setTime(17, 0, 0);
    $deliveryStart = (clone $delivery)->setTime(0, 0, 0);
    $prepDeadline = (clone $delivery)->modify("-{$deadlineHours} hours");
    
    if ($now >= $deliveryEnd) {
        return 'afgeleverd';
    }
    
    if ($now >= $deliveryStart && $now < $deliveryEnd) {
        return 'onderweg';
    }
    
    if ($now >= $prepDeadline) {
        return 'wordt_bereid';
    }
    
    return 'geplaatst';
}

function updateDeliveryStatusIfNeeded($pdo, $orderId, $deliveryDate, $currentStatus, $isCancelled) {
    if ($isCancelled) return $currentStatus;
    
    $deadlineHours = getWijzigDeadlineUren($pdo);
    $calculatedStatus = calculateDeliveryStatus($deliveryDate, $deadlineHours);
    
    if ($calculatedStatus !== $currentStatus) {
        $stmt = $pdo->prepare("UPDATE business_orders SET delivery_status = ? WHERE id = ?");
        $stmt->execute([$calculatedStatus, $orderId]);
        return $calculatedStatus;
    }
    
    return $currentStatus;
}

function updateAllDeliveryStatuses($pdo) {
    $deadlineHours = getWijzigDeadlineUren($pdo);
    
    $stmt = $pdo->query("
        SELECT id, delivery_date, delivery_status, is_cancelled 
        FROM business_orders 
        WHERE is_cancelled = 0 
          AND delivery_status != 'afgeleverd'
    ");
    $orders = $stmt->fetchAll();
    
    $updated = 0;
    foreach ($orders as $order) {
        $newStatus = calculateDeliveryStatus($order['delivery_date'], $deadlineHours);
        if ($newStatus !== $order['delivery_status']) {
            $updateStmt = $pdo->prepare("UPDATE business_orders SET delivery_status = ? WHERE id = ?");
            $updateStmt->execute([$newStatus, $order['id']]);
            $updated++;
        }
    }
    
    return $updated;
}
