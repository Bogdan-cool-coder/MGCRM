"""CONTACTS 2.0 — резолв стороны договора и маппинг её реквизитов в шаблон.

Единый источник логики «кто сторона договора и какие у неё реквизиты». До Ф4
эти функции жили в `routers/contracts.py`; bulk_generator держал собственную
копию маппинга. Чтобы не плодить дубли (риск рассинхрона набора шаблонных
переменных `{{ sublicensee.* }}`), всё вынесено сюда.

Источник истины — Company (она поглотила реквизиты в Ф3). Counterparty оставлен
дублирующим зеркалом (`Company.counterparty_id` ↔ `Counterparty.id`, оба id
заполнены), и используется ТОЛЬКО как legacy-фолбэк, если Company не нашлась.

Импортёры:
  - routers/contracts.py (реэкспортирует имена для обратной совместимости тестов)
  - services/bulk_generator.py (пакетная генерация)
  - services/renewal.py (отображение клиента / будущая авто-генерация)
"""
from __future__ import annotations

from typing import Any

from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.models import Company, Counterparty


def sublicensee_from_counterparty(cp: Counterparty) -> dict[str, Any]:
    """Маппинг полей контрагента → переменные {{ sublicensee.* }} в шаблоне.

    Legacy-путь: используется только когда Company-зеркало не нашлось.
    """
    return {
        "full_legal_form": cp.full_legal_form or "",
        "legal_form": cp.legal_form or "",
        "gender_ending_ое": cp.gender_ending_oe or "ое",
        "name": cp.name or "",
        "director_position": cp.director_position or "Директор",
        "director_genitive": cp.director_genitive or "",
        "director_short": cp.director_short or "",
        "acts_basis": cp.acts_basis or "Устава",
        "tax_id_label": cp.tax_id_label or "",
        "tax_id": cp.tax_id or "",
        "address": cp.address or "",
        "bank": cp.bank or "",
        "bank_code_label": cp.bank_code_label or "",
        "bank_code": cp.bank_code or "",
        "account": cp.account or "",
        "phone": cp.phone or "",
        "email": cp.email or "",
        "website": cp.website or "",
    }


def sublicensee_from_company(co: Company) -> dict[str, Any]:
    """Маппинг реквизитов Company → переменные {{ sublicensee.* }}.

    Зеркалит структуру `sublicensee_from_counterparty`, чтобы шаблонные
    переменные master_skeleton.docx остались теми же. `name` берём из обиходного
    Company.name (заполнен из Counterparty.name миграцией Ф0); fallback на
    short_name/legal_name, чтобы поле не оказалось пустым.
    """
    name = co.name or co.short_name or co.legal_name or ""
    return {
        "full_legal_form": co.full_legal_form or "",
        "legal_form": co.legal_form or "",
        "gender_ending_ое": co.gender_ending_oe or "ое",
        "name": name,
        "director_position": co.director_position or "Директор",
        "director_genitive": co.director_genitive or "",
        "director_short": co.director_short or "",
        "acts_basis": co.acts_basis or "Устава",
        "tax_id_label": co.tax_id_label or "",
        "tax_id": co.tax_id or "",
        "address": co.address or "",
        "bank": co.bank or "",
        "bank_code_label": co.bank_code_label or "",
        "bank_code": co.bank_code or "",
        "account": co.account or "",
        "phone": co.phone or "",
        "email": co.email or "",
        "website": co.website or "",
    }


async def resolve_party(
    session: AsyncSession,
    *,
    company_id: int | None,
    counterparty_id: int | None,
) -> tuple[Company | None, Counterparty | None, dict[str, Any] | None]:
    """Единая точка резолва стороны договора.

    Источник истины — Company. Цепочка фолбэков (генерация НЕ должна упасть):
      1. Company по company_id;
      2. Company-зеркало по counterparty_id (Company.counterparty_id == ...);
      3. Counterparty по counterparty_id (legacy путь для старых сущностей).

    Возвращает (company, counterparty, sublicensee_dict). sublicensee_dict — None,
    если ни одной стороны не нашли (вызывающий не должен затирать существующий
    context). counterparty всегда дорезолвливается (для зеркалирования id и
    legacy-кода, читающего counterparty_id).
    """
    company: Company | None = None
    counterparty: Counterparty | None = None

    if company_id is not None:
        company = (await session.execute(
            select(Company).where(Company.id == company_id)
        )).scalar_one_or_none()

    if company is None and counterparty_id is not None:
        # Зеркало: Company, привязанная к этому контрагенту.
        company = (await session.execute(
            select(Company).where(Company.counterparty_id == counterparty_id)
        )).scalars().first()

    # Дорезолвим counterparty: из company-зеркала либо по явному id.
    cp_id = (company.counterparty_id if company else None) or counterparty_id
    if cp_id is not None:
        counterparty = (await session.execute(
            select(Counterparty).where(Counterparty.id == cp_id)
        )).scalar_one_or_none()

    if company is not None:
        return company, counterparty, sublicensee_from_company(company)
    if counterparty is not None:
        return None, counterparty, sublicensee_from_counterparty(counterparty)
    return None, None, None
