<?php
require_once __DIR__ . '/../config.php';

echo "Migration 011: Push Subscriptions\n";

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS push_subscriptions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            endpoint TEXT NOT NULL,
            key_p256dh VARCHAR(255) NOT NULL,
            key_auth VARCHAR(255) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_endpoint (endpoint(500))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "OK: push_subscriptions tabel aangemaakt\n";
} catch (PDOException $e) {
    echo "FOUT: " . $e->getMessage() . "\n";
}
