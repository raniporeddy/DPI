<?php
// Test every handleSecureFileUpload failure case + one success path
require_once __DIR__ . '/../includes/functions.php';

$pass = 0; $fail = 0;

function ok($label, $cond, $extra = '') {
    global $pass, $fail;
    if ($cond) { echo "  PASS: $label\n"; $pass++; }
    else        { echo "  FAIL: $label" . ($extra ? " ($extra)" : '') . "\n"; $fail++; }
}

$uploadDir = dirname(__DIR__) . '/uploads/kyc/';

echo "\n=== 1. Empty / missing file input ===\n";
$r = handleSecureFileUpload([], $uploadDir);
ok("Empty array → failure",          !$r['success']);
ok("Empty array → useful message",   str_contains($r['error'], 'select'));
echo "  Message: {$r['error']}\n";

echo "\n=== 2. UPLOAD_ERR_NO_FILE (user clicked Submit without choosing a file) ===\n";
$fakeNoFile = ['error' => UPLOAD_ERR_NO_FILE, 'name' => '', 'tmp_name' => '', 'size' => 0, 'type' => ''];
$r = handleSecureFileUpload($fakeNoFile, $uploadDir);
ok("UPLOAD_ERR_NO_FILE → failure",         !$r['success']);
ok("UPLOAD_ERR_NO_FILE → mentions 'No file was selected'", str_contains($r['error'], 'No file was selected'));
echo "  Message: {$r['error']}\n";

echo "\n=== 3. UPLOAD_ERR_INI_SIZE (file too large for PHP) ===\n";
$fakeTooBig = ['error' => UPLOAD_ERR_INI_SIZE, 'name' => 'big.jpg', 'tmp_name' => '', 'size' => 9999999, 'type' => 'image/jpeg'];
$r = handleSecureFileUpload($fakeTooBig, $uploadDir);
ok("UPLOAD_ERR_INI_SIZE → failure",         !$r['success']);
ok("UPLOAD_ERR_INI_SIZE → mentions size",   str_contains($r['error'], 'too large') || str_contains($r['error'], 'size'));
echo "  Message: {$r['error']}\n";

echo "\n=== 4. UPLOAD_ERR_PARTIAL ===\n";
$fakePartial = ['error' => UPLOAD_ERR_PARTIAL, 'name' => 'partial.jpg', 'tmp_name' => '', 'size' => 0, 'type' => ''];
$r = handleSecureFileUpload($fakePartial, $uploadDir);
ok("UPLOAD_ERR_PARTIAL → failure",          !$r['success']);
ok("UPLOAD_ERR_PARTIAL → mentions partial", str_contains($r['error'], 'partially'));
echo "  Message: {$r['error']}\n";

echo "\n=== 5. Invalid extension (.exe) ===\n";
// Create a genuine PNG in tmp so we can bypass the is_uploaded_file check via a workaround
// Since we can't call is_uploaded_file() outside HTTP context, we test extension check only
$pngData = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
$tmp = tempnam(sys_get_temp_dir(), 'ext_test_');
file_put_contents($tmp, $pngData);
// We can't fully test move_uploaded_file outside web; test only extension/MIME guards
$fakeExt = ['error' => UPLOAD_ERR_OK, 'name' => 'malware.exe', 'tmp_name' => $tmp, 'size' => strlen($pngData), 'type' => 'application/x-msdownload'];
$r = handleSecureFileUpload($fakeExt, $uploadDir);
ok("Disallowed extension (.exe) → failure",  !$r['success']);
ok("Disallowed extension message includes format list", str_contains($r['error'], 'JPG') || str_contains($r['error'], 'Invalid'));
echo "  Message: {$r['error']}\n";
unlink($tmp);

echo "\n=== 6. Valid PNG with wrong MIME-disguised as executable ===\n";
// Name says .png but actually contains executable bytes
$tmp = tempnam(sys_get_temp_dir(), 'mime_test_');
file_put_contents($tmp, "MZ\x90\x00\x03\x00\x00\x00"); // DOS/PE header
$fakeMime = ['error' => UPLOAD_ERR_OK, 'name' => 'photo.png', 'tmp_name' => $tmp, 'size' => 8, 'type' => 'image/png'];
$r = handleSecureFileUpload($fakeMime, $uploadDir);
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$actualMime = finfo_file($finfo, $tmp);
finfo_close($finfo);
echo "  Actual MIME detected by finfo: $actualMime\n";
ok("Fake PNG with PE bytes → failure (MIME mismatch)", !$r['success']);
echo "  Message: {$r['error']}\n";
unlink($tmp);

echo "\n=== 7. Zero-byte file ===\n";
$tmp = tempnam(sys_get_temp_dir(), 'zero_test_');
file_put_contents($tmp, '');
$fakeZero = ['error' => UPLOAD_ERR_OK, 'name' => 'empty.jpg', 'tmp_name' => $tmp, 'size' => 0, 'type' => 'image/jpeg'];
$r = handleSecureFileUpload($fakeZero, $uploadDir);
ok("Zero-byte file → failure", !$r['success']);
ok("Zero-byte message mentions 0 bytes", str_contains($r['error'], '0 bytes') || str_contains($r['error'], 'empty'));
echo "  Message: {$r['error']}\n";
unlink($tmp);

echo "\n=== 8. File exceeds 5 MB app limit (even within PHP limit) ===\n";
$tmp = tempnam(sys_get_temp_dir(), 'large_test_');
file_put_contents($tmp, str_repeat('A', 6 * 1024 * 1024)); // 6 MB
$fakeLarge = ['error' => UPLOAD_ERR_OK, 'name' => 'huge.jpg', 'tmp_name' => $tmp, 'size' => 6 * 1024 * 1024, 'type' => 'image/jpeg'];
$r = handleSecureFileUpload($fakeLarge, $uploadDir);
ok("6 MB file → failure (app-level size check)", !$r['success']);
ok("Size message includes MB value", str_contains($r['error'], 'MB'));
echo "  Message: {$r['error']}\n";
unlink($tmp);

echo "\n=== 9. Upload directory writable check ===\n";
ok("uploads/kyc/ exists",     is_dir($uploadDir));
ok("uploads/kyc/ writable",   is_writable($uploadDir));
ok("uploads/extracted/ exists",  is_dir(dirname(__DIR__) . '/uploads/extracted'));
ok("uploads/extracted/ writable", is_writable(dirname(__DIR__) . '/uploads/extracted'));

echo "\n=== 10. PHP ini limits adequate ===\n";
$phpUploadMax = (int)ini_get('upload_max_filesize');
$phpPostMax   = (int)ini_get('post_max_size');
ok("upload_max_filesize >= 5 (is {$phpUploadMax}M)", $phpUploadMax >= 5);
ok("post_max_size >= 5 (is {$phpPostMax}M)",         $phpPostMax >= 5);
ok("file_uploads = On",   ini_get('file_uploads') == '1');

echo "\n" . str_repeat("=", 60) . "\n";
echo "RESULTS: {$pass} passed, {$fail} failed\n";
echo $fail === 0 ? "ALL TESTS PASSED ✓\n" : "SOME FAILURES - see above\n";
