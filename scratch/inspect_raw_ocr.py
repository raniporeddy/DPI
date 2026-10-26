import cv2
import easyocr

img_path = 'uploads/kyc/doc_6accf3c2662a17.22912885.jpeg'
img = cv2.imread(img_path)
print("Image shape:", img.shape)

reader = easyocr.Reader(['en', 'te'], gpu=False) # Telugu or Hindi might also be on Aadhaar
results = reader.readtext(img, detail=1)

print(f"Total detections on raw image: {len(results)}")
for bbox, text, conf in results:
    print(f"Conf: {conf:.2f} | Box: {bbox} | Text: {text}")
