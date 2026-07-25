import smtplib
import os
import re
from email.mime.multipart import MIMEMultipart
from email.mime.base import MIMEBase
from email.mime.text import MIMEText
from email import encoders
from typing import Optional
from app.config import settings

async def send_email_pdf(to_email: str, subject: str, body: str,
                         pdf_data: bytes, filename: str = "invoice.pdf") -> bool:
    if not all([settings.EMAIL_HOST, settings.EMAIL_USER, settings.EMAIL_PASS]):
        raise ValueError("Email settings not configured")
    try:
        msg = MIMEMultipart()
        msg["From"] = f"{settings.COMPANY_NAME} <{settings.EMAIL_USER}>"
        msg["To"] = to_email
        msg["Subject"] = subject

        html_body = f"""
        <html><body>
        <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
            <div style="background: #2c3e50; color: white; padding: 20px; text-align: center;">
                <h2>{settings.COMPANY_NAME}</h2>
            </div>
            <div style="padding: 20px;">
                {body.replace(chr(10), "<br>")}
            </div>
            <div style="background: #f8f9fa; padding: 10px; text-align: center; font-size: 12px; color: #666;">
                {settings.COMPANY_ADDRESS}<br>
                {settings.COMPANY_PHONE} | {settings.COMPANY_EMAIL}
            </div>
        </div>
        </body></html>
        """
        msg.attach(MIMEText(html_body, "html"))

        if pdf_data:
            part = MIMEBase("application", "octet-stream")
            part.set_payload(pdf_data)
            encoders.encode_base64(part)
            part.add_header("Content-Disposition", f"attachment; filename={filename}")
            msg.attach(part)

        with smtplib.SMTP(settings.EMAIL_HOST, settings.EMAIL_PORT) as server:
            server.starttls()
            server.login(settings.EMAIL_USER, settings.EMAIL_PASS)
            server.send_message(msg)
        return True
    except Exception as e:
        print(f"Email sending failed: {e}")
        return False

def _format_phone(number: str) -> str:
    digits = re.sub(r"\D", "", number)
    if digits.startswith("92") and len(digits) == 12:
        return f"+{digits}"
    if digits.startswith("0") and len(digits) == 11:
        return f"+92{digits[1:]}"
    if digits.startswith("92") and len(digits) == 13:
        return f"+{digits}"
    if digits.startswith("0") and len(digits) == 12:
        return f"+92{digits[2:]}"
    if digits.startswith("0092"):
        return f"+{digits[2:]}"
    return f"+{digits}"

async def send_whatsapp_message(to_phone: str, message: str,
                                pdf_bytes: Optional[bytes] = None,
                                pdf_filename: str = "invoice.pdf") -> dict:
    if not settings.WHATSAPP_API_KEY:
        raise ValueError("WhatsApp API not configured")
    try:
        import requests
        phone = _format_phone(to_phone)
        headers = {
            "Authorization": f"Bearer {settings.WHATSAPP_API_KEY}",
            "Content-Type": "application/json"
        }
        url = f"https://graph.facebook.com/v17.0/{settings.WHATSAPP_PHONE_NUMBER_ID}/messages"

        if pdf_bytes:
            media_headers = headers.copy()
            media_headers.pop("Content-Type", None)
            upload_url = f"https://graph.facebook.com/v17.0/{settings.WHATSAPP_PHONE_NUMBER_ID}/media"
            files = {
                "file": (pdf_filename, pdf_bytes, "application/pdf"),
                "messaging_product": (None, "whatsapp"),
                "type": (None, "application/pdf"),
            }
            upload_resp = requests.post(upload_url, headers=media_headers, files=files)
            if upload_resp.status_code == 200:
                media_id = upload_resp.json().get("id")
                data = {
                    "messaging_product": "whatsapp",
                    "to": phone,
                    "type": "document",
                    "document": {"id": media_id, "caption": message, "filename": pdf_filename}
                }
            else:
                data = {
                    "messaging_product": "whatsapp",
                    "to": phone,
                    "type": "text",
                    "text": {"body": message}
                }
        else:
            data = {
                "messaging_product": "whatsapp",
                "to": phone,
                "type": "text",
                "text": {"body": message}
            }

        response = requests.post(url, json=data, headers=headers)
        if response.status_code != 200:
            err_body = response.json()
            err_detail = err_body.get("error", {}).get("message", str(response.text))
            return {"success": False, "error": err_detail}
        return {"success": True, "error": None}
    except Exception as e:
        print(f"WhatsApp sending failed: {e}")
        return {"success": False, "error": str(e)}
