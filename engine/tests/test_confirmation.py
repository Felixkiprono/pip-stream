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
