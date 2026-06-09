# Design v2 — Batch 2: ТЗ для frontend-specialist

**Контекст:** Фундамент D0 внедрён (токены v2, `.badge-*`, `.lift`, `BlurFade`, `BorderBeam`, `EmptyState`, `Avatar`, `KpiCard`). Этот батч переводит на v2 четыре плотных рабочих экрана/паттерна. Анимации — только CSS (`blur-fade`, `animate-pulse`, stagger через `animation-delay`). Никакого `framer-motion`.

**Приоритет реализации:** 1 Реестр → 2 Финансы-таблицы → 3 Карточка контакта → 4 AI-панель.

---

## 1. Реестр клиентов

**Файл:** `apps/web/src/app/(app)/registry/page.tsx` — только компоненты `RegistryTable` и сопутствующий фильтр-бар. Остальные view (Канбан, Dashboard) — вне scope этого батча.

### Цель и дельта vs текущее

Сейчас: плоская `<table>` в `border border-gray-200 rounded-lg`, `bg-gray-100` header, hover `bg-gray-50`, attention-флаги на `bg-warning-50` без системы токенов v2.

Нужно: таблица v2 со sticky-заголовком, плавным row-hover через `shadow-elev-1`, soft-бейджи из системы токенов, lifecycle-прогресс как мини-сегменты, attention-флаги через `.badge-warning`, skeleton-строки при загрузке, row-actions (иконки) видимые только при hover строки.

### Wireframe

```
┌──────────────────────────────────────────────────────────────┐
│ PageHeader: «Реестр клиентов»            [Excel] [Импорт]   │
│             [Реестр | Канбан | Дашборд | Требуют внимания]   │
├──────────────────────────────────────────────────────────────┤
│ [Сегменты] [Сохранить сегмент]                               │
│ [Поиск…] [Платформа▾] [Регион▾] [Категория▾] [Здоровье▾]   │
│ [Ответственный▾]                                             │
├──────────────────────────────────────────────────────────────┤
│ ┌──────────────────────────────── card (rounded-2xl) ──────┐ │
│ │ thead sticky (shadow при скролле)                        │ │
│ │  □  Клиент  Платформа  Кат  Статус  Прогресс  ♥  Абон  │ │
│ │ ─────────────────────────────────────────────────────── │ │
│ │  □  Ромашка ОАО  B·RU  [B2]  [A3]  ████░░  🟢  120 000 │ │
│ │     [!внимание]                         [⋯ actions]     │ │
│ │  □  ...                                                  │ │
│ └──────────────────────────────────────────────────────────┘ │
└──────────────────────────────────────────────────────────────┘
```

### Компоненты и классы

#### Обёртка таблицы

```
<div class="card rounded-2xl overflow-hidden shadow-elev-1">
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
```

Внешний `card` даёт `rounded-2xl` + `shadow-elev-1`. Убрать нынешний `border border-gray-200 rounded-lg` — он дублирует.

#### `<thead>` sticky

```html
<thead class="sticky top-0 z-10 bg-white dark:bg-gray-900
              border-b border-gray-200 dark:border-gray-700
              registry-thead-shadow">
```

CSS-класс `registry-thead-shadow` добавить в `globals.css`:
```css
.registry-thead-shadow {
  box-shadow: 0 1px 0 0 var(--color-gray-200);
  transition: box-shadow 0.15s ease;
}
/* Когда таблица скролл > 0 — JS-класс .scrolled навешивается на обёртку: */
.registry-table-wrap.scrolled .registry-thead-shadow {
  box-shadow: 0 2px 8px 0 rgba(23,39,71,0.08);
}
```

Скролл-детект: `useEffect` с `onScroll` на обёртке `div.overflow-x-auto`. При `scrollTop > 0` добавляем класс `scrolled` на `div.registry-table-wrap`.

Заголовки колонок:
```
text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400 px-3 py-2.5
```
Первая колонка чекбокс — `w-10 text-center`. Числовые колонки — `text-right`. Статус/health — `text-center`.

#### Строки `<tbody>`

```html
<tr class="group border-b border-gray-100 dark:border-gray-800
           transition-colors duration-100
           hover:bg-primary/[0.03] dark:hover:bg-primary/[0.06]
           [&.selected]:bg-primary/[0.06]">
```

Density compact: `<td class="px-3 py-2">`.

Row-actions (иконки «Открыть», «Документ» и т.п.) — последняя `<td>`, скрыта по умолчанию, показывается при hover строки:

```html
<td class="px-3 py-2 text-right">
  <div class="opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-end gap-1">
    <a href="..." class="btn-ghost p-1 text-gray-400 hover:text-primary" title="Открыть карточку">
      <i class="bi bi-box-arrow-up-right text-xs" />
    </a>
  </div>
</td>
```

#### Attention-флаги

Вместо нынешнего `bg-warning-50 text-warning-700` — soft-бейдж из системы v2:
```html
<span class="badge badge-warning text-[10px] px-1.5 py-0.5">
  {ATTENTION_LABELS[a]}
</span>
```

#### Lifecycle-прогресс (замена `ImplBar`)

Компонент `LifecycleSegments` — новый, создать в `components/Registry/LifecycleSegments.tsx`.

Показывает этапы B0-B6 или A1-A6 как мини-сегменты. Текущий этап — залит `bg-primary-light`, предыдущие — `bg-success/60`, будущие — `bg-gray-200`.

```
Props: { code: string | null; kind: "B" | "A" | null }
```

Логика: `B` → 7 сегментов (B0..B6), `A` → 6 сегментов (A1..A6). Парсить `code` (`"B2"` → B, idx=2). C0 → одиночный красный `badge-danger`. Если code `null` → прочерк.

Визуал:
```html
<div class="flex items-center gap-0.5" title="B2 из 6">
  {segments.map((s, i) => (
    <div key={i} class={`w-3 h-1.5 rounded-sm ${s === 'past' ? 'bg-success/60' : s === 'current' ? 'bg-primary-light' : 'bg-gray-200'}`} />
  ))}
  <span class="text-xs tabular-nums text-gray-500 ml-1">{code}</span>
</div>
```

#### HealthBadge — уже переведён на soft-схему в D0. Убедиться, что `HealthBadge` использует `.badge-success/.badge-warning/.badge-danger` (проверить компонент, если нет — обновить).

#### CategoryBadge — добавить `badge` base-класс + `badge-info` если нет.

#### Skeleton-строки (loading state)

Вместо нынешнего текста «Загрузка…» — компонент `RegistryTableSkeleton`: 8 строк, каждая — `<tr>` с `<td>` содержащим `<div class="animate-pulse h-4 bg-gray-100 dark:bg-gray-800 rounded w-{n}">`. Разные ширины для разных колонок (имитируют реальные пропорции).

Вынести в `components/Registry/RegistryTableSkeleton.tsx`.

#### Empty state

```tsx
<EmptyState
  icon="bi-people"
  title="Нет клиентов по фильтру"
  description="Попробуй изменить фильтры или сбросить сегмент"
/>
```

Обернуть в `<div class="py-8">` внутри `card`.

#### Фильтр-бар v2

Текущие `<input class="input w-48">` и `<select class="input w-40">` — оставить, но сгруппировать в единую полоску:

```html
<div class="px-8 pt-3 flex flex-wrap gap-2 items-center
            border-b border-gray-100 dark:border-gray-800 pb-3">
```

Добавить кнопку сброса фильтров — `btn-ghost text-sm` с `bi-x-circle` — отображается только если хоть один фильтр != `""`.

### States

| State | Что показывать |
|---|---|
| Loading | `RegistryTableSkeleton` (8 строк) вместо таблицы |
| Empty | `EmptyState` с `bi-people` внутри `card` |
| Error | inline `text-danger` баннер над таблицей |
| Selected rows | строка `bg-primary/[0.06]`, `BulkSelectToolbar` поднимается (уже есть) |

### Animations (CSS only)

Строки таблицы при появлении данных (переход loading → data): враппер `RegistryTable` получает CSS-класс `blur-fade` на `<tbody>` — всё тело таблицы появляется одним блоком с `blur-fade-in`. Не stagger по строкам (100+ строк — дорого).

```html
<tbody class="blur-fade" style="--blur-fade-duration: 0.2s">
```

`prefers-reduced-motion` обрабатывается глобально в `globals.css` — ничего дополнительного не нужно.

### A11y

- `<table>` → добавить `role="grid"` и `aria-label="Реестр клиентов"`
- Чекбокс «выбрать все» → `aria-label="Выбрать все строки"`
- Row-actions кнопки → `aria-label` с именем клиента (`aria-label={"Открыть " + r.counterparty_name}`)
- При `selectedIds.size > 0` → `aria-live="polite"` region с текстом `"Выбрано N подписок"`

### Что НЕ трогать

- Логику фильтров, сегментов, булк-выбора, импорта
- `moveSub`, `Dashboard`, `Kanban` view
- `BulkSelectToolbar`, `BulkDocumentModal`, `SaveSegmentModal`
- SWR ключи и query-строку
- `RegistryKpi` и `fmtMoney`

### QA чеклист (реестр)

- [ ] Sticky thead: при скролле tbody заголовок не уезжает
- [ ] При скролле > 0 на `div.registry-table-wrap` появляется CSS-класс `scrolled` и thead получает тень `box-shadow: 0 2px 8px rgba(23,39,71,0.08)`
- [ ] Row hover → мягкий `bg-primary/[0.03]`
- [ ] Row-actions видны только при `group-hover` строки
- [ ] Attention-флаги рендерятся как `.badge.badge-warning`
- [ ] LifecycleSegments: B2 → 3 залитых сегмента (B0,B1 success/60, B2 primary-light), остальные серые; tooltip `"B2 из 6"`
- [ ] Loading state → скелетон 8 строк с `animate-pulse`
- [ ] Empty state → `EmptyState bi-people` внутри card
- [ ] `prefers-reduced-motion` → `.blur-fade` мгновенно показывает tbody без анимации
- [ ] tsc --noEmit: 0 ошибок

---

## 2. Финансы-таблицы (паттерн v2)

**Референс:** `apps/web/src/app/(app)/finance/balances/page.tsx`

**Применимость паттерна:** `balances`, `operations`, `registries` (реестр платежей), `cashflow`, `journals`. Паттерн описывает структуру; применить прежде всего к `balances/page.tsx` как образцовой реализации.

### Цель и дельта

Сейчас: `card overflow-hidden` + `<table>` + базовый `hover:bg-gray-50`, `tfoot` с `border-t-2 border-gray-200 bg-gray-50`. Нет sticky thead, нет semantic-color для сумм, `tfoot` визуально не выделяется достаточно.

Нужно: sticky thead с тенью при скролле, суммы `приход` → `text-success font-semibold tabular-nums`, суммы `расход` → `text-danger font-semibold tabular-nums`, итоговая строка `tfoot` как «total-bar» с отдельным elevation, skeleton при загрузке, density compact.

### Паттерн: FinTable v2

#### Обёртка

```html
<div class="card rounded-2xl overflow-hidden shadow-elev-1 fin-table-wrap">
  <div class="overflow-x-auto fin-table-scroll"
       onScroll={handleScroll}>
    <table class="w-full text-sm">
```

`fin-table-wrap` / `fin-table-scroll` — для CSS sticky-shadow аналогично паттерну реестра.

#### `<thead>`

```html
<thead class="sticky top-0 z-10 bg-white dark:bg-gray-900
              border-b border-gray-200 dark:border-gray-700
              fin-thead-shadow">
  <tr>
    <th class="text-xs font-medium uppercase tracking-wide
               text-gray-500 dark:text-gray-400
               text-left px-4 py-2.5">
      Название колонки
    </th>
    <!-- числовые: text-right -->
  </tr>
</thead>
```

CSS в `globals.css`:
```css
.fin-table-wrap.scrolled .fin-thead-shadow {
  box-shadow: 0 2px 8px 0 rgba(23,39,71,0.08);
}
```

#### `<tbody>` строки

```html
<tr class="border-b border-gray-100 dark:border-gray-800
           transition-colors duration-100
           hover:bg-gray-50 dark:hover:bg-gray-800/60
           cursor-pointer">
  <td class="px-4 py-2.5 text-sm">...</td>
</tr>
```

Density compact: `py-2.5` вместо `py-3`.

#### Семантические суммы

Правило: **сумма `income`/`приход`** — `text-success font-semibold tabular-nums`, **`expense`/`расход`** — `text-danger font-semibold tabular-nums`, **нейтральная** (остаток, перевод) — `font-semibold tabular-nums dark:text-gray-200`.

В `balances/page.tsx` — остаток нейтральный, сумма «в базе» нейтральная. Нет direction — ок.

В `operations/page.tsx` (через `MoneyCell` и `DirectionBadge`) — `DirectionBadge` уже использует `success`/`danger`, убедиться что суммы в `MoneyCell` также принимают `direction` и красятся:

```tsx
// MoneyCell.tsx — добавить prop direction?: "income" | "expense" | "transfer"
<span class={cn(
  "tabular-nums font-semibold",
  direction === "income" && "text-success",
  direction === "expense" && "text-danger",
  !direction && "dark:text-gray-200"
)}>
```

#### `<tfoot>` total-bar

```html
<tfoot>
  <tr class="border-t-2 border-gray-200 dark:border-gray-600
             bg-gradient-to-r from-gray-50 to-white
             dark:from-gray-800/80 dark:to-gray-900">
    <td colspan={N-1} class="px-4 py-3 text-sm font-semibold dark:text-gray-200">
      Итого в {currency}:
    </td>
    <td class="px-4 py-3 text-right">
      <span class="tabular-nums font-bold text-base dark:text-gray-100">
        {formatAmount(total)}
      </span>
      <span class="text-xs text-gray-400 ml-1">{currency}</span>
    </td>
  </tr>
</tfoot>
```

Градиентный фон `from-gray-50 to-white` (вместо flat `bg-gray-50`) даёт лёгкое визуальное выделение без перегруза.

#### Skeleton (loading)

Унифицированный компонент `FinTableSkeleton` (`components/Finance/FinTableSkeleton.tsx`):

```tsx
interface Props { rows?: number; cols: number[] }
// cols — массив ширин (в % или tailwind w-*), по числу колонок

export function FinTableSkeleton({ rows = 6, cols }: Props) {
  return (
    <>
      {Array.from({ length: rows }).map((_, i) => (
        <tr key={i} class="border-b border-gray-100 dark:border-gray-800">
          {cols.map((w, j) => (
            <td key={j} class="px-4 py-2.5">
              <div class={`animate-pulse h-4 bg-gray-100 dark:bg-gray-800 rounded w-${w}`} />
            </td>
          ))}
        </tr>
      ))}
    </>
  );
}
```

Вставляется вместо `<tbody>` пока `isLoading`. В `balances/page.tsx` заменить нынешний `div.p-4.space-y-3` с блоками на `<FinTableSkeleton rows={6} cols={[32, 16, 12, 24, 20]} />` внутри `<table>`.

#### Empty state

```tsx
<EmptyState
  icon="bi-wallet2"
  title="Нет записей"
  description="Попробуй изменить фильтры"
/>
```

Обернуть в `<tr><td colSpan={N}><EmptyState ... /></td></tr>` внутри `<tbody>`.

### Применение к `balances/page.tsx`

1. Обернуть `<table>` в `div.fin-table-wrap` + `div.fin-table-scroll` с `onScroll`
2. `useRef` + `useState(scrolled)` для sticky-shadow
3. `<thead>` получает `sticky top-0 z-10 fin-thead-shadow`
4. Строки: `py-2.5` вместо `py-3`
5. `tfoot` → `bg-gradient-to-r from-gray-50 to-white` + `font-bold text-base`
6. Loading: `<FinTableSkeleton rows={6} cols={[32, 16, 12, 24, 20]} />`
7. Empty: `<EmptyState icon="bi-wallet2" ...>` в `<tbody><tr><td colSpan={5}>`

### States

| State | Поведение |
|---|---|
| Loading | `FinTableSkeleton` в tbody |
| Empty | `EmptyState bi-wallet2` в tbody `<td colSpan>` |
| Error | Уже есть `text-danger` над таблицей — оставить, добавить `bg-danger/10 rounded-lg px-3 py-2` |

### Что НЕ трогать

- `formatAmount`, `FinSumFooter`, `MoneyCell`, `DirectionBadge`, `OperationStatusBadge` — только добавить/поправить `direction` prop в `MoneyCell`
- SWR ключи, фильтры, pagination
- Логику проводки/сторно/разнесения в `OperationsTable`
- `RoleGate`

### QA чеклист (финансы-таблицы)

- [ ] Sticky thead: `balances` при скролле tbody — заголовок остаётся на месте
- [ ] При `scrollTop > 0` — `fin-thead-shadow` получает `box-shadow: 0 2px 8px`
- [ ] Density compact: строки `py-2.5`, визуально плотнее прежнего `py-3`
- [ ] Tfoot: `bg-gradient-to-r from-gray-50 to-white` виден (нет flat серого фона)
- [ ] Tfoot сумма: `font-bold text-base` (крупнее строк body)
- [ ] Loading → `FinTableSkeleton` с `animate-pulse` (не старый div-блок)
- [ ] Empty → `EmptyState bi-wallet2` внутри `<td colSpan>`
- [ ] В `operations` направление `income` → сумма `text-success`, `expense` → `text-danger`
- [ ] tsc --noEmit: 0 ошибок

---

## 3. Карточка контакта

**Файлы:**
- `apps/web/src/app/(app)/contacts/[id]/page.tsx`
- `apps/web/src/components/CRM/ContactRightRail.tsx`

### Цель и дельта

Сейчас: `PageHeader` с именем, кнопки «Редактировать/К списку», затем плоская tab-полоска, левая колонка `max-w-2xl` с полями в `card`, правый rail — `w-72` border-left, иконка `bi-person-circle` как «аватар». Нет hero-блока, нет elevation на табах, нет stagger при смене таба.

Нужно: hero-секция с `Avatar` компонентом, имя + должность + soft-бейджи, быстрые action-кнопки. Правый rail — небольшой elevation. Табы — подчёркнутый style (уже в коде) + transition при смене контента. Empty states через `EmptyState`.

### Wireframe

```
┌──────────────────────────────────────────────────────────────┐
│  [← К списку]                   [Редактировать] [Удалить?]  │
├──────────────────────────────────────────────────────────────┤
│ ┌── hero card (shadow-elev-2) ────────────────────────────┐  │
│ │  [Avatar 56px]  Иванов Пётр Сергеевич                   │  │
│ │                 Директор по продажам                    │  │
│ │                 [badge: основной] [badge: источник]     │  │
│ │                 [📞 +7…] [✉ mail] [tg @user]           │  │
│ │                 [Позвонить] [Написать] [Создать сделку] │  │
│ └─────────────────────────────────────────────────────────┘  │
├──────────────────────────────────────────────────────────────┤
│ [Обзор] [Активности] [Файлы] [Сделки] [Компании] [Доки]...  │
├───────────────────────────── flex-1 ─────────────────────────┤
│  Левая область (flex-1)          │  Right rail (w-64 lg:flex)│
│  Поля / таб-контент              │  Ответственный             │
│                                  │  Источник                  │
│                                  │  Теги                      │
│                                  │  Создан / Обновлён         │
└──────────────────────────────────────────────────────────────┘
```

### Hero-секция

Добавить новый компонент `components/CRM/ContactHero.tsx`.

```tsx
interface ContactHeroProps {
  contact: Contact;
  editMode: boolean;
  onEdit: () => void;
  onBack: () => void;
}
```

Структура hero `card` (заменяет `PageHeader` для этой страницы — `PageHeader` убрать, вся шапка внутри hero):

```html
<div class="mx-8 mt-6 card rounded-2xl shadow-elev-2 p-6">
  <div class="flex items-start gap-5">

    <!-- Avatar 56px -->
    <Avatar
      name={contact.full_name}
      size={56}
      class="shrink-0"
    />

    <!-- Info block -->
    <div class="flex-1 min-w-0">
      <!-- Имя + eyebrow -->
      <div class="text-xs text-gray-400 uppercase tracking-wide mb-0.5">Контакт</div>
      <h1 class="text-xl font-semibold text-gray-900 dark:text-white truncate">
        {contact.full_name}
      </h1>

      <!-- Должность -->
      {contact.position && (
        <div class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{contact.position}</div>
      )}

      <!-- Soft-бейджи: is_primary + source -->
      <div class="flex flex-wrap gap-1.5 mt-2">
        {contact.is_primary && (
          <span class="badge badge-info text-xs">основной</span>
        )}
        {contact.source && (
          <span class="badge badge-info text-xs">{CONTACT_SOURCE_LABELS[contact.source]}</span>
        )}
      </div>

      <!-- Быстрые контакты -->
      <div class="flex flex-wrap items-center gap-3 mt-3 text-sm">
        {contact.phone && (
          <a href={`tel:${contact.phone}`}
             class="flex items-center gap-1 text-gray-600 dark:text-gray-300 hover:text-primary transition-colors">
            <i class="bi bi-telephone text-gray-400" />
            {contact.phone}
          </a>
        )}
        {contact.email && (
          <a href={`mailto:${contact.email}`}
             class="flex items-center gap-1 text-gray-600 dark:text-gray-300 hover:text-primary transition-colors truncate">
            <i class="bi bi-envelope text-gray-400" />
            {contact.email}
          </a>
        )}
        {contact.tg_username && (
          <span class="flex items-center gap-1 text-gray-600 dark:text-gray-300">
            <i class="bi bi-telegram text-gray-400" />
            {contact.tg_username}
          </span>
        )}
      </div>
    </div>

    <!-- Action-кнопки справа -->
    <div class="flex items-center gap-2 shrink-0">
      <button class="btn-ghost text-sm" onClick={onBack}>
        <i class="bi bi-arrow-left mr-1" /> К списку
      </button>
      <button class="btn-secondary text-sm" onClick={onEdit}>
        <i class={`bi ${editMode ? "bi-check2" : "bi-pencil"} mr-1`} />
        {editMode ? "Готово" : "Редактировать"}
      </button>
    </div>
  </div>
</div>
```

`Avatar` компонент уже существует (`components/Avatar`). Передавать `name={contact.full_name}` — инициалы генерируются автоматически.

### Правый rail v2

Файл `ContactRightRail.tsx` — обёртка `<aside>`:

```html
<aside class="hidden lg:flex w-64 shrink-0 flex-col gap-0 self-start">
  <div class="card rounded-2xl shadow-elev-1 p-5 space-y-5 mt-6 mr-8">
```

Убрать нынешний `border-l border-gray-200` (он подразумевал цельный сайдбар). Теперь rail — отдельная `card` с `shadow-elev-1`, sticky при достаточной высоте:

```html
<aside class="hidden lg:flex w-64 shrink-0 self-start sticky top-20">
  <div class="card rounded-2xl shadow-elev-1 p-5 space-y-5 mr-8 mt-6 w-full">
```

Секция «Контакты» в rail — убрать (уже есть в hero). Rail теперь содержит только:
- Ответственный (user или UserSelect в editMode)
- Источник (text или select в editMode)
- Теги (бейджи)
- Создан / Обновлён (даты)

Секция-заголовок каждого блока:
```html
<div class="text-[10px] uppercase tracking-widest text-gray-400 font-medium mb-1">
  Ответственный
</div>
```

Теги → `.badge.badge-info text-xs` (вместо нынешнего `bg-gray-100` вручную).

### Табы

Оставить текущий паттерн кода (массив `TABS`, `.map()`). Только доработать:

1. Контейнер табов переместить ПОСЛЕ hero-секции:
```html
<div class="px-8 border-b border-gray-200 dark:border-gray-700
            bg-white dark:bg-gray-900 flex gap-0 flex-wrap mt-0">
```

2. Активный таб:
```html
<!-- было: border border-b-0 border-gray-200 ... -->
<!-- стало: -->
class={`px-4 py-2.5 text-sm border-b-2 transition-colors duration-150
  ${tab === t.key
    ? "border-primary text-primary font-medium"
    : "border-transparent text-gray-500 hover:text-primary hover:border-primary/30"
  }`}
```

Убрать `rounded-t-lg border border-b-0` (старый «вкладка»-стиль). Новый стиль — горизонтальная линия снизу (как в amoCRM/HubSpot).

3. Контент таба — обернуть в `BlurFade` с коротким `delay=0 duration=0.15s`:
```tsx
<div class="flex flex-1" key={tab}>
  <BlurFade duration={0.15} class="flex-1 p-8 min-w-0">
    {/* tab content */}
  </BlurFade>
  <ContactRightRail ... />
</div>
```

`key={tab}` вызывает ремаунт при смене таба → BlurFade отыгрывает. При `prefers-reduced-motion` — мгновенно.

### Empty states для табов

- Timeline empty: `<EmptyState icon="bi-clock-history" title="Активностей пока нет" description="Добавь звонок, встречу или задачу" />`
- Files empty: `<EmptyState icon="bi-folder2-open" title="Файлов нет" description="Прикрепи документ или скан" />`
- Deals empty: `<EmptyState icon="bi-kanban" title="Сделок нет" description="Контакт ещё не связан ни с одной сделкой" />`
- Companies empty: `<EmptyState icon="bi-building" title="Не привязан к компаниям" description="Добавь связь с компанией" />`
- Documents empty: `<EmptyState icon="bi-file-earmark-text" title="Документов нет" />`

### States страницы

| State | Что показывать |
|---|---|
| Loading | Hero-skeleton: `div.animate-pulse` высотой 120px + rounded-2xl вместо hero; tabs-skeleton: 5 полосок |
| Error | Уже есть `bg-danger/10 rounded px-3 py-2 text-sm text-danger` — оставить, обернуть в `mx-8 mt-6` |
| Edit mode | `editMode=true` → поля `InlineEditableField` уже есть; hero-кнопка «Готово» с `bi-check2` |

### Что НЕ трогать

- `patch()`, `InlineEditableField`, `PositionSearch`, `SourceSelect`, `UserSelect` логику
- Все tab-компоненты (`Timeline`, `FilesTab`, `ContactCompaniesTab`, `RelatedDealsTab`, `ContactDocumentsTab`, `AuditLogTimeline`)
- `PrimaryContactWarningModal`
- SWR ключи

### QA чеклист (карточка контакта)

- [ ] Hero-секция отображается: `Avatar` с инициалами, имя h1, должность, бейджи is_primary/source
- [ ] Быстрые контакты в hero: phone/email — кликабельные `<a>`, tg — plain text
- [ ] Action-кнопки справа: «К списку» (ghost), «Редактировать» (secondary)
- [ ] Правый rail — отдельная `card shadow-elev-1`, без `border-l`
- [ ] Rail sticky на desktop (top-20)
- [ ] Табы — `border-b-2` стиль, без `rounded-t-lg border border-b-0`
- [ ] Смена таба → `BlurFade` `duration=0.15s` (fade + slight translateY)
- [ ] `prefers-reduced-motion` → смена таба мгновенная без анимации
- [ ] Empty states: timeline/files/deals/companies/documents — через `EmptyState`
- [ ] `ContactRightRail`: теги рендерятся как `.badge.badge-info`
- [ ] tsc --noEmit: 0 ошибок

---

## 4. AI-панель (FAB + Drawer)

**Файлы:**
- `apps/web/src/components/AI/AiAssistantButton.tsx`
- `apps/web/src/components/AI/AiAssistantDrawer.tsx`
- `apps/web/src/components/AI/AiChatMessage.tsx` (стримминг-индикатор)

### Цель и дельта

Сейчас: FAB — `rounded-full bg-primary w-14 h-14`, простой `hover:bg-primary-light`. Drawer — белый, `bg-white border-l shadow-xl`. Typing-индикатор — `animate-bounce` точки. Нет искры/свечения на FAB, нет stagger сообщений, нет magic-фона в drawer.

Нужно: FAB с «искровым» эффектом (`BorderBeam` на hover или idle-pulse), drawer с тонким градиентным фоном, сообщения stagger через CSS, typing-индикатор аккуратнее, empty-state «начало чата» через `EmptyState`.

### FAB v2

Файл `AiAssistantButton.tsx`:

```tsx
<button
  onClick={() => setOpen(!open)}
  className={cn(
    "fixed bottom-6 right-6 z-50",
    "w-14 h-14 rounded-full",
    "bg-gradient-to-br from-primary to-primary-light",
    "text-white shadow-elev-3",
    "transition-all duration-200",
    "hover:scale-110 hover:shadow-elev-4",
    "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/50",
    "relative overflow-hidden",
  )}
  title="AI-ассистент"
  aria-label="Открыть AI-ассистент"
  aria-expanded={open}
>
  <i className={`bi ${open ? "bi-x-lg" : "bi-stars"} text-xl`} />
  {/* BorderBeam: только в idle (не open), дозированно */}
  {!open && (
    <BorderBeam
      size={1.5}
      duration={4}
      colorFrom="rgba(255,255,255,0.6)"
      colorTo="rgba(156,194,255,0.4)"
      borderRadius="50%"
    />
  )}
</button>
```

`shadow-elev-3` → `shadow-elev-4` при hover — используем токены. `hover:scale-110` — легкий lift-эффект. `BorderBeam` в idle создаёт медленное (4s) свечение по периметру FAB. При `open` — FAB показывает `bi-x-lg` и `BorderBeam` убирается.

`prefers-reduced-motion`: `BorderBeam` использует `@keyframes beam-spin`, который глушится глобальным CSS. `hover:scale-110` → добавить `motion-safe:hover:scale-110` вместо `hover:scale-110`.

### Drawer v2

Файл `AiAssistantDrawer.tsx`.

#### Фон drawer

```tsx
// было:
"bg-white dark:bg-gray-900 border-l border-gray-200 dark:border-gray-700 shadow-xl"

// стало:
"bg-white dark:bg-gray-900",
"border-l border-gray-200/60 dark:border-gray-700",
"shadow-elev-4",
// + subtle gradient overlay (decorative, aria-hidden):
```

Добавить декоративный градиент в верхнюю часть drawer:
```html
<!-- Внутри drawer, сразу после корневого div, перед header -->
<div
  aria-hidden="true"
  class="pointer-events-none absolute top-0 left-0 right-0 h-32
         bg-gradient-to-b from-primary/[0.04] to-transparent
         dark:from-primary/[0.08] z-0"
/>
```

Все дочерние элементы `relative z-10` (header, messages, input).

#### Header

```tsx
// Добавить subtle gradient underline к заголовку «AI-ассистент»:
<div class="flex items-center gap-2">
  <span class="relative">
    <i class="bi bi-stars text-primary-light" />
    {/* Pulse-ring — idle анимация иконки */}
    <span aria-hidden="true"
          class="absolute inset-0 rounded-full animate-ping opacity-20 bg-primary-light
                 motion-safe:animate-ping motion-reduce:hidden" />
  </span>
  <span class="text-sm font-semibold">AI-ассистент</span>
</div>
```

`animate-ping` — из Tailwind, создаёт пульсирующий ореол вокруг иконки. Только `motion-safe:` — при `prefers-reduced-motion` убирается.

#### Сообщения со stagger

Файл `AiAssistantDrawer.tsx`, блок `messages.map(...)`:

Обернуть каждое сообщение в `BlurFade` с stagger:

```tsx
{messages.map((msg, idx) => (
  <BlurFade
    key={msg.id}
    delay={Math.min(idx * 0.04, 0.3)}   // max stagger cap 300ms
    duration={0.18}
  >
    {/* existing message content */}
  </BlurFade>
))}
```

При первом render сессии (пустая история → 0 сообщений → вдруг 10 из sessionStorage) все сообщения появятся с лёгким каскадом. Новое сообщение (добавляется в конец) получает `delay=0` через cap.

Важно: `BlurFade` — `div`-обёртка. Убедиться, что внешние `justify-end`/`justify-start` flex на каждом сообщении сохранены — они должны быть ВНУТРИ `BlurFade`, не снаружи. Поэтому:

```tsx
<BlurFade key={msg.id} delay={...} duration={0.18}>
  <div class="flex {isUser ? 'justify-end' : 'justify-start'}">
    ...existing bubble...
  </div>
</BlurFade>
```

#### Typing-индикатор v2

Файл `AiChatMessage.tsx`, компонент `AiStreamingDots`:

```tsx
export function AiStreamingDots() {
  return (
    <div class="flex justify-start">
      <div class="flex items-center gap-1.5
                  bg-gray-100 dark:bg-gray-800
                  rounded-2xl rounded-bl-none
                  px-4 py-3">
        {[0, 1, 2].map((i) => (
          <span
            key={i}
            aria-hidden="true"
            class="w-1.5 h-1.5 rounded-full bg-gray-400 dark:bg-gray-500
                   motion-safe:animate-bounce"
            style={{ animationDelay: `${i * 120}ms`, animationDuration: "0.9s" }}
          />
        ))}
        <span class="sr-only">AI отвечает…</span>
      </div>
    </div>
  );
}
```

Точки: `w-1.5 h-1.5` (меньше нынешнего `w-2 h-2`), `animationDuration: 0.9s` (медленнее, спокойнее), `motion-safe:animate-bounce`.

#### Empty state чата

```tsx
// Вместо нынешнего ad-hoc блока:
{messages.length === 0 && !busy && (
  <EmptyState
    icon="bi-stars"
    title="Привет! Я AI-ассистент"
    description="Могу создать задачу, сделку или договор — просто опиши, что нужно"
  />
)}
```

#### Input-зона

```html
<div class="border-t border-gray-200/80 dark:border-gray-700 p-4 relative z-10">
  <div class="flex gap-2 items-end
              bg-gray-50 dark:bg-gray-800
              rounded-xl border border-gray-200 dark:border-gray-700
              px-3 py-2
              focus-within:border-primary/50 focus-within:ring-1 focus-within:ring-primary/20
              transition-all duration-150">
    <textarea
      class="flex-1 resize-none text-sm bg-transparent
             border-0 outline-none focus:ring-0
             placeholder:text-gray-400"
      rows={1}    <!-- изменить с 2 на 1; авто-рост через onInput -->
      ...
    />
    <button class="btn-primary shrink-0 rounded-lg px-3 py-1.5" ...>
      <i class="bi bi-send text-sm" />
    </button>
  </div>
</div>
```

Обёртка `bg-gray-50 rounded-xl` с `focus-within` ring даёт «floating input» эффект. `textarea rows=1` + авто-рост через `onInput={(e) => { const t = e.currentTarget; t.style.height = "auto"; t.style.height = t.scrollHeight + "px"; }}`.

### States AI-панели

| State | Поведение |
|---|---|
| Закрыт | FAB виден, `BorderBeam` idle-пульс |
| Открывается | Drawer slide-in `translate-x-0` (уже есть `transition-transform duration-300`) |
| Пустой чат | `EmptyState bi-stars` + `AiQuickChips` (уже есть) |
| Ожидание ответа | `AiStreamingDots` + input `disabled` |
| Ошибка | Уже есть `bg-danger/10 rounded-lg` баннер — оставить |

### Что НЕ трогать

- `sendMessage()`, `confirmAction()`, `cancelAction()`, `buildHistory()`
- `sessionStorage` персистентность
- `AiActionCard`, `AiQuickChips`, `AiCompanyModal`, `AIPrefillModal`
- Событие `crm:open-ai` и `forceOpen` prop
- Роутинг после подтверждения действия (`ENTITY_ROUTES`)

### QA чеклист (AI-панель)

- [ ] FAB: `bg-gradient-to-br from-primary to-primary-light` (не flat `bg-primary`)
- [ ] FAB idle: `BorderBeam` видна как тонкая вращающаяся дуга по периметру круга
- [ ] FAB hover: `scale-110` (только `motion-safe`) + `shadow-elev-4`
- [ ] FAB open: `bi-x-lg`, `BorderBeam` скрыта
- [ ] Drawer верх: градиент `from-primary/[0.04]` (едва заметный тонированный фон)
- [ ] Header: `bi-stars` с `animate-ping` ореолом (только `motion-safe`)
- [ ] Сообщения: `BlurFade` stagger при появлении
- [ ] Typing dots: `w-1.5 h-1.5`, плавный `animationDuration=0.9s`
- [ ] Empty state чата → `EmptyState bi-stars` (не ad-hoc div)
- [ ] Input: `rounded-xl bg-gray-50` floating-стиль, `focus-within` ring, `rows=1` авто-рост
- [ ] `prefers-reduced-motion`: `BorderBeam` без вращения, dots без bounce, BlurFade мгновенно
- [ ] `aria-expanded` на FAB-кнопке
- [ ] `sr-only` «AI отвечает…» в `AiStreamingDots`
- [ ] tsc --noEmit: 0 ошибок

---

## Общий чеклист перед сдачей

- [ ] Все 4 экрана/паттерна реализованы
- [ ] Новые компоненты: `LifecycleSegments.tsx`, `RegistryTableSkeleton.tsx`, `FinTableSkeleton.tsx`, `ContactHero.tsx`
- [ ] CSS в `globals.css`: `.registry-thead-shadow`, `.fin-thead-shadow` + `.scrolled` модификаторы
- [ ] `MoneyCell.tsx`: prop `direction?: "income" | "expense" | "transfer"`
- [ ] `HealthBadge` и `CategoryBadge`: убедиться что используют `.badge-*` классы v2
- [ ] `tsc --noEmit` → 0 ошибок по всему проекту
- [ ] `prefers-reduced-motion` протестировано в DevTools (Rendering → Emulate)
- [ ] Desktop-first: все экраны проверены на 1280px и 1440px viewport
- [ ] Бизнес-логика не изменена ни в одном из четырёх экранов

---

## Открытые вопросы

1. **LifecycleSegments и код C0** — C0 (отвалившийся) не является частью линейной прогрессии B/A. Рендерить как одиночный `badge-danger "C0"` без сегментов. Если code вообще не парсится (нестандартный) — fallback на plain text badge-info.

2. **Avatar компонент** — убедиться, что `Avatar` принимает `size: number` prop (или аналог `className="w-14 h-14"`). Если API не совпадает — адаптировать hero-секцию под реальный интерфейс компонента.

3. **ContactHero vs PageHeader** — страница контакта в текущей реализации использует `PageHeader`. После внедрения hero hero-секция должна полностью заменить `PageHeader`. Убедиться, что breadcrumb / back-navigation не дублируется.

4. **FinTableSkeleton col widths** — Tailwind purge может удалить динамически генерируемые классы `w-${n}`. Либо передавать `style={{ width: '...' }}`, либо использовать фиксированный набор классов. Рекомендую: props `cols` как массив процентных строк (`"32%"`, `"16%"`...) и `style={{ width }}` вместо Tailwind-классов.

5. **BorderBeam на circle FAB** — `borderRadius="50%"` нестандартный для `BorderBeam`. Проверить визуально: если луч не огибает круг корректно — fallback на `box-shadow pulse` анимацию через `@keyframes` в globals.css.
