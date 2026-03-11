<?php
require_once __DIR__ . '/../config.php';
requireLogin();

$results = [];
$error = '';

function columnExists($pdo, $table, $column) {
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $stmt->execute([$column]);
    return $stmt->rowCount() > 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();
        
        if (!columnExists($pdo, 'business_orders', 'invoice_status')) {
            $pdo->exec("ALTER TABLE business_orders ADD COLUMN invoice_status VARCHAR(20) DEFAULT 'bestelbon'");
            $results[] = ['type' => 'created', 'item' => 'Kolom invoice_status toegevoegd'];
        } else {
            $results[] = ['type' => 'exists', 'item' => 'Kolom invoice_status bestaat al'];
        }
        
        if (!columnExists($pdo, 'business_orders', 'delivery_status')) {
            $pdo->exec("ALTER TABLE business_orders ADD COLUMN delivery_status VARCHAR(20) DEFAULT 'geplaatst'");
            $results[] = ['type' => 'created', 'item' => 'Kolom delivery_status toegevoegd'];
        } else {
            $results[] = ['type' => 'exists', 'item' => 'Kolom delivery_status bestaat al'];
        }
        
        $stmt = $pdo->exec("
            UPDATE business_orders 
            SET invoice_status = 'gefactureerd'
            WHERE payment_type = 'ideal' AND payment_status = 'paid'
            AND (invoice_status IS NULL OR invoice_status = '' OR invoice_status = 'bestelbon')
        ");
        if ($stmt > 0) {
            $results[] = ['type' => 'migrated', 'item' => "$stmt iDEAL betaalde orders naar invoice_status='gefactureerd'"];
        }
        
        $stmt = $pdo->exec("
            UPDATE business_orders 
            SET invoice_status = 'gefactureerd'
            WHERE (eboekhouden_invoice_id IS NOT NULL AND eboekhouden_invoice_id != '')
               OR (invoice_number IS NOT NULL AND invoice_number != '')
            AND (invoice_status IS NULL OR invoice_status = '' OR invoice_status = 'bestelbon')
        ");
        if ($stmt > 0) {
            $results[] = ['type' => 'migrated', 'item' => "$stmt orders met factuur naar invoice_status='gefactureerd'"];
        }
        
        $stmt = $pdo->exec("
            UPDATE business_orders 
            SET invoice_status = 'bestelbon'
            WHERE invoice_status IS NULL OR invoice_status = ''
        ");
        if ($stmt > 0) {
            $results[] = ['type' => 'migrated', 'item' => "$stmt orders naar invoice_status='bestelbon'"];
        }
        
        $stmt = $pdo->exec("
            UPDATE business_orders 
            SET delivery_status = 'afgeleverd'
            WHERE order_status = 'afgeleverd'
            AND (delivery_status IS NULL OR delivery_status = '' OR delivery_status = 'geplaatst')
        ");
        if ($stmt > 0) {
            $results[] = ['type' => 'migrated', 'item' => "$stmt orders naar delivery_status='afgeleverd'"];
        }
        
        $stmt = $pdo->exec("
            UPDATE business_orders 
            SET delivery_status = 'afgeleverd'
            WHERE delivery_date < CURDATE()
            AND (delivery_status IS NULL OR delivery_status = '' OR delivery_status = 'geplaatst')
        ");
        if ($stmt > 0) {
            $results[] = ['type' => 'migrated', 'item' => "$stmt oude orders naar delivery_status='afgeleverd'"];
        }
        
        $stmt = $pdo->exec("
            UPDATE business_orders 
            SET delivery_status = 'geplaatst'
            WHERE delivery_status IS NULL OR delivery_status = ''
        ");
        if ($stmt > 0) {
            $results[] = ['type' => 'migrated', 'item' => "$stmt orders naar delivery_status='geplaatst'"];
        }
        
        $pdo->commit();
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        $error = 'Fout: ' . $e->getMessage();
    }
}

$createdCount = count(array_filter($results, fn($r) => $r['type'] === 'created'));
$migratedCount = count(array_filter($results, fn($r) => $r['type'] === 'migrated'));
$existsCount = count(array_filter($results, fn($r) => $r['type'] === 'exists'));
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Migratie 004: Invoice & Delivery Status | Civetta Admin</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #f5f2ed;
            min-height: 100vh;
        }
        .header {
            background: linear-gradient(135deg, #3d6b3d, #2d4a2d);
            color: white;
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header h1 { font-size: 1.5rem; }
        .header a {
            color: white;
            text-decoration: none;
            padding: 0.5rem 1rem;
            background: rgba(255,255,255,0.2);
            border-radius: 6px;
        }
        .container {
            max-width: 800px;
            margin: 2rem auto;
            padding: 0 1rem;
        }
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            padding: 2rem;
            margin-bottom: 1rem;
        }
        .card h2 {
            color: #2d4a2d;
            margin-bottom: 1rem;
        }
        .card p {
            color: #666;
            margin-bottom: 1.5rem;
            line-height: 1.6;
        }
        .btn {
            display: inline-block;
            padding: 0.75rem 1.5rem;
            background: #3d6b3d;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-size: 1rem;
        }
        .btn:hover { background: #2d4a2d; }
        .summary {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            background: #e8f4e8;
            border: 1px solid #c3e6c3;
        }
        .summary.warning {
            background: #fff3cd;
            border-color: #ffc107;
        }
        .summary strong { color: #155724; }
        .summary.warning strong { color: #856404; }
        .error {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            background: #f8d7da;
            color: #721c24;
        }
        .results {
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid #ddd;
            border-radius: 8px;
        }
        .result-item {
            padding: 0.5rem 1rem;
            border-bottom: 1px solid #eee;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .result-item:last-child { border-bottom: none; }
        .result-item.created { background: #d4edda; }
        .result-item.migrated { background: #cce5ff; }
        .result-item.exists { background: #f8f9fa; }
        .icon { font-size: 1.1rem; }
        .breadcrumb {
            margin-bottom: 1.5rem;
        }
        .breadcrumb a {
            color: #3d6b3d;
            text-decoration: none;
        }
        .breadcrumb span {
            color: #888;
            margin: 0 0.5rem;
        }
        code {
            background: #f4f4f4;
            padding: 0.2rem 0.4rem;
            border-radius: 4px;
            font-size: 0.9rem;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 1rem 0;
        }
        th, td {
            padding: 0.5rem;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        th { background: #f8f8f8; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Civetta Admin</h1>
        <a href="../logout.php">Uitloggen</a>
    </div>
    
    <div class="container">
        <div class="breadcrumb">
            <a href="../index.php">Dashboard</a>
            <span>></span>
            Migratie 004: Invoice & Delivery Status
        </div>

        <div class="card">
            <h2>Migratie 004: Invoice & Delivery Status</h2>
            
            <?php if ($error): ?>
                <div class="error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            
            <?php if (!empty($results)): ?>
                <div class="summary <?= ($createdCount + $migratedCount) === 0 ? 'warning' : '' ?>">
                    <strong><?= $createdCount ?> kolommen toegevoegd</strong>, 
                    <strong><?= $migratedCount ?> data migraties</strong>, 
                    <?= $existsCount ?> items al aanwezig
                    <?php if (($createdCount + $migratedCount) === 0): ?>
                        <br><em>Database was al up-to-date!</em>
                    <?php endif; ?>
                </div>
                
                <div class="results">
                    <?php foreach ($results as $result): ?>
                        <div class="result-item <?= $result['type'] ?>">
                            <span class="icon"><?= $result['type'] === 'created' ? '+' : ($result['type'] === 'migrated' ? '~' : '-') ?></span>
                            <?= htmlspecialchars($result['item']) ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p>Deze migratie splitst <code>order_status</code> in twee aparte velden voor betere tracking.</p>
                
                <h3 style="margin: 1rem 0 0.5rem; color: #2d4a2d;">Nieuwe kolommen:</h3>
                <table>
                    <tr><th>Kolom</th><th>Waarden</th><th>Beschrijving</th></tr>
                    <tr><td><code>invoice_status</code></td><td>bestelbon, gefactureerd</td><td>Facturatie status</td></tr>
                    <tr><td><code>delivery_status</code></td><td>geplaatst, wordt_bereid, onderweg, afgeleverd</td><td>Lever status</td></tr>
                </table>
                
                <h3 style="margin: 1rem 0 0.5rem; color: #2d4a2d;">Migratie logica:</h3>
                <ul style="margin-bottom: 1.5rem; margin-left: 1.5rem; color: #666;">
                    <li>iDEAL betaald → <code>invoice_status='gefactureerd'</code></li>
                    <li>Heeft e-Boekhouden/lokale factuur → <code>invoice_status='gefactureerd'</code></li>
                    <li>Leverdag in verleden → <code>delivery_status='afgeleverd'</code></li>
                    <li>Rest → <code>invoice_status='bestelbon'</code>, <code>delivery_status='geplaatst'</code></li>
                </ul>
                
                <form method="POST">
                    <button type="submit" class="btn">Migratie uitvoeren</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
