<?php
// investor/documents.php
// Document Management Page with Direct Card-Based Uploads

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

// Mandatory Document Categories Configuration
$requiredDocConfig = [
    'Passport-size Photo' => [
        'label' => 'Passport-size Photo',
        'icon' => 'fas fa-portrait',
        'badge_color' => 'primary',
        'allowed_ext' => ['jpg', 'jpeg', 'png'],
        'formats_text' => 'JPG, JPEG, PNG (PDF not allowed)',
        'accept' => '.jpg,.jpeg,.png',
        'description' => 'Recent front-facing color photo with clear background.'
    ],
    'Aadhaar Card' => [
        'label' => 'Aadhaar Card',
        'icon' => 'fas fa-id-card',
        'badge_color' => 'success',
        'allowed_ext' => ['jpg', 'jpeg', 'png', 'pdf'],
        'formats_text' => 'JPG, JPEG, PNG, PDF',
        'accept' => '.jpg,.jpeg,.png,.pdf',
        'description' => 'Aadhaar document copy (front & back) or e-Aadhaar PDF.'
    ],
    'ID Proof' => [
        'label' => 'ID Proof',
        'icon' => 'fas fa-address-card',
        'badge_color' => 'info',
        'allowed_ext' => ['jpg', 'jpeg', 'png', 'pdf'],
        'formats_text' => 'JPG, JPEG, PNG, PDF',
        'accept' => '.jpg,.jpeg,.png,.pdf',
        'description' => 'PAN Card, Voter ID, or Driving Licence copy.'
    ]
];

// Handle Upload Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCSRFPost();

    $docType = trim($_POST['doc_type'] ?? '');
    $docName = trim($_POST['doc_name'] ?? '');

    if (empty($docType)) {
        $error = 'Please select a document category.';
    } elseif (!isset($requiredDocConfig[$docType])) {
        $error = 'Invalid document category. Please upload one of the 3 required categories.';
    } elseif (empty($_FILES['document_file']['name'])) {
        $error = 'Please choose a file to upload for ' . e($docType) . '.';
    } else {
        $config = $requiredDocConfig[$docType];
        $fileExt = strtolower(pathinfo($_FILES['document_file']['name'], PATHINFO_EXTENSION));

        // Enforce format validation: Photo allows only JPG, JPEG, PNG; Others allow JPG, JPEG, PNG, PDF
        if ($docType === 'Passport-size Photo' && $fileExt === 'pdf') {
            $error = 'Passport-size Photo must be an image file (JPG, JPEG, or PNG). PDF files are not allowed for photographs.';
        } elseif (!in_array($fileExt, $config['allowed_ext'])) {
            $error = 'Invalid file format for ' . e($docType) . '. Allowed formats: ' . implode(', ', array_map('strtoupper', $config['allowed_ext'])) . '.';
        } else {
            $uploadResult = handleSecureFileUpload(
                $_FILES['document_file'],
                dirname(__DIR__) . '/uploads/documents',
                $config['allowed_ext']
            );

            if (!$uploadResult['success']) {
                $error = $uploadResult['error'];
            } else {
                $displayName = !empty($docName) ? $docName : ($docType . ' - ' . $uploadResult['file_name']);
                $relativePath = 'uploads/documents/' . basename($uploadResult['file_path']);

                $stmtIns = $pdo->prepare("INSERT INTO documents (user_id, doc_type, doc_name, file_path, file_size, status) VALUES (?, ?, ?, ?, ?, 'Pending')");
                $stmtIns->execute([$userId, $docType, $displayName, $relativePath, $uploadResult['file_size']]);

                refreshApplicationStatus($pdo, $userId);

                // Create Admin Notification
                createAdminNotification($pdo, $userId, 'doc_upload', "Investor uploaded mandatory document: " . $displayName . " (" . $docType . ")");

                setFlash('success', e($docType) . ' uploaded successfully!');
                header("Location: /digital-investor/investor/documents.php");
                exit;
            }
        }
    }
}

// Fetch user's required documents status
$reqStatus = checkUserRequiredDocuments($pdo, $userId);
$allCompleted = $reqStatus['all_completed'];
$uploadedDocs = $reqStatus['uploaded'];
$missingDocs = $reqStatus['missing'];

// Render HTML View
$pageTitle = "Supporting Documents - Digital Investor Onboarding";
require_once __DIR__ . '/../includes/header.php';
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

            <!-- Mandatory Documents Overview & Direct Action Cards -->
            <div class="card shadow-sm border-0 rounded-4">
                <div class="card-header bg-white py-3 border-bottom d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <div class="d-flex align-items-center gap-2">
                        <div class="bg-primary-subtle text-primary rounded-circle p-2 px-3">
                            <i class="fas fa-folder-open fs-5"></i>
                        </div>
                        <div>
                            <h5 class="fw-bold mb-0 text-dark">
                                Mandatory Supporting Documents
                            </h5>
                            <small class="text-muted">All 3 document types below are mandatory before you can proceed to eSign.</small>
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-secondary">Module 3 of 4</span>
                        <?php if ($allCompleted): ?>
                            <span class="badge bg-success px-3 py-2 fs-7 rounded-pill">
                                <i class="fas fa-check-circle me-1"></i> 3 of 3 Uploaded (Ready for eSign)
                            </span>
                        <?php else: ?>
                            <span class="badge bg-warning text-dark px-3 py-2 fs-7 rounded-pill">
                                <i class="fas fa-clock me-1"></i> <?php echo count($uploadedDocs); ?> of 3 Uploaded
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card-body p-4">
                    <!-- Validation Status Alert -->
                    <?php if ($allCompleted): ?>
                        <div class="alert alert-success d-flex align-items-center rounded-3 shadow-sm mb-4 border border-success">
                            <i class="fas fa-check-circle fs-3 text-success me-3"></i>
                            <div class="flex-grow-1">
                                <h6 class="fw-bold mb-1 text-success">All Required Documents Uploaded Successfully</h6>
                                <p class="mb-0 small text-dark">
                                    You have successfully uploaded your <strong>Passport-size Photo</strong>, <strong>Aadhaar Card</strong>, and <strong>ID Proof</strong>. You can now proceed to the <strong>eSign</strong> module!
                                </p>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-warning d-flex align-items-center rounded-3 shadow-sm mb-4 border border-warning">
                            <i class="fas fa-exclamation-triangle fs-3 text-warning-emphasis me-3"></i>
                            <div class="flex-grow-1">
                                <h6 class="fw-bold mb-1 text-warning-emphasis">
                                    Missing Required Documents (<?php echo count($missingDocs); ?> Missing)
                                </h6>
                                <p class="mb-0 small text-dark">
                                    Please upload the following mandatory document(s) before proceeding to eSign: 
                                    <strong><?php echo e(implode(', ', $missingDocs)); ?></strong>.
                                </p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- 3 Mandatory Document Cards -->
                    <div class="row g-3">
                        <?php foreach ($requiredDocConfig as $key => $cfg): ?>
                            <?php 
                                $isUploaded = isset($uploadedDocs[$key]); 
                                $docItem = $isUploaded ? $uploadedDocs[$key] : null;
                                $formId = 'upload_form_' . md5($key);
                                $fileId = 'file_input_' . md5($key);
                            ?>
                            <div class="col-md-4">
                                <div class="card h-100 rounded-3 border <?php echo $isUploaded ? 'border-success bg-white shadow-sm' : 'border-light-subtle bg-light'; ?>">
                                    <div class="card-body p-3 d-flex flex-column">
                                        <!-- Hidden Dedicated Upload Form for this Card -->
                                        <form action="/digital-investor/investor/documents.php" method="POST" enctype="multipart/form-data" id="<?php echo $formId; ?>" class="d-none">
                                            <?php echo csrfField(); ?>
                                            <input type="hidden" name="doc_type" value="<?php echo e($key); ?>">
                                            <input type="file" name="document_file" id="<?php echo $fileId; ?>" class="card-file-input" accept="<?php echo $cfg['accept']; ?>" data-cat="<?php echo e($key); ?>">
                                        </form>

                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <div class="d-flex align-items-center gap-2">
                                                <i class="<?php echo $cfg['icon']; ?> text-<?php echo $cfg['badge_color']; ?> fs-4"></i>
                                                <strong class="text-dark fs-6"><?php echo e($cfg['label']); ?></strong>
                                            </div>
                                        </div>

                                        <div class="mb-2">
                                            <?php if ($isUploaded): ?>
                                                <span class="badge bg-success">
                                                    <i class="fas fa-check-circle me-1"></i> Uploaded
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-warning text-dark">
                                                    <i class="fas fa-clock me-1"></i> Pending Upload
                                                </span>
                                            <?php endif; ?>
                                            <span class="badge bg-danger-subtle text-danger border border-danger ms-1">Mandatory</span>
                                        </div>

                                        <p class="text-muted small mb-2 flex-grow-1">
                                            <?php echo e($cfg['description']); ?>
                                        </p>

                                        <?php if ($isUploaded && $docItem): ?>
                                            <div class="p-2 bg-light border rounded small mb-2">
                                                <div class="fw-bold text-truncate text-dark" title="<?php echo e($docItem['doc_name']); ?>">
                                                    <i class="fas fa-file-alt text-primary me-1"></i><?php echo e($docItem['doc_name']); ?>
                                                </div>
                                                <div class="text-muted fs-8 mt-1">
                                                    <span>Size: <?php echo round($docItem['file_size'] / 1024, 1); ?> KB</span> &bull;
                                                    <span><?php echo date('M d, H:i', strtotime($docItem['created_at'])); ?></span>
                                                </div>
                                            </div>
                                            <div class="d-flex gap-2 mt-auto">
                                                <a href="/digital-investor/investor/view_document.php?id=<?php echo $docItem['id']; ?>" target="_blank" class="btn btn-outline-primary btn-sm flex-fill fw-semibold">
                                                    <i class="fas fa-eye me-1"></i> View
                                                </a>
                                                <button type="button" class="btn btn-warning text-dark btn-sm flex-fill fw-bold card-upload-trigger" data-target="<?php echo $fileId; ?>" title="Upload a new file">
                                                    <i class="fas fa-upload me-1"></i> Upload
                                                </button>
                                            </div>
                                        <?php else: ?>
                                            <div class="p-2 bg-white border border-dashed rounded text-center small text-muted mb-2">
                                                <i class="fas fa-file-upload text-muted me-1"></i> No file uploaded yet
                                                <div class="fs-8 text-secondary mt-1">Formats: <?php echo e($cfg['formats_text']); ?></div>
                                            </div>
                                            <button type="button" class="btn btn-outline-primary btn-sm w-100 mt-auto fw-bold card-upload-trigger" data-target="<?php echo $fileId; ?>">
                                                <i class="fas fa-upload me-1"></i> Choose & Upload
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Action Navigation Buttons -->
                    <div class="d-flex justify-content-between align-items-center border-top pt-4 mt-4 flex-wrap gap-2">
                        <a href="/digital-investor/dashboard.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-1"></i> Back to Dashboard
                        </a>
                        <div>
                            <?php if ($allCompleted): ?>
                                <a href="/digital-investor/investor/esign.php" class="btn btn-success fw-bold px-4 rounded-3 shadow-sm">
                                    Proceed to eSign <i class="fas fa-arrow-right ms-1"></i>
                                </a>
                            <?php else: ?>
                                <button type="button" class="btn btn-secondary fw-bold px-4 rounded-3" disabled title="Upload all 3 mandatory documents (Passport-size Photo, Aadhaar Card, ID Proof) to proceed to eSign">
                                    <i class="fas fa-lock me-1"></i> Proceed to eSign <i class="fas fa-arrow-right ms-1"></i>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // When user clicks 'Choose & Upload' or '⟳' (re-upload) on any card
    document.querySelectorAll('.card-upload-trigger').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var targetInputId = this.getAttribute('data-target');
            if (targetInputId) {
                var fileInput = document.getElementById(targetInputId);
                if (fileInput) {
                    fileInput.click();
                }
            }
        });
    });

    // When a file is chosen from the native file picker
    document.querySelectorAll('.card-file-input').forEach(function (input) {
        input.addEventListener('change', function () {
            if (this.files && this.files[0]) {
                var file = this.files[0];
                var cat = this.getAttribute('data-cat');
                var ext = file.name.split('.').pop().toLowerCase();

                // Validate Passport-size Photo (PDF prohibited)
                if (cat === 'Passport-size Photo' && ext === 'pdf') {
                    alert('Passport-size Photo must be an image file (JPG, JPEG, or PNG). PDF files are not allowed for photographs.');
                    this.value = '';
                    return;
                }

                // Check allowed extensions
                var allowedMap = {
                    'Passport-size Photo': ['jpg', 'jpeg', 'png'],
                    'Aadhaar Card': ['jpg', 'jpeg', 'png', 'pdf'],
                    'ID Proof': ['jpg', 'jpeg', 'png', 'pdf']
                };

                if (allowedMap[cat] && !allowedMap[cat].includes(ext)) {
                    alert('Invalid format for ' + cat + '. Allowed formats: ' + allowedMap[cat].join(', ').toUpperCase());
                    this.value = '';
                    return;
                }

                // File size check (5MB)
                if (file.size > 5 * 1024 * 1024) {
                    alert('File size exceeds the 5MB maximum limit. Please choose a smaller file.');
                    this.value = '';
                    return;
                }

                // Automatically submit the card form
                var form = this.closest('form');
                if (form) {
                    var triggerBtn = document.querySelector('[data-target="' + this.id + '"]');
                    if (triggerBtn) {
                        triggerBtn.disabled = true;
                        triggerBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Uploading...';
                    }
                    form.submit();
                }
            }
        });
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
