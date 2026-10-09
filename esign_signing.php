<?php
// esign_signing.php
// Public / Token-Controlled Designated Signed Document Upload Workflow Page

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/project_info.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/notification_helper.php';

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$request = null;
$error = '';
$success = '';

if (!empty($token)) {
    $request = getESignRequestByToken($pdo, $token);
}

if (!$request) {
    $error = 'Invalid, expired, or missing eSign request token. Please check the access-controlled document link in your email.';
}

// Handle Document Upload / Rejection Form Submission
if ($request && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCSRFPost();

    $action = trim($_POST['signing_action'] ?? 'sign');

    if ($request['status'] === 'Signed') {
        $error = 'This agreement has already been executed and signed.';
    } elseif ($request['status'] === 'Rejected') {
        $error = 'This eSign request has already been rejected.';
    } else {
        if ($action === 'reject') {
            $reason = trim($_POST['rejection_reason'] ?? 'User declined to sign agreement.');
            try {
                $stmtRej = $pdo->prepare("UPDATE esign_requests SET status = 'Rejected', rejection_reason = ?, updated_at = NOW() WHERE id = ?");
                $stmtRej->execute([$reason, $request['id']]);

                // Create Admin and Investor Notifications
                createAdminNotification($pdo, $request['user_id'], 'esign_request_rejected', "eSign Request for '" . $request['agreement_title'] . "' was REJECTED by user " . $request['recipient_email'] . ".");
                
                $stmtNotif = $pdo->prepare("INSERT INTO notifications (user_id, type, message, created_at) VALUES (?, 'esign_rejected', ?, NOW())");
                $stmtNotif->execute([$request['user_id'], "eSign Request for '" . $request['agreement_title'] . "' sent to " . $request['recipient_email'] . " was REJECTED."]);

                setFlash('warning', 'Agreement signature request has been marked as REJECTED.');
                header("Location: /digital-investor/esign_signing.php?token=" . urlencode($token));
                exit;

            } catch (Exception $e) {
                error_log("Reject eSign error: " . $e->getMessage());
                $error = 'Database error updating rejection status.';
            }

        } elseif ($action === 'sign') {
            $signedDocPath = null;
            $relativePath = null;
            $userIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

            // Handle Signed Agreement Document File Upload
            if (isset($_FILES['signed_document']) && $_FILES['signed_document']['error'] === UPLOAD_ERR_OK) {
                $uploadDocRes = handleSecureFileUpload($_FILES['signed_document'], __DIR__ . '/uploads/signed_agreements', ['pdf', 'jpg', 'jpeg', 'png']);
                if (!$uploadDocRes['success']) {
                    $error = 'Signed Document Upload Error: ' . $uploadDocRes['error'];
                } else {
                    $signedDocPath = 'uploads/signed_agreements/' . basename($uploadDocRes['file_path']);
                    $relativePath = $signedDocPath;
                }
            } else {
                $error = 'Please select your signed document file (.pdf, .jpg, .png) to upload.';
            }

            if (empty($error) && !empty($signedDocPath)) {
                try {
                    $pdo->beginTransaction();

                    // Update esign_requests record
                    $stmtUpd = $pdo->prepare("
                        UPDATE esign_requests 
                        SET status = 'Signed', signature_path = ?, signed_doc_path = ?, signed_at = NOW(), ip_address = ? 
                        WHERE id = ?
                    ");
                    $stmtUpd->execute([$relativePath, $signedDocPath, $userIp, $request['id']]);

                    // Also update esignatures table for investor if empty/pending
                    $stmtCheckSig = $pdo->prepare("SELECT id FROM esignatures WHERE user_id = ?");
                    $stmtCheckSig->execute([$request['user_id']]);
                    if ($stmtCheckSig->fetch()) {
                        $stmtUpdSig = $pdo->prepare("UPDATE esignatures SET signature_path = ?, ip_address = ?, status = 'Signed', signed_at = NOW() WHERE user_id = ?");
                        $stmtUpdSig->execute([$relativePath, $userIp, $request['user_id']]);
                    } else {
                        $stmtInsSig = $pdo->prepare("INSERT INTO esignatures (user_id, signature_path, ip_address, status, signed_at) VALUES (?, ?, ?, 'Signed', NOW())");
                        $stmtInsSig->execute([$request['user_id'], $relativePath, $userIp]);
                    }

                    // Vault signed document into documents repository
                    $stmtDoc = $pdo->prepare("INSERT INTO documents (user_id, doc_type, doc_name, file_path, file_size, status) VALUES (?, 'Application Form', ?, ?, ?, 'Verified')");
                    $fileSize = file_exists(__DIR__ . '/' . $signedDocPath) ? filesize(__DIR__ . '/' . $signedDocPath) : 1024;
                    $stmtDoc->execute([$request['user_id'], 'Executed Signed Agreement - ' . $request['agreement_title'], $signedDocPath, $fileSize]);

                    refreshApplicationStatus($pdo, $request['user_id']);

                    // Create Admin & Investor Notifications
                    createAdminNotification($pdo, $request['user_id'], 'esign_request_completed', "User " . $request['recipient_email'] . " uploaded signed document for agreement '" . $request['agreement_title'] . "'.");

                    $stmtNotif = $pdo->prepare("INSERT INTO notifications (user_id, type, message, created_at) VALUES (?, 'esign_completed', ?, NOW())");
                    $stmtNotif->execute([$request['user_id'], "eSign Request for '" . $request['agreement_title'] . "' was SIGNED and uploaded."]);

                    $pdo->commit();

                    setFlash('success', 'Signed document uploaded successfully!');
                    header("Location: /digital-investor/esign_signing.php?token=" . urlencode($token));
                    exit;

                } catch (Exception $e) {
                    $pdo->rollBack();
                    error_log("eSign execution DB error: " . $e->getMessage());
                    $error = 'Database error completing eSign execution. Please try again.';
                }
            }
        }
    }
}

// Re-fetch request if updated
if (!empty($token)) {
    $request = getESignRequestByToken($pdo, $token);
}

$pageTitle = "Designated eSign Document Upload Workflow";
require_once __DIR__ . '/includes/header.php';
?>

<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-lg-9">
            <?php echo displayFlash(); ?>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo e($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if (!$request): ?>
                <div class="card shadow-sm border-0 rounded-4 text-center p-5">
                    <i class="fas fa-link-slash fs-1 text-danger mb-3"></i>
                    <h4 class="fw-bold text-dark mb-2">Invalid or Expired eSign Email Link</h4>
                    <p class="text-muted">The electronic signature request link you accessed is invalid or has expired.</p>
                </div>
            <?php else: ?>

                <!-- Academic Demonstration Security Disclaimer Banner -->
                <div class="alert alert-warning border-0 shadow-sm mb-4 rounded-3 d-flex align-items-center">
                    <i class="fas fa-shield-halved fs-3 text-warning-emphasis me-3"></i>
                    <div>
                        <strong class="d-block text-dark">Email-Based eSign Portal (Academic Project System)</strong>
                        <small class="text-dark">This electronic signature workflow allows users to upload their completed signed agreement document file.</small>
                    </div>
                </div>

                <!-- Main eSign Request Review & Upload Card -->
                <div class="card shadow-sm border-0 rounded-4">
                    <div class="card-header bg-white py-3 border-bottom d-flex align-items-center justify-content-between flex-wrap gap-2">
                        <div>
                            <h4 class="fw-bold mb-0 text-primary"><i class="fas fa-envelope-open-text me-2"></i>Email eSign Request Portal</h4>
                            <small class="text-muted">Upload completed signed document file</small>
                        </div>
                        <div>
                            <?php echo renderStatusBadge($request['status']); ?>
                        </div>
                    </div>

                    <div class="card-body p-4">

                        <!-- Request Details Overview Table -->
                        <div class="table-responsive mb-4">
                            <table class="table table-bordered align-middle">
                                <tbody>
                                    <tr>
                                        <th class="bg-light w-25">Agreement Title</th>
                                        <td class="w-75"><strong class="fs-6 text-primary"><?php echo e($request['agreement_title']); ?></strong> <span class="badge bg-secondary ms-2"><?php echo e($request['agreement_category']); ?></span></td>
                                    </tr>
                                    <tr>
                                        <th class="bg-light">User / Investor</th>
                                        <td><strong><?php echo e($request['investor_name'] ?: 'Investor User'); ?></strong> <code class="ms-2"><?php echo e($request['application_id'] ?: $request['investor_email']); ?></code></td>
                                    </tr>
                                    <tr>
                                        <th class="bg-light">Recipient Email</th>
                                        <td><strong><?php echo e($request['recipient_email']); ?></strong></td>
                                    </tr>
                                    <tr>
                                        <th class="bg-light">Request Date</th>
                                        <td><small class="text-muted"><?php echo date('M d, Y H:i', strtotime($request['created_at'])); ?></small></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <?php if ($request['status'] === 'Signed'): ?>
                            <!-- RECORDED SIGNED DOCUMENT DISPLAY -->
                            <div class="alert alert-success border-0 shadow-sm p-4 text-center mb-0 rounded-4">
                                <h5 class="fw-bold mb-2 text-success"><i class="fas fa-check-circle me-1"></i> Signed Document Uploaded & Executed</h5>
                                <div class="small text-muted mb-3">
                                    Signed Timestamp: <strong><?php echo e($request['signed_at']); ?></strong> | Audit IP: <code><?php echo e($request['ip_address']); ?></code>
                                </div>
                                <?php if (!empty($request['signed_doc_path'])): ?>
                                    <a href="/digital-investor/<?php echo e($request['signed_doc_path']); ?>" target="_blank" class="btn btn-success fw-bold px-4 rounded-pill">
                                        <i class="fas fa-file-download me-1"></i> Download Uploaded Signed Document File
                                    </a>
                                <?php endif; ?>
                            </div>

                        <?php elseif ($request['status'] === 'Rejected'): ?>
                            <!-- REJECTED DISPLAY -->
                            <div class="alert alert-danger border-0 shadow-sm p-4 text-center mb-0 rounded-4">
                                <h5 class="fw-bold mb-2 text-danger"><i class="fas fa-times-circle me-1"></i> Agreement eSign Request Rejected</h5>
                                <p class="mb-1 text-muted">Reason for rejection:</p>
                                <blockquote class="blockquote bg-white p-3 rounded border fs-6 italic text-dark"><?php echo e($request['rejection_reason'] ?: 'No specific reason provided.'); ?></blockquote>
                            </div>

                        <?php else: ?>
                            <!-- SIGNED DOCUMENT UPLOAD FORM -->
                            <form action="/digital-investor/esign_signing.php?token=<?php echo urlencode($token); ?>" method="POST" enctype="multipart/form-data" id="uploadEsignForm">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="token" value="<?php echo e($token); ?>">
                                <input type="hidden" name="signing_action" id="signing_action" value="sign">

                                <!-- UPLOAD SIGNED DOCUMENT FILE SECTION -->
                                <div class="card bg-white border border-2 border-primary-subtle p-3 p-md-4 rounded-4 shadow-sm mb-4">
                                    <h5 class="fw-bold text-dark border-bottom pb-3 mb-3">
                                        <i class="fas fa-upload text-primary me-2"></i>Upload Signed Document File (.pdf, .jpg, .png)
                                    </h5>
                                    <div class="mb-3">
                                        <label for="signed_document" class="form-label fw-semibold">Choose Signed Agreement File (.pdf, .jpg, .png) <span class="text-danger">*</span></label>
                                        <input type="file" class="form-control form-control-lg" id="signed_document" name="signed_document" accept=".pdf,.jpg,.jpeg,.png" required data-max-size="5">
                                        <div class="form-text text-muted mt-2">
                                            Upload your completed signed agreement copy (PDF or Image format, Max 5MB).
                                        </div>
                                    </div>
                                </div>

                                <div class="d-flex justify-content-between align-items-center border-top pt-4 mt-4 flex-wrap gap-2">
                                    <button type="button" class="btn btn-outline-danger" data-bs-toggle="collapse" data-bs-target="#rejectCollapse">
                                        <i class="fas fa-ban me-1"></i> Decline / Reject Agreement
                                    </button>

                                    <button type="submit" onclick="document.getElementById('signing_action').value='sign';" class="btn btn-success btn-lg fw-bold px-4 rounded-3 shadow-sm">
                                        <i class="fas fa-file-upload me-2"></i> Upload Signed Document & Submit
                                    </button>
                                </div>

                                <!-- Collapsible Rejection Reason Form -->
                                <div class="collapse mt-4" id="rejectCollapse">
                                    <div class="card bg-danger-subtle border border-danger p-3 rounded-3">
                                        <h6 class="fw-bold text-danger mb-2"><i class="fas fa-exclamation-triangle me-1"></i>Reject Agreement Request</h6>
                                        <div class="mb-3">
                                            <label for="rejection_reason" class="form-label text-dark small fw-semibold">Reason for Rejection</label>
                                            <textarea class="form-control" id="rejection_reason" name="rejection_reason" rows="2" placeholder="Please state why you are declining to sign..."></textarea>
                                        </div>
                                        <button type="submit" onclick="document.getElementById('signing_action').value='reject';" class="btn btn-danger fw-bold">
                                            <i class="fas fa-times-circle me-1"></i> Confirm Rejection
                                        </button>
                                    </div>
                                </div>
                            </form>
                        <?php endif; ?>

                    </div>
                </div>

            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/header.php'; ?>
