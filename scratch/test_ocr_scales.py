import cv2
import easyocr

img_path = 'uploads/kyc/doc_6accf3c2662a17.22912885.jpeg'
img = cv2.imread(img_path)
h, w = img.shape[:2]
print(f"Original shape: {img.shape}")

reader = easyocr.Reader(['en'], gpu=False, verbose=False)

# Test 1: Original image as is
print("\n--- TEST 1: Original Image ---")
res1 = reader.readtext(img)
for bbox, text, conf in res1:
    print(f"[{conf:.2f}] {text}")

# Test 2: Resized to width 800
print("\n--- TEST 2: Resized w=800 ---")
scale2 = 800.0 / w
img2 = cv2.resize(img, (800, int(h * scale2)), interpolation=cv2.INTER_CUBIC)
res2 = reader.readtext(img2)
for bbox, text, conf in res2:
    print(f"[{conf:.2f}] {text}")

# Test 3: Card section crop (bottom ~35%)
print("\n--- TEST 3: Bottom card crop ---")
card_crop = img[int(h * 0.70):, :]
cv2.imwrite("scratch/card_crop.jpg", card_crop)
res3 = reader.readtext(card_crop)
for bbox, text, conf in res3:
    print(f"[{conf:.2f}] {text}")

# Test 4: Top address block crop (top 15% to 50%)
print("\n--- TEST 4: Address block crop ---")
addr_crop = img[int(h * 0.20):int(h * 0.50), :]
cv2.imwrite("scratch/addr_crop.jpg", addr_crop)
res4 = reader.readtext(addr_crop)
for bbox, text, conf in res4:
    print(f"[{conf:.2f}] {text}")
