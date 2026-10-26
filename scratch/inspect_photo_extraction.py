"""
Inspect the detected face bounding box coordinates and test a high-quality
direct crop from the original document image without any preprocessing.
"""
import cv2
import os
import sys

img = cv2.imread('uploads/kyc/doc_6ad5d5553318a2.52282524.jpeg')
h, w = img.shape[:2]
print(f"Original doc: {w}x{h} pixels")

script_dir = 'scripts'

# --- Test YuNet and Haar detection ---
onnx_path = os.path.join(script_dir, 'face_detection_yunet_2023mar.onnx')
frontal_xml = os.path.join(script_dir, 'haarcascade_frontalface_default.xml')

print(f"\nYuNet ONNX exists: {os.path.exists(onnx_path)}")
print(f"Haar XML exists: {os.path.exists(frontal_xml)}")

candidates = []

# Try YuNet
if os.path.exists(onnx_path) and hasattr(cv2, 'FaceDetectorYN_create'):
    try:
        detector = cv2.FaceDetectorYN_create(onnx_path, "", (w, h), score_threshold=0.25)
        _, yfaces = detector.detect(img)
        if yfaces is not None:
            print(f"\nYuNet found {len(yfaces)} face(s):")
            for f in yfaces:
                fx, fy, fw, fh = int(f[0]), int(f[1]), int(f[2]), int(f[3])
                score = float(f[14])
                print(f"  x:{fx} y:{fy} w:{fw} h:{fh}  score:{score:.3f}  center:({fx+fw//2},{fy+fh//2})")
                candidates.append((fx, fy, fw, fh, score, 0))
    except Exception as e:
        print(f"YuNet error: {e}")

# Haar cascade skipped (cv2.CascadeClassifier not available in this build)
print(f"\nNote: Haar cascade skipped (not available in this OpenCV build)")

# Save annotated image for inspection
vis = img.copy()
for (fx, fy, fw, fh, sc, _) in candidates:
    cv2.rectangle(vis, (fx, fy), (fx+fw, fy+fh), (0, 255, 0), 2)
cv2.imwrite('scratch/face_detection_debug.jpg', vis)
print("\nSaved annotated image to scratch/face_detection_debug.jpg")

# Best candidate
if candidates:
    best = max(candidates, key=lambda c: c[4])
    fx, fy, fw, fh, sc, _ = best
    print(f"\nBest face: x:{fx} y:{fy} w:{fw} h:{fh} score:{sc:.3f}")
    
    # Current crop logic (with padding)
    pad_top = int(fh * 0.35)
    pad_bottom = int(fh * 0.60)
    pad_left = int(fw * 0.30)
    pad_right = int(fw * 0.30)
    cy1 = max(0, fy - pad_top)
    cy2 = min(h, fy + fh + pad_bottom)
    cx1 = max(0, fx - pad_left)
    cx2 = min(w, fx + fw + pad_right)
    
    current_crop = img[cy1:cy2, cx1:cx2]
    print(f"Current padded crop size: {current_crop.shape[1]}x{current_crop.shape[0]}")
    cv2.imwrite('scratch/photo_current_crop.png', current_crop)
    
    # Raw face crop (no padding)
    raw_crop = img[fy:fy+fh, fx:fx+fw]
    print(f"Raw face crop size: {raw_crop.shape[1]}x{raw_crop.shape[0]}")
    cv2.imwrite('scratch/photo_raw_crop.png', raw_crop)
    
    # Generous portrait crop (more padding for a natural portrait look)
    gpad_top = int(fh * 0.6)
    gpad_bottom = int(fh * 0.7)
    gpad_left = int(fw * 0.5)
    gpad_right = int(fw * 0.5)
    gy1 = max(0, fy - gpad_top)
    gy2 = min(h, fy + fh + gpad_bottom)
    gx1 = max(0, fx - gpad_left)
    gx2 = min(w, fx + fw + gpad_right)
    gen_crop = img[gy1:gy2, gx1:gx2]
    print(f"Generous portrait crop size: {gen_crop.shape[1]}x{gen_crop.shape[0]}")
    cv2.imwrite('scratch/photo_generous_crop.png', gen_crop)
else:
    print("\nNo face detected! Trying manual crop based on document layout...")
    # Aadhaar compact card (bottom half) - photo is at bottom-left
    # Bottom card starts roughly at y=1000 of 1280, photo on left
    manual = img[1000:1270, 10:140]
    print(f"Manual crop: {manual.shape[1]}x{manual.shape[0]}")
    cv2.imwrite('scratch/photo_manual_crop.png', manual)
