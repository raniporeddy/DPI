<?php
// forgot_password.php
// Academic Demo Password Reset Request Module

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/project_info.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/captcha_helper.php';

if (isLoggedIn()) {
    $userRole = $_SESSION['role'] ?? 'investor';
    header("Location: " . ($userRole === 'admin' ? '/digital-investor/admin/dashboard.php' : '/digital-investor/dashboard.php'));
    exit;
}

$error = '';
$successDemoLink = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCSRFPost();

    $email = trim($_POST['email'] ?? '');
    $captchaInput = trim($_POST['captcha_input'] ?? '');

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid registered email address.';
    } elseif (!validateCaptchaCode($captchaInput)) {
        $error = 'Security CAPTCHA verification code is incorrect. Please try again.';
    } else {
        $stmt = $pdo->prepare("SELECT id, email, role FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            // Generate secure reset token (Expires in 1 hour via MySQL NOW())
            $resetToken = bin2hex(random_bytes(16));

            $stmtUpd = $pdo->prepare("UPDATE users SET reset_token = ?, reset_expires = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id = ?");
            $stmtUpd->execute([$resetToken, $user['id']]);

            $successDemoLink = '/digital-investor/reset_password.php?token=' . $resetToken;
            setFlash('info', 'Password reset token generated! Click the demo link below to reset your password.');
        } else {
            // Friendly message without revealing email non-existence to unauthorized enumeration
            setFlash('info', 'If your email is registered in our system, a password reset link has been prepared.');
        }
    }
}

$pageTitle = "Forgot Password - Digital Investor Onboarding";
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

            <?php if (!empty($successDemoLink)): ?>
                <div class="alert alert-success border-0 shadow-sm p-4 mb-4">
                    <h6 class="fw-bold mb-2"><i class="fas fa-check-circle me-1"></i> Demo Password Reset Token Prepared</h6>
                    <p class="small mb-3">In production, a password reset link is emailed securely. For this academic demonstration, click below to proceed:</p>
                    <a href="<?php echo e($successDemoLink); ?>" class="btn btn-success fw-bold w-100 rounded-3">
                        <i class="fas fa-key me-1"></i> Proceed to Reset Password
                    </a>
                </div>
            <?php endif; ?>

            <div class="card shadow-lg border-0 rounded-4">
                <div class="card-body p-4 p-md-5">
                    <div class="text-center mb-4">
                        <div class="bg-info-subtle text-info-emphasis rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width: 60px; height: 60px;">
                            <i class="fas fa-key fs-3 text-info"></i>
                        </div>
                        <h3 class="fw-bold">Forgot Password</h3>
                        <p class="text-muted small">Enter your registered email to reset your portal password</p>
                    </div>

                    <form action="/digital-investor/forgot_password.php" method="POST">
                        <?php echo csrfField(); ?>

                        <div class="mb-3">
                            <label for="email" class="form-label fw-semibold">Registered Email Address <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="fas fa-envelope text-muted"></i></span>
                                <input type="email" class="form-control" id="email" name="email" required placeholder="name@example.com" value="<?php echo e($_POST['email'] ?? ''); ?>">
                            </div>
                        </div>

                        <!-- CAPTCHA Verification -->
                        <?php echo renderCaptchaWidget(); ?>

                        <div class="d-grid gap-2 mt-4">
                            <button type="submit" class="btn btn-primary btn-lg rounded-3 fw-bold">
                                <i class="fas fa-paper-plane me-2"></i> Request Reset Link
                            </button>
                        </div>
                    </form>

                    <div class="text-center mt-4">
                        <p class="small text-muted mb-0">Remembered your password? <a href="/digital-investor/login.php" class="fw-bold text-primary text-decoration-none">Back to Login</a></p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
