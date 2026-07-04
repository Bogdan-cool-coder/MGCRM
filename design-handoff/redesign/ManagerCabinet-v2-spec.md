# ТЗ: Кабинет менеджера v2 (DS2-редизайн)

**Версия:** 2.0 · **Дата:** 2026-07-04
**Автор:** designer
**Статус:** **РЕАЛИЗОВАНО 2026-07-04, коммит `9f8c4ee`** (Э10, reviewer PASS). As-built отклонения: акцент результатов — `orange-400` вместо amber (repo-токен ближе к бренду); `ccy-Message` (валютная плашка) убран в мотив-табе — валютная разбивка остаётся в самой строке компонента. Токены — repo-переменные (не литералы мокапа); MoodHead-фразы = новый i18n RU+EN.
**Где в коде:** `front/src/pages/ManagerCabinetPage/`
**Эталон-мокап:** `MACRO-Design-System 2/redesign/manager-cabinet.html` (DS2; агент копирует в репо параллельно)
**Смежная спека (не ломать семантику):** `design-handoff/redesign/motivation-card/SPEC.md` (as-built v1.1)

> **Зачем.** Облегчить кабинет: убрать «тяжёлые» 4 KPI-карты и DataTable, собрать результаты в одну hero-панель (кольцо МК% + настроенческий смайлик + компактные SecStat-строки), команду показать градиентными барами, ленту — pill-фильтрами с числовой пагинацией. В мотив-карте — компактная строка-шапка, hero-ЗП, свёрнутый по умолчанию аккордеон компонентов, «тихий» цвет процентов. Данные те же — **новых эндпоинтов НЕ требуется** (подтверждено аудитом), это визуальный полиш + перекомпоновка.

---

## 0. Критично: токены и тёмная тема (читать первым)

Литералы мокапа (`#12213E` / `#243358` / `#E8EDF6` / `--c-card` / `--mg-*`) — **reference-only**. Во `.vue`/`.scss` пишем **repo-токены**, никогда hex/`--c-*`/`--mg-*`.

| Роль в мокапе (`--c-*` / `--mg-*`) | repo-токен (что писать) |
|---|---|
| `--c-page` (фон полотна) | `$surface-ground` / `var(--p-surface-50)` |
| `--c-card` (карточка, панель) | `$surface-card` |
| `--c-hover` (трек бара, подложка) | `var(--p-surface-100)` / `$surface-100` |
| `--c-border` | `$surface-200` (или `var(--p-surface-200)` в `:deep`) |
| `--c-border2` (input-бордер) | `var(--p-surface-300)` |
| `--c-text` (основной текст) | `$surface-900` |
| `--c-text2` (вторичный) | `$surface-700` |
| `--c-muted` (метаданные, лейблы) | `$surface-600` |
| `--mg-primary-900` / фокус-акцент | `var(--p-primary-color)` |
| `--mg-primary-100` (плитка, аватар-фон) | `var(--p-primary-100)` (в dark — `var(--p-primary-900)` фон + `var(--p-primary-100)` текст) |
| `--mg-green-700/900` (успех) | `var(--p-green-500)` / статус-токен `--mg-status-success-*` |
| `--mg-orange-700/900` (варн) | `var(--p-orange-500)` / `--mg-status-warning-*` |
| `--mg-red-600/700` (данж) | `var(--p-red-500)` / `--mg-status-danger-*` |
| `--mg-blue-700` (звонок) | `var(--p-blue-500)` |
| `--mg-stage-amber` (середина градиента) | `var(--p-amber-500)` / `var(--p-orange-400)` |

**Primary-акценты в dark — только `var(--p-primary-color)`** (в dark сам светлеет `#172747 → #4C7DF0`). НИКОГДА не хардкодить navy в dark: на navy-фоне пропадёт. Это касается: кольца МК% (заполнение при score≥100 берёт green, при 80–99 — `var(--p-primary-color)`), hero-ЗП 38px, активной pill/таба, номера страницы-акцента, ссылок.

**Закон мёртвых dark-селекторов (charter §4 — 4 запрещённых варианта):** внутри `:deep()` любой `.app-dark`-селектор мёртв. Разрешено ровно 2 пути: (1) theme-reactive repo-токены (`$surface-*`/`var(--p-*)`/`var(--p-primary-color)` — одно правило обе темы, dark-ветка не нужна); (2) `.app-dark &` на **собственном** scoped-элементе (не внутри `:deep`). Инвертированная navy-шкала: в dark `surface-100=#111E38`, `surface-200=#172847`, `surface-900=#EAF0FA`. Для текста в dark — `$surface-700`/`$surface-900` (светлые), для raised-подложки — `var(--p-surface-100)`/`var(--p-surface-200)`. НЕ наоборот.

**SVG в компонентах — исключение под мокап.** Charter §4 запрещает SVG-файлы, но кольцо прогресса (`Ring`) и голова-смайлик (`MoodHead`) в мокапе — inline-`<svg>` из примитивов (circle/path), это единственный способ отрисовать кольцо и мимику без сторонней либы. Разрешено как inline-SVG в шаблоне SFC (не файл-ассет). Цвета `stroke`/`fill` — через repo-токены (`var(--p-*)`), не hex.

---

## 1. Структура файлов (было → стало)

Страница остаётся `ManagerCabinetPage/index.vue`. Меняем композицию Overview и полишим Motivation.

| Файл | Судьба | Комментарий |
|---|---|---|
| `index.vue` | **правим** | новая одно-рядная шапка (см. §2); контент Overview = `ResultsHero` + `ActivityFeed` |
| `components/CabinetHeader.vue` | **удаляем** | профиль-блок уезжает в объединённую шапку (имя/отдел) + hero-панель; отдельная карточка не нужна |
| `components/MonthStepper.vue` | **удаляем** | 7-кнопочный степпер заменён dropdown месяца в шапке (см. §2.4) |
| `components/KpiCards.vue` | **удаляем** | 4 KPI-карты заменены кольцом МК% + SecStat-строками внутри `ResultsHero` |
| `components/TeamComparisonTable.vue` | **удаляем** | DataTable заменён `TeamBars` (градиентные бары) внутри `ResultsHero` |
| `components/ActivityFeedList.vue` | **переписываем** → `ActivityFeed.vue` | pill-фильтры + «Первичная»-бейдж + числовая пагинация вместо DataTable+ToggleButton+Paginator |
| `components/ResultsHero.vue` | **новый** | 2-колоночная панель: слева кольцо+смайлик+SecStat, справа `TeamBars` |
| `components/ScoreRing.vue` | **новый** | inline-SVG кольцо прогресса (переиспользуемо) |
| `components/MoodHead.vue` | **новый** | inline-SVG смайлик + mood-слово + ротация фраз |
| `components/SecStatRow.vue` | **новый** | строка «лейбл — значение — sub» с опц. info-tooltip |
| `components/TeamBars.vue` | **новый** | список сотрудников с градиент-барами |
| `components/CabinetToolbar.vue` | **новый** | единая шапка (icon+title+user-picker+segmented+month) |
| `composables/useManagerCabinetPage.ts` | **правим точечно** | feed-пагинация переводится на числовую (page-стейт уже есть); всё остальное — как есть |

Motivation-таб: полиш существующих `Mk*`-компонентов (§3), новых файлов там минимум (см. §3.1).

---

## 2. OVERVIEW — раскладка

### 2.0 Wireframe (light, 1440px)

```
┌──────────────────────────────────────────────────────────────────────────────┐
│ TOOLBAR (одна строка, $surface-card, border-bottom $surface-200, pad 14/22)    │
│ [◱ icon] Кабинет менеджера          [👤 Мой кабинет ▾]  [Кабинет|Мотив.]  [Июль ▾]│
│          Богдан Меркулов · Отдел продаж   (admin only)   segmented           │
├──────────────────────────────────────────────────────────────────────────────┤
│ CONTENT (pad 20/22, фон $surface-ground, вертикальный gap $space-4)            │
│                                                                                │
│ ┌── ResultsHero (card) ─────────────────────────────────────────────────────┐ │
│ │ РЕЗУЛЬТАТЫ · Июль 2026        │ КОМАНДА              среднее 84%           │ │
│ │                              │                                            │ │
│ │  ╭───╮   [☺] выше плана       │ Богдан Меркулов  ▓▓▓▓▓▓▓░  108%           │ │
│ │  │108%│   «План побит…»       │ Игорь Волков     ▓▓▓▓▓▓░░   96%           │ │
│ │  ╰───╯                        │ Алина Сергеева   ▓▓▓▓▓░░░   88%           │ │
│ │   МК%                         │ Дамир Ахметов    ▓▓▓░░░░░   72%           │ │
│ │  ───────────────────────      │ Ольга Ким        ▓▓░░░░░░   64%           │ │
│ │  Личные продажи   8 420 000 ₸ⓘ│ Рустам Ниязов    ░░░░░░░░    —            │ │
│ │  Первичные встречи ⓘ  12 / 10 │                                            │ │
│ │  Ранг в команде ⓘ   #2 из 6   │                                            │ │
│ └────────────────────────────────────────────────────────────────────────────┘ │
│                                                                                │
│ ┌── ActivityFeed (card) ────────────────────────────────────────────────────┐ │
│ │ Активности за период   (Все)(☎Звонок)(👥Встреча)(✔Задача)(✎Заметка)        │ │
│ │ ─────────────────────────────────────────────────────────────────────────  │ │
│ │ ☎ Исходящий звонок — уточнение бюджета   ERP — «ЭнергоПром»   3 июл, 14:20  │ │
│ │ 👥 Первая встреча — «Финрезерв Банк» [Первичная] Компания #233  3 июл, 11:00 │ │
│ │ …                                                                          │ │
│ │ ─────────────────────────────────────────────────────────────────────────  │ │
│ │ Показано 6 из 34            [‹] [1] 2 3 4 5 [›]                             │ │
│ └────────────────────────────────────────────────────────────────────────────┘ │
└──────────────────────────────────────────────────────────────────────────────┘
```

Адаптив: см. §4.

### 2.1 CabinetToolbar (`components/CabinetToolbar.vue`) — единая одно-рядная шапка

Заменяет текущую пару `PageHeader` + `.manager-cabinet-page__tabs` + отдельный picker-ряд. Одна `flex`-строка, `flex-wrap: wrap`, `align-items: center`, `gap $space-3`, паддинг `$space-3 $space-5`, фон `$surface-card`, `border-bottom: 1px solid $surface-200`.

| Зона | Элемент | Реализация / props |
|---|---|---|
| Иконка | плитка 38×38, `$radius-md`, фон `var(--p-primary-100)`, иконка `pi pi-id-card` цвет `var(--p-primary-color)` | inline (как в мокапе). В dark: фон `var(--p-primary-900)`, иконка `var(--p-primary-100)` |
| Заголовок | «Кабинет менеджера» ($font-size-lg / 600 / $surface-900) + сабтайтл «{profile.full_name} · {profile.department_name}» ($font-size-xs / $surface-600) | из `profile` |
| Спейсер | `flex: 1` | — |
| User-picker | `Select` (admin/director) — «Мой кабинет» / список менеджеров | см. §2.2 |
| Segmented tabs | `SelectButton` 2 опции: «Кабинет менеджера» / «Мотивационная карта» | `:allow-empty="false"`, `option-label`/`option-value` |
| Month dropdown | `Select` месяцев (для Overview = KPI-период; для Motivation = свой период) | см. §2.4 |

> Порядок в мокапе: title → spacer → user-picker → segmented → month. **User-picker виден только при `canViewOthers`** (admin/director). Segmented и month — всегда.

### 2.2 User-picker (admin/director)

Реюз существующего решения: `Select` (PrimeVue) `filter show-clear`, `placeholder = t('managerCabinet.viewing.self')` («Мой кабинет»), опции — `memberOptions` (менеджеры+директора из `useManagerCabinetPage`). При выборе → `setViewedUser(id)` (пишет `?user_id=`), при clear → `setViewedUser(null)`. Ширина `min 210px`.

- Триггер показывает выбранного (аватар `EntityAvatar :pixel-size="26"` + имя) или «Мой кабинет» (muted).
- Мокап рисует кастомный dropdown с поиском и аватарами — **достаточно PrimeVue `Select filter`** с `#value`/`#option` слотами (аватар `EntityAvatar` + имя). Не плодить кастом-поповер.

### 2.3 Segmented tabs

`SelectButton` (как сейчас), `option-value ∈ {overview, motivation}`. Стилизация под сегмент-контрол из мокапа (капсула `$radius-md`, активный сегмент — фон `$surface-card` + тень `$shadow-sm` + текст `var(--p-primary-color)`; фон группы — `var(--p-surface-100)`). Значение синхронно с `?tab=` (логика в `index.vue` уже есть).

### 2.4 Month dropdown

Заменяет `MonthStepper` (7 кнопок). `Select` (PrimeVue), `min 150px`, значение = период.
- **Overview:** опции = последние 7 месяцев (текущий + 6), value как в текущем `KpiPeriod` (`current_month` | `YYYY-MM`). При выборе → `setPeriod(value)`.
- **Motivation:** опции = последние 12 месяцев из `useMotivationTab.monthOptions`, value `YYYY-M`. При выборе → `selectMonthByValue(value)`.
- В зависимости от активного таба dropdown биндится к соответствующему стейту (условный рендер двух `Select` или один с computed-опциями). Проще — два `Select`, показываем нужный по `activeTab`.

Лейбл выбранного месяца форматируется через существующий i18n-словарь месяцев (`motivation.card.months.*`) — переиспользовать для единообразия RU/EN.

---

## 2.5 ResultsHero (`components/ResultsHero.vue`)

Одна карточка (`$surface-card`, `border 1px $surface-200`, `$radius-lg`, `$shadow-sm`, `overflow: hidden`, `padding 0`). Внутри `grid-template-columns: 1fr 1fr`; правая колонка отделена `border-left: 1px solid $surface-200`. Каждая половина — `padding $space-4 $space-5`.

**Левая половина — «Результаты»:**
1. Eyebrow «РЕЗУЛЬТАТЫ · {период}» (`$font-size-2xs` / 700 / uppercase / ls 0.05em / `$surface-600`).
2. Ряд (`flex`, `align-items: center`, `gap $space-4`, `margin $space-3 0 $space-1`):
   - `ScoreRing` 104px (см. §2.6) с центром: число «108%» (`$font-size-2xl` / 700 / `$surface-900`) + подпись «МК%» (`$font-size-3xs` / 700 / uppercase / `$surface-600`). Если `has_salary_plan === false` → в центре «—», кольцо пустое (value 0).
   - `MoodHead` (см. §2.7).
3. Три `SecStatRow` (см. §2.8), каждая с `border-top: 1px solid $surface-200`:
   - «Личные продажи» → value `formatMoney(personal.income_fact_kopecks)`, sub «план {income_plan}», справа тихая `CcyNote` info-иконка (см. §2.9).
   - «Первичные встречи» → value `{ftm_count_fact} / {ftm_count_plan}` (если plan null → просто fact), sub «зачтённых встреч», info-tooltip (FTM-пояснение).
   - «Ранг в команде» → value «#{team.rank} из {team.size}», sub «среднее {team.avg_pct}%», info-tooltip (пояснение ранга).

**Правая половина — `TeamBars`** (см. §2.10).

### 2.6 ScoreRing (`components/ScoreRing.vue`)

Inline-SVG кольцо. Props: `pct: number` (0..100 клампится внутри), `size?: number` (default 104), `stroke?: number` (default 9), `color: string` (repo-токен-строка). Трек-круг `stroke = var(--p-surface-100)`; заполнение `stroke = color`, `stroke-linecap: round`, `stroke-dasharray/offset` по проценту, `transform: rotate(-90deg)`, transition 0.5s.

Цвет заполнения (в `ResultsHero`, по `score_pct`):
- `≥ 100` → `var(--p-green-500)`
- `80–99` → `var(--p-primary-color)` (акцент — в dark сам светлеет)
- `< 100 && < 80` → `var(--p-red-500)`
- `has_salary_plan === false` → value 0, color неважен (кольцо пустое).

Слот по центру — для числа/подписи (абсолютное позиционирование).

### 2.7 MoodHead (`components/MoodHead.vue`)

Inline-SVG «голова» (круг лица + 2 глаза + рот-`path`) + текст. Props: `score: number`.
- `mood`: `score≥100 → happy`, `80–99 → ok`, `<80 → low`.
- Цвет лица/мимики: happy `var(--p-green-500)`, ok `var(--p-primary-color)`, low `var(--p-orange-500)`. Фон круга `var(--p-primary-100)` (dark: `var(--p-primary-900)`).
- Рот-`path`: happy — дуга вверх, low — дуга вниз, ok — прямая.
- mood-слово (`$font-size-md` / 700, цвет = цвет мимики): happy «выше плана», ok «в графике», low «ниже плана».
- **Ротация фраз** ниже слова (`$font-size-xs` / `$surface-700`, `max-width 176px`, `cursor: pointer`): `setInterval` 4200ms листает массив фраз по mood; клик по фразе — следующая вручную (`title="Ещё фраза"`). При смене mood — сброс индекса на 0.

Фразы — новый i18n-блок `managerCabinet.mood.*` (RU из мокапа + EN, см. §5). Массивы:
- **happy:** «Ты выше плана — так держать!» · «Отличный темп, продолжай!» · «План побит — ты в ударе!» · «Так держать, лидер команды!»
- **ok:** «Почти у цели — поднажми!» · «Ещё чуть-чуть до плана!» · «Хороший ход, добери разрыв!» · «Ты близко — финишируй месяц!»
- **low:** «Есть куда расти — вперёд!» · «Соберись, всё получится!» · «Небольшой рывок — и догонишь!» · «Начни с одной сделки сегодня!»

> Фразы читаются через `tm('managerCabinet.mood.happy')` (массив) либо через индексированные ключи `happy.0…3`. Предпочтительно `tm()` (vue-i18n message-array) — фронтендер выбирает по удобству, но RU+EN обязателен.

### 2.8 SecStatRow (`components/SecStatRow.vue`)

Строка `flex`, `align-items: baseline`, `justify-content: space-between`, `gap $space-3`, `padding $space-3 0`, `border-top: 1px solid $surface-200`. Props: `label: string`, `value: string`, `sub?: string`, `hint?: string` (tooltip), `ccy?: boolean`.
- Слева: `label` (`$font-size-sm` / `$surface-700`) + опц. `pi pi-info-circle` (`$font-size-2xs` / `$surface-600` / `cursor: help`, `v-tooltip.top="hint"`).
- Справа (`text-align: right`): `value` (`$font-size-md` / 700 / `$surface-900` / `tabular-nums`) + опц. `CcyNote` inline + опц. `sub` блоком (`$font-size-2xs` / `$surface-600`).

### 2.9 CcyNote — тихая info-иконка (замена оранжевой плашки)

Текущий `Message severity="warn"` (multi-currency warning) **убираем**. Вместо него — тихая `pi pi-info-circle` (`$font-size-2xs` / `$surface-600` / `cursor: help`) рядом со значением «Личные продажи», `v-tooltip.top` с текстом:

> «Суммы приведены к базовой валюте; сделки без курса валют не учтены. Курсы валют — в Каталоге.»

Показывать иконку **только когда `kpi.meta.multi_currency_warning === true`** (иначе не рендерить — не шуметь). Реализация — маленький inline-элемент (не отдельный тяжёлый компонент); можно вынести в `CcyNote.vue` или встроить в `SecStatRow` через prop `ccy`.

### 2.10 TeamBars (`components/TeamBars.vue`)

Правая половина `ResultsHero`. Props: `members: TeamMember[]`, `avgPct: number`.
- Шапка: eyebrow «КОМАНДА» + справа «среднее {avgPct}%» (`$font-size-xs` / `$surface-600`).
- Список (`gap $space-3`, `margin-top $space-3`), каждая строка — `grid-template-columns: 1fr 76px 42px`, `align-items: center`, `gap $space-3`:
  - Имя (`$font-size-sm`; для `is_viewer` — 600 + `var(--p-primary-color)` + префикс `pi pi-user` 10px; иначе 500 + `$surface-900`), `text-overflow: ellipsis`.
  - Бар (высота 6px, `$radius-pill`, трек `var(--p-surface-100)`, `overflow: hidden`): при `score_pct != null` — градиент-заливка `linear-gradient(90deg, var(--p-red-500) 0%, var(--p-amber-500) 52%, var(--p-green-500) 100%)` на всю ширину + «маска» справа (`$surface-card` или `var(--p-surface-100)`) от `min(pct,120)/120*100%` до края (эффект «обрезанного» градиента — заполнение = pct к максимуму 120%). При `score_pct == null` — бар пустой (только трек).
  - `%` справа (`text-align: right`) — компонент `PctTag` (`size="sm"`) с «тихим» цветом (§3.6) — переиспользуем существующий `PctTag`. Для `null` → «—» muted.

> **Максимум бара — 120%** (как в мокапе `max=120`), чтобы 108% не упирался в край. Градиент — это «шкала здоровья»: красный слева → зелёный справа; позиция обрезки = достижение.

**Пустое состояние TeamBars** (`team.size <= 1`): иконка `pi pi-users` (`$font-size-icon-lg` / `$surface-400`) + «Вы единственный в отделе».

---

## 2.11 ActivityFeed (`components/ActivityFeed.vue`) — переписать

Карточка (`$surface-card`, border, `$radius-lg`, `$shadow-sm`), `padding $space-3 $space-5`.

**Шапка** (`flex`, `align-items: center`, `gap $space-3`, `flex-wrap`, `margin-bottom $space-3`):
- «Активности за период» (`$font-size-sm` / 600 / `$surface-900`).
- **Pill-фильтры** типов (`inline-flex`, `gap 2px`): «Все» · «Звонок» (`pi pi-phone`) · «Встреча» (`pi pi-users`) · «Задача» (`pi pi-check-square`) · «Заметка» (`pi pi-pencil`). Каждый — кнопка-капсула (`height 28px`, `$radius-pill`, `$font-size-xs` / 600): активная — фон `var(--p-primary-100)` + текст `var(--p-primary-color)`; неактивная — прозрачный фон + `$surface-600`, hover — фон `var(--p-surface-100)`. Клик → `setFeedKind(value)`, сброс страницы на 1.

> **ToggleButton «Только FTM» убираем** — фильтрация по FTM больше не нужна отдельной кнопкой; «Первичность» видна бейджем в строке. (Если продукт захочет фильтр FTM — вернём как 6-ю pill; сейчас по мокапу его нет.) `feedFtmOnly`-стейт в composable остаётся неиспользуемым или удаляется фронтендером — см. ОВ-3.

**Строки** (`flex`, `align-items: center`, `gap $space-3`, `padding $space-3 0`, `border-top: 1px solid $surface-200`):
- Иконка типа (`width 16px`, цвет по типу: call `var(--p-blue-500)`, meeting `var(--p-primary-color)`, task `var(--p-orange-500)`, note `$surface-600`).
- Заголовок `title` (`flex: 1`, `$font-size-sm` / `$surface-900`, ellipsis).
- Бейдж **«Первичная»** (если `is_first_time_meeting` или `ftm_counted`): `$font-size-3xs` / 700, текст `--mg-status-success-text`, фон `--mg-status-success-bg`, `$radius-xs`, `padding 1px $space-2`. (Текст «Первичная» вместо латинского «FTM».)
- Ссылка на объект: `router-link` (см. targetRoute, уже есть) цвет `var(--p-primary-color)`, hover underline, ellipsis `max-width 220px`. Текст — см. §GAP-1 (сейчас «Сделка #4812», мокап хочет название «ERP — «ЭнергоПром»»).
- Дата (`$font-size-xs` / `$surface-600`, `white-space: nowrap`, `width ~96px`, right) — формат «3 июл, 14:20» (день + месяц-short + время; `toLocaleString` уже так делает).

**Числовая пагинация** (низ, `flex`, `justify-content: space-between`, `margin-top $space-3`):
- Слева: «Показано {rows.length} из {feedMeta.total}» (`$font-size-xs` / `$surface-600`).
- Справа: `[‹]` `[1][2][3][4][5]` `[›]` — кнопки-квадраты 30×30, `$radius-md`:
  - Стрелки — иконочные кнопки (`pi pi-chevron-left/right`), бордер `var(--p-surface-300)`, disabled на краях (`opacity 0.4`).
  - Номера: активная — фон `var(--p-primary-color)` + текст белый + бордер `var(--p-primary-color)`; неактивная — прозрачная + `$surface-700` + бордер `var(--p-surface-300)`, hover — `var(--p-surface-100)`.
  - Показывать первые 5 страниц (`slice(0,5)`); при `last_page > 5` — по мокапу просто первые 5 (расширять эллипсисом — не сейчас, см. ОВ-4). Клик → `setFeedPage(n)`.

> Заменяем PrimeVue `Paginator` на кастомную числовую полосу (мокап). `Paginator` можно оставить как fallback, но целевой вид — числовые кнопки. `feedMeta.per_page` = размер страницы, `last_page` = число страниц.

**Состояния ActivityFeed:**
- **loading** (первый заход, `feed.length === 0`): `Skeleton` 220px (или 6 строк-скелетонов по ~44px — предпочтительно построчно, чеклист §Фиксы).
- **empty** (`feed.length === 0`, не loading): иконка `pi pi-inbox` (`$font-size-icon-lg` / `$surface-400`) + «Нет активностей за выбранный период». Если фильтр не «all» — кнопка-текст «Сбросить фильтры» (`var(--p-primary-color)`) → `resetFeedFilters()`.
- **error:** `Toast severity="error"` (уже в composable через `useToast`).

---

## 3. MOTIVATION — полиш (семантику v1.1 НЕ ломать)

Структура секций та же: шапка-строка → PayHero → DeptPlan → SalaryComponents (аккордеон) → total-футер → rates. Меняем ТОЛЬКО визуальную компоновку под мокап; данные, permission-gate, poll 30s, интерим-факт (`won_deals`-сноска) — как в as-built v1.1.

### 3.1 Компактная строка-шапка (заменяет `MkHeader.vue` карточку)

Мокап рисует шапку МК не карточкой, а **тонкой строкой** (`flex`, `align-items: center`, `gap $space-3`, `flex-wrap`):

```
[аватар 30px] Богдан Меркулов · Продажи   [Черновик]
```

- Аватар `EntityAvatar :pixel-size="30"` (initials из `meta.user.full_name`).
- Имя (`$font-size-sm` / 700 / `$surface-900`) + «· {pipeline.name}» (`$font-size-xs` / `$surface-600`).
- Статус-pill: `Tag` (существующая логика `MkHeader` — draft/finalized/paid → secondary/info/success). Оставить as-built severity-маппинг.

> Правим `MkHeader.vue`: убираем карточку-обёртку (border/padding/card), делаем строку. Воронка/период — период уже в toolbar-dropdown, в строке дублировать не нужно (мокап показывает только имя+воронку+статус). Правый aside (`meta.period.label`) из `MkHeader` **убираем** — период задаётся в шапке страницы.

### 3.2 PayHero (`MkTotalCard.vue` → перекомпоновка в 2 колонки)

Мокап объединяет «ЗП» и «прогноз бонуса» в **одну карточку из 2 колонок** (`grid 1.3fr 1fr`, правая — `border-left`), вместо текущей вертикальной sticky-колонки. Правим `MkTotalCard.vue` (или переименовываем в `MkPayHero.vue`):

**Левая колонка** (`padding $space-5 $space-6`):
- Eyebrow «Зарплата за {period}».
- **Hero-ЗП** = `total.salary_fact_kopecks`, `$font-size-4xl`(~38px) / 700 / `var(--p-primary-color)` / `letter-spacing -0.01em`. (Существующий `--hero` = `$font-size-3xl`; поднять до ~38px — новый размерный токен или `$font-size-4xl`; см. ОВ-1.)
- Строка ниже: «план {total.salary_plan}» (`$font-size-sm` / `$surface-700`) + сноска «считается по выигранным сделкам» + `pi pi-info-circle` tooltip (интерим-факт `won_deals` — по `fact_source === 'won_deals'`, как as-built). Абревиатуру `≈ 40,0 млн` из as-built сохранить опц. под hero (мокап её не показывает, но не мешает — оставить если есть).

**Правая колонка** (`padding $space-5 $space-6`, `border-left`):
- Ряд: eyebrow «Командный бонус · прогноз» + `PctTag :value="forecast.dept_pct"` (тихий цвет).
- 2 Kv-строки: «Часть 1 (60%) — по вкладу» → `part1_kopecks`; «Часть 2 (40%) — поровну» → `part2_kopecks`.
- Разделитель, затем «Прогноз итого» + `total_kopecks` (`$font-size-lg` / 700 / `$surface-900`).
- **Гейт-строка**: `forecast.gate_passed` → `pi pi-check-circle` + «Цель достигнута» (`--mg-status-success-text`); иначе `pi pi-lock` + «Цель не достигнута: {dept_pct}% из ≥ {threshold_pct}%» (`--mg-status-warning-text`). (as-built gate-логика сохраняется, только компоновка правее.)

> Если `forecast === null` — правую колонку не рендерить (карточка = только левая ЗП-часть).

### 3.3 DeptPlan (`MkDeptPlan.vue`) — + info-tooltip состава

Оставляем как есть (план → градиент-бар → факт + PctTag), **добавляем** к eyebrow «План отдела по новым поступлениям» иконку `pi pi-info-circle` с tooltip:

> «Сумма планов по новым поступлениям всех менеджеров отдела за месяц по выбранной воронке. Складывается из индивидуальных планов сотрудников.»

Бар: мокап рисует градиент `red→amber→green` с маской (как TeamBars). Текущий `MkDeptPlan` использует `ProgressBar` с одноцветной заливкой по badge. **Приводим к градиенту** (как TeamBars §2.10) для консистентности: трек `var(--p-surface-100)`, заливка-градиент + маска по `min(pct,100)`. Либо оставить `ProgressBar` но перекрасить в градиент через `:deep(.p-progressbar-value)` — фронтендер выбирает; целевой вид — градиент. (Не критично, но желательно единообразие с TeamBars.)

### 3.4 SalaryComponents (`MkSalaryTable.vue`) — аккордеон, свёрнут по умолчанию

**Ключевое изменение семантики отображения:** в as-built v1.1 строки **развёрнуты по умолчанию**. Мокап DS2 — **свёрнуты по умолчанию**, детали раскрываются по клику. В свёрнутой строке видно pct + ЗП-факт inline.

**Свёрнутая строка** (`flex`, `align-items: center`, `gap $space-3`, `padding $space-3 0`, кликабельна целиком):
```
[▣ иконка] Комиссия с продаж [5%]  ················  [112%]  168 400 ₸  ⌄
```
- Иконка-плитка 26×26 (`$radius-sm`, фон `var(--p-surface-100)`, иконка `$surface-700`) — в мокапе плитка нейтральная (не primary-100); можно оставить как есть или нейтрализовать (см. ОВ-2).
- Название (`$font-size-sm` / 600 / `$surface-900`) + опц. tag-описатель (`positionTag`: «5%» / «шт.» / «сумма» — уже вычисляется).
- Спейсер `flex: 1`.
- Для manual-KPI: текст «Выполнено» (`--mg-status-success-text`) / «Не выполнено» (`--mg-status-danger-text`) вместо pct.
- Иначе: `PctTag size="sm"` (тихий цвет §3.6).
- **ЗП-факт** `salary_fact_kopecks` (`$font-size-sm` / 700 / `$surface-900` / `tabular-nums`, `width ~96px`, right).
- Шеврон `pi pi-chevron-down` (rotate при раскрытии).

**Раскрытая деталь** (`padding-left ~37px`): текущая сетка «Показатели» (План/Факт) + «ЗП» (ЗП план / ЗП факт) — as-built `PlanFact`-раскладку сохранить. Плюс note (payment_note) и кнопка «Разбивка по сделкам» (`Popover`, as-built).

**Hint-подсказка** в шапке аккордеона (справа от eyebrow «Компоненты зарплаты»): «нажмите строку, чтобы раскрыть детали» (`$font-size-xs` / `$surface-600`).

**Стейт раскрытия:** default `false` для всех (в отличие от as-built `true`). Правим `expanded.value = Array(len).fill(false)`.

**Total-футер** (внутри карточки, `margin 0 -$space-5 -...`, `padding $space-3 $space-5`, `border-top 2px $surface-200`, фон `var(--p-surface-100)`):
```
Итого ЗП     ···     план {salary_plan}  →  {salary_fact}
```
- «Итого ЗП» (`$font-size-sm` / 700 / `$surface-900`).
- «план {…}» (`$font-size-sm` / `$surface-600`) + `pi pi-arrow-right` (`$surface-600`) + факт (`$font-size-lg` / 700 / `var(--p-primary-color)`). (as-built total сохранить, компоновка — как мокап: план → стрелка → факт.)

### 3.5 MkRates (`MkRatesFooter.vue`) — тихой строкой

Убрать карточку-обёртку (border/card). Сделать тихой строкой (`flex`, `gap $space-2`, `$font-size-xs` / `$surface-600`, `padding $space-1`):
```
↻ Курсы на 03.07.2026:  1 USD = 463,20 ₸  ·  1 RUB = 5,18 ₸
```
`pi pi-refresh` + «Курсы на {date}:» + пары (`$surface-700`). Stale-логика (>7 дней → `pi pi-exclamation-triangle` + warning-цвет) — сохранить as-built. **MkKpiStrip** (отдельные KPI-карточки) — в мокапе DS2 отсутствует: KPI-показатели теперь внутри аккордеона `SalaryComponents` (строки KPI), отдельной strip-полосы нет. **`MkKpiStrip.vue` из Motivation-таба убираем** (KPI-строки уже есть в аккордеоне как позиции `kind: 'kpi'`). См. ОВ-5.

### 3.6 Тихий цвет процентов (`pctColor`) — маппинг на repo-токены

Мокап вводит «тихую» семантику: зелёный только «в плюсе», серый в норме, красный вне плана.

| Порог | Мокап | repo-токен (в `PctTag` / тексте) |
|---|---|---|
| `≥ 100` | green | `severity="success"` (`--mg-status-success-*`) |
| `80–99` | `--c-text2` (тихий серый, НЕ warning-оранж) | `$surface-700` (нейтральный текст, не статус-тег) |
| `< 80` | red | `severity="danger"` (`--mg-status-danger-*`) |
| `null` | muted | «—» `$surface-600` |

> **Важно:** это отличается от текущего `pctToBadge` (который 80–99 красит в `warning`/оранж). Мокап хочет 80–99 **тихим серым**, не оранжевым. Нужен вариант `PctTag` с «тихой» палитрой (prop `tone="quiet"`) ИЛИ отдельный маппинг: для 80–99 рендерить не `Tag severity`, а нейтральный текст `$surface-700` 700. **Реализация:** добавить в `PctTag` опц. prop `quiet?: boolean` — при `quiet` для badge `warning` использовать нейтральный `$surface-700` вместо оранжа (success/danger остаются семантическими). Применяется к: TeamBars, SalaryComponents-строки, PayHero dept_pct, DeptPlan. См. ОВ-6.

### 3.7 Empty-state Motivation (нет карты)

as-built сохранить: `pi pi-file-edit` + «Мотивационная карта за этот месяц ещё не создана» + кнопка «Перейти в конструктор» (gate `canManage` через `motivation.manage`). Мокап рисует то же (`noCard` при `!has_card`).

---

## 4. Адаптив (breakpoints)

| BP | Overview | Motivation |
|---|---|---|
| **≥1440** | ResultsHero — 2 колонки (1fr/1fr); toolbar — одна строка | PayHero — 2 колонки (1.3fr/1fr) |
| **1280–1439** | как ≥1440 (grid держит 2 колонки); ширины SecStat/бары ужимаются | как ≥1440 |
| **768–1279 (планшет)** | ResultsHero → 1 колонка (TeamBars **под** результатами); toolbar может переносить user-picker/month на 2-ю строку (`flex-wrap`) | PayHero → 1 колонка (прогноз-бонус под ЗП); аккордеон-строки — грид деталей в 1 колонку |
| **<768 (мобайл)** | всё в 1 колонку; pill-фильтры и пагинация переносятся (`flex-wrap`); дата в строке фида уходит под заголовок | секции стопкой; total-футер держит план→факт в строку |

> Мокап-viewport 1320px. Целевые контрольные точки для QA — **1280 и 1440**. Bootstrap-grid: ResultsHero-колонки через CSS `grid` (не `.row/.col`, т.к. `border-left` + равные половины проще гридом); pill/пагинация — `d-flex flex-wrap gap-2`.

---

## 5. i18n-ключи (RU обязателен, EN — задел)

**Новый блок `managerCabinet.mood`** (фразы + слова):

```json
{
  "ru": {
    "managerCabinet.mood.word_happy": "выше плана",
    "managerCabinet.mood.word_ok": "в графике",
    "managerCabinet.mood.word_low": "ниже плана",
    "managerCabinet.mood.happy": [
      "Ты выше плана — так держать!",
      "Отличный темп, продолжай!",
      "План побит — ты в ударе!",
      "Так держать, лидер команды!"
    ],
    "managerCabinet.mood.ok": [
      "Почти у цели — поднажми!",
      "Ещё чуть-чуть до плана!",
      "Хороший ход, добери разрыв!",
      "Ты близко — финишируй месяц!"
    ],
    "managerCabinet.mood.low": [
      "Есть куда расти — вперёд!",
      "Соберись, всё получится!",
      "Небольшой рывок — и догонишь!",
      "Начни с одной сделки сегодня!"
    ],
    "managerCabinet.mood.nextPhrase": "Ещё фраза",
    "managerCabinet.results.eyebrow": "Результаты",
    "managerCabinet.results.scorePctLabel": "МК%",
    "managerCabinet.team.eyebrow": "Команда",
    "managerCabinet.feed.showing": "Показано {shown} из {total}",
    "managerCabinet.feed.firstTime": "Первичная",
    "managerCabinet.kpi.ccyNote": "Суммы приведены к базовой валюте; сделки без курса валют не учтены. Курсы валют — в Каталоге.",
    "managerCabinet.kpi.ftmHint": "Первая встреча с новым клиентом (First-Time Meeting). Зачитывается автоматически при встрече с компанией, у которой ранее встреч не было.",
    "managerCabinet.kpi.rankHint": "Место по проценту выполнения мотивационной карты (МК%) среди сотрудников отдела за месяц. #1 — лучший результат."
  },
  "en": {
    "managerCabinet.mood.word_happy": "above plan",
    "managerCabinet.mood.word_ok": "on track",
    "managerCabinet.mood.word_low": "below plan",
    "managerCabinet.mood.happy": [
      "You're above plan — keep it up!",
      "Great pace, keep going!",
      "Plan beaten — you're on fire!",
      "Keep leading the team!"
    ],
    "managerCabinet.mood.ok": [
      "Almost there — push a bit!",
      "Just a little to the plan!",
      "Good move, close the gap!",
      "You're close — finish the month!"
    ],
    "managerCabinet.mood.low": [
      "Room to grow — go for it!",
      "Get it together, you've got this!",
      "A small push and you'll catch up!",
      "Start with one deal today!"
    ],
    "managerCabinet.mood.nextPhrase": "Next phrase",
    "managerCabinet.results.eyebrow": "Results",
    "managerCabinet.results.scorePctLabel": "Score",
    "managerCabinet.team.eyebrow": "Team",
    "managerCabinet.feed.showing": "Showing {shown} of {total}",
    "managerCabinet.feed.firstTime": "First-time",
    "managerCabinet.kpi.ccyNote": "Amounts converted to base currency; deals without an FX rate are excluded. FX rates are in Catalog.",
    "managerCabinet.kpi.ftmHint": "First-Time Meeting with a new client. Counted automatically when meeting a company with no prior meetings.",
    "managerCabinet.kpi.rankHint": "Rank by motivation-card completion (Score) among department members for the month. #1 is best."
  }
}
```

Переиспользовать существующие: `managerCabinet.title`, `managerCabinet.feed.filterAll/Call/Meeting/Task/Note`, `managerCabinet.kpi.personalSales/ftm/rank/plan/noPlan`, `managerCabinet.team.of/avgLabel`, `managerCabinet.feed.resetFilters/noActivity`, `motivation.card.*` (полиш не добавляет новых МК-ключей, кроме уже существующих). Проверить наличие `managerCabinet.viewing.self`/`.label` (есть в index.vue).

---

## 6. Данные — маппинг «элемент → поле API»

Источник Overview — `KpiResponse` (`GET /api/managerCabinet/kpi`), профиль — `MeProfile`, лента — `ActivityFeedItem[]`. Источник Motivation — `MotivationCard` (`GET /api/motivation/cards/me`). Всё уже загружается в composables. Новых полей/эндпоинтов **не требуется**, кроме одного GAP (см. §7).

### Overview

| Элемент UI | Поле API |
|---|---|
| Toolbar заголовок/сабтайтл | `profile.full_name`, `profile.department_name` |
| User-picker опции | `memberOptions` (`usersApi.getUsers()` → role manager/director) |
| Кольцо МК% (число + заливка) | `kpi.personal.score_pct`, `kpi.personal.has_salary_plan`, `kpi.personal.score_badge` |
| MoodHead mood | `kpi.personal.score_pct` (клиентская деривация) |
| SecStat «Личные продажи» | `kpi.personal.income_fact_kopecks`, `income_plan_kopecks` |
| SecStat «Первичные встречи» | `kpi.personal.ftm_count_fact`, `ftm_count_plan` |
| SecStat «Ранг в команде» | `kpi.team.rank`, `kpi.team.size`, `kpi.team.avg_pct` |
| CcyNote (показ) | `kpi.meta.multi_currency_warning` |
| Eyebrow период | `kpi.meta.period.label` |
| TeamBars строки | `kpi.team.members[]`: `full_name`, `score_pct`, `is_viewer` |
| TeamBars среднее | `kpi.team.avg_pct` |
| Feed иконка/тип | `item.kind` |
| Feed заголовок | `item.title` |
| Feed бейдж «Первичная» | `item.is_first_time_meeting` \|\| `item.ftm_counted` |
| Feed ссылка (route) | `item.target_type`, `item.target_id` |
| Feed дата | `item.due_at ?? item.created_at` |
| Feed пагинация | `feedMeta.current_page`, `last_page`, `per_page`, `total` |

### Motivation

| Элемент UI | Поле API |
|---|---|
| Строка-шапка | `card.meta.user.full_name`, `card.meta.pipeline.name`, `card.meta.status` |
| Hero-ЗП | `card.total.salary_fact_kopecks`, `salary_plan_kopecks`, `currency` |
| Интерим-факт сноска | `card.meta.fact_source === 'won_deals'` |
| Прогноз бонуса | `card.team_bonus_forecast.{dept_pct,part1_kopecks,part2_kopecks,total_kopecks,gate_passed,threshold_pct,currency}` |
| DeptPlan | `card.dept_plan.{target_kopecks,fact_kopecks,pct,badge,target_currency}` |
| DeptPlan meta | `card.meta.pipeline.name`, `card.meta.period.label` |
| Аккордеон-строки | `card.items[]`: `name`, `kind`, `pct`, `badge`, `salary_fact_kopecks`, `salary_plan_kopecks`, `params.*` |
| Разбивка комиссии | `card.items[].params.breakdown[]` |
| Total-футер | `card.total.{salary_plan_kopecks,salary_fact_kopecks,currency}` |
| Rates | `card.rates.{date,pairs[]}` |

---

## 7. ГЭП: нужно решение (данные, которых реально нет в ответах)

**ГЭП-1 (feed target name).** Мокап рисует в ленте **название объекта** — «ERP — «ЭнергоПром»», «Первая встреча — «Финрезерв Банк»» — вместо технического «Сделка #4812». Но `ActivityFeedItem` возвращает только `target_type` + `target_id` (**нет** `target_name`/`target_title`). Варианты:
- **(a)** backend добавляет `target_name: string | null` в `ActivityFeedItem` (имя сделки/компании/контакта) — предпочтительно, минимальный джойн. **Требуется backend** (`sales-backender`/`crm-backender`, ресурс ленты кабинета).
- **(b)** оставить текущий формат «{label} #{id}» (Сделка #4812) как есть — без имени. Ленту это не ломает, но мокап-визуал не достигается.

Решение за продуктом/backend. **До решения — реализовать (b)** (текущий формат), а `target_name` подключить, как только backend отдаст. Не выдумывать запрос имени по каждому item на фронте (N+1).

> Остальные элементы мокапа (кольцо, mood, бары, SecStat, PayHero 60/40, гейт, аккордеон, rates, тихий цвет, CcyNote, числовая пагинация) — **полностью покрыты существующими полями API**. Новых эндпоинтов не нужно.

---

## 8. PrimeVue-компоненты и reuse

| Нужда | Решение |
|---|---|
| Segmented tabs | `SelectButton` (есть) |
| User-picker | `Select filter show-clear` + `#option`/`#value` слоты с `EntityAvatar` |
| Month dropdown | `Select` |
| Аватар | `EntityAvatar` (`:pixel-size`), НЕ PrimeVue `Avatar` (charter: единый аватар проекта) — **заменить** `Avatar` в `MkHeader`/`CabinetHeader` на `EntityAvatar` |
| % тег | `PctTag` (есть) + новый prop `quiet` (§3.6) |
| Статус МК | `Tag` (as-built severity-маппинг) |
| Кольцо прогресса | `ScoreRing` (новый, inline-SVG) — обоснование: PrimeVue не даёт кольцевой прогресс с центр-слотом; ECharts gauge избыточен для 104px-виджета |
| Смайлик | `MoodHead` (новый, inline-SVG) — обоснование: чисто декоративный настроенческий виджет, нет аналога в ките |
| Прогресс-бар (DeptPlan/TeamBars) | градиент-div (маска) ИЛИ `ProgressBar` с `:deep`-градиентом |
| Разбивка комиссии | `Popover` (as-built) |
| Tooltip | `v-tooltip` (директива, есть) |
| loading | `Skeleton` (построчно) |
| error | `Toast` (в composable) / `Message` (Motivation error, as-built) |
| empty | иконка + текст + опц. CTA |

**Новые компоненты (обоснованы выше):** `ScoreRing`, `MoodHead`, `ResultsHero`, `TeamBars`, `SecStatRow`, `CabinetToolbar`, `ActivityFeed` (переписан). `ScoreRing`/`MoodHead`/`SecStatRow`/`PctTag(quiet)` — потенциально переиспользуемы в других дашбордах; после ревью добавить `ScoreRing`/`SecStatRow` в charter §2.

---

## 9. Состояния (сводка)

| Зона | loading | empty | error |
|---|---|---|---|
| Toolbar | профиль-скелетоны (имя/сабтайтл) | — | — |
| ResultsHero (левая) | скелетон кольца (circle 104px) + 3 строки-скелетона | «Данные KPI недоступны» (`pi pi-chart-pie`) | Toast |
| TeamBars | 6 бар-скелетонов | «Вы единственный в отделе» (`pi pi-users`) при `size<=1` | Toast |
| ActivityFeed | 6 строк-скелетонов (~44px) | «Нет активностей…» (`pi pi-inbox`) + «Сбросить фильтры» если фильтр≠all | Toast |
| Motivation | `MkSkeleton` (as-built) | «МК не создана» + «Перейти в конструктор» (gate) | `Message` + «Повторить» (as-built) |

---

## 10. Acceptance-чеклист (для qa-tester, ОБЕ темы)

**Токены/тема:**
- [ ] `npm run lint:ds` зелёный (0 литеральных hex/px вне токенов).
- [ ] В обеих темах (light `body`, dark `.app-dark`) кольцо МК%, hero-ЗП 38px, активная pill/таб/номер-страницы — акцент через `var(--p-primary-color)` (в dark = светло-синий `#4C7DF0`, НЕ пропадает на navy, НЕ хардкод navy).
- [ ] В dark: карточки `ResultsHero`/`ActivityFeed`/PayHero — `$surface-card` (navy `#111E38`), текст читаем (`$surface-900`=светлый), нет dark-on-dark.
- [ ] Нет ни одного `:deep(.app-dark …)` / `:global(.app-dark) &` (закон мёртвых селекторов); dark задан theme-reactive токенами или `.app-dark &` на своём scoped-элементе.
- [ ] Градиент баров (TeamBars/DeptPlan) `red→amber→green` виден в обеих темах, трек = `var(--p-surface-100)`.

**Overview:**
- [ ] Toolbar — одна строка (≥1440); user-picker виден только у admin/director; segmented и month всегда.
- [ ] Кольцо: 108% → зелёное; 88% → акцент; 64% → красное; `has_salary_plan=false` → «—» + пустое кольцо.
- [ ] MoodHead: mood-слово и мимика меняются по score (≥100 happy / 80–99 ok / <80 low); фраза ротируется ~4.2с; клик по фразе — следующая; при смене месяца/юзера индекс сбрасывается.
- [ ] 3 SecStat-строки с info-tooltip (FTM, ранг) работают; CcyNote-иконка появляется ТОЛЬКО при `multi_currency_warning=true` (оранжевой плашки `Message` больше нет).
- [ ] TeamBars: viewer-строка выделена (600 + акцент + `pi pi-user`); `score_pct=null` → пустой бар + «—»; максимум бара 120%.
- [ ] ActivityFeed: pill-фильтры переключают тип (сброс на стр.1); бейдж «Первичная» (RU, не «FTM») на FTM-строках; числовая пагинация (‹ 1 2 3 4 5 ›), активный номер — акцент, стрелки disabled на краях; «Показано N из M».
- [ ] Пустой фид → «Нет активностей…» + «Сбросить фильтры» (если фильтр≠all).

**Motivation (семантика v1.1 не сломана):**
- [ ] Шапка — компактная строка (аватар 30px + имя · воронка + статус-pill), не карточка.
- [ ] PayHero: hero-ЗП 38px = `salary_fact`; правая колонка — прогноз 60/40 + гейт; сноска «по выигранным сделкам» при `fact_source=won_deals`.
- [ ] SalaryComponents: строки **свёрнуты по умолчанию**; в свёрнутой видно pct + ЗП-факт; клик раскрывает детали; hint «нажмите строку…»; total-футер «план → факт».
- [ ] Тихий цвет %: ≥100 зелёный, 80–99 **серый** (не оранж), <80 красный, null «—».
- [ ] DeptPlan: info-tooltip состава; rates — тихой строкой (не карточкой); MkKpiStrip удалён.
- [ ] Empty (нет карты) → «Перейти в конструктор» под `motivation.manage`.
- [ ] poll 30s прогноза и переход к конструктору — работают (as-built не сломан).

**Адаптив:**
- [ ] 1280 и 1440: layout корректен; на 768 ResultsHero/PayHero → 1 колонка (команда/прогноз под основным блоком).

---

## 11. Открытые вопросы (ОВ)

**ОВ-1 (hero-размер 38px).** Мокап рисует hero-ЗП 38px; текущий `--hero` = `$font-size-3xl`. Нужен размерный токен ~38px (`$font-size-4xl`?) — проверить наличие в `_typography.scss`; если нет — добавить токен (не литерал). Кому: designer + фронтендер согласуют токен.

**ОВ-2 (иконка-плитка в аккордеоне).** As-built красит плитку `var(--p-primary-100)` + иконку `$primary-900`; мокап — нейтральная плитка (`--c-hover` фон, `--c-text2` иконка). Нейтрализовать до `var(--p-surface-100)` + `$surface-700`? Мелочь визуала — **дефолт: нейтрализовать** (по мокапу), если продукт не против.

**ОВ-3 (FTM-фильтр).** Мокап убирает ToggleButton «Только FTM» (первичность видна бейджем). Убираем фильтр совсем? **Дефолт: убрать** (стейт `feedFtmOnly` — оставить в composable как no-op или удалить; endpoint параметр `ftm_only` перестаёт слаться). Если нужен фильтр — вернём 6-й pill.

**ОВ-4 (пагинация >5 стр).** Мокап показывает первые 5 номеров без эллипсиса. При `last_page > 5` — оставить первые 5 (как мокап) или добавить «…»/«последняя»? **Дефолт: первые 5** (мокап), доработку эллипсиса — при необходимости.

**ОВ-5 (MkKpiStrip).** Подтвердить удаление отдельной KPI-strip в Motivation (KPI теперь строки аккордеона). **Дефолт: удалить** (по мокапу). Если продукт хочет отдельные KPI-карточки (FTM/NEW INCOME) как выделенные виджеты — вернём strip.

**ОВ-6 (тихий 80–99).** Подтвердить: 80–99% в кабинете = **тихий серый** (`$surface-700`), НЕ оранжевый warning. Это меняет восприятие «нормы» (не тревожный оранж). **Дефолт: тихий серый** (по мокапу DS2). Затрагивает `PctTag quiet` — применять только в кабинете/МК, не глобально (в других экранах warning-оранж остаётся).

**ОВ-7 (GAP-1, feed target name).** Backend: добавить `target_name` в ленту кабинета? См. §7. До решения — формат «{label} #{id}». Кому: backend-architect / sales-backender.
