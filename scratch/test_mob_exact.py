import cv2
import easyocr
import numpy as np

img = cv2.imread('uploads/kyc/doc_6ad5d5553318a2.52282524.jpeg')
h, w = img.shape[:2]

addr_roi = img[int(h * 0.30):int(h * 0.50), int(w * 0.10):int(w * 0.85)]
addr_3x = cv2.resize(addr_roi, None, fx=3.0, fy=3.0, interpolation=cv2.INTER_LANCZOS4)

# Line with the number is roughly y: 460 to 530, x: 0 to 400
mob_crop = addr_3x[460:530, 0:400]
cv2.imwrite('scratch/mob_crop_exact.jpg', mob_crop)

reader = easyocr.Reader(['en'], gpu=False, verbose=False)

for scale in [1.0, 1.5, 2.0]:
    scaled = cv2.resize(mob_crop, None, fx=scale, fy=scale, interpolation=cv2.INTER_CUBIC)
    gray = cv2.cvtColor(scaled, cv2.COLOR_BGR2GRAY)
    
    # Try different thresholds
    for th_val in [0, 120, 140, 160]:
        if th_val == 0:
            target = gray
            name = f"Scale {scale} Gray"
        else:
            _, target = cv2.threshold(gray, th_val, 255, cv2.THRESH_BINARY)
            name = f"Scale {scale} Thresh {th_val}"
            
        res = reader.readtext(target, allowlist='0123456789', detail=1)
        for b, t, c in res:
            print(f"{name}: {t} ({c:.2f})")
