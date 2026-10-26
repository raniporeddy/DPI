import cv2
import easyocr
import numpy as np

img = cv2.imread('uploads/kyc/doc_6ad5d5553318a2.52282524.jpeg')
# Address block roughly: y 410 to 570, x 60 to 350
crop = img[410:560, 60:350]

reader = easyocr.Reader(['en'], gpu=False, verbose=False)

# Let's test a few resizings and sharpening
for scale in [2.0, 2.5, 3.0, 3.5]:
    resized = cv2.resize(crop, None, fx=scale, fy=scale, interpolation=cv2.INTER_CUBIC)
    
    # Method A: plain resize
    resA = reader.readtext(resized, detail=0)
    print(f"\n--- Scale {scale} Plain ---")
    print(" | ".join(resA))
    
    # Method B: unsharp mask
    gaussian = cv2.GaussianBlur(resized, (0, 0), 2.0)
    unsharp = cv2.addWeighted(resized, 1.5, gaussian, -0.5, 0)
    resB = reader.readtext(unsharp, detail=0)
    print(f"--- Scale {scale} Unsharp ---")
    print(" | ".join(resB))

    # Method C: Bilateral + Sharpen
    bila = cv2.bilateralFilter(resized, 5, 50, 50)
    bila_sharp = cv2.addWeighted(bila, 1.4, cv2.GaussianBlur(bila, (0, 0), 1.5), -0.4, 0)
    resC = reader.readtext(bila_sharp, detail=0)
    print(f"--- Scale {scale} Bilateral+Sharp ---")
    print(" | ".join(resC))
