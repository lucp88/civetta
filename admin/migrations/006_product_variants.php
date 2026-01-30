<?php
/**
 * Migration: Add product_variants table for weight/price options
 * 
 * Run: php admin/migrations/006_product_variants.php
 */

require_once __DIR__ . '/../config.php';

echo "=== Migration: Product Variants ===\n\n";

try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'product_variants'");
    if ($stmt->fetch()) {
        echo "Tabel 'product_variants' bestaat al, overslaan...\n";
    } else {
        $pdo->exec("
            CREATE TABLE product_variants (
                id INT AUTO_INCREMENT PRIMARY KEY,
                product_id INT NOT NULL,
                gewicht INT NOT NULL COMMENT 'Gewicht in gram',
                prijs DECIMAL(10,2) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
                INDEX idx_product_id (product_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        echo "Tabel 'product_variants' aangemaakt.\n";
    }
    
    echo "\n=== Migration voltooid ===\n";
    
} catch (PDOException $e) {
    echo "FOUT: " . $e->getMessage() . "\n";
    exit(1);
}
