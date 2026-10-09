<?php
// investor/submit_application.php
// Submit Completed Application Handler

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
verifyCSRFPost();

$userId = $_SESSION['user_id'];

// Refresh application status
refreshApplicationStatus($pdo, $userId);

$app = getOrInitApplication($pdo, $userId);

// Check if all 4 modules are completed
if (!$app['profile_completed'] || !$app['kyc_completed'] || !$app['docs_completed'] || !$app['esign_completed']) {
    setFlash('danger', 'Cannot submit application. Please complete all 4 onboarding modules first.');
    header("Location: /digital-investor/dashboard.php");
    exit;
}

try {
    $stmtUpd = $pdo->prepare("UPDATE applications SET status = 'Submitted', submitted_at = NOW() WHERE user_id = ?");
    $stmtUpd->execute([$userId]);

    // Create Admin Notification
    createAdminNotification($pdo, $userId, 'app_submission', "Investor submitted final onboarding application for review (Application ID: " . $app['application_id'] . ")");

    setFlash('success', 'Your onboarding application (ID: ' . $app['application_id'] . ') has been SUBMITTED for Admin review!');
} catch (Exception $e) {
    error_log("Application Submission Error: " . $e->getMessage());
    setFlash('danger', 'An error occurred while submitting your application.');
}

header("Location: /digital-investor/dashboard.php");
exit;
