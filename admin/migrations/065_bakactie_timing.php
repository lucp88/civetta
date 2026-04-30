<?php
require_once '../config.php';
requireLogin();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Migration 065</title>
    <style>
        body { font-family: -apple-system, sans-serif; padding: 2rem; background: #f5f2ed; }
        .card { background: white; padding: 2rem; border-radius: 12px; max-width: 600px; margin: 0 auto; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        h1 { color: #5c3d1e; margin-bottom: 1rem; }
        .success { color: #2e7d32; }
        .info    { color: #666; }
        .error   { color: #c62828; }
        pre { background: #f5f5f5; padding: 1rem; border-radius: 8px; overflow-x: auto; margin: 1rem 0; }
        a { color: #8b5a2b; }
        .btn { display: inline-block; padding: 0.75rem 1.5rem; background: #8b5a2b; color: white; text-decoration: none; border-radius: 8px; margin-top: 1rem; }
        .btn:hover { background: #5c3d1e; }
    </style>
</head>
<body>
<div class="card">
<h1>Migration 065: Bakactie rijstijden</h1>
<pre><?php
$cols = [
    'sourdough_fed_at'       => "ALTER TABLE bak_acties ADD COLUMN sourdough_fed_at DATETIME NULL AFTER bake_time_minutes",
    'bulk_rise_started_at'   => "ALTER TABLE bak_acties ADD COLUMN bulk_rise_started_at DATETIME NULL AFTER sourdough_fed_at",
    'bulk_rise_ended_at'     => "ALTER TABLE bak_acties ADD COLUMN bulk_rise_ended_at DATETIME NULL AFTER bulk_rise_started_at",
    'final_proof_started_at' => "ALTER TABLE bak_acties ADD COLUMN final_proof_started_at DATETIME NULL AFTER bulk_rise_ended_at",
    'final_proof_ended_at'   => "ALTER TABLE bak_acties ADD COLUMN final_proof_ended_at DATETIME NULL AFTER final_proof_started_at",
];

foreach ($cols as $col => $sql) {
    try {
        $pdo->exec($sql);
        echo "<span class='success'>✓ Kolom $col toegevoegd</span>\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            echo "<span class='info'>- $col bestaat al</span>\n";
        } else {
            echo "<span class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</span>\n";
        }
    }
}

echo "\n<span class='success'>✓ Migration 065 voltooid!</span>\n";
?></pre>

<p><strong>Wijzigingen:</strong></p>
<ul>
    <li>Kolom <code>sourdough_fed_at</code> — tijdstip desem gevoed</li>
    <li>Kolom <code>bulk_rise_started_at</code> — start bulk rijs</li>
    <li>Kolom <code>bulk_rise_ended_at</code> — einde bulk rijs</li>
    <li>Kolom <code>final_proof_started_at</code> — start eindgisting</li>
    <li>Kolom <code>final_proof_ended_at</code> — einde eindgisting</li>
</ul>

<a href="../bakker/bakker-dashboard.php" class="btn">← Naar Dashboard</a>
</div>
</body>
</html>
