<?php
// Scratch script to test pure PHP SMTP socket connection to Gmail

function sendPurePhpSmtp($toEmail, $subject, $htmlContent, $smtpUser, $smtpPass, $smtpHost = 'smtp.gmail.com', $smtpPort = 587) {
    $timeout = 15;
    $log = [];

    $socket = @fsockopen($smtpHost, $smtpPort, $errno, $errstr, $timeout);
    if (!$socket) {
        return ['success' => false, 'error' => "Could not connect to SMTP host $smtpHost:$smtpPort - $errstr ($errno)"];
    }

    $read = function() use ($socket, &$log) {
        $res = '';
        while ($str = fgets($socket, 512)) {
            $res .= $str;
            if (substr($str, 3, 1) == ' ') break;
        }
        $log[] = "SERVER: " . trim($res);
        return $res;
    };

    $write = function($cmd) use ($socket, &$log) {
        $log[] = "CLIENT: " . trim($cmd);
        fputs($socket, $cmd . "\r\n");
    };

    $greeting = $read();
    if (substr($greeting, 0, 3) != '220') {
        fclose($socket);
        return ['success' => false, 'error' => "Server greeting error: $greeting", 'log' => $log];
    }

    $write("EHLO " . gethostname());
    $read();

    if ($smtpPort == 587) {
        $write("STARTTLS");
        $res = $read();
        if (substr($res, 0, 3) != '220') {
            fclose($socket);
            return ['success' => false, 'error' => "STARTTLS failed: $res", 'log' => $log];
        }

        // Encrypt socket connection with TLS
        $cryptoRes = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
        if (!$cryptoRes) {
            fclose($socket);
            return ['success' => false, 'error' => "TLS encryption handhake failed.", 'log' => $log];
        }

        $write("EHLO " . gethostname());
        $read();
    }

    // AUTH LOGIN
    $write("AUTH LOGIN");
    $res = $read();
    if (substr($res, 0, 3) != '334') {
        fclose($socket);
        return ['success' => false, 'error' => "AUTH LOGIN not supported: $res", 'log' => $log];
    }

    $write(base64_encode($smtpUser));
    $res = $read();
    if (substr($res, 0, 3) != '334') {
        fclose($socket);
        return ['success' => false, 'error' => "Username rejected: $res", 'log' => $log];
    }

    $write(base64_encode($smtpPass));
    $res = $read();
    if (substr($res, 0, 3) != '235') {
        fclose($socket);
        return ['success' => false, 'error' => "Authentication failed: Incorrect password or Gmail App Password required. $res", 'log' => $log];
    }

    // MAIL FROM / RCPT TO
    $write("MAIL FROM: <$smtpUser>");
    $read();

    $write("RCPT TO: <$toEmail>");
    $res = $read();
    if (substr($res, 0, 3) != '250' && substr($res, 0, 3) != '251') {
        fclose($socket);
        return ['success' => false, 'error' => "Recipient rejected: $res", 'log' => $log];
    }

    // DATA
    $write("DATA");
    $read();

    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: Digital Investor System <$smtpUser>\r\n";
    $headers .= "To: <$toEmail>\r\n";
    $headers .= "Subject: $subject\r\n";
    $headers .= "Date: " . date('r') . "\r\n";

    $body = $headers . "\r\n" . $htmlContent . "\r\n.";
    $write($body);
    $res = $read();

    $write("QUIT");
    fclose($socket);

    if (substr($res, 0, 3) == '250') {
        return ['success' => true, 'message' => "Email sent successfully to $toEmail!", 'log' => $log];
    } else {
        return ['success' => false, 'error' => "Failed to deliver mail body: $res", 'log' => $log];
    }
}

// Test with dummy params
echo "Testing pure PHP SMTP helper defined.\n";
