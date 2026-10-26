import re

def test_gender(text):
    if re.search(r'\b(?:FEMALE|IFEHALE|FEHALE|FENALE|FEALE|FERALE|FELE|Mahila)\b', text, re.IGNORECASE):
        return 'Female'
    elif re.search(r'\b(?:MALE|Purush)\b', text, re.IGNORECASE) and not re.search(r'\b(?:FE|MAHILA)\b', text, re.IGNORECASE):
        return 'Male'
    return None

print("FeALE ->", test_gender("FeALE"))
print("3 FeraLE ->", test_gender("3 FeraLE"))
print("FEMALE ->", test_gender("FEMALE"))
