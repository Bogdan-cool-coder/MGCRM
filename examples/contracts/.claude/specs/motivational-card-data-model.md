# Motivational Card — Data Model (на основе PDF Ильи Рогова Апрель 2026)

**Источник**: `МК Илья Рогов ОП 2026 - Апрель 2026.pdf` (3 страницы, salary slip конкретного менеджера за период).

## Структурный разбор PDF

### Заголовок документа
- **Salary Slip of Sales manager**: ФИО менеджера (Илья Рогов)
- **Supervisor**: ФИО руководителя (Богдан Ядыкин)
- **Company**: MACRO Global
- **Period**: месяц/год (Апрель 2026)

### План отдела продаж по новым поступлениям
| План | Факт |
|---|---|
| 800 000 RUB | 1 586 365 RUB |

Это **общий план отдела**, не личный. Используется для расчёта командного бонуса.

### Salary компоненты (3 строки)

**№1 — Salary (фикс оклад)**:
- Key points: «Basic Salary»
- Indicator: `UZS`
- Plan: 17 000 000 UZS
- Fact: 17 000 000 UZS
- %: 100%
- Salary plan UZS: 17 000 000
- Salary fact UZS: 17 000 000
- Notes: «Выплачивается в след. месяце за текущим»

**№2 — Comission (комиссия)**:
- Key points: **«10% от новых поступлений, зачисленных на РС компании в текущем месяце. В зачёт идут только ЛИЧНЫЕ сделки менеджера»**
- Indicator: `RUB` (валюта плана)
- Plan: 600 000 RUB
- Fact: 982 135 RUB
- %: 164%
- Salary plan UZS: 9 081 000 [1] (конвертировано в UZS по курсу начала месяца)
- Salary fact UZS: 15 674 500 [2] (breakdown по сделкам)
- Notes: «Выплачивается СРАЗУ в момент поступления денег на РС»
- Footnote [2]: разбивка по сделкам — «3 577 000 сум за Хентай / 2 240 000 сум за Baq Group / 9 857 500 сум за Qala Dev»

**№3 — KPI (командный бонус)**:
- Key points (полная формула):
  - **Командный бонус = 500 000 тенге** (на 2 менеджера). Каждый последующий менеджер `+ 100 000 тенге`.
  - **Делится в пропорции 60/40%**:
    - **Часть 1 (60% = 300 000 тенге)**: между менеджерами **по пропорции вклада в общую сумму новых поступлений**
    - **Часть 2 (40% = 200 000 тенге)**: между менеджерами **в равных долях**
  - Условие: **Мин. порог ОП по новым поступлениям = 80% от плана** (если выполнен — бонус начисляется)
- Indicator: `UZS/KZT`
- Plan: 640 000 (видимо в KZT — 2× 300 000 + 100 000 = 700K? либо UZS — нужно уточнить)
- Fact: 1 586 365
- %: 247.87%
- Часть 1: Salary plan UZS 300 000, Salary fact UZS 4 785 489
- Часть 2: Salary plan UZS 100 000, Salary fact UZS 2 577 000
- Notes: «Мин. порог ОП по новым поступлениям = 80% от плана»

### Total
- Salary plan UZS: 26 481 000
- Salary fact UZS: 40 036 989

### Target indicators (KPI секция, метрики работы)

**№1 — FIRST TIME meetings with new potential clients**:
- Conditions of execution: `FIRST TIME MEETING with client DONE`
- Indicator: `FTM` (First-Time Meetings)
- Explanation:
  > These are meetings with clients who have never been met before by ourselves. Such meetings are scheduled over the phone or by walking to the client's office. A successfully completed meeting is considered only when:
  > 1. The decision maker of client watched the presentation of the system
  > 2. There is a report on the meeting results in AMO CRM and in the MACRO Global Sales GCC chat in Telegram
- Курс UZS к 1 RUB: **151** [3] (ориентировочный на 06.04)

**№2 — NEW INCOME from new clients**:
- Conditions: `NEW INCOME FROM NEW CLIENT CREDITED TO COMPANY ACCOUNT`
- Indicator: `AED` (странный indicator — нужно уточнить, возможно используется неверно)
- Explanation:
  > This is the money that the client paid us for the first time regarding payment plan in purchase contract (first payment).
  > Conditions:
  > 1. This is a first payment from the client and we have never received money from him
  > 2. Signed contract between MACRO and the client from both sides
  > 3. Payment was received exactly for the amount indicated in contract in the payment plan
- Курс UZS к 1 KZT: **26** [4]

### Footnotes
- [1] «+- план по курсу на начало месяца»
- [2] разбивка по конкретным сделкам с суммами в UZS
- [3] ориентировочный курс на 06.04
- [4] ориентировочный курс на 06.04

---

## Извлечённая модель данных

### Сущности

#### 1. `SalaryPlan` (план зарплаты на месяц per user)
```sql
CREATE TABLE salary_plans (
  id SERIAL PRIMARY KEY,
  user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  period_year INT NOT NULL,
  period_month INT NOT NULL,
  supervisor_user_id INT REFERENCES users(id) ON DELETE SET NULL,
  
  -- Фикс оклад
  base_salary_amount NUMERIC(15, 2) NOT NULL,
  base_salary_currency VARCHAR(8) NOT NULL,  -- "UZS", "RUB", "KZT" и т.д.
  base_salary_payment_note TEXT,  -- "Выплачивается в след. месяце за текущим"
  
  -- Комиссия (правило)
  commission_rule_id INT REFERENCES commission_rules(id) ON DELETE SET NULL,
  
  -- План личных метрик
  personal_income_plan_amount NUMERIC(15, 2),
  personal_income_plan_currency VARCHAR(8),
  personal_ftm_plan INT,  -- сколько FTM встреч в плане
  
  -- Командный план (или ссылка на team_targets)
  team_target_id INT REFERENCES team_targets(id) ON DELETE SET NULL,
  
  created_at TIMESTAMPTZ,
  updated_at TIMESTAMPTZ,
  UNIQUE (user_id, period_year, period_month)
);
```

#### 2. `CommissionRule` (правило комиссии)
```sql
CREATE TABLE commission_rules (
  id SERIAL PRIMARY KEY,
  name VARCHAR(128),  -- "10% от новых поступлений"
  
  -- Процент комиссии
  rate_pct NUMERIC(5, 2) NOT NULL,  -- 10.00 = 10%
  
  -- База расчёта
  base_metric VARCHAR(32) NOT NULL,  -- "new_income_payments" (новые поступления)
  scope VARCHAR(32) NOT NULL,  -- "personal_deals" (только личные сделки) | "any_deal"
  
  -- Условия зачёта (whitelist statuses)
  applies_to_first_payment_only BOOLEAN DEFAULT true,
  requires_signed_contract BOOLEAN DEFAULT true,
  requires_amount_match_payment_plan BOOLEAN DEFAULT true,
  
  -- Когда выплачивается
  payment_trigger VARCHAR(32) DEFAULT 'immediate',  -- "immediate" | "monthly" | "quarterly"
  payment_note TEXT,  -- "Выплачивается СРАЗУ в момент поступления денег на РС"
  
  is_active BOOLEAN DEFAULT true,
  created_by_user_id INT,
  created_at TIMESTAMPTZ,
  updated_at TIMESTAMPTZ
);
```

#### 3. `TeamTarget` (плановая цель команды на месяц)
```sql
CREATE TABLE team_targets (
  id SERIAL PRIMARY KEY,
  team_id INT,  -- если есть отделы (Эпик 14)
  pipeline_id INT REFERENCES pipelines(id),  -- к какой воронке
  period_year INT,
  period_month INT,
  
  -- Что измеряем
  metric VARCHAR(32),  -- "new_income_total" | "ftm_count" | etc.
  target_amount NUMERIC(15, 2),
  target_currency VARCHAR(8),
  
  -- Командный бонус (если есть)
  bonus_pool_amount NUMERIC(15, 2),  -- 500 000
  bonus_pool_currency VARCHAR(8),  -- KZT
  bonus_per_additional_member NUMERIC(15, 2),  -- +100 000 за каждого менеджера сверх 2
  bonus_min_completion_pct NUMERIC(5, 2) DEFAULT 80.00,  -- мин порог 80% от плана
  bonus_split_proportional_pct NUMERIC(5, 2) DEFAULT 60.00,  -- 60% по пропорции вклада
  bonus_split_equal_pct NUMERIC(5, 2) DEFAULT 40.00,  -- 40% поровну
  
  is_active BOOLEAN DEFAULT true,
  created_at TIMESTAMPTZ,
  updated_at TIMESTAMPTZ
);
```

#### 4. `MotivationalCard` (рассчитанная МК за месяц)
```sql
CREATE TABLE motivational_cards (
  id SERIAL PRIMARY KEY,
  user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  period_year INT NOT NULL,
  period_month INT NOT NULL,
  
  -- Plan (из SalaryPlan)
  plan_snapshot_json JSONB,  -- snapshot всех планов на момент расчёта
  
  -- Fact (рассчитан backend'ом)
  fact_base_salary_amount NUMERIC(15, 2),
  fact_commission_amount NUMERIC(15, 2),
  fact_commission_currency VARCHAR(8),
  fact_commission_breakdown JSONB,  -- [{contract_id, counterparty_name, payment_amount, commission}]
  fact_team_bonus_proportional_amount NUMERIC(15, 2),  -- часть 1 (60%)
  fact_team_bonus_equal_amount NUMERIC(15, 2),  -- часть 2 (40%)
  
  -- Total (конвертация в валюту менеджера)
  total_amount_local NUMERIC(15, 2),  -- в валюте менеджера (UZS для Ильи)
  total_amount_currency_local VARCHAR(8),  -- UZS
  
  -- Курсы конвертации использованные на расчёт
  exchange_rates_snapshot JSONB,  -- {RUB_to_UZS: 151, KZT_to_UZS: 26, ...} на дату
  exchange_rates_date DATE,
  
  -- Метрики работы
  ftm_count_fact INT DEFAULT 0,
  new_income_amount_fact NUMERIC(15, 2),
  new_income_currency_fact VARCHAR(8),
  
  -- Статус
  status VARCHAR(16) DEFAULT 'draft',  -- draft / finalized / paid
  finalized_at TIMESTAMPTZ,
  finalized_by_user_id INT,
  paid_at TIMESTAMPTZ,
  
  created_at TIMESTAMPTZ,
  updated_at TIMESTAMPTZ,
  UNIQUE (user_id, period_year, period_month)
);
```

#### 5. `CurrencyRate` (курсы валют)
```sql
CREATE TABLE currency_rates (
  id SERIAL PRIMARY KEY,
  from_currency VARCHAR(8) NOT NULL,  -- "RUB"
  to_currency VARCHAR(8) NOT NULL,  -- "UZS"
  rate NUMERIC(20, 8) NOT NULL,  -- 151.00000000
  rate_date DATE NOT NULL,
  source VARCHAR(32),  -- "cbr" | "manual" | "uzcb" | "nbk"
  created_at TIMESTAMPTZ DEFAULT now(),
  UNIQUE (from_currency, to_currency, rate_date)
);
```

#### 6. `FirstTimeMeeting` (отдельная сущность для зачёта FTM)
Не отдельная таблица — это **Activity с kind='meeting'** + дополнительные поля:
```sql
-- расширить activities таблицу:
ALTER TABLE activities
  ADD COLUMN is_first_time_meeting BOOLEAN DEFAULT false,
  ADD COLUMN ftm_decision_maker_attended BOOLEAN DEFAULT false,
  ADD COLUMN ftm_presentation_shown BOOLEAN DEFAULT false,
  ADD COLUMN ftm_report_url TEXT,  -- ссылка на AMO CRM или внутренний отчёт
  ADD COLUMN ftm_telegram_announced BOOLEAN DEFAULT false;

-- условие зачёта:
-- WHERE kind='meeting' AND is_first_time_meeting=true 
--   AND ftm_decision_maker_attended=true 
--   AND ftm_presentation_shown=true 
--   AND ftm_report_url IS NOT NULL
```

#### 7. Расширение `users` под мультивалютность
```sql
ALTER TABLE users
  ADD COLUMN salary_currency VARCHAR(8) DEFAULT 'RUB',  -- валюта зарплаты по умолчанию
  ADD COLUMN salary_country_code VARCHAR(2),  -- KZ/UZ/RU/AE
  ADD COLUMN employment_start_date DATE;
```

#### 8. Расширение `contracts/payments` для tracking new income
```sql
-- если есть таблица payments:
ALTER TABLE payments
  ADD COLUMN is_first_payment_from_counterparty BOOLEAN,  -- автоматически вычисляется
  ADD COLUMN attributed_to_user_id INT REFERENCES users(id);  -- кому в зачёт идёт комиссия

-- если нет — создать минимальную:
CREATE TABLE contract_payments (
  id SERIAL PRIMARY KEY,
  contract_id INT REFERENCES contracts(id) ON DELETE CASCADE,
  counterparty_id INT REFERENCES counterparties(id) ON DELETE SET NULL,
  amount NUMERIC(15, 2) NOT NULL,
  currency VARCHAR(8) NOT NULL,
  payment_date DATE NOT NULL,
  attributed_to_user_id INT REFERENCES users(id),  -- кому идёт в зачёт
  is_first_payment_from_counterparty BOOLEAN DEFAULT false,
  notes TEXT,
  created_at TIMESTAMPTZ
);
```

---

## Алгоритм расчёта МК (per month, per user)

```python
async def compute_motivational_card(user_id: int, year: int, month: int) -> MotivationalCard:
    plan = await get_salary_plan(user_id, year, month)
    
    # 1. Base salary
    base_salary = plan.base_salary_amount  # как в плане
    
    # 2. Commission
    commission_rule = plan.commission_rule
    if commission_rule:
        # Найти все платежи в этом периоде, attributed_to_user_id=user_id
        # которые удовлетворяют условиям правила
        eligible_payments = await find_eligible_payments(
            user_id, year, month,
            applies_to_first_payment_only=commission_rule.applies_to_first_payment_only,
            requires_signed_contract=commission_rule.requires_signed_contract,
            requires_amount_match=commission_rule.requires_amount_match_payment_plan,
        )
        # Конвертация каждого платежа в базовую валюту правила
        total_base = sum(
            convert(p.amount, p.currency, commission_rule.base_currency, p.payment_date)
            for p in eligible_payments
        )
        commission_fact = total_base * commission_rule.rate_pct / 100
    
    # 3. Team bonus
    team_target = plan.team_target
    if team_target:
        team_fact = await sum_team_new_income(team_target, year, month)
        completion_pct = team_fact / team_target.target_amount * 100
        
        if completion_pct >= team_target.bonus_min_completion_pct:
            # Команда выполнила минимум — бонус начисляется
            n_members = await count_team_members(team_target)
            
            # Размер пула: 500K + (n-2)*100K если n > 2
            base_pool = team_target.bonus_pool_amount
            if n_members > 2:
                base_pool += (n_members - 2) * team_target.bonus_per_additional_member
            
            # Часть 1 (60%) — по пропорции вклада user в team_fact
            user_contribution = await get_user_contribution(user_id, year, month)
            user_share_pct = user_contribution / team_fact
            bonus_proportional = base_pool * (team_target.bonus_split_proportional_pct / 100) * user_share_pct
            
            # Часть 2 (40%) — поровну
            bonus_equal = base_pool * (team_target.bonus_split_equal_pct / 100) / n_members
    
    # 4. Конвертация в валюту менеджера
    user = await get_user(user_id)
    local_currency = user.salary_currency  # UZS для Ильи
    rates_date = date(year, month, 1)  # курс на 1 число месяца ИЛИ на дату расчёта (TBD)
    
    base_salary_local = convert(base_salary, plan.base_salary_currency, local_currency, rates_date)
    commission_local = convert(commission_fact, commission_rule.base_currency, local_currency, rates_date)
    bonus_proportional_local = convert(bonus_proportional, team_target.bonus_pool_currency, local_currency, rates_date)
    bonus_equal_local = convert(bonus_equal, team_target.bonus_pool_currency, local_currency, rates_date)
    
    total_local = base_salary_local + commission_local + bonus_proportional_local + bonus_equal_local
    
    return MotivationalCard(
        user_id=user_id,
        period_year=year,
        period_month=month,
        fact_base_salary_amount=base_salary,
        fact_commission_amount=commission_fact,
        fact_commission_breakdown=[{...} for p in eligible_payments],
        fact_team_bonus_proportional_amount=bonus_proportional,
        fact_team_bonus_equal_amount=bonus_equal,
        total_amount_local=total_local,
        total_amount_currency_local=local_currency,
        exchange_rates_snapshot={...},
        exchange_rates_date=rates_date,
        # ...
    )
```

## UI представления

### Admin builder МК (admin задаёт правила)
- `/admin/salary-plans` — список планов всех менеджеров по месяцам
- `/admin/salary-plans/[user_id]/[year]/[month]` — план конкретного user'а
- Поля:
  - Base salary amount + currency
  - Commission rule select (или inline создание)
  - Team target ref
  - Personal income plan + currency
  - Personal FTM plan
- `/admin/commission-rules` — CRUD правил
- `/admin/team-targets` — CRUD целей команд

### Manager view (личный кабинет)
- `/me/motivational-card` — текущий месяц
- `/me/motivational-card/history` — прошлые месяцы
- Карточка:
  - Header: ФИО, supervisor, период, company
  - Plan column / Fact column / % column / Salary plan UZS / Salary fact UZS
  - 3 строки: Salary / Commission / KPI
  - Total row
  - Целевые метрики (FTM count + New Income)
  - Курсы валют на дату
  - Breakdown комиссии (per сделка)
- **Цвет**: индикатор % выполнения (зелёный >=100%, жёлтый 80-99%, красный <80%)

## Open questions

1. **Где источник курсов валют?** ЦБ РФ API (cbr.ru) + НБ РК + ЦБ УЗ + ЦБ ОАЭ? Или manual ввод admin'ом?
2. **`first_payment_from_counterparty`** — как определять? Сейчас в `contracts.signed_at` есть факт подписания, но **`payments`** таблицы нет — нужно создать или использовать `Subscription.fee_paid_at`?
3. **Attribution** платежа к user'у — сейчас Deal.owner_user_id известен, но платёж к Deal не привязан явно. Нужно добавить `payment.deal_id` или `payment.contract_id` + автоматическая атрибуция.
4. **FTM tracking** — сейчас Activity `kind='meeting'` есть, но без специфичных полей. Расширить + UI checkbox при создании meeting «Это первая встреча с клиентом».
5. **Снапшот плана** — при изменении плана админом задним числом (например, в середине месяца) — пересчитывать МК или использовать снапшот на момент финализации?
6. **Минимальный порог 80%** — это от **личного** плана или **командного**? В PDF указано «80% от плана» для командного бонуса — значит командного. Уточнить.
