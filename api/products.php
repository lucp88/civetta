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
            $whereClause = $isAdmin ? '' : 'WHERE p.is_hidden = 0';
            $stmt = $pdo->query("SELECT p.id, p.naam, p.ingredienten, p.beschrijving, p.prijs, p.foto, p.category_id, p.is_active, p.is_hidden, pc.naam as category_naam FROM products p LEFT JOIN product_categories pc ON p.category_id = pc.id $whereClause ORDER BY p.sort_order ASC, p.naam ASC");
            $products = $stmt->fetchAll();

            $variantStmt = $pdo->query("SELECT * FROM product_variants ORDER BY product_id ASC, sort_order ASC, gewicht ASC");
            $allVariants = $variantStmt->fetchAll();

            $variantsByProduct = [];
            $allRecipeIds = [];
            foreach ($allVariants as $v) {
                if (!$isAdmin && $v['is_hidden']) continue;
                $pid = $v['product_id'];
                $variantsByProduct[$pid][] = [
                    'id' => (int)$v['id'],
                    'naam' => $v['naam'] ?? null,
                    'gewicht' => (int)$v['gewicht'],
                    'prijs' => (float)$v['prijs'],
                    'foto' => $v['foto'] ?? null,
                    'recipe_id' => !empty($v['recipe_id']) ? (int)$v['recipe_id'] : null,
                    'is_active' => (bool)$v['is_active'],
                    'is_hidden' => (bool)$v['is_hidden'],
                ];
                if (!empty($v['recipe_id'])) {
                    $allRecipeIds[] = (int)$v['recipe_id'];
                }
            }

            // Attach variants (without recipe derivation — that's done below)
            foreach ($products as &$product) {
                $product['is_active'] = (bool)$product['is_active'];
                $product['is_hidden'] = (bool)$product['is_hidden'];
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
                    $ingStmt = $pdo->prepare("SELECT i.id, COALESCE(p.name, i.name) as name, i.is_whole_grain, i.is_biologisch, i.is_allergeen, i.allergeen_naam FROM ingredients i LEFT JOIN ingredients p ON i.parent_id = p.id WHERE i.id IN ($grainPlaceholders)");
                    $ingStmt->execute($uniqueGrainIds);
                    foreach ($ingStmt->fetchAll() as $ing) {
                        $ingredientLookup[(int)$ing['id']] = ['name' => $ing['name'], 'is_whole_grain' => (bool)$ing['is_whole_grain'], 'is_biologisch' => (bool)$ing['is_biologisch'], 'is_allergeen' => (bool)$ing['is_allergeen'], 'allergeen_naam' => $ing['allergeen_naam']];
                    }
                }

                // Build biologisch names lookup (for mixins/toppings matched by name)
                $biologischNames = [];
                $bioStmt = $pdo->query("SELECT LOWER(name) as name FROM ingredients WHERE is_biologisch = 1 AND is_active = 1");
                foreach ($bioStmt->fetchAll() as $row) {
                    $biologischNames[$row['name']] = true;
                }

                // Build allergeen names lookup (for mixins/toppings matched by name)
                // Value is allergeen_naam (may be null) instead of true, so shared.php can pass it to frontend
                $allergeenNames = [];
                $allergeenStmt = $pdo->query("SELECT LOWER(name) as name, allergeen_naam FROM ingredients WHERE is_allergeen = 1 AND is_active = 1");
                foreach ($allergeenStmt->fetchAll() as $row) {
                    $allergeenNames[$row['name']] = $row['allergeen_naam'];
                }

                // Compute per-variant ingredient and recipe details
                foreach ($products as &$product) {
                    foreach ($product['variants'] as &$variant) {
                        if (!empty($variant['recipe_id']) && isset($recipesById[$variant['recipe_id']])) {
                            $rd = $recipesById[$variant['recipe_id']];
                            $result = computeIngredientList($rd, $ingredientLookup, $biologischNames, $allergeenNames);
                            if ($result !== null) {
                                $variant['ingredienten_recipe'] = $result['text'];
                                $variant['ingredienten_items'] = $result['items'];
                                $variant['recipe_details'] = computeRecipeDetails($rd, $ingredientLookup);
                            }
                        }
                    }
                    unset($variant);

                    // Set product-level ingredient data from first variant with recipe
                    foreach ($product['variants'] as $v) {
                        if (!empty($v['ingredienten_recipe'])) {
                            $product['ingredienten_recipe'] = $v['ingredienten_recipe'];
                            $product['ingredienten_items'] = $v['ingredienten_items'] ?? null;
                            $product['recipe_details'] = $v['recipe_details'] ?? null;
                            break;
                        }
                    }
                }
                unset($product);
            } catch (Exception $e) {
                // Recipe/ingredient data unavailable — products still returned without it
            }

            // Collect trace allergens based on inventory stock status
            $allAllergens = [];
            try {
                $allAllergenStmt = $pdo->query("SELECT allergeen_naam FROM allergen_trace_status WHERE status != 'cleared' ORDER BY allergeen_naam ASC");
                foreach ($allAllergenStmt->fetchAll() as $row) {
                    $allAllergens[] = $row['allergeen_naam'];
                }
            } catch (PDOException $e) {
                // Fallback to old behavior if allergen_trace_status table doesn't exist yet
                $allAllergenStmt = $pdo->query("SELECT DISTINCT allergeen_naam FROM ingredients WHERE is_allergeen = 1 AND is_active = 1 AND allergeen_naam IS NOT NULL AND allergeen_naam != '' ORDER BY allergeen_naam ASC");
                foreach ($allAllergenStmt->fetchAll() as $row) {
                    $allAllergens[] = $row['allergeen_naam'];
                }
            }

            $stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'btw_tarief'");
            $btwTarief = floatval($stmt->fetchColumn() ?: 9);

            echo json_encode(['success' => true, 'products' => $products, 'btw_tarief' => $btwTarief, 'all_allergens' => $allAllergens]);
            break;
            
        case 'POST':
            if (!$isAdmin) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => 'Niet geautoriseerd']);
                break;
            }
            $isMultipart = str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data');
            if ($isMultipart) {
                $action = $_POST['action'] ?? '';
                if ($action === 'create_variant' || $action === 'update_variant') {
                    $naam     = trim($_POST['naam'] ?? '') ?: null;
                    $gewicht  = (int)($_POST['gewicht'] ?? 0);
                    $prijs    = (float)($_POST['prijs'] ?? 0);
                    $recipeId = !empty($_POST['recipe_id']) ? (int)$_POST['recipe_id'] : null;

                    $fotoPath = null;
                    if (isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
                        $allowed = ['image/jpeg', 'image/png', 'image/webp'];
                        if (in_array($_FILES['foto']['type'], $allowed)) {
                            $uploadDir = __DIR__ . '/../img/producten/';
                            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                            $ext      = pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION);
                            $filename = uniqid('variant_') . '.' . $ext;
                            if (move_uploaded_file($_FILES['foto']['tmp_name'], $uploadDir . $filename)) {
                                $fotoPath = 'img/producten/' . $filename;
                            }
                        }
                    }

                    if ($action === 'create_variant') {
                        $stmt = $pdo->prepare("INSERT INTO product_variants (product_id, naam, gewicht, prijs, recipe_id, foto) VALUES (?, ?, ?, ?, ?, ?)");
                        $stmt->execute([(int)$_POST['product_id'], $naam, $gewicht, $prijs, $recipeId, $fotoPath]);
                        echo json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
                    } else {
                        $id = (int)$_POST['id'];
                        if ($fotoPath !== null) {
                            $stmt = $pdo->prepare("UPDATE product_variants SET naam = ?, gewicht = ?, prijs = ?, recipe_id = ?, foto = ? WHERE id = ?");
                            $stmt->execute([$naam, $gewicht, $prijs, $recipeId, $fotoPath, $id]);
                        } else {
                            $stmt = $pdo->prepare("UPDATE product_variants SET naam = ?, gewicht = ?, prijs = ?, recipe_id = ? WHERE id = ?");
                            $stmt->execute([$naam, $gewicht, $prijs, $recipeId, $id]);
                        }
                        echo json_encode(['success' => true]);
                    }
                } else {
                    echo json_encode(['success' => false, 'error' => 'Onbekende actie']);
                }
            } else {
                $data = json_decode(file_get_contents('php://input'), true);
                if (($data['action'] ?? '') === 'create_variant') {
                    $stmt = $pdo->prepare("INSERT INTO product_variants (product_id, naam, gewicht, prijs, recipe_id) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([
                        (int)$data['product_id'],
                        $data['naam'] ?: null,
                        (int)($data['gewicht'] ?? 0),
                        (float)($data['prijs'] ?? 0),
                        !empty($data['recipe_id']) ? (int)$data['recipe_id'] : null
                    ]);
                    echo json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
                } elseif (($data['action'] ?? '') === 'create_category') {
                    $naam = trim($data['naam'] ?? '');
                    if (!$naam) { echo json_encode(['success' => false, 'error' => 'Naam vereist']); break; }
                    $maxSort = $pdo->query("SELECT COALESCE(MAX(sort_order)+1, 0) FROM product_categories")->fetchColumn();
                    $stmt = $pdo->prepare("INSERT INTO product_categories (naam, sort_order) VALUES (?, ?)");
                    $stmt->execute([$naam, (int)$maxSort]);
                    echo json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO products (naam, ingredienten, beschrijving, prijs, foto) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $data['naam'],
                        $data['ingredienten'] ?? '',
                        $data['beschrijving'] ?? '',
                        $data['prijs'] ?? null,
                        $data['foto'] ?? ''
                    ]);
                    echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
                }
            }
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
            
        case 'PATCH':
            if (!$isAdmin) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => 'Niet geautoriseerd']);
                break;
            }
            $data = json_decode(file_get_contents('php://input'), true);
            if (($data['action'] ?? '') === 'reorder' && !empty($data['items'])) {
                $stmt = $pdo->prepare("UPDATE products SET sort_order = ? WHERE id = ?");
                foreach ($data['items'] as $item) {
                    $stmt->execute([$item['sort_order'], $item['id']]);
                }
                echo json_encode(['success' => true]);
            } elseif (($data['action'] ?? '') === 'reorder_variants' && !empty($data['items'])) {
                $stmt = $pdo->prepare("UPDATE product_variants SET sort_order = ? WHERE id = ?");
                foreach ($data['items'] as $item) {
                    $stmt->execute([$item['sort_order'], $item['id']]);
                }
                echo json_encode(['success' => true]);
            } elseif (($data['action'] ?? '') === 'update_variant' && !empty($data['id'])) {
                $stmt = $pdo->prepare("UPDATE product_variants SET naam = ?, gewicht = ?, prijs = ?, recipe_id = ? WHERE id = ?");
                $stmt->execute([
                    $data['naam'] ?: null,
                    (int)($data['gewicht'] ?? 0),
                    (float)($data['prijs'] ?? 0),
                    !empty($data['recipe_id']) ? (int)$data['recipe_id'] : null,
                    (int)$data['id']
                ]);
                echo json_encode(['success' => true]);
            } elseif (($data['action'] ?? '') === 'reorder_categories' && !empty($data['items'])) {
                $stmt = $pdo->prepare("UPDATE product_categories SET sort_order = ? WHERE id = ?");
                foreach ($data['items'] as $item) {
                    $stmt->execute([$item['sort_order'], $item['id']]);
                }
                echo json_encode(['success' => true]);
            } elseif (($data['action'] ?? '') === 'rename_category' && !empty($data['id'])) {
                $naam = trim($data['naam'] ?? '');
                if (!$naam) { echo json_encode(['success' => false, 'error' => 'Naam vereist']); break; }
                $stmt = $pdo->prepare("UPDATE product_categories SET naam = ? WHERE id = ?");
                $stmt->execute([$naam, (int)$data['id']]);
                echo json_encode(['success' => true, 'naam' => htmlspecialchars($naam)]);
            } elseif (($data['action'] ?? '') === 'toggle' && isset($data['type'], $data['id'], $data['field'], $data['value'])) {
                $allowedFields = ['is_active', 'is_hidden'];
                $field = $data['field'];
                if (!in_array($field, $allowedFields)) {
                    echo json_encode(['success' => false, 'error' => 'Ongeldig veld']);
                    break;
                }
                $id = (int)$data['id'];
                $value = $data['value'] ? 1 : 0;
                if ($data['type'] === 'product') {
                    $stmt = $pdo->prepare("UPDATE products SET `$field` = ? WHERE id = ?");
                } elseif ($data['type'] === 'variant') {
                    $stmt = $pdo->prepare("UPDATE product_variants SET `$field` = ? WHERE id = ?");
                } else {
                    echo json_encode(['success' => false, 'error' => 'Ongeldig type']);
                    break;
                }
                $stmt->execute([$value, $id]);
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Onbekende actie']);
            }
            break;

        case 'DELETE':
            if (!$isAdmin) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => 'Niet geautoriseerd']);
                break;
            }
            $data = json_decode(file_get_contents('php://input'), true);
            if (!empty($data['variant_id'])) {
                $stmt = $pdo->prepare("DELETE FROM product_variants WHERE id = ?");
                $stmt->execute([(int)$data['variant_id']]);
            } elseif (!empty($data['category_id'])) {
                $catId = (int)$data['category_id'];
                $count = $pdo->prepare("SELECT COUNT(*) FROM products WHERE category_id = ?");
                $count->execute([$catId]);
                if ($count->fetchColumn() > 0) {
                    echo json_encode(['success' => false, 'error' => 'Categorie heeft nog producten']);
                    break;
                }
                $stmt = $pdo->prepare("DELETE FROM product_categories WHERE id = ?");
                $stmt->execute([$catId]);
            } else {
                $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
                $stmt->execute([$data['id']]);
            }
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
