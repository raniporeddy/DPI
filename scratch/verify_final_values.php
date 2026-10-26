<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$d = readIdentityDocumentXmlSecure(18);

echo "=== eKYC Page Field Values (User 18) ===" . PHP_EOL;
echo "Full Name:    " . $d['full_name'] . PHP_EOL;
echo "DOB:          " . $d['dob'] . PHP_EOL;
echo "Gender:       " . $d['gender'] . PHP_EOL;
echo "Full Address: " . $d['address'] . PHP_EOL;
echo "Mobile:       " . $d['mobile'] . PHP_EOL;
echo "ID (masked):  " . maskAadhaarNumber($d['id_number']) . PHP_EOL;
echo "ID (revealed):" . $d['id_number'] . PHP_EOL;
echo PHP_EOL;

// Check expected values
$checks = [
    ['Full Name', $d['full_name'], 'Poreddy Rani'],
    ['DOB', $d['dob'], '2006-05-18'],
    ['Gender', $d['gender'], 'Female'],
    ['Address', $d['address'], '1-188, Eguva Palli, Dhaniyanicheruvu, Anantapur Andhra Pradesh - 515521'],
    ['Mobile', $d['mobile'], '8317666760'],
    ['ID Masked', maskAadhaarNumber($d['id_number']), 'XXXX-XXXX-2926'],
    ['ID Full', $d['id_number'], '7320-6267-2926'],
];

foreach ($checks as [$field, $got, $expected]) {
    $ok = (trim($got) === trim($expected));
    echo ($ok ? '[PASS]' : '[FAIL]') . " $field: " . ($ok ? "OK" : "Expected '$expected', got '$got'") . PHP_EOL;
}
