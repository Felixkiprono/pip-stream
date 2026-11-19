"""Billing: verifies payment webhooks (forwarded by Core API on billing.webhook), records
invoices, updates the user's subscription, emits billing.changed (entitlements)."""
from __future__ import annotations

from datetime import datetime, timedelta, timezone

from ...common.events import BillingChanged, BillingWebhook, Event, Topic
from ...common.ids import new_id
from ...common.log import ctx
from ...common.service import Context
from .providers.base import InvalidWebhook, get


def parse_secrets(value: str | None) -> dict[str, str]:
    out = {}
    for part in (value or "").split(","):
        if ":" in part:
            k, v = part.split(":", 1)
            out[k.strip()] = v.strip()
    return out


class Billing:
    def __init__(self, c: Context):
        self.c = c
        self.secrets = parse_secrets(c.settings.billing_webhook_secrets)

    async def handle(self, ev: Event) -> None:
        wh: BillingWebhook = ev.parse()
        secret = self.secrets.get(wh.provider)
        if not secret:
            self.c.log.error("no webhook secret for provider", extra=ctx(provider=wh.provider))
            return
        try:
            pe = get(wh.provider, secret).verify_and_parse({k.lower(): v for k, v in wh.headers.items()}, wh.body)
        except (InvalidWebhook, KeyError, ValueError) as e:
            self.c.log.warning("webhook rejected", extra=ctx(provider=wh.provider, error=str(e)))
            return
        if pe is None:
            return
        async with self.c.db.acquire() as conn, conn.transaction():
            inserted = await conn.fetchval(
                "insert into invoices (id, user_id, amount_cents, currency, provider, provider_ref, status) "
                "values ($1,$2,$3,$4,$5,$6,'paid') on conflict (provider_ref) do nothing returning id",
                new_id("inv"), pe.user_id, pe.amount_cents, pe.currency, wh.provider, pe.provider_ref)
            if inserted is None:
                return  # duplicate webhook
            await conn.execute(
                "insert into billing_subscriptions (id, user_id, plan_id, status, provider, provider_ref, "
                "current_period_end) values ($1,$2,$3,$4,$5,$6,$7) on conflict (user_id) do update set "
                "plan_id=excluded.plan_id, status=excluded.status, provider=excluded.provider, "
                "provider_ref=excluded.provider_ref, current_period_end=excluded.current_period_end, updated_at=now()",
                new_id("bsub"), pe.user_id, pe.plan_id, pe.status, wh.provider, pe.provider_ref,
                datetime.now(timezone.utc) + timedelta(days=30))
        await self.c.bus.publish(Topic.BILLING_CHANGED, BillingChanged(user_id=pe.user_id, plan_id=pe.plan_id,
                                                                       status=pe.status), trace_id=ev.trace_id)


async def main(c: Context) -> None:
    b = Billing(c)
    await c.bus.subscribe([Topic.BILLING_WEBHOOK], b.handle, consumer=c.consumer)
