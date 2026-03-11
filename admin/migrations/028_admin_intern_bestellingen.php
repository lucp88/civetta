<?php
require_once '../config.php';
requireLogin();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Migration 028</title>
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
<h1>Migration 028: Admin Interne Bestellingen</h1>
<pre><?php

// 1. Add is_internal column to business_orders
try {
    $check = $pdo->query("SHOW COLUMNS FROM business_orders LIKE 'is_internal'");
    if ($check->rowCount() > 0) {
        echo "<span class='info'>- is_internal kolom bestaat al</span>\n";
    } else {
        $pdo->exec("ALTER TABLE business_orders ADD COLUMN is_internal TINYINT(1) NOT NULL DEFAULT 0 AFTER is_cancelled");
        echo "<span class='success'>✓ is_internal kolom toegevoegd aan business_orders</span>\n";
    }
} catch (PDOException $e) {
    echo "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
}

// 2. Create "Civetta (Intern)" account in business_accounts
try {
    $check = $pdo->query("SELECT id FROM business_accounts WHERE bedrijfsnaam = 'Civetta (Intern)'");
    if ($check->rowCount() > 0) {
        echo "<span class='info'>- Civetta (Intern) account bestaat al</span>\n";
    } else {
        // Get bakery details from settings
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'bedrijf_%'");
        $bedrijf = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $stmt = $pdo->prepare("INSERT INTO business_accounts
            (bedrijfsnaam, adres, postcode, plaats, contactpersoon, email, telefoon, status, delivery_same_as_business, password_hash, created_at, approved_at)
            VALUES ('Civetta (Intern)', ?, ?, ?, ?, ?, '', 'approved', 1, '', NOW(), NOW())
        ");
        $stmt->execute([
            $bedrijf['bedrijf_adres'] ?? '',
            $bedrijf['bedrijf_postcode'] ?? '',
            $bedrijf['bedrijf_plaats'] ?? '',
            $bedrijf['bedrijf_naam'] ?? 'Bakkerij Civetta',
            $bedrijf['bedrijf_email'] ?? 'info@bakkerij-civetta.nl'
        ]);
        echo "<span class='success'>✓ Civetta (Intern) account aangemaakt (ID: " . $pdo->lastInsertId() . ")</span>\n";
    }
} catch (PDOException $e) {
    echo "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
}

echo "\n<span class='success'>✓ Migration 028 voltooid!</span>\n";
?></pre>

<p><strong>Wijzigingen:</strong></p>
<ul>
    <li><code>is_internal</code> kolom toegevoegd aan <code>business_orders</code> (voor interne Civetta-bestellingen)</li>
    <li>"Civetta (Intern)" account aangemaakt in <code>business_accounts</code> (met bakkerij-adres uit instellingen)</li>
</ul>

<a href="../bakker/bakker-dashboard.php" class="btn">&larr; Naar Dashboard</a>
</div>
</body>
</html>
