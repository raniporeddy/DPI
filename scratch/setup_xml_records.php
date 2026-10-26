<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

// 1. Add xml_path column to kyc_verification if not exists
try {
    $cols = $pdo->query("SHOW COLUMNS FROM kyc_verification LIKE 'xml_path'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE kyc_verification ADD COLUMN xml_path VARCHAR(255) DEFAULT NULL AFTER photo_path");
        echo "Column xml_path added to kyc_verification.\n";
    } else {
        echo "Column xml_path already exists.\n";
    }
} catch (Exception $e) {
    echo "DB Schema note: " . $e->getMessage() . "\n";
}

// 2. Generate XML files for existing KYC records that have OCR data
$stmt = $pdo->query("SELECT user_id, ocr_name, ocr_dob, ocr_id_number, ocr_address, photo_path, document_path FROM kyc_verification");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as $r) {
    $uid = (int)$r['user_id'];
    if (!empty($r['ocr_name']) || !empty($r['photo_path']) || !empty($r['document_path'])) {
        $xmlRelative = saveIdentityDocumentXml($uid, [
            'full_name'  => $r['ocr_name'],
            'dob'        => $r['ocr_dob'],
            'id_number'  => $r['ocr_id_number'],
            'address'    => $r['ocr_address'],
            'photo_path' => $r['photo_path']
        ]);

        $pdo->prepare("UPDATE kyc_verification SET xml_path = ? WHERE user_id = ?")->execute([$xmlRelative, $uid]);
        echo "Saved XML for user $uid -> $xmlRelative\n";
    }
}

// 3. Verify user 18 XML content
echo "\n--- USER 18 XML CONTENT ---\n";
$user18Xml = readIdentityDocumentXmlSecure(18);
print_r($user18Xml);
