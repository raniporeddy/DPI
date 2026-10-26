import cv2
import re
import easyocr
import numpy as np

# Verhoeff algorithm for Aadhaar validation
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
VERHOEFF_INV = [0, 4, 3, 2, 1, 5, 6, 7, 8, 9]

def validate_verhoeff(num_str):
    if not num_str or len(num_str) != 12 or not num_str.isdigit():
        return False
    c = 0
    for i, item in enumerate(reversed(num_str)):
        c = VERHOEFF_D[c][VERHOEFF_P[i % 8][int(item)]]
    return c == 0

img_path = r'uploads/kyc/doc_6acc61eadbadf3.44620607.jpeg'
reader = easyocr.Reader(['en'], gpu=False, verbose=False)
raw_boxes = reader.readtext(img_path)

# Normalize boxes: (ymin, xmin, ymax, xmax, text, conf)
boxes = []
for bbox, text, conf in raw_boxes:
    ymin = min(p[1] for p in bbox)
    xmin = min(p[0] for p in bbox)
    ymax = max(p[1] for p in bbox)
    xmax = max(p[0] for p in bbox)
    clean = text.strip()
    if clean:
        boxes.append((ymin, xmin, ymax, xmax, clean, conf))

boxes.sort(key=lambda b: (b[0], b[1]))

print(f"Total boxes: {len(boxes)}")
for b in boxes:
    if any(k in b[4].lower() for k in ['manasa', 'dob', '5283', 'vid', 'pin', 'road', 'akdanki', 'addanki']):
        print(f"y:[{b[0]:.0f}-{b[2]:.0f}] x:[{b[1]:.0f}-{b[3]:.0f}] conf:{b[5]:.2f} -> {b[4]}")
