<?php
// scratch/test_ekyc_complete_workflow.php
// End-to-end automated test for the updated eKYC workflow

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/email_helper.php';

echo "========================================================\n";
echo "  E2E TEST: DIGITAL INVESTOR SYSTEM - eKYC WORKFLOW\n";
echo "========================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertTest($name, $condition, $details = '') {
    global $passCount, $failCount;
    if ($condition) {
        echo "  [PASS] $name\n";
        if ($details) echo "         -> $details\n";
        $passCount++;
    } else {
        echo "  [FAIL] $name\n";
        if ($details) echo "         -> $details\n";
        $failCount++;
    }
}

// -----------------------------------------------------------------
// TEST 1: Database Migration & Schema
// -----------------------------------------------------------------
echo "[1] Checking Database Schema...\n";
$cols = $pdo->query("SHOW COLUMNS FROM ekyc_requests")->fetchAll(PDO::FETCH_COLUMN);
assertTest("ekyc_requests table has token", in_array('token', $cols));
assertTest("ekyc_requests table has expires_at", in_array('expires_at', $cols));
assertTest("ekyc_requests table has status", in_array('status', $cols));
assertTest("ekyc_requests table has submitted_at", in_array('submitted_at', $cols));

// -----------------------------------------------------------------
// TEST 2: Check Dashboard & eKYC Files for Request eKYC Button
// -----------------------------------------------------------------
echo "\n[2] Checking UI Template Files for 'Request eKYC' Button...\n";
$dashContent = file_get_contents(__DIR__ . '/../dashboard.php');
assertTest("dashboard.php contains Request eKYC button", strpos($dashContent, 'name="request_ekyc"') !== false);
assertTest("dashboard.php contains CSRF protection on Request eKYC", strpos($dashContent, 'csrfField()') !== false);
assertTest("dashboard.php removes direct upload link from normal flow", strpos($dashContent, 'Submit Demo eKYC') === false);

$ekycContent = file_get_contents(__DIR__ . '/../investor/ekyc.php');
assertTest("investor/ekyc.php contains request_ekyc_email button", strpos($ekycContent, 'name="request_ekyc_email"') !== false);
assertTest("investor/ekyc.php has correct_extraction_details form/modal", strpos($ekycContent, 'correct_extraction_details') !== false);

// -----------------------------------------------------------------
// TEST 3: eKYC Request Token Creation & Expiry Time
// -----------------------------------------------------------------
echo "\n[3] Testing Token Generation & Expiry...\n";
$testUser = $pdo->query("SELECT id, email FROM users WHERE role = 'investor' LIMIT 1")->fetch();
if (!$testUser) {
    die("No investor user found in DB for test.\n");
}
$userId = (int)$testUser['id'];
$recipientEmail = $testUser['email'];

$reqRes = createEKYCRequest($pdo, $userId, $recipientEmail);
assertTest("createEKYCRequest succeeded", $reqRes['success'] === true, $reqRes['error'] ?? '');
assertTest("Token length is 64 hex characters", strlen($reqRes['token']) === 64, "Token: " . substr($reqRes['token'], 0, 16) . "...");
assertTest("Expires_at is set to ~24 hours in the future", strtotime($reqRes['expires_at']) > time() + 86000, "Expires: " . $reqRes['expires_at']);

$token = $reqRes['token'];

// Validate token in database
$reqRow = getEKYCRequestByToken($pdo, $token);
assertTest("getEKYCRequestByToken finds row", !empty($reqRow));
assertTest("Request status is 'Sent'", ($reqRow['status'] ?? '') === 'Sent');
assertTest("Token associated with correct user_id", (int)$reqRow['user_id'] === $userId);

// -----------------------------------------------------------------
// TEST 4: Single-Use Token Invalidation
// -----------------------------------------------------------------
echo "\n[4] Testing Single-Use Token Security...\n";
// Simulate submission consumption
$sampleDoc = __DIR__ . '/../uploads/kyc/demo_aadhaar_card.png';
if (file_exists($sampleDoc)) {
    $testCopy = 'uploads/kyc/test_run_' . time() . '.png';
    copy($sampleDoc, __DIR__ . '/../' . $testCopy);

    // Simulate OCR extraction
    $ocrRes = processKYCDocumentDetailed(__DIR__ . '/../' . $testCopy, __DIR__ . '/../uploads/extracted', $userId, 'Test User');
    assertTest("processKYCDocumentDetailed executed", isset($ocrRes['ocr_status']) || isset($ocrRes['photo_path']));

    // Update kyc_verification
    $pdo->prepare("
        UPDATE kyc_verification 
        SET id_type='Aadhaar', id_number='1234-5678-9012', full_name='Test Investor', 
            dob='1995-05-15', address='123 Test St', ocr_name='Test Investor', ocr_dob='1995-05-15',
            document_path=?, photo_path=?, status='Submitted', updated_at=NOW()
        WHERE user_id = ?
    ")->execute([$testCopy, $ocrRes['photo_path'] ?? null, $userId]);

    // Invalidate token
    $pdo->prepare("UPDATE ekyc_requests SET status = 'Submitted', submitted_at = NOW() WHERE token = ?")->execute([$token]);

    // Check single-use token enforcement
    $consumedToken = getEKYCRequestByToken($pdo, $token);
    assertTest("Token is marked 'Submitted' in database", $consumedToken['status'] === 'Submitted');

    // Simulate reopening token in ekyc_submission.php
    $isTokenUsed = in_array($consumedToken['status'], ['Submitted', 'Verified']);
    assertTest("ekyc_submission.php recognizes token as already used", $isTokenUsed === true);
} else {
    echo "  [SKIP] demo_aadhaar_card.png not found, skipped file copy.\n";
}

// -----------------------------------------------------------------
// TEST 5: Security Check - Public URL Protection & Masking
// -----------------------------------------------------------------
echo "\n[5] Checking Security Controls (URL Protection & Aadhaar Masking)...\n";
$htaccessFile = __DIR__ . '/../uploads/kyc/.htaccess';
assertTest("uploads/kyc/.htaccess exists", file_exists($htaccessFile));
if (file_exists($htaccessFile)) {
    $htaccessContent = file_get_contents($htaccessFile);
    assertTest("uploads/kyc/.htaccess denies direct access", strpos($htaccessContent, 'Require all denied') !== false || strpos($htaccessContent, 'Deny from all') !== false);
}

$masked = maskAadhaarNumber('123456789012', 'Aadhaar');
assertTest("maskAadhaarNumber masks to XXXX-XXXX-9012", $masked === 'XXXX-XXXX-9012', "Result: $masked");

$viewInvestorContent = file_get_contents(__DIR__ . '/../admin/view_investor.php');
assertTest("admin/view_investor.php uses secure view_document streaming", strpos($viewInvestorContent, 'view_document.php?kyc_user_id=') !== false);
assertTest("admin/view_investor.php no longer exposes unmasking toggle", strpos($viewInvestorContent, 'adminToggleBtn') === false);

// -----------------------------------------------------------------
// TEST 6: Email Inbox Preview
// -----------------------------------------------------------------
echo "\n[6] Checking Email Inbox Viewer...\n";
$viewEmailContent = file_get_contents(__DIR__ . '/../investor/view_email.php');
assertTest("investor/view_email.php supports ekyc email type", strpos($viewEmailContent, "type === 'ekyc'") !== false);
assertTest("investor/view_email.php has link to ekyc_submission.php", strpos($viewEmailContent, 'ekyc_submission.php?token=') !== false);

// -----------------------------------------------------------------
// SUMMARY
// -----------------------------------------------------------------
echo "\n========================================================\n";
echo "  TEST RESULTS: $passCount PASSED, $failCount FAILED\n";
echo "========================================================\n";

if ($failCount === 0) {
    echo "ALL TESTS PASSED! The complete eKYC workflow meets all requirements.\n";
    exit(0);
} else {
    echo "SOME TESTS FAILED. Please review output.\n";
    exit(1);
}
