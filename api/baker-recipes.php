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

function saveVersion(PDO $pdo, int $recipeId, string $name, array $recipeData, ?string $note): int {
    $stmt = $pdo->prepare("
        INSERT INTO baker_recipe_versions (recipe_id, version_number, name, recipe_data, note)
        SELECT ?, COALESCE(MAX(version_number), 0) + 1, ?, ?, ?
        FROM baker_recipe_versions WHERE recipe_id = ?
    ");
    $stmt->execute([$recipeId, $name, json_encode($recipeData), $note, $recipeId]);
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
                $vStmt = $pdo->prepare("SELECT id, version_number, name, note, created_at, recipe_data FROM baker_recipe_versions WHERE recipe_id = ? ORDER BY version_number DESC");
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

        // Save version snapshot
        try {
            $note = isset($data['version_note']) && $data['version_note'] !== '' ? $data['version_note'] : null;
            saveVersion($pdo, (int)$data['id'], $data['name'], $data['recipe_data'] ?? [], $note);
        } catch (PDOException $e) { /* version table may not exist yet */ }

        echo json_encode(['success' => true, 'dough_type_id' => $doughTypeId]);
        break;

    case 'PATCH':
        $data = json_decode(file_get_contents('php://input'), true);
        if (($data['action'] ?? '') === 'reorder' && !empty($data['items'])) {
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
