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
    login = LoginFlow(c, client_factory=client_factory, on_linked=relink, live_source=ing.sources.get)
    s = c.settings
    c.status = lambda: {
        "telegram_api_configured": bool(s.telegram_api_id and s.telegram_api_hash),
        "telegram_mode": "replay" if ing.replay else ("stand-in" if s.ingestor_fake_telegram else "real"),
        "confirm_to_saved": s.ingestor_confirm_to_saved,
        "intake_key": bool(s.intake_private_key),
        "sessions_connected": len(ing.sources),
        "channels_tracked": len(ing.chat_to_channel),
        "channels_with_reader": sum(1 for v in ing.assignment.values() if v),
        "parser_profiles": len(par.profiles),
    }

    if c.settings.ingestor_confirm_to_saved:
        if c.settings.env == "production":
            raise SystemExit("PS_INGESTOR_CONFIRM_TO_SAVED is a local testing aid; do not set it in production")

        async def confirm(msg, signal, rejected):
            await ing.confirm(msg.telegram_account_id, confirmation_text(msg, signal, rejected))
        par.on_result = confirm

    async def on_config(ev):
        await ing.on_config(ev)
        await par.on_config(ev)

    tasks = [
        c.bus.broadcast([Topic.CONFIG_CHANGED], on_config),
        c.bus.subscribe([Topic.TG_MESSAGE_NEW, Topic.TG_MESSAGE_EDITED], par.handle, consumer=c.consumer),
        c.bus.subscribe([Topic.TG_LOGIN_REQUEST], login.handle, consumer=c.consumer, concurrency=1,
                        max_deliveries=1, delete_after=True,  # carries phone/code/2FA: don't keep it
                        claim_idle_ms=120_000),  # a login step is never retried by reclaim: a 2nd send_code voids the code
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


def confirmation_text(msg, signal, rejected) -> str:
    """What intake writes to Saved Messages when PS_INGESTOR_CONFIRM_TO_SAVED is on."""
    where = f"chat {msg.tg_chat_id}, message {msg.message_id}" + (" (edited)" if msg.edit_version else "")
    if signal is None:
        reason = rejected.reason if rejected else "unknown"
        return f"PipStream read a post but did not treat it as a signal ({reason}).\n{where}"
    parts = [f"{signal.kind.upper()} {signal.side.upper() if signal.side else ''} {signal.symbol or ''}".split()]
    line = " ".join(parts[0])
    e = signal.entry
    if e and e.type == "range":
        line += f" {e.from_:g}-{e.to:g}"
    elif e and e.price is not None:
        line += f" @ {e.price:g}"
    if signal.sl is not None:
        line += f" · SL {signal.sl:g}"
    if signal.tps:
        line += " · TP " + " / ".join(f"{t:g}" for t in signal.tps)
    if signal.move_sl_to_entry:
        line += " · move SL to entry"
    if signal.close_fraction:
        line += f" · close {signal.close_fraction:.0%}"
    return f"PipStream read a signal: {line} (confidence {signal.confidence:.2f}).\n{where}"


if __name__ == "__main__":
    run("intake", main)
