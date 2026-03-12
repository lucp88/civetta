<?php
require_once '../config.php';
requireLogin();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Migration 035</title>
    <style>
        body { font-family: -apple-system, sans-serif; padding: 2rem; background: #f5f2ed; }
        .card { background: white; padding: 2rem; border-radius: 12px; max-width: 600px; margin: 0 auto; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        h1 { color: #2d4a2d; margin-bottom: 1rem; }
        .success { color: #2e7d32; }
        .info    { color: #666; }
        .error   { color: #c62828; }
        pre { background: #f5f5f5; padding: 1rem; border-radius: 8px; overflow-x: auto; margin: 1rem 0; }
        a { color: #3d6b3d; }
        .btn { display: inline-block; padding: 0.75rem 1.5rem; background: #3d6b3d; color: white; text-decoration: none; border-radius: 8px; margin-top: 1rem; }
        .btn:hover { background: #2d4a2d; }
    </style>
</head>
<body>
<div class="card">
<h1>Migration 035: Allergen Trace Status</h1>
<pre><?php

// 1. Create allergen_trace_status table
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS allergen_trace_status (
            allergeen_naam VARCHAR(100) PRIMARY KEY,
            status ENUM('in_stock','depleted','cleared') NOT NULL DEFAULT 'in_stock',
            stock_depleted_at DATETIME NULL,
            manually_cleared_at DATETIME NULL,
            cleared_by VARCHAR(100) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "<span class='success'>✓ Tabel allergen_trace_status aangemaakt</span>\n";
} catch (PDOException $e) {
    echo strpos($e->getMessage(), 'already exists') !== false
        ? "<span class='info'>- Tabel allergen_trace_status bestaat al</span>\n"
        : "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
}

// 2. Add is_allergeen_kritisch to schoonmaak_items
try {
    $pdo->exec("ALTER TABLE schoonmaak_items ADD COLUMN is_allergeen_kritisch TINYINT(1) NOT NULL DEFAULT 0 AFTER actief");
    echo "<span class='success'>✓ is_allergeen_kritisch kolom toegevoegd aan schoonmaak_items</span>\n";
} catch (PDOException $e) {
    echo strpos($e->getMessage(), 'Duplicate') !== false
        ? "<span class='info'>- is_allergeen_kritisch kolom bestaat al</span>\n"
        : "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
}

// 3. Initialize allergen_trace_status from current inventory state
try {
    $stmt = $pdo->query("
        SELECT DISTINCT i.allergeen_naam,
               COALESCE(SUM(b.quantity_remaining), 0) as total_stock
        FROM ingredients i
        LEFT JOIN ingredient_batches b ON i.id = b.ingredient_id AND b.quantity_remaining > 0
        WHERE i.is_allergeen = 1 AND i.is_active = 1
          AND i.allergeen_naam IS NOT NULL AND i.allergeen_naam != ''
        GROUP BY i.allergeen_naam
    ");
    $rows = $stmt->fetchAll();
    $inserted = 0;

    foreach ($rows as $row) {
        $status = $row['total_stock'] > 0 ? 'in_stock' : 'depleted';
        $depletedAt = $status === 'depleted' ? date('Y-m-d H:i:s') : null;

        $ins = $pdo->prepare("
            INSERT IGNORE INTO allergen_trace_status (allergeen_naam, status, stock_depleted_at)
            VALUES (?, ?, ?)
        ");
        $ins->execute([$row['allergeen_naam'], $status, $depletedAt]);
        if ($ins->rowCount() > 0) $inserted++;
    }

    echo "<span class='success'>✓ {$inserted} allergeen trace status records geïnitialiseerd</span>\n";
} catch (PDOException $e) {
    echo "<span class='error'>✗ Initialisatie fout: " . htmlspecialchars($e->getMessage()) . "</span>\n";
}

echo "\n<span class='success'>✓ Migration 035 voltooid!</span>\n";
?></pre>

<p><strong>Wijzigingen:</strong></p>
<ul>
    <li>Nieuwe tabel <code>allergen_trace_status</code> voor tracking van sporenallergenen op basis van voorraad</li>
    <li>schoonmaak_items: +is_allergeen_kritisch (markeer schoonmaakitems als allergeen-kritisch)</li>
    <li>Initialisatie van trace status op basis van huidige voorraadstand</li>
</ul>

<a href="../bakker/bakker-dashboard.php" class="btn">← Naar Dashboard</a>
</div>
</body>
</html>
