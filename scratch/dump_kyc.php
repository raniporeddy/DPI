<?php
require_once __DIR__ . '/../config/database.php';
$stmt = $pdo->query("SELECT id, user_id, full_name, dob, id_number, ocr_name, ocr_dob, ocr_gender, ocr_id_number, ocr_address, document_path, photo_path, status FROM kyc_verification");
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_PRETTY_PRINT);
