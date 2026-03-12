<?php
/**
 * Migration: Add deegtypes table and products.deegtype_id column
 * 
 * Run: php admin/migrations/009_deegtypes.php
 */

require_once __DIR__ . '/../config.php';

echo "=== Migration: Deegtypes ===\n\n";

try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'deegtypes'");
    if ($stmt->fetch()) {
        echo "Tabel 'deegtypes' bestaat al, overslaan...\n";
    } else {
        $pdo->exec("
            CREATE TABLE deegtypes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                naam VARCHAR(255) NOT NULL,
                beschrijving TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        echo "Tabel 'deegtypes' aangemaakt.\n";
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM products LIKE 'deegtype_id'");
    if ($stmt->fetch()) {
        echo "Kolom 'deegtype_id' bestaat al, overslaan...\n";
    } else {
        $pdo->exec("ALTER TABLE products ADD COLUMN deegtype_id INT NULL, ADD INDEX idx_deegtype_id (deegtype_id)");
        echo "Kolom 'deegtype_id' toegevoegd aan products.\n";
    }

    echo "\n=== Migration voltooid ===\n";

} catch (PDOException $e) {
    echo "FOUT: " . $e->getMessage() . "\n";
    exit(1);
}
