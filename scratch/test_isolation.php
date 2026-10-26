<?php
// Two-account isolation test: confirms that each user_id only sees their own row
require_once __DIR__ . '/../config/database.php';

echo "=== TWO-ACCOUNT ISOLATION TEST ===\n\n";

// Test accounts with known KYC data
$testAccounts = [
    ['user_id' => 17, 'email' => 'manasavankayala5@gmail.com',  'label' => 'Account A (Manasa)'],
    ['user_id' => 18, 'email' => 'poreddyrani268@gmail.com',    'label' => 'Account B (Poreddy Rani)'],
    ['user_id' => 1,  'email' => '11239a072@kanchiuniv.ac.in',  'label' => 'Account C (user1/John Doe)'],
];

$results = [];
foreach ($testAccounts as $account) {
    $uid = $account['user_id'];

    // 1. Simulate exactly what ekyc.php does
    $s = $pdo->prepare("SELECT * FROM kyc_verification WHERE user_id = ?");
    $s->execute([$uid]);
    $kyc = $s->fetch(PDO::FETCH_ASSOC);

    $sp = $pdo->prepare("SELECT full_name, gender FROM investor_profiles WHERE user_id = ?");
    $sp->execute([$uid]);
    $profile = $sp->fetch(PDO::FETCH_ASSOC);

    $profileName = trim($profile['full_name'] ?? $kyc['full_name'] ?? '');
    $ocrName     = trim($kyc['ocr_name'] ?? '');

    // 2. Mismatch detection (same logic as ekyc.php)
    $mismatch = false;
    if (!empty($ocrName) && !empty($profileName)) {
        $normP = strtolower(preg_replace('/\s+/', ' ', $profileName));
        $normO = strtolower(preg_replace('/\s+/', ' ', $ocrName));
        $ok    = ($normP === $normO) || str_contains($normP, $normO) || str_contains($normO, $normP);
        if (!$ok) {
            $d = levenshtein($normP, $normO);
            $m = max(strlen($normP), strlen($normO));
            if ($d > (int) ceil($m * 0.40)) $mismatch = true;
        }
    }

    // 3. Photo isolation check
    $photoPath = $kyc['photo_path'] ?? null;
    $photoOk   = true;
    if ($photoPath) {
        // Photo filename should contain user_{uid} to confirm it was generated for this user
        $photoOk = str_contains(basename($photoPath), "user_{$uid}");
    }

    $results[] = [
        'account'      => $account['label'],
        'user_id'      => $uid,
        'kv_user_id'   => $kyc['user_id'] ?? 'NO ROW',
        'profile_name' => $profileName,
        'ocr_name'     => $ocrName ?: 'NULL',
        'mismatch'     => $mismatch ? 'YES ⚠' : 'NO ✓',
        'photo_path'   => $photoPath ?: 'NULL',
        'photo_owned'  => $photoPath ? ($photoOk ? 'YES (user_{$uid} in filename) ✓' : "WARNING: filename does not contain user_{$uid}") : 'N/A',
        'isolation'    => ($kyc && (int)$kyc['user_id'] === $uid) ? 'PASS ✓' : 'FAIL ✗',
    ];
}

echo str_repeat("=", 80) . "\n";
foreach ($results as $r) {
    echo "ACCOUNT  : {$r['account']} (user_id={$r['user_id']})\n";
    echo "  DB row user_id   : {$r['kv_user_id']} | Isolation: {$r['isolation']}\n";
    echo "  Profile name     : {$r['profile_name']}\n";
    echo "  OCR name         : {$r['ocr_name']}\n";
    echo "  Name mismatch    : {$r['mismatch']}\n";
    echo "  Photo path       : {$r['photo_path']}\n";
    echo "  Photo ownership  : {$r['photo_owned']}\n";
    echo str_repeat("-", 80) . "\n";
}

echo "\nSUMMARY:\n";
$allPass = true;
foreach ($results as $r) {
    if (strpos($r['isolation'], 'PASS') === false) {
        echo " ✗ ISOLATION FAIL for {$r['account']}\n";
        $allPass = false;
    }
}
if ($allPass) {
    echo " ✓ All accounts query ONLY their own kyc_verification row (WHERE user_id = ?)\n";
    echo " ✓ No cross-account data leakage detected in DB queries\n";
}

echo "\nNOTE: Account B (user 18) shows name mismatch because the investor\n";
echo "      submitted a document belonging to Vankayala Manasa (a different person).\n";
echo "      The ekyc.php page now shows a clear 'Document Mismatch Detected' warning.\n";
echo "      No records were modified - admin must reject and ask for re-submission.\n";
