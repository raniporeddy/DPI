#!/usr/bin/env python3
"""
Generate high quality DEMO Aadhaar cards with realistic person portrait photos for offline eKYC testing.
"""

import os
import cv2
import numpy as np
from PIL import Image, ImageDraw, ImageFont

def draw_person_portrait(width, height):
    """
    Draw a realistic human face & shoulder portrait for demo card testing.
    Includes face oval, skin tone shading, eyes, eyebrows, nose, mouth, hair, shoulders.
    """
    img = Image.new('RGB', (width, height), (240, 242, 245))
    draw = ImageDraw.Draw(img)

    # Shoulders / Shirt (Navy Blue)
    draw.ellipse([width * 0.1, height * 0.65, width * 0.9, height * 1.3], fill=(30, 70, 140))
    
    # Neck (Skin Tone)
    draw.rectangle([width * 0.38, height * 0.52, width * 0.62, height * 0.70], fill=(225, 172, 142))

    # Face Oval (Skin Tone)
    draw.ellipse([width * 0.22, height * 0.18, width * 0.78, height * 0.62], fill=(235, 185, 155))

    # Hair (Dark Brown / Black)
    draw.ellipse([width * 0.20, height * 0.10, width * 0.80, height * 0.38], fill=(30, 25, 25))

    # Eyebrows
    draw.line([width * 0.32, height * 0.33, width * 0.44, height * 0.33], fill=(40, 30, 20), width=4)
    draw.line([width * 0.56, height * 0.33, width * 0.68, height * 0.33], fill=(40, 30, 20), width=4)

    # Eyes (White & Pupil)
    draw.ellipse([width * 0.33, height * 0.36, width * 0.43, height * 0.42], fill=(255, 255, 255))
    draw.ellipse([width * 0.57, height * 0.36, width * 0.67, height * 0.42], fill=(255, 255, 255))
    
    draw.ellipse([width * 0.36, height * 0.37, width * 0.40, height * 0.41], fill=(20, 20, 20))
    draw.ellipse([width * 0.60, height * 0.37, width * 0.64, height * 0.41], fill=(20, 20, 20))

    # Nose
    draw.line([width * 0.50, height * 0.40, width * 0.47, height * 0.48], fill=(200, 140, 110), width=3)
    draw.line([width * 0.47, height * 0.48, width * 0.53, height * 0.48], fill=(200, 140, 110), width=3)

    # Mouth / Smile
    draw.arc([width * 0.40, height * 0.49, width * 0.60, height * 0.56], start=10, end=170, fill=(180, 80, 80), width=4)

    return img

def create_demo_card(output_path, name="John Doe", dob="22/08/1994", gender="Male", aadhaar_no="9999 8888 7777"):
    card_w, card_h = 800, 500
    card = Image.new('RGB', (card_w, card_h), (255, 255, 255))
    draw = ImageDraw.Draw(card)

    # Header Bar (Dark Navy)
    draw.rectangle([0, 0, card_w, 65], fill=(12, 43, 82))
    
    # Fonts
    try:
        font_hdr = ImageFont.truetype("arial.ttf", 22)
        font_sub = ImageFont.truetype("arialbd.ttf", 20)
        font_text = ImageFont.truetype("arial.ttf", 18)
        font_bold = ImageFont.truetype("arialbd.ttf", 20)
        font_num = ImageFont.truetype("arialbd.ttf", 24)
    except:
        font_hdr = font_sub = font_text = font_bold = font_num = ImageFont.load_default()

    draw.text((card_w // 2, 32), "UNIQUE IDENTIFICATION AUTHORITY OF INDIA", fill=(255, 255, 255), font=font_hdr, anchor="mm")

    # Government Header
    draw.text((320, 90), "GOVERNMENT OF INDIA", fill=(0, 0, 0), font=font_sub)

    # Person Photo Slot (Left Side)
    photo_w, photo_h = 210, 270
    photo_x, photo_y = 50, 100
    
    # Draw photo frame shadow & border
    draw.rectangle([photo_x - 3, photo_y - 3, photo_x + photo_w + 3, photo_y + photo_h + 3], fill=(200, 200, 200))
    
    # Paste realistic portrait photo
    portrait = draw_person_portrait(photo_w, photo_h)
    card.paste(portrait, (photo_x, photo_y))

    # Text details
    start_y = 150
    draw.text((320, start_y), f"Name: {name}", fill=(30, 30, 30), font=font_bold)
    draw.text((320, start_y + 45), f"DOB: {dob}", fill=(30, 30, 30), font=font_text)
    draw.text((320, start_y + 85), f"Gender: {gender}", fill=(30, 30, 30), font=font_text)

    # QR Code Placeholder Box
    qr_x, qr_y, qr_s = 600, 150, 140
    draw.rectangle([qr_x, qr_y, qr_x + qr_s, qr_y + qr_s], fill=(230, 230, 230), outline=(150, 150, 150), width=2)
    draw.text((qr_x + qr_s // 2, qr_y + qr_s // 2), "[ QR CODE ]", fill=(100, 100, 100), font=font_text, anchor="mm")

    # Bottom Aadhaar Number Bar
    draw.line([30, 410, card_w - 30, 410], fill=(220, 50, 50), width=3)
    draw.text((card_w // 2, 450), f"Aadhaar No: {aadhaar_no}", fill=(0, 0, 0), font=font_num, anchor="mm")

    os.makedirs(os.path.dirname(output_path), exist_ok=True)
    card.save(output_path)
    print(f"Generated demo card at: {output_path}")

if __name__ == '__main__':
    base_dir = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
    create_demo_card(os.path.join(base_dir, "uploads", "kyc", "demo_aadhaar_card.png"), "John Doe", "22/08/1994", "Male", "9999 8888 7777")
    create_demo_card(os.path.join(base_dir, "uploads", "kyc", "demo_identity_card_sample.png"), "Poreddy Rani", "15/05/1996", "Female", "9876 5432 1098")
