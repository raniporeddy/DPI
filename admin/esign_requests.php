<?php
// admin/esign_requests.php
// Admin eSign Requests Management & Tracking Page

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/project_info.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth_check.php';

requireAdmin();

$allRequests = getAllESignRequests($pdo);

// Statistics
$totalReqs   = count($allRequests);
$signedReqs  = 0;
$pendingReqs = 0;
$rejectedReqs = 0;

foreach ($allRequests as $r) {
    if ($r['status'] === 'Signed') $signedReqs++;
    elseif ($r['status'] === 'Rejected') $rejectedReqs++;
    else $pendingReqs++;
}

$pageTitle = "eSign Requests Tracker - Admin Console";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid px-4 py-4">
    <?php echo displayFlash(); ?>

    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h3 class="fw-bold text-dark mb-1"><i class="fas fa-paper-plane text-primary me-2"></i>Email eSign Request Workflow Tracker</h3>
            <p class="text-muted small mb-0">Monitor all dispatched electronic signature requests, recipient emails, execution timestamps, and signed documents.</p>
        </div>
        <div class="d-flex gap-2">
            <span class="badge bg-primary fs-6 px-3 py-2"><?php echo $totalReqs; ?> Total Requests</span>
            <span class="badge bg-success fs-6 px-3 py-2"><?php echo $signedReqs; ?> Signed</span>
            <span class="badge bg-warning text-dark fs-6 px-3 py-2"><?php echo $pendingReqs; ?> Sent/Pending</span>
            <span class="badge bg-danger fs-6 px-3 py-2"><?php echo $rejectedReqs; ?> Rejected</span>
        </div>
    </div>

    <!-- eSign Requests Data Table -->
    <div class="card shadow-sm border-0 rounded-4 mb-4">
        <div class="card-header bg-white py-3 border-bottom d-flex align-items-center justify-content-between">
            <h5 class="fw-bold mb-0 text-dark"><i class="fas fa-list-check me-2 text-primary"></i>All Dispatched eSign Requests</h5>
            <small class="text-muted">Single-use token links & delivery logs</small>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light fs-7 text-uppercase text-muted">
                        <tr>
                            <th class="ps-4">Request ID</th>
                            <th>Investor / App ID</th>
                            <th>Agreement Title</th>
                            <th>Recipient Email</th>
                            <th>Request Date</th>
                            <th>Status</th>
                            <th>Signed Timestamp / IP</th>
                            <th class="text-end pe-4">Signed Document</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($allRequests)): ?>
                            <tr><td colspan="8" class="text-center py-5 text-muted">No eSign requests created yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($allRequests as $req): ?>
                                <tr>
                                    <td class="ps-4 font-monospace fw-bold">#<?php echo $req['id']; ?></td>
                                    <td>
                                        <strong class="d-block text-dark"><?php echo e($req['investor_name'] ?: 'Investor'); ?></strong>
                                        <small class="text-muted font-monospace"><?php echo e($req['application_id'] ?: $req['investor_email']); ?></small>
                                    </td>
                                    <td><strong><?php echo e($req['agreement_title']); ?></strong></td>
                                    <td>
                                        <span class="fw-semibold text-dark"><?php echo e($req['recipient_email']); ?></span>
                                    </td>
                                    <td><small class="text-muted"><?php echo date('M d, Y H:i', strtotime($req['created_at'])); ?></small></td>
                                    <td><?php echo renderStatusBadge($req['status']); ?></td>
                                    <td>
                                        <?php if ($req['status'] === 'Signed'): ?>
                                            <small class="d-block text-dark fw-semibold"><?php echo e($req['signed_at']); ?></small>
                                            <code class="fs-8"><?php echo e($req['ip_address']); ?></code>
                                        <?php elseif ($req['status'] === 'Rejected'): ?>
                                            <small class="text-danger fw-semibold d-block">Rejected</small>
                                            <small class="text-muted fs-8"><?php echo e($req['rejection_reason']); ?></small>
                                        <?php else: ?>
                                            <span class="text-muted fs-8">Awaiting Recipient Sign</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end pe-4">
                                        <?php if (!empty($req['signed_doc_path']) && file_exists(dirname(__DIR__) . '/' . $req['signed_doc_path'])): ?>
                                            <a href="/digital-investor/<?php echo e($req['signed_doc_path']); ?>" target="_blank" class="btn btn-sm btn-success fw-semibold">
                                                <i class="fas fa-file-download me-1"></i> View Signed PDF
                                            </a>
                                        <?php else: ?>
                                            <a href="/digital-investor/esign_signing.php?token=<?php echo urlencode($req['token']); ?>" target="_blank" class="btn btn-sm btn-outline-secondary">
                                                <i class="fas fa-link me-1"></i> Signing Link
                                            </a>
                                        <?php endif; ?>
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
