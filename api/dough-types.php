<?php
require_once 'cors.php';
require_once '../admin/config.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

function saveDtVersion(PDO $pdo, int $doughTypeId, string $name, array $recipeData, ?string $note): int {
    $stmt = $pdo->prepare("
        INSERT INTO dough_type_versions (dough_type_id, version_number, name, recipe_data, note)
        SELECT ?, COALESCE(MAX(version_number), 0) + 1, ?, ?, ?
        FROM dough_type_versions WHERE dough_type_id = ?
    ");
    $stmt->execute([$doughTypeId, $name, json_encode($recipeData), $note, $doughTypeId]);
    $versionId = (int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE dough_types SET current_version = (SELECT version_number FROM dough_type_versions WHERE id = ?) WHERE id = ?")
        ->execute([$versionId, $doughTypeId]);
    return $versionId;
}

switch ($method) {
    case 'GET':
        if (isset($_GET['id'])) {
            $stmt = $pdo->prepare("SELECT id, name, recipe_data, current_version FROM dough_types WHERE id = ?");
            $stmt->execute([$_GET['id']]);
            $row = $stmt->fetch();
            if ($row) {
                $row['recipe_data'] = $row['recipe_data'] ? json_decode($row['recipe_data'], true) : null;
                $vStmt = $pdo->prepare("SELECT id, version_number, name, note, created_at, recipe_data FROM dough_type_versions WHERE dough_type_id = ? ORDER BY version_number DESC");
                $vStmt->execute([$_GET['id']]);
                $versions = $vStmt->fetchAll();
                foreach ($versions as &$v) {
                    $v['recipe_data'] = $v['recipe_data'] ? json_decode($v['recipe_data'], true) : null;
                }
                unset($v);
                $row['versions'] = $versions;
                echo json_encode(['success' => true, 'dough_type' => $row]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Niet gevonden']);
            }
        } elseif (isset($_GET['version_id'])) {
            $stmt = $pdo->prepare("SELECT * FROM dough_type_versions WHERE id = ?");
            $stmt->execute([$_GET['version_id']]);
            $v = $stmt->fetch();
            if ($v) {
                $v['recipe_data'] = json_decode($v['recipe_data'], true);
                echo json_encode(['success' => true, 'version' => $v]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Versie niet gevonden']);
            }
        } else {
            $stmt = $pdo->query("SELECT id, name, recipe_data FROM dough_types ORDER BY sort_order ASC, name ASC");
            $rows = $stmt->fetchAll();
            foreach ($rows as &$row) {
                $row['recipe_data'] = $row['recipe_data'] ? json_decode($row['recipe_data'], true) : null;
            }
            echo json_encode(['success' => true, 'dough_types' => $rows]);
        }
        break;

    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        $name = trim($data['name'] ?? '');

        if (!$name) {
            echo json_encode(['success' => false, 'error' => 'Naam is verplicht']);
            exit;
        }

        $recipeData = isset($data['recipe_data']) ? json_encode($data['recipe_data']) : null;
        $stmt = $pdo->prepare("INSERT INTO dough_types (name, recipe_data) VALUES (?, ?)");
        $stmt->execute([$name, $recipeData]);
        $newId = (int)$pdo->lastInsertId();

        try {
            saveDtVersion($pdo, $newId, $name, $data['recipe_data'] ?? [], 'Initiële versie');
        } catch (PDOException $e) { /* version table may not exist yet */ }

        echo json_encode(['success' => true, 'id' => $newId]);
        break;

    case 'PUT':
        $data = json_decode(file_get_contents('php://input'), true);
        $id   = $data['id'] ?? null;
        $name = trim($data['name'] ?? '');

        if (!$id) {
            echo json_encode(['success' => false, 'error' => 'ID is verplicht']);
            exit;
        }

        $recipeData = $data['recipe_data'] ?? null;
        $pdo->prepare("UPDATE dough_types SET name = ?, recipe_data = ? WHERE id = ?")
            ->execute([$name, $recipeData !== null ? json_encode($recipeData) : null, $id]);

        try {
            $note = isset($data['version_note']) && $data['version_note'] !== '' ? $data['version_note'] : null;
            // Only create a new version if name or recipe_data actually changed
            $existingStmt = $pdo->prepare("SELECT name, recipe_data FROM dough_types WHERE id = ?");
            $existingStmt->execute([$id]);
            $existingRow = $existingStmt->fetch();
            $existingNormalized = $existingRow ? json_encode(json_decode($existingRow['recipe_data'], true)) : null;
            $newNormalized = $recipeData !== null ? json_encode(json_decode(json_encode($recipeData), true)) : null;
            $dataChanged = !$existingRow || $existingRow['name'] !== $name || $existingNormalized !== $newNormalized;
            if ($dataChanged || $note !== null) {
                saveDtVersion($pdo, (int)$id, $name, $recipeData ?? [], $note);
            }
        } catch (PDOException $e) { /* version table may not exist yet */ }

        // Cascade: re-merge all child baker_recipes with the updated base formula
        if ($recipeData !== null) {
            $recipeSpecificFields = ['doughWeight', 'numberOfBalls', 'weightFromOrder', 'mixinMode', 'mixins', 'toppings', 'method'];
            $children = $pdo->prepare("SELECT id, recipe_data FROM baker_recipes WHERE dough_type_id = ?");
            $children->execute([$id]);
            foreach ($children->fetchAll() as $child) {
                $existing = json_decode($child['recipe_data'], true) ?? [];
                $childFields = array_intersect_key($existing, array_flip($recipeSpecificFields));
                $merged = array_merge($recipeData, $childFields);
                $pdo->prepare("UPDATE baker_recipes SET recipe_data = ? WHERE id = ?")
                    ->execute([json_encode($merged), $child['id']]);
            }
        }

        echo json_encode(['success' => true]);
        break;

    case 'DELETE':
        $id = $_GET['id'] ?? null;

        if (!$id) {
            echo json_encode(['success' => false, 'error' => 'ID is verplicht']);
            exit;
        }

        $pdo->prepare("UPDATE baker_recipes SET dough_type_id = NULL WHERE dough_type_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM dough_types WHERE id = ?")->execute([$id]);

        echo json_encode(['success' => true]);
        break;

    case 'PATCH':
        $data = json_decode(file_get_contents('php://input'), true);
        if (($data['action'] ?? '') === 'reorder' && !empty($data['items'])) {
            $stmt = $pdo->prepare("UPDATE dough_types SET sort_order = ? WHERE id = ?");
            foreach ($data['items'] as $item) {
                $stmt->execute([$item['sort_order'], $item['id']]);
            }
            echo json_encode(['success' => true]);
        } elseif (($data['action'] ?? '') === 'update_version_number' && !empty($data['version_id'])) {
            $newNum = (int)($data['version_number'] ?? 0);
            if ($newNum < 1) { echo json_encode(['success' => false, 'error' => 'Ongeldig versienummer']); break; }
            $vStmt = $pdo->prepare("SELECT dough_type_id, version_number FROM dough_type_versions WHERE id = ?");
            $vStmt->execute([$data['version_id']]);
            $v = $vStmt->fetch();
            if (!$v) { echo json_encode(['success' => false, 'error' => 'Versie niet gevonden']); break; }
            $pdo->prepare("UPDATE dough_type_versions SET version_number = ? WHERE id = ?")->execute([$newNum, $data['version_id']]);
            // If this was the active version, update current_version on dough_types too
            $dtStmt = $pdo->prepare("SELECT current_version FROM dough_types WHERE id = ?");
            $dtStmt->execute([$v['dough_type_id']]);
            $dt = $dtStmt->fetch();
            if ($dt && (int)$dt['current_version'] === (int)$v['version_number']) {
                $pdo->prepare("UPDATE dough_types SET current_version = ? WHERE id = ?")->execute([$newNum, $v['dough_type_id']]);
            }
            echo json_encode(['success' => true]);
        } elseif (($data['action'] ?? '') === 'update_version_note' && !empty($data['version_id'])) {
            $note = isset($data['note']) && $data['note'] !== '' ? trim($data['note']) : null;
            $pdo->prepare("UPDATE dough_type_versions SET note = ? WHERE id = ?")->execute([$note, $data['version_id']]);
            echo json_encode(['success' => true]);
        } elseif (($data['action'] ?? '') === 'delete_version' && !empty($data['version_id'])) {
            $vStmt = $pdo->prepare("SELECT id, dough_type_id, version_number FROM dough_type_versions WHERE id = ?");
            $vStmt->execute([$data['version_id']]);
            $v = $vStmt->fetch();
            if (!$v) {
                echo json_encode(['success' => false, 'error' => 'Versie niet gevonden']);
                break;
            }
            // Prevent deleting the active version
            $dtStmt = $pdo->prepare("SELECT current_version FROM dough_types WHERE id = ?");
            $dtStmt->execute([$v['dough_type_id']]);
            $dt = $dtStmt->fetch();
            if ($dt && (int)$dt['current_version'] === (int)$v['version_number']) {
                echo json_encode(['success' => false, 'error' => 'De actieve versie kan niet worden verwijderd']);
                break;
            }
            $pdo->prepare("DELETE FROM dough_type_versions WHERE id = ?")->execute([$data['version_id']]);
            echo json_encode(['success' => true]);
        } elseif (($data['action'] ?? '') === 'restore_version' && !empty($data['version_id'])) {
            $vStmt = $pdo->prepare("SELECT * FROM dough_type_versions WHERE id = ?");
            $vStmt->execute([$data['version_id']]);
            $v = $vStmt->fetch();
            if (!$v) {
                echo json_encode(['success' => false, 'error' => 'Versie niet gevonden']);
                break;
            }
            $pdo->prepare("UPDATE dough_types SET name = ?, recipe_data = ? WHERE id = ?")
                ->execute([$v['name'], $v['recipe_data'], $v['dough_type_id']]);
            $oldVersion = $v['version_number'];
            saveDtVersion($pdo, (int)$v['dough_type_id'], $v['name'], json_decode($v['recipe_data'], true), "Hersteld van versie $oldVersion");
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Onbekende actie']);
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
}
