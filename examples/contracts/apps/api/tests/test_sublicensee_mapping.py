"""CONTACTS 2.0 Ф3-A: маппинг реквизитов стороны договора → {{ sublicensee.* }}.

Pure-function (без БД): проверяем, что Company даёт ту же структуру шаблонных
переменных, что и legacy Counterparty, и что fallback-значения совпадают.
"""

from app.models import Company, Counterparty
from app.routers.contracts import (
    _sublicensee_from_company,
    _sublicensee_from_counterparty,
)

# Ключи, которые master_skeleton.docx ожидает в namespace {{ sublicensee.* }}.
EXPECTED_KEYS = {
    "full_legal_form", "legal_form", "gender_ending_ое", "name",
    "director_position", "director_genitive", "director_short", "acts_basis",
    "tax_id_label", "tax_id", "address", "bank", "bank_code_label",
    "bank_code", "account", "phone", "email", "website",
}


def _full_company() -> Company:
    return Company(
        legal_name='ТОО "Застройщик Плюс"',
        short_name="ЗастройщикPlus",
        name="ЗастройщикPlus",
        full_legal_form="Товарищество с ограниченной ответственностью",
        legal_form="ТОО",
        gender_ending_oe="ое",
        country="KZ",
        country_code="kz",
        director_position="Генеральный директор",
        director_genitive="Иванова Ивана Ивановича",
        director_short="Иванов И.И.",
        acts_basis="Устава",
        tax_id_label="БИН",
        tax_id="123456789012",
        address="г. Алматы, ул. Абая, 1",
        bank="АО Kaspi Bank",
        bank_code_label="БИК",
        bank_code="CASPKZKA",
        account="KZ123456789012345678",
        phone="+7 700 000 00 00",
        email="info@example.kz",
        website="https://example.kz",
    )


def _full_counterparty() -> Counterparty:
    return Counterparty(
        name="ЗастройщикPlus",
        full_legal_form="Товарищество с ограниченной ответственностью",
        legal_form="ТОО",
        gender_ending_oe="ое",
        country_code="kz",
        director_position="Генеральный директор",
        director_genitive="Иванова Ивана Ивановича",
        director_short="Иванов И.И.",
        acts_basis="Устава",
        tax_id_label="БИН",
        tax_id="123456789012",
        address="г. Алматы, ул. Абая, 1",
        bank="АО Kaspi Bank",
        bank_code_label="БИК",
        bank_code="CASPKZKA",
        account="KZ123456789012345678",
        phone="+7 700 000 00 00",
        email="info@example.kz",
        website="https://example.kz",
    )


def test_company_mapping_has_exact_template_keys():
    ctx = _sublicensee_from_company(_full_company())
    assert set(ctx.keys()) == EXPECTED_KEYS


def test_company_mapping_equals_counterparty_for_same_data():
    """Одинаковые реквизиты в Company и Counterparty → идентичный sublicensee."""
    assert _sublicensee_from_company(_full_company()) == _sublicensee_from_counterparty(
        _full_counterparty()
    )


def test_company_name_uses_common_name_first():
    co = _full_company()
    assert _sublicensee_from_company(co)["name"] == "ЗастройщикPlus"


def test_company_name_falls_back_to_short_then_legal():
    co = Company(legal_name='ООО "Ромашка"', short_name="Ромашка", name=None)
    assert _sublicensee_from_company(co)["name"] == "Ромашка"

    co2 = Company(legal_name='ООО "Ромашка"', short_name=None, name=None)
    assert _sublicensee_from_company(co2)["name"] == 'ООО "Ромашка"'


def test_company_empty_requisites_use_safe_defaults():
    co = Company(legal_name="X", name="X")
    ctx = _sublicensee_from_company(co)
    # Дефолты совпадают с counterparty-маппингом.
    assert ctx["gender_ending_ое"] == "ое"
    assert ctx["director_position"] == "Директор"
    assert ctx["acts_basis"] == "Устава"
    # Остальные опциональные поля — пустые строки, не None (docxtpl-friendly).
    assert ctx["tax_id"] == ""
    assert ctx["bank"] == ""
    assert all(v is not None for v in ctx.values())
