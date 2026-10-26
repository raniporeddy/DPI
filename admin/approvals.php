<?php
// admin/approvals.php
// Admin Approvals Management Queue & Action Handler

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/project_info.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth_check.php';

requireAdmin();

$adminUserId = $_SESSION['user_id'];

// Handle POST Verification Actions BEFORE HTML Output
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCSRFPost();

    $targetUserId  = (int)($_POST['investor_user_id'] ?? 0);
    $actionType    = trim($_POST['action_type'] ?? '');
    $adminRemarks  = trim($_POST['admin_remarks'] ?? '');

    if ($targetUserId > 0 && !empty($actionType)) {
        $app = getOrInitApplication($pdo, $targetUserId);

        try {
            $pdo->beginTransaction();

            if ($actionType === 'verify_kyc') {
                $stmtKyc = $pdo->prepare("UPDATE kyc_verification SET status = 'Verified', admin_remarks = ?, verified_at = NOW() WHERE user_id = ?");
                $stmtKyc->execute([$adminRemarks, $targetUserId]);
                $msg = "eKYC verified successfully.";

            } elseif ($actionType === 'reject_kyc') {
                $stmtKyc = $pdo->prepare("UPDATE kyc_verification SET status = 'Rejected', admin_remarks = ? WHERE user_id = ?");
                $stmtKyc->execute([$adminRemarks, $targetUserId]);
                $msg = "eKYC marked as Rejected.";

            } elseif ($actionType === 'approve_application') {
                $stmtApp = $pdo->prepare("UPDATE applications SET status = 'Approved', reviewed_at = NOW(), admin_remarks = ? WHERE user_id = ?");
                $stmtApp->execute([$adminRemarks, $targetUserId]);

                // Keep eKYC status synced
                $stmtKyc = $pdo->prepare("UPDATE kyc_verification SET status = 'Verified', admin_remarks = ?, verified_at = NOW() WHERE user_id = ? AND status != 'Verified'");
                $stmtKyc->execute([$adminRemarks, $targetUserId]);

                $msg = "Investor application APPROVED successfully.";

            } elseif ($actionType === 'reject_application') {
                $stmtApp = $pdo->prepare("UPDATE applications SET status = 'Rejected', reviewed_at = NOW(), admin_remarks = ? WHERE user_id = ?");
                $stmtApp->execute([$adminRemarks, $targetUserId]);
                $msg = "Investor application REJECTED.";

            } elseif ($actionType === 'correct_kyc') {
                $correctedName    = trim($_POST['corrected_full_name'] ?? '');
                $correctedDob     = trim($_POST['corrected_dob'] ?? '');
                $correctedGender  = trim($_POST['corrected_gender'] ?? '');
                $correctedIdNum   = trim($_POST['corrected_id_number'] ?? '');
                $correctedAddress = trim($_POST['corrected_address'] ?? '');
                $andVerify        = !empty($_POST['and_verify']) && $_POST['and_verify'] === '1';

                $newKycStatus = $andVerify ? 'Verified' : 'Submitted';
                $verifiedAt   = $andVerify ? date('Y-m-d H:i:s') : null;

                $stmtKyc = $pdo->prepare("
                    UPDATE kyc_verification 
                    SET full_name = ?, dob = ?, id_number = ?, address = ?,
                        ocr_name = ?, ocr_dob = ?, ocr_gender = ?, ocr_id_number = ?, ocr_address = ?,
                        status = ?, admin_remarks = ?, verified_at = ?
                    WHERE user_id = ?
                ");
                $stmtKyc->execute([
                    $correctedName, $correctedDob, $correctedIdNum, $correctedAddress,
                    $correctedName, $correctedDob, $correctedGender, $correctedIdNum, $correctedAddress,
                    $newKycStatus, $adminRemarks, $verifiedAt, $targetUserId
                ]);

                // Also keep profile synced with reviewer-verified details
                $stmtProf = $pdo->prepare("
                    UPDATE investor_profiles 
                    SET full_name = ?, dob = ?, gender = ?, id_number = ?, address = ?, updated_at = NOW() 
                    WHERE user_id = ?
                ");
                $stmtProf->execute([$correctedName, $correctedDob, $correctedGender ?: null, $correctedIdNum, $correctedAddress, $targetUserId]);

                $msg = $andVerify ? "eKYC details corrected and marked as Verified." : "eKYC details corrected successfully by authorized reviewer.";

            } elseif ($actionType === 'reset_kyc_for_resubmission') {
                // -------------------------------------------------------------------
                // RESET KYC FOR RESUBMISSION
                // Rejects the mismatched submission and clears the document / OCR
                // pointers so the investor can re-upload their own identity document.
                //
                // Security guarantees:
                //   - Only admin can trigger this (requireAdmin() enforced above).
                //   - Original uploaded files are NOT deleted from disk — they stay
                //     for audit purposes. Only the DB pointers are cleared.
                //   - Investor profile fields (name, DOB, gender) are NOT modified.
                //   - The investor cannot clear their own row; they can only REQUEST.
                // -------------------------------------------------------------------
                $rejectionReason = !empty($adminRemarks)
                    ? $adminRemarks
                    : 'Document rejected by administrator: the uploaded document does not match the investor account. Please resubmit your own valid identity document.';

                $stmtReset = $pdo->prepare("
                    UPDATE kyc_verification
                    SET status        = 'Rejected',
                        document_path = NULL,
                        photo_path    = NULL,
                        ocr_name      = NULL,
                        ocr_dob       = NULL,
                        ocr_gender    = NULL,
                        ocr_address   = NULL,
                        ocr_id_number = NULL,
                        ocr_status    = NULL,
                        admin_remarks = ?,
                        verified_at   = NULL,
                        updated_at    = NOW()
                    WHERE user_id = ? AND status IN ('Submitted','Pending')
                ");
                $stmtReset->execute([$rejectionReason, $targetUserId]);

                $msg = "eKYC submission rejected and reset. Investor can now resubmit their own document.";
            }

            // Insert into Audit Log
            $stmtAudit = $pdo->prepare("INSERT INTO admin_actions (admin_id, application_id, action_type, remarks) VALUES (?, ?, ?, ?)");
            $stmtAudit->execute([$adminUserId, $app['application_id'], strtoupper($actionType), $adminRemarks]);

            $pdo->commit();

            setFlash('success', $msg);

        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Admin Approval Action Error: " . $e->getMessage());
            setFlash('danger', 'Database error executing admin action.');
        }

        header("Location: /digital-investor/admin/view_investor.php?id=" . $targetUserId);
        exit;
    }
}

// Fetch Pending Approvals Queue for View
$stmtPending = $pdo->query("
    SELECT a.*, u.email, p.full_name, p.mobile, k.status as kyc_status, s.status as esign_status
    FROM applications a
    JOIN users u ON a.user_id = u.id
    LEFT JOIN investor_profiles p ON u.id = p.user_id
    LEFT JOIN kyc_verification k ON u.id = k.user_id
    WHERE a.status IN ('Submitted', 'Under Review') OR (k.status IN ('Submitted', 'Pending') AND k.document_path IS NOT NULL)
    ORDER BY a.updated_at ASC
");
$pendingQueue = $stmtPending->fetchAll();

// Render HTML View
$pageTitle = "Approvals Queue - Admin Console";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container py-4">
    <?php echo displayFlash(); ?>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold text-dark mb-1"><i class="fas fa-tasks text-warning me-2"></i>Pending Approvals Queue</h3>
            <p class="text-muted small mb-0">Applications requiring eKYC verification and final admin approval.</p>
        </div>
        <span class="badge bg-warning text-dark fs-6 px-3 py-2 rounded-pill"><?php echo count($pendingQueue); ?> Applications Pending Review</span>
    </div>

    <!-- Pending Queue Table -->
    <div class="card shadow-sm border-0 rounded-4">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light fs-7 text-uppercase text-muted">
                        <tr>
                            <th class="ps-4">Application ID</th>
                            <th>Investor Name</th>
                            <th>Submission Date</th>
                            <th>eKYC Status</th>
                            <th>eSign Status</th>
                            <th>App Status</th>
                            <th class="text-end pe-4">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($pendingQueue)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-5">
                                    <i class="fas fa-check-circle fs-1 text-success mb-2 d-block"></i>
                                    <h6 class="fw-bold text-dark mb-0">Queue Empty!</h6>
                                    <small class="text-muted">All investor applications and eKYC submissions are up to date.</small>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($pendingQueue as $item): ?>
                                <tr>
                                    <td class="ps-4 font-monospace fw-bold text-primary"><?php echo e($item['application_id']); ?></td>
                                    <td>
                                        <strong class="d-block text-dark"><?php echo e($item['full_name'] ?: 'N/A'); ?></strong>
                                        <small class="text-muted"><?php echo e($item['email']); ?></small>
                                    </td>
                                    <td><small class="text-muted"><?php echo e($item['submitted_at'] ?: $item['updated_at']); ?></small></td>
                                    <td><?php echo renderStatusBadge($item['kyc_status'] ?? 'Pending'); ?></td>
                                    <td><?php echo renderStatusBadge($item['esign_status'] ?? 'Pending'); ?></td>
                                    <td><?php echo renderStatusBadge($item['status']); ?></td>
                                    <td class="text-end pe-4">
                                        <a href="/digital-investor/admin/view_investor.php?id=<?php echo $item['user_id']; ?>" class="btn btn-warning btn-sm fw-bold">
                                            <i class="fas fa-user-check me-1"></i> Review & Approve
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
