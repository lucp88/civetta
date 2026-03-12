<?php
/**
 * Cronjob: Update Delivery Status
 * 
 * Update de delivery_status van alle lopende bestellingen.
 * 
 * Statussen:
 * - geplaatst: Bestelling is geplaatst
 * - wordt_bereid: Binnen deadline uren voor levering (standaard 48u)
 * - onderweg: Op de leverdag (vanaf 00:00)
 * - afgeleverd: Na 17:00 op leverdag
 * 
 * Draai dit script elk uur via cron:
 * 0 * * * * php /path/to/cron/update-delivery-status.php
 */

require_once __DIR__ . '/../admin/config.php';
require_once __DIR__ . '/../api/delivery-status.php';

function cronLog($message) {
    $logFile = __DIR__ . '/delivery-status.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
    echo "[$timestamp] $message\n";
}

cronLog("=== Delivery Status Update Gestart ===");

$updated = updateAllDeliveryStatuses($pdo);

cronLog("$updated bestellingen bijgewerkt");
cronLog("=== Delivery Status Update Voltooid ===");
