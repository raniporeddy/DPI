<?php
// scratch/test_xml_ekyc_details.php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

echo "========================================================\n";
echo "TESTING DOCUMENT-EXTRACTED XML eKYC WORKFLOW\n";
echo "========================================================\n\n";

$passedTests = 0;
$totalTests = 0;

function testAssert($condition, $description) {
    global $passedTests, $totalTests;
    $totalTests++;
    if ($condition) {
        echo "[PASS] " . $description . "\n";
        $passedTests++;
    } else {
        echo "[FAIL] " . $description . "\n";
    }
}

// 1. Verify XML file exists and is valid for User 18
$xml18Path = dirname(__DIR__) . '/storage/ekyc_xml/investor_18.xml';
testAssert(file_exists($xml18Path), "XML file storage/ekyc_xml/investor_18.xml exists");

$user18Data = readIdentityDocumentXmlSecure(18);
testAssert(!empty($user18Data), "readIdentityDocumentXmlSecure() parsed XML successfully");
testAssert($user18Data['full_name'] === 'Poreddy Rani', "Extracted FullName in XML is 'Poreddy Rani'");
testAssert($user18Data['dob'] === '2006-05-18', "Extracted DateOfBirth in XML is '2006-05-18'");
testAssert($user18Data['id_number'] === '732062672926', "Extracted IDNumber in XML is '732062672926'");
testAssert($user18Data['address'] === '51551', "Extracted Address in XML is '51551'");
testAssert(str_contains($user18Data['photograph_path'], 'aadhaar_photo_user_18'), "Extracted PhotographPath in XML points to extracted document portrait");

// 2. Test unlocked HTML render for User 18
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['user_id'] = 18;
$_SESSION['role'] = 'investor';
$_SESSION['ekyc_unlocked_user_18'] = true;
$_SERVER['REQUEST_METHOD'] = 'GET';

ob_start();
require __DIR__ . '/../investor/ekyc.php';
$html = ob_get_clean();

// Check document details are present
testAssert(str_contains($html, 'Poreddy Rani'), "Document-extracted name 'Poreddy Rani' is rendered");
testAssert(str_contains($html, '18/05/2006'), "Document-extracted DOB '18/05/2006' is rendered");
testAssert(stripos($html, 'xxxx-xxxx-2926') !== false, "Document-extracted ID number is masked by default");
testAssert(str_contains($html, '51551'), "Document-extracted address '51551' is rendered");
testAssert(str_contains($html, 'aadhaar_photo_user_18'), "Document-extracted portrait photo is rendered");

// Check profile column and profile details are REMOVED
testAssert(!str_contains($html, 'Submitted Profile Detail'), "Column 'Submitted Profile Detail' is REMOVED from the table");
testAssert(!str_contains($html, '1-188,eguvapalli,anantapur'), "Profile address '1-188,eguvapalli,anantapur' is NOT displayed in table");
testAssert(!str_contains($html, '20 Years'), "Profile-computed age '(20 Years)' from profile detail is NOT displayed");

// 3. Test Missing Field Handling (Requirement 6: Display "Not extracted")
$testUid = 88888;
saveIdentityDocumentXml($testUid, [
    'full_name'  => 'Sample Extracted Name',
    'dob'        => '', // missing DOB
    'id_number'  => '', // missing ID
    'address'    => '', // missing address
    'photo_path' => ''  // missing photo
]);

$_SESSION['user_id'] = $testUid;
$_SESSION['ekyc_unlocked_user_' . $testUid] = true;

ob_start();
require __DIR__ . '/../investor/ekyc.php';
$testHtml = ob_get_clean();

testAssert(str_contains($testHtml, 'Sample Extracted Name'), "Available field in XML is displayed");
testAssert(substr_count($testHtml, 'Not extracted') >= 3, "Missing fields in XML display 'Not extracted'");
testAssert(str_contains($testHtml, 'Photograph not extracted from document'), "Missing photo displays 'Photograph not extracted from document'");

// Clean up test file
@unlink(dirname(__DIR__) . '/storage/ekyc_xml/investor_' . $testUid . '.xml');

// 4. Test Scenario: No document or XML exists (Requirement 7)
$_SESSION['user_id'] = 77777;
unset($_SESSION['ekyc_unlocked_user_77777']);

ob_start();
require __DIR__ . '/../investor/ekyc.php';
$emptyHtml = ob_get_clean();

testAssert(str_contains($emptyHtml, 'No uploaded identity document or extracted eKYC records found. Please submit your identity document first.'), "When no document exists, shows exact clear message and option to submit");

echo "\n--------------------------------------------------------\n";
echo "SUMMARY: {$passedTests} / {$totalTests} tests passed.\n";
echo "--------------------------------------------------------\n";
