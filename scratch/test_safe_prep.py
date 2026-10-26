import cv2
import numpy as np

def safe_preprocess(image):
    h, w = image.shape[:2]
    max_dim = max(h, w)
    min_dim = min(h, w)
    
    # Target max dimension between 1200 and 1800
    if max_dim < 1000:
        scale = min(2.0, 1400.0 / max_dim)
        target_w = int(w * scale)
        target_h = int(h * scale)
        image = cv2.resize(image, (target_w, target_h), interpolation=cv2.INTER_CUBIC)
    elif max_dim > 2200:
        scale = 1800.0 / max_dim
        target_w = int(w * scale)
        target_h = int(h * scale)
        image = cv2.resize(image, (target_w, target_h), interpolation=cv2.INTER_AREA)

    # Mild CLAHE only if contrast is low
    gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
    if gray.std() < 55:
        lab = cv2.cvtColor(image, cv2.COLOR_BGR2LAB)
        l, a, b = cv2.split(lab)
        clahe = cv2.createCLAHE(clipLimit=1.5, tileGridSize=(8, 8))
        cl = clahe.apply(l)
        image = cv2.cvtColor(cv2.merge((cl, a, b)), cv2.COLOR_LAB2BGR)

    return image
