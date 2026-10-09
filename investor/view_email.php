<?php
// investor/view_email.php
// User Email Inbox Viewer - Renders the exact HTML email dispatched for an eSign Request

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/project_info.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth_check.php';

requireRole('investor');

$userId = $_SESSION['user_id'];
$requestId = (int)($_GET['request_id'] ?? 0);

if (!$requestId) {
    die("Invalid request ID.");
}

// Fetch request details
$stmt = $pdo->prepare("
    SELECT r.*, a.title AS agreement_title, a.category AS agreement_category
    FROM esign_requests r
    JOIN investment_agreements a ON r.agreement_id = a.id
    WHERE r.id = ? AND r.user_id = ?
");
$stmt->execute([$requestId, $userId]);
$req = $stmt->fetch();

if (!$req) {
    die("eSign email request not found or unauthorized.");
}

$signingUrl = APP_URL . "/esign_signing.php?token=" . urlencode($req['access_token']);
$investorName = $_SESSION['full_name'] ?? 'Valued Investor';

$pageTitle = "Email Inbox - " . $req['recipient_email'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($pageTitle); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f1f5f9; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .email-client { max-width: 800px; margin: 30px auto; background: #fff; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.08); overflow: hidden; }
        .email-topbar { background: #0f172a; color: #fff; padding: 15px 25px; display: flex; align-items: center; justify-content: space-between; }
        .email-meta { padding: 20px 25px; border-bottom: 1px solid #e2e8f0; background: #f8fafc; }
        .email-body { padding: 30px; background: #ffffff; }
        .avatar { width: 42px; height: 42px; background: #2563eb; color: #fff; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 18px; }
    </style>
</head>
<body>

<div class="container">
    <div class="email-client">
        <!-- Inbox Top Header -->
        <div class="email-topbar">
            <div class="d-flex align-items-center gap-2">
                <i class="fas fa-inbox text-warning fs-5"></i>
                <span class="fw-bold fs-6">User Email Inbox Simulator</span>
            </div>
            <a href="/digital-investor/investor/esign.php" class="btn btn-sm btn-outline-light">
                <i class="fas fa-arrow-left me-1"></i> Back to eSign Portal
            </a>
        </div>

        <!-- Email Meta Information Header -->
        <div class="email-meta">
            <div class="d-flex align-items-start gap-3">
                <div class="avatar">
                    <i class="fas fa-building"></i>
                </div>
                <div class="flex-grow-1">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="fw-bold mb-1 text-dark">Action Required: eSign Request for <?php echo e($req['agreement_title']); ?></h5>
                        <small class="text-muted"><?php echo date('M d, Y h:i A', strtotime($req['created_at'])); ?></small>
                    </div>
                    <div class="text-muted fs-7">
                        <strong>From:</strong> Digital Investor System &lt;noreply@digitalinvestor.com&gt;<br>
                        <strong>To:</strong> <?php echo e($req['recipient_email']); ?><br>
                        <strong>Status:</strong> <span class="badge bg-success">Received in Inbox</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Rendered Email Body -->
        <div class="email-body">
            <div class="p-3 mb-4 rounded-3 border border-info bg-info-subtle text-info-emphasis d-flex align-items-center gap-2">
                <i class="fas fa-info-circle fs-5"></i>
                <div>
                    <strong>This is the exact email dispatched to <?php echo e($req['recipient_email']); ?>.</strong> Click the button inside the email below to open your secure eSign document upload portal.
                </div>
            </div>

            <!-- Email HTML Card Frame -->
            <div style="border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; background: #ffffff;">
                <div style="padding: 25px; background: #071527; color: #ffffff; text-align: center;">
                    <h2 style="margin:0; font-size: 22px;">Digital Investor Onboarding System</h2>
                    <span style="font-size:13px; color:#cbd5e1;">Electronic Signature Workflow</span>
                </div>
                <div style="padding: 30px; line-height: 1.6; color: #334155;">
                    <p style="font-size: 15px;">Hello,</p>
                    <p style="font-size: 15px;">An electronic signature request has been generated for <strong><?php echo e($investorName); ?></strong> for the following agreement:</p>
                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 18px; margin: 20px 0;">
                        <strong style="color:#0d6efd; font-size:17px;"><?php echo e($req['agreement_title']); ?></strong>
                        <div style="font-size: 13px; color: #64748b; margin-top: 4px;"><?php echo e($req['agreement_category']); ?></div>
                    </div>
                    <p style="font-size: 15px;">Please click the button below to open your secure eSign portal. You will be able to review the agreement document, sign it, and upload your completed signed document.</p>
                    <p style="text-align: center; margin: 30px 0;">
                        <a href="<?php echo e($signingUrl); ?>" target="_blank" style="display: inline-block; background-color: #198754; color: #ffffff !important; padding: 15px 36px; text-decoration: none; border-radius: 8px; font-weight: bold; font-size: 16px; box-shadow: 0 4px 10px rgba(25,135,84,0.3);">
                            <i class="fas fa-signature me-1"></i> Open eSign Portal & Upload Document
                        </a>
                    </p>
                    <p style="font-size:13px; color:#64748b; margin-top: 25px;">If the button above does not work, copy and paste this link into your web browser:</p>
                    <p style="word-break: break-all; font-size: 13px; color: #0d6efd;">
                        <a href="<?php echo e($signingUrl); ?>" target="_blank"><?php echo e($signingUrl); ?></a>
                    </p>
                </div>
                <div style="padding: 20px; background: #f8fafc; font-size: 12px; color: #64748b; text-align: center; border-top: 1px solid #e2e8f0;">
                    &copy; <?php echo date('Y'); ?> Digital Investor Onboarding System. Access token is single-use and encrypted.
                </div>
            </div>
        </div>

    </div>
</div>

</body>
</html>
