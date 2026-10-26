<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$docPath = __DIR__ . '/../uploads/kyc/doc_6acc61eadbadf3.44620607.jpeg';
$res = processKYCDocumentDetailed($docPath, __DIR__ . '/../uploads/extracted', 18, 'poreddy rani');

echo "Result:\n";
echo json_encode($res, JSON_PRETTY_PRINT) . "\n";
