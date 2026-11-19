"""SMTP email (works with SES / Postmark / Resend SMTP endpoints)."""
import asyncio
import smtplib
from email.message import EmailMessage
from urllib.parse import urlparse

from .base import register


@register("email")
class EmailProvider:
    def __init__(self, settings):
        self.url = urlparse(settings.smtp_url) if settings.smtp_url else None
        self.sender = settings.smtp_from

    def _send(self, to, subject, body):
        msg = EmailMessage()
        msg["From"], msg["To"], msg["Subject"] = self.sender, to, subject
        msg.set_content(body)
        with smtplib.SMTP(self.url.hostname, self.url.port or 587, timeout=15) as s:
            s.starttls()
            if self.url.username:
                s.login(self.url.username, self.url.password or "")
            s.send_message(msg)

    async def send(self, address, subject, body):
        if self.url and address:
            await asyncio.to_thread(self._send, address, subject, body)
