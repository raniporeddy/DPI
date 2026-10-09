<?php
// admin/paperless.php
// Centralized Admin Paperless Office Repository

$pageTitle = "Paperless Repository - Admin Console";
require_once __DIR__ . '/../includes/header.php';
requireAdmin();

// Fetch all digital documents with investor details
$stmt = $pdo->query("
    SELECT d.*, u.email, p.full_name, a.application_id
    FROM documents d
    JOIN users u ON d.user_id = u.id
    LEFT JOIN investor_profiles p ON u.id = p.user_id
    LEFT JOIN applications a ON u.id = a.user_id
    ORDER BY d.created_at DESC
");
$allDocuments = $stmt->fetchAll();

// Fetch eSignatures list
$stmtSig = $pdo->query("
    SELECT s.*, u.email, p.full_name, a.application_id
    FROM esignatures s
    JOIN users u ON s.user_id = u.id
    LEFT JOIN investor_profiles p ON u.id = p.user_id
    LEFT JOIN applications a ON u.id = a.user_id
    ORDER BY s.signed_at DESC
");
$allSignatures = $stmtSig->fetchAll();
?>

<div class="container-fluid px-4 py-4">
    <?php echo displayFlash(); ?>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold text-dark mb-1"><i class="fas fa-file-archive text-primary me-2"></i>Paperless Office Digital Asset Repository</h3>
            <p class="text-muted small mb-0">Centralized digital document vault for all investor identity proofs, eSign certificates, and onboarding dossiers.</p>
        </div>
        <div>
            <span class="badge bg-primary fs-6 px-3 py-2 me-2"><?php echo count($allDocuments); ?> Documents Vaulted</span>
            <span class="badge bg-success fs-6 px-3 py-2"><?php echo count($allSignatures); ?> Signatures Recorded</span>
        </div>
    </div>

    <!-- Digital Documents Table -->
    <div class="card shadow-sm border-0 rounded-4 mb-4">
        <div class="card-header bg-white py-3 border-bottom">
            <h5 class="fw-bold mb-0 text-dark"><i class="fas fa-folder me-2 text-warning"></i>Vaulted Digital Documents</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light fs-7 text-uppercase text-muted">
                        <tr>
                            <th class="ps-4">Application ID</th>
                            <th>Investor Name</th>
                            <th>Document Title</th>
                            <th>Category</th>
                            <th>File Size</th>
                            <th>Upload Date</th>
                            <th>Status</th>
                            <th class="text-end pe-4">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($allDocuments)): ?>
                            <tr><td colspan="8" class="text-center py-4 text-muted">No documents vaulted in repository yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($allDocuments as $doc): ?>
                                <tr>
                                    <td class="ps-4 font-monospace fw-bold text-primary"><?php echo e($doc['application_id'] ?: 'N/A'); ?></td>
                                    <td>
                                        <strong class="d-block text-dark"><?php echo e($doc['full_name'] ?: 'N/A'); ?></strong>
                                        <small class="text-muted"><?php echo e($doc['email']); ?></small>
                                    </td>
                                    <td><strong><?php echo e($doc['doc_name']); ?></strong></td>
                                    <td><span class="badge bg-light text-dark border"><?php echo e($doc['doc_type']); ?></span></td>
                                    <td><small class="text-muted"><?php echo round($doc['file_size'] / 1024, 1); ?> KB</small></td>
                                    <td><small class="text-muted"><?php echo date('M d, Y H:i', strtotime($doc['created_at'])); ?></small></td>
                                    <td><?php echo renderStatusBadge($doc['status']); ?></td>
                                    <td class="text-end pe-4">
                                        <a href="/digital-investor/<?php echo e($doc['file_path']); ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-eye me-1"></i> View Asset
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

    <!-- Recorded Digital Signatures Table -->
    <div class="card shadow-sm border-0 rounded-4">
        <div class="card-header bg-white py-3 border-bottom">
            <h5 class="fw-bold mb-0 text-dark"><i class="fas fa-signature me-2 text-success"></i>Executed Electronic Signatures</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light fs-7 text-uppercase text-muted">
                        <tr>
                            <th class="ps-4">Application ID</th>
                            <th>Investor Name</th>
                            <th>Signature Preview</th>
                            <th>IP Address Audit</th>
                            <th>Signed Date</th>
                            <th>Status</th>
                            <th class="text-end pe-4">Dossier</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($allSignatures)): ?>
                            <tr><td colspan="7" class="text-center py-4 text-muted">No electronic signatures recorded yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($allSignatures as $sig): ?>
                                <tr>
                                    <td class="ps-4 font-monospace fw-bold text-primary"><?php echo e($sig['application_id'] ?: 'N/A'); ?></td>
                                    <td>
                                        <strong class="d-block text-dark"><?php echo e($sig['full_name'] ?: 'N/A'); ?></strong>
                                        <small class="text-muted"><?php echo e($sig['email']); ?></small>
                                    </td>
                                    <td>
                                        <img src="/digital-investor/<?php echo e($sig['signature_path']); ?>" alt="Signature" style="max-height: 45px;" class="bg-white p-1 border rounded">
                                    </td>
                                    <td><code class="fs-8"><?php echo e($sig['ip_address']); ?></code></td>
                                    <td><small class="text-muted"><?php echo e($sig['signed_at']); ?></small></td>
                                    <td><?php echo renderStatusBadge($sig['status']); ?></td>
                                    <td class="text-end pe-4">
                                        <a href="/digital-investor/paperless.php?user_id=<?php echo $sig['user_id']; ?>" target="_blank" class="btn btn-sm btn-outline-dark">
                                            <i class="fas fa-file-contract me-1"></i> Full Dossier
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
