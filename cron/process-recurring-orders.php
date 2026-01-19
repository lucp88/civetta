<?php
/**
 * @deprecated Dit script is NIET MEER IN GEBRUIK
 * 
 * Recurring orders worden nu direct aangemaakt bij het plaatsen van de bestelling
 * in api/business-orders.php. Alle orders voor 3 maanden worden in één keer
 * aangemaakt in de business_orders tabel.
 * 
 * Gebruik in plaats hiervan:
 * - cron/recurring-renewal-reminder.php - voor verlengings-herinneringen
 * - cron/generate-monthly-invoices.php - voor maandelijkse facturatie
 * 
 * Dit bestand wordt bewaard voor referentie maar mag verwijderd worden.
 * 
 * ============================================================================
 * OUDE DOCUMENTATIE (niet meer van toepassing):
 * ============================================================================
 * Cron job voor het verwerken van terugkerende bestellingen
 * Dit script moet dagelijks draaien (bijv. om 06:00)
 * Crontab: 0 6 * * * /usr/bin/php /path/to/cron/process-recurring-orders.php
 */

die("Dit script is deprecated. Recurring orders worden nu direct aangemaakt bij bestelling.\n");

require_once __DIR__ . '/../admin/config.php';

$dryRun = in_array('--dry-run', $argv ?? []);
$verbose = in_array('-v', $argv ?? []) || in_array('--verbose', $argv ?? []);

function logMessage($message, $verbose = true) {
    global $verbose;
    if ($verbose) {
        echo date('Y-m-d H:i:s') . " - $message\n";
    }
    error_log("Recurring Orders: $message");
}

try {
    $targetDate = date('Y-m-d', strtotime('+2 days'));
    
    logMessage("Zoeken naar recurring orders met next_delivery_date = $targetDate");
    
    $stmt = $pdo->prepare("
        SELECT ro.*, ba.bedrijfsnaam, ba.email, ba.contactpersoon
        FROM business_recurring_orders ro
        JOIN business_accounts ba ON ro.account_id = ba.id
        WHERE ro.status = 'active'
        AND ro.next_delivery_date = ?
        AND (ro.end_date IS NULL OR ro.end_date >= ?)
    ");
    $stmt->execute([$targetDate, $targetDate]);
    $recurringOrders = $stmt->fetchAll();
    
    logMessage("Gevonden: " . count($recurringOrders) . " recurring order(s)");
    
    foreach ($recurringOrders as $recurring) {
        logMessage("Verwerken: #{$recurring['id']} - {$recurring['name']} voor {$recurring['bedrijfsnaam']}");
        
        $stmt = $pdo->prepare("SELECT * FROM business_recurring_order_items WHERE recurring_order_id = ?");
        $stmt->execute([$recurring['id']]);
        $items = $stmt->fetchAll();
        
        if (empty($items)) {
            logMessage("  SKIP: Geen items gevonden");
            continue;
        }
        
        $totalAmount = array_reduce($items, function($sum, $item) {
            return $sum + ($item['quantity'] * $item['unit_price']);
        }, 0);
        
        if ($dryRun) {
            logMessage("  DRY-RUN: Zou order aanmaken voor €" . number_format($totalAmount, 2));
            continue;
        }
        
        try {
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("
                INSERT INTO business_orders 
                (account_id, delivery_date, status, total_amount, notes, payment_type, recurring_order_id, is_auto_generated, created_at)
                VALUES (?, ?, 'pending_invoice', ?, ?, 'later', ?, 1, NOW())
            ");
            $stmt->execute([
                $recurring['account_id'],
                $recurring['next_delivery_date'],
                $totalAmount,
                $recurring['notes'] ?: 'Automatische terugkerende bestelling',
                $recurring['id']
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
            
            $nextDate = calculateNextDeliveryDate(
                $recurring['frequency'],
                $recurring['delivery_day'],
                $recurring['next_delivery_date']
            );
            
            $stmt = $pdo->prepare("
                UPDATE business_recurring_orders 
                SET next_delivery_date = ?
                WHERE id = ?
            ");
            $stmt->execute([$nextDate, $recurring['id']]);
            
            $pdo->commit();
            
            logMessage("  OK: Order #$orderId aangemaakt, volgende levering: $nextDate");
            
            sendNotificationEmail($recurring, $orderId, $totalAmount, $items);
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            logMessage("  ERROR: " . $e->getMessage());
        }
    }
    
    logMessage("Klaar!");
    
} catch (Exception $e) {
    logMessage("FATAL ERROR: " . $e->getMessage());
    exit(1);
}

function calculateNextDeliveryDate($frequency, $deliveryDay, $currentDate) {
    $current = new DateTime($currentDate);
    $daysOfWeek = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
    $targetDay = $daysOfWeek[$deliveryDay] ?? 'monday';
    
    if ($frequency === 'weekly') {
        $next = (clone $current)->modify('+1 week');
    } elseif ($frequency === 'biweekly') {
        $next = (clone $current)->modify('+2 weeks');
    } else {
        $next = (clone $current)->modify('+1 month');
    }
    
    while ($next->format('w') != $deliveryDay) {
        $next->modify('+1 day');
    }
    
    return $next->format('Y-m-d');
}

function sendNotificationEmail($recurring, $orderId, $totalAmount, $items) {
    $to = $recurring['email'];
    $subject = "Automatische bestelling #{$orderId} gepland";
    
    $itemsList = "";
    foreach ($items as $item) {
        $itemsList .= "- {$item['quantity']}x {$item['product_name']}\n";
    }
    
    $body = "Beste {$recurring['contactpersoon']},\n\n";
    $body .= "Uw terugkerende bestelling '{$recurring['name']}' is automatisch aangemaakt.\n\n";
    $body .= "Bestelling #{$orderId}\n";
    $body .= "Leverdatum: " . date('d-m-Y', strtotime($recurring['next_delivery_date'])) . "\n\n";
    $body .= "Producten:\n$itemsList\n";
    $body .= "Totaal: €" . number_format($totalAmount, 2, ',', '.') . "\n\n";
    $body .= "U ontvangt de factuur per e-mail.\n\n";
    $body .= "Wilt u deze bestelling aanpassen of annuleren? Ga naar uw dashboard:\n";
    $body .= "https://bakkerij-civetta.nl/mijn-bestellingen.html\n\n";
    $body .= "Met vriendelijke groet,\n";
    $body .= "Bakkerij Civetta";
    
    $headers = "From: noreply@bakkerij-civetta.nl\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    
    @mail($to, $subject, $body, $headers);
    
    $adminBody = "Automatische bestelling aangemaakt:\n\n";
    $adminBody .= "Bestelling #{$orderId}\n";
    $adminBody .= "Bedrijf: {$recurring['bedrijfsnaam']}\n";
    $adminBody .= "Terugkerende bestelling: {$recurring['name']}\n";
    $adminBody .= "Leverdatum: " . date('d-m-Y', strtotime($recurring['next_delivery_date'])) . "\n";
    $adminBody .= "Totaal: €" . number_format($totalAmount, 2, ',', '.') . "\n";
    
    @mail('laurens@bakkerij-civetta.nl', "Auto-bestelling #{$orderId} - {$recurring['bedrijfsnaam']}", $adminBody, $headers);
}
?>
