"""Ф0 ЧАНК 1 — корректность сид-данных модуля «Финансы» (pure, без БД).

Проверяет инварианты сид-данных из app.services.finance.seed_data:
  • план счетов: 39 счетов, уникальные коды, нормальные стороны согласованы по классам,
    верные классы счетов, согласованность is_money / requires_counterparty наборов;
  • дерево ДДС: 39 узлов, уникальные коды, parent-консистентность, валидные activity/direction,
    наследование activity от родителя, листья L3 имеют направленность (inflow/outflow);
  • op-types: posting_template из множества Ф0, transfer без статьи и вне ДДС;
  • роли/права: cfo ⊃ accountant по close_period/manage_settings, director без журналов/постинга.
"""

from __future__ import annotations

from app.services.finance import seed_data as sd


# ───────────────────────────── план счетов ─────────────────────────────


def test_chart_has_39_accounts():
    assert len(sd.ACCOUNTS_GL) == 39


def test_account_codes_unique():
    codes = sd.account_codes()
    assert len(codes) == len(set(codes))


def test_account_types_valid():
    for code, _name, atype, *_ in sd.ACCOUNTS_GL:
        assert atype in sd.ACCOUNT_TYPES, f"{code}: bad type {atype}"


def test_account_normal_side_matches_class():
    # asset/expense → Дт; liability/equity/income → Кт; кроме явно двусторонних (3990).
    two_sided = {"3990"}
    for code, _name, atype, _subtype, side, *_ in sd.ACCOUNTS_GL:
        if code in two_sided:
            assert side == "both", f"{code} expected two-sided"
            continue
        expected = sd.normal_side_for_type(atype)
        assert side == expected, f"{code} ({atype}): side {side} != {expected}"


def test_account_class_prefix_matches_type():
    # Первая цифра кода кодирует класс: 1=asset 2=liability 3=equity 4=income 5=expense.
    class_by_prefix = {"1": "asset", "2": "liability", "3": "equity", "4": "income", "5": "expense"}
    for code, _name, atype, *_ in sd.ACCOUNTS_GL:
        assert class_by_prefix[code[0]] == atype, f"{code}: prefix vs type mismatch"


def test_money_accounts_flagged():
    # Кортеж: (code, name, type, subtype, normal_side, is_money, requires_counterparty).
    money = {row[0] for row in sd.ACCOUNTS_GL if row[5]}
    assert money == set(sd.MONEY_ACCOUNT_CODES)
    # все денежные — это активы класса cash.
    for row in sd.ACCOUNTS_GL:
        if row[5]:
            assert row[2] == "asset" and row[3] == "cash"


def test_counterparty_accounts_flagged():
    cp = {row[0] for row in sd.ACCOUNTS_GL if row[6]}
    assert cp == set(sd.COUNTERPARTY_ACCOUNT_CODES)


def test_fx_and_opening_offset_accounts_exist():
    codes = set(sd.account_codes())
    assert sd.FX_GAIN_CODE in codes
    assert sd.FX_LOSS_CODE in codes
    assert sd.OPENING_OFFSET_CODE in codes
    assert sd.DEFAULT_BANK_GL_CODE in codes


def test_trial_balance_ready_each_class_present():
    # Готовность к trial-balance: представлены все 5 классов (иначе проводки не сбалансировать).
    types = {row[2] for row in sd.ACCOUNTS_GL}
    assert types == set(sd.ACCOUNT_TYPES)


# ───────────────────────────── дерево ДДС ─────────────────────────────


def test_cashflow_tree_node_count():
    # J §4 ПЕРЕЧИСЛЯЕТ 40 узлов (3 корня + 6 групп + 31 лист); сводная строка J §4
    # говорит «39/30» — это внутреннее расхождение спеки (off-by-one в OP-OUT, где
    # перечислено 14 листьев). Берём ЭНУМЕРИРОВАННУЮ таблицу как авторитетную.
    # ФЛАГ владельцу: сверить итоговую цифру 39↔40 в J §4.
    assert len(sd.CASHFLOW_TREE) == 40


def test_cashflow_codes_unique():
    codes = sd.cashflow_codes()
    assert len(codes) == len(set(codes))


def test_cashflow_roots_have_no_parent():
    roots = [row for row in sd.CASHFLOW_TREE if row[5] is None]
    assert {r[0] for r in roots} == {"OP", "INV", "FIN"}
    for r in roots:
        assert r[2] == 1 and r[4] == "both"


def test_cashflow_parents_exist_and_precede_children():
    seen: set[str] = set()
    for code, _name, _level, _activity, _direction, parent in sd.CASHFLOW_TREE:
        if parent is not None:
            assert parent in seen, f"{code}: parent {parent} not defined before child"
        seen.add(code)


def test_cashflow_activity_inherited_from_parent():
    by_code = {row[0]: row for row in sd.CASHFLOW_TREE}
    for code, _name, _level, activity, _direction, parent in sd.CASHFLOW_TREE:
        if parent is not None:
            assert by_code[parent][3] == activity, f"{code}: activity differs from parent"


def test_cashflow_levels_consistent():
    by_code = {row[0]: row for row in sd.CASHFLOW_TREE}
    for code, _name, level, _activity, _direction, parent in sd.CASHFLOW_TREE:
        if parent is None:
            assert level == 1
        else:
            assert level == by_code[parent][2] + 1, f"{code}: level not parent+1"


def test_cashflow_directions_valid():
    for code, _name, _level, activity, direction, _parent in sd.CASHFLOW_TREE:
        assert activity in sd.CASHFLOW_ACTIVITIES
        assert direction in sd.CASHFLOW_DIRECTIONS, f"{code}: bad direction {direction}"


def test_cashflow_leaves_are_directional():
    # Листья L3 — конкретные статьи, обязаны быть приток ИЛИ отток (не both).
    parents = {row[5] for row in sd.CASHFLOW_TREE if row[5] is not None}
    for code, _name, level, _activity, direction, _parent in sd.CASHFLOW_TREE:
        if code not in parents:  # лист
            assert direction in ("inflow", "outflow"), f"{code}: leaf must be in/out"
            assert level == 3


def test_cashflow_node_counts():
    levels = [row[2] for row in sd.CASHFLOW_TREE]
    assert levels.count(1) == 3   # 3 корня
    assert levels.count(2) == 6   # 6 групп направлений
    assert levels.count(3) == 31  # 31 лист (по энумерированной таблице J §4; см. ФЛАГ выше)


# ───────────────────────────── op-types ─────────────────────────────


def test_op_types_templates_in_phase0_set():
    allowed = {"cash_in", "cash_out", "transfer", "opening", "reversal", "manual_journal"}
    for row in sd.OP_TYPES:
        template = row[3]
        assert template in allowed, f"{row[0]}: template {template} not in Ф0 set"


def test_transfer_op_type_excluded_from_cashflow_and_pnl():
    transfer = next(row for row in sd.OP_TYPES if row[3] == "transfer")
    # cat_code=None (статья не задаётся), counts_in_pnl=False, counts_in_cashflow=False, transfer=True
    assert transfer[4] is None
    assert transfer[6] is False  # counts_in_pnl
    assert transfer[7] is False  # counts_in_cashflow
    assert transfer[8] is True   # is_internal_transfer


def test_op_type_codes_unique():
    codes = [row[0] for row in sd.OP_TYPES]
    assert len(codes) == len(set(codes))


# ───────────────────────────── ставки НДС (сид) ─────────────────────────────


def test_vat_rates_seed_kz_uz_novat():
    names = {row[0] for row in sd.VAT_RATES}
    assert "ҚҚС 12% (KZ)" in names
    assert "НДС 12% (UZ)" in names
    assert sd.VAT_RATE_NO_VAT_NAME in names


def test_vat_no_vat_rate_is_zero_exempt():
    no_vat = next(row for row in sd.VAT_RATES if row[0] == sd.VAT_RATE_NO_VAT_NAME)
    assert no_vat[1] == "0.00" and no_vat[2] == "exempt" and no_vat[3] is None


# ───────────────────────────── роли / права ─────────────────────────────


def test_base_currency_is_rub():
    assert sd.BASE_CURRENCY == "RUB"


def test_currency_by_country_map():
    assert sd.CURRENCY_BY_COUNTRY["kz"] == "KZT"
    assert sd.CURRENCY_BY_COUNTRY["uz"] == "UZS"


def test_cfo_has_close_period_and_settings_accountant_not():
    assert sd.ROLE_PERMISSIONS["cfo"].get("close_period") is True
    assert sd.ROLE_PERMISSIONS["cfo"].get("manage_settings") is True
    assert sd.ROLE_PERMISSIONS["accountant"].get("close_period") is None
    assert sd.ROLE_PERMISSIONS["accountant"].get("manage_settings") is None


def test_accountant_has_journal_capabilities_director_not():
    for cap in ("create_manual_journal", "post_manual_journal", "view_journal"):
        assert sd.ROLE_PERMISSIONS["accountant"].get(cap) is True
        assert sd.ROLE_PERMISSIONS["director"].get(cap) is None


def test_director_is_read_only_no_posting():
    for cap in ("create_operation", "post_operation", "manage_settings"):
        assert sd.ROLE_PERMISSIONS["director"].get(cap) is None
    # но отчёты/«для руководства» — есть
    assert sd.ROLE_PERMISSIONS["director"].get("view_reports") is True
    assert sd.ROLE_PERMISSIONS["director"].get("view_management") is True


def test_manager_can_only_view_operations():
    # Ф0: view_operations (свои). Ф2: + create_request (заявки на ЗП/комиссию/расход).
    assert sd.ROLE_PERMISSIONS["manager"] == {
        "view_operations": True,
        "create_request": True,
    }


def test_all_seeded_capabilities_are_known():
    for role, caps in sd.ROLE_PERMISSIONS.items():
        for cap in caps:
            assert cap in sd.CAPABILITIES, f"{role}: unknown capability {cap}"


def test_admin_has_all_capabilities():
    assert set(sd.ROLE_PERMISSIONS["admin"].keys()) == set(sd.CAPABILITIES)
