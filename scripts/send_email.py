#!/usr/bin/env python3
"""
Python Script for Dispatching SMTP Emails (Built-in smtplib & email.mime):
Dispatches HTML eSign request emails to recipient email addresses over TLS/SSL.
"""

import sys
import os
import json
import smtplib
from email.mime.multipart import MIMEMultipart
from email.mime.text import MIMEText

def send_email(to_email, subject, html_content, from_email, from_name, smtp_host, smtp_port, smtp_user, smtp_pass):
    if not to_email:
        print(json.dumps({"success": False, "error": "Recipient email address is required."}))
        sys.exit(1)

    if not smtp_host or not smtp_user or not smtp_pass:
        print(json.dumps({"success": False, "error": "SMTP credentials (Host, User, Password) are required."}))
        sys.exit(2)

    try:
        port = int(smtp_port) if smtp_port else 587

        # For Gmail / standard SMTP, envelope sender should match authenticated user
        sender_email = smtp_user if ("gmail" in smtp_host.lower() or "noreply" in from_email.lower() or not from_email) else from_email

        msg = MIMEMultipart('alternative')
        msg['Subject'] = subject
        msg['From'] = f"{from_name} <{sender_email}>" if from_name else sender_email
        msg['To'] = to_email

        part = MIMEText(html_content, 'html', 'utf-8')
        msg.attach(part)

        if port == 465:
            server = smtplib.SMTP_SSL(smtp_host, port, timeout=20)
        else:
            server = smtplib.SMTP(smtp_host, port, timeout=20)
            server.starttls()

        server.login(smtp_user, smtp_pass)
        server.sendmail(sender_email, [to_email], msg.as_string())
        server.quit()

        print(json.dumps({"success": True, "message": f"Email successfully delivered to {to_email}"}))
        sys.exit(0)

    except Exception as e:
        err_msg = str(e)
        if "AuthenticationFailed" in err_msg or "5.7.8" in err_msg or "Username and Password not accepted" in err_msg:
            err_msg = "Gmail SMTP Authentication Failed. Please make sure you are using a 16-character App Password (not your normal Gmail password)."
        print(json.dumps({"success": False, "error": err_msg}))
        sys.exit(3)

if __name__ == '__main__':
    if len(sys.argv) < 10:
        print(json.dumps({"success": False, "error": "Usage: send_email.py <to> <subject> <html_file> <from_email> <from_name> <host> <port> <user> <pass>"}))
        sys.exit(1)

    to_addr = sys.argv[1]
    subj = sys.argv[2]
    html_file = sys.argv[3]
    from_addr = sys.argv[4]
    from_name = sys.argv[5]
    host = sys.argv[6]
    port = sys.argv[7]
    user = sys.argv[8]
    pwd = sys.argv[9]

    html_body = ""
    if os.path.exists(html_file):
        with open(html_file, 'r', encoding='utf-8') as f:
            html_body = f.read()

    send_email(to_addr, subj, html_body, from_addr, from_name, host, port, user, pwd)
