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

function upsertDoughType(PDO $pdo, ?int $existingDoughTypeId, string $name, array $recipeData): int {
    $recipeSpecificFields = ['doughWeight', 'numberOfBalls', 'weightFromOrder', 'mixinMode', 'mixins', 'toppings'];
    $baseData = array_diff_key($recipeData, array_flip($recipeSpecificFields));

    if ($existingDoughTypeId) {
        $pdo->prepare("UPDATE dough_types SET name = ?, recipe_data = ? WHERE id = ?")
            ->execute([$name, json_encode($baseData), $existingDoughTypeId]);
        return $existingDoughTypeId;
    } else {
        $pdo->prepare("INSERT INTO dough_types (name, recipe_data) VALUES (?, ?)")
            ->execute([$name, json_encode($baseData)]);
        return (int)$pdo->lastInsertId();
    }
}

function saveVersion(PDO $pdo, int $recipeId, string $name, array $recipeData, ?string $note, ?int $forceDoughMajor = null): int {
    // Resolve the dough type major version for this recipe
    $doughMajor = $forceDoughMajor;
    if ($doughMajor === null) {
        $dtRow = $pdo->prepare("
            SELECT dt.current_version
            FROM baker_recipes br
            LEFT JOIN dough_types dt ON dt.id = br.dough_type_id
            WHERE br.id = ?
        ");
        $dtRow->execute([$recipeId]);
        $r = $dtRow->fetch();
        $doughMajor = ($r && $r['current_version'] !== null) ? (int)$r['current_version'] : null;
    }

    // Next minor within this major (or null for recipes without a dough type)
    $loafMinor = null;
    if ($doughMajor !== null) {
        $minorStmt = $pdo->prepare("
            SELECT COALESCE(MAX(loaf_minor_version), 0) + 1
            FROM baker_recipe_versions
            WHERE recipe_id = ? AND dough_type_version_number = ?
        ");
        $minorStmt->execute([$recipeId, $doughMajor]);
        $loafMinor = (int)$minorStmt->fetchColumn();
    }

    $stmt = $pdo->prepare("
        INSERT INTO baker_recipe_versions (recipe_id, version_number, dough_type_version_number, loaf_minor_version, name, recipe_data, note)
        SELECT ?, COALESCE(MAX(version_number), 0) + 1, ?, ?, ?, ?, ?
        FROM baker_recipe_versions WHERE recipe_id = ?
    ");
    $stmt->execute([$recipeId, $doughMajor, $loafMinor, $name, json_encode($recipeData), $note, $recipeId]);
    $versionId = (int)$pdo->lastInsertId();

    // Update current_version on the recipe
    $pdo->prepare("
        UPDATE baker_recipes SET current_version = (SELECT version_number FROM baker_recipe_versions WHERE id = ?) WHERE id = ?
    ")->execute([$versionId, $recipeId]);

    return $versionId;
}

switch ($method) {
    case 'GET':
        if (isset($_GET['id'])) {
            $stmt = $pdo->prepare("SELECT * FROM baker_recipes WHERE id = ?");
            $stmt->execute([$_GET['id']]);
            $recipe = $stmt->fetch();
            if ($recipe) {
                $recipe['recipe_data'] = json_decode($recipe['recipe_data'], true);
                $recipe['base_recipe_data'] = null;
                if ($recipe['dough_type_id'] && !$recipe['is_dough_type']) {
                    $dtStmt = $pdo->prepare("SELECT recipe_data FROM dough_types WHERE id = ?");
                    $dtStmt->execute([$recipe['dough_type_id']]);
                    $dtRow = $dtStmt->fetch();
                    if ($dtRow && $dtRow['recipe_data']) {
                        $recipe['base_recipe_data'] = json_decode($dtRow['recipe_data'], true);
                    }
                }
                // Version history
                $vStmt = $pdo->prepare("SELECT id, version_number, dough_type_version_number, loaf_minor_version, name, note, created_at, recipe_data FROM baker_recipe_versions WHERE recipe_id = ? ORDER BY version_number DESC");
                $vStmt->execute([$_GET['id']]);
                $versions = $vStmt->fetchAll();
                foreach ($versions as &$ver) {
                    $ver['recipe_data'] = $ver['recipe_data'] ? json_decode($ver['recipe_data'], true) : null;
                }
                unset($ver);
                $recipe['versions'] = $versions;
                echo json_encode(['success' => true, 'recipe' => $recipe]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Recept niet gevonden']);
            }
        } elseif (isset($_GET['version_id'])) {
            $stmt = $pdo->prepare("SELECT * FROM baker_recipe_versions WHERE id = ?");
            $stmt->execute([$_GET['version_id']]);
            $v = $stmt->fetch();
            if ($v) {
                $v['recipe_data'] = json_decode($v['recipe_data'], true);
                echo json_encode(['success' => true, 'version' => $v]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Versie niet gevonden']);
            }
        } else {
            $stmt = $pdo->query("
                SELECT r.id, r.name, r.dough_type_id, r.is_dough_type, r.recipe_data, r.created_at, r.updated_at, r.current_version, dt.name as dough_type_name,
                       EXISTS(SELECT 1 FROM product_variants WHERE recipe_id = r.id) as linked_to_product
                FROM baker_recipes r
                LEFT JOIN dough_types dt ON r.dough_type_id = dt.id
                ORDER BY dt.sort_order ASC, dt.name ASC, r.sort_order ASC, r.name ASC
            ");
            $recipes = $stmt->fetchAll();
            foreach ($recipes as &$recipe) {
                $rd = json_decode($recipe['recipe_data'], true);
                $recipe['recipe_data'] = ['description' => $rd['description'] ?? ''];
            }
            unset($recipe);
            echo json_encode(['success' => true, 'recipes' => $recipes]);
        }
        break;

    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        if (empty($data['name'])) {
            echo json_encode(['success' => false, 'error' => 'Naam is verplicht']);
            break;
        }
        $isDoughType = !empty($data['is_dough_type']) ? 1 : 0;
        $doughTypeId = isset($data['dough_type_id']) ? ($data['dough_type_id'] ?: null) : null;

        if ($isDoughType) {
            $doughTypeId = upsertDoughType($pdo, $doughTypeId, $data['name'], $data['recipe_data'] ?? []);
        }

        $stmt = $pdo->prepare("INSERT INTO baker_recipes (name, dough_type_id, is_dough_type, recipe_data) VALUES (?, ?, ?, ?)");
        $stmt->execute([$data['name'], $doughTypeId, $isDoughType, json_encode($data['recipe_data'])]);
        $newId = (int)$pdo->lastInsertId();

        // Create initial version snapshot
        try {
            saveVersion($pdo, $newId, $data['name'], $data['recipe_data'] ?? [], 'Initiële versie');
        } catch (PDOException $e) { /* version table may not exist yet */ }

        echo json_encode(['success' => true, 'id' => $newId, 'dough_type_id' => $doughTypeId]);
        break;

    case 'PUT':
        $data = json_decode(file_get_contents('php://input'), true);
        if (empty($data['id'])) {
            echo json_encode(['success' => false, 'error' => 'ID is verplicht']);
            break;
        }
        $isDoughType = !empty($data['is_dough_type']) ? 1 : 0;
        $doughTypeId = isset($data['dough_type_id']) ? ($data['dough_type_id'] ?: null) : null;

        if ($isDoughType) {
            $doughTypeId = upsertDoughType($pdo, $doughTypeId, $data['name'], $data['recipe_data'] ?? []);
        }

        $stmt = $pdo->prepare("UPDATE baker_recipes SET name = ?, dough_type_id = ?, is_dough_type = ?, recipe_data = ? WHERE id = ?");
        $stmt->execute([$data['name'], $doughTypeId, $isDoughType, json_encode($data['recipe_data']), $data['id']]);

        // Save version snapshot only if name or recipe_data changed
        try {
            $latestStmt = $pdo->prepare("SELECT name, recipe_data FROM baker_recipe_versions WHERE recipe_id = ? ORDER BY version_number DESC LIMIT 1");
            $latestStmt->execute([(int)$data['id']]);
            $latest = $latestStmt->fetch();
            $incomingJson = json_encode($data['recipe_data'] ?? []);
            $hasChanges = !$latest
                || $latest['name'] !== $data['name']
                || json_encode(json_decode($latest['recipe_data'], true)) !== json_encode(json_decode($incomingJson, true));
            if ($hasChanges) {
                $note = isset($data['version_note']) && $data['version_note'] !== '' ? $data['version_note'] : null;
                saveVersion($pdo, (int)$data['id'], $data['name'], $data['recipe_data'] ?? [], $note);
            }
        } catch (PDOException $e) { /* version table may not exist yet */ }

        echo json_encode(['success' => true, 'dough_type_id' => $doughTypeId]);
        break;

    case 'PATCH':
        $data = json_decode(file_get_contents('php://input'), true);
        if (($data['action'] ?? '') === 'update_version_number' && !empty($data['version_id'])) {
            $newNum = (int)($data['version_number'] ?? 0);
            if ($newNum < 1) { echo json_encode(['success' => false, 'error' => 'Ongeldig versienummer']); break; }
            $vStmt = $pdo->prepare("SELECT recipe_id, version_number, dough_type_version_number FROM baker_recipe_versions WHERE id = ?");
            $vStmt->execute([$data['version_id']]);
            $v = $vStmt->fetch();
            if (!$v) { echo json_encode(['success' => false, 'error' => 'Versie niet gevonden']); break; }
            $useStmt = $pdo->prepare("SELECT COUNT(*) FROM bak_acties WHERE recipe_version_id = ?");
            $useStmt->execute([$data['version_id']]);
            if ((int)$useStmt->fetchColumn() > 0) {
                echo json_encode(['success' => false, 'error' => 'Versie is gekoppeld aan één of meer bakacties en kan niet worden gewijzigd']); break;
            }
            $newMajor = isset($data['dough_type_version_number']) ? (int)$data['dough_type_version_number'] : null;
            if ($v['dough_type_version_number'] !== null || ($newMajor !== null && $newMajor >= 1)) {
                // Compound-versioned: update minor and optionally promote a legacy version to compound
                if ($newMajor !== null && $newMajor >= 1) {
                    $pdo->prepare("UPDATE baker_recipe_versions SET dough_type_version_number = ?, loaf_minor_version = ? WHERE id = ?")
                        ->execute([$newMajor, $newNum, $data['version_id']]);
                } else {
                    $pdo->prepare("UPDATE baker_recipe_versions SET loaf_minor_version = ? WHERE id = ?")->execute([$newNum, $data['version_id']]);
                }
            } else {
                // Legacy recipe without dough type: update version_number as before
                $pdo->prepare("UPDATE baker_recipe_versions SET version_number = ? WHERE id = ?")->execute([$newNum, $data['version_id']]);
                // Sync current_version on the parent recipe if this was the active version
                $rStmt = $pdo->prepare("SELECT current_version FROM baker_recipes WHERE id = ?");
                $rStmt->execute([$v['recipe_id']]);
                $r = $rStmt->fetch();
                if ($r && (int)$r['current_version'] === (int)$v['version_number']) {
                    $pdo->prepare("UPDATE baker_recipes SET current_version = ? WHERE id = ?")->execute([$newNum, $v['recipe_id']]);
                }
            }
            echo json_encode(['success' => true]);
        } elseif (($data['action'] ?? '') === 'reorder' && !empty($data['items'])) {
            $stmt = $pdo->prepare("UPDATE baker_recipes SET sort_order = ? WHERE id = ?");
            foreach ($data['items'] as $item) {
                $stmt->execute([$item['sort_order'], $item['id']]);
            }
            echo json_encode(['success' => true]);
        } elseif (($data['action'] ?? '') === 'restore_version' && !empty($data['version_id'])) {
            $vStmt = $pdo->prepare("SELECT * FROM baker_recipe_versions WHERE id = ?");
            $vStmt->execute([$data['version_id']]);
            $v = $vStmt->fetch();
            if (!$v) {
                echo json_encode(['success' => false, 'error' => 'Versie niet gevonden']);
                break;
            }
            // Update the recipe with the old data
            $pdo->prepare("UPDATE baker_recipes SET name = ?, recipe_data = ? WHERE id = ?")
                ->execute([$v['name'], $v['recipe_data'], $v['recipe_id']]);
            // Save a new version snapshot marking it as a restore
            $oldVersion = $v['version_number'];
            saveVersion($pdo, (int)$v['recipe_id'], $v['name'], json_decode($v['recipe_data'], true), "Hersteld van versie $oldVersion");
            echo json_encode(['success' => true]);
        } elseif (($data['action'] ?? '') === 'update_version_data' && !empty($data['version_id'])) {
            if (!isset($data['recipe_data']) || !is_array($data['recipe_data'])) {
                echo json_encode(['success' => false, 'error' => 'recipe_data is verplicht']);
                break;
            }
            $vStmt = $pdo->prepare("SELECT recipe_id, version_number FROM baker_recipe_versions WHERE id = ?");
            $vStmt->execute([$data['version_id']]);
            $v = $vStmt->fetch();
            if (!$v) { echo json_encode(['success' => false, 'error' => 'Versie niet gevonden']); break; }
            $useStmt = $pdo->prepare("SELECT COUNT(*) FROM bak_acties WHERE recipe_version_id = ?");
            $useStmt->execute([$data['version_id']]);
            if ((int)$useStmt->fetchColumn() > 0) {
                echo json_encode(['success' => false, 'error' => 'Versie is gekoppeld aan één of meer bakacties en kan niet worden gewijzigd']); break;
            }
            $encoded = json_encode($data['recipe_data']);
            $pdo->prepare("UPDATE baker_recipe_versions SET recipe_data = ? WHERE id = ?")->execute([$encoded, $data['version_id']]);
            // If this is the active version, update the parent recipe too
            $rStmt = $pdo->prepare("SELECT current_version FROM baker_recipes WHERE id = ?");
            $rStmt->execute([$v['recipe_id']]);
            $r = $rStmt->fetch();
            if ($r && (int)$r['current_version'] === (int)$v['version_number']) {
                $pdo->prepare("UPDATE baker_recipes SET recipe_data = ? WHERE id = ?")->execute([$encoded, $v['recipe_id']]);
            }
            echo json_encode(['success' => true]);
        } elseif (($data['action'] ?? '') === 'delete_version' && !empty($data['version_id'])) {
            $vId = (int)$data['version_id'];
            $checkStmt = $pdo->prepare("
                SELECT brv.id, brv.version_number, brv.recipe_id, br.current_version,
                       (SELECT MAX(version_number) FROM baker_recipe_versions WHERE recipe_id = brv.recipe_id) as max_version
                FROM baker_recipe_versions brv
                JOIN baker_recipes br ON br.id = brv.recipe_id
                WHERE brv.id = ?
            ");
            $checkStmt->execute([$vId]);
            $row = $checkStmt->fetch();
            if (!$row) { echo json_encode(['success' => false, 'error' => 'Versie niet gevonden']); break; }

            $isActive  = (int)$row['version_number'] === (int)$row['current_version'];
            $isLatest  = (int)$row['version_number'] === (int)$row['max_version'];

            if ($isActive && !$isLatest) {
                echo json_encode(['success' => false, 'error' => 'Kan alleen de meest recente versie verwijderen']); break;
            }

            $useStmt = $pdo->prepare("SELECT COUNT(*) FROM bak_acties WHERE recipe_version_id = ?");
            $useStmt->execute([$vId]);
            if ((int)$useStmt->fetchColumn() > 0) {
                echo json_encode(['success' => false, 'error' => 'Versie is gekoppeld aan één of meer bakacties en kan niet worden verwijderd']); break;
            }

            if ($isActive) {
                // Rolling back: find the version directly before this one
                $prevStmt = $pdo->prepare("
                    SELECT id, version_number, name, recipe_data
                    FROM baker_recipe_versions
                    WHERE recipe_id = ? AND version_number < ?
                    ORDER BY version_number DESC LIMIT 1
                ");
                $prevStmt->execute([$row['recipe_id'], $row['version_number']]);
                $prev = $prevStmt->fetch();
                if (!$prev) {
                    echo json_encode(['success' => false, 'error' => 'Kan de enige versie niet verwijderen']); break;
                }
                $pdo->prepare("DELETE FROM baker_recipe_versions WHERE id = ?")->execute([$vId]);
                $pdo->prepare("UPDATE baker_recipes SET current_version = ?, recipe_data = ?, name = ? WHERE id = ?")
                    ->execute([$prev['version_number'], $prev['recipe_data'], $prev['name'], $row['recipe_id']]);
                echo json_encode(['success' => true, 'rolled_back' => true, 'previous_version' => (int)$prev['version_number']]);
            } else {
                $pdo->prepare("DELETE FROM baker_recipe_versions WHERE id = ?")->execute([$vId]);
                echo json_encode(['success' => true]);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'Onbekende actie']);
        }
        break;

    case 'DELETE':
        $id = $_GET['id'] ?? null;
        if (!$id) {
            echo json_encode(['success' => false, 'error' => 'ID is verplicht']);
            break;
        }
        $stmt = $pdo->prepare("DELETE FROM baker_recipes WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['success' => true]);
        break;
}
