"""Канонические сид-данные модуля «Финансы», Ф0 (LOCKED-спека J).

Это ЕДИНСТВЕННЫЙ источник истины сид-данных Ф0. И Alembic-миграции (insert-missing),
и pure-function тесты импортируют отсюда — чтобы сид и проверки не разъезжались.

Содержит:
- ACCOUNTS_GL      — план счетов, 39 управленческих счетов (J §3, классы 1xxx–5xxx).
- CASHFLOW_TREE    — дерево статей ДДС, 39 узлов 3 уровней (J §4).
- OP_TYPES         — типы операций Ф0 (J §8.5 posting-templates).
- VAT_RATES        — ставки НДС (J §5.4: KZ 12%, UZ 12%, «Без НДС»).
- ROLE_PERMISSIONS — дефолты прав ролей × capability (J §7.3).
- CAPABILITIES     — enum capability Ф0 (J §7.2).
- CURRENCY_BY_COUNTRY / VAT_DEFAULT_BY_COUNTRY — карта валют/НДС по стране (J §5.3).
- BASE_CURRENCY    — базовая валюта группы = RUB (J §5.3).

Никаких побочных эффектов, только данные + чистые функции построения.
"""

from __future__ import annotations

# ───────────────────────────── §5.3 валюты / НДС / база ─────────────────────────────

#: Базовая валюта группы (singleton `fin_settings.base_currency`). Настраиваемая → Ф4.
BASE_CURRENCY = "RUB"

#: Функциональная валюта по стране юрлица (J §5.3).
CURRENCY_BY_COUNTRY: dict[str, str] = {"kz": "KZT", "uz": "UZS", "ru": "RUB"}

#: Включён ли НДС по умолчанию для страны (настраивается per-entity потом).
VAT_DEFAULT_BY_COUNTRY: dict[str, bool] = {"kz": True, "uz": True, "ru": True}


# ───────────────────────────── §3 план счетов (39) ─────────────────────────────
# Кортеж: (code, name, type, subtype|None, normal_side, is_money, requires_counterparty)
#   normal_side: 'dt' (дебетовое сальдо: asset/expense) | 'kt' (кредитовое: liability/equity/income)
#                'both' — двусторонний (3990 внутригрупповые).

ACCOUNT_TYPES = ("asset", "liability", "equity", "income", "expense")

ACCOUNTS_GL: list[tuple[str, str, str, str | None, str, bool, bool]] = [
    # ── Класс 1xxx — АКТИВЫ (нормальная сторона Дт) ──
    ("1010", "Касса", "asset", "cash", "dt", True, False),
    ("1020", "Расчётные счета (банк)", "asset", "cash", "dt", True, False),
    ("1030", "Эквайринг (счёт-транзит)", "asset", "cash", "dt", True, False),
    ("1040", "Электронные кошельки", "asset", "cash", "dt", True, False),
    ("1210", "Расчёты с покупателями (AR)", "asset", "ar", "dt", False, True),
    ("1290", "Прочая дебиторка", "asset", "ar", "dt", False, True),
    ("1910", "НДС к зачёту (input VAT)", "asset", "vat_input", "dt", False, False),
    ("1990", "Прочие активы", "asset", None, "dt", False, False),
    # ── Класс 2xxx — ОБЯЗАТЕЛЬСТВА (нормальная сторона Кт) ──
    ("2110", "Расчёты с поставщиками (AP)", "liability", "ap", "kt", False, True),
    ("2210", "Авансы полученные", "liability", "ap", "kt", False, True),
    ("2310", "НДС к уплате (output VAT)", "liability", "vat_output", "kt", False, False),
    ("2320", "Налог на прибыль к уплате", "liability", "tax", "kt", False, False),
    ("2390", "Прочие налоги к уплате", "liability", "tax", "kt", False, False),
    ("2610", "Расчёты с персоналом (ЗП)", "liability", "payroll", "kt", False, False),
    ("2620", "Расчёты по комиссиям менеджеров", "liability", "payroll", "kt", False, False),
    ("2900", "Прочие обязательства", "liability", None, "kt", False, False),
    # ── Класс 3xxx — КАПИТАЛ (нормальная сторона Кт) ──
    ("3010", "Уставный капитал / вклады", "equity", "capital", "kt", False, False),
    ("3110", "Нераспределённая прибыль", "equity", "capital", "kt", False, False),
    ("3900", "Начальные остатки (opening)", "equity", "capital", "kt", False, False),
    ("3990", "Внутригрупповые расчёты", "equity", "intercompany", "both", False, False),
    # ── Класс 4xxx — ДОХОДЫ (нормальная сторона Кт) ──
    ("4010", "Выручка от подписок (MRR)", "income", "revenue", "kt", False, False),
    ("4020", "Выручка от лицензий", "income", "revenue", "kt", False, False),
    ("4030", "Выручка от услуг (разовая)", "income", "revenue", "kt", False, False),
    ("4090", "Прочие доходы", "income", "revenue", "kt", False, False),
    ("4910", "Курсовые разницы (доход)", "income", "fx", "kt", False, False),
    # ── Класс 5xxx — РАСХОДЫ (нормальная сторона Дт) ──
    ("5110", "ФОТ (зарплата)", "expense", "payroll", "dt", False, False),
    ("5120", "Комиссии менеджерам", "expense", "payroll", "dt", False, False),
    ("5130", "Налоги с ФОТ", "expense", "tax", "dt", False, False),
    ("5210", "Инфраструктура / хостинг", "expense", None, "dt", False, False),
    ("5220", "ПО и подписки-сервисы", "expense", None, "dt", False, False),
    ("5310", "Аренда", "expense", None, "dt", False, False),
    ("5320", "Маркетинг и реклама", "expense", None, "dt", False, False),
    ("5410", "Банковские комиссии", "expense", None, "dt", False, False),
    ("5420", "Эквайринг-комиссии", "expense", None, "dt", False, False),
    ("5510", "Налоги (оборотные/прочие)", "expense", "tax", "dt", False, False),
    ("5520", "Налог на прибыль", "expense", "tax", "dt", False, False),
    ("5610", "Командировки и представительские", "expense", None, "dt", False, False),
    ("5910", "Курсовые разницы (расход)", "expense", "fx", "dt", False, False),
    ("5990", "Прочие расходы", "expense", None, "dt", False, False),
]

#: Денежные счета (is_money). Под каждый — `fin_money_account`.
MONEY_ACCOUNT_CODES = ("1010", "1020", "1030", "1040")
#: AR/AP-счета (requires_counterparty).
COUNTERPARTY_ACCOUNT_CODES = ("1210", "1290", "2110", "2210")
#: Ф5 — AR/AP/НДС-счета для проводок инвойсов/вендор-счетов (G §9).
AR_ACCOUNT_CODE = "1210"          # Расчёты с покупателями (дебиторка)
AP_ACCOUNT_CODE = "2110"          # Расчёты с поставщиками (кредиторка)
VAT_OUTPUT_ACCOUNT_CODE = "2310"  # НДС к уплате (output, с продаж)
VAT_INPUT_ACCOUNT_CODE = "1910"   # НДС к зачёту (input, к вычету)
#: Дефолтные счета выручки/расхода по позициям документов.
DEFAULT_REVENUE_CODE = "4030"     # Выручка от услуг (разовая)
DEFAULT_EXPENSE_CODE = "5990"     # Прочие расходы
#: FX-контрсчета (курсовые разницы).
FX_GAIN_CODE = "4910"
FX_LOSS_CODE = "5910"
#: Контрсчёт ввода начальных остатков денежных счетов (opening: Дт money / Кт 3900).
OPENING_OFFSET_CODE = "3900"
#: GL-счёт по умолчанию для банковского `fin_money_account` (сид из licensor_entities).
DEFAULT_BANK_GL_CODE = "1020"


# ───────────────────────────── §4 дерево статей ДДС (39) ─────────────────────────────
# Кортеж: (code, name, level, activity, direction, parent_code|None)
#   activity:  operating | investing | financing
#   direction: inflow | outflow | both

CASHFLOW_ACTIVITIES = ("operating", "investing", "financing")
CASHFLOW_DIRECTIONS = ("inflow", "outflow", "both")

CASHFLOW_TREE: list[tuple[str, str, int, str, str, str | None]] = [
    # ── Операционная ──
    ("OP", "Операционная деятельность", 1, "operating", "both", None),
    ("OP-IN", "Притоки от операционной", 2, "operating", "inflow", "OP"),
    ("OP-IN-SUB", "Поступления по подпискам (MRR)", 3, "operating", "inflow", "OP-IN"),
    ("OP-IN-LIC", "Поступления за лицензии", 3, "operating", "inflow", "OP-IN"),
    ("OP-IN-SRV", "Поступления за разовые услуги", 3, "operating", "inflow", "OP-IN"),
    ("OP-IN-ADV", "Авансы полученные от клиентов", 3, "operating", "inflow", "OP-IN"),
    ("OP-IN-OTH", "Прочие операционные поступления", 3, "operating", "inflow", "OP-IN"),
    ("OP-OUT", "Оттоки по операционной", 2, "operating", "outflow", "OP"),
    ("OP-OUT-PAY", "ФОТ (зарплата)", 3, "operating", "outflow", "OP-OUT"),
    ("OP-OUT-COM", "Комиссии менеджерам", 3, "operating", "outflow", "OP-OUT"),
    ("OP-OUT-PTX", "Налоги с ФОТ", 3, "operating", "outflow", "OP-OUT"),
    ("OP-OUT-HST", "Хостинг / инфраструктура", 3, "operating", "outflow", "OP-OUT"),
    ("OP-OUT-SFT", "ПО и подписки-сервисы", 3, "operating", "outflow", "OP-OUT"),
    ("OP-OUT-RNT", "Аренда", 3, "operating", "outflow", "OP-OUT"),
    ("OP-OUT-MKT", "Маркетинг и реклама", 3, "operating", "outflow", "OP-OUT"),
    ("OP-OUT-BNK", "Банковские комиссии", 3, "operating", "outflow", "OP-OUT"),
    ("OP-OUT-ACQ", "Эквайринг-комиссии", 3, "operating", "outflow", "OP-OUT"),
    ("OP-OUT-VAT", "НДС уплаченный в бюджет", 3, "operating", "outflow", "OP-OUT"),
    ("OP-OUT-TAX", "Прочие налоги", 3, "operating", "outflow", "OP-OUT"),
    ("OP-OUT-TRV", "Командировки и представительские", 3, "operating", "outflow", "OP-OUT"),
    ("OP-OUT-RFN", "Возвраты клиентам", 3, "operating", "outflow", "OP-OUT"),
    ("OP-OUT-OTH", "Прочие операционные выплаты", 3, "operating", "outflow", "OP-OUT"),
    # ── Инвестиционная ──
    ("INV", "Инвестиционная деятельность", 1, "investing", "both", None),
    ("INV-IN", "Притоки от инвестиционной", 2, "investing", "inflow", "INV"),
    ("INV-IN-AST", "Продажа активов / оборудования", 3, "investing", "inflow", "INV-IN"),
    ("INV-IN-OTH", "Прочие инвестиционные поступления", 3, "investing", "inflow", "INV-IN"),
    ("INV-OUT", "Оттоки по инвестиционной", 2, "investing", "outflow", "INV"),
    ("INV-OUT-CPX", "Покупка оборудования / ОС", 3, "investing", "outflow", "INV-OUT"),
    ("INV-OUT-INT", "Капитализируемая разработка / НМА", 3, "investing", "outflow", "INV-OUT"),
    ("INV-OUT-OTH", "Прочие инвестиционные выплаты", 3, "investing", "outflow", "INV-OUT"),
    # ── Финансовая ──
    ("FIN", "Финансовая деятельность", 1, "financing", "both", None),
    ("FIN-IN", "Притоки от финансовой", 2, "financing", "inflow", "FIN"),
    ("FIN-IN-EQ", "Вклады учредителей / капитал", 3, "financing", "inflow", "FIN-IN"),
    ("FIN-IN-LN", "Привлечение займов / кредитов", 3, "financing", "inflow", "FIN-IN"),
    ("FIN-IN-OTH", "Прочие финансовые поступления", 3, "financing", "inflow", "FIN-IN"),
    ("FIN-OUT", "Оттоки по финансовой", 2, "financing", "outflow", "FIN"),
    ("FIN-OUT-DIV", "Выплата дивидендов / распределение", 3, "financing", "outflow", "FIN-OUT"),
    ("FIN-OUT-LN", "Погашение займов / кредитов", 3, "financing", "outflow", "FIN-OUT"),
    ("FIN-OUT-INT", "Проценты по займам", 3, "financing", "outflow", "FIN-OUT"),
    ("FIN-OUT-OTH", "Прочие финансовые выплаты", 3, "financing", "outflow", "FIN-OUT"),
]

#: Имя единственного набора статей (`fin_cat_set`).
CAT_SET_NAME = "SaaS-набор операций"


# ───────────────────────────── op-types (Ф0) ─────────────────────────────
# Кортеж: (code, name, direction, posting_template, default_cat_code|None, default_gl_code|None,
#          counts_in_pnl, counts_in_cashflow, is_internal_transfer)
#   direction: in | out | transfer | none

OP_TYPES: list[tuple[str, str, str, str, str | None, str | None, bool, bool, bool]] = [
    ("income_generic", "Поступление денег", "in", "cash_in", "OP-IN-OTH", None, True, True, False),
    ("expense_generic", "Расход денег", "out", "cash_out", "OP-OUT-OTH", None, True, True, False),
    ("transfer", "Перевод между счетами", "transfer", "transfer", None, None, False, False, True),
    ("opening_balance", "Ввод начального остатка", "in", "opening", None, OPENING_OFFSET_CODE, False, False, False),
    ("adjustment", "Корректировка (ручная)", "none", "manual_journal", None, None, False, False, False),
    ("reversal", "Сторно", "none", "reversal", None, None, False, False, False),
    # ── Ф3: интеграция факта оплаты (write-through) + ФОТ/комиссии + подписки ──
    # Поступление по сделке/договору (mark-paid, ContractPayment write-through):
    # cash_in, контр-счёт = выручка от лицензий (4020), статья ДДС — лицензии.
    ("income_deal", "Поступление по сделке/договору", "in", "cash_in", "OP-IN-LIC", "4020", True, True, False),
    # ФОТ-выплата (зарплата по MotivationalCard/SalaryPlan): cash_out, Дт 5110 ФОТ.
    ("payroll_salary", "Выплата зарплаты (ФОТ)", "out", "cash_out", "OP-OUT-PAY", "5110", True, True, False),
    # Выплата комиссии менеджеру (MotivationalCard.fact_commission): cash_out, Дт 5120.
    ("payroll_commission", "Выплата комиссии менеджеру", "out", "cash_out", "OP-OUT-COM", "5120", True, True, False),
    # Плановое поступление по активной подписке (платёжный календарь, status=planned):
    # cash_in, контр-счёт = выручка от подписок (4010), статья ДДС — MRR.
    ("subscription_planned", "Плановое поступление по подписке", "in", "cash_in", "OP-IN-SUB", "4010", True, True, False),
]


# ───────────────────────────── §5.4 ставки НДС ─────────────────────────────
# Кортеж: (name, rate_pct, kind, country_code|None)
#   kind: standard | reduced | zero | exempt

VAT_RATES: list[tuple[str, str, str, str | None]] = [
    ("ҚҚС 12% (KZ)", "12.00", "standard", "kz"),
    ("НДС 12% (UZ)", "12.00", "standard", "uz"),
    ("Без НДС", "0.00", "exempt", None),
]
#: Имя «нулевой» ставки для юрлиц vat_enabled=False.
VAT_RATE_NO_VAT_NAME = "Без НДС"


# ───────────────────────────── §7 роли + права ─────────────────────────────

#: capability-enum Ф0 (J §7.2). 3 последних — ручные журналы (решение 4).
CAPABILITIES: tuple[str, ...] = (
    "view_operations",
    "create_operation",
    "post_operation",
    "manage_accounts",
    "manage_categories",
    "close_period",
    "view_management",
    "view_reports",
    "manage_settings",
    "create_manual_journal",
    "post_manual_journal",
    "view_journal",
    "view_all_operations",
    # ── Ф2: реестр платежей + согласование + заявки (G §4/§6) ──
    "create_request",            # менеджер создаёт заявку (ЗП/комиссия/расход/платёж)
    "fulfill_request",           # бухгалтер конвертирует approved-заявку в операцию
    "manage_registry",           # бухгалтер ведёт реестр платежей (сбор/submit/проведение)
    "approve",                   # согласант голосует по операции/реестру/заявке
    "manage_approval_scenarios",  # CFO/админ настраивает сценарии согласования
    # ── Ф5: инвойсы + акты + вендор-счета + НДС-книги (G §9) ──
    "manage_invoices",           # бухгалтер ведёт счета клиентам + акты (CRUD/issue/pay)
    "manage_vendor_bills",       # бухгалтер ведёт входящие счета поставщиков (CRUD/confirm/pay)
    "view_vat",                  # просмотр НДС-книг + AR/AP aging-отчётов
    # ── Ф4: accrual (признание выручки) + переоценка + смена базы (G §2 реш.1/5) ──
    "recognize_revenue",         # бухгалтер признаёт выручку помесячно (MRR accrual)
    "run_revaluation",           # CFO/админ запускает переоценку валютных остатков
    "change_base_currency",      # CFO/админ меняет базовую валюту (тяжёлый пересчёт)
)

#: Роли, на которые сидятся дефолты прав.
SEEDED_ROLES: tuple[str, ...] = ("accountant", "cfo", "director", "manager", "admin")

# Дефолты прав по ролям (J §7.3). True == разрешено. Отсутствие == запрещено.
# manager.view_operations = «свои»: имеет view_operations, но НЕ view_all_operations →
# листинг операций фильтруется по created_by_user_id (см. routers/finance.list_operations).
# accountant/cfo/director/admin имеют view_all_operations → видят все операции юрлица.
ROLE_PERMISSIONS: dict[str, dict[str, bool]] = {
    "accountant": {
        "view_operations": True,
        "view_all_operations": True,
        "create_operation": True,
        "post_operation": True,
        "manage_accounts": True,
        "manage_categories": True,
        "create_manual_journal": True,
        "post_manual_journal": True,
        "view_journal": True,
        "view_reports": True,
        # Ф2: бухгалтер ведёт реестр и конвертирует одобренные заявки в операции.
        "fulfill_request": True,
        "manage_registry": True,
        # Ф5: бухгалтер ведёт счета/акты/вендор-счета + видит НДС-книги.
        "manage_invoices": True,
        "manage_vendor_bills": True,
        "view_vat": True,
        # Ф4: бухгалтер признаёт выручку помесячно (accrual MRR).
        "recognize_revenue": True,
    },
    "cfo": {
        "view_operations": True,
        "view_all_operations": True,
        "create_operation": True,
        "post_operation": True,
        "manage_accounts": True,
        "manage_categories": True,
        "create_manual_journal": True,
        "post_manual_journal": True,
        "view_journal": True,
        "close_period": True,
        "view_reports": True,
        "view_management": True,
        "manage_settings": True,
        # Ф2: CFO согласует и настраивает сценарии согласования + ведёт реестр/заявки.
        "fulfill_request": True,
        "manage_registry": True,
        "approve": True,
        "manage_approval_scenarios": True,
        # Ф5: CFO ведёт счета/акты/вендор-счета + видит НДС-книги.
        "manage_invoices": True,
        "manage_vendor_bills": True,
        "view_vat": True,
        # Ф4: CFO признаёт выручку, запускает переоценку, меняет базовую валюту.
        "recognize_revenue": True,
        "run_revaluation": True,
        "change_base_currency": True,
    },
    "director": {
        "view_operations": True,
        "view_all_operations": True,
        "view_reports": True,
        "view_management": True,
        # Ф2: руководитель — согласант по умолчанию.
        "approve": True,
        # Ф5: руководитель видит НДС/aging (read-only).
        "view_vat": True,
    },
    "manager": {
        "view_operations": True,
        # Ф2: менеджер создаёт заявки на ЗП/комиссию/расход.
        "create_request": True,
    },
    "admin": {cap: True for cap in CAPABILITIES},
}


# ───────────────────────────── чистые помощники для тестов ─────────────────────────────

def account_codes() -> list[str]:
    """Все коды плана счетов в порядке сида."""
    return [row[0] for row in ACCOUNTS_GL]


def cashflow_codes() -> list[str]:
    """Все коды дерева статей ДДС в порядке сида."""
    return [row[0] for row in CASHFLOW_TREE]


def normal_side_for_type(acc_type: str) -> str:
    """Ожидаемая нормальная сторона по классу счёта (asset/expense→Дт, иначе→Кт)."""
    if acc_type in ("asset", "expense"):
        return "dt"
    return "kt"
