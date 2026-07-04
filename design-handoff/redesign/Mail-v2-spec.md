# ТЗ: Э12 — Редизайн Inbox → «Почта» (двухпанельный триаж), СРЕЗ A

**Статус:** **РЕАЛИЗОВАНО 2026-07-04 (срез A), коммит `b1bbb34`** (Э12, reviewer PASS). As-built отклонения: все цвета (ChannelDot, unread-точки, failed-баннер) — через `--p-*` var'ы репо-темы, не литералы navy из мокапа. Backend не тронут (весь срез A на текущем `/api/inbox`). Срезы B/C (звёзды/важные/snooze/date-range/per-folder counts; Отправленные/Черновики/Спам) + QA-фикстура failed-письма — отложены (§7 аудита).

**Зачем:** превратить триаж входящих из «список + модалка» в двухпанельную «почту» (список слева
+ читалка справа) — быстрее разбирать входящие в сделки, не теряя контекст. User story: менеджер/
директор открывает письмо и читает его, не закрывая список, тут же переобрабатывает failed и
переключает прочитано/непрочитано.

**Где в коде:** `front/src/pages/InboxPage/` (переработка существующей страницы; **backend не
трогаем — весь СРЕЗ A реализуем на текущем `/api/inbox`**).

**Источник фич (мокап):** `design-handoff/redesign/mail.html` (DS2, разобран целиком).
**Источник бизнес-логики:** `./examples/contracts/apps/web` (intake/triage) — уже реализовано в
текущем `Domain/Inbox`; новых полей не вводим.

**Эталон-канон:** `docs/designer-charter.md`; токены — `front/src/theme/scss/`; navy dark —
`front/src/theme/tokens/colors.ts`. Литералы navy из `mail.html` (`#12213E`/`#243358`/`#E8EDF6`)
— **reference-only**, в коде НЕ используем; берём переменные репо.

---

## 0. Что в скоупе, а что нет (жёсткие границы)

### СРЕЗ A — реализуем (весь этот документ)
Двухпанельный layout, unread-точки + тогл read/unread, цветной ChannelDot, DealChip (#deal /
«Разобрать»), фильтр-панель с 3 реализуемыми папками (Входящие / Не разобрано / В сделках) + чипы
каналов, red-баннер «Создать сделку» для failed, Raw payload аккордеон (admin/director), density
Обычная/Просторная, segmented Неп**прочитанные/Все. **Всё это закрывается текущим API** (см. §8
«Данные» — маппинг подтверждён).

### СРЕЗ B — вне скоупа (ждёт backend / отдельный спринт) — НЕ реализуем
Звёзды/помеченные (`pi-star`), «Важные» (`pi-flag`), «Отложенные»/snooze (`pi-clock`), date-range
пикер внутри панели фильтров, per-folder счётчики-бейджи на чипах папок/каналов.
В мокапе `mail.html` эти элементы присутствуют (кнопка звезды в строке и в toolbar читалки, папки
`starred`/`important`/`snoozed`, поле «Дата получения», `counts.*` на чипах) — **их в реализацию
СРЕЗА A НЕ переносим**. Причина: нет полей `starred`/`important`/`snoozed_until` в
`InboundMessage`, нет per-folder count-эндпоинта, а date-range хоть и поддержан API
(`date_from`/`date_to`), но по решению относится к пакету B (панель-фильтров-2.0).
> Примечание: date-range **технически** работает на текущем API. Оставляем в B сознательно, чтобы
> СРЕЗ A был чистым «list-vs-detail» без разрастания фильтр-панели. Если PM захочет date-range в A
> — см. ОВ-4.

### СРЕЗ C — вне скоупа (исходящая почта, отдельный спринт) — НЕ реализуем
Папки «Отправленные» / «Черновики» / «Вся почта» / «Спам» / «Корзина» (`sent`/`drafts`/`allmail`/
`spam`/`trash`), кнопка «Написать». Inbox сейчас — только **входящие** (нет исходящей сущности).
В мокапе эти папки есть в массиве `FOLDERS` — **не переносим**.

**Итог по папкам:** из 11 папок мокапа в СРЕЗ A идут ровно **3**: `all` (Входящие), `failed`
(Не разобрано), `deals` (В сделках). Остальные 8 — B/C.

---

## 1. Wireframe (ASCII) — desktop ≥ lg

```
┌──────────────────────────────────────────────────────────────────────────────────────┐
│ HEADER (PageHeader-подобная шапка, фон $surface-card)                                   │
│ ┌──┐                                    ┌───────────────────────┐                       │
│ │✉ │ Почта  [12]   [Непроч.|Все]        │ 🔍 Поиск в почте   [⚙] │      [↻ Обновить]     │
│ └──┘ ↑badge red   ↑SelectButton         └───────────┬───────────┘  ↑Button secondary   │
│                                                      │ ⚙ = панель-фильтров (папки+каналы)│
│                                          ┌───────────┴──────────────────────────┐       │
│                                          │ ПАПКИ:  [◉ Входящие] [Не разобр.] [В  │       │
│                                          │         сделках]                     │       │
│                                          │ КАНАЛЫ: [tg][wa][email][форма][api]  │       │
│                                          │                    [Сбросить][Готово]│       │
│                                          └──────────────────────────────────────┘       │
├────────────────────────────────────┬───────────────────────────────────────────────────┤
│ СПИСОК (minmax(360px,2fr))          │ ЧИТАЛКА (3fr)                                       │
│ ┌────────────────────────────────┐ │ ┌───────────────────────────────────────────────┐ │
│ │•(●) [tg]  Клиника «МедПлюс»  5м │ │ │ (●tg) Клиника «МедПлюс»       [↕ Непрочитано] │ │
│ │      Хотим CRM…      [Разобрать]│◄─┤ Telegram · #5012                                │ │
│ ├────────────────────────────────┤ │ ├───────────────────────────────────────────────┤ │
│ │ (●) [email] Финрезерв…    22м   │ │ │ ⚠ Сообщение не разобрано  [Создать сделку]    │ │ ← failed only
│ │      Запрос КП…      #4821 ✓    │ │ ├───────────────────────────────────────────────┤ │
│ ├────────────────────────────────┤ │ │ КАНАЛ    Telegram · @med…  ПОЛУЧЕНО 3 июл 14:38│ │
│ │  [wa]  Ольга Ким          3ч    │ │ │ ОТ(ИМЯ)  Клиника…          ОТ(ID) @med_plus_it │ │
│ │      Когда демо…        #4771   │ │ │ СТАТУС   [Не разобрано]    СДЕЛКА  не привязана│ │
│ │  … (read: тусклее, без точки)  │ │ ├───────────────────────────────────────────────┤ │
│ └────────────────────────────────┘ │ │ ТЕМА  Хотим CRM для сети клиник                │ │
│ ┌────────────────────────────────┐ │ │ ТЕКСТ Здравствуйте! У нас сеть из 6 клиник…    │ │
│ │ Показано 8 из 34   [‹][1][2][›] │ │ │ ▸ Raw payload (admin/director)                │ │
│ └────────────────────────────────┘ │ └───────────────────────────────────────────────┘ │
└────────────────────────────────────┴───────────────────────────────────────────────────┘
```

### Wireframe — < lg (двухпанельность складывается)
```
Открыт список:                          Открыто письмо (детали):
┌──────────────────────┐                ┌──────────────────────────┐
│ HEADER (компактно)   │                │ [‹ К списку] toolbar     │
├──────────────────────┤                ├──────────────────────────┤
│ • строка письма      │  клик строки   │ ⚠ баннер (если failed)   │
│ • строка письма      │ ────────────►  │ meta-grid (1 колонка)    │
│ • строка письма      │                │ Тема / Текст             │
│ [пагинация]          │                │ ▸ Raw payload            │
└──────────────────────┘                └──────────────────────────┘
   (читалка скрыта)                        (список скрыт, back-кнопка)
```
На `< lg`: показывается ОДНА панель. По умолчанию — список. Клик по строке → читалка на весь
экран + кнопка «‹ К списку» в toolbar читалки (emit возврата). Это заменяет модалку из текущей
реализации на том же брейкпоинте.

---

## 2. Композиция (корневая страница + подкомпоненты)

Переработка `front/src/pages/InboxPage/`. `InboxDetailDialog.vue` (модалка) **выпиливается** —
её роль занимает встроенная панель-читалка `InboxReadingPane.vue`.

| Файл | Что делаем | Роль |
|------|-----------|------|
| `index.vue` | переработать | grid-контейнер: header + двухпанельный `body` (список \| читалка); держит `selectedId`, `mobileView` ('list'\|'detail') |
| `components/InboxHeader.vue` | **новый** (или инлайн в index) | иконка+«Почта»+unread-badge, SelectButton Непроч./Все, `InboxSearchFilters`, кнопка «Обновить» |
| `components/InboxSearchFilters.vue` | **новый** (заменяет `InboxFilterBar.vue`) | поисковая пилюля + кнопка-триггер панели фильтров (папки+каналы). Заменяет старую inline-строку фильтров |
| `components/InboxList.vue` | переработать | панель-карточка списка: header-строка колонок УБИРАЕМ (в мокапе строки самодостаточны), тело со строками, футер «Показано N из M» + пагинация |
| `components/InboxMessageRow.vue` | переработать | строка мокапа: unread-точка → ChannelDot → (имя+идент+время) / (тема·текст + DealChip). БЕЗ звезды (СРЕЗ B) |
| `components/InboxReadingPane.vue` | **новый** (заменяет `InboxDetailDialog.vue`) | правая панель: toolbar (ChannelDot+имя+канал·#id + тогл read/unread), failed-баннер, meta-grid, тема, текст, Raw payload аккордеон. Empty-заглушка «Выберите сообщение» |
| `components/InboxChannelDot.vue` | **новый** | цветной круг с иконкой канала (см. §4) |
| `components/InboxDealChip.vue` | **новый** | #deal / «Разобрать» / «—» (см. §5) |
| `composables/useInboxPage.ts` | доработать | убрать `detailVisible`/`closeDetail`-модалку; добавить `selectedId` + `mobileView`; `openMessage()` грузит detail + **не** авто-читает (сохраняем текущее поведение) |
| `components/inbox/ChannelKindTag.vue` | **оставляем** | используется в meta-grid читалки (канал tag), НЕ в строке списка (там ChannelDot) |

**Reuse (обязательно):**
- `PageHeader` — НЕ используем прямо (шапка «Почты» кастомная с segmented+поиском). Берём его
  токены/высоту как ориентир, но верстаем свой header (аналогично тому, как список-страницы имеют
  собственные тулбары). *Обоснование нового header'а: PageHeader — title+subtitle+#actions, а тут
  в шапке живёт SelectButton + поисковая-пилюля-с-панелью-фильтров + badge — это toolbar, не
  header. См. ОВ-2.*
- `ChannelKindTag` — reuse в meta-grid читалки.
- PrimeVue `Popover` — для панели фильтров (папки+каналы) под поисковой пилюлей.
- PrimeVue `SelectButton` — segmented Непрочитанные/Все (как сейчас).
- PrimeVue `Message` — failed-баннер + error-состояния.
- PrimeVue `Accordion` (`AccordionPanel/Header/Content`) — Raw payload (как сейчас в диалоге).
- PrimeVue `Skeleton` — loading списка и читалки.
- PrimeVue `Badge` — unread-счётчик в шапке (как сейчас).
- PrimeVue `Paginator` — пагинация списка (как сейчас).
- `EntityAvatar` — **НЕ используем** (у входящего нет entity-id; канал важнее лица). ChannelDot —
  новый мелкий примитив (обоснование: круг с иконкой канала, не аватар).

**Новые компоненты (обоснование в ТЗ, как требует charter §8):**
- `InboxChannelDot` — цветной круг-иконка канала. Готового нет: `ChannelKindTag` — это Tag с
  лейблом (для meta/фильтров), а в строке нужен именно круглый крупный значок 34/38px как
  визуальный якорь строки. Обоснованно новый.
- `InboxDealChip` — тройное состояние (routed→#deal / dedup→#deal link / failed→кнопка
  «Разобрать»). Инкапсулирует логику, которая сейчас размазана по `InboxMessageRow`. Обоснованно
  выделяем в примитив (переиспользуется в строке И в meta-grid читалки).
- `InboxReadingPane` — встроенная читалка вместо модалки. Обоснованно новый (панель, не Dialog).
- `InboxSearchFilters` — поисковая-пилюля-с-Popover-панелью (папки+каналы). Заменяет
  `InboxFilterBar`.

---

## 3. Layout, отступы, поверхности (токены)

### Header (шапка «Почты»)
- Контейнер: `d-flex align-items-center`, `gap: $space-3`, `padding: $space-3 $space-5`,
  `background: $surface-card`, нижняя граница `1px solid $surface-200` (dark:
  `var(--p-surface-200)`), `flex-wrap: wrap` (для узких экранов).
- Иконка-плашка: 38×38, `border-radius: $radius-md`, `background: $primary-50` (dark:
  `var(--p-surface-100)`), иконка `pi pi-envelope` `$font-size-md`, цвет `$primary-color`
  (dark: `var(--p-primary-color)`).
- Заголовок «Почта»: `$font-size-xl`, `$font-weight-semibold`, `var(--p-text-color)`.
- Unread-badge: PrimeVue `Badge severity="danger"`, значение = `inboxUnreadCount` (99+ при >99).
  Показывать только при `> 0`.
- SelectButton «Непрочитанные/Все»: как сейчас (`SelectButton`, `allow-empty=false`). Reuse.
- `InboxSearchFilters`: `flex: 1; min-width: 240px`.
- Кнопка «Обновить»: `Button icon="pi pi-refresh" severity="secondary" text` (как сейчас).

### Двухпанельный body
- Контейнер: `display: grid; grid-template-columns: minmax(360px, 2fr) 3fr; gap: $space-4;
  padding: $space-4 $space-5; flex: 1; min-height: 0;`.
- Каждая панель (список / читалка): `background: $surface-card`, `border: 1px solid $surface-200`
  (dark: `var(--p-surface-200)`), `border-radius: $radius-lg`, `box-shadow: $shadow-sm`,
  `overflow: hidden`, `display: flex; flex-direction: column`.
- `< lg` (992px): `grid-template-columns: 1fr` + переключение панелей через `mobileView` (v-if).

### Строка списка (`InboxMessageRow`)
- `display: flex; align-items: center; gap: $space-3;`
- Плотность **Обычная**: `padding: $space-3 $space-4;` · **Просторная**: `padding: $space-4
  $space-4;` (density-проп; см. §7).
- Нижняя граница `1px solid $surface-200` (dark `var(--p-surface-200)`), последняя строка — без.
- Левая рамка-акцент выбранной строки: `border-left: 3px solid` — выбранная `$primary-color`,
  иначе `transparent`.
- Фон: выбранная → `$primary-50` (dark: `var(--p-surface-200)`); hover → `var(--mg-surface-hover)`;
  иначе `transparent`.
- unread-точка: 8×8 круг слева (колонка-обёртка 8px), `background: $primary-color`. read →
  колонка пустая (точки нет).
  > В dark акцент светлеет автоматически через `$primary-color`/`var(--p-primary-color)` —
  > литерал не пишем.

### Читалка (`InboxReadingPane`)
- toolbar: `d-flex align-items-center; gap: $space-3; padding: $space-3 $space-5;` нижняя граница
  `1px solid $surface-200`.
- тело: `flex: 1; overflow-y: auto; padding: $space-4 $space-5;` (скроллбар скрыт — charter).
- meta-grid: `display: grid; grid-template-columns: 1fr 1fr; gap: $space-4 $space-6; padding-block:
  $space-2 $space-4;` нижняя граница-разделитель. На `< lg` → `grid-template-columns: 1fr`.
- meta-label: `$font-size-xs`, `$font-weight-medium`, uppercase, `letter-spacing: 0.05em`,
  `var(--p-text-muted-color)`.
- meta-value: `$font-size-sm`, `var(--p-text-color)`. Идентификатор — `$font-family-mono`.

Все радиусы/отступы — из шкалы токенов. Промежуточные значения мокапа (`2px 8px`, `3px`) —
только для существующих бренд-инвариантов (badge-пилюля `$radius-badge`, hairline `$radius-2xs`).

---

## 4. ChannelDot (`InboxChannelDot`) — цвет по каналу

Круг `size` px (default 34; Просторная 38), `border-radius: $radius-circle`, `flex-shrink: 0`,
иконка внутри `pi` соответствующего канала (`font-size: size * 0.42`).

Фон круга — **бледная заливка цвета канала** (`color-mix`), сам значок — насыщенный цвет канала.
Цвета берём из **уже существующей палитры `ChannelKindTag`** (не вводим новых):

| kind | иконка | цвет значка (light) | цвет значка (dark) | фон круга |
|------|--------|---------------------|--------------------|-----------|
| `tg` | `pi-telegram` | `$blue-700` | `var(--p-blue-400)` | `color-mix(in srgb, {цвет} 14%, $surface-card)` |
| `wa` | `pi-whatsapp` | `$green-700` | `var(--p-green-400)` | тот же паттерн |
| `email` | `pi-envelope` | `$surface-600` | `var(--p-surface-500)` | тот же паттерн |
| `web_form` | `pi-globe` | `$primary-color` | `var(--p-primary-color)` | тот же паттерн |
| `api` | `pi-code` | `$orange-700` | `var(--p-orange-400)` | тот же паттерн |

> Иконка `web_form` в мокапе `pi-file-edit`, но в реальном `ChannelKindTag` — `pi-globe`. **Берём
> `pi-globe`** (консистентность с уже задеплоенным тегом). `api`-иконка мокапа = `pi-code` (совпадает).
> `color-mix` с `$surface-card` даёт корректный бледный фон в ОБЕИХ темах автоматически (в dark
> подмешивается navy-card). Реализуй фон через `var(--mg-surface-card)` (CSS custom property) —
> `color-mix` требует CSS-переменную, не SCSS-переменную. Паттерн уже есть в
> `taskKindColors.ts::taskKindChipStyle`.

**Обоснование `color-mix` вместо готовых `$blue-100`:** мокап рисует именно 14%-заливку на фоне
карточки, а не сплошной `$blue-100`. Оба варианта допустимы; предпочтителен `color-mix`, т.к.
сохраняет один источник цвета канала. Если DS-lint заругается — фолбэк на пары `$blue-100/900`
как в `ChannelKindTag`.

---

## 5. DealChip (`InboxDealChip`) — три состояния

Принимает `msg: InboundMessage`, эмитит `reprocess: [id]`, проп `pending: boolean`.

| routing_status + deal | Вид | Действие |
|-----------------------|-----|----------|
| `routed` + `target_deal_id` | `<i pi-briefcase>#{id}` зелёным (`$green-700`, dark `var(--p-green-400)`) + `pi-check-circle` (если `target_deal_created`, tooltip «Создана этим сообщением») | клик → RouterLink `/deals/{id}` (`@click.stop`) |
| `dedup` + `target_deal_id` | `<i pi-link>#{id}` синим (`$blue-700`, dark `var(--p-blue-400)`) | клик → RouterLink `/deals/{id}` (`@click.stop`) |
| `failed` | **кнопка-пилюля** «Разобрать» (outline red): высота 24, `border: 1px solid $red-300`, текст `$red-700`, иконка `pi-refresh` (или `pi-spin pi-spinner` при `pending`), `border-radius: $radius-pill` | клик (`@click.stop`) → emit `reprocess` (см. §6) |
| прочее (нет deal, не failed) | «—» `var(--p-text-muted-color)` | — |

> В строке списка `InboxDealChip` идёт справа от превью текста. В meta-grid читалки состояние
> «Сделка» дублирует ту же логику текстом «Сделка #{id}» / «не привязана» (существующий вид
> `InboxDetailDialog` meta-cell «Сделка» — переносим 1:1).

---

## 6. Failed-баннер + reprocess (reroute — существующий механизм)

Failed-письмо в читалке показывает red-баннер над meta-grid:
- контейнер: `background: $red-50` (dark: см. ниже), `border: 1px solid $red-300`,
  `border-radius: $radius-md`, `padding: $space-3`, `d-flex; gap: $space-3; align-items: flex-start`.
- иконка `pi-exclamation-triangle` `$red-700`.
- текст: заголовок «Сообщение не разобрано автоматически» + подсказка «Нажмите «Создать сделку»…».
- кнопка справа: «Создать сделку» — `Button severity="danger" icon="pi pi-refresh"
  :loading="pending"`.

Реализация через **существующий `Message severity="error"`** ИЛИ кастомный блок. Мокап рисует
кастомный блок с кнопкой внутри — предпочтительно кастом (Message не даёт удобно встроить action-
кнопку). Токены red в dark: `Message severity="error"` уже адаптирован; для кастома —
`$red-50`/`$red-300`/`$red-700` через theme-reactive, в dark добавить `.app-dark &`-ветку на
собственном scoped-элементе (charter: живой паттерн).

**Механизм reprocess (без изменений backend):**
- `POST /api/inbox/{id}/reroute` → возвращает обновлённый `InboundMessage`.
- Перед вызовом — **ConfirmDialog** (`confirmReprocess` из текущего composable — сохраняем; это
  подтверждение «Переобработать сообщение?»). Кнопка в баннере И «Разобрать» в строке обе идут
  через `confirmReprocess`.
- После: если `routing_status !== 'failed'` → success-toast «Сообщение разобрано. Сделка #{id}
  {создана|привязана}»; если остался `failed` → **warn-toast** (не error) «не удалось разобрать»
  (текущее поведение — сохраняем; reroute-фейл информационный, не ошибка).
- spinner: на строке — только на её кнопке (`currentReprocessId`); в баннере — `:loading`.

> Терминология: в UI-лейблах мокапа кнопка называется **«Создать сделку»** (баннер) и
> **«Разобрать»** (чип строки). Текущий код зовёт это «Переобработать». **Обновляем лейблы** на
> мокапные (см. §9 i18n: `reprocess.button` → «Создать сделку», новый `dealChip.reprocess` →
> «Разобрать»). Confirm-тексты оставляем («Переобработать сообщение?») — они про механику.
> См. ОВ-5.

---

## 7. Плотность + segmented (реализуемо)

**Плотность Обычная/Просторная:** влияет на `padding` строки списка и `size` ChannelDot (34→38).
Источник значения — **существующий density-store `mgcrm_density`** (введён в MSales 2.0, токен
`--mg-row-py`). Переиспользуем: если density='Просторная' → строка `$space-4`, dot 38; иначе
`$space-3`, dot 34. НЕ вводим локальный density-toggle на странице — плотность глобальная
(из настроек). *В мокапе density — tweak-переключатель; в проде это глобальная настройка.*
> Если глобальный density-store не покрывает Inbox — см. ОВ-3.

**Segmented «Непрочитанные / Все»:** `SelectButton` в шапке (reuse текущего). Управляет
`filters.unreadOnly`. Мапится на `params.unread = true|undefined`.

---

## 8. Данные — маппинг каждого элемента на текущий API

Тип-контракт: `InboundMessage` (`front/src/api/inbox.ts`). **Всё, что нужно СРЕЗУ A, в API уже
есть.** ГЭПы ниже — только про элементы, которые мы и так вынесли в СРЕЗ B/C.

| UI-элемент (мокап) | Поле API / метод | Есть? | Дефолт при отсутствии |
|--------------------|------------------|-------|----------------------|
| ChannelDot цвет/иконка | `channel.kind` (`tg\|wa\|email\|web_form\|api`) | ✅ | — |
| Имя отправителя | `from_name` | ✅ | fallback `from_identifier`, затем «—» |
| Идентификатор | `from_identifier` | ✅ | «—» |
| Тема | `subject` | ✅ (nullable) | скрыть блок «Тема» |
| Превью текста / текст | `body` | ✅ (nullable) | «(пусто)» курсивом |
| Время (относит.) | `received_at` | ✅ | — (формат: мин/ч/вчера/ДД.ММ, Asia/Dubai — текущий helper) |
| Полная дата (tooltip / meta «Получено») | `received_at` | ✅ | — |
| unread-точка / read-стиль | `read_at` (null = unread) | ✅ | — |
| Тогл прочитано/непрочитано | `POST /inbox/{id}/read` · `POST /inbox/{id}/unread` | ✅ | — (оптимистично + revert, как сейчас) |
| DealChip #deal | `target_deal_id` + `routing_status` | ✅ | «—» |
| «Создана этим сообщением» ✓ | `target_deal_created` | ✅ | скрыть галочку |
| Статус маршрутизации tag | `routing_status` (`routed\|dedup\|failed`) | ✅ | — |
| failed-баннер + «Создать сделку» | `routing_status==='failed'` + `POST /inbox/{id}/reroute` | ✅ | — |
| Raw payload аккордеон | `raw_payload` + гейт admin/director (`canViewRawPayload`) | ✅ | скрыть аккордеон |
| Канал в meta (`channel.name`) | `channel.name` | ✅ | — |
| unread-badge в шапке | `inboxStore.unreadCount` (`GET /inbox/unread-count`) | ✅ | 0 |
| Поиск | `params.q` (`q`) | ✅ | — |
| Фильтр канала (чипы) | `params.channel` (ChannelKind) | ✅ | — |
| Папка «Входящие» (all) | без status-фильтра | ✅ | дефолт |
| Папка «Не разобрано» (failed) | `params.routing_status='failed'` | ✅ | — |
| Папка «В сделках» (deals) | `params.has_deal=true` | ✅ | — (см. ниже) |
| Пагинация «Показано N из M» | `meta.total` / `meta.current_page` / `meta.last_page` | ✅ | — |

**Уточнение по папке «В сделках»:** API поддерживает `has_deal: boolean`. Папка «В сделках» =
`has_deal=true` (routed + dedup). Это чище, чем городить `routing_status` OR. Подтверждено в
`InboundMessageListParams`. **Гейт:** три папки в СРЕЗ A мапятся:
- `all` → без status/has_deal.
- `failed` → `routing_status='failed'` (совпадает с текущим `failedQuick`).
- `deals` → `has_deal=true`.
Взаимоисключающие (одна активная папка за раз), как радио. Плюс независимо — чип канала
(`channel`) и `unread`-segmented.

### ГЭПы (честные дефолты) — всё в СРЕЗ B/C, в A НЕ реализуем

| ГЭП | Поле, которого нет | Решение в СРЕЗ A |
|-----|--------------------|------------------|
| **ГЭП-1: звёзды/помеченные** | нет `starred` в `InboundMessage` | Кнопку-звезду в строке и в toolbar читалки **не рендерим**. (СРЕЗ B, нужен backend `starred` + `POST /inbox/{id}/star`.) |
| **ГЭП-2: «Важные»** | нет `important` | Папку/флаг не рендерим. (СРЕЗ B.) |
| **ГЭП-3: snooze/«Отложенные»** | нет `snoozed_until` | Не рендерим. (СРЕЗ B, нужен backend snooze.) |
| **ГЭП-4: per-folder счётчики** | нет count-эндпоинта по папкам/каналам | Чипы папок/каналов **без числовых бейджей**. (СРЕЗ B, нужен `GET /inbox/counts`.) Есть только глобальный `unread-count` для шапки — его показываем. |
| **ГЭП-5: date-range в панели фильтров** | API поддерживает (`date_from/to`), но по решению — B | Поле «Дата получения» в панели фильтров **не рендерим** в A. Технически готово, вынесено для чистоты. См. ОВ-4. |
| **ГЭП-6: исходящая почта** | нет сущности sent/drafts/spam/trash + «Написать» | Папки и кнопка «Написать» **не рендерим**. (СРЕЗ C.) |

---

## 9. Состояния (loading / empty / error)

### Список (`InboxList`)
- **loading:** 8 skeleton-строк (как сейчас): круг-скелетон 34px (ChannelDot) + строка имени +
  строка превью. `Skeleton shape="circle" size="34px"` + `Skeleton height="16px"`.
- **empty (обычный):** `pi pi-inbox` `$font-size-icon-lg` opacity 0.4 + заголовок «Входящих нет» +
  подсказка + (если активны фильтры) ссылка-кнопка «Сбросить фильтры». Reuse текущих ключей
  `inbox.empty.title/body`.
- **empty (папка «Не разобрано» пуста):** `pi pi-check-circle` `$green-500` (dark
  `var(--p-green-400)`) opacity 0.8 + «Все входящие разобраны» + «Сообщений без сделки не найдено».
  Reuse `inbox.empty.failedTitle/failedBody`.
- **error:** `Message severity="error"` «Не удалось загрузить входящие.» + кнопка «Попробовать
  снова» (retry → `fetchMessages`). Reuse `inbox.error.*`.

### Читалка (`InboxReadingPane`)
- **empty (ничего не выбрано):** центр-заглушка `pi pi-envelope` `$font-size-icon-lg` opacity 0.5
  + «Выберите сообщение» + «Кликните письмо слева, чтобы прочитать его и разобрать в сделку».
- **loading (грузится detail):** meta-grid из 6 skeleton-ячеек (label 10px + value 16px) — как
  сейчас в диалоге.
- **error (detail не загрузился):** `Message severity="error"` «Не удалось загрузить сообщение…».
  Reuse `inbox.detail.loadError`.

### Toast (reprocess)
- success → `severity: 'success'`, warn (остался failed) → `severity: 'warn'`, ошибка сети →
  `severity: 'error'`. Reuse текущих ключей `inbox.reprocess.*`.

---

## 10. Interactions (элемент → действие → результат → endpoint)

| Элемент | Действие | Результат | Endpoint |
|---------|----------|-----------|---------|
| Строка письма | click / Enter / Space | `selectedId=id`; на `<lg` → `mobileView='detail'`; грузит detail; **не** авто-читает | `GET /api/inbox/{id}` |
| Строка письма | (после открытия) | read-статус НЕ меняется автоматически (сохраняем поведение) | — |
| Тогл «Прочитано/Непрочитано» (toolbar читалки) | click | оптимистично меняет `read_at`, badge±1, revert при ошибке | `POST /api/inbox/{id}/read` \| `/unread` |
| DealChip #deal (routed/dedup) | click (`@click.stop`) | `router.push('/deals/{id}')` | — |
| DealChip «Разобрать» (failed, строка) | click (`@click.stop`) | ConfirmDialog → reroute | `POST /api/inbox/{id}/reroute` |
| Кнопка «Создать сделку» (failed-баннер) | click | ConfirmDialog → reroute + toast | `POST /api/inbox/{id}/reroute` |
| SelectButton «Непроч./Все» | change | `filters.unreadOnly`; page→1; refetch | `GET /api/inbox?unread=` |
| Поиск | ввод (debounce 300ms) | `q`; page→1; refetch | `GET /api/inbox?q=` |
| Кнопка-триггер фильтров (⚙) | click | открывает Popover-панель (папки+каналы) | — |
| Чип папки (Входящие/Не разобр./В сделках) | click | выбор папки (радио); page→1; refetch | `GET /api/inbox?routing_status=` / `?has_deal=true` |
| Чип канала | click | toggle `channel`; page→1; refetch | `GET /api/inbox?channel=` |
| «Сбросить фильтры» | click | сброс к дефолту (unread=true, папка=all, канал=null, q='') | — |
| «Обновить» | click | refetch списка + unread-count | `GET /api/inbox`, `/unread-count` |
| Пагинация | page-change | `currentPage`; refetch | `GET /api/inbox?page=` |
| Raw payload аккордеон | click (admin/director) | раскрывает `<pre>` JSON | — |
| «‹ К списку» (toolbar, `<lg`) | click | `mobileView='list'` | — |

---

## 11. Токены и компоненты (сводка)

- **Отступы:** header `$space-3 $space-5`; body `$space-4 $space-5`; строка `$space-3 $space-4`
  (Обычная) / `$space-4 $space-4` (Просторная); читалка-тело `$space-4 $space-5`; meta-grid gap
  `$space-4 $space-6`.
- **Радиусы:** панели `$radius-lg`; иконка-плашка/баннер/аккордеон `$radius-md`; поисковая-пилюля
  `$radius-pill`; чипы-фильтры/DealChip-failed `$radius-pill`; unread-точка/ChannelDot
  `$radius-circle`; ChannelKindTag `$radius-sm`.
- **Поверхности:** панели/строки — `$surface-card`; hover — `var(--mg-surface-hover)`; выбранная
  строка — `$primary-50` (dark `var(--p-surface-200)`); границы — `$surface-200` (dark
  `var(--p-surface-200)`).
- **Текст:** заголовки/значения — `var(--p-text-color)`; muted/labels — `var(--p-text-muted-color)`;
  идентификатор — `$font-family-mono`. Unread имя/тема — `$font-weight-semibold` + `$surface-900`
  (dark `var(--p-surface-900)`); read — `$font-weight-normal` + `$surface-600` (dark
  `var(--p-surface-600)`). **Не использовать `surface-200/300` для текста в dark** (dark-on-dark,
  charter).
- **Акцент:** `$primary-color` / `var(--p-primary-color)` — точка, левая рамка, web_form-dot,
  segmented-active. В dark светлеет автоматически (`#172747 → #4C7DF0`) — литерал не пишем.
- **Каналы:** цвета из палитры `ChannelKindTag` (`$blue-*/$green-*/$orange-*/$surface-*/$primary`)
  + `color-mix` для фона dot через `var(--mg-surface-card)`.
- **Статусы (routing):** `routed`→`success`, `dedup`→`info`, `failed`→`danger` (`Tag severity`).
- **Тени:** только `$shadow-sm` (панели), `$shadow-lg` (Popover-панель фильтров, Toast).
- **PrimeVue:** `SelectButton`, `Popover`, `Message`, `Accordion`(+Panel/Header/Content),
  `Skeleton`, `Badge`, `Paginator`, `Button`, `Tag`, `InputText`, `IconField`+`InputIcon`,
  `ConfirmDialog` (глобальный из DefaultLayout — НЕ монтировать локальный, charter/memory).
- **Обе темы:** все правила через theme-reactive токены; dark-override — только `.app-dark &` на
  собственном scoped-элементе (charter: 3 мёртвых паттерна запрещены). ChannelKindTag и
  InboxMessageRow уже содержат корректные dark-ветки — переносим паттерн.

---

## 12. i18n-ключи

Namespace `inbox.*` уже существует (`front/src/locales/ru.json` ~L4608, `en.json`). Переиспользуем
максимум; ниже — **дельта** (новое + переименования). Полные существующие ключи (`filters.*`,
`columns.*`, `routingStatus.*`, `channelKind.*`, `empty.*`, `error.*`, `detail.*`, `reprocess.*`)
— оставляем, кроме отмеченных изменений.

```json
{
  "ru": {
    "inbox": {
      "page": { "title": "Почта", "refresh": "Обновить" },
      "folders": {
        "label": "Папки",
        "all": "Входящие",
        "failed": "Не разобрано",
        "deals": "В сделках"
      },
      "channels": { "label": "Каналы" },
      "filters": {
        "searchPlaceholder": "Поиск в почте",
        "openFilters": "Фильтры",
        "done": "Готово",
        "reset": "Сбросить фильтры"
      },
      "reading": {
        "emptyTitle": "Выберите сообщение",
        "emptyHint": "Кликните письмо слева, чтобы прочитать его и разобрать в сделку",
        "backToList": "К списку",
        "channelAndId": "{channel} · #{id}"
      },
      "dealChip": {
        "reprocess": "Разобрать",
        "created": "Создана этим сообщением",
        "none": "не привязана"
      },
      "detail": {
        "failedBannerTitle": "Сообщение не разобрано автоматически",
        "failedBannerHint": "Нажмите «Создать сделку» — система попытается создать сделку по этому сообщению.",
        "markRead": "Прочитано",
        "markUnread": "Непрочитано"
      },
      "reprocess": {
        "button": "Создать сделку"
      },
      "list": {
        "shownOf": "Показано {shown} из {total}"
      }
    }
  },
  "en": {
    "inbox": {
      "page": { "title": "Mail", "refresh": "Refresh" },
      "folders": { "label": "Folders", "all": "Inbox", "failed": "Unrouted", "deals": "In deals" },
      "channels": { "label": "Channels" },
      "filters": { "searchPlaceholder": "Search mail", "openFilters": "Filters", "done": "Done", "reset": "Reset filters" },
      "reading": { "emptyTitle": "Select a message", "emptyHint": "Click a message on the left to read it and route it into a deal", "backToList": "Back to list", "channelAndId": "{channel} · #{id}" },
      "dealChip": { "reprocess": "Route", "created": "Created from this message", "none": "not linked" },
      "detail": { "failedBannerTitle": "Message was not routed automatically", "failedBannerHint": "Click “Create deal” — the system will try to create a deal from this message.", "markRead": "Read", "markUnread": "Unread" },
      "reprocess": { "button": "Create deal" },
      "list": { "shownOf": "Showing {shown} of {total}" }
    }
  }
}
```

**Переименования (обновить существующие значения):**
- `inbox.page.title`: «Входящие» → **«Почта»** (nav-пункт `nav.inbox` оставить «Входящие» —
  это раздел сайдбара; заголовок страницы — «Почта». См. ОВ-1).
- `inbox.reprocess.button`: «Переобработать» → **«Создать сделку»**.
- `inbox.filters.searchPlaceholder`: → **«Поиск в почте»**.
- Confirm-ключи (`reprocess.confirmTitle/Body/Accept/Reject`) — **не трогаем** (про механику).

---

## 13. Референс-экраны
- Мокап: `design-handoff/redesign/mail.html` (двухпанельный layout, ChannelDot, DealChip,
  панель фильтров, читалка).
- Текущий код: `front/src/pages/InboxPage/*` (list/row/detail/filter — паттерны переносим).
- Двухпанельный master-detail в проде: `front/src/pages/SettingsPage` (рельс+detail),
  `EntityFilesTab.vue` (двухпанельность папки+файлы) — как складывать панели.
- ChannelKindTag: `front/src/components/inbox/ChannelKindTag.vue` (палитра каналов).
- Density-store `mgcrm_density` (MSales 2.0) — источник плотности.

---

## 14. Acceptance-чеклист для qa-tester

**Функционал (обе темы):**
1. Двухпанельный layout ≥ lg: список слева, читалка справа; клик по строке грузит письмо в правую
   панель, список НЕ пропадает.
2. `< lg`: видна одна панель; клик по строке → читалка на весь экран + кнопка «‹ К списку»
   возвращает к списку.
3. unread-точка видна только на непрочитанных; read-строки визуально тусклее (имя/тема нормальным
   весом, приглушённый цвет).
4. ChannelDot: 5 каналов дают 5 разных цветов (tg синий, wa зелёный, email серый, web_form navy,
   api оранжевый) — В ОБЕИХ темах читаемо (значок не сливается с фоном круга).
5. DealChip: routed → зелёный `#id` briefcase (+ ✓ если created); dedup → синий `#id` link;
   failed → red-пилюля «Разобрать»; нет deal и не failed → «—». Клик по `#id` ведёт на `/deals/{id}`.
6. Тогл «Прочитано/Непрочитано» в toolbar читалки меняет статус оптимистично, badge в шапке ±1,
   при обрыве сети — откат.
7. failed-письмо: red-баннер в читалке + кнопка «Создать сделку»; клик → ConfirmDialog → reroute;
   success-toast зелёный / warn-toast (если остался failed) / error-toast (сеть).
8. Панель фильтров (клик ⚙): 3 папки (Входящие/Не разобрано/В сделках) как радио + 5 чипов каналов
   toggle; «Готово» закрывает, «Сбросить» возвращает дефолт.
9. Папка «Не разобрано» → в списке только failed; «В сделках» → только с `target_deal_id`.
10. Segmented «Непрочитанные/Все» фильтрует по read-статусу.
11. Поиск (debounce) фильтрует по имени/почте/теме/тексту.
12. Raw payload аккордеон виден ТОЛЬКО для admin/director; у manager — скрыт.
13. Пагинация: «Показано N из M» + переключение страниц.
14. Empty: пустой inbox → `pi-inbox`; пустая «Не разобрано» → зелёный `pi-check-circle` «Все
    входящие разобраны»; читалка без выбора → `pi-envelope` «Выберите сообщение».
15. Loading: skeleton списка (8 строк) + skeleton meta-grid читалки.
16. Error: список не загрузился → Message + «Попробовать снова»; detail не загрузился → Message.

**DS-гейт (charter §6):**
17. `npm run lint:ds` зелёный (0 литеральных hex/px вне бренд-инвариантов).
18. computed-styles в light И dark: текст читаем, нет dark-on-dark (unread-имя в dark — светлый
    `surface-900`, не `surface-200`).
19. Скроллбары скрыты в теле списка и читалки, прокрутка работает.
20. Единственный акцент в dark — через `var(--p-primary-color)` (точка/рамка светлеют к `#4C7DF0`).

**Регресс:**
21. `GET /api/inbox` фильтры (unread/channel/routing_status/has_deal/q/page) отрабатывают.
22. `unread-count` badge в сайдбаре и в шапке синхронны после read/unread/reprocess.
23. Отсутствуют элементы СРЕЗА B/C: НЕТ звёзд, НЕТ «Важные»/«Отложенные», НЕТ папок sent/drafts/
    spam/trash, НЕТ кнопки «Написать», НЕТ per-folder счётчиков на чипах, НЕТ date-range в панели.

---

## 15. Открытые вопросы

1. **[ОВ-1]** Заголовок страницы — «Почта» (мокап), но nav-пункт сайдбара сейчас «Входящие»
   (`nav.inbox`). Оставляем nav как есть, меняем только H1 страницы на «Почта»? Или
   переименовываем и nav-пункт? *Дефолт: H1 = «Почта», nav = «Входящие».*
2. **[ОВ-2]** Использовать `PageHeader` (charter-канон для листовых страниц) или кастомный header?
   Шапка «Почты» насыщеннее (segmented + поисковая-пилюля-с-панелью + badge). *Дефолт: кастомный
   header (обосновано в §2), т.к. PageHeader не рассчитан на этот toolbar.* Подтвердить.
3. **[ОВ-3]** Плотность: брать из глобального `mgcrm_density` (MSales 2.0) или добавить локальный
   toggle на странице? *Дефолт: глобальный store, без локального toggle.* Если store не даёт
   реактивно на Inbox — уточнить.
4. **[ОВ-4]** date-range: API готов (`date_from/to`), но вынесен в СРЕЗ B ради чистоты A. Оставить
   в B или вернуть в A (панель фильтров получает поле «Дата получения»)? *Дефолт: B.*
5. **[ОВ-5]** Терминология кнопки reprocess: мокап — «Создать сделку» (баннер) / «Разобрать»
   (чип), текущий код — «Переобработать». Подтвердить смену лейблов (confirm-тексты оставляем).
   *Дефолт: меняем лейблы кнопок, confirm-тексты не трогаем.*
6. **[ОВ-6]** Роль-гейт страницы: nav помечен `adminOnly: true` (admin+director), но backend-
   комментарий говорит `inbox.manage` = admin/director/**manager**. Кто реально видит «Почту»?
   *Не блокер СРЕЗА A (гейт наследуется от текущего роута), но зафиксировать для консистентности.*
   → Требуется подтверждение (не designer-решение — продуктовое/RBAC).

**Требуется backend (для СРЕЗА B/C, НЕ для A):**
- `starred`/`important`/`snoozed_until` в `InboundMessage` + `POST /inbox/{id}/star|snooze` (B).
- `GET /inbox/counts` — счётчики по папкам/каналам для бейджей на чипах (B).
- Сущность исходящих (sent/drafts/spam/trash) + `POST /inbox/compose` (C).
