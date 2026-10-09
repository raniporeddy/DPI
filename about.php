<?php
// about.php
// About Project & Academic Credits Page

$pageTitle = "About Project - Academic Details";
require_once __DIR__ . '/includes/header.php';
$academic = getAcademicDetails();
?>

<div class="container py-5">
    <!-- Academic Header Banner -->
    <div class="card shadow-sm border-0 mb-4 bg-gradient text-white rounded-4" style="background: linear-gradient(135deg, #0f2b48 0%, #1c4974 100%);">
        <div class="card-body p-4 p-md-5">
            <span class="badge bg-warning text-dark px-3 py-2 rounded-pill fs-7 mb-3 fw-bold shadow-sm">
                <i class="fas fa-graduation-cap me-1"></i> Academic Final-Year Project
            </span>
            <h2 class="fw-bold mb-2"><?php echo e($academic['project_title']); ?></h2>
            <p class="text-white-50 lead mb-0">
                Department of <?php echo e($academic['department']); ?> (Academic Year: <?php echo e($academic['academic_year']); ?>)
            </p>
        </div>
    </div>

    <div class="row g-4">
        <!-- Left Column: Student & Guide Information Cards -->
        <div class="col-lg-5">
            <!-- 1. Student Information Card -->
            <div class="card shadow-sm border-0 rounded-4 mb-4 border-top border-primary border-4">
                <div class="card-header bg-white py-3 border-bottom">
                    <h5 class="fw-bold mb-0 text-primary"><i class="fas fa-user-graduate me-2"></i>Student Details</h5>
                </div>
                <div class="card-body p-4">
                    <div class="mb-3">
                        <small class="text-muted d-block fs-8 text-uppercase fw-semibold">Student Name</small>
                        <strong class="fs-5 text-dark"><?php echo e($academic['student_name']); ?></strong>
                    </div>

                    <div class="mb-3">
                        <small class="text-muted d-block fs-8 text-uppercase fw-semibold">Register Number</small>
                        <span class="badge bg-dark font-monospace fs-6 px-3 py-2"><?php echo e($academic['register_number']); ?></span>
                    </div>

                    <div class="mb-3">
                        <small class="text-muted d-block fs-8 text-uppercase fw-semibold">Department</small>
                        <strong class="text-dark"><?php echo e($academic['department']); ?></strong>
                    </div>

                    <div class="mb-0">
                        <small class="text-muted d-block fs-8 text-uppercase fw-semibold">College / University Name</small>
                        <strong class="text-primary"><?php echo e($academic['college_name']); ?></strong>
                    </div>
                </div>
            </div>

            <!-- 2. Academic Project Supervision & Team Card -->
            <div class="card shadow-sm border-0 rounded-4 border-top border-info border-4">
                <div class="card-header bg-white py-3 border-bottom">
                    <h5 class="fw-bold mb-0 text-info"><i class="fas fa-users-cog me-2"></i>Guide & Team Information</h5>
                </div>
                <div class="card-body p-4">
                    <div class="mb-3">
                        <small class="text-muted d-block fs-8 text-uppercase fw-semibold">Project Guide Name</small>
                        <strong class="fs-6 text-dark"><i class="fas fa-chalkboard-teacher me-2 text-warning"></i><?php echo e($academic['guide_name']); ?></strong>
                    </div>

                    <div class="mb-3">
                        <small class="text-muted d-block fs-8 text-uppercase fw-semibold">Team Members</small>
                        <div class="p-3 bg-light rounded border font-monospace text-dark">
                            <?php echo e($academic['team_members']); ?>
                        </div>
                    </div>

                    <div class="mb-0">
                        <small class="text-muted d-block fs-8 text-uppercase fw-semibold">Academic Year</small>
                        <span class="badge bg-secondary"><?php echo e($academic['academic_year']); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Column: System Objectives & Architecture -->
        <div class="col-lg-7">
            <div class="card shadow-sm border-0 rounded-4 h-100">
                <div class="card-header bg-white py-3 border-bottom">
                    <h5 class="fw-bold mb-0 text-dark"><i class="fas fa-cogs me-2 text-primary"></i>Project Objectives & System Architecture</h5>
                </div>
                <div class="card-body p-4">
                    <h6 class="fw-bold text-dark mb-2">Project Overview</h6>
                    <p class="text-muted small">
                        The <strong>Digital Investor Onboarding System</strong> is designed to replace traditional paper-based investor registration with a 100% digital, paperless workflow. The system integrates Single Sign-On (SSO), demo electronic Know-Your-Customer (eKYC) identity document verification, interactive HTML5 Canvas electronic signature execution (eSign), digital document vaulting, and an Admin verification portal.
                    </p>

                    <h6 class="fw-bold text-dark mb-3 mt-4">Integrated System Modules</h6>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="p-3 bg-light rounded border h-100">
                                <h6 class="fw-bold text-primary mb-1"><i class="fas fa-key me-1"></i>1. SSO Module</h6>
                                <p class="text-muted fs-8 mb-0">Single Sign-On authentication prototype supporting instant 1-click login and OAuth2 architecture.</p>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="p-3 bg-light rounded border h-100">
                                <h6 class="fw-bold text-info mb-1"><i class="fas fa-id-card me-1"></i>2. eKYC Module</h6>
                                <p class="text-muted fs-8 mb-0">Academic demo identity verification module for Aadhaar/PAN upload and admin document review.</p>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="p-3 bg-light rounded border h-100">
                                <h6 class="fw-bold text-warning-emphasis mb-1"><i class="fas fa-folder-open me-1"></i>3. Document Management</h6>
                                <p class="text-muted fs-8 mb-0">Secure file upload handling for identity, address proof, and supporting documents.</p>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="p-3 bg-light rounded border h-100">
                                <h6 class="fw-bold text-success mb-1"><i class="fas fa-signature me-1"></i>4. HTML5 eSign Board</h6>
                                <p class="text-muted fs-8 mb-0">Interactive signature board exporting base64 PNG images with IP and timestamp audit trail.</p>
                            </div>
                        </div>

                        <div class="col-12">
                            <div class="p-3 bg-light rounded border">
                                <h6 class="fw-bold text-dark mb-1"><i class="fas fa-file-contract me-1"></i>5. Paperless Office & Admin Console</h6>
                                <p class="text-muted fs-8 mb-0">Centralized digital document repository and printable Paperless Investor Summary Dossier certificate.</p>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 pt-3 border-top text-center">
                        <a href="/digital-investor/index.php" class="btn btn-primary fw-bold me-2">
                            <i class="fas fa-home me-1"></i> Return Home
                        </a>
                        <a href="/digital-investor/login.php" class="btn btn-warning fw-bold text-dark">
                            <i class="fas fa-sign-in-alt me-1"></i> Go to Portal Login
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
