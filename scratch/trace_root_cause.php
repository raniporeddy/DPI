<?php
// Root cause trace: why does user 18 (poreddyrani268@gmail.com / "poreddy rani") 
// see "Vankayala Manasa" OCR data + a photo that appears to be Manasa's?
//
// This script:
//  1. Shows exactly what's in kv row for user 18
//  2. Shows the photo file sizes (to detect if photo belongs to different person)
//  3. Checks whether any other account shares the same document
//  4. Confirms the actual query ekyc.php uses returns only user_id=18 rows

require_once __DIR__ . '/../config/database.php';

// ===== 1. What does ekyc.php ACTUALLY fetch for each logged-in user? =====
echo "=== Simulated ekyc.php fetch for each user ===\n";
$stmt = $pdo->query("
    SELECT kv.user_id, u.email, kv.full_name, kv.ocr_name, kv.ocr_gender,
           kv.ocr_id_number, kv.ocr_address, kv.photo_path, kv.document_path, kv.status
    FROM kyc_verification kv
    JOIN users u ON u.id = kv.user_id
    ORDER BY kv.user_id ASC
");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "user_id={$r['user_id']} ({$r['email']})\n";
    echo "  submitted name: {$r['full_name']}\n";
    echo "  ocr_name      : " . ($r['ocr_name'] ?? 'NULL') . "\n";
    echo "  ocr_gender    : " . ($r['ocr_gender'] ?? 'NULL') . "\n";
    echo "  ocr_id_number : " . ($r['ocr_id_number'] ?? 'NULL') . "\n";
    $photoFile = __DIR__ . '/../' . ($r['photo_path'] ?? '');
    $photoSize = file_exists($photoFile) ? filesize($photoFile) : 0;
    echo "  photo_path    : " . ($r['photo_path'] ?? 'NULL') . " [{$photoSize} bytes]\n";
    echo "  doc_path      : " . ($r['document_path'] ?? 'NULL') . "\n";
    echo "\n";
}

// ===== 2. KEY: Does ekyc.php query filter by user_id? Let's simulate user 18 =====
echo "=== Direct query for user_id=18 (as ekyc.php does) ===\n";
$s = $pdo->prepare("SELECT * FROM kyc_verification WHERE user_id = ?");
$s->execute([18]);
$row = $s->fetch(PDO::FETCH_ASSOC);
echo "ocr_name    : " . ($row['ocr_name'] ?? 'NULL') . "\n";
echo "ocr_gender  : " . ($row['ocr_gender'] ?? 'NULL') . "\n";
echo "ocr_dob     : " . ($row['ocr_dob'] ?? 'NULL') . "\n";
echo "ocr_address : " . ($row['ocr_address'] ?? 'NULL') . "\n";
echo "ocr_id_number: " . ($row['ocr_id_number'] ?? 'NULL') . "\n";
echo "photo_path  : " . ($row['photo_path'] ?? 'NULL') . "\n";
$photoPath18 = __DIR__ . '/../' . ($row['photo_path'] ?? '');
echo "photo exists: " . (file_exists($photoPath18) ? 'YES (' . filesize($photoPath18) . ' bytes)' : 'NO') . "\n";

// ===== 3. Who has photo_path matching the user_17 or user_18 images? =====
echo "\n=== Cross-check: is user 17's photo_path stored in any other row? ===\n";
$s2 = $pdo->query("SELECT user_id, photo_path FROM kyc_verification WHERE photo_path LIKE '%user_17%' OR photo_path LIKE '%user_18%'");
foreach ($s2->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "user_id={$r['user_id']} | photo_path={$r['photo_path']}\n";
}

// ===== 4. Does the user 18 photo file actually exist and match expected size? =====
echo "\n=== All extracted photos for user 18 (last modified) ===\n";
$files = glob(__DIR__ . '/../uploads/extracted/aadhaar_photo_user_18_*.png');
usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
foreach (array_slice($files, 0, 5) as $f) {
    echo basename($f) . " | " . filesize($f) . " bytes | " . date('Y-m-d H:i:s', filemtime($f)) . "\n";
}

// ===== 5. Root cause theory: did OCR script mix up user IDs? =====
// The python script is called as: python script.py uploadedFile extractedDir userId
// Check if the photo stored in kv for user 18 is actually named user_18 (correct) or user_17 (cross-contamination)
echo "\n=== VERDICT ===\n";
if ($row) {
    $photoBasename = basename($row['photo_path'] ?? '');
    if (strpos($photoBasename, 'user_18') !== false) {
        echo "Photo filename contains 'user_18' - FILENAME IS CORRECT for user 18.\n";
        echo "Root cause must be that the OCR script processed user 17's Aadhaar card for user 18's submission,\n";
        echo "OR user 18 uploaded an Aadhaar card that belongs to Vankayala Manasa.\n";
        echo "The DB record for user 18 correctly belongs to user 18 (user_id=18 in WHERE clause).\n";
        echo "The OCR text extracted from the uploaded document contains Manasa's details - the document uploaded WAS Manasa's card.\n";
    } elseif (strpos($photoBasename, 'user_17') !== false) {
        echo "CRITICAL BUG: Photo filename contains 'user_17' but is stored in user 18's row!\n";
        echo "Root cause: OCR script or photo extraction used wrong user_id in filename.\n";
    } else {
        echo "Photo basename: $photoBasename\n";
    }
}

// ===== 6. Check ekyc.php for any missing WHERE clause or ORDER BY that could cause wrong row =====
echo "\n=== ekyc.php SQL safety check ===\n";
$ekycContent = file_get_contents(__DIR__ . '/../investor/ekyc.php');
preg_match_all('/SELECT .+FROM kyc_verification.*/i', $ekycContent, $matches);
foreach ($matches[0] as $m) {
    echo trim($m) . "\n";
}
// Check if WHERE user_id = ? is used
if (preg_match('/WHERE user_id = \?/', $ekycContent)) {
    echo "-> ekyc.php DOES use WHERE user_id = ? (parameterized) - CORRECT\n";
} else {
    echo "-> WARNING: ekyc.php does NOT filter by user_id!\n";
}
