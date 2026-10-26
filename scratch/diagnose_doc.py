import os
import sys
import cv2
import json

sys.path.append(os.path.join(os.path.dirname(__file__), '..', 'scripts'))
import process_kyc_document as pkd

doc_path = 'uploads/kyc/doc_6accf3c2662a17.22912885.jpeg'
img = cv2.imread(doc_path)
print("Image shape:", img.shape)

script_dir = os.path.join(os.path.dirname(__file__), '..', 'scripts')
cropped_portrait, best_angle, face_coords, face_score = pkd.detect_face_in_image(img, script_dir)
print(f"Face detection: best_angle={best_angle}, coords={face_coords}, score={face_score}")

upright_image = pkd.get_rotated_image(img, best_angle)
print(f"Upright image shape: {upright_image.shape}")
cv2.imwrite("scratch/test_upright.jpg", upright_image)

preprocessed_img = pkd.preprocess_image_for_ocr(upright_image)
print(f"Preprocessed image shape: {preprocessed_img.shape}")
cv2.imwrite("scratch/test_preprocessed.jpg", preprocessed_img)

import easyocr
reader = easyocr.Reader(['en'], gpu=False, verbose=False)
raw_results = reader.readtext(preprocessed_img, detail=1)

print(f"\n--- TOTAL RAW RESULTS: {len(raw_results)} ---")
for bbox, text, conf in raw_results:
    print(f"[{conf:.2f}] {text} (bbox: {bbox})")

boxes = []
for bbox, text, conf in raw_results:
    clean = text.strip()
    if clean and conf >= 0.12:
        ymin = min(p[1] for p in bbox)
        xmin = min(p[0] for p in bbox)
        ymax = max(p[1] for p in bbox)
        xmax = max(p[0] for p in bbox)
        boxes.append((ymin, xmin, ymax, xmax, clean, conf))

lines = pkd.cluster_boxes_into_lines(boxes)
print(f"\n--- CLUSTERED LINES: {len(lines)} ---")
for l in lines:
    print(f"Line (y={l['ymin']}-{l['ymax']}): {l['text']}")

aadhaar_num = pkd.extract_aadhaar_number(boxes, lines)
dob_mysql, dob_fmt = pkd.extract_dob(boxes, lines)
gender = pkd.extract_gender(boxes, lines)
full_name = pkd.extract_full_name(boxes, lines, face_box=face_coords)
address = pkd.extract_address(boxes, lines)

print("\n--- EXTRACTED RESULTS ---")
print(f"Name: {full_name}")
print(f"DOB: {dob_mysql} ({dob_fmt})")
print(f"Gender: {gender}")
print(f"Aadhaar: {aadhaar_num}")
print(f"Address: {address}")
