<?php
require_once __DIR__ . '/../admin/config.php';
require_once __DIR__ . '/../api/eboekhouden.php';
requireLogin();

$executeAction = $_POST['execute'] ?? null;
$dryRun = isset($_POST['dry_run']) || isset($_GET['dry_run']);
$executionResults = [];

header('Content-Type: text/html; charset=utf-8');

function executePaymentCheck($pdo, $dryRun, &$results) {
    $settings = getEBoekhoudenSettings($pdo);
    
    if ($settings['facturatie_systeem'] !== 'eboekhouden') {
        $results[] = ['type' => 'warn', 'message' => 'Facturatie systeem is niet e-Boekhouden'];
        return;
    }
    
    $client = getEBoekhoudenClient($pdo);
    if (!$client) {
        $results[] = ['type' => 'error', 'message' => 'Kon e-Boekhouden client niet initialiseren'];
        return;
    }
    
    $stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'eboekhouden_bank_ledger_id'");
    $bankLedgerId = $stmt->fetchColumn();
    
    if (!$bankLedgerId) {
        $results[] = ['type' => 'error', 'message' => 'eboekhouden_bank_ledger_id niet geconfigureerd'];
        return;
    }
    
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
    
    if (empty($openOrders)) {
        $results[] = ['type' => 'warn', 'message' => 'Geen openstaande factuurbestellingen gevonden'];
        return;
    }
    
    $dateFrom = date('Y-m-d', strtotime('-60 days'));
    
    try {
        $bankMutations = $client->getBankMutations($bankLedgerId, $dateFrom);
        $results[] = ['type' => 'ok', 'message' => count($bankMutations) . ' bankmutaties opgehaald (vanaf ' . $dateFrom . ')'];
    } catch (Exception $e) {
        $results[] = ['type' => 'error', 'message' => 'Kon bankmutaties niet ophalen: ' . $e->getMessage()];
        return;
    }
    
    if (empty($bankMutations)) {
        $results[] = ['type' => 'warn', 'message' => 'Geen bankmutaties gevonden in de afgelopen 60 dagen'];
        return;
    }
    
    $updateStmt = $pdo->prepare("
        UPDATE business_orders 
        SET payment_status = 'paid', updated_at = NOW()
        WHERE id = ?
    ");
    
    $matchedCount = 0;
    foreach ($openOrders as $order) {
        $invoiceNumber = $order['eboekhouden_factuurnummer'];
        $expectedAmount = $order['total_amount'];
        
        $match = $client->matchPaymentWithInvoice($bankMutations, $invoiceNumber, $expectedAmount);
        
        if ($match['matched']) {
            $matchedCount++;
            if (!$dryRun) {
                $updateStmt->execute([$order['id']]);
                
                $to = $order['email'];
                $subject = "Betaling ontvangen - Factuur $invoiceNumber";
                $body = "Beste,\n\nWij hebben uw betaling van €" . number_format($expectedAmount, 2, ',', '.') . " ontvangen voor factuur $invoiceNumber.\n\nBedankt voor uw betaling!\n\nMet vriendelijke groet,\nBakkerij Civetta";
                $headers = "From: noreply@bakkerij-civetta.nl\r\nReply-To: laurens@bakkerij-civetta.nl\r\n";
                @mail($to, $subject, $body, $headers);
                
                $results[] = ['type' => 'ok', 'message' => "Order #{$order['id']} ({$order['bedrijfsnaam']}): MATCH op {$match['date']} - status bijgewerkt + mail verzonden"];
            } else {
                $results[] = ['type' => 'ok', 'message' => "Order #{$order['id']} ({$order['bedrijfsnaam']}): MATCH op {$match['date']} (DRY RUN - geen wijzigingen)"];
            }
        } else {
            $results[] = ['type' => 'warn', 'message' => "Order #{$order['id']} ({$order['bedrijfsnaam']}): Geen match voor factuur $invoiceNumber (€$expectedAmount)"];
        }
    }
    
    $results[] = ['type' => 'ok', 'message' => "Totaal: $matchedCount van " . count($openOrders) . " bestellingen gematcht"];
}

if ($executeAction === 'check-payments') {
    executePaymentCheck($pdo, $dryRun, $executionResults);
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Payment Check Test</title>
    <style>
        body { font-family: monospace; padding: 20px; background: #1a1a1a; color: #0f0; }
        .section { margin: 20px 0; padding: 15px; background: #222; border-radius: 8px; }
        .ok { color: #0f0; }
        .error { color: #f00; }
        .warn { color: #ff0; }
        h2 { color: #fff; margin-top: 0; }
        table { border-collapse: collapse; width: 100%; }
        td, th { padding: 5px 10px; text-align: left; border-bottom: 1px solid #333; }
    </style>
</head>
<body>
    <h1>Payment Check Test - <?= date('Y-m-d H:i:s') ?></h1>

    <div class="section">
        <h2>1. Database & e-Boekhouden Configuratie</h2>
        <?php
        try {
            $stmt = $pdo->query("SELECT 1");
            echo '<p class="ok">✓ Database connectie OK</p>';
        } catch (Exception $e) {
            echo '<p class="error">✗ Database fout: ' . htmlspecialchars($e->getMessage()) . '</p>';
        }
        
        $settings = getEBoekhoudenSettings($pdo);
        $isEboekhouden = $settings['facturatie_systeem'] === 'eboekhouden';
        echo '<table>';
        echo '<tr><td>facturatie_systeem</td><td>' . ($isEboekhouden ? '<span class="ok">eboekhouden</span>' : '<span class="warn">' . ($settings['facturatie_systeem'] ?: 'niet ingesteld') . '</span>') . '</td></tr>';
        echo '<tr><td>eboekhouden_api_token</td><td>' . (empty($settings['eboekhouden_api_token']) ? '<span class="error">NIET INGESTELD</span>' : '<span class="ok">****' . substr($settings['eboekhouden_api_token'], -4) . '</span>') . '</td></tr>';
        
        $stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'eboekhouden_bank_ledger_id'");
        $bankLedgerId = $stmt->fetchColumn();
        echo '<tr><td>eboekhouden_bank_ledger_id</td><td>' . ($bankLedgerId ? '<span class="ok">' . $bankLedgerId . '</span>' : '<span class="error">NIET INGESTELD</span>') . '</td></tr>';
        echo '</table>';
        ?>
    </div>

    <div class="section">
        <h2>2. Openstaande Factuurbestellingen</h2>
        <?php
        $stmt = $pdo->prepare("
            SELECT bo.id, bo.eboekhouden_factuurnummer, bo.total_amount, bo.delivery_date,
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
        $orders = $stmt->fetchAll();
        
        if (empty($orders)) {
            echo '<span class="warn">Geen openstaande factuurbestellingen gevonden.</span>';
        } else {
            echo '<table><tr><th>Order ID</th><th>Bedrijf</th><th>Factuurnummer</th><th>Bedrag</th><th>Leverdatum</th></tr>';
            foreach ($orders as $order) {
                echo '<tr>';
                echo '<td>#' . $order['id'] . '</td>';
                echo '<td>' . htmlspecialchars($order['bedrijfsnaam']) . '</td>';
                echo '<td>' . htmlspecialchars($order['eboekhouden_factuurnummer']) . '</td>';
                echo '<td>€' . number_format($order['total_amount'], 2) . '</td>';
                echo '<td>' . $order['delivery_date'] . '</td>';
                echo '</tr>';
            }
            echo '</table>';
            echo '<p class="ok">✓ ' . count($orders) . ' openstaande bestelling(en)</p>';
            ?>
            <form method="post" style="margin-top: 15px; display: flex; gap: 10px; align-items: center;">
                <label style="display: flex; align-items: center; gap: 5px;">
                    <input type="checkbox" name="dry_run" checked>
                    Dry run (geen wijzigingen)
                </label>
                <button type="submit" name="execute" value="check-payments" 
                    style="background:#0a0;color:#fff;border:none;padding:10px 20px;cursor:pointer;font-weight:bold;">
                    ⚡ Check Betalingen
                </button>
            </form>
            <?php
        }
        ?>
    </div>

    <?php if (!empty($executionResults)): ?>
    <div class="section" style="border: 2px solid #0a0;">
        <h2>Uitvoer Resultaten<?= $dryRun ? ' (DRY RUN)' : '' ?></h2>
        <?php foreach ($executionResults as $res): ?>
            <p class="<?= $res['type'] ?>">
                <?= $res['type'] === 'ok' ? '✓' : ($res['type'] === 'error' ? '✗' : '⚠') ?>
                <?= htmlspecialchars($res['message']) ?>
            </p>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="section">
        <h2>3. Bank Mutaties Preview</h2>
        <?php
        if ($isEboekhouden && !empty($settings['eboekhouden_api_token']) && $bankLedgerId) {
            try {
                $client = new EBoekhoudenClient($settings['eboekhouden_api_token']);
                $dateFrom = date('Y-m-d', strtotime('-30 days'));
                $mutations = $client->getBankMutations($bankLedgerId, $dateFrom);
                
                if (empty($mutations)) {
                    echo '<span class="warn">Geen bankmutaties gevonden in de afgelopen 30 dagen</span>';
                } else {
                    $mutations = array_slice($mutations, 0, 10);
                    echo '<p>Laatste ' . count($mutations) . ' mutaties (max 10):</p>';
                    echo '<table><tr><th>Datum</th><th>Bedrag</th><th>Omschrijving</th><th>Factuurnr</th></tr>';
                    foreach ($mutations as $m) {
                        echo '<tr>';
                        echo '<td>' . htmlspecialchars($m['date'] ?? '-') . '</td>';
                        echo '<td>€' . number_format(floatval($m['amount'] ?? 0), 2) . '</td>';
                        echo '<td>' . htmlspecialchars(substr($m['description'] ?? '-', 0, 50)) . '</td>';
                        echo '<td>' . htmlspecialchars($m['invoiceNumber'] ?? '-') . '</td>';
                        echo '</tr>';
                    }
                    echo '</table>';
                }
            } catch (Exception $e) {
                echo '<span class="error">Fout bij ophalen mutaties: ' . htmlspecialchars($e->getMessage()) . '</span>';
            }
        } else {
            echo '<span class="warn">e-Boekhouden niet volledig geconfigureerd</span>';
        }
        ?>
    </div>

    <p style="margin-top: 30px; color: #666;">
        <a href="test-cron.php" style="color: #888;">Facturatie test</a> |
        <a href="../admin/index.php" style="color: #888;">Terug naar admin</a>
    </p>
</body>
</html>
