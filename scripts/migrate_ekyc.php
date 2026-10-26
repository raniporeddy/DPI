<?php
// scripts/migrate_ekyc.php
// Database migration runner for eKYC workflow updates

require_once __DIR__ . '/../config/database.php';

echo "Running eKYC Database Migration...\n";

try {
    // 1. Create table if not exists
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `ekyc_requests` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT NOT NULL,
            `recipient_email` VARCHAR(150) NOT NULL,
            `token` VARCHAR(100) NOT NULL UNIQUE,
            `status` ENUM('Pending', 'Sent', 'Submitted', 'Verified', 'Rejected', 'Expired') DEFAULT 'Pending',
            `expires_at` DATETIME NULL,
            `submitted_at` DATETIME DEFAULT NULL,
            `ip_address` VARCHAR(50) DEFAULT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_user_id` (`user_id`),
            INDEX `idx_token` (`token`),
            INDEX `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "  [OK] ekyc_requests table confirmed.\n";

    // 2. Check if expires_at column exists
    $cols = $pdo->query("SHOW COLUMNS FROM `ekyc_requests` LIKE 'expires_at'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE `ekyc_requests` ADD COLUMN `expires_at` DATETIME NULL AFTER `status`");
        echo "  [OK] Added `expires_at` column to ekyc_requests.\n";
    } else {
        echo "  [OK] `expires_at` column already present.\n";
    }

    // 3. Backfill expires_at for any records where it is NULL
    $pdo->exec("UPDATE `ekyc_requests` SET `expires_at` = DATE_ADD(`created_at`, INTERVAL 24 HOUR) WHERE `expires_at` IS NULL");
    echo "  [OK] Backfilled expires_at on existing records.\n";

    // 4. Verify foreign key if not present
    try {
        $pdo->exec("ALTER TABLE `ekyc_requests` ADD CONSTRAINT `fk_ekyc_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE");
        echo "  [OK] Foreign key constraint fk_ekyc_user added.\n";
    } catch (Exception $e) {
        // Foreign key already exists or duplicate
        echo "  [INFO] Foreign key check: " . $e->getMessage() . "\n";
    }

    echo "Migration completed successfully!\n";

} catch (Exception $e) {
    echo "Migration Failed: " . $e->getMessage() . "\n";
    exit(1);
}
