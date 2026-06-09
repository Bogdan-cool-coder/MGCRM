# ТЗ: Эпик 21 — UX Upgrade (Тёмная тема + Notification Center + Профиль 2.0)

**Версия:** 1.0
**Дата:** 2026-06-02
**Автор:** designer
**Исполнитель:** frontend-specialist
**Зависит от:** Эпик 16 (Security / `/profile/security`)

---

## Cover

### Цель

Три взаимосвязанных UX-улучшения, которые делают MACRO CRM комфортнее в ежедневной работе:

1. **Тёмная тема** — Tailwind `dark:` поверх существующей светлой темы. Переключатель в Header, `ThemeProvider`, persist в `localStorage` + `User.theme_preference` в БД.
2. **Notification Center** — иконка-колокольчик `bi-bell` в Header с badge непрочитанных, dropdown со списком, SWR-polling каждые 30 сек, страница `/notifications` с фильтрами.
3. **Профиль 2.0** — рефакторинг `/profile` в 6 секций через TabBar. Новые секции: Подпись, Тема, Локализация. Секция Безопасность интегрирует уже созданный в Эпике 16 экран `/profile/security`.

### Что меняется в файловой структуре

**Новые файлы:**
- `apps/web/src/contexts/ThemeContext.tsx` — контекст темы
- `apps/web/src/components/ThemeToggle.tsx` — кнопка переключения
- `apps/web/src/components/Header.tsx` — новый компонент шапки (сейчас Header встроен в AppLayout)
- `apps/web/src/components/Notifications/NotificationBell.tsx`
- `apps/web/src/components/Notifications/NotificationDropdown.tsx`
- `apps/web/src/components/Notifications/NotificationItem.tsx`
- `apps/web/src/app/(app)/notifications/page.tsx`
- `apps/web/src/app/(app)/profile/signature/page.tsx`
- `apps/web/src/app/(app)/profile/theme/page.tsx`
- `apps/web/src/app/(app)/profile/locale/page.tsx`
- `apps/web/src/app/(app)/profile/notifications/page.tsx`

**Модифицируются:**
- `apps/web/src/app/layout.tsx` — добавить `ThemeProvider` + anti-flash script
- `apps/web/src/app/(app)/layout.tsx` — вставить `Header` компонент поверх `<main>`
- `apps/web/src/app/(app)/profile/page.tsx` — рефакторинг в TabBar-структуру
- `apps/web/src/components/Sidebar.tsx` — добавить ссылки на подразделы профиля в нижний блок
- `apps/web/src/app/globals.css` — добавить `dark:` варианты для `.card`, `.input`, `.label`, `.btn-*`, `.badge`
- `apps/web/tailwind.config.ts` — добавить `darkMode: 'class'`
- `apps/web/src/lib/types.ts` — расширить интерфейс `User` новыми полями

### Координация с Эпиком 16

Эпик 16 создал `/profile/security` как отдельную страницу. Эпик 21 **не трогает** `/profile/security/page.tsx` — только добавляет ссылку на неё в TabBar профиля. Секция «Безопасность» в Профиле 2.0 = ссылка + краткий preview-блок с текущим статусом 2FA.

---

## Раздел 1: Тёмная тема

### Wireframe — переключатель в Header

```
┌────────────────────────────────────────────────────────────────┐
│ [Sidebar 240px]  │  [Header: sticky top]                       │
│                  │  ┌──────────────────────────────────────┐   │
│                  │  │ [PageHeader title]     [bi-bell 🔴3] │   │
│                  │  │                  [bi-sun/bi-moon]     │   │
│                  │  │                  [Avatar → /profile]  │   │
│                  │  └──────────────────────────────────────┘   │
│                  │  [content area]                             │
└────────────────────────────────────────────────────────────────┘
```

**Важно:** Сейчас Header не существует как отдельный компонент — `PageHeader` встроен в каждую страницу, а шапки с аватаром нет. Нужно создать новый `Header` (sticky, поверх content area) и переосмыслить layout.

### Layout после изменений

```
(app)/layout.tsx
  └─ ThemeProvider
       └─ div.flex.min-h-screen (bg-gray-100 dark:bg-gray-900)
            ├─ Sidebar (w-[240px], sticky)
            └─ div.flex-1.flex.flex-col
                 ├─ Header (sticky top-0, h-14, z-30)   ← НОВЫЙ
                 └─ main (flex-1 min-w-0)
                      └─ {children}
```

### ThemeProvider — логика

**Файл:** `apps/web/src/contexts/ThemeContext.tsx`

Состояния: `'light' | 'dark' | 'system'`

Логика применения при mount:
1. Читаем `localStorage.getItem('crm_theme')` — приоритет 1
2. Если нет → `User.theme_preference` из `useMe()` — приоритет 2
3. Если нет → `window.matchMedia('(prefers-color-scheme: dark)').matches` — приоритет 3
4. Применяем/убираем класс `dark` на `document.documentElement`

При смене темы пользователем:
- Сохраняем в `localStorage` немедленно (instant UI)
- Вызываем `PATCH /api/users/me` с `{ theme_preference }` (debounced 1s, fire-and-forget)

**Anti-flash script** (инжектировать в `<head>` до загрузки body в `app/layout.tsx`):
```html
<script dangerouslySetInnerHTML={{ __html: `
  (function(){
    var t=localStorage.getItem('crm_theme');
    var sys=window.matchMedia('(prefers-color-scheme:dark)').matches;
    if(t==='dark'||(t!=='light'&&sys)){document.documentElement.classList.add('dark')}
  })()
`}} />
```
Этот скрипт не блокирует рендер (inline, маленький), но убирает flash при загрузке.

### ThemeToggle компонент

**Файл:** `apps/web/src/components/ThemeToggle.tsx`

- Кнопка `btn-ghost` без текста, только иконка
- Светлая тема → иконка `bi-moon` (клик переключает на тёмную)
- Тёмная тема → иконка `bi-sun` (клик переключает на светлую)
- `title="Переключить тему"` для tooltip
- Размер: `text-lg` иконка, `p-2 rounded-md`

### Header компонент

**Файл:** `apps/web/src/components/Header.tsx`

```
┌──────────────────────────────────────────────────────────────┐
│ sticky top-0 h-14 bg-white dark:bg-gray-800                 │
│ border-b border-gray-200 dark:border-gray-700               │
│ px-6 flex items-center justify-between z-30                 │
│                                                              │
│ [left: пусто или breadcrumb в будущем]  [right: actions]    │
│                                         ├ NotificationBell  │
│                                         ├ ThemeToggle       │
│                                         └ Avatar → /profile │
└──────────────────────────────────────────────────────────────┘
```

Правая группа (`flex items-center gap-3`):
- `NotificationBell` (см. Раздел 2)
- `ThemeToggle`
- `Avatar` с `Link href="/profile"` — userId, name, hasAvatar из `useMe()`

**Замечание по PageHeader:** Существующий `PageHeader` компонент остаётся без изменений — он рендерится внутри каждой страницы и отображает заголовок раздела. Новый `Header` — это отдельный верхний бар с системными действиями (уведомления, тема, профиль). Они не дублируют друг друга.

### Tailwind dark: — таблица цветов

| Элемент | Light | Dark |
|---|---|---|
| Фон страницы | `bg-gray-100` | `dark:bg-gray-900` |
| Sidebar фон | `bg-white` | `dark:bg-gray-800` |
| Sidebar border | `border-gray-200` | `dark:border-gray-700` |
| Sidebar nav item inactive text | `text-gray-700` | `dark:text-gray-300` |
| Sidebar nav item inactive hover | `hover:bg-gray-100` | `dark:hover:bg-gray-700` |
| Sidebar nav item active | `bg-primary text-white` | `bg-primary text-white` (без изменений) |
| Sidebar section label | `text-gray-400` | `dark:text-gray-500` |
| Header фон | `bg-white` | `dark:bg-gray-800` |
| Header border | `border-gray-200` | `dark:border-gray-700` |
| `.card` | `bg-white border-gray-200` | `dark:bg-gray-800 dark:border-gray-700` |
| `.input` | `bg-white border-gray-300 text-primary` | `dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 dark:placeholder-gray-400` |
| `.label` | `text-gray-700` | `dark:text-gray-300` |
| `.btn-secondary` | `bg-white text-primary border-gray-300 hover:bg-gray-100` | `dark:bg-gray-700 dark:text-gray-100 dark:border-gray-600 dark:hover:bg-gray-600` |
| `.btn-ghost` | `text-primary hover:bg-gray-200` | `dark:text-gray-300 dark:hover:bg-gray-700` |
| `.badge` | без изменений — семантические цвета работают в обоих режимах | (то же) |
| Modal overlay | `bg-black/40` | (то же) |
| Modal body | `bg-white` | `dark:bg-gray-800` |
| Modal header border | `border-gray-200` | `dark:border-gray-700` |
| Modal footer | `bg-gray-50` | `dark:bg-gray-900` |
| Table header | `bg-gray-50 text-gray-500` | `dark:bg-gray-700 dark:text-gray-400` |
| Table row hover | `hover:bg-gray-50` | `dark:hover:bg-gray-700` |
| Table border | `border-gray-200` | `dark:border-gray-700` |
| Text primary | `text-primary` (#172747) | `dark:text-gray-100` |
| Text secondary | `text-gray-600` | `dark:text-gray-400` |
| Text muted | `text-gray-400` | `dark:text-gray-500` |
| PageHeader bg | `bg-white border-gray-200` | `dark:bg-gray-800 dark:border-gray-700` |
| SearchModal bg | `bg-white` | `dark:bg-gray-800` |

### Как обновить globals.css

Добавить `dark:` варианты в `@layer components`:

```css
/* Пример патча — точная реализация за frontend-specialist */
.card {
  @apply rounded-lg bg-white border border-gray-200 shadow-sm
         dark:bg-gray-800 dark:border-gray-700;
}
.input {
  @apply w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-[16px]
         focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary
         dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100
         dark:placeholder-gray-400 dark:focus:border-primary-light;
}
.label {
  @apply text-sm font-medium text-gray-700 mb-1 block dark:text-gray-300;
}
.btn-secondary {
  @apply btn bg-white text-primary border border-gray-300 hover:bg-gray-100 px-4 py-2
         dark:bg-gray-700 dark:text-gray-100 dark:border-gray-600 dark:hover:bg-gray-600;
}
.btn-ghost {
  @apply btn text-primary hover:bg-gray-200 px-3 py-1.5
         dark:text-gray-300 dark:hover:bg-gray-700;
}
```

**tailwind.config.ts** — добавить перед `theme`:
```ts
darkMode: 'class',
```

### Приоритет компонентов для dark: (в порядке реализации)

1. `globals.css` — базовые классы (`.card`, `.input`, `.label`, `.btn-*`)
2. `Sidebar.tsx` — фон, текст, hover пунктов, секции
3. `Header.tsx` (новый компонент) — изначально с dark: классами
4. `Modal.tsx` — overlay, body, footer, close button
5. `PageHeader.tsx` — фон, текст, border
6. `SearchModal.tsx` — фон результатов, hover
7. Таблицы в `/registry`, `/deals`, `/contracts`, `/counterparties` — header, row, border
8. Формы на страницах деталей сущностей

### States

- **Loading темы:** `ThemeProvider` применяет тему синхронно (anti-flash script) — нет loading state
- **Ошибка сохранения в БД:** игнорировать тихо (fire-and-forget), localStorage уже обновлён

### Interactions

| Элемент | Действие | Результат |
|---|---|---|
| `ThemeToggle` в Header | click | мгновенно переключает `dark` класс на `<html>`, обновляет localStorage, PATCH /api/users/me debounced |
| `<html class="dark">` | страница перезагружается | anti-flash script применяет тему до рендера — нет мерцания |
| `/profile/theme` радио | change | то же что ThemeToggle, но с 3 вариантами (light/dark/system) |

### Тексты (RU)

- Tooltip переключателя: `Переключить тему`
- Aria-label кнопки: `Светлая тема` / `Тёмная тема` (динамически)

### Backend (требуется правка backend)

- Новое поле: `User.theme_preference VARCHAR(16) DEFAULT 'system' CHECK (theme_preference IN ('light', 'dark', 'system'))`
- Алembic миграция: `ALTER TABLE users ADD COLUMN theme_preference VARCHAR(16) DEFAULT 'system'`
- Расширить `PATCH /api/users/me` body: принимать `theme_preference`
- Расширить `GET /api/auth/me` response: возвращать `theme_preference`
- Расширить `User` interface в `apps/web/src/lib/types.ts`: добавить `theme_preference?: 'light' | 'dark' | 'system' | null`

### Known Limitation

OnlyOffice DocEditor (`/admin/templates/master-skeleton/edit`) не управляется нашим CSS — он рендерится в iframe. Принимаем как known limitation. В тёмной теме DocEditor остаётся светлым.

---

## Раздел 2: Notification Center

### Wireframe — Header с bell + dropdown

```
┌─────────────────────────────────────────────────────────┐
│ Header                                                  │
│                        [bi-bell]🔴3  [bi-sun]  [Avatar] │
│                              │                          │
│                              ▼ (click opens dropdown)   │
│                    ┌─────────────────────┐              │
│                    │ Уведомления   [Прочитать все] │     │
│                    ├─────────────────────┤              │
│                    │ ● [icon] Заголовок  │              │
│                    │   описание текст    │              │
│                    │   5 минут назад →   │              │
│                    ├─────────────────────┤              │
│                    │   [icon] Заголовок  │              │
│                    │   описание текст    │              │
│                    │   вчера в 14:30     │              │
│                    ├─────────────────────┤              │
│                    │ Все уведомления →   │              │
│                    └─────────────────────┘              │
└─────────────────────────────────────────────────────────┘
```

### Wireframe — страница /notifications

```
┌────────────────────────────────────────────────────────┐
│ [Sidebar] │ [PageHeader: Уведомления]  [Прочитать все] │
│           ├────────────────────────────────────────────┤
│           │ [Фильтры: все типы ▼]  [только непрочит.]  │
│           ├────────────────────────────────────────────┤
│           │ ┌────────────────────────────────────────┐ │
│           │ │ ● [icon] Заголовок              время  │ │
│           │ │   Описание текста...          [Читать] │ │
│           │ ├────────────────────────────────────────┤ │
│           │ │   [icon] Заголовок              время  │ │
│           │ │   Описание текста...                   │ │
│           │ └────────────────────────────────────────┘ │
└────────────────────────────────────────────────────────┘
```

### Композиция

- `apps/web/src/components/Notifications/NotificationBell.tsx` — кнопка + badge + монтирует dropdown
- `apps/web/src/components/Notifications/NotificationDropdown.tsx` — выпадающий список
- `apps/web/src/components/Notifications/NotificationItem.tsx` — одна строка уведомления
- `apps/web/src/app/(app)/notifications/page.tsx` — полная страница
- `apps/web/src/hooks/useNotifications.ts` — SWR хук

### NotificationBell

**Файл:** `apps/web/src/components/Notifications/NotificationBell.tsx`

```
<button className="relative p-2 rounded-md hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-600 dark:text-gray-400">
  <i className="bi bi-bell text-lg" />
  {unreadCount > 0 && (
    <span className="absolute -top-0.5 -right-0.5 bg-danger text-white text-[10px]
                     font-bold rounded-full min-w-[18px] h-[18px] flex items-center
                     justify-center px-1">
      {unreadCount > 99 ? '99+' : unreadCount}
    </span>
  )}
</button>
```

- SWR: `GET /api/notifications/unread-count` с `refreshInterval: 30_000`
- При click → toggleDropdown (state `isOpen`)
- Dropdown закрывается: клик вне dropdown, Escape, переход по ссылке

### NotificationDropdown

**Файл:** `apps/web/src/components/Notifications/NotificationDropdown.tsx`

- Позиционирование: `absolute right-0 top-full mt-2 w-[380px] z-50`
- Внешний контейнер: `card shadow-lg` (подхватит dark: от globals.css)
- Максимальная высота: `max-h-[480px] overflow-y-auto`
- Закрытие по клику вне — `useEffect` с `mousedown` listener на `document`

Структура:
```
┌── header (px-4 py-3, border-b) ──────────────────┐
│ «Уведомления»  font-semibold text-primary        │
│ [btn-ghost text-sm «Прочитать все»]               │
├── list ──────────────────────────────────────────┤
│ NotificationItem × N (max 20)                    │
│ Loading: 3 skeleton строки animate-pulse          │
│ Empty: bi-bell-slash + «Всё прочитано»            │
├── footer (px-4 py-3, border-t) ─────────────────┤
│ Link → /notifications  «Все уведомления →»        │
│ text-sm text-primary hover:underline              │
└──────────────────────────────────────────────────┘
```

### NotificationItem

**Файл:** `apps/web/src/components/Notifications/NotificationItem.tsx`

```
<div className={`flex gap-3 px-4 py-3 border-b border-gray-100 dark:border-gray-700
                 hover:bg-gray-50 dark:hover:bg-gray-700 cursor-pointer transition-colors
                 ${!item.is_read ? 'bg-info/5' : ''}`}
     onClick={handleClick}>
  {/* Unread dot */}
  {!item.is_read && (
    <div className="shrink-0 mt-1.5 w-2 h-2 rounded-full bg-primary" />
  )}
  {/* Kind icon */}
  <div className="shrink-0 w-8 h-8 rounded-full flex items-center justify-center
                  bg-gray-100 dark:bg-gray-700 text-base">
    <i className={`bi ${kindIcon(item.kind)}`} />
  </div>
  {/* Text */}
  <div className="flex-1 min-w-0">
    <div className="text-sm font-medium text-primary dark:text-gray-100 leading-tight">
      {item.title}
    </div>
    {item.body && (
      <div className="text-xs text-gray-500 dark:text-gray-400 mt-0.5 line-clamp-2">
        {item.body}
      </div>
    )}
    <div className="text-xs text-gray-400 dark:text-gray-500 mt-1">
      {formatRelativeTime(item.created_at)}
    </div>
  </div>
</div>
```

**handleClick:** если `!item.is_read` → `POST /api/notifications/{id}/read` → mutate SWR → если `item.link` — navigate туда. Если уже прочитано — сразу navigate.

**kindIcon** — маппинг `kind → bi-* класс`:
| kind | иконка | смысл |
|---|---|---|
| `approval_pending` | `bi-hourglass-split text-warning` | договор ждёт согласования |
| `approval_result` | `bi-check-circle-fill text-success` | договор согласован/отклонён |
| `deal_stage_change` | `bi-kanban text-info` | этап сделки изменился |
| `activity_due_soon` | `bi-clock-fill text-warning` | задача скоро наступит |
| `onboarding_overdue` | `bi-mortarboard-fill text-danger` | просрочен курс |
| `webhook_delivery_failed` | `bi-broadcast text-danger` | webhook недоступен |
| default | `bi-bell-fill text-gray-400` | прочее |

**formatRelativeTime** — локальная утилита без зависимостей:
- < 1 мин → «только что»
- < 60 мин → «X минут назад»
- < 24 ч → «X часов назад»
- вчера → «вчера в HH:MM»
- старше → «DD.MM в HH:MM»

### useNotifications хук

**Файл:** `apps/web/src/hooks/useNotifications.ts`

```ts
// SWR ключи:
// /api/notifications?limit=20         — для dropdown
// /api/notifications/unread-count     — для badge
// /api/notifications?limit=50&...     — для страницы /notifications
```

- `refreshInterval: 30_000` для unread-count
- `refreshInterval: 60_000` для полного списка
- Экспортирует: `{ notifications, unreadCount, markRead, markAllRead, isLoading }`

**Замечание по SSE:** Plan Obsidian упоминает SSE как возможность. В текущем ТЗ принимаем решение **только SWR polling** (30s для badge, 60s для списка) — достаточно при нашем масштабе. SSE добавить позже как upgrade если latency станет проблемой. Это согласуется с Open Questions в Obsidian-плане.

### Страница /notifications

**Файл:** `apps/web/src/app/(app)/notifications/page.tsx`

Полная страница с фильтрами:

```
PageHeader title="Уведомления"
  actions: [btn-ghost «Прочитать все» → POST /api/notifications/read-all]

Filters (flex gap-3, pb-4):
  - Select «Тип»: все / approval_pending / deal_stage_change / activity_due_soon /
                  onboarding_overdue / webhook_delivery_failed
  - Toggle «Только непрочитанные» (checkbox-style toggle)

Card (список):
  - Loading: 5 skeleton строк
  - Empty: bi-bell-slash (4rem), «Нет уведомлений», описание
  - List: NotificationItem × N, infinite scroll или «Загрузить ещё» (limit=50, offset)
```

### State machine — NotificationDropdown

```
CLOSED
  │ click на bi-bell
  ▼
OPEN (loading)
  - SWR запрос GET /api/notifications?limit=20
  │ данные получены
  ▼
OPEN (loaded)
  - список NotificationItem
  │ click «Прочитать все»
  ▼
MARKING_ALL (оптимистично обновляем UI)
  - POST /api/notifications/read-all
  - mutate SWR unread-count → 0
  - все items → is_read=true
  │
  ▼
OPEN (loaded, все прочитаны)
  │ click вне / Escape
  ▼
CLOSED
```

### API контракты (Notification Center)

| Метод | Путь | Описание |
|---|---|---|
| `GET` | `/api/notifications?limit=20&unread_only=false` | список уведомлений |
| `GET` | `/api/notifications/unread-count` | `{ count: number }` |
| `POST` | `/api/notifications/{id}/read` | mark one read |
| `POST` | `/api/notifications/read-all` | mark all read |

**Response shape** `GET /api/notifications`:
```json
[
  {
    "id": 1,
    "kind": "approval_pending",
    "title": "Договор #42 ожидает согласования",
    "body": "Контрагент: ООО Ромашка",
    "link": "/contracts/42",
    "is_read": false,
    "created_at": "2026-06-02T09:15:00Z"
  }
]
```

**Backend (требуется правка backend):**
- Новая таблица `notifications` (миграция Alembic с `pg_advisory_xact_lock`)
- 4 эндпоинта выше
- Dispatch-функция `notify_user(user_id, kind, title, body, link)` — вызывается из существующих роутеров при событиях
- Первый dispatch: при `Approval.status` → `approved`/`rejected` уведомить автора договора

**Новый тип в types.ts:**
```ts
export interface Notification {
  id: number;
  kind: string;
  title: string;
  body?: string | null;
  link?: string | null;
  is_read: boolean;
  created_at: string;
}
```

### Адаптивность

Desktop-first. Dropdown имеет `w-[380px]` — на экранах < 480px превращается в bottom sheet или full-width panel (mobile — TBD, эпик 10).

### States

- **Loading dropdown:** 3 skeleton строки `animate-pulse h-12 bg-gray-100 rounded mx-4 my-2`
- **Empty (всё прочитано):** `bi-bell-slash` (2rem, `text-gray-300`), текст «Всё прочитано»
- **Error:** тихо (не показываем error state в bell — лучше badge = 0 чем битый UI)
- **Loading страницы /notifications:** 5 skeleton строк в card

### Тексты (RU)

- Заголовок dropdown: `Уведомления`
- Кнопка bulk: `Прочитать все`
- Footer link: `Все уведомления`
- Заголовок страницы: `Уведомления`
- Фильтр типа placeholder: `Все типы`
- Toggle фильтра: `Только непрочитанные`
- Empty-state title: `Нет уведомлений`
- Empty-state описание: `Когда что-то важное произойдёт — ты увидишь это здесь`
- Loading: `Загружаем уведомления…`
- Кнопка «ещё»: `Загрузить ещё`
- Aria-label bell кнопки (непрочитанные > 0): `Уведомления, {N} непрочитанных`
- Aria-label bell кнопки (прочитаны): `Уведомления`

---

## Раздел 3: Профиль 2.0

### Wireframe — структура с TabBar

```
┌────────────────────────────────────────────────────────┐
│ [Sidebar] │ [PageHeader: Профиль]                      │
│           ├────────────────────────────────────────────┤
│           │ ┌──────────────────────────────────────┐   │
│           │ │ [Личное] [Подпись] [Тема] [Локаль]   │   │
│           │ │ [Безопасность] [Уведомления] [Аудит] │   │
│           │ └──────────────────────────────────────┘   │
│           │                                            │
│           │ [Контент активной вкладки]                 │
└────────────────────────────────────────────────────────┘
```

### Подход к реализации

**Вариант А (рекомендуется): URL-based tabs** — каждая вкладка = отдельная страница в `(app)/profile/`:

```
/profile          → Личное (рефакторинг существующего page.tsx)
/profile/signature → Подпись (новая)
/profile/theme     → Тема (новая)
/profile/locale    → Локализация (новая)
/profile/security  → Безопасность (уже создана в Эпике 16, не трогаем)
/profile/notifications → Уведомления (новая)
```

**TabBar** — общий компонент `apps/web/src/components/Profile/ProfileTabBar.tsx`:
- Горизонтальный список вкладок
- Активная вкладка: `border-b-2 border-primary text-primary font-medium`
- Неактивная: `text-gray-500 hover:text-gray-700 hover:border-gray-300`
- Рендерится в начале каждой `/profile/*` страницы (или в shared layout)
- При реализации — создать `apps/web/src/app/(app)/profile/layout.tsx` с `ProfileTabBar` внутри

**Shared layout** `apps/web/src/app/(app)/profile/layout.tsx`:
```
PageHeader title="Профиль"
  description={dynamic — имя пользователя + роль}
ProfileTabBar (активная вкладка = pathname)
{children}
```

### Вкладки в TabBar

| Вкладка | Путь | Иконка | Новая? |
|---|---|---|---|
| Личное | `/profile` | `bi-person-circle` | рефакторинг |
| Подпись | `/profile/signature` | `bi-pen` | новая |
| Тема | `/profile/theme` | `bi-palette` | новая |
| Локализация | `/profile/locale` | `bi-translate` | новая |
| Безопасность | `/profile/security` | `bi-shield-lock` | Эпик 16, не трогаем |
| Уведомления | `/profile/notifications` | `bi-bell` | новая |

### Обновление Sidebar

Блок «Профиль» в нижней части Sidebar (строки 332-351 в Sidebar.tsx) сейчас — ссылка на `/profile`. Оставить как есть. Отдельных пунктов для подсекций в Sidebar не добавлять — навигация по профилю через TabBar на самой странице.

### Вкладка 1: Личное (/profile)

Рефакторинг существующего содержимого `profile/page.tsx`.

**Структура карточек остаётся той же**, добавляем новые поля:

```
card «Личные данные»
  - ФИО *
  - Email *
  - Должность (новое поле job_title, optional)
  - Телефон (уже есть поле phone)
  [Сохранить]

card «Telegram» (переносим из текущего «Аккаунт» блока)
  - статус привязки
  - [Привязать Telegram] / [Отвязать Telegram]

card «Аватар» (без изменений)
  - drag-drop zone + preview + [Загрузить] / [Удалить]
```

**Замечания:**
- Поле `phone` уже есть в `User` типе (добавлено в Эпике 13)
- Поле `job_title` — требует правки backend: `ALTER TABLE users ADD COLUMN job_title VARCHAR(128)`
- Drag-drop zone для аватара: оборачиваем существующий блок в `onDragOver` / `onDrop` обработчики, визуально: `border-2 border-dashed border-gray-300 dark:border-gray-600 rounded-lg p-4` + иконка `bi-cloud-upload` в центре при drag-over → `border-primary bg-primary/5`

### Вкладка 2: Подпись (/profile/signature)

**Файл:** `apps/web/src/app/(app)/profile/signature/page.tsx`

```
card «Подпись изображением»
  Upload drop-zone:
    Если signature_image_url пусто:
      border-dashed + bi-pen (2rem text-gray-300)
      «Загрузи PNG или JPG подписи (прозрачный фон)»
      «или перетащи файл сюда»
      [btn-secondary bi-upload «Выбрать файл»]
      подсказка: «PNG с прозрачным фоном предпочтительнее»

    Если signature_image_url есть:
      <img src={signature_image_url} alt="Подпись"
           className="max-h-[100px] object-contain border border-gray-200
                      dark:border-gray-700 rounded p-2 bg-white" />
      [btn-ghost bi-trash «Удалить подпись»]
      [btn-secondary bi-upload «Заменить»]

  Правила загрузки (text-xs text-gray-500):
    «PNG, JPG — до 500 КБ. Оптимальный размер: 400×150 px.»
    «Используется при генерации PDF-договоров.»

  Если файл > 500KB → inline error: «Файл слишком большой. Максимум 500 КБ.»
  Если неверный формат → inline error: «Допустимы только PNG и JPG.»
```

**API:**
- `POST /api/users/me/signature` — multipart/form-data, поле `file`
- `DELETE /api/users/me/signature`
- Response: `{ signature_image_url: string }`

**Backend (требуется правка backend):**
- `User.signature_image_url TEXT`
- Алembic миграция
- 2 эндпоинта — загрузка + удаление файла (аналогично avatar)
- В `GET /api/auth/me` возвращать `signature_image_url`
- Добавить в `User` interface types.ts: `signature_image_url?: string | null`

### Вкладка 3: Тема (/profile/theme)

**Файл:** `apps/web/src/app/(app)/profile/theme/page.tsx`

```
card «Тема интерфейса»
  h2 «Тема интерфейса»
  p «Выбери, как выглядит MACRO CRM на твоём устройстве.»

  Radio group (flex flex-col gap-3):
    ┌─ label.cursor-pointer ──────────────────────────────┐
    │ [radio] [bi-sun]  Светлая                           │
    │         Всегда светлый интерфейс                    │
    └─────────────────────────────────────────────────────┘
    ┌─ label.cursor-pointer ──────────────────────────────┐
    │ [radio] [bi-moon]  Тёмная                           │
    │         Комфортно при слабом освещении              │
    └─────────────────────────────────────────────────────┘
    ┌─ label.cursor-pointer ──────────────────────────────┐
    │ [radio] [bi-circle-half]  Системная                 │
    │         Следует настройке ОС                        │
    └─────────────────────────────────────────────────────┘

  Активный radio item: border-primary bg-primary/5 dark:bg-primary/10

  [btn-primary «Сохранить»] — вызывает setTheme() из ThemeContext + PATCH /api/users/me
  При сохранении — live preview (тема применяется мгновенно при выборе radio)
```

**Live preview:** при onChange radio → сразу применяем тему через ThemeContext. Кнопка «Сохранить» только персистирует в БД.

### Вкладка 4: Локализация (/profile/locale)

**Файл:** `apps/web/src/app/(app)/profile/locale/page.tsx`

Заглушка-placeholder:

```
card «Локализация»
  Центрированный блок (py-12 text-center):
    bi-translate (3rem text-gray-300)
    h3 «Локализация — скоро»
    p «Сейчас интерфейс MACRO CRM только на русском.
       Поддержка других языков появится в ближайших обновлениях.»

  (опционально, если backend уже готов)
  card «Часовой пояс»  ← только если User.timezone существует
    Select часового пояса из списка IANA zones (основные: Europe/Moscow, Asia/Tashkent, etc.)
    [Сохранить]
```

**Backend (требуется правка backend):**
- `User.locale VARCHAR(8) DEFAULT 'ru'`
- `User.timezone VARCHAR(64) DEFAULT 'Europe/Moscow'`
- Алembic миграция
- Расширить PATCH /api/users/me

### Вкладка 5: Безопасность (ссылка на /profile/security)

Эпик 16 создал `/profile/security/page.tsx`. Этот файл **не трогаем**.

В TabBar вкладка «Безопасность» просто `Link href="/profile/security"` — Next.js навигация переключает активную страницу.

### Вкладка 6: Уведомления (/profile/notifications)

**Файл:** `apps/web/src/app/(app)/profile/notifications/page.tsx`

Это NOT страница настроек нотификаций — это список уведомлений (то же что `/notifications`, но встроенное в профиль).

```
Используем те же NotificationItem компоненты.

Фильтры (как на /notifications):
  Select «Тип» + Toggle «Только непрочитанные»

Список с пагинацией (limit=30)

[btn-ghost «Прочитать все»]
```

**Замечание:** Если в будущем понадобятся **настройки** (какие виды уведомлений получать) — это отдельная задача, не в этом эпике.

### Общие states для вкладок профиля

- **Loading:** `<div className="p-8 text-gray-500 animate-pulse">Загружаем…</div>`
- **Save success:** `Banner` компонент (уже есть в profile/page.tsx) с `bg-success/40 text-gray-900 dark:text-gray-100`
- **Save error:** `Banner` с `bg-danger/30`
- **Banner в dark:** добавить `dark:text-gray-100` к тексту и проверить контраст

---

## Новые и изменённые компоненты — сводная таблица

| Компонент / Файл | Статус | Описание |
|---|---|---|
| `contexts/ThemeContext.tsx` | Новый | ThemeProvider + useTheme hook |
| `components/ThemeToggle.tsx` | Новый | bi-sun/bi-moon кнопка |
| `components/Header.tsx` | Новый | Sticky header с bell + toggle + avatar |
| `components/Notifications/NotificationBell.tsx` | Новый | Bell кнопка с badge |
| `components/Notifications/NotificationDropdown.tsx` | Новый | Dropdown список |
| `components/Notifications/NotificationItem.tsx` | Новый | Одна строка уведомления |
| `components/Profile/ProfileTabBar.tsx` | Новый | Горизонтальные вкладки профиля |
| `hooks/useNotifications.ts` | Новый | SWR хук для уведомлений |
| `app/(app)/notifications/page.tsx` | Новый | Страница всех уведомлений |
| `app/(app)/profile/layout.tsx` | Новый | Shared layout для всех /profile/* |
| `app/(app)/profile/page.tsx` | Изменён | Рефакторинг: убрать смену пароля (→ /security), добавить job_title |
| `app/(app)/profile/signature/page.tsx` | Новый | Загрузка подписи |
| `app/(app)/profile/theme/page.tsx` | Новый | Переключатель темы |
| `app/(app)/profile/locale/page.tsx` | Новый | Локализация (заглушка) |
| `app/(app)/profile/notifications/page.tsx` | Новый | Уведомления в профиле |
| `app/(app)/layout.tsx` | Изменён | Добавить Header поверх main |
| `app/layout.tsx` | Изменён | ThemeProvider + anti-flash script |
| `app/globals.css` | Изменён | dark: варианты для .card, .input, .label, .btn-* |
| `tailwind.config.ts` | Изменён | darkMode: 'class' |
| `lib/types.ts` | Изменён | User: +theme_preference, +signature_image_url, +job_title, +locale, +timezone; +Notification interface |
| `components/Sidebar.tsx` | Изменён | Убрать Avatar из нижнего блока (он перехдит в Header) ИЛИ оставить — см. Открытые вопросы |

---

## Backend — сводка изменений (все требуют правки backend)

### Алembic миграция (одна, с advisory lock)

```sql
-- users
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS theme_preference VARCHAR(16) DEFAULT 'system',
  ADD COLUMN IF NOT EXISTS signature_image_url TEXT,
  ADD COLUMN IF NOT EXISTS job_title VARCHAR(128),
  ADD COLUMN IF NOT EXISTS locale VARCHAR(8) DEFAULT 'ru',
  ADD COLUMN IF NOT EXISTS timezone VARCHAR(64) DEFAULT 'Europe/Moscow';

-- notifications
CREATE TABLE IF NOT EXISTS notifications (
  id SERIAL PRIMARY KEY,
  user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  kind VARCHAR(32) NOT NULL,
  title VARCHAR(256) NOT NULL,
  body TEXT,
  entity_type VARCHAR(32),
  entity_id INT,
  link TEXT,
  is_read BOOLEAN DEFAULT false NOT NULL,
  created_at TIMESTAMPTZ DEFAULT now() NOT NULL,
  read_at TIMESTAMPTZ
);
CREATE INDEX IF NOT EXISTS idx_notifications_user_unread
  ON notifications(user_id, is_read) WHERE is_read = false;
```

### Новые / расширенные эндпоинты

| Метод | Путь | Описание |
|---|---|---|
| `PATCH` | `/api/users/me` | + поля: theme_preference, job_title, locale, timezone |
| `GET` | `/api/auth/me` | + возвращает: theme_preference, signature_image_url, job_title, locale, timezone |
| `POST` | `/api/users/me/signature` | multipart upload подписи |
| `DELETE` | `/api/users/me/signature` | удалить подпись |
| `GET` | `/api/notifications` | ?limit=&unread_only= |
| `GET` | `/api/notifications/unread-count` | `{"count": N}` |
| `POST` | `/api/notifications/{id}/read` | mark one |
| `POST` | `/api/notifications/read-all` | mark all |

### Dispatch уведомлений (первый интеграционный случай)

В `apps/api/app/routers/contracts.py` (или approvals router) — при изменении статуса Approval:
```python
# При approval.status → approved / rejected
notify_user(
    user_id=contract.author_user_id,
    kind="approval_result",
    title=f"Договор №{contract.id} {'согласован' if approved else 'отклонён'}",
    body=f"Маршрут: {route.name}",
    link=f"/contracts/{contract.id}"
)
```

---

## Координация с Эпиком 16

| Вопрос | Решение |
|---|---|
| `/profile/security` создан в Эп.16 | Не трогать. TabBar в Эп.21 просто ссылается на него. |
| Смена пароля в `/profile/page.tsx` | Перенести ссылкой в TabBar → `/profile/security`. Саму форму смены пароля оставить в `/profile/security` (Эпик 16). Убрать дубль из `/profile`. |
| `/profile/layout.tsx` | Создаём в Эп.21. Эпик 16 создавал `/profile/security` без shared layout — после Эп.21 Security подхватит новый layout автоматически. |

---

## Адаптивность

Desktop-first. Специфика:
- TabBar на < 768px → горизонтальный скролл (`overflow-x-auto whitespace-nowrap`)
- NotificationDropdown на < 480px → позиционирование `right-0 left-0 mx-2` (full-width)
- Тёмная тема адаптивна автоматически (CSS `dark:` работает везде)
- Mobile-приоритет — TBD, эпик 10

---

## Открытые вопросы

1. **Avatar в нижнем блоке Sidebar vs Header:** Сейчас Avatar пользователя в нижней части Sidebar (`/profile` ссылка). Header добавляет второй Avatar. Это дублирование. Решение: **убрать Avatar из нижнего блока Sidebar**, заменить ссылкой `/profile` только с именем и ролью (без Avatar). Avatar остаётся только в Header. Но это ломает существующий UX. Ждём позицию продукта.

2. **Header или нет:** Сейчас у каждой страницы свой `PageHeader` с заголовком. Введение глобального `Header` добавляет новую полосу 56px поверх контента. Это уменьшает рабочую область. Альтернатива: ThemeToggle и Bell поместить прямо в `PageHeader` (правая сторона `actions`), без отдельного Header. Но тогда на страницах без `PageHeader` (например промежуточные) элементы исчезают. Решение ждёт продуктовой позиции.

3. **job_title как обязательное или optional:** По бизнес-логике менеджеры продаж имеют должность. Если `job_title` нужен в печатных формах договоров — требуется правка docxtpl-шаблонов. Уточнить с contract-specialist.

4. **Timezone + формат даты:** Поля `timezone` и `date_format` добавляем в БД, но frontend пока не использует их (нет утилит форматирования с учётом timezone). Добавить в UI как заглушку «сохраняется, но пока не влияет на отображение»? Ждём решения.

5. **Notification dispatch — где подключать:** Первый dispatch (approval result) понятен. Остальные события (deal.stage_change, activity.due_soon, onboarding.overdue, webhook.failure) требуют правок в соответствующих роутерах. Это отдельный scope — уточнить что входит в Эпик 21, а что в followup.

6. **Страница /notifications vs /profile/notifications:** Это две разные страницы или одна и та же? Предложение: `/notifications` — глобальная страница (доступна из dropdown Footer), `/profile/notifications` — встроенная в профиль та же самая страница (через shared компонент `NotificationsList`). Frontend решает через extraction в компонент.

7. **Сохранение подписи в хранилище:** Backend должен определить где хранить signature_image_url — на диске (как avatar) или в S3/MinIO. Уточнить у backend-specialist. Frontend не зависит от этого (работает через `/api/users/me/signature`).
