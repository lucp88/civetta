<?php
require_once '../config.php';
requireLogin();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Migration 051</title>
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
<h1>Migration 051: Gedeeld account (tweede contactpersoon)</h1>
<pre><?php

$columns = [
    'tweede_contactpersoon' => "ALTER TABLE business_accounts ADD COLUMN tweede_contactpersoon VARCHAR(255) NULL",
    'tweede_email'          => "ALTER TABLE business_accounts ADD COLUMN tweede_email VARCHAR(255) NULL",
    'tweede_password_hash'  => "ALTER TABLE business_accounts ADD COLUMN tweede_password_hash VARCHAR(255) NULL",
    'tweede_invite_token'   => "ALTER TABLE business_accounts ADD COLUMN tweede_invite_token VARCHAR(64) NULL",
    'tweede_invite_accepted_at' => "ALTER TABLE business_accounts ADD COLUMN tweede_invite_accepted_at DATETIME NULL",
    'tweede_invite_opened_at'   => "ALTER TABLE business_accounts ADD COLUMN tweede_invite_opened_at DATETIME NULL",
    'tweede_pw_reset_token'     => "ALTER TABLE business_accounts ADD COLUMN tweede_pw_reset_token VARCHAR(64) NULL",
    'tweede_pw_reset_expires'   => "ALTER TABLE business_accounts ADD COLUMN tweede_pw_reset_expires DATETIME NULL",
];

foreach ($columns as $col => $sql) {
    try {
        $pdo->exec($sql);
        echo "<span class='success'>✓ Kolom `$col` toegevoegd</span>\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            echo "<span class='info'>- Kolom `$col` bestaat al</span>\n";
        } else {
            echo "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
        }
    }
}

$indexes = [
    'idx_tweede_email'         => "CREATE UNIQUE INDEX idx_tweede_email ON business_accounts (tweede_email)",
    'idx_tweede_invite_token'  => "CREATE UNIQUE INDEX idx_tweede_invite_token ON business_accounts (tweede_invite_token)",
];

foreach ($indexes as $name => $sql) {
    try {
        $pdo->exec($sql);
        echo "<span class='success'>✓ Unique index `$name` aangemaakt</span>\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate key name') !== false || strpos($e->getMessage(), 'already exists') !== false) {
            echo "<span class='info'>- Index `$name` bestaat al</span>\n";
        } else {
            echo "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
        }
    }
}

echo "\n<span class='success'>✓ Migration 051 voltooid!</span>\n";
?></pre>

<p><strong>Wijzigingen:</strong></p>
<ul>
    <li>Kolommen voor tweede contactpersoon toegevoegd aan business_accounts</li>
    <li>Unique index op tweede_email (elk e-mailadres bij slechts één account)</li>
    <li>Unique index op tweede_invite_token</li>
</ul>

<a href="../bakker/bakker-dashboard.php" class="btn">← Naar Dashboard</a>
</div>
</body>
</html>
