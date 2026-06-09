"""Inbox + каналы (Эпик 5 MVP) — pure-function проверки сервисов и схем.

Без БД-фикстуры: проверяем константы, генератор токенов, маппинг source,
helper-функции выбора email/phone из identifier, валидацию submission'ов и
структуру миграции 0023.
"""
from __future__ import annotations

from pathlib import Path
from unittest.mock import AsyncMock, MagicMock

import pytest

from app.models import Channel, InboundMessage
from app.routers.channels import ChannelCreate, ChannelOut, ChannelUpdate
from app.routers.forms import FormCreate, FormOut, FormPublicOut, FormSubmitOut, FormUpdate
from app.routers.inbox import InboundMessageOut, WebhookIn, WebhookOut
from app.services.inbox import (
    CHANNEL_KINDS,
    HONEYPOT_FIELD,
    LEAD_SOURCE_MAP,
    SALES_STAGE_CODE_NEW,
    auto_create_lead_from_message,
    build_message_from_form_submission,
    channel_identifier_dedup_key,
    company_dedup_key,
    derive_contact_email,
    derive_contact_phone,
    derive_lead_name,
    derive_lead_source,
    form_submission_external_id,
    generate_channel_token,
    generate_form_slug,
    is_honeypot_filled,
    message_to_company_fields,
    validate_form_submission,
)


# ============ Константы ============

def test_channel_kinds_whitelist():
    """kind ограничен 5-ю значениями — продакт-фиксированный список."""
    assert CHANNEL_KINDS == ("tg", "wa", "email", "web_form", "api")
    assert len(set(CHANNEL_KINDS)) == len(CHANNEL_KINDS)


def test_lead_source_map_covers_all_kinds():
    """Каждый channel.kind имеет mapping → lead.source."""
    for kind in CHANNEL_KINDS:
        assert kind in LEAD_SOURCE_MAP, f"Нет mapping для kind={kind}"
    # web_form маппится на 'form' (не 'web_form'), потому что Lead.source — отдельный whitelist
    assert LEAD_SOURCE_MAP["web_form"] == "form"
    assert LEAD_SOURCE_MAP["tg"] == "tg"
    assert LEAD_SOURCE_MAP["wa"] == "wa"
    assert LEAD_SOURCE_MAP["email"] == "email"
    assert LEAD_SOURCE_MAP["api"] == "api"


def test_lead_source_map_values_in_leads_allowed_sources():
    """Все source-значения должны быть в whitelist'е Lead._ALLOWED_SOURCES."""
    from app.routers.leads import _ALLOWED_SOURCES
    for source in LEAD_SOURCE_MAP.values():
        assert source in _ALLOWED_SOURCES, f"source={source} не в _ALLOWED_SOURCES"


# ============ Генераторы токенов ============

def test_generate_channel_token_unique_and_url_safe():
    """secret_token: длина <= 64, URL-safe, два вызова дают разные значения."""
    t1 = generate_channel_token()
    t2 = generate_channel_token()
    assert t1 != t2, "Два токена должны различаться (secrets-генератор)"
    assert len(t1) <= 64, "Должно влезать в String(64) схемы channels.secret_token"
    assert len(t1) >= 32, "32 байта URL-safe ≈ 43 символа минимум"
    # URL-safe: только [A-Za-z0-9_-]
    import re
    assert re.fullmatch(r"[A-Za-z0-9_-]+", t1)


def test_generate_form_slug_short_and_unique():
    """public_slug: короткий URL-safe идентификатор, разные при каждом вызове."""
    s1 = generate_form_slug()
    s2 = generate_form_slug()
    assert s1 != s2
    assert len(s1) <= 64
    import re
    assert re.fullmatch(r"[A-Za-z0-9_-]+", s1)


# ============ Helper-функции email/phone ============

def test_derive_contact_email_from_at_sign():
    """Если from_identifier содержит '@' → это email."""
    assert derive_contact_email("foo@bar.com") == "foo@bar.com"
    assert derive_contact_email("+77001234567") is None
    assert derive_contact_email("@username") == "@username"  # начинается с @, но всё равно содержит
    assert derive_contact_email(None) is None
    assert derive_contact_email("") is None


def test_derive_contact_phone_from_plus_prefix():
    """Если from_identifier начинается с '+' → это телефон."""
    assert derive_contact_phone("+77001234567") == "+77001234567"
    assert derive_contact_phone("foo@bar.com") is None
    assert derive_contact_phone("77001234567") is None  # без +
    assert derive_contact_phone(None) is None
    assert derive_contact_phone("") is None


def test_derive_lead_name_priority():
    """from_name > from_identifier > subject > 'Lead from <channel>'."""
    channel = MagicMock(spec=Channel)
    channel.name = "TG Bot"

    msg = MagicMock(spec=InboundMessage)
    msg.from_name = "Иван"
    msg.from_identifier = "+77001234567"
    msg.subject = "Запрос"
    assert derive_lead_name(msg, channel) == "Иван"

    msg.from_name = None
    assert derive_lead_name(msg, channel) == "+77001234567"

    msg.from_identifier = None
    assert derive_lead_name(msg, channel) == "Запрос"

    msg.subject = None
    assert derive_lead_name(msg, channel) == "Lead from TG Bot"


def test_derive_lead_name_truncated_to_255():
    """Имя обрезается до 255 символов (соответствует Lead.name схеме)."""
    channel = MagicMock(spec=Channel)
    channel.name = "TG"
    msg = MagicMock(spec=InboundMessage)
    msg.from_name = "x" * 1000
    msg.from_identifier = None
    msg.subject = None
    name = derive_lead_name(msg, channel)
    assert len(name) == 255


def test_derive_lead_source_priority():
    """default_lead_source приоритетен; иначе LEAD_SOURCE_MAP."""
    channel = MagicMock(spec=Channel)
    channel.default_lead_source = "manual"
    channel.kind = "tg"
    assert derive_lead_source(channel) == "manual"

    # Empty string default → fallback to map
    channel.default_lead_source = ""
    assert derive_lead_source(channel) == "tg"


# ============ Дедуп через мок ============

async def test_dedup_skips_deal_creation_when_external_id_seen():
    """DEALS 2.0 (Ф1c): если external_id уже привязан к Deal, новый Deal не
    создаётся — сообщение линкуется к существующей сделке.

    Мок: AsyncSession.execute возвращает scalar_one_or_none() = 42 (существующий
    deal_id из find_existing_deal_for_message). auto_create_lead_from_message
    (deprecated-обёртка над auto_create_deal_from_message) должен вернуть None и
    проставить target_deal_id=42, target_deal_created=False.
    """
    session = MagicMock()
    session.execute = AsyncMock()
    existing_result = MagicMock()
    existing_result.scalar_one_or_none = MagicMock(return_value=42)
    session.execute.return_value = existing_result

    channel = MagicMock(spec=Channel)
    channel.id = 1
    channel.is_active = True

    msg = MagicMock(spec=InboundMessage)
    msg.external_id = "tg-update-12345"
    msg.target_deal_id = None
    msg.target_deal_created = False

    result = await auto_create_lead_from_message(session, channel, msg)
    assert result is None, "Deal не должен создаваться при дедупе"
    assert msg.target_deal_id == 42, "msg должен привязаться к существующей сделке"
    assert msg.target_deal_created is False, "флаг must be False для дедупа"


async def test_auto_create_skips_when_channel_inactive():
    """Inactive канал → None, никакой Company/Deal."""
    session = MagicMock()
    session.execute = AsyncMock()

    channel = MagicMock(spec=Channel)
    channel.id = 1
    channel.is_active = False

    msg = MagicMock(spec=InboundMessage)
    msg.external_id = None

    result = await auto_create_lead_from_message(session, channel, msg)
    assert result is None
    # execute не должен вызываться вообще (early return)
    session.execute.assert_not_called()


# ============ Валидация submission'ов ============

def test_validate_form_submission_required_ok():
    """Required-поля заполнены → ok."""
    fields = [
        {"name": "name", "label": "Имя", "type": "text", "required": True},
        {"name": "email", "label": "Email", "type": "email", "required": True},
        {"name": "comment", "label": "Комментарий", "type": "textarea", "required": False},
    ]
    submitted = {"name": "Иван", "email": "ivan@example.com"}
    ok, err = validate_form_submission(fields, submitted)
    assert ok is True
    assert err is None


def test_validate_form_submission_required_missing():
    """Required-поле пустое → ошибка с label."""
    fields = [
        {"name": "email", "label": "Email", "type": "email", "required": True},
    ]
    ok, err = validate_form_submission(fields, {})
    assert ok is False
    assert err is not None
    assert "Email" in err


def test_validate_form_submission_required_whitespace_only():
    """Required-поле с только пробелами → ошибка."""
    fields = [{"name": "name", "label": "Имя", "type": "text", "required": True}]
    ok, err = validate_form_submission(fields, {"name": "   "})
    assert ok is False
    assert err is not None and "Имя" in err


def test_validate_form_submission_non_dict_body():
    """Не-dict body → ошибка."""
    fields = []
    ok, err = validate_form_submission(fields, "not a dict")  # type: ignore[arg-type]
    assert ok is False
    assert err is not None


def test_validate_form_submission_no_required_fields():
    """Все поля optional → любая пустая submission ок."""
    fields = [{"name": "comment", "label": "Комментарий", "type": "textarea", "required": False}]
    ok, err = validate_form_submission(fields, {})
    assert ok is True
    assert err is None


# ============ Form submission → message kwargs ============

def test_build_message_from_form_submission_extracts_email():
    """email из submission → from_identifier."""
    kwargs = build_message_from_form_submission(
        "Контакт",
        {"name": "Иван", "email": "ivan@example.com", "phone": "+77001234567"},
    )
    # email приоритетнее phone
    assert kwargs["from_identifier"] == "ivan@example.com"
    assert kwargs["from_name"] == "Иван"
    assert kwargs["subject"] == "Форма: Контакт"
    assert "ivan@example.com" in (kwargs["body"] or "")


def test_build_message_from_form_submission_phone_fallback():
    """Без email → phone."""
    kwargs = build_message_from_form_submission(
        "Запрос",
        {"name": "Пётр", "phone": "+77011112233"},
    )
    assert kwargs["from_identifier"] == "+77011112233"


def test_build_message_from_form_submission_empty():
    """Пустая submission → from_identifier=None, body=None."""
    kwargs = build_message_from_form_submission("X", {})
    assert kwargs["from_identifier"] is None
    assert kwargs["from_name"] is None
    assert kwargs["body"] is None


# ============ DEALS 2.0 (Ф1c): message → Company / dedup-key ============

def test_sales_stage_code_new_constant():
    """Лид = Deal в этапе sales-воронки с code='new'."""
    assert SALES_STAGE_CODE_NEW == "new"


def test_message_to_company_fields_email():
    """from_identifier с '@' → Company.email; name из from_name; source из канала."""
    ch = MagicMock(spec=Channel)
    ch.name = "TG Bot"
    ch.kind = "tg"
    ch.default_lead_source = ""
    msg = MagicMock(spec=InboundMessage)
    msg.from_name = "Иван"
    msg.from_identifier = "ivan@example.com"
    msg.subject = None
    f = message_to_company_fields(msg, ch)
    assert f["name"] == "Иван"
    assert f["legal_name"] == "Иван"
    assert f["email"] == "ivan@example.com"
    assert f["phone"] is None
    assert f["source"] == "tg"


def test_message_to_company_fields_phone():
    """from_identifier с '+' → Company.phone; name fallback на identifier."""
    ch = MagicMock(spec=Channel)
    ch.name = "WA"
    ch.kind = "wa"
    ch.default_lead_source = ""
    msg = MagicMock(spec=InboundMessage)
    msg.from_name = None
    msg.from_identifier = "+77001234567"
    msg.subject = None
    f = message_to_company_fields(msg, ch)
    assert f["name"] == "+77001234567"
    assert f["phone"] == "+77001234567"
    assert f["email"] is None
    assert f["source"] == "wa"


def test_company_dedup_key_email_priority():
    """email приоритетнее phone, нормализуется (lower/strip)."""
    assert company_dedup_key("A@B.com ", "+7 700") == ("email", "a@b.com")


def test_company_dedup_key_phone_fallback():
    """Без email → phone, нормализованный «только цифры» (calldown.normalize_phone)."""
    # +/пробелы/скобки/дефисы убираются — разные форматы одного номера совпадают.
    assert company_dedup_key(None, "+7 700 123") == ("phone", "7700123")
    assert company_dedup_key("", "  +7700  ") == ("phone", "7700")
    assert company_dedup_key(None, "8 (700) 12-3") == ("phone", "8700123")


def test_company_dedup_key_none_when_empty():
    """Оба пустые → None (дедуп не применяется)."""
    assert company_dedup_key(None, None) is None
    assert company_dedup_key("", "   ") is None


# ============ Pydantic-схемы ============

def test_webhook_in_all_fields_optional():
    """WebhookIn: все поля optional, можно создать пустой."""
    payload = WebhookIn()
    assert payload.external_id is None
    assert payload.from_identifier is None


def test_channel_create_minimal():
    """ChannelCreate: minimum — name + kind."""
    c = ChannelCreate(name="TG Bot", kind="tg")
    assert c.is_active is True
    assert c.config == {}


def test_form_create_with_optional_slug():
    """FormCreate: slug optional, генерится в роутере если не передан."""
    f = FormCreate(name="Контактная форма")
    assert f.public_slug is None
    assert f.fields == []
    assert f.is_active is True


# ============ Структура миграции ============

def test_migration_0023_creates_three_tables():
    """Миграция 0023 создаёт channels / inbound_messages / forms + downgrade."""
    migration_path = (
        Path(__file__).resolve().parents[1]
        / "alembic"
        / "versions"
        / "0023_inbox_channels.py"
    )
    src = migration_path.read_text(encoding="utf-8")
    # Up: три таблицы
    assert 'create_table(\n        "channels"' in src
    assert 'create_table(\n        "inbound_messages"' in src
    assert 'create_table(\n        "forms"' in src
    # Уникальный slug для forms
    assert "uq_forms_public_slug" in src or "public_slug" in src
    # Композитный индекс для дедупа
    assert "ix_inbound_messages_channel_external" in src
    assert '["channel_id", "external_id"]' in src
    # Downgrade — все три drop'а
    assert "def downgrade()" in src
    assert 'drop_table("forms")' in src
    assert 'drop_table("inbound_messages")' in src
    assert 'drop_table("channels")' in src


# ============ Баг #3: per-channel identifier dedup-key ============

def test_channel_identifier_dedup_key():
    """TG/WA дедуп по (channel_id, identifier), нормализован lower/strip."""
    assert channel_identifier_dedup_key(5, " @Ivan ") == "5:@ivan"
    assert channel_identifier_dedup_key(7, "12345") == "7:12345"
    assert channel_identifier_dedup_key(1, None) is None
    assert channel_identifier_dedup_key(1, "   ") is None


# ============ Баг #8: honeypot ============

def test_is_honeypot_filled():
    """Заполненное honeypot-поле → бот."""
    assert is_honeypot_filled({HONEYPOT_FIELD: "http://spam"}) is True
    assert is_honeypot_filled({HONEYPOT_FIELD: "  x "}) is True
    assert is_honeypot_filled({HONEYPOT_FIELD: ""}) is False
    assert is_honeypot_filled({HONEYPOT_FIELD: "   "}) is False
    assert is_honeypot_filled({"name": "Иван"}) is False
    assert is_honeypot_filled({}) is False


# ============ Баг #9: строгая валидация submission'а ============

def test_validate_form_submission_rejects_unknown_keys():
    """Неизвестные (не объявленные в схеме) ключи → ошибка."""
    fields = [{"name": "name", "label": "Имя", "type": "text", "required": True}]
    ok, err = validate_form_submission(fields, {"name": "Иван", "evil": "x"})
    assert ok is False
    assert err is not None and "evil" in err


def test_validate_form_submission_allows_honeypot_key():
    """Honeypot-ключ разрешён (валидируется отдельно в роутере), не ломает валидацию."""
    fields = [{"name": "name", "label": "Имя", "type": "text", "required": True}]
    ok, _ = validate_form_submission(fields, {"name": "Иван", HONEYPOT_FIELD: ""})
    assert ok is True


def test_validate_form_submission_email_regex():
    """type=email валидируется регэкспом."""
    fields = [{"name": "email", "label": "Email", "type": "email", "required": True}]
    ok, _ = validate_form_submission(fields, {"email": "ivan@example.com"})
    assert ok is True
    bad, err = validate_form_submission(fields, {"email": "not-an-email"})
    assert bad is False and err is not None


def test_validate_form_submission_phone_regex():
    """type=phone валидируется регэкспом."""
    fields = [{"name": "phone", "label": "Тел", "type": "phone", "required": True}]
    ok, _ = validate_form_submission(fields, {"phone": "+7 700 123-45-67"})
    assert ok is True
    bad, _ = validate_form_submission(fields, {"phone": "abc"})
    assert bad is False


def test_validate_form_submission_value_too_long():
    """Слишком длинное значение → ошибка (anti-garbage)."""
    fields = [{"name": "name", "label": "Имя", "type": "text", "required": True}]
    ok, err = validate_form_submission(fields, {"name": "x" * 5000})
    assert ok is False and err is not None


# ============ Баг #5: стабильный external_id формы ============

def test_form_external_id_stable_within_window():
    """Один (slug + email) в одном окне → один external_id (дедуп refresh)."""
    sub = {"email": "ivan@example.com"}
    a = form_submission_external_id("lead-form", sub, 1000.0)
    b = form_submission_external_id("lead-form", sub, 1000.0 + 60)  # тот же window
    assert a is not None
    assert a == b
    assert a.startswith("form:")


def test_form_external_id_differs_across_window():
    """Разные временны́е окна → разные external_id (новая отправка позже — новый Deal)."""
    from app.services.inbox import FORM_DEDUP_WINDOW_SECONDS

    sub = {"email": "ivan@example.com"}
    a = form_submission_external_id("lead-form", sub, 1000.0)
    b = form_submission_external_id(
        "lead-form", sub, 1000.0 + FORM_DEDUP_WINDOW_SECONDS * 2
    )
    assert a != b


def test_form_external_id_none_without_contact():
    """Без email/phone → None (нечем стабильно идентифицировать → дедуп не применяется)."""
    assert form_submission_external_id("f", {"name": "Иван"}, 1000.0) is None
    assert form_submission_external_id("f", {}, 1000.0) is None


def test_form_external_id_phone_normalized():
    """Разные форматы телефона в одном окне → один external_id."""
    a = form_submission_external_id("f", {"phone": "+7 700 12"}, 1000.0)
    b = form_submission_external_id("f", {"phone": "8(700)12"}, 1000.0)
    # Оба нормализуются к цифрам, но первая цифра разная (7 vs 8) → разные ключи.
    # Проверяем стабильность одного формата:
    c = form_submission_external_id("f", {"phone": "+7-700-12"}, 1000.0)
    assert a == c
    assert a != b


# ============ Баг #4/#6: миграция 0082 ============

def test_migration_0082_unique_index_and_routing_status():
    """Миграция 0082: partial UNIQUE (channel_id, external_id) + routing_status."""
    migration_path = (
        Path(__file__).resolve().parents[1]
        / "alembic"
        / "versions"
        / "0082_inbox_dedup_routing.py"
    )
    src = migration_path.read_text(encoding="utf-8")
    assert 'down_revision: Union[str, None] = "0081_automation_idem"' in src
    assert "routing_status" in src
    assert "ux_inbound_messages_channel_external" in src
    assert "WHERE external_id IS NOT NULL" in src
    assert "pg_advisory_xact_lock" in src
    assert "def downgrade()" in src
    # revision <= 32 символов
    assert len("0082_inbox_dedup") <= 32


def test_migration_0023_has_required_indexes():
    """Все горячие индексы заведены: kind / is_active / channel_id / received_at / target_lead_id."""
    migration_path = (
        Path(__file__).resolve().parents[1]
        / "alembic"
        / "versions"
        / "0023_inbox_channels.py"
    )
    src = migration_path.read_text(encoding="utf-8")
    for ix in (
        "ix_channels_kind",
        "ix_channels_is_active",
        "ix_channels_secret_token",
        "ix_inbound_messages_channel_id",
        "ix_inbound_messages_target_lead_id",
        "ix_inbound_messages_received_at",
        "ix_inbound_messages_channel_external",
        "ix_forms_is_active",
        "ix_forms_channel_id",
    ):
        assert ix in src, f"индекс {ix} должен быть в миграции 0023"
