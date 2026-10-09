#!/usr/bin/env python3
"""
Offline Python/OpenCV Portrait Extraction Script for eKYC System.
Uses multi-detector & multi-orientation face portrait detection.
Finds the person's face inside identity document images, crops ONLY the person's photograph (expanding to full head, neck, and shoulders portrait),
and saves it locally.

If NO face is detected on the document, it fails cleanly with an error message without cropping card headers, text, logos, or top-left corners.
"""

import sys
import os

def get_rotated_image(img, angle):
    if angle == 90:
        import cv2
        return cv2.rotate(img, cv2.ROTATE_90_CLOCKWISE)
    elif angle == 180:
        import cv2
        return cv2.rotate(img, cv2.ROTATE_180)
    elif angle == 270:
        import cv2
        return cv2.rotate(img, cv2.ROTATE_90_COUNTERCLOCKWISE)
    return img

def detect_and_extract_portrait(input_path, output_path):
    if not os.path.exists(input_path):
        print("ERROR: Input document file does not exist.")
        sys.exit(1)

    try:
        import cv2
        import numpy as np
    except ImportError:
        print("ERROR: OpenCV library (cv2) is not installed.")
        sys.exit(2)

    image = cv2.imread(input_path)
    if image is None:
        print("ERROR: Unable to read image file binary.")
        sys.exit(3)

    img_h, img_w = image.shape[:2]
    if img_w < 40 or img_h < 40:
        print("ERROR: Image resolution is too small for portrait extraction.")
        sys.exit(4)

    script_dir = os.path.dirname(os.path.abspath(__file__))
    frontal_xml = os.path.join(script_dir, 'haarcascade_frontalface_default.xml')
    profile_xml = os.path.join(script_dir, 'haarcascade_profileface.xml')
    onnx_path = os.path.join(script_dir, 'face_detection_yunet_2023mar.onnx')

    candidates = []

    for angle in [0, 90, 180, 270]:
        rot_img = get_rotated_image(image, angle)
        rh, rw = rot_img.shape[:2]
        gray = cv2.cvtColor(rot_img, cv2.COLOR_BGR2GRAY)

        # 1. Haar Frontal Face Detector
        if os.path.exists(frontal_xml) and hasattr(cv2, 'CascadeClassifier'):
            try:
                cascade = cv2.CascadeClassifier(frontal_xml)
                for sf in [1.05, 1.08, 1.12, 1.18, 1.25]:
                    faces = cascade.detectMultiScale(
                        gray,
                        scaleFactor=sf,
                        minNeighbors=2,
                        minSize=(25, 25),
                        maxSize=(int(rw * 0.80), int(rh * 0.80))
                    )
                    for (fx, fy, fw, fh) in faces:
                        aspect = float(fh) / fw if fw > 0 else 0
                        if 0.7 <= aspect <= 1.8:
                            pos_weight = 1.4 if (fx + fw / 2.0) < (rw * 0.60) else 1.0
                            score = (fw * fh) * pos_weight * 2.0
                            candidates.append((fx, fy, fw, fh, score, angle, rot_img))
            except Exception:
                pass

        # 2. YuNet ONNX Deep Face Detector
        if os.path.exists(onnx_path) and hasattr(cv2, 'FaceDetectorYN_create'):
            try:
                detector = cv2.FaceDetectorYN_create(
                    onnx_path,
                    "",
                    (rw, rh),
                    score_threshold=0.30,
                    nms_threshold=0.30
                )
                _, yfaces = detector.detect(rot_img)
                if yfaces is not None and len(yfaces) > 0:
                    for f in yfaces:
                        fx, fy, fw, fh = int(f[0]), int(f[1]), int(f[2]), int(f[3])
                        if fw >= 20 and fh >= 20:
                            pos_weight = 1.4 if (fx + fw / 2.0) < (rw * 0.60) else 1.0
                            score = (fw * fh) * pos_weight * 3.0
                            candidates.append((fx, fy, fw, fh, score, angle, rot_img))
            except Exception:
                pass

        # 3. Haar Profile Face Detector
        if not candidates and os.path.exists(profile_xml) and hasattr(cv2, 'CascadeClassifier'):
            try:
                cascade_prof = cv2.CascadeClassifier(profile_xml)
                faces_prof = cascade_prof.detectMultiScale(gray, scaleFactor=1.08, minNeighbors=2, minSize=(25, 25))
                for (fx, fy, fw, fh) in faces_prof:
                    pos_weight = 1.4 if (fx + fw / 2.0) < (rw * 0.60) else 1.0
                    score = (fw * fh) * pos_weight * 1.5
                    candidates.append((fx, fy, fw, fh, score, angle, rot_img))
            except Exception:
                pass

        # 4. Skin Tone Color Density Analysis (YCrCb + HSV)
        if not candidates:
            try:
                ycrcb = cv2.cvtColor(rot_img, cv2.COLOR_BGR2YCrCb)
                hsv = cv2.cvtColor(rot_img, cv2.COLOR_BGR2HSV)
                mask_ycrcb = cv2.inRange(ycrcb, np.array([0, 133, 77], dtype=np.uint8), np.array([255, 173, 127], dtype=np.uint8))
                mask_hsv = cv2.inRange(hsv, np.array([0, 30, 60], dtype=np.uint8), np.array([25, 250, 255], dtype=np.uint8))
                mask = cv2.bitwise_and(mask_ycrcb, mask_hsv)

                kernel = cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (5, 5))
                mask = cv2.morphologyEx(mask, cv2.MORPH_OPEN, kernel, iterations=2)
                mask = cv2.morphologyEx(mask, cv2.MORPH_CLOSE, kernel, iterations=2)

                contours, _ = cv2.findContours(mask, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
                img_area = rw * rh

                for cnt in contours:
                    area = cv2.contourArea(cnt)
                    if (0.01 * img_area) <= area <= (0.35 * img_area):
                        fx, fy, fw, fh = cv2.boundingRect(cnt)
                        aspect = float(fh) / fw if fw > 0 else 0
                        if 0.8 <= aspect <= 2.2:
                            pos_weight = 1.3 if (fx + fw / 2.0) < (rw * 0.60) else 0.9
                            score = area * pos_weight
                            candidates.append((fx, fy, fw, fh, score, angle, rot_img))
            except Exception:
                pass

    if not candidates:
        print("ERROR: Face photo extraction failed. Please upload a clear Aadhaar image.")
        sys.exit(5)

    best = max(candidates, key=lambda c: c[4])
    fx, fy, fw, fh, score, angle, rot_img = best
    rh, rw = rot_img.shape[:2]

    # Calculate padding for complete anatomical portrait (head + hair + neck + shoulders)
    pad_top = int(fh * 0.40)
    pad_bottom = int(fh * 0.85)
    pad_left = int(fw * 0.35)
    pad_right = int(fw * 0.35)

    crop_y1 = max(0, int(fy - pad_top))
    crop_y2 = min(rh, int(fy + fh + pad_bottom))
    crop_x1 = max(0, int(fx - pad_left))
    crop_x2 = min(rw, int(fx + fw + pad_right))

    crop_w = crop_x2 - crop_x1
    crop_h = crop_y2 - crop_y1

    if crop_w < 25 or crop_h < 25 or crop_w >= (rw * 0.88) or crop_h >= (rh * 0.88):
        print("ERROR: Face photo extraction failed. Please upload a clear Aadhaar image.")
        sys.exit(5)

    cropped_portrait = rot_img[crop_y1:crop_y2, crop_x1:crop_x2]

    os.makedirs(os.path.dirname(os.path.abspath(output_path)), exist_ok=True)
    success = cv2.imwrite(output_path, cropped_portrait)

    if success and os.path.exists(output_path) and os.path.getsize(output_path) > 100:
        print(f"SUCCESS: Extracted person photo saved to {output_path}")
        sys.exit(0)
    else:
        print("ERROR: Failed to write extracted portrait image file.")
        sys.exit(6)

if __name__ == '__main__':
    if len(sys.argv) < 3:
        print("Usage: extract_photo.py <input_path> <output_path>")
        sys.exit(1)

    detect_and_extract_portrait(sys.argv[1], sys.argv[2])
