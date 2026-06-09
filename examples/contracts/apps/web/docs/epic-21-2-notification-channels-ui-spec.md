# ТЗ: Эпик 21.2 — Notification Channels + Email + Bulk Broadcast

**Дата:** 2026-06-02
**Статус:** готово к реализации

---

## Cover / Контекст

Эпик 21 базовый — уже в проде:
- `NotificationBell` в Sidebar (`components/Notifications/NotificationBell.tsx`)
- `NotificationDropdown` — выпадающий список последних N уведомлений
- `NotificationItem` — строка уведомления с иконкой по kind, relative time, click-to-link
- `/notifications` — страница с фильтром по kind + «только непрочитанные» + пагинация
- `/profile/notifications` — страница, сейчас дублирует `/notifications` (список + простые фильтры)
- `useNotifications` hook — SWR для list + count, `markRead`, `markAllRead`, `deleteNotification`

**Что добавляет Эпик 21.2:**
1. `/profile/notifications` — переработка в полноценный центр управления каналами (матрица kind × channel)
2. `NotificationBell` — hover-preview + группировка по дате в дропдауне (уже есть, минорные улучшения)
3. `/notifications` — дополнительные фильтры (дата, multi-select kind, bulk read)
4. `/admin/notifications/broadcast` — страница рассылки для admin
5. `/admin/notifications/broadcasts` — история рассылок
6. `/admin/notification-templates` — управление шаблонами уведомлений

**Новый Sidebar-пункт:** в секции ADMIN_ITEMS добавить «Уведомления» с подпунктами «Рассылки» / «Шаблоны».

**Backend-зависимость:** миграция `0061_notification_channel_preferences` должна быть выполнена до реализации (описание в MARATHON-3 Master Plan). Конкретно нужны:
- таблица `notification_channel_preferences (user_id, kind, channel ENUM in_app/tg/email, is_enabled)`
- таблица `notification_templates (kind, channel, locale, subject, body_template, variables JSONB)`
- поля `users.tg_quiet_hours_start`, `users.tg_quiet_hours_end` (TIME)
- таблица `notification_broadcasts` (id, title, body, link, recipients_filter JSONB, channels JSONB, created_by_id, scheduled_at, sent_at, status ENUM, total_recipients, delivered_count, failed_count)

---

## Раздел 1 — `/profile/notifications` (полная переработка)

**Зачем:** пользователь контролирует в каком канале и о чём получает уведомления. Сейчас страница — дубль списка уведомлений, никакой настройки нет.

**Где в коде:**
- `apps/web/src/app/(app)/profile/notifications/page.tsx` — переписать полностью
- `apps/web/src/components/Notifications/ChannelPreferencesMatrix.tsx` — новый
- `apps/web/src/components/Notifications/QuietHoursToggle.tsx` — новый

### Wireframe

```
┌──────────────────────────────────────────────────────────────┐
│ [Sidebar]  │ [PageHeader: Настройки уведомлений]             │
│            ├──────────────────────────────────────────────────│
│            │  p-8 max-w-4xl space-y-6                        │
│            │                                                  │
│            │ ┌──────────────────────────────────────────────┐ │
│            │ │ card: «Каналы доставки»                      │ │
│            │ │  In-App  [badge success: Активен]            │ │
│            │ │  Telegram [badge success: @username] /        │ │
│            │ │           [btn-secondary: Подключить]         │ │
│            │ │  Email    [badge success: user@...] /          │ │
│            │ │           [hint: добавь email в профиль]      │ │
│            │ │  ─────────────────────────────────────────── │ │
│            │ │  «Тихие часы для Telegram» [toggle]           │ │
│            │ │  (если вкл) с [08:00] до [23:00]              │ │
│            │ │  hint-text под пикером                        │ │
│            │ └──────────────────────────────────────────────┘ │
│            │                                                  │
│            │ ┌──────────────────────────────────────────────┐ │
│            │ │ card: «Матрица настроек»                     │ │
│            │ │ [Включить всё In-App] [Вкл. всё TG] [Вкл. Email]│
│            │ │ ────────────────────────────────────────────  │ │
│            │ │ ▼ Задачи (accordion header)                  │ │
│            │ │   Назначена задача        [☑] [☑] [☑]        │ │
│            │ │   Изменён статус задачи   [☑] [☑] [☐]        │ │
│            │ │   Запрос продления срока  [☑] [☐] [☐]        │ │
│            │ │ ▼ Сделки                                     │ │
│            │ │   Выиграна сделка         [☑] [☑] [☑]        │ │
│            │ │   Изменился этап          [☑] [☐] [☐]        │ │
│            │ │ ▶ Согласования (свёрнуто) ...                │ │
│            │ │ ▶ SLA ...                                    │ │
│            │ │ ▶ Обучение ...                               │ │
│            │ │ ▶ Договоры ...                               │ │
│            │ │ ▶ Социальное ...                             │ │
│            │ │ ▶ Системные ...                              │ │
│            │ │ ────────────────────────────────────────────  │ │
│            │ │               [btn-primary: Сохранить настройки]│
│            │ └──────────────────────────────────────────────┘ │
│            │                                                  │
│            │ ┌──────────────────────────────────────────────┐ │
│            │ │ card: «Тестовое уведомление»                 │ │
│            │ │  «Проверь, что каналы работают»              │ │
│            │ │  [btn-secondary: Отправить тестовое]         │ │
│            │ └──────────────────────────────────────────────┘ │
└──────────────────────────────────────────────────────────────┘
```

### Композиция

- `apps/web/src/app/(app)/profile/notifications/page.tsx` — корневая, Client Component
- `apps/web/src/components/Notifications/ChannelPreferencesMatrix.tsx` — матрица + аккордионы
- `apps/web/src/components/Notifications/QuietHoursToggle.tsx` — toggle + time pickers
- Реюз: `PageHeader`, `badge`

### Секция «Каналы доставки»

Карточка `card` с тремя строками. Каждая строка — flex items-center justify-between.

**In-App:**
- Иконка `bi-bell` `text-info`
- Лейбл «В приложении»
- Badge `bg-success/10 text-success` «Активен» (всегда включён, не отключается)

**Telegram:**
- Иконка `bi-telegram` `text-info`
- Лейбл «Telegram»
- Если `me.telegram_username` есть: badge `bg-success/10 text-success` `@{username}`
- Если нет: кнопка `btn-secondary text-sm` «Подключить» → ссылка `/profile` (там уже есть TG-привязка)

**Email:**
- Иконка `bi-envelope` `text-info`
- Лейбл «Email»
- Если `me.email` есть: badge `bg-success/10 text-success` с маскированным адресом (первые 3 символа + `***@domain.com`)
- Если нет: серый текст «Добавь email в профиле» со ссылкой `/profile`

**Разделитель** `border-t border-gray-100 dark:border-gray-700 my-4`

**QuietHoursToggle** (`components/Notifications/QuietHoursToggle.tsx`):
- label «Тихие часы для Telegram» + toggle справа (нативный `<input type="checkbox">` styled как switch — можно через стандартный Tailwind toggle-паттерн: `relative inline-flex ... peer`)
- При `is_enabled=true` — плавное появление блока с двумя `<input type="time">` (нативный, без сторонних либ):
  - «с» `input class="input w-28"` placeholder `08:00`
  - «до» `input class="input w-28"` placeholder `23:00`
- Help text под пикерами: `text-xs text-gray-400 mt-1` «В это время уведомления не придут в Telegram. Они появятся утром в дайджесте.»
- Сохранение quiet hours — через PATCH `/api/me/profile` (уже существует) с полями `tg_quiet_hours_start`, `tg_quiet_hours_end`; debounce 600ms после изменения, без отдельной кнопки

### Секция «Матрица настроек»

Компонент `ChannelPreferencesMatrix`.

**Data source:** `GET /api/me/notifications/preferences` → `Array<{kind: string, channel: "in_app"|"tg"|"email", is_enabled: boolean}>`

**Локальная структура kind-groups:**

```
TASK_GROUP = [task_assigned, task_status_changed, task_extend_requested]
DEAL_GROUP = [deal_won, deal_stage_changed]
APPROVAL_GROUP = [approval_needed]
SLA_GROUP = [sla_breach]
LEARNING_GROUP = [course_assigned, course_completed]
CONTRACT_GROUP = [contract_signed]
SOCIAL_GROUP = [mention]
SYSTEM_GROUP = [system]
```

**Таблица:**
- Header-строка: пустая колонка для лейбла | «In-App» | «Telegram» | «Email»
- Ширины: `w-auto flex-1` | `w-20 text-center` | `w-20 text-center` | `w-20 text-center`
- Каждая ячейка канала — `<input type="checkbox" className="accent-primary w-4 h-4 cursor-pointer">`
- Если канал недоступен (TG не подключён, Email не заполнен) — `disabled` + `opacity-40 cursor-not-allowed`
- При `disabled` — tooltip (title attr) «Сначала подключи Telegram» / «Сначала добавь email»

**Accordion-группы:**
- Header строки группы: `flex items-center justify-between py-2 px-4 bg-gray-50 dark:bg-gray-800 cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-700 text-sm font-medium text-gray-700 dark:text-gray-300`
- Иконка `bi-chevron-down` справа, rotate-180 при open
- Начальное состояние: «Задачи» и «Сделки» — expanded, остальные свёрнуты
- Строки внутри группы: `px-4 py-2.5 border-b border-gray-100 dark:border-gray-700/50 flex items-center`

**Bulk actions** — строка над таблицей (`flex gap-2 flex-wrap mb-3`):
- Per-channel: `btn-ghost text-xs py-1` «Вкл. все In-App» / «Вкл. все TG» / «Вкл. все Email» / «Выкл. всё»
- Click — обновляет локальный state всех чекбоксов нужного канала; сохранение — через общую кнопку «Сохранить»

**Кнопка сохранения:**
- `btn-primary` справа под таблицей: «Сохранить настройки»
- Loading state: `disabled` + «Сохраняем…»
- После успеха: inline-текст `text-success text-sm` «Сохранено» на 2 секунды
- PATCH `/api/me/notifications/preferences` body: `{preferences: [{kind, channel, is_enabled}]}`

### Секция «Тестовое уведомление»

Карточка `card` (compact, `p-4`):
- Flex row: иконка `bi-check2-circle text-success text-xl` + текст «Проверь, что каналы настроены верно» + кнопка `btn-secondary` «Отправить тестовое» справа
- После клика: loading state → POST `/api/me/notifications/test` → inline `text-success text-sm` «Тестовое отправлено во включённые каналы»
- Если ошибка: inline `text-danger text-sm` «Не удалось отправить. Проверь настройки каналов.»

### States

| Состояние | Поведение |
|---|---|
| Loading | Skeleton: 2 animate-pulse блока по высоте секций (`h-24`, `h-80`) |
| Матрица загрузилась | Заполняется из preferences. Чекбоксы без данных — `is_enabled: true` по умолчанию (backend возвращает defaults) |
| TG не подключён | Колонка TG — все чекбоксы disabled + tooltip |
| Email не заполнен | Колонка Email — все чекбоксы disabled + tooltip |
| Error fetch | `text-danger text-sm` над матрицей «Не удалось загрузить настройки. Обнови страницу.» |

### Interactions

| Элемент | Действие | Результат |
|---|---|---|
| «Подключить» TG | click | переход `/profile` (якорь на TG-секцию) |
| Toggle тихих часов | click | плавно показывает/скрывает time pickers |
| Time picker | change | debounce 600ms → PATCH `/api/me/profile` |
| Чекбокс в матрице | change | обновить локальный state |
| «Вкл. все In-App» | click | обновить все `in_app` чекбоксы в local state |
| «Сохранить настройки» | click | PATCH preferences → inline-feedback |
| «Отправить тестовое» | click | POST test → inline-feedback |
| Accordion header | click | expand/collapse группы |

### Тексты (RU)

- Заголовок страницы: `Настройки уведомлений`
- Заголовок секции каналов: `Каналы доставки`
- In-App лейбл: `В приложении`
- In-App badge: `Всегда активен`
- TG badge (подключён): `@{username}`
- TG кнопка (нет): `Подключить Telegram`
- Email badge: `{masked_email}`
- Email hint (нет): `Добавь email в профиле`
- TG quiet hours toggle: `Тихие часы для Telegram`
- TimePicker label «с»: `с`
- TimePicker label «до»: `до`
- Quiet hours hint: `В это время уведомления не придут в Telegram. Они появятся утром в дайджесте.`
- Заголовок матрицы: `Матрица настроек`
- Column In-App: `В приложении`
- Column TG: `Telegram`
- Column Email: `Email`
- Bulk btn: `Вкл. всё`; `Выкл. всё`
- Group headers: `Задачи` / `Сделки` / `Согласования` / `SLA` / `Обучение` / `Договоры` / `Социальное` / `Системные`
- Kind labels: `Назначена задача` / `Изменён статус задачи` / `Запрос продления срока` / `Выиграна сделка` / `Изменился этап сделки` / `Требуется согласование` / `Нарушен SLA` / `Назначен курс` / `Курс завершён` / `Подписан договор` / `Упоминание` / `Системное сообщение`
- Кнопка сохранения: `Сохранить настройки`
- Loading кнопки: `Сохраняем…`
- Success inline: `Сохранено`
- Заголовок тест-секции: `Проверь, что каналы настроены верно`
- Кнопка тест: `Отправить тестовое`
- Success тест: `Тестовое отправлено во включённые каналы`
- Error тест: `Не удалось отправить. Проверь настройки каналов.`
- Tooltip disabled TG: `Сначала подключи Telegram`
- Tooltip disabled Email: `Сначала добавь email в профиле`

### Backend API

| Метод | URL | Назначение |
|---|---|---|
| GET | `/api/me/notifications/preferences` | список preferences юзера (все kind × channel) |
| PATCH | `/api/me/notifications/preferences` | bulk-update `{preferences: [{kind, channel, is_enabled}]}` |
| GET | `/api/me/profile` | уже есть — читаем `telegram_username`, `email`, `tg_quiet_hours_start/end` |
| PATCH | `/api/me/profile` | уже есть — добавить поля `tg_quiet_hours_start`, `tg_quiet_hours_end` |
| POST | `/api/me/notifications/test` | тестовая рассылка юзеру |

**Требуется правка backend:** эндпоинты `/api/me/notifications/preferences` (GET + PATCH) и `/api/me/notifications/test` — новые. `PATCH /api/me/profile` нужно расширить полями quiet hours.

---

## Раздел 2 — `NotificationDropdown` обновлённый

**Зачем:** текущий дропдаун показывает последние 20 без группировки. Нужна группировка по дате и hover-preview для уменьшения клика до «mark all read».

**Где в коде:**
- `apps/web/src/components/Notifications/NotificationDropdown.tsx` — обновить
- `apps/web/src/components/Notifications/NotificationBell.tsx` — обновить (hover-preview)

### Wireframe — дропдаун

```
┌────────────────────────────────────────────────────┐
│ Уведомления             [Прочитать все] [✕]        │
├────────────────────────────────────────────────────┤
│ — Сегодня ─────────────────────────────────────── │
│ [●][icon] Назначена задача «Позвонить клиенту»     │
│           5 мин. назад                             │
│ [●][icon] Выиграна сделка Контрагент X             │
│           1 ч. назад                               │
│ — Вчера ───────────────────────────────────────── │
│ [ ][icon] Требуется согласование договора          │
│           вчера в 14:30                            │
├────────────────────────────────────────────────────┤
│                    Все уведомления →               │
└────────────────────────────────────────────────────┘
```

### Hover-preview на иконке Bell

**Не реализуем.** Hover-preview через CSS-tooltip усложняет компонент без ощутимой пользы — текущий паттерн (badge с числом + dropdown по клику) достаточен. Убираем эту фичу из скопа.

Обоснование: у нас нет toast-системы, hover-preview добавил бы сложность (портал, z-index, мобильный touch). Оставляем Badge + dropdown.

### Изменения в `NotificationDropdown`

**Группировка по дате** — добавить функцию `groupByDate(notifications)` → `{today: [], yesterday: [], earlier: []}`.

Секция-разделитель между группами:
```
<div className="px-4 py-1 text-xs font-medium text-gray-400 dark:text-gray-500 bg-gray-50 dark:bg-gray-800/50 sticky top-0">
  Сегодня / Вчера / Раньше
</div>
```

**Кнопка «Прочитать все»** — уже есть (`handleMarkAllRead`). Добавить загрузочный state: disabled + spinner `bi-arrow-clockwise animate-spin` пока идёт запрос.

**Кнопка закрытия** — добавить `✕` (`bi-x`) рядом с «Прочитать все» для закрытия без click-outside. `btn-ghost p-1`.

**Счётчик** — в заголовке дропдауна после слова «Уведомления»: если unreadCount > 0 — badge `bg-danger text-white text-xs rounded-full px-1.5` `({unreadCount})`.

### States

| Состояние | Поведение |
|---|---|
| Loading | 3 skeleton строки `animate-pulse h-16` |
| Empty unread | Иконка `bi-bell-slash text-3xl text-gray-300`, текст «Всё прочитано» |
| Empty total | То же, текст «Уведомлений пока нет» |
| Ошибка SWR | `text-danger text-sm` «Не удалось загрузить» + `btn-ghost text-sm` «Повторить» → revalidate |

### Interactions

| Элемент | Действие | Результат |
|---|---|---|
| `bi-bell` в Sidebar | click | открыть/закрыть дропдаун |
| Click outside / Escape | — | закрыть |
| `bi-x` | click | закрыть |
| «Прочитать все» | click | disabled+spinner → POST mark-all-read → revalidate |
| `NotificationItem` | click | markRead (если unread) → navigate link → onClose |
| «Все уведомления →» | click | onClose + navigate `/notifications` |

### Тексты (RU)

- Заголовок дропдауна: `Уведомления`
- Группа-разделитель: `Сегодня` / `Вчера` / `Раньше`
- Кнопка «читать все»: `Прочитать все`
- Loading «читать все»: `Читаем…`
- Empty (только unread): `Всё прочитано`
- Empty total: `Уведомлений пока нет`
- Ссылка в footer: `Все уведомления`
- Error: `Не удалось загрузить`
- Retry: `Повторить`

---

## Раздел 3 — `/notifications` (расширения существующей страницы)

**Зачем:** сейчас только 1 select + 1 checkbox. Нужны date-period + multi-select kind + bulk read на странице.

**Где в коде:**
- `apps/web/src/app/(app)/notifications/page.tsx` — обновить

### Wireframe

```
┌──────────────────────────────────────────────────────────────┐
│ [PageHeader: Уведомления]          [Прочитать всё на стр.]   │
├──────────────────────────────────────────────────────────────┤
│ p-8 max-w-3xl                                               │
│ ┌─────────────────────────────────────────────────────────┐  │
│ │ Фильтры (card, p-4, flex-wrap gap-3)                   │  │
│ │ [multi-select Kind ▼] [Период ▼] [☑ Непрочитанные]    │  │
│ │ [Sort: Новые сверху ▼]           [Сбросить фильтры]   │  │
│ └─────────────────────────────────────────────────────────┘  │
│                                                             │
│ ┌─────────────────────────────────────────────────────────┐  │
│ │ card overflow-hidden                                    │  │
│ │ [NotificationItem] × N                                  │  │
│ │ [Загрузить ещё]                                         │  │
│ └─────────────────────────────────────────────────────────┘  │
└──────────────────────────────────────────────────────────────┘
```

### Новые фильтры

**Multi-select Kind** — заменить одиночный `<select>` на компактный multi-select:

Реализация без сторонних либ:
- Кнопка `btn-secondary text-sm` «Тип: все ▼» (или «Тип: N выбрано» если выбраны)
- Click → inline dropdown (`absolute z-20 card shadow-md w-56 p-2`) со списком чекбоксов
- Список kinds: те же 8 групп из матрицы (approval_needed, deal_won, deal_stage_changed, task_assigned, task_status_changed, sla_breach, course_assigned, course_completed, contract_signed, mention, system, webhook_delivery_failed)
- Click outside → закрыть

**Период:**
- `<select className="input text-sm">` с опциями: «За всё время» / «Сегодня» / «Вчера» / «Эта неделя» / «Этот месяц» / «Прошлый месяц»
- Выбор маппится в `date_from` / `date_to` query params

**Сортировка:**
- `<select className="input text-sm">` «Новые сверху» / «Старые сверху» / «По приоритету»
- `sort=newest|oldest|priority` → query param

**Сброс фильтров:**
- `btn-ghost text-sm` «Сбросить» — появляется только когда хотя бы один фильтр не дефолтный
- Click → сбросить all state + offset=0

**Bulk «Прочитать всё на странице»:**
- `btn-ghost text-sm` в PageHeader actions (дополнительно к уже имеющемуся «Прочитать все»)
- Разница: «на странице» — POST markRead для ids из текущего `notifications[]`; «Прочитать все» — POST mark-all-read глобально
- Backend: PATCH `/api/notifications/bulk-read` body `{ids: number[]}` — требуется новый эндпоинт

### States

| Состояние | Поведение |
|---|---|
| Loading | 5 skeleton строк |
| Empty (нет уведомлений с фильтрами) | Иконка `bi-funnel text-5xl text-gray-300`, «Нет уведомлений с этими фильтрами», кнопка «Сбросить фильтры» |
| Empty (нет уведомлений вообще) | Иконка `bi-bell-slash text-5xl text-gray-300`, «Когда что-то важное произойдёт — ты увидишь это здесь» |
| Error | `text-danger text-sm` над card |

### Interactions

| Элемент | Действие | Результат |
|---|---|---|
| Multi-select Kind | click + чекбокс | добавить/убрать kind из фильтра, обновить SWR-ключ |
| «Период» select | change | обновить date range, offset=0 |
| «Sort» select | change | обновить sort, offset=0 |
| «☑ Непрочитанные» | toggle | обновить unread_only, offset=0 |
| «Сбросить» | click | сбросить все фильтры, offset=0 |
| «Прочитать всё на странице» | click | PATCH bulk-read с ids текущей страницы → mutate |
| «Прочитать все» (header) | click | POST mark-all-read → mutate |
| «Загрузить ещё» | click | offset += PAGE_LIMIT |

### Тексты (RU)

- Заголовок: `Уведомления`
- Кнопка bulk в header: `Прочитать всё на странице`
- Кнопка global: `Прочитать все`
- Select «Период»: `За всё время` / `Сегодня` / `Вчера` / `Эта неделя` / `Этот месяц` / `Прошлый месяц`
- Select «Sort»: `Новые сверху` / `Старые сверху` / `По приоритету`
- Кнопка Multi-select закрытый: `Тип: все` / `Тип: {N} выбрано`
- Кнопка сброса: `Сбросить фильтры`
- Empty с фильтрами title: `Нет уведомлений с этими фильтрами`
- Empty без фильтров: `Когда что-то важное произойдёт — ты увидишь это здесь`
- «Загрузить ещё»: `Загрузить ещё`
- Error: `Не удалось загрузить уведомления`

### Backend API

| Метод | URL | Назначение |
|---|---|---|
| GET | `/api/notifications?kind[]=...&date_from=...&date_to=...&unread_only=...&sort=...&limit=...&offset=...` | расширить query params |
| PATCH | `/api/notifications/bulk-read` | `{ids: number[]}` — **новый эндпоинт** |

**Требуется правка backend:** расширить GET params (date_from, date_to, sort, multiple kind[]) + новый PATCH `/api/notifications/bulk-read`.

---

## Раздел 4 — `/admin/notifications/broadcast` (новая страница)

**Зачем:** admin создаёт системные рассылки всей команде или выборочно по роли/отделу/пользователям.

**Где в коде:**
- `apps/web/src/app/(app)/admin/notifications/broadcast/page.tsx` — новый
- `apps/web/src/components/Notifications/BroadcastForm.tsx` — новый
- `apps/web/src/components/Notifications/RecipientSelector.tsx` — новый

### Wireframe

```
┌──────────────────────────────────────────────────────────────┐
│ [Sidebar] │ [PageHeader: Создать рассылку]                   │
│           ├─────────────────────────────────────────────────┤│
│           │ p-8 max-w-3xl                                   ││
│           │                                                 ││
│           │ ┌───────────────────────────────────────────┐   ││
│           │ │ card: «Сообщение»                         │   ││
│           │ │ label Заголовок *                         │   ││
│           │ │ [input]                                   │   ││
│           │ │ label Текст *                             │   ││
│           │ │ [textarea rows=5]                         │   ││
│           │ │ label Ссылка при клике                    │   ││
│           │ │ [input placeholder https://...]           │   ││
│           │ └───────────────────────────────────────────┘   ││
│           │                                                 ││
│           │ ┌───────────────────────────────────────────┐   ││
│           │ │ card: «Получатели»                        │   ││
│           │ │ (●) Все активные пользователи             │   ││
│           │ │ (○) По роли    → [RoleSelect]             │   ││
│           │ │ (○) По отделу  → [DepartmentSelect]       │   ││
│           │ │ (○) Конкретные пользователи               │   ││
│           │ │     → [UserMultiSelect]                   │   ││
│           │ │ ─────────────────────────────────────────│   ││
│           │ │ [badge info] Получит N человек            │   ││
│           │ └───────────────────────────────────────────┘   ││
│           │                                                 ││
│           │ ┌───────────────────────────────────────────┐   ││
│           │ │ card: «Каналы»                            │   ││
│           │ │ [☑] В приложении                          │   ││
│           │ │ [☑] Telegram (если бот настроен)          │   ││
│           │ │ [☑] Email                                 │   ││
│           │ └───────────────────────────────────────────┘   ││
│           │                                                 ││
│           │ ┌───────────────────────────────────────────┐   ││
│           │ │ card: «Время отправки»                    │   ││
│           │ │ (●) Отправить сейчас                      │   ││
│           │ │ (○) Запланировать → [datetime-local input]│   ││
│           │ └───────────────────────────────────────────┘   ││
│           │                                                 ││
│           │ [btn-ghost: Отмена]   [btn-primary: Отправить]  ││
└──────────────────────────────────────────────────────────────┘
```

### Компоненты

**`BroadcastForm`** — основная форма. Состояние:
```ts
{
  title: string
  body: string
  link: string
  recipient_mode: "all" | "role" | "department" | "users"
  role?: "admin" | "director" | "manager" | "lawyer"
  department_id?: number
  user_ids?: number[]
  channels: { in_app: boolean; tg: boolean; email: boolean }
  scheduled_at?: string   // ISO datetime или undefined (=сейчас)
  isSubmitting: boolean
  errors: Record<string, string>
}
```

**`RecipientSelector`** (`components/Notifications/RecipientSelector.tsx`) — карточка выбора получателей:
- Radio group с 4 вариантами
- При выборе роли: inline `<select className="input mt-2">` с вариантами `admin/director/manager/lawyer`
- При выборе отдела: SWR `GET /api/admin/departments` → `<select className="input mt-2">`
- При выборе пользователей: компонент `UserMultiSelect` (reuse из `/components/UserSelect.tsx` если есть, иначе простой multi-checkbox dropdown через SWR `GET /api/admin/users?is_active=true`)
- Preview «Получит N человек»: SWR `GET /api/admin/notifications/recipients-count?mode=...&...` — требуется backend эндпоинт. Пока: simple badge без запроса к backend («Все активные» — статичный текст, остальные — «N выбрано»)

**Datetime picker** — нативный `<input type="datetime-local" className="input">`. Минимальное значение — текущий момент (js `new Date().toISOString().slice(0,16)`).

**Валидация:**
- `title` — обязательно, минимум 3 символа
- `body` — обязательно, минимум 10 символов
- `link` — необязательно, если заполнено — валидный URL (startsWith `http`)
- `channels` — хотя бы один канал выбран
- При `recipient_mode = "role"` — role обязательна
- При `recipient_mode = "department"` — department_id обязателен
- При `recipient_mode = "users"` — хотя бы 1 пользователь

**После отправки:**
- redirect на `/admin/notifications/broadcasts`
- POST `/api/admin/notifications/broadcast`

### States

| Состояние | Поведение |
|---|---|
| Initial | форма пустая, кнопка disabled если не заполнено обязательное |
| Validation error | inline `text-danger text-xs` под каждым полем |
| Submitting | `btn-primary disabled` «Отправляем…» |
| Error API | inline `text-danger text-sm` под кнопками «Не удалось создать рассылку: {error}» |

### Interactions

| Элемент | Действие | Результат |
|---|---|---|
| Radio «По роли» | select | показать RoleSelect |
| Radio «По отделу» | select | показать DepartmentSelect (SWR) |
| Radio «Пользователи» | select | показать UserMultiSelect |
| Radio «Запланировать» | select | показать datetime-local picker |
| «Отмена» | click | navigate `/admin/notifications/broadcasts` |
| «Отправить рассылку» | click | validate → POST → redirect |

### Тексты (RU)

- Заголовок страницы: `Создать рассылку`
- Card «Сообщение» header: `Сообщение`
- label Заголовок: `Заголовок *`
- placeholder Заголовок: `Например: «Обновление системы в пятницу»`
- label Текст: `Текст *`
- placeholder Текст: `Напиши что хочешь донести до команды`
- label Ссылка: `Ссылка при клике`
- placeholder Ссылка: `https://...`
- Card «Получатели» header: `Получатели`
- Radio 1: `Все активные пользователи`
- Radio 2: `По роли`
- Radio 3: `По отделу`
- Radio 4: `Конкретные пользователи`
- Preview получателей: `Получит {N} человек` / `Все активные пользователи`
- Card «Каналы» header: `Каналы доставки`
- Checkbox In-App: `В приложении`
- Checkbox TG: `Telegram`
- Checkbox Email: `Email`
- Card «Время» header: `Время отправки`
- Radio now: `Отправить сейчас`
- Radio scheduled: `Запланировать на`
- Кнопка отмены: `Отмена`
- Кнопка отправки: `Отправить рассылку`
- Loading кнопки: `Отправляем…`
- Error: `Не удалось создать рассылку`
- Validation title: `Введи заголовок (минимум 3 символа)`
- Validation body: `Введи текст сообщения`
- Validation channels: `Выбери хотя бы один канал`
- Validation users: `Выбери хотя бы одного получателя`

### Backend API

| Метод | URL | Назначение |
|---|---|---|
| POST | `/api/admin/notifications/broadcast` | создать рассылку |
| GET | `/api/admin/departments` | список отделов для SelectBox |
| GET | `/api/admin/users?is_active=true&limit=200` | список пользователей |

---

## Раздел 5 — `/admin/notifications/broadcasts` (история рассылок)

**Зачем:** видеть что отправлено, кому, с каким результатом.

**Где в коде:**
- `apps/web/src/app/(app)/admin/notifications/broadcasts/page.tsx` — новый
- `apps/web/src/app/(app)/admin/notifications/broadcasts/[id]/page.tsx` — новый (детали)

### Wireframe

```
┌──────────────────────────────────────────────────────────────┐
│ [Sidebar] │ [PageHeader: История рассылок] [+ Создать]        │
│           ├─────────────────────────────────────────────────┤│
│           │ p-8 space-y-4                                   ││
│           │ ┌──────────────────────────────────────────┐    ││
│           │ │ card overflow-hidden                     │    ││
│           │ │ table w-full text-sm                     │    ││
│           │ │ ┌──────────┬──────┬──────┬──────┬───────┐│    ││
│           │ │ │ Заголовок│Кому  │Каналы│Отправил│Когда ││    ││
│           │ │ ├──────────┼──────┼──────┼──────┼───────┤│    ││
│           │ │ │ Итоги Q2…│ Все  │●●○  │Богдан│12.06  ││    ││
│           │ │ │ …        │      │     │      │       ││    ││
│           │ │ └──────────┴──────┴──────┴──────┴───────┘│    ││
│           │ └──────────────────────────────────────────┘    ││
└──────────────────────────────────────────────────────────────┘
```

### Таблица

Карточка `card overflow-hidden`. Таблица `w-full text-sm`.

Колонки:
| Колонка | Ширина | Содержимое |
|---|---|---|
| Заголовок | `flex-1` | title truncated + первые 60 символов body в `text-xs text-gray-500` |
| Получатели | `w-32` | «Все» / «Роль: admin» / «Отдел: ...» / «N польз.» |
| Каналы | `w-28` | chips `badge text-xs`: `In-App` `bg-info/10 text-info` / `TG` `bg-success/10 text-success` / `Email` `bg-warning/10 text-warning` |
| Отправил | `w-28` | имя пользователя (first_name last_name) |
| Когда | `w-28` | дата в `DD.MM.YYYY HH:mm` |
| Статус | `w-28` | badge: `pending` `bg-info/10 text-info` / `running` `bg-warning/10 text-warning` / `completed` `bg-success/10 text-success` / `failed` `bg-danger/10 text-danger` |
| Доставлено | `w-28` | «47 / 100» — `text-success font-medium` если completed, иначе прогресс |

Строка кликабельная → `/admin/notifications/broadcasts/{id}`.

**Страница детали `/admin/notifications/broadcasts/[id]`:**
- `PageHeader`: заголовок рассылки + badge статуса
- Карточка с полями: Текст / Ссылка / Получатели / Каналы / Запланировано / Отправлено / Создал / Доставлено N/Total / Ошибок M
- Progress bar если `status = "running"` (ширина = `(delivered_count / total_recipients) * 100%`)
- Таблица delivery log если backend поддерживает (опционально — отметить в открытых вопросах)

### States

| Состояние | Поведение |
|---|---|
| Loading | 5 skeleton строк |
| Empty | `bi-megaphone text-5xl text-gray-300`, «Рассылок пока не было», btn-primary «Создать первую» |
| Error | `text-danger text-sm` над таблицей |

### Тексты (RU)

- Заголовок: `История рассылок`
- Кнопка создания: `Создать рассылку`
- Колонки: `Рассылка` / `Получатели` / `Каналы` / `Отправил` / `Когда` / `Статус` / `Доставлено`
- Статус pending: `Ожидает`
- Статус running: `Отправляется`
- Статус completed: `Отправлено`
- Статус failed: `Ошибка`
- Empty title: `Рассылок пока не было`
- Empty description: `Создай первую, чтобы уведомить всю команду`
- «Все активные»: `Все`

### Backend API

| Метод | URL | Назначение |
|---|---|---|
| GET | `/api/admin/notifications/broadcasts?limit=50&offset=0` | список |
| GET | `/api/admin/notifications/broadcasts/{id}` | детали |

---

## Раздел 6 — `/admin/notification-templates` (шаблоны)

**Зачем:** admin правит тексты уведомлений per-kind per-channel без деплоя.

**Где в коде:**
- `apps/web/src/app/(app)/admin/notification-templates/page.tsx` — новый
- `apps/web/src/components/Notifications/TemplateEditModal.tsx` — новый

### Wireframe

```
┌──────────────────────────────────────────────────────────────┐
│ [Sidebar] │ [PageHeader: Шаблоны уведомлений]               │
│           ├─────────────────────────────────────────────────┤│
│           │ p-8 space-y-4                                   ││
│           │ ┌──────────────────────────────────────────┐    ││
│           │ │ Фильтры: [Kind ▼] [Канал ▼] [Locale ▼]  │    ││
│           │ └──────────────────────────────────────────┘    ││
│           │ ┌──────────────────────────────────────────┐    ││
│           │ │ card overflow-hidden                     │    ││
│           │ │ Kind | Канал | Locale | Subject | ✎      │    ││
│           │ │ task_assigned | in_app | ru | «Новая...» | ✎  ││
│           │ │ task_assigned | tg    | ru | «Задача...» | ✎  ││
│           │ │ ...                                      │    ││
│           │ └──────────────────────────────────────────┘    ││
└──────────────────────────────────────────────────────────────┘
```

### Таблица шаблонов

Колонки: Kind (лейбл) / Канал (badge) / Locale / Subject (truncated 60 символов) / Действия

Действия: иконка `bi-pencil text-primary cursor-pointer` → открыть `TemplateEditModal`.

**Фильтры** — 3 `<select className="input text-sm">`:
- Kind: «Все типы» + список kinds (label из матрицы)
- Канал: «Все» / «In-App» / «Telegram» / «Email»
- Locale: «Все» / «ru» / «en»

### TemplateEditModal

Модал через существующий `Modal` компонент.

Поля:
- **Kind + Channel + Locale** — read-only badges вверху, не редактируемые
- **Subject** — `<textarea className="input w-full" rows={2}>` — тема письма / заголовок пуш
- **Body** — `<textarea className="input w-full font-mono text-sm" rows={10}>` — Jinja-шаблон. Без highlight синтаксиса (нет либ в стеке). Placeholder-подсказка «Используй {{ variable_name }} для переменных»
- **Variables** — read-only блок ниже: список `variables` из БД, badge `bg-gray-100 dark:bg-gray-700 text-xs font-mono` для каждой. Заголовок «Доступные переменные:»
- **Preview** — кнопка `btn-secondary` «Предпросмотр» → рендерит body с fake data (POST `/api/admin/notification-templates/{id}/preview` body `{}`) → показывает результат в `<pre className="bg-gray-50 dark:bg-gray-800 rounded p-3 text-xs overflow-auto max-h-40 mt-3">`

Кнопки модала: `[btn-ghost: Отмена]` + `[btn-primary: Сохранить]`

PATCH `/api/admin/notification-templates/{id}` body `{subject, body_template}`

### States

| Состояние | Поведение |
|---|---|
| Loading таблицы | 6 skeleton строк |
| Empty | `bi-file-earmark-text text-5xl text-gray-300`, «Шаблоны не найдены» |
| Loading preview | `bi-arrow-clockwise animate-spin` справа от кнопки |
| Save error | inline `text-danger text-xs` под кнопками в модале |

### Тексты (RU)

- Заголовок: `Шаблоны уведомлений`
- Фильтр Kind placeholder: `Все типы`
- Фильтр Channel placeholder: `Все каналы`
- Фильтр Locale placeholder: `Все локали`
- Колонки: `Тип` / `Канал` / `Язык` / `Заголовок` / `Действия`
- Modal title: `Редактировать шаблон`
- label Subject: `Заголовок / тема`
- label Body: `Тело шаблона (Jinja2)`
- Placeholder body: `Используй {{ variable_name }} для подстановки переменных`
- label Variables: `Доступные переменные:`
- Кнопка preview: `Предпросмотр`
- Кнопка отмены: `Отмена`
- Кнопка сохранения: `Сохранить`
- Loading save: `Сохраняем…`
- Empty: `Шаблоны не найдены`

### Backend API

| Метод | URL | Назначение |
|---|---|---|
| GET | `/api/admin/notification-templates?kind=...&channel=...&locale=...` | список |
| PATCH | `/api/admin/notification-templates/{id}` | обновить subject + body |
| POST | `/api/admin/notification-templates/{id}/preview` | рендер с fake data |

**Требуется правка backend:** все три эндпоинта — новые.

---

## Обновление Sidebar

**Файл:** `apps/web/src/components/Sidebar.tsx`

В массив `ADMIN_ITEMS` добавить 3 новых пункта (рекомендуемое место — перед существующим «Webhooks»):

```
{ href: "/admin/notifications/broadcasts", icon: "bi-megaphone", label: "Рассылки", roles: ["admin", "director"] }
{ href: "/admin/notification-templates", icon: "bi-file-earmark-text", label: "Шаблоны уведомлений", roles: ["admin"] }
{ href: "/admin/integrations/logs", ... }  // существующий, для ориентира
```

Без вложенной навигации — в нашем паттерне нет аккордионных под-пунктов в ADMIN_ITEMS, просто добавляем плоские ссылки.

Иконки:
- Рассылки: `bi-megaphone`
- Шаблоны уведомлений: `bi-file-earmark-text`

---

## Новые компоненты — сводная таблица

| Компонент | Файл | Зачем |
|---|---|---|
| `ChannelPreferencesMatrix` | `components/Notifications/ChannelPreferencesMatrix.tsx` | матрица kind × channel на `/profile/notifications` |
| `QuietHoursToggle` | `components/Notifications/QuietHoursToggle.tsx` | toggle + time pickers тихих часов |
| `BroadcastForm` | `components/Notifications/BroadcastForm.tsx` | форма создания рассылки |
| `RecipientSelector` | `components/Notifications/RecipientSelector.tsx` | выбор получателей broadcast |
| `TemplateEditModal` | `components/Notifications/TemplateEditModal.tsx` | модал редактирования Jinja-шаблона |

**Reuse существующих:**
- `Modal` — для `TemplateEditModal`
- `PageHeader` — все страницы
- `NotificationItem` — без изменений
- `useNotifications` hook — дополнить функцией `bulkMarkRead(ids: number[])`

---

## Адаптивность

Desktop-first. Аудитория — команда на ноутбуках.

**Матрица настроек на mobile:** `overflow-x-auto` на враппере таблицы, таблица `min-w-[480px]`. Колонки каналов фиксированные `w-20`, лейблы-строки скроллятся горизонтально.

**Broadcast-форма на mobile:** стек `flex-col`, datetime-picker нативный (браузерный UI).

Mobile-responsive — TBD (эпик 10), сейчас не приоритет.

---

## Открытые вопросы

1. **Email-провайдер:** SendGrid / Postmark / self-hosted Postfix? Без ответа нельзя реализовать `services/notification_email.py`. Блокирует колонку Email в матрице.

2. **Defaults для матрицы:** если у юзера нет записей в `notification_channel_preferences` — возвращать ли backend defaults (все `in_app=true`, `tg=true`, `email=false`) или пустой массив? Frontend ждёт defaults из backend.

3. **Bulk-read эндпоинт:** нужен PATCH `/api/notifications/bulk-read` — отметить backend-specialist. Временно кнопка «Прочитать всё на странице» может вызывать существующий mark-all-read (менее точно).

4. **Delivery log в деталях broadcast:** нужна ли таблица «кому / когда / статус»? Если да — потребует отдельной таблицы `broadcast_delivery_log` в миграции 0061.

5. **Preview recipients count:** эндпоинт `GET /api/admin/notifications/recipients-count?mode=...` — нужен для живого «Получит N человек». Без него — только статичный текст.

6. **TemplateEditModal preview:** POST `/api/admin/notification-templates/{id}/preview` нужен бэкенду. Если сложно — можно убрать preview из v1.

7. **Scheduled broadcast:** если `scheduled_at` передан — кто запускает рассылку? Нужен cron/celery или endpoint для ручного trigger?

8. **Локали шаблонов:** в ТЗ заложен filter по locale. Если en-шаблоны пока не заполнены — на UI фильтр locale показывать только `ru` пока backend не вернёт других значений.
