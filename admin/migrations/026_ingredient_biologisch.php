<?php
require_once '../config.php';

try {
    $pdo->exec("ALTER TABLE ingredients ADD COLUMN is_biologisch TINYINT(1) NOT NULL DEFAULT 0 AFTER is_whole_grain");
    echo "Migration successful: Added is_biologisch to ingredients\n";
} catch (PDOException $e) {
    echo "Migration error: " . $e->getMessage() . "\n";
}
