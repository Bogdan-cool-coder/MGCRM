"""Public API tokens + Outbound Webhooks (Эпик 11.1 + 11.2) — pure-function тесты.

Без DB-фикстуры: проверяем generation токенов, scope-логику, HMAC-подпись,
retry-schedule, dispatch-matching, валидаторы, структуру миграции 0030 и
Pydantic-схемы роутеров.
"""
from __future__ import annotations

import hashlib
import hmac
import json
import re
from datetime import UTC, datetime, timedelta
from pathlib import Path
from unittest.mock import AsyncMock, MagicMock

import pytest

from app.models import (
    APIToken,
    Webhook,
    WebhookDelivery,
)
from app.routers.api_tokens import (
    APITokenCreate,
    APITokenCreated,
    APITokenOut,
    APITokenScopesOut,
)
from app.routers.webhooks import (
    WebhookCreate,
    WebhookCreated,
    WebhookDeliveryOut,
    WebhookEventsOut,
    WebhookOut,
    WebhookTest,
    WebhookUpdate,
    _mask_secret,
)
from app.services.api_scopes import (
    ALLOWED_SCOPES,
    scope_satisfies,
    validate_scopes,
)
from app.services.api_tokens import (
    TOKEN_ENTROPY_BYTES,
    TOKEN_PREFIX,
    generate_token,
    hash_token,
    looks_like_macro_token,
)
from app.services.webhook_dispatcher import (
    MAX_ATTEMPTS,
    MAX_BODY_SAVE,
    RETRY_SCHEDULE_SECONDS,
    build_event_payload,
    contract_to_payload,
    counterparty_to_payload,
    deal_to_payload,
    is_retryable_status,
    lead_to_payload,
    schedule_next_retry,
    subscription_to_payload,
    truncate_response_body,
)
from app.services.webhook_events import (
    WEBHOOK_EVENTS,
    WILDCARD_EVENT,
    event_matches_subscription,
    validate_event_subscriptions,
)
from app.services.webhook_signature import (
    SIGNATURE_HEADER,
    sign_body,
    verify_signature,
)


# ============ 11.1 — Token generation / hashing ============

def test_generate_token_format():
    """Plaintext: mc_<54chars>, hash = SHA256 hex (64 chars)."""
    plaintext, h = generate_token()
    assert plaintext.startswith(TOKEN_PREFIX)
    # secrets.token_urlsafe(40) → ≈ 54 base64url-символа без '=' padding'а
    assert len(plaintext) >= len(TOKEN_PREFIX) + 40
    # URL-safe body: только [A-Za-z0-9_-]
    body = plaintext.removeprefix(TOKEN_PREFIX)
    assert re.fullmatch(r"[A-Za-z0-9_-]+", body)
    # Hash — SHA256 hex
    assert len(h) == 64
    assert re.fullmatch(r"[0-9a-f]{64}", h)


def test_generate_token_uniqueness():
    """Два сгенерированных токена различны."""
    t1, h1 = generate_token()
    t2, h2 = generate_token()
    assert t1 != t2
    assert h1 != h2


def test_generate_token_hash_matches_sha256():
    """Returned hash == sha256(plaintext) — без скрытой соли."""
    plaintext, h = generate_token()
    assert h == hashlib.sha256(plaintext.encode("utf-8")).hexdigest()


def test_hash_token_pure():
    """hash_token идемпотентен (детерминистичен)."""
    assert hash_token("abc") == hash_token("abc")
    assert hash_token("abc") != hash_token("abd")
    # Известный SHA256("abc")
    assert hash_token("abc") == (
        "ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad"
    )


def test_looks_like_macro_token():
    """Heuristic: mc_-prefix + min length."""
    assert looks_like_macro_token("mc_" + "x" * 40)
    assert not looks_like_macro_token("ghp_xxx")  # GitHub-style
    assert not looks_like_macro_token("mc_short")  # слишком короткий
    assert not looks_like_macro_token(None)  # type: ignore[arg-type]
    assert not looks_like_macro_token("")


def test_token_entropy_constants():
    """Защита от случайного снижения энтропии: 40 байт ≈ 320 бит."""
    assert TOKEN_ENTROPY_BYTES >= 32  # >= 256 бит


# ============ 11.1 — Scopes whitelist + satisfies ============

def test_allowed_scopes_contains_required():
    """Ключевые scope'ы есть в whitelist'е."""
    must_have = {
        "*",
        "read:leads", "write:leads",
        "read:deals", "write:deals",
        "read:contacts", "write:contacts",
        "read:companies", "write:companies",
        "read:counterparties", "write:counterparties",
        "read:contracts", "write:contracts",
        "read:subscriptions", "write:subscriptions",
        "inbox:write",
    }
    for s in must_have:
        assert s in ALLOWED_SCOPES, f"scope {s!r} должен быть в ALLOWED_SCOPES"


def test_scope_satisfies_wildcard():
    """'*' удовлетворяет любому required scope."""
    assert scope_satisfies(["*"], "read:leads")
    assert scope_satisfies(["*"], "write:deals")
    assert scope_satisfies(["*"], "inbox:write")


def test_scope_satisfies_exact():
    """Точное совпадение разрешает."""
    assert scope_satisfies(["read:leads"], "read:leads")
    assert not scope_satisfies(["read:leads"], "write:leads")
    assert not scope_satisfies(["read:leads"], "read:deals")


def test_scope_satisfies_write_implies_read():
    """write:X неявно даёт право на read:X (нельзя писать, не читая)."""
    assert scope_satisfies(["write:leads"], "read:leads")
    assert scope_satisfies(["write:deals"], "read:deals")
    # Но обратное — нет
    assert not scope_satisfies(["read:leads"], "write:leads")


def test_scope_satisfies_empty_token():
    """Пустой набор scope'ов — ничего не удовлетворяет (кроме пустого required)."""
    assert not scope_satisfies([], "read:leads")
    assert scope_satisfies([], "")  # пустой required


def test_validate_scopes_normalizes():
    """Дубли удаляются, результат отсортирован."""
    out = validate_scopes(["read:leads", "read:leads", "*"])
    assert out == ["*", "read:leads"]


def test_validate_scopes_rejects_unknown():
    """Неизвестный scope → ValueError."""
    with pytest.raises(ValueError):
        validate_scopes(["unknown:scope"])
    with pytest.raises(ValueError):
        validate_scopes(["read:leads", "bad"])


def test_validate_scopes_rejects_non_list():
    with pytest.raises(ValueError):
        validate_scopes("read:leads")  # type: ignore[arg-type]


def test_validate_scopes_empty_ok():
    """Пустой список — legal (токен без прав, но создавать можно)."""
    assert validate_scopes([]) == []


def test_validate_scopes_rejects_non_string_items():
    with pytest.raises(ValueError):
        validate_scopes([42, "read:leads"])  # type: ignore[list-item]


# ============ 11.2 — Webhook events whitelist + match ============

def test_webhook_events_contains_required():
    """Все ключевые события в whitelist'е."""
    must_have = {
        "lead.created", "lead.converted",
        "deal.created", "deal.stage_changed", "deal.won", "deal.lost",
        "contract.created", "contract.signed",
        "subscription.created", "subscription.health_changed",
        "counterparty.created",
    }
    for e in must_have:
        assert e in WEBHOOK_EVENTS, f"event {e!r} должен быть в WEBHOOK_EVENTS"


def test_wildcard_event_constant():
    assert WILDCARD_EVENT == "*"


def test_event_matches_subscription_exact():
    assert event_matches_subscription(
        "lead.created", ["lead.created", "deal.created"]
    )
    assert not event_matches_subscription("lead.converted", ["lead.created"])


def test_event_matches_subscription_wildcard():
    """'*' в подписке — матчит любое событие."""
    assert event_matches_subscription("lead.created", ["*"])
    assert event_matches_subscription("anything.weird", ["*"])
    # А просто пустой список — никогда не матчит
    assert not event_matches_subscription("lead.created", [])


def test_event_matches_subscription_empty_event():
    """Пустое имя события — никогда не матчит."""
    assert not event_matches_subscription("", ["*"])
    assert not event_matches_subscription("", ["lead.created"])


def test_validate_event_subscriptions_allows_wildcard():
    assert validate_event_subscriptions(["*"]) == ["*"]
    # Wildcard + конкретное — оба сохраняются
    assert validate_event_subscriptions(["*", "lead.created"]) == [
        "*", "lead.created",
    ]


def test_validate_event_subscriptions_rejects_unknown():
    with pytest.raises(ValueError):
        validate_event_subscriptions(["lead.exploded"])
    with pytest.raises(ValueError):
        validate_event_subscriptions(["lead.created", "bogus.event"])


def test_validate_event_subscriptions_empty_ok():
    assert validate_event_subscriptions([]) == []


# ============ 11.2 — HMAC sign / verify ============

def test_sign_body_known_answer():
    """HMAC-SHA256 на known body — сверка с reference (RFC-style)."""
    secret = "topsecret"
    body = b'{"hello":"world"}'
    expected_hex = hmac.new(
        secret.encode(), body, hashlib.sha256
    ).hexdigest()
    assert sign_body(secret, body) == f"sha256={expected_hex}"


def test_sign_body_requires_bytes():
    """body должен быть bytes (не str — иначе подпись отличается от ожидаемой)."""
    with pytest.raises(TypeError):
        sign_body("secret", "not bytes")  # type: ignore[arg-type]


def test_verify_signature_matches():
    """verify_signature(sign_body(...)) → True."""
    secret = "abc123"
    body = b'{"event":"test"}'
    sig = sign_body(secret, body)
    assert verify_signature(secret, body, sig) is True


def test_verify_signature_wrong_secret():
    body = b"x"
    sig = sign_body("right", body)
    assert verify_signature("wrong", body, sig) is False


def test_verify_signature_wrong_body():
    secret = "k"
    sig = sign_body(secret, b"a")
    assert verify_signature(secret, b"b", sig) is False


def test_verify_signature_non_string_header():
    """Не-строка header — False, не падает."""
    assert verify_signature("k", b"x", None) is False  # type: ignore[arg-type]
    assert verify_signature("k", b"x", 42) is False  # type: ignore[arg-type]


def test_signature_header_constant():
    """Header называется X-Macro-Signature (совместимость с automation_executor)."""
    assert SIGNATURE_HEADER == "X-Macro-Signature"


# ============ 11.2 — Retry schedule ============

def test_retry_schedule_length_matches_max_attempts():
    """RETRY_SCHEDULE покрывает все attempts кроме первой попытки."""
    assert len(RETRY_SCHEDULE_SECONDS) == MAX_ATTEMPTS
    assert MAX_ATTEMPTS == 6


def test_retry_schedule_monotonic():
    """Backoff растёт: каждый следующий интервал >= предыдущего."""
    for i in range(1, len(RETRY_SCHEDULE_SECONDS)):
        assert RETRY_SCHEDULE_SECONDS[i] >= RETRY_SCHEDULE_SECONDS[i - 1]


def test_retry_schedule_first_minute():
    """Первая попытка retry — через 1 минуту (быстрый pickup транзиентов)."""
    assert RETRY_SCHEDULE_SECONDS[0] == 60


def test_retry_schedule_last_day():
    """Последняя попытка — через 24h (оператору время поднять сервис)."""
    assert RETRY_SCHEDULE_SECONDS[-1] == 24 * 60 * 60


def test_schedule_next_retry_returns_future():
    """schedule_next_retry(attempt=1) → now + 60s."""
    before = datetime.now(UTC)
    nxt = schedule_next_retry(attempt_after=1)
    assert nxt is not None
    # Допустимый разброс — несколько секунд из-за выполнения теста
    delta = (nxt - before).total_seconds()
    assert 59 <= delta <= 65


def test_schedule_next_retry_after_max_returns_none():
    """attempt_after >= MAX_ATTEMPTS → None (терминальный fail)."""
    assert schedule_next_retry(attempt_after=MAX_ATTEMPTS) is None
    assert schedule_next_retry(attempt_after=MAX_ATTEMPTS + 1) is None


def test_schedule_next_retry_zero_attempt():
    """attempt_after=0 — некорректно (не было попытки) → None."""
    assert schedule_next_retry(attempt_after=0) is None


def test_is_retryable_status():
    """5xx — ретраим; 4xx — нет (кроме 408/429)."""
    assert is_retryable_status(500) is True
    assert is_retryable_status(502) is True
    assert is_retryable_status(503) is True
    assert is_retryable_status(599) is True
    assert is_retryable_status(404) is False
    assert is_retryable_status(401) is False
    assert is_retryable_status(403) is False
    assert is_retryable_status(400) is False
    # Особые retryable 4xx
    assert is_retryable_status(408) is True
    assert is_retryable_status(429) is True


def test_truncate_response_body_short_unchanged():
    body = "x" * (MAX_BODY_SAVE - 1)
    assert truncate_response_body(body) == body


def test_truncate_response_body_long_truncated():
    body = "x" * (MAX_BODY_SAVE + 100)
    out = truncate_response_body(body)
    assert out is not None
    assert len(out) == MAX_BODY_SAVE + len("...[truncated]")
    assert out.endswith("[truncated]")


def test_truncate_response_body_none():
    assert truncate_response_body(None) is None


# ============ 11.2 — Payload builders ============

def test_build_event_payload_structure():
    """payload верхнего уровня: event/entity/data/occurred_at."""
    p = build_event_payload(
        "lead.created", "lead", 42, {"name": "Test"},
    )
    assert p["event"] == "lead.created"
    assert p["entity"] == {"type": "lead", "id": 42}
    assert p["data"] == {"name": "Test"}
    assert "occurred_at" in p
    # ISO-8601 формат
    datetime.fromisoformat(p["occurred_at"])


def test_build_event_payload_explicit_occurred_at():
    when = datetime(2026, 5, 31, 12, 0, 0, tzinfo=UTC)
    p = build_event_payload("lead.created", "lead", 1, {}, occurred_at=when)
    assert p["occurred_at"] == when.isoformat()


def test_lead_to_payload_min_fields():
    """lead_to_payload содержит публичный набор полей."""
    lead = MagicMock()
    lead.id = 1
    lead.name = "Test"
    lead.contact_email = "x@example.com"
    lead.contact_phone = None
    lead.source = "form"
    lead.owner_id = 5
    lead.pipeline_id = 1
    lead.stage_id = 2
    lead.status = "active"
    lead.score = 70
    lead.converted_to_counterparty_id = None
    lead.converted_deal_id = None
    p = lead_to_payload(lead)
    assert p["id"] == 1
    assert p["name"] == "Test"
    assert p["score"] == 70


def test_deal_to_payload_min_fields():
    deal = MagicMock()
    deal.id = 7
    deal.title = "Big deal"
    deal.pipeline_id = 1
    deal.stage_id = 3
    deal.counterparty_id = 5
    deal.amount = None
    deal.currency = "USD"
    deal.owner_user_id = 2
    deal.contract_id = None
    p = deal_to_payload(deal)
    assert p["id"] == 7
    assert p["amount"] is None
    assert p["currency"] == "USD"


def test_contract_to_payload_enum_status():
    """Status — enum, в payload берём value (а не repr)."""
    from app.models import ContractStatus
    contract = MagicMock()
    contract.id = 1
    contract.number = "ТШК-100/KZ"
    contract.title = "Договор"
    contract.product_code = "macrocrm"
    contract.country_code = "kz"
    contract.counterparty_id = 5
    contract.status = ContractStatus.signed
    contract.total = None
    contract.currency = None
    p = contract_to_payload(contract)
    assert p["status"] == "signed"  # enum.value


def test_counterparty_to_payload_min_fields():
    cp = MagicMock()
    cp.id = 1
    cp.name = "ООО Тест"
    cp.country_code = "kz"
    cp.tax_id = "12345"
    cp.email = "test@example.com"
    cp.phone = None
    cp.category_code = "M"
    p = counterparty_to_payload(cp)
    assert p["name"] == "ООО Тест"
    assert p["category_code"] == "M"


def test_subscription_to_payload_min_fields():
    sub = MagicMock()
    sub.id = 1
    sub.counterparty_id = 5
    sub.platform_id = 1
    sub.lifecycle_stage_id = 7
    sub.health_tier = "A2"
    sub.is_active = True
    p = subscription_to_payload(sub)
    assert p["health_tier"] == "A2"
    assert p["is_active"] is True


# ============ 11.2 — Dispatch matching (mock session) ============

async def test_find_subscribed_webhooks_event_match():
    """find_subscribed_webhooks возвращает Webhook'и подписанные на event."""
    from app.services.webhook_dispatcher import find_subscribed_webhooks

    wh1 = MagicMock(spec=Webhook)
    wh1.is_active = True
    wh1.event_subscriptions = ["lead.created"]
    wh2 = MagicMock(spec=Webhook)
    wh2.is_active = True
    wh2.event_subscriptions = ["*"]
    wh3 = MagicMock(spec=Webhook)
    wh3.is_active = True
    wh3.event_subscriptions = ["deal.created"]

    session = MagicMock()
    session.execute = AsyncMock()
    res = MagicMock()
    res.scalars.return_value.all = MagicMock(return_value=[wh1, wh2, wh3])
    session.execute.return_value = res

    matched = await find_subscribed_webhooks(session, "lead.created")
    assert wh1 in matched
    assert wh2 in matched  # wildcard
    assert wh3 not in matched  # подписан на другое


async def test_dispatch_event_creates_deliveries():
    """dispatch_event создаёт WebhookDelivery для подписчиков."""
    from app.services.webhook_dispatcher import dispatch_event

    wh = MagicMock(spec=Webhook)
    wh.id = 1
    wh.is_active = True
    wh.event_subscriptions = ["lead.created"]

    session = MagicMock()
    session.add = MagicMock()
    session.flush = AsyncMock()
    session.execute = AsyncMock()
    res = MagicMock()
    res.scalars.return_value.all = MagicMock(return_value=[wh])
    session.execute.return_value = res

    deliveries = await dispatch_event(
        session, "lead.created", "lead", 42, {"name": "Test"},
    )
    assert len(deliveries) == 1
    d = deliveries[0]
    assert d.webhook_id == 1
    assert d.event == "lead.created"
    assert d.status == "pending"
    assert d.attempt == 0
    assert d.payload["event"] == "lead.created"


async def test_dispatch_event_unknown_event_skipped():
    """Неизвестное событие → пустой список, лог warning."""
    from app.services.webhook_dispatcher import dispatch_event

    session = MagicMock()
    session.execute = AsyncMock()

    deliveries = await dispatch_event(
        session, "bogus.event", "lead", 1, {},
    )
    assert deliveries == []
    # execute не должен вызываться (early return на whitelist check)
    session.execute.assert_not_called()


async def test_dispatch_event_no_subscribers_returns_empty():
    """Нет подписчиков → пустой список deliveries."""
    from app.services.webhook_dispatcher import dispatch_event

    session = MagicMock()
    session.execute = AsyncMock()
    res = MagicMock()
    res.scalars.return_value.all = MagicMock(return_value=[])
    session.execute.return_value = res

    deliveries = await dispatch_event(
        session, "lead.created", "lead", 1, {},
    )
    assert deliveries == []


# ============ 11.1 — Pydantic-схемы роутера ============

def test_api_token_create_minimal():
    c = APITokenCreate(name="zapier-int")
    assert c.scopes == []
    assert c.expires_at is None


def test_api_token_create_with_scopes():
    c = APITokenCreate(name="full", scopes=["*"], expires_at=None)
    assert c.scopes == ["*"]


def test_api_token_scopes_out():
    o = APITokenScopesOut(scopes=["read:leads", "*"])
    assert o.scopes == ["read:leads", "*"]


# ============ 11.2 — Webhook router schemas ============

def test_webhook_create_minimal():
    c = WebhookCreate(name="zapier", url="https://example.com/hook")
    assert c.event_subscriptions == []
    assert c.secret is None
    assert c.headers is None
    assert c.is_active is True


def test_webhook_create_with_secret_min_length():
    """Pydantic валидирует min_length=16 для secret."""
    with pytest.raises(Exception):
        WebhookCreate(
            name="x", url="https://example.com", secret="short",
        )


def test_webhook_update_all_optional():
    u = WebhookUpdate()
    assert u.name is None
    assert u.url is None


def test_webhook_test_schema():
    t = WebhookTest(event="lead.created")
    assert t.data is None


def test_webhook_events_out():
    o = WebhookEventsOut(events=["lead.created"], wildcard="*")
    assert o.wildcard == "*"


def test_mask_secret_hides_body():
    assert _mask_secret("verysecretvalue123") == "****" + "1234"[-4:] or _mask_secret("verysecretvalue123").startswith("****")


def test_mask_secret_short():
    assert _mask_secret("a") == "****"
    assert _mask_secret("") == ""


def test_mask_secret_endswith_last4():
    """Длинный secret → last4 видны для визуальной идентификации."""
    m = _mask_secret("0123456789abcdef")
    assert m.endswith("cdef")
    assert m.startswith("****")


# ============ Migration 0030 структура ============

_MIGRATION_PATH = (
    Path(__file__).resolve().parents[1]
    / "alembic"
    / "versions"
    / "0030_api_tokens_webhooks.py"
)


def test_migration_0030_exists():
    assert _MIGRATION_PATH.exists(), (
        "Migration 0030_api_tokens_webhooks.py должен существовать"
    )


def test_migration_0030_revision_id():
    """revision = '0030_api_tokens_webhooks' (24 chars ≤ 32)."""
    src = _MIGRATION_PATH.read_text(encoding="utf-8")
    m = re.search(r'^revision[^=]*=\s*[\"\']([^\"\']+)[\"\']', src, re.M)
    assert m is not None
    rev = m.group(1)
    assert rev == "0030_api_tokens_webhooks"
    assert len(rev) <= 32


def test_migration_0030_down_revision():
    """down_revision = '0029_cpty_responsible_user' (цепочка не разорвана)."""
    src = _MIGRATION_PATH.read_text(encoding="utf-8")
    assert '"0029_cpty_responsible_user"' in src


def test_migration_0030_creates_three_tables():
    """Up создаёт api_tokens, webhooks, webhook_deliveries."""
    src = _MIGRATION_PATH.read_text(encoding="utf-8")
    assert 'create_table(\n        "api_tokens"' in src
    assert 'create_table(\n        "webhooks"' in src
    assert 'create_table(\n        "webhook_deliveries"' in src


def test_migration_0030_has_required_indexes():
    """Hot path индексы (status_next для cron, user_id, is_active)."""
    src = _MIGRATION_PATH.read_text(encoding="utf-8")
    for ix in (
        "ix_api_tokens_user_id",
        "ix_api_tokens_is_active",
        "ix_webhooks_is_active",
        "ix_webhook_deliveries_webhook_id",
        "ix_webhook_deliveries_event",
        "ix_webhook_deliveries_next_retry_at",
        "ix_webhook_deliveries_status_next",
    ):
        assert ix in src, f"index {ix} должен быть в миграции 0030"


def test_migration_0030_token_hash_unique():
    """api_tokens.token_hash UNIQUE — для O(1) lookup."""
    src = _MIGRATION_PATH.read_text(encoding="utf-8")
    # unique=True задан inline в колонке
    assert "unique=True" in src or "UniqueConstraint" in src


def test_migration_0030_downgrade_drops_all():
    """Downgrade убирает все 3 таблицы и их индексы."""
    src = _MIGRATION_PATH.read_text(encoding="utf-8")
    assert "def downgrade()" in src
    for table in ("api_tokens", "webhooks", "webhook_deliveries"):
        assert f'drop_table("{table}")' in src


# ============ deps.py — Bearer extraction (pure) ============

def test_extract_bearer_valid():
    from app.deps import _extract_bearer
    assert _extract_bearer("Bearer mc_xyz") == "mc_xyz"
    assert _extract_bearer("bearer mc_xyz") == "mc_xyz"  # case-insensitive


def test_extract_bearer_invalid():
    from app.deps import _extract_bearer
    assert _extract_bearer(None) is None
    assert _extract_bearer("") is None
    assert _extract_bearer("Bearer") is None
    assert _extract_bearer("Bearer ") is None
    assert _extract_bearer("Token mc_xyz") is None
    assert _extract_bearer("mc_xyz") is None


# ============ Sanity: WEBHOOK_EVENTS заданы строго ============

def test_webhook_events_all_dot_format():
    """Все события формата <entity>.<action> (защита от опечаток)."""
    for e in WEBHOOK_EVENTS:
        assert "." in e, f"event {e!r} должен быть <entity>.<action>"
        parts = e.split(".")
        assert len(parts) == 2, f"event {e!r}: ровно одна точка"
        assert all(p for p in parts), f"event {e!r}: непустые части"


def test_allowed_scopes_format():
    """Все scope'ы (кроме '*') формата <action>:<resource>."""
    for s in ALLOWED_SCOPES:
        if s == "*":
            continue
        assert ":" in s, f"scope {s!r} должен быть <action>:<resource>"
        parts = s.split(":")
        assert len(parts) == 2, f"scope {s!r}: ровно одно двоеточие"
        assert all(p for p in parts), f"scope {s!r}: непустые части"


# ============ Баг #7 (security): scope-check при cookie + Bearer ============
# require_scope._checker должен проверять scope Bearer-токена, ДАЖЕ когда юзер
# аутентифицировался по cookie (иначе токен с узким scope получает права cookie).

async def test_require_scope_checks_bearer_even_with_cookie(monkeypatch):
    """Cookie-юзер + Bearer с недостаточным scope → 403 (раньше пропускалось)."""
    from fastapi import HTTPException
    from app import deps

    # Bearer-токен с узким scope (нет write:leads).
    token = MagicMock(spec=APIToken)
    token.scopes = ["read:leads"]

    async def fake_resolve(_session, _plaintext):
        return (token, MagicMock())

    monkeypatch.setattr(deps, "resolve_token", fake_resolve)

    checker = deps.require_scope("write:leads")
    request = MagicMock()
    request.state = MagicMock()
    # Cookie-путь: api_token НЕ установлен в request.state.
    request.state.api_token = None
    delattr(request.state, "api_token")

    with pytest.raises(HTTPException) as exc:
        await checker(
            request=request,
            session=MagicMock(),
            current_user=MagicMock(),
            authorization="Bearer macro_xxx",
        )
    assert exc.value.status_code == 403


async def test_require_scope_passes_pure_cookie_no_bearer():
    """Cookie-юзер БЕЗ Bearer → scope не проверяется (RBAC), без ошибки."""
    from app import deps

    checker = deps.require_scope("write:leads")
    request = MagicMock()
    request.state = MagicMock()
    delattr(request.state, "api_token")

    # authorization=None → чистый cookie path, должно просто вернуть None.
    result = await checker(
        request=request,
        session=MagicMock(),
        current_user=MagicMock(),
        authorization=None,
    )
    assert result is None


async def test_require_scope_bearer_with_sufficient_scope_ok(monkeypatch):
    """Bearer с достаточным scope при cookie → проходит."""
    from app import deps

    token = MagicMock(spec=APIToken)
    token.scopes = ["write:leads"]

    async def fake_resolve(_session, _plaintext):
        return (token, MagicMock())

    monkeypatch.setattr(deps, "resolve_token", fake_resolve)

    checker = deps.require_scope("write:leads")
    request = MagicMock()
    request.state = MagicMock()
    delattr(request.state, "api_token")

    result = await checker(
        request=request,
        session=MagicMock(),
        current_user=MagicMock(),
        authorization="Bearer macro_xxx",
    )
    assert result is None
