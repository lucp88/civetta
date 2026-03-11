<?php
require_once __DIR__ . '/../config.php';
requireLogin();

header('Content-Type: text/html; charset=utf-8');

function tableExists($pdo, $table) {
    $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
    $stmt->execute([$table]);
    return $stmt->rowCount() > 0;
}

function countRows($pdo, $table) {
    if (!tableExists($pdo, $table)) return null;
    return $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
}

$uitgevoerd = false;
$fout = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bevestig']) && $_POST['bevestig'] === 'JA_VERWIJDER_ALLES') {
    try {
        $pdo->beginTransaction();

        // Verwijder in de juiste volgorde (child tables eerst)
        $pdo->exec("DELETE FROM business_order_items");
        $pdo->exec("DELETE FROM business_orders");
        $pdo->exec("DELETE FROM invoice_log");
        if (tableExists($pdo, 'renewal_reminders_sent')) {
            $pdo->exec("DELETE FROM renewal_reminders_sent");
        }

        // Reset auto-increment zodat IDs opnieuw bij 1 beginnen
        $pdo->exec("ALTER TABLE business_orders AUTO_INCREMENT = 1");
        $pdo->exec("ALTER TABLE business_order_items AUTO_INCREMENT = 1");
        $pdo->exec("ALTER TABLE invoice_log AUTO_INCREMENT = 1");

        $pdo->commit();
        $uitgevoerd = true;
    } catch (PDOException $e) {
        $pdo->rollBack();
        $fout = $e->getMessage();
    }
}

// Tel huidige aantallen
$aantalOrders      = countRows($pdo, 'business_orders');
$aantalOrderItems  = countRows($pdo, 'business_order_items');
$aantalInvoiceLogs = countRows($pdo, 'invoice_log');
$aantalReminders   = countRows($pdo, 'renewal_reminders_sent');
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Migration 023 – Verwijder test bestellingen</title>
    <style>
        body { font-family: -apple-system, sans-serif; padding: 2rem; background: #f5f2ed; }
        .card { background: white; padding: 2rem; border-radius: 12px; max-width: 640px; margin: 0 auto; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        h1 { color: #2d4a2d; margin-bottom: 0.5rem; }
        .subtitle { color: #888; margin-bottom: 1.5rem; font-size: 0.9rem; }
        table { width: 100%; border-collapse: collapse; margin: 1rem 0; }
        th, td { text-align: left; padding: 0.6rem 0.75rem; border-bottom: 1px solid #eee; }
        th { background: #f5f2ed; color: #2d4a2d; font-weight: 600; }
        td.aantal { font-weight: 700; color: #c62828; text-align: right; }
        td.aantal.nul { color: #2e7d32; }
        .waarschuwing { background: #fff3e0; border-left: 4px solid #e65100; padding: 1rem 1.25rem; border-radius: 6px; margin: 1.5rem 0; }
        .waarschuwing strong { color: #e65100; }
        .succes { background: #e8f5e9; border-left: 4px solid #2e7d32; padding: 1rem 1.25rem; border-radius: 6px; margin: 1.5rem 0; }
        .succes strong { color: #2e7d32; }
        .fout { background: #ffebee; border-left: 4px solid #c62828; padding: 1rem 1.25rem; border-radius: 6px; margin: 1.5rem 0; }
        .fout strong { color: #c62828; }
        .form-sectie { margin-top: 1.5rem; padding-top: 1.5rem; border-top: 2px solid #eee; }
        .form-sectie label { display: block; margin-bottom: 0.75rem; font-weight: 500; }
        .btn-verwijder { display: inline-block; padding: 0.75rem 1.75rem; background: #c62828; color: white; border: none; border-radius: 8px; font-size: 1rem; font-weight: 600; cursor: pointer; }
        .btn-verwijder:hover { background: #8c1c1c; }
        .btn-verwijder:disabled { background: #ccc; cursor: not-allowed; }
        .btn-terug { display: inline-block; padding: 0.75rem 1.5rem; background: #3d6b3d; color: white; text-decoration: none; border-radius: 8px; margin-top: 1rem; }
        .btn-terug:hover { background: #2d4a2d; }
        .leeg { color: #2e7d32; font-style: italic; }
    </style>
</head>
<body>
<div class="card">
    <h1>Migration 023</h1>
    <p class="subtitle">Verwijder alle test bestellingen (onomkeerbaar)</p>

    <?php if ($uitgevoerd): ?>
        <div class="succes">
            <strong>Klaar!</strong> Alle test bestellingen zijn verwijderd en de ID-tellers zijn gereset.
        </div>
        <table>
            <tr><th>Tabel</th><th>Resterende rijen</th></tr>
            <tr><td>business_orders</td><td class="aantal nul"><?= $aantalOrders ?? '–' ?></td></tr>
            <tr><td>business_order_items</td><td class="aantal nul"><?= $aantalOrderItems ?? '–' ?></td></tr>
            <tr><td>invoice_log</td><td class="aantal nul"><?= $aantalInvoiceLogs ?? '–' ?></td></tr>
            <?php if ($aantalReminders !== null): ?>
            <tr><td>renewal_reminders_sent</td><td class="aantal nul"><?= $aantalReminders ?></td></tr>
            <?php endif; ?>
        </table>
        <a href="../bestellingen/orders.php" class="btn-terug">← Naar bestellingen</a>

    <?php elseif ($fout): ?>
        <div class="fout">
            <strong>Fout:</strong> <?= htmlspecialchars($fout) ?>
        </div>

    <?php else: ?>

        <p>De volgende rijen worden verwijderd:</p>
        <table>
            <tr><th>Tabel</th><th>Te verwijderen</th></tr>
            <tr>
                <td>business_orders <small style="color:#888">(bestellingen)</small></td>
                <td class="aantal <?= $aantalOrders == 0 ? 'nul' : '' ?>"><?= $aantalOrders ?></td>
            </tr>
            <tr>
                <td>business_order_items <small style="color:#888">(orderregels)</small></td>
                <td class="aantal <?= $aantalOrderItems == 0 ? 'nul' : '' ?>"><?= $aantalOrderItems ?></td>
            </tr>
            <tr>
                <td>invoice_log <small style="color:#888">(factuurlogs)</small></td>
                <td class="aantal <?= $aantalInvoiceLogs == 0 ? 'nul' : '' ?>"><?= $aantalInvoiceLogs ?></td>
            </tr>
            <?php if ($aantalReminders !== null): ?>
            <tr>
                <td>renewal_reminders_sent <small style="color:#888">(herhalingsherinneringen)</small></td>
                <td class="aantal <?= $aantalReminders == 0 ? 'nul' : '' ?>"><?= $aantalReminders ?></td>
            </tr>
            <?php endif; ?>
        </table>

        <p><strong>Business accounts worden niet aangeraakt.</strong> Alleen de bestellingen en bijbehorende data worden gewist.</p>

        <?php if ($aantalOrders == 0 && $aantalOrderItems == 0 && $aantalInvoiceLogs == 0 && ($aantalReminders === null || $aantalReminders == 0)): ?>
            <p class="leeg">Er is niets om te verwijderen – alle tabellen zijn al leeg.</p>
        <?php else: ?>
            <div class="waarschuwing">
                <strong>Let op:</strong> Dit is onomkeerbaar. Er is geen backup-stap ingebouwd.
            </div>
            <div class="form-sectie">
                <form method="POST">
                    <label>
                        <input type="checkbox" id="chk" onchange="document.getElementById('btn').disabled = !this.checked">
                        Ik begrijp dat dit alle bestellingen permanent verwijdert
                    </label>
                    <input type="hidden" name="bevestig" value="JA_VERWIJDER_ALLES">
                    <button type="submit" id="btn" class="btn-verwijder" disabled>Verwijder alle test bestellingen</button>
                </form>
            </div>
        <?php endif; ?>

    <?php endif; ?>
</div>
</body>
</html>
