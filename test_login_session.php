<?php
// test_login_session.php - Helper to set active session for browser testing
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/database.php';

$stmt = $pdo->query("SELECT * FROM users WHERE role = 'investor' LIMIT 1");
$user = $stmt->fetch();

if ($user) {
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['role'] = $user['role'];

    $stmtProf = $pdo->prepare("SELECT full_name FROM investor_profiles WHERE user_id = ?");
    $stmtProf->execute([$user['id']]);
    $profName = $stmtProf->fetchColumn();
    $_SESSION['full_name'] = $profName ?: $user['email'];

    echo "<h3>Investor Session Active!</h3>";
    echo "<p>Logged in as: <strong>" . htmlspecialchars($_SESSION['full_name']) . "</strong> (" . htmlspecialchars($_SESSION['email']) . ")</p>";
    echo "<p><a href='/digital-investor/investor/esign.php' style='font-size:18px; font-weight:bold;'>Click Here to Open Updated Email eSign Page</a></p>";
} else {
    echo "No investor user found.";
}
