#!/usr/bin/env python3
"""
Offline Python Script for eKYC Document Processing:
1. EXIF & Smart Multi-orientation Face Detection & Upright Portrait Extraction.
2. Unified Document Orientation for both Face Cropping and OCR Processing.
3. Image Preprocessing (CLAHE, Deskewing, Noise Reduction) & Strict OCR Field Parsing (Name, DOB, Gender, ID, Address).
"""

import sys
import os
import json
import re
import warnings

warnings.filterwarnings("ignore")
os.environ["TF_CPP_MIN_LOG_LEVEL"] = "3"

def fix_exif_orientation(image_path):
    """
    Reads EXIF Orientation tag from image file (JPEG/TIFF/PNG) and returns properly oriented OpenCV BGR image.
    """
    import cv2
    image = cv2.imread(image_path)
    if image is None:
        return None

    try:
        from PIL import Image, ImageOps
        pil_img = Image.open(image_path)
        pil_img = ImageOps.exif_transpose(pil_img)
        
        import numpy as np
        if pil_img.mode == 'RGB':
            image = cv2.cvtColor(np.array(pil_img), cv2.COLOR_RGB2BGR)
        elif pil_img.mode == 'RGBA':
            image = cv2.cvtColor(np.array(pil_img), cv2.COLOR_RGBA2BGR)
    except Exception:
        pass

    return image

def get_rotated_image(img, angle):
    if angle == 90:
        import cv2
        return cv2.rotate(img, cv2.ROTATE_90_CLOCKWISE)
    elif angle == 180:
        import cv2
        return cv2.rotate(img, cv2.ROTATE_180)
    elif angle == 270:
        import cv2
        return cv2.rotate(img, cv2.ROTATE_90_COUNTERCLOCKWISE)
    return img

def detect_face_portrait_and_upright_doc(image, script_dir):
    """
    Detects person's face across 0, 90, 180, 270 angles.
    Returns (cropped_portrait, upright_document_image, orientation_angle)
    """
    try:
        import cv2
        import numpy as np
    except ImportError:
        return None, image, 0

    img_h, img_w = image.shape[:2]
    if img_w < 40 or img_h < 40:
        return None, image, 0

    frontal_xml = os.path.join(script_dir, 'haarcascade_frontalface_default.xml')
    profile_xml = os.path.join(script_dir, 'haarcascade_profileface.xml')
    onnx_path = os.path.join(script_dir, 'face_detection_yunet_2023mar.onnx')

    candidates = []  # list of (fx, fy, fw, fh, score, angle, rot_img)

    for angle in [0, 90, 180, 270]:
        rot_img = get_rotated_image(image, angle)
        rh, rw = rot_img.shape[:2]
        gray = cv2.cvtColor(rot_img, cv2.COLOR_BGR2GRAY)

        # 1. Haar Frontal Face Detector
        if os.path.exists(frontal_xml) and hasattr(cv2, 'CascadeClassifier'):
            try:
                cascade = cv2.CascadeClassifier(frontal_xml)
                for sf in [1.05, 1.08, 1.12, 1.18, 1.25]:
                    faces = cascade.detectMultiScale(
                        gray,
                        scaleFactor=sf,
                        minNeighbors=2,
                        minSize=(25, 25),
                        maxSize=(int(rw * 0.80), int(rh * 0.80))
                    )
                    for (fx, fy, fw, fh) in faces:
                        aspect = float(fh) / fw if fw > 0 else 0
                        if 0.7 <= aspect <= 1.8:
                            pos_weight = 1.4 if (fx + fw / 2.0) < (rw * 0.60) else 1.0
                            score = (fw * fh) * pos_weight * 2.0
                            candidates.append((fx, fy, fw, fh, score, angle, rot_img))
            except Exception:
                pass

        # 2. YuNet ONNX Deep Face Detector
        if os.path.exists(onnx_path) and hasattr(cv2, 'FaceDetectorYN_create'):
            try:
                detector = cv2.FaceDetectorYN_create(
                    onnx_path,
                    "",
                    (rw, rh),
                    score_threshold=0.30,
                    nms_threshold=0.30
                )
                _, yfaces = detector.detect(rot_img)
                if yfaces is not None and len(yfaces) > 0:
                    for f in yfaces:
                        fx, fy, fw, fh = int(f[0]), int(f[1]), int(f[2]), int(f[3])
                        if fw >= 20 and fh >= 20:
                            pos_weight = 1.4 if (fx + fw / 2.0) < (rw * 0.60) else 1.0
                            score = (fw * fh) * pos_weight * 3.0
                            candidates.append((fx, fy, fw, fh, score, angle, rot_img))
            except Exception:
                pass

        # 3. Haar Profile Face Detector
        if not candidates and os.path.exists(profile_xml) and hasattr(cv2, 'CascadeClassifier'):
            try:
                cascade_prof = cv2.CascadeClassifier(profile_xml)
                faces_prof = cascade_prof.detectMultiScale(gray, scaleFactor=1.08, minNeighbors=2, minSize=(25, 25))
                for (fx, fy, fw, fh) in faces_prof:
                    pos_weight = 1.4 if (fx + fw / 2.0) < (rw * 0.60) else 1.0
                    score = (fw * fh) * pos_weight * 1.5
                    candidates.append((fx, fy, fw, fh, score, angle, rot_img))
            except Exception:
                pass

        # 4. Skin Tone Color Density Analysis (YCrCb + HSV)
        if not candidates:
            try:
                ycrcb = cv2.cvtColor(rot_img, cv2.COLOR_BGR2YCrCb)
                hsv = cv2.cvtColor(rot_img, cv2.COLOR_BGR2HSV)
                mask_ycrcb = cv2.inRange(ycrcb, np.array([0, 133, 77], dtype=np.uint8), np.array([255, 173, 127], dtype=np.uint8))
                mask_hsv = cv2.inRange(hsv, np.array([0, 30, 60], dtype=np.uint8), np.array([25, 250, 255], dtype=np.uint8))
                mask = cv2.bitwise_and(mask_ycrcb, mask_hsv)

                kernel = cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (5, 5))
                mask = cv2.morphologyEx(mask, cv2.MORPH_OPEN, kernel, iterations=2)
                mask = cv2.morphologyEx(mask, cv2.MORPH_CLOSE, kernel, iterations=2)

                contours, _ = cv2.findContours(mask, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
                img_area = rw * rh

                for cnt in contours:
                    area = cv2.contourArea(cnt)
                    if (0.01 * img_area) <= area <= (0.35 * img_area):
                        fx, fy, fw, fh = cv2.boundingRect(cnt)
                        aspect = float(fh) / fw if fw > 0 else 0
                        if 0.8 <= aspect <= 2.2:
                            pos_weight = 1.3 if (fx + fw / 2.0) < (rw * 0.60) else 0.9
                            score = area * pos_weight
                            candidates.append((fx, fy, fw, fh, score, angle, rot_img))
            except Exception:
                pass

    if not candidates:
        return None, image, 0

    # Select candidate face with maximum score
    best = max(candidates, key=lambda c: c[4])
    fx, fy, fw, fh, score, angle, rot_img = best
    rh, rw = rot_img.shape[:2]

    # Calculate padding for complete anatomical portrait (head + hair + neck + shoulders)
    pad_top = int(fh * 0.40)
    pad_bottom = int(fh * 0.85)
    pad_left = int(fw * 0.35)
    pad_right = int(fw * 0.35)

    crop_y1 = max(0, int(fy - pad_top))
    crop_y2 = min(rh, int(fy + fh + pad_bottom))
    crop_x1 = max(0, int(fx - pad_left))
    crop_x2 = min(rw, int(fx + fw + pad_right))

    crop_w = crop_x2 - crop_x1
    crop_h = crop_y2 - crop_y1

    # Validate crop dimensions: must not cover entire document (>88%) or be tiny noise (<25px)
    if crop_w < 25 or crop_h < 25 or crop_w >= (rw * 0.88) or crop_h >= (rh * 0.88):
        return None, rot_img, angle

    cropped = rot_img[crop_y1:crop_y2, crop_x1:crop_x2]

    # Ensure extracted portrait is strictly upright (height >= width)
    ch, cw = cropped.shape[:2]
    if cw > ch:
        cropped = cv2.rotate(cropped, cv2.ROTATE_90_CLOCKWISE)

    return cropped, rot_img, angle

def preprocess_image_for_ocr(image):
    """
    Preprocesses document image for optimal OCR accuracy:
    - Resizing to standard width (1400px)
    - Grayscale conversion
    - Contrast Limited Adaptive Histogram Equalization (CLAHE)
    - Deskewing tilt angles
    - Denoising
    """
    import cv2
    import numpy as np

    h, w = image.shape[:2]
    target_w = 1400
    if w != target_w and w > 0:
        scale = target_w / float(w)
        target_h = int(h * scale)
        image = cv2.resize(image, (target_w, target_h), interpolation=cv2.INTER_CUBIC)

    gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)

    # Apply CLAHE contrast enhancement
    clahe = cv2.createCLAHE(clipLimit=2.0, tileGridSize=(8, 8))
    enhanced = clahe.apply(gray)

    # Denoise
    denoised = cv2.fastNlMeansDenoising(enhanced, h=10)

    return denoised

def process_document(input_path, target_photo_dir, user_id=0):
    if not os.path.exists(input_path):
        print(json.dumps({"success": False, "error": "Uploaded document file not found."}))
        sys.exit(1)

    try:
        import cv2
        import numpy as np
    except ImportError:
        print(json.dumps({"success": False, "error": "OpenCV (cv2) library is not installed."}))
        sys.exit(2)

    # 1. Fix EXIF orientation on input document image
    image = fix_exif_orientation(input_path)
    if image is None:
        print(json.dumps({"success": False, "error": "Unable to read uploaded image binary."}))
        sys.exit(3)

    script_dir = os.path.dirname(os.path.abspath(__file__))

    # 2. Detect & Crop Upright Face Portrait AND obtain upright document image for OCR
    cropped_portrait, upright_doc, angle = detect_face_portrait_and_upright_doc(image, script_dir)
    doc_for_ocr = upright_doc if upright_doc is not None else image

    photo_path = None
    if cropped_portrait is not None and cropped_portrait.size > 0:
        user_suffix = f"user_{user_id}_" if user_id > 0 else ""
        import time, random
        photo_filename = f"aadhaar_photo_{user_suffix}{int(time.time())}_{random.randint(1000, 9999)}.png"
        os.makedirs(target_photo_dir, exist_ok=True)
        full_photo_target = os.path.join(target_photo_dir, photo_filename)
        if cv2.imwrite(full_photo_target, cropped_portrait):
            photo_path = f"uploads/extracted/{photo_filename}"

    # --- 3. UNIFIED OCR TEXT EXTRACTION ON UPRIGHT DOCUMENT ---
    preprocessed_img = preprocess_image_for_ocr(doc_for_ocr)
    ocr_lines = []

    try:
        import easyocr
        reader = easyocr.Reader(['en'], gpu=False, verbose=False)
        ocr_lines = reader.readtext(preprocessed_img, detail=0)
    except Exception:
        try:
            import pytesseract
            for tpath in [r'C:\Program Files\Tesseract-OCR\tesseract.exe', r'C:\Users\pored\AppData\Local\Programs\Tesseract-OCR\tesseract.exe', 'tesseract']:
                if os.path.exists(tpath) or tpath == 'tesseract':
                    pytesseract.pytesseract.tesseract_cmd = tpath
                    full_text = pytesseract.image_to_string(preprocessed_img)
                    if full_text:
                        ocr_lines = [line.strip() for line in full_text.split('\n') if line.strip()]
                        break
        except Exception:
            pass

    extracted_name = None
    extracted_dob = None
    extracted_gender = None
    extracted_id = None
    address_lines = []

    system_keywords = [
        'government', 'india', 'authority', 'unique', 'identification',
        'enrolment', 'help', 'signature', 'not verified', 'digitally',
        'download', 'issue', 'date', 'aadhaar', 'card', 'www', 'uidai'
    ]

    if ocr_lines:
        for i, line in enumerate(ocr_lines):
            line_clean = line.strip()
            line_lower = line_clean.lower()

            # Name extraction - STRICT (Never use profile fallback or guess)
            if line_lower == 'to' and i + 1 < len(ocr_lines):
                cand = ocr_lines[i + 1].strip()
                if re.match(r'^[A-Za-z\s\.]{3,35}$', cand) and not any(k in cand.lower() for k in system_keywords):
                    extracted_name = cand

            if not extracted_name and any(k in line_lower for k in ['w/o', 's/o', 'd/o', 'wjo', 'sjo', 'son of', 'daughter of', 'wife of']):
                if i > 0:
                    prev_line = ocr_lines[i - 1].strip()
                    if re.match(r'^[A-Za-z\s\.]{3,35}$', prev_line) and not any(k in prev_line.lower() for k in system_keywords):
                        extracted_name = prev_line

            if not extracted_name:
                m_name = re.search(r'(?:Name|NAME|Holder Name)\s*[:\-]?\s*([A-Za-z\s\.]{3,35})', line_clean, re.IGNORECASE)
                if m_name:
                    cand = m_name.group(1).strip()
                    if not any(k in cand.lower() for k in system_keywords):
                        extracted_name = cand

            # DOB Extraction
            dob_m = re.search(r'(?:DOB|Date of Birth|Birth|YOB)\s*[:\-\/]?\s*(\d{2}[\/\-\.]\d{2}[\/\-\.]\d{4})', line_clean, re.IGNORECASE)
            if dob_m:
                extracted_dob = dob_m.group(1).replace('.', '/').replace('-', '/')
            elif not extracted_dob:
                dates = re.findall(r'\b(\d{2}[\/\-\.]\d{2}[\/\-\.]\d{4})\b', line_clean)
                if dates:
                    extracted_dob = dates[0].replace('.', '/').replace('-', '/')

            # Gender Extraction
            if not extracted_gender:
                if re.search(r'\b(?:Female|FEMALE)\b', line_clean):
                    extracted_gender = 'Female'
                elif re.search(r'\b(?:Male|MALE)\b', line_clean):
                    extracted_gender = 'Male'
                elif re.search(r'\b(?:Transgender)\b', line_clean, re.IGNORECASE):
                    extracted_gender = 'Transgender'

            # Aadhaar ID Extraction (12 digits 4-4-4)
            id_m = re.search(r'\b(\d{4}\s?\d{4}\s?\d{4})\b', line_clean)
            if id_m:
                raw_id = id_m.group(1).replace(' ', '')
                if len(raw_id) == 12:
                    extracted_id = f"{raw_id[:4]}-{raw_id[4:8]}-{raw_id[8:]}"

            # Address Extraction
            if any(k in line_lower for k in ['w/o', 's/o', 'd/o', 'address', 'street', 'road', 'village', 'mandal', 'dist', 'pradesh', 'nagar', 'colony', '515', '516', '500']):
                if not any(k in line_lower for k in ['signature', 'not verified', 'digitally signed', 'utc', 'dob']):
                    address_lines.append(line_clean)

    extracted_address = ", ".join(address_lines) if address_lines else None

    # Format DOB for MySQL YYYY-MM-DD
    mysql_dob = None
    if extracted_dob:
        parts = re.split(r'[\/\-\.]', extracted_dob)
        if len(parts) == 3:
            if len(parts[2]) == 4:
                mysql_dob = f"{parts[2]}-{parts[1].zfill(2)}-{parts[0].zfill(2)}"
            elif len(parts[0]) == 4:
                mysql_dob = f"{parts[0]}-{parts[1].zfill(2)}-{parts[2].zfill(2)}"

    result = {
        "success": True,
        "photo_path": photo_path,
        "ocr_name": extracted_name,
        "ocr_dob": mysql_dob or extracted_dob,
        "ocr_dob_formatted": extracted_dob,
        "ocr_gender": extracted_gender,
        "ocr_id_number": extracted_id,
        "ocr_address": extracted_address,
        "ocr_status": "Processed" if (extracted_name or extracted_dob or extracted_id or extracted_address) else "Unreadable"
    }

    print(json.dumps(result))
    sys.exit(0)

if __name__ == '__main__':
    if len(sys.argv) < 3:
        print(json.dumps({"success": False, "error": "Usage: process_kyc_document.py <input_path> <target_photo_dir> [user_id]"}))
        sys.exit(1)

    uid = int(sys.argv[3]) if len(sys.argv) > 3 else 0
    process_document(sys.argv[1], sys.argv[2], uid)
