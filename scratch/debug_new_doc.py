import os
import sys
import cv2
import json

# Add scripts directory
sys.path.append(os.path.join(os.path.dirname(__file__), '..', 'scripts'))
from process_kyc_document import (
    load_image_or_pdf_pages,
    correct_orientation,
    preprocess_image,
    extract_text_and_boxes,
    extract_fields_from_ocr,
    get_easyocr_reader
)

doc_path = 'uploads/kyc/doc_6accf3c2662a17.22912885.jpeg'
print(f"Loading document: {doc_path}")

pages = load_image_or_pdf_pages(doc_path)
print(f"Total pages loaded: {len(pages)}")

for i, page in enumerate(pages):
    print(f"Page {i} shape: {page.shape}")
    
    # Check orientation
    oriented, angle = correct_orientation(page)
    print(f"Detected rotation angle: {angle}, Oriented shape: {oriented.shape}")
    
    # Save oriented image to scratch for inspection
    cv2.imwrite(f"scratch/page_{i}_oriented.jpg", oriented)
    
    # Preprocess
    pre = preprocess_image(oriented)
    cv2.imwrite(f"scratch/page_{i}_preprocessed.jpg", pre)
    
    reader = get_easyocr_reader()
    raw_ocr = extract_text_and_boxes(pre, reader)
    print(f"Total raw OCR items extracted: {len(raw_ocr)}")
    for j, item in enumerate(raw_ocr):
        print(f"[{j}] text: '{item['text']}', box: {item['box']}, conf: {item['conf']:.2f}")
        
    extracted = extract_fields_from_ocr(raw_ocr)
    print("\n--- EXTRACTED FIELDS ---")
    print(json.dumps(extracted, indent=2))
