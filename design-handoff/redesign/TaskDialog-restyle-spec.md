# ТЗ (рестайл): Единое окно задачи (TaskExpandedPanel, mode="dialog") — приведение к DS

**Зачем:** окно задачи, открывающееся по клику на карточку в канбане «Мои задачи» и по строке в
табличном виде, визуально не адаптировано под MSales 2.0 / navy-DS. Юзер: «не адаптирована под
нашу DS, причешите чтобы была красивая и аккуратная». Плоские серые блоки вместо полей, «мёртвые»
инпуты, статус нарисован кустарным блоком вместо `Tag`, лейблы теряются в dark. Приводим к канону.

**Где в коде:** `front/src/components/crm/activity/TaskExpandedPanel.vue` (единственный компонент —
`mode="dialog"` ветка; `mode="inline"` НЕ трогаем, там всё по DS уже). Хосты:
`front/src/pages/MyTasksPage/components/TasksKanbanBoard.vue` (канбан) и
`front/src/pages/MyTasksPage/index.vue` (список) — правка ТОЛЬКО в связи с двойным-Dialog (см. §0).

**Источник фич:** уже реализовано (Волна 6, HEAD `8ef51bf`+). **Функциональность НЕ меняем** — те же
поля, те же действия (Выполнить / Отмена / Удалить-3-шага), тот же гейт «нельзя выполнить без итога».
Это чисто визуальный + микро-UX рестайл.

**Эталоны, с которыми свериться:**
- `skill macroglobal-design` — компоненты `Dialog`, `Tag`, `forms` (лейблы-uppercase, поля-значения).
- `front/src/pages/DealPage/components/MoveDealDialog.vue` — эталон причёсанного диалога: `Dialog`
  с `:header`, `section-label` (uppercase-ish, `$surface-500`), футер `<template #footer>` с
  `Button severity="secondary" text` + primary `Button`, `.app-dark &` навигация текста.
- `front/src/components/ActivityFormDialog.vue` — эталон формы: `<label class="…__label">` +
  required `<span>*`, `InputText`/`Textarea` PrimeVue, `SelectButton`-подобный kind-row.
- `motivation-card/SPEC.md` — стилистика тихих значений / uppercase-лейблов / плотности.

---

## 0. КРИТИЧНО перед стартом — двойной `<Dialog>` (латентный баг, чинится в этом же заходе)

Сейчас **и хост, и компонент рендерят `<Dialog>`**:
- `TasksKanbanBoard.vue` (стр. 4-22) оборачивает `<TaskExpandedPanel mode="dialog">` в **свой**
  `<Dialog v-model:visible="taskDialogVisible" :show-header="false">`.
- `index.vue` (стр. 115-132) — то же самое (`listTaskDialogVisible`).
- НО `TaskExpandedPanel.vue` (стр. 86-95) в `mode="dialog"` **сам рендерит `<Dialog v-else>`**.

Итог: **Dialog внутри Dialog** — двойной backdrop, двойной teleport, конфликт `@hide`. Это уже баг
(лишний слой затемнения, дёрганье фокуса), и рестайл его усугубит. **Решение — выбрать ОДИН
владелец Dialog. Канон проекта (EntityCard-spec, `:show-close-icon` паттерн): Dialog живёт в ХОСТЕ,
компонент рисует только контент + свой крестик.**

**Что сделать:**
1. В `TaskExpandedPanel.vue` в `mode="dialog"` **убрать внешний `<Dialog v-else>`** — оставить сразу
   корневой `<div class="task-window">…</div>` (тот, что сейчас внутри Dialog). Удалить локальный
   `dialogVisible` ref + его `watch` (стр. 273-277) — видимостью теперь рулит хост через `v-if`.
2. Хосты (`TasksKanbanBoard.vue`, `index.vue`) уже держат свой `<Dialog … :show-header="false">` —
   оставляем как есть, компонент просто становится его контентом. Крестик рисует компонент
   (`.task-window__close-btn`) — поэтому `:show-header="false"` на хост-Dialog корректен.
3. Проверить: `@close` из компонента → хост ставит `*Visible = false`; `@hide` хост-Dialog →
   `closeTaskDialog`/`listTaskDialogVisible = false`. Дублирующего emit нет.

> Если по какой-то причине фронт решит оставить Dialog в компоненте (не рекомендую) — тогда убрать
> Dialog из ОБОИХ хостов. Но канон — Dialog в хосте. **Это единственная правка вне визуала; всё
> остальное ниже — стили + разметка внутри `.task-window`.**

---

## 1. Wireframe (целевой, dialog 540px)

```
┌──────────────────────────────────────────────────────────────────┐  ← Dialog 540px, radius-lg
│                                                                    │     padding 0 (внутр. .task-window даёт $space-5)
│  ┌────┐  Встреча с РОП                                       [×]   │  ← ШАПКА
│  │ 📅 │  ┌─ Встреча ─┐  ⏱ 16.06.2026                              │     плитка-иконка 36px (tint типа)
│  └────┘  └───────────┘                                            │     H3 title + kind-Tag + due-мета
│   tint                                                             │
│                                                                    │
│  ┌──────────────────────────────────────────────────────────┐    │  ← КОНТЕКСТ-СТРОКА (если есть)
│  │ 💼  Север Холдинг — ожидаем оплату                    ↗   │    │     surface-100 плашка, briefcase-иконка
│  └──────────────────────────────────────────────────────────┘    │     название + RouterLink external
│                                                                    │
│  ОТВЕТСТВЕННЫЙ              ТИП ЗАДАЧИ                              │  ← ГРИД ПОЛЕЙ 2×2
│  (А) Анна К.                ┌─ Встреча ─┐                          │     лейбл uppercase surface-500/600
│                            └───────────┘                          │     значение: EntityAvatar+имя / kind-Tag
│  СРОК ВЫПОЛНЕНИЯ            СТАТУС                                 │
│  16.06.2026                ┌─ В работе ─┐  ← Tag warn (amber)     │
│                            └───────────┘                          │
│                                                                    │
│  ИТОГ ВЫПОЛНЕНИЯ *                                                 │  ← (только если status ≠ done)
│  ┌──────────────────────────────────────────────────────────┐    │     Textarea PrimeVue, rows=3
│  │ Опишите результат выполнения…                             │    │
│  └──────────────────────────────────────────────────────────┘    │
│                                                                    │
├──────────────────────────────────────────────────────────────────┤  ← ФУТЕР, border-top surface-200
│  🗑 Удалить                          Отмена      ✓ Выполнить      │     danger-text слева · secondary+primary справа
└──────────────────────────────────────────────────────────────────┘
```

Скрин юзера показал: пустой «—» в «Ответственный», плоский серый блок «Задача» в «Тип задачи»,
тёмно-серый блок «В работе» с оранжевым текстом в «Статус». Всё это — заменяем на DS-примитивы ниже.

---

## 2. Композиция и правки по зонам

Разметка `.task-window` уже почти правильная (2×2 грид, шапка, контекст-строка, секции, футер).
**Меняем в основном СТИЛИ + два-три примитива.** Плотность: корневой `.task-window` gap `$space-4`,
padding `$space-5` (было `$space-4` — чуть просторнее по DS2).

### 2.1 Шапка (`.task-window__header`)
Добавить **плитку-иконку типа** слева (сейчас голая `pi` без подложки → выглядит бедно):
- Контейнер `.task-window__header-tile` 36×36, `border-radius: $radius-md`, фон = tint цвета типа
  (переиспользовать `taskKindChipStyle(kind, isDark).background`), иконка типа
  (`resolvedKindIcon`) цветом `taskKindChipStyle(...).color`, `font-size: $font-size-base`.
  Это визуально роднит окно с карточкой канбана (там рамка в цвет типа).
- Заголовок `.task-window__title` — оставить (H3, `$font-size-base` → **поднять до `$font-size-lg`**,
  `$font-weight-semibold`, clamp 2 строки). Цвет: light `$surface-800`, dark `var(--p-surface-800)`
  (это navy-инверсия = светлый текст, ОК).
- Мета-строка `.task-window__header-meta`: kind-Tag-чип (см. 2.4) + due-мета
  `.task-window__due-chip` (иконка `pi pi-clock` + дата; overdue → красный).
- `.task-window__close-btn` — крестик 28×28 остаётся; hover `surface-100`/`surface-200`. ОК.

### 2.2 Контекст-строка сущности (`.task-window__related`)
Уже плашка `surface-50`/`surface-100`. Полировка:
- Иконку `pi-briefcase`/`pi-user`/`pi-building` (из `relatedEntity.icon`) обернуть **не голой**, а
  привести к `$surface-500` (light) / `var(--p-surface-500)` (dark) — читаемее, чем текущий
  `surface-400`.
- `.task-window__related-label` — `$surface-700`/`var(--p-surface-800)`, ellipsis. ОК.
- Ссылка `.task-window__related-link` (external-link `↗`) — цвет `var(--p-primary-color)` (в light
  = navy, в dark navy-инверсия светлеет автоматически через токен). **Убрать хардкод `$primary-900`
  + ручной `.app-dark → --p-primary-300`** → заменить одним `var(--p-primary-color)` (токен сам
  адаптируется, закон dark-селекторов). Hover: `opacity .8`.
- **Опционально (микро-UX):** дать всей строке `cursor` на ссылке, а не только на иконке — чтобы
  клик по названию тоже вёл (RouterLink оборачивает label). Не обязателен; если делаем — label
  тоже внутрь RouterLink. По умолчанию оставляем как есть (ссылка = только иконка).

### 2.3 Грид полей (`.task-window__fields`, 2 колонки)
Лейблы `.task-window__field-label` — uppercase, `letter-spacing .04em`. **Проблема сейчас:** цвет
`$surface-400` в light СЛИШКОМ бледный (юзер жалуется на «неаккуратность»). **Поднять до
`$surface-600`** (light) / `var(--p-surface-500)` (dark) — читаемо в обеих темах, но всё ещё
вторично к значению. `font-weight: $font-weight-semibold`, `font-size: $font-size-2xs`.

- **Ответственный:** заменить самодельный `.task-window__avatar` (кружок `$primary-900`) на
  **`EntityAvatar`** (charter §2 — единый примитив аватара):
  `<EntityAvatar :name="responsibleName" :pixel-size="20" />` + короткое имя `responsibleShortName`
  рядом (`$surface-700`/`var(--p-surface-800)`). Пусто → `.task-window__field-empty` «—» цветом
  `$surface-400`/`var(--p-surface-500)` (не почти-невидимый `surface-300`).
- **Тип задачи:** kind-Tag-чип (2.4) — НЕ плоский серый блок. Сейчас в скрине это серый блок
  «Задача» — потому что для kind=`task` `taskKindChipStyle` возвращает navy-tint (task=#172747),
  и в dark он читается как серый. Оставляем chip-стиль (он корректен), но убеждаемся, что это
  именно chip с иконкой, а не отдельный «блок». Визуально станет аккуратнее за счёт единой высоты
  чипов и плитки в шапке.
- **Срок выполнения:** `.task-window__due-chip` — иконка `pi-clock` + дата. Overdue → `red-500`/
  `red-400`. Значение `$surface-700`/`var(--p-surface-800)` (сейчас `$surface-400` — бледно; поднять).
- **Статус:** **PrimeVue `<Tag :severity>` — это ГЛАВНАЯ замена плоского блока.** Уже используется
  (`<Tag :severity="statusSeverity" :value>`). Проверить маппинг:
  - `new` → `severity="info"` (blue-триада) → «Новая»
  - `in_progress` → `severity="warn"` (amber-триада) → «В работе»
  - `done` → `severity="success"` (green-триада) → «Выполнено»
  - иное → `severity="secondary"`.
  PrimeVue Tag сам берёт цвета из `--p-*-*` пресета (в dark инвертируются). **Если** дизайн хочет
  строго status-триады из `base.scss` (`--app-status-warning-*` и т.д.) вместо дефолтного Tag —
  это опция (ОВ-1); дефолтный `<Tag severity>` уже даёт amber/green/blue и проще. **Рекомендация:
  оставить `<Tag severity>`** (канон DS для статусов, charter). Плоский серо-оранжевый блок из
  скрина — это старый кастомный рендер; убедиться, что рендерится именно `Tag`, а не осталась
  кастомная плашка.

> Дисабленность: поля «Ответственный / Тип / Срок / Статус» тут **read-only-значения, не инпуты** —
> они и должны выглядеть как текст/чип/аватар, а НЕ как серые мёртвые `<Select>`/`<InputText>`.
> Это и есть требование юзера «дисабленные поля выглядят как текст, не как мёртвые инпуты». Мы НЕ
> превращаем их в disabled-инпуты — оставляем как значения-под-лейблом (паттерн MoveDealDialog
> «current stage»). Единственный редактируемый инпут — «Итог выполнения» (2.5).

### 2.4 kind-Tag-чип (`.task-window__type-chip`)
Оставляем inline-style из `taskKindChipStyle(kind, isDark)` (charter разрешает — branded
constants в TS). Полировка: единая высота с due-чипом (`padding: 2px 8px`, `$radius-sm`,
`$font-size-xs`, `$font-weight-semibold`, иконка `$font-size-2xs`). Курсор `default`.

### 2.5 Итог выполнения (`.task-window__result-textarea`)
- Секция видна только при `task.status !== 'done'` — оставить.
- Лейбл `.task-window__section-label` «ИТОГ ВЫПОЛНЕНИЯ» uppercase + required `*` красным
  (`.task-window__required-star`, `var(--p-red-500)`). Поднять цвет лейбла до `$surface-600`/
  `var(--p-surface-500)` (как поля).
- **Textarea:** заменить самодельный `<textarea class="task-window__result-textarea">` на
  **PrimeVue `<Textarea>`** (ActivityFormDialog-паттерн) — так поле сразу берёт DS-стили пресета
  (фон `surface-0`/`surface-950`, бордер `surface-300`, focus navy-ring), меньше ручного SCSS и
  консистентно с остальными формами. Props: `v-model="resultDraft"`, `:rows="3"`, `auto-resize`,
  `:placeholder`, `class="w-full"`, `:class="{ 'p-invalid': resultRequired }"`,
  `@input="onResultInput"`. Ref для авто-фокуса — через `:ref` на inputEl (у PrimeVue Textarea
  фокус-таргет — `$el`; фронт-специалист знает паттерн, либо оставить нативный `<textarea>` если
  ref-фокус на PrimeVue Textarea окажется хлопотным — **это ОВ-2, на усмотрение фронта; визуально
  оба варианта должны совпасть с DS**).
- Ошибка: `<small class="p-error">{{ t('tasks.window.fields.resultRequired') }}</small>` (паттерн
  ActivityFormDialog) вместо кастомного `.task-window__result-error`.

### 2.6 Текст задачи (`.task-window__section` / `.task-window__description`)
Read-only описание (если `taskBody`). Оставить: лейбл «ТЕКСТ ЗАДАЧИ» uppercase (тот же цвет-апгрейд),
текст `$surface-600`/`var(--p-surface-700)`, `word-break`. Порядок: описание ВЫШЕ «Итога» (как в
текущем коде). ОК.

### 2.7 Футер (`.task-window__footer`)
Канон: **danger-действие слева, secondary+primary справа**, `border-top` `surface-200`/`surface-700`.
Заменить три самодельные `<button>` на **PrimeVue `<Button>`** (как MoveDealDialog `#footer`):
- **Удалить** (слева): `<Button icon="pi pi-trash" :label="…delete" severity="danger" text>` —
  3-шаговое удаление сохраняем через `@click="handleDeleteClick"` + динамический класс масштаба
  (`--warn` scale + orange, `--danger` scale + solid red). Т.к. `severity="danger" text` уже даёт
  красный текст (канон destructive), базовое состояние = danger-text. На шаге 2 (`deleteCount===1`)
  — усилить (можно оставить кастомные модификаторы поверх, или через `severity="warn"`→`danger`
  outlined→solid прогрессию). **Сохранить визуальный эскалационный паттерн** (scale 1.0→усиление),
  реализация — на фронте; главное danger-семантика и tooltip `deleteTooltip`.
- **Отмена** (справа): `<Button :label="…cancel" severity="secondary" text @click="emit('close')">`.
- **Выполнить** (справа, primary): `<Button icon="pi pi-check" :label="…complete" :loading="completing"
  :disabled="completing" @click="onCompleteSubmit">` — **зелёная**. По DS complete=success →
  `severity="success"` (green-solid), НЕ дефолтный navy-primary (действие «выполнено» семантически
  зелёное — согласовано с текущим зелёным цветом кнопки). Loading через `:loading` (spinner
  встроен) — убрать ручной `pi-spin`.
- Кнопка «Выполнить» скрыта при `status === 'done'` — оставить `v-if`.

---

## 3. PrimeVue-компоненты (итоговый список)

| Компонент | Где | Ключевые props |
|---|---|---|
| `Dialog` | **в хосте** (kanban/list), не в компоненте | `:style="{width:'540px'}"` `:modal` `:draggable="false"` `:show-header="false"` |
| `Tag` | статус + (опц.) можно и для kind, но kind оставляем chip | `:severity="statusSeverity"` `:value="t('activity.statuses.'+status)"` |
| `Textarea` | итог выполнения | `v-model` `:rows="3"` `auto-resize` `:placeholder` `class="w-full"` `:class="{'p-invalid':resultRequired}"` |
| `Button` ×3 | футер (Удалить / Отмена / Выполнить) | Удалить: `severity="danger" text icon="pi pi-trash"` · Отмена: `severity="secondary" text` · Выполнить: `severity="success" icon="pi pi-check" :loading` |
| `EntityAvatar` | ответственный | `:name="responsibleName"` `:pixel-size="20"` (charter §2, не самодельный кружок) |
| `RouterLink` | external-link контекст-сущности | `:to="relatedEntity.to"` `@click="emit('close')"` |

Kind-чип и due-чип — остаются inline-разметкой (chip-паттерн), не PrimeVue-компоненты (так во всём
проекте: composer, OpenTasksList, карточка). Согласуется с charter.

---

## 4. Токены (обязательно — никаких литералов)

| Роль | Light | Dark |
|---|---|---|
| Фон Dialog/контента | пресет (`surface-0`) | пресет navy (`surface-950`) — авто |
| Плитка-иконка типа (фон) | `taskKindChipStyle(kind,false).background` | `taskKindChipStyle(kind,true).background` |
| Заголовок | `$surface-800` | `var(--p-surface-800)` (инверсия = светлый) |
| Лейблы полей (uppercase) | `$surface-600` | `var(--p-surface-500)` |
| Значения полей | `$surface-700` | `var(--p-surface-800)` |
| Empty «—» | `$surface-400` | `var(--p-surface-500)` |
| Контекст-плашка фон | `var(--p-surface-50)` | `var(--p-surface-100)` |
| External-link | `var(--p-primary-color)` | `var(--p-primary-color)` (авто-адаптация) |
| Статус-Tag | пресет по `severity` (info/warn/success) | пресет (инверсия автоматом) |
| Due overdue | `var(--p-red-500)` | `var(--p-red-400)` |
| Textarea фон/бордер/focus | пресет `surface-0`/`surface-300`/navy-ring | пресет navy |
| Футер border-top | `var(--p-surface-200)` | `var(--p-surface-700)` |
| Delete-btn danger | `severity="danger"` пресет | пресет |

**Закон dark-селекторов (charter §«Обе темы»):** каждый `.app-dark &` пишем **top-level** внутри
блока класса, НЕ вложенным в `:deep()` или в `&:hover` без нужды. Где возможно — заменяем пары
`light-литерал` + `.app-dark & { … }` на один theme-reactive токен (`var(--p-primary-color)`,
`var(--p-surface-*)`), как в external-link (2.2). Не плодить мёртвые `:deep(.app-dark)`.

**Запрещено:** новые hex/px мимо токенов, эмодзи в разметке (в wireframe 📅/💼 — только схема),
градиенты, цветные тени. Плитку типа красим только через `taskKindChipStyle` (уже branded-исключение).

---

## 5. States

- **default:** окно с заполненными полями (см. wireframe).
- **hover:** крестик — `surface-100`/`surface-200`; external-link — `opacity .8`; Button —
  штатные PrimeVue hover (success→green-600, danger-text→red-tint).
- **focus:** Textarea — navy focus-ring (пресет); Button — focus-ring пресета; крестик —
  видимый focus-ring (a11y).
- **disabled:** «Выполнить» при `completing` — `:disabled` + `:loading` (spinner, opacity .6).
  Read-only поля — это НЕ disabled-инпуты, а значения-под-лейблом (см. 2.3).
- **empty:** «Ответственный» без данных → «—» (`field-empty`); нет `relatedEntity` → блок
  контекст-строки скрыт (`v-if`); нет `taskBody` → секция описания скрыта.
- **loading:** авто-фокус textarea при открытии через «Выполнить» (`focusResult` / `focusResultField`)
  — сохранить. Отдельного skeleton нет (данные уже в руке из карточки/строки).
- **done:** `status === 'done'` → секция «Итог» и кнопка «Выполнить» скрыты; статус-Tag = success.
- **error:** complete/delete падает → Toast `errors.server_error` (существующая логика, не трогаем).
- **validate (гейт):** пустой итог + клик «Выполнить» → `resultRequired=true` (красный бордер
  `p-invalid` + `<small class="p-error">` + фокус в textarea). Существующая логика — сохранить.

---

## 6. Interactions (элемент → действие → результат)

| Элемент | Действие | Результат | Endpoint |
|---|---|---|---|
| Клик карточки в канбане | open | хост: `activeTask=…`, `taskDialogVisible=true` → Dialog с контентом | — |
| Клик строки в списке | open | хост: `listActiveTask=…`, `listTaskDialogVisible=true` | — |
| Крестик `×` / backdrop / Esc | close | `emit('close')` → хост `*Visible=false` | — |
| External-link (↗ контекст) | navigate | RouterLink → `/deals/:id` \| `/contacts/:id` \| `/companies/:id`; окно закрывается | — |
| Textarea «Итог» | input | `onResultInput`; если был `resultRequired` и текст непустой → снять флаг | — |
| «Выполнить» (пустой итог) | click | `resultRequired=true`, фокус в textarea, НЕ отправляет | — |
| «Выполнить» (итог заполнен) | click | `completing=true`; успех → `emit('completed', updated)` + Toast success; закрыть | `POST /api/activities/:id/complete` (`activityApi.completeActivity`) |
| «Удалить» (шаг 1) | click | `deleteCount=1`, кнопка усиливается (warn/scale), tooltip «Ещё раз…», авто-сброс 3с | — |
| «Удалить» (шаг 2) | click | `deleteCount=2`, danger-solid, tooltip «Последнее нажатие…» | — |
| «Удалить» (шаг 3) | click | `doDelete()` → `emit('deleted', id)` + close | `DELETE /api/activities/:id` (`activityApi.deleteActivity`) |
| «Отмена» | click | `emit('close')` | — |

**Ни один endpoint / emit / хендлер не меняется.** Меняются только теги/классы/props разметки.

---

## 7. i18n

Все ключи уже существуют (`ru.json` L3955-3977) — **новых не нужно**:
`tasks.window.title`, `tasks.window.fields.{responsible,kind,dueAt,status,description,result,
resultPlaceholder,resultRequired}`, `tasks.window.actions.{complete,cancel,delete}`,
`tasks.window.relatedEntity.{deal,contact,company}`, статусы `activity.statuses.{new,in_progress,done}`,
виды `activity.kinds.*`. EN-задел — проверить зеркальность (если EN отсутствует по этим ключам —
добить `en.json`, но это вне визуального скоупа).

---

## 8. Референс-экраны
- Причёсанный диалог-эталон: `front/src/pages/DealPage/components/MoveDealDialog.vue` (footer
  `<template #footer>` + `Button` severity, section-label, dark-навигация текста).
- Форма-эталон: `front/src/components/ActivityFormDialog.vue` (`<label>`+`*`, `Textarea`,
  `<small class="p-error">`, `p-invalid`).
- Уже-по-DS ветка того же файла: `mode="inline"` в `TaskExpandedPanel.vue` — плотность/чипы/токены
  оттуда как ориентир (её НЕ трогаем).
- Статус-триады: `front/src/theme/scss/foundation/_colors.scss` L99-140 (`--app-status-*`) +
  `base.scss` (декларации light+dark) — на случай, если ОВ-1 решат в пользу кастом-триад.

---

## 9. Открытые вопросы

- **ОВ-1 (статус):** дефолтный `<Tag :severity>` (info/warn/success из пресета) — **рекомендую**
  (проще, канон). Альтернатива — кастом-плашка на `--app-status-warning-*`/`-success-*`/`-info-*`
  триадах из `base.scss` (даёт точную amber-триаду «В работе» с bg+border+text). Нужен выбор PO/DS.
  По умолчанию идём с `<Tag severity>`.
- **ОВ-2 (textarea):** PrimeVue `<Textarea>` (консистентность форм, меньше SCSS) vs оставить
  нативный `<textarea>` (проще авто-фокус через ref). Рекомендую `<Textarea>`; финальное решение —
  фронт по трудоёмкости ref-фокуса. Визуал в обеих ветках = DS-инпут.
- **ОВ-3 (двойной Dialog):** канон — Dialog в хосте, компонент = контент (§0). Подтвердить, что
  фронт снимает `<Dialog>` из `TaskExpandedPanel` (а не из хостов). Это единственная не-визуальная
  правка; без неё рестайл поедет поверх двойного backdrop.
- **ОВ-4 (кнопка «Выполнить»):** зелёная `severity="success"` (семантика «выполнено», совпадает с
  текущим зелёным) vs navy-primary. Рекомендую **success/зелёная** — сохраняет текущий смысл и цвет.

---

## 10. Acceptance (для qa-tester — обе темы, оба входа)

**Входы:** (A) канбан «Мои задачи» → клик по карточке; (B) табличный вид → клик по строке. Прогнать
ВСЁ ниже в **обеих темах** (light + navy-dark), для обоих входов.

1. **Один backdrop.** Открытие окна даёт ровно ОДИН слой затемнения (не двойной). DOM: один
   `.p-dialog-mask`. (регресс двойного-Dialog устранён).
2. **Шапка:** плитка-иконка типа окрашена в tint цвета типа (встреча=зелёный tint, звонок=синий,
   КП/презентация=янтарный, задача=navy-tint); заголовок читаем в обеих темах; kind-чип + due-мета
   в мета-строке; крестик закрывает.
3. **Контекст-строка:** плашка `surface-50`/`surface-100`; иконка briefcase/user/building; external-
   link ведёт на карточку сделки/контакта/компании и закрывает окно; цвет ссылки — navy в light,
   светло-navy в dark (не чёрный, не невидимый).
4. **Грид полей:** лейблы UPPERCASE читаемы в обеих темах (НЕ бледно-серые как на исходном скрине);
   «Ответственный» — `EntityAvatar` + имя (или «—» если пусто); «Тип задачи» — kind-чип (не плоский
   серый блок); «Срок» — иконка+дата (overdue красный); «Статус» — **PrimeVue Tag**: «Новая»=info/
   blue, «В работе»=warn/amber, «Выполнено»=success/green (не кустарный серо-оранжевый блок).
5. **Итог выполнения:** секция видна только если статус ≠ «Выполнено»; лейбл + красная `*`; Textarea
   — DS-инпут (не плоский блок), фон/бордер/focus-ring по теме; авто-фокус при открытии через
   «Выполнить».
6. **Гейт:** «Выполнить» с пустым итогом → красный `p-invalid` + `<small class="p-error">` +
   фокус в textarea, запрос НЕ ушёл. С заполненным → `POST …/complete`, Toast success, окно закрыто,
   карточка/строка ушла из открытых.
7. **Удаление 3-шаговое:** клик 1 → усиление (warn/scale) + tooltip; клик 2 → danger-solid + tooltip;
   клик 3 → `DELETE`, окно закрыто, задача исчезла. Авто-сброс счётчика через 3с.
8. **Футер:** «Удалить» слева (danger-text), «Отмена»+«Выполнить» справа; «Выполнить» зелёная с
   `pi-check`, при отправке — `:loading` spinner + disabled; при статусе «Выполнено» кнопки
   «Выполнить» нет.
9. **Read-only ≠ мёртвый инпут:** поля Ответственный/Тип/Срок/Статус выглядят как значения-под-
   лейблом (текст/аватар/чип/Tag), а НЕ как серые задизейбленные `<Select>`/`<input>`.
10. **Dark-контраст:** ни одного невидимого элемента (текст на фоне того же цвета), ни одного
    мёртвого `.app-dark`-селектора (визуальная проверка + `npm run lint:ds` зелёный).
11. **Регресс:** `mode="inline"` (OpenTasksList в карточках контакта/компании/сделки) НЕ затронут —
    открыть карточку контакта, развернуть открытую задачу — выглядит как раньше.
```
