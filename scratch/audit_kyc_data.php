<?php
// Comprehensive KYC data audit - shows all kyc_verification records with user details
require_once __DIR__ . '/../config/database.php';

echo "=== FULL kyc_verification TABLE ===\n";
$stmt = $pdo->query("
    SELECT 
        kv.id            AS kv_id,
        kv.user_id,
        u.email,
        kv.full_name     AS submitted_name,
        kv.id_type,
        kv.id_number     AS submitted_id,
        kv.ocr_name,
        kv.ocr_dob,
        kv.ocr_gender,
        kv.ocr_id_number,
        kv.ocr_address,
        kv.photo_path,
        kv.document_path,
        kv.status
    FROM kyc_verification kv
    JOIN users u ON u.id = kv.user_id
    ORDER BY kv.user_id ASC
");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo json_encode($r, JSON_PRETTY_PRINT) . "\n---\n";
}

echo "\n=== PHOTO FILES - existence check ===\n";
foreach ($rows as $r) {
    $photoPath = $r['photo_path'] ?? '';
    if ($photoPath) {
        $fullPath = __DIR__ . '/../' . $photoPath;
        $exists = file_exists($fullPath) ? 'EXISTS' : 'MISSING';
        echo "user_id={$r['user_id']} | photo={$photoPath} | {$exists}\n";
    } else {
        echo "user_id={$r['user_id']} | NO PHOTO PATH\n";
    }
}

echo "\n=== SESSION WOULD SHOW (simulated) ===\n";
// Simulate: what does the current ekyc.php query actually return for user_id = 18?
$testUserId = 18;
$s = $pdo->prepare("SELECT * FROM kyc_verification WHERE user_id = ?");
$s->execute([$testUserId]);
$kyc18 = $s->fetch(PDO::FETCH_ASSOC);
echo "KYC row for user_id=18:\n";
echo "  ocr_name    : " . ($kyc18['ocr_name'] ?? 'NULL') . "\n";
echo "  ocr_gender  : " . ($kyc18['ocr_gender'] ?? 'NULL') . "\n";
echo "  ocr_dob     : " . ($kyc18['ocr_dob'] ?? 'NULL') . "\n";
echo "  ocr_id_number: " . ($kyc18['ocr_id_number'] ?? 'NULL') . "\n";
echo "  photo_path  : " . ($kyc18['photo_path'] ?? 'NULL') . "\n";
echo "  photo exists: " . (file_exists(__DIR__ . '/../' . ($kyc18['photo_path'] ?? '')) ? 'YES' : 'NO') . "\n";

echo "\n=== ALL EXTRACTED PHOTOS ON DISK ===\n";
$extractedDir = __DIR__ . '/../uploads/extracted/';
if (is_dir($extractedDir)) {
    $files = glob($extractedDir . '*.{png,jpg,jpeg}', GLOB_BRACE);
    foreach ($files as $f) {
        echo basename($f) . " (" . filesize($f) . " bytes)\n";
    }
}

echo "\n=== KYC PHOTOS SUBDIR ===\n";
$photosDir = __DIR__ . '/../uploads/kyc/photos/';
if (is_dir($photosDir)) {
    $files = glob($photosDir . '*.{png,jpg,jpeg}', GLOB_BRACE);
    foreach ($files as $f) {
        echo basename($f) . " (" . filesize($f) . " bytes)\n";
    }
}

echo "\n=== ekyc_requests - recent 10 ===\n";
try {
    $s2 = $pdo->query("SELECT id, user_id, recipient_email, token, status, created_at FROM ekyc_requests ORDER BY id DESC LIMIT 10");
    foreach ($s2->fetchAll(PDO::FETCH_ASSOC) as $r) {
        echo json_encode($r) . "\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
