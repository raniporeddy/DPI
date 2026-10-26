import os
import sys
import cv2
import json

sys.path.append(os.path.join(os.path.dirname(__file__), '..', 'scripts'))
import process_kyc_document as pkd

doc_path = 'uploads/kyc/doc_6accf3c2662a17.22912885.jpeg'
img = cv2.imread(doc_path)
h, w = img.shape[:2]
print(f"Loaded image: {img.shape}")

# Use safe preprocessing:
# Keep max dimension capped around 1400-1600
max_dim = max(h, w)
if max_dim < 1000:
    scale = 1400.0 / max_dim
    pre = cv2.resize(img, (int(w * scale), int(h * scale)), interpolation=cv2.INTER_CUBIC)
elif max_dim > 2000:
    scale = 1800.0 / max_dim
    pre = cv2.resize(img, (int(w * scale), int(h * scale)), interpolation=cv2.INTER_AREA)
else:
    pre = img.copy()

print(f"Preprocessed shape: {pre.shape}")

import easyocr
reader = easyocr.Reader(['en'], gpu=False, verbose=False)
raw_results = reader.readtext(pre, detail=1)

print(f"\n--- TOTAL RAW RESULTS: {len(raw_results)} ---")
boxes = []
for bbox, text, conf in raw_results:
    clean = text.strip()
    if clean and conf >= 0.10: # lower threshold from 0.12 to 0.10 or keep 0.10
        ymin = min(p[1] for p in bbox)
        xmin = min(p[0] for p in bbox)
        ymax = max(p[1] for p in bbox)
        xmax = max(p[0] for p in bbox)
        boxes.append((ymin, xmin, ymax, xmax, clean, conf))
        print(f"[{conf:.2f}] {clean}")

lines = pkd.cluster_boxes_into_lines(boxes)
print(f"\n--- CLUSTERED LINES: {len(lines)} ---")
for l in lines:
    print(f"Line (y={l['ymin']:.1f}): {l['text']}")

# Check face coords
script_dir = os.path.join(os.path.dirname(__file__), '..', 'scripts')
portrait, angle, face_coords, score = pkd.detect_face_in_image(img, script_dir)

aadhaar_num = pkd.extract_aadhaar_number(boxes, lines)
dob_mysql, dob_fmt = pkd.extract_dob(boxes, lines)
gender = pkd.extract_gender(boxes, lines)
full_name = pkd.extract_full_name(boxes, lines, face_box=face_coords)
address = pkd.extract_address(boxes, lines)

print("\n--- FINAL EXTRACTED RESULTS ---")
print(f"Name: {full_name}")
print(f"DOB: {dob_mysql} ({dob_fmt})")
print(f"Gender: {gender}")
print(f"Aadhaar: {aadhaar_num}")
print(f"Address: {address}")
