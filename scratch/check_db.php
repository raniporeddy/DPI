<?php
require_once __DIR__ . '/../config/database.php';
try {
    $pdo->exec("ALTER TABLE kyc_verification ADD COLUMN IF NOT EXISTS ocr_gender VARCHAR(20) DEFAULT NULL AFTER ocr_dob");
    echo "ocr_gender column ensured in kyc_verification.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
$stmt = $pdo->query("DESCRIBE kyc_verification");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo $row['Field'] . " (" . $row['Type'] . ")\n";
}
