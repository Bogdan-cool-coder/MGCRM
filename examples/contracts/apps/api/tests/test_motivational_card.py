"""Epic 10.5 — Motivational Card compute algorithm tests.

Pure-function tests — без DB fixture. Тестируем:
- compute_commission
- compute_team_bonus_pool
- compute_team_bonus_shares
- is_ftm_valid
- convert_amount
- Edge cases: нулевые суммы, одиночный менеджер, план не выполнен
"""
import pytest
from decimal import Decimal

from app.services.salary import (
    compute_commission,
    compute_team_bonus_pool,
    compute_team_bonus_shares,
    is_ftm_valid,
)
from app.services.currency import convert_amount


# ============ commission tests ============

def test_commission_basic():
    """10% от 982 135 RUB = 98 213.50 RUB (пример из PDF)."""
    payments = [Decimal("982135.00")]
    result = compute_commission(payments, Decimal("10.00"))
    assert result == Decimal("98213.50")


def test_commission_multiple_payments():
    """Комиссия считается от суммы всех платежей."""
    payments = [Decimal("100000"), Decimal("200000"), Decimal("300000")]
    # 10% от 600 000 = 60 000
    result = compute_commission(payments, Decimal("10.00"))
    assert result == Decimal("60000.00")


def test_commission_zero_payments():
    """Нет платежей — нет комиссии."""
    result = compute_commission([], Decimal("10.00"))
    assert result == Decimal("0.00")


def test_commission_zero_rate():
    """Ставка 0 — комиссия 0."""
    result = compute_commission([Decimal("1000000")], Decimal("0.00"))
    assert result == Decimal("0.00")


def test_commission_fractional_rate():
    """5.5% от 200 000 = 11 000."""
    result = compute_commission([Decimal("200000")], Decimal("5.50"))
    assert result == Decimal("11000.00")


def test_commission_single_small_payment():
    """Маленький платёж с округлением."""
    # 10% от 33.33 = 3.333 → 3.33 (ROUND_HALF_UP)
    result = compute_commission([Decimal("33.33")], Decimal("10.00"))
    assert result == Decimal("3.33")


def test_commission_rounding_half_up():
    """Проверка ROUND_HALF_UP: 10% от 55.55 = 5.555 → 5.56."""
    result = compute_commission([Decimal("55.55")], Decimal("10.00"))
    assert result == Decimal("5.56")


# ============ team bonus pool tests ============

def test_bonus_pool_two_members():
    """2 менеджера — базовый пул без добавки."""
    pool = compute_team_bonus_pool(
        base_pool=Decimal("500000"),
        n_members=2,
        per_additional_member=Decimal("100000"),
    )
    assert pool == Decimal("500000.00")


def test_bonus_pool_three_members():
    """3 менеджера — пул + 100 000."""
    pool = compute_team_bonus_pool(
        base_pool=Decimal("500000"),
        n_members=3,
        per_additional_member=Decimal("100000"),
    )
    assert pool == Decimal("600000.00")


def test_bonus_pool_five_members():
    """5 менеджеров — пул + 3 * 100 000."""
    pool = compute_team_bonus_pool(
        base_pool=Decimal("500000"),
        n_members=5,
        per_additional_member=Decimal("100000"),
    )
    assert pool == Decimal("800000.00")


def test_bonus_pool_one_member():
    """1 менеджер — пул без изменений (не вычитаем)."""
    pool = compute_team_bonus_pool(
        base_pool=Decimal("500000"),
        n_members=1,
        per_additional_member=Decimal("100000"),
    )
    assert pool == Decimal("500000.00")


def test_bonus_pool_zero_members():
    """0 менеджеров — пул без изменений."""
    pool = compute_team_bonus_pool(
        base_pool=Decimal("500000"),
        n_members=0,
        per_additional_member=Decimal("100000"),
    )
    assert pool == Decimal("500000.00")


# ============ team bonus shares tests ============

def test_bonus_shares_equal_contribution():
    """Два менеджера с равным вкладом — равные доли в обоих частях."""
    prop, equal = compute_team_bonus_shares(
        pool=Decimal("500000"),
        proportional_pct=Decimal("60"),
        equal_pct=Decimal("40"),
        n_members=2,
        user_contribution=Decimal("500000"),
        team_total_contribution=Decimal("1000000"),
    )
    # Proportional: 500000 * 60% * (500000/1000000) = 150 000
    assert prop == Decimal("150000.00")
    # Equal: 500000 * 40% / 2 = 100 000
    assert equal == Decimal("100000.00")


def test_bonus_shares_dominant_contributor():
    """Менеджер с 80% вклада получает больше в пропорциональной части."""
    prop, equal = compute_team_bonus_shares(
        pool=Decimal("500000"),
        proportional_pct=Decimal("60"),
        equal_pct=Decimal("40"),
        n_members=2,
        user_contribution=Decimal("800000"),
        team_total_contribution=Decimal("1000000"),
    )
    # Proportional: 500000 * 0.6 * 0.8 = 240 000
    assert prop == Decimal("240000.00")
    # Equal: 500000 * 0.4 / 2 = 100 000
    assert equal == Decimal("100000.00")


def test_bonus_shares_zero_contribution():
    """Менеджер без вклада — нет пропорциональной доли, равная есть."""
    prop, equal = compute_team_bonus_shares(
        pool=Decimal("500000"),
        proportional_pct=Decimal("60"),
        equal_pct=Decimal("40"),
        n_members=2,
        user_contribution=Decimal("0"),
        team_total_contribution=Decimal("1000000"),
    )
    assert prop == Decimal("0.00")
    assert equal == Decimal("100000.00")


def test_bonus_shares_zero_team_total():
    """Вся команда без вклада — нет пропорциональной доли."""
    prop, equal = compute_team_bonus_shares(
        pool=Decimal("500000"),
        proportional_pct=Decimal("60"),
        equal_pct=Decimal("40"),
        n_members=2,
        user_contribution=Decimal("0"),
        team_total_contribution=Decimal("0"),
    )
    assert prop == Decimal("0.00")
    assert equal == Decimal("100000.00")


def test_bonus_shares_single_member():
    """Один менеджер — получает всё."""
    prop, equal = compute_team_bonus_shares(
        pool=Decimal("500000"),
        proportional_pct=Decimal("60"),
        equal_pct=Decimal("40"),
        n_members=1,
        user_contribution=Decimal("1000000"),
        team_total_contribution=Decimal("1000000"),
    )
    # Proportional: 500000 * 0.6 * 1.0 = 300 000
    assert prop == Decimal("300000.00")
    # Equal: 500000 * 0.4 / 1 = 200 000
    assert equal == Decimal("200000.00")


def test_bonus_shares_zero_members():
    """0 членов — ничего не начисляем."""
    prop, equal = compute_team_bonus_shares(
        pool=Decimal("500000"),
        proportional_pct=Decimal("60"),
        equal_pct=Decimal("40"),
        n_members=0,
        user_contribution=Decimal("1000"),
        team_total_contribution=Decimal("1000"),
    )
    assert prop == Decimal("0")
    assert equal == Decimal("0")


# ============ FTM validation tests ============

def test_ftm_valid_all_conditions():
    """Все 5 условий выполнены → FTM засчитывается."""
    assert is_ftm_valid(
        kind="meeting",
        is_first_time_meeting=True,
        ftm_decision_maker_attended=True,
        ftm_presentation_shown=True,
        ftm_report_url="https://example.com/report",
    ) is True


def test_ftm_not_meeting_kind():
    """Не встреча — не FTM."""
    assert is_ftm_valid(
        kind="call",
        is_first_time_meeting=True,
        ftm_decision_maker_attended=True,
        ftm_presentation_shown=True,
        ftm_report_url="https://example.com/report",
    ) is False


def test_ftm_not_first_time():
    """is_first_time_meeting=False — не FTM."""
    assert is_ftm_valid(
        kind="meeting",
        is_first_time_meeting=False,
        ftm_decision_maker_attended=True,
        ftm_presentation_shown=True,
        ftm_report_url="https://example.com/report",
    ) is False


def test_ftm_no_dm():
    """ЛПР не присутствовал — не FTM."""
    assert is_ftm_valid(
        kind="meeting",
        is_first_time_meeting=True,
        ftm_decision_maker_attended=False,
        ftm_presentation_shown=True,
        ftm_report_url="https://example.com/report",
    ) is False


def test_ftm_no_presentation():
    """Презентация не показана — не FTM."""
    assert is_ftm_valid(
        kind="meeting",
        is_first_time_meeting=True,
        ftm_decision_maker_attended=True,
        ftm_presentation_shown=False,
        ftm_report_url="https://example.com/report",
    ) is False


def test_ftm_no_report():
    """Отчёт не заполнен — не FTM."""
    assert is_ftm_valid(
        kind="meeting",
        is_first_time_meeting=True,
        ftm_decision_maker_attended=True,
        ftm_presentation_shown=True,
        ftm_report_url=None,
    ) is False


def test_ftm_empty_report_url():
    """Пустой URL отчёта — не FTM."""
    assert is_ftm_valid(
        kind="meeting",
        is_first_time_meeting=True,
        ftm_decision_maker_attended=True,
        ftm_presentation_shown=True,
        ftm_report_url="",
    ) is False
