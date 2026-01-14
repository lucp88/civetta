<?php
require_once 'config.php';
requireLogin();

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS business_accounts (
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
        
        $pdo->exec("
            ALTER TABLE business_accounts 
            ADD COLUMN IF NOT EXISTS password_hash VARCHAR(255) NULL AFTER status
        ");
        
        $pdo->exec("ALTER TABLE business_accounts ADD COLUMN IF NOT EXISTS kvk_nummer VARCHAR(20) NULL AFTER website");
        $pdo->exec("ALTER TABLE business_accounts ADD COLUMN IF NOT EXISTS btw_id VARCHAR(30) NULL AFTER kvk_nummer");
        
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS business_orders (
                id INT AUTO_INCREMENT PRIMARY KEY,
                account_id INT NOT NULL,
                delivery_date DATE NOT NULL,
                status ENUM('pending', 'paid', 'confirmed', 'delivered', 'cancelled') DEFAULT 'pending',
                total_amount DECIMAL(10,2),
                notes TEXT,
                mollie_payment_id VARCHAR(50) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (account_id) REFERENCES business_accounts(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        
        $pdo->exec("ALTER TABLE business_orders ADD COLUMN IF NOT EXISTS delivery_date DATE NULL AFTER account_id");
        $pdo->exec("ALTER TABLE business_orders ADD COLUMN IF NOT EXISTS mollie_payment_id VARCHAR(50) NULL AFTER notes");
        $pdo->exec("ALTER TABLE business_orders ADD COLUMN IF NOT EXISTS mollie_status VARCHAR(20) NULL AFTER mollie_payment_id");
        $pdo->exec("ALTER TABLE business_orders ADD COLUMN IF NOT EXISTS mollie_status_updated_at TIMESTAMP NULL AFTER mollie_status");
        
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS business_order_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                order_id INT NOT NULL,
                product_name VARCHAR(255) NOT NULL,
                quantity INT NOT NULL,
                unit_price DECIMAL(10,2),
                FOREIGN KEY (order_id) REFERENCES business_orders(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        
        $message = 'Alle tabellen succesvol aangemaakt!';
    } catch (PDOException $e) {
        $error = 'Fout: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup Business Accounts | Civetta Admin</title>
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
            max-width: 600px;
            margin: 2rem auto;
            padding: 0 1rem;
        }
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            padding: 2rem;
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
        .message {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
        }
        .message.success {
            background: #d4edda;
            color: #155724;
        }
        .message.error {
            background: #f8d7da;
            color: #721c24;
        }
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
        <a href="logout.php">Uitloggen</a>
    </div>
    
    <div class="container">
        <div class="breadcrumb">
            <a href="index.php">Dashboard</a>
            <span>›</span>
            Setup Business Accounts
        </div>

        <div class="card">
            <h2>Database Setup</h2>
            
            <?php if ($message): ?>
                <div class="message success"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="message error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            
            <p>Klik op de knop hieronder om de tabel voor bedrijfsaccounts aan te maken in de database.</p>
            
            <form method="POST">
                <button type="submit" class="btn">Maak business_accounts tabel aan</button>
            </form>
        </div>
    </div>
</body>
</html>
