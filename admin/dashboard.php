<?php
// admin/dashboard.php
// Admin Overview & Metrics Dashboard

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/project_info.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/notification_helper.php';

requireAdmin();

$academic = getAcademicDetails();

// Handle Mark Notification as Read
if (isset($_GET['action']) && $_GET['action'] === 'read' && isset($_GET['notif_id'])) {
    verifyCSRFPost();
    markNotificationAsRead($pdo, (int)$_GET['notif_id']);
    setFlash('info', 'Notification marked as read.');
    header("Location: /digital-investor/admin/dashboard.php");
    exit;
}

// Calculate Summary Statistics
$totalInvestors = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'investor'")->fetchColumn();
$pendingKyc     = $pdo->query("SELECT COUNT(*) FROM kyc_verification WHERE status = 'Submitted'")->fetchColumn();
$pendingApprovals = $pdo->query("SELECT COUNT(*) FROM applications WHERE status IN ('Submitted', 'Under Review')")->fetchColumn();
$approvedApps   = $pdo->query("SELECT COUNT(*) FROM applications WHERE status = 'Approved'")->fetchColumn();
$rejectedApps   = $pdo->query("SELECT COUNT(*) FROM applications WHERE status = 'Rejected'")->fetchColumn();

// Fetch Admin Notifications
$unreadCount   = getUnreadNotificationCount($pdo);
$notifications = getAdminNotifications($pdo, 10);

// Fetch Recent Submissions
$stmtRecent = $pdo->query("
    SELECT a.*, u.email, p.full_name, k.status as kyc_status, s.status as esign_status
    FROM applications a
    JOIN users u ON a.user_id = u.id
    LEFT JOIN investor_profiles p ON u.id = p.user_id
    LEFT JOIN kyc_verification k ON u.id = k.user_id
    LEFT JOIN esignatures s ON u.id = s.user_id
    ORDER BY a.updated_at DESC
    LIMIT 5
");
$recentApps = $stmtRecent->fetchAll();

// Render HTML View
$pageTitle = "Admin Dashboard - Digital Investor Onboarding";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container py-4">
    <?php echo displayFlash(); ?>

    <!-- Admin Welcome Header Banner -->
    <div class="card shadow-sm border-0 mb-4 bg-dark text-white rounded-4">
        <div class="card-body p-4">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h3 class="fw-bold mb-1"><i class="fas fa-user-shield text-warning me-2"></i>Admin Management Console</h3>
                    <p class="text-white-50 mb-0">Review investor applications, verify eKYC compliance documents, and inspect digital signatures.</p>
                </div>
                <div class="col-md-4 text-md-end mt-3 mt-md-0">
                    <a href="/digital-investor/admin/approvals.php" class="btn btn-warning fw-bold px-3 rounded-pill">
                        <i class="fas fa-tasks me-1"></i> Review Queue (<?php echo $pendingApprovals; ?>)
                    </a>
                    <a href="/digital-investor/admin/esign_requests.php" class="btn btn-outline-light fw-bold px-3 rounded-pill ms-1">
                        <i class="fas fa-paper-plane me-1"></i> eSign Tracker
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Admin Notifications Card -->
    <div class="card shadow-sm border-0 rounded-4 mb-4 border-top border-warning border-4">
        <div class="card-header bg-white py-3 border-bottom d-flex align-items-center justify-content-between">
            <h5 class="fw-bold mb-0 text-dark">
                <i class="fas fa-bell text-warning me-2"></i>System Notifications 
                <?php if ($unreadCount > 0): ?>
                    <span class="badge bg-danger rounded-pill ms-1"><?php echo $unreadCount; ?> Unread</span>
                <?php endif; ?>
            </h5>
            <small class="text-muted">Real-time alerts for investor registrations, eKYC & uploads</small>
        </div>
        <div class="card-body p-0">
            <?php if (empty($notifications)): ?>
                <div class="p-4 text-center text-muted">
                    <small>No admin notifications recorded yet.</small>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light fs-7 text-uppercase text-muted">
                            <tr>
                                <th class="ps-4">Timestamp</th>
                                <th>Investor Name</th>
                                <th>App ID</th>
                                <th>Notification Event Message</th>
                                <th>Status</th>
                                <th class="text-end pe-4">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($notifications as $n): ?>
                                <tr class="<?php echo !$n['is_read'] ? 'table-warning-subtle fw-semibold' : ''; ?>">
                                    <td class="ps-4"><small class="text-muted"><?php echo date('M d, H:i', strtotime($n['created_at'])); ?></small></td>
                                    <td><strong><?php echo e($n['full_name'] ?: 'Investor'); ?></strong> <small class="text-muted">(<?php echo e($n['email']); ?>)</small></td>
                                    <td><code class="font-monospace text-primary"><?php echo e($n['application_id'] ?: 'N/A'); ?></code></td>
                                    <td><?php echo e($n['message']); ?></td>
                                    <td>
                                        <?php if ($n['is_read']): ?>
                                            <span class="badge bg-light text-muted border">Read</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger">New Unread</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end pe-4">
                                        <?php if (!$n['is_read']): ?>
                                            <form action="/digital-investor/admin/dashboard.php?action=read&notif_id=<?php echo $n['id']; ?>" method="POST" class="d-inline">
                                                <?php echo csrfField(); ?>
                                                <button type="submit" class="btn btn-sm btn-outline-success">
                                                    <i class="fas fa-check me-1"></i> Mark as Read
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <span class="text-muted fs-8"><i class="fas fa-check-double text-success me-1"></i>Read</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Statistics Metrics Cards Grid -->
    <div class="row g-3 mb-4">
        <!-- 1. Total Investors -->
        <div class="col-md-4 col-lg-2-4">
            <div class="card card-hover border-start border-primary border-4 p-3 shadow-sm">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted fs-8 fw-semibold text-uppercase">Total Investors</span>
                        <h2 class="fw-bold mb-0 text-primary"><?php echo $totalInvestors; ?></h2>
                    </div>
                    <div class="bg-primary-subtle text-primary p-3 rounded-circle">
                        <i class="fas fa-users fs-4"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- 2. Pending eKYC -->
        <div class="col-md-4 col-lg-2-4">
            <div class="card card-hover border-start border-info border-4 p-3 shadow-sm">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted fs-8 fw-semibold text-uppercase">Pending eKYC</span>
                        <h2 class="fw-bold mb-0 text-info"><?php echo $pendingKyc; ?></h2>
                    </div>
                    <div class="bg-info-subtle text-info p-3 rounded-circle">
                        <i class="fas fa-id-card fs-4"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- 3. Pending Approvals -->
        <div class="col-md-4 col-lg-2-4">
            <div class="card card-hover border-start border-warning border-4 p-3 shadow-sm">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted fs-8 fw-semibold text-uppercase">Pending Review</span>
                        <h2 class="fw-bold mb-0 text-warning-emphasis"><?php echo $pendingApprovals; ?></h2>
                    </div>
                    <div class="bg-warning-subtle text-warning-emphasis p-3 rounded-circle">
                        <i class="fas fa-clock fs-4"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- 4. Approved Applications -->
        <div class="col-md-6 col-lg-2-4">
            <div class="card card-hover border-start border-success border-4 p-3 shadow-sm">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted fs-8 fw-semibold text-uppercase">Approved</span>
                        <h2 class="fw-bold mb-0 text-success"><?php echo $approvedApps; ?></h2>
                    </div>
                    <div class="bg-success-subtle text-success p-3 rounded-circle">
                        <i class="fas fa-check-circle fs-4"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- 5. Rejected Applications -->
        <div class="col-md-6 col-lg-2-4">
            <div class="card card-hover border-start border-danger border-4 p-3 shadow-sm">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted fs-8 fw-semibold text-uppercase">Rejected</span>
                        <h2 class="fw-bold mb-0 text-danger"><?php echo $rejectedApps; ?></h2>
                    </div>
                    <div class="bg-danger-subtle text-danger p-3 rounded-circle">
                        <i class="fas fa-times-circle fs-4"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Academic Project Information Card -->
    <div class="card shadow-sm border-0 rounded-4 mb-4 border-top border-primary border-4">
        <div class="card-header bg-white py-3 border-bottom d-flex align-items-center justify-content-between">
            <h5 class="fw-bold mb-0 text-dark"><i class="fas fa-graduation-cap text-primary me-2"></i>Academic Project & Student Information</h5>
            <span class="badge bg-secondary font-monospace">Academic Year <?php echo e($academic['academic_year']); ?></span>
        </div>
        <div class="card-body p-4">
            <div class="row g-3">
                <div class="col-md-4">
                    <small class="text-muted d-block fs-8 text-uppercase fw-semibold">Student Name</small>
                    <strong class="fs-6 text-dark"><?php echo e($academic['student_name']); ?></strong>
                </div>
                <div class="col-md-4">
                    <small class="text-muted d-block fs-8 text-uppercase fw-semibold">Register Number</small>
                    <code class="fs-6 fw-bold text-primary"><?php echo e($academic['register_number']); ?></code>
                </div>
                <div class="col-md-4">
                    <small class="text-muted d-block fs-8 text-uppercase fw-semibold">Department</small>
                    <strong class="text-dark"><?php echo e($academic['department']); ?></strong>
                </div>

                <div class="col-md-4">
                    <small class="text-muted d-block fs-8 text-uppercase fw-semibold">College / University Name</small>
                    <strong class="text-dark"><?php echo e($academic['college_name']); ?></strong>
                </div>
                <div class="col-md-4">
                    <small class="text-muted d-block fs-8 text-uppercase fw-semibold">Project Guide Name</small>
                    <strong class="text-dark"><?php echo e($academic['guide_name']); ?></strong>
                </div>
                <div class="col-md-4">
                    <small class="text-muted d-block fs-8 text-uppercase fw-semibold">Team Members</small>
                    <strong class="text-dark"><?php echo e($academic['team_members']); ?></strong>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Investor Applications Table -->
    <div class="card shadow-sm border-0 rounded-4 mb-4">
        <div class="card-header bg-white py-3 border-bottom d-flex align-items-center justify-content-between">
            <h5 class="fw-bold mb-0 text-dark"><i class="fas fa-list me-2 text-primary"></i>Recent Investor Applications</h5>
            <a href="/digital-investor/admin/investors.php" class="btn btn-sm btn-outline-primary fw-semibold">View All Investors</a>
        </div>

        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light fs-7 text-uppercase text-muted">
                        <tr>
                            <th class="ps-4">Application ID</th>
                            <th>Investor Name</th>
                            <th>Email</th>
                            <th>eKYC Status</th>
                            <th>eSign Status</th>
                            <th>Application Status</th>
                            <th class="text-end pe-4">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentApps)): ?>
                            <tr><td colspan="7" class="text-center py-4 text-muted">No investor applications found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($recentApps as $a): ?>
                                <tr>
                                    <td class="ps-4 fw-bold font-monospace text-primary"><?php echo e($a['application_id']); ?></td>
                                    <td><strong><?php echo e($a['full_name'] ?: 'N/A'); ?></strong></td>
                                    <td><small class="text-muted"><?php echo e($a['email']); ?></small></td>
                                    <td><?php echo renderStatusBadge($a['kyc_status'] ?? 'Pending'); ?></td>
                                    <td><?php echo renderStatusBadge($a['esign_status'] ?? 'Pending'); ?></td>
                                    <td><?php echo renderStatusBadge($a['status']); ?></td>
                                    <td class="text-end pe-4">
                                        <a href="/digital-investor/admin/view_investor.php?id=<?php echo $a['user_id']; ?>" class="btn btn-sm btn-primary">
                                            <i class="fas fa-eye me-1"></i> Inspect Dossier
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
