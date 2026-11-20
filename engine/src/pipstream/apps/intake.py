"""intake = Telegram ingestor + signal parser + account-linking flow + parse-preview, in one
process. Holds the Telegram sessions (PS_INTAKE_PRIVATE_KEY) and nothing that can trade."""
from __future__ import annotations

import asyncio

from ..common.events import Topic
from ..common.service import Context, run
from ..services.ingestor.login_flow import LoginFlow
from ..services.ingestor.service import IngestorService
from ..services.parser.service import ParserService
from .preview import serve_preview


async def main(c: Context, *, http: bool = True, client_factory=None) -> None:
    ing = IngestorService(c)
    par = ParserService(c)
    await ing.load_config()
    await par.load_profiles()

    async def relink(_account_id: str) -> None:
        await ing.load_config()
        await ing.sync_sessions()

    if client_factory is None and c.settings.ingestor_fake_telegram:
        if c.settings.env == "production":
            raise SystemExit("PS_INGESTOR_FAKE_TELEGRAM must not be set in production")
        from ..devtools.fake_telegram import FakeTelegramClient
        client_factory = FakeTelegramClient
        c.log.warning("fake Telegram enabled: web logins accept code 12345")
    login = LoginFlow(c, client_factory=client_factory, on_linked=relink)

    async def on_config(ev):
        await ing.on_config(ev)
        await par.on_config(ev)

    tasks = [
        c.bus.broadcast([Topic.CONFIG_CHANGED], on_config),
        c.bus.subscribe([Topic.TG_MESSAGE_NEW, Topic.TG_MESSAGE_EDITED], par.handle, consumer=c.consumer),
        c.bus.subscribe([Topic.TG_LOGIN_REQUEST], login.handle, consumer=c.consumer, concurrency=1,
                        max_deliveries=1),
    ]
    if ing.replay:
        from ..services.ingestor.sources.replay_source import ReplaySource
        src = ReplaySource("replay", c.settings.ingestor_replay_file)
        await src.start(ing.on_post)
        tasks.append(src.wait())
    else:
        await ing.sync_sessions()
        tasks.append(ing.maintain())
    if http:
        tasks.append(serve_preview(c, par))
    await asyncio.gather(*tasks)


if __name__ == "__main__":
    run("intake", main)
