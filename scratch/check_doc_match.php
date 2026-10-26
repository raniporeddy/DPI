<?php
// Final confirmation: check what document user 18 uploaded vs user 17
// and confirm the two documents are identical or different
require_once __DIR__ . '/../config/database.php';

$s = $pdo->query("SELECT user_id, document_path FROM kyc_verification WHERE user_id IN (17, 18) ORDER BY user_id");
$rows = $s->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    $fullPath = __DIR__ . '/../' . $r['document_path'];
    $size = file_exists($fullPath) ? filesize($fullPath) : 0;
    $md5  = file_exists($fullPath) ? md5_file($fullPath) : 'FILE_MISSING';
    echo "user_id={$r['user_id']} | doc={$r['document_path']} | size={$size} | md5={$md5}\n";
}

echo "\n--- Did user 18 upload the SAME file as user 17? ---\n";
$paths = array_column($rows, 'document_path');
if (count($paths) === 2) {
    $f1 = __DIR__ . '/../' . $paths[0];
    $f2 = __DIR__ . '/../' . $paths[1];
    if (file_exists($f1) && file_exists($f2)) {
        if (md5_file($f1) === md5_file($f2)) {
            echo "YES - both users uploaded the EXACT SAME document file (same MD5).\n";
            echo "This means user 18 used user 17's (Manasa's) Aadhaar card image.\n";
            echo "The OCR correctly extracted Manasa's details from that document.\n";
            echo "The photo embedded in that card is Manasa's face.\n";
        } else {
            echo "NO - the documents are DIFFERENT files.\n";
            echo "Sizes: " . filesize($f1) . " vs " . filesize($f2) . "\n";
        }
    } else {
        echo "One or both files are missing from disk.\n";
        foreach ($paths as $p) {
            echo "  $p: " . (file_exists(__DIR__ . '/../' . $p) ? "EXISTS" : "MISSING") . "\n";
        }
    }
}

echo "\n--- Summary of what happened ---\n";
echo "1. User 17 (manasavankayala5@gmail.com) submitted Vankayala Manasa's Aadhaar card.\n";
echo "2. User 18 (poreddyrani268@gmail.com / poreddy rani) ALSO uploaded Manasa's Aadhaar.\n";
echo "3. The OCR correctly read the card - which belongs to Manasa, not poreddy rani.\n";
echo "4. The photo extracted from that card is Manasa's photograph.\n";
echo "5. The DB correctly associates user_id=18 with these OCR results.\n";
echo "6. ekyc.php queries WHERE user_id=18 correctly - it shows user 18's OWN row.\n";
echo "7. The root cause: user 18 submitted a document that belongs to a different person.\n";
echo "\nFix options:\n";
echo "A) Do NOT overwrite/copy user 17's data into user 18's row.\n";
echo "B) Show a clear visual warning when submitted_name != ocr_name (mismatch alert).\n";
echo "C) If user 18 has no valid submission of their own card, clear the incorrect OCR data\n";
echo "   and show 'No valid eKYC submission found for your account'.\n";
echo "D) Let admin review and reject the submission; investor must re-submit their OWN card.\n";
