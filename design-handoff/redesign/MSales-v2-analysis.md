# MACROSALES 2.0 Design System — Анализ для стратегического отчёта

**Источник:** `/Users/bogdanadykin/Downloads/MSales 2.0 дизайн-бриф/`
**Дата анализа:** 2026-07-01
**Автор:** designer-agent

---

## 1. Что такое DS v2 MSales

### Статус и контекст

DS v2 — это **«Вариант B» дизайн-системы** для нового продукта MSales 2.0 (проект X),
вертикальной CRM для застройщиков/агентств недвижимости. Собрана как HTML-экспорт
дизайн-канваса (`.dc.html`) с живым JS-кодом тем. Стек реализации, декларированный
в самом брифе: **React + PrimeReact (Aura preset) + Bootstrap 12-колоночная сетка.**
Подзаголовок «Вариант B · больше воздуха, белый фон» — конкурирует с неупомянутым
«Вариантом A» (видимо, тёмный/тонированный фон).

Токены разделены на **две категории:**
- «Из гайдлайна» — зафиксированы в корпоративном брендбуке MACRO, не обсуждаются.
- «EXT» (расширение) — предложены дизайнером, **требуют подтверждения бизнесом:**
  шкала радиусов, тени, расширенная шкала статусов (маркетинговый резерв, маркетинговая
  сделка и т.д.).

### Ключевые токены

**Цветовая палитра (из гайдлайна):**

| Токен | Light | Dark | Роль |
|---|---|---|---|
| `--brand` | `#172747` | `#091020` | Рельса, шапки, brand-хедер |
| `--accent` | `#2B4987` | `#4C7DF0` | Основное действие, ссылки |
| `--accent-hover` | `#4168CB` | `#6E99FF` | Hover кнопок |
| `--bg` | `#FFFFFF` | `#0A1426` | Канвас страницы |
| `--surface` | `#FFFFFF` | `#111E38` | Карточки, попаперы |
| `--surface-2` | `#F6F7F8` | `#172847` | Строки таблицы, тулбар |
| `--surface-3` | `#EDEFF1` | `#1F3157` | Disabled-фон, skeleton |
| `--border` | `#E7E9EC` | `#27395C` | Разделители |
| `--border-strong` | `#CDD2D7` | `#3A4F78` | Активный border |
| `--text` | `#1C2530` | `#EAF0FA` | Основной текст |
| `--text-2` | `#5A616B` | `#B4C2DA` | Вторичный текст |
| `--text-3` | `#878D96` | `#8593B0` | Мета, hint |
| `--muted` | `#A6ABB2` | `#647294` | Заглушки, иконки |
| `--success` (solid) | `#A7EFAA` | `#7BE07F` | Успех |
| `--danger` (solid) | `#FF5A44` | `#FF6B57` | Ошибка, удаление |
| `--warning` (solid) | `#FFB38A` | `#FFB87E` | Предупреждение |
| `--info` (solid) | `#8DD9FF` | `#7CCBFF` | Информация |

**Фирменная шкала серого (Gray-100 → Gray-900):**
`#F1F2F3` / `#E3E4E6` / `#D5D6D8` / `#B8B9BB` / `#9B9C9F` / `#7E7F82` / `#616263` / `#444547` / `#272829`

**Типографика:**
- **Основной:** SF UI Display (все платформы, все веса Light/Regular/Medium/Semibold/Bold/Black)
- **Дополнительный:** Roboto (официальные документы)
- **Web-fallback:** `-apple-system, BlinkMacSystemFont, "SF Pro Display", "SF UI Display", "Segoe UI", Roboto, system-ui, sans-serif`

**Шкала типографики (EXT):**

| Уровень | px / weight | Роль |
|---|---|---|
| Display 1 | 72 / Bold | — |
| Display 3 | 58 / Bold | Шахматка юнитов |
| h1 | 40 / Bold | Заголовок страницы |
| h2 | 32 / Semibold | Раздел модуля |
| h3 | 28 / Semibold | Подраздел / карточка |
| h4 | 24 / Medium | Заголовок блока |
| h5 | 20 / Medium | Заголовок группы полей |
| h6 | 16 / Medium | Метка таблицы |
| Lead | 21 / Regular | Вводный текст |
| Body | 16 / Regular | Основной текст, поля |
| Small | 13 / Regular | Подписи, hint |

**Spacing (EXT):** шкала 4/8/12/16/20/24/28/32px — базис 4px.

**Радиусы (EXT — не в гайдлайне, предложены):**
- 4px — поля, чипы
- 6px — кнопки, инпуты
- 8px — карточки
- 12px — модалки, дровер
- 999px (pill) — бейджи, аватары

**Тени (EXT):**
- `sh-1`: `0 1px 3px rgba(20,28,40,.06)` — карточки, строки таблицы
- `sh-2`: `0 8px 24px rgba(20,28,40,.08)` — дропдауны, поповеры
- `sh-3`: `0 20px 50px rgba(20,28,40,.14)` — модалки, дровер

### Сетка / Layout

- **12-колоночная Bootstrap** (стандарт)
- Экран 1920px → контейнер 1320px · колонка 87.5px · гаттер 24px

### App Shell (из `MACROSALES App Shell.dc.html`)

Архитектура шелла — **трёхзонная:**

```
[68px Icon Rail] [248px Section Panel] [Main Area]
```

- **Icon Rail (68px, `#172747`):** иконки-кнопки с tooltip (hover → label справа),
  логотип внизу, кнопка collapse. `bi-*` иконки Bootstrap Icons.
- **Section Panel (248px, `--surface`):** тенант-свитчер сверху, список воронок
  с количеством, добавление воронки (для админа), избранное снизу.
- **Main Header (60px):** поиск (⌘K), уведомления, аватар-меню, AI-кнопка.
- **Sub-header воронки:** заголовок страницы + badge снимка, кнопка «Новый лид»
  (split-button), фильтры в строку, «расширенные фильтры» toggle.
- **Kanban-зона:** колонки 300px, скролл горизонтальный, коллапс колонки в узкую полосу.
- **Lead Detail:** правый drawer на весь экран (min(1320px, 97%)):
  - LEFT: форма (секции «Клиент», «Заявка», «Объект») + статус/подстатус
  - RIGHT (520px): события / сообщения (каналы) / заявки; composer снизу

### Набор компонентов

Задокументированы в DS v2:
1. **Button** — primary (accent), outlined, text, brand (dark), danger, small, icon-only, disabled
2. **InputText** — с иконкой, focus-ring (accent border + soft shadow), error-state
3. **Dropdown** — chevron, placeholder, focus
4. **DatePicker** — иконка календаря
5. **Checkbox** — accent-fill
6. **Radio** — accent-border
7. **Switch** / Toggle
8. **Chip** (removable) — surface-2 фон
9. **Tag / Badge** — pill на `--st-*-bg/ink` токенах; dot-badge (числа)
10. **Avatar** — initials, цветные, stack с overflow
11. **Tabs** — border-bottom underline, активная — accent
12. **DataTable** — compact row, header uppercase, hover accent-5%, row-border surface-2
13. **Dialog** — 320px, border-radius 16, sh-3
14. **Toast** — left-border 4px по severity, sh-2
15. **Tree** — chevron + иконки, indent
16. **Skeleton** — shimmer animation, shimmer-цвета surface-2/surface-3
17. **Empty state** — иконка 64px в surface-2 box, заголовок + описание + CTA
18. **CommandPalette (Cmd+K)** — border-radius 14, sh-2
19. **Kanban Card** — border-radius 12, left bar (colored), hover opacity actions
20. **Notification Dropdown** — 404px, grouped по датам, unread dot
21. **View Settings Modal** — 980px × 680px, два уровня вкладок (personal/company)
22. **Board Snapshot Picker** — calendar dropdown, date presets

### Темы

**ДВЕ темы полностью реализованы** (light + dark) с отдельными объектами токенов в JS.
Toggle — кнопка «Light/Dark» в интерфейсе. Семантика токенов одинакова в обеих темах
(surface/surface-2/surface-3/border/text/accent/success/danger/warning/info — все покрыты).

**RTL (AR)** — третий режим, задокументирован и демонстрируется в брифе:
dir="rtl", logical CSS properties (`padding-inline`, `inset-inline`), телефоны/числа/цены
остаются LTR (dir="ltr" инлайн).

### Степень формализации токенов

**Высокая** — все токены объявлены как CSS custom properties (`--token-name`),
JS-объект тем содержит полный маппинг, статусная палитра (solid / bg / ink)
разделена на Light и Dark. Расширения (EXT) честно отмечены как «требуют подтверждения».

---

## 2. Сопоставление с текущей темой MG CRM

### Что совпадает (прямое переиспользование)

| Аспект | DS v2 MSales | MG CRM (текущее) | Статус |
|---|---|---|---|
| Brand primary | `#172747` | `#172747` | Идентично |
| Accent | `#2B4987` | `--p-primary-700` (mapped `#2B4987`) | Идентично |
| Accent hover | `#4168CB` | `--p-primary-500` | Идентично |
| Icon-set | Bootstrap Icons (`bi bi-*`) | PrimeIcons (`pi pi-*`) | **Разные** |
| Сетка | Bootstrap 12-col | Bootstrap 12-col | Идентично |
| Статусная семантика | success/danger/warning/info | Tag severity | Совпадают по смыслу |
| Spacing base | 4px → 32px | `$space-*` 4→32 | Идентично |
| Font primary | SF UI Display | SF UI Display / Inter fallback | Совпадает |

### Что расходится

| Аспект | DS v2 MSales | MG CRM (текущее) | Δ |
|---|---|---|---|
| UI-библиотека | **React + PrimeReact** | **Vue + PrimeVue** | Разные фреймворки, одинаковые компоненты Aura |
| Icon-set | Bootstrap Icons | PrimeIcons | Замена нужна или DS-унификация |
| Surface (light) | `bg:#FFFFFF, surface:#FFFFFF, surface-2:#F6F7F8, surface-3:#EDEFF1` | MGCRM surface похожа, но токены `--p-surface-*` могут отличаться точными hex | Проверить `front/src/theme/` |
| Dark surface | `bg:#0A1426, surface:#111E38, surface-2:#172847, surface-3:#1F3157` | Инвертированная PrimeVue dark (известный gotcha) | **Критично** — смотри §3 |
| Dark accent | `#4C7DF0` (значительно светлее) | текущий primary на dark — нужно проверить | Может расходиться |
| Радиусы | 4/6/8/12/999 (EXT) | 4/6/8/12 (уже в токенах MGCRM) | **Совпадают** |
| Тени | sh-1/sh-2/sh-3 (легче) | аналогичная трёхуровневая схема | Совпадают по схеме |
| App Shell | icon-rail 68px + section-panel 248px | sidebar (таблетки, 68px) + Орбита | **Близко, но разные** |
| Шелл секции | воронки в section-panel | Орбита (Vizion Toolbox) | Разная реализация |
| RTL | полный из коробки | не реализован | **Новое требование X** |
| i18n | RU/EN/AR | RU/EN | AR — новое |
| Размер карточки лида | drawer на весь экран (1320px) | drawer (существующий) | Похоже |

### Вывод: насколько «другая система»

**Не другая — это эволюция.** Бренд-инварианты (`#172747`, `#2B4987`) идентичны.
Spacing 4px-base, Bootstrap-сетка, трёхуровневые тени, шкала радиусов 4/6/8/12 —
всё совпадает с тем, что уже заложено в MGCRM. Главное расхождение — иконки
(Bootstrap Icons vs PrimeIcons) и тонкие различия в dark-палитре.

**Практически это означает:** `definePreset` в `front/src/theme/` нужно уточнить
под новые dark-значения, добавить `--bs-icon-*` если нужен Bootstrap Icons,
но базовая тема не переписывается с нуля.

---

## 3. Воспроизводимость ОБЕИХ тем на текущем стеке (PrimeVue Aura + definePreset)

### Light-тема: воспроизводится 1-в-1

Все цвета DS v2 (light) либо уже есть как токены MGCRM, либо ложатся напрямую
в `definePreset` без кастомного CSS. Mapping:

```
DS v2 --accent       → $p-primary-700 / --p-primary-700
DS v2 --accent-hover → $p-primary-500 / --p-primary-500
DS v2 --surface-2    → --p-surface-50 (нужно проверить exact hex)
DS v2 --border       → --p-content-border-color
DS v2 --text-3       → --p-text-muted-color (или кастомный токен)
```

**Что PrimeVue закрывает из коробки:** Button (все варианты), InputText, Select,
Checkbox, RadioButton, ToggleSwitch, Chip, Tag, Badge, DataTable, Dialog, Drawer,
Skeleton, Tabs, Tree, Toast, Menu. **Это весь список компонентов DS v2.**

**Что потребует passthrough / кастомного SCSS:**
- Split-button «Новый лид + chevron» — PrimeVue `SplitButton` есть, но стиль
  с `border-inline-start` нужно будет добить через passthrough.
- CommandPalette (Cmd+K) — нет в PrimeVue; кастомный компонент через `Dialog`
  с `position:top`. (В MGCRM уже есть или аналог нужно делать.)
- Kanban Card — не компонент PrimeVue; самописный `<div>`. Нужен кастомный.
- Board Snapshot DatePicker — стандартный `DatePicker`, но dropdown-оболочка вокруг
  него кастомная. Средняя сложность.
- Notification dropdown — кастомный overlay, не PrimeVue Popover (слишком специфичный
  контент). Средняя сложность.
- View Settings Modal (tabs-in-modal) — `Dialog` + `Tabs` внутри. Стандартная композиция.

### Dark-тема: воспроизводится, но требует ручной правки

**Ключевая проблема — инвертированная dark-шкала PrimeVue Aura.**
В PrimeVue Aura dark-тема использует `surface-0…surface-900` **инвертированно:**
surface-0 в dark — это тёмный фон, а не белый. DS v2 MSales задаёт explicit dark-значения:

```js
dark: {
  bg:       '#0A1426',   // самый тёмный
  surface:  '#111E38',
  surface2: '#172847',
  surface3: '#1F3157',
  border:   '#27395C',
  borderStrong: '#3A4F78',
  accent:   '#4C7DF0',   // значительно светлее чем light #2B4987
  ...
}
```

PrimeVue Aura dark по дефолту не обязательно даст `#111E38` как surface. Нужно
в `definePreset` явно прописать dark-секцию с этими hex:

```ts
definePreset(Aura, {
  primitive: {
    // ...light токены...
  },
  semantic: {
    colorScheme: {
      dark: {
        surface: {
          0: '#0A1426',
          50: '#111E38',
          100: '#172847',
          200: '#1F3157',
          300: '#27395C',
          400: '#3A4F78',
          // ...
        },
        primary: {
          color: '#4C7DF0',      // DS v2 dark accent
          hoverColor: '#6E99FF',
          // ...
        }
      }
    }
  }
})
```

**Это решаемо, но требует ручной сверки** каждого dark-значения DS v2 с PrimeVue
color-scheme токенами. Работа: ~1 день.

**Статус цвета dark-accent:** В DS v2 dark — `#4C7DF0` (Electric Blue). В текущем
MGCRM dark-accent неизвестен без чтения `front/src/theme/`. Если там сейчас `#2B4987`
(без dark-specific значения) — это расхождение; DS v2 требует отдельного значения для dark.

### RTL

PrimeVue 4.x **поддерживает RTL** из коробки через `dir="rtl"` на корне и
logical CSS properties в компонентах. DS v2 проектировалась с `inset-inline`,
`padding-inline` — это совпадает с PrimeVue-подходом. Дополнительных усилий на
компонентном уровне минимально, но **страницы-раскладки** (шелл, воронка) нужно
будет тестировать и фиксить отдельно.

---

## 4. Оценка сложности + задачи миграции

### Список задач

| Блок | Задача | Размер | Риск |
|---|---|---|---|
| **Тема** | Сверить и обновить `definePreset` — dark-секция (surface/border/accent/text под DS v2) | S | Низкий |
| **Тема** | Явно прописать dark primary `#4C7DF0` + hover `#6E99FF` | S | Низкий |
| **Тема** | Проверить точные hex surface-2/surface-3 light против DS v2 `#F6F7F8`/`#EDEFF1` | XS | Низкий |
| **Тема** | Если нужен Bootstrap Icons рядом с PrimeIcons — добавить CDN/npm `bootstrap-icons` | S | Низкий |
| **Shell** | Section Panel (248px) — список воронок, тенант-свитчер, избранное | M | Средний |
| **Shell** | Sub-header воронки — Snapshot picker, фильтры, split-button «Новый лид» | M | Средний |
| **Shell** | Board Snapshot DatePicker (кастомный dropdown над PrimeVue DatePicker) | M | Средний |
| **Shell** | View Settings Modal (tabs-in-modal, visibility + card layout + admin process) | M | Средний |
| **Shell** | Notification dropdown (404px overlay с группами и действиями) | S | Низкий |
| **Shell** | AI-кнопка в хедере | S | Низкий |
| **Kanban** | Card с left-bar, temp-индикатор (🔥/❄), hover-actions | M | Низкий |
| **Kanban** | Column header: collapse in strip, top-bar colored, + кнопка | S | Низкий |
| **Lead Drawer** | Форма LEFT + секции + статус/подстатус | L | Средний |
| **Lead Drawer** | Activity tab (лента событий, фильтры, composer) | L | Средний |
| **Lead Drawer** | Messages tab (каналы: Telegram/WhatsApp/...) | L | Высокий (зависит от интеграций) |
| **Компоненты** | CommandPalette (Cmd+K) — новый компонент | M | Низкий |
| **Компоненты** | Extended status scale (шахматка: мрезерв, мсделка, done) — 2 новых EXT-статуса | S | Низкий |
| **Страницы** | Шахматка юнитов (custom grid, 7 статусов, left-bar) | L | Средний |
| **RTL** | Тест и фиксы раскладки при dir="rtl" | M | Средний |
| **i18n** | AR-локаль (vue-i18n, ключи + переводы) | M | Средний (контент) |
| **DS-контроль** | Обновить `lint:ds` правила под новые токены | S | Низкий |

### Грубые оценки по блокам

| Блок | Размер | Комментарий |
|---|---|---|
| Тема (definePreset-update) | **S** (1-2 дня) | Механическая работа |
| Shell (rail + panel + header) | **M** (3-5 дней) | Большинство паттернов уже есть в MGCRM |
| Kanban (колонки + карточки) | **M** (3-4 дня) | Текущий kanban как база |
| Lead Detail Drawer | **L** (5-8 дней) | Сложная двухпанельная форма |
| Messages/Channels tab | **L** (+ интеграции) | Зависит от готовности каналов |
| Шахматка юнитов | **L** (5-7 дней) | Специфичный для MSales компонент |
| CommandPalette | **M** (2-3 дня) | Кастомный, но не сложный |
| RTL + AR | **M** (3-4 дня) | Системная работа, риск регрессии |
| **Итого DS-миграция** | **XL** (~30-40 дней) | Без блокировки на новые бизнес-фичи |

### Риски

1. **Инвертированная dark-шкала** — главный риск. Если `definePreset` написан
   без явных dark-секций, dark-тема будет выглядеть неправильно. Решение: аудит
   текущего `front/src/theme/` и явная dark-секция.
2. **Bootstrap Icons vs PrimeIcons** — иконки DS v2 `bi bi-*`. Два варианта:
   а) переехать на Bootstrap Icons полностью, б) использовать PrimeIcons с алиасами.
   Вариант (а) — большой объём замен в уже написанном коде MGCRM.
3. **Messages tab** — зависит от backend: каналы (Telegram/WhatsApp/Email) должны
   быть подключены. UI без backend бессмысленен.
4. **RTL регрессия** — добавление RTL сломает экраны где используются `padding-left`
   вместо `padding-inline-start`. Нужен систематический CSS-аудит.
5. **EXT-токены неапрувнуты** — радиусы, тени, расширенные статусы (маркет.резерв,
   маркет.сделка) требуют бизнес-подтверждения. Реализовывать только после апрува.

---

## 5. Воспроизводимость на React/PrimeReact

### Общая картина

DS v2 MSales **явно написана под React + PrimeReact** (Aura preset) — это декларировано
в интро документа: «Стек реализации — React + PrimeReact (Aura preset)». Значит:

**PrimeReact + PrimeVue используют одну дизайн-систему Aura с идентичными
дизайн-токенами.** Это задокументировано командой Prime: токены `--p-*` одинаковы
между PrimeVue и PrimeReact, `definePreset` синтаксически аналогичен. Тематизация
через CSS custom properties одинакова.

### PrimeReact vs PrimeVue: что одинаково

- Токены `--p-primary-*`, `--p-surface-*`, `--p-content-*` — **идентичны**
- `definePreset(Aura, {...})` синтаксис — **идентичен**
- Набор компонентов (Button, DataTable, Dialog, Drawer, Tabs, Skeleton...) — **1-в-1**
- Aura preset семантика (colorScheme.light/dark) — **идентична**
- passthrough API — **идентичен по структуре**

### PrimeReact vs PrimeVue: что отличается

| Аспект | PrimeVue (MGCRM) | PrimeReact (MSales target) | Δ |
|---|---|---|---|
| Фреймворк | Vue 3 Composition API | React (hooks) | Другой фреймворк |
| Компонент `<Button>` | Vue SFC prop `:icon`, `:label` | React JSX prop `icon`, `label` | API похожи |
| Composables | `useToast()`, `useConfirm()` | `useToast()`, `useConfirm()` | Идентичны |
| Pinia store | Vue-специфично | Zustand/Redux | Разные |
| Vue Router | Vue-специфично | React Router | Разные |
| Templates | `<template>` slots | render props / children | Разные |
| SCSS-мост | `var(--p-*)` в `.vue` SFC | `var(--p-*)` в `.module.css` или `styled` | Одинаковы по токенам |

### Вывод по React/PrimeReact

С точки зрения **дизайн-системы и визуального результата** — PrimeReact и PrimeVue
дадут **идентичный пиксель-точный результат** при одинаковом `definePreset`.
Переход между ними — это **смена фреймворка, не смена дизайн-системы.**

Для стратегического решения «Vue vs React»:
- **DS v2 был написан под React** — значит если X строится на React, DS ложится 1-в-1.
- Если MGCRM остаётся на Vue — DS v2 тоже ложится 1-в-1 через PrimeVue (токены идентичны).
- **Единственная нетривиальная задача** в обоих случаях — dark-тема и RTL.

---

## Сводная таблица для стратегического отчёта

| Вопрос | Ответ |
|---|---|
| Есть ли light+dark в брифе? | **Да, обе** полностью реализованы с отдельными объектами токенов |
| Насколько DS v2 отличается от текущей MG CRM темы? | **Эволюция, не замена.** Бренд-инварианты идентичны. Главные дельты: dark-palette (явные hex surface/accent), иконки (bi vs pi) |
| Воспроизводимость на PrimeVue? | **Высокая.** Все компоненты есть. Нужна правка `definePreset` dark-секции + 2-3 кастомных компонента (CommandPalette, Kanban Card) |
| Сложность миграции темы (definePreset only)? | **S** (1-2 дня: dark-секция + тонкая сверка light hex) |
| Сложность миграции полного Shell + экранов? | **XL** (~30-40 дней: Shell M + Drawer L + Шахматка L + RTL M) |
| PrimeReact vs PrimeVue для DS v2? | **Одинаковая сложность.** Токены Aura идентичны. Разница — фреймворк (Vue SFC vs JSX), не DS |
| RTL готовность текущего MGCRM? | **Нет.** Требует системного CSS-аудита и замены `left/right` на `inline-*` |
| Иконки — риск? | **Средний.** DS v2 на Bootstrap Icons, MGCRM на PrimeIcons. Либо унифицировать, либо держать два набора |
| EXT-токены статус? | **Не апрувнуты.** Радиусы/тени/расш.статусы нужно подтвердить у бизнеса перед реализацией |
