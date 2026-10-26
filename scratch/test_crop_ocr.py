import cv2
import easyocr
import numpy as np

img = cv2.imread('uploads/kyc/doc_6ad5d5553318a2.52282524.jpeg')
# The address block is located roughly at y: 400 to 580, x: 50 to 350
crop = img[410:570, 60:350]
cv2.imwrite('scratch/addr_crop.jpg', crop)

reader = easyocr.Reader(['en'], gpu=False, verbose=False)

# Test 1: plain crop
print("--- TEST 1: Plain Crop ---")
for b, t, c in reader.readtext(crop, detail=1):
    print(f"({c:.2f}): {t}")

# Test 2: upscale 3x Lanczos
crop_3x = cv2.resize(crop, None, fx=3.0, fy=3.0, interpolation=cv2.INTER_LANCZOS4)
print("\n--- TEST 2: 3x Lanczos ---")
for b, t, c in reader.readtext(crop_3x, detail=1):
    print(f"({c:.2f}): {t}")

# Test 3: CLAHE + upscale
gray = cv2.cvtColor(crop_3x, cv2.COLOR_BGR2GRAY)
clahe = cv2.createCLAHE(clipLimit=2.0, tileGridSize=(8,8))
enhanced = clahe.apply(gray)
print("\n--- TEST 3: CLAHE Gray ---")
for b, t, c in reader.readtext(enhanced, detail=1):
    print(f"({c:.2f}): {t}")

# Test 4: Thresholding
_, th = cv2.threshold(enhanced, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)
print("\n--- TEST 4: OTSU Threshold ---")
for b, t, c in reader.readtext(th, detail=1):
    print(f"({c:.2f}): {t}")

# Test 5: Digits allowlist on the mobile number line (y: 535 to 570 in original, so bottom part of crop)
mob_crop = crop[120:, :]
mob_3x = cv2.resize(mob_crop, None, fx=4.0, fy=4.0, interpolation=cv2.INTER_LANCZOS4)
mob_gray = cv2.cvtColor(mob_3x, cv2.COLOR_BGR2GRAY)
print("\n--- TEST 5: Mobile Crop Allowlist ---")
for b, t, c in reader.readtext(mob_gray, allowlist='0123456789', detail=1):
    print(f"({c:.2f}): {t}")
