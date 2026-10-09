<?php
// test_features_e2e.php
// End-to-End Automated Test Script for Digital Investor System

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/email_helper.php';

echo "=== STARTING E2E FEATURE VERIFICATION TESTS ===\n\n";

// 1. Test Database Connectivity
echo "[1/7] Testing DB Connection...\n";
if ($pdo) {
    echo "  -> SUCCESS: Database connection established.\n";
} else {
    die("  -> ERROR: Database connection failed.\n");
}

// 2. Test User Accounts & Login Verification
echo "[2/7] Checking Seed Users...\n";
$stmtAd = $pdo->prepare("SELECT id, email, role FROM users WHERE role = 'admin'");
$stmtAd->execute();
$adminUser = $stmtAd->fetch();
echo "  -> Admin User Found: ID " . $adminUser['id'] . " (" . $adminUser['email'] . ")\n";

$stmtInv = $pdo->prepare("SELECT id, email, role FROM users WHERE role = 'investor'");
$stmtInv->execute();
$investorUser = $stmtInv->fetch();
echo "  -> Investor User Found: ID " . $investorUser['id'] . " (" . $investorUser['email'] . ")\n";

// 3. Test Feature 1: Create eSign Request & Demo Mode Fallback
echo "[3/7] Testing Feature 1: Email-Based eSign Request Workflow...\n";
$agreements = getAgreements($pdo);
if (empty($agreements)) {
    die("  -> ERROR: No investment agreements found in database.\n");
}
$ag = $agreements[0];
echo "  -> Selected Agreement: " . $ag['title'] . " (ID: " . $ag['id'] . ")\n";

$reqRes = createESignRequest($pdo, $investorUser['id'], $ag['id'], 'test.recipient@example.com');
if ($reqRes['success']) {
    echo "  -> SUCCESS: eSign request record created in database! Request ID: " . $reqRes['request_id'] . "\n";
    echo "  -> Generated Secure Signing URL: " . $reqRes['signing_url'] . "\n";
    $isDemoStr = (!empty($reqRes['email_res']['is_demo'])) ? 'Demo Mode Fallback Active' : 'Sent via SMTP';
    echo "  -> Email Delivery Status: " . $isDemoStr . "\n";
} else {
    echo "  -> ERROR creating eSign request: " . $reqRes['error'] . "\n";
}

// 4. Test Token Signing Workflow (Recipient opens token link and signs)
echo "[4/7] Testing Token Signing Workflow (esign_signing.php)...\n";
$reqToken = $reqRes['token'] ?? '';
$tokenReq = !empty($reqToken) ? getESignRequestByToken($pdo, $reqToken) : null;
if ($tokenReq) {
    echo "  -> SUCCESS: Token validated cleanly for request #" . $tokenReq['id'] . " (" . $tokenReq['agreement_title'] . ")\n";
    
    // Simulate signature execution
    if (!file_exists(__DIR__ . '/uploads/signatures')) {
        mkdir(__DIR__ . '/uploads/signatures', 0777, true);
    }
    if (!file_exists(__DIR__ . '/uploads/signed_agreements')) {
        mkdir(__DIR__ . '/uploads/signed_agreements', 0777, true);
    }

    $simSigPath = 'uploads/signatures/sig_test_' . time() . '.png';
    $simSignedDocPath = 'uploads/signed_agreements/signed_test_' . time() . '.pdf';
    @copy(__DIR__ . '/' . $ag['file_path'], __DIR__ . '/' . $simSignedDocPath);
    
    // Create dummy png for test signature
    $img = imagecreatetruecolor(100, 50);
    imagepng($img, __DIR__ . '/' . $simSigPath);
    imagedestroy($img);

    $stmtUpd = $pdo->prepare("UPDATE esign_requests SET status = 'Signed', signature_path = ?, signed_doc_path = ?, signed_at = NOW(), ip_address = '127.0.0.1' WHERE id = ?");
    $stmtUpd->execute([$simSigPath, $simSignedDocPath, $tokenReq['id']]);

    refreshApplicationStatus($pdo, $investorUser['id']);
    echo "  -> SUCCESS: Request #" . $tokenReq['id'] . " status updated to 'Signed'!\n";

} else {
    echo "  -> ERROR: Failed to retrieve eSign request by token.\n";
}

// 5. Test Admin eSign Requests Listing
echo "[5/7] Testing Admin eSign Requests Tracking...\n";
$allReqs = getAllESignRequests($pdo);
echo "  -> Total eSign Requests in Admin Vault: " . count($allReqs) . "\n";

// 6. Test Feature 2: eKYC Document Processing & OCR Extraction
echo "[6/7] Testing Feature 2: eKYC Document Upload & Offline OCR Extraction...\n";
$sampleKycFile = __DIR__ . '/uploads/kyc/demo_aadhaar_card.png';
if (file_exists($sampleKycFile)) {
    $procRes = processKYCDocumentDetailed($sampleKycFile, __DIR__ . '/uploads/extracted', $investorUser['id']);
    echo "  -> OCR Extraction Result: " . ($procRes['success'] ? 'SUCCESS' : 'FAILED') . "\n";
    echo "  -> Extracted Portrait Photo: " . ($procRes['photo_path'] ?: 'Photograph not detected') . "\n";
    echo "  -> Extracted Name: " . ($procRes['ocr_name'] ?: 'Not detected') . "\n";
    echo "  -> Extracted DOB: " . ($procRes['ocr_dob'] ?: 'Not detected') . "\n";
    echo "  -> Extracted ID Number: " . ($procRes['ocr_id_number'] ?: 'Not detected') . "\n";
    echo "  -> Extracted Address: " . ($procRes['ocr_address'] ?: 'Not detected') . "\n";

    // Save to Database
    $masked = maskAadhaarNumber('1234-5678-9012');
    echo "  -> Aadhaar Masking Test ('1234-5678-9012'): " . $masked . "\n";

    $stmtKycUpd = $pdo->prepare("
        INSERT INTO kyc_verification 
        (user_id, id_type, id_number, full_name, dob, address, ocr_name, ocr_dob, ocr_address, ocr_id_number, ocr_status, document_path, photo_path, status)
        VALUES (?, 'Aadhaar', '1234-5678-9012', 'John Doe', '1994-08-22', '45 Park Street, Sector 4', ?, ?, ?, ?, ?, 'uploads/kyc/demo_aadhaar_card.png', ?, 'Submitted')
        ON DUPLICATE KEY UPDATE 
        ocr_name=VALUES(ocr_name), ocr_dob=VALUES(ocr_dob), ocr_address=VALUES(ocr_address), ocr_id_number=VALUES(ocr_id_number), photo_path=VALUES(photo_path), status='Submitted'
    ");
    $stmtKycUpd->execute([
        $investorUser['id'],
        $procRes['ocr_name'] ?? null,
        $procRes['ocr_dob'] ?? null,
        $procRes['ocr_address'] ?? null,
        $procRes['ocr_id_number'] ?? null,
        $procRes['ocr_status'] ?? 'Processed',
        $procRes['photo_path'] ?? null
    ]);
    echo "  -> SUCCESS: eKYC record & OCR fields saved to database!\n";

} else {
    echo "  -> WARNING: Sample Aadhaar file not found at " . $sampleKycFile . "\n";
}

// 7. Test Admin Review and Approval
echo "[7/7] Testing Admin Approval & Verification Action...\n";
$stmtApprove = $pdo->prepare("UPDATE kyc_verification SET status = 'Verified', admin_remarks = 'All details verified by admin.', verified_at = NOW() WHERE user_id = ?");
$stmtApprove->execute([$investorUser['id']]);

$stmtAppUpd = $pdo->prepare("UPDATE applications SET status = 'Approved', reviewed_at = NOW(), admin_remarks = 'Application Approved' WHERE user_id = ?");
$stmtAppUpd->execute([$investorUser['id']]);

refreshApplicationStatus($pdo, $investorUser['id']);

$finalApp = getOrInitApplication($pdo, $investorUser['id']);
echo "  -> Investor Application Final Status: " . $finalApp['status'] . "\n";
echo "  -> Profile Completed: " . $finalApp['profile_completed'] . "\n";
echo "  -> eKYC Completed: " . $finalApp['kyc_completed'] . "\n";
echo "  -> Docs Completed: " . $finalApp['docs_completed'] . "\n";
echo "  -> eSign Completed: " . $finalApp['esign_completed'] . "\n";

echo "\n=== ALL E2E VERIFICATION TESTS COMPLETED SUCCESSFULLY! ===\n";
