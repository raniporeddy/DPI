<?php
// captcha.php
// CAPTCHA Refresh Endpoint

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/includes/captcha_helper.php';

header('Content-Type: application/json');

$newCode = generateCaptchaCode();
echo json_encode(['success' => true, 'code' => $newCode]);
exit;
