# ТЗ: Эпик 10.5 — Личный кабинет менеджера + KPI + Multi-currency + AI Assistant + Cold Call Trainer + AI карточки

**Статус**: готово для frontend-specialist  
**Дата**: 2026-06-02  
**Оценка**: 8.5 дней  
**Зависимости**: Эпик 14 (Departments — мягкая), Эпик 21 (Notifications — данные), Эпик 18 (AI паттерны)

---

## Обзор: 6 разделов эпика

| # | Раздел | Ключевые страницы | Приоритет |
|---|---|---|---|
| 1 | Личный кабинет `/me` | 6 табов + header stats | P0 (критично) |
| 2 | Multi-currency | `/admin/currency-rates` + inline конвертация в формах | P0 |
| 3 | Admin планы зарплат | `/admin/salary-plans`, `/admin/commission-rules`, `/admin/team-targets` | P0 |
| 4 | AI Assistant | Floating drawer на всех страницах | P1 |
| 5 | Cold Call Trainer | `/me/training` | P1 |
| 6 | AI карточки компаний | Modal в карточке контрагента | P1 |
| + | Mobile адаптация | `/deals`, `/registry`, `/counterparties`, `/me` | P1 |

### Новые файлы (минимум 30 компонентов)

```
apps/web/src/app/(app)/
  me/
    page.tsx                        — Личный кабинет (6 табов)
    training/
      page.tsx                      — Cold Call Trainer
  admin/
    currency-rates/
      page.tsx                      — Курсы валют
    salary-plans/
      page.tsx                      — Список планов зарплат
      [userId]/[year]/[month]/
        page.tsx                    — План конкретного менеджера
    commission-rules/
      page.tsx                      — CRUD правил комиссии
    team-targets/
      page.tsx                      — CRUD командных целей

apps/web/src/components/
  Me/
    MePageHeader.tsx                — Header с avatar + stats строка
    StatsBar.tsx                    — Строка план/факт/% с period select
    tabs/
      SummaryTab.tsx                — Таб Сводка
      MotivationalCardTab.tsx       — Таб МК (PDF-replica)
      MetricsTab.tsx                — Таб Метрики
      SubordinatesTab.tsx           — Таб Подопечные
      ActivityTab.tsx               — Таб Активность
      SettingsTab.tsx               — Таб Настройки
    widgets/
      ActiveDealsWidget.tsx         — Виджет горячих сделок
      TodayTasksWidget.tsx          — Виджет задач на сегодня
      MonthProgressWidget.tsx       — Виджет прогресса месяца
      NotificationsWidget.tsx       — Виджет уведомлений
    mk/
      MkTable.tsx                   — Таблица МК (salary/commission/kpi строки)
      MkBreakdown.tsx               — Разбивка комиссии по сделкам
      MkRatesFooter.tsx             — Курсы валют в футере МК
      MkStatusBadge.tsx             — Бейдж выполнения %
    metrics/
      SalesByDayChart.tsx           — График продаж по дням
      TeamComparisonBar.tsx         — Сравнение с командой
    training/
      ScenarioSetup.tsx             — Выбор сценария тренировки
      TrainingChat.tsx              — Чат тренировки
      TrainingScorecard.tsx         — Результаты тренировки
      TrainingHistory.tsx           — История тренировок
  AI/
    AiAssistantButton.tsx           — Floating кнопка AI
    AiAssistantDrawer.tsx           — Drawer с чатом
    AiChatMessage.tsx               — Bubble сообщения
    AiQuickChips.tsx                — Chips быстрых вопросов
    AiCompanyModal.tsx              — Modal AI анализа компании
  Currency/
    CurrencyRatesTable.tsx          — Таблица курсов
    CurrencyRateHistoryTable.tsx    — История курсов
    ManualRateModal.tsx             — Modal ручного ввода курса
    CurrencySelect.tsx              — Select валюты (переиспользуемый)
    AmountWithConversion.tsx        — Поле суммы + live конвертация
  SalaryPlans/
    SalaryPlansList.tsx             — Таблица планов всех менеджеров
    SalaryPlanForm.tsx              — Форма плана
    CommissionRuleForm.tsx          — Форма правила комиссии
    TeamTargetForm.tsx              — Форма командной цели
  FTM/
    FtmFields.tsx                   — Блок FTM полей в форме Activity
    FtmBadge.tsx                    — Бейдж FTM в timeline
```

---

## Раздел 1. Личный кабинет `/me`

**Зачем:** менеджер видит всё о своей работе в одном месте — текущие результаты, мотивационную карту, задачи и активность. Руководитель видит результаты подопечных.

**Страница:** `apps/web/src/app/(app)/me/page.tsx`

### Wireframe главной страницы

```
┌──────────────────────────────────────────────────────────────────────────┐
│ [Sidebar]   │                                                            │
│             │  ┌─────────────────────────────────────────────────────┐  │
│ - Кабинет ← │  │  HEADER BLOCK                                       │  │
│ - Дашборд   │  │                                                     │  │
│ - Сделки    │  │  [Avatar 80px]  Илья Рогов          [bi-pencil]    │  │
│ - Контраг.  │  │                 Менеджер по продажам               │  │
│ - Реестр    │  │                 Отдел: ОП Казахстан                │  │
│ - ...       │  │                 Руководитель: Богдан Ядыкин         │  │
│             │  └─────────────────────────────────────────────────────┘  │
│             │                                                            │
│             │  ┌─────────────────────────────────────────────────────┐  │
│             │  │  STATS BAR                        [Период: Месяц ▾] │  │
│             │  │                                                     │  │
│             │  │  Личные продажи    Цель команды    FTM    Score    │  │
│             │  │  982 135 ₽         1 586 365 ₽     3/5    87%     │  │
│             │  │  план 600 000 ₽    план 800 000 ₽                  │  │
│             │  │  [badge: 164%]     [badge: 198%]                   │  │
│             │  └─────────────────────────────────────────────────────┘  │
│             │                                                            │
│             │  [Сводка] [МК] [Метрики] [Подопечные] [Активность] [Настройки]
│             │  ─────────────────────────────────────────────────────     │
│             │                                                            │
│             │  [CONTENT по активному табу]                              │
│             │                                                            │
└──────────────────────────────────────────────────────────────────────────┘
```

### MePageHeader (`components/Me/MePageHeader.tsx`)

**Layout**: `card` с горизонтальным flex-рядом на desktop, вертикальным на mobile.

**Левая часть:**
- `Avatar` компонент (80px) — существующий `@/components/Avatar`
- Рядом: блок текста
  - `full_name` — `text-xl font-semibold text-gray-900 dark:text-white`
  - `role` / должность — `text-sm text-gray-500`
  - Отдел (если есть `department_name`) — `text-sm text-gray-500`, иконка `bi-diagram-3-fill mr-1`
  - Руководитель — `text-sm text-gray-500`, иконка `bi-person-fill mr-1`, имя как ссылка на `/me?user_id=<supervisor_id>` (публичный вид)

**Правая часть (ml-auto):**
- Кнопка `btn-ghost` с иконкой `bi-pencil` → переход на `/profile` (редактирование)
- Если текущий user === просматриваемый: показывать кнопку
- Если admin смотрит чужой кабинет: не показывать кнопку редактирования

**Props**: `userId?: number` (если не передан — берём `useMe()`)

**SWR-ключ**: `/api/me/profile` или `/api/users/{userId}/profile` (при просмотре чужого кабинета)

### StatsBar (`components/Me/StatsBar.tsx`)

**Layout**: `card` с 4 колонками (grid-cols-4 gap-4 на desktop, grid-cols-2 gap-3 на mobile).

**Period Select** — в правом верхнем углу карточки: `select` (`input`-класс, w-auto) с вариантами:
- `Текущий месяц`
- `Прошлый месяц`
- `Текущий квартал`
- `Текущий год`

При изменении — обновляет SWR-ключ `/api/me/dashboard?period=<value>`.

**4 стат-блока** (каждый — вертикальный flex):

```
┌─────────────────────┐
│  Личные продажи     │  ← text-xs text-gray-500 uppercase tracking-wide
│  982 135 ₽          │  ← text-2xl font-bold text-gray-900 dark:text-white
│  план 600 000 ₽     │  ← text-xs text-gray-400
│  [badge: 164%]      │  ← MkStatusBadge: success/warning/danger
└─────────────────────┘
```

1. **Личные продажи** — `personal_income_fact` / `personal_income_plan`, badge %
2. **Цель команды** — `team_income_fact` / `team_income_plan`, badge %  
3. **FTM** — `ftm_count_fact / ftm_count_plan` (например `3 / 5`), badge зелёный если ≥ плана
4. **Score** — процент выполнения МК в целом. Badge зелёный ≥100%, жёлтый 80-99%, красный <80%

**SWR**: `GET /api/me/dashboard?period={period}&user_id={userId}`

### Tabs компонент

Табы рендерятся прямо в `me/page.tsx` — нативный React state `activeTab`.

```
const TABS = [
  { id: "summary",        label: "Сводка" },
  { id: "mk",             label: "МК" },
  { id: "metrics",        label: "Метрики" },
  { id: "subordinates",   label: "Подопечные" },  // показывать если user.has_subordinates
  { id: "activity",       label: "Активность" },
  { id: "settings",       label: "Настройки" },
]
```

**Стиль табов**: горизонтальный ряд кнопок, `border-b border-gray-200 dark:border-gray-700`, активный таб — `border-b-2 border-primary text-primary font-medium`, неактивный — `text-gray-500 hover:text-gray-700 dark:hover:text-gray-300 py-2 px-4 text-sm transition-colors`.

Таб «Подопечные» показывается только если `user.subordinates_count > 0` (поле из `/api/me/profile`).

---

### Tab 1: Сводка (`components/Me/tabs/SummaryTab.tsx`)

#### Wireframe

```
┌──────────────────────────────────────────────────────────────┐
│  [SummaryTab]                                                │
│                                                              │
│  ┌──────────────────────┐  ┌───────────────────────────────┐ │
│  │ Активные сделки      │  │ Задачи на сегодня             │ │
│  │ (top 5 по heat score)│  │ (Activity kind=task, due=today)│ │
│  │                      │  │                               │ │
│  │ [Deal row x5]        │  │ [Task row x N]               │ │
│  │                      │  │                               │ │
│  │ [Все сделки →]       │  │ [Все задачи →]               │ │
│  └──────────────────────┘  └───────────────────────────────┘ │
│                                                              │
│  ┌──────────────────────┐  ┌───────────────────────────────┐ │
│  │ Прогресс месяца      │  │ Последние уведомления         │ │
│  │ [bar chart]          │  │ [NotificationItem x3]         │ │
│  │ план vs факт         │  │                               │ │
│  │                      │  │ [Все уведомления →]          │ │
│  └──────────────────────┘  └───────────────────────────────┘ │
└──────────────────────────────────────────────────────────────┘
```

**Layout**: `grid grid-cols-1 gap-4 md:grid-cols-2`

#### Виджет «Активные сделки» (`components/Me/widgets/ActiveDealsWidget.tsx`)

- `card` с заголовком «Активные сделки» (`text-sm font-medium text-gray-900 dark:text-white`) + кнопка-ссылка «Все →» (`btn-ghost text-sm`) в заголовке
- Список до 5 сделок, отсортированных по `heat_score DESC`
- Каждая строка:
  ```
  [bi-kanban text-primary]  Название сделки            [heat badge]
                            Контрагент · Этап           Сумма
  ```
  - `heat_score` → badge: ≥70 `bg-danger/10 text-danger bi-fire`, 40-69 `bg-warning/10 text-warning`, <40 `bg-info/10 text-info`
  - Строка кликабельна → `/deals/{id}`
- **Empty**: иконка `bi-kanban text-3xl text-gray-300`, текст «Нет активных сделок»
- **Loading**: 5 строк `animate-pulse h-10 bg-gray-100 dark:bg-gray-700 rounded`
- **SWR**: `GET /api/me/dashboard` → поле `active_deals` (top 5)

#### Виджет «Задачи на сегодня» (`components/Me/widgets/TodayTasksWidget.tsx`)

- `card` + заголовок «Сегодняшние задачи» + «Все →» ссылка
- Список Activity с `kind=task`, `due_date=today`, `responsible_id=me`
- Каждая строка:
  ```
  [checkbox-like circle]  Название задачи          [bi-clock text-warning если просрочена]
                          Сущность (Сделка/Контрагент)   09:00
  ```
  - Кнопка-circle (стилизованный `button`) → `PATCH /api/activities/{id}` с `is_done=true` → мутация SWR
  - Просроченная — `text-danger`
- **Empty**: `bi-check-circle text-3xl text-gray-300` + «Задач нет — хороший день»
- **SWR**: `GET /api/me/dashboard` → поле `today_tasks`

#### Виджет «Прогресс месяца» (`components/Me/widgets/MonthProgressWidget.tsx`)

- `card` + заголовок «Прогресс месяца (название_месяца год)»
- **Bar chart** — простой кастомный HTML/CSS без Chart.js:
  ```
  Личные продажи  ████████████░░░  164%  982 135 ₽
  Командный план  █████████████████████  198%  1 586 365 ₽
  FTM             ██████░░░░░  3 из 5
  ```
  - Каждый бар: `div` с фиксированной высотой `h-3 rounded-full bg-gray-100 dark:bg-gray-700`, внутри `div` с шириной `min(pct, 100)%` и цветом по badge-логике
  - Под баром: label + факт / план
- **SWR**: те же данные из `/api/me/dashboard`

#### Виджет «Последние уведомления» (`components/Me/widgets/NotificationsWidget.tsx`)

- `card` + заголовок «Уведомления» + «Все →` ссылка → `/notifications`
- До 3 последних уведомлений из `GET /api/notifications?limit=3&unread_only=false`
- Отображение аналогично существующему `NotificationBell` — дата + текст + иконка
- **Empty**: `bi-bell text-3xl text-gray-300` + «Новых уведомлений нет»

---

### Tab 2: МК (`components/Me/tabs/MotivationalCardTab.tsx`)

**Зачем:** PDF salary slip воспроизведён в веб-интерфейсе. Менеджер видит свою мотивационную карту с breakdown комиссии, может выбрать период и скачать PDF.

#### Wireframe

```
┌─────────────────────────────────────────────────────────────────┐
│  МК                                          [Период: апр 2026 ▾]│
│                                                                   │
│  ┌─────────────────────────────────────────────────────────────┐ │
│  │  MACRO Global    ·    Илья Рогов    ·    Богдан Ядыкин      │ │
│  │  Апрель 2026                                                │ │
│  │                                                             │ │
│  │  ┌──────────────────────────────────────────────────────┐  │ │
│  │  │  ПЛАН ОТДЕЛА ПО НОВЫМ ПОСТУПЛЕНИЯМ                  │  │ │
│  │  │  800 000 ₽  →  Факт: 1 586 365 ₽    [badge: 198%]  │  │ │
│  │  └──────────────────────────────────────────────────────┘  │ │
│  │                                                             │ │
│  │  Таблица МК (MkTable)                                      │ │
│  │  ┌────────┬──────────┬──────────┬──────┬──────┬──────────┐ │ │
│  │  │        │  Plan    │  Fact    │  %   │Plan  │Fact      │ │ │
│  │  │        │          │          │      │ UZS  │ UZS      │ │ │
│  │  ├────────┼──────────┼──────────┼──────┼──────┼──────────┤ │ │
│  │  │Оклад   │17 000 000│17 000 000│[100%]│17 000│17 000    │ │ │
│  │  │Комиссия│  600 000₽│  982 135₽│[164%]│ 9 081│15 675    │ │ │
│  │  │KPI [?] │  640 000 │1 586 365 │[247%]│   400│ 7 362    │ │ │
│  │  ├────────┼──────────┼──────────┼──────┼──────┼──────────┤ │ │
│  │  │ИТОГО   │          │          │      │26 481│40 037    │ │ │
│  │  └────────┴──────────┴──────────┴──────┴──────┴──────────┘ │ │
│  │                                                             │ │
│  │  Целевые метрики                                            │ │
│  │  FTM: 3 встречи выполнено                                   │ │
│  │  Новые поступления: 982 135 ₽                              │ │
│  │                                                             │ │
│  │  MkBreakdown (комиссия по сделкам)                         │ │
│  │  [Хентай]  →  3 577 000 UZS                               │ │
│  │  [Baq Group] → 2 240 000 UZS                              │ │
│  │  [Qala Dev]  → 9 857 500 UZS                              │ │
│  │                                                             │ │
│  │  MkRatesFooter (курсы валют)                               │ │
│  │  RUB → UZS: 151   ·   KZT → UZS: 26                      │ │
│  │                                                             │ │
│  │  Статус: [badge: Финализировано]        [bi-download PDF] │ │
│  └─────────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────────┘
```

#### Period Select

- В правом верхнем углу таба: `select` (`input`-класс, `w-48`)
- Опции: последние 12 месяцев (генерируются JS: `[{value: "2026-04", label: "Апрель 2026"}, ...]`) + текущий месяц первым
- При смене → обновить SWR-ключ

#### MkTable (`components/Me/mk/MkTable.tsx`)

**Таблица** — полная ширина, в `card`.

**Колонки** (`thead` с `text-xs font-medium text-gray-500 uppercase tracking-wide`):

| # | Колонка | Описание |
|---|---|---|
| 1 | Key points | Название строки + иконка `bi-info-circle` с tooltip формулы |
| 2 | Indicator | Валюта измерения (RUB / KZT / UZS) |
| 3 | Plan | Плановое значение, форматированное (`toLocaleString`) |
| 4 | Fact | Фактическое значение |
| 5 | % | `MkStatusBadge` |
| 6 | Plan UZS | тыс. UZS (divide by 1000 если нужно, или полная сумма) |
| 7 | Fact UZS | тыс. UZS |

**Строки tbody:**

1. **Оклад (Salary)**:
   - Key points: «Оклад (базовый)» + tooltip «Выплачивается в следующем месяце за текущим»
   - Indicator: `base_salary_currency` (UZS)
   - Plan / Fact одинаковы (фиксированный оклад)
   - % → `MkStatusBadge` с `100`

2. **Комиссия (Commission)**:
   - Key points: «Комиссия» + tooltip «10% от новых поступлений. Только личные сделки. Только первый платёж.» + «Выплачивается сразу при поступлении»
   - Plan = `personal_income_plan * commission_rule.rate_pct / 100`
   - Fact = рассчитан backend
   - % → `MkStatusBadge`
   - В ячейке Fact UZS: при клике разворачивается строка `MkBreakdown` (accordion) — список сделок

3. **KPI (Командный бонус)**:
   - Key points: «KPI (команда)» + tooltip с полной формулой бонуса (60%/40%, порог 80%)
   - Indicator: валюта пула (KZT)
   - Plan = `bonus_pool_amount`
   - Fact = `fact_team_bonus_proportional_amount + fact_team_bonus_equal_amount`
   - Под строкой KPI — 2 подстроки (если expanded):
     - «Часть 1 (60%, пропорция)» — plan/fact/UZS
     - «Часть 2 (40%, поровну)» — plan/fact/UZS
   - Подстроки — `tr` с `bg-gray-50 dark:bg-gray-800/50 text-xs`

**Footer строка (ИТОГО)**:
- `tr` с `font-semibold`, `border-t-2 border-gray-300 dark:border-gray-600`
- Суммарный Plan UZS и Fact UZS из backend (`total_amount_local`)
- `MkStatusBadge` для итогового %

**Tooltip по клику на `bi-info-circle`**: простой `title` атрибут или кастомный tooltip `div.absolute.z-10.bg-gray-900.text-white.text-xs.p-2.rounded.shadow-lg.w-64`. Управляется React state `hoveredTooltip: string | null`.

#### MkStatusBadge (`components/Me/mk/MkStatusBadge.tsx`)

```
pct >= 100  → badge bg-success/10 text-success   "[pct]%"
pct 80-99   → badge bg-warning/10 text-warning   "[pct]%"
pct < 80    → badge bg-danger/10 text-danger     "[pct]%"
```

**Props**: `pct: number`

#### MkBreakdown (`components/Me/mk/MkBreakdown.tsx`)

- Таблица внутри аккордеон-строки комиссии
- Колонки: Клиент / Договор / Сумма платежа / Комиссия UZS
- Строки из `fact_commission_breakdown` JSON
- Итоговая строка «Итого комиссия» — `font-semibold`
- **Empty** (если breakdown пуст): «Нет платежей в этом периоде» `text-gray-400 text-sm`

#### MkRatesFooter (`components/Me/mk/MkRatesFooter.tsx`)

- Ряд бейджей курсов: для каждой пары в `exchange_rates_snapshot`:
  ```
  [RUB → UZS: 151]   [KZT → UZS: 26]   [курсы на 01.04.2026]
  ```
- Стиль: `flex flex-wrap gap-2`, каждый бейдж `badge bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 text-xs`

#### Статус МК и кнопка PDF

- В нижней части карточки:
  - Статус бейдж: `draft` → `badge bg-info/10 text-info «Черновик»`, `finalized` → `badge bg-success/10 text-success «Финализировано»`, `paid` → `badge bg-primary/10 text-primary «Выплачено»`
  - Кнопка «Скачать PDF» `btn-secondary` с иконкой `bi-download mr-2` → `GET /api/motivational-cards/{id}/pdf` (download)

**SWR**: `GET /api/me/motivational-card?period=YYYY-MM`

**States**:
- **Loading**: skeleton из 3 строк таблицы `animate-pulse`
- **Empty** (нет МК за период): иконка `bi-file-earmark-bar-graph text-4xl text-gray-300` + «МК за этот период ещё не рассчитана» + «Обратитесь к руководителю»
- **Error**: `text-danger text-sm` над таблицей «Не удалось загрузить МК»

---

### Tab 3: Метрики (`components/Me/tabs/MetricsTab.tsx`)

#### Wireframe

```
┌─────────────────────────────────────────────────────────────────┐
│  Метрики                                   [Период: месяц 2026 ▾]│
│                                                                   │
│  ┌─────────────────────────────────────────────────────────────┐ │
│  │  Личные показатели                                          │ │
│  │                                                             │ │
│  │  ┌───────────────────────┐  ┌────────────────────────────┐ │ │
│  │  │ SalesByDayChart       │  │ Воронка личных сделок       │ │
│  │  │ (продажи по дням)     │  │ Inbound Leads: 24          │ │
│  │  │                       │  │ qualification:  12          │ │
│  │  │    ▂▅█▂▄▇▃█▄▂▅       │  │ Meeting:         8          │ │
│  │  │ 01  10  20  30        │  │ HOT:             5          │ │
│  │  └───────────────────────┘  │ Success:         2  ← %     │ │
│  │                             └────────────────────────────┘ │ │
│  │  ┌───────────────────────┐  ┌────────────────────────────┐ │ │
│  │  │ Средний цикл сделки   │  │ Конверсия по этапам        │ │
│  │  │ 23 дня                │  │ Inbound→qual: 50%          │ │
│  │  │ (avg days to success) │  │ qual→meeting: 67%          │ │
│  │  └───────────────────────┘  └────────────────────────────┘ │ │
│  └─────────────────────────────────────────────────────────────┘ │
│                                                                   │
│  ┌─────────────────────────────────────────────────────────────┐ │
│  │  Сравнение с командой                                       │ │
│  │                                                             │ │
│  │  TeamComparisonBar                                          │ │
│  │  Вы:        ████████████████████  164%                     │ │
│  │  Команда:   ████████████          90%                      │ │
│  │                                                             │ │
│  │  Ваш ранг в команде: #1 из 4 менеджеров                   │ │
│  └─────────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────────┘
```

#### SalesByDayChart (`components/Me/metrics/SalesByDayChart.tsx`)

- `card` без внешней библиотеки (CSS/SVG bar chart)
- Данные: массив `{date: "2026-04-01", amount: 120000}` за последние 30 дней
- Bar chart: горизонтальная ось — дни, вертикальная — сумма
- Реализация: SVG `<rect>` bars, ширина бара = `100% / N_DAYS / 1.2`, gap
- Цвет бара: `fill={primary}` (#172747), hover — `primary-light`
- Ось X: подписи `01`, `05`, `10`, `15`, `20`, `25`, `30`
- Ось Y: подписи в тыс. ₽ (`100K`, `500K`)
- Tooltip при hover: `absolute div` с датой + суммой (`toLocaleString('ru-RU')`)

#### Воронка личных сделок

- `card` с вертикальным списком этапов
- Каждая строка: этап → кол-во сделок → конверсия из предыдущего
  ```
  Inbound Leads    24   |██████████████████████████| 100%
  qualification    12   |█████████████             |  50%  ←text-warning если <60%
  Meeting           8   |█████████                 |  67%
  HOT deals         5   |██████                    |  63%
  Success           2   |██                        |  40%  ←text-danger если <30%
  ```
- Бар: `div` с `bg-primary/20`, внутри `bg-primary` шириной `(count/max)*100%`
- Конверсия цветом: ≥60% `text-success`, 30-59% `text-warning`, <30% `text-danger`

#### TeamComparisonBar (`components/Me/metrics/TeamComparisonBar.tsx`)

- `card` «Сравнение с командой»
- 2 бара: Вы / Команда (средний %)
- Ранг: `Ваш ранг: #N из M менеджеров` — `text-sm font-medium`
- Если ранг #1 → иконка `bi-trophy-fill text-warning mr-1`

**SWR**: `GET /api/me/metrics?period=YYYY-MM`

**States**: Loading → skeleton 4 блоков; Empty → «Нет данных за период»; Error → inline danger

---

### Tab 4: Подопечные (`components/Me/tabs/SubordinatesTab.tsx`)

**Показывать только** если `user.subordinates_count > 0`. Иначе таб не рендерится.

#### Wireframe

```
┌─────────────────────────────────────────────────────────────────┐
│  Подопечные (4 менеджера)                                        │
│                                                                   │
│  ┌───────────────────────────────────────────────────────────┐  │
│  │ ФИО          │ Отдел  │ План  │ Факт   │  %  │ FTM │ Риск│  │
│  ├──────────────┼────────┼───────┼────────┼─────┼─────┼─────┤  │
│  │ Илья Рогов   │ ОП КЗ  │600 000│982 135 │[164%]│ 3/5│  ✓  │  │
│  │ Анна Ли      │ ОП РУ  │500 000│385 000 │ [77%]│ 1/5│  ⚠  │  │
│  │ ...          │ ...    │ ...   │ ...    │ ...  │ ..  │ ..  │  │
│  └───────────────────────────────────────────────────────────┘  │
│                                                                   │
└─────────────────────────────────────────────────────────────────┘
```

**Таблица** в `card`:

| Колонка | Данные | Форматирование |
|---|---|---|
| ФИО | `full_name`, кликабельная ссылка → `/me?user_id={id}` | `text-primary hover:underline` |
| Отдел | `department_name` | `text-sm text-gray-500` |
| План | `personal_income_plan` + валюта | `text-sm` |
| Факт | `personal_income_fact` + валюта | `text-sm font-medium` |
| % выполнения | `MkStatusBadge` | badge |
| FTM | `ftm_count_fact / ftm_count_plan` | `text-sm` |
| Статус | ≥80% `badge success «На треке»`, <80% `badge warning «Риск»`, <50% `badge danger «Тревога»` | badge |

**Строки кликабельны** → переход на `/me?user_id={id}` (public view подчинённого).

**SWR**: `GET /api/me/subordinates?period={period}`

**States**: Loading → skeleton; Empty → «Нет подопечных» (не показывается вообще если так); Error → inline danger.

---

### Tab 5: Активность (`components/Me/tabs/ActivityTab.tsx`)

#### Wireframe

```
┌─────────────────────────────────────────────────────────────────┐
│  Активность                                                      │
│  [Фильтр: Все ▾]  [Период: Эта неделя ▾]  [FTM: Только FTM ☐] │
│                                                                   │
│  ┌─────────────────────────────────────────────────────────────┐ │
│  │ Timeline активностей                                        │ │
│  │                                                             │ │
│  │  02 июня 2026                                               │ │
│  │  ·  [bi-telephone]  Звонок с Baq Group    10:00  [FTM ✓]  │ │
│  │  ·  [bi-calendar]   Встреча с Qala Dev    14:00           │ │
│  │                                                             │ │
│  │  01 июня 2026                                               │ │
│  │  ·  [bi-check2]     Задача: отправить КП   ✓ выполнено    │ │
│  │  ·  [bi-chat-left]  Заметка: обсудили...                  │ │
│  └─────────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────────┘
```

**Фильтры** (flex row, gap-2):
- Kind select: «Все» / «Звонки» / «Встречи» / «Задачи» / «Заметки» — `select input`-класс `w-36`
- Period select: «Сегодня» / «Неделя» / «Месяц» — `select input`-класс `w-36`
- Чекбокс «Только FTM» — кастомный `checkbox` + label

**Timeline**: группировка по дате (заголовок `text-xs font-medium text-gray-400 uppercase mt-4 mb-2`).

**Каждая Activity строка**:
```
[иконка kind]   Название                  [время]   [FtmBadge если is_ftm]
                Привязанная сущность
```

- Иконки: `call → bi-telephone`, `meeting → bi-calendar-event`, `task → bi-check2-square`, `note → bi-chat-left-text`
- FtmBadge: компонент `components/FTM/FtmBadge.tsx`

**SWR**: `GET /api/me/activities?kind={kind}&period={period}&ftm_only={ftm_only}&user_id={userId}`

#### FtmBadge (`components/FTM/FtmBadge.tsx`)

- Показывается только для `kind=meeting AND is_first_time_meeting=true`
- Зачтена (все 4 условия выполнены): `badge bg-success/10 text-success` + `bi-award-fill mr-1` + «FTM»
- Не зачтена: `badge bg-warning/10 text-warning` + `bi-exclamation-triangle mr-1` + «FTM (не зачтено)»
- Tooltip при hover: какое условие не выполнено

#### FTM расширение в форме Activity

В существующей форме создания/редактирования Activity (`kind=meeting`):

**Компонент FtmFields** (`components/FTM/FtmFields.tsx`):
- Чекбокс «Это первая встреча с этим клиентом» — при `check=true` разворачивается блок (анимация `transition-all`)
- Блок FTM деталей:
  ```
  [☐] Присутствовал ЛПР (лицо, принимающее решение)
  [☐] Показана презентация системы
  [  URL отчёта о встрече                          ]
  [☐] Объявлено в Telegram (MACRO Global Sales)
  ```
- Каждый чекбокс — `input[type=checkbox]` + `label` с обычным текстом
- URL поле — `input input`-класс, `placeholder="https://..."`, `type="url"`
- При `is_first_time_meeting=false` весь блок `hidden`

---

### Tab 6: Настройки (`components/Me/tabs/SettingsTab.tsx`)

#### Wireframe

```
┌─────────────────────────────────────────────────────────────────┐
│  Настройки профиля                                               │
│                                                                   │
│  ┌──────────────────────────────────────────────────┐           │
│  │  Безопасность                                    │           │
│  │  [Старый пароль  ]  [Новый пароль  ]  [Сохранить]│           │
│  └──────────────────────────────────────────────────┘           │
│                                                                   │
│  ┌──────────────────────────────────────────────────┐           │
│  │  Интерфейс                                       │           │
│  │  Тема: [Светлая / Тёмная / Системная  ▾]         │           │
│  └──────────────────────────────────────────────────┘           │
│                                                                   │
│  ┌──────────────────────────────────────────────────┐           │
│  │  Валюты для конвертации                          │           │
│  │  Основная валюта: [UZS ▾]                        │           │
│  │  Показывать в: [☑ RUB] [☑ KZT] [☐ USD] [☐ AED]  │           │
│  └──────────────────────────────────────────────────┘           │
└─────────────────────────────────────────────────────────────────┘
```

**Секция «Безопасность»** (`card`):
- Переиспользует логику из `/profile` (Эпик 16) — форма смены пароля
- 2 поля `input[type=password]` + `btn-primary «Сменить пароль»`
- `PATCH /api/me/password`

**Секция «Интерфейс»** (`card`):
- Select темы (переиспользует `ThemeToggle` или inline select): `Светлая / Тёмная / Системная`
- Связано с localStorage (`crm-theme`)

**Секция «Валюты»** (`card`):
- Select «Основная валюта» — `salary_currency` из profle: `RUB / USD / EUR / KZT / UZS / AED`
- Мультичекбокс «Показывать конвертацию в» — сохраняется в user preferences
- `PATCH /api/me/preferences` с `{salary_currency, display_currencies: ["RUB", "KZT"]}`
- `btn-primary «Сохранить»` внизу секции

---

## Раздел 2. Multi-currency UI

### Страница `/admin/currency-rates`

**Путь:** `apps/web/src/app/(app)/admin/currency-rates/page.tsx`

**Роли**: только `admin`, `director` (через `RoleGate`).

#### Wireframe

```
┌──────────────────────────────────────────────────────────────────┐
│ [PageHeader: Курсы валют]    [bi-arrow-repeat Обновить из API]   │
│                                                                   │
│  ┌─────────────────────────────────────────────────────────────┐ │
│  │  Актуальные курсы (сегодня, 02.06.2026)                    │ │
│  │                                                             │ │
│  │  CurrencyRatesTable                                         │ │
│  │  Пара      │ Курс    │ Дата       │ Источник  │ Действия   │ │
│  │  RUB→UZS   │ 151.00  │ 02.06.2026 │ exchangerate-api │ [✎] │ │
│  │  KZT→UZS   │  26.00  │ 02.06.2026 │ exchangerate-api │ [✎] │ │
│  │  USD→UZS   │ 12 650  │ 02.06.2026 │ exchangerate-api │ [✎] │ │
│  │  AED→UZS   │  3 443  │ 02.06.2026 │ exchangerate-api │ [✎] │ │
│  └─────────────────────────────────────────────────────────────┘ │
│                                                                   │
│  ┌─────────────────────────────────────────────────────────────┐ │
│  │  История курсов (последние 30 дней)     [Пара: RUB→UZS ▾]  │ │
│  │                                                             │ │
│  │  CurrencyRateHistoryTable                                   │ │
│  │  Дата       │ Курс    │ Изменение   │ Источник              │ │
│  │  02.06.2026 │ 151.00  │ +0.5  (+0.3%) │ exchangerate-api   │ │
│  │  01.06.2026 │ 150.50  │ —           │ exchangerate-api     │ │
│  │  ...                                                        │ │
│  └─────────────────────────────────────────────────────────────┘ │
└──────────────────────────────────────────────────────────────────┘
```

#### PageHeader Actions
- Кнопка «Обновить из API» `btn-secondary bi-arrow-repeat mr-2`:
  - Click → `POST /api/admin/currency-rates/refresh`
  - Loading state: иконка `bi-arrow-repeat` с `animate-spin`, disabled
  - Success → inline `text-success text-sm «Курсы обновлены»` рядом с кнопкой (исчезает через 3 сек)
  - Error → `text-danger text-sm`
- Кнопка «Добавить вручную» `btn-primary bi-plus-lg mr-2` → открывает `ManualRateModal`

#### CurrencyRatesTable (`components/Currency/CurrencyRatesTable.tsx`)

Таблица в `card`. Данные из `GET /api/currency-rates?date=today`.

**Колонки**: Пара (FROM → TO) / Курс (`toLocaleString`, 2-8 знаков) / Дата / Источник / Действия.

**Источник** → badge:
- `exchangerate-api` → `badge bg-info/10 text-info «API»`
- `manual` → `badge bg-warning/10 text-warning «Вручную»`

**Действия**: иконка-кнопка `bi-pencil text-gray-400 hover:text-primary` → открывает `ManualRateModal` в режиме edit.

#### CurrencyRateHistoryTable (`components/Currency/CurrencyRateHistoryTable.tsx`)

- Select «Пара валют» слева от заголовка (пары из актуальных курсов)
- Данные: `GET /api/currency-rates?from={from}&to={to}&limit=30`
- Колонки: Дата / Курс / Изменение (delta + %) / Источник
- Изменение: `+0.5 (+0.3%)` `text-success`, `−0.5 (−0.3%)` `text-danger`, «—» нейтральный

#### ManualRateModal (`components/Currency/ManualRateModal.tsx`)

- Использует `Modal` компонент (`@/components/Modal`)
- **Форма**:
  ```
  Валюта (из)  [RUB ▾]    →    Валюта (в)   [UZS ▾]
  Курс         [151.00         ]
  Дата         [02.06.2026     ]   (date input, default: today)
  ```
  - `from_currency`: `CurrencySelect` с вариантами `RUB / USD / EUR / KZT / UZS / AED`
  - `to_currency`: `CurrencySelect`
  - `rate`: `input[type=number]`, `step="0.00000001"`, `min="0"`
  - `rate_date`: `input[type=date]`
- Кнопки: `[btn-ghost Отмена] ... [btn-primary Сохранить]`
- Submit → `POST /api/admin/currency-rates` (создание) или `PATCH /api/admin/currency-rates/{id}` (редактирование)
- Inline error под формой если 422/409

#### CurrencySelect (`components/Currency/CurrencySelect.tsx`)

Переиспользуемый `select` для выбора валюты:

```tsx
interface CurrencySelectProps {
  value: string;
  onChange: (v: string) => void;
  className?: string;
  label?: string;
}
```

Варианты: `RUB (₽) / USD ($) / EUR (€) / KZT (₸) / UZS (сум) / AED (د.إ)`.

Класс: `input`.

#### AmountWithConversion (`components/Currency/AmountWithConversion.tsx`)

Переиспользуемый составной инпут для форм с суммами (Deal, Contract, Subscription):

```
┌────────────────────────────────────────┐
│  Сумма *                               │
│  [1 000 000              ] [KZT ▾]     │
│  ≈ 26 000 000 сум (по курсу на сегодня)│
└────────────────────────────────────────┘
```

- `input[type=number]` для суммы + `CurrencySelect` рядом (flex row)
- Под полем: `text-xs text-gray-400` с конвертацией в base currency (UZS из профиля пользователя)
- Конвертация через SWR `GET /api/currency-rates?from={currency}&to={user.salary_currency}&date=today` — debounced 300ms
- Если курс не найден → `text-gray-300 «нет курса»`

**Props**:
```tsx
interface AmountWithConversionProps {
  value: number | "";
  currency: string;
  onValueChange: (v: number | "") => void;
  onCurrencyChange: (v: string) => void;
  label?: string;
  required?: boolean;
  error?: string;
}
```

---

## Раздел 3. Admin планы зарплат

### `/admin/salary-plans`

**Путь:** `apps/web/src/app/(app)/admin/salary-plans/page.tsx`

**Роли**: `admin`, `director`.

#### Wireframe

```
┌──────────────────────────────────────────────────────────────────┐
│ [PageHeader: Планы зарплат]               [+ Создать план]       │
│                                                                   │
│ [Менеджер: Все ▾]  [Месяц: Апрель 2026 ▾]  [Статус: Все ▾]      │
│                                                                   │
│  ┌─────────────────────────────────────────────────────────────┐ │
│  │  SalaryPlansList                                            │ │
│  │  Менеджер   │ Период    │ Оклад     │ Статус   │ Действия  │ │
│  │  Илья Рогов │ Апр 2026  │ 17 000 000│[Черновик]│ [✎] [✓]  │ │
│  │  Анна Ли    │ Апр 2026  │ 15 000 000│[Финализ] │ [✎]      │ │
│  └─────────────────────────────────────────────────────────────┘ │
└──────────────────────────────────────────────────────────────────┘
```

**Фильтры** (flex row gap-2):
- `UserSelect` (`@/components/UserSelect`) — «Менеджер»
- Period select — «Апрель 2026» (последние 12 месяцев)
- Status select — «Все / Черновик / Финализирован / Выплачен»

**SalaryPlansList** (`components/SalaryPlans/SalaryPlansList.tsx`):

Таблица в `card`. Данные из `GET /api/admin/salary-plans?user_id=&year=&month=&status=`.

| Колонка | |
|---|---|
| Менеджер | ссылка `→ /admin/salary-plans/{userId}/{year}/{month}` |
| Период | «Апрель 2026» |
| Оклад | `base_salary_amount` + `base_salary_currency` |
| Правило комиссии | `commission_rule.name` или «—» |
| Статус | badge: `draft bg-info/10 text-info`, `finalized bg-success/10 text-success`, `paid bg-primary/10 text-primary` |
| Действия | `bi-pencil` → `/admin/salary-plans/{userId}/{year}/{month}` ; `bi-calculator` → `POST .../compute` (пересчитать МК) |

Кнопка «Создать план» → Modal быстрого создания ИЛИ переход на страницу `/admin/salary-plans/new` (предпочтительно страница для большой формы).

### `/admin/salary-plans/[userId]/[year]/[month]`

**Путь:** `apps/web/src/app/(app)/admin/salary-plans/[userId]/[year]/[month]/page.tsx`

#### Wireframe формы

```
┌──────────────────────────────────────────────────────────────────┐
│ [PageHeader: План: Илья Рогов / Апрель 2026]   [Пересчитать МК] │
│                                                                   │
│  ┌──────────────────────────────────────────────────────────────┐│
│  │  Базовый оклад                                              ││
│  │  Сумма *   [17 000 000]  Валюта  [UZS ▾]                   ││
│  │  Примечание [Выплачивается в следующем месяце                ││
│  └──────────────────────────────────────────────────────────────┘│
│                                                                   │
│  ┌──────────────────────────────────────────────────────────────┐│
│  │  Правило комиссии                                            ││
│  │  Правило *  [10% от новых поступлений ▾] [+ Создать новое]  ││
│  │  (карточка правила: описание условий)                        ││
│  └──────────────────────────────────────────────────────────────┘│
│                                                                   │
│  ┌──────────────────────────────────────────────────────────────┐│
│  │  Личный план                                                 ││
│  │  План по новым поступлениям  [600 000]  [RUB ▾]            ││
│  │  FTM план (кол-во встреч)    [5       ]                     ││
│  └──────────────────────────────────────────────────────────────┘│
│                                                                   │
│  ┌──────────────────────────────────────────────────────────────┐│
│  │  Командный план                                              ││
│  │  Цель команды  [Казахстан ОП, Апр 2026 ▾] [+ Создать цель] ││
│  │  (карточка цели: пул, порог, разбивка)                       ││
│  └──────────────────────────────────────────────────────────────┘│
│                                                                   │
│  ┌──────────────────────────────────────────────────────────────┐│
│  │  Служебная информация                                        ││
│  │  Руководитель  [Богдан Ядыкин ▾]                            ││
│  │  Статус        [Черновик ▾]                                  ││
│  └──────────────────────────────────────────────────────────────┘│
│                                                                   │
│                        [btn-ghost Отмена]  [btn-primary Сохранить]│
└──────────────────────────────────────────────────────────────────┘
```

**SalaryPlanForm** (`components/SalaryPlans/SalaryPlanForm.tsx`):

- Секция «Базовый оклад»: `input[type=number]` + `CurrencySelect` + `textarea` для примечания
- Секция «Правило комиссии»: `select` с опциями из `GET /api/admin/commission-rules?active=true` + кнопка `+ Создать` которая открывает `Modal` с `CommissionRuleForm`
- После выбора правила — info-блок `bg-blue-50 dark:bg-blue-900/20 rounded p-3`:
  ```
  10% от новых поступлений
  Только личные сделки · Только первый платёж · Сразу при поступлении
  ```
- Секция «Личный план»: `AmountWithConversion` для личного плана + `input[type=number]` для FTM плана
- Секция «Командный план»: `select` с опциями из `GET /api/admin/team-targets?active=true` + кнопка `+ Создать` → `Modal` с `TeamTargetForm`
- После выбора цели — info-блок: пул / порог / разбивка
- Секция «Служебная»: `UserSelect` для руководителя + `select` статуса

**Кнопка «Пересчитать МК»** (в PageHeader actions):
- `btn-secondary bi-calculator mr-2`
- Click → `POST /api/admin/motivational-cards/{userId}/{year}/{month}/compute`
- Loading: disabled + `«Рассчитываем…»`
- Success: `text-success «МК пересчитана»` + ссылка «Посмотреть МК»

### CommissionRuleForm (`components/SalaryPlans/CommissionRuleForm.tsx`)

Используется и как страница `/admin/commission-rules`, и как Modal (пропустить через prop `inModal`).

**Поля формы**:
- Название правила (`input`, required, placeholder «10% от новых поступлений»)
- Ставка, % (`input[type=number]`, `0.01–100`, required)
- База расчёта (`select`): «Новые поступления» (new_income_payments) / «Любые поступления»
- Scope (`select`): «Только личные сделки» / «Все сделки»
- Чекбоксы:
  - «Только первый платёж от клиента»
  - «Требуется подписанный договор»
  - «Сумма должна совпадать с планом платежа»
- Момент выплаты (`select`): «Сразу» / «В конце месяца» / «В конце квартала»
- Примечание выплаты (`textarea`, placeholder)

**Страница `/admin/commission-rules`**: `SimpleEntityCrud`-like список + кнопка `+ Создать правило` → Modal с формой.

### TeamTargetForm (`components/SalaryPlans/TeamTargetForm.tsx`)

Форма командной цели (в Modal или самостоятельно).

**Поля формы**:
- Период (год + месяц) — 2 `select`
- Отдел (`select` из `GET /api/admin/departments` — если Эпик 14 уже есть)
- Воронка (`select` из `GET /api/pipelines`)
- Метрика: «Новые поступления» / «FTM встречи»
- Плановая сумма (`AmountWithConversion`)
- Пул командного бонуса (`AmountWithConversion`, default 500 000 KZT)
- Бонус за дополнительного менеджера (`input[type=number]`, default 100 000)
- Мин. порог выполнения (%) (`input[type=number]`, default 80)
- Пропорция: 60% / 40% (2 `input[type=number]`, сумма должна = 100 — inline валидация)

**Страница `/admin/team-targets`**: аналогично commission-rules, список + Modal форма.

---

## Раздел 4. AI Assistant

### AiAssistantButton (`components/AI/AiAssistantButton.tsx`)

**Floating button** — фиксированный, всегда видимый на всех страницах `(app)`:

```
position: fixed, bottom: 24px, right: 24px, z-index: 50
```

- Кнопка `rounded-full w-14 h-14 bg-primary text-white shadow-lg hover:bg-primary-light transition-colors`
- Иконка `bi-stars text-xl` (или `bi-robot` — TBD по результату выбора)
- Tooltip `title="AI-ассистент"` при hover
- Click → открывает `AiAssistantDrawer`
- Когда drawer открыт — кнопка показывает `bi-x-lg` (закрыть)
- **Монтируется** в `(app)/layout.tsx` как клиентский компонент ниже основного контента

### AiAssistantDrawer (`components/AI/AiAssistantDrawer.tsx`)

**Drawer** — sliding panel справа:

```
position: fixed, right: 0, top: 0, bottom: 0
width: 384px (w-96)
z-index: 40
```

- Slide-in анимация: `translate-x-full` → `translate-x-0`, `transition-transform duration-300`
- Overlay позади: `fixed inset-0 bg-black/20 z-30` (click на overlay → закрыть)
- Фон: `bg-white dark:bg-gray-900 border-l border-gray-200 dark:border-gray-700 shadow-xl`

#### Wireframe Drawer

```
┌──────────────────────────────┐
│  AI-ассистент          [bi-x]│  ← header sticky, border-b
│  Новая сессия  [bi-plus-lg]  │
├──────────────────────────────┤
│                              │
│  [AiChatMessage user]        │
│                [AiChatMsg AI]│
│  [AiChatMessage user]        │
│                [AiChatMsg AI]│
│  [анимация ...]  ← streaming │
│                              │
├──────────────────────────────┤
│  [AiQuickChips]              │
│  Горячие сделки  По плану    │
│                              │
│  ┌──────────────────────────┐│
│  │ Введи вопрос...          ││  ← textarea, auto-resize
│  └──────────────────────────┘│
│                    [Отправить]│  ← btn-primary bi-send
└──────────────────────────────┘
```

**Логика сессии**:
1. При первом открытии: `POST /api/me/chat/sessions` → получаем `session_id`
2. `GET /api/me/chat/sessions/{id}/messages` → список истории
3. Кнопка «Новая сессия»: очищает историю, создаёт новую через POST
4. Сессия сохраняется в `sessionStorage` (`crm-ai-session-id`) — чтобы не теряться при навигации между страницами

**Контекст страницы**: AiAssistantButton передаёт `current_page` через кастомный event или ref. При отправке сообщения: `POST /api/me/chat/sessions/{id}/messages` с body `{content, context: {page: pathname}}`.

#### AiChatMessage (`components/AI/AiChatMessage.tsx`)

```
role=user:
  flex justify-end
  div.max-w-[80%].bg-primary.text-white.rounded-2xl.rounded-br-none.px-4.py-2.text-sm

role=assistant:
  flex justify-start
  div.max-w-[80%].bg-gray-100.dark:bg-gray-800.text-gray-900.dark:text-white.rounded-2xl.rounded-bl-none.px-4.py-2.text-sm
```

- Дата/время под сообщением: `text-xs text-gray-400 mt-1`
- Если `tool_calls` в сообщении AI: под текстом блок «Действие выполнено»:
  ```
  [bi-check-circle text-success]  Задача создана: "Отправить КП до пятницы"  [Открыть →]
  ```

**Streaming**: пока ответ AI стримится — отображается пустое AI-сообщение с анимацией:
```
div.flex.gap-1
  span.w-2.h-2.bg-gray-400.rounded-full.animate-bounce (style: animation-delay 0ms)
  span.w-2.h-2.bg-gray-400.rounded-full.animate-bounce (style: animation-delay 150ms)
  span.w-2.h-2.bg-gray-400.rounded-full.animate-bounce (style: animation-delay 300ms)
```

SSE: `EventSource` → `onmessage` → append текста к последнему AI-сообщению в state.

#### AiQuickChips (`components/AI/AiQuickChips.tsx`)

- Flex row с `flex-wrap gap-2 px-4 py-2 border-t border-gray-100 dark:border-gray-800`
- Chips: `button.rounded-full.border.border-gray-200.dark:border-gray-700.text-xs.px-3.py-1.hover:bg-gray-50.dark:hover:bg-gray-800.transition-colors`
- Фиксированный список:
  - «Горячие сделки»
  - «Что у меня по плану?»
  - «Подопечные в риске»
  - «Создай задачу»
- Click на chip → вставляет текст в textarea + автоматически отправляет

**States**:
- Loading сессии → `text-gray-400 text-sm text-center py-8 «Загружаем…»`
- Ошибка → `text-danger text-sm text-center «Ошибка соединения. Обнови страницу.»`
- Пустая история → `bi-stars text-4xl text-gray-200` + «Привет! Чем помочь?»

---

## Раздел 5. Cold Call Trainer

### Страница `/me/training`

**Путь:** `apps/web/src/app/(app)/me/training/page.tsx`

**PageHeader**: «Тренажёр холодных звонков» + кнопка «История тренировок» `btn-ghost bi-clock-history mr-2` (открывает `TrainingHistory` в Modal/drawer).

#### Wireframe

```
┌──────────────────────────────────────────────────────────────────┐
│ [PageHeader: Тренажёр холодных звонков]  [История тренировок]    │
│                                                                   │
│  [Если нет активной сессии: ScenarioSetup]                       │
│  ┌──────────────────────────────────────────────────────────────┐│
│  │  Настрой сценарий                                            ││
│  │                                                              ││
│  │  Сценарий *                                                  ││
│  │  ┌─────────────────┐  ┌────────────────┐                    ││
│  │  │ [bi-telephone]  │  │ [bi-arrow-up]  │                    ││
│  │  │ Холодный звонок │  │ Возражение     │                    ││
│  │  └─────────────────┘  └────────────────┘                    ││
│  │  ┌─────────────────┐  ┌────────────────┐                    ││
│  │  │ [bi-person-x]   │  │ [bi-arrow-repeat]│                  ││
│  │  │ Отказ ЛПР      │  │ Повторный звонок│                   ││
│  │  └─────────────────┘  └────────────────┘                    ││
│  │                                                              ││
│  │  Тип компании *  [IT компания ▾]                             ││
│  │  Название компании (опц.)  [ACME Corp              ]        ││
│  │                                                              ││
│  │               [btn-primary bi-play-fill  Начать звонок]     ││
│  └──────────────────────────────────────────────────────────────┘│
│                                                                   │
│  [Если есть активная сессия: TrainingChat]                       │
└──────────────────────────────────────────────────────────────────┘
```

#### ScenarioSetup (`components/Me/training/ScenarioSetup.tsx`)

**4 карточки сценариев** (grid-cols-2 gap-3):

Каждая карточка — `button.card.text-left.p-4.cursor-pointer.border-2.transition-colors`:
- Неактивная: `border-gray-200 dark:border-gray-700 hover:border-primary/50`
- Активная: `border-primary bg-primary/5`
- Внутри: иконка `bi-* text-2xl text-primary mb-2` + заголовок `text-sm font-medium` + описание `text-xs text-gray-500`

Сценарии:
1. `cold_call` — `bi-telephone` — «Холодный звонок» — «Клиент тебя не ждёт, нужно установить контакт»
2. `objection_handling` — `bi-shield-exclamation` — «Возражение по цене» — «Клиент говорит "дорого" — убеди его»
3. `ceo_rejection` — `bi-person-x` — «Отказ ЛПР» — «Директор сказал нет — попробуй переломить ситуацию»
4. `follow_up` — `bi-arrow-repeat` — «Повторный звонок» — «Ты уже звонил — напомни о себе»

**Тип компании**: `select input-класс` из списка `["IT", "Производство", "Ритейл", "Строительство", "Образование", "Финансы", "Другое"]`.

**Название компании**: `input`, placeholder «Например: ACME Corp», опциональное.

**Кнопка «Начать звонок»**: `btn-primary bi-play-fill` — disabled пока не выбран сценарий и тип компании. Click → `POST /api/me/training/sessions` → получаем `session_id` → показываем `TrainingChat`.

#### TrainingChat (`components/Me/training/TrainingChat.tsx`)

Аналогичен `AiAssistantDrawer`, но полноэкранный в рамках страницы:

```
┌──────────────────────────────────────────────────────────────────┐
│ Холодный звонок · IT компания «ACME Corp»     [Завершить звонок] │
├──────────────────────────────────────────────────────────────────┤
│                                                                   │
│  [Sidebar подсказок, 280px]   │  [Чат]                          │
│                               │                                   │
│  Советы                       │  [AI: Алло, кто это?] (клиент)  │
│  ┌──────────────────────┐     │  [User: Добрый день, я Илья...] │
│  │ bi-lightbulb         │     │  [AI: Нам уже хватает CRM...]   │
│  │ Когда слышишь «нет» — │    │  [...]                          │
│  │ уточни причину, а не  │    │                                  │
│  │ убеждай сразу         │    │  ┌──────────────────────────┐   │
│  └──────────────────────┘     │  │ Твоя реплика...          │   │
│  ┌──────────────────────┐     │  └──────────────────────────┘   │
│  │ Возражение: «Дорого» │     │              [Отправить →]      │
│  │ → Сравни с потерями  │     │                                  │
│  └──────────────────────┘     │                                  │
└──────────────────────────────────────────────────────────────────┘
```

**Sidebar подсказок** (hidden на мобиле):
- `w-72 flex-shrink-0 border-r border-gray-200 dark:border-gray-700 p-4`
- Заголовок «Советы» `text-sm font-medium mb-3`
- 2-3 карточки `bg-blue-50 dark:bg-blue-900/20 rounded p-3 mb-2 text-xs` с советами по сценарию (статические, захардкожены в компоненте по `scenario_type`)

**Чат**: аналогично `AiAssistantDrawer`:
- AI — «клиент», user — менеджер
- AI сообщения слева, user — справа
- Streaming тот же паттерн (SSE или polling)
- `POST /api/me/training/sessions/{id}/message`

**Кнопка «Завершить звонок»**: `btn-secondary bi-stop-circle mr-2 text-danger` — в заголовке чата. Click → `POST /api/me/training/sessions/{id}/finish` → показывает `TrainingScorecard`.

#### TrainingScorecard (`components/Me/training/TrainingScorecard.tsx`)

Появляется после завершения (заменяет чат или Modal):

```
┌──────────────────────────────────────────────────────────────────┐
│  Результаты звонка                                               │
│                                                                   │
│              [bi-star-fill text-warning x4]  8.2 / 10           │
│                                                                   │
│  ┌──────────────────────┐  ┌──────────────────────┐              │
│  │ Грамотность     9/10 │  │ Эмпатия         7/10 │             │
│  │ ████████████████████ │  │ ████████████████      │             │
│  └──────────────────────┘  └──────────────────────┘              │
│  ┌──────────────────────┐  ┌──────────────────────┐              │
│  │ Обработка возр. 8/10 │  │ Закрытие сделки 8/10 │             │
│  │ █████████████████    │  │ █████████████████     │             │
│  └──────────────────────┘  └──────────────────────┘              │
│                                                                   │
│  Обратная связь от AI:                                           │
│  «Хорошее начало! Ты быстро установил контакт. Но при возражении │
│  о цене стоит было задать уточняющий вопрос...»                  │
│                                                                   │
│  [btn-ghost Посмотреть чат]       [btn-primary Новая тренировка] │
└──────────────────────────────────────────────────────────────────┘
```

- Общий балл: `text-4xl font-bold text-primary` + `/ 10` `text-gray-400`
- Звёзды: `Math.round(score/10*5)` звёзд из 5 (`bi-star-fill text-warning`)
- 4 sub-score карточки (grid-cols-2): значение + mini progress bar
- Фидбек AI — текст из `score_result.feedback`
- «Новая тренировка» → сброс state → `ScenarioSetup`
- «Посмотреть чат» → scroll к чату выше или Modal

#### TrainingHistory (`components/Me/training/TrainingHistory.tsx`)

- Modal или Drawer (предпочтительно Modal через `@/components/Modal`)
- Список прошлых сессий из `GET /api/me/training/sessions`
- Каждая строка: дата / сценарий / тип компании / итоговый балл / badge балла
- Клик → открывает детали (scorecard + transcript чата)

---

## Раздел 6. AI карточки компаний

### AiCompanyModal (`components/AI/AiCompanyModal.tsx`)

Вызывается из карточки контрагента `apps/web/src/app/(app)/counterparties/[id]/page.tsx` — кнопка в RightRail или в заголовке.

**Кнопка триггер** (добавить в карточку контрагента):
```
btn-secondary: [bi-stars mr-2] AI: разбор клиента
```

**Modal** (использует `@/components/Modal`, `size="lg"`):

```
┌──────────────────────────────────────────────────────────────────┐
│  AI-разбор: ACME Corp                                    [bi-x]  │
├──────────────────────────────────────────────────────────────────┤
│                                                                   │
│  [Loading state]                                                 │
│  ┌──────────────────────────────────────────────────────────────┐│
│  │  bi-stars animate-spin text-primary text-3xl                ││
│  │  «AI анализирует клиента...»                                 ││
│  │  «Обычно занимает 5-10 секунд»                              ││
│  └──────────────────────────────────────────────────────────────┘│
│                                                                   │
│  [Loaded state]                                                  │
│  ┌──────────────────────────────────────────────────────────────┐│
│  │  ICP Fit                           [badge: Высокий]          ││
│  │  IT компания 50+ сотрудников, рынок KZ, ищет автоматизацию  ││
│  └──────────────────────────────────────────────────────────────┘│
│  ┌──────────────────────────────────────────────────────────────┐│
│  │  [bi-exclamation-triangle text-warning]  Риски (2)          ││
│  │  · Конкурент AmoCRM уже используется                        ││
│  │  · Бюджет не подтверждён                                    ││
│  └──────────────────────────────────────────────────────────────┘│
│  ┌──────────────────────────────────────────────────────────────┐│
│  │  [bi-arrow-right text-success]  Рекомендации (2)            ││
│  │  · Провести демо модуля автоматизации                       ││
│  │  · Отправить кейс аналогичной компании                      ││
│  └──────────────────────────────────────────────────────────────┘│
│  ┌──────────────────────────────────────────────────────────────┐│
│  │  Статус отношений    [badge: warm «Тёплый»]                 ││
│  │  Приоритет клиента   ████████░░  8 / 10                     ││
│  └──────────────────────────────────────────────────────────────┘│
│                                                                   │
│  [bi-clock text-gray-300]  Сгенерировано: 01.06.2026 в 10:00   │
│                        [btn-secondary bi-arrow-repeat Обновить] │
│                                                                   │
│                                           [btn-ghost Закрыть]   │
└──────────────────────────────────────────────────────────────────┘
```

**Логика кэша**:
- При открытии Modal: `GET /api/counterparties/{id}/ai-summary` — проверить `generated_at`
- Если `generated_at` существует и `now - generated_at < 24h` → показать кэш + метку времени + кнопка «Обновить»
- Если нет или устарело → автоматически `POST /api/counterparties/{id}/ai-summary` → loading state

**ICP Fit badge**:
- `icp_fit` → badge: «Высокий» `bg-success/10 text-success`, «Средний» `bg-warning/10 text-warning`, «Низкий» `bg-danger/10 text-danger`

**Relationship health badge**:
- `cold` → `bg-info/10 text-info «Холодный»`
- `warm` → `bg-warning/10 text-warning «Тёплый»`
- `hot` → `bg-danger/10 text-danger «Горячий»`
- `at_risk` → `bg-danger/10 text-danger bi-exclamation-triangle mr-1 «Под угрозой»`

**Priority score**: `input[type=range]`-like бар (readonly), `bg-gray-100 rounded-full h-2`, внутри `bg-primary` на `score/10*100%`. Рядом `text-sm font-semibold "{score} / 10"`.

**Кнопка «Обновить»**: `btn-secondary bi-arrow-repeat` → принудительно `POST /api/counterparties/{id}/ai-summary?force=true` → loading → новые данные.

**States**:
- Loading: spinner + текст
- Error (`POST` вернул 500): `bi-exclamation-circle text-danger mr-2` + «Не удалось проанализировать. Попробуй ещё раз.» + кнопка «Повторить»

---

## Mobile адаптация

### Принципы

- Breakpoints: `md:` (768px) — скрытие sidebar, `lg:` (1024px) — sidebar fixed снова
- На `< md`: sidebar collapsible через hamburger menu
- Touch-friendly: `min-h-12` для интерактивных элементов, `p-4` вместо `p-2` для spacing
- Desktop-first: mobile layout — вторичный, но ключевые страницы (указаны ниже) адаптированы

### Hamburger Menu

Добавить в `Header.tsx` (или `Sidebar.tsx`) кнопку `bi-list` справа от Logo на `md:hidden`:
- Click → toggle `isMobileMenuOpen` state (поднять в layout или Context)
- Sidebar при `isMobileMenuOpen=true`: `fixed inset-0 z-40`, Overlay `bg-black/50` позади, sidebar `w-64 bg-white dark:bg-gray-900 shadow-xl`
- Sidebar при `isMobileMenuOpen=false`: `hidden md:flex`
- При навигации → автоматически `setIsMobileMenuOpen(false)`

Реализация: новый Context `MobileMenuContext` или через `useSearchParams` / localStorage. Предпочтительно — React Context (легковесно).

### `/me` — mobile-first изначально

- Header block: Avatar уменьшить до 56px, ФИО + должность вертикально
- StatsBar: `grid-cols-2` вместо `grid-cols-4`, gap уменьшить
- Tabs: горизонтальный scroll `overflow-x-auto whitespace-nowrap` если не влезают
- SummaryTab: `grid-cols-1` (колонки стекаются)

### `/deals` — list view на мобиле

- Переключатель `bi-kanban / bi-list` в правой части PageHeader
- Mobile (<768px): автоматически list view (сохранять выбор в localStorage `crm-deals-view`)
- Kanban остаётся на desktop

**List view** (карточки сделок):
```
┌────────────────────────────────────────┐
│  [Stage badge]         [Owner avatar]  │
│  Название сделки                       │
│  Контрагент · Сумма                    │
│  [Heat score badge]  [bi-arrow-right]  │
└────────────────────────────────────────┘
```

### `/registry` — card stack на мобиле

- На `< md`: таблица → `grid grid-cols-1 gap-3`
- Каждая строка реестра → карточка-card:
  ```
  [Company name]     [HealthBadge]
  [Stage]            [bi-arrow-right]
  Менеджер · Дата
  ```

### `/counterparties` — упрощённая таблица

- Скрыть второстепенные колонки на `< md` через `hidden md:table-cell`
- Оставить видимыми: Название / Статус / Действия

---

## Sidebar — добавления

Добавить в `Sidebar.tsx` в массив `SALES_ITEMS` первым после Dashboard:

```tsx
{ href: "/me", icon: "bi-person-workspace", label: "Кабинет" },
```

Добавить в `ADMIN_ITEMS`:

```tsx
{ href: "/admin/currency-rates", icon: "bi-currency-exchange", label: "Курсы валют", roles: ["admin", "director"] },
{ href: "/admin/salary-plans", icon: "bi-cash-coin", label: "Планы зарплат", roles: ["admin", "director"] },
{ href: "/admin/commission-rules", icon: "bi-percent", label: "Правила комиссии", roles: ["admin", "director"] },
{ href: "/admin/team-targets", icon: "bi-bullseye", label: "Командные цели", roles: ["admin", "director"] },
```

---

## Связь с backend (эндпоинты)

Все запросы через `fetcher` / `api` из `@/lib/api` с `credentials: "same-origin"`.

| Endpoint | Метод | Используется в |
|---|---|---|
| `/api/me/dashboard?period=` | GET | StatsBar, SummaryTab виджеты |
| `/api/me/profile` | GET | MePageHeader |
| `/api/me/motivational-card?period=YYYY-MM` | GET | MotivationalCardTab |
| `/api/motivational-cards/{id}/pdf` | GET | кнопка «Скачать PDF» |
| `/api/me/metrics?period=` | GET | MetricsTab |
| `/api/me/subordinates?period=` | GET | SubordinatesTab |
| `/api/me/activities?kind=&period=&ftm_only=` | GET | ActivityTab |
| `/api/me/preferences` | PATCH | SettingsTab (валюты) |
| `/api/me/password` | PATCH | SettingsTab (пароль) |
| `/api/me/chat/sessions` | POST | AiAssistantDrawer (новая сессия) |
| `/api/me/chat/sessions/{id}/messages` | POST | AiAssistantDrawer (отправка) |
| `/api/me/chat/sessions/{id}/messages` | GET | AiAssistantDrawer (история) |
| `/api/me/training/sessions` | POST | ScenarioSetup (начать) |
| `/api/me/training/sessions/{id}/message` | POST | TrainingChat |
| `/api/me/training/sessions/{id}/finish` | POST | TrainingChat (завершить) |
| `/api/me/training/sessions` | GET | TrainingHistory |
| `/api/counterparties/{id}/ai-summary` | GET | AiCompanyModal (кэш) |
| `/api/counterparties/{id}/ai-summary` | POST | AiCompanyModal (генерация) |
| `/api/currency-rates` | GET | CurrencyRatesTable, история |
| `/api/currency-rates?from=&to=&date=` | GET | AmountWithConversion |
| `/api/admin/currency-rates` | POST | ManualRateModal |
| `/api/admin/currency-rates/{id}` | PATCH | ManualRateModal (edit) |
| `/api/admin/currency-rates/refresh` | POST | CurrencyRatesTable (обновить) |
| `/api/admin/salary-plans` | GET | SalaryPlansList |
| `/api/admin/salary-plans/{userId}/{year}/{month}` | GET/PUT | SalaryPlanForm |
| `/api/admin/commission-rules` | GET/POST/PATCH/DELETE | CommissionRuleForm |
| `/api/admin/team-targets` | GET/POST/PATCH/DELETE | TeamTargetForm |
| `/api/admin/motivational-cards/{userId}/{year}/{month}/compute` | POST | кнопка «Пересчитать» |

---

## States (глобальные правила)

### Loading
- Таблицы: skeleton 5 строк — `div.animate-pulse.h-10.bg-gray-100.dark:bg-gray-700.rounded.mb-2`
- Карточки-виджеты: skeleton `div.animate-pulse.h-32.bg-gray-100.dark:bg-gray-700.rounded`
- Inline (кнопки): disabled + текст «Загружаем…» / «Сохраняем…» / «Рассчитываем…»
- AI generation: spinning `bi-stars animate-spin` + текст

### Empty
- Каждый виджет / таблица имеет свой empty state (см. описания выше)
- Общий паттерн: `bi-{domain-icon} text-4xl text-gray-300 mb-3` + заголовок `text-gray-500 font-medium` + описание `text-gray-400 text-sm`

### Error
- Inline под таблицей или в заголовке секции: `text-sm text-danger`
- Нет toast-системы — только inline
- При ошибке SSE: сообщение в чате «Соединение прервано. Обнови страницу.»

---

## Interactions

| Элемент | Действие | Результат |
|---|---|---|
| Period select (StatsBar) | change | `mutate` SWR-ключа с новым периодом |
| Строка подопечного | click | переход `/me?user_id={id}` |
| `bi-calculator` (план зарплат) | click | `POST .../compute` → success toast inline |
| Строка комиссии в MkTable | click | accordion разворачивает `MkBreakdown` |
| `bi-info-circle` в MkTable | hover/click | tooltip с формулой |
| «Скачать PDF» | click | `GET .../pdf` → browser download |
| Floating AI button | click | open `AiAssistantDrawer` (slide right) |
| Overlay за drawer | click | close drawer |
| Quick chip | click | вставить текст в input → auto-submit |
| Сценарий в ScenarioSetup | click | select scenario (visual highlight) |
| «Завершить звонок» | click | `POST .../finish` → показать `TrainingScorecard` |
| «AI: разбор клиента» | click | open `AiCompanyModal` → auto-fetch/POST |
| «Обновить курсы из API» | click | `POST .../refresh` → inline success/error |
| «[✎]» в курсах | click | open `ManualRateModal` (edit mode) |
| Чекбокс «Это первая встреча» | check | reveal `FtmFields` block (animate) |
| «Завершить» задачу (circle button) | click | `PATCH /api/activities/{id}` is_done=true → optimistic update |
| Hamburger menu | click (mobile) | show sidebar overlay |

---

## Тексты (RU, без i18n)

### Страница `/me`
- Заголовок — `Кабинет` (в sidebar) / `Мой кабинет` (в PageHeader если нет юзер-контекста)
- Tabs: `Сводка / МК / Метрики / Подопечные / Активность / Настройки`
- Period options: `Текущий месяц / Прошлый месяц / Текущий квартал / Текущий год`
- StatsBar labels: `Личные продажи / Цель команды / FTM встречи / Score`
- StatsBar sub: `план {X} / цель {X}`

### Виджеты
- Активные сделки заголовок: `Горячие сделки`
- Ссылка «все»: `Все сделки →`
- Задачи заголовок: `Задачи на сегодня`
- Empty задачи: `Задач нет — отличный день!`
- Прогресс заголовок: `Прогресс {месяц} {год}`
- Уведомления заголовок: `Уведомления`
- Empty уведомления: `Новых уведомлений нет`
- Empty сделки: `Нет активных сделок`

### МК
- Tab МК empty: `МК за этот период ещё не рассчитана`
- МК empty sub: `Обратись к руководителю для настройки плана`
- Статус бейджи: `Черновик / Финализировано / Выплачено`
- Кнопка PDF: `Скачать PDF`
- Строка «Оклад»: `Оклад (базовый)`
- Строка «Комиссия»: `Комиссия`
- Строка «KPI»: `KPI (командный бонус)`
- Строка «Итого»: `Итого`
- Tooltip оклад: `Выплачивается в следующем месяце за текущим`
- Tooltip комиссия: `10% от новых поступлений зачисленных на расчётный счёт. Только личные сделки. Только первый платёж. Выплачивается сразу при поступлении.`
- Курсы footer: `Курсы на {дата}`
- Разбивка заголовок: `Детализация комиссии по сделкам`
- Разбивка пусто: `Нет зачтённых платежей в этом периоде`
- Breakdown колонки: `Клиент / Договор / Платёж / Комиссия`

### Метрики
- Заголовок личные: `Личные показатели`
- Заголовок команда: `Сравнение с командой`
- Ранг: `Твой ранг в команде: #N из M менеджеров`
- Ранг №1: `🏆 #1 из M — лидер команды!` (здесь эмодзи уместно как в PDF)
- Средний цикл: `Средний цикл сделки: N дней`
- Конверсия заголовок: `Конверсия по этапам`

### Подопечные
- Заголовок: `Подопечные ({N} менеджеров)`
- Колонки: `ФИО / Отдел / План / Факт / Выполнение / FTM / Статус`
- Статус badges: `На треке / Риск / Тревога`
- Empty: `Нет подопечных` (таб не показывается)

### Активность
- Заголовок: `Активность`
- Фильтр kind: `Все / Звонки / Встречи / Задачи / Заметки`
- Фильтр период: `Сегодня / Эта неделя / Этот месяц`
- Чекбокс FTM: `Только FTM`
- FTM badge зачтена: `FTM зачтена`
- FTM badge не зачтена: `FTM не зачтена`

### Настройки
- Секция пароль: `Безопасность`
- Секция тема: `Интерфейс`
- Секция валюты: `Валюты для конвертации`
- Label основная валюта: `Основная валюта зарплаты`
- Label показывать: `Показывать конвертацию в`
- Кнопка сохранить: `Сохранить настройки`

### Курсы валют
- PageHeader: `Курсы валют`
- Кнопка обновить: `Обновить из API`
- Кнопка добавить: `Добавить курс`
- Актуальные курсы: `Актуальные курсы`
- История: `История курсов`
- Источник api: `API`
- Источник manual: `Вручную`
- Modal title создание: `Добавить курс вручную`
- Modal title edit: `Редактировать курс`
- Labels Modal: `Из валюты / В валюту / Курс / Дата`
- Success refresh: `Курсы обновлены`
- Error refresh: `Не удалось обновить курсы. Попробуй ещё раз.`

### Планы зарплат
- PageHeader список: `Планы зарплат`
- PageHeader форма: `План: {ФИО} / {месяц год}`
- Кнопка создать: `Создать план`
- Кнопка пересчитать: `Пересчитать МК`
- Секции формы: `Базовый оклад / Правило комиссии / Личный план / Командный план / Служебная информация`
- Статус план: `Черновик / Финализирован / Выплачен`
- Кнопка правило: `+ Создать правило`
- Кнопка цель: `+ Создать цель`
- Modal правило title: `Создать правило комиссии`
- Modal цель title: `Создать командную цель`

### AI Assistant
- Button tooltip: `AI-ассистент`
- Drawer title: `AI-ассистент`
- Кнопка новая сессия: `Новый чат`
- Placeholder input: `Спроси о сделках, задачах, плане...`
- Кнопка отправить: `Отправить`
- Quick chips: `Горячие сделки / Что по плану? / Подопечные в риске / Создай задачу`
- Streaming: (анимированные точки, без текста)
- Empty history: `Привет! Я знаю твои сделки, план и задачи. Спроси что-нибудь.`
- Error: `Ошибка соединения. Обнови страницу.`
- Tool call success: `Задача создана`
- Tool call link: `Открыть задачу →`

### Тренажёр
- PageHeader: `Тренажёр холодных звонков`
- Кнопка история: `История тренировок`
- Секция setup: `Настрой сценарий`
- Сценарии labels: `Холодный звонок / Возражение по цене / Отказ ЛПР / Повторный звонок`
- Сценарии descriptions: (см. ScenarioSetup выше)
- Label тип компании: `Тип компании *`
- Label название: `Название компании (необязательно)`
- Кнопка начать: `Начать звонок`
- Кнопка завершить: `Завершить звонок`
- Scorecard title: `Результаты звонка`
- Scorecard sub-labels: `Грамотность / Эмпатия / Обработка возражений / Закрытие сделки`
- Scorecard feedback label: `Обратная связь от AI`
- Кнопка повторить: `Новая тренировка`
- Кнопка посмотреть чат: `Посмотреть чат`

### AI карточки компаний
- Кнопка trigger: `AI: разбор клиента`
- Modal title: `AI-разбор: {company_name}`
- Loading: `AI анализирует клиента...`
- Loading sub: `Обычно занимает 5–10 секунд`
- Секции: `ICP Fit / Риски / Рекомендации / Статус отношений`
- ICP badges: `Высокий / Средний / Низкий`
- Health badges: `Холодный / Тёплый / Горячий / Под угрозой`
- Приоритет: `Приоритет клиента: N / 10`
- Метка времени: `Сгенерировано {дата} в {время}`
- Кнопка обновить: `Обновить анализ`
- Error: `Не удалось проанализировать. Попробуй ещё раз.`
- Кнопка повтор: `Повторить`

### FTM в форме Activity
- Чекбокс: `Это первая встреча с этим клиентом`
- Блок заголовок: `Детали FTM`
- ЛПР: `Присутствовал ЛПР (лицо, принимающее решение)`
- Презентация: `Показана презентация системы`
- URL: `Ссылка на отчёт о встрече`
- Telegram: `Объявлено в Telegram (MACRO Global Sales)`

---

## Координация с другими эпиками

| Эпик | Зависимость | Что нужно |
|---|---|---|
| Эпик 14 (Departments) | Мягкая — `TeamTarget.team_id` | Если отделы есть, показывать в форме TeamTargetForm; если нет — поле hidden |
| Эпик 21 (Notifications) | Используем данные | `NotificationsWidget` в SummaryTab использует `GET /api/notifications` |
| Эпик 16 (Security/Profile) | Переиспользуем | `SettingsTab.ChangePassword` дублирует `/profile` — переиспользовать компонент |
| Эпик 18 (AI Features) | Паттерн Claude API | AiCompanyModal в этом эпике + Contract Analysis в 18 — один подход к streaming |
| Эпик 2 (Activity/Timeline) | Расширяем Activity | `FtmFields` добавляется в существующую форму создания Activity |

---

## Открытые вопросы

1. **AI Floating button vs таб в `/me`**: ТЗ предполагает floating button на ВСЕХ страницах + рисует его в `layout.tsx`. Если это перегружает для первой версии — вопрос продукту: открыть AI drawer только на `/me`? Или глобально? Текущее ТЗ — глобально.

2. **`is_first_payment_from_counterparty` автодетект**: trigger при `POST /api/contracts/{id}/payments` считает предыдущие платежи от этого `counterparty_id`. Если `count == 0` → `true`. Требует backend реализации — флагуем как «требуется правка backend: auto-detect первого платежа».

3. **Attribution платежа** (`attributed_to_user_id`): берётся из `Deal.owner_user_id` связанной сделки. Если `contract.deal_id` есть → чейн; если нет → поле обязательно вводить вручную в форме платежа. Требует backend решения.

4. **FTM уникальность**: 1 FTM за всё время или за период? В условиях написано «first time» — значит 1 FTM за всё время с этим контрагентом. Backend должен валидировать уникальность `(counterparty_id, is_first_time_meeting=true)`. Требует правки backend.

5. **Снапшот плана при пересчёте**: если план изменили задним числом — пересчитывать МК или нет? ТЗ предполагает кнопку «Пересчитать» ручную. Автоматический пересчёт при изменении плана — открытый вопрос для продукта.

6. **SSE или polling для AI chat**: SSE предпочтительнее для streaming. Если SSE сложно в текущем стеке — fallback на polling `GET /messages?after={last_id}` каждые 500ms. Решение за backend-specialist.

7. **Период курса для МК**: курсы берутся «на начало месяца» (1-е число) или «на дату расчёта»? Из PDF — «ориентировочный на 06.04». Нужно уточнение: по умолчанию ТЗ берёт на 1-е число месяца. Требует подтверждения продукта.

8. **Mobile hamburger реализация**: ТЗ предлагает React Context. Альтернатива — Zustand store. Решение за frontend-specialist в рамках текущего стека (нет Zustand — Context).

9. **PDF генерация МК**: `GET /api/motivational-cards/{id}/pdf` — нужен backend docxtpl шаблон. Это отдельная задача backend-specialist — требуется шаблон `mk_template.docx`.

10. **Admin просмотр чужого кабинета**: URL `/me?user_id={id}` — нужен backend эндпоинт `GET /api/me/dashboard?user_id={id}` с проверкой прав (admin/director or supervisor). Требует правки backend.
