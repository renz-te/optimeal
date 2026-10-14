<?php
require_once __DIR__ . '/../api/db.php';
$cols = $pdo->query("SHOW COLUMNS FROM menu_items")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) {
    echo $c['Field'] . "\n";
}
