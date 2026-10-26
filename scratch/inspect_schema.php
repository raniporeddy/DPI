<?php
require_once __DIR__ . '/../config/database.php';
echo "--- DESCRIBE ekyc_requests ---\n";
try {
    $cols = $pdo->query('DESCRIBE ekyc_requests')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $col) {
        echo "{$col['Field']} | {$col['Type']} | {$col['Null']} | {$col['Key']} | {$col['Default']}\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "\n--- DESCRIBE kyc_verification ---\n";
try {
    $cols = $pdo->query('DESCRIBE kyc_verification')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $col) {
        echo "{$col['Field']} | {$col['Type']} | {$col['Null']} | {$col['Key']} | {$col['Default']}\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
