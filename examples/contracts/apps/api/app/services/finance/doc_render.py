"""Рендер DOCX/PDF финансовых документов — инвойсов и актов (Ф5 / Фаза A).

Переиспользует контрактный pipeline render.py (docxtpl → DOCX → LibreOffice → PDF) и
num_to_words для суммы прописью. Собирает контекст из fin_invoice/fin_act + реквизитов
юрлица (FinLegalEntity + связанный LicensorEntity) + контрагента (Company) + позиций.

ХРАНЕНИЕ ФАЙЛОВ (зеркалит bulk_generator/contracts):
  /data/storage/finance_docs/{kind}/{doc_id}/v{N}/document.docx|.pdf
  где N = новое значение document_file_id (монотонный счётчик версий на документ).
  Идемпотентность: каждая (пере)генерация инкрементит версию и кладёт свежие файлы,
  не затирая прошлые (история по версиям). document_file_id указывает на актуальную.

ПОДПИСЬ (Фаза A, решение): approval-based «подписание». Действие sign проставляет
signed_by_user_id/signed_at и ПЕРЕГЕНЕРИРУЕТ PDF с блоком подписи (ФИО подписанта +
дата + пометка «Подписано в системе MACRO CRM»). Полный OnlyOffice WYSIWYG e-sign —
отложен (под-фаза). Контекст несёт doc.signed/signer_name/signed_date — шаблон сам
рисует подпись либо линию под ручную подпись + М.П.

РЕНДЕР В PDF требует soffice (LibreOffice) — в pytest не гоняется (см. тесты:
проверяем только сборку контекста, а конвертацию — на проде/в Docker, где soffice есть).
"""

from __future__ import annotations

import asyncio
from dataclasses import dataclass
from datetime import date
from decimal import ROUND_HALF_UP, Decimal
from pathlib import Path
from typing import Any

from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from app.config import get_settings
from app.models import (
    Company,
    FinAct,
    FinInvoice,
    FinLegalEntity,
    FinVatRate,
    LicensorEntity,
)
from app.services.num_to_words import amount_to_words_ru
from app.services.render import docx_to_pdf, render_docx

settings = get_settings()

_RU_MONTHS = (
    "", "января", "февраля", "марта", "апреля", "мая", "июня",
    "июля", "августа", "сентября", "октября", "ноября", "декабря",
)


def fmt_date_ru(d: date | None) -> str:
    """Дата в формате «3 июня 2026 г.». None → пустая строка. Pure."""
    if d is None:
        return ""
    return f"{d.day} {_RU_MONTHS[d.month]} {d.year} г."


def fmt_money(value: Decimal | int | float | None) -> str:
    """Денежная сумма с разрядами и 2 знаками: 1234567.5 → «1 234 567,50». Pure."""
    if value is None:
        return "0,00"
    d = Decimal(str(value)).quantize(Decimal("0.01"), rounding=ROUND_HALF_UP)
    neg = d < 0
    whole, frac = divmod(abs(int(d * 100)), 100)
    # Разрядность пробелами.
    grouped = f"{whole:,}".replace(",", " ")
    sign = "-" if neg else ""
    return f"{sign}{grouped},{frac:02d}"


def fmt_qty(value: Decimal | int | float | None) -> str:
    """Кол-во без лишних нулей: 1.0000 → «1», 2.5000 → «2.5». Pure."""
    if value is None:
        return ""
    d = Decimal(str(value)).normalize()
    # normalize() для целых даёт экспоненту (1E+1) — приводим к обычному виду.
    if d == d.to_integral_value():
        return str(int(d))
    return format(d, "f")


# ───────────────────────────── сборка контекста (pure) ─────────────────────────────


@dataclass
class _LineCtx:
    n: int
    name: str
    qty: str
    unit_price: str
    vat_display: str
    vat_amount: str
    amount_net: str
    amount_gross: str


def build_seller_context(le: FinLegalEntity, lic: LicensorEntity | None) -> dict[str, Any]:
    """Реквизиты нашего юрлица. Приоритет — requisites_json юрлица, затем поля
    связанного LicensorEntity. Pure (только объекты на входе)."""
    req: dict[str, Any] = dict(le.requisites_json or {})

    def pick(key: str, lic_attr: str | None = None, default: str = "") -> str:
        if req.get(key):
            return str(req[key])
        if lic is not None and lic_attr is not None:
            v = getattr(lic, lic_attr, None)
            if v:
                return str(v)
        return default

    return {
        "name": le.name,
        "full_name": pick("full_name", "name", le.name),
        "tax_id_label": pick("tax_id_label", "tax_id_label", "ИНН/БИН"),
        "tax_id": pick("tax_id", "tax_id") or (le.tax_id or ""),
        "address": pick("address", "address"),
        "bank": pick("bank", "bank"),
        "bank_code_label": pick("bank_code_label", "bank_code_label", "БИК"),
        "bank_code": pick("bank_code", "bank_code"),
        "account": pick("account", "account"),
        "director_short": pick("director_short", "director_short"),
        "director_position": pick("director_position", "director_position", "Директор"),
        "phone": pick("phone", "phone"),
        "email": pick("email", "email"),
    }


def build_buyer_context(company: Company | None) -> dict[str, Any]:
    """Реквизиты контрагента (Company). Pure."""
    if company is None:
        return {"name": "", "tax_id": "", "address": ""}
    name = company.legal_name or company.name or company.short_name or ""
    return {
        "name": name,
        "tax_id": company.tax_id or "",
        "address": company.address or "",
    }


def _line_ctx(
    n: int,
    *,
    name: str,
    qty: Decimal,
    unit_price: Decimal,
    vat_rate_pct: Decimal | None,
    vat_amount: Decimal,
    amount_net: Decimal,
    amount_gross: Decimal,
) -> _LineCtx:
    """Контекст одной позиции таблицы. Pure."""
    if vat_rate_pct is None or vat_rate_pct == 0:
        vat_display = "Без НДС"
    else:
        rate_str = fmt_qty(vat_rate_pct)
        vat_display = f"{rate_str}% / {fmt_money(vat_amount)}"
    return _LineCtx(
        n=n,
        name=name,
        qty=fmt_qty(qty),
        unit_price=fmt_money(unit_price),
        vat_display=vat_display,
        vat_amount=fmt_money(vat_amount),
        amount_net=fmt_money(amount_net),
        amount_gross=fmt_money(amount_gross),
    )


def build_doc_context(
    *,
    number: str | None,
    issue_date: date,
    due_date: date | None,
    currency: str,
    purpose: str | None,
    amount_net: Decimal,
    vat_amount: Decimal,
    amount_gross: Decimal,
    period: str | None = None,
    signed: bool = False,
    signer_name: str | None = None,
    signed_date: date | None = None,
) -> dict[str, Any]:
    """Шапка/итоги документа + сумма прописью. Pure (без БД).

    amount_in_words считается от gross (всего к оплате) с подстановкой валюты.
    """
    return {
        "number": number or "(без номера)",
        "date": fmt_date_ru(issue_date),
        "due_date": fmt_date_ru(due_date),
        "currency": currency,
        "purpose": purpose or "",
        "period": period or "",
        "amount_net": fmt_money(amount_net),
        "vat_amount": fmt_money(vat_amount),
        "amount_gross": fmt_money(amount_gross),
        "amount_in_words": amount_to_words_ru(amount_gross, currency),
        "signed": signed,
        "signer_name": signer_name or "",
        "signed_date": fmt_date_ru(signed_date),
    }


# ───────────────────────────── async-сборка из БД ─────────────────────────────


async def _vat_rate_pct(session: AsyncSession, vat_rate_id: int | None) -> Decimal | None:
    if vat_rate_id is None:
        return None
    vr = (
        await session.execute(select(FinVatRate).where(FinVatRate.id == vat_rate_id))
    ).scalar_one_or_none()
    return vr.rate_pct if vr is not None else None


async def _seller_and_buyer(
    session: AsyncSession, legal_entity_id: int, company_id: int
) -> tuple[dict[str, Any], dict[str, Any]]:
    le = (
        await session.execute(
            select(FinLegalEntity).where(FinLegalEntity.id == legal_entity_id)
        )
    ).scalar_one()
    lic = None
    if le.licensor_entity_id is not None:
        lic = (
            await session.execute(
                select(LicensorEntity).where(LicensorEntity.id == le.licensor_entity_id)
            )
        ).scalar_one_or_none()
    company = (
        await session.execute(select(Company).where(Company.id == company_id))
    ).scalar_one_or_none()
    return build_seller_context(le, lic), build_buyer_context(company)


async def build_invoice_context(
    session: AsyncSession, invoice: FinInvoice, *, signer_name: str | None = None
) -> dict[str, Any]:
    """Полный jinja-контекст для invoice.docx. invoice.lines должны быть загружены."""
    seller, buyer = await _seller_and_buyer(
        session, invoice.legal_entity_id, invoice.counterparty_company_id
    )
    lines: list[dict[str, Any]] = []
    has_vat = False
    sorted_lines = sorted(invoice.lines, key=lambda x: (x.sort_order, x.id or 0))
    for i, ln in enumerate(sorted_lines, start=1):
        rate = await _vat_rate_pct(session, ln.vat_rate_id)
        if rate and rate != 0:
            has_vat = True
        ctx = _line_ctx(
            i, name=ln.name, qty=ln.qty, unit_price=ln.unit_price,
            vat_rate_pct=rate, vat_amount=ln.vat_amount,
            amount_net=ln.amount_net, amount_gross=ln.amount_gross,
        )
        lines.append(ctx.__dict__)
    signed = getattr(invoice, "signed_at", None) is not None
    doc = build_doc_context(
        number=invoice.number, issue_date=invoice.issue_date, due_date=invoice.due_date,
        currency=invoice.currency, purpose=invoice.purpose,
        amount_net=invoice.amount_net, vat_amount=invoice.vat_amount,
        amount_gross=invoice.amount_gross,
        signed=signed, signer_name=signer_name,
        signed_date=getattr(invoice, "signed_at", None) and invoice.signed_at.date(),
    )
    return {"doc": doc, "seller": seller, "buyer": buyer, "lines": lines, "has_vat": has_vat}


async def build_act_context(
    session: AsyncSession, act: FinAct, *, signer_name: str | None = None
) -> dict[str, Any]:
    """Полный jinja-контекст для act.docx. act.lines должны быть загружены."""
    seller, buyer = await _seller_and_buyer(
        session, act.legal_entity_id, act.counterparty_company_id
    )
    lines: list[dict[str, Any]] = []
    has_vat = False
    sorted_lines = sorted(act.lines, key=lambda x: (x.sort_order, x.id or 0))
    for i, ln in enumerate(sorted_lines, start=1):
        rate = await _vat_rate_pct(session, ln.vat_rate_id)
        if rate and rate != 0:
            has_vat = True
        ctx = _line_ctx(
            i, name=ln.name, qty=ln.qty, unit_price=ln.unit_price,
            vat_rate_pct=rate, vat_amount=ln.vat_amount,
            amount_net=ln.amount_net, amount_gross=ln.amount_gross,
        )
        lines.append(ctx.__dict__)
    period = None
    if act.period_year and act.period_month:
        period = f"{_RU_MONTHS[act.period_month]} {act.period_year}"
    signed = act.signed_at is not None
    doc = build_doc_context(
        number=act.number, issue_date=act.act_date, due_date=None,
        currency=act.currency, purpose=act.purpose,
        amount_net=act.amount_net, vat_amount=act.vat_amount,
        amount_gross=act.amount_gross, period=period,
        signed=signed, signer_name=signer_name,
        signed_date=act.signed_at and act.signed_at.date(),
    )
    return {"doc": doc, "seller": seller, "buyer": buyer, "lines": lines, "has_vat": has_vat}


# ───────────────────────────── шаблоны / пути файлов ─────────────────────────────


def finance_template_path(kind: str) -> Path:
    """Путь к docxtpl-шаблону. kind in {'invoice','act'}.

    Приоритет:
    1. /data/storage/templates/finance/{kind}.docx (overlay — загружен через UI)
    2. {templates_dir}/finance/{kind}.docx (из репо, монтируется в контейнер)
    """
    overlay = settings.storage_dir / "templates" / "finance" / f"{kind}.docx"
    if overlay.exists():
        return overlay
    return settings.templates_dir / "finance" / f"{kind}.docx"


def _doc_dir_path(kind: str, doc_id: int, version: int) -> Path:
    """Каталог версии документа (БЕЗ создания на диске — pure, для path-getters)."""
    return settings.storage_dir / "finance_docs" / kind / str(doc_id) / f"v{version}"


def _doc_dir(kind: str, doc_id: int, version: int) -> Path:
    """Каталог версии + mkdir (для записи при рендере)."""
    out = _doc_dir_path(kind, doc_id, version)
    out.mkdir(parents=True, exist_ok=True)
    return out


def doc_pdf_path(kind: str, doc_id: int, version: int) -> Path:
    return _doc_dir_path(kind, doc_id, version) / "document.pdf"


def doc_docx_path(kind: str, doc_id: int, version: int) -> Path:
    return _doc_dir_path(kind, doc_id, version) / "document.docx"


class RenderUnavailable(RuntimeError):
    """Шаблон не найден / soffice недоступен — рендер невозможен (→ 422/500 в роутере)."""


def regen_blocked_for_signed(signed_at: Any) -> bool:
    """True, если документ подписан (signed_at проставлен) — публичная перегенерация
    через эндпоинт /generate запрещена (иммутабельность подписи). Pure.

    ВАЖНО: внутренний рендер в /sign вызывает generate_*_document НАПРЯМУЮ (минуя
    эндпоинт-guard), поэтому установка signed_at + перегенерация в одной транзакции
    легитимна и этим предикатом НЕ блокируется.
    """
    return signed_at is not None


def render_finance_doc(
    *,
    kind: str,
    doc_id: int,
    version: int,
    context: dict[str, Any],
) -> tuple[Path, Path]:
    """Отрендерить (docx + pdf) финансовый документ из готового контекста.

    Возвращает (docx_path, pdf_path). Бросает RenderUnavailable, если шаблона нет.
    Конвертация в PDF требует soffice — RuntimeError из docx_to_pdf пробрасывается.
    """
    template_path = finance_template_path(kind)
    if not template_path.exists():
        raise RenderUnavailable(f"Шаблон {kind} не найден: {template_path}")

    out_dir = _doc_dir(kind, doc_id, version)
    docx_path = out_dir / "document.docx"
    # render_docx ожидает (product, country, licensor, contract_data) — но реально
    # просто разворачивает их в jinja-namespace. Для финансовых шаблонов передаём
    # наш контекст через contract_data (он мерджится в корень), остальные — пустые.
    render_docx(
        template_path=template_path,
        product={},
        country={},
        licensor={},
        contract_data=context,
        output_path=docx_path,
    )
    pdf_path = docx_to_pdf(docx_path, out_dir)
    return docx_path, pdf_path


async def generate_invoice_document(
    session: AsyncSession, invoice: FinInvoice, *, signer_name: str | None = None
) -> int:
    """Сгенерировать DOCX+PDF инвойса, проставить document_file_id (+1) и amount_in_words.

    Возвращает новый document_file_id (= версия). НЕ коммитит. Идемпотентно-версионно:
    каждый вызов кладёт новую версию, document_file_id указывает на актуальную.
    """
    version = (invoice.document_file_id or 0) + 1
    context = await build_invoice_context(session, invoice, signer_name=signer_name)
    # Heavy sync (docxtpl render + soffice subprocess до 120с) — в отдельный поток,
    # чтобы не блокировать event loop uvicorn-воркера (CLAUDE.md).
    await asyncio.to_thread(
        render_finance_doc, kind="invoice", doc_id=invoice.id, version=version, context=context
    )
    invoice.document_file_id = version
    invoice.amount_in_words = context["doc"]["amount_in_words"]
    await session.flush()
    return version


async def generate_act_document(
    session: AsyncSession, act: FinAct, *, signer_name: str | None = None
) -> int:
    """Сгенерировать DOCX+PDF акта, проставить document_file_id (+1) и amount_in_words. НЕ коммитит."""
    version = (act.document_file_id or 0) + 1
    context = await build_act_context(session, act, signer_name=signer_name)
    # Heavy sync (docxtpl render + soffice subprocess до 120с) — в отдельный поток,
    # чтобы не блокировать event loop uvicorn-воркера (CLAUDE.md).
    await asyncio.to_thread(
        render_finance_doc, kind="act", doc_id=act.id, version=version, context=context
    )
    act.document_file_id = version
    act.amount_in_words = context["doc"]["amount_in_words"]
    await session.flush()
    return version
