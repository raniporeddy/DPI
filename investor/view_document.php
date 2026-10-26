<?php
// investor/view_document.php
// Secure Document Viewer with Ownership Authorization & Safe Streaming

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth_check.php';

requireLogin();

$currentUserId = (int)$_SESSION['user_id'];
$currentUserRole = $_SESSION['role'] ?? '';

$docId = (int)($_GET['id'] ?? 0);
$kycUserId = (int)($_GET['kyc_user_id'] ?? 0);

$docFilePath = '';
$docOwnerId = 0;

if ($kycUserId > 0) {
    // KYC document request by user_id
    $stmt = $pdo->prepare("SELECT user_id, document_path FROM kyc_verification WHERE user_id = ?");
    $stmt->execute([$kycUserId]);
    $kycRow = $stmt->fetch();
    if (!$kycRow || empty($kycRow['document_path'])) {
        http_response_code(404);
        die("KYC Document not found.");
    }
    $docFilePath = $kycRow['document_path'];
    $docOwnerId = (int)$kycRow['user_id'];
} elseif ($docId > 0) {
    // Document by documents table ID
    $stmt = $pdo->prepare("SELECT user_id, file_path FROM documents WHERE id = ?");
    $stmt->execute([$docId]);
    $doc = $stmt->fetch();
    if (!$doc || empty($doc['file_path'])) {
        http_response_code(404);
        die("Document not found.");
    }
    $docFilePath = $doc['file_path'];
    $docOwnerId = (int)$doc['user_id'];
} else {
    http_response_code(400);
    die("Invalid Document ID or User ID.");
}

// Authorization: Only the document owner or an admin can access this document
if ($currentUserRole !== 'admin' && $docOwnerId !== $currentUserId) {
    http_response_code(403);
    die("Access Denied: You do not have permission to view documents belonging to another user.");
}

$filePath = dirname(__DIR__) . '/' . ltrim(str_replace('\\', '/', $docFilePath), '/');
$realFilePath = realpath($filePath);
$baseUploadDir = realpath(dirname(__DIR__) . '/uploads');

// Security check: Verify file exists and is strictly within uploads directory
if (!$realFilePath || !file_exists($realFilePath) || strpos($realFilePath, $baseUploadDir) !== 0) {
    http_response_code(404);
    die("File not found on server.");
}

$fileExt = strtolower(pathinfo($realFilePath, PATHINFO_EXTENSION));
$mimeMap = [
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'pdf'  => 'application/pdf',
];
$mimeType = $mimeMap[$fileExt] ?? 'application/octet-stream';

header('Content-Type: ' . $mimeType);
header('Content-Disposition: inline; filename="' . basename($docFilePath) . '"');
header('Content-Length: ' . filesize($realFilePath));
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');

readfile($realFilePath);
exit;
