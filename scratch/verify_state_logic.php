<?php
require __DIR__ . '/../config/database.php';

$users = [1, 17, 18];
foreach ($users as $uid) {
    $s = $pdo->prepare("SELECT status, document_path FROM kyc_verification WHERE user_id = ?");
    $s->execute([$uid]);
    $r = $s->fetch();
    if (!$r) { echo "user $uid: NO ROW\n"; continue; }

    // New logic from ekyc.php
    $kycIsResetState = !empty($r)
                    && empty($r['document_path'])
                    && !in_array($r['status'], ['Verified'], true);
    $hasSubmittedKyc = !empty($r) && !empty($r['document_path']) && !$kycIsResetState;

    $shows = $hasSubmittedKyc
        ? 'PASSWORD GATE -> DETAILS'
        : ($kycIsResetState ? 'RESUBMIT FORM' : 'NO-DOC CARD');

    echo "user $uid: status={$r['status']} doc=" . (empty($r['document_path']) ? 'NULL' : 'SET')
       . " => resetState=" . ($kycIsResetState ? 'YES' : 'NO')
       . " hasSubmitted=" . ($hasSubmittedKyc ? 'YES' : 'NO')
       . " => page shows: $shows\n";
}
