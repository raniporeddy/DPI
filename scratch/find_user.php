<?php
require_once __DIR__ . '/../config/database.php';
$stmt = $pdo->query("SELECT k.*, p.address as prof_addr, p.full_name as prof_name, p.dob as prof_dob, p.id_number as prof_id FROM kyc_verification k LEFT JOIN investor_profiles p ON k.user_id=p.user_id ORDER BY k.id DESC LIMIT 3");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($rows, JSON_PRETTY_PRINT);
