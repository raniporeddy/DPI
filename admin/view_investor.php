<?php
// admin/view_investor.php
// Detailed Investor Dossier View & Verification Page

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/project_info.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth_check.php';

requireAdmin();

$investorUserId = (int)($_GET['id'] ?? 0);

if (!$investorUserId) {
    setFlash('danger', 'Invalid investor user specified.');
    header("Location: /digital-investor/admin/investors.php");
    exit;
}

// Fetch User
$stmtUser = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmtUser->execute([$investorUserId]);
$user = $stmtUser->fetch();

if (!$user) {
    setFlash('danger', 'Investor not found.');
    header("Location: /digital-investor/admin/investors.php");
    exit;
}

// Fetch Profile
$stmtProf = $pdo->prepare("SELECT * FROM investor_profiles WHERE user_id = ?");
$stmtProf->execute([$investorUserId]);
$profile = $stmtProf->fetch();

// Fetch Application
$app = getOrInitApplication($pdo, $investorUserId);

// Fetch eKYC
$stmtKyc = $pdo->prepare("SELECT * FROM kyc_verification WHERE user_id = ?");
$stmtKyc->execute([$investorUserId]);
$kyc = $stmtKyc->fetch();

// -- Admin-side mismatch detection (same logic as investor/ekyc.php)
$adminNameMismatch      = false;
$adminResubmitRequested = false;
if ($kyc) {
    $adm_profileName = trim($profile['full_name'] ?? $kyc['full_name'] ?? '');
    $adm_ocrName     = trim($kyc['ocr_name'] ?? '');
    if (!empty($adm_ocrName) && !empty($adm_profileName)) {
        $adm_normP = strtolower(preg_replace('/\s+/', ' ', $adm_profileName));
        $adm_normO = strtolower(preg_replace('/\s+/', ' ', $adm_ocrName));
        $adm_isMatch = ($adm_normP === $adm_normO)
                    || str_contains($adm_normP, $adm_normO)
                    || str_contains($adm_normO, $adm_normP);
        if (!$adm_isMatch) {
            $adm_dist = levenshtein($adm_normP, $adm_normO);
            $adm_maxL = max(strlen($adm_normP), strlen($adm_normO));
            if ($adm_dist > (int) ceil($adm_maxL * 0.40)) {
                $adminNameMismatch = true;
            }
        }
    }
    $adminResubmitRequested = str_starts_with($kyc['admin_remarks'] ?? '', '[RESUBMIT_REQUESTED]');
}

// Fetch Documents
$stmtDocs = $pdo->prepare("SELECT * FROM documents WHERE user_id = ? ORDER BY created_at DESC");
$stmtDocs->execute([$investorUserId]);
$documents = $stmtDocs->fetchAll();

// Fetch eSign
$stmtSign = $pdo->prepare("SELECT * FROM esignatures WHERE user_id = ?");
$stmtSign->execute([$investorUserId]);
$esign = $stmtSign->fetch();

// Fetch Admin Audit History
$stmtAudit = $pdo->prepare("
    SELECT a.*, u.email as admin_email
    FROM admin_actions a
    JOIN users u ON a.admin_id = u.id
    WHERE a.application_id = ?
    ORDER BY a.created_at DESC
");
$stmtAudit->execute([$app['application_id']]);
$auditLogs = $stmtAudit->fetchAll();

// Render HTML View
$pageTitle = "View Investor Dossier - Admin Console";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container py-4">
    <?php echo displayFlash(); ?>

    <!-- Header Actions -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <a href="/digital-investor/admin/investors.php" class="btn btn-outline-secondary btn-sm mb-2">
                <i class="fas fa-arrow-left me-1"></i> Back to Investors Directory
            </a>
            <h3 class="fw-bold mb-0">
                Investor Dossier: <span class="text-primary font-monospace"><?php echo e($app['application_id']); ?></span>
            </h3>
        </div>

        <div class="d-flex gap-2">
            <?php echo renderStatusBadge($app['status']); ?>
            <a href="/digital-investor/paperless.php?user_id=<?php echo $investorUserId; ?>" target="_blank" class="btn btn-outline-dark fw-bold">
                <i class="fas fa-print me-1"></i> Paperless Summary Dossier
            </a>
        </div>
    </div>

    <div class="row g-4">
        <!-- Left Column: Investor Information Tabs -->
        <div class="col-lg-8">
            <!-- 1. Profile Details Card -->
            <div class="card shadow-sm border-0 rounded-4 mb-4">
                <div class="card-header bg-white py-3 border-bottom d-flex align-items-center justify-content-between">
                    <h5 class="fw-bold mb-0 text-primary"><i class="fas fa-user me-2"></i>1. Investor Profile Details</h5>
                    <span class="badge bg-light text-dark border"><?php echo e($user['email']); ?></span>
                </div>
                <div class="card-body p-4">
                    <?php if (!$profile): ?>
                        <p class="text-muted mb-0">Investor has not filled profile details yet.</p>
                    <?php else: ?>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <small class="text-muted d-block fs-8">Full Name</small>
                                <strong class="fs-6 text-dark"><?php echo e($profile['full_name']); ?></strong>
                            </div>
                            <div class="col-md-3">
                                <small class="text-muted d-block fs-8">Date of Birth</small>
                                <strong><?php echo e($profile['dob']); ?></strong>
                            </div>
                            <div class="col-md-3">
                                <small class="text-muted d-block fs-8">Gender</small>
                                <strong><?php echo e($profile['gender']); ?></strong>
                            </div>

                            <div class="col-md-6">
                                <small class="text-muted d-block fs-8">Mobile Number</small>
                                <strong><?php echo e($profile['mobile']); ?></strong>
                            </div>
                            <div class="col-md-6">
                                <small class="text-muted d-block fs-8">Occupation</small>
                                <strong><?php echo e($profile['occupation']); ?></strong>
                            </div>

                            <div class="col-12">
                                <small class="text-muted d-block fs-8">Permanent Address</small>
                                <span><?php echo e($profile['address']); ?>, <?php echo e($profile['city']); ?>, <?php echo e($profile['state']); ?> - <?php echo e($profile['pin_code']); ?></span>
                            </div>

                            <div class="col-md-6">
                                <small class="text-muted d-block fs-8">Primary ID Type & Number</small>
                                <span class="badge bg-secondary"><?php echo e($profile['id_type']); ?></span>
                                <strong class="font-monospace ms-1"><?php echo e($profile['id_number']); ?></strong>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 2. Demo eKYC Submission Card & Aadhaar Verified Photo -->
            <div class="card shadow-sm border-0 rounded-4 mb-4 <?php echo $adminNameMismatch ? 'border border-danger border-2' : ''; ?>">
                <div class="card-header bg-white py-3 border-bottom d-flex align-items-center justify-content-between">
                    <h5 class="fw-bold mb-0 text-info"><i class="fas fa-id-card me-2"></i>2. Demo eKYC Review &amp; Aadhaar Verified Photo</h5>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-secondary me-1">Demo eKYC</span>
                        <?php echo renderStatusBadge($kyc['status'] ?? 'Pending'); ?>
                        <?php if ($adminNameMismatch): ?>
                        <span class="badge bg-danger"><i class="fas fa-triangle-exclamation me-1"></i>Name Mismatch</span>
                        <?php endif; ?>
                        <?php if ($adminResubmitRequested): ?>
                        <span class="badge bg-warning text-dark"><i class="fas fa-flag me-1"></i>Reset Requested</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body p-4">
                    <?php if (!$kyc): ?>
                        <p class="text-muted mb-0">No eKYC document submitted by investor yet.</p>
                    <?php else: ?>
                        <?php 
                            $adminDocExt = strtolower(pathinfo($kyc['document_path'] ?? '', PATHINFO_EXTENSION));
                            $adminHasPhoto = !empty($kyc['photo_path']) && file_exists(dirname(__DIR__) . '/' . $kyc['photo_path']);
                            $adminHasDoc = !empty($kyc['document_path']) && file_exists(dirname(__DIR__) . '/' . $kyc['document_path']);
                        ?>

                        <!-- 1. Uploaded Aadhaar / ID Card Image Section -->
                        <div class="card bg-light border p-3 rounded-4 shadow-sm mb-4">
                            <div class="d-flex justify-content-between align-items-center border-bottom pb-2 mb-3">
                                <h6 class="fw-bold text-dark mb-0">
                                    <i class="fas fa-file-image text-primary me-2"></i>Uploaded Aadhaar / Identity Document
                                </h6>
                                <span class="badge bg-secondary"><?php echo e($kyc['id_type']); ?></span>
                            </div>

                            <?php if ($adminHasDoc): ?>
                                <div class="text-center bg-white p-3 rounded-4 border">
                                    <?php if ($adminDocExt === 'pdf'): ?>
                                        <iframe src="/digital-investor/investor/view_document.php?kyc_user_id=<?php echo $investorUserId; ?>" style="width: 100%; height: 350px; border: none;" class="rounded-3"></iframe>
                                    <?php else: ?>
                                        <img src="/digital-investor/investor/view_document.php?kyc_user_id=<?php echo $investorUserId; ?>" 
                                             alt="Uploaded Aadhaar Document" 
                                             class="img-fluid rounded-3 border shadow-sm" 
                                             style="max-height: 380px; object-fit: contain;">
                                    <?php endif; ?>
                                    <div class="mt-2 text-center">
                                        <a href="/digital-investor/investor/view_document.php?kyc_user_id=<?php echo $investorUserId; ?>" target="_blank" class="btn btn-outline-primary btn-sm fw-bold">
                                            <i class="fas fa-external-link-alt me-1"></i> View Full Document Copy
                                        </a>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-secondary mb-0">No document image uploaded.</div>
                            <?php endif; ?>
                        </div>

                        <!-- 2. Side-by-Side OCR Text Details & Extracted Face Card -->
                        <div class="card bg-white border border-2 border-primary-subtle p-4 rounded-4 shadow-sm">
                            <div class="d-flex justify-content-between align-items-center border-bottom pb-3 mb-4 flex-wrap gap-2">
                                <h6 class="fw-bold text-dark mb-0">
                                    <i class="fas fa-microchip text-primary me-2"></i>Extracted OCR Identity & Face Details
                                </h6>
                                <button type="button" class="btn btn-sm btn-outline-primary fw-semibold" data-bs-toggle="modal" data-bs-target="#correctKycModal">
                                    <i class="fas fa-user-edit me-1"></i> Review / Correct Details
                                </button>
                            </div>

                            <div class="row g-4 align-items-center">
                                <!-- Image Face Column -->
                                <div class="col-md-4 text-center border-end">
                                    <div class="p-3 rounded-4 bg-light border d-inline-block w-100">
                                        <small class="d-block fw-bold text-muted uppercase fs-8 mb-2">
                                            <i class="fas fa-camera text-primary me-1"></i> Extracted Photo Portrait
                                        </small>

                                        <?php if ($adminHasPhoto): ?>
                                            <div class="bg-white p-2 d-inline-block rounded-4 border shadow-sm my-1">
                                                <img src="/digital-investor/<?php echo e($kyc['photo_path']); ?>?v=<?php echo time(); ?>" 
                                                     alt="Extracted Face Photo" 
                                                     style="width: 130px; height: 160px; object-fit: cover; object-position: center;" 
                                                     class="rounded-3">
                                            </div>
                                            <small class="d-block text-success fw-bold mt-2">
                                                <i class="fas fa-crop-alt me-1"></i> Face Extracted
                                            </small>
                                        <?php else: ?>
                                            <div class="alert alert-warning border-0 p-3 my-2 rounded-3 text-start small">
                                                <i class="fas fa-exclamation-triangle text-warning me-1"></i> Photograph not detected from document.
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Side-by-side OCR Table Column -->
                                <div class="col-md-8">
                                    <?php 
                                        $adminMaskedAadhaar = maskAadhaarNumber($kyc['id_number'], $kyc['id_type']);
                                        $adminOcrMaskedAadhaar = !empty($kyc['ocr_id_number']) ? maskAadhaarNumber($kyc['ocr_id_number'], $kyc['id_type']) : null;
                                    ?>
                                    <div class="table-responsive">
                                        <table class="table table-bordered align-middle small mb-0">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Field</th>
                                                    <th>OCR Extracted Result</th>
                                                    <th>Submitted Details</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr>
                                                    <th class="bg-light">Full Name</th>
                                                    <td>
                                                        <?php if (!empty($kyc['ocr_name'])): ?>
                                                            <strong class="text-success"><?php echo e($kyc['ocr_name']); ?></strong>
                                                        <?php else: ?>
                                                            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning"><i class="fas fa-exclamation-triangle me-1"></i> Needs Review</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><strong><?php echo e($kyc['full_name']); ?></strong></td>
                                                </tr>
                                                <tr>
                                                    <th class="bg-light">DOB / Age</th>
                                                    <td>
                                                        <?php if (!empty($kyc['ocr_dob'])): ?>
                                                            <strong class="text-success"><?php echo e(formatDobDisplay($kyc['ocr_dob'])); ?></strong>
                                                        <?php else: ?>
                                                            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning"><i class="fas fa-exclamation-triangle me-1"></i> Needs Review</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><strong><?php echo e(formatDobDisplay($kyc['dob'])); ?></strong> (<?php echo e(calculateAge($kyc['dob'])); ?>)</td>
                                                </tr>
                                                <tr>
                                                    <th class="bg-light">Gender</th>
                                                    <td>
                                                        <?php if (!empty($kyc['ocr_gender'])): ?>
                                                            <strong class="text-success"><?php echo e($kyc['ocr_gender']); ?></strong>
                                                        <?php else: ?>
                                                            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning"><i class="fas fa-exclamation-triangle me-1"></i> Needs Review</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><strong><?php echo e($profile['gender'] ?? 'Not Specified'); ?></strong></td>
                                                </tr>
                                                <tr>
                                                    <th class="bg-light">ID Number</th>
                                                    <td>
                                                        <?php if (!empty($adminOcrMaskedAadhaar)): ?>
                                                            <code class="text-success fw-bold"><?php echo e($adminOcrMaskedAadhaar); ?></code>
                                                        <?php else: ?>
                                                            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning"><i class="fas fa-exclamation-triangle me-1"></i> Needs Review</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <code class="text-primary fw-bold" id="adminAadhaarDisp"><?php echo e($adminMaskedAadhaar); ?></code>
                                                        <small class="text-muted ms-2 fst-italic">(masked for privacy)</small>
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <th class="bg-light">Address</th>
                                                    <td>
                                                        <?php if (!empty($kyc['ocr_address'])): ?>
                                                            <span class="text-success"><?php echo e($kyc['ocr_address']); ?></span>
                                                        <?php else: ?>
                                                            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning"><i class="fas fa-exclamation-triangle me-1"></i> Needs Review</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><small><?php echo e($kyc['address']); ?></small></td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 3. Uploaded Documents List Card -->
            <div class="card shadow-sm border-0 rounded-4 mb-4">
                <div class="card-header bg-white py-3 border-bottom d-flex align-items-center justify-content-between">
                    <h5 class="fw-bold mb-0 text-warning-emphasis"><i class="fas fa-folder-open me-2"></i>3. Uploaded Digital Documents</h5>
                    <span class="badge bg-warning text-dark"><?php echo count($documents); ?> Files</span>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($documents)): ?>
                        <p class="text-muted p-4 mb-0">No documents uploaded.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light fs-7 text-uppercase text-muted">
                                    <tr>
                                        <th class="ps-4">Document Title</th>
                                        <th>Category</th>
                                        <th>Upload Date</th>
                                        <th>Status</th>
                                        <th class="text-end pe-4">File</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($documents as $d): ?>
                                        <tr>
                                            <td class="ps-4"><strong><?php echo e($d['doc_name']); ?></strong></td>
                                            <td><span class="badge bg-light text-dark border"><?php echo e($d['doc_type']); ?></span></td>
                                            <td><small class="text-muted"><?php echo date('M d, Y H:i', strtotime($d['created_at'])); ?></small></td>
                                            <td><?php echo renderStatusBadge($d['status']); ?></td>
                                            <td class="text-end pe-4">
                                                <a href="/digital-investor/<?php echo e($d['file_path']); ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                                    <i class="fas fa-eye me-1"></i> View
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 4. Digital Signature eSign Card -->
            <div class="card shadow-sm border-0 rounded-4 mb-4">
                <div class="card-header bg-white py-3 border-bottom d-flex align-items-center justify-content-between">
                    <h5 class="fw-bold mb-0 text-success"><i class="fas fa-signature me-2"></i>4. Recorded HTML5 eSignature</h5>
                    <?php echo renderStatusBadge($esign['status'] ?? 'Pending'); ?>
                </div>
                <div class="card-body p-4 text-center">
                    <?php if (!$esign || $esign['status'] !== 'Signed'): ?>
                        <p class="text-muted mb-0">Digital signature has not been drawn and executed by investor yet.</p>
                    <?php else: ?>
                        <div class="bg-white p-3 d-inline-block rounded border shadow-sm mb-3">
                            <img src="/digital-investor/<?php echo e($esign['signature_path']); ?>" alt="Investor Signature" style="max-height: 120px;">
                        </div>
                        <div class="small text-muted">
                            <span class="me-3"><i class="fas fa-clock me-1"></i> Signed at: <strong><?php echo e($esign['signed_at']); ?></strong></span>
                            <span><i class="fas fa-network-wired me-1"></i> IP Address: <code><?php echo e($esign['ip_address']); ?></code></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Right Column: Admin Approval Action Panel & Audit Logs -->
        <div class="col-lg-4">
            <!-- Verification Action Form Card -->
            <div class="card shadow-sm border-0 rounded-4 mb-4 border-top border-primary border-4">
                <div class="card-header bg-white py-3">
                    <h5 class="fw-bold mb-0 text-dark"><i class="fas fa-user-check text-primary me-2"></i>Admin Verification Actions</h5>
                </div>
                <div class="card-body p-4">
                    <?php if ($adminNameMismatch || $adminResubmitRequested): ?>
                    <!-- Mismatch / Reset Request Alert -->
                    <div class="alert alert-<?php echo $adminResubmitRequested ? 'warning' : 'danger'; ?> border border-<?php echo $adminResubmitRequested ? 'warning' : 'danger'; ?> rounded-3 mb-4 small">
                        <div class="fw-bold mb-1">
                            <i class="fas fa-<?php echo $adminResubmitRequested ? 'flag' : 'triangle-exclamation'; ?> me-1"></i>
                            <?php echo $adminResubmitRequested ? 'Investor Requested Rejection &amp; Resubmission' : 'Document Name Mismatch Detected'; ?>
                        </div>
                        <?php if ($adminResubmitRequested): ?>
                        <p class="mb-2">The investor has flagged their submission as mismatched and requested that it be rejected so they can re-upload their own document.</p>
                        <?php endif; ?>
                        <?php if ($adminNameMismatch): ?>
                        <p class="mb-2">
                            Profile name: <strong><?php echo e($profile['full_name'] ?? ''); ?></strong> &nbsp;|&nbsp;
                            OCR extracted: <strong class="text-danger"><?php echo e($kyc['ocr_name'] ?? 'N/A'); ?></strong>
                        </p>
                        <?php endif; ?>
                        <?php if (in_array($kyc['status'] ?? '', ['Submitted', 'Pending'], true)): ?>
                        <hr class="my-2">
                        <form action="/digital-investor/admin/approvals.php" method="POST" class="m-0">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="investor_user_id" value="<?php echo $investorUserId; ?>">
                            <input type="hidden" name="action_type" value="reset_kyc_for_resubmission">
                            <div class="mb-2">
                                <label class="form-label fw-semibold small mb-1">Rejection Reason (sent to investor)</label>
                                <input type="text" name="admin_remarks" class="form-control form-control-sm"
                                       placeholder="e.g. Document does not match account. Please resubmit your own Aadhaar."
                                       value="Document rejected: uploaded document does not match your account. Please resubmit your own valid identity document.">
                            </div>
                            <button type="submit"
                                    class="btn btn-warning btn-sm fw-bold rounded-pill"
                                    onclick="return confirm('Reset this investor\'s eKYC submission? This will allow them to re-upload their own document. Original files are preserved on disk for audit purposes.')"
                                    id="resetKycBtn">
                                <i class="fas fa-redo me-1"></i> Reset for Resubmission
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <form action="/digital-investor/admin/approvals.php" method="POST">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="investor_user_id" value="<?php echo $investorUserId; ?>">

                        <!-- 1. Verify eKYC Action -->
                        <div class="mb-4 bg-light p-3 rounded-3 border">
                            <label class="fw-bold text-dark d-block mb-2">1. eKYC Verification Action</label>
                            <div class="d-flex gap-2">
                                <button type="submit" name="action_type" value="verify_kyc" class="btn btn-success btn-sm w-50 fw-semibold">
                                    <i class="fas fa-check-circle me-1"></i> Verify eKYC
                                </button>
                                <button type="submit" name="action_type" value="reject_kyc" class="btn btn-outline-danger btn-sm w-50 fw-semibold">
                                    <i class="fas fa-times-circle me-1"></i> Reject eKYC
                                </button>
                            </div>
                        </div>

                        <!-- 2. Final Application Action -->
                        <div class="mb-3 bg-light p-3 rounded-3 border">
                            <label class="fw-bold text-dark d-block mb-2">2. Final Application Action</label>
                            <div class="d-flex gap-2">
                                <button type="submit" name="action_type" value="approve_application" class="btn btn-primary btn-sm w-50 fw-bold">
                                    <i class="fas fa-award me-1"></i> Approve App
                                </button>
                                <button type="submit" name="action_type" value="reject_application" class="btn btn-danger btn-sm w-50 fw-bold">
                                    <i class="fas fa-ban me-1"></i> Reject App
                                </button>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="admin_remarks" class="form-label fw-semibold">Admin Remarks / Audit Comments</label>
                            <textarea class="form-control" id="admin_remarks" name="admin_remarks" rows="3" placeholder="Enter reason or verification notes..."><?php echo e($app['admin_remarks'] ?? ''); ?></textarea>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Admin Audit Trail History Card -->
            <div class="card shadow-sm border-0 rounded-4">
                <div class="card-header bg-white py-3 border-bottom">
                    <h6 class="fw-bold mb-0 text-dark"><i class="fas fa-history me-2 text-primary"></i>Audit Trail History</h6>
                </div>
                <div class="card-body p-3">
                    <?php if (empty($auditLogs)): ?>
                        <small class="text-muted d-block text-center py-3">No admin actions recorded yet for this application.</small>
                    <?php else: ?>
                        <ul class="list-group list-group-flush small">
                            <?php foreach ($auditLogs as $log): ?>
                                <li class="list-group-item px-0 py-2">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <strong class="text-primary"><?php echo e($log['action_type']); ?></strong>
                                        <span class="text-muted fs-8"><?php echo date('M d, H:i', strtotime($log['created_at'])); ?></span>
                                    </div>
                                    <p class="mb-0 text-muted fs-8"><?php echo e($log['remarks']); ?></p>
                                    <small class="text-muted">By <?php echo e($log['admin_email']); ?></small>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Authorized Reviewer Correct & Verify eKYC Details -->
<div class="modal fade" id="correctKycModal" tabindex="-1" aria-labelledby="correctKycModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content rounded-4 border-0 shadow">
            <div class="modal-header bg-light border-bottom">
                <h5 class="modal-title fw-bold text-dark" id="correctKycModalLabel">
                    <i class="fas fa-user-edit text-primary me-2"></i>Review & Correct eKYC Details
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="/digital-investor/admin/approvals.php" method="POST">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action_type" value="correct_kyc">
                <input type="hidden" name="investor_user_id" value="<?php echo $investorUserId; ?>">

                <div class="modal-body p-4">
                    <div class="alert alert-info border-0 shadow-sm rounded-3 small mb-4">
                        <i class="fas fa-info-circle me-1"></i> As an authorized reviewer, you can inspect and rectify OCR extraction discrepancies before approving or saving identity records.
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="corrected_full_name" class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="corrected_full_name" name="corrected_full_name" required value="<?php echo e($kyc['ocr_name'] ?: $kyc['full_name']); ?>">
                            <small class="text-muted fs-8">OCR extracted: <strong><?php echo e($kyc['ocr_name'] ?: 'Needs Review'); ?></strong></small>
                        </div>
                        <div class="col-md-6">
                            <label for="corrected_dob" class="form-label fw-semibold">Date of Birth <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="corrected_dob" name="corrected_dob" required value="<?php echo e($kyc['ocr_dob'] ?: $kyc['dob']); ?>">
                            <small class="text-muted fs-8">OCR extracted: <strong><?php echo e($kyc['ocr_dob'] ?: 'Needs Review'); ?></strong></small>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="corrected_gender" class="form-label fw-semibold">Gender <span class="text-danger">*</span></label>
                            <?php $currentGen = $kyc['ocr_gender'] ?: ($investor['gender'] ?? 'Female'); ?>
                            <select class="form-select" id="corrected_gender" name="corrected_gender" required>
                                <option value="Female" <?php echo strcasecmp($currentGen, 'Female') === 0 ? 'selected' : ''; ?>>Female</option>
                                <option value="Male" <?php echo strcasecmp($currentGen, 'Male') === 0 ? 'selected' : ''; ?>>Male</option>
                                <option value="Transgender" <?php echo strcasecmp($currentGen, 'Transgender') === 0 ? 'selected' : ''; ?>>Transgender</option>
                                <option value="Other" <?php echo strcasecmp($currentGen, 'Other') === 0 ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="corrected_id_number" class="form-label fw-semibold">12-Digit Aadhaar Number <span class="text-danger">*</span></label>
                            <input type="text" class="form-control font-monospace" id="corrected_id_number" name="corrected_id_number" required value="<?php echo e($kyc['ocr_id_number'] ?: $kyc['id_number']); ?>">
                            <small class="text-muted fs-8">OCR extracted: <strong><?php echo e($kyc['ocr_id_number'] ?: 'Needs Review'); ?></strong></small>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="corrected_address" class="form-label fw-semibold">Address <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="corrected_address" name="corrected_address" rows="3" required><?php echo e($kyc['ocr_address'] ?: $kyc['address']); ?></textarea>
                        <small class="text-muted fs-8">OCR extracted: <strong><?php echo e($kyc['ocr_address'] ?: 'Needs Review'); ?></strong></small>
                    </div>

                    <div class="mb-2">
                        <label for="correct_admin_remarks" class="form-label fw-semibold">Reviewer Notes / Verification Remarks</label>
                        <textarea class="form-control" id="correct_admin_remarks" name="admin_remarks" rows="2" placeholder="e.g. Details verified against document and confirmed."><?php echo e($kyc['admin_remarks'] ?? ''); ?></textarea>
                    </div>
                </div>

                <div class="modal-footer bg-light border-top d-flex justify-content-between">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <div class="d-flex gap-2">
                        <button type="submit" name="and_verify" value="0" class="btn btn-outline-primary fw-semibold">
                            <i class="fas fa-save me-1"></i> Save Corrected Details
                        </button>
                        <button type="submit" name="and_verify" value="1" class="btn btn-success fw-bold shadow-sm">
                            <i class="fas fa-check-circle me-1"></i> Save & Verify eKYC
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
