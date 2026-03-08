<?php
/**
 * Civetta — Migration 036: Honeypot logs
 *
 * Logs bot submissions caught by honeypot fields.
 */

if (empty($GLOBALS['_migration_runner'])) {
    require_once __DIR__ . '/../config.php';
    requireLogin();
}

$steps = [
    [
        'desc' => 'Tabel honeypot_logs aanmaken',
        'check' => "SELECT COUNT(*) as c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'honeypot_logs'",
        'sql' => "CREATE TABLE honeypot_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            pagina VARCHAR(50) NOT NULL,
            ip_adres VARCHAR(45),
            user_agent TEXT,
            ingevulde_waarde VARCHAR(255),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_created_at (created_at),
            INDEX idx_pagina (pagina)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ]
];
