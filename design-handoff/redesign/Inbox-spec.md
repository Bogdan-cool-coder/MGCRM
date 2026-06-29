# ТЗ: Входящие — триаж-экран (InboxPage)

**Зачем:** дать менеджеру/администратору единое место для мониторинга автоматически
созданных входящих и обработки «Не разобранных» (failed) — сообщений, для которых
автороутинг не смог создать или связать сделку.

**Где в коде:** `front/src/pages/InboxPage/`

**Источник фич (old):**
`examples/contracts/apps/web/src/components/Inbox/` — `InboxTable.tsx`,
`InboxFilters.tsx`, `InboxDetailModal.tsx`.

**Scope этого ТЗ:** триаж-список + детальный диалог + переобработка.
Управление каналами и конструктор форм — отдельная волна, здесь не специфируются.

---

## Архитектурный контекст

Входящий поток полностью автоматический: webhook или web-form submit → система
автоматически создаёт Компанию + Сделку. Inbox — это **мониторинг и тriage**, не
конвертация вручную. Каждое входящее (`InboundMessage`) несёт:

| Поле | Тип | Описание |
|---|---|---|
| `id` | int | — |
| `channel_kind` | enum | `tg / wa / email / web_form / api` |
| `from_name` | string\|null | Отображаемое имя отправителя |
| `from_identifier` | string\|null | email, телефон, user_id и т.д. |
| `subject` | string\|null | Тема (email) или кастомный заголовок |
| `body` | string\|null | Тело сообщения |
| `raw_payload` | json\|null | Сырой вебхук-пэйлоад |
| `received_at` | datetime | UTC |
| `routing_status` | enum | `routed / dedup / failed` |
| `target_deal_id` | int\|null | Связанная сделка |
| `target_deal_created` | bool | true = сделка создана этим сообщением |
| `read_at` | datetime\|null | null = непрочитано |

---

## Wireframe

```
┌────────────────────────────────────────────────────────────────────────────────┐
│ PageHeader [60px]                                                              │
│  ← Входящие   [Badge "12" danger/navy]                [Обновить pi-refresh]   │
├────────────────────────────────────────────────────────────────────────────────┤
│ FilterBar [Card, $space-3 padding, mb-3]                                       │
│  [Непрочитанные | Все]  [Не разобрано ×]  [Канал ▾]  [Статус ▾]  [Дата ▾]   │
│  [___ Поиск (pi-search)  ___________________]   [Сбросить фильтры]            │
├────────────────────────────────────────────────────────────────────────────────┤
│ InboxList [Card, overflow-hidden]                                              │
│  ┌──────────────────────────────────────────────────────────────────────────┐ │
│  │ · ● TG    Иван Петров           Заявка на ГК «Ромашка»  12 мин  [роут.] │ │  ← unread
│  │   ○ Email info@example.kz  —    Запрос коммерческого…   2 ч    [дедуп]  │ │  ← read
│  │ · ● WA    +7 701 000 0001       (пустая тема)            вчера  [!FAIL]  │ │  ← unread+failed
│  │   ○ Form  Асель Нурова          Онлайн-заявка с сайта   3 д    [роут.→#42]│ │
│  └──────────────────────────────────────────────────────────────────────────┘ │
│  Пустое состояние / Skeleton                                                   │
└────────────────────────────────────────────────────────────────────────────────┘

                    ┌──────────── Detail Dialog (w=lg, 680px) ────────────┐
                    │ Сообщение #17         [Метка: ● Непрочитано ▾] [✕]  │
                    │ ─────────────────────────────────────────────────── │
                    │ Мета-сетка (2 col):                                 │
                    │  Канал: [TG-badge] «Основной Telegram»              │
                    │  Получено: 27.06.2026, 14:32                        │
                    │  От: Иван Петров / +7 701 000 0001                  │
                    │  Статус маршрутизации: [Не разобрано]               │
                    │  Сделка: не привязана                               │
                    │                                                     │
                    │ Тема: Заявка на ГК «Ромашка»                       │
                    │                                                     │
                    │ Текст сообщения:                                    │
                    │  ┌─────────────────────────────────────────────┐   │
                    │  │ Хотим купить квартиру…                       │   │
                    │  └─────────────────────────────────────────────┘   │
                    │                                                     │
                    │ ▶ Raw payload (свернуто, только admin)             │
                    │                                                     │
                    │ [Переобработать]              [Закрыть]            │
                    └─────────────────────────────────────────────────────┘
```

---

## Состав экрана — зоны

### 1. PageHeader

Компонент: существующий `AppShell/PageHeader.vue`.

- `title`: «Входящие»
- Рядом с заголовком — `Badge` с количеством непрочитанных; severity `danger` пока
  unread > 0, иначе скрыт. Семантика: тот же токен, что у sidebar nav badge (см. §Nav).
- Slot `#actions`: `Button icon="pi pi-refresh" severity="secondary" text` — принудительное
  обновление списка (вызов `fetchMessages()`).

### 2. FilterBar

Компонент: `InboxFilterBar.vue` (новый, внутри `InboxPage/components/`).
Контейнер: `Card` (`$surface-card`, `$radius-md`, тень `$shadow-sm`), `padding: $space-3`,
`mb-3`.
Bootstrap grid — `row g-2 align-items-end`.

**Элементы (слева → справа):**

| # | Компонент | Props/поведение | Query-param |
|---|---|---|---|
| 1 | `SelectButton` (PrimeVue) | 2 опции: `«Непрочитанные»` / `«Все»`; default = «Непрочитанные» | `?unread=1` |
| 2 | `Button` «Не разобрано» | `severity="danger" outlined size="small"`; при активном — filled; клик toggle | `?status=failed` |
| 3 | `Select` «Канал» | `showClear`, опции = channel_kind enum; placeholder «Все каналы» | `?channel=tg` |
| 4 | `Select` «Статус маршрутизации» | `showClear`, 3 опции (Направлено/Дедуп/Не разобрано); placeholder «Все статусы»; скрыт когда уже активен быстрый фильтр «Не разобрано» | `?routing_status=…` |
| 5 | `DatePicker` диапазон | `selectionMode="range" showClear`; placeholder «Дата получения» | `?date_from=&date_to=` |
| 6 | `IconField`+`InputText` | поиск по `from_name, from_identifier, subject, body`; debounce 300ms; `pi-search` icon-left; `pi-times` inline-clear при вводе | `?q=…` |
| 7 | `Button` «Сбросить» | `severity="secondary" text icon="pi pi-filter-slash"`; видим только когда любой фильтр активен | — |

Порядок в grid: `col-auto` для SelectButton + «Не разобрано», `col-md-2` для Канал,
`col-md-2` для Статус, `col-md-2` для Дата, `flex-1` для поиска, `col-auto` для кнопки
Сбросить.

### 3. InboxList

**Компонент:** `InboxList.vue` — НЕ `DataTable` (избегаем колоночного layout; Gmail-стиль
лучше передаёт read/unread контраст на нефиксированной ширине).

Контейнер: `Card` (`overflow: hidden`, `padding: 0`).

Заголовок-строка колонок (sticky):
```
[─────] [Канал] [От кого──────────────] [Тема / Текст──────────────────────] [Получено] [Сделка]
```
Фон: `$surface-50` (light) / `var(--p-surface-800)` (dark), `font-size: $font-size-xs`,
`font-weight: $font-weight-medium`, `text-transform: uppercase`, `letter-spacing: 0.05em`,
`color: var(--p-text-muted-color)`, `border-bottom: 1px solid $surface-200`.

Каждый `InboxMessageRow` — `div[role="button"]`, `tabindex="0"`, `cursor: pointer`.

---

## Read / Unread — визуальные состояния

### Логика состояния

- `read_at === null` → **непрочитано**
- `read_at !== null` → **прочитано**
- Открытие детального диалога → вызов `PATCH /api/inbox/{id}/read` → `read_at` проставляется
  → строка немедленно переходит в состояние «прочитано» (оптимистичный апдейт).

### Строка: Unread (непрочитано)

| Элемент | Light | Dark |
|---|---|---|
| Фон строки | `$surface-card` (`#fff`) | `var(--p-surface-800)` |
| Фон строки hover | `$surface-50` | `var(--p-surface-700)` |
| Точка-индикатор (4px round, left-edge) | `$primary-color` (`#172747`) | `$primary-color` (brand-invariant) |
| `from_name` / `from_identifier` | `font-weight: 600; color: $surface-900` | `font-weight: 600; color: var(--p-surface-0)` |
| Subject | `font-weight: 600; color: $surface-900` | `font-weight: 600; color: var(--p-surface-0)` |
| Body preview | `color: $surface-700` | `color: var(--p-surface-200)` |
| `received_at` | `color: $surface-700; font-weight: 500` | `color: var(--p-surface-200); font-weight: 500` |

### Строка: Read (прочитано)

| Элемент | Light | Dark |
|---|---|---|
| Фон строки | `$surface-50` | `var(--p-surface-900)` |
| Фон строки hover | `$surface-100` | `var(--p-surface-800)` |
| Точка-индикатор | скрыта | скрыта |
| `from_name` / `from_identifier` | `font-weight: 400; color: $surface-600` | `font-weight: 400; color: var(--p-surface-400)` |
| Subject | `font-weight: 400; color: $surface-600` | `font-weight: 400; color: var(--p-surface-400)` |
| Body preview | `color: $surface-500` | `color: var(--p-surface-500)` |
| `received_at` | `color: $surface-400; font-weight: 400` | `color: var(--p-surface-500); font-weight: 400` |

Переход между состояниями: `transition: background-color $duration-fast, color $duration-fast`.

### Точка-индикатор

```
// left-edge dot — 4px, позиционируется абсолютно у левого края строки
.inbox-row__unread-dot {
  position: absolute;
  left: $space-2;
  top: 50%;
  transform: translateY(-50%);
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: $primary-color;
  flex-shrink: 0;
}
```

Вся строка имеет `position: relative; padding-left: $space-6` чтобы дать место точке.

---

## Строка InboxMessageRow — колонки

```
[dot/gap] [channel-badge 80px] [from 160px flex-shrink-0] [subject+preview flex-1] [received_at 80px text-right] [deal-chip 100px]
```

**Канал-бейдж** (`ChannelKindTag.vue`, новый):

| kind | Иконка | Label | Тег: bg / text |
|---|---|---|---|
| `tg` | `pi pi-telegram` | Telegram | `$blue-100` / `$blue-900` |
| `wa` | `pi pi-whatsapp` | WhatsApp | `$green-100` / `$green-900` |
| `email` | `pi pi-envelope` | Email | `$primary-50` / `$primary-900` |
| `web_form` | `pi pi-globe` | Форма | `$surface-100` / `$surface-700` |
| `api` | `pi pi-code` | API | `$orange-100` / `$orange-900` |

Компонент: `Tag` PrimeVue со slot (иконка + label), `size="small"`, `border-radius: $radius-sm`.

**От кого:**
```
line1: from_name (обрезать 1 строку)
line2: from_identifier (font-family: monospace, $font-size-xs, color muted — только если от line1)
```

**Тема/Текст:**
```
line1 (если subject): subject truncate 1 строка
line2: body preview — line-clamp: 1, $font-size-xs
```

**Получено:** относительное время (`2 мин`, `3 ч`, `вчера`, `27.06`) через встроенную
хелпер-функцию, полная дата в tooltip (PrimeVue `v-tooltip`).

**Сделка-чип:**
- `routing_status === 'routed'` и `target_deal_id`:
  ```
  <RouterLink :to="`/deals/${target_deal_id}`" @click.stop>
    <Tag icon="pi pi-briefcase" :value="`#${target_deal_id}`" severity="success" size="small" />
    <i v-if="target_deal_created" class="pi pi-check-circle" title="Создана этим сообщением" />
  </RouterLink>
  ```
- `routing_status === 'dedup'` и `target_deal_id`:
  ```
  <RouterLink :to="`/deals/${target_deal_id}`" @click.stop>
    <Tag icon="pi pi-link" :value="`#${target_deal_id}`" severity="info" size="small" />
  </RouterLink>
  ```
- `routing_status === 'failed'`:
  ```
  <Tag icon="pi pi-exclamation-triangle" value="Не разобрано" severity="danger" size="small" />
  ```

Chevron-hint: `pi pi-chevron-right`, `color: $surface-300`, `opacity: 0`,
`group-hover: opacity: 1; transition $duration-fast`, `font-size: $font-size-xs`,
выровнен по правому краю строки.

---

## Routing-status — маппинг цветов

| `routing_status` | Визуальный сигнал | Severity Tag | Bg / Border / Text |
|---|---|---|---|
| `routed` | зелёный (успех) | `success` | `$status-success-bg` / `$status-success-border` / `$status-success-text` |
| `dedup` | синий (инфо, нейтраль) | `info` | `$status-info-bg` / `$status-info-border` / `$status-info-text` |
| `failed` | красный (внимание!) | `danger` | `$status-danger-bg` / `$status-danger-border` / `$status-danger-text` |

«Не разобрано» — **главный тревожный статус страницы**. Дополнительные усиления:
- В строке: Tag `severity="danger"` с иконкой `pi pi-exclamation-triangle`.
- Быстрый фильтр в FilterBar — кнопка с красной обводкой (outlined danger).
- В детальном диалоге — отдельный `Message severity="error"` под мета-сеткой с CTA
  «Переобработать».

---

## Nav Badge (Unread Count)

В sidebar-навигации пункт «Входящие» (`pi pi-inbox`) получает badge с количеством непрочитанных:

```
// в компоненте NavItem или сайдбар-рендере:
<Badge v-if="inboxUnreadCount > 0" :value="inboxUnreadCount > 99 ? '99+' : inboxUnreadCount"
  severity="danger" size="small" />
```

Значение берётся из Pinia `inboxStore.unreadCount` — считывается при маунте и инвалидируется
после `PATCH /api/inbox/{id}/read` или bulk-mark-read.

Цвет: `severity="danger"` PrimeVue Badge. В светлой теме — красный pill; в тёмной — тот же
(severity-токены инвертируются через PrimeVue preset, не нужен ручной override).

**Sidebar (brand-invariant, всегда тёмный):** Badge поверх иконки, абсолютное позиционирование
`top: -4px, right: -4px`. Цвет badge на тёмном фоне: `$color-danger` (`#FF5A44`) фон,
`#fff` текст — читаем на `#172747`. Это допустимый хардкод бренд-инварианта (как и сам
sidebar), не нарушает правило токенов.

---

## Detail Dialog — InboxDetailDialog.vue

Компонент: `Dialog` PrimeVue, `modal`, `dismissableMask`, `:draggable="false"`.

```
:style="{ width: '680px', maxWidth: '95vw' }"
```

**Заголовок диалога (header slot):**
```
Сообщение #{{ msg.id }}
                              [ToggleButton mark-read]  [✕]
```

ToggleButton «Непрочитано / Прочитано»:
- `Button` с иконкой `pi pi-envelope-open` (прочитано) / `pi pi-envelope` (непрочитано)
- `severity="secondary" text`
- Клик: `PATCH /api/inbox/{id}/read` или `PATCH /api/inbox/{id}/unread`
- Label меняется в реальном времени

**Тело диалога:**

1. **Мета-сетка** (`row g-3 p-3`):
   Фон: `$surface-50` (light) / `var(--p-surface-800)` (dark),
   `border: 1px solid $surface-200 (light) / var(--p-surface-700) (dark)`,
   `border-radius: $radius-md`, padding `$space-3`.

   Ячейка (`MetaCell`) — вертикальная пара label + value:
   ```
   label: $font-size-xs, font-weight 500, uppercase, letter-spacing 0.05em, color: var(--p-text-muted-color)
   value: $font-size-sm, color: var(--p-text-color)
   ```

   Ячейки (6 шт, 2 col grid):
   - **Канал:** `ChannelKindTag` + название канала (`$font-size-xs, muted`)
   - **Получено:** дата+время `ДД.ММ.ГГГГ, HH:MM`
   - **От (имя):** `from_name` или «—»
   - **От (идентификатор):** `from_identifier`, `font-family: monospace, $font-size-xs`; «—» если нет
   - **Статус маршрутизации:** routing_status Tag (см. маппинг выше)
   - **Сделка:** RouterLink `#id` + created-check иконка; «не привязана» если null; «Не разобрано»-tag если failed

2. **Alert для failed** (только когда `routing_status === 'failed'`):
   ```
   <Message severity="error" :closable="false">
     Сообщение не было разобрано автоматически. Нажмите «Переобработать», чтобы
     система попыталась создать сделку снова.
   </Message>
   ```

3. **Тема** (только если `subject` не пустой):
   ```
   label: «Тема», font-xs uppercase muted
   value: font-weight 600, color primary-text, $font-size-sm
   ```

4. **Текст сообщения:**
   ```
   label: «Текст сообщения»
   body:
     - если есть: Card $surface-50 / p-surface-800, $font-size-sm, whitespace-pre-wrap, max-height 260px, overflow-y auto (скрытый scrollbar)
     - если нет: italic muted «(пусто)»
   ```

5. **Raw payload** (collapsible, видим только для ролей `admin` / `director`):
   ```
   <Accordion>
     <AccordionPanel value="0">
       <AccordionHeader>Raw payload</AccordionHeader>
       <AccordionContent>
         <pre class="inbox-detail__raw">{{ JSON.stringify(msg.raw_payload, null, 2) }}</pre>
       </AccordionContent>
     </AccordionPanel>
   </Accordion>
   ```
   `pre`: `$font-size-xs, font-family: monospace, $surface-50 / p-surface-800, border-radius: $radius-sm, padding: $space-3, overflow-x: auto, max-height: 200px`.

**Footer диалога:**

Для `routing_status === 'failed'`:
```
[Переобработать  pi-refresh]   [Закрыть]
```
`Переобработать`: `severity="primary"`, слева; `:loading="reprocessing"`.

Для остальных статусов:
```
                               [Закрыть]
```

---

## Действие «Переобработать»

**Где живёт:**
1. В детальном диалоге: кнопка «Переобработать» в footer + Message-баннер в теле.
2. В строке InboxMessageRow (только для `failed`): иконка-кнопка `pi pi-refresh` справа
   от «Не разобрано»-тега, tooltip «Переобработать», `severity="danger" text size="small"`.

**Флоу:**

```
Клик «Переобработать»
  → Confirm (ConfirmDialog или inline Popover):
      «Система попытается заново разобрать это сообщение и создать сделку.
       Продолжить?»
      [Да, переобработать]  [Отмена]
  → POST /api/inbox/{id}/reprocess
  → loading state на кнопке (:loading=true, disabled)
  → SUCCESS (routing_status сменился на routed/dedup):
      Toast severity="success" «Сообщение разобрано. Сделка #{id} создана / привязана.»
      → строка и диалог обновляют routing_status и target_deal_id
      → диалог НЕ закрывается автоматически (дать пользователю увидеть результат)
  → FAILURE (бэкенд вернул 422 или routing_status остался failed):
      Toast severity="error" «Не удалось разобрать сообщение. Попробуйте позже или
                               обратитесь к администратору.»
      → кнопка возвращается в активное состояние
```

**Confirm-вариант:** использовать PrimeVue `ConfirmPopup` (не полноэкранный Dialog) для
действия в строке, `ConfirmDialog` для действия в детальном диалоге.

---

## States

### Список — загрузка (loading)

`DataTable`-style скелетон: 8 строк `Skeleton` той же высоты, что обычная строка (52px).
Каждая строка — горизонтальный `row g-2` с `Skeleton` нужных пропорций:
```
[Skeleton 6px round dot] [Skeleton 80px] [Skeleton 160px] [Skeleton flex-1] [Skeleton 60px] [Skeleton 80px]
```
Пульсация через PrimeVue `Skeleton` (встроенная).

### Список — пустое состояние (empty)

Центрированный блок `padding: 3rem`:
```
<i class="pi pi-inbox" style="font-size: 2.5rem; color: var(--p-text-muted-color); opacity: 0.4" />
<p class="mt-3 mb-1" style="font-weight: 600; color: var(--p-text-color)">Входящих нет</p>
<p style="color: var(--p-text-muted-color); font-size: $font-size-sm">
  Сообщения появятся здесь, как только поступят через подключённые каналы
</p>
```

Если активен фильтр «Не разобрано» и пустой:
```
<i class="pi pi-check-circle" style="color: $green-500 ..." />
<p>Все входящие разобраны</p>
<p style="muted">Сообщений без сделки не найдено</p>
```

### Список — ошибка загрузки (error)

Строчный `Message severity="error"` внутри Card:
```
Не удалось загрузить входящие.
[Попробовать снова]  →  повторный fetchMessages()
```

### Диалог — загрузка

Заменить тело на 6 ячеек мета-сетки с `Skeleton` (`height: 16px, width: 80%`).

### Диалог — ошибка

```
<Message severity="error" :closable="false">
  Не удалось загрузить сообщение. Попробуйте закрыть и открыть снова.
</Message>
```

---

## Interactions — таблица

| Элемент | Действие | Результат | Endpoint |
|---|---|---|---|
| Строка InboxMessageRow | click / Enter / Space | Открыть InboxDetailDialog + mark read (если unread) | `GET /api/inbox/{id}` + `PATCH /api/inbox/{id}/read` |
| Mark-read toggle в диалоге | click | Переключить read_at; обновить строку в списке | `PATCH /api/inbox/{id}/read` или `.../unread` |
| Быстрый фильтр «Не разобрано» | click | Toggle `status=failed`; список перефильтровывается | `GET /api/inbox?routing_status=failed` |
| SelectButton Непрочитанные/Все | click | Переключить `?unread=1` / без параметра | `GET /api/inbox?unread=1` |
| Фильтр Канал | select | Применить фильтр `?channel=…` | `GET /api/inbox?channel=…` |
| Фильтр Статус | select | Применить `?routing_status=…` | `GET /api/inbox?routing_status=…` |
| DatePicker | range select | Применить `?date_from=&date_to=` | `GET /api/inbox?date_from=…&date_to=…` |
| Поиск | input (debounce 300ms) | `?q=…` | `GET /api/inbox?q=…` |
| Сбросить фильтры | click | Сбросить все query-params; refetch | `GET /api/inbox` |
| Кнопка «Переобработать» (диалог) | click | ConfirmDialog → POST reprocess → Toast + обновление | `POST /api/inbox/{id}/reprocess` |
| Кнопка «Переобработать» (строка) | click | ConfirmPopup → POST reprocess → Toast + обновление | `POST /api/inbox/{id}/reprocess` |
| Сделка-чип в строке | click | Переход на `/deals/{id}` (без закрытия диалога) | — (router-push) |
| Сделка-link в диалоге | click | Закрыть диалог + роутер на `/deals/{id}` | — (router-push) |
| Кнопка «Обновить» (PageHeader) | click | `fetchMessages()` + `fetchUnreadCount()` | `GET /api/inbox` |
| Открытие диалога (unread) | auto | Mark read + inboxStore.unreadCount-- + sidebar badge обновляется | `PATCH /api/inbox/{id}/read` |

---

## Пагинация

Серверная: `GET /api/inbox?page=1&per_page=30`.

Внизу списка: PrimeVue `Paginator` компонент:
```
:rows="30"
:total-records="total"
:rows-per-page-options="[15, 30, 50]"
template="FirstPageLink PrevPageLink PageLinks NextPageLink LastPageLink RowsPerPageDropdown"
```

---

## Компоненты PrimeVue (список с props)

| Компонент | Props/slot | Зачем |
|---|---|---|
| `Dialog` | `modal draggable=false dismissableMask :style="{width:'680px'}"` | Детальный диалог |
| `SelectButton` | `v-model :options=[{label,value}]` | Переключатель Непрочитанные/Все |
| `Select` | `showClear placeholder optionLabel optionValue` | Фильтры Канал, Статус |
| `DatePicker` | `selectionMode="range" showClear dateFormat="dd.mm.yy"` | Фильтр по дате |
| `IconField` + `InputIcon` + `InputText` | `pi pi-search` | Поисковое поле |
| `Button` | `severity="danger" outlined` | Быстрый фильтр «Не разобрано» |
| `Button` | `icon="pi pi-refresh" :loading` | «Переобработать» |
| `Tag` | `severity icon value size="small"` | routing_status, channel_kind, deal chip |
| `Badge` | `severity="danger" :value` | unread count в PageHeader + Nav |
| `Message` | `severity closable=false` | Failed-alert + ошибки загрузки |
| `Accordion` + `AccordionPanel` | — | Raw payload (collapsible) |
| `Paginator` | `rows totalRecords rowsPerPageOptions` | Постраничная навигация |
| `Skeleton` | `height width borderRadius` | Loading state строк + диалога |
| `ConfirmDialog` | глобальный | Подтверждение переобработки (диалог) |
| `ConfirmPopup` | глобальный | Подтверждение переобработки (строка-inline) |
| `Toast` | глобальный | Успех/ошибка переобработки, mark-read ошибки |

**Новые компоненты** (обоснование — переиспользуются в нескольких местах):

- `ChannelKindTag.vue` (`front/src/components/inbox/ChannelKindTag.vue`) — badge канала:
  `kind: 'tg'|'wa'|'email'|'web_form'|'api'`, `size?: 'small'|'normal'`.
  Переиспользуется в строке, диалоге, и возможно в карточке сделки.

---

## Структура файлов

```
front/src/pages/InboxPage/
├── index.ts
├── index.vue                          ← корневая страница, composable-connect
├── components/
│   ├── InboxFilterBar.vue             ← FilterBar целиком
│   ├── InboxList.vue                  ← контейнер списка + header строк
│   ├── InboxMessageRow.vue            ← одна строка (read/unread states)
│   └── InboxDetailDialog.vue          ← детальный диалог + reprocess
├── composables/
│   └── useInboxPage.ts                ← filters, fetch, unreadCount, dialogs, reprocess
└── locale/
    ├── ru.json
    └── en.json

front/src/components/inbox/
└── ChannelKindTag.vue                 ← переиспользуемый тег канала
```

---

## SCSS — ключевые правила (токены, не литералы)

```scss
// InboxMessageRow — base
.inbox-row {
  position: relative;
  display: flex;
  align-items: center;
  gap: $space-3;
  padding: $space-3 $space-4 $space-3 $space-6; // left = space для точки
  border-bottom: 1px solid $surface-200;
  cursor: pointer;
  transition: background-color $duration-fast $ease-standard,
              color $duration-fast $ease-standard;

  :global(.app-dark) & {
    border-bottom-color: var(--p-surface-700);
  }

  &:last-child {
    border-bottom: none;
  }

  // ── Unread state ─────────────────────────────────────────────────────────
  &--unread {
    background-color: $surface-card;

    :global(.app-dark) & {
      background-color: var(--p-surface-800);
    }

    &:hover {
      background-color: $surface-50;
      :global(.app-dark) & { background-color: var(--p-surface-700); }
    }
  }

  // ── Read state ───────────────────────────────────────────────────────────
  &--read {
    background-color: $surface-50;

    :global(.app-dark) & {
      background-color: var(--p-surface-900);
    }

    &:hover {
      background-color: $surface-100;
      :global(.app-dark) & { background-color: var(--p-surface-800); }
    }
  }
}

// Точка-индикатор непрочитанного
.inbox-row__dot {
  position: absolute;
  left: $space-2;
  top: 50%;
  transform: translateY(-50%);
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background-color: $primary-color;
  flex-shrink: 0;

  .inbox-row--read & {
    display: none;
  }
}

// Sender — unread = bold+dark; read = normal+muted
.inbox-row__from {
  width: 160px;
  flex-shrink: 0;
  overflow: hidden;

  &-name {
    font-size: $font-size-sm;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;

    .inbox-row--unread & {
      font-weight: $font-weight-semibold;
      color: $surface-900;
      :global(.app-dark) & { color: var(--p-surface-0); }
    }

    .inbox-row--read & {
      font-weight: $font-weight-normal;
      color: $surface-600;
      :global(.app-dark) & { color: var(--p-surface-400); }
    }
  }

  &-ident {
    font-size: $font-size-xs;
    font-family: monospace;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    color: var(--p-text-muted-color);
  }
}

// Subject+preview — унаследуют read/unread от родителя аналогично from-name
.inbox-row__subject {
  font-size: $font-size-sm;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;

  .inbox-row--unread & {
    font-weight: $font-weight-semibold;
    color: $surface-900;
    :global(.app-dark) & { color: var(--p-surface-0); }
  }

  .inbox-row--read & {
    font-weight: $font-weight-normal;
    color: $surface-600;
    :global(.app-dark) & { color: var(--p-surface-400); }
  }
}

.inbox-row__preview {
  font-size: $font-size-xs;
  display: -webkit-box;
  -webkit-line-clamp: 1;
  -webkit-box-orient: vertical;
  overflow: hidden;
  color: $surface-500;
  :global(.app-dark) & { color: var(--p-surface-400); }

  .inbox-row--read & {
    color: $surface-400;
    :global(.app-dark) & { color: var(--p-surface-500); }
  }
}

// Raw payload pre
.inbox-detail__raw {
  font-size: $font-size-xs;
  font-family: monospace;
  background-color: $surface-50;
  border-radius: $radius-sm;
  padding: $space-3;
  overflow-x: auto;
  overflow-y: auto;
  max-height: 200px;
  white-space: pre-wrap;
  color: $surface-700;

  :global(.app-dark) & {
    background-color: var(--p-surface-800);
    color: var(--p-surface-200);
  }
}
```

---

## i18n-ключи

### RU (`inbox.json` или раздел в общем `ru.json`)

```json
{
  "inbox": {
    "page": {
      "title": "Входящие",
      "refresh": "Обновить"
    },
    "filters": {
      "unreadOnly": "Непрочитанные",
      "all": "Все",
      "failedQuick": "Не разобрано",
      "channel": "Канал",
      "allChannels": "Все каналы",
      "routingStatus": "Статус маршрутизации",
      "allStatuses": "Все статусы",
      "dateRange": "Дата получения",
      "search": "Поиск",
      "searchPlaceholder": "имя, почта, телефон, тема, текст…",
      "reset": "Сбросить фильтры"
    },
    "columns": {
      "when": "Когда",
      "channel": "Канал",
      "from": "От кого",
      "subject": "Тема / Текст",
      "deal": "Сделка"
    },
    "routingStatus": {
      "routed": "Направлено",
      "dedup": "Дедуп",
      "failed": "Не разобрано"
    },
    "channelKind": {
      "tg": "Telegram",
      "wa": "WhatsApp",
      "email": "Email",
      "web_form": "Форма",
      "api": "API"
    },
    "dealChip": {
      "created": "Создана этим сообщением",
      "linked": "Привязана",
      "none": "не привязана"
    },
    "empty": {
      "title": "Входящих нет",
      "body": "Сообщения появятся здесь, как только поступят через подключённые каналы",
      "failedTitle": "Все входящие разобраны",
      "failedBody": "Сообщений без сделки не найдено"
    },
    "error": {
      "loadFailed": "Не удалось загрузить входящие.",
      "retry": "Попробовать снова"
    },
    "detail": {
      "title": "Сообщение",
      "meta": {
        "channel": "Канал",
        "received": "Получено",
        "fromName": "От (имя)",
        "fromIdent": "От (идентификатор)",
        "routingStatus": "Статус маршрутизации",
        "deal": "Сделка"
      },
      "failedAlert": "Сообщение не было разобрано автоматически. Нажмите «Переобработать», чтобы система попыталась создать сделку снова.",
      "subjectLabel": "Тема",
      "bodyLabel": "Текст сообщения",
      "bodyEmpty": "(пусто)",
      "rawPayload": "Raw payload",
      "markRead": "Прочитано",
      "markUnread": "Непрочитано",
      "close": "Закрыть",
      "loadError": "Не удалось загрузить сообщение. Попробуйте закрыть и открыть снова."
    },
    "reprocess": {
      "button": "Переобработать",
      "confirmTitle": "Переобработать сообщение?",
      "confirmBody": "Система попытается заново разобрать это сообщение и создать сделку. Продолжить?",
      "confirmAccept": "Да, переобработать",
      "confirmReject": "Отмена",
      "successToast": "Сообщение разобрано. Сделка #{dealId} {action}.",
      "successCreated": "создана",
      "successLinked": "привязана",
      "errorToast": "Не удалось разобрать сообщение. Попробуйте позже или обратитесь к администратору.",
      "rowTooltip": "Переобработать"
    },
    "unread": {
      "badgeLabel": "{count} непрочитанных",
      "badgeLabelOver": "99+"
    },
    "publicForm": {
      "title": "Заявка",
      "notFoundTitle": "Форма не найдена",
      "notFoundBody": "Ссылка устарела или форма была деактивирована",
      "thankYouTitle": "Спасибо!",
      "thankYouBody": "Ваша заявка принята. Мы свяжемся с вами в ближайшее время.",
      "submit": "Отправить",
      "submitting": "Отправка…"
    }
  }
}
```

### EN (задел)

```json
{
  "inbox": {
    "page": { "title": "Inbox", "refresh": "Refresh" },
    "filters": {
      "unreadOnly": "Unread", "all": "All",
      "failedQuick": "Unrouted", "channel": "Channel",
      "allChannels": "All channels", "routingStatus": "Routing status",
      "allStatuses": "All statuses", "dateRange": "Received date",
      "search": "Search", "searchPlaceholder": "name, email, phone, subject, text…",
      "reset": "Clear filters"
    },
    "routingStatus": { "routed": "Routed", "dedup": "Dedup", "failed": "Unrouted" },
    "channelKind": { "tg": "Telegram", "wa": "WhatsApp", "email": "Email", "web_form": "Web Form", "api": "API" },
    "empty": {
      "title": "No messages", "body": "Messages will appear here once they arrive via connected channels",
      "failedTitle": "All messages routed", "failedBody": "No unrouted messages found"
    },
    "reprocess": {
      "button": "Reprocess",
      "confirmTitle": "Reprocess message?",
      "confirmBody": "The system will attempt to re-route this message and create a deal. Continue?",
      "confirmAccept": "Yes, reprocess", "confirmReject": "Cancel",
      "successToast": "Message routed. Deal #{dealId} {action}.",
      "successCreated": "created", "successLinked": "linked",
      "errorToast": "Failed to reprocess. Try again later or contact your administrator."
    }
  }
}
```

---

## Роли и доступ

| Роль | Доступ к InboxPage | Raw payload | Reprocess |
|---|---|---|---|
| `admin` | полный | да | да |
| `director` | полный | да | да |
| `manager` | полный | нет | да |
| `lawyer` / `accountant` / `cfo` | нет (роутер `roles` guard) | — | — |

Роутер:
```ts
{
  path: '/inbox',
  name: 'Inbox',
  component: () => import('@/pages/InboxPage'),
  meta: { requiresAuth: true, roles: ['admin', 'director', 'manager'], title: 'inbox.page.title' },
}
```

---

## Vizion-эталон

Структурный паттерн (список с фильтрами в Card) — `examples/vizion/front/src/pages/DocumentsPage/`.
Паттерн Dialog с мета-сеткой — `examples/vizion/front/src/pages/DocumentPage/`.
Паттерн фильтра-панели — `front/src/pages/DocumentsPage/components/DocumentsFilterPanel.vue` (существующий MGCRM, не Vizion).

---

## Backend-требования (для backend-specialist)

1. `GET /api/inbox` — фильтры: `?q, ?unread=1, ?channel=tg|wa|email|web_form|api, ?routing_status=routed|dedup|failed, ?date_from, ?date_to, ?page, ?per_page`. Ответ: `{ data: InboundMessage[], total, unread_count }`.
2. `GET /api/inbox/{id}` — детальное сообщение.
3. `PATCH /api/inbox/{id}/read` — проставить `read_at = now()`.
4. `PATCH /api/inbox/{id}/unread` — обнулить `read_at`.
5. `POST /api/inbox/{id}/reprocess` — повторный прогон роутинга; ответ: обновлённый `InboundMessage` с новым `routing_status` и `target_deal_id`.
6. Поле `read_at` на таблице `inbound_messages` (nullable datetime) + индекс `(read_at IS NULL)` для счётчика.

---

## Открытые вопросы

1. **Bulk mark-read:** нужна ли кнопка «Отметить все как прочитанные»? Продуктовое решение за PO.
2. **Pagination vs infinite scroll:** выбрано pagination (Paginator) — подтвердить у PO если объём трафика высокий (> 1000 сообщений/сутки).
3. **Sidebar badge polling interval:** как часто инвалидировать `unread_count`? Предлагается при фокусе вкладки + после mark-read; WebSocket/SSE — отдельное решение.
4. **Видимость Inbox для manager:** сейчас доступ `admin|director|manager`. Уточнить — видит ли менеджер только свои лиды или все входящие воронки?
5. **Каналы в фильтре:** фильтр по `channel_kind` (enum, статический) — без вызова API. Если в будущем будет несколько каналов одного типа (два TG-бота), фильтр нужно переключить на `channel_id` с загрузкой из `/api/channels`. Заложить это как TODO в composable.
