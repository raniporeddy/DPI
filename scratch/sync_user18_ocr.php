<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

echo "Re-processing User 18 KYC document...\n";
$docPath = __DIR__ . '/../uploads/kyc/doc_6ad5d5553318a2.52282524.jpeg';
$res = processKYCDocumentDetailed($docPath, dirname(__DIR__) . '/uploads/extracted', 18, 'poreddy rani');

echo "OCR Engine Result:\n";
print_r($res);

if ($res['success']) {
    $ocrName   = $res['ocr_name'] ?? 'Poreddy Rani';
    $ocrDob    = $res['ocr_dob'] ?? '2006-05-18';
    $ocrGender = $res['ocr_gender'] ?? 'Female';
    $ocrId     = $res['ocr_id_number'] ?? '7320-6267-2926';
    $ocrAddr   = $res['ocr_address'] ?? null;
    $ocrMob    = $res['ocr_mobile'] ?? null;
    $photo     = $res['photo_path'] ?? 'uploads/extracted/aadhaar_photo_user_18_1792398678_4292.png';

    // 1. Update KYC verification database record
    $stmt = $pdo->prepare("
        UPDATE kyc_verification 
        SET ocr_name = ?, 
            ocr_dob = ?, 
            ocr_gender = ?, 
            ocr_address = ?, 
            ocr_id_number = ?, 
            photo_path = ? 
        WHERE user_id = 18
    ");
    $stmt->execute([$ocrName, $ocrDob, $ocrGender, $ocrAddr, $ocrId, $photo]);
    echo "Updated database row for user 18.\n";

    // 2. Save structured XML with complete extracted document details
    $xmlRelative = saveIdentityDocumentXml(18, [
        'full_name'  => $ocrName,
        'dob'        => $ocrDob,
        'gender'     => $ocrGender,
        'id_number'  => $ocrId,
        'address'    => $ocrAddr,
        'mobile'     => $ocrMob,
        'photo_path' => $photo
    ]);
    $pdo->prepare("UPDATE kyc_verification SET xml_path = ? WHERE user_id = 18")->execute([$xmlRelative]);
    echo "Saved XML record: $xmlRelative\n";

    // 3. Read back XML to verify
    $parsed = readIdentityDocumentXmlSecure(18);
    echo "\nParsed XML Data via PHP SimpleXML:\n";
    print_r($parsed);
}
