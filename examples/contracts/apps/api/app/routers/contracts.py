import uuid
from datetime import UTC, datetime
from decimal import Decimal
from pathlib import Path
from typing import Annotated

from fastapi import APIRouter, Depends, File, HTTPException, Query, Request, UploadFile, status
from fastapi.responses import FileResponse
from sqlalchemy import func
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.config import get_settings
from app.db import get_session
from app.deps import CurrentUser, DirectorOrAdmin, load_contract, require_owner_or_role, scope_to_user
from app.models import (
    Approval,
    ApprovalDecision,
    ApprovalRoute,
    AuditLog,
    Contract,
    ContractAttachment,
    ContractItem,
    ContractRemark,
    ContractRevision,
    ContractStatus,
    Deal,
    Product,
    ProductPlan,
    ProductPrice,
    TemplateVariableType,
    UserRole,
)

# Эпик 0 (RBAC централизация, май 2026): единый dep вместо 20+ копипастных блоков
# `if user.role == manager and c.author_user_id != user.id`. Lawyer допущен наряду
# с admin/director — сохраняем legacy-семантику (юрист видит все договоры по работе).
_RequireContractOwner = Depends(
    require_owner_or_role(
        load_contract,
        owner_field="author_user_id",
        elevated=(UserRole.admin, UserRole.director, UserRole.lawyer),
    )
)
from app.routers.approval_routes import match_route
from app.schemas import (
    ApprovalDecisionIn,
    ContractApprovalSummary,
    ContractAttachmentOut,
    ContractIn,
    ContractItemOut,
    ContractItemsUpdate,
    ContractList,
    ContractOut,
    ContractPatch,
    ContractPricingOut,
    GenerateResponse,
    ReturnForReworkIn,
)
from app.services.approval_engine import (
    active_stage_index,
    advance_stage_if_needed,
    approvals_for_attempt,
    build_approval_summary,
    can_decide_contract,
    create_stage_approvals,
    current_attempt,
    normalize_stages,
)
from app.services.categories import assign_for_counterparty, snapshot_total_rub
from app.services.contract_status import (
    already_finalized_by_other,
    can_decide_from,
    can_submit_from,
    rework_comment_valid,
)
from app.services.contract_status import (
    groups_payload as contract_status_groups_payload,
)
from app.services.contract_status import (
    statuses_for_group as contract_statuses_for_group,
)
from app.services.customer_success import ensure_subscription_from_contract
from app.services.numbering import next_contract_number
from app.services.pricing import compute_totals, format_money, get_manager_max_discount, q2
from app.services.render import generate_contract_files
from app.services.templates import (
    build_custom_context,
    get_licensor_for_country,
    load_active_variables,
    load_country,
    load_product,
    master_skeleton_version,
)

router = APIRouter(prefix="/contracts", tags=["contracts"])
settings = get_settings()

ALLOWED_ATTACHMENT_TYPES = {"application/pdf", "image/jpeg", "image/png", "image/webp"}
MAX_ATTACHMENT_SIZE = 15 * 1024 * 1024  # 15 МБ
_ATTACHMENT_EXT = {
    "application/pdf": ".pdf",
    "image/jpeg": ".jpg",
    "image/png": ".png",
    "image/webp": ".webp",
}
# Whitelist допустимых kind вложения. `kind` приходит query-параметром и идёт в
# имя файла — без whitelist это path traversal (произвольная запись вне каталога
# вложений). Любое значение вне whitelist → 400.
ALLOWED_ATTACHMENT_KINDS = ("signed_scan", "payment", "other")


def _attachments_dir(contract_id: int) -> Path:
    p = settings.storage_dir / "contracts" / str(contract_id) / "attachments"
    p.mkdir(parents=True, exist_ok=True)
    return p


async def _log(session: AsyncSession, user_id: int | None, contract_id: int | None, action: str, payload: dict | None = None, ip: str | None = None):
    session.add(AuditLog(user_id=user_id, contract_id=contract_id, action=action, payload=payload, ip=ip))


def _client_ip(request: Request) -> str | None:
    return request.client.host if request.client else None


async def _create_revision(session: AsyncSession, c: Contract, user_id: int, note: str) -> None:
    """Создаёт снимок версии договора: snapshot context + копия docx/pdf в подпапку версии."""
    import shutil
    from pathlib import Path

    # Номер версии
    last = (await session.execute(
        select(ContractRevision.version_number)
        .where(ContractRevision.contract_id == c.id)
        .order_by(ContractRevision.version_number.desc())
        .limit(1)
    )).first()
    version_number = (last[0] + 1) if last else 1
    attempt = await current_attempt(session, c.id)

    # Копируем файлы в подпапку версии (чтобы не потерять при перегенерации)
    rev_docx, rev_pdf = None, None
    if c.docx_path and Path(c.docx_path).exists():
        rev_dir = Path(c.docx_path).parent / f"v{version_number}"
        rev_dir.mkdir(parents=True, exist_ok=True)
        rev_docx = str(rev_dir / "contract.docx")
        shutil.copy(c.docx_path, rev_docx)
        if c.pdf_path and Path(c.pdf_path).exists():
            rev_pdf = str(rev_dir / "contract.pdf")
            shutil.copy(c.pdf_path, rev_pdf)

    session.add(ContractRevision(
        contract_id=c.id,
        version_number=version_number,
        attempt=attempt,
        context_snapshot=dict(c.context or {}),
        template_version=c.template_version,
        docx_path=rev_docx,
        pdf_path=rev_pdf,
        note=note,
        created_by_user_id=user_id,
    ))


# CONTACTS 2.0 Ф4: резолв стороны и маппинг реквизитов вынесены в общий модуль
# app.services.party (переиспользуют bulk_generator/renewal). Алиасы с прежними
# приватными именами сохранены для обратной совместимости (тесты импортируют
# `_sublicensee_from_company` / `_resolve_party` из этого роутера).
from app.services.party import (  # noqa: E402
    resolve_party as _resolve_party,
)
from app.services.party import (  # noqa: E402
    sublicensee_from_company as _sublicensee_from_company,  # noqa: F401  (re-export for tests)
)
from app.services.party import (  # noqa: E402
    sublicensee_from_counterparty as _sublicensee_from_counterparty,  # noqa: F401  (re-export)
)


@router.get("/status-groups")
async def list_status_groups(_: CurrentUser):
    """Wave 2a: первичные группы статусов договора (для фильтра «все + 4 группы»).

    Возвращает упорядоченный список групп с входящими статусами и подстатусами —
    фронт строит из этого dropdown фильтра. Чистая структура, без БД.
    См. app.services.contract_status.
    """
    return {"groups": contract_status_groups_payload()}


@router.get("", response_model=ContractList)
async def list_contracts(
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    page: int = Query(1, ge=1),
    page_size: int = Query(50, ge=1, le=200),
    status_filter: ContractStatus | None = Query(None, alias="status"),
    status_group: str | None = Query(
        None,
        description=(
            "Wave 2a: фильтр по первичной группе статусов "
            "(archived_group | draft_group | in_review_group | approved_group). "
            "Разворачивается в IN (...) по входящим статусам. Совместно с "
            "?status= берётся пересечение (status имеет приоритет смысла, но "
            "оба применяются как AND)."
        ),
    ),
    product_code: str | None = None,
    country_code: str | None = None,
    q: str | None = None,
    include_archived: bool = False,
):
    stmt = select(Contract).order_by(Contract.created_at.desc())
    # Manager видит только свои; admin/director/lawyer — все (см. app.deps.scope_to_user).
    stmt = scope_to_user(stmt, Contract, current_user, "author_user_id")
    if not include_archived:
        stmt = stmt.where(Contract.archived_at.is_(None))
    if status_filter:
        stmt = stmt.where(Contract.status == status_filter)
    if status_group:
        # Wave 2a: разворачиваем группу в множество входящих статусов.
        # Неизвестный код группы → 400 (видимый баг фронта, не тихий no-op).
        try:
            group_statuses = contract_statuses_for_group(status_group)
        except KeyError:
            raise HTTPException(400, f"Неизвестная группа статусов: {status_group}")
        stmt = stmt.where(Contract.status.in_(group_statuses))
    if product_code:
        stmt = stmt.where(Contract.product_code == product_code)
    if country_code:
        stmt = stmt.where(Contract.country_code == country_code)
    if q:
        stmt = stmt.where(
            (Contract.number.ilike(f"%{q}%")) | (Contract.title.ilike(f"%{q}%"))
        )

    total = (await session.execute(select(func.count()).select_from(stmt.subquery()))).scalar_one()
    stmt = stmt.offset((page - 1) * page_size).limit(page_size)
    items = (await session.execute(stmt)).scalars().all()
    return ContractList(items=items, total=total)


@router.post("", response_model=ContractOut, status_code=status.HTTP_201_CREATED)
async def create_contract(
    payload: ContractIn,
    current_user: CurrentUser,
    request: Request,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    if not payload.city:
        raise HTTPException(400, "Укажите город")

    context = dict(payload.context or {})

    # CONTACTS 2.0 Ф3-A: сторона договора — Company. Резолвим по company_id,
    # с фолбэком на counterparty_id (зеркало) для обратной совместимости.
    company, counterparty, sublicensee = await _resolve_party(
        session, company_id=payload.company_id, counterparty_id=payload.counterparty_id,
    )
    if sublicensee and not context.get("sublicensee"):
        context["sublicensee"] = sublicensee

    # Дублируем оба id (company_id — новый источник истины, counterparty_id —
    # зеркало для legacy-кода до Ф4). Если пришёл только counterparty_id —
    # company резолвится из зеркала; если только company_id — counterparty из неё.
    resolved_company_id = company.id if company else payload.company_id
    resolved_counterparty_id = (
        (company.counterparty_id if company else None)
        or (counterparty.id if counterparty else None)
        or payload.counterparty_id
    )

    c = Contract(
        product_code=payload.product_code,
        country_code=payload.country_code,
        city=payload.city,
        company_id=resolved_company_id,
        counterparty_id=resolved_counterparty_id,
        title=payload.title,
        author_user_id=current_user.id,
        status=ContractStatus.draft,
        context=context,
    )
    session.add(c)
    await session.flush()
    await _log(session, current_user.id, c.id, "create", {"product": c.product_code, "country": c.country_code}, _client_ip(request))

    # BUG-2: договор создан из сделки → привязываем Deal.contract_id, чтобы
    # win-gate видел вложения/платежи договора. Если у сделки уже есть договор —
    # перезаписываем на новый (последний созданный из сделки актуальнее, прежний
    # договор остаётся в реестре сам по себе). Тихо игнорим несуществующий deal_id.
    if payload.deal_id is not None:
        deal = (
            await session.execute(select(Deal).where(Deal.id == payload.deal_id))
        ).scalar_one_or_none()
        if deal is not None:
            deal.contract_id = c.id
            await _log(
                session, current_user.id, c.id, "link_deal",
                {"deal_id": deal.id}, _client_ip(request),
            )

    await session.commit()
    await session.refresh(c)
    # Эпик 11.2: outbound webhook contract.created
    from app.services.webhook_dispatcher import contract_to_payload, safe_dispatch_event
    await safe_dispatch_event(
        session, "contract.created", "contract", c.id, contract_to_payload(c),
    )
    return c


@router.get("/{contract_id}", response_model=ContractOut)
async def get_contract(
    contract_id: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    c: Annotated[Contract, _RequireContractOwner],
):
    return c


@router.patch("/{contract_id}", response_model=ContractOut)
async def patch_contract(
    contract_id: int,
    payload: ContractPatch,
    current_user: CurrentUser,
    request: Request,
    session: Annotated[AsyncSession, Depends(get_session)],
    c: Annotated[Contract, _RequireContractOwner],
):
    if c.status not in (ContractStatus.draft, ContractStatus.rejected):
        raise HTTPException(400, "Договор уже отправлен на согласование, редактирование запрещено.")

    if payload.title is not None:
        c.title = payload.title
    if payload.city is not None:
        c.city = payload.city
    if payload.company_id is not None or payload.counterparty_id is not None:
        # CONTACTS 2.0 Ф3-A: смена стороны. Источник истины — Company; оба id
        # дублируем (зеркало). Если пришёл только counterparty_id (старый клиент) —
        # резолвим company из зеркала. Auto-fill только если sublicensee пуст.
        company, counterparty, sublicensee = await _resolve_party(
            session, company_id=payload.company_id, counterparty_id=payload.counterparty_id,
        )
        c.company_id = company.id if company else payload.company_id
        c.counterparty_id = (
            (company.counterparty_id if company else None)
            or (counterparty.id if counterparty else None)
            or payload.counterparty_id
        )
        if sublicensee:
            current_ctx = dict(c.context or {})
            if not current_ctx.get("sublicensee"):
                current_ctx["sublicensee"] = sublicensee
            c.context = current_ctx
    if payload.context is not None:
        # Баг аудита #7: PATCH без блока sublicensee не должен затирать ранее
        # авто-заполненные из Company реквизиты. Сохраняем существующий sublicensee
        # (и licensor_override_id), если входящий context их не содержит явно.
        new_ctx = dict(payload.context)
        prev_ctx = dict(c.context or {})
        for preserve_key in ("sublicensee", "licensor_override_id"):
            if not new_ctx.get(preserve_key) and prev_ctx.get(preserve_key):
                new_ctx[preserve_key] = prev_ctx[preserve_key]
        c.context = new_ctx

    await _log(session, current_user.id, c.id, "edit", {"fields": list(payload.model_dump(exclude_none=True).keys())}, _client_ip(request))
    await session.commit()
    await session.refresh(c)
    return c


@router.post("/{contract_id}/archive", response_model=ContractOut)
async def archive_contract(
    contract_id: int,
    current_user: CurrentUser,
    request: Request,
    session: Annotated[AsyncSession, Depends(get_session)],
    c: Annotated[Contract, _RequireContractOwner],
):
    if c.status == ContractStatus.in_review:
        raise HTTPException(400, "Нельзя архивировать договор на согласовании")
    c.archived_at = datetime.now(UTC)
    await _log(session, current_user.id, c.id, "archive", None, _client_ip(request))
    await session.commit()
    await session.refresh(c)
    return c


@router.post("/{contract_id}/unarchive", response_model=ContractOut)
async def unarchive_contract(
    contract_id: int,
    current_user: CurrentUser,
    request: Request,
    session: Annotated[AsyncSession, Depends(get_session)],
    c: Annotated[Contract, _RequireContractOwner],
):
    c.archived_at = None
    await _log(session, current_user.id, c.id, "unarchive", None, _client_ip(request))
    await session.commit()
    await session.refresh(c)
    return c


@router.post("/{contract_id}/duplicate", response_model=ContractOut, status_code=status.HTTP_201_CREATED)
async def duplicate_contract(
    contract_id: int,
    current_user: CurrentUser,
    request: Request,
    session: Annotated[AsyncSession, Depends(get_session)],
    src: Annotated[Contract, _RequireContractOwner],
):
    """Создать новый черновик на основе существующего: копирует context, продукт,
    страну, город и контрагента. Номер, файлы и Drive-ссылки не переносятся."""
    new = Contract(
        product_code=src.product_code,
        country_code=src.country_code,
        city=src.city,
        company_id=src.company_id,
        counterparty_id=src.counterparty_id,
        title=f"{src.title or 'Договор'} (копия)",
        author_user_id=current_user.id,
        status=ContractStatus.draft,
        context=dict(src.context or {}),
    )
    session.add(new)
    await session.flush()
    await _log(session, current_user.id, new.id, "duplicate", {"from": src.id}, _client_ip(request))
    await session.commit()
    await session.refresh(new)
    return new


@router.post("/{contract_id}/fill-from-counterparty", response_model=ContractOut)
async def fill_from_counterparty(
    contract_id: int,
    current_user: CurrentUser,
    request: Request,
    session: Annotated[AsyncSession, Depends(get_session)],
    c: Annotated[Contract, _RequireContractOwner],
):
    """Принудительно перезаписать sublicensee из текущей стороны (даже если уже заполнен).

    CONTACTS 2.0 Ф3-A: источник — Company (по company_id), с фолбэком на
    counterparty-зеркало. Имя ручки оставлено (legacy), семантика — «обновить
    реквизиты стороны из справочника».
    """
    if not c.company_id and not c.counterparty_id:
        raise HTTPException(400, "Не выбран контрагент")
    if c.status not in (ContractStatus.draft, ContractStatus.rejected):
        raise HTTPException(400, "Договор уже отправлен на согласование")
    _company, _cp, sublicensee = await _resolve_party(
        session, company_id=c.company_id, counterparty_id=c.counterparty_id,
    )
    if not sublicensee:
        raise HTTPException(404, "Контрагент не найден")
    ctx = dict(c.context or {})
    ctx["sublicensee"] = sublicensee
    c.context = ctx
    await _log(session, current_user.id, c.id, "fill_from_counterparty", None, _client_ip(request))
    await session.commit()
    await session.refresh(c)
    return c


@router.post("/{contract_id}/generate", response_model=GenerateResponse)
async def generate(
    contract_id: int,
    current_user: CurrentUser,
    request: Request,
    session: Annotated[AsyncSession, Depends(get_session)],
    c: Annotated[Contract, _RequireContractOwner],
):
    if not c.number:
        full_number, city_code, _ = await next_contract_number(
            session, city_name=c.city or "Город", country_code=c.country_code
        )
        c.number = full_number
        c.city_code = city_code

    ctx = dict(c.context or {})
    ctx.setdefault("contract", {})
    ctx["contract"].setdefault("number", c.number)

    # Подгружаем продукт, страну, лицензиара из БД. Шаблон — .docx из файла.
    product = await load_product(session, c.product_code)
    country = await load_country(session, c.country_code)
    licensor_override = ctx.get("licensor_override_id")
    licensor = await get_licensor_for_country(
        session, c.country_code,
        override_id=int(licensor_override) if licensor_override else None,
    )

    # Кастомные переменные: проверяем обязательные и собираем namespace {{ custom.* }}
    variables = await load_active_variables(session, c.product_code, c.country_code)
    raw_custom = dict(ctx.get("custom") or {})
    missing = [
        v.label for v in variables
        if v.required and v.var_type != TemplateVariableType.checkbox
        and (raw_custom.get(v.key) in (None, "") and not (v.default_value or "").strip())
    ]
    if missing:
        raise HTTPException(400, "Заполните обязательные дополнительные поля: " + ", ".join(missing))
    ctx["custom"] = build_custom_context(variables, raw_custom)

    # Позиции и итог договора → в контекст шаблона (таблица продуктов + Итого/Скидка/К оплате).
    # Для каждого значения даём число и *_text (отформатированную строку).
    items = await _load_items(session, c.id)
    ctx["items"] = [
        {
            "name": it.name_snapshot,
            "qty": float(it.qty),
            "currency": it.currency,
            "unit_price": float(it.unit_price),
            "unit_price_text": format_money(it.unit_price),
            "line_total": float(it.line_total),
            "line_total_text": format_money(it.line_total),
        }
        for it in items
    ]
    ctx["pricing"] = {
        "currency": c.currency,
        "subtotal": float(c.subtotal or 0),
        "subtotal_text": format_money(c.subtotal or 0),
        "discount_pct": float(c.discount_pct or 0),
        "discount_amount": float(c.discount_amount or 0),
        "discount_amount_text": format_money(c.discount_amount or 0),
        "total": float(c.total or 0),
        "total_text": format_money(c.total or 0),
    }

    docx_path, pdf_path = generate_contract_files(c.id, product, country, licensor, ctx)

    c.docx_path = str(docx_path)
    c.pdf_path = str(pdf_path)
    c.template_version = await master_skeleton_version(session)

    await _log(session, current_user.id, c.id, "generate", {"version": c.template_version}, _client_ip(request))
    await session.commit()

    base = f"/api/contracts/{c.id}"
    return GenerateResponse(
        contract_id=c.id,
        number=c.number,
        docx_url=f"{base}/docx",
        pdf_url=f"{base}/pdf",
    )


@router.get("/{contract_id}/docx")
async def download_docx(
    contract_id: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    c: Annotated[Contract, _RequireContractOwner],
):
    if not c.docx_path:
        raise HTTPException(404, "Документ ещё не сгенерирован")
    filename = f"Договор {c.number or c.id}.docx"
    return FileResponse(c.docx_path, filename=filename, media_type="application/vnd.openxmlformats-officedocument.wordprocessingml.document")


@router.get("/{contract_id}/pdf")
async def download_pdf(
    contract_id: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    c: Annotated[Contract, _RequireContractOwner],
    inline: bool = False,
):
    if not c.pdf_path:
        raise HTTPException(404, "PDF ещё не сгенерирован")
    filename = f"Договор {c.number or c.id}.pdf"
    # inline=1 → рендер в <iframe> превью (Content-Disposition: inline).
    # По умолчанию attachment — скачивание (кнопка .pdf, ссылки из TG, версии).
    return FileResponse(
        c.pdf_path,
        filename=filename,
        media_type="application/pdf",
        content_disposition_type="inline" if inline else "attachment",
    )


# ============ Вложения договора (скан подписи и т.п.) ============

@router.post(
    "/{contract_id}/attachments",
    response_model=ContractAttachmentOut,
    status_code=status.HTTP_201_CREATED,
)
async def upload_attachment(
    contract_id: int,
    current_user: CurrentUser,
    request: Request,
    session: Annotated[AsyncSession, Depends(get_session)],
    c: Annotated[Contract, _RequireContractOwner],
    file: UploadFile = File(...),
    kind: str = "signed_scan",
):
    if file.content_type not in ALLOWED_ATTACHMENT_TYPES:
        raise HTTPException(400, "Допустимы PDF или изображения (jpg/png/webp)")
    # `kind` идёт в имя файла → строго whitelist (защита от path traversal).
    if kind not in ALLOWED_ATTACHMENT_KINDS:
        raise HTTPException(
            400,
            f"Недопустимый тип вложения. Допустимы: {', '.join(ALLOWED_ATTACHMENT_KINDS)}",
        )
    data = await file.read()
    if len(data) > MAX_ATTACHMENT_SIZE:
        raise HTTPException(400, "Файл больше 15 МБ")

    # Расширение — только из whitelist content-type (никогда из имени файла, чтобы
    # не протащить «../» / спецсимволы). Имя файла детерминированно из kind+uuid.
    ext = _ATTACHMENT_EXT.get(file.content_type or "", ".bin")
    base_dir = _attachments_dir(contract_id)
    fname = f"{kind}_{uuid.uuid4().hex[:8]}{ext}"
    out_path = (base_dir / fname).resolve()
    # Defense-in-depth: итоговый путь обязан лежать внутри каталога вложений.
    if not out_path.is_relative_to(base_dir.resolve()):
        raise HTTPException(400, "Недопустимое имя файла")
    out_path.write_bytes(data)

    att = ContractAttachment(
        contract_id=c.id,
        kind=kind,
        path=str(out_path),
        original_name=file.filename,
        content_type=file.content_type,
        uploaded_by_user_id=current_user.id,
    )
    session.add(att)
    await _log(
        session, current_user.id, c.id, "attachment_upload",
        {"kind": kind, "name": file.filename}, _client_ip(request),
    )
    await session.commit()
    await session.refresh(att)
    return att


@router.get("/{contract_id}/attachments", response_model=list[ContractAttachmentOut])
async def list_attachments(
    contract_id: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    c: Annotated[Contract, _RequireContractOwner],
):
    rows = (
        await session.execute(
            select(ContractAttachment)
            .where(ContractAttachment.contract_id == contract_id)
            .order_by(ContractAttachment.created_at)
        )
    ).scalars().all()
    return rows


@router.get("/{contract_id}/attachments/{attachment_id}/file")
async def download_attachment(
    contract_id: int,
    attachment_id: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    c: Annotated[Contract, _RequireContractOwner],
    inline: bool = False,
):
    att = (
        await session.execute(
            select(ContractAttachment).where(
                ContractAttachment.id == attachment_id,
                ContractAttachment.contract_id == contract_id,
            )
        )
    ).scalar_one_or_none()
    if not att:
        raise HTTPException(404, "Вложение не найдено")
    if not Path(att.path).exists():
        raise HTTPException(404, "Файл отсутствует на диске")
    return FileResponse(
        att.path,
        filename=att.original_name or Path(att.path).name,
        media_type=att.content_type or "application/octet-stream",
        content_disposition_type="inline" if inline else "attachment",
    )


@router.delete("/{contract_id}/attachments/{attachment_id}", status_code=status.HTTP_204_NO_CONTENT)
async def delete_attachment(
    contract_id: int,
    attachment_id: int,
    current_user: CurrentUser,
    request: Request,
    session: Annotated[AsyncSession, Depends(get_session)],
    c: Annotated[Contract, _RequireContractOwner],
):
    if c.status == ContractStatus.signed:
        raise HTTPException(400, "Нельзя удалять вложения проведённой сделки (сначала отмените проведение)")
    att = (
        await session.execute(
            select(ContractAttachment).where(
                ContractAttachment.id == attachment_id,
                ContractAttachment.contract_id == contract_id,
            )
        )
    ).scalar_one_or_none()
    if not att:
        raise HTTPException(404, "Вложение не найдено")
    p = Path(att.path)
    if p.exists() and p.is_relative_to(settings.storage_dir):
        p.unlink(missing_ok=True)
    await session.delete(att)
    await _log(session, current_user.id, c.id, "attachment_delete", {"id": attachment_id}, _client_ip(request))
    await session.commit()


# ============ Проведение сделки (подписание) ============

@router.post("/{contract_id}/sign", response_model=ContractOut)
async def sign_contract(
    contract_id: int,
    current_user: CurrentUser,
    request: Request,
    session: Annotated[AsyncSession, Depends(get_session)],
    c: Annotated[Contract, _RequireContractOwner],
):
    """Отметить сделку проведённой: договор согласован + есть скан подписи клиента → signed."""
    if c.status not in (ContractStatus.approved, ContractStatus.uploaded):
        raise HTTPException(400, "Провести можно только согласованный договор")
    has_scan = (
        await session.execute(
            select(func.count())
            .select_from(ContractAttachment)
            .where(
                ContractAttachment.contract_id == c.id,
                ContractAttachment.kind == "signed_scan",
            )
        )
    ).scalar_one()
    if not has_scan:
        raise HTTPException(400, "Загрузите скан с подписью клиента перед проведением сделки")
    c.status = ContractStatus.signed
    c.signed_at = datetime.now(UTC)
    await snapshot_total_rub(session, c)  # ₽-снимок итога на дату подписания (для категорий)
    await _log(session, current_user.id, c.id, "sign", None, _client_ip(request))
    await session.commit()
    await session.refresh(c)
    if c.counterparty_id:
        await assign_for_counterparty(session, c.counterparty_id)  # обновить категорию клиента
        await ensure_subscription_from_contract(session, c)  # подписан → создать/привязать подписку CS (B0)
        await session.commit()
    # Эпик 11.2: outbound webhook contract.signed
    from app.services.webhook_dispatcher import contract_to_payload, safe_dispatch_event
    await safe_dispatch_event(
        session, "contract.signed", "contract", c.id, contract_to_payload(c),
    )
    # Эпик 21: in-app notification author'у — «договор подписан». catch на всё.
    try:
        from app.services.notifications import (
            build_contract_signed_notification,
            safe_create_notification,
        )
        notif_data = build_contract_signed_notification(
            contract_id=c.id,
            contract_title=c.title,
            author_user_id=c.author_user_id,
        )
        await safe_create_notification(session, **notif_data)
        await session.commit()
    except Exception as e:  # noqa: BLE001
        import logging
        logging.getLogger(__name__).warning(
            "contract_signed in-app notification failed for contract %s: %s",
            c.id, e,
        )
    return c


@router.post("/{contract_id}/unsign", response_model=ContractOut)
async def unsign_contract(
    contract_id: int,
    current_user: DirectorOrAdmin,
    request: Request,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Откатить проведение сделки (admin/director): signed → approved."""
    c = (await session.execute(select(Contract).where(Contract.id == contract_id))).scalar_one_or_none()
    if not c:
        raise HTTPException(404, "Не найден")
    if c.status != ContractStatus.signed:
        raise HTTPException(400, "Договор не в статусе «сделка проведена»")
    c.status = ContractStatus.approved
    c.signed_at = None
    await _log(session, current_user.id, c.id, "unsign", None, _client_ip(request))
    await session.commit()
    await session.refresh(c)
    if c.counterparty_id:
        await assign_for_counterparty(session, c.counterparty_id)  # договор больше не считается
        await session.commit()
    return c


# ============ Позиции и прайс договора ============

async def _load_items(session: AsyncSession, contract_id: int) -> list[ContractItem]:
    return list((
        await session.execute(
            select(ContractItem)
            .where(ContractItem.contract_id == contract_id)
            .order_by(ContractItem.sort_order, ContractItem.id)
        )
    ).scalars().all())


async def _pricing_out(session: AsyncSession, c: Contract) -> ContractPricingOut:
    items = await _load_items(session, c.id)
    cap = await get_manager_max_discount(session)
    return ContractPricingOut(
        currency=c.currency,
        subtotal=float(c.subtotal or 0),
        discount_pct=float(c.discount_pct or 0),
        discount_amount=float(c.discount_amount or 0),
        total=float(c.total or 0),
        items=[ContractItemOut.model_validate(it) for it in items],
        manager_max_discount_pct=float(cap) if cap is not None else None,
    )


@router.get("/{contract_id}/items", response_model=ContractPricingOut)
async def get_items(
    contract_id: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    c: Annotated[Contract, _RequireContractOwner],
):
    return await _pricing_out(session, c)


@router.put("/{contract_id}/items", response_model=ContractPricingOut)
async def set_items(
    contract_id: int,
    payload: ContractItemsUpdate,
    current_user: CurrentUser,
    request: Request,
    session: Annotated[AsyncSession, Depends(get_session)],
    c: Annotated[Contract, _RequireContractOwner],
):
    if c.status not in (ContractStatus.draft, ContractStatus.rejected):
        raise HTTPException(400, "Позиции можно менять только в черновике или отклонённом договоре")

    discount = Decimal(str(payload.discount_pct or 0))
    if discount < 0 or discount > 100:
        raise HTTPException(400, "Скидка должна быть в диапазоне 0–100%")
    # Лимит скидки — серверная проверка (менеджер не обойдёт через UI)
    if current_user.role == UserRole.manager:
        cap = await get_manager_max_discount(session)
        if cap is not None and discount > cap:
            raise HTTPException(403, f"Скидка превышает лимит {cap}% для роли «менеджер»")

    currency = payload.currency.upper()

    for it in await _load_items(session, c.id):
        await session.delete(it)
    await session.flush()

    line_totals: list[Decimal] = []
    for idx, item_in in enumerate(payload.items):
        prod = (await session.execute(select(Product).where(Product.id == item_in.product_id))).scalar_one_or_none()
        if not prod:
            raise HTTPException(400, f"Продукт #{item_in.product_id} не найден")
        price = (
            await session.execute(
                select(ProductPrice).where(
                    ProductPrice.product_id == item_in.product_id,
                    ProductPrice.plan_id == item_in.plan_id,
                    ProductPrice.currency == currency,
                )
            )
        ).scalar_one_or_none()
        if not price:
            raise HTTPException(400, f"Нет цены в {currency} для продукта «{prod.name}»")
        name = prod.name
        if item_in.plan_id:
            plan = (await session.execute(select(ProductPlan).where(ProductPlan.id == item_in.plan_id))).scalar_one_or_none()
            if plan:
                name = f"{prod.name} — {plan.name}"
        qty = Decimal(str(item_in.qty or 1))
        unit_price = q2(price.amount)
        line_total = q2(unit_price * qty)
        line_totals.append(line_total)
        session.add(ContractItem(
            contract_id=c.id, product_id=prod.id, plan_id=item_in.plan_id,
            name_snapshot=name, currency=currency, qty=qty,
            unit_price=unit_price, line_total=line_total, sort_order=idx,
        ))

    subtotal, discount_amount, total = compute_totals(line_totals, discount)
    c.currency = currency if payload.items else None
    c.discount_pct = discount
    c.subtotal = subtotal
    c.discount_amount = discount_amount
    c.total = total
    await _log(
        session, current_user.id, c.id, "items_update",
        {"count": len(payload.items), "currency": currency, "total": str(total)},
        _client_ip(request),
    )
    await session.commit()
    await session.refresh(c)
    return await _pricing_out(session, c)


@router.post("/{contract_id}/submit", response_model=ContractOut)
async def submit_for_approval(
    contract_id: int,
    current_user: CurrentUser,
    request: Request,
    session: Annotated[AsyncSession, Depends(get_session)],
    c: Annotated[Contract, _RequireContractOwner],
):
    if not c.docx_path:
        raise HTTPException(400, "Сначала сгенерируйте документ")
    if not can_submit_from(c.status):
        raise HTTPException(400, "Договор уже на согласовании или согласован")

    routes = (await session.execute(select(ApprovalRoute))).scalars().all()
    chosen = match_route(routes, c.product_code, c.country_code)
    if not chosen:
        raise HTTPException(400, "Не настроен маршрут согласования для этого продукта/страны")

    stages = normalize_stages(chosen)
    if not stages:
        raise HTTPException(400, "В маршруте не настроены согласователи")

    # resubmit — повторная отправка после rejected ИЛИ needs_rework (новый attempt)
    is_resubmit = c.status in (ContractStatus.rejected, ContractStatus.needs_rework)
    last_attempt = await current_attempt(session, c.id)
    new_attempt = last_attempt + 1 if is_resubmit else 1

    # Создаём approvals только для первого этапа
    await create_stage_approvals(session, c, stages[0], new_attempt)

    # Снимок версии договора (snapshot context + копия файлов)
    await _create_revision(
        session, c, current_user.id,
        note="Повторная отправка после исправлений" if is_resubmit else "Отправлен на согласование",
    )

    c.status = ContractStatus.in_review
    await _log(
        session, current_user.id, c.id,
        "resubmit" if is_resubmit else "submit",
        {"route": chosen.name, "stages_count": len(stages), "attempt": new_attempt},
        _client_ip(request),
    )
    await session.commit()
    await session.refresh(c)

    try:
        from app.services.telegram import notify_approval_request  # noqa: WPS433
        await notify_approval_request(c.id, stage_order=stages[0]["order"])
    except Exception:  # noqa: BLE001
        pass

    # Эпик 21: in-app notifications approver'ам первого этапа.
    # НЕ заменяет TG (notify_approval_request выше) — параллельно.
    try:
        await _notify_stage_approvers(
            session, c, stages[0], current_attempt_value=new_attempt,
        )
        await session.commit()
    except Exception as e:  # noqa: BLE001
        import logging
        logging.getLogger(__name__).warning(
            "approval_needed in-app notifications failed for contract %s: %s",
            c.id, e,
        )

    return c


async def _notify_stage_approvers(
    session: AsyncSession,
    contract: Contract,
    stage: dict,
    current_attempt_value: int,
) -> None:
    """Эпик 21: in-app notification approver'ам этапа `stage`.

    Находим только pending approvals для (contract_id, stage_order, attempt) —
    их id нужны для metadata. Для каждого user_id шлём notification. Идемпотентно
    в рамках одного вызова (один approval = одна нотификация); если этап
    «переотправляется» (resubmit), pending approvals нового attempt — новые
    Approval-строки, значит и новые notifications. На прошлые attempts мы не
    шлём (уже отстрелялись).
    """
    from app.services.notifications import (
        build_approval_needed_notification,
        safe_create_notification,
    )
    stage_order = stage.get("order", 0)
    approvals = (
        await session.execute(
            select(Approval).where(
                Approval.contract_id == contract.id,
                Approval.stage_order == stage_order,
                Approval.attempt == current_attempt_value,
                Approval.decision == ApprovalDecision.pending,
            )
        )
    ).scalars().all()
    for a in approvals:
        notif_data = build_approval_needed_notification(
            approval_id=a.id,
            contract_id=contract.id,
            contract_title=contract.title,
            approver_user_id=a.user_id,
            stage_order=stage_order,
        )
        await safe_create_notification(session, **notif_data)


@router.post("/{contract_id}/resubmit", response_model=ContractOut)
async def resubmit(
    contract_id: int,
    current_user: CurrentUser,
    request: Request,
    session: Annotated[AsyncSession, Depends(get_session)],
    c: Annotated[Contract, _RequireContractOwner],
):
    """Алиас submit'а для повторной отправки после исправлений (статус rejected)."""
    return await submit_for_approval(contract_id, current_user, request, session, c)


@router.get("/{contract_id}/approval-summary", response_model=ContractApprovalSummary)
async def approval_summary(
    contract_id: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    c = (await session.execute(select(Contract).where(Contract.id == contract_id))).scalar_one_or_none()
    if not c:
        raise HTTPException(404, "Не найден")
    # P0 security (Unit 3b): read-IDOR — summary палит, кто согласовал/отклонил +
    # комментарии для любого contract_id. Только visibility-gate (decide-логику не трогаем).
    from app.services.access_control import ensure_object_visible
    await ensure_object_visible(session, c, "contract", current_user)
    routes = (await session.execute(select(ApprovalRoute))).scalars().all()
    route = match_route(routes, c.product_code, c.country_code)
    return await build_approval_summary(session, c, route)


@router.post("/{contract_id}/decide", response_model=ContractOut)
async def decide(
    contract_id: int,
    payload: ApprovalDecisionIn,
    current_user: CurrentUser,
    request: Request,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    if payload.decision not in (ApprovalDecision.approved, ApprovalDecision.rejected):
        raise HTTPException(400, "Решение должно быть approved или rejected")
    if payload.decision == ApprovalDecision.rejected and not (payload.comment and payload.comment.strip()):
        raise HTTPException(400, "При отклонении необходимо указать причину")

    # P1 concurrency (audit S6 B3): row lock + перепроверка precondition ПОД
    # lock'ом. Без блокировки два согласователя могли пройти проверку
    # in_review одновременно и оба применить решение (last-write-wins:
    # approved затирал реальный reject). Теперь второй ждёт lock, перечитывает
    # статус и получает 409 «уже обработан».
    c = (await session.execute(
        select(Contract).where(Contract.id == contract_id).with_for_update()
    )).scalar_one_or_none()
    if not c:
        raise HTTPException(404, "Не найден")
    if c.status != ContractStatus.in_review:
        if already_finalized_by_other(c.status):
            raise HTTPException(409, "Договор уже обработан другим согласователем")
        raise HTTPException(400, "Договор не на согласовании")

    # B3 WARN-5 (no-self-approval): автор договора не может согласовать/отклонить
    # собственный договор (зеркало finance «деньги ↔ власть»). Проверяем до выбора
    # approval-строки, чтобы автор-согласователь не закрывал свой же этап.
    if not can_decide_contract(current_user.id, c.author_user_id):
        raise HTTPException(403, "Нельзя согласовать собственный договор")

    attempt = await current_attempt(session, c.id)

    # Резолв маршрута/этапов заранее: нужен, чтобы выбрать approval именно для
    # ТЕКУЩЕГО активного этапа (баг аудита #6). Если один и тот же user назначен
    # согласователем в нескольких этапах, у него несколько pending Approval-строк
    # (по одной на stage_order). Брать «последнюю по id» нельзя — это могла бы быть
    # строка более позднего этапа, который ещё не активен → шаги ломались.
    routes = (await session.execute(select(ApprovalRoute))).scalars().all()
    route = match_route(routes, c.product_code, c.country_code)
    stages = normalize_stages(route) if route else []
    existing_approvals = await approvals_for_attempt(session, c.id, attempt)
    active_idx = active_stage_index(stages, existing_approvals) if stages else 0
    active_order = (
        stages[active_idx]["order"] if stages and active_idx < len(stages) else None
    )

    # Все pending-решения этого юзера на текущей попытке.
    my_pending = [
        a for a in existing_approvals
        if a.user_id == current_user.id and a.decision == ApprovalDecision.pending
    ]
    if not my_pending:
        raise HTTPException(403, "Вы не назначены согласователем на этом этапе договора")
    # Приоритет — pending именно активного этапа; если по какой-то причине нет
    # (например legacy-маршрут без stages) — самый ранний pending по stage_order.
    approval = next(
        (a for a in my_pending if a.stage_order == active_order),
        None,
    ) or min(my_pending, key=lambda a: (a.stage_order, a.id))
    if active_order is not None and approval.stage_order > active_order:
        # Этап ещё в будущем (active не дошёл до него) — рано.
        # Прошлый этап (stage_order < active_order) НЕ блокируем: этап мог быть
        # пропущен/переставлен, и согласователь застрянет навсегда (баг аудита).
        raise HTTPException(400, "Ваш этап согласования ещё не наступил")

    approval.decision = payload.decision
    approval.comment = payload.comment
    approval.decided_at = datetime.now(UTC)
    await session.flush()

    # route / stages уже зарезолвлены выше (для выбора активного этапа).
    notify_next_stage_order: int | None = None
    if payload.decision == ApprovalDecision.rejected:
        c.status = ContractStatus.rejected
        # Создаём замечание для чек-листа автора
        session.add(ContractRemark(
            contract_id=c.id,
            attempt=attempt,
            stage_order=approval.stage_order,
            author_user_id=current_user.id,
            text=payload.comment.strip(),
        ))
    elif route:
        advanced, new_active = await advance_stage_if_needed(session, c, route, attempt)
        if new_active >= len(stages):
            c.status = ContractStatus.approved
        elif advanced:
            notify_next_stage_order = stages[new_active]["order"]

    await _log(session, current_user.id, c.id, payload.decision.value,
               {"comment": payload.comment, "stage_order": approval.stage_order, "attempt": attempt},
               _client_ip(request))
    await session.commit()
    await session.refresh(c)

    if notify_next_stage_order is not None:
        try:
            from app.services.telegram import notify_approval_request  # noqa: WPS433
            await notify_approval_request(c.id, stage_order=notify_next_stage_order)
        except Exception:  # noqa: BLE001
            pass

        # Эпик 21: in-app для approver'ов следующего этапа.
        try:
            next_stage = next(
                (s for s in stages if s.get("order") == notify_next_stage_order),
                None,
            )
            if next_stage:
                await _notify_stage_approvers(
                    session, c, next_stage, current_attempt_value=attempt,
                )
                await session.commit()
        except Exception as e:  # noqa: BLE001
            import logging
            logging.getLogger(__name__).warning(
                "approval_needed (advance) in-app notifications failed: %s", e,
            )

    # Личное уведомление автору о результате
    if c.status in (ContractStatus.rejected, ContractStatus.approved):
        try:
            from app.services.telegram import notify_author  # noqa: WPS433
            if c.status == ContractStatus.rejected:
                await notify_author(c.id, "rejected", reason=payload.comment)
            else:
                await notify_author(c.id, "approved")
        except Exception:  # noqa: BLE001
            pass

    return c


@router.post("/{contract_id}/return-for-rework", response_model=ContractOut)
async def return_for_rework(
    contract_id: int,
    payload: ReturnForReworkIn,
    current_user: CurrentUser,
    request: Request,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Согласователь возвращает договор автору на доработку (мягче reject).

    Отличия от reject:
      - статус → `needs_rework` (не `rejected`): договор остаётся в активном
        цикле «На согласовании» (подстатус «На доработке»), не «отклонён».
      - комментарий «что поправить» ОБЯЗАТЕЛЕН (422 при пустом).
    Общее с reject:
      - права те же (нужно быть назначенным согласователем активного этапа);
      - комментарий пишется и в Approval.comment, и в ContractRemark (чек-лист
        автора). Текущая попытка «сбрасывается» так же, как при reject: автор
        правит и через submit заходит на новый attempt с первого этапа.
    """
    if not rework_comment_valid(payload.comment):
        raise HTTPException(422, "Укажите, что нужно исправить")

    # P1 concurrency (audit S6 B3): row lock + перепроверка precondition под
    # lock'ом — идентично decide. Не даём двум согласователям финализировать
    # один договор одновременно (return_for_rework vs approve гонка).
    c = (await session.execute(
        select(Contract).where(Contract.id == contract_id).with_for_update()
    )).scalar_one_or_none()
    if not c:
        raise HTTPException(404, "Не найден")
    if not can_decide_from(c.status):
        if already_finalized_by_other(c.status):
            raise HTTPException(409, "Договор уже обработан другим согласователем")
        raise HTTPException(400, "Договор не на согласовании")

    # B3 WARN-5 (no-self-approval): автор не может вернуть на доработку собственный
    # договор (как и согласовать/отклонить) — зеркало decide.
    if not can_decide_contract(current_user.id, c.author_user_id):
        raise HTTPException(403, "Нельзя обработать собственный договор")

    attempt = await current_attempt(session, c.id)

    # Резолв активного этапа — идентично decide (см. комментарий там).
    routes = (await session.execute(select(ApprovalRoute))).scalars().all()
    route = match_route(routes, c.product_code, c.country_code)
    stages = normalize_stages(route) if route else []
    existing_approvals = await approvals_for_attempt(session, c.id, attempt)
    active_idx = active_stage_index(stages, existing_approvals) if stages else 0
    active_order = (
        stages[active_idx]["order"] if stages and active_idx < len(stages) else None
    )

    my_pending = [
        a for a in existing_approvals
        if a.user_id == current_user.id and a.decision == ApprovalDecision.pending
    ]
    if not my_pending:
        raise HTTPException(403, "Вы не назначены согласователем на этом этапе договора")
    approval = next(
        (a for a in my_pending if a.stage_order == active_order),
        None,
    ) or min(my_pending, key=lambda a: (a.stage_order, a.id))
    if active_order is not None and approval.stage_order > active_order:
        raise HTTPException(400, "Ваш этап согласования ещё не наступил")

    comment = payload.comment.strip()
    # P2 (audit A3): пишем отдельное значение needs_rework (не rejected), чтобы
    # аналитика отличала возврат-на-доработку от жёсткого отклонения. Прогресс
    # этапов это не ломает — стадии считают только approved-голоса.
    approval.decision = ApprovalDecision.needs_rework
    approval.comment = comment
    approval.decided_at = datetime.now(UTC)

    c.status = ContractStatus.needs_rework
    session.add(ContractRemark(
        contract_id=c.id,
        attempt=attempt,
        stage_order=approval.stage_order,
        author_user_id=current_user.id,
        text=comment,
    ))

    await _log(session, current_user.id, c.id, "return_for_rework",
               {"comment": comment, "stage_order": approval.stage_order, "attempt": attempt},
               _client_ip(request))
    await session.commit()
    await session.refresh(c)

    # Личное уведомление автору (тем же каналом, что и rejected — переиспользуем).
    try:
        from app.services.telegram import notify_author  # noqa: WPS433
        await notify_author(c.id, "rejected", reason=comment)
    except Exception:  # noqa: BLE001
        pass

    return c


# ============ Версии и замечания (Группа 3) ============

@router.get("/{contract_id}/revisions")
async def list_revisions(
    contract_id: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    c: Annotated[Contract, _RequireContractOwner],
):
    rows = (await session.execute(
        select(ContractRevision)
        .where(ContractRevision.contract_id == contract_id)
        .order_by(ContractRevision.version_number.desc())
    )).scalars().all()
    return [
        {
            "id": r.id,
            "version_number": r.version_number,
            "attempt": r.attempt,
            "template_version": r.template_version,
            "note": r.note,
            "has_docx": bool(r.docx_path),
            "has_pdf": bool(r.pdf_path),
            "created_by_user_id": r.created_by_user_id,
            "created_at": r.created_at,
        }
        for r in rows
    ]


@router.get("/{contract_id}/revisions/{revision_id}/pdf")
async def download_revision_pdf(
    contract_id: int,
    revision_id: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    c: Annotated[Contract, _RequireContractOwner],
):
    # IDOR-гейт: тот же owner/elevated-контроль, что у соседних download_pdf/
    # list_revisions. _RequireContractOwner грузит родительский Contract по
    # contract_id и 403/404'ит чужой; запрос ревизии ниже фильтрует revision_id
    # по contract_id, так что огородить сам Contract достаточно.
    from pathlib import Path
    rev = (await session.execute(
        select(ContractRevision).where(
            ContractRevision.id == revision_id, ContractRevision.contract_id == contract_id
        )
    )).scalar_one_or_none()
    if not rev or not rev.pdf_path or not Path(rev.pdf_path).exists():
        raise HTTPException(404, "PDF версии не найден")
    return FileResponse(rev.pdf_path, filename=f"Договор v{rev.version_number}.pdf", media_type="application/pdf")


@router.get("/{contract_id}/remarks")
async def list_remarks(
    contract_id: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    c: Annotated[Contract, _RequireContractOwner],
):
    rows = (await session.execute(
        select(ContractRemark)
        .where(ContractRemark.contract_id == contract_id)
        .order_by(ContractRemark.created_at.desc())
    )).scalars().all()
    return [
        {
            "id": r.id,
            "attempt": r.attempt,
            "stage_order": r.stage_order,
            "author_user_id": r.author_user_id,
            "text": r.text,
            "is_resolved": r.is_resolved,
            "resolved_at": r.resolved_at,
            "created_at": r.created_at,
        }
        for r in rows
    ]


@router.post("/{contract_id}/remarks/{remark_id}/resolve")
async def resolve_remark(
    contract_id: int,
    remark_id: int,
    current_user: CurrentUser,
    request: Request,
    session: Annotated[AsyncSession, Depends(get_session)],
    c: Annotated[Contract, _RequireContractOwner],
):
    """Автор отмечает замечание как исправленное (или снимает отметку)."""
    remark = (await session.execute(
        select(ContractRemark).where(ContractRemark.id == remark_id, ContractRemark.contract_id == contract_id)
    )).scalar_one_or_none()
    if not remark:
        raise HTTPException(404, "Замечание не найдено")
    # Тоггл
    if remark.is_resolved:
        remark.is_resolved = False
        remark.resolved_at = None
        remark.resolved_by_user_id = None
    else:
        remark.is_resolved = True
        remark.resolved_at = datetime.now(UTC)
        remark.resolved_by_user_id = current_user.id
    await session.commit()
    return {"id": remark.id, "is_resolved": remark.is_resolved}


# ============ Эпик 18 — AI Contract Analysis ============

from app.schemas import (
    ContractAnalysisIssue,
    ContractAnalysisOut,
    ContractAnalysisStandardSection,
)
from app.services.ai_features import analyze_contract
from app.services.anthropic_client import (
    AINotConfiguredError,
    AIResponseError,
    AIServiceError,
)


@router.post("/{contract_id}/ai-analyze", response_model=ContractAnalysisOut)
async def ai_analyze_contract(
    contract_id: int,
    current_user: CurrentUser,
    request: Request,
    session: Annotated[AsyncSession, Depends(get_session)],
    c: Annotated[Contract, _RequireContractOwner],
    force_refresh: bool = Query(
        default=False,
        description=(
            "Если true — игнорировать кэш и пересчитать анализ через Claude. "
            "По умолчанию (false) возвращается кэш, если ему < 1ч."
        ),
    ),
):
    """Запустить AI-анализ договора и вернуть структурированный результат.

    Использует кэш ai_analysis_json (TTL 1ч). force_refresh=true игнорирует кэш.
    503 — если ANTHROPIC_API_KEY не настроен. 502 — если Claude недоступен или
    вернул невалидный JSON. См. anthropic_client.AINotConfiguredError /
    AIServiceError / AIResponseError.
    """
    try:
        result = await analyze_contract(
            session, c,
            user_id=current_user.id,
            force_refresh=force_refresh,
        )
    except AINotConfiguredError:
        # Сначала логируется внутри analyze_contract, потом отдаём 503 caller'у.
        await session.commit()  # flush log row
        raise HTTPException(
            status.HTTP_503_SERVICE_UNAVAILABLE,
            "AI not configured: установите ANTHROPIC_API_KEY на бэкенде",
        )
    except (AIResponseError, AIServiceError) as e:
        await session.commit()
        raise HTTPException(
            status.HTTP_502_BAD_GATEWAY,
            f"AI service error: {e}",
        )

    await _log(
        session, current_user.id, c.id, "ai_analyze",
        {
            "from_cache": result["from_cache"],
            "issues_count": len(result["issues"]),
            "force_refresh": force_refresh,
        },
        _client_ip(request),
    )
    await session.commit()

    return ContractAnalysisOut(
        contract_id=c.id,
        issues=[ContractAnalysisIssue.model_validate(it) for it in result["issues"]],
        standard_sections=[
            ContractAnalysisStandardSection.model_validate(s)
            for s in result["standard_sections"]
        ],
        recommendations=result["recommendations"],
        model=result.get("model"),
        analyzed_at=result.get("analyzed_at"),
        ai_tokens_used=result.get("ai_tokens_used"),
        from_cache=result.get("from_cache", False),
    )
