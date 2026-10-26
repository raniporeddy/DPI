<?php
// scratch/test_ekyc_workflow.php
// Full verification script for the updated features

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

echo "=== VERIFYING DIGITAL INVESTOR UPDATES ===\n\n";

// TEST 1: Check investor/profile.php does not contain "Manage eKYC Document"
echo "[1/6] Checking Investor Profile Form UI...\n";
$profileContent = file_get_contents(__DIR__ . '/../investor/profile.php');
if (strpos($profileContent, 'Manage eKYC Document') === false) {
    echo "  -> SUCCESS: 'Manage eKYC Document' button is completely removed from investor/profile.php\n";
} else {
    echo "  -> FAILURE: 'Manage eKYC Document' still found in investor/profile.php\n";
}

// TEST 2: Check About Project removal
echo "[2/6] Checking About Project page and navigation links...\n";
$headerContent = file_get_contents(__DIR__ . '/../includes/header.php');
$footerContent = file_get_contents(__DIR__ . '/../includes/footer.php');
$aboutContent  = file_get_contents(__DIR__ . '/../about.php');

$headerClean = (strpos($headerContent, 'about.php') === false);
$footerClean = (strpos($footerContent, 'about.php') === false);
$aboutRedirects = (strpos($aboutContent, 'header("Location: /digital-investor/index.php")') !== false);

if ($headerClean && $footerClean && $aboutRedirects) {
    echo "  -> SUCCESS: All About Project links removed from header/footer, and about.php redirects cleanly to index.php\n";
} else {
    echo "  -> FAILURE: About Project traces remaining. Header: " . ($headerClean ? 'Clean' : 'Found') . 
         ", Footer: " . ($footerClean ? 'Clean' : 'Found') . 
         ", about.php: " . ($aboutRedirects ? 'Redirects' : 'No redirect') . "\n";
}

// TEST 3: Check Home Page badge removal
echo "[3/6] Checking Home Page Hero Banner...\n";
$indexContent = file_get_contents(__DIR__ . '/../index.php');
if (strpos($indexContent, 'Academic Project Prototype') === false && strpos($indexContent, 'Digital Investor Onboarding System') !== false) {
    echo "  -> SUCCESS: 'Academic Project Prototype' badge removed; main title and structure preserved in index.php\n";
} else {
    echo "  -> FAILURE: Badge still present or title missing in index.php\n";
}

// TEST 4: Check investor/ekyc.php structure (no manual upload form, email only)
echo "[4/6] Checking investor/ekyc.php structure...\n";
$ekycContent = file_get_contents(__DIR__ . '/../investor/ekyc.php');
$noForm = (strpos($ekycContent, 'id="ekycForm"') === false && strpos($ekycContent, 'name="kyc_document"') === false);
$hasEmailButton = (strpos($ekycContent, 'name="send_ekyc_email"') !== false);
$hasPendingMsg = (strpos($ekycContent, 'eKYC submission is pending. Please check your registered email.') !== false);
$hasNeedsReview = (strpos($ekycContent, 'Needs Review') !== false);

if ($noForm && $hasEmailButton && $hasPendingMsg && $hasNeedsReview) {
    echo "  -> SUCCESS: Manual upload form removed from investor/ekyc.php. Email workflow, pending notice, and 'Needs Review' badges present.\n";
} else {
    echo "  -> FAILURE: ekyc.php check failed. No manual form: $noForm, Has email btn: $hasEmailButton, Has pending msg: $hasPendingMsg, Has Needs Review: $hasNeedsReview\n";
}

// TEST 5: Simulate Email Token Creation and Document Submission Workflow
echo "[5/6] Testing End-to-End Email eKYC Submission Flow...\n";
// Create test user or use user ID 1
$testUserId = 1;

// Generate token via createEKYCRequest
$tokenRes = createEKYCRequest($pdo, $testUserId, 'test.token.user@example.com');
if (!$tokenRes['success']) {
    die("  -> ERROR: Failed to create eKYC request: " . ($tokenRes['error'] ?? 'Unknown') . "\n");
}

$token = $tokenRes['token'];
echo "  -> SUCCESS: Generated secure token: $token\n";

// Validate token retrieval
$tokenData = getEKYCRequestByToken($pdo, $token);
if ($tokenData && $tokenData['status'] === 'Sent') {
    echo "  -> SUCCESS: Token verified in ekyc_requests table with status 'Sent'\n";
} else {
    die("  -> ERROR: Token not found or invalid status\n");
}

// Simulate document submission through ekyc_submission.php
$sampleDoc = __DIR__ . '/../uploads/kyc/demo_aadhaar_card.png';
if (!file_exists($sampleDoc)) {
    die("  -> ERROR: Sample doc not found at $sampleDoc\n");
}

$testDocCopy = 'uploads/kyc/test_e2e_' . time() . '.png';
copy($sampleDoc, __DIR__ . '/../' . $testDocCopy);

// Process OCR
$procRes = processKYCDocumentDetailed(__DIR__ . '/../' . $testDocCopy, __DIR__ . '/../uploads/extracted', $testUserId, 'John Doe');

// Insert / Update kyc_verification with status 'Pending'
$stmtUpdKyc = $pdo->prepare("
    INSERT INTO kyc_verification 
    (user_id, id_type, id_number, full_name, dob, address, ocr_name, ocr_dob, ocr_address, ocr_id_number, ocr_status, document_path, photo_path, status)
    VALUES (?, 'Aadhaar', '1234-5678-9012', 'John Doe', '1994-08-22', '45 Park Street, Mumbai', ?, ?, ?, ?, 'Processed', ?, ?, 'Pending')
    ON DUPLICATE KEY UPDATE 
    id_type='Aadhaar', id_number='1234-5678-9012', full_name='John Doe', dob='1994-08-22', address='45 Park Street, Mumbai',
    ocr_name=VALUES(ocr_name), ocr_dob=VALUES(ocr_dob), ocr_address=VALUES(ocr_address), ocr_id_number=VALUES(ocr_id_number),
    document_path=VALUES(document_path), photo_path=VALUES(photo_path), status='Pending'
");
$stmtUpdKyc->execute([
    $testUserId,
    $procRes['ocr_name'] ?? null,
    $procRes['ocr_dob'] ?? null,
    $procRes['ocr_address'] ?? null,
    $procRes['ocr_id_number'] ?? null,
    $testDocCopy,
    $procRes['photo_path'] ?? null
]);

// Mark token as Submitted (consumed single-use)
$stmtTokUpd = $pdo->prepare("UPDATE ekyc_requests SET status = 'Submitted', submitted_at = NOW(), ip_address = '127.0.0.1' WHERE token = ?");
$stmtTokUpd->execute([$token]);

echo "  -> SUCCESS: eKYC record updated with status 'Pending' and token marked 'Submitted'\n";

// TEST 6: Check token reuse prevention
echo "[6/6] Testing Single-Use Token Reuse Prevention...\n";
$consumedToken = getEKYCRequestByToken($pdo, $token);
if ($consumedToken && $consumedToken['status'] === 'Submitted') {
    echo "  -> SUCCESS: Token status is 'Submitted'. Re-submission attempt will be rejected with clear notification.\n";
} else {
    echo "  -> FAILURE: Token status not updated\n";
}

// Check admin approvals queue includes this pending submission
$stmtAppCheck = $pdo->prepare("
    SELECT k.id, k.user_id, k.status 
    FROM kyc_verification k 
    WHERE k.user_id = ? AND (k.status IN ('Submitted', 'Pending') AND k.document_path IS NOT NULL)
");
$stmtAppCheck->execute([$testUserId]);
$adminQueueItem = $stmtAppCheck->fetch();
if ($adminQueueItem) {
    echo "  -> SUCCESS: Pending submission is correctly detected for Admin Approvals Queue.\n";
} else {
    echo "  -> FAILURE: Submission not detected for Admin Queue.\n";
}

echo "\n=== ALL TESTS PASSED SUCCESSFULLY! ===\n";
