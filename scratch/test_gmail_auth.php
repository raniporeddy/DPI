<?php
require_once __DIR__ . '/../includes/email_helper.php';

$res = sendPurePhpSmtpSocket('poreddychennareddy07@gmail.com', 'Test Subject', '<h1>Test Body</h1>', 'poreddychennareddy07@gmail.com', 'poreddy', 'smtp.gmail.com', 587);

echo "SMTP Result:\n";
print_r($res);
