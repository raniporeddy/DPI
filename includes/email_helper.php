<?php
// includes/email_helper.php
// Pure PHP Backend Email Service Helper for eSign Requests & Real-Time SMTP Delivery

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Helper to parse .env file in project root
 */
function loadEnvConfig() {
    static $envData = null;
    if ($envData !== null) {
        return $envData;
    }

    $envData = [];
    $envFile = dirname(__DIR__) . '/.env';
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || strpos($line, '#') === 0) {
                continue;
            }
            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $envData[trim($key)] = trim(trim($value), '"\'');
            }
        }
    }
    return $envData;
}

/**
 * Get Environment Variable with Fallback & Key Alias Mapping
 */
function getEnvVal($key, $default = '') {
    $aliases = [
        'SMTP_HOST' => ['SMTP_HOST', 'MAIL_HOST', 'EMAIL_HOST'],
        'SMTP_PORT' => ['SMTP_PORT', 'MAIL_PORT', 'EMAIL_PORT'],
        'SMTP_USER' => ['SMTP_USER', 'SMTP_USERNAME', 'MAIL_USER', 'MAIL_USERNAME', 'GMAIL_USER', 'EMAIL_USER'],
        'SMTP_PASS' => ['SMTP_PASS', 'SMTP_PASSWORD', 'MAIL_PASS', 'MAIL_PASSWORD', 'GMAIL_PASS', 'EMAIL_PASS', 'GMAIL_APP_PASSWORD', 'APP_PASSWORD'],
        'SMTP_FROM_EMAIL' => ['SMTP_FROM_EMAIL', 'MAIL_FROM_ADDRESS', 'MAIL_FROM', 'EMAIL_FROM'],
        'SMTP_FROM_NAME' => ['SMTP_FROM_NAME', 'MAIL_FROM_NAME', 'EMAIL_FROM_NAME']
    ];

    $keysToTry = isset($aliases[$key]) ? $aliases[$key] : [$key];
    $env = loadEnvConfig();

    foreach ($keysToTry as $k) {
        if (isset($env[$k]) && trim((string)$env[$k]) !== '') {
            return trim((string)$env[$k]);
        }
        $val = getenv($k);
        if ($val !== false && trim((string)$val) !== '') {
            return trim((string)$val);
        }
    }
    return $default;
}

/**
 * Pure PHP SMTP Socket Email Dispatcher (No Python required, Works on Apache/XAMPP/Production)
 */
function sendPurePhpSmtpSocket($toEmail, $subject, $htmlContent, $smtpUser, $smtpPass, $smtpHost = 'smtp.gmail.com', $smtpPort = 587, $fromName = 'Digital Investor System') {
    $timeout = 15;
    $log = [];

    $socket = @fsockopen($smtpHost, (int)$smtpPort, $errno, $errstr, $timeout);
    if (!$socket) {
        return ['success' => false, 'error' => "Could not connect to SMTP server $smtpHost:$smtpPort ($errstr)"];
    }

    $read = function() use ($socket, &$log) {
        $res = '';
        while ($str = @fgets($socket, 512)) {
            $res .= $str;
            if (substr($str, 3, 1) == ' ') break;
        }
        $log[] = "SERVER: " . trim($res);
        return $res;
    };

    $write = function($cmd) use ($socket, &$log) {
        $log[] = "CLIENT: " . trim($cmd);
        @fputs($socket, $cmd . "\r\n");
    };

    $greeting = $read();
    if (substr($greeting, 0, 3) != '220') {
        @fclose($socket);
        return ['success' => false, 'error' => "SMTP Greeting Error: $greeting"];
    }

    $write("EHLO " . gethostname());
    $read();

    if ((int)$smtpPort === 587) {
        $write("STARTTLS");
        $res = $read();
        if (substr($res, 0, 3) != '220') {
            @fclose($socket);
            return ['success' => false, 'error' => "STARTTLS Failed: $res"];
        }

        $cryptoRes = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
        if (!$cryptoRes) {
            @fclose($socket);
            return ['success' => false, 'error' => "TLS Encryption Handshake Failed with SMTP server."];
        }

        $write("EHLO " . gethostname());
        $read();
    }

    // AUTH LOGIN
    $write("AUTH LOGIN");
    $res = $read();
    if (substr($res, 0, 3) != '334') {
        @fclose($socket);
        return ['success' => false, 'error' => "AUTH LOGIN rejected: $res"];
    }

    $write(base64_encode($smtpUser));
    $res = $read();
    if (substr($res, 0, 3) != '334') {
        @fclose($socket);
        return ['success' => false, 'error' => "SMTP Username rejected: $res"];
    }

    $passToSend = (strpos(strtolower($smtpHost), 'gmail') !== false) ? str_replace(' ', '', $smtpPass) : $smtpPass;
    $write(base64_encode($passToSend));
    $res = $read();
    if (substr($res, 0, 3) != '235') {
        @fclose($socket);
        $errDetail = trim($res);
        if (strpos($res, '5.7.8') !== false || strpos($res, 'Username and Password not accepted') !== false) {
            $errDetail = "Gmail Authentication Failed: Please use a 16-character Gmail App Password (generated at Google Account > Security > App Passwords).";
        }
        return ['success' => false, 'error' => $errDetail];
    }

    // Envelope sender must match authenticated user
    $write("MAIL FROM: <$smtpUser>");
    $read();

    $write("RCPT TO: <$toEmail>");
    $res = $read();
    if (substr($res, 0, 3) != '250' && substr($res, 0, 3) != '251') {
        @fclose($socket);
        return ['success' => false, 'error' => "Recipient Email Rejected: $res"];
    }

    // DATA
    $write("DATA");
    $read();

    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: $fromName <$smtpUser>\r\n";
    $headers .= "To: <$toEmail>\r\n";
    $headers .= "Subject: $subject\r\n";
    $headers .= "Date: " . date('r') . "\r\n";

    $body = $headers . "\r\n" . $htmlContent . "\r\n.";
    $write($body);
    $res = $read();

    $write("QUIT");
    @fclose($socket);

    if (substr($res, 0, 3) == '250') {
        return ['success' => true, 'message' => "Email sent successfully to $toEmail!"];
    } else {
        return ['success' => false, 'error' => "Mail Data Transmission Failed: $res"];
    }
}

/**
 * Dispatch eSign Request Email directly to recipient email address
 */
function sendESignEmailRequest($recipientEmail, $investorName, $agreementTitle, $signingUrl, $pdfAttachmentPath = null) {
    $smtpHost  = getEnvVal('SMTP_HOST', 'smtp.gmail.com');
    $smtpUser  = getEnvVal('SMTP_USER', '');
    $smtpPass  = getEnvVal('SMTP_PASS', '');
    $smtpPort  = getEnvVal('SMTP_PORT', '587');
    $fromName  = getEnvVal('SMTP_FROM_NAME', 'Digital Investor System');

    $subject = "Action Required: eSign Request for " . $agreementTitle;

    $htmlContent = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <style>
            body { font-family: "Segoe UI", Arial, sans-serif; background-color: #f4f6f9; color: #333; margin: 0; padding: 20px; }
            .container { max-width: 600px; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.08); margin: 0 auto; border-top: 5px solid #0d6efd; }
            .header { padding: 25px; background: #071527; color: #ffffff; text-align: center; }
            .content { padding: 30px; line-height: 1.6; }
            .btn { display: inline-block; background-color: #198754; color: #ffffff !important; padding: 14px 32px; text-decoration: none; border-radius: 8px; font-weight: bold; font-size: 16px; margin: 24px 0; }
            .footer { padding: 20px; background: #f8f9fa; font-size: 12px; color: #6c757d; text-align: center; border-top: 1px solid #e9ecef; }
            .card-box { background: #f8f9fa; border: 1px solid #e2e8f0; border-radius: 8px; padding: 15px; margin: 15px 0; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h2 style="margin:0;">Digital Investor Onboarding System</h2>
                <span style="font-size:13px; color:#cbd5e1;">Electronic Signature Workflow</span>
            </div>
            <div class="content">
                <p>Hello,</p>
                <p>An electronic signature request has been generated for <strong>' . htmlspecialchars($investorName) . '</strong> for the following agreement:</p>
                <div class="card-box">
                    <strong style="color:#0d6efd; font-size:16px;">' . htmlspecialchars($agreementTitle) . '</strong>
                </div>
                <p>Please click the button below to open your secure eSign portal. You will be able to review the agreement document, sign it, and upload your completed signed document.</p>
                <p style="text-align: center;">
                    <a href="' . htmlspecialchars($signingUrl) . '" class="btn">Open eSign Portal & Upload Document</a>
                </p>
                <p style="font-size:13px; color:#64748b;">If the button above does not work, copy and paste this link into your web browser:</p>
                <p style="word-break: break-all; font-size: 12px; color: #0d6efd;"><a href="' . htmlspecialchars($signingUrl) . '">' . htmlspecialchars($signingUrl) . '</a></p>
            </div>
            <div class="footer">
                &copy; ' . date('Y') . ' Digital Investor Onboarding System. Access token is single-use and encrypted.
            </div>
        </div>
    </body>
    </html>';

    // Save copy of HTML email to inbox folder for email inbox preview/simulation
    $inboxDir = dirname(__DIR__) . '/uploads/email_inbox';
    if (!file_exists($inboxDir)) {
        @mkdir($inboxDir, 0777, true);
    }
    $inboxFile = $inboxDir . '/mail_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $recipientEmail) . '_' . time() . '.html';
    @file_put_contents($inboxFile, $htmlContent);

    // 1. If SMTP credentials exist, attempt Live Pure PHP Socket Dispatch
    if (!empty($smtpHost) && !empty($smtpUser) && !empty($smtpPass)) {
        $res = sendPurePhpSmtpSocket($recipientEmail, $subject, $htmlContent, $smtpUser, $smtpPass, $smtpHost, $smtpPort, $fromName);
        if ($res['success']) {
            return [
                'success' => true,
                'is_demo' => false,
                'message' => 'eSign request email delivered directly to recipient inbox (' . $recipientEmail . ') via Live SMTP!',
                'delivery_status' => 'Sent via SMTP',
                'inbox_file' => $inboxFile
            ];
        } else {
            return [
                'success' => false,
                'error' => 'Failed to send eSign request email to ' . $recipientEmail . '. SMTP Error: ' . $res['error'],
                'delivery_status' => 'Failed'
            ];
        }
    }

    // 2. Unconfigured SMTP Error (Do not show success without sending an actual email)
    return [
        'success' => false,
        'error' => 'SMTP credentials (SMTP_USER & 16-character Gmail App Password) are not set in the project .env file. Please set your Gmail ID and App Password in the project .env file to send real emails to ' . $recipientEmail . '.',
        'delivery_status' => 'Unconfigured'
    ];
}

/**
 * Dispatch Aadhaar eKYC Submission Request Email directly to investor email address
 */
function sendEKYCEmailRequest($recipientEmail, $investorName, $submissionUrl) {
    $smtpHost  = getEnvVal('SMTP_HOST', 'smtp.gmail.com');
    $smtpUser  = getEnvVal('SMTP_USER', '');
    $smtpPass  = getEnvVal('SMTP_PASS', '');
    $smtpPort  = getEnvVal('SMTP_PORT', '587');
    $fromName  = getEnvVal('SMTP_FROM_NAME', 'Digital Investor System');

    $subject = "Action Required: Complete Your Aadhaar eKYC Verification";

    $htmlContent = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <style>
            body { font-family: "Segoe UI", Arial, sans-serif; background-color: #f4f6f9; color: #333; margin: 0; padding: 20px; }
            .container { max-width: 600px; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.08); margin: 0 auto; border-top: 5px solid #ffc107; }
            .header { padding: 25px; background: #071527; color: #ffffff; text-align: center; }
            .content { padding: 30px; line-height: 1.6; }
            .btn { display: inline-block; background-color: #0d6efd; color: #ffffff !important; padding: 14px 32px; text-decoration: none; border-radius: 8px; font-weight: bold; font-size: 16px; margin: 24px 0; }
            .footer { padding: 20px; background: #f8f9fa; font-size: 12px; color: #6c757d; text-align: center; border-top: 1px solid #e9ecef; }
            .card-box { background: #f8f9fa; border: 1px solid #e2e8f0; border-radius: 8px; padding: 15px; margin: 15px 0; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h2 style="margin:0;">Digital Investor Onboarding System</h2>
                <span style="font-size:13px; color:#cbd5e1;">Aadhaar eKYC Verification Workflow</span>
            </div>
            <div class="content">
                <p>Hello,</p>
                <p>An Aadhaar eKYC submission request has been generated for <strong>' . htmlspecialchars($investorName) . '</strong>.</p>
                <div class="card-box">
                    <strong style="color:#0d6efd; font-size:16px;">Aadhaar Document & Photo Submission Portal</strong>
                </div>
                <p>Please click the button below to open your secure single-use eKYC submission portal. You will be able to upload your Aadhaar document, photo, and identity details for verification.</p>
                <p style="text-align: center;">
                    <a href="' . htmlspecialchars($submissionUrl) . '" class="btn">Open eKYC Submission Portal</a>
                </p>
                <p style="font-size:13px; color:#64748b;">If the button above does not work, copy and paste this link into your web browser:</p>
                <p style="word-break: break-all; font-size: 12px; color: #0d6efd;"><a href="' . htmlspecialchars($submissionUrl) . '">' . htmlspecialchars($submissionUrl) . '</a></p>
            </div>
            <div class="footer">
                &copy; ' . date('Y') . ' Digital Investor Onboarding System. Access token is single-use and encrypted.
            </div>
        </div>
    </body>
    </html>';

    // Save copy of HTML email to inbox folder
    $inboxDir = dirname(__DIR__) . '/uploads/email_inbox';
    if (!file_exists($inboxDir)) {
        @mkdir($inboxDir, 0777, true);
    }
    $inboxFile = $inboxDir . '/mail_ekyc_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $recipientEmail) . '_' . time() . '.html';
    @file_put_contents($inboxFile, $htmlContent);

    if (!empty($smtpHost) && !empty($smtpUser) && !empty($smtpPass)) {
        $res = sendPurePhpSmtpSocket($recipientEmail, $subject, $htmlContent, $smtpUser, $smtpPass, $smtpHost, $smtpPort, $fromName);
        if ($res['success']) {
            return [
                'success' => true,
                'is_demo' => false,
                'message' => 'eKYC submission email delivered directly to recipient inbox (' . $recipientEmail . ') via Live SMTP!',
                'delivery_status' => 'Sent via SMTP',
                'inbox_file' => $inboxFile
            ];
        } else {
            return [
                'success' => false,
                'error' => 'Failed to send eKYC request email to ' . $recipientEmail . '. SMTP Error: ' . $res['error'],
                'delivery_status' => 'Failed'
            ];
        }
    }

    return [
        'success' => false,
        'error' => 'SMTP credentials (SMTP_USER & 16-character Gmail App Password) are not set in the project .env file.',
        'delivery_status' => 'Unconfigured'
    ];
}

/**
 * Update values in .env file dynamically
 */
function updateEnvConfig($newValues) {
    $envFile = dirname(__DIR__) . '/.env';
    $existingEnv = loadEnvConfig();
    $merged = array_merge($existingEnv, $newValues);

    $lines = [];
    $lines[] = "# Digital Investor Onboarding System - Active Environment Configuration";
    $lines[] = "# Configured via Web UI / .env Settings Manager";
    $lines[] = "";

    foreach ($merged as $k => $v) {
        if (strpos($v, ' ') !== false) {
            $v = '"' . trim($v, '"\'') . '"';
        }
        $lines[] = "{$k}={$v}";
    }
    $lines[] = "";

    return @file_put_contents($envFile, implode("\n", $lines)) !== false;
}
