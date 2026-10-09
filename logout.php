<?php
// logout.php
// Logout session handler

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$_SESSION = array();

if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

session_destroy();

session_start();
$_SESSION['flash_msg'] = [
    'type' => 'info',
    'message' => 'You have been logged out successfully.'
];

header("Location: /digital-investor/index.php");
exit;
