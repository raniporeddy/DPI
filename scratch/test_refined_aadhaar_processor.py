import cv2
import os
import re
import easyocr
import pytesseract
import numpy as np

img_path = r"c:\Users\pored\.antigravity-ide\digital-investor\uploads\kyc\doc_6ac8e3b6be7ed7.29593758.jpeg"
image = cv2.imread(img_path)
img_h, img_w = image.shape[:2]

gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)

# --- 1. FACE PHOTO EXTRACTION (FILTERING OUT SIGNATURE BOX ON RIGHT) ---
# Cardholder face photos on Aadhaar are ALWAYS on the LEFT side (x_center < 0.52 * w).
# Signature verification checkmarks & QR codes are on the RIGHT side.

script_dir = os.path.dirname(os.path.abspath(__file__))
frontal_xml = os.path.join(script_dir, '../scripts/haarcascade_frontalface_default.xml')
profile_xml = os.path.join(script_dir, '../scripts/haarcascade_profileface.xml')

detected_faces = []

if os.path.exists(frontal_xml):
    cascade = cv2.CascadeClassifier(frontal_xml)
    for sf in [1.05, 1.1, 1.15, 1.2]:
        faces = cascade.detectMultiScale(
            gray,
            scaleFactor=sf,
            minNeighbors=2,
            minSize=(30, 30),
            maxSize=(int(img_w * 0.7), int(img_h * 0.7))
        )
        for (fx, fy, fw, fh) in faces:
            # Filter out right-side signature box or bottom-right QR code
            if (fx + fw / 2.0) < (img_w * 0.52):
                detected_faces.append((fx, fy, fw, fh))

if not detected_faces and os.path.exists(profile_xml):
    cascade_prof = cv2.CascadeClassifier(profile_xml)
    faces_prof = cascade_prof.detectMultiScale(gray, scaleFactor=1.08, minNeighbors=2, minSize=(30, 30))
    for (fx, fy, fw, fh) in faces_prof:
        if (fx + fw / 2.0) < (img_w * 0.52):
            detected_faces.append((fx, fy, fw, fh))

# Skin contour detection limited to left half
if not detected_faces:
    left_half = image[:, :int(img_w * 0.52)]
    ycrcb = cv2.cvtColor(left_half, cv2.COLOR_BGR2YCrCb)
    lower_skin = np.array([0, 133, 77], dtype=np.uint8)
    upper_skin = np.array([255, 173, 127], dtype=np.uint8)
    mask = cv2.inRange(ycrcb, lower_skin, upper_skin)
    kernel = cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (5, 5))
    mask = cv2.morphologyEx(mask, cv2.MORPH_OPEN, kernel, iterations=2)
    mask = cv2.morphologyEx(mask, cv2.MORPH_CLOSE, kernel, iterations=2)
    contours, _ = cv2.findContours(mask, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
    img_area = img_w * img_h
    valid_face_boxes = []
    for cnt in contours:
        area = cv2.contourArea(cnt)
        if (0.01 * img_area) <= area <= (0.35 * img_area):
            x, y, w, h = cv2.boundingRect(cnt)
            aspect = float(h) / w if w > 0 else 0
            if 0.8 <= aspect <= 2.2 and y > 0.1 * img_h:
                valid_face_boxes.append((x, y, w, h, area))
    if valid_face_boxes:
        best_box = max(valid_face_boxes, key=lambda b: b[4])
        detected_faces.append((best_box[0], best_box[1], best_box[2], best_box[3]))

# Fallback: Left-side crop slot where photo is located on e-Aadhaar slips
if not detected_faces:
    crop_y1 = int(img_h * 0.65)
    crop_y2 = int(img_h * 0.88)
    crop_x1 = int(img_w * 0.05)
    crop_x2 = int(img_w * 0.45)
    cropped_portrait = image[crop_y1:crop_y2, crop_x1:crop_x2]
else:
    best_face = max(detected_faces, key=lambda f: f[2] * f[3])
    fx, fy, fw, fh = best_face
    pad_top = int(fh * 0.20)
    pad_bottom = int(fh * 0.25)
    pad_left = int(fw * 0.20)
    pad_right = int(fw * 0.20)
    crop_y1 = max(0, int(fy - pad_top))
    crop_y2 = min(img_h, int(fy + fh + pad_bottom))
    crop_x1 = max(0, int(fx - pad_left))
    crop_x2 = min(img_w, int(fx + fw + pad_right))
    cropped_portrait = image[crop_y1:crop_y2, crop_x1:crop_x2]

cv2.imwrite(r"c:\Users\pored\.antigravity-ide\digital-investor\scratch\extracted_portrait_test.png", cropped_portrait)
print("Extracted photo saved to scratch/extracted_portrait_test.png")

# --- 2. ADVANCED OCR PARSING FOR NAME, DOB, ID, ADDRESS ---
reader = easyocr.Reader(['en'], gpu=False, verbose=False)
lines = reader.readtext(img_path, detail=0)

extracted_name = None
extracted_dob = None
extracted_id = None
extracted_address = []

for i, line in enumerate(lines):
    line_clean = line.strip()
    
    # Name right after "To"
    if line_clean.lower() == 'to' and i + 1 < len(lines):
        candidate = lines[i + 1].strip()
        if re.match(r'^[A-Za-z\s]{3,35}$', candidate) and not any(k in candidate.lower() for k in ['government', 'india', 'authority', 'enrolment']):
            extracted_name = candidate

    # Name right before S/O or W/O or DOB
    if not extracted_name and any(k in line_clean.lower() for k in ['w/o', 's/o', 'd/o', 'wjo', 'sjo', 'dob / yob', 'dob:']):
        if i > 0 and re.match(r'^[A-Za-z\s]{3,35}$', lines[i - 1].strip()):
            extracted_name = lines[i - 1].strip()

    # DOB / YOB
    dob_m = re.search(r'(?:DOB|Date of Birth|Birth|YOB)\s*[:\-\/]?\s*(\d{2}[\/\-\.]\d{2}[\/\-\.]\d{4})', line_clean, re.IGNORECASE)
    if dob_m:
        extracted_dob = dob_m.group(1).replace('.', '/').replace('-', '/')
    elif not extracted_dob:
        dm = re.search(r'\b(\d{2}[\/\-\.]\d{2}[\/\-\.]\d{4})\b', line_clean)
        if dm:
            extracted_dob = dm.group(1).replace('.', '/').replace('-', '/')

    # Aadhaar Number (12 digits 4 4 4)
    id_m = re.search(r'\b(\d{4}\s?\d{4}\s?\d{4})\b', line_clean)
    if id_m:
        raw_id = id_m.group(1).replace(' ', '')
        if len(raw_id) == 12:
            extracted_id = f"{raw_id[:4]}-{raw_id[4:8]}-{raw_id[8:]}"

    # Address lines (W/O, S/O, Eguvapalli, Kadiri, Ananthapur, etc.)
    if any(k in line_clean.lower() for k in ['w/o', 's/o', 'd/o', '1-', '2-', '3-', 'street', 'village', 'mandal', 'dist', 'pradesh', '515', '516', '500']):
        if not any(k in line_clean.lower() for k in ['signature', 'not verified', 'digitally signed', 'utc', 'dob']):
            extracted_address.append(line_clean)

full_address = ", ".join(extracted_address) if extracted_address else None

print("\n--- OCR EXTRACTION RESULTS ---")
print(f"Extracted Name: {extracted_name}")
print(f"Extracted DOB: {extracted_dob}")
print(f"Extracted ID: {extracted_id}")
print(f"Extracted Address: {full_address}")
