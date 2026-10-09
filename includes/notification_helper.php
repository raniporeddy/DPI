<?php
// includes/notification_helper.php
// Admin Notification System Helper

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Create a new notification for Admin
 */
function createAdminNotification($pdo, $userId, $type, $message) {
    try {
        // Fetch application ID if available
        $stmtApp = $pdo->prepare("SELECT application_id FROM applications WHERE user_id = ?");
        $stmtApp->execute([$userId]);
        $appId = $stmtApp->fetchColumn() ?: null;

        $stmt = $pdo->prepare("INSERT INTO notifications (user_id, application_id, type, message, is_read) VALUES (?, ?, ?, ?, 0)");
        $stmt->execute([$userId, $appId, $type, $message]);
        return true;
    } catch (Exception $e) {
        error_log("Notification Creation Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Count unread notifications for Admin
 */
function getUnreadNotificationCount($pdo) {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM notifications WHERE is_read = 0");
        return (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * Fetch latest notifications list
 */
function getAdminNotifications($pdo, $limit = 20) {
    try {
        $stmt = $pdo->prepare("
            SELECT n.*, u.email, p.full_name
            FROM notifications n
            JOIN users u ON n.user_id = u.id
            LEFT JOIN investor_profiles p ON u.id = p.user_id
            ORDER BY n.created_at DESC
            LIMIT ?
        ");
        $stmt->bindValue(1, (int)$limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Fetch Notifications Error: " . $e->getMessage());
        return [];
    }
}

/**
 * Mark notification as read
 */
function markNotificationAsRead($pdo, $notificationId) {
    try {
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ?");
        $stmt->execute([(int)$notificationId]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}
