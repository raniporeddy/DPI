import cv2
import easyocr
import re
import numpy as np

img = cv2.imread('uploads/kyc/doc_6ad5d5553318a2.52282524.jpeg')
h, w = img.shape[:2]

reader = easyocr.Reader(['en'], gpu=False, verbose=False)

# Let's crop the address region or scale the image
# Address region on Aadhaar is generally located between 30% and 55% of height, left side
addr_roi = img[int(h * 0.30):int(h * 0.50), int(w * 0.10):int(w * 0.85)]
addr_3x = cv2.resize(addr_roi, None, fx=3.0, fy=3.0, interpolation=cv2.INTER_LANCZOS4)

res = reader.readtext(addr_3x, detail=1)

print("Address ROI (3x Lanczos) raw boxes:")
lines = []
for bbox, text, conf in res:
    ymin = int(min(p[1] for p in bbox))
    xmin = int(min(p[0] for p in bbox))
    ymax = int(max(p[1] for p in bbox))
    xmax = int(max(p[0] for p in bbox))
    print(f"[{ymin:4d}-{ymax:4d}] ({conf:.2f}): {text}")
    lines.append((ymin, text, conf))
