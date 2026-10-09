<?php
// includes/captcha_helper.php
// Academic Demo CAPTCHA Verification Helper

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Generate random CAPTCHA string and store in session
 */
function generateCaptchaCode($length = 5) {
    $chars = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    $code = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= $chars[mt_rand(0, strlen($chars) - 1)];
    }
    $_SESSION['captcha_code'] = $code;
    return $code;
}

/**
 * Get current active CAPTCHA code or generate new one
 */
function getCaptchaCode() {
    if (empty($_SESSION['captcha_code'])) {
        return generateCaptchaCode();
    }
    return $_SESSION['captcha_code'];
}

/**
 * Server-side validation of submitted CAPTCHA
 */
function validateCaptchaCode($inputCode) {
    if (empty($_SESSION['captcha_code']) || empty($inputCode)) {
        return false;
    }
    $isValid = (strtoupper(trim($inputCode)) === strtoupper(trim($_SESSION['captcha_code'])));
    // Regenerate code after validation attempt for security
    generateCaptchaCode();
    return $isValid;
}

/**
 * Render Bootstrap CAPTCHA input field widget with refresh button
 */
function renderCaptchaWidget() {
    $code = getCaptchaCode();
    return '
    <div class="mb-3">
        <label for="captcha_input" class="form-label fw-semibold">Security Verification (CAPTCHA) <span class="text-danger">*</span></label>
        <div class="d-flex align-items-center gap-2 mb-2">
            <div class="captcha-box bg-dark text-warning font-monospace fw-bold fs-4 px-3 py-1-5 rounded-3 tracking-widest text-center shadow-sm select-none" style="letter-spacing: 6px; user-select: none; min-width: 140px;">
                ' . e($code) . '
            </div>
            <button type="button" class="btn btn-outline-secondary btn-sm" id="refreshCaptchaBtn" title="Refresh CAPTCHA Code" onclick="refreshCaptchaCode()">
                <i class="fas fa-sync-alt"></i> Refresh
            </button>
        </div>
        <input type="text" class="form-control text-uppercase font-monospace" id="captcha_input" name="captcha_input" required placeholder="Enter 5-character code shown above" autocomplete="off" maxlength="6">
        <small class="text-muted fs-8"><i class="fas fa-shield-alt me-1 text-primary"></i> Academic prototype CAPTCHA verification</small>
    </div>
    <script>
    function refreshCaptchaCode() {
        fetch("/digital-investor/captcha.php?action=refresh")
            .then(res => res.json())
            .then(data => {
                var box = document.querySelector(".captcha-box");
                if (box && data.code) {
                    box.textContent = data.code;
                }
            })
            .catch(err => console.error("CAPTCHA refresh error:", err));
    }
    </script>';
}
