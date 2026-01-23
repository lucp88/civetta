<?php
/**
 * Cronjob: Automatische Facturatie
 * 
 * Dit script factureert bestellingen automatisch op basis van de instellingen:
 * - facturatie_moment: voor_leverdag / op_leverdag / na_leverdag
 * - facturatie_dagen_offset: aantal dagen offset
 * - facturatie_uur: tijdstip (voor scheduling)
 * 
 * Uitgesloten van automatische facturatie:
 * - Bestellingen die al gefactureerd zijn
 * - Bestellingen betaald via iDeal (die worden direct gefactureerd)
 * - Geannuleerde bestellingen
 * 
 * Draai dit script elk uur via cron:
 * 0 * * * * php /path/to/cron/auto-invoice.php
 */

require_once __DIR__ . '/../admin/config.php';
require_once __DIR__ . '/../api/eboekhouden.php';

function cronLog($message) {
    $logFile = __DIR__ . '/auto-invoice.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
    echo "[$timestamp] $message\n";
}

function getFacturatieSettings($pdo) {
    $settings = [];
    $keys = [
        'facturatie_systeem',
        'facturatie_moment',
        'facturatie_uur',
        'facturatie_dagen_offset',
        'eboekhouden_api_token',
        'eboekhouden_template_id_openstaand',
        'eboekhouden_ledger_id',
        'eboekhouden_debiteuren_ledger_id',
        'btw_tarief'
    ];
    
    foreach ($keys as $key) {
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $settings[$key] = $stmt->fetchColumn() ?: '';
    }
    
    return $settings;
}

function shouldRunNow($settings) {
    $configuredHour = intval(substr($settings['facturatie_uur'] ?: '17:00', 0, 2));
    $currentHour = intval(date('H'));
    
    return $currentHour === $configuredHour;
}

function getOrdersToInvoice($pdo, $settings) {
    $moment = $settings['facturatie_moment'] ?: 'op_leverdag';
    $offset = intval($settings['facturatie_dagen_offset'] ?: 0);
    
    $today = new DateTime();
    
    switch ($moment) {
        case 'voor_leverdag':
            $targetDate = (clone $today)->modify("+{$offset} days");
            break;
        case 'na_leverdag':
            $targetDate = (clone $today)->modify("-{$offset} days");
            break;
        case 'op_leverdag':
        default:
            $targetDate = $today;
            break;
    }
    
    $targetDateStr = $targetDate->format('Y-m-d');
    
    cronLog("Zoeken naar orders met leverdatum: $targetDateStr (moment: $moment, offset: $offset dagen)");
    
    $stmt = $pdo->prepare("
        SELECT bo.*, ba.bedrijfsnaam, ba.adres, ba.postcode, ba.plaats,
               ba.contactpersoon, ba.email, ba.telefoon, ba.kvk_nummer, ba.btw_id
        FROM business_orders bo
        JOIN business_accounts ba ON bo.account_id = ba.id
        WHERE bo.delivery_date = ?
          AND bo.payment_type = 'factuur'
          AND (bo.eboekhouden_invoice_id IS NULL OR bo.eboekhouden_invoice_id = '')
          AND (bo.invoice_number IS NULL OR bo.invoice_number = '')
          AND (bo.is_cancelled IS NULL OR bo.is_cancelled = 0)
          AND bo.payment_status != 'paid'
    ");
    $stmt->execute([$targetDateStr]);
    
    return $stmt->fetchAll();
}

function createInvoiceForOrder($pdo, $order, $settings) {
    $orderId = $order['id'];
    cronLog("Factuur aanmaken voor order #$orderId ({$order['bedrijfsnaam']})");
    
    $stmt = $pdo->prepare("SELECT product_name, quantity, unit_price FROM business_order_items WHERE order_id = ?");
    $stmt->execute([$orderId]);
    $items = $stmt->fetchAll();
    
    if (empty($items)) {
        cronLog("  WAARSCHUWING: Geen items gevonden voor order #$orderId");
        return false;
    }
    
    if ($settings['facturatie_systeem'] === 'eboekhouden' && !empty($settings['eboekhouden_api_token'])) {
        return createEBoekhoudenInvoice($pdo, $order, $items, $settings);
    } else {
        return createLocalInvoice($pdo, $order, $items, $settings);
    }
}

function createEBoekhoudenInvoice($pdo, $order, $items, $settings) {
    $orderId = $order['id'];
    
    try {
        $client = new EBoekhoudenClient($settings['eboekhouden_api_token']);
        
        $btwCode = 'LAAG_VERK_9';
        if (floatval($settings['btw_tarief']) == 21) {
            $btwCode = 'HOOG_VERK_21';
        }
        
        $accountData = [
            'email' => $order['email'],
            'bedrijfsnaam' => $order['bedrijfsnaam'],
            'contactpersoon' => $order['contactpersoon'],
            'adres' => $order['adres'],
            'postcode' => $order['postcode'],
            'plaats' => $order['plaats'],
            'telefoon' => $order['telefoon'],
            'kvk_nummer' => $order['kvk_nummer'],
            'btw_id' => $order['btw_id']
        ];
        
        $orderItems = [];
        foreach ($items as $item) {
            $orderItems[] = [
                'product_name' => $item['product_name'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price']
            ];
        }
        
        $result = $client->createFullInvoice(
            $accountData,
            $orderItems,
            $settings['eboekhouden_template_id_openstaand'],
            $settings['eboekhouden_ledger_id'],
            $btwCode,
            true,
            $settings['eboekhouden_debiteuren_ledger_id'] ?: null
        );
        
        $stmt = $pdo->prepare("
            UPDATE business_orders 
            SET eboekhouden_invoice_id = ?, 
                eboekhouden_factuurnummer = ?, 
                eboekhouden_pdf_url = ?,
                facturatie_systeem = 'eboekhouden',
                invoiced_at = NOW(),
                order_status = 'afgeleverd'
            WHERE id = ?
        ");
        $stmt->execute([
            $result['id'],
            $result['invoiceNumber'],
            $result['pdfUrl'],
            $orderId
        ]);
        
        cronLog("  ✓ e-Boekhouden factuur aangemaakt: {$result['invoiceNumber']}");
        return true;
        
    } catch (Exception $e) {
        cronLog("  ✗ FOUT bij e-Boekhouden factuur: " . $e->getMessage());
        return false;
    }
}

function createLocalInvoice($pdo, $order, $items, $settings) {
    $orderId = $order['id'];
    
    $invoiceNumber = 'F' . date('Y') . '-' . str_pad($orderId, 4, '0', STR_PAD_LEFT);
    
    $stmt = $pdo->prepare("
        UPDATE business_orders 
        SET invoice_number = ?,
            invoiced_at = NOW(),
            facturatie_systeem = 'eigen',
            order_status = 'afgeleverd'
        WHERE id = ?
    ");
    $stmt->execute([$invoiceNumber, $orderId]);
    
    require_once __DIR__ . '/../api/factuur.php';
    
    $facturenDir = __DIR__ . '/../facturen';
    if (!is_dir($facturenDir)) {
        mkdir($facturenDir, 0755, true);
    }
    
    $factuurFile = $facturenDir . '/factuur-' . $orderId . '.pdf';
    generateFactuur($pdo, $orderId, $factuurFile);
    
    $emailSent = sendFactuurEmail($pdo, $orderId);
    
    if ($emailSent) {
        cronLog("  ✓ Lokale factuur aangemaakt en verstuurd: $invoiceNumber");
    } else {
        cronLog("  ⚠ Lokale factuur aangemaakt maar email kon niet worden verstuurd: $invoiceNumber");
    }
    
    return true;
}

cronLog("=== Automatische Facturatie Gestart ===");

$settings = getFacturatieSettings($pdo);

if (!shouldRunNow($settings)) {
    $configuredHour = substr($settings['facturatie_uur'] ?: '17:00', 0, 5);
    cronLog("Niet het juiste tijdstip. Geconfigureerd: $configuredHour, nu: " . date('H:i'));
    cronLog("Script wordt overgeslagen.");
    exit(0);
}

cronLog("Facturatie-instellingen:");
cronLog("  - Systeem: " . ($settings['facturatie_systeem'] ?: 'eigen'));
cronLog("  - Moment: " . ($settings['facturatie_moment'] ?: 'op_leverdag'));
cronLog("  - Dagen offset: " . ($settings['facturatie_dagen_offset'] ?: '0'));
cronLog("  - Uur: " . ($settings['facturatie_uur'] ?: '17:00'));

$orders = getOrdersToInvoice($pdo, $settings);

if (empty($orders)) {
    cronLog("Geen orders gevonden om te factureren.");
    cronLog("=== Automatische Facturatie Voltooid ===");
    exit(0);
}

cronLog("Gevonden orders: " . count($orders));

$success = 0;
$failed = 0;

foreach ($orders as $order) {
    $result = createInvoiceForOrder($pdo, $order, $settings);
    if ($result) {
        $success++;
    } else {
        $failed++;
    }
}

cronLog("=== Resultaat ===");
cronLog("Succesvol: $success");
cronLog("Mislukt: $failed");
cronLog("=== Automatische Facturatie Voltooid ===");
