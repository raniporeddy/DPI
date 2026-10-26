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
 * Check mandatory document status for an investor:
 * 1. Passport-size Photo (JPG, JPEG, PNG)
 * 2. Aadhaar Card (JPG, JPEG, PNG, PDF)
 * 3. ID Proof (JPG, JPEG, PNG, PDF)
 */
function checkUserRequiredDocuments($pdo, $userId) {
    $requiredKeys = ['Passport-size Photo', 'Aadhaar Card', 'ID Proof'];

    $stmt = $pdo->prepare("SELECT * FROM documents WHERE user_id = ? ORDER BY id DESC");
    $stmt->execute([$userId]);
    $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $latestDocs = [];
    foreach ($docs as $doc) {
        $type = $doc['doc_type'];
        if (in_array($type, $requiredKeys) && !isset($latestDocs[$type])) {
            $latestDocs[$type] = $doc;
        }
    }

    // Fallback detection for legacy records:
    // If Aadhaar Card not directly uploaded, check if doc_type has 'Aadhaar' or doc_name contains 'Aadhaar'
    if (!isset($latestDocs['Aadhaar Card'])) {
        foreach ($docs as $doc) {
            if (stripos($doc['doc_type'], 'Aadhaar') !== false || stripos($doc['doc_name'], 'Aadhaar') !== false) {
                $latestDocs['Aadhaar Card'] = $doc;
                break;
            }
        }
    }

    // If ID Proof not directly uploaded, check if an Identity Proof document exists (not already mapped to Aadhaar)
    if (!isset($latestDocs['ID Proof'])) {
        foreach ($docs as $doc) {
            if ($doc['doc_type'] === 'Identity Proof' && (empty($latestDocs['Aadhaar Card']) || $latestDocs['Aadhaar Card']['id'] !== $doc['id'])) {
                $latestDocs['ID Proof'] = $doc;
                break;
            }
        }
    }

    $uploaded = [];
    $missing = [];
    foreach ($requiredKeys as $key) {
        if (isset($latestDocs[$key])) {
            $uploaded[$key] = $latestDocs[$key];
        } else {
            $missing[] = $key;
        }
    }

    return [
        'all_completed' => empty($missing),
        'uploaded' => $uploaded,
        'missing' => $missing,
        'latest_docs' => $latestDocs,
        'count_uploaded' => count($uploaded),
        'total_required' => count($requiredKeys)
    ];
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
    $stmtKyc = $pdo->prepare("SELECT status, document_path FROM kyc_verification WHERE user_id = ?");
    $stmtKyc->execute([$userId]);
    $kyc = $stmtKyc->fetch();
    $kycCompleted = ($kyc && (in_array($kyc['status'], ['Submitted', 'Verified']) || ($kyc['status'] === 'Pending' && !empty($kyc['document_path'])))) ? 1 : 0;

    // 3. Check Documents (All 3 mandatory documents: Passport-size Photo, Aadhaar Card, ID Proof)
    $reqDocs = checkUserRequiredDocuments($pdo, $userId);
    $docsCompleted = $reqDocs['all_completed'] ? 1 : 0;

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
 * Validate and move uploaded file securely.
 * Returns detailed, user-friendly error messages for each failure case.
 *
 * Validation order:
 *  1. Presence check (empty / missing $_FILES entry)
 *  2. PHP upload error code (UPLOAD_ERR_* constants)
 *  3. Extension check — filename string only, safe before is_uploaded_file
 *  4. Size check     — superglobal integer, safe before is_uploaded_file
 *  5. Zero-byte guard — superglobal integer
 *  6. is_uploaded_file() — security guard before any filesystem I/O
 *  7. MIME-type check — reads file content, needs real file on disk
 *  8. Directory writable check
 *  9. move_uploaded_file()
 */
function handleSecureFileUpload($fileInput, $targetDir, $allowedExtensions = ['jpg', 'jpeg', 'png', 'pdf'], $maxSizeBytes = 5242880) {
    // 1. Presence check
    if (empty($fileInput) || !is_array($fileInput) || !isset($fileInput['error'])) {
        return ['success' => false, 'error' => 'No file was received by the server. Please select a file and try again.'];
    }

    // 2. PHP upload error codes → specific user-friendly messages
    $uploadErrMessages = [
        UPLOAD_ERR_OK         => '',
        UPLOAD_ERR_INI_SIZE   => 'The file is too large. Maximum allowed upload size on this server is ' . ini_get('upload_max_filesize') . '.',
        UPLOAD_ERR_FORM_SIZE  => 'The file exceeds the maximum size allowed by this form (5 MB).',
        UPLOAD_ERR_PARTIAL    => 'The file was only partially uploaded. Please check your connection and try again.',
        UPLOAD_ERR_NO_FILE    => 'No file was selected. Please choose your Aadhaar document and click Submit.',
        UPLOAD_ERR_NO_TMP_DIR => 'Server configuration error: temporary upload directory is missing. Please contact support.',
        UPLOAD_ERR_CANT_WRITE => 'Server error: failed to write the uploaded file to disk. Please contact support.',
        UPLOAD_ERR_EXTENSION  => 'A server extension stopped the upload. Please contact support.',
    ];
    $errCode = (int) $fileInput['error'];
    if ($errCode !== UPLOAD_ERR_OK) {
        $msg = $uploadErrMessages[$errCode]
            ?? 'File upload failed (error code ' . $errCode . '). Please try again.';
        return ['success' => false, 'error' => $msg];
    }

    // 3. Extension validation (reads only filename string — safe before is_uploaded_file)
    $fileExt = strtolower(pathinfo($fileInput['name'], PATHINFO_EXTENSION));
    if (!in_array($fileExt, $allowedExtensions, true)) {
        return ['success' => false, 'error' =>
            'Invalid file format “' . htmlspecialchars($fileExt, ENT_QUOTES) . '”. '
            . 'Accepted formats: ' . strtoupper(implode(', ', $allowedExtensions)) . '.'
        ];
    }

    // 4. Application file-size limit (reads only superglobal integer — safe before is_uploaded_file)
    if ($fileInput['size'] > $maxSizeBytes) {
        return ['success' => false, 'error' =>
            'File size (' . number_format($fileInput['size'] / (1024 * 1024), 2) . ' MB) '
            . 'exceeds the maximum allowed limit of ' . ($maxSizeBytes / (1024 * 1024)) . ' MB.'
        ];
    }

    // 5. Zero-byte guard (reads only superglobal integer)
    if ($fileInput['size'] === 0) {
        return ['success' => false, 'error' => 'The uploaded file is empty (0 bytes). Please select a valid document.'];
    }

    // 6. is_uploaded_file() — security guard; prevents path-injection attacks.
    //    Runs before any filesystem I/O (MIME reading, move_uploaded_file).
    if (!is_uploaded_file($fileInput['tmp_name'])) {
        return ['success' => false, 'error' => 'Security check failed: the file was not uploaded via HTTP POST.'];
    }

    // 7. MIME-type check (reads actual file content — requires real tmp file on disk)
    $finfo    = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $fileInput['tmp_name']);
    finfo_close($finfo);
    $allowedMimes = ['image/jpeg', 'image/png', 'application/pdf'];
    if (!in_array($mimeType, $allowedMimes, true)) {
        return ['success' => false, 'error' =>
            'Security check failed: file content does not match an accepted image or PDF '
            . '(detected: ' . htmlspecialchars($mimeType, ENT_QUOTES) . '). '
            . 'Please upload a genuine Aadhaar card image or PDF.'
        ];
    }

    // 8. Target directory
    if (!is_dir($targetDir)) {
        if (!mkdir($targetDir, 0755, true)) {
            return ['success' => false, 'error' => 'Server error: could not create upload directory. Please contact support.'];
        }
    }
    if (!is_writable($targetDir)) {
        return ['success' => false, 'error' => 'Server error: upload directory is not writable. Please contact support.'];
    }

    // 9. Move to permanent location
    $newFilename = uniqid('doc_', true) . '.' . $fileExt;
    $targetPath  = rtrim($targetDir, '/\\') . DIRECTORY_SEPARATOR . $newFilename;
    if (move_uploaded_file($fileInput['tmp_name'], $targetPath)) {
        return [
            'success'       => true,
            'file_name'     => $fileInput['name'],
            'file_path'     => str_replace('\\', '/', $targetPath),
            'relative_path' => str_replace('\\', '/', str_replace(dirname(__DIR__), '', $targetPath)),
            'file_size'     => $fileInput['size'],
        ];
    }
    return ['success' => false, 'error' => 'Failed to move uploaded file to destination. Ensure the upload directory is writable.'];
}

/**
 * Automatically process uploaded Aadhaar / ID document offline:
 * 1. Extract face photo using local OpenCV (YuNet/Haar Cascades).
 * 2. Extract OCR text (Name, DOB, ID Number, Mobile, Address) using local EasyOCR.
 */
function processKYCDocumentDetailed($uploadedFilePath, $targetPhotoDir = '', $userId = 0, $submittedFullName = '') {
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
            $cmd = '"' . $pyExe . '" "' . $pyScript . '" "' . $uploadedFilePath . '" "' . $targetPhotoDir . '" ' . (int)$userId . ' "' . addslashes($submittedFullName) . '"';
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
        'ocr_name' => null,
        'dob' => null,
        'dob_formatted' => null,
        'id_number' => null,
        'gender' => null,
        'ocr_gender' => null,
        'mobile' => null,
        'ocr_mobile' => null,
        'address' => null,
        'ocr_address' => null,
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
function createEKYCRequest($pdo, $userId, $recipientEmail, $validityHours = 24) {
    require_once __DIR__ . '/email_helper.php';
    require_once __DIR__ . '/notification_helper.php';

    $stmtProf = $pdo->prepare("SELECT full_name FROM investor_profiles WHERE user_id = ?");
    $stmtProf->execute([$userId]);
    $prof = $stmtProf->fetch();
    $investorName = $prof['full_name'] ?? 'Investor User #' . $userId;

    if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error' => 'Please enter a valid recipient email address.'];
    }

    // 64-character secure random token
    $token = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', time() + ($validityHours * 3600));

    try {
        $stmtIns = $pdo->prepare("
            INSERT INTO ekyc_requests (user_id, recipient_email, token, status, expires_at, created_at)
            VALUES (?, ?, ?, 'Sent', ?, NOW())
        ");
        $stmtIns->execute([$userId, $recipientEmail, $token, $expiresAt]);
        $requestId = $pdo->lastInsertId();

        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $submissionUrl = $protocol . '://' . $host . '/digital-investor/ekyc_submission.php?token=' . $token;

        $emailRes = sendEKYCEmailRequest($recipientEmail, $investorName, $submissionUrl, $expiresAt);

        if (isset($emailRes['success']) && $emailRes['success'] === false) {
            $stmtDel = $pdo->prepare("DELETE FROM ekyc_requests WHERE id = ?");
            $stmtDel->execute([$requestId]);

            return [
                'success' => false,
                'error' => $emailRes['error'] ?? 'Failed to send eKYC request email.'
            ];
        }

        createAdminNotification($pdo, $userId, 'ekyc_request_sent', "eKYC verification request email sent to " . $recipientEmail . " (Expires: " . date('M d, H:i', strtotime($expiresAt)) . ").");

        return [
            'success' => true,
            'request_id' => $requestId,
            'token' => $token,
            'expires_at' => $expiresAt,
            'submission_url' => $submissionUrl,
            'email_res' => $emailRes
        ];

    } catch (Exception $e) {
        error_log("createEKYCRequest error: " . $e->getMessage());
        return ['success' => false, 'error' => 'Database error creating eKYC request.'];
    }
}

/**
 * Fetch latest eKYC request for a specific user
 */
function getLatestEKYCRequestForUser($pdo, $userId) {
    $stmt = $pdo->prepare("
        SELECT * FROM ekyc_requests 
        WHERE user_id = ? 
        ORDER BY id DESC 
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    return $stmt->fetch();
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

/**
 * Securely save OCR-extracted identity document details into a structured XML file.
 * Stored in storage/ekyc_xml/ with access restricted to prevent direct web viewing.
 *
 * @param int $userId Investor User ID
 * @param array $details Associative array containing: full_name, dob, gender, id_number, address, mobile, photo_path
 * @return string Relative path to saved XML file
 */
function saveIdentityDocumentXml(int $userId, array $details): string {
    $projectRoot = dirname(__DIR__);
    $xmlDir = $projectRoot . '/storage/ekyc_xml';

    if (!is_dir($xmlDir)) {
        mkdir($xmlDir, 0750, true);
        // Protect directory from direct HTTP access
        $htaccessContent = "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n";
        file_put_contents($xmlDir . '/.htaccess', $htaccessContent);
        file_put_contents($xmlDir . '/index.html', '');
    }

    $fileName = 'investor_' . (int)$userId . '.xml';
    $xmlFullPath = $xmlDir . '/' . $fileName;
    $relativeXmlPath = 'storage/ekyc_xml/' . $fileName;

    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->formatOutput = true;

    $root = $dom->createElement('IdentityDocument');
    $dom->appendChild($root);

    // Clean inputs: strip out any raw entity codes like &#xA;, &#xA0;, &nbsp; or unneeded carriage returns
    $cleanVal = function($val) {
        $str = trim((string)$val);
        $str = str_replace(["&#xA;", "&#xa;", "&#xA0;", "&#xa0;", "&nbsp;", "\r"], [" ", " ", " ", " ", " ", ""], $str);
        $str = preg_replace('/\s+/', ' ', $str);
        return trim($str);
    };

    $fields = [
        'FullName'       => $cleanVal($details['full_name'] ?? $details['ocr_name'] ?? ''),
        'DateOfBirth'    => $cleanVal($details['dob'] ?? $details['ocr_dob'] ?? ''),
        'Gender'         => $cleanVal($details['gender'] ?? $details['ocr_gender'] ?? ''),
        'Address'        => $cleanVal($details['address'] ?? $details['ocr_address'] ?? ''),
        'MobileNumber'   => $cleanVal($details['mobile'] ?? $details['mobile_number'] ?? $details['ocr_mobile'] ?? ''),
        'IDNumber'       => $cleanVal($details['id_number'] ?? $details['ocr_id_number'] ?? ''),
        'PhotographPath' => $cleanVal($details['photo_path'] ?? $details['photograph_path'] ?? '')
    ];

    foreach ($fields as $key => $val) {
        $node = $dom->createElement($key);
        $node->appendChild($dom->createTextNode($val));
        $root->appendChild($node);
    }

    $dom->save($xmlFullPath);
    return $relativeXmlPath;
}

/**
 * Read and parse an investor's Identity Document XML file securely using PHP SimpleXML.
 * Implements XXE prevention (disables entity loader and external DTDs).
 *
 * @param int $userId Logged-in investor user ID
 * @return array|null Associative array of extracted details, or null if no valid XML exists
 */
function readIdentityDocumentXmlSecure(int $userId): ?array {
    $projectRoot = dirname(__DIR__);
    $xmlFullPath = $projectRoot . '/storage/ekyc_xml/investor_' . (int)$userId . '.xml';

    if (!file_exists($xmlFullPath) || !is_readable($xmlFullPath)) {
        return null;
    }

    $xmlContent = file_get_contents($xmlFullPath);
    if ($xmlContent === false || trim($xmlContent) === '') {
        return null;
    }

    // Clean any XML entities or literal entities before parsing
    $xmlContent = str_replace(["&#xA;", "&#xa;", "&#xA0;", "&#xa0;"], [" ", " ", " ", " "], $xmlContent);

    // XXE Protection: Disable external entity resolution
    $prevEntityLoader = libxml_disable_entity_loader(true);
    libxml_use_internal_errors(true);

    $xml = simplexml_load_string($xmlContent, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOENT);
    libxml_disable_entity_loader($prevEntityLoader);

    if ($xml === false) {
        libxml_clear_errors();
        return null;
    }

    $cleanStr = function($val) {
        $str = trim((string)$val);
        $str = str_replace(["&#xA;", "&#xa;", "&#xA0;", "&#xa0;", "&nbsp;"], [" ", " ", " ", " ", " "], $str);
        $str = preg_replace('/\s+/', ' ', $str);
        return trim($str);
    };

    $fullName   = $cleanStr($xml->FullName ?? '');
    $dob        = $cleanStr($xml->DateOfBirth ?? '');
    $gender     = $cleanStr($xml->Gender ?? '');
    $address    = $cleanStr($xml->Address ?? '');
    $mobile     = $cleanStr($xml->MobileNumber ?? $xml->Mobile ?? '');
    $idNumber   = $cleanStr($xml->IDNumber ?? '');
    $photoPath  = $cleanStr($xml->PhotographPath ?? '');

    return [
        'full_name'       => $fullName !== '' ? $fullName : null,
        'dob'             => $dob !== '' ? $dob : null,
        'gender'          => $gender !== '' ? $gender : null,
        'address'         => $address !== '' ? $address : null,
        'mobile'          => $mobile !== '' ? $mobile : null,
        'id_number'       => $idNumber !== '' ? $idNumber : null,
        'photograph_path' => $photoPath !== '' ? $photoPath : null,
        'xml_file_path'   => 'storage/ekyc_xml/investor_' . (int)$userId . '.xml'
    ];
}






