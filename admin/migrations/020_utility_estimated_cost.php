<?php
require_once '../config.php';
requireLogin();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Migration 020</title>
    <style>
        body { font-family: -apple-system, sans-serif; padding: 2rem; background: #f5f2ed; }
        .card { background: white; padding: 2rem; border-radius: 12px; max-width: 600px; margin: 0 auto; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        h1 { color: #5c3d1e; margin-bottom: 1rem; }
        .success { color: #2e7d32; }
        .info { color: #666; }
        .error { color: #c62828; }
        pre { background: #f5f5f5; padding: 1rem; border-radius: 8px; overflow-x: auto; margin: 1rem 0; }
        a { color: #8b5a2b; }
        .btn { display: inline-block; padding: 0.75rem 1.5rem; background: #8b5a2b; color: white; text-decoration: none; border-radius: 8px; margin-top: 1rem; }
        .btn:hover { background: #5c3d1e; }
    </style>
</head>
<body>
<div class="card">
<h1>Migration 020: Geschatte kosten veld</h1>
<pre><?php

// Create table if it doesn't exist yet (in case migration 013 was never run)
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS utility_costs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            type ENUM('water', 'electricity') NOT NULL,
            `year_month` CHAR(7) NOT NULL COMMENT 'Format: YYYY-MM',
            cost DECIMAL(10,2) NULL,
            estimated_cost DECIMAL(10,2) NULL,
            is_estimate TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_type_month (type, `year_month`),
            INDEX idx_year_month (`year_month`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "<span class='success'>✓ utility_costs tabel aangemaakt (of bestond al)</span>\n";
} catch (PDOException $e) {
    echo "<span class='error'>✗ Error creating table: " . htmlspecialchars($e->getMessage()) . "</span>\n";
}

// Add estimated_cost column if it doesn't exist yet (for servers where 013 did run)
try {
    $pdo->exec("ALTER TABLE utility_costs ADD COLUMN estimated_cost DECIMAL(10,2) NULL AFTER cost");
    echo "<span class='success'>✓ estimated_cost kolom toegevoegd aan utility_costs</span>\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false) {
        echo "<span class='info'>- estimated_cost kolom bestaat al</span>\n";
    } else {
        echo "<span class='error'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</span>\n";
    }
}

// Allow cost column to be NULL (was NOT NULL in 013)
try {
    $pdo->exec("ALTER TABLE utility_costs MODIFY COLUMN cost DECIMAL(10,2) NULL");
    echo "<span class='success'>✓ cost kolom staat nu NULL toe</span>\n";
} catch (PDOException $e) {
    echo "<span class='info'>- cost kolom: " . htmlspecialchars($e->getMessage()) . "</span>\n";
}

// Migrate existing is_estimate=1 rows: move cost → estimated_cost, set cost = NULL
try {
    $pdo->exec("UPDATE utility_costs SET estimated_cost = cost, cost = NULL WHERE is_estimate = 1 AND estimated_cost IS NULL");
    echo "<span class='success'>✓ Bestaande schattingen gemigreerd naar estimated_cost kolom</span>\n";
} catch (PDOException $e) {
    echo "<span class='error'>✗ Error migrating data: " . htmlspecialchars($e->getMessage()) . "</span>\n";
}

echo "\n<span class='success'>✓ Migration 020 voltooid!</span>\n";
?></pre>

<p><strong>Wijzigingen:</strong></p>
<ul>
    <li>utility_costs: +estimated_cost (aparte kolom voor geschatte kosten)</li>
    <li>Bestaande schattingen gemigreerd naar estimated_cost kolom</li>
</ul>

<a href="../bakker/voorraad.php" class="btn">← Naar Voorraadbeheer</a>
</div>
</body>
</html>
