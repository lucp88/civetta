<?php
require_once __DIR__ . '/../admin/config.php';
require_once __DIR__ . '/../api/eboekhouden.php';

$verbose = in_array('-v', $argv) || in_array('--verbose', $argv);
$dryRun = in_array('--dry-run', $argv);

function logMessage($message, $verbose, $forceOutput = false) {
    $timestamp = date('Y-m-d H:i:s');
    $logLine = "[$timestamp] $message";
    
    $logFile = __DIR__ . '/check-payments.log';
    file_put_contents($logFile, $logLine . "\n", FILE_APPEND);
    
    if ($verbose || $forceOutput) {
        echo $logLine . "\n";
    }
}

logMessage("=== Start payment check ===", $verbose, true);

if ($dryRun) {
    logMessage("DRY RUN MODE - geen wijzigingen worden opgeslagen", $verbose, true);
}

$settings = getEBoekhoudenSettings($pdo);

if ($settings['facturatie_systeem'] !== 'eboekhouden') {
    logMessage("Facturatie systeem is niet e-Boekhouden, stoppen", $verbose, true);
    exit(0);
}

$client = getEBoekhoudenClient($pdo);
if (!$client) {
    logMessage("ERROR: Kon e-Boekhouden client niet initialiseren", $verbose, true);
    exit(1);
}

$stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'eboekhouden_bank_ledger_id'");
$bankLedgerId = $stmt->fetchColumn();

if (!$bankLedgerId) {
    logMessage("ERROR: eboekhouden_bank_ledger_id niet geconfigureerd in settings", $verbose, true);
    logMessage("Voeg deze setting toe met de ledger ID van je bankrekening (bijv. 1010)", $verbose, true);
    exit(1);
}

logMessage("Bank ledger ID: $bankLedgerId", $verbose);

$stmt = $pdo->prepare("
    SELECT bo.id, bo.eboekhouden_factuurnummer, bo.total_amount, bo.account_id, 
           ba.bedrijfsnaam, ba.email
    FROM business_orders bo
    JOIN business_accounts ba ON bo.account_id = ba.id
    WHERE bo.payment_status = 'pending'
    AND bo.payment_type = 'factuur'
    AND bo.invoice_status = 'gefactureerd'
    AND bo.is_cancelled = 0
    AND bo.eboekhouden_factuurnummer IS NOT NULL
    AND bo.eboekhouden_factuurnummer != ''
");
$stmt->execute();
$openOrders = $stmt->fetchAll();

$orderCount = count($openOrders);
logMessage("Gevonden: $orderCount openstaande factuurbestellingen", $verbose, true);

if ($orderCount === 0) {
    logMessage("Geen openstaande bestellingen om te checken", $verbose, true);
    exit(0);
}

$dateFrom = date('Y-m-d', strtotime('-60 days'));
logMessage("Ophalen bankmutaties vanaf $dateFrom...", $verbose);

try {
    $bankMutations = $client->getBankMutations($bankLedgerId, $dateFrom);
    logMessage("Ontvangen: " . count($bankMutations) . " bankmutaties", $verbose);
} catch (Exception $e) {
    logMessage("ERROR: Kon bankmutaties niet ophalen: " . $e->getMessage(), $verbose, true);
    exit(1);
}

if (empty($bankMutations)) {
    logMessage("Geen bankmutaties gevonden in de afgelopen 60 dagen", $verbose, true);
    exit(0);
}

$matchedCount = 0;
$updateStmt = $pdo->prepare("
    UPDATE business_orders 
    SET payment_status = 'paid',
        updated_at = NOW()
    WHERE id = ?
");

foreach ($openOrders as $order) {
    $invoiceNumber = $order['eboekhouden_factuurnummer'];
    $expectedAmount = $order['total_amount'];
    
    logMessage("Checking order #{$order['id']} - Factuur: $invoiceNumber, Bedrag: €$expectedAmount", $verbose);
    
    $match = $client->matchPaymentWithInvoice($bankMutations, $invoiceNumber, $expectedAmount);
    
    if ($match['matched']) {
        $matchedCount++;
        logMessage("  MATCH! Betaling gevonden op {$match['date']}: €{$match['amount']}", $verbose, true);
        
        if (!$dryRun) {
            $updateStmt->execute([$order['id']]);
            logMessage("  -> payment_status bijgewerkt naar 'paid'", $verbose);
            
            $to = $order['email'];
            $subject = "Betaling ontvangen - Factuur $invoiceNumber";
            $body = "Beste,\n\n";
            $body .= "Wij hebben uw betaling van €" . number_format($expectedAmount, 2, ',', '.') . " ontvangen voor factuur $invoiceNumber.\n\n";
            $body .= "Bedankt voor uw betaling!\n\n";
            $body .= "Met vriendelijke groet,\nBakkerij Civetta";
            
            $headers = "From: noreply@bakkerij-civetta.nl\r\n";
            $headers .= "Reply-To: info@bakkerij-civetta.nl\r\n";
            
            @mail($to, $subject, $body, $headers);
            logMessage("  -> Bevestigingsmail verzonden naar {$order['email']}", $verbose);
        } else {
            logMessage("  -> DRY RUN: zou payment_status updaten en mail sturen", $verbose);
        }
    } else {
        logMessage("  Geen match gevonden", $verbose);
    }
}

logMessage("=== Klaar ===", $verbose, true);
logMessage("Gematcht en bijgewerkt: $matchedCount van $orderCount bestellingen", $verbose, true);
