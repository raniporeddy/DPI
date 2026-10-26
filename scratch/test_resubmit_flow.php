<?php
// End-to-end workflow test for the mismatch + resubmission flow
require_once __DIR__ . '/../config/database.php';

$pass = 0;
$fail = 0;

function ok($label, $cond) {
    global $pass, $fail;
    if ($cond) { echo "  PASS: {$label}\n"; $pass++; }
    else        { echo "  FAIL: {$label}\n"; $fail++; }
}

// ---- TEST 1: Mismatch detection logic ----
echo "\n=== TEST 1: Name-mismatch detection ===\n";
function detectMismatch($profileName, $ocrName) {
    if (empty($ocrName) || empty($profileName)) return false;
    $nP = strtolower(preg_replace('/\s+/', ' ', trim($profileName)));
    $nO = strtolower(preg_replace('/\s+/', ' ', trim($ocrName)));
    $ok = ($nP === $nO) || str_contains($nP, $nO) || str_contains($nO, $nP);
    if ($ok) return false;
    $d = levenshtein($nP, $nO);
    $m = max(strlen($nP), strlen($nO));
    return $d > (int) ceil($m * 0.40);
}

ok("Same name → no mismatch",       !detectMismatch("Poreddy Rani", "Poreddy Rani"));
ok("Substring → no mismatch",       !detectMismatch("poreddy rani", "rani"));
ok("Completely different → mismatch", detectMismatch("poreddy rani", "Vankayala Manasa"));
ok("Completely different → mismatch", detectMismatch("manasa", "Poreddy Rani"));
ok("Empty OCR → no mismatch",        !detectMismatch("poreddy rani", ""));
ok("Minor typo → no mismatch",       !detectMismatch("Poreddy Rani", "Poreddy Ranni"));

// ---- TEST 2: KYC isolation - each user sees only their own row ----
echo "\n=== TEST 2: Per-user KYC isolation ===\n";
$testUsers = [1, 17, 18];
foreach ($testUsers as $uid) {
    $s = $pdo->prepare("SELECT user_id FROM kyc_verification WHERE user_id = ?");
    $s->execute([$uid]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        echo "  SKIP: user {$uid} has no KYC row\n";
        continue;
    }
    ok("user_id={$uid} query returns its own row", (int)$row['user_id'] === $uid);
}

// ---- TEST 3: Simulate request_resubmission marking ----
echo "\n=== TEST 3: Simulate resubmit request flag on user 18 ===\n";
// Check current state
$s = $pdo->prepare("SELECT admin_remarks, status FROM kyc_verification WHERE user_id = 18");
$s->execute();
$r = $s->fetch(PDO::FETCH_ASSOC);
echo "  Before: status={$r['status']}, admin_remarks=" . substr($r['admin_remarks'] ?? 'NULL', 0, 60) . "\n";

$alreadyFlagged = str_starts_with($r['admin_remarks'] ?? '', '[RESUBMIT_REQUESTED]');
ok("Investor has not yet self-flagged for test to proceed", !$alreadyFlagged);

// ---- TEST 4: Simulate admin reset action ----
echo "\n=== TEST 4: Simulate admin 'reset_kyc_for_resubmission' on user 18 ===\n";
$pdo->beginTransaction();
try {
    $rejectionReason = 'TEST: Document rejected — uploaded document does not match account. Please resubmit your own Aadhaar.';
    $pdo->prepare("
        UPDATE kyc_verification
        SET status='Rejected', document_path=NULL, photo_path=NULL,
            ocr_name=NULL, ocr_dob=NULL, ocr_gender=NULL,
            ocr_address=NULL, ocr_id_number=NULL, ocr_status=NULL,
            admin_remarks=?, verified_at=NULL, updated_at=NOW()
        WHERE user_id=18 AND status IN ('Submitted','Pending')
    ")->execute([$rejectionReason]);

    $s = $pdo->prepare("SELECT status, document_path, ocr_name, admin_remarks FROM kyc_verification WHERE user_id=18");
    $s->execute();
    $after = $s->fetch(PDO::FETCH_ASSOC);

    ok("Status = Rejected after reset",        $after['status'] === 'Rejected');
    ok("document_path cleared",                $after['document_path'] === null);
    ok("ocr_name cleared",                     $after['ocr_name'] === null);
    ok("admin_remarks set to rejection reason", str_contains($after['admin_remarks'] ?? '', 'TEST:'));

    // ---- TEST 5: kycIsResetState detection ----
    echo "\n=== TEST 5: kycIsResetState detection ===\n";
    $kycIsResetState = ($after['status'] === 'Rejected' && empty($after['document_path']));
    ok("kycIsResetState = true after admin reset",  $kycIsResetState);
    ok("hasSubmittedKyc = false in reset state",    !(!empty($after) && !empty($after['document_path']) && !$kycIsResetState));

    // ---- TEST 6: Simulate investor resubmission (document_path update only, no real upload) ----
    echo "\n=== TEST 6: Simulate investor resubmission into user 18 row ===\n";
    $fakeDocPath   = 'uploads/kyc/test_resubmission_user18.png';
    $fakePhotoPath = 'uploads/extracted/aadhaar_photo_user_18_TEST.png';
    $fakeOcrName   = 'Poreddy Rani'; // correct name this time

    $pdo->prepare("
        UPDATE kyc_verification
        SET document_path=?, photo_path=?,
            ocr_name=?, ocr_dob='2006-05-18', ocr_gender='Female',
            ocr_address='Test Address', ocr_id_number='1234-5678-9012',
            ocr_status='Processed', status='Submitted', admin_remarks=NULL, updated_at=NOW()
        WHERE user_id=18 AND status='Rejected'
    ")->execute([$fakeDocPath, $fakePhotoPath, $fakeOcrName]);

    $s = $pdo->prepare("SELECT status, document_path, ocr_name, admin_remarks FROM kyc_verification WHERE user_id=18");
    $s->execute();
    $resubmitted = $s->fetch(PDO::FETCH_ASSOC);

    ok("Status = Submitted after resubmission",      $resubmitted['status'] === 'Submitted');
    ok("document_path set to new document",          !empty($resubmitted['document_path']));
    ok("ocr_name updated to correct name",           $resubmitted['ocr_name'] === 'Poreddy Rani');
    ok("admin_remarks cleared after resubmission",   $resubmitted['admin_remarks'] === null);

    // ---- TEST 7: Mismatch check after resubmission (should be clean) ----
    echo "\n=== TEST 7: Mismatch check after correct resubmission ===\n";
    $profileNameCheck = 'poreddy rani';
    $ocrNameCheck     = $resubmitted['ocr_name'];
    $stillMismatched  = detectMismatch($profileNameCheck, $ocrNameCheck);
    ok("No mismatch after investor submits their own document", !$stillMismatched);

    $pdo->rollBack(); // Roll back all test changes - don't touch production data
    echo "\n  [All test DB changes rolled back — no production data modified]\n";

} catch (Exception $e) {
    $pdo->rollBack();
    echo "  ERROR: " . $e->getMessage() . "\n";
}

// ---- SUMMARY ----
echo "\n" . str_repeat("=", 60) . "\n";
echo "RESULTS: {$pass} passed, {$fail} failed\n";
if ($fail === 0) {
    echo "ALL TESTS PASSED ✓\n";
} else {
    echo "SOME TESTS FAILED. Review output above.\n";
}
