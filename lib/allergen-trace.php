<?php
/**
 * Allergen Trace Status Helper
 *
 * Tracks which allergens should be shown as trace allergens ("kan sporen bevatten van")
 * based on actual inventory stock, with a 60-day cooldown and mandatory allergen-critical
 * cleaning completion before an allergen can be cleared.
 */

/**
 * Update allergen_trace_status for a given ingredient after any stock change.
 * Called after: add_batch, consume, adjust_batch, purge_batch, consolidation, delete batch.
 */
function updateAllergenTraceStatus(PDO $pdo, int $ingredientId): void {
    // Get all allergen names for this ingredient from the junction table
    $stmt = $pdo->prepare("
        SELECT ia.allergeen_naam
        FROM ingredient_allergenen ia
        JOIN ingredients i ON i.id = ia.ingredient_id
        WHERE ia.ingredient_id = ? AND i.is_active = 1
    ");
    $stmt->execute([$ingredientId]);
    $allergeenNamen = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Not an allergen ingredient — nothing to do
    if (empty($allergeenNamen)) {
        return;
    }

    foreach ($allergeenNamen as $allergeenNaam) {
        // Check total remaining stock for ALL ingredients with this allergeen_naam
        $stockStmt = $pdo->prepare("
            SELECT COALESCE(SUM(b.quantity_remaining), 0) as total_stock
            FROM ingredients i
            JOIN ingredient_batches b ON i.id = b.ingredient_id
            JOIN ingredient_allergenen ia ON ia.ingredient_id = i.id
            WHERE ia.allergeen_naam = ?
              AND i.is_active = 1
              AND b.quantity_remaining > 0
        ");
        $stockStmt->execute([$allergeenNaam]);
        $totalStock = floatval($stockStmt->fetch()['total_stock']);

        if ($totalStock > 0) {
            $upd = $pdo->prepare("
                INSERT INTO allergen_trace_status (allergeen_naam, status, stock_depleted_at)
                VALUES (?, 'in_stock', NULL)
                ON DUPLICATE KEY UPDATE
                    status = 'in_stock',
                    stock_depleted_at = NULL,
                    manually_cleared_at = NULL,
                    cleared_by = NULL
            ");
            $upd->execute([$allergeenNaam]);
        } else {
            $upd = $pdo->prepare("
                INSERT INTO allergen_trace_status (allergeen_naam, status, stock_depleted_at)
                VALUES (?, 'depleted', NOW())
                ON DUPLICATE KEY UPDATE
                    status = IF(status = 'in_stock', 'depleted', status),
                    stock_depleted_at = IF(status = 'in_stock', NOW(), stock_depleted_at)
            ");
            $upd->execute([$allergeenNaam]);
        }
    }
}

/**
 * Auto-clear depleted allergens when both conditions are met:
 * 1. At least 60 days have passed since stock depletion
 * 2. All active allergen-critical cleaning items have been completed at least once since depletion
 */
function autoClearDepletedAllergens(PDO $pdo): void {
    // Get all depleted allergens
    $stmt = $pdo->query("
        SELECT allergeen_naam, stock_depleted_at
        FROM allergen_trace_status
        WHERE status = 'depleted'
          AND stock_depleted_at IS NOT NULL
    ");
    $depleted = $stmt->fetchAll();

    if (empty($depleted)) return;

    // Get count of active allergen-critical cleaning items
    $critStmt = $pdo->query("
        SELECT COUNT(*) as cnt
        FROM schoonmaak_items
        WHERE is_allergeen_kritisch = 1 AND actief = 1
    ");
    $criticalCount = intval($critStmt->fetch()['cnt']);

    foreach ($depleted as $row) {
        $depletedAt = $row['stock_depleted_at'];
        $daysSince = (time() - strtotime($depletedAt)) / 86400;

        // Condition 1: At least 60 days
        if ($daysSince < 60) continue;

        // Condition 2: All critical cleaning items completed since depletion
        if ($criticalCount > 0) {
            $cleanStmt = $pdo->prepare("
                SELECT COUNT(DISTINCT si.id) as completed_count
                FROM schoonmaak_items si
                JOIN schoonmaak_lijst_items sli ON sli.item_id = si.id
                WHERE si.is_allergeen_kritisch = 1
                  AND si.actief = 1
                  AND sli.afgevinkt = 1
                  AND sli.tijdstip_afgerond > ?
            ");
            $cleanStmt->execute([$depletedAt]);
            $completedCount = intval($cleanStmt->fetch()['completed_count']);

            if ($completedCount < $criticalCount) continue;
        }

        // Both conditions met → auto-clear
        $upd = $pdo->prepare("
            UPDATE allergen_trace_status
            SET status = 'cleared', manually_cleared_at = NOW(), cleared_by = 'auto'
            WHERE allergeen_naam = ?
        ");
        $upd->execute([$row['allergeen_naam']]);
    }
}
