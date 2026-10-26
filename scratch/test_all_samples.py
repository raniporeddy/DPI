import os
import sys
import cv2
import json

sys.path.append(os.path.join(os.path.dirname(__file__), '..', 'scripts'))
import process_kyc_document as pkd

docs = [
    'uploads/kyc/doc_6accf3c2662a17.22912885.jpeg',
    'uploads/kyc/doc_6acc61eadbadf3.44620607.jpeg',
    'uploads/kyc/doc_6ac8f0b74880e5.52920008.jpeg',
    'uploads/kyc/test_e2e_1791799155.png'
]

def safe_preprocess(image):
    h, w = image.shape[:2]
    max_dim = max(h, w)
    
    if max_dim < 1000:
        scale = 1400.0 / float(max_dim)
        image = cv2.resize(image, (int(w * scale), int(h * scale)), interpolation=cv2.INTER_CUBIC)
    elif max_dim > 2000:
        scale = 1800.0 / float(max_dim)
        image = cv2.resize(image, (int(w * scale), int(h * scale)), interpolation=cv2.INTER_AREA)

    image = pkd.deskew_image(image)
    return image

import easyocr
reader = easyocr.Reader(['en'], gpu=False, verbose=False)
script_dir = os.path.join(os.path.dirname(__file__), '..', 'scripts')

for doc in docs:
    if not os.path.exists(doc):
        print(f"Skipping {doc} (not found)")
        continue
    img = cv2.imread(doc)
    print(f"\n==========================================")
    print(f"Testing document: {doc} (original shape: {img.shape})")
    
    portrait, angle, face_coords, score = pkd.detect_face_in_image(img, script_dir)
    upright = pkd.get_rotated_image(img, angle)
    pre = safe_preprocess(upright)
    print(f"Angle: {angle}, Preprocessed shape: {pre.shape}")
    
    raw = reader.readtext(pre, detail=1)
    boxes = []
    for bbox, text, conf in raw:
        clean = text.strip()
        if clean and conf >= 0.10:
            ymin = min(p[1] for p in bbox)
            xmin = min(p[0] for p in bbox)
            ymax = max(p[1] for p in bbox)
            xmax = max(p[0] for p in bbox)
            boxes.append((ymin, xmin, ymax, xmax, clean, conf))
            
    lines = pkd.cluster_boxes_into_lines(boxes)
    
    aadhaar_num = pkd.extract_aadhaar_number(boxes, lines)
    dob_mysql, dob_fmt = pkd.extract_dob(boxes, lines)
    gender = pkd.extract_gender(boxes, lines)
    full_name = pkd.extract_full_name(boxes, lines, face_box=face_coords)
    address = pkd.extract_address(boxes, lines)
    
    print(f"Extracted Name:    {full_name}")
    print(f"Extracted DOB:     {dob_mysql} ({dob_fmt})")
    print(f"Extracted Gender:  {gender}")
    print(f"Extracted Aadhaar: {aadhaar_num}")
    print(f"Extracted Address: {address}")
