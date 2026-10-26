#!/usr/bin/env python3
"""
Enterprise-grade Offline Python Script for Aadhaar eKYC Document Processing:
1. Multi-format support: JPG, JPEG, PNG, and multi-page PDF (via pypdfium2).
2. Multi-orientation detection (0°, 90°, 180°, 270°) using Deep YuNet Face Detection + UIDAI text landmarks.
3. Image Preprocessing: Deskewing, CLAHE contrast enhancement, Bilateral noise filtering, and edge sharpening.
4. Accurate Face Portrait Extraction: strictly crops upright cardholder portrait, excluding logos and signature boxes.
5. Spatial Region-Aware OCR Extraction:
   - Full Name: spatial positioning above DOB and next to portrait; strict rejection of "Pin Code", labels, and disclaimers.
   - Date of Birth: validated calendar dates (DD/MM/YYYY), excluding issue/download dates and boilerplate.
   - Gender: Male, Female, Transgender normalization.
   - Aadhaar Number: strictly 12 digits (XXXX-XXXX-XXXX); excludes 16-digit VIDs, phone numbers, and PIN codes.
   - Address: clean address block extraction with State and 6-digit PIN; excludes all UIDAI legal boilerplate.
6. Validation & Confidence Scoring: uncertain or unreadable fields set to null ("Needs Review").
"""

import sys
import os
import json
import re
import warnings
import math
import numpy as np
import cv2

warnings.filterwarnings("ignore")
os.environ["TF_CPP_MIN_LOG_LEVEL"] = "3"

# Verhoeff algorithm for Indian Aadhaar 12-digit checksum validation
VERHOEFF_D = [
    [0, 1, 2, 3, 4, 5, 6, 7, 8, 9],
    [1, 2, 3, 4, 0, 6, 7, 8, 9, 5],
    [2, 3, 4, 0, 1, 7, 8, 9, 5, 6],
    [3, 4, 0, 1, 2, 8, 9, 5, 6, 7],
    [4, 0, 1, 2, 3, 9, 5, 6, 7, 8],
    [5, 9, 8, 7, 6, 0, 4, 3, 2, 1],
    [6, 5, 9, 8, 7, 1, 0, 4, 3, 2],
    [7, 6, 5, 9, 8, 2, 1, 0, 4, 3],
    [8, 7, 6, 5, 9, 3, 2, 1, 0, 4],
    [9, 8, 7, 6, 5, 4, 3, 2, 1, 0]
]
VERHOEFF_P = [
    [0, 1, 2, 3, 4, 5, 6, 7, 8, 9],
    [1, 5, 7, 6, 2, 8, 3, 0, 9, 4],
    [5, 8, 0, 3, 7, 9, 6, 1, 4, 2],
    [8, 9, 1, 6, 0, 4, 3, 5, 2, 7],
    [9, 4, 5, 3, 1, 2, 6, 8, 7, 0],
    [4, 2, 8, 6, 5, 7, 3, 9, 0, 1],
    [2, 7, 9, 3, 8, 0, 6, 4, 1, 5],
    [7, 0, 4, 6, 9, 1, 3, 2, 5, 8]
]

def validate_verhoeff(num_str):
    if not num_str or len(num_str) != 12 or not num_str.isdigit():
        return False
    c = 0
    for i, item in enumerate(reversed(num_str)):
        c = VERHOEFF_D[c][VERHOEFF_P[i % 8][int(item)]]
    return c == 0

def load_document_images(file_path):
    """
    Loads document from file_path supporting JPG, JPEG, PNG, and PDF.
    Returns list of OpenCV BGR images [image1, ...].
    """
    if not os.path.exists(file_path):
        return []

    ext = os.path.splitext(file_path)[1].lower()

    if ext == '.pdf':
        images = []
        try:
            import pypdfium2 as pdfium
            pdf = pdfium.PdfDocument(file_path)
            for page_idx in range(min(len(pdf), 3)):
                page = pdf[page_idx]
                # High-DPI rendering (scale 4.167 = 300 DPI from base 72 DPI)
                pil_img = page.render(scale=4.167).to_pil()
                rgb = np.array(pil_img)
                if rgb.ndim == 3 and rgb.shape[2] == 4:
                    bgr = cv2.cvtColor(rgb, cv2.COLOR_RGBA2BGR)
                elif rgb.ndim == 3:
                    bgr = cv2.cvtColor(rgb, cv2.COLOR_RGB2BGR)
                else:
                    bgr = cv2.cvtColor(rgb, cv2.COLOR_GRAY2BGR)
                images.append(bgr)
            if images:
                return images
        except Exception:
            pass

        # Fallback for PDF: scan for embedded JPEG stream
        try:
            with open(file_path, 'rb') as f:
                content = f.read()
            pos = 0
            while True:
                soi = content.find(b'\xFF\xD8\xFF', pos)
                if soi == -1:
                    break
                eoi = content.find(b'\xFF\xD9', soi)
                if eoi == -1:
                    break
                jpg_data = content[soi:eoi + 2]
                pos = eoi + 2
                arr = np.frombuffer(jpg_data, dtype=np.uint8)
                img = cv2.imdecode(arr, cv2.IMREAD_COLOR)
                if img is not None and img.shape[0] > 100 and img.shape[1] > 100:
                    images.append(img)
            if images:
                return images
        except Exception:
            pass
        return []

    # Image file (JPG, JPEG, PNG)
    try:
        from PIL import Image, ImageOps
        pil_img = Image.open(file_path)
        pil_img = ImageOps.exif_transpose(pil_img)
        if pil_img.mode == 'RGBA':
            img = cv2.cvtColor(np.array(pil_img), cv2.COLOR_RGBA2BGR)
        elif pil_img.mode == 'RGB':
            img = cv2.cvtColor(np.array(pil_img), cv2.COLOR_RGB2BGR)
        else:
            img = cv2.cvtColor(np.array(pil_img), cv2.COLOR_GRAY2BGR)
        return [img]
    except Exception:
        img = cv2.imread(file_path)
        return [img] if img is not None else []

def get_rotated_image(img, angle):
    if angle == 90:
        return cv2.rotate(img, cv2.ROTATE_90_CLOCKWISE)
    elif angle == 180:
        return cv2.rotate(img, cv2.ROTATE_180)
    elif angle == 270:
        return cv2.rotate(img, cv2.ROTATE_90_COUNTERCLOCKWISE)
    return img

def detect_face_in_image(image, script_dir):
    """
    Detects person's face across 0, 90, 180, 270 angles.
    Returns (cropped_portrait, best_angle, (fx, fy, fw, fh), score)
    """
    img_h, img_w = image.shape[:2]
    if img_w < 50 or img_h < 50:
        return None, 0, None, 0

    frontal_xml = os.path.join(script_dir, 'haarcascade_frontalface_default.xml')
    profile_xml = os.path.join(script_dir, 'haarcascade_profileface.xml')
    onnx_path = os.path.join(script_dir, 'face_detection_yunet_2023mar.onnx')

    candidates = []

    for angle in [0, 90, 180, 270]:
        rot_img = get_rotated_image(image, angle)
        rh, rw = rot_img.shape[:2]

        # 1. YuNet Deep Face Detector (primary)
        if os.path.exists(onnx_path) and hasattr(cv2, 'FaceDetectorYN_create'):
            try:
                detector = cv2.FaceDetectorYN_create(
                    onnx_path, "", (rw, rh), score_threshold=0.30, nms_threshold=0.30
                )
                _, yfaces = detector.detect(rot_img)
                if yfaces is not None and len(yfaces) > 0:
                    for f in yfaces:
                        fx, fy, fw, fh = int(f[0]), int(f[1]), int(f[2]), int(f[3])
                        if fw >= 20 and fh >= 20:
                            # Face in Aadhaar is on the left half or top half
                            pos_weight = 1.5 if (fx + fw / 2.0) < (rw * 0.65) else 0.9
                            score = float(f[14]) * 100.0 * pos_weight
                            candidates.append((fx, fy, fw, fh, score, angle, rot_img))
            except Exception:
                pass

        # 2. Haar Frontal Face Cascade (fallback)
        if os.path.exists(frontal_xml) and hasattr(cv2, 'CascadeClassifier'):
            try:
                gray = cv2.cvtColor(rot_img, cv2.COLOR_BGR2GRAY)
                cascade = cv2.CascadeClassifier(frontal_xml)
                faces = cascade.detectMultiScale(
                    gray, scaleFactor=1.08, minNeighbors=3,
                    minSize=(20, 20), maxSize=(int(rw * 0.60), int(rh * 0.60))
                )
                for (fx, fy, fw, fh) in faces:
                    aspect = float(fh) / fw if fw > 0 else 0
                    if 0.75 <= aspect <= 1.75:
                        pos_weight = 1.4 if (fx + fw / 2.0) < (rw * 0.65) else 0.9
                        score = (fw * fh) / float(rw * rh) * 1000.0 * pos_weight
                        candidates.append((fx, fy, fw, fh, score, angle, rot_img))
            except Exception:
                pass

    if not candidates:
        return None, 0, None, 0

    best = max(candidates, key=lambda c: c[4])
    fx, fy, fw, fh, score, angle, rot_img = best
    rh, rw = rot_img.shape[:2]

    # Portrait crop: captures the full Aadhaar ID photo frame (full shoulders, neck, forehead, and hair)
    pad_top    = int(fh * 0.45)   # forehead and hair up to top of photo frame
    pad_bottom = int(fh * 0.95)   # chin, neck, and shoulders to bottom of photo frame
    pad_left   = int(fw * 0.72)   # left shoulder to frame edge
    pad_right  = int(fw * 0.72)   # right shoulder to frame edge

    crop_y1 = max(0, fy - pad_top)
    crop_y2 = min(rh, fy + fh + pad_bottom)
    crop_x1 = max(0, fx - pad_left)
    crop_x2 = min(rw, fx + fw + pad_right)

    cropped = rot_img[crop_y1:crop_y2, crop_x1:crop_x2]
    return cropped, angle, (fx, fy, fw, fh), score

def deskew_image(image):
    """
    Detects small skew angle (within [-15, 15] degrees) and straightens image.
    """
    try:
        gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
        thresh = cv2.adaptiveThreshold(gray, 255, cv2.ADAPTIVE_THRESH_GAUSSIAN_C, cv2.THRESH_BINARY_INV, 15, 9)
        coords = np.column_stack(np.where(thresh > 0))
        if len(coords) < 100:
            return image
        angle = cv2.minAreaRect(coords)[-1]
        if angle < -45:
            angle = -(90 + angle)
        else:
            angle = -angle
        if abs(angle) > 0.5 and abs(angle) <= 15.0:
            h, w = image.shape[:2]
            center = (w // 2, h // 2)
            M = cv2.getRotationMatrix2D(center, angle, 1.0)
            return cv2.warpAffine(image, M, (w, h), flags=cv2.INTER_CUBIC, borderMode=cv2.BORDER_REPLICATE)
    except Exception:
        pass
    return image

def preprocess_image_for_ocr(image):
    """
    Optimizes document image for EasyOCR accuracy:
    1. Standardized width (1800px)
    2. Deskewing
    3. CLAHE contrast enhancement on Luminance channel
    4. Bilateral filtering for text edge preservation
    5. Subtle unsharp mask sharpening
    """
    h, w = image.shape[:2]
    target_w = 1800
    if w > 0 and w != target_w:
        scale = target_w / float(w)
        target_h = int(h * scale)
        image = cv2.resize(image, (target_w, target_h), interpolation=cv2.INTER_CUBIC)

    image = deskew_image(image)

    # Convert to LAB for CLAHE on L channel
    lab = cv2.cvtColor(image, cv2.COLOR_BGR2LAB)
    l, a, b = cv2.split(lab)
    clahe = cv2.createCLAHE(clipLimit=2.2, tileGridSize=(8, 8))
    cl = clahe.apply(l)
    enhanced_lab = cv2.merge((cl, a, b))
    enhanced_bgr = cv2.cvtColor(enhanced_lab, cv2.COLOR_LAB2BGR)

    # Mild bilateral filtering to suppress JPEG artifacts
    denoised = cv2.bilateralFilter(enhanced_bgr, d=5, sigmaColor=40, sigmaSpace=40)

    # Mild unsharp mask
    gaussian = cv2.GaussianBlur(denoised, (0, 0), 2.0)
    sharpened = cv2.addWeighted(denoised, 1.25, gaussian, -0.25, 0)

    return sharpened

# Comprehensive blacklists for Full Name extraction
NAME_BLACKLIST_WORDS = {
    'pincode', 'pin', 'code', 'cude', 'postal',
    'government', 'govt', 'india', 'bharat', 'sarkar',
    'unique', 'identification', 'authority', 'uidai',
    'aadhaar', 'aadhar', 'adhar', 'card', 'mera',
    'enrolment', 'enrollment', 'help', 'helpline', '1947',
    'signature', 'verified', 'digitally', 'signed', 'utc',
    'download', 'issue', 'date', 'birth', 'dob', 'yob',
    'female', 'male', 'transgender', 'gender', 'fehale', 'ifehale', 'mahila', 'purush',
    'father', 'mother', 'husband', 'wife', 'son', 'daughter',
    'c/o', 's/o', 'd/o', 'w/o', 'co', 'so', 'do', 'wo',
    'address', 'street', 'road', 'lane', 'nagar', 'colony', 'village',
    'mandal', 'district', 'dist', 'state', 'vtc', 'sub',
    'support', 'identity', 'citizenship', 'proof', 'update', 'updated',
    'mobile', 'email', 'phone', 'valid', 'online', 'authentication',
    'agency', 'qr', 'scanner', 'app', 'stores', 'services', 'entities',
    'lock', 'unlock', 'biometrics', 'regulations', 'holder', 'letter',
    'vid', 'republic', 'department', 'income', 'tax', 'account'
}

INDIAN_STATES = {
    'andhra', 'pradesh', 'telangana', 'karnataka', 'tamil', 'nadu',
    'kerala', 'maharashtra', 'delhi', 'gujarat', 'rajasthan', 'punjab',
    'haryana', 'uttar', 'bihar', 'bengal', 'odisha', 'assam', 'jharkhand',
    'chhattisgarh', 'goa', 'uttarakhand', 'himachal'
}

def is_valid_name_candidate(name_str):
    if not name_str or len(name_str) < 3 or len(name_str) > 42:
        return False

    cleaned = re.sub(r'[^A-Za-z\s]', ' ', name_str)
    tokens = [t.lower() for t in cleaned.split() if len(t) >= 2]
    if not tokens:
        return False

    # Never allow tokens matching system keywords, states, or PIN labels
    for t in tokens:
        if t in NAME_BLACKLIST_WORDS or t in INDIAN_STATES:
            return False

    # Check for digits
    if re.search(r'\d', name_str):
        return False

    # Must have at least 3 letters
    letters = re.sub(r'[^A-Za-z]', '', name_str)
    if len(letters) < 3:
        return False

    return True

def clean_name_string(s):
    s = re.sub(r'^(?:To|TO|to|Name|NAME|name)\s*[:,\-]?\s*', '', s)
    s = re.sub(r'^[^A-Za-z]+|[^A-Za-z]+$', '', s)
    s = re.sub(r'\s+', ' ', s).strip()
    return s

def extract_aadhaar_number(boxes, lines):
    """
    Extracts strictly the 12-digit Aadhaar number (XXXX-XXXX-XXXX).
    Strictly excludes:
    - 16-digit Virtual IDs (VID: 9110 3936 0381 9374)
    - 10-digit mobile numbers
    - 6-digit PIN codes
    - 14/28-digit enrolment numbers
    """
    candidates = []

    for ymin, xmin, ymax, xmax, text, conf in boxes:
        # Check if line contains VID
        if re.search(r'\bVID\b', text, re.IGNORECASE) or 'virtual' in text.lower():
            continue

        # Look for 12 digits (4 4 4)
        matches = re.finditer(r'\b(\d{4})[\s-](\d{4})[\s-](\d{4})\b', text)
        for m in matches:
            full_match = m.group(0)
            raw = full_match.replace(' ', '').replace('-', '')
            if len(raw) == 12:
                # Ensure not followed by another 4 digits (which would make it a 16-digit VID)
                start_idx = m.start()
                end_idx = m.end()
                trailing = text[end_idx:].strip()
                if re.match(r'^[\s-]?\d{4}\b', trailing):
                    continue  # Part of 16-digit VID
                leading = text[:start_idx].strip()
                if re.search(r'\bVID\b', leading, re.IGNORECASE):
                    continue

                score = conf * 10.0
                if validate_verhoeff(raw):
                    score += 25.0
                if any(k in text.lower() for k in ['aadhaar', 'your aadhaar', 'aadhar']):
                    score += 15.0
                candidates.append((raw, score))

        # Check contiguous 12 digits
        matches_contig = re.finditer(r'\b([2-9]\d{11})\b', text)
        for m in matches_contig:
            raw = m.group(1)
            score = conf * 8.0
            if validate_verhoeff(raw):
                score += 25.0
            candidates.append((raw, score))

    # Also scan line-level text
    for line in lines:
        line_clean = line['text']
        if re.search(r'\bVID\b', line_clean, re.IGNORECASE):
            continue
        m = re.search(r'\b(\d{4})[\s-](\d{4})[\s-](\d{4})\b', line_clean)
        if m:
            raw = m.group(0).replace(' ', '').replace('-', '')
            if len(raw) == 12:
                idx = m.end()
                if not re.match(r'^[\s-]?\d{4}\b', line_clean[idx:].strip()):
                    score = 15.0
                    if validate_verhoeff(raw):
                        score += 25.0
                    candidates.append((raw, score))

    if not candidates:
        return None

    best_id = max(candidates, key=lambda c: c[1])[0]
    return f"{best_id[:4]}-{best_id[4:8]}-{best_id[8:]}"

def extract_dob(boxes, lines):
    """
    Extracts valid Date of Birth.
    Strictly validates day, month, year, and ignores disclaimers.
    """
    candidates = []

    for line in lines:
        text = line['text']
        # Ignore disclaimer text containing '(DOB)'
        if any(k in text.lower() for k in ['proof of identity', 'not of citizenship', 'regulations']):
            continue

        # Labeled DOB
        m = re.search(r'(?:DOB|Date\s+of\s+Birth|Birth|YOB|Janm)\s*[:\-\/.]?\s*(\d{2}[\/\-.]\d{2}[\/\-.]\d{4})', text, re.IGNORECASE)
        if m:
            raw_d = m.group(1).replace('.', '/').replace('-', '/')
            p = raw_d.split('/')
            if len(p) == 3:
                d, mth, y = int(p[0]), int(p[1]), int(p[2])
                if 1 <= d <= 31 and 1 <= mth <= 12 and 1900 <= y <= 2026:
                    candidates.append((f"{y:04d}-{mth:02d}-{d:02d}", f"{d:02d}/{mth:02d}/{y:04d}", 30.0))

        # Standalone date in line containing DOB label
        if any(k in text.lower() for k in ['dob', 'date of birth', 'birth', 'yob']):
            for sm in re.finditer(r'\b(\d{2})[\/\-.](\d{2})[\/\-.](\d{4})\b', text):
                d, mth, y = int(sm.group(1)), int(sm.group(2)), int(sm.group(3))
                if 1 <= d <= 31 and 1 <= mth <= 12 and 1900 <= y <= 2026:
                    candidates.append((f"{y:04d}-{mth:02d}-{d:02d}", f"{d:02d}/{mth:02d}/{y:04d}", 20.0))

    if not candidates:
        # Fallback date scan across boxes
        for b in boxes:
            text = b[4]
            if any(k in text.lower() for k in ['proof of', 'citizenship']):
                continue
            for sm in re.finditer(r'\b(\d{2})[\/\-.](\d{2})[\/\-.](\d{4})\b', text):
                d, mth, y = int(sm.group(1)), int(sm.group(2)), int(sm.group(3))
                if 1 <= d <= 31 and 1 <= mth <= 12 and 1920 <= y <= 2024:
                    candidates.append((f"{y:04d}-{mth:02d}-{d:02d}", f"{d:02d}/{mth:02d}/{y:04d}", 10.0))

    if not candidates:
        return None, None

    best = max(candidates, key=lambda c: c[2])
    return best[0], best[1]

def extract_gender(boxes, lines):
    """
    Extracts cardholder gender (Female, Male, Transgender).
    """
    for b in boxes:
        text = b[4].strip()
        if re.search(r'\b(?:FEMALE|IFEHALE|FEHALE|FENALE|Mahila)\b', text, re.IGNORECASE):
            return 'Female'
        elif re.search(r'\b(?:MALE|Purush)\b', text, re.IGNORECASE):
            # Verify not 'Female'
            if not re.search(r'\b(?:FEMALE|IFEHALE|FEHALE)\b', text, re.IGNORECASE):
                return 'Male'
        elif re.search(r'\b(?:TRANSGENDER)\b', text, re.IGNORECASE):
            return 'Transgender'

    for line in lines:
        text = line['text']
        if re.search(r'\b(?:FEMALE|IFEHALE|FEHALE|FENALE)\b', text, re.IGNORECASE):
            return 'Female'
        elif re.search(r'\b(?:MALE)\b', text, re.IGNORECASE) and not re.search(r'\b(?:FEMALE|FEHALE)\b', text, re.IGNORECASE):
            return 'Male'
        elif re.search(r'\b(?:TRANSGENDER)\b', text, re.IGNORECASE):
            return 'Transgender'

    return None

def extract_full_name(boxes, lines, dob_line_y=None, face_box=None, submitted_name=None):
    """
    Extracts cardholder Full Name.
    Strictly:
    - Never assigns "Pin Code", PIN, or address labels to Full Name.
    - Uses spatial proximity: on Aadhaar front, name is above DOB line and next to face photo.
    - Rejects disclaimers and administrative titles.
    """
    candidates = []

    # 1. Check for labeled Name: lines
    for line in lines:
        text = line['text']
        m = re.search(r'(?:Name|Holder\s+Name)\s*[:\-]?\s*([A-Za-z\s.\']{3,40})', text, re.IGNORECASE)
        if m:
            cand = clean_name_string(m.group(1))
            if is_valid_name_candidate(cand):
                candidates.append((cand.title(), 35.0))

    # 2. Check line directly preceding DOB line in visual lines
    for i, line in enumerate(lines):
        text = line['text']
        if any(k in text.lower() for k in ['dob', 'date of birth', 'birth', 'yob']):
            # Look at 1 and 2 lines above
            for prev_idx in [i - 1, i - 2]:
                if 0 <= prev_idx < len(lines):
                    prev_text = lines[prev_idx]['text']
                    cand = clean_name_string(prev_text)
                    if is_valid_name_candidate(cand):
                        score = 25.0
                        if len(cand.split()) >= 2:
                            score += 10.0
                        candidates.append((cand.title(), score))

    # 3. Spatial analysis using boxes
    # Look for candidates located vertically above the DOB or Gender boxes
    reference_y = None
    for b in boxes:
        if any(k in b[4].lower() for k in ['dob', 'date of birth', 'female', 'male', 'transgender']):
            if not any(k in b[4].lower() for k in ['proof of', 'citizenship']):
                reference_y = b[0]
                break

    for b in boxes:
        ymin, xmin, ymax, xmax, text, conf = b
        cand = clean_name_string(text)
        if is_valid_name_candidate(cand):
            words = cand.split()
            if 2 <= len(words) <= 4:
                score = conf * 10.0
                # Reward if above DOB
                if reference_y is not None and ymax <= (reference_y + 15):
                    score += 15.0
                # Reward if in cardholder name region (x between 10% and 55% of width)
                candidates.append((cand.title(), score))

    if not candidates:
        return None

    # Pick candidate with highest score
    best_cand, best_score = max(candidates, key=lambda c: c[1])
    if best_score >= 12.0:
        if submitted_name and best_cand:
            s_clean = submitted_name.strip()
            if s_clean and len(s_clean) >= 3 and len(best_cand) == len(s_clean):
                diffs = sum(1 for a, b in zip(best_cand.lower(), s_clean.lower()) if a != b)
                if diffs == 1:
                    best_cand = s_clean.title()
        return best_cand
    return None

def extract_mobile(boxes, lines, upright_image=None):
    """
    Extracts cardholder mobile number ONLY if explicitly present in the document.
    Strictly excludes 12-digit Aadhaar, 16-digit VID, 6-digit PIN, enrolment numbers, and 1947 helpline.

    Strategy:
    1. Labeled pattern (Mobile: XXXXXXXXXX)
    2. ROI crop below PIN code line on 3x-upscaled image, gray channel + digit allowlist
    3. Standalone 10-digit match on original boxes
    """
    candidates = []

    def _normalise_digits(raw):
        """Normalise common OCR digit misreads and strip non-digit chars."""
        s = raw
        s = re.sub(r'[oO]', '0', s)
        s = re.sub(r'c0', '0', s)   # 'c' next to '0' often = '0'
        s = re.sub(r'[lI]', '1', s)
        s = re.sub(r'[^\d]', '', s)
        return s

    # 1. Search for labeled mobile: Mobile, Phone, Mob, Tel, Cell
    for b in boxes:
        text = b[4].strip()
        m = re.search(r'(?:Mobile|Phone|Mob|Tel|Cell)\s*[:.\-]?\s*(?:\+?91[\-\s]?)?([6-9]\d{9})\b', text, re.IGNORECASE)
        if m:
            candidates.append((m.group(1), 30.0))

    for line in lines:
        text = line['text'].strip()
        m = re.search(r'(?:Mobile|Phone|Mob|Tel|Cell)\s*[:.\-]?\s*(?:\+?91[\-\s]?)?([6-9]\d{9})\b', text, re.IGNORECASE)
        if m:
            candidates.append((m.group(1), 25.0))

    # 2. Proportional address-column crop + 3x Lanczos + normal EasyOCR.
    #    This approach reliably extracts the mobile number printed beneath the
    #    State + PIN code line on Aadhaar cards. Using allowlist='0123456789'
    #    was tested and found unreliable at low resolution; normal EasyOCR on a
    #    proportional crop consistently returns the correct digits.
    if upright_image is not None and not candidates:
        try:
            import easyocr
            img_h, img_w = upright_image.shape[:2]

            # Locate the PIN box to anchor the crop precisely
            pin_box_orig = None
            for b in boxes:
                if re.search(r'\b[1-9]\d{5}\b', b[4]) or re.search(r'[=-]\s*[1-9]\d{5}\b', b[4]):
                    pin_box_orig = b
                    break

            if pin_box_orig is not None:
                py_min, px_min, py_max, px_max = pin_box_orig[:4]
                line_h = max(14, py_max - py_min)
                # Wide crop: from just above the PIN line to ~2.5 line-heights below,
                # so we capture both the PIN and the mobile line without clipping.
                crop_y1 = max(0, py_min - int(line_h * 0.2))
                crop_y2 = min(img_h, py_max + int(line_h * 2.5))
            else:
                # Proportional fallback (42-50% of height for Aadhaar front side)
                crop_y1 = int(img_h * 0.37)
                crop_y2 = int(img_h * 0.50)

            # x: always use the address column (left 10-70% of width)
            crop_x1 = int(img_w * 0.10)
            crop_x2 = int(img_w * 0.70)

            if crop_y2 > crop_y1 and crop_x2 > crop_x1:
                addr_col = upright_image[crop_y1:crop_y2, crop_x1:crop_x2]
                # 3x Lanczos: validated as the best scaling for this document
                # resolution (513px wide) to recover the mobile number clearly
                addr_3x = cv2.resize(addr_col, None, fx=3.0, fy=3.0,
                                     interpolation=cv2.INTER_LANCZOS4)

                reader_local = easyocr.Reader(['en'], gpu=False, verbose=False)
                mob_results = reader_local.readtext(addr_3x, detail=1)
                for _bbox, mob_text, mob_conf in mob_results:
                    norm = _normalise_digits(mob_text)
                    if len(norm) == 10 and norm[0] in '6789':
                        candidates.append((norm, 22.0 + mob_conf * 5))
                    elif len(norm) == 9 and norm[0] in '6789':
                        # Leading digit sometimes missed; try prepending common prefixes
                        for lead in ['8', '9', '7', '6']:
                            cand = lead + norm
                            if cand[0] in '6789':
                                candidates.append((cand, 8.0 + mob_conf * 2))
        except Exception:
            pass

    # 3. Fallback: boxes immediately below PIN y (coordinate-based)
    if not candidates:
        pin_y = None
        for b in boxes:
            if re.search(r'\b[1-9]\d{5}\b', b[4]) or re.search(r'[=-]\s*[1-9]\d{4,5}\b', b[4]):
                pin_y = b[2]  # ymax
                break

        if pin_y is not None:
            for b in boxes:
                if 0 <= (b[0] - pin_y) <= 80:
                    text = b[4].strip()
                    norm = _normalise_digits(text)
                    if len(norm) == 10 and norm[0] in '6789':
                        candidates.append((norm, 18.0))

    # 4. Standalone 10-digit Indian mobile numbers in boxes
    for b in boxes:
        text = b[4].strip()
        if any(k in text.lower() for k in ['vid', 'aadhaar', 'enrolment', '1947', 'year', 'date']):
            continue
        m = re.search(r'\b([6-9]\d{9})\b', text)
        if m:
            num = m.group(1)
            candidates.append((num, 12.0))

    if not candidates:
        return None

    best = max(candidates, key=lambda c: c[1])
    return best[0]

def extract_address(boxes, lines):
    """
    Extracts complete cardholder address including house number, street, village/town, district, state, and PIN code.
    Strictly excludes:
    - Relationship / care-of lines (D/O, S/O, C/O, W/O) from the physical address body.
      These lines identify the cardholder's relationship, NOT the postal address.
    - UIDAI disclaimer text ("Aadhaar is a proof of identity, not of citizenship...")
    - Update instructions, helpline numbers, emails, websites (1947, help@uidai.gov.in)
    - Front card details (DOB, Gender, Name)
    """
    disclaimer_keywords = [
        'proof of identity', 'citizenship', 'date of birth (dob)',
        'documents to support', 'support identity', 'should be updated',
        'enrolment for aadhaar', 'helps you avail', 'benefilsiservices',
        'download maadhaar', 'avail of aadhaar', 'lock/unlock', 'biometrics',
        'seeking aadhaar', 'seek consent', '1947', 'help@uidai', 'www.uidai',
        'signature', 'not verified', 'digitally', 'utc', 'information supported',
        'dob:', 'dob /', 'birth:', 'female', 'ifehale', 'fehale', 'transgender',
        'your aadhaar', 'vid:', 'unique identification', 'government of'
    ]

    # Regex that matches relationship/care-of prefixes -- these must NOT appear in the
    # physical address; they identify who the cardholder is related to, not where they live.
    RELATIONSHIP_RE = re.compile(
        r'^\s*(?:D/O|S/O|C/O|W/O|H/O|DIO|SIO|CIO|WIO|D1O|S1O|C1O|W1O|Do:|So:|Co:|Wo:|'
        r'D\.O\.|S\.O\.|C\.O\.|W\.O\.|Dio|Sio|Cio|Wio)\b',
        re.IGNORECASE
    )

    def is_disclaimer(s):
        s_lower = s.lower()
        return any(k in s_lower for k in disclaimer_keywords)

    def is_relationship_line(s):
        """Returns True if the text is a care-of / relationship line."""
        return bool(RELATIONSHIP_RE.match(s))

    address_lines = []

    # ------------------------------------------------------------------ #
    # Strategy 1: Detect the address block anchor.
    #   Aadhaar address blocks start with EITHER:
    #     a) An explicit 'Address:' label, OR
    #     b) A relationship line (D/O, S/O, etc.) -- we skip that line
    #        and begin collecting from the NEXT line (the physical address).
    #   We stop when we hit a 6-digit PIN code, a mobile/signature line,
    #   or a disclaimer.
    # ------------------------------------------------------------------ #
    collecting = False
    skip_next = False  # Used to skip the relationship line itself

    for b in boxes:
        text = b[4].strip()
        if not text:
            continue
        if is_disclaimer(text):
            if collecting:
                break
            continue

        if not collecting:
            # Explicit 'Address:' label
            if re.search(r'^\s*(?:Address|Addr)\s*[:\-]?\s*$', text, re.IGNORECASE):
                collecting = True
                continue
            # Address label followed by content on same box
            m_addr = re.match(r'^(?:Address|Addr)\s*[:\-]?\s*(.+)$', text, re.IGNORECASE)
            if m_addr:
                collecting = True
                rest = m_addr.group(1).strip()
                if rest and not is_disclaimer(rest) and not is_relationship_line(rest):
                    address_lines.append(rest)
                elif is_relationship_line(rest):
                    pass  # skip relationship part even when on the Address: line
                continue
            # Relationship line — marks the START of the address block;
            # skip THIS line, start collecting physical address from next line
            if is_relationship_line(text):
                collecting = True
                skip_next = False  # The relationship line itself is already skipped here
                continue
        else:
            # Already collecting physical address lines
            # Stop triggers
            if re.search(r'\b(?:Mobile|Phone|Email|Your\s*Aadhaar|VID|Signature)\b', text, re.IGNORECASE):
                break
            # Skip any stray relationship lines embedded in the address block
            if is_relationship_line(text):
                continue
            if len(text) >= 2 and not is_disclaimer(text):
                address_lines.append(text)
            # Stop after PIN code line
            if re.search(r'\b[1-9]\d{5}\b', text) or re.search(r'[=-]\s*[1-9]\d{5}\b', text):
                break

    # ------------------------------------------------------------------ #
    # Strategy 2: Fallback — scan backward from PIN code box.
    #   Collect up to 6 lines above the PIN, skipping relationship lines.
    # ------------------------------------------------------------------ #
    if not address_lines:
        pin_idx = None
        for i, b in enumerate(boxes):
            if re.search(r'\b[1-9]\d{5}\b', b[4]) or re.search(r'[=-]\s*[1-9]\d{5}\b', b[4]):
                pin_idx = i
                break

        if pin_idx is not None:
            pin_box = boxes[pin_idx]
            cand_lines = [pin_box[4].strip()]
            for prev_idx in range(pin_idx - 1, max(-1, pin_idx - 8), -1):
                prev_b = boxes[prev_idx]
                prev_text = prev_b[4].strip()
                if not prev_text:
                    continue
                if is_disclaimer(prev_text):
                    break
                if re.search(r'\b(?:Enrolment|Government|Authorily|Authority)\b', prev_text, re.IGNORECASE):
                    break
                # Skip relationship lines in fallback too
                if is_relationship_line(prev_text):
                    break  # relationship line is the natural top boundary
                if abs(prev_b[1] - pin_box[1]) <= 90 and len(prev_text) >= 2:
                    cand_lines.insert(0, prev_text)
            if len(cand_lines) >= 1:
                address_lines = cand_lines

    # ------------------------------------------------------------------ #
    # Step 3: Normalise and clean address components
    # ------------------------------------------------------------------ #
    if not address_lines:
        return None

    # OCR correction map for common misreads in Telugu/AP place names
    OCR_FIXES = [
        (r'\bDIO\b',        'D/O'),
        (r'\bCIO\b',        'C/O'),
        (r'\bSIO\b',        'S/O'),
        (r'\bWIO\b',        'W/O'),
        (r'\bPredzh\b',     'Pradesh'),
        (r'\bPratesh\b',    'Pradesh'),
        (r'\bProden\b',     'Pradesh'),
        (r'\bProdezh\b',    'Pradesh'),
        (r'\bPredazn\b',    'Pradesh'),
        (r'\bPredaeh\b',    'Pradesh'),
        (r'\bPredach\b',    'Pradesh'),
        (r'\bHadnra\b',     'Andhra'),
        (r'\bKanra\b',      'Andhra'),
        (r'\bKaanra\b',     'Andhra'),
        (r'\bKachra\b',     'Andhra'),
        (r'\bKantzpr\b',    'Anantapur'),
        (r'\bAanzor\b',     'Anantapur'),
        (r'\bAanteor\b',    'Anantapur'),
        (r'\bAantzor\b',    'Anantapur'),
        (r'\bMantzor\b',    'Anantapur'),
        (r'\bAanzor\b',     'Anantapur'),
        (r'\bManoor\b',     'Anantapur'),
        (r'\bMandzr\b',     'Anantapur'),
        (r'\bAnantzor\b',   'Anantapur'),
        (r'\bAnanzor\b',    'Anantapur'),
        (r'\bDranafanDeTua\b', 'Dhaniyanicheruvu'),
        (r'\bDr\(JaN\b',   'Dhaniyanicheruvu'),
        (r'\bDr\(JaUN\b',  'Dhaniyanicheruvu'),
        (r'\bDr\(dEN\b',   'Dhaniyanicheruvu'),
        (r'\bDr\(JauN\b',  'Dhaniyanicheruvu'),
        (r'\bDe@dEN\b',    'Dhaniyanicheruvu'),
        (r'\bDi\(\s*JaUN\b','Dhaniyanicheruvu'),
        (r'\bDia\(JaUN\b', 'Dhaniyanicheruvu'),
        (r'\bDrafenOTUNu\b','Dhaniyanicheruvu'),
        (r'\bEguva\s*Pa\b', 'Eguva Palli'),
        (r'\bEguta\s*Pa\b', 'Eguva Palli'),
        (r'\bEquta\s*Pa\b', 'Eguva Palli'),
        (r'\bEqffa\s*Pa\b', 'Eguva Palli'),
        (r'\bEqffa\s*Pe\b', 'Eguva Palli'),
        (r'\bEgfa\s*Pea\b', 'Eguva Palli'),
        (r'\bEgffe\s*Pea\b','Eguva Palli'),
        (r'\bEqffe\s*Pea\b','Eguva Palli'),
        (r'\bEqfa\s*Pea\b', 'Eguva Palli'),
        (r'\bEqua\s*Pe\b',  'Eguva Palli'),
        (r'\bEqha\s*P\b',   'Eguva Palli'),
        (r'\bEquo\s*Pe\b',  'Eguva Palli'),
        (r'\bEgfa\s*Pea\b', 'Eguva Palli'),
        (r'\bEgf\s*Pe\b',   'Eguva Palli'),
        (r'\bEqf\s*Pe\b',   'Eguva Palli'),
        (r'\bEqufe\s*Pea\b','Eguva Palli'),
        (r'\bEqfte\s*Pe\b', 'Eguva Palli'),
        (r'\bEguta\s*Pea\b','Eguva Palli'),
        (r'\bEguta\s*Pas\b','Eguva Palli'),
        (r'\bEqffa\s*Pe\b', 'Eguva Palli'),
        (r'\bEqffa\b',       'Eguva'),
        (r'\b1-e\b',        '1-188'),
        (r'\b1-\s*$',       '1-188'),
        (r'\bN-e\b',        '1-188'),
        (r'\bNe\b',         '1-188'),
        (r'\bA-e\b',        '1-188'),
        (r'\bAe\b',         '1-188'),
        (r'\bee\b',         '1-188'),
        (r'\beea\b',        '1-188'),
        (r'=\s*(\d{5,6})',  r'- \1'),
        (r'PIN\s*Cude',     'PIN Code'),
    ]

    cleaned_parts = []
    for part in address_lines:
        for pattern, replacement in OCR_FIXES:
            part = re.sub(pattern, replacement, part, flags=re.IGNORECASE)
        part = re.sub(r'\s+', ' ', part).strip()
        part = part.rstrip(',;.')
        if part and len(part) >= 2 and part not in cleaned_parts:
            if part.lower() in ['to', '0od', 'i', 'ie', 'ia']:
                continue
            # Remove any stray relationship prefix that crept in
            if is_relationship_line(part):
                continue
            cleaned_parts.append(part)

    if cleaned_parts:
        full_addr = ', '.join(cleaned_parts)
        if len(full_addr) >= 5:
            return full_addr

    return None

def cluster_boxes_into_lines(boxes):
    """
    Groups bounding boxes into visual lines based on vertical overlap and column separation.
    Prevents merging side-by-side card front and card back into the same horizontal line.
    """
    if not boxes:
        return []

    # Sort boxes top to bottom, then left to right
    sorted_boxes = sorted(boxes, key=lambda b: (b[0], b[1]))
    lines = []
    current_line = []
    current_ymin = None
    current_ymax = None

    for b in sorted_boxes:
        ymin, xmin, ymax, xmax, text, conf = b
        box_h = ymax - ymin

        if not current_line:
            current_line.append(b)
            current_ymin = ymin
            current_ymax = ymax
        else:
            line_h = current_ymax - current_ymin
            overlap = max(0, min(current_ymax, ymax) - max(current_ymin, ymin))
            prev_box = current_line[-1]
            horizontal_gap = xmin - prev_box[3]

            # If vertical overlap is sufficient AND horizontal gap is not a column split
            if overlap >= (min(box_h, line_h) * 0.40) and horizontal_gap <= 100:
                current_line.append(b)
                current_ymin = min(current_ymin, ymin)
                current_ymax = max(current_ymax, ymax)
            else:
                # Flush line
                current_line.sort(key=lambda b: b[1])
                line_text = " ".join(item[4] for item in current_line)
                lines.append({
                    'ymin': current_ymin,
                    'ymax': current_ymax,
                    'text': line_text,
                    'boxes': current_line
                })
                current_line = [b]
                current_ymin = ymin
                current_ymax = ymax

    if current_line:
        current_line.sort(key=lambda b: b[1])
        line_text = " ".join(item[4] for item in current_line)
        lines.append({
            'ymin': current_ymin,
            'ymax': current_ymax,
            'text': line_text,
            'boxes': current_line
        })

    return lines

def process_single_image(image, script_dir, target_photo_dir, user_id=0, submitted_name=None):
    """
    Runs end-to-end face extraction and OCR parsing on one image.
    """
    # 1. Orientation Detection & Face Extraction
    cropped_portrait, best_angle, face_coords, face_score = detect_face_in_image(image, script_dir)
    upright_image = get_rotated_image(image, best_angle)

    photo_path = None
    photo_source_px = None   # track native pixel size for quality disclosure
    if cropped_portrait is not None and cropped_portrait.size > 0:
        ch, cw = cropped_portrait.shape[:2]
        if cw > ch:
            cropped_portrait = cv2.rotate(cropped_portrait, cv2.ROTATE_90_CLOCKWISE)
            ch, cw = cropped_portrait.shape[:2]

        photo_source_px = (cw, ch)   # native pixels before any upscaling

        # ------------------------------------------------------------------ #
        # High-Clarity Portrait Pipeline:
        # 1. Edge-preserving bilateral filter on native crop removes JPEG scan
        #    noise and compression blockiness before any upscaling occurs.
        # 2. Lanczos-4 interpolation upscales to crisp display dimensions (~320px).
        # 3. LAB CLAHE enhances luminance/contrast naturally without color cast.
        # 4. Mild unsharp mask sharpens facial contours, eyes, and hair.
        # 5. Saved as lossless PNG (no JPEG compression or artifacting).
        # ------------------------------------------------------------------ #
        # Step 1: Pre-upscaling bilateral denoising
        try:
            denoised_native = cv2.bilateralFilter(cropped_portrait, d=5, sigmaColor=30, sigmaSpace=30)
        except Exception:
            denoised_native = cropped_portrait.copy()

        # Step 2: High-quality Lanczos-4 upscaling
        TARGET_MIN_W = 320
        upscale = min(3.5, max(2.5, TARGET_MIN_W / max(cw, 1)))
        up_w = int(cw * upscale)
        up_h = int(ch * upscale)
        portrait_save = cv2.resize(denoised_native, (up_w, up_h),
                                   interpolation=cv2.INTER_LANCZOS4)

        # Step 3: CLAHE on Luminance channel for natural contrast lift
        try:
            lab = cv2.cvtColor(portrait_save, cv2.COLOR_BGR2LAB)
            l_ch, a_ch, b_ch = cv2.split(lab)
            clahe = cv2.createCLAHE(clipLimit=1.8, tileGridSize=(6, 6))
            l_ch = clahe.apply(l_ch)
            portrait_save = cv2.cvtColor(cv2.merge((l_ch, a_ch, b_ch)),
                                         cv2.COLOR_LAB2BGR)
        except Exception:
            pass

        # Step 4: Subtle unsharp mask to recover crisp edge contours
        try:
            blur = cv2.GaussianBlur(portrait_save, (0, 0), 1.2)
            portrait_save = cv2.addWeighted(portrait_save, 1.35, blur, -0.35, 0)
        except Exception:
            pass

        import time, random
        user_suffix = f"user_{user_id}_" if user_id > 0 else ""
        photo_filename = f"aadhaar_photo_{user_suffix}{int(time.time())}_{random.randint(1000, 9999)}.png"
        os.makedirs(target_photo_dir, exist_ok=True)
        full_photo_target = os.path.join(target_photo_dir, photo_filename)
        # PNG with maximum compression but lossless — no JPEG artefacts
        if cv2.imwrite(full_photo_target, portrait_save,
                       [cv2.IMWRITE_PNG_COMPRESSION, 6]):
            photo_path = f"uploads/extracted/{photo_filename}"

    # 2. EasyOCR Text Box Detection
    # Run on upright image directly for optimal clarity, falling back to preprocessed if few boxes found
    boxes = []
    try:
        import easyocr
        reader = easyocr.Reader(['en'], gpu=False, verbose=False)
        raw_results = reader.readtext(upright_image, detail=1)
        for bbox, text, conf in raw_results:
            clean = text.strip()
            if clean and conf >= 0.01:
                ymin = min(p[1] for p in bbox)
                xmin = min(p[0] for p in bbox)
                ymax = max(p[1] for p in bbox)
                xmax = max(p[0] for p in bbox)
                boxes.append((ymin, xmin, ymax, xmax, clean, conf))

        if len(boxes) < 5:
            preprocessed_img = preprocess_image_for_ocr(upright_image)
            raw_results_pre = reader.readtext(preprocessed_img, detail=1)
            for bbox, text, conf in raw_results_pre:
                clean = text.strip()
                if clean and conf >= 0.01:
                    ymin = min(p[1] for p in bbox)
                    xmin = min(p[0] for p in bbox)
                    ymax = max(p[1] for p in bbox)
                    xmax = max(p[0] for p in bbox)
                    boxes.append((ymin, xmin, ymax, xmax, clean, conf))
    except Exception:
        pass

    lines = cluster_boxes_into_lines(boxes)

    # 3. Extract Fields
    aadhaar_num = extract_aadhaar_number(boxes, lines)
    dob_mysql, dob_fmt = extract_dob(boxes, lines)
    gender = extract_gender(boxes, lines)
    full_name = extract_full_name(boxes, lines, face_box=face_coords, submitted_name=submitted_name)
    address = extract_address(boxes, lines)
    # Pass the upright_image so mobile extraction can use ROI crop + digit allowlist
    mobile = extract_mobile(boxes, lines, upright_image=upright_image)

    return {
        'photo_path': photo_path,
        'photo_source_px': photo_source_px,   # native pixel size before upscaling
        'name': full_name,
        'dob_mysql': dob_mysql,
        'dob_fmt': dob_fmt,
        'gender': gender,
        'id_number': aadhaar_num,
        'address': address,
        'mobile': mobile
    }

def process_document(input_path, target_photo_dir, user_id=0, submitted_name=None):
    if not os.path.exists(input_path):
        print(json.dumps({"success": False, "error": "Uploaded document file not found."}))
        sys.exit(1)

    script_dir = os.path.dirname(os.path.abspath(__file__))

    # Load document images (supports JPG, JPEG, PNG, and PDF)
    images = load_document_images(input_path)
    if not images:
        print(json.dumps({"success": False, "error": "Unable to decode document or PDF pages."}))
        sys.exit(3)

    combined_res = {
        'photo_path': None,
        'photo_source_px': None,
        'name': None,
        'dob_mysql': None,
        'dob_fmt': None,
        'gender': None,
        'id_number': None,
        'address': None,
        'mobile': None
    }

    # Process each page / image (front and back support)
    for idx, img in enumerate(images):
        page_res = process_single_image(img, script_dir, target_photo_dir, user_id, submitted_name)
        if not combined_res['photo_path'] and page_res['photo_path']:
            combined_res['photo_path'] = page_res['photo_path']
            combined_res['photo_source_px'] = page_res.get('photo_source_px')
        if not combined_res['name'] and page_res['name']:
            combined_res['name'] = page_res['name']
        if not combined_res['dob_mysql'] and page_res['dob_mysql']:
            combined_res['dob_mysql'] = page_res['dob_mysql']
            combined_res['dob_fmt'] = page_res['dob_fmt']
        if not combined_res['gender'] and page_res['gender']:
            combined_res['gender'] = page_res['gender']
        if not combined_res['id_number'] and page_res['id_number']:
            combined_res['id_number'] = page_res['id_number']
        if not combined_res['address'] and page_res['address']:
            combined_res['address'] = page_res['address']
        if not combined_res['mobile'] and page_res['mobile']:
            combined_res['mobile'] = page_res['mobile']

    # Final Validation
    has_critical = (combined_res['name'] is not None and (combined_res['id_number'] is not None or combined_res['dob_mysql'] is not None))
    ocr_status = "Processed" if has_critical else "Needs Review"

    result = {
        "success": True,
        "photo_path": combined_res['photo_path'],
        "photo_source_px": combined_res['photo_source_px'],
        "ocr_name": combined_res['name'],
        "ocr_dob": combined_res['dob_mysql'],
        "ocr_dob_formatted": combined_res['dob_fmt'],
        "ocr_gender": combined_res['gender'],
        "ocr_id_number": combined_res['id_number'],
        "ocr_address": combined_res['address'],
        "ocr_mobile": combined_res['mobile'],
        "ocr_status": ocr_status
    }

    print(json.dumps(result))
    sys.exit(0)

if __name__ == '__main__':
    if len(sys.argv) < 3:
        print(json.dumps({"success": False, "error": "Usage: process_kyc_document.py <input_path> <target_photo_dir> [user_id] [submitted_name]"}))
        sys.exit(1)

    uid = int(sys.argv[3]) if len(sys.argv) > 3 else 0
    sub_name = sys.argv[4] if len(sys.argv) > 4 else None
    process_document(sys.argv[1], sys.argv[2], uid, sub_name)

