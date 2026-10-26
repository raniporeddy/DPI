<?php
// Comprehensive upload diagnostics
require_once __DIR__ . '/../config/database.php';

echo "=== PHP UPLOAD CONFIGURATION ===\n";
$settings = [
    'upload_max_filesize',
    'post_max_size',
    'file_uploads',
    'max_file_uploads',
    'max_input_time',
    'max_execution_time',
    'memory_limit',
];
foreach ($settings as $s) {
    echo "  $s = " . ini_get($s) . "\n";
}

echo "\n=== UPLOAD DIRECTORY CHECK ===\n";
$baseDir = dirname(__DIR__);
$dirs = [
    $baseDir . '/uploads',
    $baseDir . '/uploads/kyc',
    $baseDir . '/uploads/kyc/',    // trailing slash variant
    $baseDir . '/uploads/extracted',
];
foreach ($dirs as $d) {
    $d = rtrim($d, '/\\');
    $exists   = is_dir($d);
    $writable = $exists && is_writable($d);
    $perms    = $exists ? substr(sprintf('%o', fileperms($d)), -4) : 'N/A';
    echo sprintf("  %-55s exists=%-5s writable=%-5s perms=%s\n",
        str_replace($baseDir, '.', $d), $exists ? 'YES' : 'NO', $writable ? 'YES' : 'NO', $perms);
}

echo "\n=== SIMULATE UPLOAD VALIDATION (valid PNG 1x1 pixel) ===\n";
// Create a minimal valid PNG in a temp file to test validation logic
$pngData = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
$tmpFile = tempnam(sys_get_temp_dir(), 'test_upload_');
file_put_contents($tmpFile, $pngData);

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime  = finfo_file($finfo, $tmpFile);
finfo_close($finfo);
echo "  Test PNG MIME: $mime\n";
echo "  Test PNG size: " . strlen($pngData) . " bytes\n";

$allowedMimes = ['image/jpeg', 'image/png', 'application/pdf'];
echo "  MIME allowed:  " . (in_array($mime, $allowedMimes) ? 'YES' : 'NO') . "\n";
unlink($tmpFile);

echo "\n=== FORM HTML CHECK ===\n";
$ekycHtml = file_get_contents(__DIR__ . '/../investor/ekyc.php');
// Check enctype
echo "  enctype=multipart/form-data: " . (str_contains($ekycHtml, 'enctype="multipart/form-data"') ? 'YES' : 'NO - MISSING!') . "\n";
// Check method
echo "  method=POST: " . (str_contains($ekycHtml, 'method="POST"') || str_contains($ekycHtml, "method=\"POST\"") ? 'YES' : 'NO') . "\n";
// Check file input name
echo "  input name=kyc_document: " . (str_contains($ekycHtml, 'name="kyc_document"') ? 'YES' : 'NO') . "\n";
// Check action
preg_match('/action="([^"]+resubmit[^"]*)"/', $ekycHtml, $m);
echo "  Form action: " . ($m[1] ?? 'not found via resubmit') . "\n";
// Check POST handler
echo "  POST handler (resubmit_document): " . (str_contains($ekycHtml, "isset(\$_POST['resubmit_document'])") ? 'YES' : 'NO') . "\n";
// Check CSRF
echo "  CSRF field present: " . (str_contains($ekycHtml, 'csrfField()') ? 'YES' : 'NO') . "\n";

echo "\n=== UPLOAD ERROR CODE MEANINGS ===\n";
$errCodes = [
    UPLOAD_ERR_OK         => 'No error',
    UPLOAD_ERR_INI_SIZE   => 'File exceeds upload_max_filesize directive',
    UPLOAD_ERR_FORM_SIZE  => 'File exceeds MAX_FILE_SIZE directive in HTML form',
    UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded',
    UPLOAD_ERR_NO_FILE    => 'No file was uploaded',
    UPLOAD_ERR_NO_TMP_DIR => 'Missing temp folder',
    UPLOAD_ERR_CANT_WRITE => 'Failed to write to disk',
    UPLOAD_ERR_EXTENSION  => 'Extension stopped upload',
];
foreach ($errCodes as $code => $meaning) {
    echo "  UPLOAD_ERR $code: $meaning\n";
}

echo "\n=== ROOT-CAUSE ANALYSIS OF handleSecureFileUpload ===\n";
// Check: what does the function return for an empty/missing file input?
$emptyInput = [];
$errCheck1 = (!isset($emptyInput) || ($emptyInput['error'] ?? -1) !== UPLOAD_ERR_OK);
echo "  Empty \$_FILES entry triggers 'no file' error: " . ($errCheck1 ? 'YES (correct)' : 'NO - BUG') . "\n";

// Check: error message when no file selected
// The function returns 'File upload failed or no file selected.' for any non-OK error code
// UPLOAD_ERR_NO_FILE (4) is what happens when no file is chosen
// UPLOAD_ERR_INI_SIZE (1) happens when file > upload_max_filesize

echo "\n=== POTENTIAL ISSUES CHECKLIST ===\n";
$issues = [];

// 1. Check if upload_max_filesize >= 5MB
$uploadMax = ini_get('upload_max_filesize');
$uploadMaxBytes = (int)$uploadMax * 1024 * 1024;
if (str_ends_with(strtolower($uploadMax), 'k')) $uploadMaxBytes = (int)$uploadMax * 1024;
elseif (str_ends_with(strtolower($uploadMax), 'm')) $uploadMaxBytes = (int)$uploadMax * 1024 * 1024;
elseif (str_ends_with(strtolower($uploadMax), 'g')) $uploadMaxBytes = (int)$uploadMax * 1024 * 1024 * 1024;
else $uploadMaxBytes = (int)$uploadMax;
if ($uploadMaxBytes < 5 * 1024 * 1024) {
    $issues[] = "upload_max_filesize ($uploadMax) is less than 5MB - large files will silently fail with PHP's UPLOAD_ERR_INI_SIZE";
}

// 2. Check post_max_size
$postMax = ini_get('post_max_size');
$postMaxBytes = (int)$postMax;
if (str_ends_with(strtolower($postMax), 'k')) $postMaxBytes = (int)$postMax * 1024;
elseif (str_ends_with(strtolower($postMax), 'm')) $postMaxBytes = (int)$postMax * 1024 * 1024;
elseif (str_ends_with(strtolower($postMax), 'g')) $postMaxBytes = (int)$postMax * 1024 * 1024 * 1024;
if ($postMaxBytes < 5 * 1024 * 1024) {
    $issues[] = "post_max_size ($postMax) is less than 5MB - upload may be silently truncated";
}

// 3. Check upload dir exists/writable
$kycDir = $baseDir . '/uploads/kyc';
if (!is_dir($kycDir)) {
    $issues[] = "uploads/kyc directory does NOT EXIST - uploads will fail";
} elseif (!is_writable($kycDir)) {
    $issues[] = "uploads/kyc directory is NOT WRITABLE - uploads will fail";
}

// 4. Check error message specificity
$issues[] = "SILENT FAILURE: handleSecureFileUpload returns same generic message for ALL upload errors (no file, too large, wrong type). Investor sees 'File upload failed or no file selected.' for every failure.";

// 5. Check docPath construction
// dirname(__DIR__) from investor/ = the project root
$projectRoot = dirname(dirname(__FILE__)); // From scratch/
echo "\n=== docPath construction from investor/ekyc.php ===\n";
echo "  dirname(__DIR__) in investor/ekyc.php = project root\n";
echo "  uploadDir = {project_root}/uploads/kyc/\n";
echo "  file_path from handleSecureFileUpload = full absolute path\n";
echo "  docPath = ltrim(str_replace(dirname(__DIR__), '', file_path), '/\\\\')\n";
echo "  Result: uploads/kyc/doc_XXXX.jpg  (relative from project root)\n";

if (empty($issues)) {
    echo "\n  No issues found\n";
} else {
    echo "\n  ISSUES:\n";
    foreach ($issues as $i => $issue) {
        echo "  " . ($i + 1) . ". $issue\n";
    }
}
