<?php
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';

$users = [1, 17, 18];
foreach ($users as $uid) {
    $s = $pdo->prepare("
        SELECT k.user_id, k.status, k.document_path, k.photo_path,
               k.ocr_name, k.ocr_dob, k.ocr_gender, k.id_number,
               k.full_name, k.admin_remarks,
               p.full_name as profile_name
        FROM kyc_verification k
        LEFT JOIN investor_profiles p ON k.user_id = p.user_id
        WHERE k.user_id = ?
    ");
    $s->execute([$uid]);
    $r = $s->fetch(PDO::FETCH_ASSOC);

    if (!$r) {
        echo "user $uid : NO KYC ROW\n";
        continue;
    }

    $projectRoot = dirname(__DIR__);
    $photoAbsPath = !empty($r['photo_path'])
        ? $projectRoot . '/' . ltrim($r['photo_path'], '/')
        : null;
    $hasPhoto = $photoAbsPath && file_exists($photoAbsPath);
    $photoFile = !empty($r['photo_path']) ? basename($r['photo_path']) : 'NULL';

    $docAbsPath = !empty($r['document_path'])
        ? $projectRoot . '/' . ltrim($r['document_path'], '/')
        : null;
    $hasDoc = $docAbsPath && file_exists($docAbsPath);

    $profileName = $r['profile_name'] ?? '?';
    $ocrName     = $r['ocr_name'] ?? 'NULL';
    $kycIsReset  = ($r['status'] === 'Rejected' && empty($r['document_path']));
    $remarks     = substr($r['admin_remarks'] ?? 'NULL', 0, 60);

    echo "user {$uid} ({$profileName}):\n";
    echo "  status={$r['status']}  reset_state=" . ($kycIsReset ? 'YES' : 'NO') . "\n";
    echo "  document_path=" . ($r['document_path'] ?? 'NULL') . "  exists=" . ($hasDoc ? 'YES' : 'NO') . "\n";
    echo "  photo_path=$photoFile  exists=" . ($hasPhoto ? 'YES' : 'NO') . "\n";
    echo "  ocr_name=$ocrName\n";
    echo "  profile_name=$profileName\n";
    echo "  admin_remarks=$remarks\n";

    // Check mismatch exactly as ekyc.php does
    $nameMismatch = false;
    if (!empty($ocrName) && !empty($profileName)) {
        $nP = strtolower(preg_replace('/\s+/', ' ', $profileName));
        $nO = strtolower(preg_replace('/\s+/', ' ', $ocrName));
        $ok = ($nP === $nO) || str_contains($nP, $nO) || str_contains($nO, $nP);
        if (!$ok) {
            $d = levenshtein($nP, $nO);
            $m = max(strlen($nP), strlen($nO));
            if ($d > (int) ceil($m * 0.40)) $nameMismatch = true;
        }
    }
    echo "  mismatch=" . ($nameMismatch ? 'YES' : 'NO') . "\n";
    echo "  would_show_details=" . (!$kycIsReset && !empty($r['document_path']) ? 'YES (after pw unlock)' : 'NO (reset/no doc)') . "\n";
    echo "\n";
}
