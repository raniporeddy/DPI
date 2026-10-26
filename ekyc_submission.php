<?php
// ekyc_submission.php
// Secure Single-Use eKYC Document Submission Portal via Emailed Link

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/project_info.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/notification_helper.php';

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$request = null;
$error = '';
$isTokenUsed = false;
$isTokenExpired = false;
$submissionSuccess = false;
$showReviewStep = false;
$sessionMismatch = false;

if (!empty($token)) {
    $request = getEKYCRequestByToken($pdo, $token);
}

if ($request) {
    // 1. Single-use validation
    if (in_array($request['status'], ['Submitted', 'Verified'])) {
        $isTokenUsed = true;
    }
    // 2. Expiry time validation (checks explicit expires_at or 24-hour created_at fallback)
    if (!empty($request['expires_at']) && strtotime($request['expires_at']) < time()) {
        $isTokenExpired = true;
    } elseif (!empty($request['created_at']) && (time() - strtotime($request['created_at']) > 86400)) {
        $isTokenExpired = true;
    }

    // 3. Authorization check if investor is currently logged in
    if (!empty($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'investor') {
        if ((int)$_SESSION['user_id'] !== (int)$request['user_id']) {
            $sessionMismatch = true;
        }
    }
}

$userId = (int)($request['user_id'] ?? 0);

// Fetch existing profile and kyc to assist display
$profile = null;
$kyc = null;
if ($userId > 0) {
    $stmtProf = $pdo->prepare("SELECT * FROM investor_profiles WHERE user_id = ?");
    $stmtProf->execute([$userId]);
    $profile = $stmtProf->fetch();

    $stmtKyc = $pdo->prepare("SELECT * FROM kyc_verification WHERE user_id = ?");
    $stmtKyc->execute([$userId]);
    $kyc = $stmtKyc->fetch();
}

// Variables for review stage
$reviewData = [
    'doc_path'      => '',
    'photo_path'    => '',
    'file_name'     => '',
    'file_size'     => 0,
    'full_name'     => '',
    'dob'           => '',
    'gender'        => '',
    'id_number'     => '',
    'address'       => '',
    'ocr_name'      => '',
    'ocr_dob'       => '',
    'ocr_gender'    => '',
    'ocr_id_number' => '',
    'ocr_address'   => '',
    'ocr_status'    => 'Processed'
];

// -------------------------------------------------------------
// POST HANDLER: Step 1 (Upload & Extract OCR) OR Step 2 (Confirm)
// -------------------------------------------------------------
if ($request && !$isTokenUsed && !$isTokenExpired && !$sessionMismatch && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCSRFPost();
    $action = trim($_POST['ekyc_action'] ?? '');
    $userIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    if ($action === 'upload_extract') {
        // Step 1: Validate file upload and extract details via OCR
        if (!isset($_FILES['kyc_document']) || $_FILES['kyc_document']['error'] === UPLOAD_ERR_NO_FILE) {
            $error = 'Please select a valid identity document (Aadhaar / ID Card) to upload.';
        } elseif ($_FILES['kyc_document']['error'] !== UPLOAD_ERR_OK) {
            $error = 'File upload error (Code ' . $_FILES['kyc_document']['error'] . '). Please select a valid document.';
        } else {
            $uploadResult = handleSecureFileUpload($_FILES['kyc_document'], __DIR__ . '/uploads/kyc', ['jpg', 'jpeg', 'png', 'pdf']);
            if (!$uploadResult['success']) {
                $error = 'Document Upload Error: ' . $uploadResult['error'];
            } else {
                $savedDocPath = 'uploads/kyc/' . basename($uploadResult['file_path']);
                $submittedFullName = trim($_POST['pre_full_name'] ?? $profile['full_name'] ?? $request['investor_name'] ?? '');

                // Run OCR and photo extraction
                $procRes = processKYCDocumentDetailed($uploadResult['file_path'], __DIR__ . '/uploads/extracted', $userId, $submittedFullName);

                $photoPath = !empty($procRes['photo_path']) ? $procRes['photo_path'] : null;
                $ocrName   = !empty($procRes['ocr_name']) ? trim($procRes['ocr_name']) : '';
                $ocrDob    = !empty($procRes['dob']) ? trim($procRes['dob']) : (!empty($procRes['ocr_dob']) ? trim($procRes['ocr_dob']) : '');
                $ocrGender = !empty($procRes['gender']) ? trim($procRes['gender']) : (!empty($procRes['ocr_gender']) ? trim($procRes['ocr_gender']) : '');
                $ocrIdNum  = !empty($procRes['id_number']) ? trim($procRes['id_number']) : (!empty($procRes['ocr_id_number']) ? trim($procRes['ocr_id_number']) : '');
                $ocrAddr   = !empty($procRes['address']) ? trim($procRes['address']) : (!empty($procRes['ocr_address']) ? trim($procRes['ocr_address']) : '');
                $ocrMobile = !empty($procRes['mobile']) ? trim($procRes['mobile']) : (!empty($procRes['ocr_mobile']) ? trim($procRes['ocr_mobile']) : '');
                $ocrStatus = !empty($procRes['ocr_status']) ? $procRes['ocr_status'] : 'Processed';

                // Initial suggested values: prefer OCR extraction, fallback to profile/user values
                $reviewData = [
                    'doc_path'      => $savedDocPath,
                    'photo_path'    => $photoPath,
                    'file_name'     => $uploadResult['file_name'],
                    'file_size'     => $uploadResult['file_size'],
                    'full_name'     => $ocrName ?: ($profile['full_name'] ?? $request['investor_name'] ?? ''),
                    'dob'           => $ocrDob ?: ($profile['dob'] ?? ''),
                    'gender'        => $ocrGender ?: ($profile['gender'] ?? 'Male'),
                    'id_number'     => $ocrIdNum ?: ($profile['id_number'] ?? ''),
                    'address'       => $ocrAddr ?: ($profile['address'] ?? ''),
                    'ocr_name'      => $ocrName,
                    'ocr_dob'       => $ocrDob,
                    'ocr_gender'    => $ocrGender,
                    'ocr_id_number' => $ocrIdNum,
                    'ocr_address'   => $ocrAddr,
                    'ocr_mobile'    => $ocrMobile,
                    'ocr_status'    => $ocrStatus
                ];

                $showReviewStep = true;
            }
        }

    } elseif ($action === 'confirm_submission') {
        // Step 2: Final Confirmation & Review Correction Submission
        $docPath     = trim($_POST['doc_path'] ?? '');
        $photoPath   = trim($_POST['photo_path'] ?? '');
        $fileName    = trim($_POST['file_name'] ?? 'Aadhaar_Document');
        $fileSize    = (int)($_POST['file_size'] ?? 0);
        $fullName    = trim($_POST['full_name'] ?? '');
        $dob         = trim($_POST['dob'] ?? '');
        $gender      = trim($_POST['gender'] ?? 'Male');
        $idNumber    = strtoupper(trim($_POST['id_number'] ?? ''));
        $address     = trim($_POST['address'] ?? '');

        $ocrName     = trim($_POST['ocr_name'] ?? '');
        $ocrDob      = trim($_POST['ocr_dob'] ?? '');
        $ocrGender   = trim($_POST['ocr_gender'] ?? '');
        $ocrIdNumber = trim($_POST['ocr_id_number'] ?? '');
        $ocrAddress  = trim($_POST['ocr_address'] ?? '');
        $ocrMobile   = trim($_POST['ocr_mobile'] ?? '');
        $ocrStatus   = trim($_POST['ocr_status'] ?? 'Processed');

        // Security check on document path
        $cleanDocPath = __DIR__ . '/' . ltrim(str_replace(['\\', '..'], ['/', ''], $docPath), '/');
        if (empty($docPath) || !file_exists($cleanDocPath)) {
            $error = 'Document file not found on server. Please upload your identity document again.';
        } elseif (empty($fullName) || empty($dob) || empty($idNumber) || empty($address)) {
            $error = 'Please fill in all identity verification fields (Full Name, Date of Birth, ID Number, Address).';
            $showReviewStep = true;
            $reviewData = [
                'doc_path'      => $docPath,
                'photo_path'    => $photoPath,
                'file_name'     => $fileName,
                'file_size'     => $fileSize,
                'full_name'     => $fullName,
                'dob'           => $dob,
                'gender'        => $gender,
                'id_number'     => $idNumber,
                'address'       => $address,
                'ocr_name'      => $ocrName,
                'ocr_dob'       => $ocrDob,
                'ocr_gender'    => $ocrGender,
                'ocr_id_number' => $ocrIdNumber,
                'ocr_address'   => $ocrAddress,
                'ocr_mobile'    => $ocrMobile,
                'ocr_status'    => $ocrStatus
            ];
        } else {
            try {
                $pdo->beginTransaction();

                $idType = 'Aadhaar';
                $newStatus = 'Submitted';

                if ($kyc) {
                    $stmtUpd = $pdo->prepare("
                        UPDATE kyc_verification 
                        SET id_type = ?, id_number = ?, full_name = ?, dob = ?, address = ?, 
                            ocr_name = ?, ocr_dob = ?, ocr_gender = ?, ocr_address = ?, ocr_id_number = ?, ocr_status = ?,
                            document_path = ?, photo_path = ?, status = ?, admin_remarks = NULL, updated_at = NOW() 
                            WHERE user_id = ?
                    ");
                    $stmtUpd->execute([
                        $idType, $idNumber, $fullName, $dob, $address,
                        $ocrName ?: null, $ocrDob ?: null, $ocrGender ?: null, $ocrAddress ?: null, $ocrIdNumber ?: null, $ocrStatus,
                        $docPath, $photoPath ?: null, $newStatus, $userId
                    ]);
                } else {
                    $stmtIns = $pdo->prepare("
                        INSERT INTO kyc_verification 
                        (user_id, id_type, id_number, full_name, dob, address, ocr_name, ocr_dob, ocr_gender, ocr_address, ocr_id_number, ocr_status, document_path, photo_path, status) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmtIns->execute([
                        $userId, $idType, $idNumber, $fullName, $dob, $address,
                        $ocrName ?: null, $ocrDob ?: null, $ocrGender ?: null, $ocrAddress ?: null, $ocrIdNumber ?: null, $ocrStatus,
                        $docPath, $photoPath ?: null, $newStatus
                    ]);
                }

                // Save structured XML with OCR-extracted document details
                $xmlRelative = saveIdentityDocumentXml($userId, [
                    'full_name'  => $ocrName,
                    'dob'        => $ocrDob,
                    'gender'     => $ocrGender,
                    'id_number'  => $ocrIdNumber,
                    'address'    => $ocrAddress,
                    'mobile'     => $ocrMobile,
                    'photo_path' => $photoPath
                ]);
                $pdo->prepare("UPDATE kyc_verification SET xml_path = ? WHERE user_id = ?")->execute([$xmlRelative, $userId]);

                // Invalidate single-use token in ekyc_requests
                $stmtReqUpd = $pdo->prepare("
                    UPDATE ekyc_requests 
                    SET status = 'Submitted', submitted_at = NOW(), ip_address = ? 
                    WHERE id = ?
                ");
                $stmtReqUpd->execute([$userIp, $request['id']]);

                // Store in documents repository
                $stmtDocIns = $pdo->prepare("
                    INSERT INTO documents (user_id, doc_type, doc_name, file_path, file_size, status) 
                    VALUES (?, 'Aadhaar Card', ?, ?, ?, 'Pending')
                ");
                $stmtDocIns->execute([$userId, 'Aadhaar Card - ' . basename($docPath), $docPath, $fileSize]);

                // Update investor profile with reviewed details
                $stmtProfUpd = $pdo->prepare("
                    UPDATE investor_profiles 
                    SET full_name = ?, dob = ?, gender = ?, id_type = ?, id_number = ?, address = ?, updated_at = NOW() 
                    WHERE user_id = ?
                ");
                $stmtProfUpd->execute([$fullName, $dob, $gender, $idType, $idNumber, $address, $userId]);

                // Sync application onboarding progress
                refreshApplicationStatus($pdo, $userId);

                // Admin Notification
                createAdminNotification(
                    $pdo, $userId, 'ekyc_submission',
                    "Investor {$fullName} submitted identity document for eKYC. OCR status: {$ocrStatus}. Pending administrator review."
                );

                $pdo->commit();
                $submissionSuccess = true;
                $isTokenUsed = true;

            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("eKYC Submission Error: " . $e->getMessage());
                $error = 'Database error submitting eKYC. Please try again.';
            }
        }
    }
}

$pageTitle = "Aadhaar eKYC Document Submission - Digital Investor Onboarding";
require_once __DIR__ . '/includes/header.php';
?>

<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-lg-9">
            <?php echo displayFlash(); ?>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show shadow-sm rounded-3" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo e($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- Academic Demonstration Disclaimer Banner -->
            <div class="alert alert-secondary border-0 shadow-sm mb-4 d-flex align-items-center rounded-3">
                <i class="fas fa-university fs-3 text-primary me-3 flex-shrink-0"></i>
                <div>
                    <strong class="d-block text-dark">Academic Demonstration System (Demo eKYC)</strong>
                    <small class="text-muted">This module processes synthetic identity documents offline for academic prototype evaluation. It extracts details via offline OCR and does not perform live UIDAI government authentication.</small>
                </div>
            </div>

            <?php if ($submissionSuccess): ?>
                <!-- SUBMISSION SUCCESSFUL CARD -->
                <div class="card shadow-sm border-0 rounded-4 p-5 text-center bg-white">
                    <div class="text-success mb-3">
                        <i class="fas fa-check-circle fa-4x"></i>
                    </div>
                    <h3 class="fw-bold text-dark mb-2">eKYC Identity Document Submitted!</h3>
                    <p class="text-muted mb-4 lead fs-6">
                        Your identity document and photograph have been uploaded and processed. Your eKYC status is now updated to <strong>Submitted (Under Review)</strong> for administrator verification.
                    </p>
                    <div class="d-flex justify-content-center gap-3 flex-wrap">
                        <a href="/digital-investor/investor/ekyc.php" class="btn btn-primary btn-lg rounded-pill px-4 fw-bold shadow-sm">
                            <i class="fas fa-id-card me-2"></i> View eKYC Details
                        </a>
                        <a href="/digital-investor/dashboard.php" class="btn btn-outline-secondary btn-lg rounded-pill px-4 fw-semibold">
                            <i class="fas fa-columns me-2"></i> Go to Dashboard
                        </a>
                    </div>
                </div>

            <?php elseif ($sessionMismatch): ?>
                <!-- SESSION MISMATCH CARD -->
                <div class="card shadow-sm border-0 rounded-4 text-center p-5 bg-white">
                    <div class="text-danger mb-3">
                        <i class="fas fa-user-lock fa-4x"></i>
                    </div>
                    <h4 class="fw-bold text-dark mb-2">Session Account Mismatch</h4>
                    <p class="text-muted mb-4">
                        You are currently logged in as <strong><?php echo e($_SESSION['email'] ?? 'another user'); ?></strong>, but this eKYC link belongs to <strong><?php echo e($request['recipient_email']); ?></strong>. To protect investor privacy, cross-account submission is strictly prevented.
                    </p>
                    <div class="d-flex justify-content-center gap-3 flex-wrap">
                        <a href="/digital-investor/logout.php" class="btn btn-danger rounded-pill px-4 fw-bold">
                            <i class="fas fa-sign-out-alt me-1"></i> Log Out &amp; Use Link
                        </a>
                        <a href="/digital-investor/dashboard.php" class="btn btn-outline-secondary rounded-pill px-4">
                            <i class="fas fa-arrow-left me-1"></i> Back to My Dashboard
                        </a>
                    </div>
                </div>

            <?php elseif (!$request): ?>
                <!-- INVALID TOKEN CARD -->
                <div class="card shadow-sm border-0 rounded-4 text-center p-5 bg-white">
                    <div class="text-danger mb-3">
                        <i class="fas fa-link-slash fa-4x"></i>
                    </div>
                    <h4 class="fw-bold text-dark mb-2">Invalid or Missing eKYC Link</h4>
                    <p class="text-muted mb-4">
                        The electronic eKYC submission link you accessed is invalid or has not been recognized.
                    </p>
                    <div>
                        <a href="/digital-investor/dashboard.php" class="btn btn-primary rounded-pill px-4 fw-bold">
                            <i class="fas fa-arrow-left me-2"></i> Go to Dashboard &amp; Request Link
                        </a>
                    </div>
                </div>

            <?php elseif ($isTokenUsed): ?>
                <!-- ALREADY USED TOKEN CARD -->
                <div class="card shadow-sm border-0 rounded-4 text-center p-5 bg-white">
                    <div class="text-warning mb-3">
                        <i class="fas fa-check-double fa-4x"></i>
                    </div>
                    <h4 class="fw-bold text-dark mb-2">This eKYC Link Has Already Been Used</h4>
                    <p class="text-muted mb-4">
                        For security, each eKYC link is single-use. Your document submission has already been received for account <code><?php echo e($request['recipient_email']); ?></code>.
                    </p>
                    <div class="d-flex justify-content-center gap-3 flex-wrap">
                        <a href="/digital-investor/investor/ekyc.php" class="btn btn-primary rounded-pill px-4 fw-bold">
                            <i class="fas fa-id-card me-2"></i> View eKYC Details
                        </a>
                        <a href="/digital-investor/dashboard.php" class="btn btn-outline-secondary rounded-pill px-4 fw-semibold">
                            <i class="fas fa-columns me-2"></i> Return to Dashboard
                        </a>
                    </div>
                </div>

            <?php elseif ($isTokenExpired): ?>
                <!-- EXPIRED TOKEN CARD -->
                <div class="card shadow-sm border-0 rounded-4 text-center p-5 bg-white">
                    <div class="text-danger mb-3">
                        <i class="fas fa-clock fa-4x"></i>
                    </div>
                    <h4 class="fw-bold text-dark mb-2">This eKYC Link Has Expired</h4>
                    <p class="text-muted mb-4">
                        For your account security, eKYC links are valid for 24 hours only. Please return to your dashboard to request a fresh eKYC verification link.
                    </p>
                    <div>
                        <a href="/digital-investor/dashboard.php" class="btn btn-primary rounded-pill px-4 fw-bold">
                            <i class="fas fa-paper-plane me-2"></i> Request New eKYC Link on Dashboard
                        </a>
                    </div>
                </div>

            <?php elseif ($showReviewStep): ?>
                <!-- ======================================================== -->
                <!-- STEP 2: REVIEW & CORRECT OCR-EXTRACTED IDENTITY DETAILS  -->
                <!-- ======================================================== -->
                <div class="card shadow-sm border-0 rounded-4">
                    <div class="card-header bg-white py-3 border-bottom d-flex align-items-center justify-content-between flex-wrap gap-2">
                        <div>
                            <span class="badge bg-primary me-2">Step 2 of 2</span>
                            <h5 class="fw-bold d-inline-block mb-0 text-dark">
                                <i class="fas fa-user-check text-primary me-2"></i>Review &amp; Correct Extracted Details
                            </h5>
                        </div>
                        <span class="badge bg-success-subtle text-success border border-success">
                            <i class="fas fa-microchip me-1"></i> OCR Extraction Complete
                        </span>
                    </div>

                    <div class="card-body p-4 p-md-5">
                        <div class="alert alert-info border-0 rounded-3 mb-4">
                            <i class="fas fa-info-circle me-1"></i>
                            <strong>Please review the details below:</strong> Our OCR engine extracted these fields from your uploaded document. You may edit and correct any extraction errors before final submission.
                        </div>

                        <form action="/digital-investor/ekyc_submission.php?token=<?php echo urlencode($token); ?>" method="POST" id="confirmEkycForm">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="token" value="<?php echo e($token); ?>">
                            <input type="hidden" name="ekyc_action" value="confirm_submission">
                            <input type="hidden" name="doc_path" value="<?php echo e($reviewData['doc_path']); ?>">
                            <input type="hidden" name="photo_path" value="<?php echo e($reviewData['photo_path']); ?>">
                            <input type="hidden" name="file_name" value="<?php echo e($reviewData['file_name']); ?>">
                            <input type="hidden" name="file_size" value="<?php echo e($reviewData['file_size']); ?>">
                            <input type="hidden" name="ocr_name" value="<?php echo e($reviewData['ocr_name']); ?>">
                            <input type="hidden" name="ocr_dob" value="<?php echo e($reviewData['ocr_dob']); ?>">
                            <input type="hidden" name="ocr_gender" value="<?php echo e($reviewData['ocr_gender']); ?>">
                            <input type="hidden" name="ocr_id_number" value="<?php echo e($reviewData['ocr_id_number']); ?>">
                            <input type="hidden" name="ocr_address" value="<?php echo e($reviewData['ocr_address']); ?>">
                            <input type="hidden" name="ocr_mobile" value="<?php echo e($reviewData['ocr_mobile'] ?? ''); ?>">
                            <input type="hidden" name="ocr_status" value="<?php echo e($reviewData['ocr_status']); ?>">

                            <div class="row g-4 mb-4">
                                <!-- Extracted Photograph Preview -->
                                <div class="col-md-4 text-center">
                                    <div class="card bg-light border p-3 rounded-4 h-100 d-flex flex-column align-items-center justify-content-center">
                                        <h6 class="fw-bold text-dark border-bottom pb-2 mb-3 w-100">
                                            <i class="fas fa-camera text-primary me-1"></i> Extracted Photo
                                        </h6>
                                        <?php if (!empty($reviewData['photo_path']) && file_exists(__DIR__ . '/' . $reviewData['photo_path'])): ?>
                                            <div class="bg-white p-2 rounded-3 border shadow-sm my-2">
                                                <img src="/digital-investor/<?php echo e($reviewData['photo_path']); ?>?v=<?php echo time(); ?>" 
                                                     alt="Extracted Portrait" 
                                                     style="width: 120px; height: 150px; object-fit: cover;" 
                                                     class="rounded">
                                            </div>
                                            <small class="text-success fw-bold mt-2"><i class="fas fa-check-circle me-1"></i> Photograph Extracted</small>
                                        <?php else: ?>
                                            <div class="p-3 text-muted">
                                                <i class="fas fa-user-circle fa-3x mb-2 text-secondary"></i>
                                                <small class="d-block text-warning"><i class="fas fa-exclamation-triangle me-1"></i> Portrait could not be extracted automatically.</small>
                                            </div>
                                        <?php endif; ?>
                                        <small class="text-muted mt-2 fs-8">Document: <?php echo e($reviewData['file_name']); ?></small>
                                    </div>
                                </div>

                                <!-- Review & Edit Fields -->
                                <div class="col-md-8">
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label for="full_name" class="form-label fw-semibold">
                                                Full Name <span class="text-danger">*</span>
                                                <?php if (!empty($reviewData['ocr_name'])): ?>
                                                    <span class="badge bg-success-subtle text-success fs-8">OCR Detected</span>
                                                <?php endif; ?>
                                            </label>
                                            <input type="text" class="form-control" id="full_name" name="full_name" required value="<?php echo e($reviewData['full_name']); ?>" placeholder="Full Name as per document">
                                        </div>

                                        <div class="col-md-6">
                                            <label for="dob" class="form-label fw-semibold">
                                                Date of Birth <span class="text-danger">*</span>
                                                <?php if (!empty($reviewData['ocr_dob'])): ?>
                                                    <span class="badge bg-success-subtle text-success fs-8">OCR Detected</span>
                                                <?php endif; ?>
                                            </label>
                                            <input type="date" class="form-control" id="dob" name="dob" required value="<?php echo e($reviewData['dob']); ?>">
                                        </div>

                                        <div class="col-md-6">
                                            <label for="gender" class="form-label fw-semibold">Gender <span class="text-danger">*</span></label>
                                            <select class="form-select" id="gender" name="gender" required>
                                                <option value="Male" <?php echo ($reviewData['gender'] === 'Male') ? 'selected' : ''; ?>>Male</option>
                                                <option value="Female" <?php echo ($reviewData['gender'] === 'Female') ? 'selected' : ''; ?>>Female</option>
                                                <option value="Other" <?php echo ($reviewData['gender'] === 'Other') ? 'selected' : ''; ?>>Other</option>
                                            </select>
                                        </div>

                                        <div class="col-md-6">
                                            <label for="id_number" class="form-label fw-semibold">
                                                ID / Aadhaar Number <span class="text-danger">*</span>
                                                <?php if (!empty($reviewData['ocr_id_number'])): ?>
                                                    <span class="badge bg-success-subtle text-success fs-8">OCR Detected</span>
                                                <?php endif; ?>
                                            </label>
                                            <input type="text" class="form-control font-monospace" id="id_number" name="id_number" required value="<?php echo e($reviewData['id_number']); ?>" placeholder="e.g. 1234-5678-9012">
                                            <div class="form-text fs-8 text-muted">Aadhaar numbers are masked automatically in public views.</div>
                                        </div>

                                        <div class="col-12">
                                            <label for="address" class="form-label fw-semibold">
                                                Address <span class="text-danger">*</span>
                                                <?php if (!empty($reviewData['ocr_address'])): ?>
                                                    <span class="badge bg-success-subtle text-success fs-8">OCR Detected</span>
                                                <?php endif; ?>
                                            </label>
                                            <textarea class="form-control" id="address" name="address" rows="3" required placeholder="Address matching document"><?php echo e($reviewData['address']); ?></textarea>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex justify-content-between align-items-center border-top pt-4">
                                <a href="/digital-investor/ekyc_submission.php?token=<?php echo urlencode($token); ?>" class="btn btn-outline-secondary">
                                    <i class="fas fa-redo me-1"></i> Re-upload Document
                                </a>
                                <button type="submit" id="confirmEkycBtn" class="btn btn-success btn-lg fw-bold px-4 rounded-3 shadow-sm">
                                    <span id="btnConfirmText"><i class="fas fa-check-circle me-2"></i> Confirm &amp; Submit eKYC</span>
                                    <span id="btnConfirmSpinner" class="d-none"><i class="fas fa-spinner fa-spin me-2"></i> Saving Submission...</span>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

            <?php else: ?>
                <!-- ======================================================== -->
                <!-- STEP 1: UPLOAD IDENTITY DOCUMENT FORM                   -->
                <!-- ======================================================== -->
                <div class="card shadow-sm border-0 rounded-4">
                    <div class="card-header bg-white py-3 border-bottom d-flex align-items-center justify-content-between flex-wrap gap-2">
                        <div>
                            <span class="badge bg-primary me-2">Step 1 of 2</span>
                            <h5 class="fw-bold d-inline-block mb-0 text-dark">
                                <i class="fas fa-id-card text-primary me-2"></i>Upload Identity Document
                            </h5>
                        </div>
                        <span class="badge bg-warning text-dark">
                            <i class="fas fa-lock me-1"></i> Single-Use Secure Link
                        </span>
                    </div>

                    <div class="card-body p-4 p-md-5">
                        <div class="alert alert-light border rounded-3 mb-4 d-flex align-items-center justify-content-between flex-wrap gap-2">
                            <div>
                                <small class="text-muted d-block">Authorized Investor Account:</small>
                                <strong class="text-dark"><?php echo e($request['investor_name'] ?: 'Valued Investor'); ?> (<code><?php echo e($request['recipient_email']); ?></code>)</strong>
                            </div>
                            <div class="text-md-end">
                                <small class="text-muted d-block">Link Expiry Time:</small>
                                <span class="badge bg-info-subtle text-info-emphasis border border-info">
                                    <i class="fas fa-clock me-1"></i> <?php echo !empty($request['expires_at']) ? date('M d, Y h:i A', strtotime($request['expires_at'])) : '24 hours'; ?>
                                </span>
                            </div>
                        </div>

                        <form action="/digital-investor/ekyc_submission.php?token=<?php echo urlencode($token); ?>" method="POST" enctype="multipart/form-data" id="uploadEkycForm">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="token" value="<?php echo e($token); ?>">
                            <input type="hidden" name="ekyc_action" value="upload_extract">

                            <div class="mb-4">
                                <label for="kyc_document" class="form-label fw-bold fs-6">
                                    Select Identity Document (Aadhaar Card / Government ID) <span class="text-danger">*</span>
                                </label>
                                <input type="file" class="form-control form-control-lg" id="kyc_document" name="kyc_document" accept=".jpg,.jpeg,.png,.pdf" required>
                                <div class="form-text text-muted mt-2">
                                    <i class="fas fa-file-check me-1"></i> Supported formats: <strong>JPG, JPEG, PNG, PDF</strong> (Maximum size: 5MB).<br>
                                    <i class="fas fa-shield-alt me-1"></i> Documents are saved in protected storage. Name, date of birth, and portrait photograph will be extracted automatically for your review.
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="pre_full_name" class="form-label fw-semibold small text-muted">Investor Name (Pre-fill hint for OCR verification)</label>
                                <input type="text" class="form-control" id="pre_full_name" name="pre_full_name" value="<?php echo e($profile['full_name'] ?? $request['investor_name'] ?? ''); ?>" placeholder="Full Name">
                            </div>

                            <div class="d-flex justify-content-between align-items-center border-top pt-4">
                                <a href="/digital-investor/dashboard.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-arrow-left me-1"></i> Cancel
                                </a>
                                <button type="submit" id="btnUploadDoc" class="btn btn-primary btn-lg fw-bold px-4 rounded-3 shadow-sm">
                                    <span id="btnUploadText"><i class="fas fa-upload me-2"></i> Upload &amp; Extract Details</span>
                                    <span id="btnUploadSpinner" class="d-none"><i class="fas fa-spinner fa-spin me-2"></i> Uploading &amp; Processing OCR...</span>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

            <?php endif; ?>

        </div>
    </div>
</div>

<script>
document.getElementById('uploadEkycForm')?.addEventListener('submit', function() {
    const btn = document.getElementById('btnUploadDoc');
    const txt = document.getElementById('btnUploadText');
    const spn = document.getElementById('btnUploadSpinner');
    if (btn && txt && spn) {
        btn.disabled = true;
        txt.classList.add('d-none');
        spn.classList.remove('d-none');
    }
});

document.getElementById('confirmEkycForm')?.addEventListener('submit', function() {
    const btn = document.getElementById('confirmEkycBtn');
    const txt = document.getElementById('btnConfirmText');
    const spn = document.getElementById('btnConfirmSpinner');
    if (btn && txt && spn) {
        btn.disabled = true;
        txt.classList.add('d-none');
        spn.classList.remove('d-none');
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
