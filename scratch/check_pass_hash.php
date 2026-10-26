<?php
require 'config/database.php';
$stmt = $pdo->prepare("SELECT id, email, password FROM users WHERE id IN (1, 17, 18)");
$stmt->execute();
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $u) {
    echo "User ID: " . $u['id'] . ", Email: " . $u['email'] . ", Hash prefix: " . substr($u['password'], 0, 7) . "\n";
}
