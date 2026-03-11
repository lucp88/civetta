<?php
require_once '../config.php';
requireLogin();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Migration 022</title>
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
<h1>Migration 022: Categorieën voor voedselveiligheid</h1>
<pre><?php

// Categories table
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS schoonmaak_categorieen (
            id INT AUTO_INCREMENT PRIMARY KEY,
            naam VARCHAR(255) NOT NULL,
            volgorde INT NOT NULL DEFAULT 0,
            aangemaakt_op DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "<span class='success'>✓ schoonmaak_categorieen tabel aangemaakt</span>\n";
} catch (PDOException $e) {
    echo "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
}

// categorie_id on items
try {
    $pdo->exec("ALTER TABLE schoonmaak_items ADD COLUMN categorie_id INT NULL AFTER naam");
    echo "<span class='success'>✓ categorie_id toegevoegd aan schoonmaak_items</span>\n";
} catch (PDOException $e) {
    echo strpos($e->getMessage(), 'Duplicate') !== false
        ? "<span class='info'>- categorie_id bestaat al</span>\n"
        : "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
}

// FK categorie_id → schoonmaak_categorieen
try {
    $pdo->exec("ALTER TABLE schoonmaak_items ADD CONSTRAINT fk_item_categorie FOREIGN KEY (categorie_id) REFERENCES schoonmaak_categorieen(id) ON DELETE SET NULL");
    echo "<span class='success'>✓ FK categorie_id aangemaakt</span>\n";
} catch (PDOException $e) {
    echo "<span class='info'>- FK: " . htmlspecialchars($e->getMessage()) . "</span>\n";
}

// categorie_naam snapshot on lijst_items
try {
    $pdo->exec("ALTER TABLE schoonmaak_lijst_items ADD COLUMN categorie_naam VARCHAR(255) NULL AFTER naam");
    echo "<span class='success'>✓ categorie_naam toegevoegd aan schoonmaak_lijst_items</span>\n";
} catch (PDOException $e) {
    echo strpos($e->getMessage(), 'Duplicate') !== false
        ? "<span class='info'>- categorie_naam bestaat al</span>\n"
        : "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
}

// is_due flag on lijst_items
try {
    $pdo->exec("ALTER TABLE schoonmaak_lijst_items ADD COLUMN is_due TINYINT(1) NOT NULL DEFAULT 1 AFTER due_date");
    echo "<span class='success'>✓ is_due toegevoegd aan schoonmaak_lijst_items</span>\n";
} catch (PDOException $e) {
    echo strpos($e->getMessage(), 'Duplicate') !== false
        ? "<span class='info'>- is_due bestaat al</span>\n"
        : "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
}

echo "\n<span class='success'>✓ Migration 022 voltooid!</span>\n";
?></pre>

<p><strong>Wijzigingen:</strong></p>
<ul>
    <li>schoonmaak_categorieen: nieuwe tabel voor item-categorieën</li>
    <li>schoonmaak_items: +categorie_id (FK)</li>
    <li>schoonmaak_lijst_items: +categorie_naam (snapshot), +is_due (was item due bij aanmaken formulier)</li>
</ul>

<a href="../bakker/voedselveiligheid.php" class="btn">← Naar Voedselveiligheid</a>
</div>
</body>
</html>
