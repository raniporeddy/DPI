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
            <div class="card shadow-sm border-0 rounded-4 mb-4">
                <div class="card-header bg-white py-3 border-bottom d-flex align-items-center justify-content-between">
                    <h5 class="fw-bold mb-0 text-info"><i class="fas fa-id-card me-2"></i>2. Demo eKYC Review & Aadhaar Verified Photo</h5>
                    <div>
                        <span class="badge bg-secondary me-2">Demo eKYC</span>
                        <?php echo renderStatusBadge($kyc['status'] ?? 'Pending'); ?>
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
                                        <iframe src="/digital-investor/<?php echo e($kyc['document_path']); ?>" style="width: 100%; height: 350px; border: none;" class="rounded-3"></iframe>
                                    <?php else: ?>
                                        <img src="/digital-investor/<?php echo e($kyc['document_path']); ?>?v=<?php echo time(); ?>" 
                                             alt="Uploaded Aadhaar Document" 
                                             class="img-fluid rounded-3 border shadow-sm" 
                                             style="max-height: 380px; object-fit: contain;">
                                    <?php endif; ?>
                                    <div class="mt-2 text-center">
                                        <a href="/digital-investor/<?php echo e($kyc['document_path']); ?>" target="_blank" class="btn btn-outline-primary btn-sm fw-bold">
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
                            <h6 class="fw-bold text-dark border-bottom pb-3 mb-4">
                                <i class="fas fa-microchip text-primary me-2"></i>Extracted OCR Identity & Face Details
                            </h6>

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
                                                            <span class="badge bg-danger-subtle text-danger border border-danger"><i class="fas fa-times-circle me-1"></i> Not detected</span>
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
                                                            <span class="badge bg-danger-subtle text-danger border border-danger"><i class="fas fa-times-circle me-1"></i> Not detected</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><strong><?php echo e(formatDobDisplay($kyc['dob'])); ?></strong> (<?php echo e(calculateAge($kyc['dob'])); ?>)</td>
                                                </tr>
                                                <tr>
                                                    <th class="bg-light">ID Number</th>
                                                    <td>
                                                        <?php if (!empty($adminOcrMaskedAadhaar)): ?>
                                                            <code class="text-success fw-bold"><?php echo e($adminOcrMaskedAadhaar); ?></code>
                                                        <?php else: ?>
                                                            <span class="badge bg-danger-subtle text-danger border border-danger"><i class="fas fa-times-circle me-1"></i> Not detected</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <code class="text-primary fw-bold" id="adminAadhaarDisp"><?php echo e($adminMaskedAadhaar); ?></code>
                                                        <button type="button" class="btn btn-link btn-sm p-0 ms-1" onclick="const e=document.getElementById('adminAadhaarDisp'); const b=document.getElementById('adminToggleBtn'); if(e.innerText==='<?php echo e($adminMaskedAadhaar); ?>'){e.innerText='<?php echo e($kyc['id_number']); ?>';b.innerText='[Hide]';}else{e.innerText='<?php echo e($adminMaskedAadhaar); ?>';b.innerText='[Show]';}">
                                                            <small id="adminToggleBtn">[Show]</small>
                                                        </button>
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <th class="bg-light">Address</th>
                                                    <td>
                                                        <?php if (!empty($kyc['ocr_address'])): ?>
                                                            <span class="text-success"><?php echo e($kyc['ocr_address']); ?></span>
                                                        <?php else: ?>
                                                            <span class="badge bg-danger-subtle text-danger border border-danger"><i class="fas fa-times-circle me-1"></i> Not detected</span>
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
