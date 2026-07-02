# ТЗ: MSales 2.0 — тёмная тема (navy). Резолюция трёх открытых вопросов

**Зачем:** этап 1 визуал-апдейта MSales 2.0 — миграция dark-темы CRM на navy-палитру
пакета MACROSALES 2.0. Этот документ закрывает три открытых дизайн-вопроса тех-аудита
(`docs/audit/Theme-migration-tech-audit-2026-07.md` §6) конкретными hex и правилами.
**Где в коде:** `front/src/theme/**` (preset/foundation/colors/appVariables/scss/echarts).
**Эталон значений:** `design-handoff/tokens/dark.css` (в репо; = пакетный `dark.css`, идентичны).
**Статус:** апрувнуто для реализации frontend-specialist. Light-тема НЕ меняется — только dark.

> **Инвариант подхода (из аудита §4.1, option 1):** сохраняем ИНВЕРТИРОВАННУЮ шкалу
> `colorScheme.dark.surface` — меняем только hex-значения (grey → navy). `surface-50` = самый
> тёмный фон … `surface-900` = почти-белый текст. Никакого переписывания на прямую шкалу
> (сломало бы 59 usages `surface-800/900/0` как тёмных фонов). Все ссылки `{surface.X}` в
> foundation.ts/preset.ts сохраняют смысл, просто резолвятся в navy.

---

## Решение (a) — Filled-primary акцент в dark

**Вердикт: ДА, переключаем.** Сегодня filled-кнопка force-pinned на `{primary.900}` = `#172747`
в ОБЕИХ темах (`preset.ts` button.colorScheme.dark.root.primary). На фоне `#0A1426` navy-кнопка
почти сливается → нарушает пакетный чек-лист приёмки. В dark бренд-акцент **светлеет** до
`#4C7DF0` (пакет `--mg-action-primary-*`). Light остаётся navy без изменений.

| Роль | light (без изменений) | dark (новое) | источник dark.css |
|---|---|---|---|
| filled primary bg | `#172747` | **`#4C7DF0`** | `--mg-action-primary-bg` |
| filled primary hover | `#1F2F5A` | **`#6E99FF`** | `--mg-action-primary-hover` |
| filled primary active | `#263A6E` | **`#3D6AD8`** | `--mg-action-primary-active` |
| filled primary **текст (ink)** | `#FFFFFF` | **`#EEF3FB`** (near-white) | `--mg-action-primary-text` |

**Ink на светло-синем `#4C7DF0`:** остаётся светлым (`#EEF3FB`), НЕ инвертируем в тёмный.
`#4C7DF0` — средне-тёмный синий (контраст near-white ≈ 4.6:1, проходит AA для 14px+/bold).
Не используем `#0A1426`-ink на кнопке (был бы низкий контраст и «дешёвый» вид).

**Что с остальными вариантами кнопок в dark:**
- **outlined / text / link primary** — используют `primary.color` (не заливку). В dark
  `colorScheme.dark.primary.color` уже становится светло-синим — меняем его с текущего
  `{primary.400}` (`#6F87BC`) на **`#4C7DF0`** (hover `#6E99FF`, active `#3D6AD8`). Тогда
  бордер/текст outlined и текст text/link-кнопок автоматически читаются на navy.
- **outlined secondary** — существующий dark-фикс (`preset.ts`, border `{surface.300}`,
  text `{surface.800}`) сохраняется как есть: он и так завязан на surface-шкалу, которая
  станет navy. Проверить визуально после навязки шкалы (c).
- **secondary filled** (если где-то есть) — bg `#172847`, ink `#DCE4F2`, border `#3A4F78`
  (`--mg-action-secondary-*`).
- **danger** — bg `#FF6B57`, hover `#FF8271`, ink `#FFFFFF` (`--mg-action-danger-*`);
  destructive-outlined читает danger `text` (см. статусы).

**Ссылки (текстовые `<a>` / inline-links):** dark `#9DB8FF` (`--mg-text-link`) — светлее
акцента, ярче на navy. Hover — `#6E99FF`.

**Активные состояния контролов (checkbox / radio / switch «on»):** заливка — акцент
**`#4C7DF0`**, галочка/точка/ручка — `#EEF3FB`. Это тот же `primary.color`, так что
переезжает вместе с ним. Индетерминантный/hover-бордер контрола — `#6E99FF`.

**Focus-ring:** в dark — **`#6E99FF`** (`--mg-input-focus-border` / `--mg-border-accent`).
Light focus-ring остаётся `#172747` (бренд, `semantic.focus`). Ring в dark должен быть
светлее акцента, чтобы читаться на navy-полях.

---

## Решение (b) — ECharts palette + хром на navy

**Series-палитра — задаём отдельный DARK-список** (осветлённые тинты тех же hue, чтобы
графики читались на карточке `#111E38`). Текущая `MACRO_ECHARTS_PALETTE` подобрана под
светлое: `#172747` (navy) и `#2B4987` на navy-карточке сливаются, `#7E7F82`/`#9B9C9F` глохнут.

`plugins/echarts.ts`: `buildMacroCrmTheme(isDark)` выбирает палитру по флагу
(`isDark ? MACRO_ECHARTS_PALETTE_DARK : MACRO_ECHARTS_PALETTE`).

**`MACRO_ECHARTS_PALETTE_DARK` (10 цветов, порядок сохранён):**

| # | light (текущий) | dark (новый) | обоснование |
|---|---|---|---|
| 0 | `#2B4987` | **`#5B8DEF`** | акцент-синий (= navy-accent осветлённый) |
| 1 | `#172747` | **`#4C7DF0`** | бренд-акцент dark (`--mg-navy-accent`) |
| 2 | `#8DD9FF` | **`#7CCBFF`** | info-solid dark |
| 3 | `#6C757D` | **`#8593B0`** | нейтраль navy-muted (`--mg-text-muted`) |
| 4 | `#A7EFAA` | **`#7BE07F`** | success-solid dark |
| 5 | `#FF5A44` | **`#FF6B57`** | danger-solid dark |
| 6 | `#ABB5BE` | **`#AEB9CE`** | светлая нейтраль navy (`--mg-gray-700` dark) |
| 7 | `#FFB38A` | **`#FFB87E`** | warning-solid dark |
| 8 | `#7E7F82` | **`#6E99FF`** | второй синий (accent-hover) для доп.серий |
| 9 | `#9B9C9F` | **`#9DB8FF`** | accent-soft |

> Первые 6 hue покрывают самые частые 6-серийные графики и все читаются на `#111E38`.
> Серии 7–9 — доп. тона. Ни один цвет не темнее карточки.

**Оси / сетка / подписи / tooltip — обновить DARK-константы** (сейчас grey `#E3E4E6`/`#7E7F82`/
`rgba(97,98,99,…)`/`rgba(39,40,41,.96)`):

| константа | текущий dark | новый dark | источник |
|---|---|---|---|
| `TEXT_PRIMARY_DARK` | `#E3E4E6` | **`#EAF0FA`** | `--mg-text-primary` |
| `TEXT_MUTED_DARK` | `#7E7F82` | **`#8593B0`** | `--mg-text-muted` |
| `AXIS_LINE_DARK` | `rgba(97,98,99,.4)` | **`rgba(58,79,120,.55)`** | `--mg-navy-border-strong` @55% |
| `SPLIT_LINE_DARK` | `rgba(97,98,99,.25)` | **`rgba(39,57,92,.45)`** | `--mg-navy-border` @45% |
| `TOOLTIP_BG_DARK` | `rgba(39,40,41,.96)` | **`rgba(23,40,71,.96)`** | `#172847` (surface2) @96% |
| line/pie `borderColor` (dark) | `#2C2C2C` | **`#111E38`** | card bg = разделитель сегментов |

Tooltip-текст остаётся светлым (`#EAF0FA`); подписи pie/легенда — `TEXT_PRIMARY_DARK`.

---

## Решение (c) — Полная инвертированная navy-шкала surface-50…900

Достраиваем полную шкалу по опорным точкам пакета (`#0A1426 / #111E38 / #172847 / #1F3157`
+ границы `#27395C / #3A4F78`). **Инверсия сохраняется:** `surface.0/50` = самые тёмные фоны,
`surface.800/900` = светлый текст. Средние шаги (300–700) интерполированы по hue navy
(тон ~218°, растущая светлота), консистентно с опорными.

`foundation.ts → colorScheme.dark.surface` (задаём hex напрямую, как сегодня; можно вынести
в `colors.ts` как `navyDarkSurfacePalette` для чистоты):

| `--p-surface-*` (dark) | текущий grey | **NAVY (новый)** | роль | опора dark.css |
|---|---|---|---|---|
| 0 | `#000000` | **`#0A1426`** | глубочайший фон / page | `--mg-navy-bg` |
| 50 | `#272829` | **`#0F1F3D`** | app bg alt / disabled-input darker | `--mg-gray-50` (dark) |
| 100 | `#444547` | **`#111E38`** | **card / panel / input / overlay bg** | `--mg-navy-surface` |
| 200 | `#616263` | **`#172847`** | muted / raised / border-soft | `--mg-navy-surface2` |
| 300 | `#7E7F82` | **`#27395C`** | border default / hover-border | `--mg-navy-border` |
| 400 | `#9B9C9F` | **`#3A4F78`** | strong border / placeholder / icon | `--mg-navy-border-strong` |
| 500 | `#B8B9BB` | **`#647294`** | muted text / disabled ink | interp (input-placeholder) |
| 600 | `#D5D6D8` | **`#8593B0`** | secondary-muted text | `--mg-text-muted` |
| 700 | `#E3E4E6` | **`#B4C2DA`** | secondary text | `--mg-text-secondary` |
| 800 | `#F1F2F3` | **`#C6D0E2`** | strong text (outlined ink) | `--mg-gray-800` (dark) |
| 900 | `#272829→#F9FAFB` | **`#EAF0FA`** | primary text | `--mg-text-primary` |
| 950 | `#000000→#FFFFFF` | **`#F5F8FE`** | max contrast text/near-white | interp |

> Проверка семантики после навязки (уже завязано на эти шаги через `{surface.X}`):
> card bg = `{surface.100}` = `#111E38` ✔ · card border = `{surface.200}` = `#172847` ✔ ·
> primary text = `{surface.900}` = `#EAF0FA` ✔ · input bg = `{surface.100}` = `#111E38` ✔ ·
> input border = `{surface.200}`; лучше поднять до `{surface.400}` (`#3A4F78`) — см. правило
> ниже · overlay/modal bg = `{surface.100}` = `#111E38` ✔ · striped row = `{surface.50}` =
> `#0F1F3D` (чуть темнее card) ✔.

**Правка семантики inputs после навязки шкалы:** пакет хочет border инпута `#3A4F78`
(`--mg-input-border`), а `{surface.200}` = `#172847` (мягкий). Переопределить в
`formField.borderColor` dark на `{surface.400}` = `#3A4F78`; hover — `{primary.color}`
(`#4C7DF0`), focus — `#6E99FF`. (Мелкая правка, не ломает light.)

---

## Подтверждения бренд-хрома

- **Сайдбар в dark:** темнеет до **`#091020`** (остаётся navy, НЕ нейтральный серый).
  Light — без изменений `#172747`. Hover `#0A1426`, active-bg `rgba(76,125,240,.16)`,
  **active-bar `#6E99FF`** (сейчас `#ffffff`), text `rgba(234,240,250,.66)`, text-active
  `#FFFFFF`, divider `rgba(255,255,255,.08)`. Источник — `--mg-sidebar-*`.
  → `_colors.scss`: `$sidebar-*` больше НЕ статические константы; каждая получает
  light-значение + `.app-dark`-оверрайд.
- **Шапка карточки сделки в dark:** **`#111E38`** (dark-адаптив; navy-хром темнеет, но
  остаётся navy — по духу пакета, `--mg-deal-header-bg`). Light — без изменений `#172747`.
  → `_colors.scss`: `$brand-header-bg` получает `.app-dark`-оверрайд `#111E38`. Тот же
  токен читают `EntityInfoHeader.vue` / `DealInfoHeader.vue` — оба адаптируются.
- **Статус-триады в dark** (мягкий тинт того же hue + светлый ink, без чёрно-белых флипов).
  Сейчас статусы в dark держат light-тинт — добавляем `.app-dark`-оверрайды `--app-status-*`
  (новое поведение, аудит §3.3):

| статус | bg (dark) | border (dark) | ink/text (dark) | solid (dark) |
|---|---|---|---|---|
| success | `rgba(123,196,140,.16)` | `rgba(123,196,140,.30)` | `#8FD3A0` | `#7BE07F` |
| danger | `rgba(232,120,104,.18)` | `rgba(232,120,104,.32)` | `#F4A293` | `#FF6B57` |
| warning | `rgba(214,164,110,.16)` | `rgba(214,164,110,.30)` | `#E6B98C` | `#FFB87E` |
| info | `rgba(120,176,224,.16)` | `rgba(120,176,224,.30)` | `#94C2EC` | `#7CCBFF` |

  Расширенные (funnel/chess) — `reserve` ink `#DFC57E`, `mdeal` ink `#DDAAC9`, `done` ink
  `#AEB9CE`, каждый на `rgba(...,.16)` (см. dark.css L74–84).

---

## Сводная таблица «токен → light → dark» (все затронутые роли)

| роль (репо-токен / где) | light | dark |
|---|---|---|
| surface-0 (page bg) | `#FFFFFF` | `#0A1426` |
| surface-50 | `#F9FAFB` | `#0F1F3D` |
| surface-100 (card/input/overlay) | `#F1F2F3` | `#111E38` |
| surface-200 (soft border) | `#E3E4E6` | `#172847` |
| surface-300 (border) | `#D5D6D8` | `#27395C` |
| surface-400 (strong border) | `#B8B9BB` | `#3A4F78` |
| surface-500 (muted ink) | `#9B9C9F` | `#647294` |
| surface-600 | `#7E7F82` | `#8593B0` |
| surface-700 | `#616263` | `#B4C2DA` |
| surface-800 (strong text) | `#444547` | `#C6D0E2` |
| surface-900 (primary text) | `#272829` | `#EAF0FA` |
| surface-950 (max text) | `#000000` | `#F5F8FE` |
| primary.color (links/outlined/controls) | `#172747` | `#4C7DF0` |
| primary hover | `#1F2F5A` | `#6E99FF` |
| primary active | `#263A6E` | `#3D6AD8` |
| **filled button bg** (preset pin) | `#172747` | `#4C7DF0` |
| filled button ink | `#FFFFFF` | `#EEF3FB` |
| text link | `#172747` | `#9DB8FF` |
| status success text | (light palette) | `#8FD3A0` |
| status danger text | (light palette) | `#F4A293` |
| status warning text | (light palette) | `#E6B98C` |
| status info text | (light palette) | `#94C2EC` |
| border default | `#E3E4E6` | `#27395C` |
| border strong | `#D5D6D8` | `#3A4F78` |
| border focus | `#172747` | `#4C7DF0` |
| **focus-ring** | `#172747` | `#6E99FF` |
| **sidebar bg** | `#172747` | `#091020` |
| sidebar hover | `#0E172B` | `#0A1426` |
| sidebar active-bar | `#FFFFFF` | `#6E99FF` |
| sidebar active-bg | `rgba(255,255,255,.08)` | `rgba(76,125,240,.16)` |
| **deal-header bg** | `#172747` | `#111E38` |
| shadow-card | (текущий light) | `0 4px 14px rgba(3,8,20,.55)` |
| shadow-md | (текущий light) | `0 8px 26px rgba(3,8,20,.60)` |
| shadow-lg | (текущий light) | `0 22px 52px rgba(2,6,16,.72)` |
| ECharts series[0] | `#2B4987` | `#5B8DEF` |
| ECharts axis line | `rgba(209,213,219,.4)` | `rgba(58,79,120,.55)` |
| ECharts tooltip bg | `rgba(23,39,71,.92)` | `rgba(23,40,71,.96)` |
| ECharts pie/line border | `#FFFFFF` | `#111E38` |

---

## Правила для frontend-specialist

1. **Что PINNED (не адаптируется по теме):** только 2 бренд-инварианта в LIGHT остаются
   хардкодом — sidebar `#172747` (light) и deal-header `#172747` (light). Всё остальное —
   через токены с `.app-dark`-оверрайдом.
2. **Что стало АДАПТИВНЫМ (было pinned — теперь меняется в dark):**
   - filled primary button — снять «navy в обеих темах»; dark → `#4C7DF0` (правка
     `preset.ts` button.colorScheme.**dark**.root.primary.*; light-блок НЕ трогать);
   - sidebar `$sidebar-*` и `$brand-header-bg` — из статических SCSS-констант в
     light-значение + `.app-dark`-оверрайд;
   - статус-триады — добавить `.app-dark` `--app-status-*` (новое поведение);
   - тени — добавить `.app-dark` значения.
3. **Инверсию шкалы НЕ ломать** (аудит §4.1 opt.1): в `colorScheme.dark.surface` меняем
   только hex, порядок инверсии (0=тёмный … 900=светлый) сохраняем. Все `{surface.X}`-ссылки
   в foundation.ts/preset.ts трогать не нужно — они дадут navy автоматически.
4. **Rebase `color-mix()` чип-тинтов** (TaskCard.vue, MyTasksTable.vue, 8 строк) с
   старого `#444547` на `var(--p-surface-100)` (= `#111E38`), НЕ хардкодить новый navy.
5. **Никаких литералов в `.vue`/`.scss`** мимо токенов — только `var(--p-*)` / `var(--app-*)`
   / `$scss-var`. Единственные допустимые raw-navy — 2 бренд-инварианта (п.1) и, где raw
   неизбежен, `/* stylelint-disable */` по образцу существующего `--mg-surface-hover`.
6. **Обе темы обязательны** — после каждой зоны `npm run type-check` + `npm run lint:ds`;
   финал — визуальный гейт qa-tester (computed-styles в обеих темах).
7. **Порядок реализации** (аудит §5): colors.ts/foundation.ts (шкала+акцент) →
   preset.ts (filled-button + input border) → appVariables.ts (статусы+тени) →
   _colors.scss (sidebar/deal-header) → echarts.ts → sweep чип-тинтов → lint + QA.

### Открытые вопросы
- **ОВ-1 (backend не нужен):** `surface-950` в dark задан как `#F5F8FE` (near-white для
  max-контраст-текста). Если где-то `{surface.950}` в dark используется как ФОН (не текст) —
  проверить точечно; в текущем коде такие случаи уже переопределены (ToggleButton/formField).
- **ОВ-2:** ECharts series 8–9 (`#6E99FF`, `#9DB8FF`) — оба синие; если появится ≥8-серийный
  график с легендой, различимость соседних синих проверить визуально (маловероятно — макс.
  наблюдаемое 6 серий). При проблеме — заменить series[8] на бирюзу `#3CBDA0`.
