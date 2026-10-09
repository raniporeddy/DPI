<?php
// dashboard.php
// Investor Dashboard

$pageTitle = "Investor Dashboard - Digital Investor Onboarding";
require_once __DIR__ . '/includes/header.php';
requireRole('investor');

$userId = $_SESSION['user_id'];

// Refresh application status
refreshApplicationStatus($pdo, $userId);

// Fetch Application
$app = getOrInitApplication($pdo, $userId);

// Fetch Profile
$stmtProf = $pdo->prepare("SELECT * FROM investor_profiles WHERE user_id = ?");
$stmtProf->execute([$userId]);
$profile = $stmtProf->fetch();

// Fetch KYC
$stmtKyc = $pdo->prepare("SELECT * FROM kyc_verification WHERE user_id = ?");
$stmtKyc->execute([$userId]);
$kyc = $stmtKyc->fetch();

// Fetch Documents
$stmtDocs = $pdo->prepare("SELECT * FROM documents WHERE user_id = ? ORDER BY created_at DESC");
$stmtDocs->execute([$userId]);
$documents = $stmtDocs->fetchAll();

// Fetch eSign
$stmtSign = $pdo->prepare("SELECT * FROM esignatures WHERE user_id = ?");
$stmtSign->execute([$userId]);
$esign = $stmtSign->fetch();

// Fetch latest eSign Request
$userRequests = getESignRequestsForUser($pdo, $userId);
$latestReq = !empty($userRequests) ? $userRequests[0] : null;

// Calculate Progress %
$stepsCompleted = ($app['profile_completed'] ? 1 : 0) + 
                 ($app['kyc_completed'] ? 1 : 0) + 
                 ($app['docs_completed'] ? 1 : 0) + 
                 ($app['esign_completed'] ? 1 : 0);
$progressPercent = ($stepsCompleted / 4) * 100;
?>

<div class="container py-4">
    <?php echo displayFlash(); ?>

    <!-- Welcome Header Card -->
    <div class="card shadow-sm border-0 mb-4 text-white rounded-4 overflow-hidden" style="background: linear-gradient(135deg, #071527 0%, #0f2b48 100%);">
        <div class="card-body p-4 p-md-5">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <div class="d-flex align-items-center gap-3">
                        <div class="bg-warning text-dark rounded-circle d-flex align-items-center justify-content-center fw-bold fs-2 shadow-sm" style="width: 64px; height: 64px; flex-shrink: 0;">
                            <?php echo strtoupper(substr($profile['full_name'] ?? $_SESSION['email'], 0, 1)); ?>
                        </div>
                        <div>
                            <h3 class="fw-bold mb-1 text-white">Welcome, <?php echo e($profile['full_name'] ?? $_SESSION['email']); ?></h3>
                            <p class="mb-0 text-white-50">
                                Application ID: <span class="badge bg-warning text-dark font-monospace fs-6 px-3 py-1"><?php echo e($app['application_id']); ?></span>
                            </p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 text-md-end mt-3 mt-md-0">
                    <span class="d-block text-white-50 small mb-1">Overall Application Status</span>
                    <?php echo renderStatusBadge($app['status']); ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Onboarding Progress Stepper -->
    <div class="card shadow-sm border-0 rounded-4 mb-4">
        <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
            <h5 class="fw-bold mb-0 text-primary"><i class="fas fa-tasks me-2"></i>Onboarding Progress Tracker</h5>
            <span class="fw-bold text-muted fs-7"><i class="fas fa-chart-line text-success me-1"></i><?php echo round($progressPercent); ?>% Completed</span>
        </div>
        <div class="card-body p-4">
            <!-- Animated Progress Bar -->
            <div class="progress mb-4 rounded-pill" style="height: 12px; background-color: #e2e8f0;">
                <div class="progress-bar progress-bar-striped progress-bar-animated bg-success rounded-pill" role="progressbar" style="width: <?php echo $progressPercent; ?>%;" aria-valuenow="<?php echo $progressPercent; ?>" aria-valuemin="0" aria-valuemax="100"></div>
            </div>

            <!-- Stepper Steps -->
            <div class="stepper-wrapper">
                <div class="stepper-item completed">
                    <div class="step-counter"><i class="fas fa-check"></i></div>
                    <div class="step-name">1. Registration</div>
                </div>
                <div class="stepper-item <?php echo $app['profile_completed'] ? 'completed' : 'active'; ?>">
                    <div class="step-counter"><?php echo $app['profile_completed'] ? '<i class="fas fa-check"></i>' : '2'; ?></div>
                    <div class="step-name">2. Profile</div>
                </div>
                <div class="stepper-item <?php echo $app['kyc_completed'] ? 'completed' : ($app['profile_completed'] ? 'active' : ''); ?>">
                    <div class="step-counter"><?php echo $app['kyc_completed'] ? '<i class="fas fa-check"></i>' : '3'; ?></div>
                    <div class="step-name">3. eKYC</div>
                </div>
                <div class="stepper-item <?php echo $app['docs_completed'] ? 'completed' : ($app['kyc_completed'] ? 'active' : ''); ?>">
                    <div class="step-counter"><?php echo $app['docs_completed'] ? '<i class="fas fa-check"></i>' : '4'; ?></div>
                    <div class="step-name">4. Documents</div>
                </div>
                <div class="stepper-item <?php echo $app['esign_completed'] ? 'completed' : ($app['docs_completed'] ? 'active' : ''); ?>">
                    <div class="step-counter"><?php echo $app['esign_completed'] ? '<i class="fas fa-check"></i>' : '5'; ?></div>
                    <div class="step-name">5. eSign</div>
                </div>
                <div class="stepper-item <?php echo $app['status'] === 'Approved' ? 'completed' : ($app['status'] === 'Under Review' || $app['status'] === 'Submitted' ? 'active' : ''); ?>">
                    <div class="step-counter"><?php echo $app['status'] === 'Approved' ? '<i class="fas fa-check"></i>' : '6'; ?></div>
                    <div class="step-name">6. Approval</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Onboarding Module Grid Cards -->
    <div class="row g-4 mb-4">
        <!-- 1. Profile Module -->
        <div class="col-md-6 col-lg-3">
            <div class="card h-100 card-hover rounded-4">
                <div class="card-body p-4 d-flex flex-column">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div class="bg-primary-subtle text-primary rounded-circle p-3">
                            <i class="fas fa-user-edit fs-4"></i>
                        </div>
                        <?php echo renderStatusBadge($app['profile_completed'] ? 'Verified' : 'Pending'); ?>
                    </div>
                    <h5 class="fw-bold mb-2">Investor Profile</h5>
                    <p class="text-muted small flex-grow-1">Personal, contact, and employment information details.</p>
                    <a href="/digital-investor/investor/profile.php" class="btn btn-outline-primary w-100 mt-2 fw-semibold rounded-3">
                        <?php echo $app['profile_completed'] ? '<i class="fas fa-edit me-1"></i> Edit Profile' : '<i class="fas fa-plus me-1"></i> Fill Profile'; ?>
                    </a>
                </div>
            </div>
        </div>

        <!-- 2. Demo eKYC Module -->
        <div class="col-md-6 col-lg-3">
            <div class="card h-100 card-hover rounded-4">
                <div class="card-body p-4 d-flex flex-column">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div class="bg-info-subtle text-info-emphasis rounded-circle p-3">
                            <i class="fas fa-id-card fs-4"></i>
                        </div>
                        <?php if (($kyc['status'] ?? '') === 'Verified'): ?>
                            <span class="badge bg-success"><i class="fas fa-check-circle me-1"></i> eKYC Verified</span>
                        <?php else: ?>
                            <?php echo renderStatusBadge($kyc['status'] ?? 'Pending'); ?>
                        <?php endif; ?>
                    </div>
                    <h5 class="fw-bold mb-1">Demo eKYC</h5>
                    <p class="text-muted small flex-grow-1 mb-2">Aadhaar document verification & automatic photo extraction.</p>
                    
                    <?php if (!empty($kyc['photo_path']) && file_exists(__DIR__ . '/' . $kyc['photo_path'])): ?>
                        <div class="bg-light p-2 rounded text-center mb-2 border">
                            <img src="/digital-investor/<?php echo e($kyc['photo_path']); ?>" alt="Aadhaar Verified Photo" style="max-height: 55px;" class="rounded border bg-white">
                            <small class="d-block text-muted fs-8 mt-1">Aadhaar Verified Photo</small>
                        </div>
                    <?php elseif (!empty($kyc['document_path'])): ?>
                        <small class="text-muted d-block text-center mb-2 fs-8"><i class="fas fa-exclamation-triangle text-warning me-1"></i> Unable to extract portrait from this document</small>
                    <?php endif; ?>

                    <a href="/digital-investor/investor/ekyc.php" class="btn btn-outline-info text-dark w-100 mt-auto fw-semibold rounded-3">
                        <?php echo isset($kyc['status']) && $kyc['status'] !== 'Pending' ? '<i class="fas fa-eye me-1"></i> View Demo eKYC' : '<i class="fas fa-upload me-1"></i> Submit Demo eKYC'; ?>
                    </a>
                </div>
            </div>
        </div>

        <!-- 3. Documents Module -->
        <div class="col-md-6 col-lg-3">
            <div class="card h-100 card-hover rounded-4">
                <div class="card-body p-4 d-flex flex-column">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div class="bg-warning-subtle text-warning-emphasis rounded-circle p-3">
                            <i class="fas fa-folder-open fs-4"></i>
                        </div>
                        <?php echo renderStatusBadge($app['docs_completed'] ? 'Submitted' : 'Pending'); ?>
                    </div>
                    <h5 class="fw-bold mb-2">Documents</h5>
                    <p class="text-muted small flex-grow-1">Upload required identity & address supporting documents.</p>
                    <a href="/digital-investor/investor/documents.php" class="btn btn-outline-warning text-dark w-100 mt-2 fw-semibold rounded-3">
                        <i class="fas fa-folder me-1"></i> Manage Docs (<?php echo count($documents); ?>)
                    </a>
                </div>
            </div>
        </div>

        <!-- 4. eSign Module -->
        <div class="col-md-6 col-lg-3">
            <div class="card h-100 card-hover rounded-4">
                <div class="card-body p-4 d-flex flex-column">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div class="bg-success-subtle text-success rounded-circle p-3">
                            <i class="fas fa-file-signature fs-4"></i>
                        </div>
                        <?php 
                            $eStatus = $app['esign_completed'] ? 'Signed' : ($latestReq['status'] ?? ($esign['status'] ?? 'Pending'));
                            echo renderStatusBadge($eStatus);
                        ?>
                    </div>
                    <h5 class="fw-bold mb-1">eSign Workflow</h5>
                    <p class="text-muted small mb-2">Email eSign request workflow & digital signature execution.</p>
                    
                    <?php if ($latestReq): ?>
                        <div class="bg-light p-2 rounded text-start mb-2 border small fs-8">
                            <div class="text-truncate"><strong>To:</strong> <?php echo e($latestReq['recipient_email']); ?></div>
                            <div><strong>Sent:</strong> <?php echo date('M d, H:i', strtotime($latestReq['created_at'])); ?></div>
                            <div>
                                <strong>Status:</strong> 
                                <span class="badge <?php echo $latestReq['status'] === 'Signed' ? 'bg-success' : ($latestReq['status'] === 'Rejected' ? 'bg-danger' : 'bg-warning text-dark'); ?>">
                                    <?php echo e($latestReq['status']); ?>
                                </span>
                                <?php if (!empty($latestReq['signed_doc_path'])): ?>
                                    <span class="text-success ms-1 fw-bold"><i class="fas fa-check-double"></i> Doc Available</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <a href="/digital-investor/investor/esign.php" class="btn btn-outline-success w-100 mt-auto fw-semibold rounded-3">
                        <i class="fas fa-pen-fancy me-1"></i> Manage eSign Requests
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Application Submission Banner / Action -->
    <div class="card shadow-sm border-0 rounded-4 bg-white p-4">
        <div class="row align-items-center">
            <div class="col-lg-8">
                <h5 class="fw-bold mb-1"><i class="fas fa-paper-plane text-primary me-2"></i>Final Application Submission</h5>
                <?php if ($app['status'] === 'Approved'): ?>
                    <p class="text-success mb-0 fw-semibold"><i class="fas fa-check-circle me-1"></i> Congratulations! Your digital investor application has been reviewed and APPROVED by Admin.</p>
                    <?php if (!empty($app['admin_remarks'])): ?>
                        <small class="text-muted d-block mt-1">Admin Remarks: <?php echo e($app['admin_remarks']); ?></small>
                    <?php endif; ?>
                <?php elseif ($app['status'] === 'Under Review' || $app['status'] === 'Submitted'): ?>
                    <p class="text-warning-emphasis mb-0 fw-semibold"><i class="fas fa-clock me-1"></i> Your application has been submitted and is currently UNDER REVIEW by Admin verification officers.</p>
                <?php elseif ($app['status'] === 'Rejected'): ?>
                    <p class="text-danger mb-0 fw-semibold"><i class="fas fa-times-circle me-1"></i> Application rejected. Remarks: <?php echo e($app['admin_remarks'] ?? 'Please update information.'); ?></p>
                <?php else: ?>
                    <p class="text-muted mb-0">Complete all 4 required modules (Profile, eKYC, Documents, eSign) to submit your application for Admin approval.</p>
                <?php endif; ?>
            </div>
            <div class="col-lg-4 text-lg-end mt-3 mt-lg-0">
                <?php if (in_array($app['status'], ['Draft', 'Rejected'])): ?>
                    <form action="/digital-investor/investor/submit_application.php" method="POST">
                        <?php echo csrfField(); ?>
                        <button type="submit" class="btn btn-success btn-lg fw-bold px-4 rounded-pill shadow-sm" <?php echo ($stepsCompleted < 4) ? 'disabled' : ''; ?>>
                            <i class="fas fa-check-double me-1"></i> Submit Application
                        </button>
                    </form>
                    <?php if ($stepsCompleted < 4): ?>
                        <small class="text-danger d-block mt-1 fs-8">Complete all 4 modules above to enable submission.</small>
                    <?php endif; ?>
                <?php else: ?>
                    <a href="/digital-investor/paperless.php" class="btn btn-outline-dark btn-lg fw-bold px-4 rounded-pill">
                        <i class="fas fa-file-contract me-1"></i> View Paperless Dossier
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
