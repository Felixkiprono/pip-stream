"""Rule-based signal parser (no LLM/ML, per Telegram API terms).

Pipeline for one post:
  normalise -> ignore patterns -> prefilter -> per-channel templates -> classify
  (open / follow-up / options / info / noise) -> extract -> validate -> confidence.

Every channel has a ParserProfile (stored in `parser_profiles.config`, editable from the
Core API "parse preview" endpoint). Profiles add aliases, words and exact regex templates
on top of the generic extractor below.
"""
from __future__ import annotations

import re
from dataclasses import dataclass, field
from functools import cached_property
from importlib import resources
from pathlib import Path

import yaml
from pydantic import BaseModel, Field

from ...common.events import EntrySpec, OptionsSpec, ParsedSignal, SignalRejected, SignalSource

NUM = r"\d+(?:\.\d+)?"


# --------------------------------------------------------------------------- catalog

class SymbolInfo(BaseModel):
    kind: str
    pip: float
    point: float
    contract_size: float = 1.0
    aliases: list[str] = Field(default_factory=list)


class SymbolCatalog:
    def __init__(self, symbols: dict[str, SymbolInfo]):
        self.symbols = symbols

    @classmethod
    def load(cls, path: str | None = None) -> "SymbolCatalog":
        if path:
            data = yaml.safe_load(Path(path).read_text())
        else:
            data = yaml.safe_load(resources.files("pipstream.services.parser")
                                  .joinpath("default_symbols.yaml").read_text())
        return cls({k: SymbolInfo(**v) for k, v in data["symbols"].items()})

    def info(self, symbol: str) -> SymbolInfo | None:
        return self.symbols.get(symbol)

    def alias_map(self, extra: dict[str, str] | None = None) -> dict[str, str]:
        m: dict[str, str] = {}
        for canon, info in self.symbols.items():
            m[canon] = canon
            for a in info.aliases:
                m[a.upper()] = canon
        for a, canon in (extra or {}).items():
            m[a.upper()] = canon.upper()
        return m


# --------------------------------------------------------------------------- profile

class ParserTemplate(BaseModel):
    """Exact regex for a channel's house format. Named groups used:
    symbol, side, entry, entry_from, entry_to, sl, tp1..tp6."""
    name: str
    pattern: str


class ParserProfile(BaseModel):
    id: str = "default"
    version: int = 1
    min_confidence: float = 0.8
    require_sl: bool = False
    symbol_aliases: dict[str, str] = Field(default_factory=dict)
    ignore_patterns: list[str] = Field(default_factory=list)
    templates: list[ParserTemplate] = Field(default_factory=list)
    buy_words: list[str] = Field(default_factory=lambda: ["BUY", "LONG"])
    sell_words: list[str] = Field(default_factory=lambda: ["SELL", "SHORT"])
    followups_without_reply: bool = False  # trust "move SL to BE" posted without a reply
    max_text_len: int = 600


@dataclass
class ParseResult:
    signal: ParsedSignal | None = None
    rejected: SignalRejected | None = None
    issues: list[str] = field(default_factory=list)


# --------------------------------------------------------------------------- helpers

_EMOJI_OR_SYMBOLS = re.compile(r"[^\w\s.,:;/@%$&|\-+()=~#'\n]")


def normalise(text: str) -> str:
    t = text.upper()
    t = t.replace("–", "-").replace("—", "-").replace("−", "-")
    t = re.sub(r"(?<=\d),(?=\d{3}\b)", "", t)          # 2,348.50 -> 2348.50
    t = _EMOJI_OR_SYMBOLS.sub(" ", t)
    t = re.sub(r"\b([A-Z]{3})\s*/\s*([A-Z]{3})\b", r"\1\2", t)  # XAU/USD -> XAUUSD
    t = re.sub(r"[ \t]+", " ", t)
    return t.strip()


def _expand_shorthand(a: float, b_str: str) -> float:
    """'2348-50' -> 2350 (second bound written with only the last digits)."""
    b = float(b_str)
    if b < a / 10 and "." not in b_str:
        a_int = str(int(a))
        if len(b_str) < len(a_int):
            return float(a_int[: len(a_int) - len(b_str)] + b_str)
    return b


_FOLLOW_BE = re.compile(r"\b(?:MOVE|SET|PUT|SHIFT)?\s*(?:SL|S/L|STOP\s*LOSS|STOPLOSS)\s*(?:TO|AT|@)?\s*"
                        r"(?:ENTRY|BE|B/E|BREAK\s*EVEN|BREAKEVEN|COST)\b|\bBREAK\s*EVEN\b|\bBREAKEVEN\b")
_FOLLOW_NEW_SL = re.compile(rf"\b(?:MOVE|SET|NEW|ADJUST|CHANGE)\s*(?:SL|S/L|STOP\s*LOSS)\s*(?:TO|AT|@|:)?\s*({NUM})")
_FOLLOW_NEW_TP = re.compile(rf"\b(?:NEW|MOVE|SET|ADJUST|CHANGE)\s*(?:TP|T/P|TAKE\s*PROFIT)\s*(?:TO|AT|@|:)?\s*({NUM})")
_FOLLOW_PARTIAL = re.compile(rf"\b(?:CLOSE|TAKE|SECURE)\s*(?:(HALF|PARTIALS?)|({NUM})\s*%)")
_FOLLOW_CLOSE = re.compile(r"\b(?:CLOSE|EXIT)\s*(?:ALL|NOW|FULL|TRADES?|IT|POSITIONS?|EARLY)?\b")
_INFO = re.compile(r"\b(HIT|SMASHED|RUNNING|BOOKED|SECURED|CONGRATS|RESULTS?|REPORT)\b")
_OPTIONS_DIR = re.compile(r"\b(RISE|FALL|CALL|PUT|HIGHER|LOWER)\b")
_OPTIONS_DUR = re.compile(rf"\b({NUM})\s*(TICKS?|T|SECONDS?|SECS?|S|MINUTES?|MINS?|M|HOURS?|H|DAYS?|D)\b")
_STAKE = re.compile(rf"(?:STAKE|AMOUNT|RISK)\s*[:=]?\s*\$?\s*({NUM})|\$\s*({NUM})")
_SL = re.compile(rf"(?:\b(?:SL|S/L|STOP\s*LOSS|STOPLOSS)|(?<!BUY )(?<!SELL )\bSTOP)\s*[:=@-]?\s*({NUM})\s*(PIPS?)?")
# "TP1: x", "TP 2: x", "TP 1 ➜ x" (arrow stripped to a space), "TP 3 - x" carry an index;
# "TP 2360" does not (the digit is the start of the price).
_TP = re.compile(rf"\b(?:TP|T/P|TAKE\s*PROFIT|TARGETS?)(?:\d|\s+\d(?=\s*[:=).]\s|\s*-?\s+\d))?\s*[:=@-]?\s*"
                 rf"((?:{NUM})(?:\s*(?:/|,|\||&|AND|-)\s*{NUM})*)\s*(PIPS?)?")
_ENTRY_KW = re.compile(rf"\b(?:ENTRY|ENTER|OPEN|PRICE|ZONE)\s*(?:PRICE|ZONE|AT)?\s*[:=@]?\s*({NUM})"
                       rf"(?:\s*(?:-|/|~|TO)\s*({NUM}))?")


class SignalParser:
    def __init__(self, catalog: SymbolCatalog):
        self.catalog = catalog

    # ---------------------------------------------------------------- public
    def parse(self, text: str, *, channel_id: str, source: SignalSource,
              profile: ParserProfile | None = None) -> ParseResult:
        profile = profile or ParserProfile()
        norm = normalise(text)

        def reject(reason: str, conf: float = 0.0, issues: list[str] | None = None) -> ParseResult:
            return ParseResult(rejected=SignalRejected(channel_id=channel_id, source=source, reason=reason,
                                                       confidence=round(conf, 3), raw_text=text),
                               issues=issues or [])

        if not norm:
            return reject("empty")
        for pat in profile.ignore_patterns:
            if re.search(pat, norm, re.I):
                return reject("ignored_pattern")

        aliases = self.catalog.alias_map(profile.symbol_aliases)
        symbols = self._find_symbols(norm, aliases)
        sides = self._find_sides(norm, profile)
        has_num = bool(re.search(r"\d", norm))
        followup_word = bool(_FOLLOW_BE.search(norm) or _FOLLOW_CLOSE.search(norm)
                             or _FOLLOW_PARTIAL.search(norm) or _FOLLOW_NEW_SL.search(norm) or _FOLLOW_NEW_TP.search(norm))

        # fast prefilter: needs (direction + number) or a follow-up phrase
        if not ((sides and has_num) or followup_word or (_OPTIONS_DIR.search(norm) and has_num)):
            return reject("info_update" if _INFO.search(norm) else "noise_prefilter")

        # 1) channel templates (exact house formats)
        for tpl in profile.templates:
            m = re.search(tpl.pattern, norm, re.I | re.S)
            if m:
                res = self._from_template(m, tpl.name, norm, text, channel_id, source, profile, aliases)
                if res:
                    return self._finish(res[0], res[1], profile, reject)

        has_levels = bool(_SL.search(norm) or _TP.search(norm))
        # 2) a new trade wins over follow-up words ("... close at TP")
        if sides and symbols and (has_levels or not followup_word):
            if len(set(sides)) > 1:
                return reject("conflicting_sides", 0.2)
            sig, issues = self._extract_open(norm, text, channel_id, source, profile, symbols, sides[0])
            return self._finish(sig, issues, profile, reject)

        # 3) follow-ups (modify / close) for an earlier signal
        if followup_word:
            sig, issues = self._extract_followup(norm, text, channel_id, source, profile, symbols)
            if sig:
                return self._finish(sig, issues, profile, reject)

        # 4) Deriv-style options
        if _OPTIONS_DIR.search(norm) and _OPTIONS_DUR.search(norm):
            sig, issues = self._extract_options(norm, text, channel_id, source, profile, symbols)
            if sig:
                return self._finish(sig, issues, profile, reject)

        if _INFO.search(norm):
            return reject("info_update")
        if sides and not symbols:
            return reject("no_symbol", 0.3)
        return reject("unclassified", 0.1)

    # ---------------------------------------------------------------- finish
    def _finish(self, sig: ParsedSignal, issues: list[str], profile: ParserProfile, reject) -> ParseResult:
        if "missing_sl" in issues and profile.require_sl:
            return reject("missing_sl", sig.confidence, issues)
        if sig.confidence < profile.min_confidence:
            return reject("low_confidence:" + ",".join(issues), sig.confidence, issues)
        return ParseResult(signal=sig, issues=issues)

    # ---------------------------------------------------------------- finders
    @staticmethod
    def _find_symbols(norm: str, aliases: dict[str, str]) -> list[str]:
        found: list[tuple[int, str]] = []
        taken: list[tuple[int, int]] = []
        for alias in sorted(aliases, key=len, reverse=True):
            pat = r"(?<![A-Z0-9_])" + re.escape(alias).replace(r"\ ", r"\s*") + r"(?![A-Z0-9_])"
            for m in re.finditer(pat, norm):
                if any(m.start() < e and m.end() > s for s, e in taken):
                    continue
                taken.append((m.start(), m.end()))
                found.append((m.start(), aliases[alias]))
        out: list[str] = []
        for _, canon in sorted(found):
            if canon not in out:
                out.append(canon)
        return out

    @staticmethod
    def _find_sides(norm: str, profile: ParserProfile) -> list[str]:
        out: list[tuple[int, str]] = []
        for side, words in (("buy", profile.buy_words), ("sell", profile.sell_words)):
            for w in words:
                for m in re.finditer(rf"(?<![A-Z]){re.escape(w.upper())}(?![A-Z])", norm):
                    out.append((m.start(), side))
        return [s for _, s in sorted(out)]

    # ---------------------------------------------------------------- open
    def _extract_open(self, norm, text, channel_id, source, profile, symbols, side):
        issues: list[str] = []
        conf = 1.0
        symbol = symbols[0]
        if len(symbols) > 1:
            issues.append("multiple_symbols")
            conf -= 0.5
        info = self.catalog.info(symbol)
        pip = info.pip if info else 0.0001

        entry = self._entry(norm, profile, side)
        ref = entry.reference_price()
        if entry.type == "market" and ref is None:
            issues.append("no_entry_price")
            conf -= 0.05

        sl, sl_pips = None, None
        m = _SL.search(norm)
        if m:
            sl, sl_pips, flag = self._level(float(m.group(1)), bool(m.group(2)), ref, side, pip, is_sl=True)
            if flag:
                issues.append(flag)
                conf -= 0.1
        else:
            issues.append("missing_sl")
            conf -= 0.25

        tps: list[float] = []
        tp_pips: list[float] = []
        for tm in _TP.finditer(norm):
            for n in re.findall(NUM, tm.group(1)):
                v, vp, flag = self._level(float(n), bool(tm.group(2)), ref, side, pip, is_sl=False)
                if v is not None and v not in tps:
                    tps.append(v)
                if vp is not None:
                    tp_pips.append(vp)
                if flag and flag not in issues:
                    issues.append(flag)
                    conf -= 0.05
        if not tps and not tp_pips:
            issues.append("missing_tp")
            conf -= 0.1

        conf -= self._validate_levels(side, ref, sl, tps, issues)
        if len(text) > profile.max_text_len:
            issues.append("long_text")
            conf -= 0.15

        sig = ParsedSignal(channel_id=channel_id, source=source, kind="open", symbol=symbol, side=side,
                           entry=entry, sl=sl, tps=tps, sl_pips=sl_pips, tp_pips=tp_pips,
                           confidence=round(max(conf, 0.0), 3), parser_profile=profile.id, raw_text=text)
        return sig, issues

    def _entry(self, norm: str, profile: ParserProfile, side: str) -> EntrySpec:
        m = _ENTRY_KW.search(norm)
        if m:
            a = float(m.group(1))
            if m.group(2):
                return EntrySpec(type="range", **{"from": a, "to": _expand_shorthand(a, m.group(2))})
            return EntrySpec(type="market", price=a)
        words = profile.buy_words if side == "buy" else profile.sell_words
        alt = "|".join(re.escape(w.upper()) for w in words)
        m = re.search(rf"(?<![A-Z])(?:{alt})(?![A-Z])\s*(LIMIT|STOP|NOW|MARKET|CE|CMP)?\s*"
                      rf"(?:[A-Z0-9_]+\s+)?(?:@|AT|FROM|:)?\s*({NUM})(?:\s*(?:-|/|~|TO)\s*({NUM}))?", norm)
        # avoid picking the SL/TP number when the price is missing ("BUY GOLD SL 2340")
        if m:
            between = norm[m.start():m.start(2)]
            if re.search(r"\b(SL|TP|STOP\s*LOSS|TAKE)\b", between):
                m = None
        if not m:
            return EntrySpec(type="market")
        kind = (m.group(1) or "").strip()
        a = float(m.group(2))
        if m.group(3):
            return EntrySpec(type="range", **{"from": a, "to": _expand_shorthand(a, m.group(3))})
        if kind in ("LIMIT", "STOP"):
            return EntrySpec(type=kind.lower(), price=a)
        return EntrySpec(type="market", price=a)

    @staticmethod
    def _level(v: float, explicit_pips: bool, ref: float | None, side: str, pip: float, *, is_sl: bool):
        """Return (price, pips, issue). Converts pip distances to prices when anchored."""
        looks_like_pips = explicit_pips or (ref is not None and abs(v - ref) / ref > 0.5)
        if not looks_like_pips:
            return v, None, None
        flag = None if explicit_pips else "implicit_pips"
        if ref is None:
            return None, v, flag
        sign = -1 if (side == "buy") == is_sl else 1
        return round(ref + sign * v * pip, 6), None, flag

    @staticmethod
    def _validate_levels(side, ref, sl, tps, issues) -> float:
        penalty = 0.0
        anchor = ref
        if anchor is None and sl is not None and tps:
            anchor = None  # can still compare sl vs tp
            if (side == "buy" and sl >= tps[0]) or (side == "sell" and sl <= tps[0]):
                issues.append("sl_tp_inverted")
                penalty += 0.6
            return penalty
        if anchor is None:
            return 0.0
        if sl is not None and ((side == "buy" and sl >= anchor) or (side == "sell" and sl <= anchor)):
            issues.append("sl_wrong_side")
            penalty += 0.6
        bad_tp = [t for t in tps if (side == "buy" and t <= anchor) or (side == "sell" and t >= anchor)]
        if bad_tp:
            issues.append("tp_wrong_side")
            penalty += 0.4
        return penalty

    # ---------------------------------------------------------------- follow-ups
    def _extract_followup(self, norm, text, channel_id, source, profile, symbols):
        issues: list[str] = []
        conf = 0.95 if source.reply_to_id else (0.85 if profile.followups_without_reply else 0.6)
        if not source.reply_to_id:
            issues.append("no_reply_anchor")
        kw = dict(channel_id=channel_id, source=source, symbol=symbols[0] if symbols else None,
                  parser_profile=profile.id, raw_text=text)
        if m := _FOLLOW_PARTIAL.search(norm):
            frac = 0.5 if m.group(1) else min(float(m.group(2)) / 100.0, 1.0)
            return ParsedSignal(kind="close", close_fraction=frac, confidence=conf, **kw), issues
        if _FOLLOW_BE.search(norm):
            return ParsedSignal(kind="modify", move_sl_to_entry=True, confidence=conf, **kw), issues
        m_sl, m_tp = _FOLLOW_NEW_SL.search(norm), _FOLLOW_NEW_TP.search(norm)
        if m_sl or m_tp:
            return ParsedSignal(kind="modify", new_sl=float(m_sl.group(1)) if m_sl else None,
                                new_tp=float(m_tp.group(1)) if m_tp else None, confidence=conf, **kw), issues
        if _FOLLOW_CLOSE.search(norm):
            return ParsedSignal(kind="close", close_fraction=1.0, confidence=conf, **kw), issues
        return None, issues

    # ---------------------------------------------------------------- options
    def _extract_options(self, norm, text, channel_id, source, profile, symbols):
        issues: list[str] = []
        conf = 0.95
        d = _OPTIONS_DIR.search(norm).group(1)
        contract = "rise" if d in ("RISE", "CALL", "HIGHER") else "fall"
        dm = _OPTIONS_DUR.search(norm)
        unit_word = dm.group(2)
        unit = {"T": "t", "S": "s", "M": "m", "H": "h", "D": "d"}[unit_word[0]]
        if unit_word.startswith("MIN"):
            unit = "m"
        stake = None
        if sm := _STAKE.search(norm):
            stake = float(sm.group(1) or sm.group(2))
        if not symbols:
            return None, ["no_symbol"]
        sym = symbols[0]
        info = self.catalog.info(sym)
        if info and info.kind != "synthetic":
            issues.append("options_on_non_synthetic")
            conf -= 0.2
        return ParsedSignal(channel_id=channel_id, source=source, kind="options", symbol=sym,
                            side="buy" if contract == "rise" else "sell",
                            options=OptionsSpec(contract=contract, duration=int(float(dm.group(1))),
                                                duration_unit=unit, stake=stake),
                            confidence=conf, parser_profile=profile.id, raw_text=text), issues

    # ---------------------------------------------------------------- templates
    def _from_template(self, m: re.Match, name, norm, text, channel_id, source, profile, aliases):
        g = {k: v for k, v in m.groupdict().items() if v}
        sym_raw = (g.get("symbol") or "").upper().replace("/", "")
        symbol = aliases.get(sym_raw, sym_raw or None)
        side_raw = (g.get("side") or "").upper()
        side = "buy" if side_raw in [w.upper() for w in profile.buy_words] else \
            "sell" if side_raw in [w.upper() for w in profile.sell_words] else None
        if not symbol or not side:
            return None
        if "entry_from" in g and "entry_to" in g:
            a = float(g["entry_from"])
            entry = EntrySpec(type="range", **{"from": a, "to": _expand_shorthand(a, g["entry_to"])})
        elif "entry" in g:
            entry = EntrySpec(type="market", price=float(g["entry"]))
        else:
            entry = EntrySpec(type="market")
        tps = [float(g[f"tp{i}"]) for i in range(1, 7) if f"tp{i}" in g]
        sl = float(g["sl"]) if "sl" in g else None
        issues: list[str] = [] if sl is not None else ["missing_sl"]
        conf = 0.99 - (0.25 if sl is None else 0)
        conf -= self._validate_levels(side, entry.reference_price(), sl, tps, issues)
        sig = ParsedSignal(channel_id=channel_id, source=source, kind="open", symbol=symbol, side=side,
                           entry=entry, sl=sl, tps=tps, confidence=round(max(conf, 0), 3),
                           parser_profile=profile.id, template=name, raw_text=text)
        return sig, issues
