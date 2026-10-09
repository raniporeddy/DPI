<?php
// investor/esign.php
// Email-Based eSign Request Workflow for Logged-In Investor

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/project_info.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/notification_helper.php';
require_once __DIR__ . '/../includes/email_helper.php';

requireRole('investor');

$userId = $_SESSION['user_id'];
$error = '';

// Automatically fetch logged-in user email address from authentication account
$stmtUser = $pdo->prepare("
    SELECT u.email as auth_email, p.email as profile_email 
    FROM users u 
    LEFT JOIN investor_profiles p ON u.id = p.user_id 
    WHERE u.id = ?
");
$stmtUser->execute([$userId]);
$userAcc = $stmtUser->fetch();

$userEmail = trim(!empty($userAcc['auth_email']) ? $userAcc['auth_email'] : ($userAcc['profile_email'] ?? $_SESSION['email'] ?? ''));

// Fetch Application Status
$app = getOrInitApplication($pdo, $userId);

// Fetch Investment Agreements
$agreements = getAgreements($pdo);

// Handle Form Submission: Send eSign Request Mail to Logged-In User Email
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCSRFPost();

    $agreementId = (int)($_POST['agreement_id'] ?? 0);

    if (!$agreementId) {
        $error = 'Please select an investment agreement document for eSign.';
    } elseif (empty($userEmail) || !filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
        $error = 'Registered user email address is invalid or missing.';
    } else {
        // Create eSign request record and dispatch email via backend email service
        $reqRes = createESignRequest($pdo, $userId, $agreementId, $userEmail);
        
        if (!$reqRes['success']) {
            // Display actual backend error message if email sending fails
            $error = $reqRes['error'];
        } else {
            // Display success message ONLY after backend email service confirms successful delivery
            setFlash('success', 'eSign Request email sent successfully to your registered email address (' . e($userEmail) . ')! Please open your email inbox to view the agreement, sign it, and upload your document.');
            header("Location: /digital-investor/investor/esign.php");
            exit;
        }
    }
}

// Render HTML View
$pageTitle = "Email eSign Request Workflow - Digital Investor Onboarding";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-lg-10">
            <?php echo displayFlash(); ?>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i><strong>Email Sending Error:</strong> <?php echo e($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- Workflow Disclaimer Banner -->
            <div class="alert alert-info border-0 shadow-sm mb-4 rounded-3 d-flex align-items-center">
                <i class="fas fa-envelope-open-text fs-3 text-info-emphasis me-3"></i>
                <div>
                    <strong class="d-block text-dark">Email-Based eSign Workflow</strong>
                    <small class="text-dark">Select an agreement document below to send an eSign request mail directly to your registered email address (<code><?php echo e($userEmail); ?></code>). Open your email inbox, click the access link, review the agreement, sign it, and upload your document.</small>
                </div>
            </div>

            <!-- REQUEST eSIGN MAIL FORM CARD -->
            <div class="card shadow-sm border-0 rounded-4 mb-4 border-top border-primary border-4">
                <div class="card-header bg-white py-3 border-bottom d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <div>
                        <h4 class="fw-bold mb-0 text-primary"><i class="fas fa-paper-plane me-2"></i>Send eSign Request to My Email</h4>
                        <small class="text-muted">An electronic signature request mail with a secure access token link will be sent to your registered email address</small>
                    </div>
                    <div>
                        <span class="badge bg-secondary me-2">Module 4 of 4</span>
                        <?php echo renderStatusBadge($app['esign_completed'] ? 'Signed' : 'Pending'); ?>
                    </div>
                </div>

                <div class="card-body p-4">
                    <form action="/digital-investor/investor/esign.php" method="POST">
                        <?php echo csrfField(); ?>

                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label for="agreement_id" class="form-label fw-semibold">Select Investment Agreement <span class="text-danger">*</span></label>
                                <select class="form-select" id="agreement_id" name="agreement_id" required>
                                    <option value="">-- Choose Investment Agreement Document --</option>
                                    <?php foreach ($agreements as $ag): ?>
                                        <option value="<?php echo $ag['id']; ?>">
                                            <?php echo e($ag['title']); ?> (<?php echo e($ag['category']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Registered User Email Address (Automatically Fetched)</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="fas fa-user-check text-success"></i></span>
                                    <input type="email" class="form-control bg-light fw-bold text-dark" value="<?php echo e($userEmail); ?>" readonly>
                                </div>
                                <small class="text-muted fs-8">eSign request link will be sent directly to your logged-in email inbox.</small>
                            </div>
                        </div>

                        <div class="d-flex justify-content-between align-items-center border-top pt-3">
                            <a href="/digital-investor/dashboard.php" class="btn btn-outline-secondary">
                                <i class="fas fa-arrow-left me-1"></i> Back to Dashboard
                            </a>
                            <button type="submit" class="btn btn-primary btn-lg fw-bold px-4 rounded-3 shadow-sm">
                                <i class="fas fa-envelope me-1"></i> Send eSign Request Mail to My Email
                            </button>
                        </div>
                    </form>
                </div>
            </div>

        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
