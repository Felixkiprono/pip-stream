import logging

from .base import register

log = logging.getLogger("pipstream.notify")


@register("log")
class LogProvider:
    def __init__(self, settings):
        pass

    async def send(self, address, subject, body):
        log.info("notify", extra={"ctx": {"to": address, "subject": subject, "body": body}})
