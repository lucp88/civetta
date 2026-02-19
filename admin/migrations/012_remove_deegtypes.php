<?php
require_once '../config.php';
requireLogin();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Migration 012</title>
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
<h1>Migration 012: Remove Deegtypes</h1>
<pre><?php

try {
    $pdo->exec("ALTER TABLE products DROP INDEX idx_deegtype_id");
    echo "<span class='success'>✓ Dropped index idx_deegtype_id</span>\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), "check that column/key exists") !== false || strpos($e->getMessage(), "Can't DROP") !== false) {
        echo "<span class='info'>- Index idx_deegtype_id does not exist</span>\n";
    } else {
        echo "<span class='error'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</span>\n";
    }
}

try {
    $pdo->exec("ALTER TABLE products DROP COLUMN deegtype_id");
    echo "<span class='success'>✓ Dropped deegtype_id column from products</span>\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), "check that column/key exists") !== false || strpos($e->getMessage(), "Unknown column") !== false) {
        echo "<span class='info'>- deegtype_id column does not exist</span>\n";
    } else {
        echo "<span class='error'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</span>\n";
    }
}

try {
    $pdo->exec("DROP TABLE IF EXISTS deegtypes");
    echo "<span class='success'>✓ Dropped deegtypes table</span>\n";
} catch (PDOException $e) {
    echo "<span class='error'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</span>\n";
}

echo "\n<span class='success'>✓ Migration completed!</span>\n";
?></pre>

<p><strong>Note:</strong> Deegtypes zijn vervangen door recepten uit de Bak Calculator.</p>

<a href="../index.php" class="btn">← Terug naar Admin</a>
</div>
</body>
</html>
