"""Inbox-сервис: авто-создание из входящего сообщения.

DEALS 2.0 (Ф1c): входящий поток (webhook каналов, public form submit) создаёт
**Company + Deal** в sales-воронке (этап code='new'), а не Lead. Лид = Deal на
Company в этапе «Новые лиды». См. auto_create_deal_from_message.

`auto_create_lead_from_message` оставлен deprecated-обёрткой над новой логикой
(чтобы существующие callers продолжали работать, но писали уже в Deal). Lead-
модель/таблица НЕ удалена (deprecate).

Логика edge-cases:
- Дедуп webhook-доставок по (channel_id, external_id): если уже есть
  InboundMessage с тем же external_id, привязанный к Deal — новый Deal не
  создаётся (сообщение линкуется к существующей сделке).
- Дедуп компании: если по email/phone уже есть Company — переиспользуем её
  (новая сделка), не плодим дубль-компанию.
- Выбор pipeline/stage: приоритет — channel.default_pipeline_id/default_stage_id;
  fallback — sales-воронка «Продажи» + этап code='new'.
- source: приоритет — channel.default_lead_source; fallback — LEAD_SOURCE_MAP по
  channel.kind.
- Email vs phone: from_identifier → Company.email если содержит '@',
  Company.phone если начинается с '+'.
- Name/title: from_name > from_identifier > subject > 'Лид из <channel.name>'.
"""
from __future__ import annotations

import hashlib
import logging
import re
import secrets
from typing import Any

from sqlalchemy import func as sa_func
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.models import (
    Channel,
    Company,
    Counterparty,
    Deal,
    InboundMessage,
    Pipeline,
    PipelineStage,
)
from app.services.calldown import normalize_phone

_logger = logging.getLogger(__name__)

# sales-воронка: код этапа «Новые лиды» (лид = Deal в этом этапе)
SALES_STAGE_CODE_NEW = "new"

# Допустимые виды каналов
CHANNEL_KINDS: tuple[str, ...] = ("tg", "wa", "email", "web_form", "api")

# channel.kind → дефолтный Lead.source (используется, если channel.default_lead_source
# пуст или не подходит). Соответствует whitelist'у в routers/leads.py:_ALLOWED_SOURCES.
LEAD_SOURCE_MAP: dict[str, str] = {
    "tg": "tg",
    "wa": "wa",
    "email": "email",
    "web_form": "form",
    "api": "api",
}


def generate_channel_token() -> str:
    """32 байта URL-safe → ~43 символа (≤ 64 по схеме channels.secret_token)."""
    return secrets.token_urlsafe(32)


def generate_form_slug() -> str:
    """8 байт URL-safe → ~11 символов (≤ 64 по схеме forms.public_slug)."""
    return secrets.token_urlsafe(8)


def derive_lead_source(channel: Channel) -> str:
    """Источник для Lead на основе канала: channel.default_lead_source > kind-map."""
    if channel.default_lead_source:
        return channel.default_lead_source
    return LEAD_SOURCE_MAP.get(channel.kind, "api")


def derive_lead_name(msg: InboundMessage, channel: Channel) -> str:
    """Имя Lead: from_name > from_identifier > subject > 'Lead from <channel.name>'."""
    raw = msg.from_name or msg.from_identifier or msg.subject or f"Lead from {channel.name}"
    return raw[:255]


def derive_contact_email(from_identifier: str | None) -> str | None:
    """Если from_identifier содержит '@' — это email; иначе None."""
    if from_identifier and "@" in from_identifier:
        return from_identifier
    return None


def derive_contact_phone(from_identifier: str | None) -> str | None:
    """Если from_identifier начинается с '+' — это телефон; иначе None."""
    if from_identifier and from_identifier.startswith("+"):
        return from_identifier
    return None


# ============ DEALS 2.0 (Ф1c): message → Company / Deal mapping ============


def message_to_company_fields(
    msg: InboundMessage, channel: Channel
) -> dict[str, Any]:
    """Входящее сообщение → поля для создания Company (pure-function).

    name ← from_name > from_identifier > subject > 'Лид из <channel.name>'.
    email/phone разбираются из from_identifier. source ← derive_lead_source.
    legal_name дублирует name (NOT NULL у Company.legal_name). Реквизиты
    Counterparty-зеркала проставит company_to_counterparty_fields (дефолт KZ).

    >>> from unittest.mock import MagicMock
    >>> ch = MagicMock(); ch.name='TG'; ch.kind='tg'; ch.default_lead_source=''
    >>> m = MagicMock(); m.from_name='Иван'; m.from_identifier='a@b.c'; m.subject=None
    >>> f = message_to_company_fields(m, ch)
    >>> f['name'], f['email'], f['phone'], f['source']
    ('Иван', 'a@b.c', None, 'tg')
    """
    name = derive_lead_name(msg, channel)
    return {
        "name": name,
        "legal_name": name,
        "email": derive_contact_email(msg.from_identifier),
        "phone": derive_contact_phone(msg.from_identifier),
        "source": derive_lead_source(channel),
    }


def company_dedup_key(email: str | None, phone: str | None) -> tuple[str, str] | None:
    """Нормализованный ключ дедупа компании: ('email', e) | ('phone', p) | None.

    Приоритет email над phone. Email — lower/strip. Phone нормализуется через
    calldown.normalize_phone («только цифры», без +/скобок/дефисов) — это даёт
    совпадение даже если один лид пришёл как «+7 700…», другой как «8 (700)…».
    None если оба пустые (тогда дедуп компании по контакту не применяется).

    >>> company_dedup_key('A@B.com ', '+7 700')
    ('email', 'a@b.com')
    >>> company_dedup_key(None, '+7 700 123')
    ('phone', '7700123')
    >>> company_dedup_key('', '   ')
    """
    if email and email.strip():
        return ("email", email.strip().lower())
    norm = normalize_phone(phone)
    if norm:
        return ("phone", norm)
    return None


def channel_identifier_dedup_key(
    channel_id: int, from_identifier: str | None
) -> str | None:
    """Ключ дедупа TG/WA-собеседника: '<channel_id>:<identifier>' | None.

    Для каналов tg/wa один и тот же chat_id/phone = один и тот же собеседник;
    дедупим по паре (канал, идентификатор), а НЕ по глобальному phone (иначе
    каждое TG-сообщение плодило бы Company, т.к. identifier не в +формате и не
    совпадал с Company.phone-дедупом). identifier нормализуем: lower/strip.
    """
    if not from_identifier or not from_identifier.strip():
        return None
    return f"{channel_id}:{from_identifier.strip().lower()}"


# kind'ы, для которых дедуп идёт по (channel_id, from_identifier), а не по
# email/phone (TG/WA: chat_id/username не email и не глобальный phone).
_PER_CHANNEL_DEDUP_KINDS: frozenset[str] = frozenset({"tg", "wa"})


async def find_default_sales_pipeline(session: AsyncSession) -> Pipeline | None:
    """Sales-воронка «Продажи» (kind='sales', приоритет name='Продажи')."""
    result = await session.execute(
        select(Pipeline)
        .where(Pipeline.kind == "sales")
        .order_by((Pipeline.name == "Продажи").desc(), Pipeline.id)
        .limit(1)
    )
    return result.scalar_one_or_none()


async def find_stage_by_code(
    session: AsyncSession, pipeline_id: int, code: str
) -> PipelineStage | None:
    """Этап воронки по code (например 'new'). None если нет."""
    result = await session.execute(
        select(PipelineStage)
        .where(
            PipelineStage.pipeline_id == pipeline_id,
            PipelineStage.code == code,
        )
        .limit(1)
    )
    return result.scalar_one_or_none()


# Regexp-выражение для нормализации Company.phone на стороне БД (убрать всё,
# кроме цифр) — чтобы phone-дедуп сравнивал «только цифры» с обеих сторон, даже
# если в БД телефоны в разных форматах (+7…, 8(700)…). Postgres regexp_replace.
_DIGITS_ONLY_PG = r"\D"


async def find_existing_company_by_contact(
    session: AsyncSession, email: str | None, phone: str | None
) -> Company | None:
    """Найти Company по email (приоритет) или phone — для дедупа.

    Case-insensitive по email. Phone сравнивается по «только цифры» с обеих
    сторон (regexp_replace в БД), чтобы +7…/8(700)… совпадали. Возвращает самую
    раннюю совпавшую (минимальный id) — детерминизм при гонке.
    """
    key = company_dedup_key(email, phone)
    if key is None:
        return None
    kind, value = key
    if kind == "email":
        stmt = (
            select(Company)
            .where(sa_func.lower(Company.email) == value)
            .order_by(Company.id)
            .limit(1)
        )
    else:
        # value уже нормализован (только цифры). Нормализуем и Company.phone в БД.
        dialect = session.bind.dialect.name if session.bind is not None else ""
        if dialect == "postgresql":
            normalized_col = sa_func.regexp_replace(
                Company.phone, _DIGITS_ONLY_PG, "", "g"
            )
            stmt = (
                select(Company)
                .where(Company.phone.is_not(None), normalized_col == value)
                .order_by(Company.id)
                .limit(1)
            )
        else:
            # SQLite (тесты) — regexp_replace отсутствует; точное сравнение по
            # уже-нормализованному значению (тесты не полагаются на разные
            # форматы в БД).
            stmt = (
                select(Company)
                .where(Company.phone == value)
                .order_by(Company.id)
                .limit(1)
            )
    return (await session.execute(stmt)).scalar_one_or_none()


async def find_existing_company_by_channel_identifier(
    session: AsyncSession, channel_id: int, from_identifier: str
) -> Company | None:
    """Найти Company через предыдущее InboundMessage того же (channel, identifier).

    Для TG/WA: дедуп собеседника по паре (channel_id, from_identifier), а не по
    глобальному phone. Берём самое раннее сообщение этого собеседника, у которого
    уже есть target_deal_id, и через Deal → company_id. Так повторные сообщения
    одного TG-чата прилипают к уже созданной Company (новая сделка на ней), не
    плодя дубль-Company.
    """
    ident = from_identifier.strip()
    if not ident:
        return None
    # Самое раннее сообщение этого собеседника с привязанным Deal.
    stmt = (
        select(Deal.company_id)
        .join(InboundMessage, InboundMessage.target_deal_id == Deal.id)
        .where(
            InboundMessage.channel_id == channel_id,
            sa_func.lower(InboundMessage.from_identifier) == ident.lower(),
            Deal.company_id.is_not(None),
        )
        .order_by(InboundMessage.id)
        .limit(1)
    )
    company_id = (await session.execute(stmt)).scalar_one_or_none()
    if company_id is None:
        return None
    return (
        await session.execute(select(Company).where(Company.id == company_id))
    ).scalar_one_or_none()


async def find_default_lead_pipeline(session: AsyncSession) -> Pipeline | None:
    """Pipeline.kind='lead' (первый по sort_order)."""
    result = await session.execute(
        select(Pipeline)
        .where(Pipeline.kind == "lead")
        .order_by(Pipeline.sort_order)
        .limit(1)
    )
    return result.scalar_one_or_none()


async def find_first_stage(session: AsyncSession, pipeline_id: int) -> PipelineStage | None:
    """Первый активный этап воронки по sort_order."""
    result = await session.execute(
        select(PipelineStage)
        .where(PipelineStage.pipeline_id == pipeline_id, PipelineStage.is_active.is_(True))
        .order_by(PipelineStage.sort_order)
        .limit(1)
    )
    return result.scalar_one_or_none()


async def find_existing_lead_for_message(
    session: AsyncSession, channel_id: int, external_id: str
) -> int | None:
    """Найти Lead, уже созданный из сообщения с тем же external_id (для дедупа).

    Возвращает Lead.id если предыдущее сообщение с тем же external_id уже привязано
    к Lead, иначе None. Защита от повторных доставок webhook.
    """
    result = await session.execute(
        select(InboundMessage.target_lead_id)
        .where(
            InboundMessage.channel_id == channel_id,
            InboundMessage.external_id == external_id,
            InboundMessage.target_lead_id.is_not(None),
        )
        .limit(1)
    )
    row = result.scalar_one_or_none()
    return int(row) if row is not None else None


async def find_existing_deal_for_message(
    session: AsyncSession, channel_id: int, external_id: str
) -> int | None:
    """Найти Deal, уже созданный из сообщения с тем же external_id (дедуп webhook).

    Возвращает Deal.id если предыдущее сообщение с тем же external_id уже
    привязано к Deal, иначе None. Защита от повторных доставок webhook'а.
    """
    result = await session.execute(
        select(InboundMessage.target_deal_id)
        .where(
            InboundMessage.channel_id == channel_id,
            InboundMessage.external_id == external_id,
            InboundMessage.target_deal_id.is_not(None),
        )
        .limit(1)
    )
    row = result.scalar_one_or_none()
    return int(row) if row is not None else None


async def auto_create_deal_from_message(
    session: AsyncSession,
    channel: Channel,
    msg: InboundMessage,
) -> Deal | None:
    """DEALS 2.0 (Ф1c): авто-создать Company + Deal для входящего сообщения.

    Возвращает созданный (или переиспользованный) Deal, либо None.

    Логика:
    - Канал inactive → None.
    - Дедуп webhook: если external_id уже привязан к Deal — линкуем сообщение к
      этой сделке (target_deal_id), target_deal_created=False, новый Deal НЕ
      создаётся.
    - Дедуп компании: если по email/phone уже есть Company — переиспользуем её
      (новая сделка на ней), не плодим дубль.
    - Pipeline/stage: channel.default_pipeline_id/default_stage_id если заданы;
      иначе sales-воронка «Продажи» + этап code='new'.
    - Owner: channel.default_owner_id (round-robin перекроет через on_create
      автоматизацию change_owner).
    - Создаём Counterparty-зеркало (faithful mirror) только для новой компании.

    НЕ коммитит сессию (commit делает caller — router). Эмитит webhook-события
    (deal.created [+ counterparty.created для новой компании]) через dispatch_event
    БЕЗ commit — caller-router коммитит сам.
    """
    if not channel.is_active:
        return None

    # ---- Дедуп webhook-доставки (read-then-write; БД-UNIQUE — финальный гард) ----
    if msg.external_id:
        existing_deal_id = await find_existing_deal_for_message(
            session, channel.id, msg.external_id
        )
        if existing_deal_id is not None:
            msg.target_deal_id = existing_deal_id
            msg.target_deal_created = False
            msg.routing_status = "dedup"
            return None

    # ---- Pipeline ----
    pipeline_id = channel.default_pipeline_id
    if pipeline_id is None:
        sales_pipe = await find_default_sales_pipeline(session)
        if sales_pipe is None:
            # Лид НЕ должен теряться молча: помечаем failed, чтобы Inbox UI
            # показал «не разобрано», и логируем error (баг #6 код-аудита).
            msg.routing_status = "failed"
            _logger.error(
                "inbox routing failed: нет sales-воронки для канала id=%s kind=%s "
                "(сообщение id=%s останется неразобранным)",
                channel.id, channel.kind, getattr(msg, "id", None),
            )
            return None
        pipeline_id = sales_pipe.id

    # ---- Stage ----
    stage_id = channel.default_stage_id
    if stage_id is None:
        stage = await find_stage_by_code(session, pipeline_id, SALES_STAGE_CODE_NEW)
        if stage is None:
            # fallback: первый активный этап воронки
            stage = await find_first_stage(session, pipeline_id)
        if stage is None:
            msg.routing_status = "failed"
            _logger.error(
                "inbox routing failed: нет этапа 'new'/активного этапа в воронке "
                "id=%s (канал id=%s, сообщение id=%s)",
                pipeline_id, channel.id, getattr(msg, "id", None),
            )
            return None
        stage_id = stage.id

    owner_id = channel.default_owner_id
    cfields = message_to_company_fields(msg, channel)

    # ---- Company: дедуп или создание ----
    # TG/WA дедупим по (channel_id, from_identifier) — chat_id/username не email
    # и не глобальный phone (баг #3a). Прочие каналы (email/web_form/api) — по
    # контакту (email/phone). Owner существующей компании НЕ перетираем (баг #3c).
    company: Company | None = None
    counterparty_created = False
    if channel.kind in _PER_CHANNEL_DEDUP_KINDS and msg.from_identifier:
        company = await find_existing_company_by_channel_identifier(
            session, channel.id, msg.from_identifier
        )
    if company is None:
        company = await find_existing_company_by_contact(
            session, cfields["email"], cfields["phone"]
        )
    if company is None:
        company = Company(
            legal_name=cfields["legal_name"],
            name=cfields["name"],
            email=cfields["email"],
            phone=cfields["phone"],
            source=cfields["source"],
            owner_user_id=owner_id,
            tags=[],
            extra_fields={},
        )
        session.add(company)
        await session.flush()  # нужен company.id

        # Counterparty-зеркало (faithful mirror, как в Contacts 2.0 / 0073).
        from app.services.contacts_v2 import company_to_counterparty_fields

        mirror = company_to_counterparty_fields(
            {
                "name": cfields["name"],
                "legal_name": cfields["legal_name"],
                "email": cfields["email"],
                "phone": cfields["phone"],
                "owner_user_id": owner_id,
            }
        )
        cp = Counterparty(
            name=mirror["name"],
            country_code=mirror["country_code"],
            phone=mirror.get("phone"),
            email=mirror.get("email"),
            owner_user_id=mirror.get("owner_user_id"),
            extra_fields={},
        )
        session.add(cp)
        await session.flush()  # нужен cp.id
        company.counterparty_id = cp.id
        counterparty_created = True
    # NB: для существующей компании owner_user_id НЕ трогаем — не «угоняем»
    # чужую карточку у её владельца (баг #3c). Создаём только новый Deal на ней.

    # ---- Deal ----
    title = derive_lead_name(msg, channel)
    deal = Deal(
        pipeline_id=pipeline_id,
        stage_id=stage_id,
        company_id=company.id,
        counterparty_id=company.counterparty_id,
        title=title,
        owner_user_id=owner_id,
        department_id=company.department_id,
    )
    session.add(deal)
    # Дедуп webhook-доставки на уровне БД (UNIQUE channel_id, external_id) ловится
    # на INSERT самого InboundMessage в caller-роутере (msg уже сфлашен до вызова
    # этого сервиса). Здесь flush'им только Deal — конфликта дедупа быть не может.
    await session.flush()  # нужен deal.id

    msg.target_deal_id = deal.id
    msg.target_deal_created = True
    msg.routing_status = "routed"

    # ---- Outbound webhook-события (баг #1: входящий поток не эмитил события) ----
    # dispatch_event БЕЗ commit (caller-router коммитит). Сначала counterparty.created
    # (для нового зеркала), затем deal.created. catch-all внутри dispatch_event.
    from app.services.webhook_dispatcher import (
        counterparty_to_payload,
        deal_to_payload,
        dispatch_event,
    )

    if counterparty_created and company.counterparty_id is not None:
        cp_obj = (
            await session.execute(
                select(Counterparty).where(Counterparty.id == company.counterparty_id)
            )
        ).scalar_one_or_none()
        if cp_obj is not None:
            await dispatch_event(
                session,
                "counterparty.created",
                "counterparty",
                cp_obj.id,
                counterparty_to_payload(cp_obj),
            )
    await dispatch_event(
        session, "deal.created", "deal", deal.id, deal_to_payload(deal)
    )

    # on_create автоматизации (Эпик 4.1): change_owner round_robin для
    # распределения новых сделок перекроет channel.default_owner_id.
    # Catch на всё: сломанная автоматизация НЕ роняет приём webhook'а/формы.
    try:
        from app.services.automation_executor import run_on_create_automations

        await run_on_create_automations(session, "deal", deal.id)
    except Exception:  # noqa: BLE001
        pass

    return deal


async def auto_create_lead_from_message(
    session: AsyncSession,
    channel: Channel,
    msg: InboundMessage,
) -> Deal | None:
    """DEPRECATED-обёртка над auto_create_deal_from_message (DEALS 2.0 Ф1c).

    Имя сохранено для обратной совместимости существующих callers (inbox webhook,
    public form submit). Теперь входящий поток создаёт Company + Deal вместо Lead;
    msg.target_deal_id / target_deal_created проставляются новой логикой.

    Возвращает Deal (а не Lead) — caller'ы используют только msg.target_*_created
    флаги для ответа, так что тип возврата для них не критичен.

    НЕ коммитит сессию (commit делает caller — router).
    """
    return await auto_create_deal_from_message(session, channel, msg)


# Имя honeypot-поля в публичных формах: скрытое поле, которое настоящий
# пользователь не видит и не заполняет. Если заполнено — это бот (баг #8).
HONEYPOT_FIELD = "website"

# Лимит размера одного значения поля (символов) — защита от мусора/огромных
# payload'ов в Company/raw_payload (баг #9).
MAX_FIELD_VALUE_LEN = 2000
# Лимит числа полей в submission'е.
MAX_SUBMISSION_FIELDS = 50

# Регэкспы для типов email/phone (простые, anti-garbage, не RFC-строгие).
_EMAIL_RE = re.compile(r"^[^@\s]+@[^@\s]+\.[^@\s]+$")
_PHONE_RE = re.compile(r"^[+\d][\d\s()\-]{4,30}$")


def is_honeypot_filled(submitted: dict[str, Any]) -> bool:
    """True, если honeypot-поле заполнено — submission от бота, надо тихо дропнуть."""
    if not isinstance(submitted, dict):
        return False
    val = submitted.get(HONEYPOT_FIELD)
    return isinstance(val, str) and bool(val.strip())


def validate_form_submission(
    fields_schema: list[dict[str, Any]], submitted: dict[str, Any]
) -> tuple[bool, str | None]:
    """Строгая валидация submission'а по объявленным form.fields (баг #9).

    Правила:
    - body — JSON-объект, не более MAX_SUBMISSION_FIELDS ключей.
    - Разрешены только объявленные в схеме ключи + honeypot (неизвестные →
      ошибка, мусор не попадает в Company/raw_payload).
    - required-поля присутствуют и не пусты.
    - type='email'/'phone' валидируются regex'ом; длина значения ≤ MAX_FIELD_VALUE_LEN.

    Возвращает (ok, error_message).
    """
    if not isinstance(submitted, dict):
        return False, "Тело запроса должно быть JSON-объектом"
    if len(submitted) > MAX_SUBMISSION_FIELDS:
        return False, "Слишком много полей в форме"

    declared: dict[str, dict[str, Any]] = {}
    for field in fields_schema:
        if isinstance(field, dict) and field.get("name"):
            declared[str(field["name"])] = field

    # Неизвестные ключи (кроме honeypot) — отбрасываем как ошибку.
    for key in submitted:
        if key == HONEYPOT_FIELD:
            continue
        if key not in declared:
            return False, f"Неизвестное поле '{key}'"

    for name, field in declared.items():
        value = submitted.get(name)
        is_empty = value is None or (isinstance(value, str) and not value.strip())
        if field.get("required") and is_empty:
            label = field.get("label") or name
            return False, f"Поле '{label}' обязательно"
        if is_empty:
            continue
        if isinstance(value, str) and len(value) > MAX_FIELD_VALUE_LEN:
            label = field.get("label") or name
            return False, f"Поле '{label}' слишком длинное"
        ftype = field.get("type")
        if ftype == "email" and isinstance(value, str):
            if not _EMAIL_RE.match(value.strip()):
                label = field.get("label") or name
                return False, f"Поле '{label}': некорректный email"
        elif ftype == "phone" and isinstance(value, str):
            if not _PHONE_RE.match(value.strip()):
                label = field.get("label") or name
                return False, f"Поле '{label}': некорректный телефон"
    return True, None


# Окно дедупа повторной отправки одной и той же формы (двойной клик / refresh).
# Внутри окна один и тот же (slug + email|phone) даёт один external_id → один Deal.
FORM_DEDUP_WINDOW_SECONDS = 6 * 60 * 60  # 6 часов


def form_submission_external_id(
    slug: str, submitted: dict[str, Any], now_ts: float
) -> str | None:
    """Стабильный external_id для form submission'а (баг #5: дедуп повторной отправки).

    Ключ = hash(slug + нормализованный email|phone + временно́е окно). Внутри окна
    FORM_DEDUP_WINDOW_SECONDS повторная отправка той же формы тем же контактом даёт
    тот же external_id → дедуп в auto_create_deal_from_message не плодит второй Deal.

    None если нет ни email, ни phone (нечем стабильно идентифицировать отправителя —
    тогда дедуп формы не применяется, как и раньше).
    """
    submitted = submitted or {}
    email = submitted.get("email")
    phone = submitted.get("phone")
    key = company_dedup_key(
        email if isinstance(email, str) else None,
        phone if isinstance(phone, str) else None,
    )
    if key is None:
        return None
    _kind, value = key
    window = int(now_ts // FORM_DEDUP_WINDOW_SECONDS)
    raw = f"{slug}|{value}|{window}".encode()
    digest = hashlib.sha256(raw).hexdigest()[:32]
    return f"form:{digest}"


def build_message_from_form_submission(
    form_name: str, submitted: dict[str, Any]
) -> dict[str, Any]:
    """Из dict submission'а вытащить стандартные поля сообщения (name/email/phone/body).

    Возвращает kwargs для InboundMessage:
    - from_name: значение поля 'name' (если есть)
    - from_identifier: email > phone
    - subject: f"Форма: {form_name}"
    - body: сериализованный текст всех полей формы
    """
    submitted = submitted or {}
    name = submitted.get("name") or submitted.get("full_name")
    email = submitted.get("email")
    phone = submitted.get("phone")

    # from_identifier: email > phone > None
    identifier: str | None = None
    if isinstance(email, str) and "@" in email:
        identifier = email
    elif isinstance(phone, str) and phone.strip():
        identifier = phone.strip()

    # body: сериализуем все поля для notes (`key: value` построчно), кроме
    # honeypot-поля (служебное, не показываем в карточке).
    body_lines: list[str] = []
    for key, value in submitted.items():
        if key == HONEYPOT_FIELD:
            continue
        if value is None or value == "":
            continue
        body_lines.append(f"{key}: {value}")
    body = "\n".join(body_lines) if body_lines else None

    return {
        "from_name": (str(name).strip()[:255] if name else None),
        "from_identifier": identifier[:255] if identifier else None,
        "subject": f"Форма: {form_name}"[:255],
        "body": body,
    }
