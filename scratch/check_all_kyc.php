<?php
require 'config/database.php';
$stmt = $pdo->query("SELECT user_id, ocr_name, ocr_dob, ocr_id_number, ocr_address, photo_path, document_path, status FROM kyc_verification");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    print_r($row);
}
