import cv2
import os
import pytesseract
import easyocr
import json

img_path = r"c:\Users\pored\.antigravity-ide\digital-investor\uploads\kyc\doc_6ac8e3b6be7ed7.29593758.jpeg"
image = cv2.imread(img_path)
h, w = image.shape[:2]
print(f"Image Dimensions: Width={w}, Height={h}")

gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)

# Test Tesseract OCR text
try:
    for tpath in [r'C:\Program Files\Tesseract-OCR\tesseract.exe', r'C:\Users\pored\AppData\Local\Programs\Tesseract-OCR\tesseract.exe']:
        if os.path.exists(tpath):
            pytesseract.pytesseract.tesseract_cmd = tpath
            text = pytesseract.image_to_string(gray)
            print("--- Tesseract Raw Text ---")
            print(text)
            break
except Exception as e:
    print(f"Tesseract Error: {e}")

# Test EasyOCR
try:
    reader = easyocr.Reader(['en'], gpu=False, verbose=False)
    results = reader.readtext(img_path)
    print("--- EasyOCR Raw Output ---")
    for bbox, text, prob in results:
        print(f"BBOX: {bbox} | TEXT: {text} | PROB: {prob:.2f}")
except Exception as e:
    print(f"EasyOCR Error: {e}")
