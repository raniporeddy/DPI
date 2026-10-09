<?php
// index.php
// Landing Page for Digital Investor Onboarding System

$pageTitle = "Home - Digital Investor Onboarding";
require_once __DIR__ . '/includes/header.php';
?>

<!-- Hero Banner Section -->
<section class="hero-banner text-center">
    <div class="container">
        <span class="badge bg-warning text-dark px-3 py-2 rounded-pill fs-7 mb-3 fw-bold shadow-sm">
            <i class="fas fa-university me-1"></i> Academic Project Prototype
        </span>
        <h1 class="hero-title">Digital Investor Onboarding System</h1>
        <p class="hero-subtitle">
            A complete paperless investor onboarding platform integrating Single Sign-On (SSO), Demo eKYC identity verification, HTML5 Canvas eSign digital signatures, and a centralized Paperless Office repository.
        </p>
        
        <div class="d-flex justify-content-center gap-3 flex-wrap">
            <?php if ($isLoggedIn): ?>
                <a href="<?php echo $userRole === 'admin' ? '/digital-investor/admin/dashboard.php' : '/digital-investor/dashboard.php'; ?>" class="btn btn-warning btn-lg rounded-pill px-4 fw-bold shadow">
                    <i class="fas fa-columns me-2"></i> Go to Dashboard
                </a>
            <?php else: ?>
                <a href="/digital-investor/register.php" class="btn btn-warning btn-lg rounded-pill px-4 fw-bold shadow">
                    <i class="fas fa-user-plus me-2"></i> Get Started as Investor
                </a>
                <a href="/digital-investor/sso_login.php" class="btn btn-outline-light btn-lg rounded-pill px-4 fw-bold">
                    <i class="fas fa-key me-2"></i> Continue with SSO
                </a>
                <a href="/digital-investor/login.php" class="btn btn-primary btn-lg rounded-pill px-4 fw-bold border-light">
                    <i class="fas fa-sign-in-alt me-2"></i> Investor / Admin Login
                </a>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- System Features Section -->
<section class="py-5">
    <div class="container py-4">
        <div class="text-center mb-5">
            <h6 class="text-primary fw-bold text-uppercase tracking-wider">System Architecture</h6>
            <h2 class="fw-bold">Core Integrated Modules</h2>
            <p class="text-muted max-w-600 mx-auto">Explore the five pillars powering our paperless digital investor onboarding workflow.</p>
        </div>

        <div class="row g-4">
            <!-- 1. SSO Module -->
            <div class="col-md-6 col-lg-3">
                <div class="card h-100 card-hover p-4 text-center">
                    <div class="bg-primary text-white rounded-circle d-inline-flex align-items-center justify-content-center mx-auto mb-3" style="width: 60px; height: 60px;">
                        <i class="fas fa-key fs-4"></i>
                    </div>
                    <h5 class="fw-bold mb-2">1. SSO Authentication</h5>
                    <p class="text-muted small">Seamless single sign-on prototype with OAuth2 architecture readiness for identity validation.</p>
                </div>
            </div>

            <!-- 2. eKYC Verification -->
            <div class="col-md-6 col-lg-3">
                <div class="card h-100 card-hover p-4 text-center">
                    <div class="bg-info text-dark rounded-circle d-inline-flex align-items-center justify-content-center mx-auto mb-3" style="width: 60px; height: 60px;">
                        <i class="fas fa-id-card fs-4"></i>
                    </div>
                    <h5 class="fw-bold mb-2">2. Demo eKYC Module</h5>
                    <p class="text-muted small">Academic identity verification module for Aadhaar/PAN upload, photo verification, and document audit.</p>
                </div>
            </div>

            <!-- 3. eSign Digital Signature -->
            <div class="col-md-6 col-lg-3">
                <div class="card h-100 card-hover p-4 text-center">
                    <div class="bg-success text-white rounded-circle d-inline-flex align-items-center justify-content-center mx-auto mb-3" style="width: 60px; height: 60px;">
                        <i class="fas fa-signature fs-4"></i>
                    </div>
                    <h5 class="fw-bold mb-2">3. HTML5 Canvas eSign</h5>
                    <p class="text-muted small">Interactive canvas for drawing signature via mouse/touch, converting signature to base64 PNG with timestamp audit trail.</p>
                </div>
            </div>

            <!-- 4. Paperless Office -->
            <div class="col-md-6 col-lg-3">
                <div class="card h-100 card-hover p-4 text-center">
                    <div class="bg-dark text-white rounded-circle d-inline-flex align-items-center justify-content-center mx-auto mb-3" style="width: 60px; height: 60px;">
                        <i class="fas fa-folder-open fs-4"></i>
                    </div>
                    <h5 class="fw-bold mb-2">4. Paperless Repository</h5>
                    <p class="text-muted small">100% digital document vault generating instant investor verification dossiers without physical paper waste.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Onboarding Workflow Stepper Overview -->
<section class="bg-white py-5 border-top border-bottom">
    <div class="container py-3">
        <div class="text-center mb-4">
            <h3 class="fw-bold"><i class="fas fa-route text-primary me-2"></i>Investor Onboarding Journey</h3>
            <p class="text-muted">A streamlined 6-step lifecycle from registration to final admin approval.</p>
        </div>

        <div class="row align-items-center justify-content-center g-3 text-center">
            <div class="col-6 col-md-2">
                <div class="p-3 rounded bg-light border">
                    <span class="badge bg-primary rounded-circle mb-2 fs-6">1</span>
                    <h6 class="fw-bold mb-0">Registration</h6>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="p-3 rounded bg-light border">
                    <span class="badge bg-primary rounded-circle mb-2 fs-6">2</span>
                    <h6 class="fw-bold mb-0">Profile</h6>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="p-3 rounded bg-light border">
                    <span class="badge bg-primary rounded-circle mb-2 fs-6">3</span>
                    <h6 class="fw-bold mb-0">eKYC</h6>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="p-3 rounded bg-light border">
                    <span class="badge bg-primary rounded-circle mb-2 fs-6">4</span>
                    <h6 class="fw-bold mb-0">Documents</h6>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="p-3 rounded bg-light border">
                    <span class="badge bg-primary rounded-circle mb-2 fs-6">5</span>
                    <h6 class="fw-bold mb-0">eSign</h6>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="p-3 rounded bg-light border">
                    <span class="badge bg-success rounded-circle mb-2 fs-6">6</span>
                    <h6 class="fw-bold mb-0">Approval</h6>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Demo Credentials Information Callout -->
<section class="py-5 bg-light">
    <div class="container">
        <div class="card border-warning shadow-sm">
            <div class="card-body p-4">
                <div class="row align-items-center">
                    <div class="col-lg-8">
                        <h4 class="fw-bold text-dark mb-2"><i class="fas fa-key text-warning me-2"></i>Academic Demo Credentials</h4>
                        <p class="text-muted mb-0">For evaluation and academic testing, pre-configured demo accounts are available:</p>
                        <ul class="list-inline mt-2 mb-0">
                            <li class="list-inline-item me-4"><strong>Demo Admin:</strong> <code>admin@digitalinvestor.com</code> / <code>Admin@123</code></li>
                            <li class="list-inline-item me-4"><strong>Approved Investor:</strong> <code>investor@example.com</code> / <code>Investor@123</code></li>
                            <li class="list-inline-item"><strong>Pending Investor:</strong> <code>john.doe@example.com</code> / <code>Investor@123</code></li>
                        </ul>
                    </div>
                    <div class="col-lg-4 text-lg-end mt-3 mt-lg-0">
                        <a href="/digital-investor/login.php" class="btn btn-warning fw-bold px-4 py-2">
                            <i class="fas fa-sign-in-alt me-1"></i> Try Demo Login
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
