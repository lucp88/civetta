<?php
require_once '../config.php';
requireLogin();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Migration 033</title>
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
<h1>Migration 033: Particulieren & Saldo</h1>
<pre><?php

// 1. Add account_type column
try {
    $pdo->exec("ALTER TABLE business_accounts ADD COLUMN account_type ENUM('zakelijk', 'particulier') NOT NULL DEFAULT 'zakelijk' AFTER id");
    echo "<span class='success'>✓ Kolom account_type toegevoegd aan business_accounts</span>\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate') !== false) {
        echo "<span class='info'>- Kolom account_type bestaat al</span>\n";
    } else {
        echo "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
    }
}

// 2. Add has_balance column
try {
    $pdo->exec("ALTER TABLE business_accounts ADD COLUMN has_balance TINYINT(1) NOT NULL DEFAULT 0 AFTER btw_id");
    echo "<span class='success'>✓ Kolom has_balance toegevoegd aan business_accounts</span>\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate') !== false) {
        echo "<span class='info'>- Kolom has_balance bestaat al</span>\n";
    } else {
        echo "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
    }
}

// 3. Add balance column
try {
    $pdo->exec("ALTER TABLE business_accounts ADD COLUMN balance DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER has_balance");
    echo "<span class='success'>✓ Kolom balance toegevoegd aan business_accounts</span>\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate') !== false) {
        echo "<span class='info'>- Kolom balance bestaat al</span>\n";
    } else {
        echo "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
    }
}

// 4. Create balance_transactions table
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS balance_transactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        account_id INT NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        type ENUM('credit', 'debit', 'order', 'refund') NOT NULL,
        description VARCHAR(255) NOT NULL,
        order_id INT DEFAULT NULL,
        created_by VARCHAR(100) NOT NULL DEFAULT 'admin',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (account_id) REFERENCES business_accounts(id) ON DELETE CASCADE,
        FOREIGN KEY (order_id) REFERENCES business_orders(id) ON DELETE SET NULL,
        INDEX idx_account_id (account_id),
        INDEX idx_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "<span class='success'>✓ Tabel balance_transactions aangemaakt</span>\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'already exists') !== false) {
        echo "<span class='info'>- Tabel balance_transactions bestaat al</span>\n";
    } else {
        echo "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
    }
}

echo "\n<span class='success'>✓ Migration 033 voltooid!</span>\n";
?></pre>

<p><strong>Wijzigingen:</strong></p>
<ul>
    <li><code>account_type</code> kolom op business_accounts (zakelijk/particulier)</li>
    <li><code>has_balance</code> kolom op business_accounts (saldo ingeschakeld per account)</li>
    <li><code>balance</code> kolom op business_accounts (huidig saldo bedrag)</li>
    <li><code>balance_transactions</code> tabel voor saldo-mutaties tracking</li>
</ul>

<a href="../bakker/bakker-dashboard.php" class="btn">← Naar Dashboard</a>
</div>
</body>
</html>
