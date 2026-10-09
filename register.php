<?php
// register.php
// Investor Registration Page

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/project_info.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/captcha_helper.php';
require_once __DIR__ . '/includes/notification_helper.php';

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

    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $captchaInput = trim($_POST['captcha_input'] ?? '');

    if (empty($fullName) || empty($email) || empty($password) || empty($confirmPassword)) {
        $error = 'All mandatory fields are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters long.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } elseif (!validateCaptchaCode($captchaInput)) {
        $error = 'Security CAPTCHA verification code is incorrect. Please try again.';
    } else {
        // Check if email already exists
        $stmtCheck = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmtCheck->execute([$email]);
        if ($stmtCheck->fetch()) {
            $error = 'An account with this email address already exists.';
        } else {
            try {
                $pdo->beginTransaction();

                // Hash password securely using password_hash()
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

                // Insert into users
                $stmtInsUser = $pdo->prepare("INSERT INTO users (email, password, role) VALUES (?, ?, 'investor')");
                $stmtInsUser->execute([$email, $hashedPassword]);
                $userId = $pdo->lastInsertId();

                // Insert into investor_profiles
                $stmtInsProf = $pdo->prepare("INSERT INTO investor_profiles (user_id, full_name, email) VALUES (?, ?, ?)");
                $stmtInsProf->execute([$userId, $fullName, $email]);

                // Initialize Application
                getOrInitApplication($pdo, $userId);

                // Create Admin Notification
                createAdminNotification($pdo, $userId, 'registration', "New investor registered: " . $fullName . " (" . $email . ")");

                $pdo->commit();

                // Log user in automatically
                session_regenerate_id(true);
                $_SESSION['user_id'] = $userId;
                $_SESSION['email'] = $email;
                $_SESSION['role'] = 'investor';
                $_SESSION['full_name'] = $fullName;

                setFlash('success', 'Registration successful! Welcome to your Digital Investor Onboarding Portal.');
                
                header("Location: /digital-investor/dashboard.php");
                exit;

            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("Registration Error: " . $e->getMessage());
                $error = 'An error occurred during registration. Please try again.';
            }
        }
    }
}

// Render HTML View
$pageTitle = "Investor Registration - Digital Investor Onboarding";
require_once __DIR__ . '/includes/header.php';
?>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-7 col-lg-6">
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
                        <div class="bg-warning-subtle text-warning-emphasis rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width: 60px; height: 60px;">
                            <i class="fas fa-user-plus fs-3 text-warning"></i>
                        </div>
                        <h3 class="fw-bold">Create Investor Account</h3>
                        <p class="text-muted small">Begin your digital paperless onboarding journey</p>
                    </div>

                    <!-- SSO Registration Link -->
                    <div class="d-grid mb-3">
                        <a href="/digital-investor/sso_login.php" class="btn btn-outline-primary py-2.5 fw-semibold rounded-3 d-flex align-items-center justify-content-center gap-2">
                            <i class="fas fa-key text-warning fs-5"></i> 
                            <div>
                                <span class="d-block text-dark fw-bold">Quick Register with SSO</span>
                                <small class="text-muted fs-8 d-block">No separate password creation needed</small>
                            </div>
                        </a>
                    </div>

                    <div class="position-relative my-4 text-center">
                        <hr class="text-muted opacity-25">
                        <span class="position-absolute top-50 start-50 translate-middle bg-white px-3 text-muted small fw-semibold">OR REGISTER WITH EMAIL & PASSWORD</span>
                    </div>

                    <form action="/digital-investor/register.php" method="POST">
                        <?php echo csrfField(); ?>

                        <div class="mb-3">
                            <label for="full_name" class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="fas fa-user text-muted"></i></span>
                                <input type="text" class="form-control" id="full_name" name="full_name" required placeholder="e.g. Vikram Sharma" value="<?php echo e($_POST['full_name'] ?? ''); ?>">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="email" class="form-label fw-semibold">Email Address <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="fas fa-envelope text-muted"></i></span>
                                <input type="email" class="form-control" id="email" name="email" required placeholder="name@example.com" value="<?php echo e($_POST['email'] ?? ''); ?>">
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="password" class="form-label fw-semibold">Password <span class="text-danger">*</span></label>
                                <input type="password" class="form-control" id="password" name="password" required placeholder="Min 6 characters">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="confirm_password" class="form-label fw-semibold">Confirm Password <span class="text-danger">*</span></label>
                                <input type="password" class="form-control" id="confirm_password" name="confirm_password" required placeholder="Repeat password">
                            </div>
                        </div>

                        <!-- CAPTCHA Verification -->
                        <?php echo renderCaptchaWidget(); ?>

                        <div class="d-grid gap-2 mt-4">
                            <button type="submit" class="btn btn-warning btn-lg rounded-3 fw-bold text-dark">
                                <i class="fas fa-arrow-right me-2"></i> Register & Start Onboarding
                            </button>
                        </div>
                    </form>

                    <div class="text-center mt-4">
                        <p class="small text-muted mb-0">Already registered? <a href="/digital-investor/login.php" class="fw-bold text-primary text-decoration-none">Sign In Here</a></p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
