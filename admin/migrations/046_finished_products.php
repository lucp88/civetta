<?php
require_once '../config.php';
requireLogin();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Migration 046</title>
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
<h1>Migration 046: Afgemaakte producten opslag</h1>
<pre><?php
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS finished_products (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_name VARCHAR(255) NOT NULL,
            quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
            unit VARCHAR(50) NOT NULL DEFAULT 'stuks',
            location ENUM('kast', 'koelkast', 'vriezer') NOT NULL DEFAULT 'kast',
            status ENUM('beschikbaar', 'gereserveerd', 'gebruikt') NOT NULL DEFAULT 'beschikbaar',
            order_id INT NULL,
            notes TEXT,
            production_date DATE NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_location (location),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "<span class='success'>✓ Tabel finished_products aangemaakt</span>\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'already exists') !== false) {
        echo "<span class='info'>- Tabel finished_products bestaat al</span>\n";
    } else {
        echo "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
    }
}

echo "\n<span class='success'>✓ Migration 046 voltooid!</span>\n";
?></pre>

<p><strong>Wijzigingen:</strong></p>
<ul>
    <li>Nieuwe tabel <code>finished_products</code> voor het bijhouden van afgemaakte producten in kast/koelkast/vriezer</li>
    <li>Veld <code>status</code>: beschikbaar / gereserveerd / gebruikt — klaar voor toekomstige koppeling met bestellingen</li>
    <li>Veld <code>order_id</code>: toekomstige koppeling met business_orders</li>
</ul>

<a href="../bakker/voorraad.php" class="btn">← Naar Voorraadbeheer</a>
</div>
</body>
</html>
