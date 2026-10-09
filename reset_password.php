<?php
// reset_password.php
// Academic Demo Password Reset Execution Module

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/project_info.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth_check.php';

$token = trim($_GET['token'] ?? ($_POST['token'] ?? ''));
$error = '';

if (empty($token)) {
    setFlash('danger', 'Invalid or missing password reset token.');
    header("Location: /digital-investor/login.php");
    exit;
}

// Verify token in database
$stmt = $pdo->prepare("SELECT id, email FROM users WHERE reset_token = ? AND reset_expires > NOW()");
$stmt->execute([$token]);
$user = $stmt->fetch();

if (!$user) {
    $error = 'The password reset token is invalid or has expired. Please request a new link.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($error)) {
    verifyCSRFPost();

    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (empty($newPassword) || strlen($newPassword) < 6) {
        $error = 'New password must be at least 6 characters long.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } else {
        try {
            // Hash password securely with password_hash()
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

            // Update user password & clear reset token
            $stmtUpd = $pdo->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?");
            $stmtUpd->execute([$hashedPassword, $user['id']]);

            setFlash('success', 'Your password has been reset successfully! You can now log in with your new password.');
            header("Location: /digital-investor/login.php");
            exit;

        } catch (Exception $e) {
            error_log("Password Reset Execution Error: " . $e->getMessage());
            $error = 'An error occurred while resetting your password.';
        }
    }
}

$pageTitle = "Reset Password - Digital Investor Onboarding";
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

            <?php if ($user): ?>
                <div class="card shadow-lg border-0 rounded-4">
                    <div class="card-body p-4 p-md-5">
                        <div class="text-center mb-4">
                            <div class="bg-success-subtle text-success rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width: 60px; height: 60px;">
                                <i class="fas fa-lock-open fs-3 text-success"></i>
                            </div>
                            <h3 class="fw-bold">Reset Password</h3>
                            <p class="text-muted small">Account: <strong><?php echo e($user['email']); ?></strong></p>
                        </div>

                        <form action="/digital-investor/reset_password.php" method="POST">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="token" value="<?php echo e($token); ?>">

                            <div class="mb-3">
                                <label for="new_password" class="form-label fw-semibold">New Password <span class="text-danger">*</span></label>
                                <input type="password" class="form-control" id="new_password" name="new_password" required placeholder="Min 6 characters">
                            </div>

                            <div class="mb-4">
                                <label for="confirm_password" class="form-label fw-semibold">Confirm New Password <span class="text-danger">*</span></label>
                                <input type="password" class="form-control" id="confirm_password" name="confirm_password" required placeholder="Repeat new password">
                            </div>

                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-success btn-lg rounded-3 fw-bold">
                                    <i class="fas fa-save me-2"></i> Update Password
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
