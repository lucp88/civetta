<?php
/**
 * Test script voor cron jobs
 * Alleen toegankelijk voor ingelogde admins
 */

require_once __DIR__ . '/../admin/config.php';
require_once __DIR__ . '/../api/eboekhouden.php';
requireLogin();

$executeAction = $_POST['execute'] ?? null;
$executionResults = [];

header('Content-Type: text/html; charset=utf-8');

$simulatedDate = $_GET['date'] ?? $_POST['date'] ?? null;
$simulatedTime = $_GET['time'] ?? $_POST['time'] ?? null;

if ($simulatedDate) {
    $testDate = new DateTime($simulatedDate . ' ' . ($simulatedTime ?: '12:00'));
} else {
    $testDate = new DateTime();
}

function getSimulatedNow() {
    global $testDate;
    return clone $testDate;
}

function getFacturatieSettings($pdo) {
    $settings = [];
    $keys = [
        'facturatie_systeem', 'facturatie_moment', 'facturatie_uur', 'facturatie_dagen_offset',
        'eboekhouden_api_token', 'eboekhouden_template_id_openstaand', 'eboekhouden_ledger_id',
        'eboekhouden_debiteuren_ledger_id', 'btw_tarief'
    ];
    foreach ($keys as $key) {
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $settings[$key] = $stmt->fetchColumn() ?: '';
    }
    return $settings;
}

function executeAutoInvoice($pdo, $targetDateStr, &$results) {
    $settings = getFacturatieSettings($pdo);
    
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
    $orders = $stmt->fetchAll();
    
    if (empty($orders)) {
        $results[] = ['type' => 'warn', 'message' => 'Geen orders gevonden om te factureren'];
        return;
    }
    
    foreach ($orders as $order) {
        $orderId = $order['id'];
        
        $stmt = $pdo->prepare("SELECT product_name, quantity, unit_price FROM business_order_items WHERE order_id = ?");
        $stmt->execute([$orderId]);
        $items = $stmt->fetchAll();
        
        if (empty($items)) {
            $results[] = ['type' => 'error', 'message' => "Order #$orderId: Geen items gevonden"];
            continue;
        }
        
        if ($settings['facturatie_systeem'] === 'eboekhouden' && !empty($settings['eboekhouden_api_token'])) {
            try {
                $client = new EBoekhoudenClient($settings['eboekhouden_api_token']);
                
                $btwCode = floatval($settings['btw_tarief']) == 21 ? 'HOOG_VERK_21' : 'LAAG_VERK_9';
                
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
                    $accountData, $orderItems,
                    $settings['eboekhouden_template_id_openstaand'],
                    $settings['eboekhouden_ledger_id'],
                    $btwCode, true,
                    $settings['eboekhouden_debiteuren_ledger_id'] ?: null
                );
                
                $stmt = $pdo->prepare("
                    UPDATE business_orders 
                    SET eboekhouden_invoice_id = ?, eboekhouden_factuurnummer = ?, eboekhouden_pdf_url = ?,
                        facturatie_systeem = 'eboekhouden', invoiced_at = NOW(), order_status = 'afgeleverd'
                    WHERE id = ?
                ");
                $stmt->execute([$result['id'], $result['invoiceNumber'], $result['pdfUrl'], $orderId]);
                
                $results[] = ['type' => 'ok', 'message' => "Order #$orderId ({$order['bedrijfsnaam']}): Factuur {$result['invoiceNumber']} aangemaakt en gemaild"];
                
            } catch (Exception $e) {
                $results[] = ['type' => 'error', 'message' => "Order #$orderId: " . $e->getMessage()];
            }
        } else {
            $invoiceNumber = 'F' . date('Y') . '-' . str_pad($orderId, 4, '0', STR_PAD_LEFT);
            $stmt = $pdo->prepare("
                UPDATE business_orders 
                SET invoice_number = ?, invoiced_at = NOW(), facturatie_systeem = 'eigen', order_status = 'afgeleverd'
                WHERE id = ?
            ");
            $stmt->execute([$invoiceNumber, $orderId]);
            $results[] = ['type' => 'ok', 'message' => "Order #$orderId ({$order['bedrijfsnaam']}): Lokale factuur $invoiceNumber aangemaakt"];
        }
    }
}

if ($executeAction === 'auto-invoice') {
    $moment = '';
    $offset = 0;
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'facturatie_moment'");
    $stmt->execute();
    $moment = $stmt->fetchColumn() ?: 'op_leverdag';
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'facturatie_dagen_offset'");
    $stmt->execute();
    $offset = intval($stmt->fetchColumn()) ?: 0;
    
    $simDate = getSimulatedNow();
    switch ($moment) {
        case 'voor_leverdag': $targetDate = (clone $simDate)->modify("+{$offset} days"); break;
        case 'na_leverdag': $targetDate = (clone $simDate)->modify("-{$offset} days"); break;
        default: $targetDate = $simDate;
    }
    executeAutoInvoice($pdo, $targetDate->format('Y-m-d'), $executionResults);
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Cron Test</title>
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
    <h1>Cron Test - <?= $testDate->format('Y-m-d H:i:s') ?><?= $simulatedDate ? ' <span style="color:#ff0">(GESIMULEERD)</span>' : '' ?></h1>

    <div class="section">
        <h2>Simulatie</h2>
        <form method="get" style="display: flex; gap: 10px; align-items: center;">
            <label>Datum: <input type="date" name="date" value="<?= $testDate->format('Y-m-d') ?>" style="background:#333;color:#fff;border:1px solid #555;padding:5px;"></label>
            <label>Tijd: <input type="time" name="time" value="<?= $testDate->format('H:i') ?>" style="background:#333;color:#fff;border:1px solid #555;padding:5px;"></label>
            <button type="submit" style="background:#0a0;color:#fff;border:none;padding:8px 15px;cursor:pointer;">Simuleer</button>
            <a href="test-cron.php" style="color:#888;margin-left:10px;">Reset</a>
        </form>
    </div>

    <div class="section">
        <h2>1. Database Connectie</h2>
        <?php
        try {
            $stmt = $pdo->query("SELECT 1");
            echo '<span class="ok">✓ Database connectie OK</span>';
        } catch (Exception $e) {
            echo '<span class="error">✗ Database fout: ' . htmlspecialchars($e->getMessage()) . '</span>';
        }
        ?>
    </div>

    <div class="section">
        <h2>2. Facturatie Instellingen</h2>
        <?php
        $keys = ['facturatie_systeem', 'facturatie_moment', 'facturatie_uur', 'facturatie_dagen_offset', 'eboekhouden_api_token'];
        echo '<table>';
        foreach ($keys as $key) {
            $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
            $stmt->execute([$key]);
            $value = $stmt->fetchColumn();
            $display = $key === 'eboekhouden_api_token' ? (empty($value) ? '<span class="error">NIET INGESTELD</span>' : '<span class="ok">****' . substr($value, -4) . '</span>') : ($value ?: '<span class="warn">leeg</span>');
            echo "<tr><td>$key</td><td>$display</td></tr>";
        }
        echo '</table>';
        ?>
    </div>

    <div class="section">
        <h2>3. Auto-Invoice Check (wat zou nu gefactureerd worden)</h2>
        <?php
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'facturatie_moment'");
        $stmt->execute();
        $moment = $stmt->fetchColumn() ?: 'op_leverdag';
        
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'facturatie_dagen_offset'");
        $stmt->execute();
        $offset = intval($stmt->fetchColumn()) ?: 0;
        
        $today = getSimulatedNow();
        switch ($moment) {
            case 'voor_leverdag':
                $targetDate = (clone $today)->modify("+{$offset} days");
                break;
            case 'na_leverdag':
                $targetDate = (clone $today)->modify("-{$offset} days");
                break;
            default:
                $targetDate = $today;
        }
        $targetDateStr = $targetDate->format('Y-m-d');
        
        echo "<p>Target leverdatum: <strong>$targetDateStr</strong> (moment: $moment, offset: $offset)</p>";
        
        $stmt = $pdo->prepare("
            SELECT bo.id, bo.delivery_date, bo.total_amount, bo.payment_type, bo.order_status, ba.bedrijfsnaam
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
        $orders = $stmt->fetchAll();
        
        if (empty($orders)) {
            echo '<span class="warn">Geen orders gevonden om te factureren voor deze datum.</span>';
        } else {
            echo '<table><tr><th>Order ID</th><th>Bedrijf</th><th>Leverdatum</th><th>Bedrag</th><th>Status</th></tr>';
            foreach ($orders as $order) {
                echo '<tr>';
                echo '<td>#' . $order['id'] . '</td>';
                echo '<td>' . htmlspecialchars($order['bedrijfsnaam']) . '</td>';
                echo '<td>' . $order['delivery_date'] . '</td>';
                echo '<td>€' . number_format($order['total_amount'], 2) . '</td>';
                echo '<td>' . $order['order_status'] . '</td>';
                echo '</tr>';
            }
            echo '</table>';
            echo '<p class="ok">✓ ' . count($orders) . ' order(s) zouden gefactureerd worden</p>';
            ?>
            <form method="post" style="margin-top: 15px;">
                <input type="hidden" name="date" value="<?= htmlspecialchars($simulatedDate ?: '') ?>">
                <input type="hidden" name="time" value="<?= htmlspecialchars($simulatedTime ?: '') ?>">
                <button type="submit" name="execute" value="auto-invoice" 
                    style="background:#f80;color:#fff;border:none;padding:10px 20px;cursor:pointer;font-weight:bold;">
                    ⚡ UITVOEREN - Factureer deze orders nu
                </button>
            </form>
            <?php
        }
        ?>
    </div>

    <?php if (!empty($executionResults)): ?>
    <div class="section" style="border: 2px solid #f80;">
        <h2>Uitvoer Resultaten</h2>
        <?php foreach ($executionResults as $res): ?>
            <p class="<?= $res['type'] ?>">
                <?= $res['type'] === 'ok' ? '✓' : ($res['type'] === 'error' ? '✗' : '⚠') ?>
                <?= htmlspecialchars($res['message']) ?>
            </p>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="section">
        <h2>4. Huidige uur check</h2>
        <?php
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'facturatie_uur'");
        $stmt->execute();
        $configuredHour = substr($stmt->fetchColumn() ?: '17:00', 0, 2);
        $currentHour = $testDate->format('H');
        
        echo "<p>Geconfigureerd uur: <strong>$configuredHour:00</strong></p>";
        echo "<p>Huidig uur: <strong>$currentHour:00</strong></p>";
        
        if ($currentHour == $configuredHour) {
            echo '<span class="ok">✓ Cron zou NU draaien</span>';
        } else {
            echo '<span class="warn">Cron draait pas om ' . $configuredHour . ':00</span>';
        }
        ?>
    </div>

    <div class="section">
        <h2>5. Recurring Renewal Check</h2>
        <?php
        $twoWeeksFromNow = (clone $testDate)->modify('+14 days')->format('Y-m-d');
        $stmt = $pdo->prepare("
            SELECT recurring_group_id, COUNT(*) as order_count, MIN(recurring_confirmed_until) as confirmed_until
            FROM business_orders 
            WHERE is_recurring = 1 
              AND recurring_confirmed_until IS NOT NULL
              AND recurring_confirmed_until <= ?
            GROUP BY recurring_group_id
        ");
        $stmt->execute([$twoWeeksFromNow]);
        $groups = $stmt->fetchAll();
        
        if (empty($groups)) {
            echo '<span class="ok">✓ Geen recurring groepen die binnen 2 weken verlopen</span>';
        } else {
            echo '<table><tr><th>Groep ID</th><th>Orders</th><th>Verloopt op</th></tr>';
            foreach ($groups as $group) {
                echo '<tr>';
                echo '<td>' . htmlspecialchars($group['recurring_group_id']) . '</td>';
                echo '<td>' . $group['order_count'] . '</td>';
                echo '<td>' . $group['confirmed_until'] . '</td>';
                echo '</tr>';
            }
            echo '</table>';
            echo '<p class="warn">' . count($groups) . ' groep(en) hebben renewal reminder nodig</p>';
        }
        ?>
    </div>

    <p style="margin-top: 30px; color: #666;">
        Test voltooid. <a href="../admin/index.php" style="color: #888;">Terug naar admin</a>
    </p>
</body>
</html>
