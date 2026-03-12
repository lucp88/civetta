<?php
require_once '../config.php';
requireLogin();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Migration 021</title>
    <style>
        body { font-family: -apple-system, sans-serif; padding: 2rem; background: #f5f2ed; }
        .card { background: white; padding: 2rem; border-radius: 12px; max-width: 600px; margin: 0 auto; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        h1 { color: #2d4a2d; margin-bottom: 1rem; }
        .success { color: #2e7d32; }
        .info { color: #666; }
        .error { color: #c62828; }
        pre { background: #f5f5f5; padding: 1rem; border-radius: 8px; overflow-x: auto; margin: 1rem 0; }
        a { color: #3d6b3d; }
        .btn { display: inline-block; padding: 0.75rem 1.5rem; background: #3d6b3d; color: white; text-decoration: none; border-radius: 8px; margin-top: 1rem; }
        .btn:hover { background: #2d4a2d; }
    </style>
</head>
<body>
<div class="card">
<h1>Migration 021: Voedselveiligheid checklist</h1>
<pre><?php

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS schoonmaak_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            naam VARCHAR(255) NOT NULL,
            type ENUM('schoonmaak', 'voorraad') NOT NULL DEFAULT 'schoonmaak',
            frequentie ENUM('dagelijks', 'dagelijks_mits_gebruikt', 'wekelijks', 'maandelijks') NOT NULL DEFAULT 'dagelijks',
            actief TINYINT(1) NOT NULL DEFAULT 1,
            volgorde INT NOT NULL DEFAULT 0,
            aangemaakt_op DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "<span class='success'>✓ schoonmaak_items tabel aangemaakt</span>\n";
} catch (PDOException $e) {
    echo "<span class='error'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</span>\n";
}

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS schoonmaak_lijsten (
            id INT AUTO_INCREMENT PRIMARY KEY,
            datum DATE NOT NULL,
            status ENUM('volledig', 'onvolledig', 'afwijking') NOT NULL DEFAULT 'onvolledig',
            heeft_afwijking TINYINT(1) NOT NULL DEFAULT 0,
            aangemaakt_op DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_datum (datum)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "<span class='success'>✓ schoonmaak_lijsten tabel aangemaakt</span>\n";
} catch (PDOException $e) {
    echo "<span class='error'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</span>\n";
}

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS schoonmaak_lijst_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            lijst_id INT NOT NULL,
            item_id INT NULL,
            naam VARCHAR(255) NOT NULL,
            type ENUM('schoonmaak', 'voorraad') NOT NULL DEFAULT 'schoonmaak',
            frequentie ENUM('dagelijks', 'dagelijks_mits_gebruikt', 'wekelijks', 'maandelijks') NOT NULL DEFAULT 'dagelijks',
            due_date DATE NULL,
            afgevinkt TINYINT(1) NOT NULL DEFAULT 0,
            notities TEXT NULL,
            uitvoerder VARCHAR(255) NULL,
            tijdstip_afgerond DATETIME NULL,
            aangemaakt_op DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (lijst_id) REFERENCES schoonmaak_lijsten(id) ON DELETE CASCADE,
            FOREIGN KEY (item_id) REFERENCES schoonmaak_items(id) ON DELETE SET NULL,
            INDEX idx_lijst_id (lijst_id),
            INDEX idx_item_id (item_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "<span class='success'>✓ schoonmaak_lijst_items tabel aangemaakt</span>\n";
} catch (PDOException $e) {
    echo "<span class='error'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</span>\n";
}

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS schoonmaak_audit_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            lijst_id INT NULL,
            lijst_item_id INT NULL,
            actie VARCHAR(100) NOT NULL,
            gebruiker VARCHAR(255) NULL,
            details TEXT NULL,
            aangemaakt_op DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_lijst_id (lijst_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "<span class='success'>✓ schoonmaak_audit_log tabel aangemaakt</span>\n";
} catch (PDOException $e) {
    echo "<span class='error'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</span>\n";
}

echo "\n<span class='success'>✓ Migration 021 voltooid!</span>\n";
?></pre>

<p><strong>Wijzigingen:</strong></p>
<ul>
    <li>schoonmaak_items: master lijst van schoonmaak/voorraad items</li>
    <li>schoonmaak_lijsten: dagelijkse checklists per datum</li>
    <li>schoonmaak_lijst_items: ingevulde items per daglijst</li>
    <li>schoonmaak_audit_log: audit trail voor late wijzigingen en afwijkingen</li>
</ul>

<a href="../bakker/voedselveiligheid.php" class="btn">← Naar Voedselveiligheid</a>
</div>
</body>
</html>
