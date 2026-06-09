"""CONTACTS 2.0 mirror-инвариант — pure-function проверки company→counterparty
маппинга + структуры backfill-миграции 0073.

Без БД-фикстуры: проверяем faithful-mirror (поля копируются 1:1), заполнение
NOT NULL колонок Counterparty (name, country_code) и наличие advisory-lock +
идемпотентности в миграции 0073.
"""
from __future__ import annotations

from pathlib import Path

from app.services.contacts_v2 import (
    MIRROR_DEFAULT_COUNTRY_CODE,
    company_to_counterparty_fields,
)

_MIGRATION_0073 = (
    Path(__file__).resolve().parents[1]
    / "alembic" / "versions" / "0073_company_cp_mirror.py"
)


# ============ pure-function: company → counterparty fields ============

def test_mirror_name_from_common_name():
    """name зеркала ← company.name (обиходное), приоритет над legal_name."""
    out = company_to_counterparty_fields(
        {"name": "ПТС Казахстан", "legal_name": "ТОО ПТС"}
    )
    assert out["name"] == "ПТС Казахстан"


def test_mirror_name_falls_back_to_legal_name():
    """Если name пуст — берём legal_name."""
    out = company_to_counterparty_fields({"name": "  ", "legal_name": "ТОО Альфа"})
    assert out["name"] == "ТОО Альфа"


def test_mirror_name_never_empty():
    """name — NOT NULL у Counterparty: при отсутствии обоих имён ставим '—'."""
    out = company_to_counterparty_fields({})
    assert out["name"] == "—"


def test_mirror_country_code_from_country_code():
    """country_code зеркала ← company.country_code (приоритет)."""
    out = company_to_counterparty_fields({"country_code": "uz", "country": "KZ"})
    assert out["country_code"] == "UZ"


def test_mirror_country_code_falls_back_to_country():
    """Если country_code пуст — берём company.country (источник истины)."""
    out = company_to_counterparty_fields({"country_code": None, "country": "kz"})
    assert out["country_code"] == "KZ"


def test_mirror_country_code_default_when_empty():
    """Краевой случай: ни country, ни country_code → безопасный дефолт (NOT NULL)."""
    out = company_to_counterparty_fields({"name": "Тест"})
    assert out["country_code"] == MIRROR_DEFAULT_COUNTRY_CODE
    assert out["country_code"] == "KZ"


def test_mirror_country_code_truncated_to_two_chars():
    """country_code зеркала ≤2 символов (VARCHAR(2))."""
    out = company_to_counterparty_fields({"country": "KAZ"})
    assert len(out["country_code"]) == 2


def test_mirror_copies_requisites_faithfully():
    """Реквизиты копируются 1:1 из одноимённых полей company."""
    company = {
        "name": "ПТС",
        "legal_name": "ТОО ПТС",
        "country": "KZ",
        "legal_form": "ТОО",
        "full_legal_form": "Товарищество с ограниченной ответственностью",
        "gender_ending_oe": "ое",
        "director_position": "Директор",
        "director_genitive": "Иванова Ивана Ивановича",
        "director_short": "Иванов И.И.",
        "acts_basis": "Устава",
        "tax_id": "123456789012",
        "tax_id_label": "БИН",
        "address": "г. Алматы, ул. Абая 1",
        "bank": "АО Каспи Банк",
        "bank_code_label": "БИК",
        "bank_code": "CASPKZKA",
        "account": "KZ123456789",
        "phone": "+77001234567",
        "email": "info@pts.kz",
        "website": "pts.kz",
        "group_id": 7,
        "category_code": "L",
        "owner_user_id": 3,
        "responsible_user_id": 5,
        "department_id": 2,
        "city": "Алматы",
    }
    out = company_to_counterparty_fields(company)
    for f in (
        "legal_form", "full_legal_form", "gender_ending_oe",
        "director_position", "director_genitive", "director_short", "acts_basis",
        "tax_id", "tax_id_label", "address",
        "bank", "bank_code_label", "bank_code", "account",
        "phone", "email", "website",
        "group_id", "category_code",
        "owner_user_id", "responsible_user_id", "department_id", "city",
    ):
        assert out[f] == company[f], f"поле {f} должно копироваться 1:1"


# ============ for_sync: ownership/scope не перетирается при PATCH компании ============

_OWNERSHIP_FIELDS = ("owner_user_id", "responsible_user_id", "department_id", "group_id")


def test_mirror_create_includes_ownership():
    """create-путь (for_sync=False, дефолт): ownership/scope копируется как стартовое."""
    company = {
        "name": "ПТС", "country": "KZ",
        "owner_user_id": 3, "responsible_user_id": 5, "department_id": 2, "group_id": 7,
    }
    out = company_to_counterparty_fields(company)
    for f in _OWNERSHIP_FIELDS:
        assert out[f] == company[f], f"при создании зеркала {f} копируется"


def test_mirror_sync_excludes_ownership():
    """sync-путь (for_sync=True): ownership/scope НЕ попадают в выход — зеркало
    их сохраняет за CS-стороной (visibility scope / KPI / salary-атрибуция)."""
    company = {
        "name": "ПТС", "country": "KZ",
        "owner_user_id": 99, "responsible_user_id": 88, "department_id": 77, "group_id": 66,
    }
    out = company_to_counterparty_fields(company, for_sync=True)
    for f in _OWNERSHIP_FIELDS:
        assert f not in out, (
            f"при синхронизации зеркала {f} НЕ должен перетирать CS-сторону"
        )


def test_mirror_sync_still_copies_requisites():
    """sync-путь продолжает синхронизировать display/реквизиты (PATCH телефона и т.п.)."""
    company = {
        "name": "ПТС-2", "country": "KZ",
        "phone": "+77009998877", "email": "new@pts.kz", "address": "новый адрес",
        "tax_id": "999", "bank": "Halyk", "account": "KZ999", "city": "Астана",
        "category_code": "M",
        # ownership присутствует во входе, но не должен попасть в выход
        "owner_user_id": 1, "department_id": 1, "group_id": 1,
    }
    out = company_to_counterparty_fields(company, for_sync=True)
    for f in ("phone", "email", "address", "tax_id", "bank", "account", "city", "category_code"):
        assert out[f] == company[f], f"реквизит {f} синхронизируется при PATCH"
    # NOT NULL гарантии сохраняются
    assert out["name"] == "ПТС-2"
    assert out["country_code"] == "KZ"


def test_mirror_sync_preserves_not_null_columns():
    """for_sync=True по-прежнему заполняет NOT NULL name/country_code."""
    out = company_to_counterparty_fields({}, for_sync=True)
    assert out["name"] == "—"
    assert out["country_code"] == MIRROR_DEFAULT_COUNTRY_CODE


def test_mirror_output_only_counterparty_columns():
    """Зеркало не тянет company-only поля (legal_name/country/industry/tags и т.п.)."""
    out = company_to_counterparty_fields(
        {"name": "X", "legal_name": "Y", "country": "KZ", "industry": "IT"}
    )
    assert "legal_name" not in out
    assert "country" not in out  # у Counterparty только country_code
    assert "industry" not in out


# ============ router helper: company_to_dict ============

def test_router_company_to_dict_imports_and_covers_fields():
    """company_to_dict (router) собирает все поля, нужные pure-function мапперу."""
    from app.routers.companies import _MIRROR_SOURCE_FIELDS

    class _Stub:
        pass

    stub = _Stub()
    for f in _MIRROR_SOURCE_FIELDS:
        setattr(stub, f, f"v_{f}")
    from app.routers.companies import company_to_dict

    d = company_to_dict(stub)  # type: ignore[arg-type]
    # все исходные поля присутствуют
    for f in _MIRROR_SOURCE_FIELDS:
        assert d[f] == f"v_{f}"
    # маппер не падает на таком входе и заполняет NOT NULL поля
    out = company_to_counterparty_fields(d)
    assert out["name"]
    assert out["country_code"]


# ============ migration 0073 structure ============

def test_migration_0073_has_advisory_lock():
    """Backfill seedит уникальные значения → обязан брать pg_advisory_xact_lock."""
    src = _MIGRATION_0073.read_text(encoding="utf-8")
    assert "pg_advisory_xact_lock" in src


def test_migration_0073_is_idempotent():
    """Повторный прогон не плодит дубли: фильтр counterparty_id IS NULL."""
    src = _MIGRATION_0073.read_text(encoding="utf-8")
    assert "WHERE counterparty_id IS NULL" in src


def test_migration_0073_has_downgrade():
    """downgrade() должен существовать (обратимость интерфейса Alembic)."""
    src = _MIGRATION_0073.read_text(encoding="utf-8")
    assert "def downgrade()" in src


def test_migration_0073_revision_fits_limit():
    """revision id 0073 ≤32 символов (alembic_version VARCHAR(32))."""
    import re

    src = _MIGRATION_0073.read_text(encoding="utf-8")
    m = re.search(r"^revision[^=]*=\s*[\"']([^\"']+)[\"']", src, re.M)
    assert m is not None
    assert len(m.group(1)) <= 32


# ============ _company_mirror_source: ORM → dict источник ============

def test_company_mirror_source_extracts_known_fields():
    """_company_mirror_source собирает пересекающиеся поля из ORM-инстанса."""
    from app.models import Company
    from app.services.contacts_v2 import _company_mirror_source

    company = Company(
        id=1, legal_name="ТОО Бета", name="Бета", country="KZ", city="Алматы",
        tax_id="123", phone="+7700", email="a@b.kz", group_id=5,
        owner_user_id=3,
    )
    src = _company_mirror_source(company)
    assert src["legal_name"] == "ТОО Бета"
    assert src["name"] == "Бета"
    assert src["country"] == "KZ"
    assert src["city"] == "Алматы"
    assert src["tax_id"] == "123"
    assert src["group_id"] == 5
    assert src["owner_user_id"] == 3


def test_company_mirror_source_feeds_counterparty_mapper():
    """_company_mirror_source + company_to_counterparty_fields дают валидное зеркало."""
    from app.models import Company
    from app.services.contacts_v2 import _company_mirror_source

    company = Company(id=1, legal_name="ТОО Гамма", country="uz")
    out = company_to_counterparty_fields(_company_mirror_source(company))
    assert out["name"] == "ТОО Гамма"
    assert out["country_code"] == "UZ"  # нормализация в маппере


# ============ country-нормализация в Pydantic-схемах companies ============

def test_company_create_normalizes_country_uppercase():
    """CompanyCreate.country/country_code → uppercase (фильтры используют .upper())."""
    from app.routers.companies import CompanyCreate

    c = CompanyCreate(legal_name="X", country="kz", country_code="uz")
    assert c.country == "KZ"
    assert c.country_code == "UZ"


def test_company_update_normalizes_country_uppercase():
    from app.routers.companies import CompanyUpdate

    c = CompanyUpdate(country="kz")
    assert c.country == "KZ"


def test_company_create_country_none_stays_none():
    from app.routers.companies import CompanyCreate

    c = CompanyCreate(legal_name="X")
    assert c.country is None
    assert c.country_code is None
