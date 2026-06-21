# Contacts — Implementation Plan (Contacts-spec.md → code)

**Статус:** план, не коммитить.
**Целевой файл:** `front/src/pages/ContactsPage/`
**Источник требований:** `design-handoff/redesign/Contacts-spec.md` + `contacts.html`
**Дата:** 2026-06-21

---

## 0. Токены: мэппинг `--c-*` / `--mg-*` → переменные репо

Спека использует собственный набор `--c-*` и `--mg-*`, внутри `.surface`. В репо они
реализованы через `--app-*` / `$surface-*` / `$primary-*` / `$radius-*` SCSS-переменные.
Фронт НИКОГДА не пишет литеральный hex — только переменные репо.

| Спека (`--c-*`)   | Переменная репо                                             |
|-------------------|-------------------------------------------------------------|
| `--c-page`        | `$surface-100` / dark: `var(--p-surface-800)`               |
| `--c-card`        | `$surface-card`                                             |
| `--c-border`      | `$surface-200` / dark: `var(--p-surface-700)`               |
| `--c-border2`     | `$surface-300` / dark: `var(--p-surface-600)`               |
| `--c-text`        | `var(--p-text-color)`                                       |
| `--c-text2`       | `$surface-600` / dark: `var(--p-surface-300)`               |
| `--c-muted`       | `$surface-400` / dark: `var(--p-surface-500)`               |
| `--c-hover`       | `$surface-50` / dark: `var(--p-surface-700)`                |

| Спека (`--mg-*`)         | Переменная репо                          |
|--------------------------|------------------------------------------|
| `--mg-primary-900`       | `$primary-900`                           |
| `--mg-primary-800`       | `$primary-600` (closest available step)  |
| `--mg-primary-100`       | `$primary-100`                           |
| `--mg-radius-sm/md/lg`   | `$radius-sm` / `$radius-md` / `$radius-lg` |
| `--mg-shadow-sm/md/lg`   | `var(--p-card-shadow)` для `md`; `var(--p-overlay-navigation-shadow)` для `lg` |
| `--mg-green-700`         | `$green-900` (ближайший: `var(--app-green-900)`) |
| `--mg-orange-700`        | `$orange-900` (`var(--app-orange-900)`)  |
| `--mg-red-600`           | `$red-700` (`var(--app-red-700)`)        |
| `--mg-green-100`         | `$green-100` (`var(--app-green-100)`)    |
| `--mg-orange-100`        | `$orange-100` (`var(--app-orange-100)`)  |
| `--mg-red-50`            | `$red-50` (`var(--app-red-50)`)          |

> Dark-режим в спеке задан через `.surface.dark`. В коде — через `:global(.app-dark) &`.
> Все dark-переопределения пишем внутри `:global(.app-dark) &` в scoped SCSS блоке.

---

## 1. Каркас страницы

**Текущее состояние.** Страница живёт в роутерной обёртке (layout), не в собственной `.surface`. Нет
`max-width:1340px` контейнера. Нет `.card` с border+shadow оборачивающего весь раздел.

**Что менять.** Сама страница не оборачивается в новый `.surface`-див — это ответственность layout'а
(sidebar + main area уже существуют). Нужно только обернуть содержимое `.contacts-page` в единую
`.card`-панель:

### Правки `index.vue` (структура):

```
.contacts-page
  └─ .contacts-page__card          ← НОВЫЙ: bg=$surface-card; border:1px solid $surface-200;
     border-radius:$radius-lg; overflow:hidden; flex:1; display:flex; flex-direction:column
     ├─ ContactsToolbar            (§2 — переписать)
     ├─ ContactsKpiBar             (§3 — НОВЫЙ компонент)
     ├─ ContactsFilterOverlay      (§4 — переработать в inline-panel)
     ├─ contacts-page__table-wrap  (§5 — правки колонок)
     └─ contacts-page__paginator   (§6 — заменить Paginator на кастомный)
```

Отступ `.contacts-page` убрать `margin: calc(-1 * $space-4) calc(-1 * $space-6) 0` — он
конфликтует с новой card-обёрткой. Вместо этого: `padding: $space-6 $space-7` на родительском
layout-контейнере (или уточнить у layout — это ОТКРЫТЫЙ ВОПРОС #1).

**Файлы:** `front/src/pages/ContactsPage/index.vue` (шаблон + scoped style)

---

## 2. Шапка раздела (`ContactsToolbar`)

**Текущее состояние.** Тулбар (`ContactsToolbar.vue`) реализован как горизонтальный flex:
`SavedViewsDropdown` → счётчик → `SelectButton` (тип) → spacer → поиск → кнопка «Фильтры»
→ плотность → колонки → «⋮» → «Создать».

**Дельты vs. спека:**
- Нет иконки `pi-building` / `pi-users` + `<h1>«Контакты»` 20px/600 слева.
- Переключатель `SelectButton` позиционирован НЕ сразу правее заголовка — есть `SavedViewsDropdown`
  и счётчик слева от него. По спеке: иконка → `<h1>` → переключатель сущности → поиск → «Фильтры».
- Отдельные кнопки «плотность» и «колонки» в шапке — не предусмотрены спекой (их убрать в «⋮»).
- Кнопка «⋮» сейчас `text`-стиль без `compact` (высота не 31px).
- Кнопка «Создать» не имеет явно выставленной высоты 38px через `style` или CSS-класс.
- Бейдж активных фильтров: сейчас `PrimVue Badge` (`severity="danger"` → красный). По спеке —
  **оранжевый** (`background: $orange-500`), не дефолтный PrimeVue-красный.
- Счётчик «N записей» — убрать или переместить в KPI-ленту.
- `SavedViewsDropdown` — не описан в спеке. Обсудить с PM: скрыть или оставить (ОТКРЫТЫЙ ВОПРОС #2).

### Правки `ContactsToolbar.vue`:

**Новый порядок элементов:**
```
[pi-building/pi-users 20px $primary-900]  [<h1> «Контакты» 20px/600]  [SegmentedSwitch]
[поиск 240px/38px]  [«Фильтры» outlined/38px + оранжевый бейдж]
  ──── margin-left:auto ────
[«⋮» outlined compact/31px]  [«Создать компанию»/«Создать контакт» primary/38px]
```

**Переключатель сущности** (`SegmentedSwitch`): сейчас `SelectButton` — можно оставить
PrimeVue `SelectButton`, но сбросить дефолтные стили:
- Контейнер: `height:38px; padding:3px; gap:2px; background:$surface-100; border-radius:7px`
- Активная таблетка: `background:$surface-card; color:$primary-900; box-shadow:var(--p-card-shadow)`
- Неактивная: прозрачный фон, `color:$surface-400`
- Размер шрифта: 13px/600
- Переиспользуй `SelectButton` с `pt` (passthrough) или scoped `:deep(.p-selectbutton)`.

**Поиск:** убрать PrimeVue `IconField` + `InputIcon` (создаёт лишний padding-слой). Заменить
на `position:relative` + ручная иконка `pi-search` (абсолютная, left:11px, `$surface-400`, 13px).
`InputText`: `width:240px; height:38px; padding:8px 12px 8px 32px; border-color:$surface-300; border-radius:$radius-md; font-size:14px`.

**Кнопка «Фильтры»:** текущий `Button outlined` — менять только стили бейджа:
```scss
.contacts-toolbar__filter-badge {
  position: absolute;
  top: -7px; right: -7px;
  min-width: 18px; height: 18px;
  border-radius: 9px;
  background: $orange-500;   // не var(--p-badge-background) !
  color: #fff;
  font-size: 10px; font-weight: 700;
}
```
Убери PrimeVue `<Badge>` — замени на `<span class="contacts-toolbar__filter-badge">`.

**Кнопка «⋮»:** добавить prop/class `.contacts-toolbar__more-btn` с `height:31px` и
`padding:0 10px`. Пример:
```scss
.contacts-toolbar__more-btn {
  height: 31px !important;
  padding: 0 $space-2;
}
```

**Кнопка «Создать»:** добавить явный `height:38px` через `.contacts-toolbar__create-btn`.

**Меню «⋮» (moreMenuItems):** скорректировать состав строго по §2.2 спеки:
1. «Поиск дубликатов» → `emit('openDedup')` (уже есть)
2. «Импорт из файла» → пока backlog (disabled или скрыта)
3. «Экспорт в Excel» → `emit('export')` (уже есть)
4. «Настроить колонки» → `emit('openColumns')` (перенести из отдельной кнопки → в меню)
5. separator
6. «Массовые действия» → `emit('enterBulk')` (уже есть)

Кнопки «Плотность» и «Настроить колонки» (отдельные в тулбаре) — убрать из основного ряда,
вынести в меню «⋮». Density — переместить как подменю или дополнительный пункт в «⋮».

**Placeholder поиска:** сделать реактивным:
```ts
const searchPlaceholder = computed(() =>
  entityType === 'company'
    ? t('contacts.page.search.placeholder.company')  // «Поиск по названию, БИН…»
    : t('contacts.page.search.placeholder.contact')  // «Поиск по имени, телефону, email…»
)
```

**Файлы:** `front/src/pages/ContactsPage/components/ContactsToolbar.vue`

### i18n-ключи (§2):
```json
{
  "contacts.page.search.placeholder.company": "Поиск по названию, БИН…",
  "contacts.page.search.placeholder.contact": "Поиск по имени, телефону, email…",
  "contacts.page.header.title": "Контакты",
  "contacts.page.header.switchCompany": "Компании",
  "contacts.page.header.switchContact": "Физлица",
  "contacts.page.menu.import": "Импорт из файла",
  "contacts.page.menu.export": "Экспорт в Excel",
  "contacts.page.menu.dedup": "Поиск дубликатов",
  "contacts.page.menu.columns": "Настроить колонки",
  "contacts.page.menu.bulk": "Массовые действия"
}
```

EN-задел: `contacts.page.search.placeholder.company: "Search by name, BIN..."` и т.д.

---

## 3. KPI-лента (`ContactsKpiBar`)

**Текущее состояние.** Компонент отсутствует полностью.

**Обоснование нового компонента.** KPI-лента — самостоятельный отображаемый блок данных с
отдельной логикой (свои данные / endpoint / entity-зависимость). Нельзя встроить в тулбар или
таблицу без нарушения single-responsibility. Размер небольшой — простой SFC без composable.

**Создать:** `front/src/pages/ContactsPage/components/ContactsKpiBar.vue`

**Шаблон:**
```
.contacts-kpi-bar (flex-wrap; gap:$space-2; padding:13px $space-5; border-bottom:1px solid $surface-200)
  └─ ContactsKpiChip × N (pill, radius:999px, padding:6px 13px)
     ├─ <i class="pi pi-*" 12px $surface-400>
     ├─ <span class="label" opacity:.8>«Всего:»</span>
     └─ <strong class="value" 700>96</strong>
```

**Чипы для компаний (entityType='company'):**

| Чип | Иконка | label | accent (bg/text) |
|-----|--------|-------|-----------------|
| Всего | `pi-building` | «Всего» | brand (`$primary-100` / `$primary-900`) |
| Клиенты | `pi-check-circle` | «Клиенты» | success (`$green-100` / `$green-900`) |
| Категория L | `pi-star-fill` | «Кат. L» | danger (`$red-50` / `$red-700`) |
| Категория M | `pi-star` | «Кат. M» | amber (`$orange-100` / `$orange-900`) |
| Категория S | `pi-minus-circle` | «Кат. S» | success (`$green-100` / `$green-900`) |
| Новые за неделю | `pi-calendar-plus` | «Новые» | teal (`#d4f3e8` / `#0A6E4E`) |

**Чипы для физлиц (entityType='contact'):**

| Чип | Иконка | label | accent |
|-----|--------|-------|--------|
| Всего | `pi-users` | «Всего» | brand |
| Активные | `pi-check-circle` | «Активные» | success |
| Без касаний 30+ | `pi-clock` | «Без касаний» | info (`$blue-100` / `$blue-900`) |
| Новые за неделю | `pi-calendar-plus` | «Новые» | teal |

**Props:**
```ts
interface Props {
  entityType: 'company' | 'contact'
  stats: {
    total: number
    // companies
    clients?: number
    cat_l?: number
    cat_m?: number
    cat_s?: number  // S1+S2
    // contacts
    active?: number
    no_touch_30?: number
    // shared
    new_week?: number
  }
  loading?: boolean
}
```

**States:**
- `loading=true` → 4–6 `Skeleton` (pill-форма, `border-radius:999px; width:100px; height:32px`)
- пустые данные — показываем чипы с `0`

**Dark-тема:**
- `teal` чип: фон `#0A3D28` / текст `#d4f3e8`
- остальные accent'ы — через `$status-*-bg` / `$status-*-text` (уже адаптированы в теме)

**Требуется backend:**
> `/api/contacts/kpi?entity=company|contact` — агрегированные счётчики. Должен возвращать:
> `{ total, clients, cat_l, cat_m, cat_s, active, no_touch_30, new_week }`.
> **Эндпоинт не существует.** Текущий `contactsApi` / `companiesApi` такого метода не имеют.
> Нужен новый метод в API + Laravel controller action.

### i18n-ключи (§3):
```json
{
  "contacts.kpi.total": "Всего",
  "contacts.kpi.clients": "Клиенты",
  "contacts.kpi.catL": "Кат. L",
  "contacts.kpi.catM": "Кат. M",
  "contacts.kpi.catS": "Кат. S",
  "contacts.kpi.active": "Активные",
  "contacts.kpi.noTouch": "Без касаний",
  "contacts.kpi.newWeek": "Новые за нед."
}
```

---

## 4. Панель фильтров

**Текущее состояние.** `ContactsFilterOverlay.vue` рендерится через `Teleport to="body"` с
backdrop'ом и `position:fixed` — как модальный overlay поверх всего приложения. По спеке —
**inline-панель**, раскрывающаяся под шапкой внутри карточки (`border-bottom`, no backdrop).

**Что менять:**

### 4.1 Архитектурное изменение: overlay → inline-panel

Убрать `Teleport` и backdrop. `ContactsFilterOverlay` рендерится непосредственно в
`index.vue` между тулбаром и таблицей — `v-show="filterOverlayOpen"`:

```html
<div v-show="filterOverlayOpen" class="contacts-filter-panel">
  <!-- содержимое из ContactsFilterOverlay без Teleport -->
</div>
```

CSS:
```scss
.contacts-filter-panel {
  border-bottom: 1px solid $surface-200;
  background: $surface-50;
  padding: $space-4 $space-5;
  :global(.app-dark) & {
    background: var(--p-surface-800);
    border-bottom-color: var(--p-surface-700);
  }
}
```

Удалить `.filter-overlay-backdrop` и его `position:fixed` CSS. Удалить `box-shadow` с панели.

### 4.2 Строка сегментов

Текущие `ToggleButton` PrimeVue — можно оставить, но нужно:
- Добавить лейбл «СЕГМЕНТЫ» (`11px/700/uppercase/$surface-400`) слева
- Активный `ToggleButton` → менять внешний вид по severity (см. спеку): success/warning/danger
  через scoped `:deep(.p-togglebutton)` или кастомный класс `.contacts-filter-panel__preset-chip--{sev}`

Пресеты **строго по спеке**: «Мои» · «Активные» (success) · «С открытыми сделками» · «Без задач» (warning) · «Дубликаты» (danger).

### 4.3 Три колонки

Текущая сетка `row g-4` Bootstrap — ОК, оставить.

**Колонка «Кто»:** текущий состав (Ответственный, Автор, Теги, Источник, Вовлечённость) — верен.
Убрать лишние поля если есть; оставить строго: Ответственный · Автор · Вовлечённость.
Теги → перенести в «Что».

**Колонка «Что (компании)»:** Тип компании · Категория (L/M/S1/S2) · Страна · Теги.
**Колонка «Что (физлица)»:** Компания · Должность · Источник · Теги.
(Сейчас: Источник в «Кто», Должность — отсутствует. Необходима перегруппировка.)

**Колонка «Когда»:** Создан · Последняя активность — сейчас есть, ОК.

**Поля-стиль `FField` + `FSelect`** — в коде используются `<label>` + `<MultiSelect>`/`<Select>`.
Добавить унифицированный класс:
```scss
.contacts-filter-panel__field { margin-bottom: $space-3; }
.contacts-filter-panel__label { font-size: 12px; font-weight: 500; color: $surface-600; display: block; margin-bottom: $space-1; }
```

### 4.4 Подвал фильтров

Текущий footer с `Сбросить` (text) + `Применить` (primary) — верен. Добавить `pi-check` к «Применить».
Применить: иконка `pi pi-check`.

### 4.5 Backend

**Требуется backend:** фильтр «Должность» для физлиц не передаётся в `ContactListParams`.
Добавить `position?: string` в params и маппинг в `buildContactParams()`.

### i18n-ключи (§4):
```json
{
  "contacts.filter.segmentsLabel": "СЕГМЕНТЫ",
  "contacts.filter.preset.mine": "Мои",
  "contacts.filter.preset.active": "Активные",
  "contacts.filter.preset.withDeals": "С открытыми сделками",
  "contacts.filter.preset.noTask": "Без задач",
  "contacts.filter.preset.duplicates": "Дубликаты",
  "contacts.filter.col.who": "Кто",
  "contacts.filter.col.what": "Что",
  "contacts.filter.col.when": "Когда",
  "contacts.filter.field.owner": "Ответственный",
  "contacts.filter.field.author": "Автор",
  "contacts.filter.field.engagement": "Вовлечённость",
  "contacts.filter.field.companyType": "Тип компании",
  "contacts.filter.field.category": "Категория",
  "contacts.filter.field.country": "Страна",
  "contacts.filter.field.tags": "Теги",
  "contacts.filter.field.company": "Компания",
  "contacts.filter.field.position": "Должность",
  "contacts.filter.field.source": "Источник",
  "contacts.filter.field.createdAt": "Создан",
  "contacts.filter.field.lastActivity": "Последняя активность",
  "contacts.filter.apply": "Применить",
  "contacts.filter.reset": "Сбросить"
}
```

---

## 5. Таблица — колонки

### 5.1 Общее

**Что менять:**
- `striped-rows` — **убрать**. По спеке — строки без зебры, только hover `--c-hover`.
- Колонка «⋮ в рядах» (Row actions с `pi-ellipsis-v`) — **убрать** строго по §5. Редактирование через переход в карточку.
- `scroll-height="flex" scrollable` — оставить.
- `frozen` на колонке имени — убрать (спека не требует).
- Добавить `cursor:pointer` на строки (уже есть в `.contacts-page__table`).

**Стили `<th>` и `<td>`:**
```scss
:deep(.p-datatable-thead > tr > th) {
  padding: 10px 14px;
  font-size: 11px; font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: $surface-400;
  border-bottom: 1px solid $surface-200;
  white-space: nowrap;
  background: $surface-card;
}
:deep(.p-datatable-tbody > tr > td) {
  padding: 10px 14px;
  font-size: 13px;
  border-bottom: 1px solid $surface-200;
  white-space: nowrap;
}
:deep(.p-datatable-tbody > tr:hover > td) {
  background: $surface-50;
}
:global(.app-dark) :deep(.p-datatable-thead > tr > th) {
  border-bottom-color: var(--p-surface-700);
  color: var(--p-surface-500);
  background: var(--p-surface-900);
}
:global(.app-dark) :deep(.p-datatable-tbody > tr > td) {
  border-bottom-color: var(--p-surface-700);
}
:global(.app-dark) :deep(.p-datatable-tbody > tr:hover > td) {
  background: var(--p-surface-700);
}
```

### 5.2 Колонки КОМПАНИЙ (строго по §5.1)

Порядок и состав строго по спеке. Текущие `DEFAULT_COMPANY_FIELDS` пересмотреть:

**Текущие:** `['id', 'name', 'engagement_tier', 'company_type', 'category_code', 'country_code', 'employees_count', 'open_deals_count', 'owner', 'tags']`

**Целевые:** `['name', 'category_code', 'country_code', 'open_deals_count', 'last_activity_at', 'engagement_tier', 'owner', 'tags']`

Изменения по каждой колонке:

| Поле | Что менять |
|------|------------|
| `name` (Название) | Добавить `Avatar` 32px square + инициалы компании перед ссылкой. Убрать `pi-building` иконку. Ссылка: `color:$primary-900; font-weight:600`. |
| `category_code` (Категория) | Добавить `style="text-align:center"` на `<Column>`. ОК: `Tag` severity уже есть. Убрать `width:90px` — дать контенту. |
| `country_code` (Страна) | Рендер `color:$surface-400` (muted). ОК. |
| `open_deals_count` (Сделки) | Добавить `style="text-align:center"`. Заменить рендер: если >0 → кружок-бейдж (`min-width:22px; height:20px; border-radius:10px; background:$primary-100; color:$primary-900; font-size:12px; font-weight:700`), иначе `«—»`. Убрать текущий `contacts-page__deals-count` (просто текст). |
| `last_activity_at` (Посл. контакт) | ДОБАВИТЬ колонку. Сейчас отсутствует в company columns. Рендер с цветом по свежести (см. §5.3 ниже). |
| `engagement_tier` (Вовлечённость) | Изменить рендер: точка 8px + текст «Высокая/Средняя/Низкая». Убрать `dot-only` из `EngagementChip` или добавить новый вариант. |
| `owner` (Ответственный) | Добавить `Avatar` 22px круг + `full_name` рядом (12px, `$surface-600`). Убрать inline-edit pencil из колонки (редактируется в карточке). |
| `tags` (Теги) | ОК, кроме: убрать счётчик `+N` или оставить — на усмотрение (спека допускает wrap). Текущая обрезка slice(0,2) — допустимо. |
| `id`, `company_type`, `employees_count` | Убрать из видимых по умолчанию. |

**Требуется backend (компании):** `last_activity_at` не возвращается в `CompanyListResource`.
Добавить поле в API Resource и убедиться что оно вычисляется.

### 5.3 Колонки ФИЗЛИЦ (строго по §5.2)

**Текущие DEFAULT_CONTACT_FIELDS:** `['id', 'full_name', 'engagement_tier', 'position', 'company', 'last_activity_at', 'open_deals_count', 'owner', 'tags']`

**Целевые:** `['full_name', 'company', 'phone', 'last_activity_at', 'tags', 'owner']`

| Поле | Что менять |
|------|------------|
| `full_name` (ФИО) | Добавить `Avatar` 32px круг. Текущий `pi-user` → убрать. Двухстрочный рендер: ссылка-имя (600) + должность (11px, `$surface-400`). |
| `company` (Компания) | ОК. Убедиться: ссылка `color:$primary-900`. |
| `phone` (Телефон) | ДОБАВИТЬ колонку. Поле `contact.phone`. Рендер: текст `$surface-400`, формат телефона как есть. |
| `last_activity_at` (Посл. контакт) | Уже есть в composable. Добавить цвет по свежести (§5.3). |
| `tags` | ОК. |
| `owner` (Автор) | По спеке физлица — «Автор» (кто создал/привёл), не «Ответственный». Переименовать header: `t('contacts.page.columns.author')`. Рендер: `Avatar(22)` + имя (12px, `$surface-600`). |
| `id`, `engagement_tier`, `position`, `open_deals_count` | Убрать из видимых по умолчанию. |

**Требуется backend (физлица):** `phone` не возвращается в `ContactListResource` для списочного
эндпоинта. Убедиться что `phone` включён в `ContactListResource`.

### 5.4 Цвет «Посл. контакт» по свежести

Новая утилита-функция в `index.vue` (или вынести в `utils/dates.ts`):

```ts
type TouchFreshness = 'g' | 'a' | 'r' | 'n'

function touchFreshness(iso: string | null): TouchFreshness {
  if (!iso) return 'n'
  const days = (Date.now() - new Date(iso).getTime()) / 86_400_000
  if (days <= 3) return 'g'
  if (days <= 14) return 'a'
  return 'r'
}

function touchColor(iso: string | null): string {
  const f = touchFreshness(iso)
  switch (f) {
    case 'g': return 'var(--app-green-900)'   // $green-900
    case 'a': return 'var(--app-orange-900)'  // $orange-900
    case 'r': return 'var(--app-red-700)'     // $red-700
    default:  return '$surface-400'           // muted
  }
}
```

В `<Column last_activity_at>` обёртка:
```html
<span :style="{ color: touchColor(data.last_activity_at), fontWeight: touchFreshness(data.last_activity_at) !== 'n' ? 600 : 400 }">
  {{ formatDate(data.last_activity_at) }}
</span>
```

### 5.5 Вовлечённость — рендер (§5.4)

Текущий `EngagementChip` с `dot-only` пропом. По спеке — точка + текст. Два варианта:

**Вариант A (предпочтительный):** убрать `dot-only` в этой колонке, отображать полный чип:
точка 8px (`border-radius:50%`) + текст «Высокая/Средняя/Низкая».

`EngagementChip` уже существует — убрать проп `dot-only` в Column, добавить текст если его нет.
Цвета:
```scss
.eng-dot--high { background: #A7EFAA; }   // $mg-success
.eng-text--high { color: #2b6b38; }       // $green-900
.eng-dot--mid  { background: #FFB38A; }   // $mg-warning
.eng-text--mid { color: #9b4029; }        // $orange-900
.eng-dot--low  { background: #D5D6D8; }   // $surface-300
.eng-text--low { color: $surface-400; }
```

В dark-режиме цвета текста `high/mid/low` — те же (они заданы как абсолютные, не surface).

### 5.6 Avatar — новый переиспользуемый компонент

**Проверить:** существует ли уже `Avatar` компонент в `front/src/components/`.

Если нет — создать `front/src/components/ui/CrmAvatar.vue`:
```ts
interface Props {
  name: string           // строка для инициалов
  size?: number          // px, default 32
  square?: boolean       // default false (круглый)
}
```
Инициалы: первые буквы первых двух слов имени.
Фон: `$primary-900`. Текст: `#fff`.
Размер шрифта: `Math.round(size * 0.38)` px.
Radius: square → `$radius-md`, round → `50%`.

Обоснование нового компонента: используется минимум в 4 местах (Название компании,
Название контакта, Ответственный/Автор ×2). Без компонента — дублирование разметки.

---

## 6. Пагинация

**Текущее состояние.** Использует PrimeVue `<Paginator :rows-per-page-options="[25, 50, 100]">`.
Дефолтные PrimeVue стили.

**Дельты vs. спека:**
- Опции размера страницы: `[25, 50, 100]` → менять на `[50, 100, 200]` по спеке.
- Default `perPage` в `useContactsPageData`: `ref(25)` → `ref(50)`.
- Визуал: PrimeVue Paginator НЕ соответствует спеке (дропдаун раскрывается вниз; нет
  текста «Показывать:»; другой стиль активной страницы).
- Текст «Показано N из M» отсутствует.

**Подход:** кастомный компонент `ContactsPaginator.vue` вместо PrimeVue `<Paginator>`.

**Обоснование нового компонента:** PrimeVue Paginator нельзя перевести в нужный вид без
полного переопределения DOM-структуры через слоты — проще написать простой кастомный
за ~80 строк, чем бороться с `pt` passthrough'ом.

**Создать:** `front/src/pages/ContactsPage/components/ContactsPaginator.vue`

```
.contacts-paginator (flex; justify-content:space-between; align-items:center;
  padding:12px $space-5; border-top:1px solid $surface-200; flex-shrink:0)
  ├─ .contacts-paginator__left (gap:$space-4; display:flex; align-items:center)
  │   ├─ PageSizeDropdown (раскрывается вверх)
  │   └─ «Показано 1–50 из 248»
  └─ .contacts-paginator__right (gap:$space-1; display:flex; align-items:center)
      ├─ pi-angle-double-left, pi-angle-left
      ├─ номера страниц (активная: 28×28 квадрат, $radius-sm, bg:$primary-900, #fff/600)
      └─ pi-angle-right, pi-angle-double-right
```

**PageSizeDropdown:** `Menu` popup + кнопка-дропдаун. Меню `placement="top-start"` (раскрывается
вверх). Состояние persist в `localStorage` под ключом `mgcrm_contacts_per_page_v1`.

**Props:**
```ts
interface Props {
  page: number
  perPage: number
  total: number
  perPageOptions?: number[]  // default [50, 100, 200]
}
// Emits: 'update:page', 'update:perPage'
```

**Текст «Показано»:**
```ts
const fromRecord = computed(() => (page - 1) * perPage + 1)
const toRecord = computed(() => Math.min(page * perPage, total))
// «Показано {from}–{to} из {total}»
```

**В `useContactsPageData.ts`:** изменить `perPage.value = 25` → `50`. Добавить persist ключа
`mgcrm_contacts_per_page_v1` в localStorage.

**Убрать** `<Paginator>` из `index.vue`, добавить `<ContactsPaginator>`.

### i18n-ключи (§6):
```json
{
  "contacts.paginator.showing": "Показано {from}–{to} из {total}",
  "contacts.paginator.perPageLabel": "Показывать:"
}
```

---

## 7. Поведение обеих тем

Ключевые проверки (QA-тестер должен верифицировать computed-styles):

| Элемент | Light | Dark |
|---------|-------|------|
| Фон карточки | `$surface-card` (#fff) | `var(--p-surface-900)` |
| Border таблицы | `$surface-200` | `var(--p-surface-700)` |
| Hover строки | `$surface-50` | `var(--p-surface-700)` |
| Активная пагинация | bg `$primary-900` | bg `$primary-900` (инвариант) |
| KPI-лента bg | `$surface-50` | `var(--p-surface-800)` |
| Панель фильтров | `$surface-50` | `var(--p-surface-800)` |
| Тулбар | `$surface-card` | `var(--p-surface-900)` |
| Бейдж фильтров | `$orange-500` / #fff | `$orange-500` / #fff (инвариант) |
| Вовлечённость high-текст | `#2b6b38` | `#2b6b38` (инвариант) |
| Посл. контакт свежий | `var(--app-green-900)` | `var(--app-green-900)` |
| Посл. контакт тёплый | `var(--app-orange-900)` | `var(--app-orange-900)` |

---

## 8. Чек-лист соответствия (адаптирован под код)

**§2 Шапка:**
- [ ] Иконка `pi-building`/`pi-users` 20px `$primary-900` + `<h1>` «Контакты» 20px/600 добавлены в `ContactsToolbar`
- [ ] Переключатель сущности — сразу правее заголовка (не после SavedViews)
- [ ] Default entity = `'company'` (проверить: `initialType = 'company'` в `index.vue`)
- [ ] Поиск: `width:240px; height:38px`, иконка-абсолют left:11px, placeholder по entity
- [ ] Кнопка «Фильтры» outlined/38px; бейдж оранжевый (`$orange-500`), не красный PrimeVue
- [ ] Кнопка «⋮» height:31px, outlined
- [ ] Кнопка «Создать» height:38px, primary, icon `pi-plus`
- [ ] Группа «⋮ + Создать» `margin-left:auto`, перенос целиком на узких экранах
- [ ] Меню «⋮»: 5 пунктов строго по §2.2 (дедуп / импорт / экспорт / колонки / separator / массовые)
- [ ] Кнопки «Плотность» и «Колонки» убраны из основного ряда тулбара

**§3 KPI-лента:**
- [ ] Компонент `ContactsKpiBar` создан
- [ ] Отображается строго под тулбаром, border-bottom
- [ ] Чипы по entity: 6 для компаний, 4 для физлиц
- [ ] Loading → Skeleton pill-форма
- [ ] Backend `/api/contacts/kpi` — задача backend-specialist

**§4 Фильтры:**
- [ ] Overlay → inline-panel (Teleport убран, нет backdrop)
- [ ] Строка сегментов: лейбл «СЕГМЕНТЫ» + 5 пресетов с severity
- [ ] Три колонки Кто/Что/Когда, поля строго по entity
- [ ] Footer: «Сбросить» text + «Применить» primary с `pi-check`

**§5 Таблица:**
- [ ] `striped-rows` убран
- [ ] Колонка «⋮» в рядах убрана
- [ ] `frozen` на колонке имени убран
- [ ] Компании: порядок Название/Категория/Страна/Сделки/Посл.контакт/Вовлечённость/Ответственный/Теги
- [ ] Физлица: порядок ФИО/Компания/Телефон/Посл.контакт/Теги/Автор
- [ ] Категория и Сделки — `text-align:center`
- [ ] Название: Avatar 32px square (компании) / round (физлица) + 2-строчный рендер физлица
- [ ] Посл. контакт: цвет по свежести (green/orange/red/muted)
- [ ] Вовлечённость: точка 8px + текст
- [ ] Ответственный/Автор: Avatar 22px + имя 12px/muted
- [ ] `last_activity_at` в компаниях — задача backend-specialist

**§6 Пагинация:**
- [ ] `ContactsPaginator` создан, PrimeVue `<Paginator>` убран
- [ ] Опции 50/100/200 (не 25/50/100)
- [ ] Меню раскрывается **вверх**
- [ ] Default perPage = 50
- [ ] Persist perPage в localStorage (`mgcrm_contacts_per_page_v1`)
- [ ] Текст «Показано N–M из X» слева
- [ ] Активная страница: 28×28 квадрат, `$radius-sm`, `$primary-900`, #fff

---

## 9. Фазы реализации

**Фаза A — каркас + шапка** (1 файл):
- `ContactsToolbar.vue`: новый порядок элементов, иконка+заголовок, SegmentedSwitch стили,
  поиск ручной, бейдж оранжевый, кнопки высоты, меню «⋮» состав.

**Фаза B — таблица: колонки и рендер** (1 файл + 1 новый):
- `index.vue`: убрать striped/frozen/row-actions-column; обновить DEFAULT fields; добавить
  phone колонку физлиц; touchColor/freshness; двухстрочный рендер ФИО.
- Создать `front/src/components/ui/CrmAvatar.vue`.

**Фаза C — KPI-лента** (1 новый компонент + backend-зависимость):
- Создать `ContactsKpiBar.vue` с Skeleton-состоянием.
- Подключить в `index.vue`.
- Backend: `/api/contacts/kpi` — отдельная задача.

**Фаза D — фильтры inline** (1 файл):
- `ContactsFilterOverlay.vue`: убрать Teleport/backdrop; переименовать в `ContactsFilterPanel`;
  inline-рендер в `index.vue`; перегруппировка полей.

**Фаза E — пагинация** (1 новый компонент):
- Создать `ContactsPaginator.vue`.
- Заменить `<Paginator>` в `index.vue`.
- Обновить `perPage = 50` в `useContactsPageData`.

**Фаза F — стили и темы** (scoped SCSS правки):
- Убрать `striped-rows` стили.
- Добавить dark-режим для всех новых элементов.
- Прогнать `npm run lint:ds`.

---

## 10. Backend-пробелы (итог)

| # | Что нужно | Где используется |
|---|-----------|-----------------|
| B1 | `GET /api/contacts/kpi?entity=company\|contact` — агрегированные счётчики KPI | `ContactsKpiBar` (§3) |
| B2 | `last_activity_at` в `CompanyListResource` (компании) | Колонка «Посл. контакт» (§5.1) |
| B3 | `phone` в `ContactListResource` для list-endpoint | Колонка «Телефон» физлица (§5.2) |
| B4 | `position` фильтр в `ContactListParams` + бэкенд-маппинг | Панель фильтров физлиц (§4) |

---

## 11. Открытые вопросы

1. **Layout padding:** текущий `margin: calc(-1 * $space-4) calc(-1 * $space-6) 0` на
   `.contacts-page` компенсирует отступ родительского layout. Если добавляем `.card`-обёртку —
   нужно уточнить у layout'а какой именно padding нужно убрать/изменить. Возможно достаточно
   просто убрать негативный margin и дать layout паддинг.

2. **SavedViewsDropdown:** компонент не описан в спеке. Сохранять ли его в шапке или убрать?
   Рекомендация: скрыть до отдельного ревью — спека не предусматривает сохранённые виды в
   заголовочном баре. Продуктовое решение — на PM.

3. **Inline edit owner:** колонка «Ответственный» сейчас имеет inline-edit Popover.
   Спека этого не предусматривает (редактирование — через карточку). Убрать или оставить
   как power-фичу? Рекомендация: убрать `pi-pencil` и Popover из колонки списка — они
   усложняют компонент без обоснования в спеке.

4. **EngagementChip:** компонент имеет `dot-only` проп. В колонке компаний по спеке нужен
   полный вид (точка + текст). Нужно либо убрать `dot-only` в этой колонке, либо добавить
   вариант `compact` в сам компонент. Рекомендация: убрать `dot-only` здесь, дать полный чип.

5. **Категория S:** спека упоминает «Категория S» как единый чип в KPI (S1+S2 суммарно).
   Backend B1 должен вернуть суммированное значение или два отдельных?
