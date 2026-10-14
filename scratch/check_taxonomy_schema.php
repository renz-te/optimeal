<?php
require_once __DIR__ . '/../api/db.php';

echo "=== CHECKING SUBMISSION DUPLICATES ===\n";
$subs = $pdo->query("
    SELECT LOWER(TRIM(raw_name)) as norm_name, COUNT(*) as cnt, GROUP_CONCAT(id) as ids
    FROM ingredient_submissions
    GROUP BY LOWER(TRIM(raw_name))
    HAVING cnt > 1
")->fetchAll(PDO::FETCH_ASSOC);
print_r($subs);

echo "=== CHECKING INGREDIENTS SIMILARITY / PLURALS ===\n";
$allIngs = $pdo->query("SELECT id, name FROM ingredients ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$names = [];
foreach ($allIngs as $i) {
    $norm = strtolower(trim($i['name']));
    $names[$norm] = $i['id'];
}

foreach ($allIngs as $i) {
    $norm = strtolower(trim($i['name']));
    // Check if plural ends with s or es
    if (str_ends_with($norm, 'es')) {
        $stem = substr($norm, 0, -2);
        if (isset($names[$stem])) {
            echo "Potential plural pair: '{$i['name']}' (ID {$i['id']}) vs ID {$names[$stem]}\n";
        }
    } elseif (str_ends_with($norm, 's')) {
        $stem = substr($norm, 0, -1);
        if (isset($names[$stem])) {
            echo "Potential plural pair: '{$i['name']}' (ID {$i['id']}) vs ID {$names[$stem]}\n";
        }
    }
}
