<?php
require_once '../config.php';
requireLogin();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Migration 047</title>
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
<h1>Migration 047: Productcategorieën</h1>
<pre><?php
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS product_categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            naam VARCHAR(100) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "<span class='success'>✓ Tabel product_categories aangemaakt</span>\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'already exists') !== false) {
        echo "<span class='info'>- Tabel product_categories bestaat al</span>\n";
    } else {
        echo "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
    }
}

try {
    $pdo->exec("ALTER TABLE products ADD COLUMN category_id INT NULL AFTER id");
    echo "<span class='success'>✓ Kolom category_id toegevoegd aan products</span>\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false) {
        echo "<span class='info'>- Kolom category_id bestaat al</span>\n";
    } else {
        echo "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
    }
}

try {
    $count = $pdo->query("SELECT COUNT(*) FROM product_categories WHERE naam = 'Brood'")->fetchColumn();
    if ($count == 0) {
        $pdo->exec("INSERT INTO product_categories (naam, sort_order) VALUES ('Brood', 0)");
        echo "<span class='success'>✓ Standaard categorie 'Brood' aangemaakt</span>\n";
    } else {
        echo "<span class='info'>- Categorie 'Brood' bestaat al</span>\n";
    }
} catch (PDOException $e) {
    echo "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
}

try {
    $catId = $pdo->query("SELECT id FROM product_categories WHERE naam = 'Brood' LIMIT 1")->fetchColumn();
    if ($catId) {
        $updated = $pdo->exec("UPDATE products SET category_id = $catId WHERE category_id IS NULL");
        echo "<span class='success'>✓ $updated bestaande producten aan 'Brood' gekoppeld</span>\n";
    }
} catch (PDOException $e) {
    echo "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
}

echo "\n<span class='success'>✓ Migration 047 voltooid!</span>\n";
?></pre>

<p><strong>Wijzigingen:</strong></p>
<ul>
    <li>Nieuwe tabel <code>product_categories</code> voor productcategorieën (bijv. Brood, Granola)</li>
    <li>Kolom <code>category_id</code> toegevoegd aan <code>products</code></li>
    <li>Standaard categorie 'Brood' aangemaakt en alle bestaande producten hieraan gekoppeld</li>
</ul>

<a href="../producten/products.php" class="btn">← Naar Producten</a>
</div>
</body>
</html>
