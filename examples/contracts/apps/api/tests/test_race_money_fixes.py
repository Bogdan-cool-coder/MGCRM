"""Race/IDOR/money fixes (MEDIUM audit batch).

Покрывает:
- B7: per-counterparty advisory-lock subkey (int4-clamp, pure).
- B9: атомарный upsert номера договора — формат и семантика (через стаб-сессию).
- B5: team-contribution конвертирует мультивалюту перед суммированием.

Pure-function + AsyncMock-стабы (без DB fixture), как в test_tg_intent_executor.
"""
from __future__ import annotations

from decimal import Decimal
from types import SimpleNamespace
from unittest.mock import AsyncMock, MagicMock, patch

import pytest


# ============ B7: first-payment advisory-lock subkey (pure int4-clamp) ============

def test_counterparty_lock_subkey_small_id_unchanged():
    """Маленький id остаётся в положительном int4-диапазоне без изменения."""
    from app.routers.contract_payments import _counterparty_lock_subkey

    assert _counterparty_lock_subkey(1) == 1
    assert _counterparty_lock_subkey(123_456) == 123_456


def test_counterparty_lock_subkey_in_int4_range():
    """Любой id (в т.ч. за пределами int4) приводится к знаковому int4."""
    from app.routers.contract_payments import _counterparty_lock_subkey

    for cid in (1, 0x3FFFFFFF, 0x40000000, 0x7FFFFFFF, 5_000_000_000, 10**12):
        sub = _counterparty_lock_subkey(cid)
        assert -0x80000000 <= sub <= 0x7FFFFFFF


def test_counterparty_lock_subkey_deterministic():
    """Один и тот же id всегда даёт один subkey (детерминизм лока)."""
    from app.routers.contract_payments import _counterparty_lock_subkey

    assert _counterparty_lock_subkey(98765) == _counterparty_lock_subkey(98765)


# ============ B9: contract number — формат + семантика инкремента ============

def test_city_code_and_suffix_helpers():
    """Префикс города = 3 буквы upper; суффикс страны = upper."""
    from app.services.numbering import city_code_from_name, country_suffix

    assert city_code_from_name("Ташкент") == "ТАШ"
    assert city_code_from_name("  almaty ") == "ALM"
    assert country_suffix("uz") == "UZ"
    assert country_suffix("kz") == "KZ"


@pytest.mark.asyncio
async def test_next_contract_number_first_returns_start_number():
    """Первый вызов (RETURNING start_number) даёт ТШК-220/UZ — семантика сохранена."""
    from app.services import numbering

    session = AsyncMock()
    result = MagicMock()
    result.scalar_one.return_value = 220  # ON CONFLICT не сработал → start_number
    session.execute = AsyncMock(return_value=result)

    full, code, num = await numbering.next_contract_number(
        session, "Ташкент", "uz", start_number=220
    )
    assert (full, code, num) == ("ТАШ-220/UZ", "ТАШ", 220)


@pytest.mark.asyncio
async def test_next_contract_number_subsequent_uses_incremented_value():
    """Конкурентный/последующий вызов отдаёт инкрементированное RETURNING-значение."""
    from app.services import numbering

    session = AsyncMock()
    result = MagicMock()
    result.scalar_one.return_value = 221  # ON CONFLICT DO UPDATE → current_number+1
    session.execute = AsyncMock(return_value=result)

    full, code, num = await numbering.next_contract_number(
        session, "Ташкент", "uz", start_number=220
    )
    assert (full, code, num) == ("ТАШ-221/UZ", "ТАШ", 221)


@pytest.mark.asyncio
async def test_next_contract_number_single_statement_no_select_for_update():
    """B9: одна атомарная statement (upsert), без отдельного SELECT FOR UPDATE."""
    from app.services import numbering

    session = AsyncMock()
    result = MagicMock()
    result.scalar_one.return_value = 220
    session.execute = AsyncMock(return_value=result)

    await numbering.next_contract_number(session, "Ташкент", "uz")
    # Ровно один execute — атомарный INSERT ... ON CONFLICT ... RETURNING.
    assert session.execute.await_count == 1


# ============ B5: team-contribution мультивалютная конвертация ============

@pytest.mark.asyncio
async def test_team_contribution_converts_before_summing():
    """KZT/USD/RUB платежи конвертируются в target_currency ПЕРЕД суммированием.

    Раньше суммировались сырые amount (1000 USD ≡ 1000 KZT) — искажение. Теперь
    каждый платёж умножается на курс к target_currency, затем суммируется.
    """
    from app.services import salary

    # Один менеджер с тремя платежами в разных валютах. После N+1-фикса
    # _get_team_contribution забирает платежи ВСЕХ членов одним запросом и
    # раскладывает их по attributed_to_user_id — поэтому стаб-платежи несут
    # явный attributed_to_user_id (= тестируемый менеджер 7).
    payments = [
        SimpleNamespace(amount=Decimal("100"), currency="USD", payment_date=None, attributed_to_user_id=7),
        SimpleNamespace(amount=Decimal("100"), currency="RUB", payment_date=None, attributed_to_user_id=7),
        SimpleNamespace(amount=Decimal("100"), currency="KZT", payment_date=None, attributed_to_user_id=7),
    ]
    scalars_mock = MagicMock()
    scalars_mock.scalars.return_value = MagicMock(all=MagicMock(return_value=payments))
    session = AsyncMock()
    session.execute = AsyncMock(return_value=scalars_mock)

    # Курсы к target_currency=KZT: USD→KZT=500, RUB→KZT=5, KZT→KZT=1.
    rates = {("USD", "KZT"): Decimal("500"), ("RUB", "KZT"): Decimal("5")}

    async def fake_get_rate(_session, frm, to, _on_date):
        if frm == to:
            return Decimal("1")
        return rates[(frm, to)]

    with patch.object(salary, "get_rate", side_effect=fake_get_rate):
        contributions = await salary._get_team_contribution(
            session, [7], 2026, 6, None, "KZT"
        )

    # 100*500 + 100*5 + 100*1 = 50_000 + 500 + 100 = 50_600 KZT.
    assert contributions[7] == Decimal("50600.00")


@pytest.mark.asyncio
async def test_team_contribution_empty_members_returns_empty():
    """Без участников — пустой словарь (ранний выход, без запросов)."""
    from app.services import salary

    session = AsyncMock()
    assert await salary._get_team_contribution(session, [], 2026, 6, None, "RUB") == {}


@pytest.mark.asyncio
async def test_team_contribution_same_currency_no_rate_distortion():
    """Все платежи в target_currency — суммируются как есть (курс 1.0)."""
    from app.services import salary

    payments = [
        SimpleNamespace(amount=Decimal("300"), currency="RUB", payment_date=None, attributed_to_user_id=3),
        SimpleNamespace(amount=Decimal("700"), currency="RUB", payment_date=None, attributed_to_user_id=3),
    ]
    scalars_mock = MagicMock()
    scalars_mock.scalars.return_value = MagicMock(all=MagicMock(return_value=payments))
    session = AsyncMock()
    session.execute = AsyncMock(return_value=scalars_mock)

    async def fake_get_rate(_session, frm, to, _on_date):
        return Decimal("1")

    with patch.object(salary, "get_rate", side_effect=fake_get_rate):
        contributions = await salary._get_team_contribution(
            session, [3], 2026, 6, None, "RUB"
        )
    assert contributions[3] == Decimal("1000.00")
