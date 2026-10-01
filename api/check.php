<?php
require 'db.php';
try {
  $stmt = $pdo->query("SELECT id, email, role FROM users");
  print_r($stmt->fetchAll());
  $stmt = $pdo->query("SHOW COLUMNS FROM users");
  print_r($stmt->fetchAll());
} catch (Exception $e) {
  echo $e->getMessage();
}
