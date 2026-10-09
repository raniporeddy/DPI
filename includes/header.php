<?php
// includes/header.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/project_info.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth_check.php';

$currentPage = basename($_SERVER['PHP_SELF']);
$isLoggedIn = isLoggedIn();
$userRole = $_SESSION['role'] ?? null;
$userName = $_SESSION['full_name'] ?? ($_SESSION['email'] ?? 'User');

// Fetch verified investor photo for navbar display
$userKycPhoto = null;
if ($isLoggedIn && $userRole === 'investor' && isset($pdo) && !empty($_SESSION['user_id'])) {
    $stmtHdrKyc = $pdo->prepare("SELECT photo_path, status FROM kyc_verification WHERE user_id = ?");
    $stmtHdrKyc->execute([$_SESSION['user_id']]);
    $hdrKyc = $stmtHdrKyc->fetch();
    if (!empty($hdrKyc['photo_path']) && file_exists(__DIR__ . '/../' . $hdrKyc['photo_path'])) {
        $userKycPhoto = $hdrKyc['photo_path'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Digital Investor Onboarding System - Academic Prototype with SSO, eKYC, eSign and Paperless Office Modules">
    <title><?php echo isset($pageTitle) ? e($pageTitle) . ' | Digital Investor Onboarding' : 'Digital Investor Onboarding System'; ?></title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome 6 Icons -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <!-- Custom CSS -->
    <link href="/digital-investor/assets/css/style.css" rel="stylesheet">
</head>
<body>

    <!-- Header Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-dark navbar-custom sticky-top">
        <div class="container-fluid px-4">
            <a class="navbar-brand d-flex align-items-center" href="/digital-investor/index.php">
                <i class="fas fa-chart-line text-warning fs-3 me-2"></i>
                <div>
                    <span class="fs-5 fw-bold text-white d-block leading-tight">Digital Investor</span>
                    <small class="text-white-50 fs-8 fw-normal">Onboarding & Paperless Office</small>
                </div>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarMain" aria-controls="navbarMain" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            
            <div class="collapse navbar-collapse" id="navbarMain">
                <ul class="navbar-nav me-auto mb-2 mb-lg-0 ms-lg-4">
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'index.php' ? 'active fw-bold' : ''; ?>" href="/digital-investor/index.php"><i class="fas fa-home me-1"></i> Home</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'about.php' ? 'active fw-bold' : ''; ?>" href="/digital-investor/about.php"><i class="fas fa-info-circle me-1"></i> About Project</a>
                    </li>
                    <?php if ($isLoggedIn && $userRole === 'investor'): ?>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $currentPage === 'dashboard.php' ? 'active fw-bold' : ''; ?>" href="/digital-investor/dashboard.php"><i class="fas fa-tachometer-alt me-1"></i> Dashboard</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], 'investor/profile.php') !== false ? 'active fw-bold' : ''; ?>" href="/digital-investor/investor/profile.php"><i class="fas fa-user-edit me-1"></i> Profile</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], 'investor/ekyc.php') !== false ? 'active fw-bold' : ''; ?>" href="/digital-investor/investor/ekyc.php"><i class="fas fa-id-card me-1"></i> eKYC</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], 'investor/documents.php') !== false ? 'active fw-bold' : ''; ?>" href="/digital-investor/investor/documents.php"><i class="fas fa-folder-open me-1"></i> Documents</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], 'investor/esign.php') !== false ? 'active fw-bold' : ''; ?>" href="/digital-investor/investor/esign.php"><i class="fas fa-file-signature me-1"></i> eSign</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $currentPage === 'paperless.php' ? 'active fw-bold' : ''; ?>" href="/digital-investor/paperless.php"><i class="fas fa-file-contract me-1"></i> Paperless Dossier</a>
                        </li>
                    <?php elseif ($isLoggedIn && $userRole === 'admin'): ?>
                        <li class="nav-item">
                            <a class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], 'admin/dashboard.php') !== false ? 'active fw-bold' : ''; ?>" href="/digital-investor/admin/dashboard.php"><i class="fas fa-chart-pie me-1"></i> Admin Dashboard</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], 'admin/investors.php') !== false ? 'active fw-bold' : ''; ?>" href="/digital-investor/admin/investors.php"><i class="fas fa-users me-1"></i> All Investors</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], 'admin/approvals.php') !== false ? 'active fw-bold' : ''; ?>" href="/digital-investor/admin/approvals.php"><i class="fas fa-tasks me-1"></i> Approvals Queue</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], 'admin/esign_requests.php') !== false ? 'active fw-bold' : ''; ?>" href="/digital-investor/admin/esign_requests.php"><i class="fas fa-paper-plane me-1"></i> eSign Tracker</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], 'admin/paperless.php') !== false ? 'active fw-bold' : ''; ?>" href="/digital-investor/admin/paperless.php"><i class="fas fa-file-archive me-1"></i> Paperless Repository</a>
                        </li>
                    <?php endif; ?>
                </ul>

                <div class="d-flex align-items-center gap-2">
                    <?php if ($isLoggedIn): ?>
                        <div class="dropdown">
                            <button class="btn btn-outline-light dropdown-toggle d-flex align-items-center gap-2 px-3 py-1-5 rounded-pill" type="button" id="userMenu" data-bs-toggle="dropdown" aria-expanded="false">
                                <?php if ($userKycPhoto): ?>
                                    <img src="/digital-investor/<?php echo e($userKycPhoto); ?>" alt="Profile Photo" style="width: 28px; height: 28px; object-fit: cover; object-position: center;" class="rounded-circle border border-warning shadow-sm">
                                <?php else: ?>
                                    <i class="fas <?php echo $userRole === 'admin' ? 'fa-user-shield text-warning' : 'fa-user-circle'; ?>"></i>
                                <?php endif; ?>
                                <span class="fw-semibold"><?php echo e($userName); ?></span>
                                <span class="badge <?php echo $userRole === 'admin' ? 'bg-danger' : 'bg-info text-dark'; ?> ms-1"><?php echo ucfirst(e($userRole)); ?></span>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow border-0 mt-2" aria-labelledby="userMenu">
                                <li><a class="dropdown-item py-2" href="<?php echo $userRole === 'admin' ? '/digital-investor/admin/dashboard.php' : '/digital-investor/dashboard.php'; ?>"><i class="fas fa-columns me-2 text-primary"></i> Dashboard</a></li>
                                <li><a class="dropdown-item py-2" href="/digital-investor/about.php"><i class="fas fa-graduation-cap me-2 text-info"></i> Project Credits</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item py-2 text-danger" href="/digital-investor/logout.php"><i class="fas fa-sign-out-alt me-2"></i> Logout</a></li>
                            </ul>
                        </div>
                    <?php else: ?>
                        <a href="/digital-investor/sso_login.php" class="btn btn-outline-warning rounded-pill px-3 me-1">
                            <i class="fas fa-key me-1"></i> Continue with SSO
                        </a>
                        <a href="/digital-investor/login.php" class="btn btn-outline-light rounded-pill px-3">
                            <i class="fas fa-sign-in-alt me-1"></i> Login
                        </a>
                        <a href="/digital-investor/register.php" class="btn btn-warning rounded-pill px-3 fw-semibold">
                            <i class="fas fa-user-plus me-1"></i> Register
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </nav>
