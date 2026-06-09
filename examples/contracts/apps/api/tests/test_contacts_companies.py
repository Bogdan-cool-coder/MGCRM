"""Contact + Company (Эпик 1.2) — pure-function проверки схем, моделей, миграции.

Без БД-фикстуры: проверяем Pydantic-валидацию, имена таблиц SQLAlchemy и
структуру миграции 0019. Цель — поймать регрессии в контрактах API и схеме БД
без поднятия postgres.
"""
from __future__ import annotations

from pathlib import Path

import pytest
from pydantic import ValidationError

from app.models import Company, Contact, LegacyContact
from app.routers.companies import CompanyCreate
from app.routers.contacts import ContactCreate
from app.routers.leads import LeadConvert, LeadConvertOut


def test_company_requires_legal_name():
    """legal_name — обязательное поле, пустая строка / отсутствие — ошибка валидации."""
    with pytest.raises(ValidationError):
        CompanyCreate()  # type: ignore[call-arg]
    with pytest.raises(ValidationError):
        CompanyCreate(legal_name="")  # min_length=1


def test_company_create_optional_fields_accept_none():
    """Все доп. поля компании nullable — null/отсутствие принимается."""
    c = CompanyCreate(legal_name="MACRO Global Technologies")
    assert c.short_name is None
    assert c.tax_id is None
    assert c.country is None
    assert c.category_code is None
    assert c.counterparty_id is None


def test_company_country_is_iso_alpha2():
    """country = 2 символа (ISO 3166-1 alpha-2). Не 1 и не 3."""
    CompanyCreate(legal_name="Test", country="KZ")  # ok
    with pytest.raises(ValidationError):
        CompanyCreate(legal_name="Test", country="K")
    with pytest.raises(ValidationError):
        CompanyCreate(legal_name="Test", country="KAZ")


def test_contact_requires_full_name():
    """full_name — обязательное и непустое."""
    with pytest.raises(ValidationError):
        ContactCreate()  # type: ignore[call-arg]
    with pytest.raises(ValidationError):
        ContactCreate(full_name="")


def test_contact_is_primary_defaults_false():
    """is_primary по умолчанию False, явный True допустим."""
    assert ContactCreate(full_name="Иван Иванов").is_primary is False
    assert ContactCreate(full_name="Иван Иванов", is_primary=True).is_primary is True


def test_lead_convert_accepts_counterparty_id():
    """LeadConvert (Эпик 1.2): принимает counterparty_id для привязки к существующему КА."""
    payload = LeadConvert(counterparty_id=42)
    assert payload.counterparty_id == 42
    assert payload.counterparty_name is None
    # обратная совместимость: можно по-старому давать имя
    legacy = LeadConvert(counterparty_name="ТОО Тест")
    assert legacy.counterparty_id is None
    assert legacy.counterparty_name == "ТОО Тест"


def test_lead_convert_out_has_created_new_flag():
    """LeadConvertOut (Эпик 1.2): новое поле created_new_counterparty обязательное."""
    fields = LeadConvertOut.model_fields
    assert "created_new_counterparty" in fields
    assert fields["created_new_counterparty"].is_required()


def test_contact_and_company_use_crm_tables():
    """Новые сущности живут в crm_contacts / crm_companies — не пересекаются с legacy contacts."""
    assert Contact.__tablename__ == "crm_contacts"
    assert Company.__tablename__ == "crm_companies"
    # Legacy таблица под именем contacts — сохранена, доступна как LegacyContact
    assert LegacyContact.__tablename__ == "contacts"


def test_migration_0019_creates_expected_indexes():
    """Миграция 0019 создаёт ожидаемые индексы (защита от случайных удалений в рефакторе).

    Читаем файл миграции как текст и проверяем строки `create_index`. Это дешёвый
    smoke-тест, не требующий postgres.
    """
    migration_path = (
        Path(__file__).resolve().parents[1]
        / "alembic"
        / "versions"
        / "0019_contact_company.py"
    )
    src = migration_path.read_text(encoding="utf-8")
    # Companies — индексы на tax_id / email / country / category_code
    for ix in (
        "ix_crm_companies_tax_id",
        "ix_crm_companies_email",
        "ix_crm_companies_country",
        "ix_crm_companies_category_code",
    ):
        assert ix in src, f"индекс {ix} должен быть в миграции 0019"
    # Contacts — индексы на email / phone / company_id / owner_id
    for ix in (
        "ix_crm_contacts_email",
        "ix_crm_contacts_phone",
        "ix_crm_contacts_company_id",
        "ix_crm_contacts_owner_id",
    ):
        assert ix in src, f"индекс {ix} должен быть в миграции 0019"


def test_migration_0019_has_downgrade():
    """Миграция должна быть обратимой — downgrade() реализован."""
    migration_path = (
        Path(__file__).resolve().parents[1]
        / "alembic"
        / "versions"
        / "0019_contact_company.py"
    )
    src = migration_path.read_text(encoding="utf-8")
    assert "def downgrade()" in src
    # downgrade должен дропать обе таблицы (порядок: сначала contacts → потом companies из-за FK)
    assert 'drop_table("crm_contacts")' in src
    assert 'drop_table("crm_companies")' in src
    # contacts FK на companies → contacts дропаются раньше
    pos_contacts = src.find('drop_table("crm_contacts")')
    pos_companies = src.find('drop_table("crm_companies")')
    assert pos_contacts < pos_companies, "crm_contacts должны дропаться до crm_companies (FK)"
