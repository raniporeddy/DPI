<?php
// includes/auth_check.php
// Session & Access Control Helper

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Check if user is logged in
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Require active user login session
 */
function requireLogin() {
    if (!isLoggedIn()) {
        if (isset($_SESSION['flash_msg']) == false) {
            $_SESSION['flash_msg'] = [
                'type' => 'warning',
                'message' => 'Please login to access this area.'
            ];
        }
        header("Location: /digital-investor/login.php");
        exit;
    }
}

/**
 * Require specific user role ('investor' or 'admin')
 */
function requireRole($allowedRole) {
    requireLogin();
    if ($_SESSION['role'] !== $allowedRole) {
        $_SESSION['flash_msg'] = [
            'type' => 'danger',
            'message' => 'Unauthorized access attempt. Access denied.'
        ];
        if ($_SESSION['role'] === 'admin') {
            header("Location: /digital-investor/admin/dashboard.php");
        } else {
            header("Location: /digital-investor/dashboard.php");
        }
        exit;
    }
}

/**
 * Require Admin privilege
 */
function requireAdmin() {
    requireRole('admin');
}

/**
 * Get logged-in user object from DB
 */
function getCurrentUser($pdo) {
    if (!isLoggedIn()) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT id, email, role, sso_provider, created_at FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch();
}
