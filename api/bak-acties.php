<?php
session_start();
require_once '../admin/config.php';
require_once '../lib/includes/inventory-functions.php';
require_once '../lib/allergen-trace.php';

if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Niet ingelogd']);
    exit;
}

header('Content-Type: application/json');
$method = $_SERVER['REQUEST_METHOD'];

function consumeBakactieInventory($pdo, $bakactieId, $lockedRecipeData, $totalWeightG, $orderIdsJson, $notesDataJson = null) {
    $movementId = null;
    if ($lockedRecipeData && $totalWeightG) {
        try {
            $pdo->prepare("INSERT INTO voorraad_movements (bakactie_id, movement_type) VALUES (?, 'deeg')")->execute([$bakactieId]);
            $movementId = (int)$pdo->lastInsertId();
        } catch (PDOException $e) {
            error_log('voorraad_movements insert mislukt (migratie 073 nog niet uitgevoerd?): ' . $e->getMessage());
        }

        $recipeData      = is_array($lockedRecipeData) ? $lockedRecipeData : json_decode($lockedRecipeData, true);
        $nd              = $notesDataJson ? (is_array($notesDataJson) ? $notesDataJson : json_decode($notesDataJson, true)) : [];
        $brandSelections = $nd['ingredient_brands'] ?? [];
        $ingredients     = $recipeData ? calculateBakactieIngredients($recipeData, $totalWeightG, $pdo, $brandSelections) : [];
        $consumedIds = [];
        foreach ($ingredients as $ing) {
            if ($ing['quantity'] > 0) {
                consumeIngredient($pdo, $ing['ingredient_id'], $ing['quantity'], null, $bakactieId, $movementId);
                $consumedIds[] = $ing['ingredient_id'];
            }
        }
        foreach (array_unique($consumedIds) as $ingId) {
            updateAllergenTraceStatus($pdo, $ingId);
        }
        if ($orderIdsJson) {
            $orderIds = is_array($orderIdsJson) ? $orderIdsJson : json_decode($orderIdsJson, true);
            if (is_array($orderIds) && !empty($orderIds)) {
                $ph = implode(',', array_fill(0, count($orderIds), '?'));
                $pdo->prepare("UPDATE business_orders SET inventory_consumed = 1 WHERE id IN ($ph)")->execute($orderIds);
            }
        }
    }
    $pdo->prepare("UPDATE bak_acties SET inventory_consumed = 1 WHERE id = ?")->execute([$bakactieId]);
    return $movementId;
}

switch ($method) {
    case 'GET':
        if (isset($_GET['preview_inventory'])) {
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) { echo json_encode(['success' => false, 'error' => 'ID vereist']); break; }
            $preview = previewBakactieInventory($pdo, $id);
            echo json_encode(['success' => true, 'preview' => $preview]);
            break;
        }
        if (isset($_GET['preview_sourdough'])) {
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) { echo json_encode(['success' => false, 'error' => 'ID vereist']); break; }
            $preview = previewSourdoughInventory($pdo, $id);
            echo json_encode(['success' => true, 'preview' => $preview]);
            break;
        }
        if (isset($_GET['id'])) {
            $stmt = $pdo->prepare("
                SELECT ba.*, br.name as recipe_current_name
                FROM bak_acties ba
                LEFT JOIN baker_recipes br ON ba.recipe_id = br.id
                WHERE ba.id = ?
            ");
            $stmt->execute([$_GET['id']]);
            $row = $stmt->fetch();
            if ($row) {
                $row['locked_recipe_data'] = $row['locked_recipe_data'] ? json_decode($row['locked_recipe_data'], true) : null;
                $row['order_ids'] = $row['order_ids'] ? json_decode($row['order_ids'], true) : [];
                echo json_encode(['success' => true, 'bak_actie' => $row]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Bakactie niet gevonden']);
            }
        } else {
            $where = [];
            $params = [];
            if (isset($_GET['status'])) {
                $where[] = 'ba.status = ?';
                $params[] = $_GET['status'];
            }
            if (isset($_GET['date'])) {
                $where[] = 'DATE(ba.datum) = ?';
                $params[] = $_GET['date'];
            }
            if (isset($_GET['dough_type_name'])) {
                $where[] = 'ba.dough_type_name = ?';
                $params[] = $_GET['dough_type_name'];
            }
            if (isset($_GET['recipe_version_id'])) {
                $where[] = 'ba.recipe_version_id = ?';
                $params[] = (int)$_GET['recipe_version_id'];
            }
            if (isset($_GET['dough_type_version_id'])) {
                $where[] = 'ba.dough_type_version_id = ?';
                $params[] = (int)$_GET['dough_type_version_id'];
            }
            $whereStr = $where ? 'WHERE ' . implode(' AND ', $where) : '';
            $stmt = $pdo->prepare("
                SELECT ba.id, ba.recipe_id, ba.recipe_version_id, ba.dough_type_version_id, ba.dough_type_name,
                       ba.locked_recipe_name, ba.order_ids, ba.total_qty, ba.total_weight_g,
                       ba.datum, ba.bakker, ba.notes, ba.notes_data, ba.status,
                       ba.start_time, ba.end_time, ba.water_temp, ba.dough_temp,
                       ba.created_at, ba.updated_at, ba.action_categories,
                       ba.inventory_consumed, ba.sourdough_consumed,
                       ba.sourdough_fed_at, ba.dough_mixed_at,
                       ba.bulk_rise_started_at, ba.bulk_rise_ended_at,
                       ba.final_proof_started_at, ba.final_proof_ended_at,
                       br.name as recipe_current_name
                FROM bak_acties ba
                LEFT JOIN baker_recipes br ON ba.recipe_id = br.id
                $whereStr
                ORDER BY ba.datum DESC
            ");
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
            foreach ($rows as &$row) {
                $row['order_ids'] = $row['order_ids'] ? json_decode($row['order_ids'], true) : [];
                $row['notes_data'] = $row['notes_data'] ? json_decode($row['notes_data'], true) : null;
            }
            unset($row);
            echo json_encode(['success' => true, 'bak_acties' => $rows]);
        }
        break;

    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        try {

        if (($data['_action'] ?? '') === 'update_planned_loaf_version') {
            $id       = (int)($data['id']        ?? 0);
            $recipeId = (string)($data['recipe_id'] ?? '');
            $versionId = (int)($data['version_id'] ?? 0);
            if (!$id || !$recipeId || !$versionId) { echo json_encode(['success' => false, 'error' => 'id, recipe_id en version_id vereist']); break; }
            $plvStmt = $pdo->prepare("SELECT planned_loaf_versions FROM bak_acties WHERE id = ?");
            $plvStmt->execute([$id]);
            $plvRow = $plvStmt->fetch();
            $planned = ($plvRow && $plvRow['planned_loaf_versions']) ? json_decode($plvRow['planned_loaf_versions'], true) : [];
            $planned[$recipeId] = [
                'version_id'                => $versionId,
                'dough_type_version_number' => isset($data['dough_type_version_number']) ? (int)$data['dough_type_version_number'] : null,
                'loaf_minor_version'        => isset($data['loaf_minor_version'])        ? (int)$data['loaf_minor_version']        : null,
            ];
            try {
                $pdo->prepare("UPDATE bak_acties SET planned_loaf_versions = ? WHERE id = ?")->execute([json_encode($planned), $id]);
                echo json_encode(['success' => true]);
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'error' => 'Voer migratie 070 uit: ' . $e->getMessage()]);
            }
            break;
        }

        if (($data['_action'] ?? '') === 'consume_sourdough') {
            $id = (int)($data['id'] ?? 0);
            if (!$id) { echo json_encode(['success' => false, 'error' => 'ID vereist']); break; }
            $baStmt = $pdo->prepare("SELECT sourdough_consumed, locked_recipe_data, total_weight_g, notes_data, status FROM bak_acties WHERE id = ?");
            $baStmt->execute([$id]);
            $ba = $baStmt->fetch();
            if (!$ba) { echo json_encode(['success' => false, 'error' => 'Bakactie niet gevonden']); break; }
            if ($ba['sourdough_consumed']) { echo json_encode(['success' => false, 'error' => 'Desem al afgeschreven']); break; }
            // ingredient_id + quantity_g come from the frontend (already computed + displayed there)
            $ingId = (int)($data['ingredient_id'] ?? 0);
            $qty   = (float)($data['quantity_g'] ?? 0);
            // Fallback: server-side calculation (FIFO, no brand selected)
            if (!$ingId || $qty <= 0) {
                $nd = $ba['notes_data'] ? json_decode($ba['notes_data'], true) : [];
                $brandSelections = $nd['ingredient_brands'] ?? [];
                $rd = $ba['locked_recipe_data'] ? json_decode($ba['locked_recipe_data'], true) : null;
                $ings = $rd ? calculateSourdoughIngredients($rd, (float)$ba['total_weight_g'], $pdo, $brandSelections) : [];
                if (empty($ings)) { echo json_encode(['success' => false, 'error' => 'Geen desem in recept']); break; }
                $ingId = $ings[0]['ingredient_id'];
                $qty   = $ings[0]['quantity'];
            }
            $sdMovementId = null;
            try {
                $pdo->prepare("INSERT INTO voorraad_movements (bakactie_id, movement_type) VALUES (?, 'pre-ferment')")->execute([$id]);
                $sdMovementId = (int)$pdo->lastInsertId();
            } catch (PDOException $e) {
                error_log('voorraad_movements insert mislukt (migratie 073 nog niet uitgevoerd?): ' . $e->getMessage());
            }
            consumeIngredient($pdo, $ingId, $qty, null, $id, $sdMovementId);
            updateAllergenTraceStatus($pdo, $ingId);
            $statusSet = ($ba['status'] ?? '') === 'gepland' ? ", status = 'bezig'" : '';
            $pdo->prepare("UPDATE bak_acties SET sourdough_consumed = 1{$statusSet} WHERE id = ?")->execute([$id]);
            echo json_encode(['success' => true]);
            break;
        }

        if (($data['_action'] ?? '') === 'consume_inventory') {
            $id = (int)($data['id'] ?? 0);
            if (!$id) { echo json_encode(['success' => false, 'error' => 'ID vereist']); break; }
            try {
                $baStmt = $pdo->prepare("SELECT locked_recipe_data, total_weight_g, order_ids, notes_data, inventory_consumed, status, planned_loaf_versions FROM bak_acties WHERE id = ?");
                $baStmt->execute([$id]);
                $ba = $baStmt->fetch();
            } catch (PDOException $e) {
                // planned_loaf_versions missing → migration 070 not run; retry without it
                $baStmt = $pdo->prepare("SELECT locked_recipe_data, total_weight_g, order_ids, notes_data, inventory_consumed, status FROM bak_acties WHERE id = ?");
                $baStmt->execute([$id]);
                $ba = $baStmt->fetch();
                if ($ba) $ba['planned_loaf_versions'] = null;
            }
            if (!$ba) { echo json_encode(['success' => false, 'error' => 'Bakactie niet gevonden']); break; }
            if ($ba['inventory_consumed']) { echo json_encode(['success' => false, 'error' => 'Voorraad al afgeschreven']); break; }

            try {
                // Lock in the planned (or current) version of every loaf recipe linked to this bakactie's orders
                $lockedLoafVersions = null;
                $plannedLV = ($ba['planned_loaf_versions'] ?? null)
                    ? (is_array($ba['planned_loaf_versions']) ? $ba['planned_loaf_versions'] : json_decode($ba['planned_loaf_versions'], true))
                    : [];
                $orderIds = $ba['order_ids'] ? (is_array($ba['order_ids']) ? $ba['order_ids'] : json_decode($ba['order_ids'], true)) : [];
                if (!empty($orderIds)) {
                    $ph = implode(',', array_fill(0, count($orderIds), '?'));
                    $lvStmt = $pdo->prepare("
                        SELECT DISTINCT br.id as recipe_id, br.current_version as version_number,
                               brv.id as version_id,
                               brv.dough_type_version_number,
                               brv.loaf_minor_version
                        FROM business_order_items boi
                        LEFT JOIN product_variants pv ON pv.id = boi.variant_id
                        LEFT JOIN baker_recipes br ON pv.recipe_id = br.id
                        LEFT JOIN baker_recipe_versions brv ON brv.recipe_id = br.id AND brv.version_number = br.current_version
                        WHERE boi.order_id IN ($ph) AND br.id IS NOT NULL
                    ");
                    $lvStmt->execute($orderIds);
                    $lvRows = $lvStmt->fetchAll();
                    if ($lvRows) {
                        $lockedLoafVersions = [];
                        foreach ($lvRows as $lvRow) {
                            $rid = (string)$lvRow['recipe_id'];
                            if (!empty($plannedLV[$rid])) {
                                $plv = $plannedLV[$rid];
                                $vdStmt = $pdo->prepare("SELECT version_number, dough_type_version_number, loaf_minor_version FROM baker_recipe_versions WHERE id = ?");
                                $vdStmt->execute([$plv['version_id']]);
                                $vd = $vdStmt->fetch();
                                $lockedLoafVersions[$rid] = [
                                    'version_id'                => (int)$plv['version_id'],
                                    'version_number'            => $vd ? (int)$vd['version_number'] : (int)$lvRow['version_number'],
                                    'dough_type_version_number' => $vd && $vd['dough_type_version_number'] !== null ? (int)$vd['dough_type_version_number'] : null,
                                    'loaf_minor_version'        => $vd && $vd['loaf_minor_version'] !== null ? (int)$vd['loaf_minor_version'] : null,
                                ];
                            } else {
                                $lockedLoafVersions[$rid] = [
                                    'version_id'                => (int)$lvRow['version_id'],
                                    'version_number'            => (int)$lvRow['version_number'],
                                    'dough_type_version_number' => $lvRow['dough_type_version_number'] !== null ? (int)$lvRow['dough_type_version_number'] : null,
                                    'loaf_minor_version'        => $lvRow['loaf_minor_version'] !== null ? (int)$lvRow['loaf_minor_version'] : null,
                                ];
                            }
                        }
                    }
                }
                if ($lockedLoafVersions !== null) {
                    try {
                        $pdo->prepare("UPDATE bak_acties SET locked_loaf_versions = ? WHERE id = ?")
                            ->execute([json_encode($lockedLoafVersions), $id]);
                    } catch (PDOException $e) { /* migration 068 */ }
                }
            } catch (PDOException $e) {
                error_log('consume_inventory loaf version locking mislukt: ' . $e->getMessage());
            }

            $movementId = consumeBakactieInventory($pdo, $id, $ba['locked_recipe_data'], $ba['total_weight_g'], $ba['order_ids'], $ba['notes_data']);
            if (($ba['status'] ?? '') === 'gepland') {
                $pdo->prepare("UPDATE bak_acties SET status = 'bezig' WHERE id = ?")->execute([$id]);
            }
            foreach ($data['extras'] ?? [] as $extra) {
                $ingId = (int)($extra['ingredient_id'] ?? 0);
                $qty   = (float)($extra['quantity_g'] ?? 0);
                if ($ingId && $qty > 0) {
                    consumeIngredient($pdo, $ingId, $qty, null, $id, $movementId);
                    updateAllergenTraceStatus($pdo, $ingId);
                }
            }
            echo json_encode(['success' => true]);
            break;
        }

        if (($data['_action'] ?? '') === 'reset_sourdough_consumed') {
            $id = (int)($data['id'] ?? 0);
            if (!$id) { echo json_encode(['success' => false, 'error' => 'ID vereist']); break; }
            $pdo->prepare("UPDATE bak_acties SET sourdough_consumed = 0 WHERE id = ?")->execute([$id]);
            echo json_encode(['success' => true]);
            break;
        }

        if (($data['_action'] ?? '') === 'revert_inventory') {
            $id = (int)($data['id'] ?? 0);
            if (!$id) { echo json_encode(['success' => false, 'error' => 'ID vereist']); break; }
            // Delete all inventory_consumption rows for this bakactie and restore batch quantities
            $consStmt = $pdo->prepare("SELECT id, batch_id, quantity_consumed FROM inventory_consumption WHERE bakactie_id = ?");
            $consStmt->execute([$id]);
            $consumptions = $consStmt->fetchAll();
            foreach ($consumptions as $c) {
                $pdo->prepare("UPDATE ingredient_batches SET quantity_remaining = quantity_remaining + ?, is_open = 1 WHERE id = ?")
                    ->execute([$c['quantity_consumed'], $c['batch_id']]);
            }
            $pdo->prepare("DELETE FROM inventory_consumption WHERE bakactie_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM voorraad_movements WHERE bakactie_id = ?")->execute([$id]);
            try {
                $pdo->prepare("UPDATE bak_acties SET inventory_consumed = 0, sourdough_consumed = 0, locked_loaf_versions = NULL WHERE id = ?")->execute([$id]);
            } catch (PDOException $e) {
                $pdo->prepare("UPDATE bak_acties SET inventory_consumed = 0, sourdough_consumed = 0 WHERE id = ?")->execute([$id]);
            }
            echo json_encode(['success' => true]);
            break;
        }

        if (empty($data['datum'])) {
            echo json_encode(['success' => false, 'error' => 'datum is verplicht']);
            break;
        }
        if (empty($data['dough_type_name']) && empty($data['locked_recipe_name'])) {
            echo json_encode(['success' => false, 'error' => 'dough_type_name of locked_recipe_name is verplicht']);
            break;
        }
        $recipeId      = !empty($data['recipe_id'])         ? (int)$data['recipe_id']         : null;
        $versionId     = !empty($data['recipe_version_id']) ? (int)$data['recipe_version_id'] : null;
        $doughTypeName = isset($data['dough_type_name'])    ? trim($data['dough_type_name'])   : null;
        $bakker        = isset($data['bakker'])  ? trim($data['bakker'])  : null;
        $notes         = isset($data['notes'])   ? trim($data['notes'])   : null;
        $status        = in_array($data['status'] ?? '', ['gepland','bezig','voltooid']) ? $data['status'] : 'gepland';
        $skipInventory      = !empty($data['skip_inventory']) ? 1 : 0;
        $actionCategories   = isset($data['action_categories']) ? trim($data['action_categories']) : '';
        $orderIds      = isset($data['order_ids']) && is_array($data['order_ids']) ? $data['order_ids'] : null;
        $totalQty      = isset($data['total_qty'])      ? (int)$data['total_qty']      : null;
        $totalWeightG  = isset($data['total_weight_g']) ? (int)$data['total_weight_g'] : null;
        $startTime     = isset($data['start_time']) && $data['start_time'] !== '' ? $data['start_time'] : null;
        $endTime       = isset($data['end_time'])   && $data['end_time']   !== '' ? $data['end_time']   : null;
        $waterTemp       = isset($data['water_temp'])     && $data['water_temp']     !== '' ? (float)$data['water_temp']     : null;
        $doughTemp       = isset($data['dough_temp'])     && $data['dough_temp']     !== '' ? (float)$data['dough_temp']     : null;
        $flourTemp       = isset($data['flour_temp'])     && $data['flour_temp']     !== '' ? (float)$data['flour_temp']     : null;
        $ambientTemp     = isset($data['ambient_temp'])   && $data['ambient_temp']   !== '' ? (float)$data['ambient_temp']   : null;
        $bakkerijTemp    = isset($data['bakkerij_temp'])  && $data['bakkerij_temp']  !== '' ? (float)$data['bakkerij_temp']  : null;
        $ovenTemp        = isset($data['oven_temp'])    && $data['oven_temp']    !== '' ? (float)$data['oven_temp']    : null;
        $bakeTimeMinutes       = isset($data['bake_time_minutes']) && $data['bake_time_minutes'] !== '' ? (int)$data['bake_time_minutes'] : null;
        $notesData             = isset($data['notes_data']) ? (is_array($data['notes_data']) ? json_encode($data['notes_data']) : $data['notes_data']) : null;
        $sourdoughFedAt        = isset($data['sourdough_fed_at'])       && $data['sourdough_fed_at']       !== '' ? $data['sourdough_fed_at']       : null;
        $doughMixedAt          = isset($data['dough_mixed_at'])         && $data['dough_mixed_at']         !== '' ? $data['dough_mixed_at']         : null;
        $bulkRiseStartedAt     = isset($data['bulk_rise_started_at'])   && $data['bulk_rise_started_at']   !== '' ? $data['bulk_rise_started_at']   : null;
        $bulkRiseEndedAt       = isset($data['bulk_rise_ended_at'])     && $data['bulk_rise_ended_at']     !== '' ? $data['bulk_rise_ended_at']     : null;
        $finalProofStartedAt   = isset($data['final_proof_started_at']) && $data['final_proof_started_at'] !== '' ? $data['final_proof_started_at'] : null;
        $finalProofEndedAt     = isset($data['final_proof_ended_at'])   && $data['final_proof_ended_at']   !== '' ? $data['final_proof_ended_at']   : null;

        $stmt = $pdo->prepare("
            INSERT INTO bak_acties
                (recipe_id, recipe_version_id, dough_type_name, locked_recipe_name, locked_recipe_data,
                 order_ids, total_qty, total_weight_g, datum, bakker, notes, status, skip_inventory, action_categories,
                 start_time, end_time, water_temp, dough_temp, flour_temp, ambient_temp, bakkerij_temp, oven_temp, bake_time_minutes, notes_data,
                 sourdough_fed_at, dough_mixed_at, bulk_rise_started_at, bulk_rise_ended_at, final_proof_started_at, final_proof_ended_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $lockedRecipeDataRaw = isset($data['locked_recipe_data']) ? json_encode($data['locked_recipe_data']) : null;
        $stmt->execute([
            $recipeId, $versionId,
            $doughTypeName,
            $data['locked_recipe_name'] ?? $doughTypeName,
            $lockedRecipeDataRaw,
            $orderIds ? json_encode($orderIds) : null,
            $totalQty, $totalWeightG,
            $data['datum'],
            $bakker ?: null,
            $notes  ?: null,
            $status, $skipInventory, $actionCategories,
            $startTime, $endTime, $waterTemp, $doughTemp,
            $flourTemp, $ambientTemp, $bakkerijTemp, $ovenTemp, $bakeTimeMinutes, $notesData,
            $sourdoughFedAt, $doughMixedAt, $bulkRiseStartedAt, $bulkRiseEndedAt, $finalProofStartedAt, $finalProofEndedAt,
        ]);
        $newId = $pdo->lastInsertId();
        echo json_encode(['success' => true, 'id' => $newId]);
        } catch (\Throwable $e) {
            error_log('bak-acties POST fout: ' . $e->getMessage());
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    case 'PUT':
        $data = json_decode(file_get_contents('php://input'), true);
        if (empty($data['id'])) {
            echo json_encode(['success' => false, 'error' => 'ID is verplicht']);
            break;
        }
        $existingStmt = $pdo->prepare("SELECT start_time, inventory_consumed, skip_inventory, locked_recipe_data, total_weight_g, order_ids, status FROM bak_acties WHERE id = ?");
        $existingStmt->execute([$data['id']]);
        $existingRow = $existingStmt->fetch();
        $fields = [];
        $params = [];
        if (isset($data['datum']))              { $fields[] = 'datum = ?';               $params[] = $data['datum']; }
        if (isset($data['bakker']))             { $fields[] = 'bakker = ?';              $params[] = $data['bakker'] ?: null; }
        if (isset($data['notes']))              { $fields[] = 'notes = ?';               $params[] = $data['notes'] ?: null; }
        if (isset($data['status']) && in_array($data['status'], ['gepland','bezig','voltooid'])) {
            $fields[] = 'status = ?'; $params[] = $data['status'];
        }
        if (array_key_exists('start_time', $data)) { $fields[] = 'start_time = ?'; $params[] = ($data['start_time'] !== '') ? $data['start_time'] : null; }
        if (array_key_exists('end_time', $data))   { $fields[] = 'end_time = ?';   $params[] = ($data['end_time']   !== '') ? $data['end_time']   : null; }
        if (array_key_exists('water_temp', $data))        { $fields[] = 'water_temp = ?';        $params[] = ($data['water_temp']        !== '') ? (float)$data['water_temp']        : null; }
        if (array_key_exists('dough_temp', $data))        { $fields[] = 'dough_temp = ?';        $params[] = ($data['dough_temp']        !== '') ? (float)$data['dough_temp']        : null; }
        if (array_key_exists('flour_temp', $data))        { $fields[] = 'flour_temp = ?';        $params[] = ($data['flour_temp']        !== '') ? (float)$data['flour_temp']        : null; }
        if (array_key_exists('ambient_temp', $data))      { $fields[] = 'ambient_temp = ?';      $params[] = ($data['ambient_temp']      !== '') ? (float)$data['ambient_temp']      : null; }
        if (array_key_exists('bakkerij_temp', $data))    { $fields[] = 'bakkerij_temp = ?';    $params[] = ($data['bakkerij_temp']    !== '') ? (float)$data['bakkerij_temp']    : null; }
        if (array_key_exists('oven_temp', $data))         { $fields[] = 'oven_temp = ?';         $params[] = ($data['oven_temp']         !== '') ? (float)$data['oven_temp']         : null; }
        if (array_key_exists('bake_time_minutes', $data)) { $fields[] = 'bake_time_minutes = ?'; $params[] = ($data['bake_time_minutes'] !== '') ? (int)$data['bake_time_minutes']   : null; }
        if (array_key_exists('notes_data', $data))        { $fields[] = 'notes_data = ?';        $params[] = is_array($data['notes_data']) ? json_encode($data['notes_data']) : $data['notes_data']; }
        if (isset($data['locked_recipe_data'])) { $fields[] = 'locked_recipe_data = ?'; $params[] = json_encode($data['locked_recipe_data']); }
        if (isset($data['locked_recipe_name'])) { $fields[] = 'locked_recipe_name = ?'; $params[] = $data['locked_recipe_name']; }
        if (isset($data['recipe_version_id']))       { $fields[] = 'recipe_version_id = ?';       $params[] = (int)$data['recipe_version_id'] ?: null; }
        if (isset($data['dough_type_version_id']))  { $fields[] = 'dough_type_version_id = ?';  $params[] = (int)$data['dough_type_version_id'] ?: null; }
        if (isset($data['total_weight_g']))     { $fields[] = 'total_weight_g = ?';     $params[] = (int)$data['total_weight_g']; }
        if (isset($data['skip_inventory']))       { $fields[] = 'skip_inventory = ?';      $params[] = !empty($data['skip_inventory']) ? 1 : 0; }
        if (isset($data['action_categories']))    { $fields[] = 'action_categories = ?';  $params[] = trim($data['action_categories']); }
        if (array_key_exists('sourdough_fed_at',       $data)) { $fields[] = 'sourdough_fed_at = ?';       $params[] = ($data['sourdough_fed_at']       !== '') ? $data['sourdough_fed_at']       : null; }
        if (array_key_exists('dough_mixed_at',         $data)) { $fields[] = 'dough_mixed_at = ?';         $params[] = ($data['dough_mixed_at']         !== '') ? $data['dough_mixed_at']         : null; }
        if (array_key_exists('bulk_rise_started_at',   $data)) { $fields[] = 'bulk_rise_started_at = ?';   $params[] = ($data['bulk_rise_started_at']   !== '') ? $data['bulk_rise_started_at']   : null; }
        if (array_key_exists('bulk_rise_ended_at',     $data)) { $fields[] = 'bulk_rise_ended_at = ?';     $params[] = ($data['bulk_rise_ended_at']     !== '') ? $data['bulk_rise_ended_at']     : null; }
        if (array_key_exists('final_proof_started_at', $data)) { $fields[] = 'final_proof_started_at = ?'; $params[] = ($data['final_proof_started_at'] !== '') ? $data['final_proof_started_at'] : null; }
        if (array_key_exists('final_proof_ended_at',   $data)) { $fields[] = 'final_proof_ended_at = ?';   $params[] = ($data['final_proof_ended_at']   !== '') ? $data['final_proof_ended_at']   : null; }
        // Auto gepland → bezig when any timing is set and status wasn't explicitly changed
        if (!isset($data['status']) && ($existingRow['status'] ?? '') === 'gepland') {
            foreach (['start_time','sourdough_fed_at','dough_mixed_at','bulk_rise_started_at','final_proof_started_at'] as $_tf) {
                if (array_key_exists($_tf, $data) && $data[$_tf] !== '') {
                    $fields[] = 'status = ?';
                    $params[] = 'bezig';
                    break;
                }
            }
        }
        if (empty($fields)) {
            echo json_encode(['success' => false, 'error' => 'Geen velden om bij te werken']);
            break;
        }
        $params[] = $data['id'];
        $pdo->prepare("UPDATE bak_acties SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);
        echo json_encode(['success' => true]);
        break;

    case 'DELETE':
        $id = $_GET['id'] ?? null;
        if (!$id) {
            echo json_encode(['success' => false, 'error' => 'ID is verplicht']);
            break;
        }
        $pdo->prepare("DELETE FROM bak_acties WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true]);
        break;
}
