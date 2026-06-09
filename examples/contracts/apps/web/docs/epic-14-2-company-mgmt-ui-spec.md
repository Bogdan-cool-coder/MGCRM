# ТЗ: Эпик 14.2 — Company Management (Увольнение / Передача прав / График / Отпуска)

**Версия:** 1.0
**Дата:** 2026-06-02
**Автор:** designer
**Исполнитель:** frontend-specialist
**Статус:** READY FOR IMPLEMENTATION

---

## Cover

### Цель

Закрыть системную дыру: сейчас удаление сотрудника ломает FK-связи (контрагенты, сделки, задачи
остаются с `NULL` owner). Эпик 14.2 вводит полный флоу управления жизненным циклом сотрудника:
soft-dismissal с substitute reassign, audit-log передачи прав, график работы на уровне отдела,
отпуска с автоматическим re-route на substitute, производственный календарь и slot-picker
для встреч.

### Референс

MACRO Digital (`docs.macrodigital.ru/platforma-macro/`) — разделы «Управление компанией»
(4 из 12 доступных страниц на момент research 2026-06-02). Остальные slugs недоступны (404) —
при появлении возможности провести second-pass research.

### Что добавляем

| Область | Что делаем |
|---|---|
| `/company/employees` | Новая страница — расширенный список сотрудников со статусами |
| `/company/rights-transfers` | Новая страница — audit log передачи прав |
| `/company/schedules` | Новая страница — графики работы по отделам |
| `/company/absences` | Новая страница — AbsenceCalendar (Gantt-like) |
| `/me/vacations` | Новая страница — самообслуживание отпусков |
| `components/Company/` | Новая папка: 12 компонентов |
| `Sidebar.tsx` | Новые пункты в ADMIN_ITEMS + ссылка в profile-секции |
| Activity card (Timeline/Tasks) | Индикатор «исполнитель в отпуске» + CTA «Переназначить» |

### Что НЕ меняем

Страница `/admin/users` остаётся как есть — она про создание учётных записей.
`/company/employees` — про управление HR-статусами.

### Зависимости

- **Эпик 24** (Задачник): SlotPickerModal встраивается в `TaskCreateModal` при `kind=meeting` —
  компонент `SlotPickerModal` проектируем здесь, но интеграция в `TaskCreateModal` происходит
  в момент реализации Эпика 24.
- **Эпик 21** (Notifications): при успешном dismiss → `notification_kind = "employee_dismissed"`
  уходит substitute + руководителю. Backend-вызов `send(user_id, kind, payload)` ожидается
  от notifications-сервиса; в UI достаточно показать inline-success после POST.

### Backend-зависимости (требуется от backend-specialist)

Все 9 разделов ТЗ опираются на миграции и эндпоинты, описанные в MARATHON-3 Master Plan (Эпик 14.2):

- **Миграция 0058** — `users.employment_status`, `dismissed_at`, `substitute_user_id`
- **Миграция 0059** — `rights_transfer_logs`, `rights_transfer_items`
- **Миграция 0060** — `work_schedules`, `user_vacations`, `production_calendar`
- Эндпоинты: `POST /api/admin/users/{id}/dismiss`, `POST /api/admin/users/{id}/restore`,
  `POST /api/admin/rights-transfers`, `GET /api/admin/rights-transfers`,
  `POST + PATCH + DELETE /api/me/vacations`, `GET /api/admin/vacations/calendar`,
  `GET /api/users/{id}/available-slots`, `PATCH /api/admin/work-schedules/bulk`

---

## Раздел 1. Страница `/company/employees`

### Зачем

Дать администратору единую точку управления HR-статусами: уволить сотрудника с корректным
переносом данных, восстановить, передать права, посмотреть кто в отпуске и его substitute.

### Где в коде

- Страница: `apps/web/src/app/(app)/company/employees/page.tsx`
- Компоненты:
  - `apps/web/src/components/Company/EmployeesTable.tsx`
  - `apps/web/src/components/Company/EmployeeStatusBadge.tsx`
  - `apps/web/src/components/Company/EmployeeRowActions.tsx`

### Wireframe

```
┌─────────────────────────────────────────────────────────────────┐
│ [Sidebar]  │ Сотрудники                         [+ Добавить]   │
│            ├─────────────────────────────────────────────────── │
│  SALES     │                                                     │
│  CS        │ [Активные] [В отпуске] [Уволенные] [Все]          │
│  АНАЛИТ.   │ [Поиск по имени или email…]  [Отдел v]            │
│            │                                                     │
│  КОМАНДА   │ ┌──────────────────────────────────────────────┐   │
│  Отделы    │ │ ФИО    Отдел  Руководитель  Статус  Substitute│   │
│  Видим.    │ ├──────────────────────────────────────────────┤   │
│ >Сотрудники│ │ Иван…  Продажи  Марина В.  [active]   —      │ … │
│  Передача  │ ├──────────────────────────────────────────────┤   │
│  Графики   │ │ Лера…  CS       Олег Т.   [vacation] Иван П. │ … │
│  Отсутствия│ ├──────────────────────────────────────────────┤   │
│            │ │ Дима…  Продажи  Марина В.  [dismissed] —     │ … │
│  ПРОФИЛЬ   │ └──────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────┘
```

### Фильтры и поиск

- **Status tabs** (горизонтальная группа кнопок, `btn-ghost` неактивная / `btn-secondary`
  активная):
  `Активные` | `В отпуске` | `Уволенные` | `Все`
- **Поиск** — `<input className="input" placeholder="Поиск по имени или email…" />` с
  debounce 300 мс, фильтрация на клиенте по уже загруженному списку.
- **Фильтр Отдел** — `<select className="input">` со списком из `GET /api/departments`.

### Колонки таблицы

| Колонка | Описание |
|---|---|
| ФИО | `Avatar` (24 px) + `full_name`. Click на строку → `openEdit` (существующий modal `/admin/users`) |
| Отдел | название отдела или «—» |
| Руководитель | `full_name` руководителя или «—» |
| Статус | `EmployeeStatusBadge` (см. ниже) |
| Substitute | `full_name` substitute или «—» (показывается только если статус `on_vacation` или `dismissed`) |
| Actions | `EmployeeRowActions` — иконка `bi-three-dots-vertical`, dropdown |

### EmployeeStatusBadge

```
active      → badge bg-success/10 text-success    «Активен»
on_vacation → badge bg-warning/10 text-warning    «В отпуске»
dismissed   → badge bg-danger/10  text-danger     «Уволен»
```

Класс `badge` + цвет через `bg-*/10 text-*`.

### EmployeeRowActions (3-dot menu)

Dropdown из `bg-white dark:bg-gray-800 shadow-lg rounded-md py-1 min-w-[180px]`.
Показывать только допустимые для текущего статуса пункты:

| Пункт | Иконка | Условие показа |
|---|---|---|
| Редактировать | `bi-pencil` | всегда |
| Уволить | `bi-person-x` | только `active` |
| Восстановить | `bi-person-check` | только `dismissed` |
| Передать права | `bi-arrow-left-right` | только `active` |
| Расписание | `bi-calendar-week` | всегда |

Destructive-пункт «Уволить» — `text-danger hover:bg-danger/10`.

### SWR-ключ

`GET /api/admin/users/employees` — новый эндпоинт, возвращает расширенный список
с полями `employment_status`, `substitute_user_id`, `substitute_name`.

Если backend пока не готов — fallback на `GET /api/users` с `employment_status = "active"` по
умолчанию для всех записей.

### States

**Loading:** skeleton 5 строк таблицы (`div.animate-pulse h-10 bg-gray-100 rounded mb-1`).
**Empty (нет по фильтру):** `card` по центру, иконка `bi-people text-4xl text-gray-300`,
  «Нет сотрудников», «Попробуй другой фильтр».
**Empty (первый запуск):** иконка `bi-people-fill`, «Пока нет сотрудников»,
  кнопка `btn-primary` «Добавить сотрудника» → `/admin/users` (открывает modal создания).
**Error:** `div.text-danger.text-sm` над таблицей «Не удалось загрузить список сотрудников».

### Адаптивность

Desktop-first. Mobile — TBD (эпик 10), сейчас таблица скроллится горизонтально.

---

## Раздел 2. EmployeeDismissModal

### Зачем

Корректно уволить сотрудника: назначить substitute, выбрать что переносить, указать причину,
подтвердить — всё в одном 4-шаговом wizard в нашем `Modal`.

### Где в коде

`apps/web/src/components/Company/EmployeeDismissModal.tsx`

Используется из `EmployeeRowActions` при клике «Уволить».

### Wireframe

```
┌─── Увольнение: Иван Петров ─────────────────── [x] ──┐
│                                                        │
│  [1 Substitute] ──── [2 Что передать] ──── [3 Причина]│
│        ●                    ○                   ○      │
│                                                        │
│  Шаг 1 — Substitute                                   │
│  ┌──────────────────────────────────────────────────┐ │
│  │ Выбери кто заменит уволенного                    │ │
│  │ [UserSelect ─── Марина Васильева v]              │ │
│  │                                                   │ │
│  │ ℹ На этого человека перейдут все незавершённые   │ │
│  │   задачи, контакты и сделки Ивана                │ │
│  └──────────────────────────────────────────────────┘ │
│                                                        │
│  [Отмена]                           [Далее →]         │
└────────────────────────────────────────────────────────┘
```

### Stepper

Горизонтальный, 3 шага, в верхней части body модала (не в header).
Реализуется инлайн в компоненте — общего `Stepper` нет.

```tsx
// псевдоструктура
const steps = [
  { id: 1, label: "Substitute" },
  { id: 2, label: "Что передать" },
  { id: 3, label: "Причина" },
]
```

Активный шаг: `w-6 h-6 rounded-full bg-primary text-white text-xs flex items-center justify-center`.
Пройденный: `bg-success text-white` + `bi-check`.
Неактивный: `bg-gray-200 text-gray-500`.
Соединитель: `h-px flex-1 bg-gray-200` (активный → `bg-primary`).

### Шаг 1 — Substitute

- Лейбл: «Кто заменит» (required, `*`)
- `UserSelect` — исключить уволенных (`employment_status != "dismissed"`), исключить самого
  увольняемого.
- Help text под select: `«На этого человека перейдут все незавершённые задачи, контакты
  и сделки {full_name}`»
- Валидация: если substitute не выбран — показать `text-danger text-sm` «Выбери substitute»
  при попытке перейти к шагу 2.

### Шаг 2 — Что передать

Заголовок: «Выбери что передать substitute»

**6 чекбоксов** (все checked по умолчанию):

```
☑  Контакты                 responsible_user_id = уволенный
☑  Сделки                   owner_user_id = уволенный
☑  Задачи как постановщик   created_by_user_id = уволенный
☑  Задачи как исполнитель   responsible_user_id = уволенный
☑  Согласования             approver_user_id = уволенный (в статусе pending)
☑  Настройки и конфиги      automation creators, visibility rules
```

Стиль чекбоксов: нативный `<input type="checkbox" className="mr-2" />` + `<label>` в обёртке
`flex items-center gap-2 py-2 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 px-2 rounded`.

**Preview-блок** под чекбоксами (загружается через `GET /api/admin/users/{id}/transfer-preview`):

```
┌──────────────────────────────────────────┐
│ Будет передано:                          │
│  23 контакта   8 сделок   15 задач      │
│  3 согласования   2 конфига             │
└──────────────────────────────────────────┘
```

`card bg-gray-50 dark:bg-gray-700/50 rounded p-3 mt-3 grid grid-cols-3 gap-2 text-sm`.
Цифры — `font-semibold text-primary`.
Loading preview: inline «Считаем…» + `animate-pulse`.
Если preview недоступен (backend ещё не готов) — скрываем блок, не блокируем флоу.

### Шаг 3 — Причина

- `<textarea className="input" rows={4} placeholder="Опционально: укажи причину увольнения…" />`
- Под textarea: серый hint «Причина сохраняется в журнале и видна только администраторам»
- Кнопка «Уволить» — `btn-primary` c `text-danger` оттенком:
  `bg-danger hover:bg-danger/90 text-white`

Перед отправкой — inline confirm в теле modal (без отдельного modal поверх):
```
┌─────────────────────────────────────────────────┐
│  ⚠ Уволить {full_name}?                         │
│  После увольнения войти в систему будет          │
│  невозможно. Данные перейдут к {substitute}.     │
│                                                  │
│  [Отмена]              [Уволить сотрудника]      │
└─────────────────────────────────────────────────┘
```

`bg-danger/10 border border-danger/30 rounded p-4` + кнопка `bg-danger text-white`.

### POST payload

```json
POST /api/admin/users/{user_id}/dismiss
{
  "substitute_user_id": 42,
  "transfer_categories": ["contacts", "deals", "tasks_assignee", "tasks_creator", "approvals", "configs"],
  "reason": "текст или null"
}
```

### After success

1. Закрыть modal.
2. `mutate()` SWR-ключ `/admin/users/employees`.
3. Inline-success: зелёная полоска поверх таблицы `bg-success/10 text-success px-4 py-2 text-sm`
   «Иван Петров уволен. Данные переданы Марине Васильевой.» — исчезает через 5 секунд.

### States

**Saving (шаг 3 → confirm):** кнопка `disabled` + текст «Увольняем…»
**Error:** `div.text-danger.text-sm bg-danger/10 px-3 py-2 rounded` под кнопкой.

### Доступ

Только роли `admin`, `director`. `RoleGate` на кнопку «Уволить» в `EmployeeRowActions`.

---

## Раздел 3. RestoreUserModal

### Зачем

Восстановить ранее уволенного сотрудника с выбором новой роли.

### Где в коде

`apps/web/src/components/Company/RestoreUserModal.tsx`

### Wireframe

```
┌─── Восстановить сотрудника ──────────────── [x] ──┐
│                                                     │
│  Восстановить Дмитрия Кузнецова?                   │
│  Сотрудник снова получит доступ в систему.         │
│                                                     │
│  Новая роль *                                       │
│  [select: Менеджер v]                              │
│                                                     │
│  [Отмена]                      [Восстановить]      │
└─────────────────────────────────────────────────────┘
```

Размер: `width="sm"` (наш `Modal`).

### Поля

- `<SelectField label="Новая роль" required />` — options из `RoleLabels`: admin/director/lawyer/manager.
- Validation: роль обязательна.

### POST payload

```json
POST /api/admin/users/{user_id}/restore
{
  "new_role": "manager"
}
```

### After success

Закрыть → mutate → inline-success «{ФИО} восстановлен как {роль}».

### States

Saving: `disabled` + «Восстанавливаем…».
Error: inline `text-danger`.

---

## Раздел 4. RightsTransferModal (передача без увольнения)

### Зачем

Передать права конкретного сотрудника другому человеку без увольнения — например, при смене
ответственного по воронке, при декрете или временной передаче клиентской базы.

### Где в коде

`apps/web/src/components/Company/RightsTransferModal.tsx`

### Wireframe

```
┌─── Передача прав: Лера Иванова ─────────── [x] ──┐
│                                                    │
│  Кому передать *                                  │
│  [UserSelect: Выбери получателя v]                │
│                                                    │
│  Что передать (выбери категории)                  │
│  ☑ Контакты    ☑ Сделки    ☑ Задачи (исполнитель) │
│  ☐ Согласования            ☐ Настройки             │
│                                                    │
│  Причина передачи                                 │
│  [textarea…]                                      │
│                                                    │
│  [Отмена]                   [Передать права]      │
└────────────────────────────────────────────────────┘
```

Размер: `width="md"`.

Те же 6 категорий, что в `EmployeeDismissModal`, но не все checked по умолчанию (только
«Контакты» и «Сделки»).

### POST payload

```json
POST /api/admin/rights-transfers
{
  "from_user_id": 17,
  "to_user_id": 42,
  "categories": ["contacts", "deals"],
  "reason": "Переход клиентской базы при ротации"
}
```

### After success

Закрыть → mutate → inline-success.

---

## Раздел 5. Страница `/company/rights-transfers` (audit log)

### Зачем

Полный журнал всех передач прав: кто, кому, когда, что именно, можно ли откатить.

### Где в коде

- Страница: `apps/web/src/app/(app)/company/rights-transfers/page.tsx`
- Компоненты:
  - `apps/web/src/components/Company/RightsTransferTable.tsx`
  - `apps/web/src/components/Company/RightsTransferViewModal.tsx`
  - `apps/web/src/components/Company/RightsTransferRevertModal.tsx`

### Wireframe

```
┌──────────────────────────────────────────────────────────────────┐
│ Передача прав                                                     │
├──────────────────────────────────────────────────────────────────┤
│ [От: UserSelect v]  [Кому: UserSelect v]  [Период: date range]  │
│ [Статус: Все v]                                                  │
├──────────────────────────────────────────────────────────────────┤
│ От → К     │ Категории  │ Причина │ Кто инициировал │ Когда │ Кол-во │ Статус │ Actions │
├────────────┼────────────┼─────────┼─────────────────┼───────┼────────┼────────┼─────────┤
│ [av] Иван  │ [chip]     │ При     │ Марина (admin)  │ 1 ч.  │   23   │[badge] │ Смотреть│
│   → Марина │ Контакты   │ уволь.  │                 │ назад │        │        │ Откатить│
└──────────────────────────────────────────────────────────────────┘
```

### Фильтры

- «От:» — `UserSelect` (placeholder «Любой отправитель»), `value=""` = нет фильтра
- «Кому:» — `UserSelect` (placeholder «Любой получатель»)
- «Период» — два `<input type="date" className="input" />` с лейблом «с» / «по»
- «Статус» — `<select className="input">`:
  - `all` — «Все»
  - `reversible` — «Можно откатить»
  - `reverted` — «Откатано»

Применяются как query-параметры: `GET /api/admin/rights-transfers?from_user_id=&to_user_id=&since=&until=&status=`

### Колонки таблицы

| Колонка | Описание |
|---|---|
| От → К | `Avatar` 24 px + `full_name` → `Avatar` 24 px + `full_name` с `bi-arrow-right mx-2 text-gray-400` |
| Категории | chips: `badge bg-info/10 text-info text-xs` для каждой категории |
| Причина | truncated 60 символов, tooltip с полным текстом |
| Кто инициировал | `full_name` + роль маленьким серым текстом |
| Когда | relative time («2 ч. назад», «3 дня назад») + full datetime в tooltip |
| Записей | число (кол-во `rights_transfer_items`) |
| Статус | `badge`: `reversible` → `bg-info/10 text-info «Активен»`; `reverted` → `bg-gray-100 text-gray-500 «Откатан»` |
| Действия | `btn-ghost text-sm` «Смотреть» + если `reversible` → `btn-ghost text-danger text-sm` «Откатить» |

### RightsTransferViewModal

Размер `width="lg"`. Показывает:
- Заголовок: «Передача прав · {дата}»
- Мета: От → К, категории, инициатор, причина
- Таблица `rights_transfer_items`:
  | entity_type | entity_id | Название | Было | Стало |
- Пагинация если строк > 20 (клиентская, slice).

### RightsTransferRevertModal

Размер `width="sm"`.

```
┌── Откатить передачу прав? ──────────────────┐
│                                             │
│ Восстановится прежний owner для            │
│ 23 записей (контакты и сделки).            │
│                                             │
│ [Отмена]           [Откатить]              │
└─────────────────────────────────────────────┘
```

`POST /api/admin/rights-transfers/{id}/revert`

### States

**Loading:** skeleton 5 строк.
**Empty:** `card` центрированный, `bi-arrow-left-right text-4xl text-gray-300`, «Передач прав пока нет».
**Error:** inline `text-danger` над таблицей.

---

## Раздел 6. Страница `/company/schedules` (графики работы)

### Зачем

Настроить рабочее расписание на уровне отдела: когда работает, когда нет, длина слота встречи.
Это основа для SlotPicker (Раздел 9).

### Где в коде

- Страница: `apps/web/src/app/(app)/company/schedules/page.tsx`
- Компоненты:
  - `apps/web/src/components/Company/DepartmentScheduleEditor.tsx`
  - `apps/web/src/components/Company/WorkDayRow.tsx`

### Wireframe

```
┌──────────────────────────────────────────────────────────────────┐
│ График работы                          [Сохранить все изменения] │
├──────────────────────────────────────────────────────────────────┤
│                                                                   │
│ ▼ Отдел продаж                                                   │
│ ┌────────────────────────────────────────────────────────────┐  │
│ │ День        │ Рабочий │ Начало  │ Конец  │ Слот (мин)     │  │
│ ├─────────────┼─────────┼─────────┼────────┼────────────────┤  │
│ │ Понедельник │  [☑]   │ [09:00] │[18:00] │  [30]          │  │
│ │ Вторник     │  [☑]   │ [09:00] │[18:00] │  [30]          │  │
│ │ Среда       │  [☑]   │ [09:00] │[18:00] │  [30]          │  │
│ │ Четверг     │  [☑]   │ [09:00] │[18:00] │  [30]          │  │
│ │ Пятница     │  [☑]   │ [09:00] │[18:00] │  [30]          │  │
│ │ Суббота     │  [☐]   │  —      │  —     │   —            │  │
│ │ Воскресенье │  [☐]   │  —      │  —     │   —            │  │
│ └────────────────────────────────────────────────────────────┘  │
│  [+ Добавить исключение для сотрудника]                         │
│                                                                   │
│ ▶ Отдел CS                                                       │
│ ▶ Юридический отдел                                             │
└──────────────────────────────────────────────────────────────────┘
```

### Аккордеон отделов

По умолчанию: первый отдел раскрыт, остальные свёрнуты.
Toggle — кнопка-header `flex items-center gap-2 w-full py-3 font-semibold text-primary hover:bg-gray-50 dark:hover:bg-gray-700 px-3 rounded`.
Иконка: `bi-chevron-down` (раскрытый) / `bi-chevron-right` (свёрнутый).

Состояние аккордеона хранится в local state массивом раскрытых `department_id`.

### WorkDayRow (строка для одного дня)

Каждый из 7 дней — строка `WorkDayRow`:

- **День** — текст (`Понедельник` ... `Воскресенье`), ширина `w-32`
- **Рабочий** — `<input type="checkbox" />` `is_working`
- **Начало** — `<input type="time" className="input w-28" />`, disabled если `!is_working`
- **Конец** — `<input type="time" className="input w-28" />`, disabled если `!is_working`
- **Слот (мин)** — `<input type="number" min={15} max={120} step={15} className="input w-20" />`,
  disabled если `!is_working`

Когда чекбокс снимается — поля `start/end/slot` обнуляются визуально (disabled + серые).

### Per-user override

Ссылка «+ Добавить исключение для сотрудника» (под таблицей отдела) открывает
`UserScheduleOverrideModal` (inline modal width="md"):
- UserSelect (только сотрудники отдела)
- Те же 7 строк `WorkDayRow` для выбранного сотрудника
- Save → `PATCH /api/admin/work-schedules/bulk` с `scope="user"` и `user_id`

### PATCH payload (bulk)

```json
PATCH /api/admin/work-schedules/bulk
[
  {
    "scope": "department",
    "department_id": 3,
    "day_of_week": 1,
    "is_working": true,
    "start_time": "09:00",
    "end_time": "18:00",
    "meeting_slot_min": 30
  },
  ...
]
```

### Save логика

- Кнопка «Сохранить все изменения» в PageHeader (top-right) — `btn-primary`.
- Dirty-detection: `JSON.stringify(original) !== JSON.stringify(current)`.
- Если нет изменений — кнопка disabled.
- Loading: `disabled` + «Сохраняем…».
- Success: inline «Сохранено» `text-success text-sm` рядом с кнопкой на 3 секунды.
- Error: `text-danger text-sm` под кнопкой.

### States

**Loading:** skeleton-аккордеон (3 отдела-заглушки `animate-pulse h-12 rounded`).
**Empty (нет отделов):** `card` с иконкой `bi-diagram-3`, «Сначала создай отделы в разделе Отделы».
**Error:** inline `text-danger`.

### Доступ

Только `admin`, `director`.

---

## Раздел 7. Страница `/me/vacations`

### Зачем

Менеджер сам управляет своими отпусками: подаёт заявку, назначает substitute, отслеживает статус.
Администратор/руководитель может утверждать/отклонять заявки подчинённых прямо на этой странице
(через query `?user_id=`).

### Где в коде

- Страница: `apps/web/src/app/(app)/me/vacations/page.tsx`
- Компоненты:
  - `apps/web/src/components/Company/VacationsList.tsx`
  - `apps/web/src/components/Company/VacationCreateModal.tsx`
  - `apps/web/src/components/Company/VacationStatusBadge.tsx`

### Wireframe

```
┌──────────────────────────────────────────────────────────────────┐
│ Мои отпуска                              [+ Новый отпуск]       │
├──────────────────────────────────────────────────────────────────┤
│                                                                   │
│ ┌────────────────────────────────────────────────────────────┐  │
│ │ Период             │ Тип         │ Substitute  │  Статус   │  │
│ ├────────────────────┼─────────────┼─────────────┼───────────┤  │
│ │ 15 июн — 29 июн    │ Отпуск      │ Марина В.   │[ожидание] │  │
│ │ 01 мар — 07 мар    │ Больничный  │ Олег Т.     │[одобрен]  │  │
│ │ 20 янв — 20 янв    │ Отгул       │ —           │[одобрен]  │  │
│ └────────────────────────────────────────────────────────────┘  │
└──────────────────────────────────────────────────────────────────┘
```

### Список отпусков

SWR: `GET /api/me/vacations` — сортировка по `start_date DESC`.

Колонки:
| Колонка | Описание |
|---|---|
| Период | `дд ммм — дд ммм гггг` (если один год) |
| Тип | chip из `VacationTypeLabelMap` |
| Substitute | `full_name` или «—» |
| Статус | `VacationStatusBadge` |
| Действия | `bi-trash text-danger` (только если `status = pending_approval`) |

**VacationTypeLabelMap:**
```
vacation      → «Отпуск»         bg-info/10 text-info
sick_leave    → «Больничный»     bg-warning/10 text-warning
day_off       → «Отгул»          bg-gray-100 text-gray-600
business_trip → «Командировка»   bg-primary/10 text-primary
```

**VacationStatusBadge:**
```
pending_approval → bg-warning/10 text-warning  «Ожидает одобрения»
approved         → bg-success/10 text-success  «Одобрен»
rejected         → bg-danger/10  text-danger   «Отклонён»
```

### Approve / Reject (для admin/director)

Если `current_user.role in [admin, director]` и страница открыта через `?user_id=X` (подчинённый),
то рядом с `pending_approval` записями показываются кнопки:
- `btn-secondary text-success text-sm` «Одобрить»
- `btn-ghost text-danger text-sm` «Отклонить»

`PATCH /api/admin/vacations/{vacation_id}/approve` / `.../reject`

### VacationCreateModal

Размер `width="md"`.

```
┌─── Новый отпуск ─────────────────────────── [x] ──┐
│                                                     │
│  Тип отпуска *                                     │
│  [select: Отпуск v]                                │
│                                                     │
│  Период *                                          │
│  [date: Начало]          [date: Конец]             │
│                                                     │
│  Substitute                                        │
│  [UserSelect: Кто заменит v]                      │
│                                                     │
│  ℹ При наступлении отпуска новые задачи будут     │
│    автоматически назначаться substitute           │
│                                                     │
│  Заметки                                          │
│  [textarea…]                                      │
│                                                     │
│  [Отмена]                  [Подать заявку]        │
└─────────────────────────────────────────────────────┘
```

Поля:
- `SelectField label="Тип отпуска"` required — 4 опции из `VacationTypeLabelMap`.
- `<input type="date" className="input" />` «Начало» + «Конец» required.
  Валидация: `end_date >= start_date`, иначе inline «Дата окончания не может быть раньше начала».
- `UserSelect label="Substitute"` — необязательно; исключить текущего пользователя.
- Help text (синий info-блок): `bg-info/10 text-info text-sm rounded p-3`
  «При наступлении отпуска новые задачи будут автоматически назначаться substitute».
- `TextareaField label="Заметки"` rows={3} не обязательно.

POST payload:
```json
POST /api/me/vacations
{
  "start_date": "2026-06-15",
  "end_date": "2026-06-29",
  "vacation_type": "vacation",
  "substitute_user_id": 42,
  "notes": "Текст или null"
}
```

After success: закрыть → mutate.

### States

Loading: skeleton 3 строки.
Empty: `card`, `bi-airplane text-4xl text-gray-300`, «Отпусков пока нет»,
  `btn-primary` «Подать заявку на отпуск».
Error: inline `text-danger`.

---

## Раздел 8. Страница `/company/absences` — AbsenceCalendar

### Зачем

Администратору нужна единая «карта отсутствий» по всей компании: кто когда в отпуске,
визуально пересечения, кто заменяет. Gantt-like горизонтальные полоски по времени.

### Где в коде

- Страница: `apps/web/src/app/(app)/company/absences/page.tsx`
- Компонент: `apps/web/src/components/Company/AbsenceCalendar.tsx`

### Wireframe

```
┌──────────────────────────────────────────────────────────────────────┐
│ Отсутствия в компании                                                 │
├──────────────────────────────────────────────────────────────────────┤
│ [Отдел: Все v]   [Период: июнь 2026 v]   [< Пред.]   [Сегодня]  [След. >] │
├──────────────────────────────────────────────────────────────────────┤
│                │  2  3  4  5  6  7  8  9 10 11 12 13 14 15 ...     │
│ Иван Петров    │        ██████████████████                           │
│ Лера Иванова   │                          ░░░░░░                    │
│ Олег Тараненко │  ████                                               │
│ Марина Васил.  │                                  ████████          │
└──────────────────────────────────────────────────────────────────────┘
```

### Реализация календаря

Без сторонних библиотек. Pure CSS grid-based:

- **X-ось**: дни месяца. `grid-cols-[160px_repeat(N,1fr)]` где N = дни в месяце.
- **Y-ось**: строки сотрудников.
- Заголовок дней: `div.text-xs.text-gray-400.text-center` с номером дня.
  Выходные (`production_calendar.is_holiday OR day_of_week in [6,7]`) — `bg-gray-50 dark:bg-gray-700/30`.
  Сегодня — `bg-primary/10`.
- **Полоска отпуска**: абсолютное позиционирование через inline-style `grid-column: start/end`.
  Цвет по типу:
  ```
  vacation      → bg-info/30 border border-info/50
  sick_leave    → bg-warning/30 border border-warning/50
  day_off       → bg-gray-200 border border-gray-300
  business_trip → bg-primary/20 border border-primary/40
  ```
  `rounded text-xs font-medium flex items-center px-1 truncate cursor-pointer`.
- **Hover tooltip**: при hover на полоске — `Tooltip` (inline `div` absolute) с полями:
  ФИО, тип, период, substitute, заметки.

SWR: `GET /api/admin/vacations/calendar?from=2026-06-01&to=2026-06-30&department_id=`

### Навигация по периоду

- «< Пред.» / «Сегодня» / «Сл. >» — `btn-ghost` кнопки, меняют month/year в state.
- `[Период: июнь 2026 v]` — select месяц+год (12 месяцев вперёд/назад от сегодня).
- `[Отдел: Все v]` — select из `/api/departments`.

### States

Loading: skeleton 4 строки с `animate-pulse` полосками.
Empty: `card` центрированный, `bi-calendar-x text-4xl text-gray-300`, «В этом периоде отсутствий нет».
Error: inline `text-danger` над календарём.

### Доступ

Только `admin`, `director`.

---

## Раздел 9. Индикатор «исполнитель в отпуске» в Activity card

### Зачем

Менеджер видит задачу — исполнитель в отпуске. Надо показать предупреждение прямо в карточке
и дать быстрый способ переназначить.

### Где в коде

`apps/web/src/components/Timeline/ActivityCard.tsx` (или аналогичный компонент для карточки
активности/задачи). Добавить блок после поля «Исполнитель».

### Wireframe

```
┌─────────────────────────────────────────────────────┐
│ Позвонить Клиенту X                                 │
│ Исполнитель: Лера Иванова                           │
│                                                     │
│ ┌─────────────────────────────────────────────────┐ │
│ │ ✈ Лера Иванова в отпуске до 29 июн             │ │
│ │   Substitute: Иван Петров  [Переназначить]     │ │
│ └─────────────────────────────────────────────────┘ │
│                                                     │
│ Срок: 20 июн 2026                                  │
└─────────────────────────────────────────────────────┘
```

### Логика показа

Показывать блок если:
- `activity.responsible_user.employment_status === "on_vacation"`
- И `today` попадает в диапазон одного из `user_vacations` исполнителя

Данные об отпуске исполнителя должны приходить в расширенном DTO активности либо
подгружаться отдельным SWR-запросом `GET /api/users/{id}/current-vacation`.

Рекомендуется: добавить в `Activity` DTO поле `responsible_user_vacation` (nullable object с
`end_date`, `substitute_user_id`, `substitute_name`) — отметить в Открытых вопросах.

### Блок «в отпуске»

```html
<div className="bg-warning/10 border border-warning/30 rounded p-3 text-sm flex items-start gap-2">
  <i className="bi bi-airplane text-warning mt-0.5 shrink-0" />
  <div>
    <span className="font-medium">{responsible_name} в отпуске до {end_date_formatted}</span>
    {substitute && (
      <div className="mt-1 text-gray-600 dark:text-gray-400 flex items-center gap-2">
        Substitute: {substitute_name}
        <button className="btn-ghost text-xs py-0.5 px-2">Переназначить</button>
      </div>
    )}
  </div>
</div>
```

### Кнопка «Переназначить»

Click → `PATCH /api/activities/{id}` body `{ "responsible_user_id": substitute_user_id }` →
mutate → блок исчезает (новый исполнитель не в отпуске).

Если substitute не задан — кнопка не показывается; вместо неё текст «Substitute не назначен».

---

## Раздел 10. SlotPickerModal (для TaskCreateModal, Эпик 24)

### Зачем

При создании встречи (activity kind=meeting) пользователь может выбрать свободный временной
слот исполнителя, не гадая когда тот занят. Слоты считаются с учётом рабочего расписания,
отпусков и производственного календаря.

### Где в коде

`apps/web/src/components/Company/SlotPickerModal.tsx`

Интеграция: вызывается из `TaskCreateModal` (Эпик 24) через prop `onSlotSelected`.
Здесь проектируем компонент — соединение произойдёт при реализации Эпика 24.

### Wireframe

```
┌─── Подобрать слот: Марина Васильева ─────── [x] ──┐
│                                                     │
│  [< Пред. неделя]  2–8 июн 2026  [Сл. неделя >]  │
│                                                     │
│  Пн 2 июн                                         │
│  ┌─────────────┬─────────────┬─────────────┐      │
│  │  09:00–09:30 │  10:00–10:30 │  14:00–14:30 │    │
│  └─────────────┴─────────────┴─────────────┘      │
│                                                     │
│  Вт 3 июн                                         │
│  ┌─────────────┬─────────────┐                    │
│  │  09:30–10:00 │  11:00–11:30 │                  │
│  └─────────────┴─────────────┘                    │
│                                                     │
│  Ср 4 июн — нерабочий день                        │
│  Чт 5 июн — слотов нет (полностью занят)           │
│  Пт 6 июн                                         │
│  ┌─────────────┐                                   │
│  │  15:00–15:30 │                                  │
│  └─────────────┘                                   │
│                                                     │
│  [Отмена]                                         │
└─────────────────────────────────────────────────────┘
```

### SWR

```
GET /api/users/{user_id}/available-slots?from=2026-06-02&to=2026-06-08
```

Response shape:
```json
[
  { "start": "2026-06-02T09:00:00", "end": "2026-06-02T09:30:00" },
  ...
]
```

Слоты группируются по дате на клиенте.

### Props

```typescript
interface SlotPickerModalProps {
  open: boolean;
  userId: number;
  userName: string;
  onClose: () => void;
  onSlotSelected: (start: string, end: string) => void;
}
```

### Навигация по неделям

State: `weekOffset: number` (0 = текущая неделя).
«< Пред. неделя» / «Сл. неделя >» — `btn-ghost`.
Диапазон dates вычисляется из `weekOffset` + `startOfWeek(today)`.

### Слот-чипы

`<button className="text-sm bg-primary/10 text-primary hover:bg-primary/20 border border-primary/30 rounded px-3 py-1.5 transition-colors">09:00–09:30</button>`

Click → `onSlotSelected(start, end)` → закрыть modal → подставить значения в форму встречи.

### Нерабочий день / нет слотов

Нерабочий день: серый текст «{день} — нерабочий день», без слотов.
Полностью занят: «{день} — слотов нет», опционально `(занят 8 из 8)`.
Общий empty (вся неделя): `bi-calendar-x text-3xl text-gray-300` + «На этой неделе слотов нет»
  + `btn-ghost` «Перейти на следующую неделю».

### States

Loading: `animate-pulse` группы чипов-заглушек.
Error: `text-danger text-sm` в теле модала.

---

## Раздел 11. Изменения в Sidebar

### Где в коде

`apps/web/src/components/Sidebar.tsx` — редактировать массив `ADMIN_ITEMS` и блок profile-ссылок.

### Новые пункты в ADMIN_ITEMS

Добавить **после** `{ href: "/admin/departments", ... }`:

```typescript
{ href: "/company/employees",       icon: "bi-people",            label: "Сотрудники",     roles: ["admin", "director"] },
{ href: "/company/rights-transfers", icon: "bi-arrow-left-right",  label: "Передача прав",  roles: ["admin", "director"] },
{ href: "/company/schedules",        icon: "bi-calendar-week",     label: "График работы",  roles: ["admin", "director"] },
{ href: "/company/absences",         icon: "bi-airplane",          label: "Отсутствия",     roles: ["admin", "director"] },
```

### Ссылка «Мои отпуска» в профильной секции

В нижней части Sidebar (блок профиля, рядом с аватаром и кнопкой logout) добавить ссылку:

```tsx
<Link href="/me/vacations" className="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400 hover:text-primary px-3 py-1.5 rounded hover:bg-gray-100 dark:hover:bg-gray-700">
  <i className="bi bi-airplane text-base" />
  Мои отпуска
</Link>
```

Точное место вставки — над кнопкой logout, под ссылкой на profile.

---

## Список новых компонентов

| Компонент | Путь | Используется в |
|---|---|---|
| `EmployeesTable` | `Company/EmployeesTable.tsx` | `/company/employees` |
| `EmployeeStatusBadge` | `Company/EmployeeStatusBadge.tsx` | EmployeesTable, Activity card |
| `EmployeeRowActions` | `Company/EmployeeRowActions.tsx` | EmployeesTable |
| `EmployeeDismissModal` | `Company/EmployeeDismissModal.tsx` | EmployeeRowActions |
| `RestoreUserModal` | `Company/RestoreUserModal.tsx` | EmployeeRowActions |
| `RightsTransferModal` | `Company/RightsTransferModal.tsx` | EmployeeRowActions |
| `RightsTransferTable` | `Company/RightsTransferTable.tsx` | `/company/rights-transfers` |
| `RightsTransferViewModal` | `Company/RightsTransferViewModal.tsx` | RightsTransferTable |
| `RightsTransferRevertModal` | `Company/RightsTransferRevertModal.tsx` | RightsTransferTable |
| `DepartmentScheduleEditor` | `Company/DepartmentScheduleEditor.tsx` | `/company/schedules` |
| `WorkDayRow` | `Company/WorkDayRow.tsx` | DepartmentScheduleEditor |
| `UserScheduleOverrideModal` | `Company/UserScheduleOverrideModal.tsx` | DepartmentScheduleEditor |
| `VacationsList` | `Company/VacationsList.tsx` | `/me/vacations` |
| `VacationCreateModal` | `Company/VacationCreateModal.tsx` | VacationsList |
| `VacationStatusBadge` | `Company/VacationStatusBadge.tsx` | VacationsList, AbsenceCalendar |
| `AbsenceCalendar` | `Company/AbsenceCalendar.tsx` | `/company/absences` |
| `SlotPickerModal` | `Company/SlotPickerModal.tsx` | TaskCreateModal (Эпик 24) |

Итого: **17 новых компонентов** в папке `apps/web/src/components/Company/`.

---

## Все тексты (RU, для копипаст в JSX)

### Заголовки страниц (PageHeader.title)

- `/company/employees` → `«Сотрудники»`
- `/company/employees` → description: `«Управление статусами сотрудников и передача прав»`
- `/company/rights-transfers` → `«Передача прав»`
- `/company/rights-transfers` → description: `«Журнал всех передач: кто, кому, когда и что»`
- `/company/schedules` → `«График работы»`
- `/company/schedules` → description: `«Рабочее расписание по отделам для расчёта слотов встреч»`
- `/company/absences` → `«Отсутствия в компании»`
- `/company/absences` → description: `«Кто и когда в отпуске — обзор по всей команде»`
- `/me/vacations` → `«Мои отпуска»`
- `/me/vacations` → description: `«Заявки на отпуск, больничные и командировки»`

### Кнопки

- Добавить сотрудника: `«Добавить»` (ведёт на `/admin/users` modal)
- Новый отпуск: `«+ Новый отпуск»`
- Подать заявку: `«Подать заявку»`
- Сохранить график: `«Сохранить все изменения»`
- Уволить (confirm): `«Уволить сотрудника»`
- Восстановить: `«Восстановить»`
- Передать права: `«Передать права»`
- Откатить: `«Откатить»`
- Переназначить: `«Переназначить»`
- Одобрить отпуск: `«Одобрить»`
- Отклонить отпуск: `«Отклонить»`
- Смотреть: `«Смотреть»`

### EmployeeStatusBadge

- active: `«Активен»`
- on_vacation: `«В отпуске»`
- dismissed: `«Уволен»`

### VacationTypeLabelMap

- vacation: `«Отпуск»`
- sick_leave: `«Больничный»`
- day_off: `«Отгул»`
- business_trip: `«Командировка»`

### VacationStatusBadge

- pending_approval: `«Ожидает одобрения»`
- approved: `«Одобрен»`
- rejected: `«Отклонён»`

### EmployeeDismissModal

- Шаг 1 заголовок: `«Substitute»`
- Шаг 2 заголовок: `«Что передать»`
- Шаг 3 заголовок: `«Причина»`
- Шаг 1 label: `«Кто заменит»`
- Шаг 1 help: `«На этого человека перейдут все незавершённые задачи, контакты и сделки {full_name}»`
- Шаг 2 заголовок: `«Выбери что передать substitute»`
- Чекбоксы: `«Контакты»` / `«Сделки»` / `«Задачи как постановщик»` / `«Задачи как исполнитель»` / `«Согласования»` / `«Настройки и конфиги»`
- Preview prefix: `«Будет передано:»`
- Шаг 3 placeholder: `«Опционально: укажи причину увольнения…»`
- Шаг 3 hint: `«Причина сохраняется в журнале и видна только администраторам»`
- Confirm title: `«Уволить {full_name}?»`
- Confirm body: `«После увольнения войти в систему будет невозможно. Данные перейдут к {substitute_name}.»`
- Validation: `«Выбери substitute»`

### RestoreUserModal

- Title: `«Восстановить сотрудника»`
- Body: `«Восстановить {full_name}? Сотрудник снова получит доступ в систему.»`
- Label: `«Новая роль»`

### RightsTransferModal

- Title: `«Передача прав: {full_name}»`
- Label «кому»: `«Кому передать»`
- Label категории: `«Что передать (выбери категории)»`
- Label причина: `«Причина передачи»`

### RightsTransferRevertModal

- Title: `«Откатить передачу прав?»`
- Body: `«Восстановится прежний owner для {count} записей ({categories}).»`

### Activity card — vacation indicator

- Текст: `«{full_name} в отпуске до {end_date}»`
- Substitute текст: `«Substitute: {name}»`
- Нет substitute: `«Substitute не назначен»`

### VacationCreateModal

- Info block: `«При наступлении отпуска новые задачи будут автоматически назначаться substitute»`
- Label тип: `«Тип отпуска»`
- Label период начало: `«Начало»`
- Label период конец: `«Конец»`
- Label substitute: `«Substitute»`
- Label заметки: `«Заметки»`
- Validation: `«Дата окончания не может быть раньше начала»`

### SlotPickerModal

- Title: `«Подобрать слот: {user_name}»`
- Empty week: `«На этой неделе слотов нет»`
- CTA empty: `«Перейти на следующую неделю»`
- Non-working day: `«{день} — нерабочий день»`
- Full day: `«{день} — слотов нет»`

### Empty states (общие)

- Сотрудники: title `«Пока нет сотрудников»`, description `«Добавь первого в разделе Пользователи»`
- Передача прав: title `«Передач прав пока нет»`, description `«Появятся после первого увольнения или ручной передачи»`
- Графики: title `«Нет отделов»`, description `«Сначала создай отделы в разделе Отделы»`
- Отсутствия: title `«В этом периоде отсутствий нет»`
- Мои отпуска: title `«Отпусков пока нет»`, description `«Подай заявку — она уйдёт на одобрение руководителю»`

### Success / Error (inline, не toast)

- Уволен: `«{full_name} уволен. Данные переданы {substitute_name}.»`
- Восстановлен: `«{full_name} восстановлен как {role}.»`
- Права переданы: `«Права переданы {to_name}.»`
- Откатано: `«Передача откатана. Записи восстановлены.»`
- График сохранён: `«Сохранено»`
- Ошибка по умолчанию: `«Что-то пошло не так. Попробуй ещё раз.»`

---

## Связь с backend

### Новые эндпоинты (все требуют реализации в backend-specialist)

| Метод | URL | Описание |
|---|---|---|
| `GET` | `/api/admin/users/employees` | Список с `employment_status`, `substitute_*`, `department_name`, `manager_name` |
| `GET` | `/api/admin/users/{id}/transfer-preview` | Кол-во записей по категориям для Preview в DismissModal |
| `POST` | `/api/admin/users/{id}/dismiss` | Soft-dismiss + atomic reassign |
| `POST` | `/api/admin/users/{id}/restore` | Восстановление |
| `POST` | `/api/admin/rights-transfers` | Ручная передача |
| `GET` | `/api/admin/rights-transfers` | Список с фильтрами |
| `POST` | `/api/admin/rights-transfers/{id}/revert` | Откат |
| `GET` | `/api/admin/rights-transfers/{id}/items` | Детали (для ViewModal) |
| `PATCH` | `/api/admin/work-schedules/bulk` | Bulk сохранение графика |
| `GET` | `/api/admin/work-schedules?scope=department&department_id=` | Чтение графика |
| `POST` | `/api/me/vacations` | Подать заявку |
| `PATCH` | `/api/me/vacations/{id}` | Редактировать (пока pending) |
| `DELETE` | `/api/me/vacations/{id}` | Удалить (пока pending) |
| `GET` | `/api/me/vacations` | Мои отпуска |
| `GET` | `/api/admin/vacations/calendar` | Все отпуска для AbsenceCalendar |
| `PATCH` | `/api/admin/vacations/{id}/approve` | Одобрить |
| `PATCH` | `/api/admin/vacations/{id}/reject` | Отклонить |
| `GET` | `/api/users/{id}/available-slots?from=&to=` | Слоты для SlotPickerModal |
| `GET` | `/api/users/{id}/current-vacation` | Текущий отпуск пользователя (для Activity card) |

### DTO расширения (требуются от backend-specialist)

- `User` DTO: добавить `employment_status: "active" | "dismissed" | "on_vacation"`,
  `substitute_user_id: int | null`, `substitute_name: str | null`, `dismissed_at: str | null`
- `Activity` DTO: добавить `responsible_user_vacation: { end_date: str, substitute_user_id: int | null, substitute_name: str | null } | null`

---

## Координация с другими эпиками

### Эпик 24 — Задачник (SlotPickerModal)

`SlotPickerModal` (Раздел 10) проектируется здесь. При реализации Эпика 24 frontend-specialist:
1. Импортирует `SlotPickerModal` из `@/components/Company/SlotPickerModal`
2. Добавляет кнопку «Подобрать слот» в `TaskCreateModal` (только при `kind="meeting"` + выбранном `responsible_user_id`)
3. Пробрасывает `onSlotSelected` → заполнить поля `start_at`/`end_at` в форме

### Эпик 21 — Notifications

При успешном `POST /api/admin/users/{id}/dismiss` backend должен отправить уведомления:
- Substitute: `kind = "employee_dismissed_substitute"` — «Вам переданы {N} записей от {name}»
- Прямой руководитель: `kind = "employee_dismissed_manager"` — «{name} уволен»

Frontend ничего дополнительно не делает — достаточно inline-success после POST.
Backend-specialist должен учесть это при реализации `dismiss`-сервиса.

---

## Открытые вопросы

1. **Backend: `GET /api/admin/users/employees`** — конфликт с `GET /api/admin/users/{id}` если `id = "employees"`? Нужно убедиться что роутер FastAPI правильно различит. Рекомендация: `GET /api/admin/employees` отдельным префиксом.

2. **DTO `Activity`** — добавлять ли `responsible_user_vacation` к каждой активности (overhead) или загружать отдельным запросом `GET /api/users/{id}/current-vacation` лениво по клику/hover? Решение влияет на число N+1 запросов. Рекомендация: флаг `is_responsible_on_vacation: bool` в Activity DTO + отдельный endpoint за деталями.

3. **Soft-dismiss vs is_active** — сейчас `User.is_active` управляет доступом. После `dismiss` нужно `is_active=false` + `employment_status="dismissed"`. Какой приоритет? Нужно согласовать с backend-specialist.

4. **AbsenceCalendar — производственный календарь** — нужен ли seed с праздниками РФ сразу или достаточно выходных (суббота/воскресенье) для MVP? Если РФ + КЗ + другие страны — логика усложняется (Открытый вопрос из MARATHON-3 Master Plan п.2).

5. **SlotPickerModal — часовые пояса** — слоты возвращаются в UTC или локальном времени пользователя? Без per-user timezone (Эпик 14.2 его не вводит) предлагается использовать серверный timezone компании по умолчанию. Если нужны tz — это отдельный подэпик.

6. **VacationCreateModal — одобрение** — кто именно может одобрять: только `admin`, только `manager_id` сотрудника, или все `director`? Нужна позиция продукта для `PATCH /api/admin/vacations/{id}/approve` роль-фильтра.

7. **RightsTransferItems таблица в ViewModal** — при большом числе записей (100+) нужна ли серверная пагинация или достаточно клиентской? Рекомендация: `GET /api/admin/rights-transfers/{id}/items?offset=&limit=20`.

8. **UserScheduleOverrideModal** — нужен ли в MVP или достаточно departmental-level schedules? Если откладываем — убрать ссылку «+ Добавить исключение» из DepartmentScheduleEditor.

9. **DismissModal «Настройки и конфиги»** категория — что именно переносится? Automation creators? Visibility rules? Нужно точное определение из backend-specialist чтобы Preview-блок показывал корректные цифры.
