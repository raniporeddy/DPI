<?php
// includes/sso_helper.php
// Single Sign-On (SSO) Prototype & Helper Module

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/notification_helper.php';

/**
 * Class SSOService Prototype
 * Handles simulated single sign-on authentication flow and provides hooks for future OAuth2/OpenID Connect integration.
 */
class SSOService {
    
    /**
     * Get available demo SSO identity providers
     */
    public static function getProviders() {
        return [
            'investor_sso' => [
                'name' => 'InvestorAuth SSO (Demo IDP)',
                'icon' => 'fa-id-badge',
                'bg_color' => '#0d6efd',
                'description' => 'Academic single sign-on identity provider. No password creation required.'
            ],
            'google' => [
                'name' => 'Simulated Google Identity',
                'icon' => 'fa-google',
                'bg_color' => '#ea4335',
                'description' => 'Simulated OAuth2 login workflow. No password creation required.'
            ]
        ];
    }

    /**
     * Process simulated SSO authentication response
     */
    public static function handleSSOCallback($pdo, $providerKey, $ssoUserEmail, $ssoUserName = 'SSO Demo User') {
        $providers = self::getProviders();
        $providerName = $providers[$providerKey]['name'] ?? 'SSO Provider';

        // Check if user already exists with this email
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$ssoUserEmail]);
        $user = $stmt->fetch();

        if (!$user) {
            // Create user registered via SSO (No separate password required from user)
            $ssoId = 'sso_' . strtolower($providerKey) . '_' . substr(md5(uniqid()), 0, 8);
            $randomPassword = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);

            $stmtIns = $pdo->prepare("INSERT INTO users (email, password, role, sso_provider, sso_id) VALUES (?, ?, 'investor', ?, ?)");
            $stmtIns->execute([$ssoUserEmail, $randomPassword, $providerName, $ssoId]);
            $userId = $pdo->lastInsertId();

            // Create initial investor profile
            $stmtProf = $pdo->prepare("INSERT INTO investor_profiles (user_id, full_name, email, created_at) VALUES (?, ?, ?, NOW())");
            $stmtProf->execute([$userId, $ssoUserName, $ssoUserEmail]);

            // Initialize application record
            getOrInitApplication($pdo, $userId);

            // Create Admin Notification
            createAdminNotification($pdo, $userId, 'sso_registration', "New investor registered via SSO (" . $providerName . "): " . $ssoUserName . " (" . $ssoUserEmail . ")");

            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
        } else {
            // Update SSO provider if empty
            if (empty($user['sso_provider'])) {
                $stmtUpd = $pdo->prepare("UPDATE users SET sso_provider = ? WHERE id = ?");
                $stmtUpd->execute([$providerName, $user['id']]);
            }
        }

        // Initialize User Session
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['sso_login'] = true;

        // Fetch investor name for greeting
        $stmtName = $pdo->prepare("SELECT full_name FROM investor_profiles WHERE user_id = ?");
        $stmtName->execute([$user['id']]);
        $profName = $stmtName->fetchColumn();
        $_SESSION['full_name'] = $profName ?: $user['email'];

        return true;
    }
}
