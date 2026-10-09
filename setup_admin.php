<?php
// setup_admin.php
// Secure Local Utility to Authorize Admin Account & Set Local Admin Password

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/project_info.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';

$defaultEmail = 'poreddyrani21@gmail.com';
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCSRFPost();

    $adminEmail  = trim($_POST['admin_email'] ?? $defaultEmail);
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPass = $_POST['confirm_password'] ?? '';

    if (empty($adminEmail) || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid admin email address.';
    } elseif (empty($newPassword) || strlen($newPassword) < 6) {
        $error = 'Admin password must be at least 6 characters long.';
    } elseif ($newPassword !== $confirmPass) {
        $error = 'Passwords do not match.';
    } else {
        try {
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

            // Check if user account exists
            $stmtCheck = $pdo->prepare("SELECT id, role FROM users WHERE email = ?");
            $stmtCheck->execute([$adminEmail]);
            $existingUser = $stmtCheck->fetch();

            if ($existingUser) {
                // Update existing account to Admin role and set new password
                $stmtUpd = $pdo->prepare("UPDATE users SET password = ?, role = 'admin' WHERE id = ?");
                $stmtUpd->execute([$hashedPassword, $existingUser['id']]);
                $userId = $existingUser['id'];
            } else {
                // Create new Admin user
                $stmtIns = $pdo->prepare("INSERT INTO users (email, password, role) VALUES (?, ?, 'admin')");
                $stmtIns->execute([$adminEmail, $hashedPassword]);
                $userId = $pdo->lastInsertId();
            }

            // Ensure investor_profiles record exists for admin
            $stmtProf = $pdo->prepare("SELECT id FROM investor_profiles WHERE user_id = ?");
            $stmtProf->execute([$userId]);
            if (!$stmtProf->fetch()) {
                $stmtInsProf = $pdo->prepare("INSERT INTO investor_profiles (user_id, full_name, email) VALUES (?, 'System Administrator', ?)");
                $stmtInsProf->execute([$userId, $adminEmail]);
            }

            $success = "Admin account for <strong>" . e($adminEmail) . "</strong> has been authorized and updated successfully with your new password!";

        } catch (Exception $e) {
            error_log("Admin Setup Error: " . $e->getMessage());
            $error = 'Database error updating Admin credentials. Please try again.';
        }
    }
}

$pageTitle = "Setup Admin Password - Digital Investor Onboarding";
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

            <?php if (!empty($success)): ?>
                <div class="alert alert-success border-0 shadow-sm p-4 mb-4">
                    <h5 class="fw-bold mb-2"><i class="fas fa-check-circle me-2"></i> Admin Account Authorized!</h5>
                    <p class="mb-3"><?php echo $success; ?></p>
                    <a href="/digital-investor/admin/login.php" class="btn btn-success fw-bold w-100 rounded-3">
                        <i class="fas fa-sign-in-alt me-1"></i> Proceed to Admin Login
                    </a>
                </div>
            <?php endif; ?>

            <div class="card shadow-lg border-0 rounded-4">
                <div class="card-header bg-dark text-white p-4 text-center rounded-top-4">
                    <div class="bg-warning text-dark rounded-circle d-inline-flex align-items-center justify-content-center mb-2" style="width: 55px; height: 55px;">
                        <i class="fas fa-user-shield fs-3"></i>
                    </div>
                    <h4 class="fw-bold mb-0">Authorize Admin Account</h4>
                    <small class="text-white-50">Local Admin Credentials Management</small>
                </div>

                <div class="card-body p-4 p-md-5">
                    <form action="/digital-investor/setup_admin.php" method="POST">
                        <?php echo csrfField(); ?>

                        <div class="mb-3">
                            <label for="admin_email" class="form-label fw-semibold">Admin Email Address <span class="text-danger">*</span></label>
                            <input type="email" class="form-control form-control-lg" id="admin_email" name="admin_email" required value="<?php echo e($_POST['admin_email'] ?? $defaultEmail); ?>">
                            <small class="text-muted fs-8">Authorizes this email address for Admin role access.</small>
                        </div>

                        <div class="mb-3">
                            <label for="new_password" class="form-label fw-semibold">Set New Admin Password <span class="text-danger">*</span></label>
                            <input type="password" class="form-control form-control-lg" id="new_password" name="new_password" required placeholder="Enter custom password">
                        </div>

                        <div class="mb-4">
                            <label for="confirm_password" class="form-label fw-semibold">Confirm Password <span class="text-danger">*</span></label>
                            <input type="password" class="form-control form-control-lg" id="confirm_password" name="confirm_password" required placeholder="Repeat new password">
                        </div>

                        <div class="d-grid">
                            <button type="submit" class="btn btn-dark btn-lg fw-bold rounded-3">
                                <i class="fas fa-save me-2 text-warning"></i> Authorize & Save Admin Password
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
