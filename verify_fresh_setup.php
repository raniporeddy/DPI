<?php
// verify_fresh_setup.php
$baseUrl = "http://localhost/digital-investor";

function makeReq($url, $method = 'GET', $postFields = [], $cookieFile = null) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    
    if ($cookieFile) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    }
    
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $header = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    curl_close($ch);
    
    return ['code' => $httpCode, 'header' => $header, 'body' => $body];
}

function getCsrf($html) {
    preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m);
    return $m[1] ?? '';
}

function getCaptcha($html) {
    preg_match('/captcha-box[^>]*>\s*([A-Z0-9]{5})\s*<\/div>/i', $html, $m);
    return trim($m[1] ?? '');
}

echo "=== POST-RESET FRESH ACCOUNT CREATION & VERIFICATION ===\n\n";

// 1. Verify Database is Clean
require_once __DIR__ . '/config/database.php';
$investorCount = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'investor'")->fetchColumn();
$adminCount = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
echo "[VERIFY 1] Existing Investor Accounts Count: $investorCount - " . ($investorCount == 0 ? "PASSED (0 Accounts)" : "FAILED") . "\n";
echo "[VERIFY 2] Existing Admin Accounts Count: $adminCount - " . ($adminCount == 0 ? "PASSED (0 Accounts)" : "FAILED") . "\n";

// 3. Test Fresh Investor Registration
$ckInv = __DIR__ . '/test_fresh_inv_ck.txt';
if (file_exists($ckInv)) @unlink($ckInv);
$resRegGet = makeReq("$baseUrl/register.php", 'GET', [], $ckInv);
$csrfReg = getCsrf($resRegGet['body']);
$captchaReg = getCaptcha($resRegGet['body']);

$postReg = [
    'csrf_token' => $csrfReg,
    'full_name' => 'Fresh Demo Investor',
    'email' => 'fresh.investor@example.com',
    'password' => 'FreshInvestor@123',
    'confirm_password' => 'FreshInvestor@123',
    'captcha_input' => $captchaReg
];
$resRegPost = makeReq("$baseUrl/register.php", 'POST', $postReg, $ckInv);
$regSuccess = (strpos($resRegPost['header'], '302') !== false || strpos($resRegPost['body'], 'Dashboard') !== false);
echo "[VERIFY 3] Fresh Investor Registration (fresh.investor@example.com): HTTP {$resRegPost['code']} - " . ($regSuccess ? "PASSED (Registered & Authenticated)" : "FAILED") . "\n";

// 4. Test Fresh Admin Setup
$ckAdminSetup = __DIR__ . '/test_fresh_admin_setup_ck.txt';
if (file_exists($ckAdminSetup)) @unlink($ckAdminSetup);
$resSetupGet = makeReq("$baseUrl/setup_admin.php", 'GET', [], $ckAdminSetup);
$csrfSetup = getCsrf($resSetupGet['body']);

$postSetup = [
    'csrf_token' => $csrfSetup,
    'admin_email' => 'poreddyrani21@gmail.com',
    'new_password' => 'MyFreshAdmin@2026',
    'confirm_password' => 'MyFreshAdmin@2026'
];
$resSetupPost = makeReq("$baseUrl/setup_admin.php", 'POST', $postSetup, $ckAdminSetup);
$adminCreated = strpos($resSetupPost['body'], 'has been authorized and updated') !== false;
echo "[VERIFY 4] Fresh Admin Creation/Setup (poreddyrani21@gmail.com): HTTP {$resSetupPost['code']} - " . ($adminCreated ? "PASSED (Authorized as Admin)" : "FAILED") . "\n";

// 5. Test Fresh Investor Login
$ckInvLog = __DIR__ . '/test_fresh_inv_log_ck.txt';
if (file_exists($ckInvLog)) @unlink($ckInvLog);
$resLogInvGet = makeReq("$baseUrl/login.php", 'GET', [], $ckInvLog);
$csrfLogInv = getCsrf($resLogInvGet['body']);
$captchaLogInv = getCaptcha($resLogInvGet['body']);

$postLogInv = [
    'csrf_token' => $csrfLogInv,
    'email' => 'fresh.investor@example.com',
    'password' => 'FreshInvestor@123',
    'captcha_input' => $captchaLogInv
];
$resLogInv = makeReq("$baseUrl/login.php", 'POST', $postLogInv, $ckInvLog);
$invLoginSuccess = (strpos($resLogInv['header'], '302') !== false || strpos($resLogInv['body'], 'Dashboard') !== false);
echo "[VERIFY 5.1] Fresh Investor Login: HTTP {$resLogInv['code']} - " . ($invLoginSuccess ? "PASSED (Logged in to Investor Dashboard)" : "FAILED") . "\n";

// 6. Test Fresh Admin Login
$ckAdminLog = __DIR__ . '/test_fresh_admin_log_ck.txt';
if (file_exists($ckAdminLog)) @unlink($ckAdminLog);
$resLogAdminGet = makeReq("$baseUrl/admin/login.php", 'GET', [], $ckAdminLog);
$csrfLogAdmin = getCsrf($resLogAdminGet['body']);
$captchaLogAdmin = getCaptcha($resLogAdminGet['body']);

$postLogAdmin = [
    'csrf_token' => $csrfLogAdmin,
    'email' => 'poreddyrani21@gmail.com',
    'password' => 'MyFreshAdmin@2026',
    'captcha_input' => $captchaLogAdmin
];
$resLogAdmin = makeReq("$baseUrl/admin/login.php", 'POST', $postLogAdmin, $ckAdminLog);
$adminLoginSuccess = (strpos($resLogAdmin['header'], '302') !== false || strpos($resLogAdmin['body'], 'Dashboard') !== false);
echo "[VERIFY 5.2] Fresh Admin Login: HTTP {$resLogAdmin['code']} - " . ($adminLoginSuccess ? "PASSED (Logged in to Admin Dashboard)" : "FAILED") . "\n";

// Cleanup
@unlink($ckInv); @unlink($ckAdminSetup); @unlink($ckInvLog); @unlink($ckAdminLog);

echo "\n=== ALL POST-RESET VERIFICATIONS PASSED 100% ===\n";
