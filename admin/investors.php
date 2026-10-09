<?php
// admin/investors.php
// All Investors Management & Search

$pageTitle = "All Investors - Admin Console";
require_once __DIR__ . '/../includes/header.php';
requireAdmin();

$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$kycFilter = trim($_GET['kyc_status'] ?? '');

// Build dynamic WHERE query
$where = ["u.role = 'investor'"];
$params = [];

if (!empty($search)) {
    $where[] = "(p.full_name LIKE ? OR u.email LIKE ? OR a.application_id LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if (!empty($statusFilter)) {
    $where[] = "a.status = ?";
    $params[] = $statusFilter;
}

if (!empty($kycFilter)) {
    $where[] = "k.status = ?";
    $params[] = $kycFilter;
}

$whereClause = implode(" AND ", $where);

$sql = "
    SELECT u.id as user_id, u.email, u.sso_provider, u.created_at as registered_at,
           p.full_name, p.mobile, p.city, p.id_type, p.id_number,
           a.application_id, a.status as app_status, a.profile_completed, a.kyc_completed, a.docs_completed, a.esign_completed,
           k.status as kyc_status,
           s.status as esign_status
    FROM users u
    LEFT JOIN investor_profiles p ON u.id = p.user_id
    LEFT JOIN applications a ON u.id = a.user_id
    LEFT JOIN kyc_verification k ON u.id = k.user_id
    LEFT JOIN esignatures s ON u.id = s.user_id
    WHERE $whereClause
    ORDER BY u.created_at DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$investors = $stmt->fetchAll();
?>

<div class="container-fluid px-4 py-4">
    <?php echo displayFlash(); ?>

    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold text-dark mb-1"><i class="fas fa-users text-primary me-2"></i>Investor Directory</h3>
            <p class="text-muted small mb-0">Search, filter, and inspect registered investors across all onboarding stages.</p>
        </div>
        <span class="badge bg-primary fs-6 px-3 py-2 rounded-pill"><?php echo count($investors); ?> Investors Found</span>
    </div>

    <!-- Search & Filter Controls -->
    <div class="card shadow-sm border-0 rounded-4 mb-4">
        <div class="card-body p-3">
            <form action="/digital-investor/admin/investors.php" method="GET" class="row g-3">
                <div class="col-md-5">
                    <div class="input-group">
                        <span class="input-group-text bg-light"><i class="fas fa-search text-muted"></i></span>
                        <input type="text" class="form-control" name="search" placeholder="Search by name, email, or application ID..." value="<?php echo e($search); ?>">
                    </div>
                </div>

                <div class="col-md-3">
                    <select name="status" class="form-select">
                        <option value="">All Application Statuses</option>
                        <option value="Draft" <?php echo $statusFilter === 'Draft' ? 'selected' : ''; ?>>Draft</option>
                        <option value="Submitted" <?php echo $statusFilter === 'Submitted' ? 'selected' : ''; ?>>Submitted</option>
                        <option value="Under Review" <?php echo $statusFilter === 'Under Review' ? 'selected' : ''; ?>>Under Review</option>
                        <option value="Approved" <?php echo $statusFilter === 'Approved' ? 'selected' : ''; ?>>Approved</option>
                        <option value="Rejected" <?php echo $statusFilter === 'Rejected' ? 'selected' : ''; ?>>Rejected</option>
                    </select>
                </div>

                <div class="col-md-3">
                    <select name="kyc_status" class="form-select">
                        <option value="">All eKYC Statuses</option>
                        <option value="Pending" <?php echo $kycFilter === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="Submitted" <?php echo $kycFilter === 'Submitted' ? 'selected' : ''; ?>>Submitted</option>
                        <option value="Verified" <?php echo $kycFilter === 'Verified' ? 'selected' : ''; ?>>Verified</option>
                        <option value="Rejected" <?php echo $kycFilter === 'Rejected' ? 'selected' : ''; ?>>Rejected</option>
                    </select>
                </div>

                <div class="col-md-1 d-grid">
                    <button type="submit" class="btn btn-primary fw-bold">Filter</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Investors List Table -->
    <div class="card shadow-sm border-0 rounded-4">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light fs-7 text-uppercase text-muted">
                        <tr>
                            <th class="ps-4">Application ID</th>
                            <th>Investor Profile</th>
                            <th>Mobile / Location</th>
                            <th>eKYC Status</th>
                            <th>eSign Status</th>
                            <th>Onboarding Steps</th>
                            <th>App Status</th>
                            <th class="text-end pe-4">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($investors)): ?>
                            <tr><td colspan="8" class="text-center py-5 text-muted">No investors match the search/filter criteria.</td></tr>
                        <?php else: ?>
                            <?php foreach ($investors as $inv): ?>
                                <tr>
                                    <td class="ps-4 font-monospace fw-bold text-primary">
                                        <?php echo e($inv['application_id'] ?: 'N/A'); ?>
                                        <?php if (!empty($inv['sso_provider'])): ?>
                                            <span class="badge bg-warning text-dark d-block mt-1 fs-8"><i class="fas fa-key me-1"></i>SSO</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong class="d-block text-dark"><?php echo e($inv['full_name'] ?: 'Profile Incomplete'); ?></strong>
                                        <small class="text-muted"><?php echo e($inv['email']); ?></small>
                                    </td>
                                    <td>
                                        <small class="d-block text-dark"><?php echo e($inv['mobile'] ?: 'N/A'); ?></small>
                                        <small class="text-muted"><?php echo e($inv['city'] ?: 'N/A'); ?></small>
                                    </td>
                                    <td><?php echo renderStatusBadge($inv['kyc_status'] ?? 'Pending'); ?></td>
                                    <td><?php echo renderStatusBadge($inv['esign_status'] ?? 'Pending'); ?></td>
                                    <td>
                                        <div class="d-flex gap-1" title="Profile | eKYC | Docs | eSign">
                                            <span class="badge <?php echo $inv['profile_completed'] ? 'bg-success' : 'bg-light text-dark border'; ?>">Prof</span>
                                            <span class="badge <?php echo $inv['kyc_completed'] ? 'bg-success' : 'bg-light text-dark border'; ?>">KYC</span>
                                            <span class="badge <?php echo $inv['docs_completed'] ? 'bg-success' : 'bg-light text-dark border'; ?>">Doc</span>
                                            <span class="badge <?php echo $inv['esign_completed'] ? 'bg-success' : 'bg-light text-dark border'; ?>">Sign</span>
                                        </div>
                                    </td>
                                    <td><?php echo renderStatusBadge($inv['app_status'] ?: 'Draft'); ?></td>
                                    <td class="text-end pe-4">
                                        <a href="/digital-investor/admin/view_investor.php?id=<?php echo $inv['user_id']; ?>" class="btn btn-sm btn-outline-primary fw-semibold">
                                            <i class="fas fa-search me-1"></i> Inspect Dossier
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
