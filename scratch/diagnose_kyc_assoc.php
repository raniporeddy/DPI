<?php
// Focused audit: show only the data relevant to diagnosing the wrong-data problem
require_once __DIR__ . '/../config/database.php';

echo "=== kyc_verification: user_id, submitted name, ocr_name, photo_path, status ===\n";
$stmt = $pdo->query("
    SELECT kv.id, kv.user_id, u.email, kv.full_name AS submitted_name, kv.id_number,
           kv.ocr_name, kv.ocr_id_number, kv.photo_path, kv.document_path, kv.status
    FROM kyc_verification kv
    JOIN users u ON u.id = kv.user_id
    ORDER BY kv.user_id ASC
");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

printf("%-6s %-8s %-35s %-20s %-20s %-20s %-70s %-12s\n",
    "kv_id","user_id","email","submitted_name","ocr_name","ocr_id_number","photo_path","status");
echo str_repeat("-", 200) . "\n";
foreach ($rows as $r) {
    printf("%-6s %-8s %-35s %-20s %-20s %-20s %-70s %-12s\n",
        $r['id'], $r['user_id'], $r['email'],
        $r['submitted_name'] ?? 'NULL', $r['ocr_name'] ?? 'NULL',
        $r['ocr_id_number'] ?? 'NULL', $r['photo_path'] ?? 'NULL',
        $r['status'] ?? 'NULL');
}

echo "\n\n=== ekyc_submission.php photo_path logic trace ===\n";
echo "The ekyc_submission.php does: processKYCDocumentDetailed(uploadedFile, extractedDir, userId, fullName)\n";
echo "Then stores \$photoPath into kyc_verification.photo_path WHERE user_id = token's user_id\n\n";

echo "=== WHERE does ekyc_submission set photo_path? (check functions.php) ===\n";
// Check what processKYCDocumentDetailed does
$funcs = file_get_contents(__DIR__ . '/../includes/functions.php');
// Find photo_path / photo related logic
preg_match_all('/photo_path[^;]{0,100}/i', $funcs, $matches);
foreach ($matches[0] as $m) {
    echo trim($m) . "\n";
}

echo "\n=== Check: does processKYCDocumentDetailed embed user_id in filename? ===\n";
preg_match('/function processKYCDocumentDetailed.{0,3000}/s', $funcs, $m2);
if ($m2) {
    echo substr($m2[0], 0, 1500) . "...\n";
}

echo "\n=== Investor profiles ===\n";
$s2 = $pdo->query("SELECT user_id, full_name, dob, gender FROM investor_profiles ORDER BY user_id ASC");
foreach ($s2->fetchAll(PDO::FETCH_ASSOC) as $r) {
    printf("user_id=%-4s name=%-25s dob=%-12s gender=%s\n",
        $r['user_id'], $r['full_name'], $r['dob'] ?? 'NULL', $r['gender'] ?? 'NULL');
}
