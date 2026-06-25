# ТЗ: Доступ и оргструктура (Settings → Access Control)

**Зачем:** конструктор для admin/director — настройка иерархии отделов, матрицы прав ролей
и политик видимости записей по отделам, опирающийся на IAM-1 (spatie авторитетен) и
заготовленную ветку `VisibilityScope::Department` в `VisibilityResolver`.

**Где в коде:**
- `front/src/pages/AccessControlPage/` (новая страница)
- Маршруты: `/admin/access-control` (хаб) + `/admin/access-control/departments` + `/admin/access-control/roles` + `/admin/access-control/visibility`
- Backend (IAM + Org): `src/app/Domain/Iam/` + `src/app/Domain/Org/`
- Существующие модели: `Department` (`src/app/Domain/Org/Models/Department.php`), `User` (имеет `department_id`, `manager_id`), `Role` enum, `VisibilityScope` enum, `VisibilityResolver`
- Существующие эндпоинты: `GET /api/admin/departments`, `GET /api/admin/users` (список), `PATCH /api/admin/users/{id}` (назначить отдел/руководителя)
- Роутер: `front/src/router/routes/base.ts` (добавить новые маршруты, `roles: ['admin', 'director']`)
- Nav: `front/src/shared/nav/navItems.ts` (добавить в `adminNavItems`)

**Источник фич:** `src/database/seeders/RolePermissionSeeder.php` (матрица permissions),
`src/app/Domain/Iam/Services/VisibilityResolver.php` (ветка Department), `src/app/Domain/Org/Models/Department.php`

---

## Wireframe — Хаб страницы

```
┌─────────────────────────────────────────────────────────────────┐
│ [TopBar]                                                        │
├──────────┬──────────────────────────────────────────────────────┤
│          │ PageHeader: «Доступ и оргструктура»  pi-shield       │
│ Sidebar  │ Subtitle: «Отделы, роли и видимость записей»         │
│          ├──────────────────────────────────────────────────────┤
│ (nav)    │                                                      │
│          │  [Tabs]  Отделы │ Роли и права │ Видимость           │
│          │ ─────────────────────────────────────────────────    │
│          │                                                      │
│          │  [TabPanel активный — переключается по табу]         │
│          │                                                      │
└──────────┴──────────────────────────────────────────────────────┘
```

---

## Таб 1 — Отделы (DepartmentsTab)

### Wireframe

```
┌─────────────────────────────────────────────────────────────────────┐
│ [Row] [ Поиск по названию ····· ]               [+ Добавить отдел]  │
├───────────────────────────────────┬─────────────────────────────────┤
│ Дерево отделов (Tree)             │ Панель отдела (правая панель)    │
│                                   │ ─────────────────────────────── │
│ v Компания (корень)               │ Название *                       │
│   > Отдел продаж     [✏] [🗑]   │ [InputText ···················]  │
│     v Направление А  [✏] [🗑]   │                                  │
│         Менеджер Б   [✏] [🗑]   │ Родительский отдел               │
│   > HR               [✏] [🗑]   │ [Select ···················· v]  │
│   > Финансы          [✏] [🗑]   │                                  │
│                                   │ Руководитель                     │
│ (Tree → TreeNode компоненты)      │ [Select (users list) ········ v] │
│                                   │                                  │
│ Empty state (нет отделов):        │ Сотрудники отдела                │
│   pi-building / «Отделы не       │ [DataTable small: имя / роль]    │
│   созданы» / [+ Добавить отдел]  │   [+ Добавить сотрудника]        │
│                                   │                                  │
│                                   │ [Отмена]  [Сохранить]           │
└───────────────────────────────────┴─────────────────────────────────┘
```

### Org-chart вид (переключатель в шапке таба)

Кнопка-тогл «Дерево / Схема» рядом с поиском. В режиме «Схема» рендерится
`OrgChartView.vue` — горизонтальные узлы (карточки 160×80px) соединённые линиями.
Каждый узел: название отдела, имя руководителя, число сотрудников. Клик на узел —
раскрывает ту же боковую панель. Реализация: рекурсивный компонент на flexbox
(детям — отступ вниз); при более 3 уровней рендерится вертикальный список.
Новый компонент `OrgChartView.vue` — обоснование: `Tree` PrimeVue не поддерживает
визуальный org-chart с карточками-узлами.

### PrimeVue-компоненты

| Компонент | Props / назначение |
|---|---|
| `Tabs` + `TabPanel` | хаб (Отделы / Роли / Видимость) |
| `Tree` (PrimeVue) | дерево отделов; `:value` — рекурсивный массив `TreeNode`; `selectionMode="single"`; `@node-select` → боковая панель |
| `Button` icon=`pi pi-plus` | «Добавить отдел» (primary, справа в шапке) |
| `Button` icon=`pi pi-pencil` | редактировать inline в строке дерева (severity=secondary, text, size=small) |
| `Button` icon=`pi pi-trash` | удалить inline (severity=danger, text, size=small; disabled для root-узла) |
| `InputText` | название отдела в форме |
| `Select` | родительский отдел; `showClear`; опция «— Корень —» (id=null) |
| `Select` | руководитель; опции из `/api/users?per_page=200` |
| `DataTable` size=small | сотрудники отдела в боковой панели |
| `MultiSelect` | добавить сотрудников (открывается из «+ Добавить сотрудника») |
| `ConfirmDialog` | подтверждение удаления отдела (содержит описание последствий) |
| `OrgChartView` (новый) | горизонтальная схема (см. выше) |
| `Skeleton` | загрузка дерева |

---

## Таб 2 — Роли и права (RolesPermissionsTab)

### Wireframe

```
┌─────────────────────────────────────────────────────────────────────┐
│ [Alert info] «admin всегда получает все права — нельзя ограничить»  │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  Группа: CRM                                                        │
│  ┌──────────────────┬────────┬──────────┬────────┬────────┬────────┐│
│  │ Право            │ admin  │ director │ lawyer │manager │accountant││
│  ├──────────────────┼────────┼──────────┼────────┼────────┼────────┤│
│  │ crm.view         │ [✓]   │ [✓]     │ [✓]  │ [✓]  │ [ ]  ││
│  │ crm.manage       │ [✓]   │ [✓]     │ [ ]  │ [✓]  │ [ ]  ││
│  └──────────────────┴────────┴──────────┴────────┴────────┴────────┘│
│                                                                     │
│  Группа: Продажи                                                    │
│  ┌──────────────────┬────────┬──────────┬────────┬────────┬────────┐│
│  │ Право            │ admin  │ director │ lawyer │manager │...     ││
│  │ sales.view       │ [✓]   │ [✓]     │ [✓]  │ [✓]  │ [ ]  ││
│  │ sales.manage     │ [✓]   │ [✓]     │ [ ]  │ [✓]  │ [ ]  ││
│  └──────────────────┴────────┴──────────┴────────┴────────┴────────┘│
│                                                                     │
│  [+ развернуть все группы]              [Сбросить]  [Сохранить]    │
└─────────────────────────────────────────────────────────────────────┘
```

### Описание

Матрица: строки = права (из `RolePermissionSeeder`), сгруппированные по доменам;
столбцы = 6 ролей (admin / director / lawyer / manager / accountant / cfo).
Ячейка = `Checkbox`. Роль `admin` — все чекбоксы checked и disabled (readonly).
Ability-permissions (`admin-write`, `system-reset`, `dedup-scan-all`,
`view-manager-cabinet`) вынесены в отдельную группу «Системные права».

**Группировка прав по доменам:**

| Группа (label) | Права |
|---|---|
| CRM | `crm.view`, `crm.manage` |
| Продажи | `sales.view`, `sales.manage` |
| Договоры | `contracts.view`, `contracts.manage` |
| Пользователи | `users.view`, `users.manage` |
| Автоматизации | `automation.manage` |
| Аналитика | `analytics.view`, `settings.manage` |
| Финансы | `finance.view`, `finance.entry`, `finance.posting`, `finance.journals.manual`, `finance.payments.approve`, `finance.period.close`, `finance.settings.manage`, `finance.reports.management` |
| Системные права | `admin-write`, `dedup-scan-all`, `view-manager-cabinet`, `system-reset` |

**Поведение:** при снятии чекбокса — немедленное визуальное изменение (оптимистик).
Кнопка «Сохранить» активируется при наличии изменений (diff от загруженного состояния).
«Сбросить» — восстановить до загруженного состояния (перечитать из API). После сохранения —
Toast success.

### PrimeVue-компоненты

| Компонент | Props |
|---|---|
| `Message` severity=info | предупреждение про admin |
| `Panel` `:toggleable="true"` `:collapsed="false"` | каждая группа прав |
| `DataTable` | строки прав, столбцы ролей; `:value` — массив строк матрицы; нет пагинации |
| `Column` для каждой роли | `#body` → `Checkbox` |
| `Checkbox` | `:disabled="role==='admin'"` |
| `Button` label=«Сохранить» | primary, справа; `:disabled="!isDirty"` `:loading="saving"` |
| `Button` label=«Сбросить» severity=secondary outlined | рядом с Сохранить |
| `Toast` | успех/ошибка сохранения |

---

## Таб 3 — Видимость (VisibilityScopeTab)

### Wireframe

```
┌─────────────────────────────────────────────────────────────────────┐
│ [Alert warning] «Настройки видимости влияют на то, какие записи    │
│  (сделки, контакты, задачи) видит каждая роль. Изменяйте с         │
│  осторожностью.»                                                    │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  ┌───────────────┬──────────────────────────────────┐              │
│  │ Роль          │ Видимость записей                 │              │
│  ├───────────────┼──────────────────────────────────┤              │
│  │ admin         │ [Select: Все •••••••••••••••• v] │              │
│  │ director      │ [Select: Все •••••••••••••••• v] │              │
│  │ lawyer        │ [Select: Все •••••••••••••••• v] │              │
│  │ manager       │ [Select: Свои ················v] │              │
│  │ accountant    │ [Select: Свои ················v] │              │
│  │ cfo           │ [Select: Свои ················v] │              │
│  └───────────────┴──────────────────────────────────┘              │
│                                                                     │
│  Варианты видимости:                                                │
│  • Все — видит все записи системы                                   │
│  • Отдел (+подотделы) — видит записи своего отдела и дочерних      │
│  • Свои — только записи, где он ответственный                      │
│                                                                     │
│  Примечание: значение «Отдел» работает только если у роли           │
│  назначен отдел в карточке пользователя.                            │
│                                                                     │
│                              [Сбросить]  [Сохранить]               │
└─────────────────────────────────────────────────────────────────────┘
```

### Описание

Таблица 6 строк (по числу ролей). Для каждой роли — `Select` с тремя вариантами:
`all` («Все»), `department` («Отдел (+подотделы)»), `own` («Свои»).
Текущее значение загружается из `GET /api/admin/visibility-config`.
Изменение сохраняется через `PATCH /api/admin/visibility-config` (тело: `{role: scope}`).
После сохранения — Toast success. Роли `admin` / `director` / `lawyer` — Select
с pre-set `all`, незаблокированы (admin может переключить). Есть hint: при выборе
`department` для роли, у которой нет отдела — сервер применит фолбэк `own`.

### PrimeVue-компоненты

| Компонент | Props |
|---|---|
| `Message` severity=warning | предупреждение про влияние |
| `DataTable` | `:value` — массив `{ role, scope }` |
| `Column` «Роль» | `Tag` с severity по роли |
| `Column` «Видимость» | `Select` `:options="scopeOptions"` |
| `Button` «Сохранить» | primary; `:disabled="!isDirty"` `:loading="saving"` |
| `Button` «Сбросить» | secondary outlined |
| `Toast` | успех/ошибка |

---

## Навигация и маршруты

### Новые маршруты (добавить в `front/src/router/routes/base.ts`)

```
/admin/access-control           → AccessControlPage (redirect → /admin/access-control/departments)
/admin/access-control/departments  → AccessControlPage (таб «Отделы»)
/admin/access-control/roles        → AccessControlPage (таб «Роли и права»)
/admin/access-control/visibility   → AccessControlPage (таб «Видимость»)
```

Все маршруты: `meta: { requiresAuth: true, roles: ['admin', 'director'] }`.
Хаб реализован как единая страница с `Tabs` + `useRoute`/`useRouter` для синхронизации
активного таба с URL. Переход между табами — `router.push('/admin/access-control/{tab}')`.

### Добавить в navItems (adminNavItems)

```typescript
{
  key: 'access-control',
  route: '/admin/access-control',
  icon: 'pi pi-shield',
  labelKey: 'nav.accessControl',
  adminOnly: true,
}
```

---

## Эндпоинты

### Существующие (используются как есть)

| Метод | Путь | Назначение |
|---|---|---|
| `GET` | `/api/admin/departments` | список отделов (flat; FE строит дерево) |
| `GET` | `/api/admin/users` | список пользователей (для `Select` руководителя и сотрудников) |
| `PATCH` | `/api/admin/users/{id}` | смена `department_id` / `manager_id` сотруднику |

### Новые (требуется реализация backend)

| Метод | Путь | Назначение | Авторизация |
|---|---|---|---|
| `POST` | `/api/admin/departments` | создать отдел | `can:admin-write` |
| `PATCH` | `/api/admin/departments/{id}` | переименовать / сменить родителя / руководителя | `can:admin-write` |
| `DELETE` | `/api/admin/departments/{id}` | удалить (детей пересадить в parent или null) | `can:admin-write` |
| `POST` | `/api/admin/departments/{id}/members` | добавить сотрудников в отдел (bulk `user_ids`) | `can:admin-write` |
| `DELETE` | `/api/admin/departments/{id}/members/{userId}` | убрать сотрудника из отдела | `can:admin-write` |
| `GET` | `/api/admin/roles/permissions` | матрица роль×право из spatie | `can:admin-write` |
| `PUT` | `/api/admin/roles/{role}/permissions` | перезаписать set прав роли (кроме admin) | `can:admin-write` |
| `GET` | `/api/admin/visibility-config` | текущий `{role: scope}` для всех ролей | `can:admin-write` |
| `PATCH` | `/api/admin/visibility-config` | обновить scope для одной или нескольких ролей | `can:admin-write` |

Примечание по `visibility-config`: scope хранится в config или отдельной таблице
`role_visibility_configs (id, role, scope, created_at, updated_at)` — решение backend.
VisibilityResolver читает оттуда вместо жёстко прошитого `VisibilityScope::forRole()`.

---

## States (состояния)

### Загрузка

- Дерево: `Skeleton` три строки высотой 20px с отступом-иерархией
- Матрица прав: `Skeleton` таблица 8 строк × 6 столбцов
- Таб видимости: `Skeleton` 6 строк

### Пустое состояние (таб Отделы, нет ни одного отдела)

```
[pi-building 48px, opacity 0.3]
Отделы не созданы
Добавьте первый отдел, чтобы настроить оргструктуру
[Кнопка «+ Добавить отдел»]
```

### Ошибка загрузки (любой таб)

```
[pi-exclamation-circle 48px, danger muted]
Не удалось загрузить данные
[Кнопка «Повторить» severity=secondary]
```
Дополнительно — Toast `severity="error"` с текстом ошибки API.

### Ошибка сохранения

Toast `severity="error"` с сообщением из ответа сервера (первая validation error или
общий текст «Ошибка сохранения»). Форма остаётся открытой, данные не теряются.

### 403 (не admin/director)

Страница не рендерится — router guard редиректит на `/dashboard`.
Если guard пропустил — `Message severity="error"` с текстом «Доступ запрещён» на всю
рабочую зону.

---

## Interactions — таблица элемент→действие→результат

| Элемент | Действие | Результат | Endpoint |
|---|---|---|---|
| Кнопка «+ Добавить отдел» | click | боковая панель открывается в режиме create; форма пустая | — |
| `TreeNode` (строка дерева) | click | боковая панель открывается с данными выбранного отдела | `GET /api/admin/departments` (уже загружено) |
| Кнопка «✏» (редактировать) | click | то же, что клик на узел | — |
| Кнопка «🗑» (удалить) | click | `ConfirmDialog`: «Удалить отдел "X"? Дочерние отделы и сотрудники останутся без родителя.» → «Удалить» | `DELETE /api/admin/departments/{id}` |
| Форма отдела → «Сохранить» | click | валидация (name required); `loading` на кнопке; обновление дерева; боковая панель закрывается | `POST` или `PATCH /api/admin/departments/{id}` |
| Форма отдела → «+ Добавить сотрудника» | click | `MultiSelect` открывается поверх таблицы сотрудников; выбор → `POST /api/admin/departments/{id}/members` | `POST /api/admin/departments/{id}/members` |
| Удалить сотрудника из таблицы | click `pi-times` в строке | confirm inline (tooltip «Убрать из отдела?») → `DELETE` | `DELETE /api/admin/departments/{id}/members/{userId}` |
| Тогл «Дерево / Схема» | click | смена вида (Tree ↔ OrgChartView); url не меняется | — |
| Чекбокс в матрице прав | change | оптимистик-update локального state; флаг `isDirty = true` | — (батч при «Сохранить») |
| «Сохранить» (матрица) | click | `loading`; `PUT /api/admin/roles/{role}/permissions` для каждой роли с изменёнными правами (параллельные запросы); Toast success/error | `PUT /api/admin/roles/{role}/permissions` |
| «Сбросить» (матрица) | click | локальный state → snapshot при загрузке; `isDirty = false` | — |
| `Select` видимости | change | локальный state; `isDirty = true` | — (батч при «Сохранить») |
| «Сохранить» (видимость) | click | `PATCH /api/admin/visibility-config` тело `{admin:'all', director:'all', ...}`; Toast success/error; VisibilityResolver начинает читать из новой конфигурации | `PATCH /api/admin/visibility-config` |

---

## Поведение в темах (light / dark)

| Зона | Light | Dark |
|---|---|---|
| Фон страницы | `var(--p-surface-ground)` (`$mg-gray-100`) | `var(--p-surface-ground)` (инвертированный) |
| Карточки/панели | `var(--p-surface-card)` (белый) | `var(--p-surface-card)` (тёмный) |
| Дерево отделов | стандартный `Tree` PrimeVue | стандартный `Tree` PrimeVue dark |
| Матрица — шапка таблицы | `var(--p-datatable-header-background)` | то же |
| Checkbox checked | `var(--p-primary-color)` (`#172747`) | `var(--p-primary-color)` (светлый в dark preset) |
| Alert info / warning | `severity` prop — PrimeVue `Message` адаптируется автоматически | то же |
| OrgChart узел | `var(--p-surface-card)`, border `var(--p-content-border-color)` | auto |

Бренд-инварианты: sidebar и хедер страницы сделки — всегда `#172747`.
Хаб AccessControl — обычная страница, не инвариантная.

---

## Композиция (файловая структура фронта)

```
front/src/pages/AccessControlPage/
├── index.ts                         ← реэкспорт
├── index.vue                        ← хаб: PageHeader + Tabs + router-sync
├── components/
│   ├── DepartmentsTab.vue           ← таб отделов
│   ├── DepartmentTree.vue           ← Tree-обёртка с inline-кнопками
│   ├── DepartmentSidePanel.vue      ← форма отдела (Drawer правый или side panel)
│   ├── OrgChartView.vue             ← горизонтальная схема (новый компонент)
│   ├── RolesPermissionsTab.vue      ← таб матрицы прав
│   ├── PermissionMatrix.vue         ← DataTable с Checkbox-ячейками
│   └── VisibilityScopeTab.vue       ← таб видимости
└── composables/
    ├── useDepartments.ts            ← CRUD отделов
    ├── useDepartmentMembers.ts      ← члены отдела
    ├── useRolesPermissions.ts       ← загрузка/сохранение матрицы
    └── useVisibilityConfig.ts       ← загрузка/сохранение visibility-config
```

Используем Drawer (PrimeVue) position=`right` шириной 420px для `DepartmentSidePanel`
(аналогично фильтр-панели в `ContactsPage`). Drawer закрывается по «Отмена» и после
успешного сохранения. `:show-close-icon="false"` — крест убран, закрытие только через
кнопки формы (паттерн Волны 2).

---

## i18n-ключи

```json
{
  "nav": {
    "accessControl": "Доступ и оргструктура"
  },
  "accessControl": {
    "page": {
      "title": "Доступ и оргструктура",
      "subtitle": "Отделы, роли и видимость записей"
    },
    "tabs": {
      "departments": "Отделы",
      "roles": "Роли и права",
      "visibility": "Видимость"
    },
    "departments": {
      "addDepartment": "Добавить отдел",
      "editDepartment": "Редактировать отдел",
      "deleteDepartment": "Удалить отдел",
      "deleteConfirm": "Удалить отдел «{name}»? Дочерние отделы и сотрудники останутся без родителя.",
      "nameLabel": "Название",
      "parentLabel": "Родительский отдел",
      "parentRoot": "— Корень —",
      "managerLabel": "Руководитель",
      "membersLabel": "Сотрудники отдела",
      "addMember": "Добавить сотрудника",
      "removeMember": "Убрать из отдела",
      "viewTree": "Дерево",
      "viewChart": "Схема",
      "empty": "Отделы не созданы",
      "emptyHint": "Добавьте первый отдел, чтобы настроить оргструктуру",
      "saveSuccess": "Отдел сохранён",
      "deleteSuccess": "Отдел удалён",
      "errorLoad": "Не удалось загрузить отделы",
      "errorSave": "Ошибка сохранения отдела",
      "errorDelete": "Ошибка удаления отдела"
    },
    "roles": {
      "title": "Матрица прав",
      "adminNote": "Роль admin всегда получает все права и не может быть ограничена.",
      "groupCrm": "CRM",
      "groupSales": "Продажи",
      "groupContracts": "Договоры",
      "groupUsers": "Пользователи",
      "groupAutomation": "Автоматизации",
      "groupAnalytics": "Аналитика",
      "groupFinance": "Финансы",
      "groupSystem": "Системные права",
      "save": "Сохранить",
      "reset": "Сбросить",
      "saveSuccess": "Права ролей сохранены",
      "errorLoad": "Не удалось загрузить матрицу прав",
      "errorSave": "Ошибка сохранения прав"
    },
    "visibility": {
      "title": "Видимость записей",
      "warning": "Настройки видимости влияют на то, какие записи (сделки, контакты, задачи) видит каждая роль. Изменяйте с осторожностью.",
      "scopeAll": "Все",
      "scopeDepartment": "Отдел (+подотделы)",
      "scopeOwn": "Свои",
      "roleColumn": "Роль",
      "scopeColumn": "Видимость записей",
      "departmentHint": "Значение «Отдел» работает только если у пользователя указан отдел.",
      "save": "Сохранить",
      "reset": "Сбросить",
      "saveSuccess": "Настройки видимости сохранены",
      "errorLoad": "Не удалось загрузить конфигурацию видимости",
      "errorSave": "Ошибка сохранения настроек видимости"
    }
  },
  "roles": {
    "admin": "Администратор",
    "director": "Директор",
    "lawyer": "Юрист",
    "manager": "Менеджер",
    "accountant": "Бухгалтер",
    "cfo": "CFO"
  }
}
```

EN-задел (ключи идентичны, значения переведены при необходимости в `en.json`).

---

## Поэтапный план реализации (BE → FE)

### Фаза A — Backend: Departments CRUD

1. `DepartmentController` расширить: добавить `store`, `update`, `destroy`.
2. `POST /api/admin/departments` — создать (`name`, `parent_id?`, `manager_id?`).
3. `PATCH /api/admin/departments/{id}` — обновить.
4. `DELETE /api/admin/departments/{id}` — удалить (детей пересадить в `parent_id` удаляемого или null).
5. `POST /api/admin/departments/{id}/members` (`user_ids[]`) — bulk `UPDATE users SET department_id={id}`.
6. `DELETE /api/admin/departments/{id}/members/{userId}` — `UPDATE users SET department_id=null`.
7. Авторизация: `can:admin-write` на все write-глаголы.
8. Tests: PHPUnit Feature (SQLite :memory:) для всех 6 endpoint'ов.

### Фаза B — Backend: Roles Permissions API

1. `GET /api/admin/roles/permissions` → возвращает `{role: [permissions]}` из spatie DB.
2. `PUT /api/admin/roles/{role}/permissions` → `SpatieRole::syncPermissions([...])`.
3. Запрет на изменение роли `admin` (422 с сообщением).
4. Авторизация: `can:admin-write`.
5. Tests.

### Фаза C — Backend: Visibility Config

1. Создать миграцию `role_visibility_configs (id, role varchar, scope varchar, updated_at)`.
2. Сидер: заполнить дефолтами из `VisibilityScope::forRole()`.
3. `GET /api/admin/visibility-config` → `{role: scope}`.
4. `PATCH /api/admin/visibility-config` → upsert.
5. Обновить `VisibilityResolver::resolve()` → читать из БД (кешировать в Redis, TTL 5 min).
6. Авторизация: `can:admin-write`.
7. Tests.

### Фаза D — Frontend: DepartmentsTab

1. Создать `front/src/pages/AccessControlPage/` (структура выше).
2. `useDepartments.ts` — CRUD composable (TanStack Query или vue-query / useQuery-like); строит `TreeNode[]` из flat-списка через рекурсию.
3. `DepartmentTree.vue` + `DepartmentSidePanel.vue` + `OrgChartView.vue`.
4. Роутер + navItems.
5. i18n ключи (ru.json).

### Фаза E — Frontend: RolesPermissionsTab

1. `useRolesPermissions.ts` — загрузка матрицы + diff-tracking + save.
2. `PermissionMatrix.vue` — DataTable + Checkbox per cell.
3. Группировка прав константой `PERMISSION_GROUPS` в файле composable.

### Фаза F — Frontend: VisibilityScopeTab

1. `useVisibilityConfig.ts` — загрузка + сохранение.
2. `VisibilityScopeTab.vue` — DataTable + Select per row.
3. Активация Department-ветки в `VisibilityResolver` (фаза C BE делает это автоматически).

---

## Vizion-эталон

Структура хаба (Tabs + боковая панель формы): `./examples/vizion/front/src/pages/SettingsPage/`
(аналог Settings с вкладками). Inline-редактирование в дереве: паттерн из
`./examples/vizion/front/src/pages/PipelineSettingsPage/` (ряды с action-кнопками).
DataTable с Checkbox: `./examples/vizion/front/src/pages/` — нет прямого аналога матрицы
прав; Checkbox в ячейке — стандартная практика PrimeVue DataTable.

---

## Открытые вопросы

1. **Хранилище visibility-config** — таблица в БД (рекомендуется, с Redis-кешем) или
   JSON в `storage/app/visibility.json`? Таблица даёт audit trail и атомарные транзакции.
   Решение оставляем backend-специалисту.

2. **Глубина дерева отделов** — ограничить ли максимальную вложенность (например, 5 уровней)?
   Без ограничения OrgChartView деградирует в список. Рекомендую: предупреждение в UI при
   глубине > 4, без жёсткого запрета.

3. **Синхронизация `users.manager_id` vs `departments.manager_id`** — поле `manager_id` есть
   на обеих моделях. При назначении руководителя отдела обновлять ли `users.manager_id`
   у всех членов отдела автоматически? Требует решения product-manager.

4. **Директ-линк руководитель** — должен ли руководитель отдела автоматически получать
   scope `Department`? Сейчас VisibilityResolver смотрит только на роль, не на `manager_id`.
   Это изменение логики BE — решение на product-manager + backend-специалист.

5. **Audit log изменений прав** — нужна ли запись в `entity_logs` при изменении permissions?
   Рекомендую: да (чувствительные операции), но объём реализации — backend-специалист.
