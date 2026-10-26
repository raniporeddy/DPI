<?php
// investor/ekyc.php
// Academic Demo eKYC Verification Module with Email Workflow & Document Preview

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/project_info.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/notification_helper.php';

requireRole('investor');

$userId = $_SESSION['user_id'];
$error = '';

// Fetch existing eKYC submission
$stmt = $pdo->prepare("SELECT * FROM kyc_verification WHERE user_id = ?");
$stmt->execute([$userId]);
$kyc = $stmt->fetch();

// Pre-fill from investor profile if available
$stmtProf = $pdo->prepare("SELECT * FROM investor_profiles WHERE user_id = ?");
$stmtProf->execute([$userId]);
$profile = $stmtProf->fetch();

// Handle Form Submission BEFORE HTML Output
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCSRFPost();

    // 1. Handle Email-Based eKYC Submission Request
    if (isset($_POST['send_ekyc_email'])) {
        $recipientEmail = $_SESSION['email'];
        $reqRes = createEKYCRequest($pdo, $userId, $recipientEmail);
        if ($reqRes['success']) {
            setFlash('success', 'eKYC submission email delivered directly to your inbox (' . $recipientEmail . ') via Live Gmail SMTP! Please check your email to open your secure portal.');
            header("Location: /digital-investor/investor/ekyc.php");
            exit;
        } else {
            $error = $reqRes['error'] ?? 'Failed to send eKYC email.';
        }
    } else {
        // 2. Handle Direct Form Submission
        $idType   = trim($_POST['id_type'] ?? 'Aadhaar');
        $idNumber = strtoupper(trim($_POST['id_number'] ?? ''));
        $fullName = trim($_POST['full_name'] ?? '');
        $dob      = trim($_POST['dob'] ?? '');
        $address  = trim($_POST['address'] ?? '');

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

            // Handle Identity Document Upload & Automatic Photo & OCR Extraction
            if (isset($_FILES['kyc_document']) && $_FILES['kyc_document']['error'] !== UPLOAD_ERR_NO_FILE) {
                if ($_FILES['kyc_document']['error'] !== UPLOAD_ERR_OK) {
                    $error = 'File upload error (Code ' . $_FILES['kyc_document']['error'] . '). Please select a valid document file.';
                } else {
                    $uploadResult = handleSecureFileUpload($_FILES['kyc_document'], dirname(__DIR__) . '/uploads/kyc', ['jpg', 'jpeg', 'png', 'pdf']);
                    if (!$uploadResult['success']) {
                        $error = 'Document Upload Error: ' . $uploadResult['error'];
                    } else {
                        $docPath = 'uploads/kyc/' . basename($uploadResult['file_path']);

                        // Extract photograph & OCR identity details offline
                        $procRes = processKYCDocumentDetailed($uploadResult['file_path'], dirname(__DIR__) . '/uploads/extracted', $userId);
                        
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

            if (empty($error)) {
                if (!$docPath) {
                    $error = 'Please upload a valid identity document copy (JPG, PNG or PDF).';
                } else {
                    try {
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

                        // Update investor_profiles
                        $stmtProfUpd = $pdo->prepare("UPDATE investor_profiles SET full_name = ?, dob = ?, id_type = ?, id_number = ?, address = ?, updated_at = NOW() WHERE user_id = ?");
                        $stmtProfUpd->execute([$fullName, $dob, $idType, $idNumber, $address, $userId]);

                        refreshApplicationStatus($pdo, $userId);

                        // Create Admin Notification
                        createAdminNotification($pdo, $userId, 'ekyc_submission', "Investor " . $fullName . " submitted eKYC document for admin verification.");

                        setFlash('success', 'eKYC document submitted successfully! Admin review is pending.');
                        header("Location: /digital-investor/investor/ekyc.php");
                        exit;

                    } catch (Exception $e) {
                        error_log("eKYC Submission Error: " . $e->getMessage());
                        $error = 'Database error submitting eKYC. Please try again.';
                    }
                }
            }
        }
    }
}

// Render HTML View
$pageTitle = "Demo eKYC Verification & OCR Details - Digital Investor Onboarding";
require_once __DIR__ . '/../includes/header.php';
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

            <!-- EMAIL-BASED EKYC WORKFLOW CARD -->
            <div class="card bg-white border border-2 border-warning-subtle shadow-sm rounded-4 p-4 mb-4">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
                    <div>
                        <h5 class="fw-bold text-dark mb-1">
                            <i class="fas fa-envelope text-warning me-2"></i>Submit Aadhaar eKYC by Email Workflow
                        </h5>
                        <p class="text-muted small mb-0">Send a single-use secure eKYC submission portal link directly to your registered email inbox (<code><?php echo e($_SESSION['email']); ?></code>).</p>
                    </div>
                    <form action="/digital-investor/investor/ekyc.php" method="POST" class="m-0">
                        <?php echo csrfField(); ?>
                        <button type="submit" name="send_ekyc_email" value="1" class="btn btn-warning btn-lg fw-bold px-4 rounded-3 shadow-sm">
                            <i class="fas fa-paper-plane me-2"></i>Send eKYC Link to My Email
                        </button>
                    </form>
                </div>
            </div>

            <!-- Demo eKYC Academic Disclaimer Banner -->
            <div class="alert alert-secondary border-0 shadow-sm mb-4 d-flex align-items-center rounded-3">
                <i class="fas fa-graduation-cap fs-3 text-primary me-3"></i>
                <div>
                    <strong class="d-block text-dark">Demo eKYC Module (Academic Demonstration System)</strong>
                    <small class="text-muted">This module processes synthetic/sample identity documents offline. It does not perform live UIDAI government verification or claim official Aadhaar authentication.</small>
                </div>
            </div>

            <div class="card shadow-sm border-0 rounded-4 mb-4">
                <div class="card-header bg-white py-3 border-bottom d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <h4 class="fw-bold mb-0 text-primary"><i class="fas fa-id-card me-2"></i>Demo eKYC Verification & OCR Review</h4>
                    <div>
                        <span class="badge bg-secondary me-2">Module 2 of 4</span>
                        <?php echo renderStatusBadge($kyc['status'] ?? 'Pending'); ?>
                    </div>
                </div>

                <div class="card-body p-4">

                    <!-- TOP eKYC RESULT SCREEN: Extracted Face Photo & OCR Details (No Full Document Image) -->
                    <?php if (isset($kyc['status']) && in_array($kyc['status'], ['Submitted', 'Verified', 'Rejected'])): ?>
                        <?php 
                            $rawPhotoPath = $kyc['photo_path'] ?? '';
                            $hasPhoto = !empty($rawPhotoPath) && file_exists(dirname(__DIR__) . '/' . $rawPhotoPath);
                            $displayPhotoPath = $hasPhoto ? $rawPhotoPath : null;

                            $maskedAadhaar = maskAadhaarNumber($kyc['id_number'], $kyc['id_type']);
                            $maskedOcrAadhaar = !empty($kyc['ocr_id_number']) ? maskAadhaarNumber($kyc['ocr_id_number'], $kyc['id_type']) : null;
                        ?>

                        <div class="card bg-white border border-2 border-primary-subtle shadow-sm rounded-4 p-4 mb-4">
                            <!-- Header with Title & Status Badge -->
                            <div class="border-bottom pb-3 mb-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
                                <div>
                                    <h4 class="fw-bold text-dark mb-1">
                                        <i class="fas fa-user-check text-primary me-2"></i>eKYC Verification Details
                                    </h4>
                                    <small class="text-muted">Extracted face photograph and side-by-side OCR verification details</small>
                                </div>
                                <div>
                                    <?php echo renderStatusBadge($kyc['status']); ?>
                                </div>
                            </div>

                            <div class="row g-4">
                                <!-- LEFT COLUMN: EXTRACTED FACE PHOTOGRAPH ONLY (NO FULL DOCUMENT IMAGE) -->
                                <div class="col-lg-5">
                                    <div class="card bg-light border p-4 rounded-4 h-100 shadow-sm text-center d-flex flex-column justify-content-center align-items-center">
                                        <h6 class="fw-bold text-dark border-bottom w-100 pb-2 mb-3">
                                            <i class="fas fa-camera text-primary me-2"></i>Extracted Document Photograph
                                        </h6>
                                        <?php if ($hasPhoto): ?>
                                            <div class="bg-white p-2 d-inline-block rounded-4 border shadow-sm my-2">
                                                <img src="/digital-investor/<?php echo e($displayPhotoPath); ?>?v=<?php echo time(); ?>" 
                                                     alt="Document Photograph" 
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

                                <!-- RIGHT COLUMN: SIDE-BY-SIDE OCR EXTRACTED DETAILS & COMPARISON -->
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
                                                        <td><strong><?php echo e($kyc['full_name']); ?></strong></td>
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
                                                        <td><strong><?php echo e(formatDobDisplay($kyc['dob'])); ?></strong> (<?php echo e(calculateAge($kyc['dob'])); ?>)</td>
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
                                                            <button type="button" class="btn btn-link btn-sm p-0 ms-1" onclick="toggleAadhaarVisibility('<?php echo e($kyc['id_number']); ?>', '<?php echo e($maskedAadhaar); ?>')">
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
                                                        <td><small><?php echo e($kyc['address']); ?></small></td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>

                                        <!-- Verification Status & Admin Remarks Box -->
                                        <div class="p-3 bg-light rounded-3 border mt-auto">
                                            <div class="d-flex justify-content-between align-items-center mb-1">
                                                <small class="fw-bold text-dark">Verification Status:</small>
                                                <?php echo renderStatusBadge($kyc['status']); ?>
                                            </div>
                                            <?php if (!empty($kyc['admin_remarks'])): ?>
                                                <small class="d-block text-muted mt-2 border-top pt-2">
                                                    <strong>Admin Remarks:</strong> <?php echo e($kyc['admin_remarks']); ?>
                                                </small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- eKYC SUBMISSION FORM -->
                    <form action="/digital-investor/investor/ekyc.php" method="POST" enctype="multipart/form-data" id="ekycForm">
                        <?php echo csrfField(); ?>

                        <h5 class="fw-bold text-dark border-bottom pb-2 mb-3">
                            <i class="fas fa-edit text-primary me-2"></i><?php echo isset($kyc['status']) ? 'Re-upload / Update eKYC Document' : 'Submit Identity Document & Details'; ?>
                        </h5>

                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label for="id_type" class="form-label fw-semibold">ID Proof Type <span class="text-danger">*</span></label>
                                <?php $currentIdType = $_POST['id_type'] ?? $kyc['id_type'] ?? $profile['id_type'] ?? 'Aadhaar'; ?>
                                <select class="form-select" id="id_type" name="id_type" required>
                                    <option value="Aadhaar" <?php echo $currentIdType === 'Aadhaar' ? 'selected' : ''; ?>>Aadhaar Card (Recommended)</option>
                                    <option value="PAN" <?php echo $currentIdType === 'PAN' ? 'selected' : ''; ?>>PAN Card</option>
                                    <option value="Passport" <?php echo $currentIdType === 'Passport' ? 'selected' : ''; ?>>Passport</option>
                                    <option value="Voter ID" <?php echo $currentIdType === 'Voter ID' ? 'selected' : ''; ?>>Voter ID Card</option>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label for="id_number" class="form-label fw-semibold">Aadhaar / ID Number <span class="text-danger">*</span></label>
                                <input type="text" class="form-control text-uppercase" id="id_number" name="id_number" required value="<?php echo e($_POST['id_number'] ?? $kyc['id_number'] ?? $profile['id_number'] ?? ''); ?>" placeholder="Enter 12-digit Aadhaar Number">
                            </div>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label for="full_name" class="form-label fw-semibold">Full Name as per Aadhaar / ID <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="full_name" name="full_name" required value="<?php echo e($_POST['full_name'] ?? $kyc['full_name'] ?? $profile['full_name'] ?? ''); ?>" placeholder="Name matching document">
                            </div>

                            <div class="col-md-6">
                                <label for="dob" class="form-label fw-semibold">Date of Birth as per Aadhaar / ID <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="dob" name="dob" required value="<?php echo e($_POST['dob'] ?? $kyc['dob'] ?? $profile['dob'] ?? ''); ?>">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="address" class="form-label fw-semibold">Address as per Aadhaar / ID <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="address" name="address" rows="2" required placeholder="Address text matching ID proof"><?php echo e($_POST['address'] ?? $kyc['address'] ?? $profile['address'] ?? ''); ?></textarea>
                        </div>

                        <!-- Upload Identity Document -->
                        <div class="mb-4">
                            <label for="kyc_document" class="form-label fw-semibold">Upload Aadhaar Card / Identity Document <span class="text-danger">*</span></label>
                            <input type="file" class="form-control" id="kyc_document" name="kyc_document" accept=".jpg,.jpeg,.png,.pdf" data-max-size="5" <?php echo empty($kyc['document_path']) ? 'required' : ''; ?>>
                            <div class="form-text text-muted">
                                Allowed formats: JPG, JPEG, PNG, PDF (Max 5MB). Photo portrait and OCR text details are processed automatically offline.
                            </div>
                        </div>

                        <div class="d-flex justify-content-between align-items-center border-top pt-4">
                            <a href="/digital-investor/dashboard.php" class="btn btn-outline-secondary">
                                <i class="fas fa-arrow-left me-1"></i> Back to Dashboard
                            </a>
                            <button type="submit" id="submitKycBtn" class="btn btn-primary btn-lg fw-bold px-4 rounded-3" <?php echo (isset($kyc['status']) && $kyc['status'] === 'Verified') ? 'disabled' : ''; ?>>
                                <span id="btnText"><i class="fas fa-paper-plane me-1"></i> Submit Demo eKYC Details</span>
                                <span id="btnSpinner" class="d-none"><i class="fas fa-spinner fa-spin me-1"></i> Processing & Extracting OCR...</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function toggleAadhaarVisibility(fullVal, maskedVal) {
    const el = document.getElementById('userAadhaarDisplay');
    const btn = document.getElementById('toggleAadhaarBtn');
    if (el.innerText === maskedVal) {
        el.innerText = fullVal;
        btn.innerText = '[Hide]';
    } else {
        el.innerText = maskedVal;
        btn.innerText = '[Show]';
    }
}

document.getElementById('ekycForm')?.addEventListener('submit', function() {
    const btn = document.getElementById('submitKycBtn');
    const btnText = document.getElementById('btnText');
    const btnSpinner = document.getElementById('btnSpinner');
    if (btn && btnText && btnSpinner) {
        btn.disabled = true;
        btnText.classList.add('d-none');
        btnSpinner.classList.remove('d-none');
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
