import cv2
import easyocr
import re
import numpy as np

img = cv2.imread('uploads/kyc/doc_6ad5d5553318a2.52282524.jpeg')
h, w = img.shape[:2]

reader = easyocr.Reader(['en'], gpu=False, verbose=False)

# Let's upscale the entire image by 2.5x with INTER_CUBIC or Lanczos
# and test what boxes we get across the image
scale = 2.5
upscaled = cv2.resize(img, (int(w * scale), int(h * scale)), interpolation=cv2.INTER_LANCZOS4)

# Find PIN code in boxes
boxes_raw = reader.readtext(upscaled, detail=1)

pin_box = None
for bbox, text, conf in boxes_raw:
    if re.search(r'\b[1-9]\d{5}\b', text) or re.search(r'[=-]\s*[1-9]\d{4,5}\b', text):
        ymin = int(min(p[1] for p in bbox))
        xmin = int(min(p[0] for p in bbox))
        ymax = int(max(p[1] for p in bbox))
        xmax = int(max(p[0] for p in bbox))
        pin_box = (ymin, xmin, ymax, xmax, text, conf)
        print(f"Found PIN box: {pin_box}")
        break

if pin_box:
    # Crop region below PIN code
    ymin, xmin, ymax, xmax, text, conf = pin_box
    # Region directly below PIN box (up to 200px down at 2.5x scale)
    crop_mob = upscaled[ymax:min(upscaled.shape[0], ymax + 200), max(0, xmin - 50):min(upscaled.shape[1], xmin + 350)]
    cv2.imwrite('scratch/mob_test.jpg', crop_mob)
    
    # Run OCR with allowlist digits
    mob_res = reader.readtext(crop_mob, detail=1)
    print("Crop below PIN normal:")
    for b, t, c in mob_res:
        print(f"  ({c:.2f}): {t}")
        
    mob_res_digits = reader.readtext(crop_mob, allowlist='0123456789', detail=1)
    print("Crop below PIN digits allowlist:")
    for b, t, c in mob_res_digits:
        print(f"  ({c:.2f}): {t}")
