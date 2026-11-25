"""Channel self-test: a customer posts a one-time code (PS-XXXXXX) in a channel they follow and
the app shows them, live, that the post arrived, how it was read and what would be copied.

Laravel starts a test by writing two Redis keys (TTL 30 min):
    ps:chtest:code:<CODE>   {test_id, user_id, channel_id, place}
    ps:chtest:<test_id>     the result the app polls; state "waiting"
intake (parser) fills in `arrived` and `parse`; the risk engine fills in `plan`. A post that
carries a code is never handed to anyone else's copy rules, and it opens no trade unless the
customer ticked "place it" (place=true), and then only on their own rule.
"""
from __future__ import annotations

import json
import re
import time

CODE_RE = re.compile(r"\bPS-([A-HJ-NP-Z2-9]{6})\b", re.I)
# the friendly words around the code in the text we ask customers to paste
_INTRO_RE = re.compile(r"^\s*pip\s*stream\s+(?:self[- ]?)?test\b[:.!\s-]*", re.I | re.M)
TTL_S = 3600


def find_code(text: str) -> str | None:
    m = CODE_RE.search(text or "")
    return m.group(1).upper() if m else None


def strip_code(text: str) -> str:
    """The post without the code and the "PipStream test" intro: what the parser should read."""
    out = CODE_RE.sub(" ", text or "")
    out = _INTRO_RE.sub("", out)
    return "\n".join(line.rstrip() for line in out.splitlines() if line.strip()).strip()


def code_key(prefix: str, code: str) -> str:
    return f"{prefix}:chtest:code:{code.upper()}"


def result_key(prefix: str, test_id: str) -> str:
    return f"{prefix}:chtest:{test_id}"


async def load_meta(redis, prefix: str, code: str) -> dict | None:
    raw = await redis.get(code_key(prefix, code))
    return json.loads(raw) if raw else None


async def update(redis, prefix: str, test_id: str, **fields) -> dict:
    key = result_key(prefix, test_id)
    raw = await redis.get(key)
    cur = json.loads(raw) if raw else {"test_id": test_id}
    cur.update(fields, updated_at=time.time())
    await redis.set(key, json.dumps(cur, default=str), ex=TTL_S)
    return cur
