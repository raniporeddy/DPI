<?php
// investor/ekyc.php
// eKYC Identity Verification - Secure Password-Protected XML Document Details Viewer

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

$userId = (int) $_SESSION['user_id'];
$error  = '';

// 1. Fetch user account for secure password verification
$stmtUser = $pdo->prepare("SELECT id, email, password FROM users WHERE id = ?");
$stmtUser->execute([$userId]);
$userRow = $stmtUser->fetch();

// 2. Fetch KYC record for THIS logged-in investor ONLY (enforcing user isolation)
$stmtKyc = $pdo->prepare("SELECT * FROM kyc_verification WHERE user_id = ?");
$stmtKyc->execute([$userId]);
$kyc = $stmtKyc->fetch();

// 3. Read OCR-extracted document details from secure account XML via PHP SimpleXML
$xmlData = readIdentityDocumentXmlSecure($userId);

// Fallback auto-sync: if XML doesn't exist yet on disk but KYC database record has OCR data, generate it
if (!$xmlData && !empty($kyc) && (!empty($kyc['ocr_name']) || !empty($kyc['photo_path']) || !empty($kyc['document_path']))) {
    $xmlRelative = saveIdentityDocumentXml($userId, [
        'full_name'  => $kyc['ocr_name'] ?? '',
        'dob'        => $kyc['ocr_dob'] ?? '',
        'gender'     => $kyc['ocr_gender'] ?? '',
        'id_number'  => $kyc['ocr_id_number'] ?? '',
        'address'    => $kyc['ocr_address'] ?? '',
        'mobile'     => $kyc['ocr_mobile'] ?? '',
        'photo_path' => $kyc['photo_path'] ?? ''
    ]);
    $pdo->prepare("UPDATE kyc_verification SET xml_path = ? WHERE user_id = ?")->execute([$xmlRelative, $userId]);
    $xmlData = readIdentityDocumentXmlSecure($userId);
}

// Check whether an uploaded document or extracted OCR record exists
$hasDocument = !empty($kyc['document_path']) || !empty($xmlData['full_name']) || !empty($xmlData['photograph_path']);

// Per-session unlock state (keyed by user ID to guarantee strict user isolation)
$sessionUnlockKey = 'ekyc_unlocked_user_' . $userId;
$isUnlocked = !empty($_SESSION[$sessionUnlockKey]);

// 4. Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCSRFPost();

    // Lock Details action
    if (isset($_POST['lock_ekyc'])) {
        unset($_SESSION[$sessionUnlockKey]);
        $isUnlocked = false;
        setFlash('info', 'Your eKYC details have been locked.');
        header("Location: /digital-investor/investor/ekyc.php");
        exit;
    }

    // Verify Password action
    if (isset($_POST['verify_password'])) {
        $enteredPassword = $_POST['account_password'] ?? '';

        if (empty($enteredPassword)) {
            $error = 'Please enter your account password to verify your identity.';
        } elseif (!$userRow || empty($userRow['password'])) {
            $error = 'Account credentials not found. Please log in again.';
        } elseif (!password_verify($enteredPassword, $userRow['password'])) {
            // Secure verification against bcrypt hash using password_verify()
            $error = 'Incorrect account password. Please check your password and try again.';
            usleep(250000); // 250ms throttle to prevent brute-force
        } else {
            // Password verified successfully!
            $_SESSION[$sessionUnlockKey] = true;
            $isUnlocked = true;
            header("Location: /digital-investor/investor/ekyc.php");
            exit;
        }
    }

    // Request Resubmission action (separate action, only when rejected)
    if (isset($_POST['request_resubmission']) && $hasDocument && ($kyc['status'] ?? '') === 'Rejected') {
        $reason = trim($_POST['resubmit_reason'] ?? 'Investor requested document resubmission after administrative review.');
        createAdminNotification(
            $pdo,
            $userId,
            'kyc_resubmit_request',
            "Investor (ID {$userId}) requested eKYC resubmission. Note: {$reason}"
        );
        setFlash('success', 'Your resubmission request has been submitted to the administrator for review.');
        header("Location: /digital-investor/investor/ekyc.php");
        exit;
    }
}

// Render HTML
$pageTitle = "eKYC Document Verification - Digital Investor Onboarding";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-lg-10">
            <?php echo displayFlash(); ?>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show shadow-sm rounded-3 mb-4" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo e($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if (!$hasDocument): ?>
                <!-- ========================================================
                     SCENARIO 1: NO UPLOADED DOCUMENT / OCR RECORDS (Requirement 7)
                     ======================================================== -->
                <div class="card bg-white border border-2 border-secondary-subtle shadow-sm rounded-4 p-5 text-center my-4">
                    <div class="mx-auto mb-3 text-secondary">
                        <i class="fas fa-file-excel fa-4x text-muted"></i>
                    </div>
                    <h4 class="fw-bold text-dark mb-2">No Document Uploaded</h4>
                    <p class="text-muted mx-auto mb-4" style="max-width: 520px;">
                        No uploaded identity document or extracted eKYC records found. Please submit your identity document first. Document details are never fabricated.
                    </p>
                    <div class="d-flex justify-content-center gap-3">
                        <a href="/digital-investor/dashboard.php" class="btn btn-outline-secondary px-4 py-2 rounded-pill">
                            <i class="fas fa-arrow-left me-1"></i> Back to Dashboard
                        </a>
                        <a href="/digital-investor/dashboard.php" class="btn btn-primary px-4 py-2 fw-semibold rounded-pill">
                            <i class="fas fa-upload me-1"></i> Submit Identity Document
                        </a>
                    </div>
                </div>

            <?php elseif (!$isUnlocked): ?>
                <!-- ========================================================
                     SCENARIO 2: PASSWORD VERIFICATION PAGE (Requirements 1 & 9)
                     ======================================================== -->
                <div class="card border-0 shadow rounded-4 p-4 p-md-5 mx-auto my-3" style="max-width: 500px;">
                    <div class="text-center mb-4">
                        <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-inline-flex align-items-center justify-content-center p-3 mb-3" style="width: 70px; height: 70px;">
                            <i class="fas fa-shield-alt fa-2x"></i>
                        </div>
                        <h4 class="fw-bold text-dark mb-1">eKYC Security Verification</h4>
                        <p class="text-muted small mb-0">
                            Please verify your identity with your account password to view your document-extracted eKYC details.
                        </p>
                    </div>

                    <form action="/digital-investor/investor/ekyc.php" method="POST" id="passwordVerifyForm">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="verify_password" value="1">

                        <div class="mb-4">
                            <label for="account_password" class="form-label fw-semibold text-dark small">
                                Account Password <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="fas fa-lock text-muted"></i></span>
                                <input type="password" 
                                       class="form-control" 
                                       id="account_password" 
                                       name="account_password" 
                                       placeholder="Enter your registered account password" 
                                       autocomplete="current-password"
                                       required 
                                       autofocus>
                                <button type="button" class="btn btn-outline-secondary" id="togglePwBtn" onclick="togglePasswordVisibility()" title="Show/Hide Password">
                                    <i class="fas fa-eye" id="pwEyeIcon"></i>
                                </button>
                            </div>
                            <div class="form-text text-muted small mt-1">
                                <i class="fas fa-shield-halved text-success me-1"></i> Securely authenticated using <code>password_verify()</code>.
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary w-100 fw-bold py-2 rounded-3 shadow-sm mb-3" id="btnVerify">
                            <i class="fas fa-unlock me-2"></i> Verify Password &amp; View Details
                        </button>

                        <a href="/digital-investor/dashboard.php" class="btn btn-outline-secondary w-100 py-2 rounded-3">
                            <i class="fas fa-arrow-left me-1"></i> Back to Dashboard
                        </a>
                    </form>
                </div>

            <?php else: ?>
                <!-- ========================================================
                     SCENARIO 3: DOCUMENT-EXTRACTED OCR / XML DETAILS DISPLAY
                     (Requirements 2, 3, 4, 5, 6, 8, 10)
                     ======================================================== -->
                <?php
                    // Resolve photograph extracted from document ONLY (never profile photo)
                    $rawPhotoPath = $xmlData['photograph_path'] ?? ($kyc['photo_path'] ?? '');
                    $projectRoot  = dirname(__DIR__);
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

                    $hasPhoto = !empty($rawPhotoPath) && file_exists($photoAbsFull);
                    $displayPhotoPath = $hasPhoto ? $rawPhotoPath : null;

                    // Extract actual document values from XML (strictly NO profile fallbacks)
                    $extractedName    = !empty($xmlData['full_name']) ? $xmlData['full_name'] : null;
                    $extractedDob     = !empty($xmlData['dob']) ? $xmlData['dob'] : null;
                    $extractedGender  = !empty($xmlData['gender']) ? $xmlData['gender'] : null;
                    $extractedAddress = !empty($xmlData['address']) ? $xmlData['address'] : null;
                    $extractedMobile  = !empty($xmlData['mobile']) ? $xmlData['mobile'] : null;
                    $extractedIdRaw   = !empty($xmlData['id_number']) ? $xmlData['id_number'] : null;

                    // Mask ID number by default
                    $maskedIdNumber = !empty($extractedIdRaw) ? maskAadhaarNumber($extractedIdRaw, 'Aadhaar') : null;
                ?>

                <!-- Academic Demonstration / Document-Extracted Disclaimer Banner -->
                <div class="alert alert-secondary border-0 shadow-sm mb-4 d-flex align-items-center rounded-3">
                    <i class="fas fa-microchip fs-3 text-primary me-3 flex-shrink-0"></i>
                    <div>
                        <strong class="d-block text-dark">Document-Extracted Details (OCR &amp; XML Record)</strong>
                        <small class="text-muted">
                            These details were extracted directly from the uploaded identity document and stored in your secure account XML record. Offline document OCR extraction does not constitute live UIDAI government verification or official Aadhaar authentication.
                        </small>
                    </div>
                </div>

                <!-- Main Card Header -->
                <div class="card shadow-sm border-0 rounded-4 mb-4">
                    <div class="card-header bg-white py-3 border-bottom d-flex align-items-center justify-content-between flex-wrap gap-2">
                        <h4 class="fw-bold mb-0 text-primary">
                            <i class="fas fa-id-card me-2"></i>Demo eKYC Verification &amp; OCR Review
                        </h4>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-secondary">Module 2 of 4</span>
                            <?php echo renderStatusBadge($kyc['status'] ?? 'Submitted'); ?>
                            
                            <form action="/digital-investor/investor/ekyc.php" method="POST" class="d-inline m-0 ms-2">
                                <?php echo csrfField(); ?>
                                <button type="submit" name="lock_ekyc" value="1" class="btn btn-sm btn-outline-secondary rounded-pill" title="Lock details again">
                                    <i class="fas fa-lock me-1"></i> Lock Details
                                </button>
                            </form>
                        </div>
                    </div>

                    <div class="card-body p-4">

                        <!-- Rejection Notice (if rejected by administrator) -->
                        <?php if (($kyc['status'] ?? '') === 'Rejected'): ?>
                            <div class="alert alert-danger border border-danger rounded-4 d-flex align-items-start gap-3 mb-4 shadow-sm" role="alert">
                                <i class="fas fa-ban fa-lg text-danger mt-1 flex-shrink-0"></i>
                                <div class="w-100">
                                    <strong class="d-block mb-1">eKYC Submission Rejected by Administrator</strong>
                                    <p class="mb-2 small text-muted">
                                        Your previous eKYC document submission was reviewed and rejected.
                                        <?php if (!empty($kyc['admin_remarks'])): ?>
                                            <br><em class="mt-1 d-block text-danger-emphasis fw-semibold">Admin note: <?php echo e($kyc['admin_remarks']); ?></em>
                                        <?php endif; ?>
                                    </p>
                                    <div class="mt-2">
                                        <button type="button" class="btn btn-sm btn-danger rounded-pill fw-semibold" data-bs-toggle="modal" data-bs-target="#requestResubmitModal">
                                            <i class="fas fa-redo me-1"></i> Request Document Resubmission
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- eKYC Verification Details Box -->
                        <div class="card bg-white border border-2 border-primary-subtle shadow-sm rounded-4 p-4 mb-4">
                            <div class="border-bottom pb-3 mb-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
                                <div>
                                    <h4 class="fw-bold text-dark mb-0">
                                        <i class="fas fa-user-check text-primary me-2"></i>eKYC Verification Details
                                    </h4>
                                </div>
                                <div>
                                    <?php echo renderStatusBadge($kyc['status'] ?? 'Submitted'); ?>
                                </div>
                            </div>

                            <div class="row g-4">
                                <!-- LEFT COLUMN: EXTRACTED FACE PHOTOGRAPH FROM DOCUMENT ONLY -->
                                <div class="col-lg-5">
                                    <div class="card bg-light border p-4 rounded-4 h-100 shadow-sm text-center d-flex flex-column justify-content-center align-items-center">
                                        <h6 class="fw-bold text-dark border-bottom w-100 pb-2 mb-3">
                                            <i class="fas fa-camera text-primary me-2"></i>Extracted Document Photograph
                                        </h6>
                                        <?php if ($hasPhoto): ?>
                                            <div class="bg-white p-2 d-inline-block rounded-4 border shadow-sm my-2"
                                                 style="line-height:0;">
                                                <img src="/digital-investor/<?php echo e($displayPhotoPath); ?>?v=<?php echo time(); ?>"
                                                     alt="Extracted Document Photograph"
                                                     style="width: 165px; height: auto; max-height: 215px;
                                                            object-fit: contain;
                                                            object-position: center;
                                                            background: #f8f9fa;
                                                            display: block;"
                                                     class="rounded-3 shadow-none">
                                            </div>
                                            <p class="text-muted small mt-2 mb-0" style="font-size:0.72rem;">
                                                <i class="fas fa-info-circle me-1 text-secondary"></i>
                                                Photo extracted directly from uploaded identity document.
                                            </p>
                                        <?php else: ?>
                                            <div class="bg-white p-4 rounded-4 border shadow-sm my-2 text-center text-muted w-100">
                                                <i class="fas fa-user-circle fs-1 text-secondary mb-2 d-block"></i>
                                                <div class="alert alert-warning border border-warning rounded-3 mb-0 p-2 small">
                                                    <i class="fas fa-exclamation-triangle me-1"></i> Photograph not extracted from document.
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- RIGHT COLUMN: DOCUMENT EXTRACTED OCR / XML DETAILS -->
                                <div class="col-lg-7">
                                    <div class="card bg-white border border-2 border-primary-subtle p-3 p-md-4 rounded-4 shadow-sm h-100 d-flex flex-column justify-content-center">
                                        <div class="table-responsive">
                                            <table class="table table-bordered align-middle small mb-0">
                                                <tbody>
                                                    <!-- Full Name extracted from document -->
                                                    <tr>
                                                        <th class="bg-light" style="width: 32%;">Full Name</th>
                                                        <td>
                                                            <?php if (!empty($extractedName)): ?>
                                                                <strong class="text-success"><?php echo e($extractedName); ?></strong>
                                                            <?php else: ?>
                                                                <span class="badge bg-warning-subtle text-warning-emphasis border border-warning">Not extracted</span>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>

                                                    <!-- Date of Birth extracted from document -->
                                                    <tr>
                                                        <th class="bg-light">Date of Birth</th>
                                                        <td>
                                                            <?php if (!empty($extractedDob)): ?>
                                                                <strong class="text-success"><?php echo e(formatDobDisplay($extractedDob)); ?></strong>
                                                            <?php else: ?>
                                                                <span class="badge bg-warning-subtle text-warning-emphasis border border-warning">Not extracted</span>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>

                                                    <!-- Gender extracted from document -->
                                                    <tr>
                                                        <th class="bg-light">Gender</th>
                                                        <td>
                                                            <?php if (!empty($extractedGender)): ?>
                                                                <strong class="text-success"><?php echo e($extractedGender); ?></strong>
                                                            <?php else: ?>
                                                                <span class="badge bg-warning-subtle text-warning-emphasis border border-warning">Not extracted</span>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>

                                                    <!-- Full Address extracted from document -->
                                                    <tr>
                                                        <th class="bg-light">Full Address</th>
                                                        <td>
                                                            <?php if (!empty($extractedAddress)): ?>
                                                                <span class="text-success"><?php echo e($extractedAddress); ?></span>
                                                            <?php else: ?>
                                                                <span class="badge bg-warning-subtle text-warning-emphasis border border-warning">Not extracted</span>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>

                                                    <!-- Mobile Number extracted from document -->
                                                    <tr>
                                                        <th class="bg-light">Mobile Number</th>
                                                        <td>
                                                            <?php if (!empty($extractedMobile)): ?>
                                                                <span class="text-success fw-bold"><?php echo e($extractedMobile); ?></span>
                                                            <?php else: ?>
                                                                <span class="badge bg-warning-subtle text-warning-emphasis border border-warning">Not extracted</span>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>

                                                    <!-- ID Number extracted from document (masked by default) -->
                                                    <tr>
                                                        <th class="bg-light">ID Number</th>
                                                        <td>
                                                            <?php if (!empty($maskedIdNumber)): ?>
                                                                <code class="text-primary fw-bold" id="userAadhaarDisplay"><?php echo e($maskedIdNumber); ?></code>
                                                                <button type="button" class="btn btn-link btn-sm p-0 ms-1" onclick="toggleAadhaarVisibility('<?php echo e($extractedIdRaw); ?>', '<?php echo e($maskedIdNumber); ?>')">
                                                                    <small id="toggleAadhaarBtn">[Show]</small>
                                                                </button>
                                                            <?php else: ?>
                                                                <span class="badge bg-warning-subtle text-warning-emphasis border border-warning">Not extracted</span>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Footer Navigation (No file upload, no editable form) -->
                        <div class="d-flex justify-content-between align-items-center border-top pt-3">
                            <a href="/digital-investor/dashboard.php" class="btn btn-outline-secondary">
                                <i class="fas fa-arrow-left me-1"></i> Back to Dashboard
                            </a>
                            <form action="/digital-investor/investor/ekyc.php" method="POST" class="m-0">
                                <?php echo csrfField(); ?>
                                <button type="submit" name="lock_ekyc" value="1" class="btn btn-outline-primary">
                                    <i class="fas fa-lock me-1"></i> Lock Details
                                </button>
                            </form>
                        </div>

                    </div>
                </div>

                <!-- Modal: Request Resubmission (Only when rejected) -->
                <?php if (($kyc['status'] ?? '') === 'Rejected'): ?>
                <div class="modal fade" id="requestResubmitModal" tabindex="-1" aria-labelledby="resubmitModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content rounded-4 border-0 shadow">
                            <div class="modal-header bg-danger text-white">
                                <h5 class="modal-title fw-bold" id="resubmitModalLabel">
                                    <i class="fas fa-redo me-2"></i>Request Document Resubmission
                                </h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <form action="/digital-investor/investor/ekyc.php" method="POST">
                                <?php echo csrfField(); ?>
                                <div class="modal-body p-4">
                                    <p class="text-dark mb-3">
                                        Send a resubmission request to the administrator. Once approved, you will be notified to provide an updated identity document.
                                    </p>
                                    <div class="mb-3">
                                        <label for="resubmit_reason" class="form-label fw-semibold">Note to Administrator (Optional)</label>
                                        <textarea class="form-control" id="resubmit_reason" name="resubmit_reason" rows="3" placeholder="Provide any additional context or clarification regarding your resubmission..."></textarea>
                                    </div>
                                </div>
                                <div class="modal-footer border-top d-flex justify-content-between">
                                    <button type="button" class="btn btn-outline-secondary rounded-pill" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" name="request_resubmission" value="1" class="btn btn-danger fw-bold rounded-pill px-4">
                                        <i class="fas fa-paper-plane me-1"></i> Submit Request
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

            <?php endif; ?>

        </div>
    </div>
</div>

<script>
// Show/Hide Password Toggle
function togglePasswordVisibility() {
    var field = document.getElementById('account_password');
    var icon  = document.getElementById('pwEyeIcon');
    if (!field) return;
    if (field.type === 'password') {
        field.type = 'text';
        icon.classList.replace('fa-eye', 'fa-eye-slash');
    } else {
        field.type = 'password';
        icon.classList.replace('fa-eye-slash', 'fa-eye');
    }
}

// Show/Hide Masked Aadhaar Number
function toggleAadhaarVisibility(fullVal, maskedVal) {
    var el = document.getElementById('userAadhaarDisplay');
    var btn = document.getElementById('toggleAadhaarBtn');
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
