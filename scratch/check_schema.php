<?php
require_once __DIR__ . '/../api/db.php';
$cols = $pdo->query('DESCRIBE batches')->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) {
    echo "{$c['Field']} | {$c['Type']} | {$c['Null']} | {$c['Default']}\n";
}
