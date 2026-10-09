<?php
// investor/profile.php
// Investor Profile Form & Management

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
$error = '';

// Fetch current profile data
$stmt = $pdo->prepare("SELECT * FROM investor_profiles WHERE user_id = ?");
$stmt->execute([$userId]);
$profile = $stmt->fetch();

// Fetch eKYC for photo & identity alignment
$stmtKyc = $pdo->prepare("SELECT * FROM kyc_verification WHERE user_id = ?");
$stmtKyc->execute([$userId]);
$kyc = $stmtKyc->fetch();
$hasVerifiedPhoto = !empty($kyc['photo_path']) && file_exists(dirname(__DIR__) . '/' . $kyc['photo_path']);

// Handle Form Submission BEFORE HTML Output
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCSRFPost();

    $fullName   = trim($_POST['full_name'] ?? '');
    $dob        = trim($_POST['dob'] ?? '');
    $gender     = trim($_POST['gender'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $mobile     = trim($_POST['mobile'] ?? '');
    $address    = trim($_POST['address'] ?? '');
    $city       = trim($_POST['city'] ?? '');
    $state      = trim($_POST['state'] ?? '');
    $pinCode    = trim($_POST['pin_code'] ?? '');
    $occupation = trim($_POST['occupation'] ?? '');
    $idType     = trim($_POST['id_type'] ?? 'PAN');
    $idNumber   = strtoupper(trim($_POST['id_number'] ?? ''));

    // Server-side validations
    if (empty($fullName) || empty($dob) || empty($gender) || empty($mobile) || empty($address) || empty($city) || empty($state) || empty($pinCode) || empty($idNumber)) {
        $error = 'Please fill in all mandatory fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (!preg_match('/^[0-9]{10}$/', $mobile)) {
        $error = 'Mobile number must be exactly 10 digits.';
    } elseif (!preg_match('/^[0-9]{6}$/', $pinCode)) {
        $error = 'PIN Code must be exactly 6 digits.';
    } else {
        try {
            if ($profile) {
                // Update existing profile
                $stmtUpd = $pdo->prepare("UPDATE investor_profiles SET full_name = ?, dob = ?, gender = ?, email = ?, mobile = ?, address = ?, city = ?, state = ?, pin_code = ?, occupation = ?, id_type = ?, id_number = ?, updated_at = NOW() WHERE user_id = ?");
                $stmtUpd->execute([$fullName, $dob, $gender, $email, $mobile, $address, $city, $state, $pinCode, $occupation, $idType, $idNumber, $userId]);
            } else {
                // Insert new profile
                $stmtIns = $pdo->prepare("INSERT INTO investor_profiles (user_id, full_name, dob, gender, email, mobile, address, city, state, pin_code, occupation, id_type, id_number) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmtIns->execute([$userId, $fullName, $dob, $gender, $email, $mobile, $address, $city, $state, $pinCode, $occupation, $idType, $idNumber]);
            }

            // Update session full name
            $_SESSION['full_name'] = $fullName;

            // Refresh Application progress status
            refreshApplicationStatus($pdo, $userId);

            setFlash('success', 'Investor profile saved and updated successfully!');
            
            // Clean HTTP redirect before any output
            header("Location: /digital-investor/dashboard.php");
            exit;

        } catch (Exception $e) {
            error_log("Profile Update Error: " . $e->getMessage());
            $error = 'Database error while saving profile. Please try again.';
        }
    }
}

// Render HTML view
$pageTitle = "Investor Profile - Digital Investor Onboarding";
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

            <!-- Verified Aadhaar Profile Photo Banner -->
            <div class="card bg-white border border-2 border-primary-subtle shadow-sm rounded-4 p-3 mb-4">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
                    <div class="d-flex align-items-center gap-3">
                        <?php if ($hasVerifiedPhoto): ?>
                            <div class="position-relative">
                                <img src="/digital-investor/<?php echo e($kyc['photo_path']); ?>" alt="Aadhaar Verified Photo" style="width: 72px; height: 88px; object-fit: cover; object-position: center;" class="rounded-3 border shadow-sm">
                                <?php if (($kyc['status'] ?? '') === 'Verified'): ?>
                                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-circle bg-success border border-white p-1" title="Verified Photo">
                                        <i class="fas fa-check"></i>
                                    </span>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="bg-light text-secondary rounded-3 d-flex align-items-center justify-content-center border" style="width: 72px; height: 88px;">
                                <i class="fas fa-user-circle fs-1 text-muted"></i>
                            </div>
                        <?php endif; ?>
                        <div>
                            <h5 class="fw-bold mb-1 text-dark"><?php echo e($profile['full_name'] ?? $_SESSION['email']); ?></h5>
                            <span class="text-muted small d-block">Investor Account ID: <code>#<?php echo e($userId); ?></code></span>
                            <?php if ($hasVerifiedPhoto): ?>
                                <span class="badge bg-success-subtle text-success border border-success mt-1"><i class="fas fa-id-card me-1"></i> Aadhaar Photo Attached</span>
                            <?php else: ?>
                                <span class="badge bg-secondary mt-1"><i class="fas fa-info-circle me-1"></i> Default Profile Avatar (eKYC Photo Pending)</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div>
                        <a href="/digital-investor/investor/ekyc.php" class="btn btn-outline-primary btn-sm fw-semibold">
                            <i class="fas fa-id-card me-1"></i> Manage eKYC Document
                        </a>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm border-0 rounded-4 mb-4">
                <div class="card-header bg-white py-3 border-bottom d-flex align-items-center justify-content-between">
                    <h4 class="fw-bold mb-0 text-primary"><i class="fas fa-user-edit me-2"></i>Investor Profile Form</h4>
                    <span class="badge bg-secondary">Module 1 of 4</span>
                </div>

                <div class="card-body p-4">
                    <form action="/digital-investor/investor/profile.php" method="POST" class="needs-validation" novalidate>
                        <?php echo csrfField(); ?>

                        <h6 class="fw-bold text-uppercase text-muted border-bottom pb-2 mb-3 fs-7">1. Personal Details</h6>
                        
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label for="full_name" class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="full_name" name="full_name" required value="<?php echo e($_POST['full_name'] ?? $profile['full_name'] ?? ''); ?>" placeholder="As per official ID proof">
                            </div>
                            <div class="col-md-3">
                                <label for="dob" class="form-label fw-semibold">Date of Birth <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="dob" name="dob" required value="<?php echo e($_POST['dob'] ?? $profile['dob'] ?? ''); ?>">
                            </div>
                            <div class="col-md-3">
                                <label for="gender" class="form-label fw-semibold">Gender <span class="text-danger">*</span></label>
                                <select class="form-select" id="gender" name="gender" required>
                                    <?php $currentGender = $_POST['gender'] ?? $profile['gender'] ?? ''; ?>
                                    <option value="">Select...</option>
                                    <option value="Male" <?php echo $currentGender === 'Male' ? 'selected' : ''; ?>>Male</option>
                                    <option value="Female" <?php echo $currentGender === 'Female' ? 'selected' : ''; ?>>Female</option>
                                    <option value="Other" <?php echo $currentGender === 'Other' ? 'selected' : ''; ?>>Other</option>
                                </select>
                            </div>
                        </div>

                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label for="email" class="form-label fw-semibold">Email Address <span class="text-danger">*</span></label>
                                <input type="email" class="form-control" id="email" name="email" required value="<?php echo e($profile['email'] ?? $_SESSION['email']); ?>" readonly>
                                <small class="text-muted fs-8">Primary login email address</small>
                            </div>
                            <div class="col-md-6">
                                <label for="mobile" class="form-label fw-semibold">Mobile Number <span class="text-danger">*</span></label>
                                <input type="tel" class="form-control" id="mobile" name="mobile" required pattern="[0-9]{10}" placeholder="10-digit mobile number" value="<?php echo e($_POST['mobile'] ?? $profile['mobile'] ?? ''); ?>">
                            </div>
                        </div>

                        <h6 class="fw-bold text-uppercase text-muted border-bottom pb-2 mb-3 fs-7">2. Address & Occupation</h6>

                        <div class="mb-3">
                            <label for="address" class="form-label fw-semibold">Permanent Address <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="address" name="address" rows="2" required placeholder="House/Flat No., Street, Area"><?php echo e($_POST['address'] ?? $profile['address'] ?? ''); ?></textarea>
                        </div>

                        <div class="row g-3 mb-4">
                            <div class="col-md-4">
                                <label for="city" class="form-label fw-semibold">City <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="city" name="city" required value="<?php echo e($_POST['city'] ?? $profile['city'] ?? ''); ?>">
                            </div>
                            <div class="col-md-4">
                                <label for="state" class="form-label fw-semibold">State <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="state" name="state" required value="<?php echo e($_POST['state'] ?? $profile['state'] ?? ''); ?>">
                            </div>
                            <div class="col-md-4">
                                <label for="pin_code" class="form-label fw-semibold">PIN Code <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="pin_code" name="pin_code" required pattern="[0-9]{6}" maxlength="6" value="<?php echo e($_POST['pin_code'] ?? $profile['pin_code'] ?? ''); ?>" placeholder="6 digits">
                            </div>
                        </div>

                        <h6 class="fw-bold text-uppercase text-muted border-bottom pb-2 mb-3 fs-7">3. Employment & Identification</h6>

                        <div class="row g-3 mb-4">
                            <div class="col-md-4">
                                <label for="occupation" class="form-label fw-semibold">Occupation <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="occupation" name="occupation" required value="<?php echo e($_POST['occupation'] ?? $profile['occupation'] ?? ''); ?>" placeholder="e.g. Software Engineer, Business">
                            </div>
                            <div class="col-md-4">
                                <label for="id_type" class="form-label fw-semibold">Primary ID Type <span class="text-danger">*</span></label>
                                <?php $currentIdType = $_POST['id_type'] ?? $profile['id_type'] ?? 'PAN'; ?>
                                <select class="form-select" id="id_type" name="id_type" required>
                                    <option value="PAN" <?php echo $currentIdType === 'PAN' ? 'selected' : ''; ?>>PAN Card</option>
                                    <option value="Aadhaar" <?php echo $currentIdType === 'Aadhaar' ? 'selected' : ''; ?>>Aadhaar Card</option>
                                    <option value="Passport" <?php echo $currentIdType === 'Passport' ? 'selected' : ''; ?>>Passport</option>
                                    <option value="Voter ID" <?php echo $currentIdType === 'Voter ID' ? 'selected' : ''; ?>>Voter ID</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="id_number" class="form-label fw-semibold">ID Number <span class="text-danger">*</span></label>
                                <input type="text" class="form-control text-uppercase" id="id_number" name="id_number" required value="<?php echo e($_POST['id_number'] ?? $profile['id_number'] ?? ''); ?>" placeholder="e.g. ABCDE1234F">
                            </div>
                        </div>

                        <div class="d-flex justify-content-between align-items-center border-top pt-4">
                            <a href="/digital-investor/dashboard.php" class="btn btn-outline-secondary">
                                <i class="fas fa-arrow-left me-1"></i> Back to Dashboard
                            </a>
                            <button type="submit" class="btn btn-primary btn-lg fw-bold px-4 rounded-3">
                                <i class="fas fa-save me-1"></i> Save Profile Details
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
