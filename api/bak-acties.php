<?php
session_start();
require_once '../admin/config.php';

if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Niet ingelogd']);
    exit;
}

header('Content-Type: application/json');
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
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
            $whereStr = $where ? 'WHERE ' . implode(' AND ', $where) : '';
            $stmt = $pdo->prepare("
                SELECT ba.id, ba.recipe_id, ba.recipe_version_id, ba.dough_type_name,
                       ba.locked_recipe_name, ba.order_ids, ba.total_qty, ba.total_weight_g,
                       ba.datum, ba.bakker, ba.notes, ba.status,
                       ba.start_time, ba.end_time, ba.water_temp, ba.dough_temp,
                       ba.created_at, ba.updated_at,
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
            }
            unset($row);
            echo json_encode(['success' => true, 'bak_acties' => $rows]);
        }
        break;

    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
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
        $orderIds      = isset($data['order_ids']) && is_array($data['order_ids']) ? $data['order_ids'] : null;
        $totalQty      = isset($data['total_qty'])      ? (int)$data['total_qty']      : null;
        $totalWeightG  = isset($data['total_weight_g']) ? (int)$data['total_weight_g'] : null;
        $startTime     = isset($data['start_time']) && $data['start_time'] !== '' ? $data['start_time'] : null;
        $endTime       = isset($data['end_time'])   && $data['end_time']   !== '' ? $data['end_time']   : null;
        $waterTemp       = isset($data['water_temp'])   && $data['water_temp']   !== '' ? (float)$data['water_temp']   : null;
        $doughTemp       = isset($data['dough_temp'])   && $data['dough_temp']   !== '' ? (float)$data['dough_temp']   : null;
        $flourTemp       = isset($data['flour_temp'])   && $data['flour_temp']   !== '' ? (float)$data['flour_temp']   : null;
        $ambientTemp     = isset($data['ambient_temp']) && $data['ambient_temp'] !== '' ? (float)$data['ambient_temp'] : null;
        $ovenTemp        = isset($data['oven_temp'])    && $data['oven_temp']    !== '' ? (float)$data['oven_temp']    : null;
        $bakeTimeMinutes = isset($data['bake_time_minutes']) && $data['bake_time_minutes'] !== '' ? (int)$data['bake_time_minutes'] : null;
        $notesData       = isset($data['notes_data']) ? (is_array($data['notes_data']) ? json_encode($data['notes_data']) : $data['notes_data']) : null;

        $stmt = $pdo->prepare("
            INSERT INTO bak_acties
                (recipe_id, recipe_version_id, dough_type_name, locked_recipe_name, locked_recipe_data,
                 order_ids, total_qty, total_weight_g, datum, bakker, notes, status,
                 start_time, end_time, water_temp, dough_temp, flour_temp, ambient_temp, oven_temp, bake_time_minutes, notes_data)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $recipeId, $versionId,
            $doughTypeName,
            $data['locked_recipe_name'] ?? $doughTypeName,
            isset($data['locked_recipe_data']) ? json_encode($data['locked_recipe_data']) : null,
            $orderIds ? json_encode($orderIds) : null,
            $totalQty, $totalWeightG,
            $data['datum'],
            $bakker ?: null,
            $notes  ?: null,
            $status,
            $startTime, $endTime, $waterTemp, $doughTemp,
            $flourTemp, $ambientTemp, $ovenTemp, $bakeTimeMinutes, $notesData,
        ]);
        echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
        break;

    case 'PUT':
        $data = json_decode(file_get_contents('php://input'), true);
        if (empty($data['id'])) {
            echo json_encode(['success' => false, 'error' => 'ID is verplicht']);
            break;
        }
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
        if (array_key_exists('oven_temp', $data))         { $fields[] = 'oven_temp = ?';         $params[] = ($data['oven_temp']         !== '') ? (float)$data['oven_temp']         : null; }
        if (array_key_exists('bake_time_minutes', $data)) { $fields[] = 'bake_time_minutes = ?'; $params[] = ($data['bake_time_minutes'] !== '') ? (int)$data['bake_time_minutes']   : null; }
        if (array_key_exists('notes_data', $data))        { $fields[] = 'notes_data = ?';        $params[] = is_array($data['notes_data']) ? json_encode($data['notes_data']) : $data['notes_data']; }
        if (isset($data['locked_recipe_data'])) { $fields[] = 'locked_recipe_data = ?'; $params[] = json_encode($data['locked_recipe_data']); }
        if (isset($data['locked_recipe_name'])) { $fields[] = 'locked_recipe_name = ?'; $params[] = $data['locked_recipe_name']; }
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
