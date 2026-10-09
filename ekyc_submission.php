<?php
// ekyc_submission.php
// Public / Token-Controlled Designated eKYC Document & Photo Submission Portal Page

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
$success = '';

if (!empty($token)) {
    $request = getEKYCRequestByToken($pdo, $token);
}

if (!$request) {
    $error = 'Invalid, expired, or missing eKYC submission token. Please check the access-controlled link in your email.';
}

$userId = $request['user_id'] ?? 0;

// Fetch existing eKYC verification record for user if present
$kyc = null;
if ($userId > 0) {
    $stmtKyc = $pdo->prepare("SELECT * FROM kyc_verification WHERE user_id = ?");
    $stmtKyc->execute([$userId]);
    $kyc = $stmtKyc->fetch();
}

// Handle Form Submission BEFORE HTML Output
if ($request && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCSRFPost();

    $idType   = trim($_POST['id_type'] ?? 'Aadhaar');
    $idNumber = strtoupper(trim($_POST['id_number'] ?? ''));
    $fullName = trim($_POST['full_name'] ?? '');
    $dob      = trim($_POST['dob'] ?? '');
    $address  = trim($_POST['address'] ?? '');
    $userIp   = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    if (empty($idNumber) || empty($fullName) || empty($dob) || empty($address)) {
        $error = 'All mandatory identity fields are required for eKYC submission.';
    } else {
        $docPath = $kyc['document_path'] ?? null;
        $photoPath = $kyc['photo_path'] ?? null;
        $ocrName = $kyc['ocr_name'] ?? null;
        $ocrDob = $kyc['ocr_dob'] ?? null;
        $ocrAddress = $kyc['ocr_address'] ?? null;
        $ocrIdNumber = $kyc['ocr_id_number'] ?? null;
        $ocrStatus = 'Processed';

        // 1. Handle Mandatory Identity Document Upload
        if (isset($_FILES['kyc_document']) && $_FILES['kyc_document']['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($_FILES['kyc_document']['error'] !== UPLOAD_ERR_OK) {
                $error = 'File upload error (Code ' . $_FILES['kyc_document']['error'] . '). Please select a valid document file.';
            } else {
                $uploadResult = handleSecureFileUpload($_FILES['kyc_document'], __DIR__ . '/uploads/kyc', ['jpg', 'jpeg', 'png', 'pdf']);
                if (!$uploadResult['success']) {
                    $error = 'Document Upload Error: ' . $uploadResult['error'];
                } else {
                    $docPath = 'uploads/kyc/' . basename($uploadResult['file_path']);

                    // Extract photograph & OCR identity details offline
                    $procRes = processKYCDocumentDetailed($uploadResult['file_path'], __DIR__ . '/uploads/extracted', $userId);
                    
                    if (!empty($procRes['photo_path'])) {
                        $photoPath = $procRes['photo_path'];
                    }

                    $ocrName = !empty($procRes['ocr_name']) ? $procRes['ocr_name'] : null;
                    $ocrDob = !empty($procRes['ocr_dob']) ? $procRes['ocr_dob'] : null;
                    $ocrAddress = !empty($procRes['ocr_address']) ? $procRes['ocr_address'] : null;
                    $ocrIdNumber = !empty($procRes['ocr_id_number']) ? $procRes['ocr_id_number'] : null;
                    $ocrStatus = $procRes['ocr_status'] ?? 'Processed';

                    // Insert document into documents repository
                    $stmtDocIns = $pdo->prepare("INSERT INTO documents (user_id, doc_type, doc_name, file_path, file_size, status) VALUES (?, 'Identity Proof', ?, ?, ?, 'Pending')");
                    $stmtDocIns->execute([$userId, $idType . ' Document - ' . $uploadResult['file_name'], $docPath, $uploadResult['file_size']]);
                }
            }
        }

        // 2. Handle Optional Explicit Investor Photo Upload
        if (empty($error) && isset($_FILES['photo_file']) && $_FILES['photo_file']['error'] === UPLOAD_ERR_OK) {
            $photoUploadRes = handleSecureFileUpload($_FILES['photo_file'], __DIR__ . '/uploads/extracted', ['jpg', 'jpeg', 'png']);
            if ($photoUploadRes['success']) {
                $photoPath = 'uploads/extracted/' . basename($photoUploadRes['file_path']);
            }
        }

        if (empty($error)) {
            if (!$docPath) {
                $error = 'Please upload a valid identity document copy (JPG, PNG or PDF).';
            } else {
                try {
                    $pdo->beginTransaction();

                    $newStatus = 'Submitted';

                    if ($kyc) {
                        $stmtUpd = $pdo->prepare("
                            UPDATE kyc_verification 
                            SET id_type = ?, id_number = ?, full_name = ?, dob = ?, address = ?, 
                                ocr_name = ?, ocr_dob = ?, ocr_address = ?, ocr_id_number = ?, ocr_status = ?,
                                document_path = ?, photo_path = ?, status = ?, updated_at = NOW() 
                            WHERE user_id = ?
                        ");
                        $stmtUpd->execute([$idType, $idNumber, $fullName, $dob, $address, $ocrName, $ocrDob, $ocrAddress, $ocrIdNumber, $ocrStatus, $docPath, $photoPath, $newStatus, $userId]);
                    } else {
                        $stmtIns = $pdo->prepare("
                            INSERT INTO kyc_verification 
                            (user_id, id_type, id_number, full_name, dob, address, ocr_name, ocr_dob, ocr_address, ocr_id_number, ocr_status, document_path, photo_path, status) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmtIns->execute([$userId, $idType, $idNumber, $fullName, $dob, $address, $ocrName, $ocrDob, $ocrAddress, $ocrIdNumber, $ocrStatus, $docPath, $photoPath, $newStatus]);
                    }

                    // Update ekyc_requests token record
                    $stmtReqUpd = $pdo->prepare("UPDATE ekyc_requests SET status = 'Submitted', submitted_at = NOW(), ip_address = ? WHERE id = ?");
                    $stmtReqUpd->execute([$userIp, $request['id']]);

                    // Update investor_profiles
                    $stmtProfUpd = $pdo->prepare("UPDATE investor_profiles SET full_name = ?, dob = ?, id_type = ?, id_number = ?, address = ?, updated_at = NOW() WHERE user_id = ?");
                    $stmtProfUpd->execute([$fullName, $dob, $idType, $idNumber, $address, $userId]);

                    refreshApplicationStatus($pdo, $userId);

                    // Create Admin Notification
                    createAdminNotification($pdo, $userId, 'ekyc_submission', "Investor " . $fullName . " submitted Aadhaar eKYC document via email workflow. Status: Pending Verification.");

                    $pdo->commit();

                    setFlash('success', 'Aadhaar eKYC document & details submitted successfully! Verification status is set to Pending Verification.');
                    header("Location: /digital-investor/ekyc_submission.php?token=" . urlencode($token));
                    exit;

                } catch (Exception $e) {
                    $pdo->rollBack();
                    error_log("Email eKYC Submission Error: " . $e->getMessage());
                    $error = 'Database error submitting eKYC. Please try again.';
                }
            }
        }
    }
}

// Re-fetch request & kyc if updated
if (!empty($token)) {
    $request = getEKYCRequestByToken($pdo, $token);
    if ($userId > 0) {
        $stmtKyc = $pdo->prepare("SELECT * FROM kyc_verification WHERE user_id = ?");
        $stmtKyc->execute([$userId]);
        $kyc = $stmtKyc->fetch();
    }
}

$pageTitle = "Designated Email eKYC Document & Photo Submission Portal";
require_once __DIR__ . '/includes/header.php';
?>

<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-lg-10">
            <?php echo displayFlash(); ?>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo e($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if (!$request): ?>
                <div class="card shadow-sm border-0 rounded-4 text-center p-5">
                    <i class="fas fa-link-slash fs-1 text-danger mb-3"></i>
                    <h4 class="fw-bold text-dark mb-2">Invalid or Expired eKYC Email Link</h4>
                    <p class="text-muted">The electronic eKYC submission link you accessed is invalid or has expired.</p>
                </div>
            <?php else: ?>

                <!-- Demonstration Banner -->
                <div class="alert alert-warning border-0 shadow-sm mb-4 rounded-3 d-flex align-items-center">
                    <i class="fas fa-shield-halved fs-3 text-warning-emphasis me-3"></i>
                    <div>
                        <strong class="d-block text-dark">Email-Based eKYC Submission Portal</strong>
                        <small class="text-dark">This single-use portal allows investors to submit their Aadhaar document, photo, and identity details via secure email link.</small>
                    </div>
                </div>

                <!-- Main eKYC Portal Card -->
                <div class="card shadow-sm border-0 rounded-4">
                    <div class="card-header bg-white py-3 border-bottom d-flex align-items-center justify-content-between flex-wrap gap-2">
                        <div>
                            <h4 class="fw-bold mb-0 text-primary"><i class="fas fa-envelope-open-text me-2"></i>Email eKYC Submission Portal</h4>
                            <small class="text-muted">Logged-in Recipient: <strong><?php echo e($request['recipient_email']); ?></strong></small>
                        </div>
                        <div>
                            <?php echo renderStatusBadge($request['status']); ?>
                        </div>
                    </div>

                    <div class="card-body p-4">

                        <?php if (in_array($request['status'], ['Submitted', 'Verified'])): ?>
                            <?php
                                $rawPhotoPath = $kyc['photo_path'] ?? '';
                                $hasPhoto = !empty($rawPhotoPath) && file_exists(__DIR__ . '/' . $rawPhotoPath);
                                $displayPhotoPath = $hasPhoto ? $rawPhotoPath : null;

                                $maskedAadhaar = !empty($kyc['id_number']) ? maskAadhaarNumber($kyc['id_number'], $kyc['id_type'] ?? 'Aadhaar') : 'N/A';
                                $maskedOcrAadhaar = !empty($kyc['ocr_id_number']) ? maskAadhaarNumber($kyc['ocr_id_number'], $kyc['id_type'] ?? 'Aadhaar') : null;
                            ?>

                            <!-- SUBMITTED / VERIFIED RESULT DISPLAY (NO FULL DOCUMENT IMAGE) -->
                            <div class="card bg-white border border-2 border-primary-subtle shadow-sm rounded-4 p-4 mb-4">
                                <div class="border-bottom pb-3 mb-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
                                    <div>
                                        <h4 class="fw-bold text-dark mb-1">
                                            <i class="fas fa-user-check text-primary me-2"></i>eKYC Verification Details
                                        </h4>
                                        <small class="text-muted">Extracted face photograph and OCR verification details comparison</small>
                                    </div>
                                    <div>
                                        <?php echo renderStatusBadge($kyc['status'] ?? $request['status']); ?>
                                    </div>
                                </div>

                                <div class="row g-4">
                                    <!-- LEFT COLUMN: EXTRACTED FACE PHOTOGRAPH ONLY (NO FULL DOCUMENT) -->
                                    <div class="col-lg-5">
                                        <div class="card bg-light border p-4 rounded-4 h-100 shadow-sm text-center d-flex flex-column justify-content-center align-items-center">
                                            <h6 class="fw-bold text-dark border-bottom w-100 pb-2 mb-3">
                                                <i class="fas fa-camera text-primary me-2"></i>Extracted Document Photograph
                                            </h6>
                                            <?php if ($hasPhoto): ?>
                                                <div class="bg-white p-2 d-inline-block rounded-4 border shadow-sm my-2">
                                                    <img src="/digital-investor/<?php echo e($displayPhotoPath); ?>?v=<?php echo time(); ?>" 
                                                         alt="Extracted Photo" 
                                                         style="width: 140px; height: 175px; object-fit: cover; object-position: center;" 
                                                         class="rounded-3">
                                                </div>
                                                <small class="d-block text-success fw-bold mt-2">
                                                    <i class="fas fa-check-circle me-1"></i> Face Portrait Extracted
                                                </small>
                                            <?php else: ?>
                                                <div class="bg-white p-4 rounded-4 border shadow-sm my-2 text-center text-muted w-100">
                                                    <i class="fas fa-user-circle fs-1 text-secondary mb-2 d-block"></i>
                                                    <div class="alert alert-warning border border-warning rounded-3 mb-0 p-2 small">
                                                        <i class="fas fa-exclamation-triangle me-1"></i> Face photo extraction failed. Please upload a clear Aadhaar image.
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <!-- RIGHT COLUMN: SIDE-BY-SIDE OCR EXTRACTED DETAILS COMPARISON -->
                                    <div class="col-lg-7">
                                        <div class="card bg-white border border-2 border-primary-subtle p-3 p-md-4 rounded-4 shadow-sm h-100">
                                            <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">
                                                <i class="fas fa-microchip text-primary me-2"></i>OCR Text Extraction Results
                                            </h6>

                                            <div class="table-responsive">
                                                <table class="table table-bordered align-middle small mb-3">
                                                    <thead class="table-light">
                                                        <tr>
                                                            <th>Field Title</th>
                                                            <th>Extracted OCR Detail</th>
                                                            <th>Submitted Profile Detail</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <tr>
                                                            <th class="bg-light">Full Name</th>
                                                            <td>
                                                                <?php if (!empty($kyc['ocr_name'])): ?>
                                                                    <strong class="text-success"><?php echo e($kyc['ocr_name']); ?></strong>
                                                                <?php else: ?>
                                                                    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning"><i class="fas fa-exclamation-triangle me-1"></i> Unable to extract — manual review required</span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td><strong><?php echo e($kyc['full_name'] ?? $request['investor_name'] ?? 'N/A'); ?></strong></td>
                                                        </tr>
                                                        <tr>
                                                            <th class="bg-light">Date of Birth</th>
                                                            <td>
                                                                <?php if (!empty($kyc['ocr_dob'])): ?>
                                                                    <strong class="text-success"><?php echo e(formatDobDisplay($kyc['ocr_dob'])); ?></strong>
                                                                <?php else: ?>
                                                                    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning"><i class="fas fa-exclamation-triangle me-1"></i> Unable to extract — manual review required</span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td><strong><?php echo e(formatDobDisplay($kyc['dob'] ?? '')); ?></strong> (<?php echo e(calculateAge($kyc['dob'] ?? '')); ?>)</td>
                                                        </tr>
                                                        <tr>
                                                            <th class="bg-light">ID Number</th>
                                                            <td>
                                                                <?php if (!empty($maskedOcrAadhaar)): ?>
                                                                    <code class="text-success fw-bold"><?php echo e($maskedOcrAadhaar); ?></code>
                                                                <?php else: ?>
                                                                    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning"><i class="fas fa-exclamation-triangle me-1"></i> Unable to extract — manual review required</span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td>
                                                                <code class="text-primary fw-bold" id="userAadhaarDisplay"><?php echo e($maskedAadhaar); ?></code>
                                                                <button type="button" class="btn btn-link btn-sm p-0 ms-1" onclick="toggleAadhaarVisibility('<?php echo e($kyc['id_number'] ?? ''); ?>', '<?php echo e($maskedAadhaar); ?>')">
                                                                    <small id="toggleAadhaarBtn">[Show]</small>
                                                                </button>
                                                            </td>
                                                        </tr>
                                                        <tr>
                                                            <th class="bg-light">Address</th>
                                                            <td>
                                                                <?php if (!empty($kyc['ocr_address'])): ?>
                                                                    <span class="text-success"><?php echo e($kyc['ocr_address']); ?></span>
                                                                <?php else: ?>
                                                                    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning"><i class="fas fa-exclamation-triangle me-1"></i> Unable to extract — manual review required</span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td><small><?php echo e($kyc['address'] ?? 'N/A'); ?></small></td>
                                                        </tr>
                                                    </tbody>
                                                </table>
                                            </div>

                                            <!-- Verification Status Box -->
                                            <div class="p-3 bg-light rounded-3 border mt-auto d-flex justify-content-between align-items-center">
                                                <small class="fw-bold text-dark">Verification Status:</small>
                                                <?php echo renderStatusBadge($kyc['status'] ?? $request['status']); ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!isset($kyc['status']) || $kyc['status'] !== 'Verified'): ?>
                            <!-- EKYC SUBMISSION / RE-UPLOAD FORM -->
                            <form action="/digital-investor/ekyc_submission.php?token=<?php echo urlencode($token); ?>" method="POST" enctype="multipart/form-data">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="token" value="<?php echo e($token); ?>">

                                <h5 class="fw-bold text-dark border-bottom pb-2 mb-3">
                                    <i class="fas fa-edit text-primary me-2"></i><?php echo (isset($kyc['status']) && in_array($kyc['status'], ['Submitted', 'Pending'])) ? 'Re-upload / Update eKYC Document' : 'Submit Identity Details & Upload Aadhaar'; ?>
                                </h5>

                                <div class="row g-3 mb-3">
                                    <div class="col-md-6">
                                        <label for="id_type" class="form-label fw-semibold">ID Proof Type <span class="text-danger">*</span></label>
                                        <?php $currentIdType = $_POST['id_type'] ?? $kyc['id_type'] ?? 'Aadhaar'; ?>
                                        <select class="form-select" id="id_type" name="id_type" required>
                                            <option value="Aadhaar" <?php echo $currentIdType === 'Aadhaar' ? 'selected' : ''; ?>>Aadhaar Card (Recommended)</option>
                                            <option value="PAN" <?php echo $currentIdType === 'PAN' ? 'selected' : ''; ?>>PAN Card</option>
                                            <option value="Passport" <?php echo $currentIdType === 'Passport' ? 'selected' : ''; ?>>Passport</option>
                                            <option value="Voter ID" <?php echo $currentIdType === 'Voter ID' ? 'selected' : ''; ?>>Voter ID Card</option>
                                        </select>
                                    </div>

                                    <div class="col-md-6">
                                        <label for="id_number" class="form-label fw-semibold">Aadhaar / ID Number <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control text-uppercase" id="id_number" name="id_number" required value="<?php echo e($_POST['id_number'] ?? $kyc['id_number'] ?? ''); ?>" placeholder="Enter 12-digit Aadhaar Number">
                                    </div>
                                </div>

                                <div class="row g-3 mb-3">
                                    <div class="col-md-6">
                                        <label for="full_name" class="form-label fw-semibold">Full Name as per Aadhaar / ID <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="full_name" name="full_name" required value="<?php echo e($_POST['full_name'] ?? $kyc['full_name'] ?? $request['investor_name'] ?? ''); ?>" placeholder="Name matching document">
                                    </div>

                                    <div class="col-md-6">
                                        <label for="dob" class="form-label fw-semibold">Date of Birth as per Aadhaar / ID <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control" id="dob" name="dob" required value="<?php echo e($_POST['dob'] ?? $kyc['dob'] ?? ''); ?>">
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label for="address" class="form-label fw-semibold">Address as per Aadhaar / ID <span class="text-danger">*</span></label>
                                    <textarea class="form-control" id="address" name="address" rows="2" required placeholder="Address text matching ID proof"><?php echo e($_POST['address'] ?? $kyc['address'] ?? ''); ?></textarea>
                                </div>

                                <!-- Upload Identity Document File -->
                                <div class="mb-3">
                                    <label for="kyc_document" class="form-label fw-semibold">Upload Aadhaar Card / Identity Document <span class="text-danger">*</span></label>
                                    <input type="file" class="form-control" id="kyc_document" name="kyc_document" accept=".jpg,.jpeg,.png,.pdf" data-max-size="5" <?php echo empty($kyc['document_path']) ? 'required' : ''; ?>>
                                    <div class="form-text text-muted">
                                        Allowed formats: JPG, JPEG, PNG, PDF (Max 5MB). Photo portrait and OCR text details are processed automatically offline.
                                    </div>
                                </div>

                                <!-- Upload Investor Photo Portrait File -->
                                <div class="mb-4">
                                    <label for="photo_file" class="form-label fw-semibold">Upload Investor Photo Portrait (Optional Image)</label>
                                    <input type="file" class="form-control" id="photo_file" name="photo_file" accept=".jpg,.jpeg,.png" data-max-size="5">
                                    <div class="form-text text-muted">
                                        Optional investor photo upload (JPG/PNG, Max 5MB). If omitted, photo will be automatically extracted from your Aadhaar document image.
                                    </div>
                                </div>

                                <div class="d-flex justify-content-between align-items-center border-top pt-4">
                                    <a href="/digital-investor/dashboard.php" class="btn btn-outline-secondary">
                                        <i class="fas fa-arrow-left me-1"></i> Back to Dashboard
                                    </a>
                                    <button type="submit" class="btn btn-primary btn-lg fw-bold px-4 rounded-3 shadow-sm">
                                        <i class="fas fa-paper-plane me-2"></i>Submit Demo eKYC Details
                                    </button>
                                </div>
                            </form>
                        <?php endif; ?>

                    </div>
                </div>

            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function toggleAadhaarVisibility(fullVal, maskedVal) {
    const el = document.getElementById('userAadhaarDisplay');
    const btn = document.getElementById('toggleAadhaarBtn');
    if (!el || !btn) return;
    if (el.innerText === maskedVal) {
        el.innerText = fullVal;
        btn.innerText = '[Hide]';
    } else {
        el.innerText = maskedVal;
        btn.innerText = '[Show]';
    }
}
</script>

<?php require_once __DIR__ . '/includes/header.php'; ?>
