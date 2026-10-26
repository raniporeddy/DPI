<?php
// scratch/test_updated_ekyc_render.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$_SESSION['user_id'] = 18;
$_SESSION['role'] = 'investor';
$_SESSION['username'] = 'poreddy rani';
$_SESSION['email'] = 'poreddyrani268@gmail.com';
$_SESSION['ekyc_unlocked_user_18'] = true; // Simulating unlocked session
$_SERVER['REQUEST_METHOD'] = 'GET';

ob_start();
require __DIR__ . '/../investor/ekyc.php';
$html = ob_get_clean();

$checks = [
    // 1. Subtitle removed
    'Subtitle removed' => !str_contains($html, 'Extracted face photograph and document-extracted OCR details (parsed via PHP SimpleXML)'),
    
    // 2. OCR Section heading removed
    'OCR text extraction heading removed' => !str_contains($html, 'OCR Text Extraction Results'),
    
    // 3. Table header removed
    'Table header row removed' => !str_contains($html, 'Document Extracted Detail (OCR / XML)') && !str_contains($html, 'Field Title</th>'),
    
    // 4. Verification info panel removed
    'Verification status panel removed' => !str_contains($html, 'Verification Status: Submitted') 
                                          && !str_contains($html, 'Data retrieved from secure account XML record')
                                          && !str_contains($html, 'storage/ekyc_xml/investor_18.xml'),
    
    // 5. Photograph caption removed & photo preserved
    'Photograph caption Face Portrait Extracted removed' => !str_contains($html, 'Face Portrait Extracted'),
    'Photograph image tag preserved' => str_contains($html, '<img src="/digital-investor/uploads/extracted/aadhaar_photo_user_18_') || str_contains($html, 'aadhaar_photo_user_18'),
    'No literal entity noise' => !str_contains($html, '&#xA;') && !str_contains($html, '&#xA0;'),

    // 6. Identity details displayed clearly
    'Full Name field present' => str_contains($html, 'Full Name') && str_contains($html, 'Poreddy Rani'),
    'Date of Birth field present' => str_contains($html, 'Date of Birth') && str_contains($html, '18/05/2006'),
    'Gender field present' => str_contains($html, 'Gender') && str_contains($html, 'Female'),
    'Full Address field present' => str_contains($html, 'Full Address') && str_contains($html, '515521') && str_contains($html, 'Andhra Pradesh'),
    'Mobile Number field present' => str_contains($html, 'Mobile Number'),
    'ID Number masked by default' => str_contains($html, 'ID Number') && (str_contains($html, 'xxxx-xxxx-2926') || str_contains($html, '7320-6267-2926')),
    'Show/Hide toggle present' => str_contains($html, 'toggleAadhaarVisibility'),
    'Lock details button present' => str_contains($html, 'Lock Details'),
    'Back to Dashboard button present' => str_contains($html, 'Back to Dashboard'),
];

echo "========================================================\n";
echo "EKYC VERIFICATION DETAILS PAGE AUDIT\n";
echo "========================================================\n";

$allPassed = true;
foreach ($checks as $desc => $result) {
    echo ($result ? "[PASS] " : "[FAIL] ") . $desc . "\n";
    if (!$result) $allPassed = false;
}

if ($allPassed) {
    echo "\n>>> ALL VERIFICATION CHECKS PASSED PERFECTLY!\n";
} else {
    echo "\n>>> SOME VERIFICATION CHECKS FAILED.\n";
}
