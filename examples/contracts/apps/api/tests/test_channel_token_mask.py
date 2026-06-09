"""C4 CRIT-3 — secret_token канала не утекает всем CurrentUser.

ChannelOut (list/get) отдаёт только маску; ChannelSecretOut (create/regenerate/
reveal) — полный токен. Pure-function тесты без БД.
"""
from __future__ import annotations

from datetime import UTC, datetime
from types import SimpleNamespace

from app.routers.channels import ChannelOut, ChannelSecretOut, _mask_token


def _fake_channel(token: str = "supersecrettoken1234") -> SimpleNamespace:
    now = datetime(2026, 6, 5, tzinfo=UTC)
    return SimpleNamespace(
        id=1,
        name="TG канал",
        kind="tg",
        secret_token=token,
        config={},
        default_lead_source="tg",
        default_owner_id=None,
        default_pipeline_id=None,
        default_stage_id=None,
        is_active=True,
        created_at=now,
        updated_at=now,
    )


def test_mask_token_shows_last4():
    assert _mask_token("supersecrettoken1234") == "****1234"


def test_mask_token_short_fully_masked():
    assert _mask_token("short") == "****"
    assert _mask_token("12345678") == "****"  # ровно 8 → не раскрываем


def test_mask_token_empty():
    assert _mask_token("") == "****"
    assert _mask_token(None) == "****"


def test_channel_out_has_no_full_token():
    out = ChannelOut.from_channel(_fake_channel())
    # Замаскированное поле есть, полного secret_token в выдаче нет.
    assert out.secret_token_preview == "****1234"
    assert not hasattr(out, "secret_token")
    dumped = out.model_dump()
    assert "secret_token" not in dumped
    assert dumped["secret_token_preview"] == "****1234"


def test_channel_secret_out_carries_full_token():
    out = ChannelSecretOut.from_channel(_fake_channel())
    assert out.secret_token == "supersecrettoken1234"
    assert out.secret_token_preview == "****1234"
