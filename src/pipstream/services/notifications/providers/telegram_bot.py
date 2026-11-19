"""Alerts via a Telegram BOT (Bot API) - separate from the read-only userbot sessions.
The user must /start the bot first; `address` is their chat id."""
import httpx

from .base import register


@register("telegram_bot")
class TelegramBotProvider:
    def __init__(self, settings):
        self.token = settings.telegram_bot_token

    async def send(self, address, subject, body):
        if not self.token or not address:
            return
        async with httpx.AsyncClient(timeout=10) as cli:
            r = await cli.post(f"https://api.telegram.org/bot{self.token}/sendMessage",
                               json={"chat_id": address, "text": f"{subject}\n{body}"})
            r.raise_for_status()
