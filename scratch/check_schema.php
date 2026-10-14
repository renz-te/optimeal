<?php
require_once __DIR__ . '/../api/db.php';
$items = $pdo->query('
    SELECT oi.*, m.name, m.base_price 
    FROM order_items oi 
    JOIN menu_items m ON oi.menu_item_id = m.id 
    LIMIT 5
')->fetchAll(PDO::FETCH_ASSOC);
print_r($items);
