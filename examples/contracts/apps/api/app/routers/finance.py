"""API модуля «Финансы», Ф0 (ЧАНК 3) — /api/finance/*.

Operation-centric REST поверх double-entry GL. Пользователь работает со
справочниками/операциями/журналами/отчётами; проводки пишет ТОЛЬКО posting-движок
(services/finance/posting_db). Эндпоинты:

  Справочники (read + CRUD по правам):
    GET    /legal-entities                 view_operations
    POST   /legal-entities                 manage_settings
    PATCH  /legal-entities/{id}            manage_settings
    GET    /chart-of-accounts              view_operations   (план счетов, read)
    GET    /money-accounts                 view_operations
    POST   /money-accounts                 manage_accounts   (initial≠0 → opening-проводка)
    PATCH  /money-accounts/{id}            manage_accounts
    GET    /money-accounts/{id}/balance    view_operations
    GET    /op-types                       view_operations
    POST   /op-types                       manage_categories
    PATCH  /op-types/{id}                  manage_categories
    GET    /categories                     view_operations
    POST   /categories                     manage_categories
    PATCH  /categories/{id}                manage_categories
    GET    /cat-sets                       view_operations
    GET    /vat-rates                      view_operations
    GET    /permissions                    manage_settings   (read матрица)

  Операции:
    GET    /operations                     view_operations   (фильтры + sum-footer)
    GET    /operations/{id}                view_operations
    POST   /operations                     create_operation  (create+post)
    PATCH  /operations/{id}                create_operation
    POST   /operations/{id}/post           post_operation
    POST   /operations/{id}/reverse        post_operation
    GET    /operations/{id}/allocations    view_operations
    PUT    /operations/{id}/allocations    create_operation  (Σ == amount)

  Ручные журналы:
    GET    /journals                       view_journal
    GET    /journals/{id}                  view_journal
    POST   /journals                       create_manual_journal
    PATCH  /journals/{id}                  create_manual_journal  (только draft)
    POST   /journals/{id}/post             post_manual_journal
    POST   /journals/{id}/reverse          post_manual_journal    (сторно проводки журнала)
    DELETE /journals/{id}                  create_manual_journal  (только draft)

  Сторно проводки:
    POST   /entries/{id}/reverse           post_operation

  Отчёты Ф0:
    GET    /balances                       view_operations   (остатки)
    GET    /reports/cashflow-simple        view_reports      (простой ДДС)

  Периоды:
    GET    /periods                        view_operations   (?entity=)
    POST   /periods/lock                   close_period      (body {legal_entity_id,year,month})
    DELETE /periods/lock                   close_period      (body {legal_entity_id,year,month})

Гейтинг — `require_fin(capability)` (фабрика поверх access.fin_can). Исключения
движка → HTTP через http_errors.posting_status.
"""

from __future__ import annotations

from datetime import UTC, date, datetime
from decimal import Decimal
from typing import Annotated, NoReturn

from fastapi import APIRouter, Depends, HTTPException, Query, status
from fastapi.responses import FileResponse
from sqlalchemy import and_
from sqlalchemy import func as sa_func
from sqlalchemy import select
from sqlalchemy.exc import IntegrityError
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.orm import selectinload

from app.db import get_session
from app.deps import get_current_user
from app.models import (
    ClientSubscription,
    Company,
    Deal,
    FinAccountGl,
    FinAct,
    FinActLine,
    FinAllocation,
    FinApproval,
    FinApprovalScenario,
    FinCashflowCategory,
    FinCatSet,
    FinInvoice,
    FinInvoiceLine,
    FinJournalEntry,
    FinLegalEntity,
    FinManualJournal,
    FinManualJournalLine,
    FinMoneyAccount,
    FinOperation,
    FinOpType,
    FinPaymentRegistry,
    FinPeriodLock,
    FinPermission,
    FinRequest,
    FinRevenueSchedule,
    FinSettings,
    FinVatRate,
    FinVendorBill,
    FinVendorBillLine,
    MotivationalCard,
    User,
)
from app.schemas_finance import (
    ActCreate,
    ActDetailOut,
    ActOut,
    ActPatch,
    AgingReportOut,
    AllocationOut,
    AllocationsPut,
    ApprovalDecisionIn,
    ApprovalScenarioCreate,
    ApprovalScenarioOut,
    ApprovalScenarioPatch,
    ApprovalSummaryOut,
    ApprovalVoteOut,
    ArApReportOut,
    ArApRowOut,
    BalanceRow,
    BalancesOut,
    BaseCurrencyChangeIn,
    BaseRecomputeJobOut,
    CalendarDayTotalOut,
    CalendarEventOut,
    CalendarOut,
    CashflowReportOut,
    CashflowRowOut,
    CategoryCreate,
    CategoryOut,
    CategoryPatch,
    CategoryReorderItem,
    CatSetOut,
    DocPaymentIn,
    EntriesListOut,
    FinSettingsOut,
    GlAccountOut,
    InvoiceCreate,
    InvoiceDetailOut,
    InvoiceOut,
    InvoicePatch,
    JournalEntryOut,
    LegalEntityCreate,
    LegalEntityOut,
    LegalEntityPatch,
    ManualJournalCreate,
    ManualJournalOut,
    ManualJournalPatch,
    MoneyAccountCreate,
    MoneyAccountOut,
    MoneyAccountPatch,
    MotivationalCardPayOut,
    OperationCreate,
    OperationListOut,
    OperationOut,
    OperationPatch,
    OpTypeCreate,
    OpTypeOut,
    OpTypePatch,
    PeriodLockIn,
    PeriodLockOut,
    PermissionCreate,
    PermissionOut,
    PermissionPatch,
    PnlLineOut,
    PnlReportOut,
    RegistryCreate,
    RegistryDetailOut,
    RegistryItemsIn,
    RegistryOut,
    RegistryPatch,
    ReorderOut,
    RequestCreate,
    RequestFulfillIn,
    RequestOut,
    RequestPatch,
    RevaluationRunIn,
    RevaluationRunOut,
    RevenueRecognitionRunIn,
    RevenueRecognitionRunOut,
    RevenueReverseIn,
    RevenueReverseOut,
    RevenueScheduleOut,
    ReverseIn,
    ShadowReconOut,
    SubscriptionPlanIn,
    SubscriptionPlanOut,
    TrialBalanceReportOut,
    TrialBalanceRowOut,
    VatRateOut,
    VatReportOut,
    VendorBillCreate,
    VendorBillDetailOut,
    VendorBillOut,
    VendorBillPatch,
)
from app.services.bulk_reorder import apply_reorder_simple, validate_reorder_payload
from app.services.finance import audit_fin, fin_approval, numbering, posting_db
from app.services.finance import balance as balance_svc
from app.services.finance import calendar as calendar_svc
from app.services.finance import base_currency as base_currency_svc
from app.services.finance import cashflow as cashflow_svc
from app.services.finance import doc_render as doc_render_svc
from app.services.finance import invoicing as invoicing_svc
from app.services.finance import pay_integration as pay_integration_svc
from app.services.finance import recognition as recognition_svc
from app.services.finance import registry as registry_svc
from app.services.finance import reports as reports_svc
from app.services.finance import requests as requests_svc
from app.services.finance import revaluation as revaluation_svc
from app.services.finance import shadow_recon as shadow_recon_svc
from app.services.finance import vat_books as vat_books_svc
from app.services.finance.access import accessible_legal_entity_ids, fin_can
from app.services.finance.fx import FxRateMissing
from app.services.finance.http_errors import (
    phase2_status,
    phase3_status,
    phase4_status,
    phase5_status,
    posting_status,
)
from app.services.finance.posting import (
    PostingError,
    assert_manual_journal_mutable,
    assert_operation_mutable,
)

# Допустимые значения query-фильтров листинга операций (W7 — валидация enum в query).
# Должны соответствовать ck_fin_operation_status / direction в models.FinOperation.
_OP_DIRECTIONS = ("in", "out", "transfer")
_OP_STATUSES = (
    "planned",
    "to_pay",
    "on_hold",
    "posted",
    "reversed",
    "rejected",
    "cancelled",
    "partially_paid",
)

#: Допустимые статусы строки плана признания выручки (CHECK
#: ck_fin_revenue_schedule_status в models.py). Валидация фильтра ?status= в листинге.
_SCHEDULE_STATUSES = ("scheduled", "recognized", "skipped", "reversed")


def _normalize_q(q: str | None) -> str | None:
    """Нормализовать q-поиск: trim + пустую/None строку → None (фильтр не применяется).

    Pure — тестируется без БД (предикат q-фильтра list_operations: ILIKE по
    purpose/number применяется ⇔ результат не None).
    """
    if q is None:
        return None
    term = q.strip()
    return term or None


router = APIRouter(prefix="/finance", tags=["finance"])

CurrentUser = Annotated[User, Depends(get_current_user)]
Session = Annotated[AsyncSession, Depends(get_session)]


# ───────────────────────────── гейтинг прав ─────────────────────────────


def require_fin(capability: str):
    """Фабрика dep: пускает, только если `fin_can(user, capability, entity)` True.

    legal_entity_id берётся из query (?legal_entity_id) — для per-entity-разреза
    прав (H12). В Ф0 одно юрлицо, но код-путь заложен. На write-эндпоинтах, где
    юрлицо приходит в body, дополнительная per-entity-проверка делается уже внутри
    хендлера (этот dep гарантирует базовый capability).
    """

    async def _checker(
        session: Session,
        user: CurrentUser,
        legal_entity_id: int | None = Query(default=None),
    ) -> User:
        if not await fin_can(
            session, user, capability, legal_entity_id=legal_entity_id
        ):
            raise HTTPException(
                status.HTTP_403_FORBIDDEN,
                f"Недостаточно прав модуля «Финансы»: требуется {capability}",
            )
        return user

    return _checker


async def _ensure_cap(
    session: AsyncSession, user: User, capability: str, legal_entity_id: int | None
) -> None:
    """Per-entity проверка внутри хендлера (когда юрлицо приходит в body, не в query)."""
    if not await fin_can(session, user, capability, legal_entity_id=legal_entity_id):
        raise HTTPException(
            status.HTTP_403_FORBIDDEN,
            f"Недостаточно прав по юрлицу: требуется {capability}",
        )


async def _ensure_read_scope(
    session: AsyncSession,
    user: User,
    legal_entity_id: int,
    capability: str = "view_operations",
) -> None:
    """W1 — per-entity проверка доступа к финданным конкретного юрлица в read-by-id.

    require_fin(...) на read-эндпоинте проверяет capability только в разрезе
    query-параметра (?legal_entity_id), которого в read-by-id нет. Поэтому после
    загрузки сущности её фактическое legal_entity_id надо проверить отдельно — иначе
    авторизованный с view_* пользователь видит данные ЧУЖОГО юрлица (утечка).
    """
    if not await fin_can(
        session, user, capability, legal_entity_id=legal_entity_id
    ):
        raise HTTPException(
            status.HTTP_403_FORBIDDEN,
            "Нет доступа к данным этого юрлица",
        )


def _raise_posting(exc: Exception) -> NoReturn:
    """Перевести исключение движка/курса в HTTPException (422/409). Всегда бросает.

    Аннотация NoReturn структурно гарантирует, что после
    `except ...: _raise_posting(exc)` код недостижим (W4) — переменные, присвоенные
    в try (напр. `rev`), не могут оказаться unbound на пути после except-блока.
    """
    code, detail = posting_status(exc)
    raise HTTPException(code, detail) from exc


# ───────────────────────────── LegalEntity ─────────────────────────────


@router.get("/legal-entities", response_model=list[LegalEntityOut])
async def list_legal_entities(
    session: Session,
    _: Annotated[User, Depends(require_fin("view_operations"))],
):
    rows = (
        await session.execute(
            select(FinLegalEntity).order_by(
                FinLegalEntity.sort_order, FinLegalEntity.id
            )
        )
    ).scalars().all()
    return rows


@router.post("/legal-entities", response_model=LegalEntityOut, status_code=201)
async def create_legal_entity(
    payload: LegalEntityCreate,
    session: Session,
    user: CurrentUser,
):
    await _ensure_cap(session, user, "manage_settings", None)
    le = FinLegalEntity(**payload.model_dump())
    session.add(le)
    await session.commit()
    await session.refresh(le)
    return le


@router.patch("/legal-entities/{le_id}", response_model=LegalEntityOut)
async def patch_legal_entity(
    le_id: int,
    payload: LegalEntityPatch,
    session: Session,
    user: CurrentUser,
):
    le = await _get_or_404(session, FinLegalEntity, le_id, "Юрлицо не найдено")
    await _ensure_cap(session, user, "manage_settings", le.id)
    fields = payload.model_dump(exclude_unset=True)
    # B3: функциональная валюта юрлица — основа инварианта Σ amount_func=0 по всем его
    # проводкам. Менять её при существующих проводках нельзя — иначе баланс смешает
    # валюты (старые строки в старой func-валюте, новые — в новой). Разрешаем смену
    # только пока у юрлица НЕТ ни одной проводки.
    new_func = fields.get("functional_currency")
    if new_func is not None and new_func != le.functional_currency:
        has_entries = (
            await session.execute(
                select(FinJournalEntry.id)
                .where(FinJournalEntry.legal_entity_id == le.id)
                .limit(1)
            )
        ).scalar_one_or_none()
        if has_entries is not None:
            raise HTTPException(
                409,
                "Нельзя сменить функциональную валюту юрлица: по нему уже есть "
                "проводки. Смена нарушит инвариант Σ amount_func=0 (смешение валют).",
            )
    for k, v in fields.items():
        setattr(le, k, v)
    await session.commit()
    await session.refresh(le)
    return le


# ───────────────────────────── GL accounts (план счетов, read) ─────────────────────────────


@router.get("/chart-of-accounts", response_model=list[GlAccountOut])
async def list_gl_accounts(
    session: Session,
    _: Annotated[User, Depends(require_fin("view_operations"))],
    only_active: bool = Query(default=True),
):
    stmt = select(FinAccountGl)
    if only_active:
        stmt = stmt.where(FinAccountGl.is_active.is_(True))
    stmt = stmt.order_by(FinAccountGl.sort_order, FinAccountGl.code)
    return (await session.execute(stmt)).scalars().all()


# ───────────────────────────── MoneyAccount ─────────────────────────────


@router.get("/money-accounts", response_model=list[MoneyAccountOut])
async def list_money_accounts(
    session: Session,
    _: Annotated[User, Depends(require_fin("view_operations"))],
    legal_entity_id: int | None = Query(default=None),
    only_active: bool = Query(default=True),
):
    stmt = select(FinMoneyAccount)
    if legal_entity_id is not None:
        stmt = stmt.where(FinMoneyAccount.legal_entity_id == legal_entity_id)
    if only_active:
        stmt = stmt.where(FinMoneyAccount.is_active.is_(True))
    stmt = stmt.order_by(FinMoneyAccount.sort_order, FinMoneyAccount.id)
    return (await session.execute(stmt)).scalars().all()


@router.get("/money-accounts/{ma_id}", response_model=MoneyAccountOut)
async def get_money_account(
    ma_id: int,
    session: Session,
    user: CurrentUser,
    _: Annotated[User, Depends(require_fin("view_operations"))],
):
    """Карточка денежного счёта по id (read-only, cookie-auth)."""
    ma = await _get_or_404(session, FinMoneyAccount, ma_id, "Денежный счёт не найден")
    await _ensure_read_scope(session, user, ma.legal_entity_id)  # W1
    return ma


@router.post("/money-accounts", response_model=MoneyAccountOut, status_code=201)
async def create_money_account(
    payload: MoneyAccountCreate,
    session: Session,
    user: CurrentUser,
):
    """Создать денежный счёт. Если initial_balance≠0 — постит opening-проводку
    (Дт money / Кт 3900) той же транзакцией через специальную opening-операцию."""
    await _ensure_cap(session, user, "manage_accounts", payload.legal_entity_id)

    gl = await _get_or_404(
        session, FinAccountGl, payload.gl_account_id, "GL-счёт не найден"
    )
    if not gl.is_money:
        raise HTTPException(
            422, f"Счёт {gl.code} не денежный (is_money=False) — нельзя привязать"
        )

    ma = FinMoneyAccount(
        legal_entity_id=payload.legal_entity_id,
        gl_account_id=payload.gl_account_id,
        name=payload.name,
        account_type=payload.account_type,
        currency=payload.currency,
        initial_balance=payload.initial_balance,
        sort_order=payload.sort_order,
    )
    session.add(ma)
    await session.flush()

    if payload.initial_balance and payload.initial_balance != Decimal("0"):
        await _post_opening(session, ma, user)

    await session.commit()
    await session.refresh(ma)
    return ma


async def _post_opening(
    session: AsyncSession, ma: FinMoneyAccount, user: User
) -> None:
    """Завести opening-операцию по счёту и провести её (Дт money / Кт 3900)."""
    op_type = (
        await session.execute(
            select(FinOpType).where(FinOpType.posting_template == "opening").limit(1)
        )
    ).scalar_one_or_none()
    if op_type is None:
        # W3: явный rollback для консистентности отката с остальным роутером.
        await session.rollback()
        raise HTTPException(
            500, "Не найден тип операции с posting_template=opening (сид Ф0)"
        )
    op = FinOperation(
        legal_entity_id=ma.legal_entity_id,
        op_type_id=op_type.id,
        direction="in",
        status="draft",
        op_date=date.today(),
        amount=abs(ma.initial_balance),
        currency=ma.currency,
        account_to_id=ma.id,
        purpose=f"Ввод начального остатка по счёту «{ma.name}»",
        created_by_user_id=user.id,
        source="opening",
        source_ref_id=ma.id,
    )
    session.add(op)
    await session.flush()
    try:
        await posting_db.post_operation(session, op, posted_by_user_id=user.id)
    except (PostingError, FxRateMissing) as exc:
        _raise_posting(exc)


@router.patch("/money-accounts/{ma_id}", response_model=MoneyAccountOut)
async def patch_money_account(
    ma_id: int,
    payload: MoneyAccountPatch,
    session: Session,
    user: CurrentUser,
):
    ma = await _get_or_404(session, FinMoneyAccount, ma_id, "Денежный счёт не найден")
    await _ensure_cap(session, user, "manage_accounts", ma.legal_entity_id)
    for k, v in payload.model_dump(exclude_unset=True).items():
        setattr(ma, k, v)
    await session.commit()
    await session.refresh(ma)
    return ma


@router.get("/money-accounts/{ma_id}/balance")
async def money_account_balance(
    ma_id: int,
    session: Session,
    user: CurrentUser,
    _: Annotated[User, Depends(require_fin("view_operations"))],
    on_date: date | None = Query(default=None),
):
    ma = await _get_or_404(session, FinMoneyAccount, ma_id, "Денежный счёт не найден")
    await _ensure_read_scope(session, user, ma.legal_entity_id)  # W1
    bal = await balance_svc.money_account_balance(session, ma, on_date=on_date)
    return {
        "money_account_id": bal.money_account_id,
        "currency": bal.currency,
        "amount": bal.amount,
        "amount_func": bal.amount_func,
    }


# ───────────────────────────── OpType ─────────────────────────────


@router.get("/op-types", response_model=list[OpTypeOut])
async def list_op_types(
    session: Session,
    _: Annotated[User, Depends(require_fin("view_operations"))],
    include_archived: bool = Query(default=False),
):
    stmt = select(FinOpType)
    if not include_archived:
        stmt = stmt.where(FinOpType.is_archived.is_(False))
    stmt = stmt.order_by(FinOpType.sort_order, FinOpType.id)
    return (await session.execute(stmt)).scalars().all()


@router.post("/op-types", response_model=OpTypeOut, status_code=201)
async def create_op_type(
    payload: OpTypeCreate,
    session: Session,
    user: CurrentUser,
):
    await _ensure_cap(session, user, "manage_categories", None)
    ot = FinOpType(**payload.model_dump())
    session.add(ot)
    try:
        await session.commit()
    except IntegrityError as exc:
        await session.rollback()
        raise HTTPException(409, f"Тип операции с кодом {payload.code} уже есть") from exc
    await session.refresh(ot)
    return ot


@router.patch("/op-types/{ot_id}", response_model=OpTypeOut)
async def patch_op_type(
    ot_id: int,
    payload: OpTypePatch,
    session: Session,
    user: CurrentUser,
):
    ot = await _get_or_404(session, FinOpType, ot_id, "Тип операции не найден")
    await _ensure_cap(session, user, "manage_categories", None)
    for k, v in payload.model_dump(exclude_unset=True).items():
        setattr(ot, k, v)
    await session.commit()
    await session.refresh(ot)
    return ot


# ───────────────────────────── Categories (статьи ДДС) ─────────────────────────────


@router.get("/categories", response_model=list[CategoryOut])
async def list_categories(
    session: Session,
    _: Annotated[User, Depends(require_fin("view_operations"))],
    only_active: bool = Query(default=True),
):
    stmt = select(FinCashflowCategory)
    if only_active:
        stmt = stmt.where(FinCashflowCategory.is_active.is_(True))
    stmt = stmt.order_by(FinCashflowCategory.sort_order, FinCashflowCategory.code)
    return (await session.execute(stmt)).scalars().all()


@router.post("/categories", response_model=CategoryOut, status_code=201)
async def create_category(
    payload: CategoryCreate,
    session: Session,
    user: CurrentUser,
):
    await _ensure_cap(session, user, "manage_categories", None)
    cat = FinCashflowCategory(**payload.model_dump())
    session.add(cat)
    try:
        await session.commit()
    except IntegrityError as exc:
        await session.rollback()
        raise HTTPException(
            409, f"Статья с кодом {payload.code} в этом наборе уже есть"
        ) from exc
    await session.refresh(cat)
    return cat


@router.patch("/categories/reorder", response_model=ReorderOut)
async def reorder_categories(
    payload: list[CategoryReorderItem],
    session: Session,
    user: CurrentUser,
    cat_set_id: int = Query(...),
    activity: str = Query(...),
):
    """Bulk-обновить sort_order статей ДДС ВНУТРИ одной группы (cat_set × activity).

    cat_set_id и activity передаются query-параметрами и образуют scope_filter:
    переупорядочить можно только статьи одной группы (вида деятельности),
    случайно перетащить статью в чужую группу нельзя — id вне scope → 400.
    """
    await _ensure_cap(session, user, "manage_categories", None)
    items_dict = [it.model_dump() for it in payload]
    pairs = validate_reorder_payload(items_dict, order_field="sort_order")
    scope = and_(
        FinCashflowCategory.cat_set_id == cat_set_id,
        FinCashflowCategory.activity == activity,
    )
    count = await apply_reorder_simple(
        session,
        FinCashflowCategory,
        pairs,
        scope_filter=scope,
        order_field="sort_order",
    )
    await session.commit()
    return ReorderOut(updated=count)


@router.patch("/categories/{cat_id}", response_model=CategoryOut)
async def patch_category(
    cat_id: int,
    payload: CategoryPatch,
    session: Session,
    user: CurrentUser,
):
    cat = await _get_or_404(session, FinCashflowCategory, cat_id, "Статья не найдена")
    await _ensure_cap(session, user, "manage_categories", None)
    for k, v in payload.model_dump(exclude_unset=True).items():
        setattr(cat, k, v)
    await session.commit()
    await session.refresh(cat)
    return cat


@router.get("/cat-sets", response_model=list[CatSetOut])
async def list_cat_sets(
    session: Session,
    _: Annotated[User, Depends(require_fin("view_operations"))],
):
    return (
        await session.execute(select(FinCatSet).order_by(FinCatSet.id))
    ).scalars().all()


# ───────────────────────────── VAT rates (read) ─────────────────────────────


@router.get("/vat-rates", response_model=list[VatRateOut])
async def list_vat_rates(
    session: Session,
    _: Annotated[User, Depends(require_fin("view_operations"))],
    legal_entity_id: int | None = Query(default=None),
):
    stmt = select(FinVatRate).where(FinVatRate.is_active.is_(True))
    if legal_entity_id is not None:
        stmt = stmt.where(
            (FinVatRate.legal_entity_id == legal_entity_id)
            | (FinVatRate.legal_entity_id.is_(None))
        )
    stmt = stmt.order_by(FinVatRate.id)
    return (await session.execute(stmt)).scalars().all()


# ───────────────────────────── Permissions (read матрица) ─────────────────────────────


@router.get("/permissions", response_model=list[PermissionOut])
async def list_permissions(
    session: Session,
    _: Annotated[User, Depends(require_fin("manage_settings"))],
):
    return (
        await session.execute(select(FinPermission).order_by(FinPermission.id))
    ).scalars().all()


# ───────────────────────────── Operations ─────────────────────────────


@router.get("/operations", response_model=OperationListOut)
async def list_operations(
    session: Session,
    user: CurrentUser,
    _: Annotated[User, Depends(require_fin("view_operations"))],
    legal_entity_id: int | None = Query(default=None),
    direction: str | None = Query(default=None),
    op_status: str | None = Query(default=None, alias="status"),
    account_id: int | None = Query(default=None),
    counterparty_company_id: int | None = Query(default=None),
    op_type_id: int | None = Query(default=None),
    cashflow_category_id: int | None = Query(default=None),
    date_from: date | None = Query(default=None),
    date_to: date | None = Query(default=None),
    q: str | None = Query(default=None),
    limit: int = Query(default=100, ge=1, le=500),
    offset: int = Query(default=0, ge=0),
):
    """Листинг операций с фильтрами + sum-footer.

    W7: direction/status валидируются против enum → 422 при неверном значении
        (раньше неверная строка тихо давала пустой результат).
    W5: manager (нет capability view_all_operations) видит ТОЛЬКО свои операции
        (created_by_user_id == user.id). accountant/cfo/director/admin — все.
    W (per-entity scope): при отсутствии legal_entity_id выборка ограничивается
        доступными юрлицами через accessible_legal_entity_ids (defense-in-depth).
        Сейчас все фин-роли имеют view_operations со scope=NULL → None → ограничения
        нет (поведение не меняется); как только появятся per-entity права (Ф2),
        выборка автоматически сузится до доступных юрлиц без правок этого эндпоинта.
    W2: пагинация (limit/offset), total (COUNT) и sum-footer (агрегат) считаются
        в SQL по ВСЕЙ выборке фильтра, не материализацией всей таблицы в память.
    q: ILIKE-поиск по назначению (purpose) и номеру операции (number).
    """
    if direction is not None and direction not in _OP_DIRECTIONS:
        raise HTTPException(
            422, f"direction должен быть одним из {', '.join(_OP_DIRECTIONS)}"
        )
    if op_status is not None and op_status not in _OP_STATUSES:
        raise HTTPException(
            422, f"status должен быть одним из {', '.join(_OP_STATUSES)}"
        )

    # Набор WHERE-условий — общий для items / count / sum-агрегата.
    conds = []
    if legal_entity_id is not None:
        conds.append(FinOperation.legal_entity_id == legal_entity_id)
    else:
        # Defense-in-depth: при отсутствии явного юрлица ограничиваем выборку
        # доступными юрлицами (None = NULL-scope grant, ограничения нет).
        scope = await accessible_legal_entity_ids(session, user, "view_operations")
        if scope is not None:
            conds.append(FinOperation.legal_entity_id.in_(scope))
    if direction is not None:
        conds.append(FinOperation.direction == direction)
    if op_status is not None:
        conds.append(FinOperation.status == op_status)
    if account_id is not None:
        conds.append(
            (FinOperation.account_from_id == account_id)
            | (FinOperation.account_to_id == account_id)
        )
    if counterparty_company_id is not None:
        conds.append(FinOperation.counterparty_company_id == counterparty_company_id)
    if op_type_id is not None:
        conds.append(FinOperation.op_type_id == op_type_id)
    if cashflow_category_id is not None:
        conds.append(FinOperation.cashflow_category_id == cashflow_category_id)
    if date_from is not None:
        conds.append(FinOperation.op_date >= date_from)
    if date_to is not None:
        conds.append(FinOperation.op_date <= date_to)
    q_term = _normalize_q(q)
    if q_term is not None:
        like = f"%{q_term}%"
        conds.append(
            FinOperation.purpose.ilike(like) | FinOperation.number.ilike(like)
        )

    # W5: own-only для пользователей без view_all_operations (manager «видит свои»).
    if not await fin_can(
        session, user, "view_all_operations", legal_entity_id=legal_entity_id
    ):
        conds.append(FinOperation.created_by_user_id == user.id)

    base = select(FinOperation)
    for c in conds:
        base = base.where(c)

    total = (
        await session.execute(
            select(sa_func.count()).select_from(base.subquery())
        )
    ).scalar_one()

    page = (
        await session.execute(
            base.order_by(
                FinOperation.op_date.desc(), FinOperation.id.desc()
            ).limit(limit).offset(offset)
        )
    ).scalars().all()

    # sum-footer считаем агрегатом по ВСЕЙ выборке фильтра (не по странице).
    sum_stmt = select(
        FinOperation.direction, sa_func.coalesce(sa_func.sum(FinOperation.amount), 0)
    )
    for c in conds:
        sum_stmt = sum_stmt.where(c)
    sum_stmt = sum_stmt.group_by(FinOperation.direction)
    sum_by_dir = {
        d: Decimal(s) for d, s in (await session.execute(sum_stmt)).all()
    }
    return OperationListOut(
        items=page,
        total=total,
        sum_in=sum_by_dir.get("in", Decimal("0")),
        sum_out=sum_by_dir.get("out", Decimal("0")),
    )


@router.get("/operations/{op_id}", response_model=OperationOut)
async def get_operation(
    op_id: int,
    session: Session,
    user: CurrentUser,
    _: Annotated[User, Depends(require_fin("view_operations"))],
):
    op = await _get_or_404(session, FinOperation, op_id, "Операция не найдена")
    # W1: доступ к юрлицу операции.
    await _ensure_read_scope(session, user, op.legal_entity_id)
    # W5: пользователь без view_all_operations видит только свои операции (по id тоже).
    if op.created_by_user_id != user.id and not await fin_can(
        session, user, "view_all_operations", legal_entity_id=op.legal_entity_id
    ):
        raise HTTPException(404, "Операция не найдена")
    return op


@router.post("/operations", response_model=OperationOut, status_code=201)
async def create_operation(
    payload: OperationCreate,
    session: Session,
    user: CurrentUser,
    auto_post: bool = Query(default=True),
):
    """Создать операцию (черновик) и, если auto_post — сразу провести в проводку."""
    await _ensure_cap(session, user, "create_operation", payload.legal_entity_id)
    if auto_post:
        await _ensure_cap(session, user, "post_operation", payload.legal_entity_id)

    op = FinOperation(
        legal_entity_id=payload.legal_entity_id,
        op_type_id=payload.op_type_id,
        direction=payload.direction,
        status="draft",
        op_date=payload.op_date,
        due_date=payload.due_date,
        amount=payload.amount,
        currency=payload.currency,
        to_amount=payload.to_amount,
        account_from_id=payload.account_from_id,
        account_to_id=payload.account_to_id,
        cashflow_category_id=payload.cashflow_category_id,
        counterparty_company_id=payload.counterparty_company_id,
        vat_rate_id=payload.vat_rate_id,
        vat_amount=payload.vat_amount,
        amount_net=payload.amount_net,
        purpose=payload.purpose,
        deal_id=payload.deal_id,
        contract_id=payload.contract_id,
        subscription_id=payload.subscription_id,
        is_for_management=payload.is_for_management,
        created_by_user_id=user.id,
        source="operation",
    )
    session.add(op)
    await session.flush()

    gated = False
    if auto_post:
        # B7 CRIT-2: канал-независимый порог согласования. Если под (тип+сумма+юрлицо)
        # настроен сценарий с этапами → операция уходит в согласование (on_hold), а не
        # постится мгновенно. Нет сценария / сценарий без этапов → постим как раньше.
        scenario = await _operation_approval_gate(
            session,
            op_type_id=op.op_type_id,
            legal_entity_id=op.legal_entity_id,
            amount=op.amount,
        )
        if scenario is not None:
            await _gate_operation_into_approval(session, op, scenario, user=user)
            gated = True
        else:
            try:
                await posting_db.post_operation(session, op, posted_by_user_id=user.id)
            except (PostingError, FxRateMissing) as exc:
                await session.rollback()
                _raise_posting(exc)

    await audit_fin.log_fin(
        session, entity_type="fin_operation", entity_id=op.id,
        user_id=user.id,
        action="submit" if gated else ("post" if auto_post else "create"),
        after=audit_fin.snapshot_operation(op),
    )
    await session.commit()
    await session.refresh(op)
    return op


@router.patch("/operations/{op_id}", response_model=OperationOut)
async def patch_operation(
    op_id: int,
    payload: OperationPatch,
    session: Session,
    user: CurrentUser,
):
    """Правка операции. Проведённую (posted/reversed) править нельзя — только сторно."""
    op = await _get_or_404(session, FinOperation, op_id, "Операция не найдена")
    await _ensure_cap(session, user, "create_operation", op.legal_entity_id)
    try:
        assert_operation_mutable(op.status)
    except PostingError as exc:
        _raise_posting(exc)
    for k, v in payload.model_dump(exclude_unset=True).items():
        setattr(op, k, v)
    await session.commit()
    await session.refresh(op)
    return op


@router.post("/operations/{op_id}/post", response_model=OperationOut)
async def post_operation_endpoint(
    op_id: int,
    session: Session,
    user: CurrentUser,
):
    op = await _get_or_404(session, FinOperation, op_id, "Операция не найдена")
    await _ensure_cap(session, user, "post_operation", op.legal_entity_id)
    before = audit_fin.snapshot_operation(op)

    # B7 CRIT-2: тот же канал-независимый порог, что и при create+auto_post. Уже-
    # согласованную операцию (статус on_hold с завершённым approval) сюда не зовут —
    # её постит operation decision-endpoint. Здесь гейтим первую попытку прямой проводки.
    scenario = await _operation_approval_gate(
        session,
        op_type_id=op.op_type_id,
        legal_entity_id=op.legal_entity_id,
        amount=op.amount,
    )
    if scenario is not None:
        await _gate_operation_into_approval(session, op, scenario, user=user)
        await audit_fin.log_fin(
            session, entity_type="fin_operation", entity_id=op.id,
            user_id=user.id, action="submit",
            before=before, after=audit_fin.snapshot_operation(op),
        )
        await session.commit()
        await session.refresh(op)
        return op

    try:
        await posting_db.post_operation(session, op, posted_by_user_id=user.id)
    except (PostingError, FxRateMissing) as exc:
        await session.rollback()
        _raise_posting(exc)
    await audit_fin.log_fin(
        session, entity_type="fin_operation", entity_id=op.id,
        user_id=user.id, action="post",
        before=before, after=audit_fin.snapshot_operation(op),
    )
    await session.commit()
    await session.refresh(op)
    return op


@router.post("/operations/{op_id}/reverse", response_model=OperationOut)
async def reverse_operation_endpoint(
    op_id: int,
    payload: ReverseIn,
    session: Session,
    user: CurrentUser,
):
    """Сторнировать проведённую операцию (через её journal_entry)."""
    op = await _get_or_404(session, FinOperation, op_id, "Операция не найдена")
    await _ensure_cap(session, user, "post_operation", op.legal_entity_id)
    if op.journal_entry_id is None:
        raise HTTPException(409, "Операция не проведена — нечего сторнировать")
    entry = await _get_or_404(
        session, FinJournalEntry, op.journal_entry_id, "Проводка не найдена"
    )
    before = audit_fin.snapshot_operation(op)
    try:
        await posting_db.reverse_entry(
            session, entry, on_date=payload.on_date,
            posted_by_user_id=user.id, memo=payload.memo,
        )
    except (PostingError, FxRateMissing) as exc:
        await session.rollback()
        _raise_posting(exc)
    op.status = "reversed"
    await audit_fin.log_fin(
        session, entity_type="fin_operation", entity_id=op.id,
        user_id=user.id, action="reverse",
        before=before, after=audit_fin.snapshot_operation(op),
    )
    await session.commit()
    await session.refresh(op)
    return op


# ───────── согласование прямой операции (B7 CRIT-2: гейт on_hold → decision) ─────────


@router.get("/operations/{op_id}/approval", response_model=ApprovalSummaryOut)
async def operation_approval_summary(op_id: int, session: Session, user: CurrentUser):
    """Сводка согласования операции, попавшей в гейт порога (status=on_hold)."""
    op = await _get_or_404(session, FinOperation, op_id, "Операция не найдена")
    await _ensure_read_scope(session, user, op.legal_entity_id)
    scenario_id = await _current_scenario_id(session, "operation", op.id)
    return await _approval_summary_payload(
        session, approvable_kind="operation", approvable_id=op.id, scenario_id=scenario_id,
    )


@router.post("/operations/{op_id}/decision", response_model=OperationOut)
async def decide_operation(
    op_id: int, payload: ApprovalDecisionIn, session: Session, user: CurrentUser
):
    """Голос согласанта по операции, попавшей в порог (B7 CRIT-2).

    При завершении сценария approved → операция ПРОВОДИТСЯ (post_operation). rejected →
    статус rejected (не проводится). Самосогласование автора запрещено (деньги ↔ власть).
    """
    op = await _get_or_404(session, FinOperation, op_id, "Операция не найдена")
    await _ensure_cap(session, user, "approve", op.legal_entity_id)
    # нельзя согласовывать собственную операцию (как и заявку)
    if not fin_approval.can_decide(user.id, op.created_by_user_id):
        raise HTTPException(403, "Нельзя согласовывать собственную операцию")
    # B6: row-lock на операцию ДО record_decision — финальный голос двух согласантов в
    # гонке (scale=2) иначе оба видят status_overall='approved' на STALE-снимке и оба
    # зовут post_operation (двойная проводка). Под локом read-modify-write голосов +
    # последующая проводка атомарны; второй конкурент ждёт COMMIT первого и затем
    # либо видит уже-завершённый approval, либо отбивается ImmutablePosted в движке.
    await session.execute(
        select(FinOperation.id).where(FinOperation.id == op.id).with_for_update()
    )
    scenario_id = await _current_scenario_id(session, "operation", op.id)
    if scenario_id is None:
        raise HTTPException(422, "По операции нет активного согласования")
    scenario = await _get_or_404(
        session, FinApprovalScenario, scenario_id, "Сценарий не найден"
    )
    before = audit_fin.snapshot_operation(op)
    try:
        status_overall = await fin_approval.record_decision(
            session, approvable_kind="operation", approvable_id=op.id,
            scenario=scenario, user_id=user.id,
            decision=payload.decision, comment=payload.comment,
        )
    except fin_approval.ApprovalError as exc:
        await session.rollback()
        _raise_phase2(exc)

    if status_overall == "approved":
        # порог пройден → проводим операцию (assert_operation_mutable пропускает on_hold)
        try:
            await posting_db.post_operation(session, op, posted_by_user_id=user.id)
        except (PostingError, FxRateMissing) as exc:
            await session.rollback()
            _raise_posting(exc)
        action = "post"
    elif status_overall == "rejected":
        op.status = "rejected"
        op.rejected_reason = payload.comment
        action = "approve_decision"
    else:
        action = "approve_decision"

    await audit_fin.log_fin(
        session, entity_type="fin_operation", entity_id=op.id,
        user_id=user.id, action=action,
        before=before, after=audit_fin.snapshot_operation(op),
    )
    await session.commit()
    await session.refresh(op)
    return op


# ───────────────────────────── Allocations (split) ─────────────────────────────


@router.get("/operations/{op_id}/allocations", response_model=list[AllocationOut])
async def get_allocations(
    op_id: int,
    session: Session,
    user: CurrentUser,
    _: Annotated[User, Depends(require_fin("view_operations"))],
):
    op = await _get_or_404(session, FinOperation, op_id, "Операция не найдена")
    await _ensure_read_scope(session, user, op.legal_entity_id)  # W1
    return (
        await session.execute(
            select(FinAllocation).where(FinAllocation.operation_id == op_id)
        )
    ).scalars().all()


@router.put("/operations/{op_id}/allocations", response_model=list[AllocationOut])
async def put_allocations(
    op_id: int,
    payload: AllocationsPut,
    session: Session,
    user: CurrentUser,
):
    """Заменить разнесение операции по статьям ДДС. Для проведённой операции
    Σ allocation.amount ДОЛЖНА равняться operation.amount; для черновика — допустим
    неполный split (инвариант allocation J §fin_allocation)."""
    op = await _get_or_404(session, FinOperation, op_id, "Операция не найдена")
    await _ensure_cap(session, user, "create_operation", op.legal_entity_id)

    total = sum((it.amount for it in payload.items), Decimal("0"))
    if op.status in ("posted", "reversed") and total != op.amount:
        raise HTTPException(
            422,
            f"Сумма разнесения {total} ≠ сумме операции {op.amount} "
            "(для проведённой операции split должен покрывать всю сумму)",
        )

    # удалить старые, вставить новые
    old = (
        await session.execute(
            select(FinAllocation).where(FinAllocation.operation_id == op_id)
        )
    ).scalars().all()
    for a in old:
        await session.delete(a)
    new_rows = [
        FinAllocation(
            operation_id=op_id,
            cashflow_category_id=it.cashflow_category_id,
            amount=it.amount,
            comment=it.comment,
        )
        for it in payload.items
    ]
    session.add_all(new_rows)
    await session.commit()
    return (
        await session.execute(
            select(FinAllocation).where(FinAllocation.operation_id == op_id)
        )
    ).scalars().all()


# ───────────────────────────── ManualJournal ─────────────────────────────


@router.get("/journals", response_model=list[ManualJournalOut])
async def list_journals(
    session: Session,
    user: CurrentUser,
    _: Annotated[User, Depends(require_fin("view_journal"))],
    legal_entity_id: int | None = Query(default=None),
    journal_status: str | None = Query(default=None, alias="status"),
    date_from: date | None = Query(default=None),
    date_to: date | None = Query(default=None),
):
    # W (per-entity scope): без legal_entity_id выборка ограничивается доступными
    # юрлицами (defense-in-depth). NULL-scope grant → None → ограничения нет.
    stmt = select(FinManualJournal)
    if legal_entity_id is not None:
        stmt = stmt.where(FinManualJournal.legal_entity_id == legal_entity_id)
    else:
        scope = await accessible_legal_entity_ids(session, user, "view_journal")
        if scope is not None:
            stmt = stmt.where(FinManualJournal.legal_entity_id.in_(scope))
    if journal_status is not None:
        stmt = stmt.where(FinManualJournal.status == journal_status)
    if date_from is not None:
        stmt = stmt.where(FinManualJournal.date >= date_from)
    if date_to is not None:
        stmt = stmt.where(FinManualJournal.date <= date_to)
    stmt = stmt.order_by(FinManualJournal.date.desc(), FinManualJournal.id.desc())
    # N+1 fix: грузим строки журналов одним selectinload, не запросом на каждый журнал.
    stmt = stmt.options(selectinload(FinManualJournal.lines))
    journals = (await session.execute(stmt)).scalars().all()
    return [_journal_out_from_orm(j) for j in journals]


@router.get("/journals/{j_id}", response_model=ManualJournalOut)
async def get_journal(
    j_id: int,
    session: Session,
    user: CurrentUser,
    _: Annotated[User, Depends(require_fin("view_journal"))],
):
    j = await _get_or_404(session, FinManualJournal, j_id, "Журнал не найден")
    await _ensure_read_scope(session, user, j.legal_entity_id, "view_journal")  # W1
    return await _journal_with_lines(session, j)


@router.post("/journals", response_model=ManualJournalOut, status_code=201)
async def create_journal(
    payload: ManualJournalCreate,
    session: Session,
    user: CurrentUser,
    auto_post: bool = Query(default=True),
):
    """Создать ручной журнал (draft) и, если auto_post — сразу провести."""
    await _ensure_cap(session, user, "create_manual_journal", payload.legal_entity_id)
    if auto_post:
        await _ensure_cap(
            session, user, "post_manual_journal", payload.legal_entity_id
        )

    j = FinManualJournal(
        legal_entity_id=payload.legal_entity_id,
        date=payload.date,
        status="draft",
        memo=payload.memo,
        created_by_user_id=user.id,
    )
    j.lines = [
        FinManualJournalLine(
            account_gl_id=ln.account_gl_id,
            side=ln.side,
            amount=ln.amount,
            currency=ln.currency,
            counterparty_company_id=ln.counterparty_company_id,
            money_account_id=ln.money_account_id,
            cashflow_category_id=ln.cashflow_category_id,
            comment=ln.comment,
        )
        for ln in payload.lines
    ]
    session.add(j)
    await session.flush()

    if auto_post:
        # B7 CRIT-2: канал-независимый порог — журнал, подпадающий под сценарий с
        # этапами, нельзя проводить напрямую (минуя согласование). Журнал остаётся
        # draft; вызывающий оформляет расход через канал с согласованием.
        await _assert_journal_under_threshold(
            session, legal_entity_id=j.legal_entity_id, lines=j.lines,
        )
        try:
            await posting_db.post_manual_journal(
                session, j, posted_by_user_id=user.id
            )
        except (PostingError, FxRateMissing) as exc:
            await session.rollback()
            _raise_posting(exc)

    await audit_fin.log_fin(
        session, entity_type="fin_manual_journal", entity_id=j.id,
        user_id=user.id, action="post" if auto_post else "create",
        after=audit_fin.snapshot_manual_journal(j),
    )
    await session.commit()
    await session.refresh(j)
    return await _journal_with_lines(session, j)


@router.patch("/journals/{j_id}", response_model=ManualJournalOut)
async def patch_journal(
    j_id: int,
    payload: ManualJournalPatch,
    session: Session,
    user: CurrentUser,
):
    """W6 (J §6.4) — правка ЧЕРНОВИКА ручного журнала. posted/reversed → 409."""
    j = await _get_or_404(session, FinManualJournal, j_id, "Журнал не найден")
    await _ensure_cap(session, user, "create_manual_journal", j.legal_entity_id)
    try:
        assert_manual_journal_mutable(j.status)
    except PostingError as exc:
        _raise_posting(exc)

    if payload.date is not None:
        j.date = payload.date
    if payload.memo is not None:
        j.memo = payload.memo

    if payload.lines is not None:
        old = (
            await session.execute(
                select(FinManualJournalLine).where(
                    FinManualJournalLine.manual_journal_id == j_id
                )
            )
        ).scalars().all()
        for ln in old:
            await session.delete(ln)
        await session.flush()
        session.add_all(
            FinManualJournalLine(
                manual_journal_id=j_id,
                account_gl_id=ln.account_gl_id,
                side=ln.side,
                amount=ln.amount,
                currency=ln.currency,
                counterparty_company_id=ln.counterparty_company_id,
                money_account_id=ln.money_account_id,
                cashflow_category_id=ln.cashflow_category_id,
                comment=ln.comment,
            )
            for ln in payload.lines
        )

    await session.commit()
    await session.refresh(j)
    return await _journal_with_lines(session, j)


@router.post("/journals/{j_id}/post", response_model=ManualJournalOut)
async def post_journal_endpoint(
    j_id: int,
    session: Session,
    user: CurrentUser,
):
    j = await _get_or_404(session, FinManualJournal, j_id, "Журнал не найден")
    await _ensure_cap(session, user, "post_manual_journal", j.legal_entity_id)
    # подгрузить строки (post_manual_journal читает j.lines)
    j = await _journal_orm_with_lines(session, j_id)
    # B7 CRIT-2: тот же канал-независимый порог согласования, что и при create+auto_post.
    await _assert_journal_under_threshold(
        session, legal_entity_id=j.legal_entity_id, lines=j.lines,
    )
    before = audit_fin.snapshot_manual_journal(j)
    try:
        await posting_db.post_manual_journal(session, j, posted_by_user_id=user.id)
    except (PostingError, FxRateMissing) as exc:
        await session.rollback()
        _raise_posting(exc)
    await audit_fin.log_fin(
        session, entity_type="fin_manual_journal", entity_id=j.id,
        user_id=user.id, action="post",
        before=before, after=audit_fin.snapshot_manual_journal(j),
    )
    await session.commit()
    await session.refresh(j)
    return await _journal_with_lines(session, j)


@router.delete("/journals/{j_id}", status_code=204)
async def delete_journal(
    j_id: int,
    session: Session,
    user: CurrentUser,
):
    """Удалить ЧЕРНОВИК ручного журнала. posted/reversed → 409 (только сторно).

    Право create_manual_journal (то же, что и создание/правка черновика). Строки
    удаляются каскадом по FK (ON DELETE CASCADE на manual_journal_id)."""
    j = await _get_or_404(session, FinManualJournal, j_id, "Журнал не найден")
    await _ensure_cap(session, user, "create_manual_journal", j.legal_entity_id)
    try:
        assert_manual_journal_mutable(j.status)
    except PostingError as exc:
        _raise_posting(exc)
    await session.delete(j)
    await session.commit()


@router.post("/journals/{j_id}/reverse", response_model=ManualJournalOut)
async def reverse_journal_endpoint(
    j_id: int,
    payload: ReverseIn,
    session: Session,
    user: CurrentUser,
):
    """Сторнировать проведённый ручной журнал (через его journal_entry).

    Создаёт зеркальную сторно-проводку и переводит журнал в статус reversed.
    Право post_manual_journal (то же, что и проведение)."""
    j = await _get_or_404(session, FinManualJournal, j_id, "Журнал не найден")
    await _ensure_cap(session, user, "post_manual_journal", j.legal_entity_id)
    if j.journal_entry_id is None:
        raise HTTPException(409, "Журнал не проведён — нечего сторнировать")
    entry = await _get_or_404(
        session, FinJournalEntry, j.journal_entry_id, "Проводка не найдена"
    )
    before = audit_fin.snapshot_manual_journal(j)
    try:
        await posting_db.reverse_entry(
            session, entry, on_date=payload.on_date,
            posted_by_user_id=user.id, memo=payload.memo,
        )
    except (PostingError, FxRateMissing) as exc:
        await session.rollback()
        _raise_posting(exc)
    j.status = "reversed"
    await audit_fin.log_fin(
        session, entity_type="fin_manual_journal", entity_id=j.id,
        user_id=user.id, action="reverse",
        before=before, after=audit_fin.snapshot_manual_journal(j),
    )
    await session.commit()
    await session.refresh(j)
    return await _journal_with_lines(session, j)


# ───────────────────────────── Entry reverse (общий, и для adjustment) ─────────────────────────────


@router.post("/entries/{entry_id}/reverse", response_model=JournalEntryOut)
async def reverse_entry_endpoint(
    entry_id: int,
    payload: ReverseIn,
    session: Session,
    user: CurrentUser,
):
    entry = await _get_or_404(session, FinJournalEntry, entry_id, "Проводка не найдена")
    await _ensure_cap(session, user, "post_operation", entry.legal_entity_id)
    try:
        rev = await posting_db.reverse_entry(
            session, entry, on_date=payload.on_date,
            posted_by_user_id=user.id, memo=payload.memo,
        )
    except (PostingError, FxRateMissing) as exc:
        await session.rollback()
        _raise_posting(exc)
    await session.commit()
    return await _entry_with_lines(session, rev.id)


# ───────────────────────────── Reports ─────────────────────────────


@router.get("/balances", response_model=BalancesOut)
async def balances(
    session: Session,
    _: Annotated[User, Depends(require_fin("view_operations"))],
    legal_entity_id: int = Query(...),
    on_date: date | None = Query(default=None),
):
    from app.models import FinLedgerLine
    from app.services.finance import fx as fx_svc

    accounts = (
        await session.execute(
            select(FinMoneyAccount)
            .where(
                FinMoneyAccount.legal_entity_id == legal_entity_id,
                FinMoneyAccount.is_active.is_(True),
            )
            .order_by(FinMoneyAccount.sort_order, FinMoneyAccount.id)
        )
    ).scalars().all()

    if not accounts:
        return BalancesOut(legal_entity_id=legal_entity_id, on_date=on_date, rows=[])

    # Perf (N+1 fix): раньше на КАЖДЫЙ счёт делался отдельный money_account_balance
    # (свой SUM-запрос + свой FX-lookup) → 2·N запросов. Теперь:
    #   1. один GROUP BY money_account_id по ЖИВЫМ строкам (posted+reversed) для
    #      всех счетов юрлица сразу;
    #   2. функц.валюту юрлица берём один раз;
    #   3. FX-курс для initial_balance дёргаем ТОЛЬКО для счетов с ненулевым
    #      initial в валюте, отличной от функциональной (с кешем по валюте).
    # Семантика остатка идентична balance_svc.money_account_balance.
    acc_ids = [ma.id for ma in accounts]
    sum_q = (
        select(
            FinLedgerLine.money_account_id,
            sa_func.coalesce(sa_func.sum(FinLedgerLine.amount), Decimal("0")),
            sa_func.coalesce(sa_func.sum(FinLedgerLine.amount_func), Decimal("0")),
        )
        .join(FinJournalEntry, FinLedgerLine.journal_entry_id == FinJournalEntry.id)
        .where(
            FinLedgerLine.money_account_id.in_(acc_ids),
            FinJournalEntry.status.in_(balance_svc._LIVE_STATUSES),
        )
        .group_by(FinLedgerLine.money_account_id)
    )
    if on_date is not None:
        sum_q = sum_q.where(FinJournalEntry.date <= on_date)
    sums: dict[int, tuple[Decimal, Decimal]] = {
        ma_id: (amt, amt_func)
        for (ma_id, amt, amt_func) in (await session.execute(sum_q)).all()
    }

    func_ccy = (
        await session.execute(
            select(FinLegalEntity.functional_currency).where(
                FinLegalEntity.id == legal_entity_id
            )
        )
    ).scalar_one()
    rate_date = on_date or date.today()
    fx_cache: dict[str, Decimal] = {}

    rows: list[BalanceRow] = []
    for ma in accounts:
        amount_sum, func_sum = sums.get(ma.id, (Decimal("0"), Decimal("0")))
        initial = ma.initial_balance or Decimal("0")

        # initial в функц.валюте: 0 без FX; та же валюта — как есть; иначе строгий
        # курс на rate_date (кешируем по валюте счёта — счетов одной валюты много).
        if initial == Decimal("0") or ma.currency == func_ccy:
            initial_func = initial
        else:
            rate = fx_cache.get(ma.currency)
            if rate is None:
                rate = await fx_svc.get_rate_strict(
                    session, ma.currency, func_ccy, rate_date
                )
                fx_cache[ma.currency] = rate
            initial_func = balance_svc._q2(initial * rate)

        rows.append(
            BalanceRow(
                money_account_id=ma.id,
                account_name=ma.name,
                currency=ma.currency,
                amount=balance_svc._q2(initial + amount_sum),
                amount_func=balance_svc._q2(initial_func + func_sum),
            )
        )
    return BalancesOut(legal_entity_id=legal_entity_id, on_date=on_date, rows=rows)


@router.get("/reports/cashflow-simple", response_model=CashflowReportOut)
async def cashflow_simple(
    session: Session,
    _: Annotated[User, Depends(require_fin("view_reports"))],
    legal_entity_id: int = Query(...),
    date_from: date = Query(...),
    date_to: date = Query(...),
):
    rows = await cashflow_svc.cashflow_by_category(
        session, legal_entity_id, date_from=date_from, date_to=date_to
    )
    inflow, outflow, net = await cashflow_svc.cashflow_totals(
        session, legal_entity_id, date_from=date_from, date_to=date_to
    )
    return CashflowReportOut(
        legal_entity_id=legal_entity_id,
        date_from=date_from,
        date_to=date_to,
        rows=[
            CashflowRowOut(
                category_id=r.category_id,
                category_code=r.category_code,
                category_name=r.category_name,
                activity=r.activity,
                direction=r.direction,
                net_func=r.net_func,
            )
            for r in rows
        ],
        inflow=inflow,
        outflow=outflow,
        net=net,
    )


# ───────────────────────────── Reports ядра (Ф1) ─────────────────────────────


@router.get("/reports/pnl", response_model=PnlReportOut)
async def report_pnl(
    session: Session,
    _: Annotated[User, Depends(require_fin("view_reports"))],
    legal_entity_id: int = Query(...),
    date_from: date = Query(...),
    date_to: date = Query(...),
    basis: str = Query(default="accrual"),
):
    """P&L-lite: доходы (4xxx) − расходы (5xxx) по счетам за период (func-валюта).

    basis='accrual' (default) — по начислению (КАНОНИЧЕСКИЙ P&L: все income/expense строки;
    выручка признаётся инвойсом/recognition независимо от оплаты). basis='cash' — по
    деньгам (только income/expense из проводок с денежной строкой). Разница accrual−cash =
    неоплаченные начисления периода. Проекция POSTED-проводок; переводы/сторно исключены.
    """
    if basis not in (reports_svc.BASIS_ACCRUAL, reports_svc.BASIS_CASH):
        raise HTTPException(422, "basis должен быть 'accrual' или 'cash'")
    rep = await reports_svc.profit_and_loss(
        session, legal_entity_id, date_from=date_from, date_to=date_to, basis=basis
    )
    return PnlReportOut(
        legal_entity_id=legal_entity_id,
        date_from=date_from,
        date_to=date_to,
        basis=basis,
        income_lines=[_pnl_line_out(x) for x in rep.income_lines],
        expense_lines=[_pnl_line_out(x) for x in rep.expense_lines],
        total_income=rep.total_income,
        total_expense=rep.total_expense,
        profit=rep.profit,
    )


@router.get("/reports/trial-balance", response_model=TrialBalanceReportOut)
async def report_trial_balance(
    session: Session,
    _: Annotated[User, Depends(require_fin("view_reports"))],
    legal_entity_id: int = Query(...),
    on_date: date | None = Query(default=None),
):
    """Оборотно-сальдовая: дебет/кредит обороты + сальдо по GL-счёту (накопительно).

    Σ Дт = Σ Кт ⇔ GL сбалансирован (is_balanced; инвариант №2, детектор багов).
    """
    rep = await reports_svc.trial_balance_report(
        session, legal_entity_id, on_date=on_date
    )
    return TrialBalanceReportOut(
        legal_entity_id=legal_entity_id,
        on_date=on_date,
        rows=[
            TrialBalanceRowOut(
                account_id=r.account_id,
                account_code=r.account_code,
                account_name=r.account_name,
                account_type=r.account_type,
                debit=r.debit,
                credit=r.credit,
                balance=r.balance,
            )
            for r in rep.rows
        ],
        total_debit=rep.total_debit,
        total_credit=rep.total_credit,
        is_balanced=rep.is_balanced,
    )


@router.get("/reports/ar-ap", response_model=ArApReportOut)
async def report_ar_ap(
    session: Session,
    _: Annotated[User, Depends(require_fin("view_reports"))],
    legal_entity_id: int = Query(...),
    kind: str = Query(..., description="ar (дебиторка) | ap (кредиторка)"),
    on_date: date | None = Query(default=None),
):
    """AR/AP-сальдо по контрагенту из ledger lines (Ф1 — простой агрегат).

    ОГРАНИЧЕНИЕ: сальдо без привязки к инвойсам/возрасту долга — полные книги AR/AP
    появятся в Ф5 (fin_invoice/fin_vendor_bill).
    """
    if kind not in ("ar", "ap"):
        raise HTTPException(422, "kind должен быть 'ar' или 'ap'")
    rows = await reports_svc.receivables_payables(
        session, legal_entity_id, kind=kind, on_date=on_date
    )
    total = sum((r.balance for r in rows), Decimal("0"))
    return ArApReportOut(
        legal_entity_id=legal_entity_id,
        kind=kind,
        on_date=on_date,
        rows=[
            ArApRowOut(
                counterparty_company_id=r.counterparty_company_id,
                account_id=r.account_id,
                account_code=r.account_code,
                account_name=r.account_name,
                balance=r.balance,
            )
            for r in rows
        ],
        total=total,
    )


@router.get("/entries", response_model=EntriesListOut)
async def list_entries_endpoint(
    session: Session,
    _: Annotated[User, Depends(require_fin("view_journal"))],
    legal_entity_id: int | None = Query(default=None),
    account_gl_id: int | None = Query(default=None),
    entry_status: str | None = Query(default=None, alias="status"),
    date_from: date | None = Query(default=None),
    date_to: date | None = Query(default=None),
    limit: int = Query(default=100, ge=1, le=500),
    offset: int = Query(default=0, ge=0),
):
    """GL-журнал (read-only «главная книга»): листинг проводок со строками для аудита.

    W (per-entity scope, Ф2+): без legal_entity_id выборка не ограничена по юрлицам.
    Сейчас view_journal у фин-ролей — scope=NULL (все юрлица), на проде KZ+UZ оба
    видны всем фин-ролям по матрице прав, утечки нет. При введении per-entity прав
    этот эндпоинт ОБЯЗАН фильтровать по доступным юрлицам или требовать
    legal_entity_id. См. BACKLOG (Финансы).
    """
    if entry_status is not None and entry_status not in (
        "draft", "posted", "reversed"
    ):
        raise HTTPException(
            422, "status должен быть одним из draft, posted, reversed"
        )
    entries, total = await reports_svc.list_entries(
        session,
        legal_entity_id=legal_entity_id,
        account_gl_id=account_gl_id,
        status=entry_status,
        date_from=date_from,
        date_to=date_to,
        limit=limit,
        offset=offset,
    )
    return EntriesListOut(items=entries, total=total)


@router.get("/entries/{entry_id}", response_model=JournalEntryOut)
async def get_entry_endpoint(
    entry_id: int,
    session: Session,
    user: CurrentUser,
    _: Annotated[User, Depends(require_fin("view_journal"))],
):
    """Одна проводка со строками (главная книга, read-by-id)."""
    entry = await reports_svc.get_entry(session, entry_id)
    if entry is None:
        raise HTTPException(404, "Проводка не найдена")
    await _ensure_read_scope(session, user, entry.legal_entity_id, "view_journal")  # W1
    return entry


def _pnl_line_out(x) -> PnlLineOut:
    return PnlLineOut(
        account_id=x.account_id,
        account_code=x.account_code,
        account_name=x.account_name,
        account_type=x.account_type,
        amount=x.amount,
    )


# ───────────────────────────── Permissions CRUD (Ф1) ─────────────────────────────


@router.post("/permissions", response_model=PermissionOut, status_code=201)
async def create_permission(
    payload: PermissionCreate,
    session: Session,
    user: CurrentUser,
):
    """Добавить правило прав (role-дефолт ИЛИ точечный user-override). cap manage_settings."""
    await _ensure_cap(session, user, "manage_settings", payload.legal_entity_id)
    perm = FinPermission(
        role=payload.role,
        user_id=payload.user_id,
        legal_entity_id=payload.legal_entity_id,
        capability=payload.capability,
        allowed=payload.allowed,
    )
    session.add(perm)
    try:
        await session.flush()
    except IntegrityError as exc:
        await session.rollback()
        raise HTTPException(409, "Такое правило прав уже существует") from exc
    await audit_fin.log_fin(
        session, entity_type="fin_permission", entity_id=perm.id,
        user_id=user.id, action="create",
        after=audit_fin.snapshot_permission(perm),
    )
    await session.commit()
    await session.refresh(perm)
    return perm


@router.patch("/permissions/{perm_id}", response_model=PermissionOut)
async def patch_permission(
    perm_id: int,
    payload: PermissionPatch,
    session: Session,
    user: CurrentUser,
):
    """Изменить флаг allowed правила прав. cap manage_settings."""
    perm = await _get_or_404(session, FinPermission, perm_id, "Правило прав не найдено")
    await _ensure_cap(session, user, "manage_settings", perm.legal_entity_id)
    before = audit_fin.snapshot_permission(perm)
    perm.allowed = payload.allowed
    await audit_fin.log_fin(
        session, entity_type="fin_permission", entity_id=perm.id,
        user_id=user.id, action="update",
        before=before, after=audit_fin.snapshot_permission(perm),
    )
    await session.commit()
    await session.refresh(perm)
    return perm


@router.delete("/permissions/{perm_id}", status_code=204)
async def delete_permission(
    perm_id: int,
    session: Session,
    user: CurrentUser,
):
    """Удалить правило прав (вернётся дефолт роли). cap manage_settings."""
    perm = await _get_or_404(session, FinPermission, perm_id, "Правило прав не найдено")
    await _ensure_cap(session, user, "manage_settings", perm.legal_entity_id)
    await audit_fin.log_fin(
        session, entity_type="fin_permission", entity_id=perm.id,
        user_id=user.id, action="delete",
        before=audit_fin.snapshot_permission(perm),
    )
    await session.delete(perm)
    await session.commit()


# ───────────────────────────── Period locks ─────────────────────────────


@router.get("/periods", response_model=list[PeriodLockOut])
async def list_period_locks(
    session: Session,
    _: Annotated[User, Depends(require_fin("view_operations"))],
    legal_entity_id: int | None = Query(default=None, alias="entity"),
):
    stmt = select(FinPeriodLock)
    if legal_entity_id is not None:
        stmt = stmt.where(FinPeriodLock.legal_entity_id == legal_entity_id)
    stmt = stmt.order_by(
        FinPeriodLock.year.desc(), FinPeriodLock.month.desc()
    )
    return (await session.execute(stmt)).scalars().all()


@router.post("/periods/lock", response_model=PeriodLockOut, status_code=201)
async def create_period_lock(
    payload: PeriodLockIn,
    session: Session,
    user: CurrentUser,
):
    """Закрыть период (поставить lock) по (legal_entity, year, month)."""
    await _ensure_cap(session, user, "close_period", payload.legal_entity_id)
    lock = FinPeriodLock(
        legal_entity_id=payload.legal_entity_id,
        year=payload.year,
        month=payload.month,
        locked_by_user_id=user.id,
    )
    session.add(lock)
    try:
        await session.flush()
    except IntegrityError as exc:
        await session.rollback()
        raise HTTPException(
            409, f"Период {payload.year}-{payload.month:02d} уже закрыт"
        ) from exc
    await audit_fin.log_fin(
        session, entity_type="fin_period_lock", entity_id=lock.id,
        user_id=user.id, action="lock_period",
        after={
            "legal_entity_id": lock.legal_entity_id,
            "year": lock.year, "month": lock.month,
        },
    )
    await session.commit()
    await session.refresh(lock)
    return lock


@router.delete("/periods/lock", status_code=204)
async def delete_period_lock(
    payload: PeriodLockIn,
    session: Session,
    user: CurrentUser,
):
    """Открыть период (снять lock) по (legal_entity, year, month). Право close_period."""
    await _ensure_cap(session, user, "close_period", payload.legal_entity_id)
    lock = (
        await session.execute(
            select(FinPeriodLock).where(
                FinPeriodLock.legal_entity_id == payload.legal_entity_id,
                FinPeriodLock.year == payload.year,
                FinPeriodLock.month == payload.month,
            )
        )
    ).scalar_one_or_none()
    if lock is None:
        raise HTTPException(
            404, f"Период {payload.year}-{payload.month:02d} не закрыт"
        )
    await audit_fin.log_fin(
        session, entity_type="fin_period_lock", entity_id=lock.id,
        user_id=user.id, action="unlock_period",
        before={
            "legal_entity_id": lock.legal_entity_id,
            "year": lock.year, "month": lock.month,
        },
    )
    await session.delete(lock)
    await session.commit()


# ───────────────────────────── helpers ─────────────────────────────


async def _get_or_404(session: AsyncSession, model, obj_id: int, msg: str):
    obj = (
        await session.execute(select(model).where(model.id == obj_id))
    ).scalar_one_or_none()
    if obj is None:
        raise HTTPException(404, msg)
    return obj


async def _journal_orm_with_lines(
    session: AsyncSession, j_id: int
) -> FinManualJournal:
    """Загрузить журнал с его строками (для post — движок читает j.lines)."""
    j = await _get_or_404(session, FinManualJournal, j_id, "Журнал не найден")
    lines = (
        await session.execute(
            select(FinManualJournalLine).where(
                FinManualJournalLine.manual_journal_id == j_id
            )
        )
    ).scalars().all()
    j.lines = list(lines)
    return j


def _journal_out_from_orm(j: FinManualJournal) -> ManualJournalOut:
    """Собрать ManualJournalOut из ORM-журнала с УЖЕ загруженными (selectinload)
    строками — без дополнительного запроса (для листинга, N+1 fix)."""
    return ManualJournalOut.model_validate(
        {
            "id": j.id,
            "number": j.number,
            "legal_entity_id": j.legal_entity_id,
            "date": j.date,
            "status": j.status,
            "memo": j.memo,
            "journal_entry_id": j.journal_entry_id,
            "posted_at": j.posted_at,
            "created_at": j.created_at,
            "lines": j.lines,
        }
    )


async def _journal_with_lines(
    session: AsyncSession, j: FinManualJournal
) -> ManualJournalOut:
    lines = (
        await session.execute(
            select(FinManualJournalLine).where(
                FinManualJournalLine.manual_journal_id == j.id
            )
        )
    ).scalars().all()
    return ManualJournalOut.model_validate(
        {
            "id": j.id,
            "number": j.number,
            "legal_entity_id": j.legal_entity_id,
            "date": j.date,
            "status": j.status,
            "memo": j.memo,
            "journal_entry_id": j.journal_entry_id,
            "posted_at": j.posted_at,
            "created_at": j.created_at,
            "lines": lines,
        }
    )


async def _entry_with_lines(session: AsyncSession, entry_id: int) -> JournalEntryOut:
    entry = await _get_or_404(
        session, FinJournalEntry, entry_id, "Проводка не найдена"
    )
    from app.models import FinLedgerLine

    lines = (
        await session.execute(
            select(FinLedgerLine).where(FinLedgerLine.journal_entry_id == entry_id)
        )
    ).scalars().all()
    return JournalEntryOut.model_validate(
        {
            "id": entry.id,
            "legal_entity_id": entry.legal_entity_id,
            "date": entry.date,
            "kind": entry.kind,
            "status": entry.status,
            "source": entry.source,
            "source_ref_id": entry.source_ref_id,
            "reverses_entry_id": entry.reverses_entry_id,
            "func_currency": entry.func_currency,
            "memo": entry.memo,
            "posted_at": entry.posted_at,
            "lines": lines,
        }
    )


# ═════════════════════════ Ф2: согласование / реестр / заявки ═════════════════════════


def _raise_phase2(exc: Exception) -> NoReturn:
    """Перевести исключение флоу Ф2 в HTTPException (409/422/403). Всегда бросает."""
    code, detail = phase2_status(exc)
    raise HTTPException(code, detail) from exc


# ───────────────────────────── сценарии согласования ─────────────────────────────


@router.get("/approval-scenarios", response_model=list[ApprovalScenarioOut])
async def list_approval_scenarios(
    session: Session,
    _: Annotated[User, Depends(require_fin("manage_approval_scenarios"))],
    applies_to: str | None = Query(default=None),
):
    cond = []
    if applies_to is not None:
        cond.append(FinApprovalScenario.applies_to == applies_to)
    rows = (
        await session.execute(
            select(FinApprovalScenario)
            .where(*cond)
            .order_by(FinApprovalScenario.priority.desc(), FinApprovalScenario.id)
        )
    ).scalars().all()
    return rows


@router.post("/approval-scenarios", response_model=ApprovalScenarioOut, status_code=201)
async def create_approval_scenario(
    payload: ApprovalScenarioCreate,
    session: Session,
    user: CurrentUser,
):
    await _ensure_cap(
        session, user, "manage_approval_scenarios", payload.legal_entity_id
    )
    sc = FinApprovalScenario(
        name=payload.name,
        applies_to=payload.applies_to,
        op_type_id=payload.op_type_id,
        legal_entity_id=payload.legal_entity_id,
        min_amount=payload.min_amount,
        max_amount=payload.max_amount,
        stages=[s.model_dump() for s in payload.stages],
        priority=payload.priority,
        is_active=payload.is_active,
        created_by_user_id=user.id,
    )
    session.add(sc)
    await session.flush()
    await audit_fin.log_fin(
        session, entity_type="fin_approval_scenario", entity_id=sc.id,
        user_id=user.id, action="create", after=audit_fin.snapshot_scenario(sc),
    )
    await session.commit()
    await session.refresh(sc)
    return sc


@router.patch("/approval-scenarios/{sc_id}", response_model=ApprovalScenarioOut)
async def patch_approval_scenario(
    sc_id: int,
    payload: ApprovalScenarioPatch,
    session: Session,
    user: CurrentUser,
):
    sc = await _get_or_404(session, FinApprovalScenario, sc_id, "Сценарий не найден")
    await _ensure_cap(session, user, "manage_approval_scenarios", sc.legal_entity_id)
    before = audit_fin.snapshot_scenario(sc)
    data = payload.model_dump(exclude_unset=True)
    if "stages" in data and data["stages"] is not None:
        data["stages"] = [s.model_dump() if hasattr(s, "model_dump") else s for s in payload.stages]
    for k, v in data.items():
        setattr(sc, k, v)
    await audit_fin.log_fin(
        session, entity_type="fin_approval_scenario", entity_id=sc.id,
        user_id=user.id, action="update",
        before=before, after=audit_fin.snapshot_scenario(sc),
    )
    await session.commit()
    await session.refresh(sc)
    return sc


# ───────────────────────────── общий движок согласования (target) ─────────────────────────────


async def _scenario_for_target(
    session: AsyncSession,
    *,
    applies_to: str,
    op_type_id: int | None,
    legal_entity_id: int,
    amount: Decimal,
) -> FinApprovalScenario | None:
    return await fin_approval.select_scenario(
        session,
        applies_to=applies_to,
        op_type_id=op_type_id,
        legal_entity_id=legal_entity_id,
        amount=amount,
    )


async def _auto_approve_without_scenario(session: AsyncSession) -> bool:
    """Глобальный флаг: разрешён ли авто-апрув при отсутствии сценария (CRITICAL #1).

    Default FALSE (нет настроек = безопасно): без сценария согласование невозможно → 422.
    Только явный opt-out владельца (fin_settings.auto_approve_without_scenario=True)
    допускает проведение расхода без единого голоса.
    """
    settings = (
        await session.execute(select(FinSettings).limit(1))
    ).scalar_one_or_none()
    return bool(settings and settings.auto_approve_without_scenario)


def _no_scenario_422(kind: str) -> NoReturn:
    raise HTTPException(
        422,
        f"Сценарий согласования не настроен для этого {kind} (типа операции/юрлица). "
        "Создайте сценарий или включите авто-апрув без сценария в настройках модуля.",
    )


async def _approval_summary_payload(
    session: AsyncSession,
    *,
    approvable_kind: str,
    approvable_id: int,
    scenario_id: int | None,
) -> ApprovalSummaryOut:
    scenario = None
    if scenario_id is not None:
        scenario = (
            await session.execute(
                select(FinApprovalScenario).where(FinApprovalScenario.id == scenario_id)
            )
        ).scalar_one_or_none()
    summary = await fin_approval.approval_summary(
        session,
        approvable_kind=approvable_kind,
        approvable_id=approvable_id,
        scenario=scenario,
    )
    votes = (
        await session.execute(
            select(FinApproval)
            .where(
                FinApproval.approvable_kind == approvable_kind,
                FinApproval.approvable_id == approvable_id,
            )
            .order_by(FinApproval.stage_order, FinApproval.id)
        )
    ).scalars().all()
    return ApprovalSummaryOut(
        status=summary["status"],
        active_stage=summary["active_stage"],
        total_stages=summary["total_stages"],
        stages=summary["stages"],
        scenario_id=summary["scenario_id"],
        votes=[ApprovalVoteOut.model_validate(v) for v in votes],
    )


async def _current_scenario_id(
    session: AsyncSession, approvable_kind: str, approvable_id: int
) -> int | None:
    row = (
        await session.execute(
            select(FinApproval.scenario_id)
            .where(
                FinApproval.approvable_kind == approvable_kind,
                FinApproval.approvable_id == approvable_id,
            )
            .limit(1)
        )
    ).first()
    return row[0] if row else None


# ─────────── гейт согласования прямых операций/журналов (B7 CRIT-2) ───────────


async def _operation_approval_gate(
    session: AsyncSession,
    *,
    op_type_id: int | None,
    legal_entity_id: int,
    amount: Decimal,
) -> FinApprovalScenario | None:
    """Канал-независимый порог согласования (B7 CRIT-2).

    Прямые операции (приход/расход/перевод) и ручные журналы постились мгновенно по
    одному праву `post_operation`/`post_manual_journal`, минуя порог суммы — бухгалтер/
    cfo обходил согласование, выбрав прямой канал вместо заявки. Тут перед проводкой мы
    проверяем applies_to='operation'-сценарий тем же `select_scenario` (op_type+сумма+
    юрлицо), что и заявки.

    Возвращает сценарий, ТРЕБУЮЩИЙ подписи (есть валидные этапы) → вызывающий обязан
    отправить операцию в согласование, а не постить. None — если сценарий не найден ИЛИ
    найден, но без этапов (авто-апрув): SAFE-BY-DEFAULT — рутинные операции без
    настроенного порога проводятся мгновенно как раньше, новых трений нет.
    """
    scenario = await fin_approval.select_scenario(
        session,
        applies_to="operation",
        op_type_id=op_type_id,
        legal_entity_id=legal_entity_id,
        amount=amount,
    )
    if scenario is None:
        return None
    if not fin_approval.scenario_requires_signoff(scenario.stages):
        return None  # сценарий без этапов = авто-апрув → постим сразу
    return scenario


async def _gate_operation_into_approval(
    session: AsyncSession,
    op: FinOperation,
    scenario: FinApprovalScenario,
    *,
    user: User,
) -> None:
    """Перевести операцию в on_hold и запустить согласование (вместо мгновенной проводки)."""
    op.status = "on_hold"
    await session.flush()
    await fin_approval.start_approval(
        session, approvable_kind="operation", approvable_id=op.id, scenario=scenario,
    )


def _journal_over_threshold_422() -> NoReturn:
    """B7 CRIT-2: ручной журнал превышает порог согласования — прямой постинг запрещён."""
    raise HTTPException(
        422,
        "Сумма ручного журнала подпадает под сценарий согласования (порог суммы). "
        "Проведение напрямую запрещено — оформите расход через заявку/операцию, "
        "поддерживающую согласование, либо измените сценарий/порог в настройках модуля.",
    )


async def _assert_journal_under_threshold(
    session: AsyncSession,
    *,
    legal_entity_id: int,
    lines: list,
) -> None:
    """Гейт ручного журнала (B7 CRIT-2): если Σ-дебет подпадает под operation-сценарий с
    этапами → 422 (нельзя постить мимо согласования). Нет сценария / без этапов → ok.

    У журнала нет op_type → подбираем сценарии с op_type_id IS NULL («для всех типов»).
    Safe-by-default: рутинные журналы без настроенного порога проводятся как раньше.
    """
    amount = fin_approval.journal_gross_debit(lines)
    scenario = await _operation_approval_gate(
        session, op_type_id=None, legal_entity_id=legal_entity_id, amount=amount,
    )
    if scenario is not None:
        _journal_over_threshold_422()


# ───────────────────────────── заявки ─────────────────────────────


@router.get("/requests", response_model=list[RequestOut])
async def list_requests(
    session: Session,
    user: CurrentUser,
    legal_entity_id: int | None = Query(default=None),
    status_f: str | None = Query(default=None, alias="status"),
    request_type: str | None = Query(default=None),
):
    # view_operations гейтит доступ к модулю; manager без view_all_operations видит свои.
    if not await fin_can(session, user, "view_operations", legal_entity_id=legal_entity_id):
        raise HTTPException(403, "Нет доступа к заявкам")
    cond = []
    if legal_entity_id is not None:
        cond.append(FinRequest.legal_entity_id == legal_entity_id)
    else:
        scope = await accessible_legal_entity_ids(session, user, "view_operations")
        if scope is not None:
            cond.append(FinRequest.legal_entity_id.in_(scope))
    if status_f is not None:
        cond.append(FinRequest.status == status_f)
    if request_type is not None:
        cond.append(FinRequest.request_type == request_type)
    if not await fin_can(session, user, "view_all_operations", legal_entity_id=legal_entity_id):
        cond.append(FinRequest.requester_user_id == user.id)
    rows = (
        await session.execute(
            select(FinRequest).where(*cond).order_by(FinRequest.id.desc())
        )
    ).scalars().all()
    return rows


@router.get("/requests/{req_id}", response_model=RequestOut)
async def get_request(req_id: int, session: Session, user: CurrentUser):
    req = await _get_or_404(session, FinRequest, req_id, "Заявка не найдена")
    await _ensure_read_scope(session, user, req.legal_entity_id)
    if (
        not await fin_can(session, user, "view_all_operations", legal_entity_id=req.legal_entity_id)
        and req.requester_user_id != user.id
    ):
        raise HTTPException(403, "Нет доступа к этой заявке")
    return req


@router.post("/requests", response_model=RequestOut, status_code=201)
async def create_request(payload: RequestCreate, session: Session, user: CurrentUser):
    await _ensure_cap(session, user, "create_request", payload.legal_entity_id)
    req = FinRequest(
        request_type=payload.request_type,
        legal_entity_id=payload.legal_entity_id,
        requester_user_id=user.id,
        amount=payload.amount,
        currency=payload.currency,
        op_type_id=payload.op_type_id,
        counterparty_company_id=payload.counterparty_company_id,
        payee_user_id=payload.payee_user_id,
        cashflow_category_id=payload.cashflow_category_id,
        period_year=payload.period_year,
        period_month=payload.period_month,
        desired_date=payload.desired_date,
        description=payload.description,
        status="draft",
    )
    session.add(req)
    await session.flush()
    await audit_fin.log_fin(
        session, entity_type="fin_request", entity_id=req.id,
        user_id=user.id, action="create", after=audit_fin.snapshot_request(req),
    )
    await session.commit()
    await session.refresh(req)
    return req


@router.patch("/requests/{req_id}", response_model=RequestOut)
async def patch_request(
    req_id: int, payload: RequestPatch, session: Session, user: CurrentUser
):
    req = await _get_or_404(session, FinRequest, req_id, "Заявка не найдена")
    await _ensure_cap(session, user, "create_request", req.legal_entity_id)
    if req.requester_user_id != user.id and not await fin_can(
        session, user, "manage_registry", legal_entity_id=req.legal_entity_id
    ):
        raise HTTPException(403, "Править можно только свою заявку")
    try:
        requests_svc.assert_editable(req.status)
    except requests_svc.RequestError as exc:
        _raise_phase2(exc)
    for k, v in payload.model_dump(exclude_unset=True).items():
        setattr(req, k, v)
    await session.commit()
    await session.refresh(req)
    return req


@router.post("/requests/{req_id}/submit", response_model=ApprovalSummaryOut)
async def submit_request(req_id: int, session: Session, user: CurrentUser):
    """Подать заявку на согласование. Выбирает сценарий; нет сценария → авто-approved."""
    req = await _get_or_404(session, FinRequest, req_id, "Заявка не найдена")
    await _ensure_cap(session, user, "create_request", req.legal_entity_id)
    if req.requester_user_id != user.id and not await fin_can(
        session, user, "manage_registry", legal_entity_id=req.legal_entity_id
    ):
        raise HTTPException(403, "Подать можно только свою заявку")
    try:
        requests_svc.assert_submittable(req.status)
    except requests_svc.RequestError as exc:
        _raise_phase2(exc)

    scenario = await _scenario_for_target(
        session, applies_to="request", op_type_id=req.op_type_id,
        legal_entity_id=req.legal_entity_id, amount=req.amount,
    )
    # CRITICAL #1: нет сценария → авто-апрув ТОЛЬКО при явном opt-out владельца, иначе 422.
    if scenario is None and not await _auto_approve_without_scenario(session):
        _no_scenario_422("типа заявки")

    before = audit_fin.snapshot_request(req)
    requests_svc.mark_submitted(req)
    scenario_id = None
    if scenario is not None:
        status_overall = await fin_approval.start_approval(
            session, approvable_kind="request", approvable_id=req.id, scenario=scenario,
        )
        scenario_id = scenario.id
        if status_overall == "approved":  # сценарий без этапов = авто
            requests_svc.mark_approved(req)
    else:
        requests_svc.mark_approved(req)  # opt-out: авто-апрув без согласования

    await audit_fin.log_fin(
        session, entity_type="fin_request", entity_id=req.id,
        user_id=user.id, action="submit",
        before=before, after=audit_fin.snapshot_request(req),
    )
    await session.commit()
    return await _approval_summary_payload(
        session, approvable_kind="request", approvable_id=req.id, scenario_id=scenario_id,
    )


@router.get("/requests/{req_id}/approval", response_model=ApprovalSummaryOut)
async def request_approval_summary(req_id: int, session: Session, user: CurrentUser):
    req = await _get_or_404(session, FinRequest, req_id, "Заявка не найдена")
    await _ensure_read_scope(session, user, req.legal_entity_id)
    scenario_id = await _current_scenario_id(session, "request", req.id)
    return await _approval_summary_payload(
        session, approvable_kind="request", approvable_id=req.id, scenario_id=scenario_id,
    )


@router.post("/requests/{req_id}/decision", response_model=ApprovalSummaryOut)
async def decide_request(
    req_id: int, payload: ApprovalDecisionIn, session: Session, user: CurrentUser
):
    """Голос согласанта по заявке. При завершении сценария → approved/rejected."""
    req = await _get_or_404(session, FinRequest, req_id, "Заявка не найдена")
    await _ensure_cap(session, user, "approve", req.legal_entity_id)
    # CRITICAL #2: нельзя согласовывать собственную заявку (разделение деньги ↔ власть).
    if not fin_approval.can_decide(user.id, req.requester_user_id):
        raise HTTPException(403, "Нельзя согласовывать собственную заявку")
    scenario_id = await _current_scenario_id(session, "request", req.id)
    if scenario_id is None:
        raise HTTPException(422, "По заявке нет активного согласования")
    scenario = await _get_or_404(
        session, FinApprovalScenario, scenario_id, "Сценарий не найден"
    )
    before = audit_fin.snapshot_request(req)
    try:
        status_overall = await fin_approval.record_decision(
            session, approvable_kind="request", approvable_id=req.id,
            scenario=scenario, user_id=user.id,
            decision=payload.decision, comment=payload.comment,
        )
    except fin_approval.ApprovalError as exc:
        await session.rollback()
        _raise_phase2(exc)
    if status_overall == "approved":
        requests_svc.mark_approved(req)
    elif status_overall == "rejected":
        requests_svc.mark_rejected(req, payload.comment)
    await audit_fin.log_fin(
        session, entity_type="fin_request", entity_id=req.id,
        user_id=user.id, action="approve_decision",
        before=before, after=audit_fin.snapshot_request(req),
    )
    await session.commit()
    return await _approval_summary_payload(
        session, approvable_kind="request", approvable_id=req.id, scenario_id=scenario_id,
    )


@router.post("/requests/{req_id}/fulfill", response_model=RequestOut)
async def fulfill_request(
    req_id: int, payload: RequestFulfillIn, session: Session, user: CurrentUser
):
    """Конвертировать approved-заявку в расходную операцию (бухгалтер) и провести."""
    req = await _get_or_404(session, FinRequest, req_id, "Заявка не найдена")
    await _ensure_cap(session, user, "fulfill_request", req.legal_entity_id)
    # B8: row-lock на заявку + перечитать актуальный status/resulting_operation_id ПОД
    # локом перед assert_fulfillable. Без этого два параллельных fulfill при scale=2
    # читали STALE 'approved' без resulting_operation_id и оба строили+проводили операцию
    # (двойная оплата по одной заявке). Второй конкурент теперь ждёт COMMIT первого,
    # видит resulting_operation_id уже проставленным и отбивается (assert_fulfillable).
    await session.execute(
        select(FinRequest.id).where(FinRequest.id == req.id).with_for_update()
    )
    await session.refresh(req, attribute_names=["status", "resulting_operation_id"])
    try:
        requests_svc.assert_fulfillable(req.status, req.resulting_operation_id)
    except requests_svc.RequestError as exc:
        _raise_phase2(exc)
    before = audit_fin.snapshot_request(req)
    try:
        op = await requests_svc.build_operation_from_request(
            session, req,
            account_from_id=payload.account_from_id,
            op_date=payload.op_date,
            op_type_id=payload.op_type_id,
            created_by_user_id=user.id,
        )
    except requests_svc.RequestError as exc:
        await session.rollback()
        _raise_phase2(exc)

    if payload.auto_post:
        await _ensure_cap(session, user, "post_operation", req.legal_entity_id)
        try:
            await posting_db.post_operation(session, op, posted_by_user_id=user.id)
        except (PostingError, FxRateMissing) as exc:
            await session.rollback()
            _raise_posting(exc)
        requests_svc.mark_paid(req, op.id)  # post_operation тоже ставит paid (идемпотентно)
    else:
        # связь есть, статус paid поставит post_operation в момент фактической проводки (W3)
        req.resulting_operation_id = op.id

    await audit_fin.log_fin(
        session, entity_type="fin_operation", entity_id=op.id,
        user_id=user.id, action="post" if payload.auto_post else "create",
        after=audit_fin.snapshot_operation(op),
    )
    await audit_fin.log_fin(
        session, entity_type="fin_request", entity_id=req.id,
        user_id=user.id, action="fulfill",
        before=before, after=audit_fin.snapshot_request(req),
    )
    await session.commit()
    await session.refresh(req)
    return req


@router.post("/requests/{req_id}/cancel", response_model=RequestOut)
async def cancel_request(req_id: int, session: Session, user: CurrentUser):
    req = await _get_or_404(session, FinRequest, req_id, "Заявка не найдена")
    await _ensure_cap(session, user, "create_request", req.legal_entity_id)
    if req.requester_user_id != user.id and not await fin_can(
        session, user, "manage_registry", legal_entity_id=req.legal_entity_id
    ):
        raise HTTPException(403, "Отменить можно только свою заявку")
    try:
        requests_svc.assert_cancellable(req.status)
    except requests_svc.RequestError as exc:
        _raise_phase2(exc)
    before = audit_fin.snapshot_request(req)
    req.status = "cancelled"
    await audit_fin.log_fin(
        session, entity_type="fin_request", entity_id=req.id,
        user_id=user.id, action="update",
        before=before, after=audit_fin.snapshot_request(req),
    )
    await session.commit()
    await session.refresh(req)
    return req


# ───────────────────────────── реестр платежей ─────────────────────────────


async def _registry_detail(
    session: AsyncSession, registry: FinPaymentRegistry
) -> RegistryDetailOut:
    members = await registry_svc.registry_members(session, registry.id)
    total = sum((m.amount for m in members), Decimal("0"))
    base = RegistryOut.model_validate(registry)
    return RegistryDetailOut(
        **base.model_dump(),
        items=[OperationOut.model_validate(m) for m in members],
        total_amount=total,
    )


@router.get("/registries", response_model=list[RegistryOut])
async def list_registries(
    session: Session,
    user: CurrentUser,
    _: Annotated[User, Depends(require_fin("manage_registry"))],
    legal_entity_id: int | None = Query(default=None),
    approval_status: str | None = Query(default=None),
):
    cond = []
    if legal_entity_id is not None:
        cond.append(FinPaymentRegistry.legal_entity_id == legal_entity_id)
    else:
        scope = await accessible_legal_entity_ids(session, user, "manage_registry")
        if scope is not None:
            cond.append(FinPaymentRegistry.legal_entity_id.in_(scope))
    if approval_status is not None:
        cond.append(FinPaymentRegistry.approval_status == approval_status)
    rows = (
        await session.execute(
            select(FinPaymentRegistry).where(*cond).order_by(FinPaymentRegistry.id.desc())
        )
    ).scalars().all()
    return rows


@router.get("/registries/{reg_id}", response_model=RegistryDetailOut)
async def get_registry(reg_id: int, session: Session, user: CurrentUser):
    reg = await _get_or_404(session, FinPaymentRegistry, reg_id, "Реестр не найден")
    await _ensure_read_scope(session, user, reg.legal_entity_id, "manage_registry")
    return await _registry_detail(session, reg)


@router.post("/registries", response_model=RegistryOut, status_code=201)
async def create_registry(payload: RegistryCreate, session: Session, user: CurrentUser):
    await _ensure_cap(session, user, "manage_registry", payload.legal_entity_id)
    reg = FinPaymentRegistry(
        legal_entity_id=payload.legal_entity_id,
        source_account_id=payload.source_account_id,
        registry_date=payload.registry_date,
        title=payload.title,
        comment=payload.comment,
        approval_status="draft",
        payment_status="new",
        created_by_user_id=user.id,
    )
    session.add(reg)
    await session.flush()
    await audit_fin.log_fin(
        session, entity_type="fin_payment_registry", entity_id=reg.id,
        user_id=user.id, action="create", after=audit_fin.snapshot_registry(reg),
    )
    await session.commit()
    await session.refresh(reg)
    return reg


@router.patch("/registries/{reg_id}", response_model=RegistryOut)
async def patch_registry(
    reg_id: int, payload: RegistryPatch, session: Session, user: CurrentUser
):
    reg = await _get_or_404(session, FinPaymentRegistry, reg_id, "Реестр не найден")
    await _ensure_cap(session, user, "manage_registry", reg.legal_entity_id)
    try:
        registry_svc.assert_draft_composable(reg.approval_status)
    except registry_svc.RegistryError as exc:
        _raise_phase2(exc)
    for k, v in payload.model_dump(exclude_unset=True).items():
        setattr(reg, k, v)
    await session.commit()
    await session.refresh(reg)
    return reg


@router.post("/registries/{reg_id}/items", response_model=RegistryDetailOut)
async def add_registry_items(
    reg_id: int, payload: RegistryItemsIn, session: Session, user: CurrentUser
):
    """Добавить операции в реестр (только draft). Валидация: expense + один счёт."""
    reg = await _get_or_404(session, FinPaymentRegistry, reg_id, "Реестр не найден")
    await _ensure_cap(session, user, "manage_registry", reg.legal_entity_id)
    try:
        registry_svc.assert_draft_composable(reg.approval_status)
    except registry_svc.RegistryError as exc:
        _raise_phase2(exc)
    ops = (
        await session.execute(
            select(FinOperation).where(FinOperation.id.in_(payload.operation_ids))
        )
    ).scalars().all()
    found = {o.id for o in ops}
    missing = set(payload.operation_ids) - found
    if missing:
        raise HTTPException(404, f"Операции не найдены: {sorted(missing)}")
    for op in ops:
        try:
            registry_svc.validate_member(
                registry_svc.MemberRow(
                    id=op.id, legal_entity_id=op.legal_entity_id,
                    direction=op.direction, account_from_id=op.account_from_id,
                    status=op.status, registry_id=op.registry_id, amount=op.amount,
                ),
                registry_id=reg.id,
                legal_entity_id=reg.legal_entity_id,
                source_account_id=reg.source_account_id,
            )
        except registry_svc.RegistryError as exc:
            _raise_phase2(exc)
        op.registry_id = reg.id
    await registry_svc.refresh_payment_status(session, reg)
    await session.commit()
    await session.refresh(reg)
    return await _registry_detail(session, reg)


@router.delete("/registries/{reg_id}/items/{op_id}", response_model=RegistryDetailOut)
async def remove_registry_item(
    reg_id: int, op_id: int, session: Session, user: CurrentUser
):
    reg = await _get_or_404(session, FinPaymentRegistry, reg_id, "Реестр не найден")
    await _ensure_cap(session, user, "manage_registry", reg.legal_entity_id)
    try:
        registry_svc.assert_draft_composable(reg.approval_status)
    except registry_svc.RegistryError as exc:
        _raise_phase2(exc)
    op = await _get_or_404(session, FinOperation, op_id, "Операция не найдена")
    if op.registry_id != reg.id:
        raise HTTPException(409, "Операция не входит в этот реестр")
    op.registry_id = None
    await registry_svc.refresh_payment_status(session, reg)
    await session.commit()
    await session.refresh(reg)
    return await _registry_detail(session, reg)


@router.post("/registries/{reg_id}/submit", response_model=ApprovalSummaryOut)
async def submit_registry(reg_id: int, session: Session, user: CurrentUser):
    """Отправить реестр на согласование (состав замораживается). Нет сценария → approved."""
    reg = await _get_or_404(session, FinPaymentRegistry, reg_id, "Реестр не найден")
    await _ensure_cap(session, user, "manage_registry", reg.legal_entity_id)
    members = await registry_svc.registry_members(session, reg.id)
    try:
        registry_svc.assert_submittable(reg.approval_status, len(members))
    except registry_svc.RegistryError as exc:
        _raise_phase2(exc)

    total = sum((m.amount for m in members), Decimal("0"))
    scenario = await _scenario_for_target(
        session, applies_to="registry", op_type_id=None,
        legal_entity_id=reg.legal_entity_id, amount=total,
    )
    # CRITICAL #1: нет сценария → авто-апрув ТОЛЬКО при явном opt-out владельца, иначе 422.
    if scenario is None and not await _auto_approve_without_scenario(session):
        _no_scenario_422("реестра (типа операции/юрлица)")

    before = audit_fin.snapshot_registry(reg)
    reg.approval_status = "on_review"
    reg.submitted_at = datetime.now(UTC)
    scenario_id = None
    if scenario is not None:
        status_overall = await fin_approval.start_approval(
            session, approvable_kind="registry", approvable_id=reg.id, scenario=scenario,
        )
        scenario_id = scenario.id
        if status_overall == "approved":
            reg.approval_status = "approved"
            reg.approved_at = datetime.now(UTC)
    else:
        reg.approval_status = "approved"  # opt-out: авто-апрув без согласования
        reg.approved_at = datetime.now(UTC)

    await audit_fin.log_fin(
        session, entity_type="fin_payment_registry", entity_id=reg.id,
        user_id=user.id, action="submit",
        before=before, after=audit_fin.snapshot_registry(reg),
    )
    await session.commit()
    return await _approval_summary_payload(
        session, approvable_kind="registry", approvable_id=reg.id, scenario_id=scenario_id,
    )


@router.get("/registries/{reg_id}/approval", response_model=ApprovalSummaryOut)
async def registry_approval_summary(reg_id: int, session: Session, user: CurrentUser):
    reg = await _get_or_404(session, FinPaymentRegistry, reg_id, "Реестр не найден")
    await _ensure_read_scope(session, user, reg.legal_entity_id, "manage_registry")
    scenario_id = await _current_scenario_id(session, "registry", reg.id)
    return await _approval_summary_payload(
        session, approvable_kind="registry", approvable_id=reg.id, scenario_id=scenario_id,
    )


@router.post("/registries/{reg_id}/decision", response_model=ApprovalSummaryOut)
async def decide_registry(
    reg_id: int, payload: ApprovalDecisionIn, session: Session, user: CurrentUser
):
    reg = await _get_or_404(session, FinPaymentRegistry, reg_id, "Реестр не найден")
    await _ensure_cap(session, user, "approve", reg.legal_entity_id)
    # CRITICAL #2: нельзя согласовывать собственный реестр (создатель ≠ согласант).
    if not fin_approval.can_decide(user.id, reg.created_by_user_id):
        raise HTTPException(403, "Нельзя согласовывать собственный реестр")
    scenario_id = await _current_scenario_id(session, "registry", reg.id)
    if scenario_id is None:
        raise HTTPException(422, "По реестру нет активного согласования")
    scenario = await _get_or_404(
        session, FinApprovalScenario, scenario_id, "Сценарий не найден"
    )
    before = audit_fin.snapshot_registry(reg)
    try:
        status_overall = await fin_approval.record_decision(
            session, approvable_kind="registry", approvable_id=reg.id,
            scenario=scenario, user_id=user.id,
            decision=payload.decision, comment=payload.comment,
        )
    except fin_approval.ApprovalError as exc:
        await session.rollback()
        _raise_phase2(exc)
    if status_overall == "approved":
        reg.approval_status = "approved"
        reg.approved_at = datetime.now(UTC)
    elif status_overall == "rejected":
        reg.approval_status = "rejected"
    await audit_fin.log_fin(
        session, entity_type="fin_payment_registry", entity_id=reg.id,
        user_id=user.id, action="approve_decision",
        before=before, after=audit_fin.snapshot_registry(reg),
    )
    await session.commit()
    return await _approval_summary_payload(
        session, approvable_kind="registry", approvable_id=reg.id, scenario_id=scenario_id,
    )


@router.post("/registries/{reg_id}/provision", response_model=RegistryDetailOut)
async def provision_registry(reg_id: int, session: Session, user: CurrentUser):
    """Массовое проведение реестра: провести все непроведённые расходные позиции."""
    reg = await _get_or_404(session, FinPaymentRegistry, reg_id, "Реестр не найден")
    await _ensure_cap(session, user, "manage_registry", reg.legal_entity_id)
    await _ensure_cap(session, user, "post_operation", reg.legal_entity_id)
    # WARNING #5: при scale=2 два provision могут гоняться → двойная проводка. Берём
    # row-lock на строку реестра (сериализуем provision); пере-читаем актуальный статус.
    await session.execute(
        select(FinPaymentRegistry.id)
        .where(FinPaymentRegistry.id == reg.id)
        .with_for_update()
    )
    await session.refresh(reg)
    try:
        registry_svc.assert_postable(reg.approval_status)
    except registry_svc.RegistryError as exc:
        _raise_phase2(exc)
    members = await registry_svc.registry_members(session, reg.id)
    posted_any = False
    for op in members:
        if op.status in ("posted", "reversed", "cancelled", "rejected"):
            continue
        try:
            await posting_db.post_operation(session, op, posted_by_user_id=user.id)
        except (PostingError, FxRateMissing) as exc:
            await session.rollback()
            _raise_posting(exc)
        await audit_fin.log_fin(
            session, entity_type="fin_operation", entity_id=op.id,
            user_id=user.id, action="post", after=audit_fin.snapshot_operation(op),
        )
        posted_any = True
    before = audit_fin.snapshot_registry(reg)
    await registry_svc.refresh_payment_status(session, reg)
    if posted_any:
        await audit_fin.log_fin(
            session, entity_type="fin_payment_registry", entity_id=reg.id,
            user_id=user.id, action="post",
            before=before, after=audit_fin.snapshot_registry(reg),
        )
    await session.commit()
    await session.refresh(reg)
    return await _registry_detail(session, reg)


# ═══════════════════════════════ Ф5: инвойсы / акты / вендор-счета ═══════════════════════════════


def _raise_phase5(exc: Exception) -> NoReturn:
    """Перевести исключение флоу Ф5 в HTTPException (409/422). Всегда бросает."""
    code, detail = phase5_status(exc)
    raise HTTPException(code, detail) from exc


async def _invoice_detail(session: AsyncSession, inv_id: int) -> FinInvoice:
    inv = (
        await session.execute(
            select(FinInvoice)
            .options(selectinload(FinInvoice.lines))
            .where(FinInvoice.id == inv_id)
        )
    ).scalar_one_or_none()
    if inv is None:
        raise HTTPException(404, "Счёт не найден")
    return inv


async def _apply_invoice_lines(session: AsyncSession, inv: FinInvoice, lines: list) -> None:
    """Заменить позиции инвойса (только draft) + посчитать суммы через calc_line.

    Суммы (net/vat/gross) считаются здесь по qty×price и ставке НДС — данные верны
    независимо от последующего recompute_invoice_lines (не полагаемся на «в лоб»).
    """
    calcs = []
    new_lines = []
    for li in lines:
        rate = await invoicing_svc._vat_rate_pct(session, li.vat_rate_id)
        c = invoicing_svc.calc_line(li.qty, li.unit_price, rate)
        calcs.append(c)
        new_lines.append(
            FinInvoiceLine(
                name=li.name,
                qty=li.qty,
                unit_price=li.unit_price,
                amount_net=c.amount_net,
                vat_rate_id=li.vat_rate_id,
                vat_amount=c.vat_amount,
                amount_gross=c.amount_gross,
                revenue_account_code=li.revenue_account_code,
                cashflow_category_id=li.cashflow_category_id,
                sort_order=li.sort_order,
            )
        )
    inv.lines = new_lines
    totals = invoicing_svc.sum_totals(calcs) if calcs else None
    inv.amount_net = totals.amount_net if totals else Decimal("0")
    inv.vat_amount = totals.vat_amount if totals else Decimal("0")
    inv.amount_gross = totals.amount_gross if totals else Decimal("0")


# ───────────────────────────── инвойсы ─────────────────────────────


@router.get("/invoices", response_model=list[InvoiceOut])
async def list_invoices(
    session: Session,
    user: CurrentUser,
    _: Annotated[User, Depends(require_fin("manage_invoices"))],
    legal_entity_id: int | None = Query(default=None),
    status_filter: str | None = Query(default=None, alias="status"),
    counterparty_company_id: int | None = Query(default=None),
    limit: int = Query(default=100, ge=1, le=500),
    offset: int = Query(default=0, ge=0),
):
    conds = []
    if legal_entity_id is not None:
        conds.append(FinInvoice.legal_entity_id == legal_entity_id)
    else:
        scope = await accessible_legal_entity_ids(session, user, "manage_invoices")
        if scope is not None:
            conds.append(FinInvoice.legal_entity_id.in_(scope))
    if status_filter is not None:
        conds.append(FinInvoice.status == status_filter)
    if counterparty_company_id is not None:
        conds.append(FinInvoice.counterparty_company_id == counterparty_company_id)
    rows = (
        await session.execute(
            select(FinInvoice).where(*conds)
            .order_by(FinInvoice.issue_date.desc(), FinInvoice.id.desc())
            .limit(limit).offset(offset)
        )
    ).scalars().all()
    return rows


@router.get("/invoices/{inv_id}", response_model=InvoiceDetailOut)
async def get_invoice(inv_id: int, session: Session, user: CurrentUser):
    inv = await _invoice_detail(session, inv_id)
    await _ensure_read_scope(session, user, inv.legal_entity_id, "manage_invoices")
    return inv


@router.post("/invoices", response_model=InvoiceDetailOut, status_code=201)
async def create_invoice(payload: InvoiceCreate, session: Session, user: CurrentUser):
    await _ensure_cap(session, user, "manage_invoices", payload.legal_entity_id)
    inv = FinInvoice(
        legal_entity_id=payload.legal_entity_id,
        counterparty_company_id=payload.counterparty_company_id,
        contact_id=payload.contact_id,
        deal_id=payload.deal_id,
        contract_id=payload.contract_id,
        subscription_id=payload.subscription_id,
        issue_date=payload.issue_date,
        due_date=payload.due_date,
        currency=payload.currency,
        revenue_account_code=payload.revenue_account_code,
        purpose=payload.purpose,
        created_by_user_id=user.id,
    )
    await _apply_invoice_lines(session, inv, payload.lines)
    session.add(inv)
    await session.flush()
    await audit_fin.log_fin(
        session, entity_type="fin_invoice", entity_id=inv.id,
        user_id=user.id, action="create", after=audit_fin.snapshot_invoice(inv),
    )
    await session.commit()
    return await _invoice_detail(session, inv.id)


@router.patch("/invoices/{inv_id}", response_model=InvoiceDetailOut)
async def patch_invoice(inv_id: int, payload: InvoicePatch, session: Session, user: CurrentUser):
    inv = await _invoice_detail(session, inv_id)
    await _ensure_cap(session, user, "manage_invoices", inv.legal_entity_id)
    try:
        invoicing_svc.assert_doc_editable(inv.status)
    except invoicing_svc.DocumentError as exc:
        _raise_phase5(exc)
    before = audit_fin.snapshot_invoice(inv)
    data = payload.model_dump(exclude_unset=True)
    new_lines = data.pop("lines", None)
    for k, v in data.items():
        setattr(inv, k, v)
    if new_lines is not None:
        await _apply_invoice_lines(session, inv, payload.lines)
        await session.flush()
    else:
        await invoicing_svc.recompute_invoice_lines(session, inv)
    await audit_fin.log_fin(
        session, entity_type="fin_invoice", entity_id=inv.id,
        user_id=user.id, action="update",
        before=before, after=audit_fin.snapshot_invoice(inv),
    )
    await session.commit()
    return await _invoice_detail(session, inv.id)


@router.delete("/invoices/{inv_id}", status_code=204)
async def delete_invoice(inv_id: int, session: Session, user: CurrentUser):
    inv = await _get_or_404(session, FinInvoice, inv_id, "Счёт не найден")
    await _ensure_cap(session, user, "manage_invoices", inv.legal_entity_id)
    try:
        invoicing_svc.assert_doc_editable(inv.status)  # удалять можно только draft
    except invoicing_svc.DocumentError as exc:
        _raise_phase5(exc)
    await audit_fin.log_fin(
        session, entity_type="fin_invoice", entity_id=inv.id,
        user_id=user.id, action="delete", before=audit_fin.snapshot_invoice(inv),
    )
    await session.delete(inv)
    await session.commit()


@router.post("/invoices/{inv_id}/issue", response_model=InvoiceDetailOut)
async def issue_invoice_ep(inv_id: int, session: Session, user: CurrentUser):
    """Выставить счёт → accrual-проводка (Дт AR / Кт выручка / Кт НДС output). Присваивает номер."""
    inv = await _invoice_detail(session, inv_id)
    await _ensure_cap(session, user, "manage_invoices", inv.legal_entity_id)
    if inv.number is None:
        inv.number = await numbering.next_number(
            session, doc_type="invoice", legal_entity_id=inv.legal_entity_id,
            year=inv.issue_date.year,
        )
    before = audit_fin.snapshot_invoice(inv)
    try:
        await invoicing_svc.issue_invoice(session, inv, posted_by_user_id=user.id)
    except (invoicing_svc.DocumentError, PostingError, FxRateMissing) as exc:
        await session.rollback()
        _raise_phase5(exc)
    await audit_fin.log_fin(
        session, entity_type="fin_invoice", entity_id=inv.id,
        user_id=user.id, action="issue",
        before=before, after=audit_fin.snapshot_invoice(inv),
    )
    await session.commit()
    return await _invoice_detail(session, inv.id)


@router.post("/invoices/{inv_id}/pay", response_model=InvoiceDetailOut)
async def pay_invoice_ep(
    inv_id: int, payload: DocPaymentIn, session: Session, user: CurrentUser
):
    """Оплата счёта клиентом → Дт деньги / Кт AR. Гасит дебиторку, обновляет статус."""
    inv = await _get_or_404(session, FinInvoice, inv_id, "Счёт не найден")
    await _ensure_cap(session, user, "manage_invoices", inv.legal_entity_id)
    await _ensure_cap(session, user, "post_operation", inv.legal_entity_id)
    before = audit_fin.snapshot_invoice(inv)
    try:
        await invoicing_svc.pay_invoice(
            session, inv,
            money_account_id=payload.money_account_id,
            amount=payload.amount, on_date=payload.on_date,
            cashflow_category_id=payload.cashflow_category_id,
            posted_by_user_id=user.id,
        )
    except (invoicing_svc.DocumentError, PostingError, FxRateMissing) as exc:
        await session.rollback()
        _raise_phase5(exc)
    await audit_fin.log_fin(
        session, entity_type="fin_invoice", entity_id=inv.id,
        user_id=user.id, action="pay",
        before=before, after=audit_fin.snapshot_invoice(inv),
    )
    await session.commit()
    return await _invoice_detail(session, inv.id)


@router.post("/invoices/{inv_id}/cancel", response_model=InvoiceDetailOut)
async def cancel_invoice_ep(inv_id: int, session: Session, user: CurrentUser):
    """Отменить счёт. draft → cancelled; issued без оплат → сторно проводки + cancelled."""
    inv = await _get_or_404(session, FinInvoice, inv_id, "Счёт не найден")
    await _ensure_cap(session, user, "manage_invoices", inv.legal_entity_id)
    before = audit_fin.snapshot_invoice(inv)
    try:
        await invoicing_svc.cancel_invoice(session, inv, posted_by_user_id=user.id)
    except (invoicing_svc.DocumentError, PostingError, FxRateMissing) as exc:
        await session.rollback()
        _raise_phase5(exc)
    await audit_fin.log_fin(
        session, entity_type="fin_invoice", entity_id=inv.id,
        user_id=user.id, action="cancel",
        before=before, after=audit_fin.snapshot_invoice(inv),
    )
    await session.commit()
    return await _invoice_detail(session, inv.id)


# ───────────────────────── рендер / подпись документов (Фаза A) ─────────────────────────


def _user_signature_name(user: User) -> str:
    """ФИО подписанта для блока подписи (имя + e-mail). Pure."""
    name = (getattr(user, "name", None) or "").strip()
    email = (getattr(user, "email", None) or "").strip()
    if name and email:
        return f"{name} ({email})"
    return name or email or f"user#{user.id}"


def _serve_finance_doc(kind: str, doc_id: int, version: int, number: str | None, fmt: str):
    """Отдать сгенерированный файл (pdf|docx) с диска. 404 если не сгенерирован."""
    if version is None or version <= 0:
        raise HTTPException(404, "Документ не сгенерирован — сначала вызовите /generate")
    if fmt == "pdf":
        path = doc_render_svc.doc_pdf_path(kind, doc_id, version)
        media = "application/pdf"
        ext = "pdf"
    else:
        path = doc_render_svc.doc_docx_path(kind, doc_id, version)
        media = "application/vnd.openxmlformats-officedocument.wordprocessingml.document"
        ext = "docx"
    if not path.exists():
        raise HTTPException(404, "Файл документа не найден на диске")
    label = "Счёт" if kind == "invoice" else "Акт"
    filename = f"{label} {number or doc_id}.{ext}"
    return FileResponse(str(path), media_type=media, filename=filename)


@router.post("/invoices/{inv_id}/generate", response_model=InvoiceDetailOut)
async def generate_invoice_doc_ep(inv_id: int, session: Session, user: CurrentUser):
    """Сгенерировать DOCX+PDF счёта (docxtpl → LibreOffice). Проставляет document_file_id
    (новая версия) и amount_in_words. Идемпотентно-версионно."""
    inv = await _invoice_detail(session, inv_id)
    await _ensure_cap(session, user, "manage_invoices", inv.legal_entity_id)
    if doc_render_svc.regen_blocked_for_signed(inv.signed_at):
        raise HTTPException(409, "Документ подписан, перегенерация запрещена")
    signer = None
    try:
        await doc_render_svc.generate_invoice_document(session, inv, signer_name=signer)
    except doc_render_svc.RenderUnavailable as exc:
        await session.rollback()
        raise HTTPException(422, str(exc)) from exc
    except RuntimeError as exc:  # soffice / конвертация упала
        await session.rollback()
        raise HTTPException(500, f"Не удалось отрендерить документ: {exc}") from exc
    await audit_fin.log_fin(
        session, entity_type="fin_invoice", entity_id=inv.id,
        user_id=user.id, action="update",
        after=audit_fin.snapshot_invoice(inv),
    )
    await session.commit()
    return await _invoice_detail(session, inv.id)


@router.get("/invoices/{inv_id}/document")
async def download_invoice_doc_ep(
    inv_id: int, session: Session, user: CurrentUser,
    fmt: str = Query(default="pdf", pattern="^(pdf|docx)$"),
):
    """Скачать сгенерированный файл счёта (pdf|docx)."""
    inv = await _get_or_404(session, FinInvoice, inv_id, "Счёт не найден")
    await _ensure_read_scope(session, user, inv.legal_entity_id, "manage_invoices")
    return _serve_finance_doc("invoice", inv.id, inv.document_file_id, inv.number, fmt)


@router.post("/invoices/{inv_id}/sign", response_model=InvoiceDetailOut)
async def sign_invoice_ep(inv_id: int, session: Session, user: CurrentUser):
    """Подписать счёт (approval-based). Проставляет signed_by/signed_at, перегенерирует
    PDF с блоком подписи. Подписанный счёт иммутабелен от правки. Только из issued+."""
    inv = await _invoice_detail(session, inv_id)
    await _ensure_cap(session, user, "manage_invoices", inv.legal_entity_id)
    if inv.status not in ("issued", "partially_paid", "paid"):
        raise HTTPException(409, f"Подписать можно только выставленный счёт (статус {inv.status!r})")
    if inv.signed_at is not None:
        raise HTTPException(409, "Счёт уже подписан")
    before = audit_fin.snapshot_invoice(inv)
    inv.signed_by_user_id = user.id
    inv.signed_at = datetime.now(UTC)
    # Перегенерируем документ с блоком подписи (если шаблон/soffice доступны).
    try:
        await doc_render_svc.generate_invoice_document(
            session, inv, signer_name=_user_signature_name(user)
        )
    except doc_render_svc.RenderUnavailable as exc:
        await session.rollback()
        raise HTTPException(422, str(exc)) from exc
    except RuntimeError as exc:
        await session.rollback()
        raise HTTPException(500, f"Не удалось отрендерить документ: {exc}") from exc
    await audit_fin.log_fin(
        session, entity_type="fin_invoice", entity_id=inv.id,
        user_id=user.id, action="confirm",
        before=before, after=audit_fin.snapshot_invoice(inv),
    )
    await session.commit()
    return await _invoice_detail(session, inv.id)


# ───────────────────────────── акты выполненных работ ─────────────────────────────


async def _act_detail(session: AsyncSession, act_id: int) -> FinAct:
    act = (
        await session.execute(
            select(FinAct).options(selectinload(FinAct.lines)).where(FinAct.id == act_id)
        )
    ).scalar_one_or_none()
    if act is None:
        raise HTTPException(404, "Акт не найден")
    return act


async def _apply_act_lines(session: AsyncSession, act: FinAct, lines: list) -> None:
    """Заменить позиции акта + пересчитать суммы (акт проводку НЕ постит)."""
    from decimal import Decimal as _D

    calcs = []
    new_lines = []
    for li in lines:
        rate = await invoicing_svc._vat_rate_pct(session, li.vat_rate_id)
        c = invoicing_svc.calc_line(li.qty, li.unit_price, rate)
        calcs.append(c)
        new_lines.append(
            FinActLine(
                name=li.name, qty=li.qty, unit_price=li.unit_price,
                amount_net=c.amount_net, vat_rate_id=li.vat_rate_id,
                vat_amount=c.vat_amount, amount_gross=c.amount_gross,
                sort_order=li.sort_order,
            )
        )
    act.lines = new_lines
    totals = invoicing_svc.sum_totals(calcs) if calcs else None
    act.amount_net = totals.amount_net if totals else _D("0")
    act.vat_amount = totals.vat_amount if totals else _D("0")
    act.amount_gross = totals.amount_gross if totals else _D("0")


@router.get("/acts", response_model=list[ActOut])
async def list_acts(
    session: Session,
    user: CurrentUser,
    _: Annotated[User, Depends(require_fin("manage_invoices"))],
    legal_entity_id: int | None = Query(default=None),
    invoice_id: int | None = Query(default=None),
    limit: int = Query(default=100, ge=1, le=500),
    offset: int = Query(default=0, ge=0),
):
    conds = []
    if legal_entity_id is not None:
        conds.append(FinAct.legal_entity_id == legal_entity_id)
    else:
        scope = await accessible_legal_entity_ids(session, user, "manage_invoices")
        if scope is not None:
            conds.append(FinAct.legal_entity_id.in_(scope))
    if invoice_id is not None:
        conds.append(FinAct.invoice_id == invoice_id)
    rows = (
        await session.execute(
            select(FinAct).where(*conds)
            .order_by(FinAct.act_date.desc(), FinAct.id.desc())
            .limit(limit).offset(offset)
        )
    ).scalars().all()
    return rows


@router.get("/acts/{act_id}", response_model=ActDetailOut)
async def get_act(act_id: int, session: Session, user: CurrentUser):
    act = await _act_detail(session, act_id)
    await _ensure_read_scope(session, user, act.legal_entity_id, "manage_invoices")
    return act


@router.post("/acts", response_model=ActDetailOut, status_code=201)
async def create_act(payload: ActCreate, session: Session, user: CurrentUser):
    await _ensure_cap(session, user, "manage_invoices", payload.legal_entity_id)
    act = FinAct(
        legal_entity_id=payload.legal_entity_id,
        counterparty_company_id=payload.counterparty_company_id,
        invoice_id=payload.invoice_id,
        contract_id=payload.contract_id,
        subscription_id=payload.subscription_id,
        act_date=payload.act_date,
        period_year=payload.period_year,
        period_month=payload.period_month,
        currency=payload.currency,
        purpose=payload.purpose,
        created_by_user_id=user.id,
    )
    session.add(act)
    await session.flush()
    await _apply_act_lines(session, act, payload.lines)
    await audit_fin.log_fin(
        session, entity_type="fin_act", entity_id=act.id,
        user_id=user.id, action="create", after=audit_fin.snapshot_act(act),
    )
    await session.commit()
    return await _act_detail(session, act.id)


@router.patch("/acts/{act_id}", response_model=ActDetailOut)
async def patch_act(act_id: int, payload: ActPatch, session: Session, user: CurrentUser):
    act = await _act_detail(session, act_id)
    await _ensure_cap(session, user, "manage_invoices", act.legal_entity_id)
    try:
        invoicing_svc.assert_doc_editable(act.status)
    except invoicing_svc.DocumentError as exc:
        _raise_phase5(exc)
    before = audit_fin.snapshot_act(act)
    data = payload.model_dump(exclude_unset=True)
    new_lines = data.pop("lines", None)
    for k, v in data.items():
        setattr(act, k, v)
    if new_lines is not None:
        await _apply_act_lines(session, act, payload.lines)
    await audit_fin.log_fin(
        session, entity_type="fin_act", entity_id=act.id,
        user_id=user.id, action="update",
        before=before, after=audit_fin.snapshot_act(act),
    )
    await session.commit()
    return await _act_detail(session, act.id)


@router.delete("/acts/{act_id}", status_code=204)
async def delete_act(act_id: int, session: Session, user: CurrentUser):
    act = await _get_or_404(session, FinAct, act_id, "Акт не найден")
    await _ensure_cap(session, user, "manage_invoices", act.legal_entity_id)
    try:
        invoicing_svc.assert_doc_editable(act.status)
    except invoicing_svc.DocumentError as exc:
        _raise_phase5(exc)
    await audit_fin.log_fin(
        session, entity_type="fin_act", entity_id=act.id,
        user_id=user.id, action="delete", before=audit_fin.snapshot_act(act),
    )
    await session.delete(act)
    await session.commit()


@router.post("/acts/{act_id}/issue", response_model=ActDetailOut)
async def issue_act_ep(act_id: int, session: Session, user: CurrentUser):
    """Выставить акт (draft→issued). Документ выполнения — проводку НЕ постит (выручка
    признаётся инвойсом). Присваивает номер."""
    act = await _act_detail(session, act_id)
    await _ensure_cap(session, user, "manage_invoices", act.legal_entity_id)
    try:
        invoicing_svc.assert_issuable(act.status)
    except invoicing_svc.DocumentError as exc:
        _raise_phase5(exc)
    if act.number is None:
        act.number = await numbering.next_number(
            session, doc_type="act", legal_entity_id=act.legal_entity_id,
            year=act.act_date.year,
        )
    before = audit_fin.snapshot_act(act)
    act.status = "issued"
    await audit_fin.log_fin(
        session, entity_type="fin_act", entity_id=act.id,
        user_id=user.id, action="issue",
        before=before, after=audit_fin.snapshot_act(act),
    )
    await session.commit()
    return await _act_detail(session, act.id)


@router.post("/acts/{act_id}/generate", response_model=ActDetailOut)
async def generate_act_doc_ep(act_id: int, session: Session, user: CurrentUser):
    """Сгенерировать DOCX+PDF акта (docxtpl → LibreOffice). Проставляет document_file_id
    (новая версия) и amount_in_words. Идемпотентно-версионно."""
    act = await _act_detail(session, act_id)
    await _ensure_cap(session, user, "manage_invoices", act.legal_entity_id)
    if doc_render_svc.regen_blocked_for_signed(act.signed_at):
        raise HTTPException(409, "Документ подписан, перегенерация запрещена")
    signer = None
    try:
        await doc_render_svc.generate_act_document(session, act, signer_name=signer)
    except doc_render_svc.RenderUnavailable as exc:
        await session.rollback()
        raise HTTPException(422, str(exc)) from exc
    except RuntimeError as exc:
        await session.rollback()
        raise HTTPException(500, f"Не удалось отрендерить документ: {exc}") from exc
    await audit_fin.log_fin(
        session, entity_type="fin_act", entity_id=act.id,
        user_id=user.id, action="update", after=audit_fin.snapshot_act(act),
    )
    await session.commit()
    return await _act_detail(session, act.id)


@router.get("/acts/{act_id}/document")
async def download_act_doc_ep(
    act_id: int, session: Session, user: CurrentUser,
    fmt: str = Query(default="pdf", pattern="^(pdf|docx)$"),
):
    """Скачать сгенерированный файл акта (pdf|docx)."""
    act = await _get_or_404(session, FinAct, act_id, "Акт не найден")
    await _ensure_read_scope(session, user, act.legal_entity_id, "manage_invoices")
    return _serve_finance_doc("act", act.id, act.document_file_id, act.number, fmt)


@router.post("/acts/{act_id}/sign", response_model=ActDetailOut)
async def sign_act_ep(act_id: int, session: Session, user: CurrentUser):
    """Подписать акт (approval-based, issued→signed). Проставляет signed_by/signed_at,
    перегенерирует PDF с блоком подписи. Подписанный акт иммутабелен."""
    act = await _act_detail(session, act_id)
    await _ensure_cap(session, user, "manage_invoices", act.legal_entity_id)
    if act.status != "issued":
        raise HTTPException(409, f"Подписать можно только выставленный акт (статус {act.status!r})")
    before = audit_fin.snapshot_act(act)
    act.status = "signed"
    act.signed_by_user_id = user.id
    act.signed_at = datetime.now(UTC)
    # Перегенерируем документ с блоком подписи (если шаблон/soffice доступны).
    try:
        await doc_render_svc.generate_act_document(
            session, act, signer_name=_user_signature_name(user)
        )
    except doc_render_svc.RenderUnavailable as exc:
        await session.rollback()
        raise HTTPException(422, str(exc)) from exc
    except RuntimeError as exc:
        await session.rollback()
        raise HTTPException(500, f"Не удалось отрендерить документ: {exc}") from exc
    await audit_fin.log_fin(
        session, entity_type="fin_act", entity_id=act.id,
        user_id=user.id, action="confirm",
        before=before, after=audit_fin.snapshot_act(act),
    )
    await session.commit()
    return await _act_detail(session, act.id)


@router.post("/acts/{act_id}/cancel", response_model=ActDetailOut)
async def cancel_act_ep(act_id: int, session: Session, user: CurrentUser):
    """Отменить акт (draft/issued/signed → cancelled). Проводок нет — статусный переход."""
    act = await _get_or_404(session, FinAct, act_id, "Акт не найден")
    await _ensure_cap(session, user, "manage_invoices", act.legal_entity_id)
    if act.status == "cancelled":
        raise HTTPException(409, "Акт уже отменён")
    before = audit_fin.snapshot_act(act)
    act.status = "cancelled"
    await audit_fin.log_fin(
        session, entity_type="fin_act", entity_id=act.id,
        user_id=user.id, action="cancel",
        before=before, after=audit_fin.snapshot_act(act),
    )
    await session.commit()
    return await _act_detail(session, act.id)


# ───────────────────────────── вендор-счета ─────────────────────────────


async def _bill_detail(session: AsyncSession, bill_id: int) -> FinVendorBill:
    bill = (
        await session.execute(
            select(FinVendorBill)
            .options(selectinload(FinVendorBill.lines))
            .where(FinVendorBill.id == bill_id)
        )
    ).scalar_one_or_none()
    if bill is None:
        raise HTTPException(404, "Вендор-счёт не найден")
    return bill


async def _apply_bill_lines(session: AsyncSession, bill: FinVendorBill, lines: list) -> None:
    """Заменить позиции вендор-счёта (только draft) + посчитать суммы через calc_line.

    Суммы (net/vat/gross) считаются здесь по qty×price и ставке НДС — данные верны
    независимо от последующего recompute_bill_lines (не выставляем amount_net/gross «в лоб»).
    """
    calcs = []
    new_lines = []
    for li in lines:
        rate = await invoicing_svc._vat_rate_pct(session, li.vat_rate_id)
        c = invoicing_svc.calc_line(li.qty, li.unit_price, rate)
        calcs.append(c)
        new_lines.append(
            FinVendorBillLine(
                name=li.name, qty=li.qty, unit_price=li.unit_price,
                amount_net=c.amount_net, vat_rate_id=li.vat_rate_id,
                vat_amount=c.vat_amount, amount_gross=c.amount_gross,
                expense_account_code=li.expense_account_code,
                cashflow_category_id=li.cashflow_category_id,
                sort_order=li.sort_order,
            )
        )
    bill.lines = new_lines
    totals = invoicing_svc.sum_totals(calcs) if calcs else None
    bill.amount_net = totals.amount_net if totals else Decimal("0")
    bill.vat_amount = totals.vat_amount if totals else Decimal("0")
    bill.amount_gross = totals.amount_gross if totals else Decimal("0")


@router.get("/vendor-bills", response_model=list[VendorBillOut])
async def list_vendor_bills(
    session: Session,
    user: CurrentUser,
    _: Annotated[User, Depends(require_fin("manage_vendor_bills"))],
    legal_entity_id: int | None = Query(default=None),
    status_filter: str | None = Query(default=None, alias="status"),
    supplier_company_id: int | None = Query(default=None),
    limit: int = Query(default=100, ge=1, le=500),
    offset: int = Query(default=0, ge=0),
):
    conds = []
    if legal_entity_id is not None:
        conds.append(FinVendorBill.legal_entity_id == legal_entity_id)
    else:
        scope = await accessible_legal_entity_ids(session, user, "manage_vendor_bills")
        if scope is not None:
            conds.append(FinVendorBill.legal_entity_id.in_(scope))
    if status_filter is not None:
        conds.append(FinVendorBill.status == status_filter)
    if supplier_company_id is not None:
        conds.append(FinVendorBill.supplier_company_id == supplier_company_id)
    rows = (
        await session.execute(
            select(FinVendorBill).where(*conds)
            .order_by(FinVendorBill.bill_date.desc(), FinVendorBill.id.desc())
            .limit(limit).offset(offset)
        )
    ).scalars().all()
    return rows


@router.get("/vendor-bills/{bill_id}", response_model=VendorBillDetailOut)
async def get_vendor_bill(bill_id: int, session: Session, user: CurrentUser):
    bill = await _bill_detail(session, bill_id)
    await _ensure_read_scope(session, user, bill.legal_entity_id, "manage_vendor_bills")
    return bill


@router.post("/vendor-bills", response_model=VendorBillDetailOut, status_code=201)
async def create_vendor_bill(payload: VendorBillCreate, session: Session, user: CurrentUser):
    await _ensure_cap(session, user, "manage_vendor_bills", payload.legal_entity_id)
    bill = FinVendorBill(
        legal_entity_id=payload.legal_entity_id,
        supplier_company_id=payload.supplier_company_id,
        bill_no=payload.bill_no,
        bill_date=payload.bill_date,
        due_date=payload.due_date,
        currency=payload.currency,
        expense_account_code=payload.expense_account_code,
        cashflow_category_id=payload.cashflow_category_id,
        purpose=payload.purpose,
        created_by_user_id=user.id,
    )
    await _apply_bill_lines(session, bill, payload.lines)
    session.add(bill)
    await session.flush()
    await audit_fin.log_fin(
        session, entity_type="fin_vendor_bill", entity_id=bill.id,
        user_id=user.id, action="create", after=audit_fin.snapshot_vendor_bill(bill),
    )
    await session.commit()
    return await _bill_detail(session, bill.id)


@router.patch("/vendor-bills/{bill_id}", response_model=VendorBillDetailOut)
async def patch_vendor_bill(
    bill_id: int, payload: VendorBillPatch, session: Session, user: CurrentUser
):
    bill = await _bill_detail(session, bill_id)
    await _ensure_cap(session, user, "manage_vendor_bills", bill.legal_entity_id)
    try:
        invoicing_svc.assert_doc_editable(bill.status)
    except invoicing_svc.DocumentError as exc:
        _raise_phase5(exc)
    before = audit_fin.snapshot_vendor_bill(bill)
    data = payload.model_dump(exclude_unset=True)
    new_lines = data.pop("lines", None)
    for k, v in data.items():
        setattr(bill, k, v)
    if new_lines is not None:
        await _apply_bill_lines(session, bill, payload.lines)
        await session.flush()
    else:
        await invoicing_svc.recompute_bill_lines(session, bill)
    await audit_fin.log_fin(
        session, entity_type="fin_vendor_bill", entity_id=bill.id,
        user_id=user.id, action="update",
        before=before, after=audit_fin.snapshot_vendor_bill(bill),
    )
    await session.commit()
    return await _bill_detail(session, bill.id)


@router.delete("/vendor-bills/{bill_id}", status_code=204)
async def delete_vendor_bill(bill_id: int, session: Session, user: CurrentUser):
    bill = await _get_or_404(session, FinVendorBill, bill_id, "Вендор-счёт не найден")
    await _ensure_cap(session, user, "manage_vendor_bills", bill.legal_entity_id)
    try:
        invoicing_svc.assert_doc_editable(bill.status)
    except invoicing_svc.DocumentError as exc:
        _raise_phase5(exc)
    await audit_fin.log_fin(
        session, entity_type="fin_vendor_bill", entity_id=bill.id,
        user_id=user.id, action="delete", before=audit_fin.snapshot_vendor_bill(bill),
    )
    await session.delete(bill)
    await session.commit()


@router.post("/vendor-bills/{bill_id}/confirm", response_model=VendorBillDetailOut)
async def confirm_vendor_bill_ep(bill_id: int, session: Session, user: CurrentUser):
    """Подтвердить вендор-счёт → accrual (Дт расход + Дт НДС input / Кт AP). Внутренний номер."""
    bill = await _bill_detail(session, bill_id)
    await _ensure_cap(session, user, "manage_vendor_bills", bill.legal_entity_id)
    if bill.number is None:
        bill.number = await numbering.next_number(
            session, doc_type="vendor_bill", legal_entity_id=bill.legal_entity_id,
            year=bill.bill_date.year, prefix="ВС",
        )
    before = audit_fin.snapshot_vendor_bill(bill)
    try:
        await invoicing_svc.confirm_vendor_bill(session, bill, posted_by_user_id=user.id)
    except (invoicing_svc.DocumentError, PostingError, FxRateMissing) as exc:
        await session.rollback()
        _raise_phase5(exc)
    await audit_fin.log_fin(
        session, entity_type="fin_vendor_bill", entity_id=bill.id,
        user_id=user.id, action="confirm",
        before=before, after=audit_fin.snapshot_vendor_bill(bill),
    )
    await session.commit()
    return await _bill_detail(session, bill.id)


@router.post("/vendor-bills/{bill_id}/pay", response_model=VendorBillDetailOut)
async def pay_vendor_bill_ep(
    bill_id: int, payload: DocPaymentIn, session: Session, user: CurrentUser
):
    """Оплата вендор-счёта → Дт AP / Кт деньги. Гасит кредиторку, обновляет статус."""
    bill = await _get_or_404(session, FinVendorBill, bill_id, "Вендор-счёт не найден")
    await _ensure_cap(session, user, "manage_vendor_bills", bill.legal_entity_id)
    await _ensure_cap(session, user, "post_operation", bill.legal_entity_id)
    before = audit_fin.snapshot_vendor_bill(bill)
    try:
        await invoicing_svc.pay_vendor_bill(
            session, bill,
            money_account_id=payload.money_account_id,
            amount=payload.amount, on_date=payload.on_date,
            cashflow_category_id=payload.cashflow_category_id,
            posted_by_user_id=user.id,
        )
    except (invoicing_svc.DocumentError, PostingError, FxRateMissing) as exc:
        await session.rollback()
        _raise_phase5(exc)
    await audit_fin.log_fin(
        session, entity_type="fin_vendor_bill", entity_id=bill.id,
        user_id=user.id, action="pay",
        before=before, after=audit_fin.snapshot_vendor_bill(bill),
    )
    await session.commit()
    return await _bill_detail(session, bill.id)


@router.post("/vendor-bills/{bill_id}/cancel", response_model=VendorBillDetailOut)
async def cancel_vendor_bill_ep(bill_id: int, session: Session, user: CurrentUser):
    """Отменить вендор-счёт. draft → cancelled; confirmed без оплат → сторно + cancelled."""
    bill = await _get_or_404(session, FinVendorBill, bill_id, "Вендор-счёт не найден")
    await _ensure_cap(session, user, "manage_vendor_bills", bill.legal_entity_id)
    before = audit_fin.snapshot_vendor_bill(bill)
    try:
        await invoicing_svc.cancel_vendor_bill(session, bill, posted_by_user_id=user.id)
    except (invoicing_svc.DocumentError, PostingError, FxRateMissing) as exc:
        await session.rollback()
        _raise_phase5(exc)
    await audit_fin.log_fin(
        session, entity_type="fin_vendor_bill", entity_id=bill.id,
        user_id=user.id, action="cancel",
        before=before, after=audit_fin.snapshot_vendor_bill(bill),
    )
    await session.commit()
    return await _bill_detail(session, bill.id)


# ───────────────────────────── НДС-книги + AR/AP aging ─────────────────────────────


@router.get("/reports/vat", response_model=VatReportOut)
async def vat_report_ep(
    session: Session,
    _: Annotated[User, Depends(require_fin("view_vat"))],
    legal_entity_id: int = Query(...),
    date_from: date = Query(...),
    date_to: date = Query(...),
):
    """Отчёт по НДС за период: книга продаж (2310) / книга покупок (1910) + НДС к уплате."""
    report = await vat_books_svc.vat_report(
        session, legal_entity_id, date_from=date_from, date_to=date_to
    )
    return VatReportOut(
        output_total=report.output_total,
        input_total=report.input_total,
        vat_payable=report.vat_payable,
        sales_book=[vars(e) for e in report.sales_book],
        purchase_book=[vars(e) for e in report.purchase_book],
    )


@router.get("/reports/ar-aging", response_model=AgingReportOut)
async def ar_aging_ep(
    session: Session,
    _: Annotated[User, Depends(require_fin("view_vat"))],
    legal_entity_id: int = Query(...),
    as_of: date | None = Query(default=None),
):
    """AR aging: неоплаченные счета клиентам по бакетам возраста долга (от due_date)."""
    report = await vat_books_svc.receivables_aging(
        session, legal_entity_id, as_of=as_of or date.today()
    )
    return AgingReportOut(
        docs=[vars(d) for d in report.docs],
        by_bucket=report.by_bucket, total=report.total,
    )


@router.get("/reports/ap-aging", response_model=AgingReportOut)
async def ap_aging_ep(
    session: Session,
    _: Annotated[User, Depends(require_fin("view_vat"))],
    legal_entity_id: int = Query(...),
    as_of: date | None = Query(default=None),
):
    """AP aging: неоплаченные вендор-счета по бакетам возраста долга (от due_date)."""
    report = await vat_books_svc.payables_aging(
        session, legal_entity_id, as_of=as_of or date.today()
    )
    return AgingReportOut(
        docs=[vars(d) for d in report.docs],
        by_bucket=report.by_bucket, total=report.total,
    )


# ───────────────────────────── Ф3: интеграция факта оплаты ─────────────────────────────


def _raise_phase3(exc: Exception) -> NoReturn:
    """Перевести исключение Ф3-интеграции/движка в HTTPException. Всегда бросает."""
    code, detail = phase3_status(exc)
    raise HTTPException(code, detail) from exc


@router.post(
    "/integrations/deals/{deal_id}/post-payment", response_model=OperationOut
)
async def post_deal_payment_ep(
    deal_id: int,
    session: Session,
    user: Annotated[User, Depends(require_fin("post_operation"))],
):
    """Ручная проводка факта оплаты сделки → income-операция (идемпотентно по source='deal').

    Резервный путь к авто-хуку mark-paid: бухгалтер может провести оплату вручную, если
    авто-write-through не сработал. Повторный вызов вернёт уже существующую операцию.
    Анти-двойной-учёт: no-op (404), если по сделке уже есть write-through из ContractPayment.
    """
    from app.models import Deal

    deal = (
        await session.execute(select(Deal).where(Deal.id == deal_id))
    ).scalar_one_or_none()
    if deal is None:
        raise HTTPException(404, "Сделка не найдена")
    try:
        op = await pay_integration_svc.integrate_deal_paid(
            session, deal, posted_by_user_id=user.id
        )
        if op is None:
            raise HTTPException(
                422,
                "Операция не создана: оплата по сделке уже учтена через "
                "ContractPayment-платёж (или у сделки нет суммы для проводки).",
            )
        await session.commit()
    except (PostingError, FxRateMissing, pay_integration_svc.IntegrationError) as exc:
        await session.rollback()
        _raise_phase3(exc)
    await session.refresh(op)
    return op


@router.post(
    "/integrations/deals/{deal_id}/reverse-payment",
    response_model=JournalEntryOut,
)
async def reverse_deal_payment_ep(
    deal_id: int,
    session: Session,
    user: Annotated[User, Depends(require_fin("post_operation"))],
):
    """Снятие пометки оплаты сделки → сторно income-операции (если была проведена).

    404 — если по сделке нет проведённой операции (нечего сторнировать).
    """
    entry: FinJournalEntry | None = None
    try:
        entry = await pay_integration_svc.reverse_deal_paid(
            session, deal_id, posted_by_user_id=user.id
        )
        if entry is None:
            raise HTTPException(404, "Нет проведённой операции по сделке для сторно")
        await session.commit()
    except (PostingError, FxRateMissing, pay_integration_svc.IntegrationError) as exc:
        await session.rollback()
        _raise_phase3(exc)
    await session.refresh(entry)
    return entry


@router.post(
    "/integrations/motivational-cards/{card_id}/pay",
    response_model=MotivationalCardPayOut,
)
async def pay_motivational_card_ep(
    card_id: int,
    session: Session,
    user: Annotated[User, Depends(require_fin("post_operation"))],
):
    """Провести выплату по мотивационной карте → расходная (ФОТ/комиссия) операция.

    Переводит МК в status='paid' (если ещё не) И создаёт расходную операцию (идемпотентно
    по source='motivational_card'). Дт 5110 ФОТ / 5120 комиссии, Кт деньги. Расчёт МК НЕ
    меняется — только фиксируется факт выплаты в GL.
    """
    card = (
        await session.execute(
            select(MotivationalCard).where(MotivationalCard.id == card_id)
        )
    ).scalar_one_or_none()
    if card is None:
        raise HTTPException(404, "Мотивационная карта не найдена")
    if card.status == "draft":
        raise HTTPException(
            422, "МК в статусе draft — финализируйте перед выплатой."
        )
    existed = await pay_integration_svc._existing_op(
        session, pay_integration_svc.SOURCE_MOTIVATIONAL_CARD, card.id
    )
    try:
        if card.status != "paid":
            card.status = "paid"
            card.paid_at = datetime.now(UTC)
            await session.flush()
        op = await pay_integration_svc.integrate_motivational_card_paid(
            session, card, posted_by_user_id=user.id
        )
        await session.commit()
    except (PostingError, FxRateMissing, pay_integration_svc.IntegrationError) as exc:
        await session.rollback()
        _raise_phase3(exc)
    return MotivationalCardPayOut(
        card_id=card.id,
        card_status=card.status,
        operation_id=op.id if op is not None else None,
        created=(op is not None and existed is None),
    )


@router.post(
    "/integrations/subscriptions/plan", response_model=SubscriptionPlanOut
)
async def plan_subscriptions_ep(
    payload: SubscriptionPlanIn,
    session: Session,
    user: Annotated[User, Depends(require_fin("create_operation"))],
):
    """Сгенерировать плановые поступления подписок за период (платёжный календарь).

    По активным ClientSubscription (или одной — subscription_id) создаёт планы
    (status='planned') по fee_actual. Идемпотентно (partial-unique subscription_id+op_date):
    повторный прогон не задваивает. Планы НЕ проводятся — это ожидания (ДДС план/факт — Ф4).
    """
    q = select(ClientSubscription).where(ClientSubscription.is_active.is_(True))
    if payload.subscription_id is not None:
        q = q.where(ClientSubscription.id == payload.subscription_id)
    subs = (await session.execute(q)).scalars().all()

    created = existing = skipped = 0
    try:
        for sub in subs:
            if pay_integration_svc.subscription_planned_amount(sub) is None:
                skipped += 1
                continue
            before = await session.execute(
                select(FinOperation.id).where(
                    FinOperation.subscription_id == sub.id,
                    FinOperation.source == pay_integration_svc.SOURCE_SUBSCRIPTION,
                    FinOperation.op_date
                    == pay_integration_svc.period_first_day(payload.year, payload.month),
                    FinOperation.status == "planned",
                )
            )
            was = before.first() is not None
            await pay_integration_svc.integrate_subscription_planned(
                session, sub, year=payload.year, month=payload.month,
                created_by_user_id=user.id,
            )
            if was:
                existing += 1
            else:
                created += 1
        await session.commit()
    except (PostingError, FxRateMissing, pay_integration_svc.IntegrationError) as exc:
        await session.rollback()
        _raise_phase3(exc)
    return SubscriptionPlanOut(
        period=f"{payload.year}-{payload.month:02d}",
        processed=len(subs),
        created=created,
        existing=existing,
        skipped=skipped,
    )


@router.get(
    "/integrations/shadow-reconciliation", response_model=ShadowReconOut
)
async def shadow_reconciliation_ep(
    session: Session,
    _: Annotated[User, Depends(require_fin("view_reports"))],
    date_from: date | None = Query(default=None),
    date_to: date | None = Query(default=None),
    require_signed_contract: bool = Query(default=True),
    first_payment_only: bool = Query(default=True),
):
    """SHADOW-сверка комиссий: ContractPayment (старый путь) vs GL-зеркало (новый путь).

    ok=true ⇔ 0 расхождений — зелёный сигнал безопасности для будущего переключения
    чтения комиссий на GL (само переключение — НЕ в этой фазе). Фильтры повторяют
    salary.py (eligible-платежи). Read-only — ничего не мутирует.
    """
    result = await shadow_recon_svc.reconcile_commissions(
        session,
        period_start=date_from,
        period_end=date_to,
        require_signed_contract=require_signed_contract,
        first_payment_only=first_payment_only,
    )
    return ShadowReconOut(**result.as_dict())


# ═════════════════════════ Ф4: accrual + переоценка + смена базы ═════════════════════════


def _raise_phase4(exc: Exception) -> NoReturn:
    """Перевести исключение Ф4 (признание/переоценка/смена базы) в HTTPException. Бросает."""
    code, detail = phase4_status(exc)
    raise HTTPException(code, detail) from exc


async def _legal_entity_or_422(session: AsyncSession, legal_entity_id: int) -> FinLegalEntity:
    le = (
        await session.execute(
            select(FinLegalEntity).where(FinLegalEntity.id == legal_entity_id)
        )
    ).scalar_one_or_none()
    if le is None:
        raise HTTPException(422, f"Юрлицо id={legal_entity_id} не найдено")
    return le


@router.post("/revenue-recognition/run", response_model=RevenueRecognitionRunOut)
async def revenue_recognition_run(
    payload: RevenueRecognitionRunIn,
    session: Session,
    user: Annotated[User, Depends(require_fin("recognize_revenue"))],
):
    """Признать выручку (MRR) за период по активным подпискам.

    Генерирует план (fin_revenue_schedule, idempotent по recognition_key) + постит
    accrual-проводки (Дт AR / Кт выручка 4010 / Кт НДС). Выручка признаётся по
    НАЧИСЛЕНИЮ, независимо от оплаты. Период-lock: нельзя признать в закрытый период
    (→ 409). Повторный прогон не задваивает (existing). Per-entity cap-проверка.
    """
    await _ensure_cap(session, user, "recognize_revenue", payload.legal_entity_id)
    le = await _legal_entity_or_422(session, payload.legal_entity_id)
    # Single-flight: advisory-lock на (юрлицо, год, месяц) — scale=2 не даст двум
    # параллельным прогонам ОДНОГО периода гоняться за recognition_key UNIQUE
    # (иначе IntegrityError→500). Разные периоды/юрлица не блокируются.
    await session.execute(
        select(
            sa_func.pg_advisory_xact_lock(
                recognition_svc.RECOGNITION_ADVISORY_NS,
                recognition_svc.period_lock_subkey(
                    payload.legal_entity_id, payload.year, payload.month
                ),
            )
        )
    )
    try:
        counters = await recognition_svc.run_recognition_period(
            session,
            legal_entity=le,
            year=payload.year,
            month=payload.month,
            subscription_id=payload.subscription_id,
            vat_rate_id=payload.vat_rate_id,
            posted_by_user_id=user.id,
        )
        await session.commit()
    except (PostingError, FxRateMissing, recognition_svc.RecognitionError) as exc:
        await session.rollback()
        _raise_phase4(exc)
    except IntegrityError as exc:
        # Belt-and-suspenders: recognition_key UNIQUE / partial-unique JE accrual.
        # Advisory-lock уже сериализует прогоны, но при гонке вне нашего лока
        # (напр. ручной прогон + cron одновременно по разным путям) отдаём 409,
        # а не 500. Конфликт состояния, не невалидный ввод.
        await session.rollback()
        raise HTTPException(
            409, "Признание выручки за период уже выполняется или выполнено"
        ) from exc
    return RevenueRecognitionRunOut(
        period=f"{payload.year}-{payload.month:02d}",
        processed=counters["processed"],
        scheduled=counters["scheduled"],
        recognized=counters["recognized"],
        existing=counters["existing"],
        skipped=counters["skipped"],
    )


@router.get("/revenue-recognition/schedule", response_model=list[RevenueScheduleOut])
async def revenue_recognition_schedule(
    session: Session,
    _: Annotated[User, Depends(require_fin("view_reports"))],
    legal_entity_id: int = Query(...),
    year: int | None = Query(default=None),
    month: int | None = Query(default=None),
    subscription_id: int | None = Query(default=None),
    status_filter: str | None = Query(default=None, alias="status"),
):
    """Листинг строк плана признания выручки (read-only). Фильтры: период/подписка/статус."""
    q = select(FinRevenueSchedule).where(
        FinRevenueSchedule.legal_entity_id == legal_entity_id
    )
    if year is not None:
        q = q.where(FinRevenueSchedule.period_year == year)
    if month is not None:
        q = q.where(FinRevenueSchedule.period_month == month)
    if subscription_id is not None:
        q = q.where(FinRevenueSchedule.subscription_id == subscription_id)
    if status_filter is not None:
        if status_filter not in _SCHEDULE_STATUSES:
            raise HTTPException(
                422, f"status должен быть одним из {', '.join(_SCHEDULE_STATUSES)}"
            )
        q = q.where(FinRevenueSchedule.status == status_filter)
    q = q.order_by(
        FinRevenueSchedule.period_year.desc(),
        FinRevenueSchedule.period_month.desc(),
        FinRevenueSchedule.id.desc(),
    )
    rows = (await session.execute(q)).scalars().all()
    return [RevenueScheduleOut.model_validate(r) for r in rows]


@router.post(
    "/revenue-recognition/schedule/{schedule_id}/reverse",
    response_model=RevenueReverseOut,
)
async def revenue_recognition_reverse(
    schedule_id: int,
    payload: RevenueReverseIn,
    session: Session,
    user: Annotated[User, Depends(require_fin("recognize_revenue"))],
):
    """Откат признанной выручки (H6) — сторно в ТЕКУЩЕМ открытом периоде.

    Признанная выручка ЗАКРЫТОГО периода НЕ стирается — корректировка reversal-проводкой
    в открытом периоде (reverse_date; None=сегодня). Например: подписка отвалилась «с мая»,
    май закрыт → сторно в июне (корректировка прошлого периода в текущем).
    """
    sched = (
        await session.execute(
            select(FinRevenueSchedule).where(FinRevenueSchedule.id == schedule_id)
        )
    ).scalar_one_or_none()
    if sched is None:
        raise HTTPException(404, "Строка признания не найдена")
    await _ensure_cap(session, user, "recognize_revenue", sched.legal_entity_id)
    before = audit_fin.snapshot_revenue_schedule(sched)
    try:
        rev_entry = await recognition_svc.reverse_recognition(
            session, sched, on_date=payload.reverse_date, posted_by_user_id=user.id
        )
        await audit_fin.log_fin(
            session, entity_type="fin_revenue_schedule", entity_id=sched.id,
            user_id=user.id, action="reverse",
            before=before, after=audit_fin.snapshot_revenue_schedule(sched),
        )
        await session.commit()
    except (PostingError, FxRateMissing, recognition_svc.RecognitionError) as exc:
        await session.rollback()
        _raise_phase4(exc)
    return RevenueReverseOut(
        schedule_id=sched.id, reversal_entry_id=rev_entry.id, status=sched.status
    )


@router.post("/revaluation/run", response_model=RevaluationRunOut)
async def revaluation_run(
    payload: RevaluationRunIn,
    session: Session,
    user: Annotated[User, Depends(require_fin("run_revaluation"))],
):
    """Переоценка валютных остатков на конец периода (курсовые разницы → 4910/5910).

    Переоцениваются денежные счета юрлица, валюта которых ≠ функц.валюте. Дельта оценки →
    проводка fx_reval (Σ=0). Идемпотентно (нет двойной переоценки за период). Период-lock:
    нельзя переоценить в закрытый период (→ 409). CFO/админ. Per-entity cap-проверка.
    """
    await _ensure_cap(session, user, "run_revaluation", payload.legal_entity_id)
    le = await _legal_entity_or_422(session, payload.legal_entity_id)
    # Single-flight: advisory-lock на (юрлицо, год, месяц) — два параллельных прогона
    # переоценки одного периода не задвоят fx_reval-проводку на счёт (идемпотентность
    # внутри run_revaluation_period — проверка существующей проводки — на scale=2 без
    # лока подвержена гонке read-then-insert).
    await session.execute(
        select(
            sa_func.pg_advisory_xact_lock(
                revaluation_svc.REVALUATION_ADVISORY_NS,
                recognition_svc.period_lock_subkey(
                    payload.legal_entity_id, payload.year, payload.month
                ),
            )
        )
    )
    try:
        counters = await revaluation_svc.run_revaluation_period(
            session,
            legal_entity=le,
            year=payload.year,
            month=payload.month,
            posted_by_user_id=user.id,
        )
        await session.commit()
    except (PostingError, FxRateMissing, revaluation_svc.RevaluationError) as exc:
        await session.rollback()
        _raise_phase4(exc)
    except IntegrityError as exc:
        await session.rollback()
        raise HTTPException(
            409, "Переоценка за период уже выполняется или выполнена"
        ) from exc
    return RevaluationRunOut(
        period=f"{payload.year}-{payload.month:02d}",
        processed=counters["processed"],
        revalued=counters["revalued"],
        no_change=counters["no_change"],
        skipped_func_ccy=counters["skipped_func_ccy"],
    )


@router.get("/settings", response_model=FinSettingsOut)
async def fin_settings_get(
    session: Session,
    _: Annotated[User, Depends(require_fin("view_operations"))],
):
    """Глобальные настройки модуля (singleton): текущая базовая валюта группы.

    Read-only. Используется UI смены базовой валюты (показать текущую базу + дату
    последней смены). Если строки настроек ещё нет — дефолт base_currency='RUB'.
    """
    settings = (
        await session.execute(select(FinSettings).limit(1))
    ).scalar_one_or_none()
    if settings is None:
        return FinSettingsOut(
            base_currency="RUB",
            base_currency_changed_at=None,
            auto_approve_without_scenario=False,
        )
    return FinSettingsOut.model_validate(settings)


@router.post("/settings/base-currency", response_model=BaseRecomputeJobOut)
async def settings_change_base_currency(
    payload: BaseCurrencyChangeIn,
    session: Session,
    user: Annotated[User, Depends(require_fin("change_base_currency"))],
):
    """Сменить базовую валюту группы + пересчитать amount_in_base (открытые периоды, H4).

    ТЯЖЁЛАЯ операция: служба идёт по всем ledger-строкам и пересчитывает amount_in_base по
    дате каждой строки. ПОЛИТИКА H4: пересчитываются ТОЛЬКО ОТКРЫТЫЕ периоды — закрытые
    сохраняют исторический base/amount_in_base (period-lock философия). amount_func НЕ
    трогается (база — проекция). Single-flight (advisory-lock) — параллельный запуск не
    задвоит. Нет курса на дату → строка fx_missing, job='partial'. CFO/админ.
    """
    target = payload.target_currency.strip().upper()
    if not target:
        raise HTTPException(422, "Не задана целевая валюта")
    # Single-flight: advisory-lock на время пересчёта (scale=2 — два запуска не гонятся).
    await session.execute(
        select(sa_func.pg_advisory_xact_lock(base_currency_svc.RECOMPUTE_ADVISORY_KEY))
    )
    try:
        job = await base_currency_svc.recompute_base_amounts(
            session, target_currency=target, started_by_user_id=user.id
        )
        await audit_fin.log_fin(
            session, entity_type="fin_base_recompute_job", entity_id=job.id,
            user_id=user.id, action="change_base",
            after=audit_fin.snapshot_base_recompute_job(job),
        )
        await session.commit()
    except (FxRateMissing, base_currency_svc.BaseCurrencyError) as exc:
        await session.rollback()
        _raise_phase4(exc)
    return BaseRecomputeJobOut.model_validate(job)


# ───────────────────────────── Платёжный календарь (read-only) ─────────────────────────────

_CALENDAR_DIRECTIONS = ("in", "out", "all")
#: Защита от запроса гигантских окон (читает несколько таблиц).
_CALENDAR_MAX_DAYS = 400


@router.get("/calendar", response_model=CalendarOut)
async def fin_calendar(
    session: Session,
    user: Annotated[User, Depends(require_fin("view_reports"))],
    date_from: date = Query(...),
    date_to: date = Query(...),
    direction: str = Query("all"),
    legal_entity_id: int | None = Query(default=None),
):
    """Платёжный календарь: предстоящие/прошедшие ожидаемые платежи (read-only проекция).

    Агрегирует из нескольких источников в плоский список событий + дневные агрегаты:
      • FinInvoice (неоплаченные счета клиентам)        → in
      • FinVendorBill (неоплаченные счета поставщиков)  → out
      • FinAct (акты выполненных работ)                 → in (информ.)
      • FinPaymentRegistry (реестры платежей)           → out
      • FinRequest (заявки менеджеров на платёж/ЗП)     → out
      • Deal.expected_payment_date (ожидаемая оплата)   → in

    Статус: overdue ⇔ срок прошёл и не оплачено; paid / planned. НЕ постит проводок.
    Гейт view_reports (бухгалтер/cfo/директор/админ). Фильтры: окно дат, направление,
    опц. юрлицо. Денежные суммы мультивалютны (без конвертации — UI группирует).
    """
    if direction not in _CALENDAR_DIRECTIONS:
        raise HTTPException(
            422, f"direction должен быть одним из {', '.join(_CALENDAR_DIRECTIONS)}"
        )
    if date_to < date_from:
        raise HTTPException(422, "date_to не может быть раньше date_from")
    if (date_to - date_from).days > _CALENDAR_MAX_DAYS:
        raise HTTPException(
            422, f"Окно календаря не может превышать {_CALENDAR_MAX_DAYS} дней"
        )

    today = date.today()
    events: list[calendar_svc.CalendarEvent] = []

    # Defense-in-depth: при отсутствии явного юрлица ограничиваем источники
    # доступными юрлицами (None = NULL-scope grant, ограничения нет).
    le_scope: list[int] | None = None
    if legal_entity_id is None:
        le_scope = await accessible_legal_entity_ids(session, user, "view_reports")

    # ── Инвойсы (in): окно по due_date или issue_date ──
    inv_q = (
        select(FinInvoice, Company.name, Company.legal_name)
        .join(Company, FinInvoice.counterparty_company_id == Company.id, isouter=True)
        .where(
            sa_func.coalesce(FinInvoice.due_date, FinInvoice.issue_date) >= date_from,
            sa_func.coalesce(FinInvoice.due_date, FinInvoice.issue_date) <= date_to,
            FinInvoice.status.notin_(("draft", "paid", "cancelled")),
        )
    )
    if legal_entity_id is not None:
        inv_q = inv_q.where(FinInvoice.legal_entity_id == legal_entity_id)
    elif le_scope is not None:
        inv_q = inv_q.where(FinInvoice.legal_entity_id.in_(le_scope))
    for inv, c_name, c_legal in (await session.execute(inv_q)).all():
        ev = calendar_svc.event_from_invoice(
            invoice_id=inv.id, number=inv.number,
            counterparty_name=c_name or c_legal,
            issue_date=inv.issue_date, due_date=inv.due_date,
            currency=inv.currency, amount_gross=inv.amount_gross,
            paid_amount=inv.paid_amount, status=inv.status,
            legal_entity_id=inv.legal_entity_id,
            counterparty_company_id=inv.counterparty_company_id, today=today,
        )
        if ev is not None:
            events.append(ev)

    # ── Вендор-счета (out) ──
    vb_q = (
        select(FinVendorBill, Company.name, Company.legal_name)
        .join(Company, FinVendorBill.supplier_company_id == Company.id, isouter=True)
        .where(
            sa_func.coalesce(FinVendorBill.due_date, FinVendorBill.bill_date) >= date_from,
            sa_func.coalesce(FinVendorBill.due_date, FinVendorBill.bill_date) <= date_to,
            FinVendorBill.status.notin_(("draft", "paid", "cancelled")),
        )
    )
    if legal_entity_id is not None:
        vb_q = vb_q.where(FinVendorBill.legal_entity_id == legal_entity_id)
    elif le_scope is not None:
        vb_q = vb_q.where(FinVendorBill.legal_entity_id.in_(le_scope))
    for bill, c_name, c_legal in (await session.execute(vb_q)).all():
        ev = calendar_svc.event_from_vendor_bill(
            bill_id=bill.id, number=bill.number, supplier_name=c_name or c_legal,
            bill_date=bill.bill_date, due_date=bill.due_date, currency=bill.currency,
            amount_gross=bill.amount_gross, paid_amount=bill.paid_amount,
            status=bill.status, legal_entity_id=bill.legal_entity_id,
            supplier_company_id=bill.supplier_company_id, today=today,
        )
        if ev is not None:
            events.append(ev)

    # ── Акты (in, информ.) ──
    act_q = (
        select(FinAct, Company.name, Company.legal_name)
        .join(Company, FinAct.counterparty_company_id == Company.id, isouter=True)
        .where(
            FinAct.act_date >= date_from, FinAct.act_date <= date_to,
            FinAct.status.notin_(("draft", "cancelled")),
        )
    )
    if legal_entity_id is not None:
        act_q = act_q.where(FinAct.legal_entity_id == legal_entity_id)
    elif le_scope is not None:
        act_q = act_q.where(FinAct.legal_entity_id.in_(le_scope))
    for act, c_name, c_legal in (await session.execute(act_q)).all():
        ev = calendar_svc.event_from_act(
            act_id=act.id, number=act.number, counterparty_name=c_name or c_legal,
            act_date=act.act_date, currency=act.currency, amount_gross=act.amount_gross,
            status=act.status, legal_entity_id=act.legal_entity_id,
            counterparty_company_id=act.counterparty_company_id, today=today,
        )
        if ev is not None:
            events.append(ev)

    # ── Реестры платежей (out): сумма = Σ amount операций реестра ──
    reg_q = select(FinPaymentRegistry).where(
        FinPaymentRegistry.registry_date >= date_from,
        FinPaymentRegistry.registry_date <= date_to,
        FinPaymentRegistry.approval_status != "rejected",
    )
    if legal_entity_id is not None:
        reg_q = reg_q.where(FinPaymentRegistry.legal_entity_id == legal_entity_id)
    elif le_scope is not None:
        reg_q = reg_q.where(FinPaymentRegistry.legal_entity_id.in_(le_scope))
    registries = list((await session.execute(reg_q)).scalars().all())
    if registries:
        reg_ids = [r.id for r in registries]
        sums = dict(
            (
                await session.execute(
                    select(
                        FinOperation.registry_id,
                        sa_func.coalesce(sa_func.sum(FinOperation.amount), 0),
                    )
                    .where(FinOperation.registry_id.in_(reg_ids))
                    .group_by(FinOperation.registry_id)
                )
            ).all()
        )
        # Валюта реестра = валюта счёта-источника.
        cur_rows = dict(
            (
                await session.execute(
                    select(FinPaymentRegistry.id, FinMoneyAccount.currency)
                    .join(
                        FinMoneyAccount,
                        FinPaymentRegistry.source_account_id == FinMoneyAccount.id,
                    )
                    .where(FinPaymentRegistry.id.in_(reg_ids))
                )
            ).all()
        )
        for reg in registries:
            ev = calendar_svc.event_from_registry(
                registry_id=reg.id, number=reg.number, title=reg.title,
                registry_date=reg.registry_date, legal_entity_id=reg.legal_entity_id,
                amount_total=sums.get(reg.id, Decimal("0")),
                currency=cur_rows.get(reg.id, "RUB"),
                payment_status=reg.payment_status, today=today,
            )
            if ev is not None:
                events.append(ev)

    # ── Заявки менеджеров (out) ── desired_date в окне ЛИБО NULL (тогда дата=created_at,
    #    отбор по created_at делаем в Python после загрузки, т.к. created_at — timestamptz).
    req_q = select(FinRequest).where(
        (
            (
                FinRequest.desired_date.is_not(None)
                & (FinRequest.desired_date >= date_from)
                & (FinRequest.desired_date <= date_to)
            )
            | FinRequest.desired_date.is_(None)
        ),
        FinRequest.status.notin_(("draft", "rejected", "cancelled")),
    )
    if legal_entity_id is not None:
        req_q = req_q.where(FinRequest.legal_entity_id == legal_entity_id)
    elif le_scope is not None:
        req_q = req_q.where(FinRequest.legal_entity_id.in_(le_scope))
    for req in (await session.execute(req_q)).scalars().all():
        created_d = req.created_at.date()
        # NULL desired_date → событие в день создания; отфильтруем по окну тут.
        if req.desired_date is None and not (date_from <= created_d <= date_to):
            continue
        ev = calendar_svc.event_from_request(
            request_id=req.id, number=req.number, request_type=req.request_type,
            desired_date=req.desired_date,
            created_date=created_d, currency=req.currency,
            amount=req.amount, status=req.status,
            legal_entity_id=req.legal_entity_id,
            counterparty_company_id=req.counterparty_company_id, today=today,
        )
        if ev is not None:
            events.append(ev)

    # ── Сделки с ожидаемой датой оплаты (in) ── (legal_entity не на Deal → без фильтра LE)
    # Под per-entity ограничением (le_scope is not None) сделки исключаем: у них нет
    # юрлица, поэтому отнести их к доступному набору нельзя.
    if legal_entity_id is None and le_scope is None:
        deal_q = select(Deal).where(
            Deal.expected_payment_date.is_not(None),
            Deal.expected_payment_date >= date_from,
            Deal.expected_payment_date <= date_to,
            Deal.amount.is_not(None),
        )
        for d in (await session.execute(deal_q)).scalars().all():
            if d.expected_payment_date is None:
                continue
            ev = calendar_svc.event_from_deal(
                deal_id=d.id, title=d.title,
                expected_payment_date=d.expected_payment_date,
                currency=d.currency, amount=d.amount,
                legal_entity_id=None, today=today,
            )
            if ev is not None:
                events.append(ev)

    # Фильтр направления (pure) + сортировка по дате.
    events = calendar_svc.filter_by_direction(events, direction)
    events.sort(key=lambda e: (e.date, e.source_type, e.source_id))

    out_events = [
        CalendarEventOut(
            date=e.date, direction=e.direction, title=e.title, amount=e.amount,
            currency=e.currency, source_type=e.source_type, source_id=e.source_id,
            status=e.status, legal_entity_id=e.legal_entity_id,
            counterparty_company_id=e.counterparty_company_id,
        )
        for e in events
    ]
    totals = [
        CalendarDayTotalOut(
            date=t.date, total_in=t.total_in, total_out=t.total_out,
            overdue_count=t.overdue_count, event_count=t.event_count,
        )
        for t in calendar_svc.day_totals(events)
    ]
    return CalendarOut(
        date_from=date_from, date_to=date_to, direction=direction,
        events=out_events, day_totals=totals,
    )
