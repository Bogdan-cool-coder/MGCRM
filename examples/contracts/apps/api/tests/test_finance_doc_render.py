"""Pure-function тесты рендера финансовых документов (Ф5 / Фаза A), без DB fixture.

Покрыто:
  • форматтеры fmt_date_ru / fmt_money / fmt_qty (разрядность, знак, нормализация);
  • build_seller_context — приоритет requisites_json над LicensorEntity, фолбэки;
  • build_buyer_context — legal_name/name/short_name приоритет;
  • build_doc_context — сумма прописью (gross), флаги подписи, период;
  • _line_ctx — «Без НДС» vs «12% / сумма», has_vat-детект;
  • пути файлов doc_pdf_path/doc_docx_path версионируются.

РЕНДЕР DOCX→PDF (render_finance_doc) НЕ гоняем — нужен soffice (LibreOffice),
которого нет в pytest-окружении. Конвертация проверяется на проде/в Docker.
"""

from __future__ import annotations

from datetime import date
from decimal import Decimal
from types import SimpleNamespace

from app.services.finance.doc_render import (
    _line_ctx,
    build_buyer_context,
    build_doc_context,
    build_seller_context,
    doc_docx_path,
    doc_pdf_path,
    fmt_date_ru,
    fmt_money,
    fmt_qty,
    regen_blocked_for_signed,
)

# ───────────────────────────── форматтеры ─────────────────────────────


def test_fmt_date_ru():
    assert fmt_date_ru(date(2026, 6, 3)) == "3 июня 2026 г."
    assert fmt_date_ru(date(2026, 12, 31)) == "31 декабря 2026 г."
    assert fmt_date_ru(None) == ""


def test_fmt_money_grouping_and_decimals():
    assert fmt_money(Decimal("1234567.5")) == "1 234 567,50"
    assert fmt_money(Decimal("0")) == "0,00"
    assert fmt_money(Decimal("100")) == "100,00"
    assert fmt_money(None) == "0,00"


def test_fmt_money_negative():
    assert fmt_money(Decimal("-1500.25")) == "-1 500,25"


def test_fmt_money_rounding_half_up():
    # 2 знака с округлением.
    assert fmt_money(Decimal("12.005")) == "12,01"


def test_fmt_qty_strips_trailing_zeros():
    assert fmt_qty(Decimal("1.0000")) == "1"
    assert fmt_qty(Decimal("2.5000")) == "2.5"
    assert fmt_qty(Decimal("10")) == "10"
    assert fmt_qty(None) == ""


# ───────────────────────────── seller / buyer ─────────────────────────────


def test_build_seller_context_prefers_requisites_json():
    le = SimpleNamespace(
        name="ТОО MACRO",
        tax_id="OLD",
        requisites_json={
            "full_name": "ТОО MACRO Global Technologies",
            "tax_id_label": "БИН",
            "tax_id": "123456789",
            "address": "Алматы, пр. Достык 1",
            "bank": "Kaspi Bank",
            "bank_code": "CASPKZKA",
            "account": "KZ1234567890",
            "director_short": "И. Иванов",
        },
    )
    ctx = build_seller_context(le, lic=None)
    assert ctx["full_name"] == "ТОО MACRO Global Technologies"
    assert ctx["tax_id"] == "123456789"
    assert ctx["bank"] == "Kaspi Bank"
    assert ctx["director_short"] == "И. Иванов"


def test_build_seller_context_falls_back_to_licensor():
    le = SimpleNamespace(name="ТОО MACRO", tax_id=None, requisites_json=None)
    lic = SimpleNamespace(
        name="ТОО MACRO Global",
        tax_id_label="БИН",
        tax_id="999",
        address="Астана",
        bank="Halyk",
        bank_code_label="БИК",
        bank_code="HLYK",
        account="KZ999",
        director_short="П. Петров",
        director_position="Генеральный директор",
        phone="+7700",
        email="x@y.kz",
    )
    ctx = build_seller_context(le, lic=lic)
    assert ctx["tax_id"] == "999"
    assert ctx["address"] == "Астана"
    assert ctx["director_position"] == "Генеральный директор"


def test_build_seller_context_tax_id_label_default():
    le = SimpleNamespace(name="X", tax_id="55", requisites_json=None)
    ctx = build_seller_context(le, lic=None)
    assert ctx["tax_id_label"] == "ИНН/БИН"
    assert ctx["tax_id"] == "55"  # из le.tax_id когда requisites/lic пусты


def test_build_buyer_context_name_priority():
    c = SimpleNamespace(legal_name="ООО «Клиент»", name="Клиент", short_name="К", tax_id="77", address="Москва")
    ctx = build_buyer_context(c)
    assert ctx["name"] == "ООО «Клиент»"
    assert ctx["tax_id"] == "77"
    assert ctx["address"] == "Москва"


def test_build_buyer_context_none():
    ctx = build_buyer_context(None)
    assert ctx == {"name": "", "tax_id": "", "address": ""}


# ───────────────────────────── doc-контекст ─────────────────────────────


def test_build_doc_context_amount_in_words_from_gross():
    ctx = build_doc_context(
        number="INV-1", issue_date=date(2026, 6, 3), due_date=None,
        currency="KZT", purpose="Подписка",
        amount_net=Decimal("100000"), vat_amount=Decimal("12000"),
        amount_gross=Decimal("112000"),
    )
    assert ctx["amount_gross"] == "112 000,00"
    # сумма прописью считается от gross + валюта подставлена.
    assert "тенге" in ctx["amount_in_words"]
    assert ctx["signed"] is False
    assert ctx["signer_name"] == ""


def test_build_doc_context_signed():
    ctx = build_doc_context(
        number="ACT-1", issue_date=date(2026, 6, 3), due_date=None,
        currency="RUB", purpose=None,
        amount_net=Decimal("80000"), vat_amount=Decimal("0"),
        amount_gross=Decimal("80000"),
        signed=True, signer_name="Богдан Я.", signed_date=date(2026, 6, 4),
    )
    assert ctx["signed"] is True
    assert ctx["signer_name"] == "Богдан Я."
    assert ctx["signed_date"] == "4 июня 2026 г."


def test_build_doc_context_number_fallback():
    ctx = build_doc_context(
        number=None, issue_date=date(2026, 1, 1), due_date=None,
        currency="RUB", purpose=None,
        amount_net=Decimal("0"), vat_amount=Decimal("0"), amount_gross=Decimal("0"),
    )
    assert ctx["number"] == "(без номера)"


# ───────────────────────────── line-контекст ─────────────────────────────


def test_line_ctx_no_vat():
    li = _line_ctx(
        1, name="Лицензия", qty=Decimal("1"), unit_price=Decimal("100000"),
        vat_rate_pct=Decimal("0"), vat_amount=Decimal("0"),
        amount_net=Decimal("100000"), amount_gross=Decimal("100000"),
    )
    assert li.vat_display == "Без НДС"
    assert li.amount_gross == "100 000,00"
    assert li.n == 1


def test_line_ctx_with_vat():
    li = _line_ctx(
        2, name="Внедрение", qty=Decimal("2"), unit_price=Decimal("50000"),
        vat_rate_pct=Decimal("12"), vat_amount=Decimal("12000"),
        amount_net=Decimal("100000"), amount_gross=Decimal("112000"),
    )
    assert li.vat_display == "12% / 12 000,00"
    assert li.qty == "2"
    assert li.unit_price == "50 000,00"


def test_line_ctx_vat_rate_none_is_no_vat():
    li = _line_ctx(
        1, name="X", qty=Decimal("1"), unit_price=Decimal("10"),
        vat_rate_pct=None, vat_amount=Decimal("0"),
        amount_net=Decimal("10"), amount_gross=Decimal("10"),
    )
    assert li.vat_display == "Без НДС"


# ───────────────────────────── пути файлов ─────────────────────────────


def _with_tmp_storage(tmp_path, monkeypatch):
    """Подменить storage_path на tmp, чтобы path-getters не трогали /data/storage."""
    from app.services.finance import doc_render as dr

    monkeypatch.setattr(dr.settings, "storage_path", str(tmp_path))


def test_doc_paths_versioned_and_distinct(tmp_path, monkeypatch):
    _with_tmp_storage(tmp_path, monkeypatch)
    p1 = doc_pdf_path("invoice", 42, 1)
    p2 = doc_pdf_path("invoice", 42, 2)
    assert p1 != p2
    assert p1.name == "document.pdf"
    assert "v1" in str(p1) and "v2" in str(p2)
    assert "invoice" in str(p1) and "42" in str(p1)


def test_doc_docx_path_extension(tmp_path, monkeypatch):
    _with_tmp_storage(tmp_path, monkeypatch)
    p = doc_docx_path("act", 7, 3)
    assert p.name == "document.docx"
    assert "act" in str(p) and "v3" in str(p)


# ───────────── иммутабельность подписи: guard перегенерации (C-2) ─────────────


def test_regen_blocked_for_signed_predicate():
    import datetime as _dt

    # Не подписан → перегенерация разрешена.
    assert regen_blocked_for_signed(None) is False
    # Подписан → перегенерация через /generate запрещена.
    assert regen_blocked_for_signed(_dt.datetime(2026, 6, 3, 12, 0)) is True


def test_generate_endpoints_guard_signed_docs_with_409():
    """Эндпоинты /generate для инвойса и акта должны отклонять подписанный
    документ 409 (иммутабельность подписи). Проверяем по исходнику эндпоинта."""
    import inspect

    from app.routers import finance as fin

    for fn in (fin.generate_invoice_doc_ep, fin.generate_act_doc_ep):
        src = inspect.getsource(fn)
        assert "regen_blocked_for_signed" in src, fn.__name__
        assert "409" in src and "перегенерация запрещена" in src, fn.__name__


def test_sign_endpoints_bypass_regen_guard_and_call_generate():
    """Эндпоинты /sign НЕ содержат guard перегенерации (он бы заблокировал
    собственный рендер с блоком подписи) и вызывают generate напрямую — флоу подписи
    остаётся рабочим даже после установки signed_at в той же транзакции."""
    import inspect

    from app.routers import finance as fin

    inv_src = inspect.getsource(fin.sign_invoice_ep)
    assert "regen_blocked_for_signed" not in inv_src
    assert "generate_invoice_document" in inv_src
    # signed_at проставляется ДО рендера — иначе блок подписи не попал бы в PDF.
    assert "inv.signed_at = " in inv_src

    act_src = inspect.getsource(fin.sign_act_ep)
    assert "regen_blocked_for_signed" not in act_src
    assert "generate_act_document" in act_src
    assert "act.signed_at = " in act_src


def test_generate_document_functions_offload_render_to_thread():
    """C-1: тяжёлый sync-рендер обёрнут в asyncio.to_thread в обеих generate-функциях
    (event loop uvicorn не блокируется)."""
    import inspect

    from app.services.finance import doc_render as dr

    for fn in (dr.generate_invoice_document, dr.generate_act_document):
        src = inspect.getsource(fn)
        assert "asyncio.to_thread" in src, fn.__name__
        assert "render_finance_doc" in src, fn.__name__
