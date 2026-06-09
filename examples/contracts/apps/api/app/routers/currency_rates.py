"""Epic 10.5 — Currency rates endpoints.

Управление курсами валют:
- GET /api/currency-rates — список курсов (admin)
- POST /api/currency-rates — ручное добавление курса (admin)
- POST /api/admin/currency-rates/refresh — форс-обновление с API (admin)
- POST /api/admin/currency-rates/seed-pairs — заглушки + опц. fetch (admin)
- GET /api/currency-rates/convert — конвертер сумм
- GET /api/currency-rates/supported — список поддерживаемых валют + флаг API
"""
from __future__ import annotations

from datetime import date
from datetime import date as date_cls
from decimal import Decimal
from typing import Annotated, Any

from fastapi import APIRouter, Depends, HTTPException, Query, status
from pydantic import BaseModel, ConfigDict, Field
from sqlalchemy import func as sa_func
from sqlalchemy.dialects.postgresql import insert as pg_insert
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.config import get_settings
from app.db import get_session
from app.deps import AdminUser, CurrentUser
from app.models import CurrencyRate, FinSettings, User
from app.services.currency import (
    SUPPORTED_CURRENCIES,
    convert_amount,
    get_rate,
    reason_message,
    update_currency_rates,
)
from app.services.finance import audit_fin
from app.services.finance import base_currency as base_currency_svc
from app.services.finance.access import fin_can
from app.services.finance.fx import FxRateMissing

router = APIRouter(prefix="/currency-rates", tags=["currency-rates"])
admin_router = APIRouter(prefix="/admin/currency-rates", tags=["currency-rates-admin"])


# ============ Schemas ============

class CurrencyRateOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    from_currency: str
    to_currency: str
    rate: Decimal
    rate_date: date
    source: str | None


class CurrencyRateIn(BaseModel):
    from_currency: str = Field(..., max_length=8)
    to_currency: str = Field(..., max_length=8)
    rate: Decimal = Field(..., gt=0)
    rate_date: date
    source: str | None = "manual"


class ConvertResponse(BaseModel):
    from_currency: str
    to_currency: str
    from_amount: Decimal
    to_amount: Decimal
    rate: Decimal
    rate_date: date


class RefreshResponse(BaseModel):
    updated_pairs: int
    message: str
    #: Машинная причина для UI (ok | no_api_key | api_error | empty_response).
    reason: str
    ok: bool


# ============ Endpoints ============

@router.get("", response_model=list[CurrencyRateOut])
async def list_currency_rates(
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    from_currency: str | None = Query(None),
    to_currency: str | None = Query(None),
    on_date: date | None = Query(None, alias="date"),
    limit: int = Query(100, le=500),
) -> list[CurrencyRateOut]:
    """Список курсов валют. Доступен всем аутентифицированным."""
    q = select(CurrencyRate).order_by(
        CurrencyRate.rate_date.desc(),
        CurrencyRate.from_currency,
        CurrencyRate.to_currency,
    )
    if from_currency:
        q = q.where(CurrencyRate.from_currency == from_currency.upper())
    if to_currency:
        q = q.where(CurrencyRate.to_currency == to_currency.upper())
    if on_date:
        q = q.where(CurrencyRate.rate_date <= on_date)
    q = q.limit(limit)
    rows = list((await session.execute(q)).scalars().all())
    return rows


async def _create_currency_rate_impl(
    body: CurrencyRateIn,
    session: AsyncSession,
) -> CurrencyRate:
    """Общий imp для ручного create/upsert. Используется в POST /currency-rates
    и POST /admin/currency-rates (alias для совместимости с UI ManualRateModal)."""
    from_cur = body.from_currency.upper()
    to_cur = body.to_currency.upper()

    if from_cur not in SUPPORTED_CURRENCIES or to_cur not in SUPPORTED_CURRENCIES:
        raise HTTPException(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            detail=f"Unsupported currency. Supported: {SUPPORTED_CURRENCIES}",
        )
    if from_cur == to_cur:
        raise HTTPException(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            detail="from_currency and to_currency must differ",
        )

    # Upsert
    existing = (
        await session.execute(
            select(CurrencyRate).where(
                CurrencyRate.from_currency == from_cur,
                CurrencyRate.to_currency == to_cur,
                CurrencyRate.rate_date == body.rate_date,
            )
        )
    ).scalar_one_or_none()

    if existing:
        existing.rate = body.rate
        existing.source = body.source or "manual"
        await session.commit()
        await session.refresh(existing)
        return existing

    row = CurrencyRate(
        from_currency=from_cur,
        to_currency=to_cur,
        rate=body.rate,
        rate_date=body.rate_date,
        source=body.source or "manual",
    )
    session.add(row)
    await session.commit()
    await session.refresh(row)
    return row


@router.post("", response_model=CurrencyRateOut, status_code=status.HTTP_201_CREATED)
async def create_currency_rate(
    body: CurrencyRateIn,
    admin: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
) -> CurrencyRate:
    """Ручное добавление / переопределение курса (admin)."""
    return await _create_currency_rate_impl(body, session)


@admin_router.post("", response_model=CurrencyRateOut, status_code=status.HTTP_201_CREATED)
async def create_currency_rate_admin_alias(
    body: CurrencyRateIn,
    admin: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
) -> CurrencyRate:
    """Alias на POST /currency-rates под префиксом /admin (UI ManualRateModal шлёт сюда)."""
    return await _create_currency_rate_impl(body, session)


# ============ Edit / Delete (admin) ============

class CurrencyRatePatch(BaseModel):
    """Partial-патч курса. Все поля опциональные."""
    from_currency: str | None = Field(default=None, max_length=8)
    to_currency: str | None = Field(default=None, max_length=8)
    rate: Decimal | None = Field(default=None, gt=0)
    rate_date: date | None = None
    source: str | None = None


@admin_router.patch("/{rate_id}", response_model=CurrencyRateOut)
async def update_currency_rate(
    rate_id: int,
    body: CurrencyRatePatch,
    admin: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
) -> CurrencyRate:
    """Редактирование существующего курса по id (admin)."""
    row = (
        await session.execute(
            select(CurrencyRate).where(CurrencyRate.id == rate_id)
        )
    ).scalar_one_or_none()
    if row is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Курс не найден")

    data = body.model_dump(exclude_unset=True)

    if "from_currency" in data and data["from_currency"] is not None:
        fc = data["from_currency"].upper()
        if fc not in SUPPORTED_CURRENCIES:
            raise HTTPException(
                status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
                detail=f"Unsupported from_currency: {fc}",
            )
        row.from_currency = fc
    if "to_currency" in data and data["to_currency"] is not None:
        tc = data["to_currency"].upper()
        if tc not in SUPPORTED_CURRENCIES:
            raise HTTPException(
                status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
                detail=f"Unsupported to_currency: {tc}",
            )
        row.to_currency = tc
    if row.from_currency == row.to_currency:
        raise HTTPException(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            detail="from_currency and to_currency must differ",
        )
    if "rate" in data and data["rate"] is not None:
        row.rate = data["rate"]
    if "rate_date" in data and data["rate_date"] is not None:
        row.rate_date = data["rate_date"]
    if "source" in data:
        row.source = data["source"]

    # Конфликт unique(from, to, date) с другой записью → 409
    conflict = (
        await session.execute(
            select(CurrencyRate).where(
                CurrencyRate.from_currency == row.from_currency,
                CurrencyRate.to_currency == row.to_currency,
                CurrencyRate.rate_date == row.rate_date,
                CurrencyRate.id != row.id,
            )
        )
    ).scalar_one_or_none()
    if conflict is not None:
        raise HTTPException(
            status_code=status.HTTP_409_CONFLICT,
            detail=(
                f"Курс {row.from_currency} → {row.to_currency} на дату "
                f"{row.rate_date.isoformat()} уже задан (id={conflict.id})."
            ),
        )

    await session.commit()
    await session.refresh(row)
    return row


@admin_router.delete("/{rate_id}", status_code=status.HTTP_204_NO_CONTENT)
async def delete_currency_rate(
    rate_id: int,
    admin: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
) -> None:
    """Удаление курса по id (admin)."""
    row = (
        await session.execute(
            select(CurrencyRate).where(CurrencyRate.id == rate_id)
        )
    ).scalar_one_or_none()
    if row is None:
        raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail="Курс не найден")
    await session.delete(row)
    await session.commit()


@router.get("/convert", response_model=ConvertResponse)
async def convert_currency(
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    amount: Decimal = Query(..., gt=0),
    from_currency: str = Query(..., alias="from"),
    to_currency: str = Query(..., alias="to"),
    on_date: date | None = Query(None, alias="date"),
) -> ConvertResponse:
    """Конвертировать сумму из одной валюты в другую."""
    if on_date is None:
        from datetime import date as date_cls
        on_date = date_cls.today()

    from_cur = from_currency.upper()
    to_cur = to_currency.upper()

    rate = await get_rate(session, from_cur, to_cur, on_date)
    converted = convert_amount(amount, from_cur, to_cur, rate)

    return ConvertResponse(
        from_currency=from_cur,
        to_currency=to_cur,
        from_amount=amount,
        to_amount=converted,
        rate=rate,
        rate_date=on_date,
    )


@admin_router.post("/refresh", response_model=RefreshResponse)
async def refresh_currency_rates(
    admin: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
) -> RefreshResponse:
    """Форс-обновление курсов с exchangerate-api.com (admin).

    Возвращает 200 с ЯВНОЙ причиной, если курсы не загрузились (нет ключа /
    ошибка API / пустой ответ) — вместо тихого нуля. UI показывает RU-сообщение,
    чтобы пользователь понимал ПОЧЕМУ ничего не загрузилось.
    """
    res = await update_currency_rates(session)
    return RefreshResponse(
        updated_pairs=res.updated,
        message=reason_message(res.reason),
        reason=res.reason,
        ok=res.ok,
    )


# ============ Base currency (делегирует в ЕДИНЫЙ источник FinSettings) ============
#
# Базовая валюта группы живёт в FinSettings.base_currency (finance — единственный
# источник истины). Эти эндпоинты — тонкая обёртка на /admin/currency-rates, чтобы
# страница курсов могла прочитать/сменить базу БЕЗ дублирования логики: SET делегирует
# в base_currency_svc.recompute_base_amounts (та же служба, что и
# POST /finance/settings/base-currency). На странице finance дубликат-селектор убирается.

class BaseCurrencyOut(BaseModel):
    """Текущая базовая валюта группы (из FinSettings, дефолт RUB)."""

    base_currency: str
    base_currency_changed_at: Any | None = None


class BaseCurrencyChangeRequest(BaseModel):
    target_currency: str = Field(..., min_length=3, max_length=8)


class BaseCurrencyChangeResponse(BaseModel):
    """Итог смены базы + пересчёта amount_in_base (открытые периоды, H4)."""

    base_currency: str
    job_id: int
    status: str
    total_lines: int
    processed_lines: int
    skipped_closed: int
    missing_rate_lines: int
    message: str


async def _require_cap(
    session: AsyncSession, user: User, capability: str
) -> None:
    """Гейт по матрице прав модуля «Финансы» (admin/cfo для base-currency)."""
    if not await fin_can(session, user, capability, legal_entity_id=None):
        raise HTTPException(
            status_code=status.HTTP_403_FORBIDDEN,
            detail=f"Недостаточно прав: требуется {capability}",
        )


@admin_router.get("/base-currency", response_model=BaseCurrencyOut)
async def get_base_currency(
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
) -> BaseCurrencyOut:
    """Текущая базовая валюта группы (единый источник — FinSettings).

    Гейт: view_operations (бухгалтер/cfo/админ). Дефолт RUB, если строки настроек нет.
    """
    await _require_cap(session, current_user, "view_operations")
    settings_row = (
        await session.execute(select(FinSettings).limit(1))
    ).scalar_one_or_none()
    if settings_row is None:
        return BaseCurrencyOut(base_currency="RUB", base_currency_changed_at=None)
    return BaseCurrencyOut(
        base_currency=settings_row.base_currency,
        base_currency_changed_at=settings_row.base_currency_changed_at,
    )


@admin_router.post("/base-currency", response_model=BaseCurrencyChangeResponse)
async def set_base_currency(
    body: BaseCurrencyChangeRequest,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
) -> BaseCurrencyChangeResponse:
    """Сменить базовую валюту группы — ДЕЛЕГИРУЕТ в base_currency_svc (тот же путь,
    что и POST /finance/settings/base-currency, без дублирования логики).

    Гейт: change_base_currency (cfo/админ). Тяжёлый пересчёт amount_in_base по дате
    каждой строки (H4 — только открытые периоды; закрытые/amount_func НЕ трогаются).
    Single-flight через тот же advisory-lock, что и finance-эндпоинт.
    """
    await _require_cap(session, current_user, "change_base_currency")
    target = body.target_currency.strip().upper()
    if not target:
        raise HTTPException(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            detail="Не задана целевая валюта",
        )
    if target not in SUPPORTED_CURRENCIES:
        raise HTTPException(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            detail=f"Валюта не поддерживается: {target}",
        )
    # Single-flight: тот же advisory-key, что и в finance-роутере (защита от гонки scale=2).
    await session.execute(
        sa_func.pg_advisory_xact_lock(base_currency_svc.RECOMPUTE_ADVISORY_KEY)
    )
    try:
        job = await base_currency_svc.recompute_base_amounts(
            session, target_currency=target, started_by_user_id=current_user.id
        )
        await audit_fin.log_fin(
            session,
            entity_type="fin_base_recompute_job",
            entity_id=job.id,
            user_id=current_user.id,
            action="change_base",
            after=audit_fin.snapshot_base_recompute_job(job),
        )
        await session.commit()
    except (FxRateMissing, base_currency_svc.BaseCurrencyError) as exc:
        await session.rollback()
        raise HTTPException(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            detail=str(exc) or "Не удалось сменить базовую валюту",
        ) from exc
    msg = (
        f"Базовая валюта изменена на {target}. "
        f"Пересчитано строк: {job.processed_lines}."
    )
    if job.missing_rate_lines:
        msg += f" Без курса (требует ручного ввода): {job.missing_rate_lines}."
    if job.skipped_closed:
        msg += f" Пропущено в закрытых периодах: {job.skipped_closed}."
    return BaseCurrencyChangeResponse(
        base_currency=target,
        job_id=job.id,
        status=job.status,
        total_lines=job.total_lines,
        processed_lines=job.processed_lines,
        skipped_closed=job.skipped_closed,
        missing_rate_lines=job.missing_rate_lines,
        message=msg,
    )


# ============ Supported currencies (для UI селектора) ============

class SupportedCurrenciesOut(BaseModel):
    """Список поддерживаемых валют + флаг готовности exchange-rate-api."""
    currencies: list[str]
    api_configured: bool


@router.get("/supported", response_model=SupportedCurrenciesOut)
async def get_supported_currencies(_: CurrentUser) -> SupportedCurrenciesOut:
    """Whitelist валют для UI селектора. api_configured показывает,
    можно ли вызывать /admin/currency-rates/refresh с реальным fetch'ем,
    или нужно вводить курсы вручную."""
    settings = get_settings()
    return SupportedCurrenciesOut(
        currencies=list(SUPPORTED_CURRENCIES),
        api_configured=bool(settings.exchange_rate_api_key),
    )


# ============ Seed-pairs: массовое создание заглушек для пар (admin) ============

class SeedPairsRequest(BaseModel):
    """Запрос на массовое создание placeholder-курсов для пар (base × currencies).

    Используется кнопкой «Создать заглушки для всех пар» на /admin/currency-rates.
    Если ключ к exchange-rate-api настроен — после создания заглушек сразу
    дёргается fetch для перезаписи placeholder'ов реальными курсами.
    """
    currencies: list[str] = Field(..., min_length=1)
    base_currency: str = Field(default="RUB", max_length=8)


class SeedPairsResponse(BaseModel):
    inserted: int
    skipped: int
    refreshed: int
    api_configured: bool
    message: str


@admin_router.post("/seed-pairs", response_model=SeedPairsResponse)
async def seed_currency_pairs(
    body: SeedPairsRequest,
    admin: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
) -> SeedPairsResponse:
    """Создать placeholder-курсы (rate=1.0) для всех пар (base × selected).

    Pairs создаются в обе стороны: base → cur и cur → base. Если запись
    (from, to, today) уже есть — не трогаем (ON CONFLICT DO NOTHING).
    После insert'а — если EXCHANGE_RATE_API_KEY задан, сразу запускаем
    update_currency_rates() чтобы перезаписать заглушки реальными курсами.

    Невалидные коды валют (не в SUPPORTED_CURRENCIES) → 422.
    """
    base_cur = body.base_currency.upper()
    if base_cur not in SUPPORTED_CURRENCIES:
        raise HTTPException(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            detail=(
                f"Unsupported base_currency: {base_cur}. "
                f"Supported: {list(SUPPORTED_CURRENCIES)}"
            ),
        )

    upper_currencies = [c.upper() for c in body.currencies]
    unknown = [c for c in upper_currencies if c not in SUPPORTED_CURRENCIES]
    if unknown:
        raise HTTPException(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            detail=(
                f"Unsupported currencies: {unknown}. "
                f"Supported: {list(SUPPORTED_CURRENCIES)}"
            ),
        )

    today = date_cls.today()
    rows: list[dict[str, Any]] = []
    seen: set[tuple[str, str]] = set()
    for cur in upper_currencies:
        if cur == base_cur:
            continue
        for from_cur, to_cur in ((base_cur, cur), (cur, base_cur)):
            if (from_cur, to_cur) in seen:
                continue
            seen.add((from_cur, to_cur))
            rows.append({
                "from_currency": from_cur,
                "to_currency": to_cur,
                "rate": Decimal("1.0"),
                "rate_date": today,
                "source": "seed_placeholder",
            })

    if not rows:
        return SeedPairsResponse(
            inserted=0,
            skipped=0,
            refreshed=0,
            api_configured=bool(get_settings().exchange_rate_api_key),
            message="Nothing to seed (all currencies match base_currency).",
        )

    # Сначала посчитаем, сколько уже есть — чтобы корректно отдать skipped.
    existing_pairs_q = await session.execute(
        select(
            CurrencyRate.from_currency,
            CurrencyRate.to_currency,
        ).where(
            CurrencyRate.rate_date == today,
            CurrencyRate.from_currency.in_({r["from_currency"] for r in rows}),
            CurrencyRate.to_currency.in_({r["to_currency"] for r in rows}),
        )
    )
    existing_set = {(f, t) for f, t in existing_pairs_q.all()}
    skipped = sum(
        1 for r in rows
        if (r["from_currency"], r["to_currency"]) in existing_set
    )
    inserted = len(rows) - skipped

    if inserted > 0:
        stmt = pg_insert(CurrencyRate).values(rows)
        stmt = stmt.on_conflict_do_nothing(
            constraint="uq_currency_rate_from_to_date"
        )
        await session.execute(stmt)
        await session.commit()

    # Опциональный fetch — если ключ задан, сразу перезаписываем placeholder'ы.
    settings_obj = get_settings()
    api_configured = bool(settings_obj.exchange_rate_api_key)
    refreshed = 0
    if api_configured:
        try:
            refreshed = (await update_currency_rates(session)).updated
        except Exception:  # noqa: BLE001
            # Лучше отдать частичный успех (seed прошёл), чем кричать 500.
            refreshed = 0

    if api_configured and refreshed > 0:
        msg = (
            f"Seeded {inserted} placeholder pair(s), "
            f"refreshed {refreshed} from API."
        )
    elif api_configured:
        msg = (
            f"Seeded {inserted} placeholder pair(s); "
            "API refresh returned 0 (check API status)."
        )
    else:
        msg = (
            f"Seeded {inserted} placeholder pair(s) at rate=1.0. "
            "Set EXCHANGE_RATE_API_KEY or edit rates manually."
        )

    return SeedPairsResponse(
        inserted=inserted,
        skipped=skipped,
        refreshed=refreshed,
        api_configured=api_configured,
        message=msg,
    )
