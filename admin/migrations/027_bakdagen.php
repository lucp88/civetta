<?php
require_once '../config.php';

try {
    // Create bakdagen_extra table for impromptu baking days
    $pdo->exec("CREATE TABLE IF NOT EXISTS bakdagen_extra (
        id INT AUTO_INCREMENT PRIMARY KEY,
        datum DATE NOT NULL UNIQUE,
        notitie VARCHAR(255) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    echo "Created bakdagen_extra table\n";

    // Insert default settings
    $pdo->exec("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('bakdagen_patroon', '')");
    $pdo->exec("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('bakdagen_voorbereiding_dagen', '3')");
    echo "Inserted default bakdagen settings\n";

    echo "Migration 027_bakdagen successful\n";
} catch (PDOException $e) {
    echo "Migration error: " . $e->getMessage() . "\n";
}
