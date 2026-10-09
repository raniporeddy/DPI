<?php
// sso_login.php
// Simulated SSO Login Portal

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/project_info.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/sso_helper.php';

// Redirect if already logged in BEFORE rendering page
if (isLoggedIn()) {
    $userRole = $_SESSION['role'] ?? 'investor';
    header("Location: " . ($userRole === 'admin' ? '/digital-investor/admin/dashboard.php' : '/digital-investor/dashboard.php'));
    exit;
}

$error = '';

// Process POST request BEFORE HTML output
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCSRFPost();

    $providerKey = $_POST['sso_provider'] ?? 'investor_sso';
    $ssoEmail = trim($_POST['sso_email'] ?? '');
    $ssoName = trim($_POST['sso_name'] ?? 'SSO Investor User');

    if (empty($ssoEmail) || !filter_var($ssoEmail, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email for SSO authentication.';
    } else {
        SSOService::handleSSOCallback($pdo, $providerKey, $ssoEmail, $ssoName);
        setFlash('success', 'Authenticated successfully via Single Sign-On (' . e($providerKey) . ')!');
        
        header("Location: /digital-investor/dashboard.php");
        exit;
    }
}

// Render HTML View
$providers = SSOService::getProviders();
$pageTitle = "SSO Authentication - Digital Investor Onboarding";
require_once __DIR__ . '/includes/header.php';
?>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-8 col-lg-6">
            <?php echo displayFlash(); ?>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger shadow-sm">
                    <i class="fas fa-exclamation-triangle me-2"></i><?php echo e($error); ?>
                </div>
            <?php endif; ?>

            <div class="card shadow-lg border-0 rounded-4">
                <div class="card-header bg-primary text-white p-4 text-center rounded-top-4">
                    <i class="fas fa-key fs-1 text-warning mb-2 d-block"></i>
                    <h3 class="fw-bold mb-1">Single Sign-On (SSO) Portal</h3>
                    <p class="mb-0 text-white-50 small">Simulated OAuth2 / OpenID Connect Identity Service</p>
                </div>

                <div class="card-body p-4 p-md-5">
                    <div class="disclaimer-ribbon mb-4">
                        <i class="fas fa-info-circle me-1"></i> <strong>Academic Prototype Note:</strong> This SSO portal simulates third-party Identity Provider (IDP) authentication. In production, this connects to OAuth2 endpoints (e.g. Google, Okta, Keycloak).
                    </div>

                    <h5 class="fw-bold mb-3">Select Identity Provider:</h5>

                    <form action="/digital-investor/sso_login.php" method="POST">
                        <?php echo csrfField(); ?>

                        <div class="mb-4">
                            <div class="row g-3">
                                <?php foreach ($providers as $key => $p): ?>
                                    <div class="col-12">
                                        <label class="card card-hover p-3 border cursor-pointer border-2 shadow-sm d-flex flex-row align-items-center gap-3">
                                            <input type="radio" name="sso_provider" value="<?php echo e($key); ?>" class="form-check-input mt-0" <?php echo $key === 'investor_sso' ? 'checked' : ''; ?>>
                                            <div class="rounded-circle text-white d-flex align-items-center justify-content-center" style="width: 45px; height: 45px; background-color: <?php echo $p['bg_color']; ?>;">
                                                <i class="fab <?php echo $p['icon']; ?> fs-5"></i>
                                            </div>
                                            <div>
                                                <h6 class="fw-bold mb-0 text-dark"><?php echo e($p['name']); ?></h6>
                                                <small class="text-muted"><?php echo e($p['description']); ?></small>
                                            </div>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="border-top pt-4">
                            <h6 class="fw-bold mb-3">Simulated Identity Credentials:</h6>
                            <div class="mb-3">
                                <label for="sso_name" class="form-label small text-muted">Investor Full Name</label>
                                <input type="text" class="form-control" id="sso_name" name="sso_name" value="Amit Patel" required>
                            </div>
                            <div class="mb-4">
                                <label for="sso_email" class="form-label small text-muted">SSO Email Address</label>
                                <input type="email" class="form-control" id="sso_email" name="sso_email" value="amit.patel@sso-demo.org" required>
                            </div>

                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary btn-lg fw-bold rounded-3">
                                    <i class="fas fa-shield-alt me-2"></i> Authorize & Continue with SSO
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
