<?php
require_once '../config.php';
requireLogin();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Migration 073</title>
    <style>
        body { font-family: -apple-system, sans-serif; padding: 2rem; background: #f5f2ed; }
        .card { background: white; padding: 2rem; border-radius: 12px; max-width: 600px; margin: 0 auto; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        h1 { color: #5c3d1e; margin-bottom: 1rem; }
        .success { color: #2e7d32; }
        .info    { color: #666; }
        .error   { color: #c62828; }
        pre { background: #f5f5f5; padding: 1rem; border-radius: 8px; overflow-x: auto; margin: 1rem 0; }
        a { color: #8b5a2b; }
        .btn { display: inline-block; padding: 0.75rem 1.5rem; background: #8b5a2b; color: white; text-decoration: none; border-radius: 8px; margin-top: 1rem; }
        .btn:hover { background: #5c3d1e; }
    </style>
</head>
<body>
<div class="card">
<h1>Migration 073: Voorraad bewegingen groepering</h1>
<pre><?php

try {
    $pdo->exec("
        CREATE TABLE voorraad_movements (
            id          INT NOT NULL AUTO_INCREMENT,
            bakactie_id INT NULL DEFAULT NULL,
            created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_bakactie_id (bakactie_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "<span class='success'>✓ Tabel voorraad_movements aangemaakt</span>\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'already exists') !== false)
        echo "<span class='info'>- voorraad_movements bestaat al</span>\n";
    else
        echo "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
}

try {
    $pdo->exec("ALTER TABLE inventory_consumption ADD COLUMN movement_id INT NULL DEFAULT NULL AFTER bakactie_id");
    echo "<span class='success'>✓ Kolom movement_id toegevoegd aan inventory_consumption</span>\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false)
        echo "<span class='info'>- movement_id bestaat al</span>\n";
    else
        echo "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
}

try {
    $pdo->exec("ALTER TABLE inventory_consumption ADD INDEX idx_movement_id (movement_id)");
    echo "<span class='success'>✓ Index op movement_id aangemaakt</span>\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate key name') !== false)
        echo "<span class='info'>- Index bestaat al</span>\n";
    else
        echo "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
}

// Backfill: create one movement record per bakactie that already has consumption rows
try {
    $stmt = $pdo->query("
        SELECT DISTINCT bakactie_id
        FROM inventory_consumption
        WHERE bakactie_id IS NOT NULL AND movement_id IS NULL
        ORDER BY bakactie_id
    ");
    $bakactieIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $count = 0;
    foreach ($bakactieIds as $bakactieId) {
        // Use the earliest consumed_at for this bakactie as the movement timestamp
        $tsStmt = $pdo->prepare("
            SELECT MIN(consumed_at) FROM inventory_consumption WHERE bakactie_id = ?
        ");
        $tsStmt->execute([$bakactieId]);
        $createdAt = $tsStmt->fetchColumn() ?: date('Y-m-d H:i:s');

        $ins = $pdo->prepare("INSERT INTO voorraad_movements (bakactie_id, created_at) VALUES (?, ?)");
        $ins->execute([$bakactieId, $createdAt]);
        $movementId = $pdo->lastInsertId();

        $pdo->prepare("UPDATE inventory_consumption SET movement_id = ? WHERE bakactie_id = ? AND movement_id IS NULL")
            ->execute([$movementId, $bakactieId]);

        $count++;
    }
    echo "<span class='success'>✓ $count bestaande bakacties gekoppeld aan een movement record</span>\n";
} catch (PDOException $e) {
    echo "<span class='error'>✗ Backfill mislukt: " . htmlspecialchars($e->getMessage()) . "</span>\n";
}

echo "\n<span class='success'>✓ Migration 073 voltooid!</span>\n";
?></pre>

<p><strong>Wijzigingen:</strong></p>
<ul>
    <li><code>voorraad_movements</code> — nieuwe tabel, één rij per voorraadafschrijving (gekoppeld aan bakactie)</li>
    <li><code>inventory_consumption.movement_id</code> — FK naar voorraad_movements, groepeert de individuele afschrijfregels</li>
    <li>Backfill: bestaande consumption-rijen gekoppeld aan een nieuw movement record per bakactie</li>
</ul>

<a href="../bakker/bakker-dashboard.php" class="btn">← Naar Dashboard</a>
</div>
</body>
</html>
