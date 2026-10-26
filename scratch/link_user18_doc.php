<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$docPath = 'uploads/kyc/doc_6ad5d5553318a2.52282524.jpeg';
$photoPath = 'uploads/extracted/aadhaar_photo_user_18_1792398678_4292.png';

$stmt = $pdo->prepare("
    UPDATE kyc_verification 
    SET document_path = ?,
        photo_path = ?,
        status = 'Submitted',
        admin_remarks = NULL,
        ocr_name = 'Poreddy Rani',
        ocr_dob = '2006-05-18',
        ocr_id_number = '732062672926',
        ocr_address = '51551',
        full_name = 'poreddy rani',
        dob = '2006-05-18',
        id_number = '732062672926',
        address = '1-188,eguvapalli,anantapur'
    WHERE user_id = 18
");
$stmt->execute([$docPath, $photoPath]);

$stmtReq = $pdo->prepare("UPDATE ekyc_requests SET status = 'Submitted', submitted_at = NOW() WHERE user_id = 18 AND status = 'Sent'");
$stmtReq->execute();

echo "User 18 KYC updated with doc and photo successfully!\n";
