<?php
require_once '../config.php';
requireLogin();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Migration 041</title>
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
<h1>Migration 041: Sort order producten</h1>
<pre><?php

try {
    $pdo->exec("ALTER TABLE products ADD COLUMN sort_order INT NOT NULL DEFAULT 0");
    echo "<span class='success'>✓ Kolom sort_order toegevoegd aan products</span>\n";
} catch (PDOException $e) {
    if (str_contains($e->getMessage(), 'Duplicate column')) {
        echo "<span class='info'>- sort_order bestaat al in products</span>\n";
    } else {
        echo "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
    }
}

// Initialize sort_order based on current name order
try {
    $rows = $pdo->query("SELECT id FROM products ORDER BY naam ASC")->fetchAll();
    foreach ($rows as $i => $row) {
        $pdo->prepare("UPDATE products SET sort_order = ? WHERE id = ?")->execute([$i, $row['id']]);
    }
    echo "<span class='success'>✓ sort_order geïnitialiseerd voor " . count($rows) . " producten</span>\n";
} catch (PDOException $e) {
    echo "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
}

echo "\n<span class='success'>✓ Migration 041 voltooid!</span>\n";
?></pre>

<p><strong>Wijzigingen:</strong></p>
<ul>
    <li>Kolom <code>sort_order</code> toegevoegd aan <code>products</code></li>
</ul>

<a href="../producten/products.php" class="btn">← Naar Producten</a>
</div>
</body>
</html>
