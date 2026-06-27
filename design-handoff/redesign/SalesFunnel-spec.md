# Раздел «Сделки» (воронка продаж) — ТЗ для реализации (Claude Code)

Пересборка страницы воронки продаж: канбан-доска по этапам + табличный вид. Эталон —
`redesign/sales-funnel.html`. Документ описывает разметку, размеры, токены и поведение так,
чтобы фронтендер (или агент `frontend-specialist`) собрал страницу пиксель-в-пиксель.

- **Стек:** Vue 3 + PrimeVue 4 (в проекте). Карточки — `DealsKanbanCard.vue`, колонки —
  `DealsKanbanColumn.vue`, таблица — `DealsListView.vue`, тулбар — `DealsToolbar.vue`,
  страница — `pages/DealsPage/index.vue`. Фильтр — `DealsFilterOverlay.vue`.
- **Шрифт:** `--mg-font-sans` (SF UI Display → web-фолбэк Inter). Иконки — **PrimeIcons 7**.
- **Темы:** светлая и тёмная (класс `.surface` / `.surface.dark`).

---

## 0. Токены поверхности (light / dark)

Объявляются на корневом `.surface`. Никаких хардкод-цветов в разметке.

| Переменная | Light | Dark |
|---|---|---|
| `--c-page` | `#F1F2F3` | `#272829` |
| `--c-board` (фон доски) | `#ECEDEF` | `#1f2021` |
| `--c-card` | `#FFFFFF` | `#3a3b3d` |
| `--c-border` | `#E3E4E6` | `#54595E` |
| `--c-border2` (поля/кнопки) | `#D5D6D8` | `#54595E` |
| `--c-text` | `#272829` | `#F9FAFB` |
| `--c-text2` | `#616263` | `#E3E4E6` |
| `--c-muted` | `#7E7F82` | `#9B9C9F` |
| `--c-hover` | `#F9FAFB` | `#444547` |

Бренд/радиусы/тени — из дизайн-системы: `--mg-primary-900` (акцент, по умолч. `#172747`),
`--mg-primary-800` (hover), `--mg-primary-100` (светлая плашка), `--mg-radius-sm/md/lg`,
`--mg-shadow-sm/md/lg`. Палитра этапов и статусов — `--mg-green-*`, `--mg-red-*`,
`--mg-orange-*`, `--mg-blue-*`.

### Цвета этапов воронки (kanban)
Закреплены в данных этапа (`stage.color`). Эталонный набор:

| Этап | Цвет |
|---|---|
| Новая | `#7F77DD` |
| Квалификация | `#378ADD` |
| Презентация | `#1D9E75` |
| Переговоры | `#EF9F27` |
| Договор | `#D4537E` |
| Успешно (won) | `#36a04b` |
| Холодные (скрытый) | `#9B9C9F` |
| Закрыта/проиграно (скрытый) | `#7E7F82` |

---

## 1. Каркас страницы

```
.surface (height:100vh; flex column; background: var(--c-board))
├─ TopBar (шапка + тулбар в ОДНОЙ строке)          §2
├─ FilterPanel (раскрывается под шапкой)            §3
├─ MoreMenu / PipelineMenu (поповеры)               §2.3 / §2.4
└─ Board (kanban)  |  ListView (таблица)            §4 / §5
```

Состояние: `view ∈ {'kanban','list'}` (по умолч. `kanban`), `filterOpen`, `pipe`
(выбранная воронка), `shownHidden` (массив id включённых скрытых статусов).

---

## 2. TopBar — единая верхняя строка

Flex-ряд, `align-items:center; gap:12px; padding:14px 20px; border-bottom:1px --c-border;
background:--c-card; flex-wrap:wrap`. Слева направо:

1. **Иконка раздела** — плитка 38×38, `background:--mg-primary-100; radius:--mg-radius-md`,
   внутри `pi-briefcase` 17px цвета `--mg-primary-900`.
2. **Заголовок-блок:** `<h1>` «Сделки» (19px/600/`--c-text`) + подпись
   «MACRO Global · {N} сделок · ≈ 114 млн ₸» (12px/`--c-muted`).
3. **Спейсер** `flex:1`.
4. **Кнопка «Поиск и фильтр»** (`height:38`, padding `0 14px`, radius `--mg-radius-md`,
   13px/600). Неактивна — border `--c-border2`, текст `--c-text2`; активна — заливка
   `--mg-primary-100`, текст и border `--mg-primary-900`. **Бейдж счётчика** активных
   фильтров — абсолютный кружок справа-сверху (`top:-7;right:-7; min-w:18;h:18;radius:9;
   background:--mg-orange-700; color:#fff; 10px/700`).
5. **Переключатель воронки** (§2.4) — `height:38`, border `--c-border2`, иконка `pi-sitemap`
   13px + название воронки + `pi-chevron-down`.
6. **Сегмент-переключатель вида** — контейнер `inline-flex; gap:2px; background:--mg-gray-100;
   radius:7px; padding:3px`; внутри две icon-кнопки **compact (height:31)**: `pi-th-large`
   (kanban), `pi-list` (список). Активная — заливка `--mg-primary-100`, цвет `--mg-primary-900`.
7. **Кнопка «⋮»** (`pi-ellipsis-v`) — icon-only, **outlined, compact (height:31)** — на
   ~18% ниже остальных кнопок. Открывает MoreMenu.
8. **Кнопка «Создать сделку»** — primary (`height:38`, заливка `--mg-primary-900`, hover
   `--mg-primary-800`, текст #fff), иконка `pi-plus`.

> **Высоты:** все кнопки строки — **38px**, кроме «⋮» и сегмента вида — **31px**
> (`box-sizing:border-box`). Группа справа переносится целиком на узких экранах.

### 2.3 MoreMenu (по «⋮»)
Дропдаун (`background:--c-card; border:1px --c-border; radius:--mg-radius-md;
shadow:--mg-shadow-lg; padding:5px; min-width:210`), пункты 13px/500 с иконкой 15px слева,
hover `--c-hover`, разделители — линия `--c-border`. **Порядок строго:**
`Массовые действия` (pi-check-square) · `Поиск дублей` (pi-clone) · — · `Импорт` (pi-download)
· `Экспорт` (pi-upload). **Сортировки в меню нет** — сортировка делается стрелками на
заголовках колонок списка.

### 2.4 PipelineMenu (по переключателю воронки)
Дропдаун `min-width:230`, позиция `top:42;right:0`. Пункты: иконка + 2 строки (название
13px/600 + подпись 11px/`--c-muted`), активный залит `--mg-primary-100` с `pi-check` справа.
Набор: **Продажи** (воронка продаж, pi-sitemap) · **Онбординг** (внедрение и запуск, pi-flag)
· **Партнёрская** (реферальные сделки, pi-users) · — · `Настроить воронки` (pi-cog).

---

## 3. FilterPanel (раскрывается под TopBar)

`border-bottom:1px --c-border; background:--c-hover; padding:16px 20px`.

- **Строка поиска:** `position:relative; max-width:460`, иконка `pi-search` (13px,
  абс. left:12, центр) + инпут `height:38; padding:0 12px 0 34px; border:1px --c-border2;
  radius:--mg-radius-md; background:--c-card`. Плейсхолдер «Поиск по сделкам, компаниям,
  контактам…».
- **Пресеты:** лейбл «ПРЕСЕТЫ» (11px/700 uppercase, `--c-muted`) + pill-чипы (`radius:999px;
  padding:6px 13px; 13px/600`). **Мультивыбор с подсветкой:** активный залит цветом severity
  и показывает `pi-check` слева; неактивный — border `--c-border2`, текст `--c-text2`.
  Набор: `Открытые`(brand, активен по умолч.) · `Только мои`(brand) · `Успешно завершённые`(success)
  · `Нереализованные`(danger) · `Без задач`(warning) · `С просроченными`(danger). Справа —
  `pi-times` (закрыть панель).
- **Сетка полей** (`grid; columns: repeat(4,1fr); gap:14px 18px`). Поле = лейбл (12px/500
  `--c-text2`) + контрол (`height:36; border:1px --c-border2; radius:--mg-radius-md;
  background:--c-card`, шеврон `pi-chevron-down` справа):
  `Ответственный`(Все сотрудники) · `Этап`(Любой) · `Продукт`(Любой) · `Регион / страна`(Любой)
  · `Город`(Любой) · `Бюджет` (два инпута «от»/«до» по 50%) · `Теги`(Выбрать…)
  · `Период создания`(За всё время). **Поля «Компания» нет** (убрано намеренно).
- **Скрытые статусы** — раскрывающийся блок, **`max-width:50%`** (≈ две колонки фильтров),
  не на всю ширину: `border:1px --c-border; radius:--mg-radius-md; background:--c-card`.
  Заголовок-кнопка (`pi-eye-slash` + «Скрытые статусы» 13px/600 + бейдж «{N} вкл.» +
  шеврон). Раскрыт — пояснение (11px/`--c-muted`: «По умолчанию эти статусы скрыты из
  воронки. Включите тригер, чтобы отобразить колонку.») + строки по каждому скрытому
  статусу: цветная точка 9px + название + счётчик сделок + **тумблер** (`34×19; radius:999;
  off:--c-border2 / on:--mg-primary-900`, кружок 15px). Внизу — `Настроить, какие статусы
  скрывать` (pi-cog). Включённый тумблер добавляет колонку этого статуса в канбан.
- **Низ:** справа `Сбросить` (text) + `Применить` (primary, `pi-check`).

---

## 4. Канбан-доска (view = kanban)

Контейнер: `flex; align-items:flex-start; gap:12px; padding:16px 20px; overflow-x:auto;
overflow-y:auto`. **`align-items:flex-start`** обязательно — колонки обнимают контент по
высоте, не растягиваются. Порядок: видимые этапы → включённые скрытые статусы.

### 4.1 Колонка (`DealsKanbanColumn`)
Белая карточка-подложка: `width:284; flex-shrink:0; background:--c-card; border:1px
--c-border; radius:--mg-radius-lg; shadow:--mg-shadow-sm; overflow:hidden; align-self:flex-start;
max-height:100%`.

- **Шапка** (спокойная, НЕ яркая заливка): `border-top:3px solid {stage.color}`;
  `background: color-mix(in srgb, {stage.color} 13%, --c-card)`; `border-bottom:1px --c-border`;
  `padding:11px 13px 9px`.
  - Верхний ряд — `grid: 34px 1fr 34px; align-items:center`:
    - **слева** — бейдж-счётчик карточек (`12px/700 --c-text2; background:--c-card;
      border:1px --c-border; radius:999px; padding:1px 0; min-width:26; justify-self:start`).
    - **по центру** — НАЗВАНИЕ этапа: `14px/700; letter-spacing:0.04em; text-transform:uppercase;
      color:--c-text`. **Без точки и без кнопки «+» в шапке** (создание — через «Создать сделку»).
    - справа — пустой спейсер (для симметрии центрирования).
  - **Сумма по колонке** (разделитель между названием и списком): `12px/--c-muted;
    text-align:center; margin-top:6px` (формат «≈ 12 400 000 ₸»). Нижняя граница шапки = линия-разделитель.
- **Список карточек:** `padding:10px; gap:8px; background:--c-card; overflow-y:auto`. **Все
  карточки статуса показаны по умолчанию** — никакой кнопки «Ещё N» нет.

### 4.2 Карточка сделки (`DealsKanbanCard`)
`background:--c-card; border:1px --c-border; radius:--mg-radius-md; shadow:--mg-shadow-sm;
overflow:hidden; cursor:pointer`. Цветовой сигнал здоровья — inset-полоса слева:
- `no-task` → `box-shadow: inset 4px 0 0 --mg-warning`;
- `overdue` → `box-shadow: inset 4px 0 0 --mg-danger` + `border-color:--mg-danger`.

Тело (`padding:11px 12px`):
- **Заголовок** — 13px/600/`--c-text`, в одну строку с эллипсисом.
- **Сумма + продукт** (`gap:8`): сумма 12px/700/`--mg-primary-900`; чип продукта (если есть) —
  `background:--c-hover; radius:--mg-radius-sm; padding:1px 7px; 11px/--c-muted`, иконка
  `pi-box` 10px + название (эллипсис).
- **Низ** (`gap:8`): аватар-кружок 20px (`--mg-primary-900`, инициал) + имя (12px/`--c-muted`,
  «Имя Ф.»); справа — «дней в стадии» с `pi-clock` 11px, цвет по возрасту: `≥14` → `--mg-red-600`,
  `≥7` → `--mg-orange-700`, иначе `--c-muted`. **Кнопки быстрой задачи «+» НЕТ** (задача
  ставится внутри карточки сделки).
- **Health-полоса** (низ карточки, `padding:7px 12px; border-top:1px --c-border; min-height:28`):
  - `ok` → иконка типа задачи (`pi-phone`/`pi-calendar`/`pi-check-square`…) + дата задачи,
    фон `--c-hover`, текст `--c-text2`;
  - `no-task` → «Нет задачи» (`--mg-orange-900` на `--mg-orange-50`) + ссылка `+ Задача`
    (`--mg-primary-900`);
  - `overdue` → иконка danger + «Просрочено: {когда}» (`--mg-red-700` на `--mg-red-50`).

Иконки типов задач: `call→pi-phone, meeting→pi-calendar, task→pi-check-square,
note→pi-file-edit, follow_up→pi-arrow-right-arrow-left`.

---

## 5. Табличный вид (view = list, `DealsListView`)

Контейнер `flex:1; overflow:auto; padding:16px 20px`.

### 5.1 KPI-чипы (вторая строка под шапкой, на белой подложке)
Карточка-подложка: `background:--c-card; border:1px --c-border; radius:--mg-radius-lg;
shadow:--mg-shadow-sm; padding:12px 14px; margin-bottom:14px`. Внутри — flex-wrap чипы.
Чип = pill (`radius:999px; padding:6px 13px; 13px`), иконка 12px + «label:» (opacity .8) +
**значение** (700). Состав и цвета:
- `В работе: {N} компаний` (pi-briefcase, brand: `--mg-primary-100`/`--mg-primary-900`) —
  N = уникальные компании по сделкам не в статусе «Успешно».
- `Категории L/M/S: {nL} / {nM} / {nS}` (pi-tags, info: `--mg-blue-100`/`--mg-blue-700`).
- `Успешных: {N}` (pi-check-circle, success: `--mg-green-100`/`--mg-green-900`) — всего
  выигранных сделок.
- `Без задачи: {N}` (pi-clock, warning: `--mg-orange-50`/`--mg-orange-900`).
- `Просрочено: {N}` (pi-exclamation-circle, danger: `--mg-red-50`/`--mg-red-700`).

### 5.2 Таблица
Карточка `background:--c-card; border:1px --c-border; radius:--mg-radius-lg;
shadow:--mg-shadow-sm; overflow:hidden`. `table{width:100%; border-collapse:collapse}`.

- **`<th>`:** `padding:11px 14px; 12px/600; color:--c-text2; border-bottom:1px --c-border;
  white-space:nowrap; background:--c-card`. Каждый заголовок со **значком сортировки**
  `pi-sort-alt` (11px, `--c-muted`) — на ВСЕХ колонках.
- **`<td>`:** `padding:12px 14px; 13px; border-bottom:1px --c-border; vertical-align:middle`;
  `muted` → `--c-muted`. Ряды зебра: чётный `--c-hover`, нечётный `--c-card`; `cursor:pointer`.

**Порядок колонок строго:**

| # | Колонка | Рендер |
|---|---|---|
| 1 | Название | ссылка `--mg-primary-900`/500 |
| 2 | Страна | текст `muted` |
| 3 | Сумма | right, 700 |
| 4 | Статус | чип этапа: `background: color-mix(in srgb, {color} 22%, --c-card)`; точка 7px {color} + «{N}. {Этап}» (12px/600/`--c-text`) |
| 5 | В статусе | `muted`, «{N} дней» (склонение: день/дня/дней) |
| 6 | Посл. контакт | дата с цветом свежести (см. 5.3) |
| 7 | Задача | `overdue`→пилюля «Просрочено» (red); `no-task`→пилюля «Нет задачи» (orange); `ok`→иконка типа + дата (12px/`--c-text2`) |
| 8 | Ответственный | аватар 22px + полное имя |

### 5.3 Цвет «Посл. контакт» (freshness)
`g` свежо → `--mg-green-700`; `a` тепло → `--mg-orange-700`; `r` давно → `--mg-red-600`;
`n` нет → `--c-muted`. Логика: `overdue` или `days≥21` → r; `days≥7` → a; иначе g.
Текст: `days≤1` → «Сегодня», иначе «{N} дн назад».

---

## 6. Чек-лист соответствия
- [ ] Шапка и тулбар — в одной верхней строке; иконка-портфель + «Сделки» + подпись слева,
      контролы справа.
- [ ] Все кнопки строки 38px, «⋮» (вертикальные) и сегмент вида — 31px.
- [ ] Канбан: белые колонки-подложки, спокойная шапка (тинт 13% + полоска цвета 3px сверху),
      название этапа по центру КАПСОМ, счётчик слева, сумма-разделитель по центру.
- [ ] Колонки обнимают контент по высоте; все карточки показаны (нет «Ещё N»); нет «+» на
      карточках и в шапке колонки.
- [ ] Health-сигнал: inset-полоса (warning/danger) + нижняя полоса состояния.
- [ ] Фильтры: пресеты мультивыбором с подсветкой; «Компании» в полях нет; «Скрытые статусы»
      — блок в пол-ширины с тумблерами; включение добавляет колонку.
- [ ] MoreMenu: Массовые действия → Поиск дублей → — → Импорт → Экспорт (без сортировки).
- [ ] Список: KPI-чипы на белой подложке; колонки в порядке §5.2; сортировка на всех th.
- [ ] Светлая и тёмная темы корректны (только токены `--c-*` / `--mg-*`).
