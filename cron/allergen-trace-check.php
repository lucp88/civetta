<?php
/**
 * Daily cron: auto-clear depleted allergens when 60-day + cleaning conditions are met.
 * Safety net — also runs via consume-inventory.php, but this ensures clearing
 * even when no stock changes happen.
 */
require_once __DIR__ . '/../admin/config.php';
require_once __DIR__ . '/../lib/allergen-trace.php';

autoClearDepletedAllergens($pdo);
echo "Allergen trace check voltooid.\n";
