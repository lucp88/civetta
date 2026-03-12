<?php
/**
 * Migration: Create recurring_group_templates tables for template-based recurring orders
 * 
 * Run via browser: /admin/migrations/008_recurring_templates.php (requires admin login)
 * Run via CLI: php admin/migrations/008_recurring_templates.php
 */

require_once __DIR__ . '/../config.php';

if (php_sapi_name() !== 'cli') {
    requireLogin();
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><title>Migration 008</title>';
    echo '<style>body{font-family:monospace;padding:2rem;background:#1e1e1e;color:#d4d4d4;}';
    echo '.success{color:#4ec9b0;}.error{color:#f14c4c;}.info{color:#569cd6;}</style></head><body>';
    echo '<h2>Migration 008: Recurring Templates</h2><pre>';
}

function output($msg, $class = '') {
    if (php_sapi_name() === 'cli') {
        echo $msg . "\n";
    } else {
        echo "<span class=\"$class\">$msg</span>\n";
    }
}

output("=== Migration: Recurring Templates ===", "info");
output("");

try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'recurring_group_templates'");
    if ($stmt->fetch()) {
        output("Tabel 'recurring_group_templates' bestaat al, overslaan...", "info");
    } else {
        $pdo->exec("
            CREATE TABLE recurring_group_templates (
                id INT AUTO_INCREMENT PRIMARY KEY,
                recurring_group_id VARCHAR(36) NOT NULL UNIQUE,
                account_id INT NOT NULL,
                name VARCHAR(255),
                notes TEXT,
                frequency ENUM('weekly', 'biweekly', 'monthly') DEFAULT 'weekly',
                delivery_day TINYINT DEFAULT 4,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (account_id) REFERENCES business_accounts(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        output("✓ Tabel 'recurring_group_templates' aangemaakt.", "success");
    }

    $stmt = $pdo->query("SHOW TABLES LIKE 'recurring_group_template_items'");
    if ($stmt->fetch()) {
        output("Tabel 'recurring_group_template_items' bestaat al, overslaan...", "info");
    } else {
        $pdo->exec("
            CREATE TABLE recurring_group_template_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                template_id INT NOT NULL,
                product_name VARCHAR(255) NOT NULL,
                quantity INT NOT NULL DEFAULT 1,
                unit_price DECIMAL(10,2) NOT NULL,
                FOREIGN KEY (template_id) REFERENCES recurring_group_templates(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        output("✓ Tabel 'recurring_group_template_items' aangemaakt.", "success");
    }

    output("");
    output("=== Migratie bestaande recurring groups ===", "info");

    $stmt = $pdo->query("
        SELECT DISTINCT 
            recurring_group_id,
            account_id,
            recurring_name as name,
            recurring_frequency as frequency,
            recurring_day as delivery_day
        FROM business_orders 
        WHERE is_recurring = 1 
        AND recurring_group_id IS NOT NULL
    ");
    $groups = $stmt->fetchAll();

    $checkTemplate = $pdo->prepare("SELECT id FROM recurring_group_templates WHERE recurring_group_id = ?");
    
    $insertTemplate = $pdo->prepare("
        INSERT INTO recurring_group_templates (recurring_group_id, account_id, name, frequency, delivery_day)
        VALUES (?, ?, ?, ?, ?)
    ");

    $insertItem = $pdo->prepare("
        INSERT INTO recurring_group_template_items (template_id, product_name, quantity, unit_price)
        VALUES (?, ?, ?, ?)
    ");

    $getItems = $pdo->prepare("
        SELECT product_name, quantity, unit_price 
        FROM business_order_items 
        WHERE order_id = (
            SELECT id FROM business_orders 
            WHERE recurring_group_id = ? 
            ORDER BY id ASC LIMIT 1
        )
    ");

    $migrated = 0;
    $skipped = 0;

    foreach ($groups as $group) {
        $checkTemplate->execute([$group['recurring_group_id']]);
        if ($checkTemplate->fetch()) {
            $skipped++;
            continue;
        }

        $insertTemplate->execute([
            $group['recurring_group_id'],
            $group['account_id'],
            $group['name'],
            $group['frequency'] ?: 'weekly',
            $group['delivery_day'] ?: 4
        ]);
        $templateId = $pdo->lastInsertId();

        $getItems->execute([$group['recurring_group_id']]);
        $items = $getItems->fetchAll();

        foreach ($items as $item) {
            $insertItem->execute([
                $templateId,
                $item['product_name'],
                $item['quantity'],
                $item['unit_price']
            ]);
        }

        output("✓ Template aangemaakt voor: {$group['recurring_group_id']}", "success");
        $migrated++;
    }

    output("");
    output("=== Migration voltooid ===", "success");
    output("$migrated templates aangemaakt, $skipped overgeslagen (bestonden al).", "info");

} catch (PDOException $e) {
    output("FOUT: " . $e->getMessage(), "error");
    if (php_sapi_name() !== 'cli') {
        echo '</pre></body></html>';
    }
    exit(1);
}

if (php_sapi_name() !== 'cli') {
    echo '</pre>';
    echo '<p><a href="/admin/" style="color:#569cd6;">← Terug naar admin</a></p>';
    echo '</body></html>';
}
