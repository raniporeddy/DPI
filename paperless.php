<?php
// paperless.php
// Paperless Office Digital Document Repository & Summary Sheet

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/project_info.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth_check.php';

requireLogin();

// Retrieve user role from session safely
$userRole = $_SESSION['role'] ?? '';

// If admin is viewing a specific investor, allow user_id GET parameter
$targetUserId = $_SESSION['user_id'];
if ($userRole === 'admin' && isset($_GET['user_id'])) {
    $targetUserId = (int)$_GET['user_id'];
}

// Fetch Profile
$stmtProf = $pdo->prepare("SELECT * FROM investor_profiles WHERE user_id = ?");
$stmtProf->execute([$targetUserId]);
$profile = $stmtProf->fetch();

// Fetch Application
$app = getOrInitApplication($pdo, $targetUserId);

// Fetch eKYC
$stmtKyc = $pdo->prepare("SELECT * FROM kyc_verification WHERE user_id = ?");
$stmtKyc->execute([$targetUserId]);
$kyc = $stmtKyc->fetch();

// Fetch Documents
$stmtDocs = $pdo->prepare("SELECT * FROM documents WHERE user_id = ? ORDER BY created_at DESC");
$stmtDocs->execute([$targetUserId]);
$documents = $stmtDocs->fetchAll();

// Fetch eSign
$stmtSign = $pdo->prepare("SELECT * FROM esignatures WHERE user_id = ?");
$stmtSign->execute([$targetUserId]);
$esign = $stmtSign->fetch();

// Render HTML View
$pageTitle = "Paperless Office Repository - Digital Investor Onboarding";
require_once __DIR__ . '/includes/header.php';
?>

<div class="container py-4">
    <?php echo displayFlash(); ?>

    <!-- Print Action Header -->
    <div class="d-flex justify-content-between align-items-center mb-4 d-print-none">
        <div>
            <h3 class="fw-bold text-dark mb-1"><i class="fas fa-file-contract text-success me-2"></i>Paperless Investor Dossier</h3>
            <p class="text-muted small mb-0">100% digital paperless office record repository and verification summary.</p>
        </div>
        <button onclick="window.print()" class="btn btn-dark fw-bold px-4 rounded-pill shadow-sm">
            <i class="fas fa-print me-1"></i> Print / Save PDF Dossier
        </button>
    </div>

    <!-- Official Paperless Dossier Summary Document -->
    <div class="card shadow-lg border-0 rounded-4 p-4 p-md-5 bg-white border-top border-4 border-success">
        <!-- Document Header Header -->
        <div class="row align-items-center border-bottom pb-4 mb-4">
            <div class="col-sm-8">
                <div class="d-flex align-items-center gap-3">
                    <div class="bg-primary text-white rounded-circle p-3">
                        <i class="fas fa-university fs-2"></i>
                    </div>
                    <div>
                        <h4 class="fw-bold text-primary mb-0">DIGITAL INVESTOR ONBOARDING SYSTEM</h4>
                        <small class="text-muted uppercase fw-semibold">Paperless Investor Dossier & Verification Certificate</small>
                    </div>
                </div>
            </div>
            <div class="col-sm-4 text-sm-end mt-3 mt-sm-0">
                <span class="d-block text-muted small">Application ID</span>
                <span class="badge bg-dark font-monospace fs-6 px-3 py-2"><?php echo e($app['application_id']); ?></span>
                <div class="mt-1"><?php echo renderStatusBadge($app['status']); ?></div>
            </div>
        </div>

        <!-- 1. Investor Identity Overview & Aadhaar Verified Photo -->
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="fw-bold text-dark mb-0"><i class="fas fa-user-check text-primary me-2"></i>1. Investor Profile & Aadhaar Verified Photo</h5>
            <?php 
                $photoPath = $kyc['photo_path'] ?? '';
                $hasValidPhoto = !empty($photoPath) && file_exists(__DIR__ . '/' . $photoPath);
            ?>
            <?php if ($hasValidPhoto): ?>
                <div class="text-end">
                    <small class="d-block text-muted fs-8 fw-semibold mb-1">Aadhaar Verified Photo</small>
                    <img src="/digital-investor/<?php echo e($photoPath); ?>" alt="Aadhaar Verified Photo" style="max-height: 95px; width: 75px; object-fit: cover;" class="rounded border p-1 bg-white shadow-sm">
                </div>
            <?php elseif (!empty($kyc['document_path'])): ?>
                <div class="text-end">
                    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning"><i class="fas fa-exclamation-triangle me-1"></i> Face photo extraction failed. Please upload a clear Aadhaar image.</span>
                </div>
            <?php else: ?>
                <div class="text-end">
                    <span class="badge bg-light text-muted border"><i class="fas fa-user-circle me-1"></i> Default Profile Avatar (eKYC Photo Pending)</span>
                </div>
            <?php endif; ?>
        </div>

        <div class="table-responsive mb-4">
            <table class="table table-bordered align-middle">
                <tbody>
                    <tr>
                        <th class="bg-light w-25">Full Name</th>
                        <td class="w-25"><strong><?php echo e($profile['full_name'] ?? $kyc['full_name'] ?? 'N/A'); ?></strong></td>
                        <th class="bg-light w-25">Date of Birth (DOB) / Age</th>
                        <td class="w-25">
                            <strong><?php echo e(formatDobDisplay($profile['dob'] ?? $kyc['dob'] ?? '')); ?></strong>
                            <span class="badge bg-primary-subtle text-primary border border-primary ms-2 fw-bold"><?php echo e(calculateAge($profile['dob'] ?? $kyc['dob'] ?? '')); ?></span>
                        </td>
                    </tr>
                    <tr>
                        <th class="bg-light">Gender</th>
                        <td><?php echo e($profile['gender'] ?? 'N/A'); ?></td>
                        <th class="bg-light">Mobile Number</th>
                        <td><?php echo e($profile['mobile'] ?? 'N/A'); ?></td>
                    </tr>
                    <tr>
                        <th class="bg-light">Residential Address</th>
                        <td colspan="3"><?php echo e($profile['address'] ?? 'N/A'); ?>, <?php echo e($profile['city'] ?? ''); ?>, <?php echo e($profile['state'] ?? ''); ?> - <?php echo e($profile['pin_code'] ?? ''); ?></td>
                    </tr>
                    <tr>
                        <th class="bg-light">Occupation</th>
                        <td><?php echo e($profile['occupation'] ?? 'N/A'); ?></td>
                        <th class="bg-light">Primary ID (<?php echo e($profile['id_type'] ?? $kyc['id_type'] ?? 'Aadhaar'); ?>)</th>
                        <td><strong class="font-monospace text-primary"><?php echo e($profile['id_number'] ?? $kyc['id_number'] ?? 'N/A'); ?></strong></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- 2. Demo eKYC & eSign Compliance Status -->
        <h5 class="fw-bold text-dark mb-3"><i class="fas fa-shield-alt text-info me-2"></i>2. Demo eKYC Compliance Verification</h5>
        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <div class="p-3 bg-light rounded border">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <strong class="text-dark">Demo eKYC Status:</strong>
                        <?php if (($kyc['status'] ?? '') === 'Verified'): ?>
                            <span class="badge bg-success"><i class="fas fa-check-circle me-1"></i> eKYC Verified</span>
                        <?php else: ?>
                            <?php echo renderStatusBadge($kyc['status'] ?? 'Pending'); ?>
                        <?php endif; ?>
                    </div>
                    <small class="d-block text-muted">ID Number: <code><?php echo e(maskAadhaarNumber($kyc['id_number'] ?? '', $kyc['id_type'] ?? 'Aadhaar')); ?></code></small>
                    <small class="d-block text-muted">Verified Date: <?php echo e($kyc['verified_at'] ?? 'Pending'); ?></small>
                </div>
            </div>
            <div class="col-md-6">
                <div class="p-3 bg-light rounded border">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <strong class="text-dark">HTML5 eSign Status:</strong>
                        <?php echo renderStatusBadge($esign['status'] ?? 'Pending'); ?>
                    </div>
                    <small class="d-block text-muted">Signed Timestamp: <?php echo e($esign['signed_at'] ?? 'N/A'); ?></small>
                    <small class="d-block text-muted">IP Audit Trail: <code><?php echo e($esign['ip_address'] ?? 'N/A'); ?></code></small>
                </div>
            </div>
        </div>

        <!-- 3. Recorded Digital Signature Image -->
        <?php if ($esign && $esign['status'] === 'Signed'): ?>
            <h5 class="fw-bold text-dark mb-3"><i class="fas fa-signature text-success me-2"></i>3. Executed Electronic Signature</h5>
            <div class="p-3 bg-light rounded border text-center mb-4">
                <img src="/digital-investor/<?php echo e($esign['signature_path']); ?>" alt="Executed eSign" style="max-height: 110px;" class="bg-white p-2 border rounded">
                <div class="mt-2 text-muted fs-8">
                    Digitally signed & encrypted by <?php echo e($profile['full_name'] ?? 'Investor'); ?> on <?php echo e($esign['signed_at']); ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- 4. Digital Documents Vault Table -->
        <h5 class="fw-bold text-dark mb-3"><i class="fas fa-folder text-warning me-2"></i>4. Digitized Supporting Documents Repository</h5>
        <div class="table-responsive mb-4">
            <table class="table table-sm table-hover align-middle border">
                <thead class="table-light fs-7 text-uppercase">
                    <tr>
                        <th class="ps-3">Doc Type</th>
                        <th>Document Title</th>
                        <th>Upload Date</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($documents)): ?>
                        <tr><td colspan="4" class="text-center text-muted py-3">No uploaded digital documents.</td></tr>
                    <?php else: ?>
                        <?php foreach ($documents as $doc): ?>
                            <tr>
                                <td class="ps-3"><span class="badge bg-light text-dark border"><?php echo e($doc['doc_type']); ?></span></td>
                                <td><strong><?php echo e($doc['doc_name']); ?></strong></td>
                                <td><small class="text-muted"><?php echo e($doc['created_at']); ?></small></td>
                                <td><?php echo renderStatusBadge($doc['status']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Dossier Footer Disclaimer -->
        <div class="border-top pt-3 text-center text-muted fs-8">
            <p class="mb-0">This document is generated by the Digital Investor Onboarding System (Paperless Office Module).</p>
            <p class="mb-0">Academic Project Prototype. Verified on <?php echo date('Y-m-d H:i:s'); ?>.</p>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
