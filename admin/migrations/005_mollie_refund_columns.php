<?php
/**
 * Migration: Add Mollie refund columns to business_orders
 * 
 * Run: php admin/migrations/005_mollie_refund_columns.php
 */

require_once __DIR__ . '/../config.php';

echo "=== Migration: Mollie Refund Columns ===\n\n";

try {
    $stmt = $pdo->query("SHOW COLUMNS FROM business_orders LIKE 'mollie_refund_id'");
    if ($stmt->fetch()) {
        echo "Kolom 'mollie_refund_id' bestaat al, overslaan...\n";
    } else {
        $pdo->exec("ALTER TABLE business_orders ADD COLUMN mollie_refund_id VARCHAR(50) NULL AFTER mollie_status_updated_at");
        echo "Kolom 'mollie_refund_id' toegevoegd.\n";
    }
    
    $stmt = $pdo->query("SHOW COLUMNS FROM business_orders LIKE 'mollie_refund_status'");
    if ($stmt->fetch()) {
        echo "Kolom 'mollie_refund_status' bestaat al, overslaan...\n";
    } else {
        $pdo->exec("ALTER TABLE business_orders ADD COLUMN mollie_refund_status VARCHAR(20) NULL AFTER mollie_refund_id");
        echo "Kolom 'mollie_refund_status' toegevoegd.\n";
    }
    
    echo "\n=== Migration voltooid ===\n";
    
} catch (PDOException $e) {
    echo "FOUT: " . $e->getMessage() . "\n";
    exit(1);
}
