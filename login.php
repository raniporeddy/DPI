<?php
// login.php
// Login Page for Investor and Admin

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/project_info.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/captcha_helper.php';

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

    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $captchaInput = trim($_POST['captcha_input'] ?? '');

    if (empty($email) || empty($password)) {
        $error = 'Please enter both email and password.';
    } elseif (!validateCaptchaCode($captchaInput)) {
        $error = 'Security CAPTCHA verification code is incorrect. Please try again.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['role'] = $user['role'];

            // Fetch profile full name
            $stmtProf = $pdo->prepare("SELECT full_name FROM investor_profiles WHERE user_id = ?");
            $stmtProf->execute([$user['id']]);
            $profName = $stmtProf->fetchColumn();
            $_SESSION['full_name'] = $profName ?: $user['email'];

            setFlash('success', 'Welcome back, ' . ($_SESSION['full_name']) . '!');

            if ($user['role'] === 'admin') {
                header("Location: /digital-investor/admin/dashboard.php");
            } else {
                header("Location: /digital-investor/dashboard.php");
            }
            exit;
        } else {
            $error = 'Invalid email address or password.';
        }
    }
}

// Render HTML View
$pageTitle = "Login - Digital Investor Onboarding";
require_once __DIR__ . '/includes/header.php';
?>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5">
            <?php echo displayFlash(); ?>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo e($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="card shadow-lg border-0 rounded-4">
                <div class="card-body p-4 p-md-5">
                    <div class="text-center mb-4">
                        <div class="bg-primary-subtle text-primary rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width: 60px; height: 60px;">
                            <i class="fas fa-lock fs-3 text-primary"></i>
                        </div>
                        <h3 class="fw-bold">Sign In</h3>
                        <p class="text-muted small">Access your Investor Portal or Admin Dashboard</p>
                    </div>

                    <!-- SSO Option Button -->
                    <div class="d-grid mb-3">
                        <a href="/digital-investor/sso_login.php" class="btn btn-outline-primary py-2.5 fw-semibold rounded-3 d-flex align-items-center justify-content-center gap-2">
                            <i class="fas fa-key text-warning fs-5"></i> 
                            <div>
                                <span class="d-block text-dark fw-bold">Continue with Single Sign-On (SSO)</span>
                                <small class="text-muted fs-8 d-block">No password creation required for SSO</small>
                            </div>
                        </a>
                    </div>

                    <div class="position-relative my-4 text-center">
                        <hr class="text-muted opacity-25">
                        <span class="position-absolute top-50 start-50 translate-middle bg-white px-3 text-muted small fw-semibold">OR WITH EMAIL & PASSWORD</span>
                    </div>

                    <form action="/digital-investor/login.php" method="POST">
                        <?php echo csrfField(); ?>

                        <div class="mb-3">
                            <label for="email" class="form-label fw-semibold">Email Address <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="fas fa-envelope text-muted"></i></span>
                                <input type="email" class="form-control" id="email" name="email" required placeholder="name@example.com" value="<?php echo e($_POST['email'] ?? ''); ?>">
                            </div>
                        </div>

                        <div class="mb-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <label for="password" class="form-label fw-semibold mb-0">Password <span class="text-danger">*</span></label>
                                <a href="/digital-investor/forgot_password.php" class="small text-primary text-decoration-none fw-semibold">Forgot Password?</a>
                            </div>
                            <div class="input-group mt-1">
                                <span class="input-group-text bg-light"><i class="fas fa-key text-muted"></i></span>
                                <input type="password" class="form-control" id="password" name="password" required placeholder="Enter password">
                                <button class="btn btn-outline-secondary toggle-password" type="button" data-target="#password">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>

                        <!-- CAPTCHA Verification -->
                        <?php echo renderCaptchaWidget(); ?>

                        <div class="d-grid gap-2 mt-4">
                            <button type="submit" class="btn btn-primary btn-lg rounded-3 fw-bold">
                                <i class="fas fa-sign-in-alt me-2"></i> Log In
                            </button>
                        </div>
                    </form>

                    <div class="text-center mt-4">
                        <p class="small text-muted mb-0">Don't have an account? <a href="/digital-investor/register.php" class="fw-bold text-primary text-decoration-none">Register as Investor</a></p>
                    </div>

                    <!-- Quick Demo Credentials Loader -->
                    <div class="bg-light p-3 rounded-3 mt-4 border">
                        <small class="fw-bold text-uppercase text-muted d-block mb-2 fs-8">Quick Demo Logins:</small>
                        <div class="d-flex flex-wrap gap-2">
                            <button type="button" class="btn btn-xs btn-outline-secondary text-dark fs-8" onclick="fillDemo('admin@digitalinvestor.com', 'Admin@123')">Admin</button>
                            <button type="button" class="btn btn-xs btn-outline-secondary text-dark fs-8" onclick="fillDemo('investor@example.com', 'Investor@123')">Approved Investor</button>
                            <button type="button" class="btn btn-xs btn-outline-secondary text-dark fs-8" onclick="fillDemo('john.doe@example.com', 'Investor@123')">Pending Investor</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function fillDemo(email, pass) {
    document.getElementById('email').value = email;
    document.getElementById('password').value = pass;
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
