<?php
// includes/functions.php
// Common Helper Functions for Digital Investor Onboarding System

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Escape HTML output safely
 */
function e($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Calculate age in years from DOB date string
 */
function calculateAge($dobStr) {
    if (empty($dobStr) || $dobStr === 'N/A' || $dobStr === '0000-00-00') {
        return 'N/A';
    }
    try {
        $dobDate = new DateTime($dobStr);
        $now = new DateTime();
        if ($dobDate > $now) {
            return 'N/A';
        }
        $diff = $now->diff($dobDate);
        return $diff->y > 0 ? $diff->y . ' Years' : '0 Years';
    } catch (Exception $e) {
        return 'N/A';
    }
}

/**
 * Format DOB string for clean user-facing display (e.g. DD/MM/YYYY)
 */
function formatDobDisplay($dobStr) {
    if (empty($dobStr) || $dobStr === 'N/A' || $dobStr === '0000-00-00') {
        return 'N/A';
    }
    try {
        $date = new DateTime($dobStr);
        return $date->format('d/m/Y');
    } catch (Exception $e) {
        return $dobStr;
    }
}

/**
 * Set flash message in session
 */
function setFlash($type, $message) {
    $_SESSION['flash_msg'] = [
        'type' => $type, // 'success', 'danger', 'warning', 'info'
        'message' => $message
    ];
}

/**
 * Display and clear flash message
 */
function displayFlash() {
    if (isset($_SESSION['flash_msg'])) {
        $flash = $_SESSION['flash_msg'];
        unset($_SESSION['flash_msg']);
        $icon = $flash['type'] === 'success' ? 'fa-check-circle' : ($flash['type'] === 'danger' ? 'fa-exclamation-triangle' : 'fa-info-circle');
        return '<div class="alert alert-' . e($flash['type']) . ' alert-dismissible fade show shadow-sm border-0 rounded-3 mb-4" role="alert">
                    <i class="fas ' . $icon . ' me-2"></i>' . e($flash['message']) . '
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>';
    }
    return '';
}

/**
 * Render Bootstrap badge with FontAwesome icon for Status strings
 */
function renderStatusBadge($status) {
    $class = 'bg-secondary text-white';
    $icon = 'fa-circle';
    $statusText = trim($status ?? 'Pending');

    switch (strtolower($statusText)) {
        case 'approved':
            $class = 'bg-success-subtle text-success border border-success';
            $icon = 'fa-check-circle';
            break;
        case 'verified':
            $class = 'bg-success text-white';
            $icon = 'fa-user-check';
            break;
        case 'signed':
            $class = 'bg-emerald text-white bg-success';
            $icon = 'fa-file-signature';
            break;
        case 'under review':
        case 'submitted':
            $class = 'bg-warning-subtle text-warning-emphasis border border-warning';
            $icon = 'fa-clock';
            break;
        case 'rejected':
            $class = 'bg-danger text-white';
            $icon = 'fa-times-circle';
            break;
        case 'pending':
        case 'draft':
            $class = 'bg-info-subtle text-info-emphasis border border-info';
            $icon = 'fa-hourglass-half';
            break;
    }
    return '<span class="badge ' . $class . ' px-3 py-1-5 rounded-pill fs-7 fw-semibold"><i class="fas ' . $icon . ' me-1"></i> ' . e($statusText) . '</span>';
}

/**
 * Generate unique Application ID format: INV-YYYYMMDD-XXXX
 */
function generateApplicationID() {
    return 'INV-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 4));
}

/**
 * Base URL helper
 */
function baseUrl($path = '') {
    return '/' . ltrim($path, '/');
}

/**
 * Ensure user has an Application record initialized
 */
function getOrInitApplication($pdo, $userId) {
    $stmt = $pdo->prepare("SELECT * FROM applications WHERE user_id = ?");
    $stmt->execute([$userId]);
    $app = $stmt->fetch();

    if (!$app) {
        $appId = generateApplicationID();
        $stmtInsert = $pdo->prepare("INSERT INTO applications (user_id, application_id, status, profile_completed, kyc_completed, docs_completed, esign_completed) VALUES (?, ?, 'Draft', 0, 0, 0, 0)");
        $stmtInsert->execute([$userId, $appId]);
        
        $stmt = $pdo->prepare("SELECT * FROM applications WHERE user_id = ?");
        $stmt->execute([$userId]);
        $app = $stmt->fetch();
    }

    return $app;
}

/**
 * Recalculate investor onboarding progress status
 */
function refreshApplicationStatus($pdo, $userId) {
    // 1. Check Profile
    $stmtProf = $pdo->prepare("SELECT full_name, mobile, address, id_number FROM investor_profiles WHERE user_id = ?");
    $stmtProf->execute([$userId]);
    $prof = $stmtProf->fetch();
    $profileCompleted = (!empty($prof['full_name']) && !empty($prof['mobile']) && !empty($prof['address']) && !empty($prof['id_number'])) ? 1 : 0;

    // 2. Check eKYC
    $stmtKyc = $pdo->prepare("SELECT status FROM kyc_verification WHERE user_id = ?");
    $stmtKyc->execute([$userId]);
    $kyc = $stmtKyc->fetch();
    $kycCompleted = ($kyc && in_array($kyc['status'], ['Submitted', 'Verified'])) ? 1 : 0;

    // 3. Check Documents
    $stmtDocs = $pdo->prepare("SELECT COUNT(*) as doc_count FROM documents WHERE user_id = ?");
    $stmtDocs->execute([$userId]);
    $docCount = $stmtDocs->fetchColumn();
    $docsCompleted = ($docCount >= 1) ? 1 : 0;

    // 4. Check eSign (Check both direct canvas signature and agreement eSign requests)
    $stmtSign = $pdo->prepare("SELECT status FROM esignatures WHERE user_id = ?");
    $stmtSign->execute([$userId]);
    $esign = $stmtSign->fetch();

    $stmtReqSign = $pdo->prepare("SELECT COUNT(*) FROM esign_requests WHERE user_id = ? AND status = 'Signed'");
    $stmtReqSign->execute([$userId]);
    $reqSignCount = $stmtReqSign->fetchColumn();

    $esignCompleted = (($esign && $esign['status'] === 'Signed') || $reqSignCount > 0) ? 1 : 0;

    // Update applications table
    $stmtUpd = $pdo->prepare("UPDATE applications SET profile_completed = ?, kyc_completed = ?, docs_completed = ?, esign_completed = ? WHERE user_id = ?");
    $stmtUpd->execute([$profileCompleted, $kycCompleted, $docsCompleted, $esignCompleted, $userId]);
}

/**
 * Validate and move uploaded file securely
 */
function handleSecureFileUpload($fileInput, $targetDir, $allowedExtensions = ['jpg', 'jpeg', 'png', 'pdf'], $maxSizeBytes = 5242880) {
    if (!isset($fileInput) || $fileInput['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'File upload failed or no file selected.'];
    }

    if ($fileInput['size'] > $maxSizeBytes) {
        return ['success' => false, 'error' => 'File size exceeds maximum allowed limit of ' . ($maxSizeBytes / (1024 * 1024)) . 'MB.'];
    }

    $fileExt = strtolower(pathinfo($fileInput['name'], PATHINFO_EXTENSION));
    if (!in_array($fileExt, $allowedExtensions)) {
        return ['success' => false, 'error' => 'Invalid file format. Allowed formats: ' . implode(', ', $allowedExtensions)];
    }

    // Verify MIME type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $fileInput['tmp_name']);
    finfo_close($finfo);

    $allowedMimes = ['image/jpeg', 'image/png', 'application/pdf'];
    if (!in_array($mimeType, $allowedMimes)) {
        return ['success' => false, 'error' => 'Security warning: Invalid file content type.'];
    }

    // Ensure target directory exists
    if (!file_exists($targetDir)) {
        mkdir($targetDir, 0777, true);
    }

    $newFilename = uniqid('doc_', true) . '.' . $fileExt;
    $targetPath = rtrim($targetDir, '/\\') . DIRECTORY_SEPARATOR . $newFilename;

    if (move_uploaded_file($fileInput['tmp_name'], $targetPath)) {
        return [
            'success' => true,
            'file_name' => $fileInput['name'],
            'file_path' => str_replace('\\', '/', $targetPath),
            'relative_path' => str_replace('\\', '/', str_replace(dirname(__DIR__), '', $targetPath)),
            'file_size' => $fileInput['size']
        ];
    } else {
        return ['success' => false, 'error' => 'Failed to save uploaded file to destination.'];
    }
}

/**
 * Automatically process uploaded Aadhaar / ID document offline:
 * 1. Extract face photo using local OpenCV (YuNet/Haar Cascades).
 * 2. Extract OCR text (Name, DOB, ID Number, Mobile, Address) using local EasyOCR.
 */
function processKYCDocumentDetailed($uploadedFilePath, $targetPhotoDir = '', $userId = 0) {
    if (empty($uploadedFilePath) || !file_exists($uploadedFilePath)) {
        return ['success' => false, 'error' => 'Uploaded document file not found on server.'];
    }

    if (empty($targetPhotoDir)) {
        $targetPhotoDir = dirname(__DIR__) . '/uploads/extracted';
    }

    if (!file_exists($targetPhotoDir)) {
        mkdir($targetPhotoDir, 0777, true);
    }

    $pyScript = dirname(__DIR__) . '/scripts/process_kyc_document.py';
    if (file_exists($pyScript)) {
        $pyCmds = [
            'C:\\Users\\pored\\AppData\\Local\\Programs\\Python\\Python314\\python.exe',
            'py',
            'python',
            'python3'
        ];
        foreach ($pyCmds as $pyExe) {
            $cmd = '"' . $pyExe . '" "' . $pyScript . '" "' . $uploadedFilePath . '" "' . $targetPhotoDir . '" ' . (int)$userId;
            $output = [];
            $returnVar = -1;
            @exec($cmd, $output, $returnVar);
            if (!empty($output)) {
                $rawStr = implode("\n", $output);
                $jsonStart = strpos($rawStr, '{');
                $jsonEnd = strrpos($rawStr, '}');
                if ($jsonStart !== false && $jsonEnd !== false && $jsonEnd >= $jsonStart) {
                    $cleanJson = substr($rawStr, $jsonStart, ($jsonEnd - $jsonStart) + 1);
                    $data = json_decode($cleanJson, true);
                    if ($data && isset($data['success']) && $data['success'] === true) {
                        return $data;
                    }
                }
            }
        }
    }

    // Fallback: extract photo via GD if python is unavailable or fails
    $fallbackPhoto = extractPhotoFromDocumentDetailed($uploadedFilePath, $targetPhotoDir, $userId);
    return [
        'success' => $fallbackPhoto['success'],
        'photo_path' => $fallbackPhoto['photo_path'] ?? null,
        'full_name' => null,
        'dob' => null,
        'dob_formatted' => null,
        'id_number' => null,
        'mobile' => 'Phone number not available on document',
        'address' => null,
        'error' => $fallbackPhoto['error'] ?? null
    ];
}

/**
 * Automatically detect and extract ONLY the person's portrait area from uploaded Aadhaar/ID document image or PDF.
 * Uses skin-tone grid clustering to locate person's face/head and exclude logo, text, QR code, and footer.
 */
function extractPhotoFromDocumentDetailed($uploadedFilePath, $targetPhotoDir = '', $userId = 0) {
    if (empty($uploadedFilePath) || !file_exists($uploadedFilePath)) {
        return ['success' => false, 'error' => 'Uploaded document file not found on server.'];
    }

    if (empty($targetPhotoDir)) {
        $targetPhotoDir = dirname(__DIR__) . '/uploads/extracted';
    }

    if (!file_exists($targetPhotoDir)) {
        mkdir($targetPhotoDir, 0777, true);
    }

    $ext = strtolower(pathinfo($uploadedFilePath, PATHINFO_EXTENSION));
    $userSuffix = $userId > 0 ? 'user_' . $userId . '_' : '';
    $photoFileName = 'aadhaar_photo_' . $userSuffix . time() . '_' . mt_rand(1000, 9999) . '.png';
    $targetPath = rtrim($targetPhotoDir, '/\\') . DIRECTORY_SEPARATOR . $photoFileName;

    // 1. Try Local OpenCV Python script if available
    $pyScript = dirname(__DIR__) . '/scripts/process_kyc_document.py';
    if (!file_exists($pyScript)) {
        $pyScript = dirname(__DIR__) . '/scripts/extract_photo.py';
    }
    if (file_exists($pyScript) && $ext !== 'pdf') {
        $pyCmds = [
            'C:\\Users\\pored\\AppData\\Local\\Programs\\Python\\Python314\\python.exe',
            'py',
            'python',
            'python3'
        ];
        foreach ($pyCmds as $pyExe) {
            $cmd = '"' . $pyExe . '" "' . $pyScript . '" "' . $uploadedFilePath . '" "' . $targetPhotoDir . '" ' . (int)$userId;
            $output = [];
            $returnVar = -1;
            @exec($cmd, $output, $returnVar);
            if ($returnVar === 0 && file_exists($targetPath) && filesize($targetPath) > 100) {
                return ['success' => true, 'photo_path' => 'uploads/extracted/' . $photoFileName];
            }
        }
    }

    // 2. Local PHP GD Image Processing (100% Offline fallback)
    $imageData = null;

    if ($ext === 'pdf') {
        // Extract embedded scanned card image stream from PDF binary
        $pdfContent = @file_get_contents($uploadedFilePath);
        if ($pdfContent) {
            $startPos = strpos($pdfContent, "\xFF\xD8\xFF");
            if ($startPos !== false) {
                $endPos = strpos($pdfContent, "\xFF\xD9", $startPos);
                if ($endPos !== false) {
                    $imageData = substr($pdfContent, $startPos, ($endPos - $startPos) + 2);
                }
            }
        }
        if (!$imageData) {
            return ['success' => false, 'error' => 'Unable to extract image stream from PDF document.'];
        }
    } else {
        $imageData = @file_get_contents($uploadedFilePath);
    }

    if (!$imageData) {
        return ['success' => false, 'error' => 'Image processing error: Unable to read uploaded file data.'];
    }

    // Load GD image resource
    $srcImg = null;
    if (function_exists('imagecreatefromstring')) {
        $srcImg = @imagecreatefromstring($imageData);
    }

    if (!$srcImg) {
        return ['success' => false, 'error' => 'Image processing error: Unsupported image format or corrupted binary data.'];
    }

    try {
        $width = imagesx($srcImg);
        $height = imagesy($srcImg);

        if ($width < 50 || $height < 50) {
            imagedestroy($srcImg);
            return ['success' => false, 'error' => 'Image processing error: Document resolution is too small for portrait extraction.'];
        }

        // Run Skin Tone Grid Density Scan (YCbCr Space) to detect Person Portrait Box
        $gridCols = 30;
        $gridRows = 30;
        $cellW = $width / $gridCols;
        $cellH = $height / $gridRows;

        $skinCounts = array_fill(0, $gridRows, array_fill(0, $gridCols, 0));

        for ($r = 0; $r < $gridRows; $r++) {
            for ($c = 0; $c < $gridCols; $c++) {
                $skinPixels = 0;
                for ($sy = 0; $sy < 4; $sy++) {
                    for ($sx = 0; $sx < 4; $sx++) {
                        $px = (int)($c * $cellW + ($sx + 0.5) * ($cellW / 4));
                        $py = (int)($r * $cellH + ($sy + 0.5) * ($cellH / 4));

                        $px = max(0, min($width - 1, $px));
                        $py = max(0, min($height - 1, $py));

                        $rgb = imagecolorat($srcImg, $px, $py);
                        $red = ($rgb >> 16) & 0xFF;
                        $green = ($rgb >> 8) & 0xFF;
                        $blue = $rgb & 0xFF;

                        $cb = 128 - (0.168736 * $red) - (0.331264 * $green) + (0.5 * $blue);
                        $cr = 128 + (0.5 * $red) - (0.418688 * $green) - (0.081312 * $blue);

                        if ($cb >= 77 && $cb <= 127 && $cr >= 133 && $cr <= 173 && $red > $green && $red > $blue) {
                            $skinPixels++;
                        }
                    }
                }
                $skinCounts[$r][$c] = $skinPixels;
            }
        }

        // Find face skin cluster window
        $maxScore = 0;
        $bestWinR = -1;
        $bestWinC = -1;
        $winRows = 8;
        $winCols = 6;

        for ($r = 1; $r <= $gridRows - $winRows - 1; $r++) {
            for ($c = 1; $c <= $gridCols - $winCols - 1; $c++) {
                $score = 0;
                for ($wr = 0; $wr < $winRows; $wr++) {
                    for ($wc = 0; $wc < $winCols; $wc++) {
                        $score += $skinCounts[$r + $wr][$c + $wc];
                    }
                }
                if ($score > $maxScore) {
                    $maxScore = $score;
                    $bestWinR = $r;
                    $bestWinC = $c;
                }
            }
        }

        $croppedImg = null;

        if ($maxScore >= 12 && $bestWinR >= 0 && $bestWinC >= 0) {
            $cropX = (int)($bestWinC * $cellW);
            $cropY = (int)($bestWinR * $cellH);
            $cropW = (int)($winCols * $cellW);
            $cropH = (int)($winRows * $cellH);

            // Add portrait padding
            $padX = (int)($cropW * 0.20);
            $padY = (int)($cropH * 0.35);

            $cropX = max(0, $cropX - $padX);
            $cropY = max(0, $cropY - (int)($padY * 0.6));
            $cropW = min($width - $cropX, $cropW + 2 * $padX);
            $cropH = min($height - $cropY, $cropH + 2 * $padY);

            if (function_exists('imagecrop')) {
                $croppedImg = @imagecrop($srcImg, ['x' => $cropX, 'y' => $cropY, 'width' => $cropW, 'height' => $cropH]);
            }
            if (!$croppedImg && function_exists('imagecreatetruecolor') && function_exists('imagecopyresampled')) {
                $croppedImg = imagecreatetruecolor($cropW, $cropH);
                if ($croppedImg) {
                    imagecopyresampled($croppedImg, $srcImg, 0, 0, $cropX, $cropY, $cropW, $cropH, $cropW, $cropH);
                }
            }
        }

        // IF NO FACE DETECTED BY SKIN SCAN -> FAIL CLEANLY (NO FIXED CROP OR TOP-LEFT FALLBACK)
        if ($croppedImg) {
            if (function_exists('imagepng')) {
                imagepng($croppedImg, $targetPath);
                imagedestroy($croppedImg);
                imagedestroy($srcImg);
                return ['success' => true, 'photo_path' => 'uploads/extracted/' . $photoFileName];
            } elseif (function_exists('imagejpeg')) {
                $jpgPath = str_replace('.png', '.jpg', $targetPath);
                imagejpeg($croppedImg, $jpgPath, 90);
                imagedestroy($croppedImg);
                imagedestroy($srcImg);
                return ['success' => true, 'photo_path' => 'uploads/extracted/' . str_replace('.png', '.jpg', $photoFileName)];
            }
        }

        imagedestroy($srcImg);

    } catch (Exception $e) {
        error_log("Portrait extraction exception: " . $e->getMessage());
        if ($srcImg) @imagedestroy($srcImg);
        return ['success' => false, 'error' => 'Person photo could not be detected from this document.'];
    }

    return ['success' => false, 'error' => 'Person photo could not be detected from this document.'];
}

/**
 * Backward-compatible wrapper function
 */
function extractPhotoFromDocument($uploadedFilePath, $targetPhotoDir, $userId = 0) {
    $res = extractPhotoFromDocumentDetailed($uploadedFilePath, $targetPhotoDir, $userId);
    return $res['success'] ? $res['photo_path'] : null;
}

/**
 * Mask Aadhaar Number / ID Number for privacy compliance
 * Example: 1234-5678-9012 -> XXXX-XXXX-9012
 */
function maskAadhaarNumber($idNumber, $idType = 'Aadhaar') {
    if (empty($idNumber)) return 'N/A';
    $clean = trim($idNumber);
    $digits = preg_replace('/[^0-9A-Za-z]/', '', $clean);
    
    if (strtolower($idType) === 'aadhaar' || strlen($digits) === 12) {
        $last4 = substr($digits, -4);
        return 'XXXX-XXXX-' . $last4;
    } elseif (strlen($digits) === 10) {
        return 'XXXXX' . substr($digits, -5);
    }
    
    if (strlen($clean) > 4) {
        return str_repeat('X', strlen($clean) - 4) . substr($clean, -4);
    }
    return $clean;
}

/**
 * Fetch all available investment agreements
 */
function getAgreements($pdo) {
    $stmt = $pdo->query("SELECT * FROM investment_agreements ORDER BY id ASC");
    return $stmt->fetchAll();
}

/**
 * Fetch specific agreement by ID
 */
function getAgreementById($pdo, $agreementId) {
    $stmt = $pdo->prepare("SELECT * FROM investment_agreements WHERE id = ?");
    $stmt->execute([$agreementId]);
    return $stmt->fetch();
}

/**
 * Create eSign request record and dispatch email
 */
function createESignRequest($pdo, $userId, $agreementId, $recipientEmail) {
    require_once __DIR__ . '/email_helper.php';
    require_once __DIR__ . '/notification_helper.php';

    // Validate ownership & profile
    $stmtProf = $pdo->prepare("SELECT full_name FROM investor_profiles WHERE user_id = ?");
    $stmtProf->execute([$userId]);
    $prof = $stmtProf->fetch();
    $investorName = $prof['full_name'] ?? 'Investor User #' . $userId;

    $agreement = getAgreementById($pdo, $agreementId);
    if (!$agreement) {
        return ['success' => false, 'error' => 'Selected investment agreement not found.'];
    }

    if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error' => 'Please enter a valid recipient email address.'];
    }

    // Generate secure single-use access token
    $token = bin2hex(random_bytes(24));

    try {
        $stmtIns = $pdo->prepare("
            INSERT INTO esign_requests (user_id, agreement_id, recipient_email, token, status, created_at)
            VALUES (?, ?, ?, ?, 'Sent', NOW())
        ");
        $stmtIns->execute([$userId, $agreementId, $recipientEmail, $token]);
        $requestId = $pdo->lastInsertId();

        // Build absolute single-use signing URL
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $signingUrl = $protocol . '://' . $host . '/digital-investor/esign_signing.php?token=' . $token;

        // Dispatch Email Service
        $emailRes = sendESignEmailRequest($recipientEmail, $investorName, $agreement['title'], $signingUrl, dirname(__DIR__) . '/' . $agreement['file_path']);

        if (isset($emailRes['success']) && $emailRes['success'] === false) {
            // Delete failed record from DB so user can retry cleanly
            $stmtDel = $pdo->prepare("DELETE FROM esign_requests WHERE id = ?");
            $stmtDel->execute([$requestId]);

            return [
                'success' => false,
                'error' => $emailRes['error'] ?? 'Failed to send eSign request email.'
            ];
        }

        // Create Admin Notification
        $isDemoStr = (!empty($emailRes['is_demo'])) ? 'Demo Mode' : 'Sent';
        createAdminNotification($pdo, $userId, 'esign_request_sent', "eSign request sent to " . $recipientEmail . " for agreement '" . $agreement['title'] . "' (" . $isDemoStr . ").");

        return [
            'success' => true,
            'request_id' => $requestId,
            'token' => $token,
            'signing_url' => $signingUrl,
            'email_res' => $emailRes
        ];

    } catch (Exception $e) {
        error_log("createESignRequest error: " . $e->getMessage());
        return ['success' => false, 'error' => 'Database error creating eSign request.'];
    }
}

/**
 * Fetch eSign request by secure token
 */
function getESignRequestByToken($pdo, $token) {
    $stmt = $pdo->prepare("
        SELECT r.*, a.title as agreement_title, a.category as agreement_category, a.file_path as agreement_file_path, a.description as agreement_description,
               u.email as investor_email, p.full_name as investor_name, app.application_id
        FROM esign_requests r
        JOIN investment_agreements a ON r.agreement_id = a.id
        JOIN users u ON r.user_id = u.id
        LEFT JOIN investor_profiles p ON u.id = p.user_id
        LEFT JOIN applications app ON u.id = app.user_id
        WHERE r.token = ?
    ");
    $stmt->execute([$token]);
    return $stmt->fetch();
}

/**
 * Fetch eSign requests for specific investor
 */
function getESignRequestsForUser($pdo, $userId) {
    $stmt = $pdo->prepare("
        SELECT r.*, a.title as agreement_title, a.category as agreement_category, a.file_path as agreement_file_path
        FROM esign_requests r
        JOIN investment_agreements a ON r.agreement_id = a.id
        WHERE r.user_id = ?
        ORDER BY r.created_at DESC
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

/**
 * Fetch all eSign requests for Admin Tracking
 */
function getAllESignRequests($pdo) {
    $stmt = $pdo->query("
        SELECT r.*, a.title as agreement_title, u.email as investor_email, p.full_name as investor_name, app.application_id
        FROM esign_requests r
        JOIN investment_agreements a ON r.agreement_id = a.id
        JOIN users u ON r.user_id = u.id
        LEFT JOIN investor_profiles p ON u.id = p.user_id
        LEFT JOIN applications app ON u.id = app.user_id
        ORDER BY r.created_at DESC
    ");
    return $stmt->fetchAll();
}

/**
 * Create eKYC request record and dispatch email
 */
function createEKYCRequest($pdo, $userId, $recipientEmail) {
    require_once __DIR__ . '/email_helper.php';
    require_once __DIR__ . '/notification_helper.php';

    $stmtProf = $pdo->prepare("SELECT full_name FROM investor_profiles WHERE user_id = ?");
    $stmtProf->execute([$userId]);
    $prof = $stmtProf->fetch();
    $investorName = $prof['full_name'] ?? 'Investor User #' . $userId;

    if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error' => 'Please enter a valid recipient email address.'];
    }

    $token = bin2hex(random_bytes(24));

    try {
        $stmtIns = $pdo->prepare("
            INSERT INTO ekyc_requests (user_id, recipient_email, token, status, created_at)
            VALUES (?, ?, ?, 'Sent', NOW())
        ");
        $stmtIns->execute([$userId, $recipientEmail, $token]);
        $requestId = $pdo->lastInsertId();

        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $submissionUrl = $protocol . '://' . $host . '/digital-investor/ekyc_submission.php?token=' . $token;

        $emailRes = sendEKYCEmailRequest($recipientEmail, $investorName, $submissionUrl);

        if (isset($emailRes['success']) && $emailRes['success'] === false) {
            $stmtDel = $pdo->prepare("DELETE FROM ekyc_requests WHERE id = ?");
            $stmtDel->execute([$requestId]);

            return [
                'success' => false,
                'error' => $emailRes['error'] ?? 'Failed to send eKYC request email.'
            ];
        }

        createAdminNotification($pdo, $userId, 'ekyc_request_sent', "eKYC submission email sent to " . $recipientEmail . ".");

        return [
            'success' => true,
            'request_id' => $requestId,
            'token' => $token,
            'submission_url' => $submissionUrl,
            'email_res' => $emailRes
        ];

    } catch (Exception $e) {
        error_log("createEKYCRequest error: " . $e->getMessage());
        return ['success' => false, 'error' => 'Database error creating eKYC request.'];
    }
}

/**
 * Fetch eKYC request by secure token
 */
function getEKYCRequestByToken($pdo, $token) {
    $stmt = $pdo->prepare("
        SELECT r.*, u.email as investor_email, p.full_name as investor_name, app.application_id
        FROM ekyc_requests r
        JOIN users u ON r.user_id = u.id
        LEFT JOIN investor_profiles p ON u.id = p.user_id
        LEFT JOIN applications app ON u.id = app.user_id
        WHERE r.token = ?
    ");
    $stmt->execute([$token]);
    return $stmt->fetch();
}





