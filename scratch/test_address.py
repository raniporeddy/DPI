import re

lines = [
    {'text': 'Unique Identification Authority of India', 'ymin': 319},
    {'text': 'be3-1s/ Enrolment No : 0000/0084B/71034', 'ymin': 356},
    {'text': 'MNDI HemasWI', 'ymin': 437},
    {'text': 'DIO Bandi Amur Hath', 'ymin': 457},
    {'text': 'nandyala toid', 'ymin': 498},
    {'text': 'Nmydukur', 'ymin': 537},
    {'text': 'Cuddapuh Andhra Pradeen - 516172', 'ymin': 553},
    {'text': '9676616606', 'ymin': 572},
    {'text': 'Signature yulid', 'ymin': 654},
    {'text': 'Your Aadhaar No', 'ymin': 877},
    {'text': '7386 8478 7271', 'ymin': 911}
]

def extract_addr(lines):
    collecting = False
    addr_parts = []
    
    for l in lines:
        t = l['text'].strip()
        if re.search(r'\b(?:Address|Address\s*:|C/O|S/O|D/O|W/O|Co:|So:|Do:|Wo:|D[I/\\|1\-]O|C[I/\\|1\-]O|S[I/\\|1\-]O|W[I/\\|1\-]O)\b', t, re.IGNORECASE):
            collecting = True
            addr_parts.append(t)
            continue
        if collecting:
            if re.search(r'\b(?:Mobile|Phone|Email|Signature|Aadhaar|VID)\b', t, re.IGNORECASE) or '7386' in t:
                break
            addr_parts.append(t)
            if re.search(r'\b[1-9]\d{5}\b', t):
                break
    return ", ".join(addr_parts)

print("Extracted address:", extract_addr(lines))
