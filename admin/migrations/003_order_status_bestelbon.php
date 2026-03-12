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
        
        if (!columnExists($pdo, 'business_orders', 'order_status')) {
            $pdo->exec("ALTER TABLE business_orders ADD COLUMN order_status VARCHAR(30) DEFAULT 'bestelbon'");
            $results[] = ['type' => 'created', 'item' => 'Kolom order_status toegevoegd'];
        } else {
            $results[] = ['type' => 'exists', 'item' => 'Kolom order_status bestaat al'];
        }
        
        if (!columnExists($pdo, 'business_orders', 'bestelbon_sent_at')) {
            $pdo->exec("ALTER TABLE business_orders ADD COLUMN bestelbon_sent_at DATETIME NULL");
            $results[] = ['type' => 'created', 'item' => 'Kolom bestelbon_sent_at toegevoegd'];
        } else {
            $results[] = ['type' => 'exists', 'item' => 'Kolom bestelbon_sent_at bestaat al'];
        }
        
        if (!columnExists($pdo, 'business_orders', 'bestelbon_number')) {
            $pdo->exec("ALTER TABLE business_orders ADD COLUMN bestelbon_number VARCHAR(50) NULL");
            $results[] = ['type' => 'created', 'item' => 'Kolom bestelbon_number toegevoegd'];
        } else {
            $results[] = ['type' => 'exists', 'item' => 'Kolom bestelbon_number bestaat al'];
        }
        
        if (!columnExists($pdo, 'business_orders', 'can_be_edited_until')) {
            $pdo->exec("ALTER TABLE business_orders ADD COLUMN can_be_edited_until DATETIME NULL");
            $results[] = ['type' => 'created', 'item' => 'Kolom can_be_edited_until toegevoegd'];
        } else {
            $results[] = ['type' => 'exists', 'item' => 'Kolom can_be_edited_until bestaat al'];
        }
        
        $stmt = $pdo->exec("
            UPDATE business_orders 
            SET order_status = 'afgeleverd' 
            WHERE status IN ('delivered', 'paid', 'invoiced')
            AND (order_status IS NULL OR order_status = '' OR order_status = 'bestelbon')
        ");
        if ($stmt > 0) {
            $results[] = ['type' => 'migrated', 'item' => "$stmt orders naar order_status='afgeleverd' gemigreerd"];
        } else {
            $results[] = ['type' => 'exists', 'item' => 'Geen orders om naar afgeleverd te migreren'];
        }
        
        $stmt = $pdo->exec("
            UPDATE business_orders 
            SET order_status = 'bestelbon' 
            WHERE order_status IS NULL OR order_status = ''
        ");
        if ($stmt > 0) {
            $results[] = ['type' => 'migrated', 'item' => "$stmt orders naar order_status='bestelbon' gezet"];
        } else {
            $results[] = ['type' => 'exists', 'item' => 'Geen orders met lege order_status'];
        }
        
        $stmt = $pdo->exec("
            UPDATE business_orders 
            SET payment_type = 'ideal' 
            WHERE payment_type IN ('direct', 'mollie_direct')
        ");
        if ($stmt > 0) {
            $results[] = ['type' => 'migrated', 'item' => "$stmt orders: payment_type direct/mollie_direct -> ideal"];
        } else {
            $results[] = ['type' => 'exists', 'item' => "Geen orders met payment_type='direct' of 'mollie_direct'"];
        }
        
        $stmt = $pdo->exec("
            UPDATE business_orders 
            SET payment_type = 'factuur' 
            WHERE payment_type IN ('later', 'invoice')
        ");
        if ($stmt > 0) {
            $results[] = ['type' => 'migrated', 'item' => "$stmt orders: payment_type later/invoice -> factuur"];
        } else {
            $results[] = ['type' => 'exists', 'item' => "Geen orders met payment_type='later' of 'invoice'"];
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
    <title>Migratie 003: Order Status & Bestelbon | Civetta Admin</title>
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
            Migratie 003: Order Status & Bestelbon
        </div>

        <div class="card">
            <h2>Migratie 003: Order Status & Bestelbon</h2>
            
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
                <p>Deze migratie voegt order_status en bestelbon velden toe voor de twee-stappen facturatie workflow.</p>
                <ul style="margin-bottom: 1.5rem; margin-left: 1.5rem; color: #666;">
                    <li>Voegt kolom <code>order_status</code> toe (bestelbon/gefactureerd/afgeleverd)</li>
                    <li>Voegt kolom <code>bestelbon_sent_at</code> toe</li>
                    <li>Voegt kolom <code>bestelbon_number</code> toe</li>
                    <li>Voegt kolom <code>can_be_edited_until</code> toe</li>
                    <li>Normaliseert payment_type naar 'ideal' of 'factuur'</li>
                </ul>
                
                <form method="POST">
                    <button type="submit" class="btn">Migratie uitvoeren</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
