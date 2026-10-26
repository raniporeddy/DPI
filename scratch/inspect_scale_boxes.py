import cv2
import easyocr
import re

img_path = 'uploads/kyc/doc_6ad5d5553318a2.52282524.jpeg'
img = cv2.imread(img_path)
h, w = img.shape[:2]
print(f"Original shape: {w}x{h}")

reader = easyocr.Reader(['en'], gpu=False, verbose=False)
res_orig = reader.readtext(img, detail=1)

print("\n--- ORIGINAL IMAGE BOXES ---")
for bbox, text, conf in res_orig:
    ymin = int(min(p[1] for p in bbox))
    xmin = int(min(p[0] for p in bbox))
    ymax = int(max(p[1] for p in bbox))
    xmax = int(max(p[0] for p in bbox))
    print(f"y:[{ymin:4d}-{ymax:4d}] x:[{xmin:4d}-{xmax:4d}] ({conf:.2f}): {text}")

# Test with 2.5x upscaling
scale = 2.5
img_upscaled = cv2.resize(img, (int(w * scale), int(h * scale)), interpolation=cv2.INTER_LANCZOS4)
res_up = reader.readtext(img_upscaled, detail=1)

print("\n--- 2.5x UPSCALED IMAGE BOXES ---")
for bbox, text, conf in res_up:
    ymin = int(min(p[1] for p in bbox))
    xmin = int(min(p[0] for p in bbox))
    ymax = int(max(p[1] for p in bbox))
    xmax = int(max(p[0] for p in bbox))
    print(f"y:[{ymin:4d}-{ymax:4d}] x:[{xmin:4d}-{xmax:4d}] ({conf:.2f}): {text}")
