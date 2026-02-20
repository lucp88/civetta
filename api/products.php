<?php
require_once '../admin/config.php';
require_once '../lib/shared.php';
require_once 'cors.php';

header('Content-Type: application/json');
setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$method = $_SERVER['REQUEST_METHOD'];
$isAdmin = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;

// Recipe helper functions (grainIsWhole, grainDisplayName, buildFlourTypeMap,
// computeIngredientList, computeRecipeDetails) are in lib/shared.php

try {
    switch ($method) {
        case 'GET':
            $stmt = $pdo->query("SELECT id, naam, ingredienten, beschrijving, prijs, foto FROM products ORDER BY naam ASC");
            $products = $stmt->fetchAll();

            $variantStmt = $pdo->query("SELECT * FROM product_variants ORDER BY naam ASC, gewicht ASC");
            $allVariants = $variantStmt->fetchAll();

            $variantsByProduct = [];
            $allRecipeIds = [];
            foreach ($allVariants as $v) {
                $pid = $v['product_id'];
                $variantsByProduct[$pid][] = [
                    'id' => (int)$v['id'],
                    'naam' => $v['naam'] ?? null,
                    'gewicht' => (int)$v['gewicht'],
                    'prijs' => (float)$v['prijs'],
                    'foto' => $v['foto'] ?? null,
                    'recipe_id' => !empty($v['recipe_id']) ? (int)$v['recipe_id'] : null
                ];
                if (!empty($v['recipe_id'])) {
                    $allRecipeIds[] = (int)$v['recipe_id'];
                }
            }

            // Attach variants (without recipe derivation — that's done below)
            foreach ($products as &$product) {
                $product['variants'] = $variantsByProduct[$product['id']] ?? [];
            }
            unset($product);

            // Derive ingredient list per variant recipe — wrapped so any DB error here
            // never prevents the basic product list from being returned
            try {
                $recipesById = [];
                $allRecipeIds = array_unique($allRecipeIds);
                if (!empty($allRecipeIds)) {
                    $placeholders = implode(',', array_fill(0, count($allRecipeIds), '?'));
                    $recipeStmt = $pdo->prepare("SELECT id, recipe_data FROM baker_recipes WHERE id IN ($placeholders)");
                    $recipeStmt->execute(array_values($allRecipeIds));
                    foreach ($recipeStmt->fetchAll() as $r) {
                        $recipesById[$r['id']] = json_decode($r['recipe_data'], true);
                    }
                }

                $allGrainIds = [];
                foreach ($recipesById as $rd) {
                    foreach (['mainDoughGrains', 'sourdoughGrains', 'preFermentGrains'] as $key) {
                        foreach ($rd[$key] ?? [] as $grain) {
                            if (is_numeric($grain['type'] ?? '')) {
                                $allGrainIds[] = (int)$grain['type'];
                            }
                        }
                    }
                }
                $ingredientLookup = [];
                if (!empty($allGrainIds)) {
                    $uniqueGrainIds = array_values(array_unique($allGrainIds));
                    $grainPlaceholders = implode(',', array_fill(0, count($uniqueGrainIds), '?'));
                    $ingStmt = $pdo->prepare("SELECT id, name, is_whole_grain FROM ingredients WHERE id IN ($grainPlaceholders)");
                    $ingStmt->execute($uniqueGrainIds);
                    foreach ($ingStmt->fetchAll() as $ing) {
                        $ingredientLookup[(int)$ing['id']] = ['name' => $ing['name'], 'is_whole_grain' => (bool)$ing['is_whole_grain']];
                    }
                }

                // Compute per-variant ingredient and recipe details
                foreach ($products as &$product) {
                    foreach ($product['variants'] as &$variant) {
                        if (!empty($variant['recipe_id']) && isset($recipesById[$variant['recipe_id']])) {
                            $rd = $recipesById[$variant['recipe_id']];
                            $list = computeIngredientList($rd, $ingredientLookup);
                            if ($list !== null) {
                                $variant['ingredienten_recipe'] = $list;
                                $variant['recipe_details'] = computeRecipeDetails($rd, $ingredientLookup);
                            }
                        }
                    }
                    unset($variant);

                    // Set product-level ingredient data from first variant with recipe
                    foreach ($product['variants'] as $v) {
                        if (!empty($v['ingredienten_recipe'])) {
                            $product['ingredienten_recipe'] = $v['ingredienten_recipe'];
                            $product['recipe_details'] = $v['recipe_details'] ?? null;
                            break;
                        }
                    }
                }
                unset($product);
            } catch (Exception $e) {
                // Recipe/ingredient data unavailable — products still returned without it
            }

            $stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'btw_tarief'");
            $btwTarief = floatval($stmt->fetchColumn() ?: 9);

            echo json_encode(['success' => true, 'products' => $products, 'btw_tarief' => $btwTarief]);
            break;
            
        case 'POST':
            if (!$isAdmin) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => 'Niet geautoriseerd']);
                break;
            }
            $data = json_decode(file_get_contents('php://input'), true);
            $stmt = $pdo->prepare("INSERT INTO products (naam, ingredienten, beschrijving, prijs, foto) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([
                $data['naam'],
                $data['ingredienten'] ?? '',
                $data['beschrijving'] ?? '',
                $data['prijs'] ?? null,
                $data['foto'] ?? ''
            ]);
            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
            break;
            
        case 'PUT':
            if (!$isAdmin) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => 'Niet geautoriseerd']);
                break;
            }
            $data = json_decode(file_get_contents('php://input'), true);
            $stmt = $pdo->prepare("UPDATE products SET naam = ?, ingredienten = ?, beschrijving = ?, prijs = ?, foto = ? WHERE id = ?");
            $stmt->execute([
                $data['naam'],
                $data['ingredienten'] ?? '',
                $data['beschrijving'] ?? '',
                $data['prijs'] ?? null,
                $data['foto'] ?? '',
                $data['id']
            ]);
            echo json_encode(['success' => true]);
            break;
            
        case 'DELETE':
            if (!$isAdmin) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => 'Niet geautoriseerd']);
                break;
            }
            $data = json_decode(file_get_contents('php://input'), true);
            $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
            $stmt->execute([$data['id']]);
            echo json_encode(['success' => true]);
            break;
            
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database fout']);
}
?>
