<?php
require_once __DIR__ . '/../api/db.php';

echo "=== MIGRATION: Phase B4.10 Chunk 3 (Taxonomy Seed & Deduplication) ===\n";

try {
    $pdo->beginTransaction();

    // -------------------------------------------------------------
    // PART 1: Auto-deduplication routine for `ingredients` table
    // -------------------------------------------------------------
    echo "1. Scanning ingredients table for duplicates...\n";

    // Normalize and find duplicates by LOWER(TRIM(name))
    $stmt = $pdo->query("
        SELECT LOWER(TRIM(name)) as norm_name, COUNT(*) as cnt, GROUP_CONCAT(id ORDER BY id ASC) as ids
        FROM ingredients
        GROUP BY LOWER(TRIM(name))
        HAVING cnt > 1
    ");
    $duplicates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $mergedCount = 0;
    foreach ($duplicates as $dup) {
        $idList = array_map('intval', explode(',', $dup['ids']));
        $canonicalId = array_shift($idList); // Keep earliest/lowest ID

        foreach ($idList as $dupId) {
            echo "   Merging duplicate ingredient ID $dupId into canonical ID $canonicalId ('{$dup['norm_name']}')...\n";

            // Re-point ingredient_allergen_map
            $pdo->prepare("UPDATE IGNORE ingredient_allergen_map SET ingredient_id = ? WHERE ingredient_id = ?")
                ->execute([$canonicalId, $dupId]);
            $pdo->prepare("DELETE FROM ingredient_allergen_map WHERE ingredient_id = ?")
                ->execute([$dupId]);

            // Re-point menu_item_ingredients
            $pdo->prepare("UPDATE IGNORE menu_item_ingredients SET ingredient_id = ? WHERE ingredient_id = ?")
                ->execute([$canonicalId, $dupId]);
            $pdo->prepare("DELETE FROM menu_item_ingredients WHERE ingredient_id = ?")
                ->execute([$dupId]);

            // Re-point recipe_ingredients
            $pdo->prepare("UPDATE recipe_ingredients SET ingredient_id = ? WHERE ingredient_id = ?")
                ->execute([$canonicalId, $dupId]);

            // Re-point ingredient_submissions
            $pdo->prepare("UPDATE ingredient_submissions SET resolved_ingredient_id = ? WHERE resolved_ingredient_id = ?")
                ->execute([$canonicalId, $dupId]);

            // Delete the duplicate ingredient
            $pdo->prepare("DELETE FROM ingredients WHERE id = ?")->execute([$dupId]);
            $mergedCount++;
        }
    }
    echo "   Deduplicated $mergedCount redundant ingredient records.\n";

    // -------------------------------------------------------------
    // PART 2: Auto-deduplication for pending `ingredient_submissions`
    // -------------------------------------------------------------
    echo "2. Scanning pending ingredient_submissions for duplicates...\n";
    $subStmt = $pdo->query("
        SELECT LOWER(TRIM(raw_name)) as norm_name, COUNT(*) as cnt, GROUP_CONCAT(id ORDER BY id ASC) as ids
        FROM ingredient_submissions
        WHERE status = 'pending'
        GROUP BY LOWER(TRIM(raw_name))
        HAVING cnt > 1
    ");
    $subDuplicates = $subStmt->fetchAll(PDO::FETCH_ASSOC);

    $subRemovedCount = 0;
    foreach ($subDuplicates as $sDup) {
        $idList = array_map('intval', explode(',', $sDup['ids']));
        $keepId = array_shift($idList); // Keep earliest pending submission

        if (!empty($idList)) {
            $inClause = implode(',', $idList);
            // Delete redundant duplicate pending submissions
            $pdo->exec("DELETE FROM ingredient_submissions WHERE id IN ($inClause)");
            $subRemovedCount += count($idList);
            echo "   Collapsed " . count($idList) . " redundant pending submissions for '{$sDup['norm_name']}' (kept ID $keepId).\n";
        }
    }
    echo "   Deduplicated $subRemovedCount redundant pending submissions.\n";

    // -------------------------------------------------------------
    // PART 3: Seed Baseline Taxonomy Ingredients & Mappings
    // -------------------------------------------------------------
    echo "3. Seeding baseline taxonomy ingredients and allergen mappings...\n";

    // Load critical allergens lookup by lowercase name
    $allergenRows = $pdo->query("SELECT id, name FROM critical_allergens")->fetchAll(PDO::FETCH_ASSOC);
    $allergenLookup = [];
    foreach ($allergenRows as $ar) {
        $allergenLookup[strtolower(trim($ar['name']))] = (int)$ar['id'];
    }

    // Baseline ingredients with optional allergen keys
    $baselineIngredients = [
        // Allergenic staples
        ['name' => 'Peanuts', 'allergens' => ['peanuts']],
        ['name' => 'Peanut Butter', 'allergens' => ['peanuts']],
        ['name' => 'Tree Nuts', 'allergens' => ['tree nuts']],
        ['name' => 'Cashews', 'allergens' => ['tree nuts']],
        ['name' => 'Almonds', 'allergens' => ['tree nuts']],
        ['name' => 'Walnuts', 'allergens' => ['tree nuts']],
        ['name' => 'Cashew Butter', 'allergens' => ['tree nuts']],
        ['name' => 'Shellfish / Crustaceans', 'allergens' => ['shellfish / crustaceans']],
        ['name' => 'Shrimp', 'allergens' => ['shellfish / crustaceans']],
        ['name' => 'Crab', 'allergens' => ['shellfish / crustaceans']],
        ['name' => 'Squid', 'allergens' => ['shellfish / crustaceans']],
        ['name' => 'Clams', 'allergens' => ['shellfish / crustaceans']],
        ['name' => 'Oysters', 'allergens' => ['shellfish / crustaceans']],
        ['name' => 'Shrimp Paste', 'allergens' => ['shrimp paste (bagoong)']],
        ['name' => 'Soy Sauce', 'allergens' => ['soy']],
        ['name' => 'Tofu', 'allergens' => ['soy']],
        ['name' => 'Soy', 'allergens' => ['soy']],
        ['name' => 'Milk', 'allergens' => ['dairy']],
        ['name' => 'Cheese', 'allergens' => ['dairy']],
        ['name' => 'Butter', 'allergens' => ['dairy']],
        ['name' => 'Condensed Milk', 'allergens' => ['dairy']],
        ['name' => 'Evaporated Milk', 'allergens' => ['dairy']],
        ['name' => 'Yogurt', 'allergens' => ['dairy']],
        ['name' => 'Eggs', 'allergens' => ['eggs']],
        ['name' => 'Egg Noodles', 'allergens' => ['eggs', 'wheat/gluten']],
        ['name' => 'Fish Sauce', 'allergens' => ['fish']],
        ['name' => 'Bangus', 'allergens' => ['fish']],
        ['name' => 'Tilapia', 'allergens' => ['fish']],
        ['name' => 'Tuna', 'allergens' => ['fish']],
        ['name' => 'Salmon', 'allergens' => ['fish']],
        ['name' => 'Flour', 'allergens' => ['wheat/gluten']],
        ['name' => 'Bread', 'allergens' => ['wheat/gluten']],
        ['name' => 'Pasta', 'allergens' => ['wheat/gluten']],
        ['name' => 'Sesame Oil', 'allergens' => ['sesame']],
        ['name' => 'Sesame Seeds', 'allergens' => ['sesame']],

        // Non-allergenic cafeteria staples
        ['name' => 'Chicken'],
        ['name' => 'Chicken Thigh'],
        ['name' => 'Chicken Liver'],
        ['name' => 'Pork'],
        ['name' => 'Pork Belly'],
        ['name' => 'Pork Ribs'],
        ['name' => 'Pork Snout'],
        ['name' => 'Beef'],
        ['name' => 'Ground Beef'],
        ['name' => 'Garlic'],
        ['name' => 'Onion'],
        ['name' => 'Ginger'],
        ['name' => 'Tomato'],
        ['name' => 'Tamarind'],
        ['name' => 'Taro'],
        ['name' => 'Kangkong'],
        ['name' => 'Green Chili Pepper'],
        ['name' => 'Eggplant'],
        ['name' => 'String Beans'],
        ['name' => 'Bok Choy'],
        ['name' => 'Cabbage'],
        ['name' => 'Carrots'],
        ['name' => 'Potatoes'],
        ['name' => 'Radish'],
        ['name' => 'Lemongrass'],
        ['name' => 'Bay Leaves'],
        ['name' => 'Black Pepper'],
        ['name' => 'Salt'],
        ['name' => 'Sugar'],
        ['name' => 'Brown Sugar'],
        ['name' => 'Vinegar'],
        ['name' => 'Cane Vinegar'],
        ['name' => 'Vegetable Oil'],
        ['name' => 'Cooking Oil'],
        ['name' => 'Water'],
        ['name' => 'Coconut Milk'],
        ['name' => 'Annatto Powder'],
        ['name' => 'Annatto Oil'],
        ['name' => 'Calamansi Juice'],
    ];

    $checkStmt = $pdo->prepare("SELECT id FROM ingredients WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1");
    $insertIngStmt = $pdo->prepare("INSERT INTO ingredients (name) VALUES (?)");
    $insertMapStmt = $pdo->prepare("INSERT IGNORE INTO ingredient_allergen_map (ingredient_id, allergen_id) VALUES (?, ?)");

    $seededCount = 0;
    $mappedCount = 0;

    foreach ($baselineIngredients as $item) {
        $checkStmt->execute([$item['name']]);
        $ingId = $checkStmt->fetchColumn();

        if (!$ingId) {
            $insertIngStmt->execute([$item['name']]);
            $ingId = (int)$pdo->lastInsertId();
            $seededCount++;
        } else {
            $ingId = (int)$ingId;
        }

        if (!empty($item['allergens'])) {
            foreach ($item['allergens'] as $aName) {
                if (isset($allergenLookup[$aName])) {
                    $insertMapStmt->execute([$ingId, $allergenLookup[$aName]]);
                    $mappedCount++;
                }
            }
        }
    }

    echo "   Seeded $seededCount new baseline ingredients. Ensured $mappedCount allergen mappings.\n";

    $pdo->commit();
    echo "\n=== Migration B4.10 Chunk 3 completed successfully! ===\n";
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "Migration failed: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
