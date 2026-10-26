<?php
require 'config/database.php';
foreach(['users', 'kyc_verification', 'investor_profiles'] as $t) {
    echo "=== Table $t ===\n";
    foreach($pdo->query("DESCRIBE $t")->fetchAll(PDO::FETCH_ASSOC) as $c) {
        echo "  " . $c['Field'] . " (" . $c['Type'] . ")\n";
    }
}
