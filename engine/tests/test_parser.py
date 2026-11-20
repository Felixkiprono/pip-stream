"""Parser behaviour on PLACEHOLDER posts (invented to cover common channel styles).
Replace/extend with real samples from the target channels."""
import pytest

from pipstream.common.events import SignalSource
from pipstream.services.parser.engine import ParserProfile, ParserTemplate, SignalParser, SymbolCatalog

P = SignalParser(SymbolCatalog.load())


def parse(text, reply=None, profile=None):
    return P.parse(text, channel_id="ch", source=SignalSource(message_id=1, reply_to_id=reply), profile=profile)


@pytest.mark.parametrize("text,symbol,side,entry,sl,tps", [
    ("XAUUSD BUY 2348-2350 SL 2340 TP 2360 / 2375", "XAUUSD", "buy", ("range", 2348, 2350), 2340, [2360, 2375]),
    ("🔥 GOLD SELL NOW @ 2351\nSL: 2358\nTP1: 2345\nTP2: 2340\nTP3: 2330", "XAUUSD", "sell", ("market", 2351), 2358,
     [2345, 2340, 2330]),
    ("XAU/USD SELL 2350-52 SL 2358 TP 2340", "XAUUSD", "sell", ("range", 2350, 2352), 2358, [2340]),
    ("EURUSD sell 1.0850 SL 1.0880 TP 1.0820", "EURUSD", "sell", ("market", 1.085), 1.088, [1.082]),
    ("GBPJPY sell 189.50 sl 190.20 tp 188.80", "GBPJPY", "sell", ("market", 189.5), 190.2, [188.8]),
    ("BTCUSD long 64,250 sl 63,500 tp 66,000", "BTCUSD", "buy", ("market", 64250), 63500, [66000]),
    ("EURUSD BUY LIMIT 1.0850 SL 30 pips TP 60 pips", "EURUSD", "buy", ("limit", 1.085), 1.082, [1.091]),
    ("USDJPY BUY 151.20 SL 25 TP 40", "USDJPY", "buy", ("market", 151.2), 150.95, [151.6]),
    # numbered targets with arrows / dashes: the 1, 2, 3 are indexes, not prices (real channel post)
    ("#XAUUSD\nDirection: SELL 📉\nEntry: 2440.00\nSL - 2450.00\nTP 1 ➜ 2430.00\nTP 2 ➜ 2420.00\nTP 3 ➜ 2405.00",
     "XAUUSD", "sell", ("market", 2440), 2450, [2430, 2420, 2405]),
    ("GOLD BUY 2350 SL 2340 TP 1 - 2360 TP 2 - 2370", "XAUUSD", "buy", ("market", 2350), 2340, [2360, 2370]),
    ("GOLD BUY 2350 SL 2340 TP 1 → 2360\nTP 2 → 2370", "XAUUSD", "buy", ("market", 2350), 2340, [2360, 2370]),
])
def test_open_signals(text, symbol, side, entry, sl, tps):
    r = parse(text)
    assert r.signal, r.rejected
    s = r.signal
    assert (s.kind, s.symbol, s.side) == ("open", symbol, side)
    assert s.entry.type == entry[0]
    if entry[0] == "range":
        assert (s.entry.from_, s.entry.to) == entry[1:]
    else:
        assert s.entry.price == pytest.approx(entry[1])
    assert s.sl == pytest.approx(sl)
    assert s.tps == pytest.approx(tps)


def test_market_without_price():
    s = parse("Buy gold now\nSL 2340\nTP 2360").signal
    assert s.entry.type == "market" and s.entry.price is None and s.sl == 2340


@pytest.mark.parametrize("text,reason", [
    ("Good morning traders, big week ahead!", "noise_prefilter"),
    ("TP1 hit ✅ +50 pips 🚀", "info_update"),
    ("XAUUSD BUY 2348 SL 2355 TP 2360", "low_confidence"),          # SL above a buy entry
    ("US30 buy @ 39000 sl 38900 tp 39200 NAS100 too", "low_confidence"),  # two symbols
    ("BUY 2348 SL 2340 TP 2360", "no_symbol"),
    ("EURUSD BUY 1.08 SELL 1.09", "conflicting_sides"),
])
def test_rejections(text, reason):
    r = parse(text)
    assert r.signal is None
    assert r.rejected.reason.startswith(reason)


def test_followups_need_reply_anchor():
    assert parse("Move SL to entry", reply=99).signal.move_sl_to_entry
    assert parse("close half now", reply=99).signal.close_fraction == 0.5
    assert parse("close 30%", reply=99).signal.close_fraction == pytest.approx(0.3)
    assert parse("Close all trades", reply=99).signal.close_fraction == 1.0
    assert parse("move sl to 2345", reply=99).signal.new_sl == 2345
    assert parse("Close all trades").signal is None  # no reply -> not trusted by default
    lenient = ParserProfile(followups_without_reply=True)
    assert parse("Close all trades", profile=lenient).signal.kind == "close"


def test_options_signal():
    s = parse("V75 RISE 5 ticks stake $2").signal
    assert s.kind == "options" and s.symbol == "R_75"
    assert (s.options.contract, s.options.duration, s.options.duration_unit, s.options.stake) == ("rise", 5, "t", 2)
    s = parse("Boom 1000 FALL 1 min").signal
    assert s.symbol == "BOOM1000" and s.options.duration_unit == "m"


def test_channel_template_and_ignore():
    prof = ParserProfile(id="p", ignore_patterns=[r"\bVIP\b"], templates=[ParserTemplate(
        name="house", pattern=r"#(?P<symbol>\w+)\s+(?P<side>BUY|SELL)\s+E(?P<entry>[\d.]+)\s+S(?P<sl>[\d.]+)\s+T(?P<tp1>[\d.]+)")])
    s = parse("#GOLD BUY E2349 S2340 T2360", profile=prof).signal
    assert s.template == "house" and s.symbol == "XAUUSD" and s.sl == 2340 and s.tps == [2360]
    assert parse("Join VIP: XAUUSD BUY 2348 SL 2340 TP 2360", profile=prof).rejected.reason == "ignored_pattern"
