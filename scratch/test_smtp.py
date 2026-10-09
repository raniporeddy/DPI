import smtplib
import socket

def test_smtp():
    try:
        print("Testing direct connection to Gmail MX...")
        server = smtplib.SMTP('gmail-smtp-in.l.google.com', 25, timeout=10)
        print("Connected to Gmail MX successfully!")
        server.quit()
        return True
    except Exception as e:
        print(f"Direct MX port 25 failed: {e}")
        return False

test_smtp()
