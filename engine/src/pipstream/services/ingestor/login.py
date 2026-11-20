"""Fallback: link a Telegram account from a terminal (the web app does the same through
`tg.login.request`, see login_flow.py). Stores the session sealed to intake's key; never prints it.

  docker compose run --rm intake python -m pipstream.services.ingestor.login \
      --account-id tga_spare --user-id usr_owner --phone +2547... --list-channels
"""
from __future__ import annotations

import argparse
import asyncio

from ...common.db import create_pool
from ...common.settings import Settings
from ...common.vault import Opener


async def amain(a) -> None:
    from telethon import TelegramClient
    from telethon.sessions import StringSession

    s = Settings.from_env()
    client = TelegramClient(StringSession(), s.telegram_api_id, s.telegram_api_hash)
    await client.start(phone=a.phone)  # prompts for code and 2FA password
    session = client.session.save()
    me = await client.get_me()
    if a.list_channels:  # helps fill channels.tg_chat_id in config/pipstream.yaml
        async for d in client.iter_dialogs():
            if d.is_channel:
                print(f"{d.id}\t{d.name}")
    await client.disconnect()
    box = Opener(s.intake_private_key)
    pool = await create_pool(s.database_url)
    await pool.execute(
        "insert into telegram_accounts (id, user_id, phone_hint, session_ciphertext, status) "
        "values ($1,$2,$3,$4,'active') on conflict (id) do update set session_ciphertext = excluded.session_ciphertext, "
        "status = 'active'", a.account_id, a.user_id, a.phone[-3:], box.seal(session))
    await pool.close()
    print(f"linked telegram account {a.account_id} (tg user id {me.id}); session stored encrypted")


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--account-id", required=True)
    ap.add_argument("--user-id", required=True)
    ap.add_argument("--phone", required=True)
    ap.add_argument("--list-channels", action="store_true")
    asyncio.run(amain(ap.parse_args()))


if __name__ == "__main__":
    main()
