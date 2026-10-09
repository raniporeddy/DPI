<?php
// investor/documents.php
// Document Management Page with Client-Side Instant File Preview

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/project_info.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/notification_helper.php';

requireRole('investor');

$userId = $_SESSION['user_id'];
$error = '';

// Handle Upload BEFORE HTML Output
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCSRFPost();

    $docType = trim($_POST['doc_type'] ?? '');
    $docName = trim($_POST['doc_name'] ?? '');

    if (empty($docType) || empty($_FILES['document_file']['name'])) {
        $error = 'Please select a document category and choose a file to upload.';
    } else {
        $uploadResult = handleSecureFileUpload($_FILES['document_file'], dirname(__DIR__) . '/uploads/documents', ['jpg', 'jpeg', 'png', 'pdf']);
        if (!$uploadResult['success']) {
            $error = $uploadResult['error'];
        } else {
            $displayName = !empty($docName) ? $docName : $docType . ' - ' . $uploadResult['file_name'];
            $relativePath = 'uploads/documents/' . basename($uploadResult['file_path']);

            $stmtIns = $pdo->prepare("INSERT INTO documents (user_id, doc_type, doc_name, file_path, file_size, status) VALUES (?, ?, ?, ?, ?, 'Pending')");
            $stmtIns->execute([$userId, $docType, $displayName, $relativePath, $uploadResult['file_size']]);

            refreshApplicationStatus($pdo, $userId);

            // Create Admin Notification
            createAdminNotification($pdo, $userId, 'doc_upload', "Investor uploaded document: " . $displayName . " (" . $docType . ")");

            setFlash('success', 'Document uploaded successfully!');
            header("Location: /digital-investor/investor/documents.php");
            exit;
        }
    }
}

// Render HTML View
$pageTitle = "Document Management - Digital Investor Onboarding";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <?php echo displayFlash(); ?>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo e($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- Document Upload & Live Preview Card -->
            <div class="card shadow-sm border-0 rounded-4">
                <div class="card-header bg-white py-3 border-bottom d-flex align-items-center justify-content-between">
                    <h4 class="fw-bold mb-0 text-primary"><i class="fas fa-file-upload me-2"></i>Upload Required Documents</h4>
                    <span class="badge bg-secondary">Module 3 of 4</span>
                </div>

                <div class="card-body p-4">
                    <form action="/digital-investor/investor/documents.php" method="POST" enctype="multipart/form-data">
                        <?php echo csrfField(); ?>

                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label for="doc_type" class="form-label fw-semibold">Document Category <span class="text-danger">*</span></label>
                                <select class="form-select" id="doc_type" name="doc_type" required>
                                    <option value="">Select category...</option>
                                    <option value="Identity Proof">Identity Proof (PAN / Aadhaar / Passport)</option>
                                    <option value="Address Proof">Address Proof (Utility Bill / Bank Statement)</option>
                                    <option value="Application Form">Application Form / Declaration</option>
                                    <option value="Other Supporting Document">Other Supporting Document</option>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label for="doc_name" class="form-label fw-semibold">Document Custom Name (Optional)</label>
                                <input type="text" class="form-control" id="doc_name" name="doc_name" placeholder="e.g. Electricity Bill 2026">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="document_file" class="form-label fw-semibold">Choose File <span class="text-danger">*</span></label>
                            <input type="file" class="form-control form-control-lg" id="document_file" name="document_file" accept=".jpg,.jpeg,.png,.pdf" data-max-size="5" required>
                            <div class="form-text">Allowed Formats: JPG, JPEG, PNG, PDF (Max file size: 5MB)</div>
                        </div>

                        <!-- Live File Preview Container -->
                        <div id="filePreviewContainer" class="p-3 bg-light border rounded-3 d-none mb-4">
                            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                                <div>
                                    <small class="text-muted d-block fw-semibold">Selected File:</small>
                                    <span id="selectedFileName" class="fw-bold text-dark font-monospace me-2"></span>
                                    <span id="selectedFileSize" class="badge bg-secondary"></span>
                                </div>
                                <button type="button" id="viewSelectedFileBtn" class="btn btn-outline-primary btn-sm fw-bold">
                                    <i class="fas fa-eye me-1"></i> View / Preview File
                                </button>
                            </div>
                        </div>

                        <div class="d-flex justify-content-between align-items-center border-top pt-4">
                            <a href="/digital-investor/dashboard.php" class="btn btn-outline-secondary">
                                <i class="fas fa-arrow-left me-1"></i> Back to Dashboard
                            </a>
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-warning fw-bold text-dark px-4 rounded-3">
                                    <i class="fas fa-upload me-1"></i> Upload Document
                                </button>
                                <a href="/digital-investor/investor/esign.php" class="btn btn-success fw-bold px-4 rounded-3">
                                    Proceed to eSign <i class="fas fa-arrow-right ms-1"></i>
                                </a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var fileInput = document.getElementById('document_file');
    var previewContainer = document.getElementById('filePreviewContainer');
    var fileNameSpan = document.getElementById('selectedFileName');
    var fileSizeSpan = document.getElementById('selectedFileSize');
    var viewBtn = document.getElementById('viewSelectedFileBtn');
    var currentObjectUrl = null;

    fileInput.addEventListener('change', function () {
        if (this.files && this.files[0]) {
            var file = this.files[0];
            fileNameSpan.textContent = file.name;
            fileSizeSpan.textContent = (file.size / (1024 * 1024)).toFixed(2) + ' MB';

            if (currentObjectUrl) {
                URL.revokeObjectURL(currentObjectUrl);
            }
            currentObjectUrl = URL.createObjectURL(file);

            previewContainer.classList.remove('d-none');
        } else {
            previewContainer.classList.add('d-none');
        }
    });

    viewBtn.addEventListener('click', function () {
        if (!fileInput.files || !fileInput.files[0] || !currentObjectUrl) {
            alert('Please select a document file first.');
            return;
        }

        var file = fileInput.files[0];
        var ext = file.name.split('.').pop().toLowerCase();

        if (['jpg', 'jpeg', 'png', 'pdf'].includes(ext)) {
            window.open(currentObjectUrl, '_blank');
        } else {
            alert('Instant preview is available for JPG, PNG, and PDF document files.');
        }
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
