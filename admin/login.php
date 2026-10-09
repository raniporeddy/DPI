<?php
// admin/login.php
// Admin Login Portal

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/project_info.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/captcha_helper.php';

// Redirect if already logged in BEFORE rendering page
if (isLoggedIn()) {
    $userRole = $_SESSION['role'] ?? 'admin';
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
        $error = 'Please enter admin email and password.';
    } elseif (!validateCaptchaCode($captchaInput)) {
        $error = 'Security CAPTCHA verification code is incorrect. Please try again.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND role = 'admin'");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['role'] = $user['role'];

            // Fetch profile name if exists
            $stmtProf = $pdo->prepare("SELECT full_name FROM investor_profiles WHERE user_id = ?");
            $stmtProf->execute([$user['id']]);
            $profName = $stmtProf->fetchColumn();

            $_SESSION['full_name'] = $profName ?: ($user['email'] === 'poreddyrani21@gmail.com' ? 'Poreddy Rani (Admin)' : 'System Administrator');

            setFlash('success', 'Admin login successful! Welcome to the Admin Console.');
            
            header("Location: /digital-investor/admin/dashboard.php");
            exit;
        } else {
            $error = 'Invalid admin credentials or unauthorized account.';
        }
    }
}

// Render HTML View
$pageTitle = "Admin Login - Digital Investor Onboarding";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-5">
            <?php echo displayFlash(); ?>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger shadow-sm">
                    <i class="fas fa-exclamation-triangle me-2"></i><?php echo e($error); ?>
                </div>
            <?php endif; ?>

            <div class="card shadow-lg border-0 rounded-4">
                <div class="card-header bg-dark text-white p-4 text-center rounded-top-4">
                    <div class="bg-warning text-dark rounded-circle d-inline-flex align-items-center justify-content-center mb-2" style="width: 55px; height: 55px;">
                        <i class="fas fa-user-shield fs-3"></i>
                    </div>
                    <h4 class="fw-bold mb-0">Admin Portal Sign In</h4>
                    <small class="text-white-50">Digital Investor Review & Verification System</small>
                </div>

                <div class="card-body p-4 p-md-5">
                    <form action="/digital-investor/admin/login.php" method="POST">
                        <?php echo csrfField(); ?>

                        <div class="mb-3">
                            <label for="email" class="form-label fw-semibold">Admin Email Address <span class="text-danger">*</span></label>
                            <input type="email" class="form-control form-control-lg" id="email" name="email" required placeholder="poreddyrani21@gmail.com" value="<?php echo e($_POST['email'] ?? ''); ?>">
                        </div>

                        <div class="mb-3">
                            <label for="password" class="form-label fw-semibold">Password <span class="text-danger">*</span></label>
                            <input type="password" class="form-control form-control-lg" id="password" name="password" required placeholder="Enter admin password">
                        </div>

                        <!-- CAPTCHA Verification -->
                        <?php echo renderCaptchaWidget(); ?>

                        <div class="d-grid mb-3 mt-4">
                            <button type="submit" class="btn btn-dark btn-lg fw-bold rounded-3">
                                <i class="fas fa-lock me-2 text-warning"></i> Login as Admin
                            </button>
                        </div>
                    </form>

                    <div class="bg-light p-3 rounded-3 mt-4 border text-center">
                        <small class="text-muted d-block mb-1">Authorized Admin Accounts:</small>
                        <code class="fw-bold d-block mb-1">poreddyrani21@gmail.com</code>
                        <small class="text-muted fs-8">Set or change your admin password locally anytime at: <a href="/digital-investor/setup_admin.php" class="fw-bold text-primary">/setup_admin.php</a></small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
