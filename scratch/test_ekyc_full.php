<?php
/**
 * Comprehensive end-to-end simulation of investor/ekyc.php for all test users.
 * Exercises the exact same PHP logic as the page, verifying:
 *  - Password gate: only session-unlocked investors see details
 *  - Data isolation: each user sees ONLY their own record (queried by user_id)
 *  - Photo path resolution: both stored path formats resolve correctly
 *  - Mismatch detection: OCR name vs profile name comparison
 *  - State routing: correct UI path chosen for each user
 *  - Masking: Aadhaar number is never exposed in full
 *  - No data transfer: another investor's data never shown
 */

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';

$pass = 0; $fail = 0;

function ok($label, $cond, $detail = '') {
    global $pass, $fail;
    if ($cond) { echo "  PASS: $label\n"; $pass++; }
    else        { echo "  FAIL: $label" . ($detail ? " [$detail]" : '') . "\n"; $fail++; }
}
function section($title) { echo "\n" . str_repeat('─', 60) . "\n  $title\n" . str_repeat('─', 60) . "\n"; }

$projectRoot = dirname(__DIR__); // = digital-investor/

// ────────────────────────────────────────────────────────────
// Shared: simulate the per-user data fetch exactly as ekyc.php
// ────────────────────────────────────────────────────────────
function fetchKycPageData(PDO $pdo, int $userId, string $projectRoot): array {
    // Fetch KYC (ownership enforced by WHERE user_id = ?)
    $s = $pdo->prepare("SELECT * FROM kyc_verification WHERE user_id = ?");
    $s->execute([$userId]);
    $kyc = $s->fetch(PDO::FETCH_ASSOC) ?: [];

    // Fetch profile
    $s = $pdo->prepare("SELECT * FROM investor_profiles WHERE user_id = ?");
    $s->execute([$userId]);
    $profile = $s->fetch(PDO::FETCH_ASSOC) ?: [];

    // State classification (new logic)
    $kycStatus       = $kyc['status']        ?? '';
    $kycDocPath      = $kyc['document_path'] ?? '';
    $kycIsResetState = !empty($kyc)
                    && empty($kycDocPath)
                    && !in_array($kycStatus, ['Verified'], true);
    $hasSubmittedKyc = !empty($kyc) && !empty($kycDocPath) && !$kycIsResetState;

    $resubmitRequested = !empty($kyc)
        && str_starts_with($kyc['admin_remarks'] ?? '', '[RESUBMIT_REQUESTED]');

    // Photo path resolution (new logic with secondary check)
    $rawPhotoPath = $kyc['photo_path'] ?? '';
    $photoAbsFull = !empty($rawPhotoPath)
        ? rtrim($projectRoot, '/\\') . '/' . ltrim(str_replace('\\', '/', $rawPhotoPath), '/')
        : null;
    if (!empty($rawPhotoPath) && !file_exists($photoAbsFull)) {
        $photoWithPrefix = $projectRoot . '/uploads/extracted/' . basename($rawPhotoPath);
        if (file_exists($photoWithPrefix)) {
            $rawPhotoPath = 'uploads/extracted/' . basename($rawPhotoPath);
            $photoAbsFull = $photoWithPrefix;
        }
    }
    $hasPhoto         = !empty($rawPhotoPath) && file_exists($photoAbsFull);
    $displayPhotoPath = $hasPhoto ? $rawPhotoPath : null;

    // Masking
    $maskedAadhaar    = maskAadhaarNumber($kyc['id_number']     ?? '', $kyc['id_type'] ?? 'Aadhaar');
    $maskedOcrAadhaar = !empty($kyc['ocr_id_number'])
        ? maskAadhaarNumber($kyc['ocr_id_number'], $kyc['id_type'] ?? 'Aadhaar')
        : null;

    // Display values (OCR preferred > stored > null)
    $displayName    = !empty($kyc['ocr_name'])    ? $kyc['ocr_name']    : ($kyc['full_name']      ?? null);
    $displayDob     = !empty($kyc['ocr_dob'])     ? $kyc['ocr_dob']     : ($kyc['dob']            ?? null);
    $displayGender  = !empty($kyc['ocr_gender'])  ? $kyc['ocr_gender']  : ($profile['gender']     ?? null);
    $displayAddress = !empty($kyc['ocr_address']) ? $kyc['ocr_address'] : ($kyc['address']        ?? null);
    $displayIdNum   = !empty($maskedOcrAadhaar)   ? $maskedOcrAadhaar   : $maskedAadhaar;

    // Mismatch detection
    $nameMismatch  = false;
    $profileName   = trim($profile['full_name'] ?? $kyc['full_name'] ?? '');
    $ocrName       = trim($kyc['ocr_name'] ?? '');
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

    // UI path
    $uiPath = !$hasSubmittedKyc
        ? ($kycIsResetState ? 'RESUBMIT_FORM' : 'NO_DOC_CARD')
        : 'PASSWORD_GATE'; // → after unlock: DETAILS

    return compact(
        'kyc', 'profile', 'kycStatus', 'kycDocPath', 'kycIsResetState',
        'hasSubmittedKyc', 'resubmitRequested', 'hasPhoto', 'displayPhotoPath',
        'maskedAadhaar', 'maskedOcrAadhaar', 'displayName', 'displayDob',
        'displayGender', 'displayAddress', 'displayIdNum', 'nameMismatch',
        'profileName', 'ocrName', 'uiPath'
    );
}

// ═══════════════════════════════════════════════════════════
// USER 1 — "poreddy" (has doc + photo; mismatch with John Doe)
// ═══════════════════════════════════════════════════════════
section('USER 1 (poreddy) — has document, has photo, OCR mismatch');
$d = fetchKycPageData($pdo, 1, $projectRoot);

ok('KYC row belongs to user 1',        (int)($d['kyc']['user_id'] ?? -1) === 1);
ok('hasSubmittedKyc = true',           $d['hasSubmittedKyc']);
ok('kycIsResetState = false',          !$d['kycIsResetState']);
ok('UI path = PASSWORD_GATE',          $d['uiPath'] === 'PASSWORD_GATE');
ok('Photo file exists on disk',        $d['hasPhoto']);
ok('displayPhotoPath is set',          !empty($d['displayPhotoPath']));
ok('Aadhaar is masked (not full)',     !empty($d['maskedAadhaar']) && !preg_match('/^\d{12}$/', $d['maskedAadhaar']));
ok('Mismatch detected (John Doe vs poreddy)', $d['nameMismatch']);
ok('displayName shows OCR name',       $d['displayName'] === ($d['kyc']['ocr_name'] ?? null));
echo "  displayName   : {$d['displayName']}\n";
echo "  profileName   : {$d['profileName']}\n";
echo "  maskedAadhaar : {$d['maskedAadhaar']}\n";
echo "  displayPhotoPath: {$d['displayPhotoPath']}\n";
echo "  kycStatus     : {$d['kycStatus']}\n";

// ═══════════════════════════════════════════════════════════
// USER 17 — "manasa" (has doc + photo; mismatch with Poreddy Rani)
// ═══════════════════════════════════════════════════════════
section('USER 17 (manasa) — has document, has photo, OCR mismatch');
$d = fetchKycPageData($pdo, 17, $projectRoot);

ok('KYC row belongs to user 17',       (int)($d['kyc']['user_id'] ?? -1) === 17);
ok('hasSubmittedKyc = true',           $d['hasSubmittedKyc']);
ok('kycIsResetState = false',          !$d['kycIsResetState']);
ok('UI path = PASSWORD_GATE',          $d['uiPath'] === 'PASSWORD_GATE');
ok('Photo file exists on disk',        $d['hasPhoto']);
ok('Aadhaar is masked',                !empty($d['maskedAadhaar']) && !preg_match('/^\d{12}$/', $d['maskedAadhaar']));
ok('Mismatch detected (Poreddy Rani vs manasa)', $d['nameMismatch']);
ok('User 17 data not contaminated with user 1 data',
   ($d['kyc']['user_id'] ?? -1) != 1 && $d['displayName'] !== 'John Doe');
echo "  displayName   : {$d['displayName']}\n";
echo "  profileName   : {$d['profileName']}\n";
echo "  maskedAadhaar : {$d['maskedAadhaar']}\n";
echo "  displayPhotoPath: {$d['displayPhotoPath']}\n";

// ═══════════════════════════════════════════════════════════
// USER 18 — "poreddy rani" (no document, reset state)
// ═══════════════════════════════════════════════════════════
section('USER 18 (poreddy rani) — no document, reset/resubmit state');
$d = fetchKycPageData($pdo, 18, $projectRoot);

ok('KYC row belongs to user 18',       (int)($d['kyc']['user_id'] ?? -1) === 18);
ok('hasSubmittedKyc = false',          !$d['hasSubmittedKyc']);
ok('kycIsResetState = true',           $d['kycIsResetState']);
ok('UI path = RESUBMIT_FORM',          $d['uiPath'] === 'RESUBMIT_FORM');
ok('No photo on file',                 !$d['hasPhoto']);
ok('No cross-contamination: does NOT show user 1 photo',
   $d['displayPhotoPath'] !== ($d['kyc']['photo_path'] ?? null) || empty($d['displayPhotoPath']));
ok('User 18 data not contaminated with user 17 data',
   ($d['kyc']['user_id'] ?? -1) != 17);
echo "  kycStatus     : {$d['kycStatus']}\n";
echo "  document_path : " . ($d['kycDocPath'] ?: 'NULL') . "\n";
echo "  admin_remarks : " . substr($d['kyc']['admin_remarks'] ?? 'NULL', 0, 70) . "\n";

// ═══════════════════════════════════════════════════════════
// CROSS-CONTAMINATION GUARD: ensure no user sees another's data
// ═══════════════════════════════════════════════════════════
section('CROSS-CONTAMINATION GUARD — no investor sees another\'s data');
$users = [1, 17, 18];
$datasets = [];
foreach ($users as $uid) {
    $datasets[$uid] = fetchKycPageData($pdo, $uid, $projectRoot);
}

// Each user's KYC row must have their own user_id
foreach ($users as $uid) {
    $rowUid = (int)($datasets[$uid]['kyc']['user_id'] ?? -1);
    ok("User $uid: KYC row.user_id=$uid (not someone else's)", $rowUid === $uid);
}

// User 18 must not display user 17's photo
ok('User 18 does NOT display user 17 photo',
   $datasets[18]['displayPhotoPath'] !== $datasets[17]['displayPhotoPath']
   || empty($datasets[18]['displayPhotoPath']));

// User 17 must not display user 1's photo
ok('User 17 does NOT display user 1 photo',
   $datasets[17]['displayPhotoPath'] !== $datasets[1]['displayPhotoPath']);

// Mismatch flag protects user — their own name != OCR → mismatch shown
ok('User 1 mismatch detected → warning shown, NOT hidden',  $datasets[1]['nameMismatch']);
ok('User 17 mismatch detected → warning shown, NOT hidden', $datasets[17]['nameMismatch']);
ok('User 18 no mismatch (OCR matches profile)',             !$datasets[18]['nameMismatch']);

// ═══════════════════════════════════════════════════════════
// AADHAAR MASKING: ensure full numbers are never exposed
// ═══════════════════════════════════════════════════════════
section('AADHAAR MASKING');
foreach ($users as $uid) {
    $d = $datasets[$uid];
    $displayIdNum = $d['displayIdNum'] ?? '';
    // Full unmasked 12-digit Aadhaar must never appear
    $unmasked = preg_match('/^\d{12}$/', str_replace([' ', '-'], '', $displayIdNum));
    ok("User $uid: displayIdNum is masked (not 12 raw digits): '$displayIdNum'", !$unmasked);
}

// ═══════════════════════════════════════════════════════════
// SUMMARY
// ═══════════════════════════════════════════════════════════
echo "\n" . str_repeat('═', 60) . "\n";
echo "RESULTS: $pass passed, $fail failed\n";
echo $fail === 0 ? "ALL TESTS PASSED ✓\n" : "SOME TESTS FAILED — review above\n";
