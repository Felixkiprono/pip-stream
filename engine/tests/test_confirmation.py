"""The Saved Messages note intake writes when PS_INGESTOR_CONFIRM_TO_SAVED is on (local testing)."""
from types import SimpleNamespace

from pipstream.apps.intake import confirmation_text
from pipstream.common.events import SignalSource
from pipstream.services.parser.engine import SignalParser, SymbolCatalog

P = SignalParser(SymbolCatalog.load())
MSG = SimpleNamespace(tg_chat_id=-1001234, message_id=77, edit_version=0)


def _parse(text):
    return P.parse(text, channel_id="ch", source=SignalSource(message_id=77))


def test_signal_confirmation():
    r = _parse("XAUUSD BUY 2348-2350 SL 2340 TP 2360 / 2375")
    txt = confirmation_text(MSG, r.signal, r.rejected)
    assert txt.startswith("PipStream read a signal: OPEN BUY XAUUSD 2348-2350 · SL 2340 · TP 2360 / 2375")
    assert "message 77" in txt


def test_non_signal_confirmation():
    r = _parse("Good morning traders!")
    assert "did not treat it as a signal" in confirmation_text(MSG, r.signal, r.rejected)


async def test_fake_source_reads_posts_and_writes_saved():
    """The stand-in reader (PS_INGESTOR_FAKE_TELEGRAM) feeds posts in and records Saved Messages."""
    import asyncio
    import json

    from pipstream.devtools.fake_telegram import FakeTelegramSource, inbox_key, saved_key

    class R:  # the few Redis list calls the source uses
        def __init__(self):
            self.lists = {}

        async def lpop(self, k):
            lst = self.lists.get(k) or []
            return lst.pop(0) if lst else None

        async def rpush(self, k, v):
            self.lists.setdefault(k, []).append(v)

        async def ltrim(self, k, a, b):
            pass

    r, got = R(), []

    async def on_post(acc, post):
        got.append((acc, post))

    src = FakeTelegramSource("tga_1", r, "ps")
    await r.rpush(f"{inbox_key('ps')}:tga_1", json.dumps({"tg_chat_id": -100, "message_id": 5, "text": "hi"}))
    await src.start(on_post)
    for _ in range(20):
        if got:
            break
        await asyncio.sleep(0.05)
    await src.send_to_saved("PipStream read a signal: x")
    await src.stop()
    assert got[0][0] == "tga_1" and got[0][1].message_id == 5 and got[0][1].tg_chat_id == -100
    assert json.loads(r.lists[saved_key("ps")][0])["text"] == "PipStream read a signal: x"
