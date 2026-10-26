<?php
require __DIR__ . '/../config/database.php';
$stmt = $pdo->query(
    'SELECT u.id, u.email, u.role, kv.id_type, kv.id_number, kv.full_name, kv.dob,
            kv.ocr_name, kv.ocr_dob, kv.ocr_gender, kv.ocr_address, kv.ocr_id_number,
            kv.photo_path, kv.document_path, kv.status
     FROM users u
     LEFT JOIN kyc_verification kv ON u.id = kv.user_id
     WHERE u.role = "investor"
     LIMIT 20'
);
$rows = $stmt->fetchAll();
foreach ($rows as $r) {
    echo json_encode($r) . PHP_EOL;
}
// Also check if ekyc_requests table exists
try {
    $s2 = $pdo->query('SELECT COUNT(*) as cnt FROM ekyc_requests');
    echo "ekyc_requests count: " . $s2->fetchColumn() . PHP_EOL;
} catch(Exception $e) {
    echo "ekyc_requests table: " . $e->getMessage() . PHP_EOL;
}
// Check investor_profiles for photo/gender
$s3 = $pdo->query('SELECT user_id, full_name, dob, gender FROM investor_profiles LIMIT 20');
foreach($s3->fetchAll() as $r) { echo "profile: " . json_encode($r) . PHP_EOL; }
