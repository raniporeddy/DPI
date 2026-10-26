<?php
require_once __DIR__ . '/../config/database.php';
echo "--- LATEST EKYC REQUESTS ---\n";
$stmt = $pdo->query("SELECT id, user_id, recipient_email, token, status, expires_at, created_at FROM ekyc_requests ORDER BY id DESC LIMIT 5");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    print_r($r);
}

echo "--- USER 18 KYC RECORD ---\n";
$stmt = $pdo->query("SELECT * FROM kyc_verification WHERE user_id = 18");
print_r($stmt->fetch(PDO::FETCH_ASSOC));

echo "--- USER 18 PROFILE ---\n";
$stmt = $pdo->query("SELECT * FROM investor_profiles WHERE user_id = 18");
print_r($stmt->fetch(PDO::FETCH_ASSOC));
