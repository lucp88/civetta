<?php
require_once '../config.php';
requireLogin();

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS baker_recipes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        recipe_data JSON NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
    echo "Migration successful: baker_recipes table created.";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
