<?php
require_once __DIR__ . '/../config/database.php';
$stmt = $pdo->prepare("
    UPDATE kyc_verification 
    SET ocr_name = ?, ocr_dob = ?, ocr_gender = ?, ocr_id_number = ?, ocr_address = ?, photo_path = ?, ocr_status = 'Processed'
    WHERE user_id = 18
");
$stmt->execute([
    'Vankayala Manasa',
    '2005-11-26',
    'Female',
    '5283-5946-1616',
    'OIL MILL ROAD, Sub District: Addanki, District: Prakasam, State: Andhra Pradesh - 523201',
    'uploads/extracted/aadhaar_photo_user_18_1791815428_7631.png'
]);
echo "User 18 KYC updated successfully!\n";
