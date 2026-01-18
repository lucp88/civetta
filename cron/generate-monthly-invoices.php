<?php
/**
 * Maandelijkse facturatie cron voor terugkerende bestellingen
 * 
 * Dit script draait op de 1e van elke maand (bijv. om 08:00)
 * Het verzamelt alle recurring leveringen van de vorige maand per klant
 * en genereert één gebundelde factuur via e-Boekhouden
 * 
 * Crontab: 0 8 1 * * /usr/bin/php /path/to/cron/generate-monthly-invoices.php
 * 
 * CLI opties:
 *   --dry-run     Test zonder facturen aan te maken
 *   -v, --verbose Uitgebreide logging
 *   --month=YYYY-MM  Specifieke maand factureren (bijv. --month=2024-01)
 */

require_once __DIR__ . '/../admin/config.php';
require_once __DIR__ . '/../api/eboekhouden.php';

$dryRun = in_array('--dry-run', $argv ?? []);
$verbose = in_array('-v', $argv ?? []) || in_array('--verbose', $argv ?? []);

$customMonth = null;
foreach ($argv ?? [] as $arg) {
    if (strpos($arg, '--month=') === 0) {
        $customMonth = substr($arg, 8);
    }
}

function logMessage($message) {
    global $verbose;
    $timestamp = date('Y-m-d H:i:s');
    if ($verbose) {
        echo "$timestamp - $message\n";
    }
    error_log("Monthly Invoices: $message");
}

try {
    if ($customMonth) {
        $firstDayMonth = $customMonth . '-01';
        $lastDayMonth = date('Y-m-t', strtotime($firstDayMonth));
        $monthLabel = date('F Y', strtotime($firstDayMonth));
    } else {
        $firstDayMonth = date('Y-m-01', strtotime('-1 month'));
        $lastDayMonth = date('Y-m-t', strtotime('-1 month'));
        $monthLabel = date('F Y', strtotime('-1 month'));
    }
    
    logMessage("=== Maandelijkse facturatie voor $monthLabel ===");
    logMessage("Periode: $firstDayMonth t/m $lastDayMonth");
    
    if ($dryRun) {
        logMessage("*** DRY-RUN MODUS - Geen wijzigingen worden gemaakt ***");
    }
    
    $stmt = $pdo->prepare("
        SELECT 
            bo.*,
            ba.id as account_id,
            ba.bedrijfsnaam,
            ba.email,
            ba.kvk_nummer,
            ba.btw_nummer,
            ba.adres,
            ba.postcode,
            ba.plaats,
            ba.contactpersoon,
            ba.telefoon
        FROM business_orders bo
        JOIN business_accounts ba ON bo.account_id = ba.id
        WHERE bo.is_recurring = 1
        AND bo.status IN ('recurring_pending', 'delivered')
        AND bo.delivery_date BETWEEN ? AND ?
        ORDER BY bo.account_id, bo.delivery_date
    ");
    $stmt->execute([$firstDayMonth, $lastDayMonth]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($orders)) {
        logMessage("Geen recurring orders gevonden voor deze periode.");
        exit(0);
    }
    
    logMessage("Gevonden: " . count($orders) . " recurring order(s)");
    
    $ordersByAccount = [];
    foreach ($orders as $order) {
        $accountId = $order['account_id'];
        if (!isset($ordersByAccount[$accountId])) {
            $ordersByAccount[$accountId] = [
                'account' => [
                    'id' => $order['account_id'],
                    'bedrijfsnaam' => $order['bedrijfsnaam'],
                    'email' => $order['email'],
                    'kvk_nummer' => $order['kvk_nummer'],
                    'btw_nummer' => $order['btw_nummer'],
                    'adres' => $order['adres'],
                    'postcode' => $order['postcode'],
                    'plaats' => $order['plaats'],
                    'contactpersoon' => $order['contactpersoon'],
                    'telefoon' => $order['telefoon']
                ],
                'orders' => []
            ];
        }
        $ordersByAccount[$accountId]['orders'][] = $order;
    }
    
    logMessage("Aantal klanten te factureren: " . count($ordersByAccount));
    
    $successCount = 0;
    $errorCount = 0;
    
    foreach ($ordersByAccount as $accountId => $data) {
        $account = $data['account'];
        $accountOrders = $data['orders'];
        
        logMessage("");
        logMessage("--- Verwerken: {$account['bedrijfsnaam']} ---");
        logMessage("  Aantal leveringen: " . count($accountOrders));
        
        $totalAmount = 0;
        $allItems = [];
        $orderIds = [];
        
        foreach ($accountOrders as $order) {
            $orderIds[] = $order['id'];
            $totalAmount += floatval($order['total_amount']);
            
            $stmt = $pdo->prepare("SELECT * FROM business_order_items WHERE order_id = ?");
            $stmt->execute([$order['id']]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($items as $item) {
                $item['delivery_date'] = $order['delivery_date'];
                $item['order_id'] = $order['id'];
                $allItems[] = $item;
            }
            
            logMessage("  - Order #{$order['id']} ({$order['delivery_date']}): €" . number_format($order['total_amount'], 2));
        }
        
        logMessage("  Totaal: €" . number_format($totalAmount, 2));
        
        if ($dryRun) {
            logMessage("  DRY-RUN: Zou factuur aanmaken voor €" . number_format($totalAmount, 2));
            $successCount++;
            continue;
        }
        
        try {
            $invoiceLines = buildInvoiceLines($allItems, $accountOrders, $monthLabel);
            
            $invoiceData = [
                'Relatiecode' => 'B' . str_pad($accountId, 5, '0', STR_PAD_LEFT),
                'Datum' => date('Y-m-d'),
                'Betalingstermijn' => 14,
                'Factuursjabloon' => '',
                'PerEmailVerzwordsn' => 1,
                'EmailOnderwerp' => "Factuur $monthLabel - Bakkerij Civetta",
                'EmailBericht' => buildEmailMessage($account, $monthLabel, $totalAmount, count($accountOrders)),
                'EmailVanAdres' => 'facturen@bakkerij-civetta.nl',
                'EmailVanNaam' => 'Bakkerij Civetta',
                'Regels' => $invoiceLines
            ];
            
            $result = createEBoekhoudenFactuur($invoiceData);
            
            if ($result && isset($result['Factuurnummer'])) {
                $invoiceNumber = $result['Factuurnummer'];
                
                $updateStmt = $pdo->prepare("
                    UPDATE business_orders 
                    SET status = 'invoiced', 
                        invoice_number = ?,
                        invoiced_at = NOW()
                    WHERE id IN (" . implode(',', array_map('intval', $orderIds)) . ")
                ");
                $updateStmt->execute([$invoiceNumber]);
                
                $logStmt = $pdo->prepare("
                    INSERT INTO invoice_log (account_id, invoice_number, total_amount, order_count, period_start, period_end, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, NOW())
                ");
                $logStmt->execute([
                    $accountId,
                    $invoiceNumber,
                    $totalAmount,
                    count($orderIds),
                    $firstDayMonth,
                    $lastDayMonth
                ]);
                
                logMessage("  OK: Factuur $invoiceNumber aangemaakt en verzonden");
                $successCount++;
                
            } else {
                throw new Exception("Geen factuurnummer ontvangen van e-Boekhouden");
            }
            
        } catch (Exception $e) {
            logMessage("  ERROR: " . $e->getMessage());
            $errorCount++;
            
            $pdo->prepare("
                UPDATE business_orders 
                SET status = 'invoice_error',
                    notes = CONCAT(COALESCE(notes, ''), '\nFactuurfout: " . addslashes($e->getMessage()) . "')
                WHERE id IN (" . implode(',', array_map('intval', $orderIds)) . ")
            ")->execute();
        }
    }
    
    logMessage("");
    logMessage("=== Samenvatting ===");
    logMessage("Succesvol: $successCount");
    logMessage("Fouten: $errorCount");
    logMessage("Klaar!");
    
    if ($errorCount > 0) {
        $adminBody = "Maandelijkse facturatie $monthLabel afgerond.\n\n";
        $adminBody .= "Succesvol: $successCount\n";
        $adminBody .= "Fouten: $errorCount\n\n";
        $adminBody .= "Controleer de error log voor details.";
        
        $headers = "From: noreply@bakkerij-civetta.nl\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        
        @mail('laurens@bakkerij-civetta.nl', "Facturatie $monthLabel - $errorCount fouten", $adminBody, $headers);
    }
    
} catch (Exception $e) {
    logMessage("FATAL ERROR: " . $e->getMessage());
    exit(1);
}

function buildInvoiceLines($allItems, $orders, $monthLabel) {
    $lines = [];
    
    $lines[] = [
        'Aantal' => 1,
        'Eenheid' => '',
        'Code' => '',
        'Omschrijving' => "Leveringen $monthLabel",
        'PrijsPerEenheid' => 0,
        'BTWCode' => 'GEEN',
        'TeserialiseLevering' => ''
    ];
    
    $itemsByDate = [];
    foreach ($allItems as $item) {
        $date = $item['delivery_date'];
        if (!isset($itemsByDate[$date])) {
            $itemsByDate[$date] = [];
        }
        $itemsByDate[$date][] = $item;
    }
    
    ksort($itemsByDate);
    
    foreach ($itemsByDate as $date => $items) {
        $formattedDate = date('d-m-Y', strtotime($date));
        
        $lines[] = [
            'Aantal' => 1,
            'Eenheid' => '',
            'Code' => '',
            'Omschrijving' => "--- Levering $formattedDate ---",
            'PrijsPerEenheid' => 0,
            'BTWCode' => 'GEEN',
            'TegenrekeningCode' => ''
        ];
        
        foreach ($items as $item) {
            $lines[] = [
                'Aantal' => floatval($item['quantity']),
                'Eenheid' => 'stuk',
                'Code' => $item['product_code'] ?? '',
                'Omschrijving' => $item['product_name'],
                'PrijsPerEenheid' => floatval($item['unit_price']),
                'BTWCode' => 'LAAG_9',
                'TegenrekeningCode' => '8000'
            ];
        }
    }
    
    return $lines;
}

function buildEmailMessage($account, $monthLabel, $totalAmount, $orderCount) {
    $message = "Beste {$account['contactpersoon']},\n\n";
    $message .= "Bijgaand ontvangt u de factuur voor uw leveringen in $monthLabel.\n\n";
    $message .= "Overzicht:\n";
    $message .= "- Aantal leveringen: $orderCount\n";
    $message .= "- Totaalbedrag: €" . number_format($totalAmount, 2, ',', '.') . "\n\n";
    $message .= "De betalingstermijn is 14 dagen.\n\n";
    $message .= "Heeft u vragen over deze factuur? Neem gerust contact met ons op.\n\n";
    $message .= "Met vriendelijke groet,\n";
    $message .= "Bakkerij Civetta\n";
    $message .= "Tel: 020-1234567\n";
    $message .= "Email: info@bakkerij-civetta.nl";
    
    return $message;
}
?>
