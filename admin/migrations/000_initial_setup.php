<?php
require_once __DIR__ . '/../config.php';
requireLogin();

$results = [];
$error = '';

function tableExists($pdo, $table) {
    $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
    $stmt->execute([$table]);
    return $stmt->rowCount() > 0;
}

function columnExists($pdo, $table, $column) {
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $stmt->execute([$column]);
    return $stmt->rowCount() > 0;
}

function indexExists($pdo, $table, $indexName) {
    $stmt = $pdo->prepare("SHOW INDEX FROM `$table` WHERE Key_name = ?");
    $stmt->execute([$indexName]);
    return $stmt->rowCount() > 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!tableExists($pdo, 'business_accounts')) {
            $pdo->exec("
                CREATE TABLE business_accounts (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    bedrijfsnaam VARCHAR(255) NOT NULL,
                    adres TEXT NOT NULL,
                    postcode VARCHAR(10),
                    plaats VARCHAR(100),
                    contactpersoon VARCHAR(255) NOT NULL,
                    email VARCHAR(255) NOT NULL,
                    telefoon VARCHAR(20),
                    website VARCHAR(255),
                    opmerkingen TEXT,
                    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
                    password_hash VARCHAR(255) NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    approved_at TIMESTAMP NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $results[] = ['type' => 'created', 'item' => 'Tabel business_accounts aangemaakt'];
        } else {
            $results[] = ['type' => 'exists', 'item' => 'Tabel business_accounts bestaat al'];
        }

        $accountColumns = [
            'password_hash' => 'VARCHAR(255) NULL',
            'kvk_nummer' => 'VARCHAR(20) NULL',
            'btw_id' => 'VARCHAR(30) NULL',
            'delivery_same_as_business' => 'TINYINT(1) DEFAULT 1',
            'delivery_adres' => 'VARCHAR(255) NULL',
            'delivery_postcode' => 'VARCHAR(10) NULL',
            'delivery_plaats' => 'VARCHAR(100) NULL',
            'delivery_contactpersoon' => 'VARCHAR(255) NULL'
        ];
        
        foreach ($accountColumns as $col => $def) {
            if (!columnExists($pdo, 'business_accounts', $col)) {
                $pdo->exec("ALTER TABLE business_accounts ADD COLUMN $col $def");
                $results[] = ['type' => 'created', 'item' => "Kolom business_accounts.$col toegevoegd"];
            } else {
                $results[] = ['type' => 'exists', 'item' => "Kolom business_accounts.$col bestaat al"];
            }
        }

        if (!tableExists($pdo, 'business_orders')) {
            $pdo->exec("
                CREATE TABLE business_orders (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    account_id INT NOT NULL,
                    delivery_date DATE NOT NULL,
                    status VARCHAR(30) DEFAULT 'pending',
                    total_amount DECIMAL(10,2),
                    notes TEXT,
                    mollie_payment_id VARCHAR(50) NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    FOREIGN KEY (account_id) REFERENCES business_accounts(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $results[] = ['type' => 'created', 'item' => 'Tabel business_orders aangemaakt'];
        } else {
            $results[] = ['type' => 'exists', 'item' => 'Tabel business_orders bestaat al'];
        }

        $orderColumns = [
            'delivery_date' => 'DATE NULL',
            'mollie_payment_id' => 'VARCHAR(50) NULL',
            'mollie_status' => 'VARCHAR(20) NULL',
            'mollie_status_updated_at' => 'TIMESTAMP NULL',
            'eboekhouden_invoice_id' => 'INT NULL',
            'eboekhouden_factuurnummer' => 'VARCHAR(50) NULL',
            'eboekhouden_pdf_url' => 'TEXT NULL',
            'facturatie_systeem' => 'VARCHAR(20) NULL',
            'payment_type' => "VARCHAR(20) DEFAULT 'direct'",
            'recurring_order_id' => 'INT NULL',
            'is_auto_generated' => 'TINYINT(1) DEFAULT 0',
            'is_recurring' => 'TINYINT(1) DEFAULT 0',
            'recurring_name' => 'VARCHAR(255) NULL',
            'recurring_frequency' => "VARCHAR(20) DEFAULT 'weekly'",
            'recurring_day' => 'TINYINT DEFAULT 1',
            'recurring_end_date' => 'DATE NULL',
            'invoice_number' => 'VARCHAR(50) NULL',
            'invoiced_at' => 'DATETIME NULL',
            'recurring_group_id' => 'VARCHAR(50) NULL',
            'recurring_confirmed_until' => 'DATE NULL',
            'recurring_parent_id' => 'INT NULL',
            'delivery_address' => 'VARCHAR(500) NULL'
        ];
        
        foreach ($orderColumns as $col => $def) {
            if (!columnExists($pdo, 'business_orders', $col)) {
                $pdo->exec("ALTER TABLE business_orders ADD COLUMN $col $def");
                $results[] = ['type' => 'created', 'item' => "Kolom business_orders.$col toegevoegd"];
            } else {
                $results[] = ['type' => 'exists', 'item' => "Kolom business_orders.$col bestaat al"];
            }
        }

        $indexes = [
            'idx_recurring_group' => 'recurring_group_id',
            'idx_delivery_date' => 'delivery_date'
        ];
        
        foreach ($indexes as $indexName => $col) {
            if (!indexExists($pdo, 'business_orders', $indexName)) {
                $pdo->exec("ALTER TABLE business_orders ADD INDEX $indexName ($col)");
                $results[] = ['type' => 'created', 'item' => "Index $indexName toegevoegd"];
            } else {
                $results[] = ['type' => 'exists', 'item' => "Index $indexName bestaat al"];
            }
        }

        if (!tableExists($pdo, 'business_order_items')) {
            $pdo->exec("
                CREATE TABLE business_order_items (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    order_id INT NOT NULL,
                    product_name VARCHAR(255) NOT NULL,
                    quantity INT NOT NULL,
                    unit_price DECIMAL(10,2),
                    FOREIGN KEY (order_id) REFERENCES business_orders(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $results[] = ['type' => 'created', 'item' => 'Tabel business_order_items aangemaakt'];
        } else {
            $results[] = ['type' => 'exists', 'item' => 'Tabel business_order_items bestaat al'];
        }

        if (!tableExists($pdo, 'business_favorites')) {
            $pdo->exec("
                CREATE TABLE business_favorites (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    account_id INT NOT NULL,
                    naam VARCHAR(255) NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (account_id) REFERENCES business_accounts(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $results[] = ['type' => 'created', 'item' => 'Tabel business_favorites aangemaakt'];
        } else {
            $results[] = ['type' => 'exists', 'item' => 'Tabel business_favorites bestaat al'];
        }

        if (!tableExists($pdo, 'business_favorite_items')) {
            $pdo->exec("
                CREATE TABLE business_favorite_items (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    favorite_id INT NOT NULL,
                    product_id INT NULL,
                    product_name VARCHAR(255) NOT NULL,
                    quantity INT NOT NULL,
                    unit_price DECIMAL(10,2),
                    FOREIGN KEY (favorite_id) REFERENCES business_favorites(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $results[] = ['type' => 'created', 'item' => 'Tabel business_favorite_items aangemaakt'];
        } else {
            $results[] = ['type' => 'exists', 'item' => 'Tabel business_favorite_items bestaat al'];
        }

        if (!tableExists($pdo, 'business_recurring_orders')) {
            $pdo->exec("
                CREATE TABLE business_recurring_orders (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    account_id INT NOT NULL,
                    name VARCHAR(255) NOT NULL,
                    frequency ENUM('weekly', 'biweekly', 'monthly') NOT NULL,
                    delivery_day TINYINT NOT NULL,
                    next_delivery_date DATE NOT NULL,
                    end_date DATE NULL,
                    status ENUM('active', 'paused', 'cancelled') DEFAULT 'active',
                    notes TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    FOREIGN KEY (account_id) REFERENCES business_accounts(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $results[] = ['type' => 'created', 'item' => 'Tabel business_recurring_orders aangemaakt (deprecated)'];
        } else {
            $results[] = ['type' => 'exists', 'item' => 'Tabel business_recurring_orders bestaat al (deprecated)'];
        }

        if (!tableExists($pdo, 'business_recurring_order_items')) {
            $pdo->exec("
                CREATE TABLE business_recurring_order_items (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    recurring_order_id INT NOT NULL,
                    product_id INT NULL,
                    product_name VARCHAR(255) NOT NULL,
                    quantity INT NOT NULL,
                    unit_price DECIMAL(10,2),
                    FOREIGN KEY (recurring_order_id) REFERENCES business_recurring_orders(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $results[] = ['type' => 'created', 'item' => 'Tabel business_recurring_order_items aangemaakt (deprecated)'];
        } else {
            $results[] = ['type' => 'exists', 'item' => 'Tabel business_recurring_order_items bestaat al (deprecated)'];
        }

        if (!tableExists($pdo, 'invoice_log')) {
            $pdo->exec("
                CREATE TABLE invoice_log (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    account_id INT NOT NULL,
                    invoice_number VARCHAR(50) NOT NULL,
                    total_amount DECIMAL(10,2) NOT NULL,
                    order_count INT NOT NULL,
                    period_start DATE NOT NULL,
                    period_end DATE NOT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (account_id) REFERENCES business_accounts(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $results[] = ['type' => 'created', 'item' => 'Tabel invoice_log aangemaakt'];
        } else {
            $results[] = ['type' => 'exists', 'item' => 'Tabel invoice_log bestaat al'];
        }

    } catch (PDOException $e) {
        $error = 'Fout: ' . $e->getMessage();
    }
}

$createdCount = count(array_filter($results, fn($r) => $r['type'] === 'created'));
$existsCount = count(array_filter($results, fn($r) => $r['type'] === 'exists'));
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Migratie 000: Initial Setup | Civetta Admin</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #f5f2ed;
            min-height: 100vh;
        }
        .header {
            background: linear-gradient(135deg, #8b5a2b, #5c3d1e);
            color: white;
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header h1 { font-size: 1.5rem; }
        .header a {
            color: white;
            text-decoration: none;
            padding: 0.5rem 1rem;
            background: rgba(255,255,255,0.2);
            border-radius: 6px;
        }
        .container {
            max-width: 800px;
            margin: 2rem auto;
            padding: 0 1rem;
        }
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            padding: 2rem;
            margin-bottom: 1rem;
        }
        .card h2 {
            color: #5c3d1e;
            margin-bottom: 1rem;
        }
        .card p {
            color: #666;
            margin-bottom: 1.5rem;
            line-height: 1.6;
        }
        .btn {
            display: inline-block;
            padding: 0.75rem 1.5rem;
            background: #8b5a2b;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-size: 1rem;
        }
        .btn:hover { background: #5c3d1e; }
        .summary {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            background: #e8f4e8;
            border: 1px solid #c3e6c3;
        }
        .summary.warning {
            background: #fff3cd;
            border-color: #ffc107;
        }
        .summary strong { color: #155724; }
        .summary.warning strong { color: #856404; }
        .error {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            background: #f8d7da;
            color: #721c24;
        }
        .results {
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid #ddd;
            border-radius: 8px;
        }
        .result-item {
            padding: 0.5rem 1rem;
            border-bottom: 1px solid #eee;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .result-item:last-child { border-bottom: none; }
        .result-item.created { background: #d4edda; }
        .result-item.exists { background: #f8f9fa; }
        .icon { font-size: 1.1rem; }
        .breadcrumb {
            margin-bottom: 1.5rem;
        }
        .breadcrumb a {
            color: #8b5a2b;
            text-decoration: none;
        }
        .breadcrumb span {
            color: #888;
            margin: 0 0.5rem;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Civetta Admin</h1>
        <a href="../logout.php">Uitloggen</a>
    </div>
    
    <div class="container">
        <div class="breadcrumb">
            <a href="../index.php">Dashboard</a>
            <span>></span>
            Migratie 000: Initial Setup
        </div>

        <div class="card">
            <h2>Migratie 000: Initial Setup</h2>
            
            <?php if ($error): ?>
                <div class="error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            
            <?php if (!empty($results)): ?>
                <div class="summary <?= $createdCount === 0 ? 'warning' : '' ?>">
                    <strong><?= $createdCount ?> items aangemaakt</strong>, 
                    <?= $existsCount ?> items bestonden al
                    <?php if ($createdCount === 0): ?>
                        <br><em>Database was al up-to-date!</em>
                    <?php endif; ?>
                </div>
                
                <div class="results">
                    <?php foreach ($results as $result): ?>
                        <div class="result-item <?= $result['type'] ?>">
                            <span class="icon"><?= $result['type'] === 'created' ? '+' : '-' ?></span>
                            <?= htmlspecialchars($result['item']) ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p>Deze migratie maakt alle basis tabellen en kolommen aan voor het bedrijven bestelsysteem.</p>
                
                <form method="POST">
                    <button type="submit" class="btn">Migratie uitvoeren</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
