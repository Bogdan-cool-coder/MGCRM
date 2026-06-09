"""Эпик 10 (frontend fallback bridge) — pure-function проверки.

Закрываются 3 fallback'а на фронте:
1. Карточка КА «Ответственный менеджер» — теперь нативное поле
   `Counterparty.responsible_user_id` (миграция 0029) вместо вычитки из
   первой попавшейся сделки.
2. Дашборд «Мои задачи» — `GET /activities?responsible_id=me` (вместо
   предварительного запроса /me для получения числового id).
3. Дашборд «Горячие сделки» — новый `GET /deals/hot?owner=me&limit=N`
   с серверной классификацией heat_reason (idle/deadline).

Плюс серверные sort/filter:
- `/leads?min_score=X&sort=score_desc` для hot leads.
- `/deals?close_before=DATE&sort=close_date_asc` для forecast.

Все тесты pure-function (без DB fixture).
"""
from __future__ import annotations

from pathlib import Path

import pytest

from app.models import Counterparty
from app.services.deals import (
    HOT_DEADLINE_DAYS,
    HOT_IDLE_DAYS_THRESHOLD,
    compute_heat_reason,
)
from app.services.owner_resolver import resolve_owner_param


# ============ resolve_owner_param ============


def test_resolve_owner_param_me_with_user_id():
    """`me` + user_id=42 → 42 (основной кейс)."""
    assert resolve_owner_param("me", user_id=42) == 42


def test_resolve_owner_param_me_without_user_id():
    """`me` без user_id → None (caller сам решит как обработать)."""
    assert resolve_owner_param("me") is None
    assert resolve_owner_param("me", user_id=None) is None


def test_resolve_owner_param_numeric_string():
    """Числовая строка → int."""
    assert resolve_owner_param("17") == 17
    assert resolve_owner_param("1") == 1
    assert resolve_owner_param("999999") == 999999


def test_resolve_owner_param_numeric_string_ignores_user_id():
    """Если передан явный id — user_id не учитывается."""
    assert resolve_owner_param("17", user_id=42) == 17


def test_resolve_owner_param_none():
    """None → None."""
    assert resolve_owner_param(None) is None
    assert resolve_owner_param(None, user_id=42) is None


def test_resolve_owner_param_empty_string():
    """Пустая строка → None (silent ignore)."""
    assert resolve_owner_param("") is None
    assert resolve_owner_param("   ") is None
    assert resolve_owner_param("", user_id=42) is None


def test_resolve_owner_param_garbage():
    """Невалидная строка (не 'me', не int) → None."""
    assert resolve_owner_param("abc") is None
    assert resolve_owner_param("ME") is None  # case-sensitive: только lowercase
    assert resolve_owner_param("me123") is None
    assert resolve_owner_param("user") is None


def test_resolve_owner_param_negative_or_zero():
    """0 и отрицательные → None (id всегда положительный)."""
    assert resolve_owner_param("0") is None
    assert resolve_owner_param("-1") is None
    assert resolve_owner_param("-99") is None


# ============ compute_heat_reason ============


def test_compute_heat_reason_idle_only():
    """idle > 3, no deadline → 'idle'."""
    assert compute_heat_reason(idle_days=4, days_to_close=None) == "idle"
    assert compute_heat_reason(idle_days=10, days_to_close=None) == "idle"
    # Граница: idle == threshold не «горит» (нужно строго больше)
    assert compute_heat_reason(idle_days=3, days_to_close=None) is None


def test_compute_heat_reason_deadline_only():
    """deadline < 7, idle низкий → 'deadline'."""
    assert compute_heat_reason(idle_days=1, days_to_close=2) == "deadline"
    assert compute_heat_reason(idle_days=0, days_to_close=0) == "deadline"
    assert compute_heat_reason(idle_days=0, days_to_close=6) == "deadline"
    # Граница: ровно 7 — НЕ горит (строго меньше)
    assert compute_heat_reason(idle_days=0, days_to_close=7) is None
    # Отрицательные дни (просрочка) — тоже deadline
    assert compute_heat_reason(idle_days=0, days_to_close=-2) == "deadline"


def test_compute_heat_reason_deadline_beats_idle():
    """Если оба условия — deadline приоритетнее (тип = 'deadline')."""
    assert compute_heat_reason(idle_days=10, days_to_close=3) == "deadline"
    assert compute_heat_reason(idle_days=100, days_to_close=1) == "deadline"


def test_compute_heat_reason_not_hot():
    """Маленькие idle + далёкий deadline → None."""
    assert compute_heat_reason(idle_days=1, days_to_close=30) is None
    assert compute_heat_reason(idle_days=0, days_to_close=100) is None
    assert compute_heat_reason(idle_days=1, days_to_close=None) is None


def test_compute_heat_reason_thresholds_documented():
    """Пороги задокументированы как константы — UI зависит от них."""
    assert HOT_IDLE_DAYS_THRESHOLD == 3
    assert HOT_DEADLINE_DAYS == 7


# ============ Counterparty model: responsible_user_id ============


def test_counterparty_model_has_responsible_user_id():
    """Counterparty.responsible_user_id — новая колонка из миграции 0029."""
    cols = {c.name for c in Counterparty.__table__.columns}
    assert "responsible_user_id" in cols
    # FK на users(id) с ON DELETE SET NULL
    col = Counterparty.__table__.c.responsible_user_id
    assert col.nullable is True
    assert len(col.foreign_keys) == 1
    fk = next(iter(col.foreign_keys))
    assert fk.column.table.name == "users"
    assert fk.column.name == "id"
    assert fk.ondelete == "SET NULL"


def test_counterparty_schema_in_has_responsible_user_id():
    """CounterpartyIn / CounterpartyOut принимают responsible_user_id."""
    from app.schemas import CounterpartyIn, CounterpartyOut
    assert "responsible_user_id" in CounterpartyIn.model_fields
    assert "responsible_user_id" in CounterpartyOut.model_fields
    # Опционально на in — None дефолт
    f = CounterpartyIn.model_fields["responsible_user_id"]
    assert f.default is None


def test_counterparty_audit_fields_includes_responsible():
    """Смена ответственного должна попадать в audit log."""
    from app.routers.counterparties import _CP_AUDIT_FIELDS
    assert "responsible_user_id" in _CP_AUDIT_FIELDS


# ============ HotDealOut schema ============


def test_hot_deal_out_shape():
    """HotDealOut: набор полей соответствует контракту фронта.

    CONTACTS 2.0 Ф4: добавлен company_id (nullable, источник истины для ссылки).
    """
    from app.schemas import HotDealOut
    fields = HotDealOut.model_fields
    expected = {
        "id", "title", "amount", "currency",
        "stage_name", "stage_color",
        "idle_days", "days_to_close",
        "heat_reason", "counterparty_name",
        "company_id",
    }
    assert set(fields.keys()) == expected


def test_hot_deal_out_construction():
    """HotDealOut правильно строится из полного payload'а."""
    from app.schemas import HotDealOut
    out = HotDealOut(
        id=1,
        title="Test deal",
        amount=100000.0,
        currency="KZT",
        stage_name="Trial",
        stage_color="#E8853A",
        idle_days=5,
        days_to_close=3,
        heat_reason="deadline",
        counterparty_name="ACME LLC",
    )
    assert out.id == 1
    assert out.heat_reason == "deadline"
    assert out.counterparty_name == "ACME LLC"


def test_hot_deal_out_nullable_fields():
    """amount / currency / days_to_close / counterparty_name могут быть None."""
    from app.schemas import HotDealOut
    out = HotDealOut(
        id=1,
        title="Test",
        amount=None,
        currency=None,
        stage_name="Lead",
        stage_color=None,
        idle_days=5,
        days_to_close=None,
        heat_reason="idle",
        counterparty_name=None,
    )
    assert out.amount is None
    assert out.days_to_close is None
    assert out.counterparty_name is None


# ============ Migration 0029 ============


def test_migration_0029_exists_and_structure():
    """0029_counterparty_responsible_user: ADD COLUMN с FK + index + downgrade."""
    p = (
        Path(__file__).resolve().parents[1]
        / "alembic" / "versions"
        / "0029_counterparty_responsible_user.py"
    )
    assert p.exists(), "миграция 0029 должна быть создана"
    src = p.read_text(encoding="utf-8")
    # Revision metadata
    assert 'revision: str = "0029_cpty_responsible_user"' in src
    assert (
        'down_revision: Union[str, None] = "0028_ensure_one_admin_trigger"' in src
    )
    # upgrade: ADD COLUMN + FK + index
    assert 'add_column' in src
    assert '"responsible_user_id"' in src
    assert 'ForeignKey("users.id", ondelete="SET NULL")' in src
    assert 'ix_counterparties_responsible_user_id' in src
    # downgrade
    assert "def downgrade()" in src
    assert 'drop_column("counterparties", "responsible_user_id")' in src
    # drop_index может быть отформатирован многострочно — проверяем по имени
    assert "ix_counterparties_responsible_user_id" in src
    assert "drop_index" in src


def test_migration_0029_no_advisory_lock_no_seed():
    """DDL-only без seed'а — advisory-lock не нужен (alembic_version блокирует)."""
    p = (
        Path(__file__).resolve().parents[1]
        / "alembic" / "versions"
        / "0029_counterparty_responsible_user.py"
    )
    src = p.read_text(encoding="utf-8")
    assert "pg_advisory" not in src
    assert "INSERT" not in src.upper()


# ============ Leads filter: min_score + sort ============


def test_lead_list_endpoint_accepts_min_score_query():
    """list_leads имеет параметр min_score (Эпик 10)."""
    from app.routers.leads import list_leads
    import inspect
    sig = inspect.signature(list_leads)
    assert "min_score" in sig.parameters
    assert "sort" in sig.parameters


# ============ Deals filter: close_before + sort ============


def test_deal_list_endpoint_accepts_close_before_query():
    """list_deals имеет параметры close_before / sort (Эпик 10)."""
    from app.routers.deals import list_deals
    import inspect
    sig = inspect.signature(list_deals)
    assert "close_before" in sig.parameters
    assert "sort" in sig.parameters


# ============ Endpoint registration ============


def test_hot_deals_endpoint_registered():
    """GET /api/deals/hot должен быть зарегистрирован в app.routes."""
    from app.main import app
    paths_methods = {
        (r.path, tuple(sorted(getattr(r, "methods", []) or [])))
        for r in app.routes
    }
    assert ("/api/deals/hot", ("GET",)) in paths_methods, (
        "GET /api/deals/hot должен быть зарегистрирован "
        "(новый endpoint Эпика 10)"
    )


def test_activities_endpoint_accepts_responsible_id_str():
    """list_activities принимает responsible_id как str (для 'me')."""
    from app.routers.activities import list_activities
    import inspect
    sig = inspect.signature(list_activities)
    p = sig.parameters["responsible_id"]
    # Тип параметра — str | None (а не int | None как было раньше).
    assert "str" in str(p.annotation), (
        f"responsible_id должен быть str | None, а получил {p.annotation}"
    )


# ============ Service: deals.compute_heat_reason — symmetry ============


@pytest.mark.parametrize(
    "idle,deadline,expected",
    [
        # Idle варианты
        (0, None, None),
        (1, None, None),
        (3, None, None),  # граница (>3, не >=3)
        (4, None, "idle"),
        (10, None, "idle"),
        # Deadline варианты
        (0, 0, "deadline"),
        (0, 1, "deadline"),
        (0, 6, "deadline"),
        (0, 7, None),  # граница (<7, не <=7)
        (0, 8, None),
        (0, -5, "deadline"),  # просрочка
        # Combo — deadline побеждает
        (10, 5, "deadline"),
        (100, 0, "deadline"),
        # Combo — оба не сработали
        (1, 30, None),
    ],
)
def test_compute_heat_reason_table(idle, deadline, expected):
    assert compute_heat_reason(idle_days=idle, days_to_close=deadline) == expected
