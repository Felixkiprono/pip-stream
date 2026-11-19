from datetime import datetime, timedelta, timezone

import pytest

from pipstream.common.events import SymbolSpec, TradeIntent
from pipstream.common.signing import IntentSigner, IntentVerifier, generate_keypair
from pipstream.common.vault import SecretBox
from pipstream.services.risk_engine.sizing import SizingError, finalize, resolve_symbol, risk_lots, split_tps


def _intent(**kw):
    base = dict(idempotency_key="k", user_id="u", broker_account_id="b", copy_rule_id="r", connector="paper",
                mode="paper", action="open", signal_id="s", channel_id="c", source_message_id=1, symbol="XAUUSD",
                side="buy", volume=0.1, expires_at=datetime.now(timezone.utc) + timedelta(seconds=5))
    base.update(kw)
    return TradeIntent(**base)


def test_sign_verify_tamper_expiry_rotation():
    priv, pub = generate_keypair()
    signer = IntentSigner(priv, "k1")
    v = IntentVerifier.from_setting(pub)
    signed = signer.sign(_intent())
    assert v.verify(signed)
    assert not v.verify(signed.model_copy(update={"volume": 10.0}))          # tampered
    assert not v.verify(signed.model_copy(update={"signature": None}))
    _, other_pub = generate_keypair()
    assert not IntentVerifier.from_setting(other_pub).verify(signed)         # wrong key
    assert IntentVerifier.from_setting(f"k0:{other_pub},k1:{pub}").verify(signed)  # rotation
    assert v.expired(_intent(expires_at=datetime.now(timezone.utc) - timedelta(milliseconds=1)))


def test_secretbox_roundtrip_and_rotation():
    k1, k2 = SecretBox.generate_key(), SecretBox.generate_key()
    tok = SecretBox(k1).encrypt({"password": "hunter2-secret"})
    assert "hunter2" not in tok
    both = SecretBox(f"{k2},{k1}")
    assert both.decrypt(tok) == {"password": "hunter2-secret"}
    assert SecretBox(k2).decrypt(both.rotate(tok)) == {"password": "hunter2-secret"}


GOLD = SymbolSpec(point=0.01, tick_size=0.01, tick_value=1.0, contract_size=100, volume_min=0.01, volume_step=0.01)


def test_risk_sizing():
    # $10k x 1% = $100 risk; 9.00 SL distance = 900 ticks x $1 = $900 per lot -> 0.111 lots
    assert risk_lots(10_000, 1.0, 2349, 2340, GOLD) == pytest.approx(0.1111, rel=1e-3)
    assert finalize(0.1111, GOLD, max_lot=1.0) == 0.11
    assert finalize(5.0, GOLD, max_lot=0.5) == 0.5
    with pytest.raises(SizingError):
        finalize(0.004, GOLD, max_lot=1.0)


def test_split_tps():
    assert split_tps(0.11, 2, GOLD) == [0.06, 0.05]
    assert split_tps(0.02, 3, GOLD) == [0.01, 0.01]      # cannot do 3 legs of 0.0067
    assert split_tps(0.3, 3, GOLD, "first_only") == [0.3]


def test_resolve_symbol():
    assert resolve_symbol("XAUUSD", {}, {"XAUUSDm", "EURUSDm"}) == "XAUUSDm"
    assert resolve_symbol("XAUUSD", {"XAUUSD": "GOLD"}, {"GOLD"}) == "GOLD"
    assert resolve_symbol("XAUUSD", {}, None) == "XAUUSD"
    assert resolve_symbol("US30", {}, {"EURUSD"}) is None
