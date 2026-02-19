<?php
require_once '../admin/config.php';
require_once 'cors.php';

header('Content-Type: application/json');
setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$method = $_SERVER['REQUEST_METHOD'];
$isAdmin = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;

function grainDisplayName($type, $lookup, $capitalize = false) {
    if (is_numeric($type) && isset($lookup[(int)$type])) {
        $name = $lookup[(int)$type]['name'];
        return $capitalize ? $name : strtolower($name);
    }
    // Legacy string fallback (recipes saved before ingredient IDs were used)
    $legacy = [
        'wheat_white' => 'tarwebloem', 'wheat_whole' => 'volkorenmeel',
        'spelt_white' => 'speltbloem', 'spelt_whole' => 'volkorenspeltmeel',
        'durum' => 'durummeel', 'emmer' => 'emmermeel',
        'rye_white' => 'roggebloem', 'rye_whole' => 'volkorenroggemeel',
        'einkorn' => 'einkornmeel', 'buckwheat' => 'boekweitmeel',
        'rice' => 'rijstmeel', 'barley' => 'gerstemeel', 'teff' => 'teffmeel',
    ];
    $name = $legacy[$type] ?? (string)$type;
    return $capitalize ? ucfirst($name) : $name;
}

function grainIsWhole($type, $lookup) {
    if (is_numeric($type) && isset($lookup[(int)$type])) {
        return (bool)$lookup[(int)$type]['is_whole_grain'];
    }
    return strpos((string)$type, '_whole') !== false;
}

function calculateWholeGrainPct($recipe, $lookup = []) {
    $allGrains = [];
    if (!empty($recipe['mainDoughGrains'])) {
        $allGrains = array_merge($allGrains, $recipe['mainDoughGrains']);
    }
    if (!empty($recipe['useSourdough']) && !empty($recipe['sourdoughGrains'])) {
        $allGrains = array_merge($allGrains, $recipe['sourdoughGrains']);
    }
    if (!empty($recipe['usePreFerment']) && !empty($recipe['preFermentGrains'])) {
        $allGrains = array_merge($allGrains, $recipe['preFermentGrains']);
    }
    $totalPct = 0;
    $wholePct = 0;
    foreach ($allGrains as $grain) {
        $pct = $grain['pct'] ?? 0;
        $totalPct += $pct;
        if (grainIsWhole($grain['type'] ?? '', $lookup)) {
            $wholePct += $pct;
        }
    }
    return $totalPct > 0 ? ($wholePct / $totalPct) * 100 : 0;
}

function computeIngredientList($recipeData, $lookup = []) {
    $yeastNames = [
        'fresh_yeast' => 'verse gist', 'instant_yeast' => 'gist', 'sourdough_culture' => 'desemcultuur',
    ];

    // Grains always come first as a group, sorted among themselves by %
    $grains = [];
    foreach ($recipeData['mainDoughGrains'] ?? [] as $grain) {
        if (($grain['pct'] ?? 0) > 0) {
            $grains[] = ['name' => grainDisplayName($grain['type'] ?? '', $lookup), 'amount' => (float)$grain['pct']];
        }
    }
    usort($grains, fn($a, $b) => $b['amount'] <=> $a['amount']);

    // Non-grain ingredients sorted by amount
    $others = [];
    // Sourdough is omitted: it is flour + water, not a separate ingredient
    $others[] = ['name' => 'water', 'amount' => (float)($recipeData['hydration'] ?? 65)];
    $others[] = ['name' => 'zout', 'amount' => (float)($recipeData['saltPct'] ?? 2.6)];

    if (!empty($recipeData['useYeast'])) {
        $yeastType = $recipeData['yeastType'] ?? 'instant_yeast';
        $others[] = ['name' => $yeastNames[$yeastType] ?? 'gist', 'amount' => (float)($recipeData['yeastPct'] ?? 1)];
    }

    foreach ($recipeData['mixins'] ?? [] as $mixin) {
        if (!empty($mixin['ingredient']) && ($mixin['pct'] ?? 0) > 0) {
            $others[] = ['name' => strtolower($mixin['ingredient']), 'amount' => (float)$mixin['pct']];
        }
    }

    foreach ($recipeData['toppings'] ?? [] as $topping) {
        if (!empty($topping['ingredient']) && ($topping['pct'] ?? 0) > 0) {
            $others[] = ['name' => strtolower($topping['ingredient']), 'amount' => (float)$topping['pct']];
        }
    }

    usort($others, fn($a, $b) => $b['amount'] <=> $a['amount']);

    $names = array_column(array_merge($grains, $others), 'name');
    return !empty($names) ? implode(', ', $names) : null;
}

function computeRecipeDetails($recipeData, $lookup = []) {
    $grains = [];
    foreach ($recipeData['mainDoughGrains'] ?? [] as $grain) {
        if (($grain['pct'] ?? 0) > 0) {
            $grains[] = ['name' => grainDisplayName($grain['type'] ?? '', $lookup, true), 'pct' => (int)round((float)$grain['pct'])];
        }
    }
    usort($grains, fn($a, $b) => $b['pct'] <=> $a['pct']);

    return [
        'volkoren_pct' => (int)round(calculateWholeGrainPct($recipeData, $lookup)),
        'grains' => $grains,
    ];
}

try {
    switch ($method) {
        case 'GET':
            $stmt = $pdo->query("SELECT id, naam, ingredienten, beschrijving, prijs, foto FROM products ORDER BY naam ASC");
            $products = $stmt->fetchAll();

            $variantStmt = $pdo->query("SELECT id, product_id, gewicht, prijs, recipe_id FROM product_variants ORDER BY gewicht ASC");
            $allVariants = $variantStmt->fetchAll();

            $variantsByProduct = [];
            $recipeIdByProduct = [];
            foreach ($allVariants as $v) {
                $pid = $v['product_id'];
                $variantsByProduct[$pid][] = [
                    'id' => (int)$v['id'],
                    'gewicht' => (int)$v['gewicht'],
                    'prijs' => (float)$v['prijs']
                ];
                if (!isset($recipeIdByProduct[$pid]) && !empty($v['recipe_id'])) {
                    $recipeIdByProduct[$pid] = (int)$v['recipe_id'];
                }
            }

            // Attach variants (without recipe derivation — that's done below)
            foreach ($products as &$product) {
                $product['variants'] = $variantsByProduct[$product['id']] ?? [];
            }
            unset($product);

            // Derive ingredient list from recipes — wrapped so any DB error here
            // never prevents the basic product list from being returned
            try {
                $recipesById = [];
                if (!empty($recipeIdByProduct)) {
                    $uniqueIds = array_unique(array_values($recipeIdByProduct));
                    $placeholders = implode(',', array_fill(0, count($uniqueIds), '?'));
                    $recipeStmt = $pdo->prepare("SELECT id, recipe_data FROM baker_recipes WHERE id IN ($placeholders)");
                    $recipeStmt->execute($uniqueIds);
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
                    $uniqueGrainIds = array_unique($allGrainIds);
                    $grainPlaceholders = implode(',', array_fill(0, count($uniqueGrainIds), '?'));
                    $ingStmt = $pdo->prepare("SELECT id, name, is_whole_grain FROM ingredients WHERE id IN ($grainPlaceholders)");
                    $ingStmt->execute($uniqueGrainIds);
                    foreach ($ingStmt->fetchAll() as $ing) {
                        $ingredientLookup[(int)$ing['id']] = ['name' => $ing['name'], 'is_whole_grain' => (bool)$ing['is_whole_grain']];
                    }
                }

                foreach ($products as &$product) {
                    $pid = $product['id'];
                    if (isset($recipeIdByProduct[$pid]) && isset($recipesById[$recipeIdByProduct[$pid]])) {
                        $rd = $recipesById[$recipeIdByProduct[$pid]];
                        $list = computeIngredientList($rd, $ingredientLookup);
                        if ($list !== null) {
                            $product['ingredienten_recipe'] = $list;
                            $product['recipe_details'] = computeRecipeDetails($rd, $ingredientLookup);
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
