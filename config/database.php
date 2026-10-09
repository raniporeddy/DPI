<?php
// config/database.php
// PDO Database Connection for Digital Investor Onboarding System

define('DB_HOST', 'localhost');
define('DB_NAME', 'digital_investor');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

function getDBConnection() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // Friendly error message without leaking sensitive server credentials
            error_log("Database Connection Error: " . $e->getMessage());
            die("<div style='padding:20px; font-family:sans-serif; background:#f8d7da; color:#721c24; border-radius:5px; margin:30px;'>
                    <h3>Database Connection Error</h3>
                    <p>Unable to connect to the database. Please ensure XAMPP MySQL service is running and the database '<strong>" . DB_NAME . "</strong>' has been imported.</p>
                 </div>");
        }
    }
    return $pdo;
}

// Global PDO instance helper
$pdo = getDBConnection();
