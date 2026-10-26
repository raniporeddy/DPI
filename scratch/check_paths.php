<?php
require __DIR__ . '/../config/database.php';

$root = dirname(__DIR__);

// Check user 1 photo_path storage format
$s = $pdo->prepare("SELECT photo_path, document_path, status FROM kyc_verification WHERE user_id = 1");
$s->execute();
$r = $s->fetch();
echo "=== USER 1 PHOTO PATH ===\n";
echo "Raw DB value:     " . var_export($r['photo_path'], true) . "\n";
// ekyc.php checks: file_exists(dirname(__DIR__) . '/' . $rawPhotoPath)
// dirname(__DIR__) from investor/ = project root
$checkPath1 = $root . '/digital-investor/' . $r['photo_path'];
$checkPath2 = $root . '/digital-investor/uploads/extracted/' . basename($r['photo_path']);
echo "Check as-stored ($checkPath1):\n  exists=" . (file_exists($checkPath1) ? 'YES' : 'NO') . "\n";
echo "Check with uploads/extracted/ ($checkPath2):\n  exists=" . (file_exists($checkPath2) ? 'YES' : 'NO') . "\n";

// The __DIR__ inside investor/ekyc.php is: {project}/digital-investor/investor
// dirname(__DIR__) = {project}/digital-investor
$ekycDir = $root . '/digital-investor/investor';
$ekycParent = dirname($ekycDir);   // = {project}/digital-investor
$check3 = $ekycParent . '/' . $r['photo_path'];
echo "From investor/ekyc.php perspective ($check3):\n  exists=" . (file_exists($check3) ? 'YES' : 'NO') . "\n";

echo "\n=== USER 18 STATE ===\n";
$s = $pdo->prepare("SELECT status, document_path, photo_path, ocr_name, ocr_dob, full_name, admin_remarks FROM kyc_verification WHERE user_id = 18");
$s->execute();
$r = $s->fetch();
echo "status=" . $r['status'] . "\n";
echo "document_path=" . var_export($r['document_path'], true) . "\n";
echo "photo_path=" . var_export($r['photo_path'], true) . "\n";
echo "ocr_name=" . var_export($r['ocr_name'], true) . "\n";
echo "admin_remarks=" . substr($r['admin_remarks'] ?? '', 0, 80) . "\n";
$kycIsResetState = ($r['status'] === 'Rejected' && empty($r['document_path']));
$hasSubmittedKyc = (!empty($r) && !empty($r['document_path']) && !$kycIsResetState);
echo "kycIsResetState=$" . ($kycIsResetState ? 'true' : 'false') . "\n";
echo "hasSubmittedKyc=$" . ($hasSubmittedKyc ? 'true' : 'false') . "\n";
echo "=> Page shows: " . (!$hasSubmittedKyc ? ($kycIsResetState ? 'RESUBMIT FORM' : 'NO DOCUMENT STATE') : 'DETAILS (after pw unlock)') . "\n";
