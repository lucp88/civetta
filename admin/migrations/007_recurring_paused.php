<?php
/**
 * Migration: Add is_paused column to business_orders for recurring pause functionality
 * 
 * Run via browser: /admin/migrations/007_recurring_paused.php (requires admin login)
 * Run via CLI: php admin/migrations/007_recurring_paused.php
 */

require_once __DIR__ . '/../config.php';

if (php_sapi_name() !== 'cli') {
    requireLogin();
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><title>Migration 007</title>';
    echo '<style>body{font-family:monospace;padding:2rem;background:#1e1e1e;color:#d4d4d4;}';
    echo '.success{color:#4ec9b0;}.error{color:#f14c4c;}.info{color:#569cd6;}</style></head><body>';
    echo '<h2>Migration 007: Recurring Paused</h2><pre>';
}

function output($msg, $class = '') {
    if (php_sapi_name() === 'cli') {
        echo $msg . "\n";
    } else {
        echo "<span class=\"$class\">$msg</span>\n";
    }
}

output("=== Migration: Recurring Paused ===", "info");
output("");

try {
    $stmt = $pdo->query("SHOW COLUMNS FROM business_orders LIKE 'is_paused'");
    if ($stmt->fetch()) {
        output("Kolom 'is_paused' bestaat al, overslaan...", "info");
    } else {
        $pdo->exec("
            ALTER TABLE business_orders 
            ADD COLUMN is_paused TINYINT(1) DEFAULT 0 
            COMMENT 'Of de bestelling gepauzeerd is (terugkerende bestellingen)'
        ");
        output("✓ Kolom 'is_paused' toegevoegd aan business_orders.", "success");
    }
    
    try {
        $pdo->exec("CREATE INDEX idx_is_paused ON business_orders(is_paused)");
        output("✓ Index op 'is_paused' aangemaakt.", "success");
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate') !== false) {
            output("Index bestaat al, overslaan...", "info");
        } else {
            throw $e;
        }
    }
    
    output("");
    output("=== Migration voltooid ===", "success");
    
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
