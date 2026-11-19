"""Generate the architecture diagrams (SVG) in docs/diagrams/.

    python docs/diagrams/build.py            # writes *.svg
    python docs/diagrams/build.py --png      # also renders *.png (needs playwright + chromium)

The topic matrix is built from src/pipstream/common/permissions.py, so it can never drift
from what the Redis ACL actually enforces.
"""
from __future__ import annotations

import sys
from html import escape
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE.parents[1] / "src"))

FONT = "Inter, 'Segoe UI', Helvetica, Arial, sans-serif"
C = {
    "ink": "#0F172A", "muted": "#475569", "faint": "#94A3B8", "line": "#334155",
    "pub_bg": "#EEF4FF", "pub_bd": "#6B8FE0", "priv_bg": "#F6F7F9", "priv_bd": "#9AA3AF",
    "ext_bg": "#FFF7ED", "ext_bd": "#C2410C",
    "svc_bd": "#2457C5", "svc_hd": "#2457C5", "sup_bd": "#6D28D9", "data_bd": "#0F766E",
    "intent": "#15803D", "broadcast": "#2563EB", "out": "#C2410C", "warn": "#B91C1C",
}


class SVG:
    def __init__(self, w: int, h: int):
        self.w, self.h, self.parts = w, h, []

    def add(self, s: str):
        self.parts.append(s)

    def text(self, x, y, s, size=14, weight=400, fill=None, anchor="start", italic=False):
        style = " font-style='italic'" if italic else ""
        self.add(f"<text x='{x}' y='{y}' font-size='{size}' font-weight='{weight}' fill='{fill or C['ink']}' "
                 f"text-anchor='{anchor}'{style}>{escape(s)}</text>")

    def rect(self, x, y, w, h, fill="#fff", stroke=None, r=10, sw=1.5, dash=None):
        d = f" stroke-dasharray='{dash}'" if dash else ""
        self.add(f"<rect x='{x}' y='{y}' width='{w}' height='{h}' rx='{r}' fill='{fill}' "
                 f"stroke='{stroke or C['line']}' stroke-width='{sw}'{d}/>")

    def path(self, d, color=None, sw=2, dash=None, marker="arrow"):
        color = color or C["line"]
        dd = f" stroke-dasharray='{dash}'" if dash else ""
        m = f" marker-end='url(#{marker}-{color[1:]})'" if marker else ""
        self.add(f"<path d='{d}' fill='none' stroke='{color}' stroke-width='{sw}'{dd}{m} "
                 f"stroke-linejoin='round' stroke-linecap='round'/>")

    def label(self, x, y, s, color=None, size=12, anchor="middle", weight=600):
        """Text on a white pill so it stays readable over lines."""
        w = len(s) * size * 0.58 + 14
        x0 = x - w / 2 if anchor == "middle" else (x if anchor == "start" else x - w)
        self.add(f"<rect x='{x0:.1f}' y='{y - size - 3}' width='{w:.1f}' height='{size + 9}' rx='{(size + 9) / 2}' "
                 f"fill='#fff' stroke='{color or C['line']}' stroke-width='1'/>")
        self.text(x0 + w / 2, y + 1, s, size=size, weight=weight, fill=color or C["line"], anchor="middle")

    def render(self) -> str:
        colors = {C["line"], C["intent"], C["broadcast"], C["out"], C["faint"], C["warn"], C["sup_bd"], C["data_bd"]}
        markers = "".join(
            f"<marker id='arrow-{c[1:]}' viewBox='0 0 10 10' refX='9' refY='5' markerWidth='11' markerHeight='11' markerUnits='userSpaceOnUse' "
            f"orient='auto-start-reverse'><path d='M0,0 L10,5 L0,10 z' fill='{c}'/></marker>" for c in colors)
        return (f"<svg xmlns='http://www.w3.org/2000/svg' width='{self.w}' height='{self.h}' "
                f"viewBox='0 0 {self.w} {self.h}' font-family=\"{FONT}\">"
                f"<defs>{markers}</defs><rect width='100%' height='100%' fill='#FFFFFF'/>"
                + "".join(self.parts) + "</svg>")


def service(s: SVG, x, y, w, h, title, lines=(), color=None, tag=None, fill="#fff"):
    color = color or C["svc_bd"]
    s.rect(x, y, w, h, fill=fill, stroke=color, sw=2)
    s.add(f"<rect x='{x}' y='{y}' width='{w}' height='30' rx='10' fill='{color}'/>"
          f"<rect x='{x}' y='{y + 18}' width='{w}' height='12' fill='{color}'/>")
    s.text(x + w / 2, y + 21, title, size=15, weight=700, fill="#fff", anchor="middle")
    for i, ln in enumerate(lines):
        s.text(x + w / 2, y + 52 + i * 18, ln, size=12.5, fill=C["muted"], anchor="middle")
    if tag:
        tw = len(tag) * 7 + 16
        s.add(f"<rect x='{x + w - tw - 6}' y='{y + h - 24}' width='{tw}' height='18' rx='9' fill='{C['out']}'/>")
        s.text(x + w - tw / 2 - 6, y + h - 11, tag, size=11, weight=700, fill="#fff", anchor="middle")


# ============================================================================ 1. service map
def service_map() -> str:
    s = SVG(1720, 1010)
    s.text(40, 48, "PipStream: services, network zones and event flow", size=26, weight=800)
    s.text(40, 74, "Each box is its own container. Services never call each other directly: they exchange events on the bus.",
           size=14, fill=C["muted"])
    ZR = 1390  # right edge of the zones

    # zones
    s.rect(250, 100, ZR - 250, 150, fill=C["pub_bg"], stroke=C["pub_bd"], r=16, sw=2, dash="8 5")
    s.text(268, 126, "PUBLIC EDGE  (the only published port: 127.0.0.1 behind a TLS proxy)", size=13, weight=800,
           fill=C["pub_bd"])
    s.rect(250, 275, ZR - 250, 700, fill=C["priv_bg"], stroke=C["priv_bd"], r=16, sw=2, dash="8 5")
    s.text(268, 301, "PRIVATE NETWORK  (no inbound ports; no internet except boxes tagged egress)", size=13,
           weight=800, fill=C["muted"])

    def ext(x, y, w, h, title, lines):
        s.rect(x, y, w, h, fill=C["ext_bg"], stroke=C["ext_bd"], sw=2)
        s.text(x + w / 2, y + 26, title, size=15, weight=700, fill=C["ext_bd"], anchor="middle")
        for i, ln in enumerate(lines):
            s.text(x + w / 2, y + 48 + i * 17, ln, size=12.5, fill=C["muted"], anchor="middle")

    EX = 1440
    ext(30, 120, 190, 60, "Users / admin", ["browser, curl"])
    ext(30, 195, 190, 60, "Payment providers", ["M-Pesa, Paystack, Stripe"])
    ext(30, 380, 190, 90, "Telegram", ["signal channels", "(MTProto push)"])
    ext(EX, 360, 250, 130, "Brokers / venues", ["MT5 bridge (Wine)", "Deriv Options API", "hosted MT API (later)",
                                                "cTrader, exchanges (later)"])
    ext(EX, 600, 250, 100, "Alert channels", ["Telegram bot, email", "SMS, push (later)"])

    service(s, 640, 140, 380, 92, "Core API", ["config source of truth, admin, kill switch,",
                                               "parse preview, payment webhooks"])

    Y, W, H = 380, 190, 120
    xs = [280, 570, 860, 1150]
    mid = lambda i: xs[i] + W / 2  # noqa: E731
    service(s, xs[0], Y, W, H, "Telegram Ingestor", ["Telethon userbot sessions", "lease, dedupe, fan-out",
                                                     "sessions encrypted"], tag="egress")
    service(s, xs[1], Y, W, H, "Signal Parser", ["rule-based templates", "confidence scoring", "no LLM (Telegram terms)"])
    service(s, xs[2], Y, W, H, "Rules / Risk Engine", ["filters, limits, sizing", "multi-TP split", "holds SIGNING key"])
    service(s, xs[3], Y, W, H, "Execution Router", ["verifies signature + expiry", "idempotent, retries",
                                                    "holds BROKER creds"], tag="egress")
    Y2 = 610
    service(s, xs[0], Y2, W, 110, "Billing", ["verifies payment webhooks", "plans -> entitlements"], color=C["sup_bd"])
    service(s, xs[2], Y2, W, 110, "Notifications", ["routes alerts per user", "prefs and templates"],
            color=C["sup_bd"], tag="egress")
    service(s, xs[3], Y2, W, 110, "Position Tracker", ["message -> tickets", "reconciles with broker"], color=C["sup_bd"])

    # data stores
    for x, w, title, lines, foot in [
        (280, 520, "Postgres", ["config, signals, intents, orders, positions,", "billing, append-only audit log"],
         "each table has exactly one writer service"),
        (840, 500, "Redis", ["event bus (Streams), session leases,", "idempotency keys, account snapshots, kill switch"],
         "one ACL user per service: may only write its own topics")]:
        s.rect(x, 820, w, 120, fill="#fff", stroke=C["data_bd"], sw=2)
        s.text(x + w / 2, 850, title, size=16, weight=800, fill=C["data_bd"], anchor="middle")
        for i, ln in enumerate(lines):
            s.text(x + w / 2, 874 + i * 18, ln, size=12.5, fill=C["muted"], anchor="middle")
        s.text(x + w / 2, 916, foot, size=12.5, fill=C["data_bd"], anchor="middle", italic=True)
    s.text(820, 800, "every arrow is an event on the Redis bus; every service reads and writes its own Postgres tables",
           size=12.5, fill=C["faint"], anchor="middle", italic=True)

    # external -> edge
    s.path("M220,150 H640", C["out"], 2)
    s.path("M220,225 H420 V205 H640", C["out"], 2)
    s.label(330, 145, "HTTPS", C["out"], size=11)
    s.path(f"M220,425 H{xs[0]}", C["out"], 2.5)
    s.label(250, 415, "push", C["out"], size=11)

    # pipeline
    def gap(i):
        return (xs[i] + W + xs[i + 1]) / 2
    s.path(f"M{xs[0] + W},445 H{xs[1]}", sw=2.5)
    s.label(gap(0), 437, "tg.message", size=11)
    s.path(f"M{xs[1] + W},445 H{xs[2]}", sw=2.5)
    s.label(gap(1), 437, "signal.parsed", size=11)
    s.path(f"M{xs[2] + W},445 H{xs[3]}", C["intent"], 4)
    s.label(gap(2), 437, "trade.intent", C["intent"], size=11)
    s.text(gap(2), 470, "Ed25519 signed", size=11, weight=700, fill=C["intent"], anchor="middle")
    s.text(gap(2), 484, "expires in 5 s", size=11, weight=700, fill=C["intent"], anchor="middle")
    s.path(f"M{xs[3] + W},420 H{EX}", C["out"], 2.5)
    s.label((xs[3] + W + EX) / 2, 412, "orders", C["out"], size=11)
    s.path(f"M{EX},465 H{xs[3] + W}", C["out"], 1.5, dash="5 4")
    s.text((xs[3] + W + EX) / 2, 486, "fills, positions", size=11, fill=C["out"], anchor="middle")

    # config.changed broadcast
    s.path("M830,232 V330", C["broadcast"], 2, dash="6 5", marker=None)
    s.path(f"M{mid(0)},330 H{mid(3)}", C["broadcast"], 2, dash="6 5", marker=None)
    for i in range(4):
        s.path(f"M{mid(i)},330 V{Y}", C["broadcast"], 2, dash="6 5")
    s.label(830, 352, "config.changed  (broadcast to every replica)", C["broadcast"], size=11)

    # billing
    s.path(f"M640,215 H265 V665 H{xs[0]}", C["sup_bd"], 2)
    s.label(330, 600, "billing.webhook", C["sup_bd"], size=11)
    bx = xs[1] + W + 50
    s.path(f"M{xs[0] + W},690 H{bx} V470 H{xs[2]}", C["sup_bd"], 2, dash="6 5")
    s.label((xs[0] + W + bx) / 2, 690, "billing.changed", C["sup_bd"], size=11)

    # to notifications / tracker
    s.path(f"M{mid(1)},{Y + H} V565 H{xs[2] + 40} V{Y2}", sw=2)
    s.label(mid(1), 565, "signal.rejected", size=11)
    s.path(f"M{mid(2)},{Y + H} V{Y2}", C["warn"], 2)
    s.label(mid(2) + 10, 540, "risk.limit_hit", C["warn"], size=11)
    s.path(f"M{xs[3] + 30},{Y + H} V585 H{xs[2] + W - 30} V{Y2}", sw=2)
    s.label((xs[2] + W - 30 + xs[3] + 30) / 2, 585, "trade.executed / failed", size=11)
    s.path(f"M{mid(3) + 40},{Y + H} V{Y2}", sw=2)
    s.label(mid(3) + 50, 540, "executed, snapshot", size=11)
    s.path(f"M{xs[3]},680 H{xs[2] + W}", sw=2)
    s.label((xs[3] + xs[2] + W) / 2, 672, "trade.closed", size=11)
    s.path(f"M{mid(2)},720 V760 H1415 V650 H{EX}", C["out"], 2)
    s.label(1300, 760, "alerts", C["out"], size=11)

    # legend
    lx, ly = EX, 760
    s.rect(lx, ly, 250, 200, fill="#fff", stroke=C["faint"], sw=1)
    s.text(lx + 16, ly + 26, "Legend", size=14, weight=800)
    items = [(C["line"], None, 2, "event (one consumer group)"), (C["broadcast"], "6 5", 2, "broadcast (all replicas)"),
             (C["intent"], None, 4, "signed trade intent"), (C["out"], None, 2.5, "outbound internet"),
             (C["warn"], None, 2, "risk limit / skipped")]
    for i, (col, dash, sw, txt) in enumerate(items):
        y = ly + 52 + i * 22
        s.path(f"M{lx + 16},{y} H{lx + 56}", col, sw, dash=dash)
        s.text(lx + 66, y + 4, txt, size=12, fill=C["muted"])
    s.add(f"<rect x='{lx + 16}' y='{ly + 164}' width='54' height='18' rx='9' fill='{C['out']}'/>")
    s.text(lx + 43, ly + 177, "egress", size=11, weight=700, fill="#fff", anchor="middle")
    s.text(lx + 78, ly + 178, "may call the internet", size=12, fill=C["muted"])
    return s.render()


# ============================================================================ 2. signal flow
def signal_flow() -> str:
    lanes = ["Telegram", "Ingestor", "Parser", "Risk Engine", "Exec Router", "Broker", "Pos Tracker", "Notifications"]
    ext = {"Telegram", "Broker"}
    x0, dx = 110, 190
    X = {n: x0 + i * dx for i, n in enumerate(lanes)}
    s = SVG(1560, 2000)
    s.text(40, 48, "One post, end to end", size=26, weight=800)
    s.text(40, 74, "Placeholder example. Every step carries the same trace_id, so GET /v1/admin/trace/{trace_id} shows the whole story.",
           size=14, fill=C["muted"])
    top = 100
    lifeline_at = len(s.parts)  # lifelines are inserted here once the height is known
    for n, x in X.items():
        col = C["ext_bd"] if n in ext else C["svc_bd"]
        s.rect(x - 80, top, 160, 44, fill=C["ext_bg"] if n in ext else col, stroke=col, sw=2)
        s.text(x, top + 28, n, size=15, weight=700, fill=C["ext_bd"] if n in ext else "#fff", anchor="middle")

    y = [top + 80]

    def phase(title, sub):
        s.rect(20, y[0] - 22, 1520, 34, fill="#F1F5F9", stroke="#F1F5F9", r=8)
        s.text(36, y[0], title, size=15, weight=800)
        s.text(36 + len(title) * 9 + 14, y[0], sub, size=13, fill=C["muted"])
        y[0] += 46

    n = [0]

    def msg(a, b, text, color=None, sw=2, dash=None, note=None):
        n[0] += 1
        xa, xb = X[a], X[b]
        s.path(f"M{xa},{y[0]} H{xb + (-8 if xb > xa else 8)}", color or C["line"], sw, dash=dash)
        lx = min(xa, xb)
        s.add(f"<circle cx='{lx + 16}' cy='{y[0] - 14}' r='10' fill='{color or C['line']}'/>")
        s.text(lx + 16, y[0] - 10, str(n[0]), size=11, weight=800, fill="#fff", anchor="middle")
        s.text(lx + 32, y[0] - 9, text, size=13, weight=600, fill=color or C["ink"])
        if note:
            s.text(lx + 32, y[0] + 17, note, size=11.5, fill=C["muted"], italic=True)
        y[0] += 58 if note else 46

    def work(lane, lines, color=None):
        col = color or C["svc_bd"]
        h = 14 + 18 * len(lines)
        w = max(len(ln) for ln in lines) * 7.1 + 24
        x = X[lane] - w / 2
        s.rect(x, y[0] - 16, w, h, fill="#fff", stroke=col, sw=1.5, r=8)
        for i, ln in enumerate(lines):
            s.text(X[lane], y[0] + 1 + i * 18, ln, size=12.5, fill=C["ink"], anchor="middle")
        y[0] += h + 26

    phase("A. New signal", 'post #102: "XAUUSD BUY 2348-2350 SL 2340 TP 2360 / 2375"')
    msg("Telegram", "Ingestor", "new message #102", C["out"], 2.5)
    work("Ingestor", ["map chat -> channel", "this session is the channel's reader?", "dedupe chat:msg:edit (24 h)"])
    msg("Ingestor", "Parser", "tg.message.new")
    work("Parser", ["prefilter -> template -> classify", "extract entry/SL/TPs, pips -> price", "confidence 1.00 >= 0.85: accept"])
    msg("Parser", "Risk Engine", "signal.parsed", note="rejected posts go to the review queue instead (signal.rejected)")
    work("Risk Engine", ["for each copy rule on this channel:", "plan, kill switch, filters, price guard,",
                         "limits; 1% of $10k / SL 9.00 = 0.11 lots", "split over 2 TPs: 0.06 + 0.05"])
    msg("Risk Engine", "Exec Router", "trade.intent x 2  (signed, expires 5 s)", C["intent"], 4)
    work("Exec Router", ["verify Ed25519 signature + expiry", "kill switch, lot cap", "claim idempotency key (SET NX)"],
         C["intent"])
    msg("Exec Router", "Broker", "open BUY 0.06 TP 2360 / open BUY 0.05 TP 2375", C["out"], 2.5)
    msg("Broker", "Exec Router", "tickets 1, 2 filled @ 2349", C["out"], 1.5, dash="5 4")
    msg("Exec Router", "Pos Tracker", "trade.executed x 2", note="tracker records: message #102 -> tickets 1, 2")
    msg("Exec Router", "Notifications", "trade.executed x 2", note="user alerted; latency post -> fill recorded")

    phase("B. Follow-up", 'post #104, a reply to #102: "Move SL to entry"')
    msg("Telegram", "Ingestor", "new message #104 (reply_to 102)", C["out"], 2.5)
    msg("Ingestor", "Parser", "tg.message.new")
    work("Parser", ["reply + follow-up phrase", "kind = modify, move_sl_to_entry"])
    msg("Parser", "Risk Engine", "signal.parsed")
    work("Risk Engine", ["open positions for message #102", "-> tickets 1 and 2 only"])
    msg("Risk Engine", "Exec Router", "trade.intent(modify, ticket) x 2", C["intent"], 4)
    msg("Exec Router", "Broker", "modify SL = 2349 on tickets 1, 2", C["out"], 2.5)
    msg("Exec Router", "Pos Tracker", "trade.executed (modify)", note="if the broker later closes a ticket, the snapshot reconcile emits trade.closed")
    bottom = y[0]
    lines = SVG(0, 0)
    for x in X.values():
        lines.path(f"M{x},{top + 44} V{bottom}", C["faint"], 1.5, dash="4 6", marker=None)
    s.parts[lifeline_at:lifeline_at] = lines.parts
    s.h = bottom + 30
    return s.render()


# ============================================================================ 3. topic matrix
def topic_matrix() -> str:
    from pipstream.common.events import Topic
    from pipstream.common.permissions import SERVICES

    svcs = list(SERVICES)
    nice = {"ingestor": "Ingestor", "parser": "Parser", "risk_engine": "Risk Engine",
            "execution_router": "Exec Router", "position_tracker": "Pos Tracker", "notifications": "Notifications",
            "billing": "Billing", "core_api": "Core API"}
    topics = list(Topic)
    cw, rh, left, top = 135, 40, 260, 170
    s = SVG(left + cw * len(svcs) + 40, top + rh * len(topics) + 150)
    s.text(40, 48, "Event topics: who may publish and consume", size=26, weight=800)
    s.text(40, 74, "Generated from src/pipstream/common/permissions.py, the same table that renders the Redis ACL.",
           size=14, fill=C["muted"])
    s.text(40, 96, "A service cannot write a stream it does not own, even if compromised.", size=14, fill=C["muted"])
    for j, sv in enumerate(svcs):
        x = left + j * cw
        s.rect(x + 4, top - 50, cw - 8, 40, fill=C["svc_bd"], stroke=C["svc_bd"], r=8)
        s.text(x + cw / 2, top - 25, nice[sv], size=13.5, weight=700, fill="#fff", anchor="middle")
    for i, t in enumerate(topics):
        y = top + i * rh
        if i % 2 == 0:
            s.rect(30, y, left - 30 + cw * len(svcs), rh, fill="#F8FAFC", stroke="#F8FAFC", r=0)
        s.text(44, y + 25, str(t), size=14, weight=700 if t == Topic.TRADE_INTENT else 500,
               fill=C["intent"] if t == Topic.TRADE_INTENT else C["ink"])
        for j, sv in enumerate(svcs):
            p = SERVICES[sv]
            cx = left + j * cw + cw / 2
            if t in p.publish:
                col, txt = (C["intent"] if t == Topic.TRADE_INTENT else C["svc_bd"]), "PUBLISH"
                s.add(f"<rect x='{cx - 42}' y='{y + 9}' width='84' height='22' rx='11' fill='{col}'/>")
                s.text(cx, y + 25, txt, size=11.5, weight=800, fill="#fff", anchor="middle")
            elif t in p.consume:
                s.add(f"<rect x='{cx - 42}' y='{y + 9}' width='84' height='22' rx='11' fill='#fff' "
                      f"stroke='{C['line']}' stroke-width='1.5'/>")
                s.text(cx, y + 25, "consume", size=11.5, weight=700, fill=C["line"], anchor="middle")
            elif t in p.broadcast:
                s.add(f"<rect x='{cx - 42}' y='{y + 9}' width='84' height='22' rx='11' fill='#fff' "
                      f"stroke='{C['broadcast']}' stroke-width='1.5' stroke-dasharray='4 3'/>")
                s.text(cx, y + 25, "broadcast", size=11.5, weight=700, fill=C["broadcast"], anchor="middle")
    yb = top + rh * len(topics) + 40
    s.text(44, yb, "PUBLISH = only this service's Redis user can XADD the stream.   consume = consumer group, each event "
           "handled by one replica.", size=13, fill=C["muted"])
    s.text(44, yb + 22, "broadcast = read-only, every replica sees every event (config reloads, entitlements).   "
           "Failed events go to the dlq stream after 5 attempts.", size=13, fill=C["muted"])
    s.text(44, yb + 44, "Second layer for trade.intent: the router also verifies the Ed25519 signature, the 5 s expiry and "
           "a one-time idempotency key.", size=13, fill=C["intent"], weight=600)
    return s.render()


DIAGRAMS = {"service-map": service_map, "signal-flow": signal_flow, "topic-matrix": topic_matrix}


def main() -> None:
    for name, fn in DIAGRAMS.items():
        (HERE / f"{name}.svg").write_text(fn())
    if "--png" in sys.argv:
        from playwright.sync_api import sync_playwright
        with sync_playwright() as p:
            b = p.chromium.launch()
            for name in DIAGRAMS:
                svg = (HERE / f"{name}.svg").read_text()
                w = int(svg.split("width='")[1].split("'")[0])
                h = int(svg.split("height='")[1].split("'")[0])
                pg = b.new_page(viewport={"width": w, "height": h}, device_scale_factor=2)
                pg.set_content(f"<html><body style='margin:0'>{svg}</body></html>")
                pg.screenshot(path=str(HERE / f"{name}.png"), full_page=True)
                pg.close()
            b.close()
    print("wrote", ", ".join(DIAGRAMS))


if __name__ == "__main__":
    main()
