# ТЗ: Финансы Ф2 — Заявки + Реестр платежей + Согласование + Сценарии

**Зачем:** Замкнуть финансовый контур — дать менеджерам создавать заявки на выплаты,
бухгалтерам — собирать реестры и проводить их, CFO/директору — видеть очередь на
согласование и управлять правилами. Backend Ф2 готов (commit 6fbfa28).

---

## КРИТИЧЕСКИ ВАЖНО ДЛЯ FRONTEND-SPECIALIST

**ОБЯЗАТЕЛЬНО сверь каждое поле формы с реальными схемами в `apps/api/app/schemas_finance.py`.**
Урок Ф0: предположения о shape response приводят к runtime-ошибкам. Конкретно:

- `RequestCreate` — поля `request_type`, `legal_entity_id`, `amount`, `currency`, `payee_user_id`,
  `counterparty_company_id`, `cashflow_category_id`, `period_year`, `period_month`,
  `desired_date`, `description`. **Нет поля `purpose`** — используй `description`.
- `RequestOut` — `requester_user_id`, `payee_user_id`, `resulting_operation_id`,
  `rejected_reason`, `submitted_at`, `decided_at`, `created_at`.
- `RegistryCreate` — `legal_entity_id`, `source_account_id`, `registry_date`, `title`, `comment`.
- `RegistryDetailOut extends RegistryOut` — добавляет `items: FinOperation[]` и `total_amount`.
- `ApprovalSummaryOut` — `status`, `active_stage`, `total_stages`, `stages[]`, `votes[]`.
  Endpoint согласования для заявки: **`GET /api/finance/requests/{id}/approval`** (не `/approval-summary`).
  Endpoint согласования для реестра: **`GET /api/finance/registries/{id}/approval`**.
- `ApprovalScenarioCreate` — `stages: ScenarioStage[]`, где каждый этап:
  `{order, name, user_ids[], min_required, mode: "any"|"all"}`.
- Все денежные суммы из backend — `Decimal` (строка в JSON, не float). В TS — `string` или `number`.
  `MoneyCell` уже умеет принимать `number | string`.

Типы Ф2 в `@/lib/types.ts` **ещё не добавлены** — добавь сам перед реализацией компонентов.

---

## Итерации реализации

**Итерация 1 (ядро):** Заявки (`/finance/requests`) + Согласования (`/finance/approvals`) + Sidebar-пункты.

**Итерация 2:** Реестр платежей (`/finance/registries` + `[id]`).

**Итерация 3 (админ):** Сценарии согласования (`/finance/settings/approval-scenarios`).

---

## Гейтинг по ролям (capability-based)

Backend использует capability-систему через `fin_permission`. Фронт должен ориентироваться
на роль пользователя (`useMe().user.role`), но всегда обрабатывать 403 gracefully.

| Раздел | Роли-минимум | Backend capability |
|---|---|---|
| Заявки (создать) | manager, accountant, cfo, admin | `create_request` |
| Заявки (видеть все) | accountant, cfo, director, admin | `view_all_operations` |
| Заявки (согласовать) | accountant, cfo, director, admin | `approve` |
| Реестр | accountant, cfo, admin | `manage_registry` |
| Согласования (inbox) | все у кого есть pending голоса | `approve` |
| Сценарии | cfo, admin | `manage_approval_scenarios` |

Менеджер видит **только свои** заявки (backend отфильтрует по `requester_user_id`).
Бухгалтер/CFO/директор/admin — все заявки.

---

## Экран 1: `/finance/requests` — Заявки

**Где в коде:**
- Страница: `apps/web/src/app/(app)/finance/requests/page.tsx`
- Карточка: `apps/web/src/app/(app)/finance/requests/[id]/page.tsx`
- Компоненты: `apps/web/src/components/Finance/RequestStatusBadge.tsx`
- Компоненты: `apps/web/src/components/Finance/RequestCreateModal.tsx`
- Компоненты: `apps/web/src/components/Finance/RequestFulfillModal.tsx`
- Компоненты: `apps/web/src/components/Finance/ApprovalSummaryPanel.tsx`

### Wireframe — листинг

```
┌─────────────────────────────────────────────────────────────────────────┐
│ [Sidebar]  │ [PageHeader: Заявки]                   [+ Создать заявку] │
│            ├─────────────────────────────────────────────────────────────┤
│            │ ┌──── Filters card ────────────────────────────────────┐   │
│            │ │ [Тип ▼] [Статус ▼] [Юрлицо ▼] [Дата с] [Дата по]   │   │
│            │ └──────────────────────────────────────────────────────┘   │
│            │                                                             │
│            │ ┌──── Table card ───────────────────────────────────────┐  │
│            │ │ №  │ Тип   │ Сумма      │ Статус │ Дата  │ Заявитель │  │
│            │ ├────┼───────┼────────────┼────────┼───────┼───────────┤  │
│            │ │ .. │ Зарп. │ 150 000 KZT│[badge] │06.01  │ Иванов    │  │
│            │ │ .. │ Расход│  25 000 RUB│[badge] │05.01  │ Петрова   │  │
│            │ └───────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────────────────┘
```

### Листинг: компоненты и props

**Фильтры** (`card p-4 mb-4`):
- `<select className="input text-sm w-auto">` — Тип заявки: `salary` / `commission` / `expense_reimbursement` / `payment`
- `<select className="input text-sm w-auto">` — Статус: `draft` / `submitted` / `approved` / `rejected` / `paid` / `cancelled`
- `<select className="input text-sm w-auto">` — Юрлицо (SWR `/api/finance/legal-entities`)
- `<input type="date" className="input text-sm w-auto">` — Дата с / Дата по (фильтр по `desired_date`)

**SWR-ключ** — строится из фильтров:
```
/api/finance/requests?status={status}&legal_entity_id={entity}&request_type={type}
```
Параметры `date_from`/`date_to` backend пока не поддерживает через query — применяй client-side фильтр по `desired_date` если нужно.

**Таблица** (`card overflow-hidden`):
```
th: №, Тип, Сумма, Желаемая дата, Статус, Заявитель, Действия
```
- Колонка «Сумма» — `<MoneyCell amount={req.amount} currency={req.currency} direction="out" />`
- Колонка «Статус» — `<RequestStatusBadge status={req.status} />`
- Колонка «Заявитель» — показывать только если роль ≠ manager (manager видит только свои)
- Колонка «Действия» — кнопки-иконки по правам:
  - draft + автор → `bi-send` (Отправить) — `btn-ghost text-primary text-sm`
  - любая → `bi-eye` (Открыть) — ссылка на `/finance/requests/{id}`

**Строка** — кликабельна целиком → переход на `/finance/requests/{id}`.

### RequestStatusBadge

Новый компонент по образцу `OperationStatusBadge`:

```
draft        → bg-gray-100 text-gray-600       «Черновик»
submitted    → bg-yellow-50 text-yellow-700     «На согласовании»
approved     → bg-green-50 text-green-700       «Одобрено»
rejected     → bg-red-50 text-red-700           «Отклонено»
paid         → bg-blue-50 text-blue-700         «Оплачено»
cancelled    → bg-gray-100 text-gray-400        «Отменено»
```

Тёмный режим: аналогично `OperationStatusBadge` — `/20` opacity + `dark:` варианты.

### RequestCreateModal

`Modal` компонент (`width="lg"`). Форма создания заявки.

**Поля (секция «Основное» — `border rounded-lg p-4 space-y-4`):**

```
[Тип заявки *]        select — salary / commission / expense_reimbursement / payment
[Юрлицо *]           select (SWR /api/finance/legal-entities)
[Сумма *]             input[type=number] + [Валюта] CurrencySelect (реюз из Ф0)
[Желаемая дата]       input[type=date]
[Описание]            textarea rows=3
```

**Поля условно (зависит от типа):**
- `salary` / `commission` → показать `[Сотрудник-получатель]` (UserSelect, `payee_user_id`) — SWR `/api/users`
- `expense_reimbursement` → показать `[Контрагент]` (`counterparty_company_id`) — select SWR `/api/counterparties`
- `payment` → показать `[Контрагент]` + `[Статья ДДС]` (`cashflow_category_id`) — select SWR `/api/finance/categories`
- Для `salary` / `commission` → показать `[Период: год + месяц]` (`period_year`, `period_month`)

**Валидация на фронте:**
- `request_type` — обязательно
- `legal_entity_id` — обязательно
- `amount > 0` — обязательно
- `currency` — обязательно (дефолт `KZT`)

**Footer Modal:**
```
[btn-ghost: Отмена]                [btn-primary: Создать заявку]
```

После успешного `POST /api/finance/requests` → `mutate("/api/finance/requests")` → `onClose()`.

### Wireframe — карточка заявки `/finance/requests/[id]`

```
┌───────────────────────────────────────────────────────────────────────┐
│ [PageHeader: Заявка №REQ-001]    [Отправить] [Одобрить] [Отклонить] │
│                                                                       │
│ ┌── Детали заявки (card) ──┐  ┌── Согласование (card) ─────────────┐ │
│ │ Тип: Зарплата            │  │ Статус: На согласовании            │ │
│ │ Сумма: 150 000 KZT       │  │                                    │ │
│ │ Юрлицо: MACRO KZ         │  │ Этап 1: CFO ················ 0/1  │ │
│ │ Дата желаемая: 10.01     │  │   Ivan Petrov       [Ожидает]     │ │
│ │ Описание: …              │  │                                    │ │
│ │ Статус: [badge]          │  │ Этап 2: Директор ··········· —    │ │
│ │ Заявитель: Иванов        │  │   (ещё не активен)                │ │
│ └──────────────────────────┘  └────────────────────────────────────┘ │
│                                                                       │
│ ┌── Действия (card) ── виден только когда есть доступные кнопки ─┐   │
│ │ [btn-ghost: Отменить заявку]  [btn-primary: Исполнить (бухг.)] │   │
│ └────────────────────────────────────────────────────────────────┘   │
└───────────────────────────────────────────────────────────────────────┘
```

### Карточка заявки: компоненты

**SWR:**
```ts
const { data: req }     = useSWR<FinRequest>(`/api/finance/requests/${id}`, fetcher);
const { data: approval }= useSWR<ApprovalSummary>(`/api/finance/requests/${id}/approval`, fetcher);
```

**Карточка «Детали»** (`card p-5`):
- Grid 2-col: Тип | Статус-badge
- Grid 2-col: Сумма (`MoneyCell`) | Валюта
- Grid 2-col: Юрлицо | Желаемая дата
- Grid 2-col: Заявитель (user_id) | Получатель (`payee_user_id`, если есть)
- Описание (полная строка)
- `rejected_reason` — если отклонена: блок `bg-red-50 dark:bg-red-900/20 rounded p-3 text-sm text-danger` с текстом причины
- Ссылка на `resulting_operation_id` — если `status=paid`: `Операция #N →` ссылка на `/finance/operations/{id}`

**ApprovalSummaryPanel** (`card p-5`) — переиспользуется на заявках И реестрах:

Props:
```ts
interface Props {
  summary: ApprovalSummaryOut | undefined;
  isLoading: boolean;
  // для панели голосования (согласант)
  onDecide?: (decision: "approved" | "rejected", comment: string) => Promise<void>;
  canDecide?: boolean;
}
```

Визуально:
- Заголовок: «Согласование» + бейдж статуса (`pending` → «На согласовании» info, `approved` → success, `rejected` → danger)
- Прогресс-строка: «Этап N из M» (`active_stage + 1` из `total_stages`)
- Для каждого этапа из `summary.stages[]`:
  - Заголовок: `{stage.name}` + режим (`mode=all` → «нужны все», `mode=any` → `min_required из N`)
  - Список согласантов из `stage.user_ids` — резолвить имена через SWR `/api/users` или `/api/me` (кешировать)
  - Счётчик голосов: `✓ {approved}  ✗ {rejected}  ⏳ {pending}`
  - Если `stage.is_active=true` — выделить бордером `border-primary`
- Голоса (`summary.votes[]`): компактный список под этапом с `decided_at` и `comment`

**Форма голосования** (показать только если `canDecide=true` и `summary.status=pending`):
```
[textarea: Комментарий к решению (необязательно)] rows=2
[btn-secondary text-danger: Отклонить]  [btn-primary: Одобрить]
```
При submit → `POST /api/finance/requests/{id}/decision` body `{decision, comment}` → `mutate`.

**Кнопки действий в PageHeader** (по правам и статусу):

| Условие | Кнопка |
|---|---|
| `status=draft` И `req.requester_user_id=me.id` | `btn-primary` «Отправить на согласование» → POST `/{id}/submit` |
| `status=approved` И роль `accountant/cfo/admin` | `btn-primary` «Исполнить» → открыть `RequestFulfillModal` |
| `status=draft\|submitted` И (`автор` ИЛИ `manage_registry`) | `btn-secondary text-danger` «Отменить» → confirm → POST `/{id}/cancel` |

### RequestFulfillModal

`Modal width="md"`. Для бухгалтера, конвертирует approved-заявку в операцию.

Поля (соответствуют `RequestFulfillIn`):
```
[Счёт списания *]     select (SWR /api/finance/money-accounts)
[Дата операции]       input[type=date] (дефолт — сегодня)
[Тип операции]        select (SWR /api/finance/op-types, direction=out) — переопределить если нужно
[Провести сразу?]     checkbox, дефолт true (auto_post)
```

Footer: `[btn-ghost: Отмена]` `[btn-primary: Исполнить]`

POST `/api/finance/requests/{id}/fulfill` body `RequestFulfillIn` → mutate → onClose.

---

## Экран 2: `/finance/registries` — Реестр платежей

**Где в коде:**
- Листинг: `apps/web/src/app/(app)/finance/registries/page.tsx`
- Карточка: `apps/web/src/app/(app)/finance/registries/[id]/page.tsx`
- Компоненты: `apps/web/src/components/Finance/RegistryStatusBadge.tsx`
- Компоненты: `apps/web/src/components/Finance/RegistryCreateModal.tsx`
- Компоненты: `apps/web/src/components/Finance/RegistryItemsPanel.tsx`

### Wireframe — листинг

```
┌──────────────────────────────────────────────────────────────────────────┐
│ [Sidebar]  │ [PageHeader: Реестры платежей]            [+ Новый реестр] │
│            ├──────────────────────────────────────────────────────────────┤
│            │ ┌── Filters ──────────────────────────────────────────────┐  │
│            │ │ [Статус согл. ▼] [Статус оплаты ▼] [Юрлицо ▼]         │  │
│            │ └─────────────────────────────────────────────────────────┘  │
│            │                                                               │
│            │ ┌── Table ──────────────────────────────────────────────────┐ │
│            │ │ №  │ Дата  │ Счёт        │ Позиций │ Сумма  │ Апрув │Опл │ │
│            │ ├────┼───────┼─────────────┼─────────┼────────┼───────┼────┤ │
│            │ │ R1 │06.01  │ Kaspi KZT   │    4    │ 600к   │[draft]│[new│ │
│            │ └───────────────────────────────────────────────────────────┘ │
└──────────────────────────────────────────────────────────────────────────┘
```

**Доступ:** `RoleGate allowed={["accountant", "cfo", "admin"]}` — только они видят раздел.

**Фильтры:**
- `approval_status`: `draft` / `on_review` / `approved` / `rejected`
- `payment_status`: `new` / `partial` / `paid`
- `legal_entity_id`: select (SWR `/api/finance/legal-entities`)

**SWR-ключ:**
```
/api/finance/registries?approval_status={}&legal_entity_id={}
```

**Таблица:**
- Колонка «Счёт» — резолвить `source_account_id` → имя счёта через SWR `/api/finance/money-accounts`
- Колонка «Сумма» — из `RegistryDetailOut.total_amount` (листинг возвращает `RegistryOut` без total, поэтому либо суммировать отдельно либо показывать «—» в листинге, считать на странице детали). **Открытый вопрос #1.**
- Статусы: два бейджа — `RegistryStatusBadge` (approval) + `PaymentStatusBadge` (payment)

### RegistryStatusBadge (для approval_status)

```
draft      → bg-gray-100 text-gray-600       «Черновик»
on_review  → bg-yellow-50 text-yellow-700    «На согласовании»
approved   → bg-green-50 text-green-700      «Одобрено»
rejected   → bg-red-50 text-red-700          «Отклонено»
```

### PaymentStatusBadge (для payment_status)

```
new        → bg-gray-100 text-gray-600       «Не оплачен»
partial    → bg-yellow-50 text-yellow-700    «Частично»
paid       → bg-green-50 text-green-700      «Оплачен»
```

### RegistryCreateModal

`Modal width="md"`.

Поля (соответствуют `RegistryCreate`):
```
[Юрлицо *]       select (SWR /api/finance/legal-entities)
[Счёт списания *] select (SWR /api/finance/money-accounts, фильтровать по legal_entity_id если выбрано)
[Дата реестра *]  input[type=date] (дефолт — сегодня)
[Название]        input[type=text] placeholder="Например: Зарплата январь 2026"
[Комментарий]     textarea rows=2
```

Footer: `[btn-ghost: Отмена]` `[btn-primary: Создать реестр]`

POST `/api/finance/registries` body `RegistryCreate` → navigate to `/finance/registries/{id}`.

### Wireframe — карточка реестра `/finance/registries/[id]`

```
┌────────────────────────────────────────────────────────────────────────────┐
│ [PageHeader: Реестр №R-001]  [Подать на согл.] [Провести всё]             │
│                                                                             │
│ ┌── Детали реестра (card) ──────────┐  ┌── Согласование (card) ──────────┐ │
│ │ Дата: 06.01.2026                  │  │ [ApprovalSummaryPanel]          │ │
│ │ Счёт: Kaspi KZT                   │  │                                 │ │
│ │ Юрлицо: MACRO KZ                 │  └─────────────────────────────────┘ │
│ │ Статус: [badge] [badge]           │                                      │
│ │ Комментарий: …                    │                                      │
│ └───────────────────────────────────┘                                      │
│                                                                             │
│ ┌── Позиции реестра (card) ─────────────────────────────────────────────┐  │
│ │ [Добавить операции ▼]                             Итого: 600 000 KZT  │  │
│ ├──────────────────────────────────────────────────────────────────────┤  │
│ │ №операции │ Назначение   │ Сумма        │ Статус        │ [удалить] │  │
│ ├──────────┼───────────────┼──────────────┼───────────────┼───────────┤  │
│ │ OP-1234  │ Зарплата      │ 150 000 KZT  │ [to_pay]      │ [bi-x]   │  │
│ │ OP-1235  │ Комиссия      │  25 000 KZT  │ [to_pay]      │ [bi-x]   │  │
│ └──────────────────────────────────────────────────────────────────────┘  │
└────────────────────────────────────────────────────────────────────────────┘
```

### RegistryItemsPanel

Компонент для управления позициями (только в статусе `draft`).

**SWR:**
```ts
const { data: reg } = useSWR<RegistryDetailOut>(`/api/finance/registries/${id}`, fetcher);
```
`RegistryDetailOut` содержит `items: FinOperation[]` и `total_amount`.

**Таблица позиций:**
```
th: Операция | Назначение | Сумма | Статус | (кнопка удалить)
```
- Колонка «Сумма» — `MoneyCell amount={op.amount} currency={op.currency} direction="out"`
- Колонка «Статус» — `OperationStatusBadge status={op.status}`
- Кнопка удалить (только `draft`) — `bi-x-lg text-danger btn-ghost text-sm` → confirm → `DELETE /api/finance/registries/{id}/items/{op_id}` → mutate

**Итого-строка** (`FinSumFooter` паттерн из Ф0):
```
[Итого: {total_amount} {валюта счёта}] — выровнять вправо, tabular-nums font-bold
```

**Добавить операции** (только `draft`) — кнопка `btn-secondary + bi-plus-lg`:
Открывает inline-панель (или Modal) с выбором операций.

**Picker операций** — Modal «Добавить операции»:
- SWR `/api/finance/operations?direction=out&account_from_id={source_account_id}&status=to_pay`
  (логика: в реестр идут расходные операции с того же счёта, что `source_account_id`)
- Таблица с чекбоксами: Операция | Назначение | Сумма | Статус
- `[btn-primary: Добавить выбранные]` → POST `/api/finance/registries/{id}/items` body `{operation_ids: [...]}`
- Исключить уже добавленные операции (`op.registry_id != null`)

**Кнопки действий в PageHeader:**

| Условие | Кнопка |
|---|---|
| `approval_status=draft` И кол-во позиций > 0 | `btn-primary` «Подать на согласование» → POST `/{id}/submit` |
| `approval_status=approved` И `payment_status!=paid` И роль `accountant/cfo/admin` | `btn-primary` «Провести всё» → confirm → POST `/{id}/provision` |

После provision → mutate → показать результат (сколько проведено).

**Нельзя редактировать** состав реестра если `approval_status != "draft"` — кнопки добавить/удалить скрыты, показывать `text-sm text-gray-400 italic «Состав заморожен после подачи на согласование»`.

---

## Экран 3: `/finance/approvals` — Мои согласования (inbox)

**Где в коде:**
- Страница: `apps/web/src/app/(app)/finance/approvals/page.tsx`

**Зачем:** Один экран где согласант видит ВСЁ что ждёт его решения — заявки и реестры.
Исключает необходимость ходить по разным разделам.

### Wireframe

```
┌──────────────────────────────────────────────────────────────────────────┐
│ [Sidebar]  │ [PageHeader: Согласования]                                  │
│            ├──────────────────────────────────────────────────────────────┤
│            │ ┌── Tabs ──────────────────────────────────────────────────┐ │
│            │ │ [Все (7)]  [Заявки (5)]  [Реестры (2)]                  │ │
│            │ └─────────────────────────────────────────────────────────┘  │
│            │                                                               │
│            │ ┌── Card: заявка REQ-001 ───────────────────────────────┐   │
│            │ │ [bi-file-earmark-text] Заявка · Зарплата · MACRO KZ  │   │
│            │ │ Иванов Иван · 150 000 KZT · Этап 1: CFO              │   │
│            │ │                                                        │   │
│            │ │ [Комментарий ________________________]                 │   │
│            │ │ [btn-ghost: Открыть →]  [btn-secondary: Отклонить]   │   │
│            │ │                              [btn-primary: Одобрить]  │   │
│            │ └────────────────────────────────────────────────────────┘  │
│            │ ┌── Card: реестр R-002 ─────────────────────────────────┐   │
│            │ │ [bi-list-check] Реестр · 4 позиции · Kaspi KZT        │   │
│            │ │ Петрова · 600 000 KZT · Этап 1: Директор              │   │
│            │ │ [Комментарий ________________________]                 │   │
│            │ │ [btn-ghost: Открыть →]  [btn-secondary: Отклонить]   │   │
│            │ │                              [btn-primary: Одобрить]  │   │
│            │ └────────────────────────────────────────────────────────┘  │
└──────────────────────────────────────────────────────────────────────────┘
```

### Логика получения pending-объектов

Backend **не имеет** единого endpoint `GET /api/finance/approvals/my-pending`.
Frontend-специалист должен реализовать следующую логику:

1. `GET /api/finance/requests?status=submitted` — все заявки на рассмотрении
2. `GET /api/finance/registries?approval_status=on_review` — все реестры на рассмотрении
3. Для каждого объекта → `GET /api/finance/requests/{id}/approval` / `GET /api/finance/registries/{id}/approval`
4. Фильтровать на клиенте: оставить те, в которых `me.id` входит в `active_stage.user_ids` (поле `stages[is_active=true].user_ids`)

**ВАЖНО об оптимизации:** Параллельные запросы на approval-summary для каждого объекта могут быть затратны. На первой итерации — допустимо. В будущем потребует backend-endpoint `GET /api/finance/approvals/pending-for-me`. Отметить как `// TODO: backend endpoint` в коде.

Используй `useSWR` с `{ refreshInterval: 30_000 }` — inbox должен обновляться.

### Компоненты

**Tabs** (статический переключатель, не URL-based):
- «Все», «Заявки», «Реестры» — счётчики в скобках из загруженных данных
- Активная вкладка — `border-b-2 border-primary text-primary font-medium`

**ApprovalInboxCard** — `card p-4` для каждого объекта:

Заголовок (flex items-center gap-2):
- Иконка: заявка → `bi-file-earmark-text text-primary`, реестр → `bi-list-check text-primary`
- Тип: «Заявка» / «Реестр» · Подтип (request_type / «N позиций»)
- Юрлицо (если есть)

Строка 2: автор · сумма (MoneyCell) · «Этап N: {stage.name}»

Строка 3: поле для комментария (textarea rows=1, placeholder «Комментарий к решению…»)

Кнопки (flex justify-between):
- Слева: `btn-ghost text-sm` «Открыть →» — Link на карточку (`/finance/requests/{id}` или `/finance/registries/{id}`)
- Справа: `btn-secondary text-danger text-sm` «Отклонить» + `btn-primary text-sm` «Одобрить»

При клике Одобрить/Отклонить:
- Для заявок: POST `/api/finance/requests/{id}/decision` body `{decision, comment}`
- Для реестров: POST `/api/finance/registries/{id}/decision` body `{decision, comment}`
- После ответа → mutate списка → карточка исчезает из inbox (объект больше не pending для этого user)

**Счётчик в Sidebar** (необязательно в Итерации 1 — отметить как «опционально»):
Хук `useFinApprovalCount` → SWR с той же логикой → `{ count: N }` → `NavItemWithBadge` с `badgeCount`.
Реализовать если не затратно, иначе — TODO.

---

## Экран 4: `/finance/settings/approval-scenarios` — Сценарии согласования

**Где в коде:**
- Страница: `apps/web/src/app/(app)/finance/settings/approval-scenarios/page.tsx`
- Компоненты: `apps/web/src/components/Finance/ApprovalScenarioEditor.tsx`

**Доступ:** только `cfo`, `admin`. Оборачивать `RoleGate allowed={["cfo", "admin"]}`.

### Wireframe — листинг

```
┌───────────────────────────────────────────────────────────────────────────┐
│ [Sidebar]  │ [PageHeader: Сценарии согласования]   [+ Создать сценарий]  │
│            ├───────────────────────────────────────────────────────────────┤
│            │ ┌── Фильтр: [Тип объекта ▼: operation|registry|request]     │ │
│            │                                                               │
│            │ ┌── Table ──────────────────────────────────────────────┐    │
│            │ │ Название │ Тип объекта │ Сумма от-до │ Приоритет │Ред │    │
│            │ ├──────────┼─────────────┼─────────────┼───────────┼────┤    │
│            │ │ CFO >= 50к│ request    │ 50 000 — ∞  │    10     │[✏] │    │
│            │ │ Директор  │ registry   │ 0 — ∞       │     5     │[✏] │    │
│            │ └──────────────────────────────────────────────────────┘    │
└───────────────────────────────────────────────────────────────────────────┘
```

**SWR:** `GET /api/finance/approval-scenarios?applies_to={filter}` → `ApprovalScenarioOut[]`

**Таблица:**
- «Тип объекта» — `applies_to`: `operation` / `registry` / `request` / `invoice`
- «Сумма» — `{min_amount ?? 0} — {max_amount ?? '∞'}` + валюта (если null → пусто)
- «Тип операции» — `op_type_id` → резолвить через SWR `/api/finance/op-types`
- «Юрлицо» — `legal_entity_id` → резолвить через SWR `/api/finance/legal-entities`
- «Статус» — `is_active` → бейдж `Активен`/`Архив`
- Кнопка редактировать `bi-pencil btn-ghost` → открыть `ApprovalScenarioEditor` в Modal с данными сценария

### ApprovalScenarioEditor (создание и редактирование)

`Modal width="xl"` (максимальный). Форма в нём большая — два блока.

**Блок 1: Параметры применения** (`border rounded-lg p-4 space-y-4 mb-4`):
```
[Название *]              input[type=text]
[Тип объекта *]           select — operation | registry | request | invoice
[Тип операции]            select (SWR /api/finance/op-types, nullable) «Все типы»
[Юрлицо]                 select (SWR /api/finance/legal-entities, nullable) «Все юрлица»
[Сумма от]               input[type=number min=0] (min_amount, nullable)
[Сумма до]               input[type=number min=0] (max_amount, nullable)
[Приоритет]              input[type=number] (дефолт 0, выше = важнее)
[Активен]                checkbox (is_active)
```

**Блок 2: Конструктор этапов** (`border rounded-lg p-4 space-y-4`):

Заголовок: «Этапы согласования» + кнопка `btn-secondary bi-plus-lg «Добавить этап»`

Для каждого этапа — `border rounded p-3 space-y-2 mb-2`:
```
[Название этапа *]      input[type=text] placeholder="Например: Согласование CFO"
[Согласанты *]          UserSelect (мульти, min 1 пользователь)
                         — или: select с мульти-выбором через SWR /api/users
[Режим]                 radio: (●) Достаточно одного   ( ) Нужны все
[Мин. голосов]          input[type=number min=1] (visible только если режим=any)
                         auto-clamp в [1, len(user_ids)]
[btn-ghost text-danger bi-trash: удалить этап]   (если этапов > 1)
```

Drag-to-reorder этапов — **НЕ реализовывать** в первой итерации. Кнопки «↑ / ↓» для ручного переупорядочивания (PATCH с обновлённым `stages` массивом где `order` проставлен по позиции в списке).

**Валидация на фронте:**
- Минимум один этап
- В каждом этапе: название не пусто, `user_ids.length >= 1`
- `min_required` ∈ `[1, user_ids.length]`
- `min_amount <= max_amount` (если оба заданы)
- Перед POST/PATCH: пронумеровать `order` от 0 по позиции в массиве

**Footer Modal:**
```
[btn-ghost: Отмена]          [btn-primary: Сохранить сценарий]
```

POST `/api/finance/approval-scenarios` (создание) или PATCH `/{id}` (редактирование).
При ошибке 422 (validate_stages) — показать inline `text-danger` с текстом из `detail`.

---

## Изменения в Sidebar

В `apps/web/src/components/Sidebar.tsx` добавить пункты в группу «Финансы».

**Вставить после `{ href: "/finance/journals", ... }`:**

```ts
{ href: "/finance/requests",  icon: "bi-file-earmark-text", label: "Заявки",
  roles: ["manager", "accountant", "cfo", "director", "admin"] },
{ href: "/finance/approvals", icon: "bi-check2-square",     label: "Согласования",
  roles: ["accountant", "cfo", "director", "admin"] },
{ href: "/finance/registries", icon: "bi-list-check",       label: "Реестр платежей",
  roles: ["accountant", "cfo", "admin"] },
```

**Вставить в настройки (после `{ href: "/finance/settings/op-types", ... }`):**

```ts
{ href: "/finance/settings/approval-scenarios", icon: "bi-diagram-3", label: "Сценарии согл.",
  roles: ["cfo", "admin"] },
```

Тип `manager` должен быть добавлен в `UserRole` в `@/lib/types.ts` если его там нет —
проверь и добавь при необходимости.

---

## Типы TypeScript (добавить в `@/lib/types.ts`)

Добавить в конец файла (строго типизировать, никакого `any`):

```ts
// ── Финансы Ф2: заявки ──────────────────────────────────────────────────

export type FinRequestType = "salary" | "commission" | "expense_reimbursement" | "payment";
export type FinRequestStatus = "draft" | "submitted" | "approved" | "rejected" | "paid" | "cancelled";

export interface FinRequest {
  id: number;
  number: string | null;
  request_type: FinRequestType;
  legal_entity_id: number;
  requester_user_id: number | null;
  amount: string;         // Decimal → string в JSON
  currency: string;
  op_type_id: number | null;
  counterparty_company_id: number | null;
  payee_user_id: number | null;
  cashflow_category_id: number | null;
  period_year: number | null;
  period_month: number | null;
  desired_date: string | null;   // YYYY-MM-DD
  description: string | null;
  status: FinRequestStatus;
  resulting_operation_id: number | null;
  rejected_reason: string | null;
  submitted_at: string | null;
  decided_at: string | null;
  created_at: string;
}

// ── Финансы Ф2: реестры ─────────────────────────────────────────────────

export type FinRegistryApprovalStatus = "draft" | "on_review" | "approved" | "rejected";
export type FinRegistryPaymentStatus = "new" | "partial" | "paid";

export interface FinRegistry {
  id: number;
  number: string | null;
  legal_entity_id: number;
  source_account_id: number;
  registry_date: string;   // YYYY-MM-DD
  title: string | null;
  approval_status: FinRegistryApprovalStatus;
  payment_status: FinRegistryPaymentStatus;
  comment: string | null;
  created_by_user_id: number | null;
  submitted_at: string | null;
  approved_at: string | null;
  posted_at: string | null;
  created_at: string;
}

export interface FinRegistryDetail extends FinRegistry {
  items: FinOperation[];
  total_amount: string;   // Decimal
}

// ── Финансы Ф2: согласование ─────────────────────────────────────────────

export type FinApprovalStatus = "pending" | "approved" | "rejected";
export type FinApprovalDecision = "pending" | "approved" | "rejected";

export interface FinApprovalVote {
  id: number;
  approvable_kind: string;
  approvable_id: number;
  scenario_id: number | null;
  user_id: number;
  stage_order: number;
  decision: FinApprovalDecision;
  comment: string | null;
  decided_at: string | null;
}

export interface FinApprovalStageSummary {
  order: number;
  name: string;
  mode: "any" | "all";
  user_ids: number[];
  min_required: number;
  approved: number;
  rejected: number;
  pending: number;
  is_active: boolean;
}

export interface FinApprovalSummary {
  status: FinApprovalStatus;
  active_stage: number;
  total_stages: number;
  stages: FinApprovalStageSummary[];
  scenario_id: number | null;
  votes: FinApprovalVote[];
}

// ── Финансы Ф2: сценарии согласования ───────────────────────────────────

export interface FinScenarioStage {
  order: number;
  name: string;
  user_ids: number[];
  min_required: number;
  mode: "any" | "all";
}

export interface FinApprovalScenario {
  id: number;
  name: string;
  applies_to: "operation" | "registry" | "request" | "invoice";
  op_type_id: number | null;
  legal_entity_id: number | null;
  min_amount: string | null;   // Decimal
  max_amount: string | null;
  stages: FinScenarioStage[];
  priority: number;
  is_active: boolean;
}
```

---

## States (loading / empty / error)

### Листинги (requests, registries, approval-scenarios)

- **Loading:** скелетон `animate-pulse` — 4 строки таблицы, высота `h-8 bg-gray-100 dark:bg-gray-700 rounded mb-2`
- **Empty:**
  - Заявки: `bi-file-earmark-text text-5xl text-gray-300`, «Заявок пока нет», «Создай первую, чтобы запустить процесс согласования», `btn-primary «Создать заявку»`
  - Реестры: `bi-list-check text-5xl text-gray-300`, «Реестров пока нет», «Создай реестр, чтобы собрать платежи для проведения», `btn-primary «Новый реестр»`
  - Согласования: `bi-check2-all text-5xl text-gray-300`, «Нет объектов на согласовании», «Здесь появятся заявки и реестры, ожидающие твоего решения»
  - Сценарии: `bi-diagram-3 text-5xl text-gray-300`, «Сценарии не настроены», «Без сценария согласование невозможно — создай первый», `btn-primary «Создать сценарий»`
- **Error:** `text-danger text-sm p-3 bg-red-50 dark:bg-red-900/20 rounded` под фильтрами

### ApprovalSummaryPanel

- **Loading:** `animate-pulse` 3 строки
- **No approval started** (empty summary): `text-sm text-gray-400 italic «Согласование ещё не запущено»`
- **Status=approved:** блок `bg-green-50 dark:bg-green-900/20 p-3 rounded flex items-center gap-2` с `bi-check-circle-fill text-success` и «Согласовано»
- **Status=rejected:** аналогично `bg-red-50` + причина отклонения из последнего rejected vote

---

## Interactions

| Элемент | Действие | Результат |
|---|---|---|
| `+ Создать заявку` | click | Открыть `RequestCreateModal` |
| Строка заявки в таблице | click | Переход на `/finance/requests/{id}` |
| `bi-send` в таблице | click | POST `/{id}/submit` → mutate → toast inline |
| `Отправить на согласование` (карточка) | click | POST `/{id}/submit` → mutate → кнопка исчезает |
| `Одобрить` / `Отклонить` (карточка заявки) | click | POST `/{id}/decision` → mutate summary |
| `Исполнить` (карточка, approved) | click | Открыть `RequestFulfillModal` |
| `Отменить заявку` | click | Confirm modal → POST `/{id}/cancel` → mutate |
| `+ Новый реестр` | click | Открыть `RegistryCreateModal` → navigate to `/{id}` |
| `Добавить операции` (реестр) | click | Открыть picker Modal → POST `/{id}/items` |
| `bi-x-lg` в позиции реестра | click | DELETE `/{id}/items/{op_id}` → mutate |
| `Подать на согласование` (реестр) | click | Confirm → POST `/{id}/submit` → mutate |
| `Провести всё` (реестр, approved) | click | Confirm → POST `/{id}/provision` → mutate |
| `Одобрить` / `Отклонить` (inbox) | click | POST decision → mutate → карточка уходит из inbox |
| `+ Создать сценарий` | click | Открыть `ApprovalScenarioEditor` (пустой) |
| `bi-pencil` в сценарии | click | Открыть `ApprovalScenarioEditor` (с данными) |
| `Добавить этап` в редакторе | click | Append новый пустой этап в список |
| `bi-trash` в этапе | click | Удалить этап из локального состояния |
| Сохранить сценарий | click | POST или PATCH → mutate → onClose |

---

## Адаптивность

Desktop-first. Mobile — TBD (эпик 10). Таблицы горизонтально скроллятся на узких экранах.

---

## Тексты (RU, без i18n)

### Страница «Заявки»
- Заголовок: `Заявки`
- Кнопка создать: `Создать заявку`
- Фильтр тип: `Все типы` / `Зарплата` / `Комиссия` / `Возмещение расходов` / `Платёж`
- Фильтр статус: `Все статусы` / `Черновик` / `На согласовании` / `Одобрено` / `Отклонено` / `Оплачено` / `Отменено`
- TH таблицы: `№` / `Тип` / `Сумма` / `Желаемая дата` / `Статус` / `Заявитель`
- Empty title: `Заявок пока нет`
- Empty desc: `Создай первую, чтобы запустить процесс согласования`

### Модал «Создать заявку»
- Заголовок: `Новая заявка`
- Лейбл тип: `Тип заявки *`
- Лейбл юрлицо: `Юрлицо *`
- Лейбл сумма: `Сумма *`
- Лейбл валюта: `Валюта *`
- Лейбл дата: `Желаемая дата выплаты`
- Лейбл получатель: `Сотрудник-получатель`
- Лейбл контрагент: `Контрагент`
- Лейбл статья: `Статья ДДС`
- Лейбл период: `Период (год)` / `Период (месяц)`
- Лейбл описание: `Описание / назначение`
- Placeholder описание: `Укажи назначение платежа или обоснование…`
- Кнопка сохр.: `Создать заявку`
- Ошибка валидации: `Укажи тип, юрлицо и сумму`

### Карточка заявки
- Заголовок: `Заявка №{number}` или `Заявка #{id}`
- Секция детали: `Детали заявки`
- Секция согласование: `Согласование`
- Поле «Причина отклонения»: `Причина отклонения`
- Ссылка на операцию: `Создана операция →`
- Кнопка отправить: `Отправить на согласование`
- Кнопка исполнить: `Исполнить`
- Кнопка отменить: `Отменить заявку`
- Confirm отмены: `Отменить заявку?` / `Заявка будет отменена, отменить это действие нельзя.` / `Да, отменить`

### ApprovalSummaryPanel
- Заголовок: `Согласование`
- Прогресс: `Этап {active+1} из {total}`
- Режим all: `Нужны все`
- Режим any: `Достаточно {min} из {n}`
- Счётчик: `✓ {approved} одобрили · ✗ {rejected} отклонили · ⏳ {pending} ожидают`
- Placeholder комментарий: `Комментарий к решению (необязательно)`
- Кнопка одобрить: `Одобрить`
- Кнопка отклонить: `Отклонить`
- Состояние «ещё не активен»: `Ожидает завершения предыдущего этапа`
- Empty: `Согласование ещё не запущено`
- Approved: `Согласовано`
- Rejected: `Отклонено`

### Страница «Реестр платежей»
- Заголовок: `Реестры платежей`
- Кнопка создать: `Новый реестр`
- TH: `№` / `Дата` / `Счёт` / `Позиций` / `Сумма` / `Согласование` / `Оплата`
- Empty title: `Реестров пока нет`
- Empty desc: `Создай реестр, чтобы собрать расходные операции для проведения`

### Карточка реестра
- Кнопка подать: `Подать на согласование`
- Кнопка провести: `Провести всё`
- Confirm провести: `Провести все позиции реестра? Отменить это действие нельзя.` / `Провести`
- Итого: `Итого:`
- Заголовок позиции: `Позиции реестра`
- Кнопка добавить: `Добавить операции`
- Modal picker заголовок: `Выбрать операции`
- Modal picker кнопка: `Добавить выбранные`
- Состав заморожен: `Состав заморожен — реестр подан на согласование`
- Confirm подачи: `Подать реестр на согласование? После подачи состав нельзя изменить.` / `Подать`

### Страница «Согласования»
- Заголовок: `Согласования`
- Вкладки: `Все` / `Заявки` / `Реестры`
- Кнопка открыть: `Открыть →`
- Empty title: `Нет объектов на согласовании`
- Empty desc: `Здесь появятся заявки и реестры, ожидающие твоего решения`

### Страница «Сценарии согласования»
- Заголовок: `Сценарии согласования`
- Кнопка создать: `Создать сценарий`
- TH: `Название` / `Тип объекта` / `Тип операции` / `Юрлицо` / `Диапазон сумм` / `Приоритет` / `Статус`
- Applies_to labels: `operation → Операция` / `registry → Реестр` / `request → Заявка` / `invoice → Счёт`
- Empty title: `Сценарии не настроены`
- Empty desc: `Без сценария согласование невозможно — создай хотя бы один`

### Редактор сценария
- Заголовок (создание): `Новый сценарий`
- Заголовок (редакт.): `Редактировать сценарий`
- Лейбл название: `Название *`
- Лейбл applies_to: `Тип объекта *`
- Лейбл op_type: `Тип операции (необязательно)`
- Placeholder op_type: `Все типы операций`
- Лейбл legal_entity: `Юрлицо (необязательно)`
- Placeholder legal_entity: `Все юрлица`
- Лейбл min_amount: `Сумма от`
- Лейбл max_amount: `Сумма до`
- Лейбл priority: `Приоритет (выше = важнее)`
- Лейбл is_active: `Сценарий активен`
- Секция этапов: `Этапы согласования`
- Кнопка добавить этап: `Добавить этап`
- Лейбл название этапа: `Название этапа *`
- Лейбл согласанты: `Согласанты *`
- Лейбл режим: `Режим`
- Режим any: `Достаточно одного`
- Режим all: `Нужны все`
- Лейбл min_required: `Минимальное число голосов`
- Ошибка: `Этап #{n}: нужен хотя бы один согласант`
- Ошибка min_required: `Минимум не может превышать количество согласантов`
- Кнопка сохр.: `Сохранить сценарий`

---

## Связь с backend

| Метод | URL | Назначение |
|---|---|---|
| GET | `/api/finance/requests` | список заявок (фильтры: `legal_entity_id`, `status`) |
| POST | `/api/finance/requests` | создать заявку (body: `RequestCreate`) |
| GET | `/api/finance/requests/{id}` | детали заявки (`RequestOut`) |
| PATCH | `/api/finance/requests/{id}` | правка черновика (`RequestPatch`) |
| POST | `/api/finance/requests/{id}/submit` | подать на согл. → `ApprovalSummaryOut` |
| GET | `/api/finance/requests/{id}/approval` | сводка согл. → `ApprovalSummaryOut` |
| POST | `/api/finance/requests/{id}/decision` | голос (body: `{decision, comment}`) |
| POST | `/api/finance/requests/{id}/fulfill` | исполнить (body: `RequestFulfillIn`) |
| POST | `/api/finance/requests/{id}/cancel` | отменить |
| GET | `/api/finance/registries` | список реестров (фильтры: `legal_entity_id`, `approval_status`) |
| POST | `/api/finance/registries` | создать реестр (`RegistryCreate`) |
| GET | `/api/finance/registries/{id}` | детали реестра (`RegistryDetailOut`) |
| PATCH | `/api/finance/registries/{id}` | правка черновика (`RegistryPatch`) |
| POST | `/api/finance/registries/{id}/items` | добавить операции (body: `{operation_ids:[]}`) |
| DELETE | `/api/finance/registries/{id}/items/{op_id}` | убрать операцию |
| POST | `/api/finance/registries/{id}/submit` | подать на согл. |
| GET | `/api/finance/registries/{id}/approval` | сводка согл. |
| POST | `/api/finance/registries/{id}/decision` | голос |
| POST | `/api/finance/registries/{id}/provision` | провести всё |
| GET | `/api/finance/approval-scenarios` | список сценариев (фильтр: `applies_to`) |
| POST | `/api/finance/approval-scenarios` | создать сценарий |
| PATCH | `/api/finance/approval-scenarios/{id}` | редактировать |
| GET | `/api/users` | список пользователей (для UserSelect в сценариях) |
| GET | `/api/finance/operations` | для picker операций в реестр |
| GET | `/api/finance/money-accounts` | список счетов |
| GET | `/api/finance/legal-entities` | список юрлиц |
| GET | `/api/finance/op-types` | список типов операций |
| GET | `/api/finance/categories` | список статей ДДС |

**Auth:** cookie `access_token`, все запросы через `api`/`fetcher` из `@/lib/api` с `credentials: "same-origin"`.

---

## Открытые вопросы

**#1 [Backend]** — `GET /api/finance/registries` возвращает `RegistryOut[]` без `total_amount`
(оно только в `RegistryDetailOut`). В листинге реестров показывать «—» в колонке суммы
или загружать `RegistryDetailOut` на каждую строку (дорого)?
**Решение-дефолт:** показывать «—» в листинге, сумму — только на карточке.
Если нужна сумма в листинге — требуется backend-endpoint с агрегатами.

**#2 [Backend]** — Нет `GET /api/finance/approvals/pending-for-me` endpoint.
Inbox строится через N параллельных запросов на сводку. При большом объёме — проблема.
На первой итерации — допустимо (кол-во pending обычно мало). TODO: бэкендовый endpoint.

**#3 [UX]** — Picker операций для реестра: фильтровать по `status=to_pay` или
показывать все расходные (`direction=out`)? Backend разрешает только `direction=out`
с `account_from_id=source_account_id`. Статус `to_pay` — рекомендация UX,
не жёсткое правило backend. Показывать все расходные с этого счёта, подсвечивать `to_pay`.

**#4 [UX]** — Счётчик-бейдж «Согласования» в Sidebar: реализовывать в Итерации 1?
Требует хук с SWR-запросами на listing + approval-summaries. Пропустить если затратно —
пользователь сам зайдёт на страницу.

**#5 [TS]** — `UserSelect` для сценариев: есть ли готовый компонент?
Проверить наличие `@/components/UserSelect` (используется в других местах).
Если нет — сделать inline select с SWR `/api/users`, мультивыбор через чекбоксы.

**#6 [UX]** — Удаление сценария не предусмотрено (нет DELETE в backend). Только `is_active=false` через PATCH. Кнопки «Удалить» нет — только «Архивировать» (PATCH `{is_active: false}`).

**#7 [Data]** — `period_year`/`period_month` в заявке типа salary/commission: показывать select
месяца (Январь..Декабрь) или числовой input? Предпочтительно — select для месяца + input для года.

**#8 [API]** — Endpoint `PATCH /api/finance/approval-scenarios/{id}` существует (проверено в router).
При `stages` в PATCH — полная замена этапов. Фронт должен отправлять весь массив этапов.
