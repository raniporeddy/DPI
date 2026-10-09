<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/email_helper.php';

$stmt = $pdo->prepare("SELECT id, email, role FROM users WHERE email = ?");
$stmt->execute(['11239a072@kanchiuniv.ac.in']);
$user = $stmt->fetch();

print_r($user);

if ($user) {
    $agreements = getAgreements($pdo);
    if (!empty($agreements)) {
        $ag = $agreements[0];
        echo "Sending eSign Request for user {$user['email']} (ID: {$user['id']}) for agreement '{$ag['title']}'...\n";
        $res = createESignRequest($pdo, $user['id'], $ag['id'], $user['email']);
        print_r($res);
    }
}
