# Аудит визуальной консистентности фронта (2026-07)

**HEAD:** `601ea9f` · **Дата:** 2026-07-04 · **Прод:** `mgcrm.macroglobal.tech`
**Тип:** сводный аудит визуальной консистентности `front/src/pages/**` — экстракция канона из эталонов юзера + сплошной read-only код-суип 7 DS-аудиторов + живой браузерный проход обеих тем.
**Охват:** 7 модульных зон (deal-page · entity-cards · tasks+cabinet · analytics-hub · catalog+docs · onboarding+auth · automation+shell). **169 находок** (24 high / 83 medium / 62 low), сгруппированных в **9 системных семейств**.

> Этот документ — **аудит «ДО»** и **карта дрейфа**, НЕ план исполнения. §5 — предложение ремонта волнами (юзер решает объём и порядок). Код в рамках этого аудита не менялся.

---

## §1. Методика и охват

**Эталон визуала — экраны, названные юзером «тем же продуктом»:** Почта (`InboxPage`), Сделки (`DealsPage`), Настройки (`SettingsPage`), Контакты (`ContactsPage`) + дизайн-система (`docs/designer-charter.md`, skill `.claude/skills/macroglobal-design/`, токены `front/src/theme`). Из них извлечён **канон** (16 паттернов + 20 анти-паттернов, приложение A) — источник истины «как должно выглядеть».

**Три измерения аудита:**
1. **Канон-экстракция** — из эталонов юзера + charter + skill + `front/src/theme` выведены 16 паттернов (шапка-тулбар, segmented, кнопки, два канона фильтров, диалоги, глобальные оверлеи, таблицы, виджеты/KPI, теги/статусы, empty/loading, формы, каркас настроек, иконки/деньги/типографика, dark-законы) и 20 анти-паттернов (grep-ловушки: PageHeader, голые `<button>`, ручные hex, `show-gridlines`, мёртвые dark-селекторы, `rgba($…)`, локальные Toast/ConfirmDialog, Tailwind-классы, `toLocaleString()` без `ru-RU` и т.д.).
2. **7 код-аудиторов** (read-only, никто не правил код) — каждый прошёл свою зону файл-за-файлом, сверяя разметку и scoped-стили с каноном; каждая находка привязана к `file:line` + процитировано нарушенное правило эталона.
3. **Живой браузерный проход обеих тем** — визуальный смоук на проде (light + dark) для верификации, что код-находки видны глазом, и для отлова того, что код-суип пропустил (несуществующие иконки, синяя browser-ссылка в dark, `?`-аватар, расхождение empty-состояний между близнецами).

Каждая находка имеет **severity** (high — молча сломано / нечитаемо / нарушение закона; medium — заметный дрейф поколения; low — косметика/швы) и **effort** (S / M / L).

---

## §2. Гештальт-вердикт + карта поколений

**Ядро 2.0 — цельное.** Почта, Сделки, Контакты+карточки сущностей, Настройки, Дашборд/Аналитика, Задачи, Кабинет менеджера ощущаются одним продуктом: однорядные тулбары вместо PageHeader, канбан с color-mix-тинтами, карточные подложки таблиц, PrimeVue-диалоги со стандартным `#footer`, деньги через `formatCurrency`, только PrimeIcons, глобальные Toast/Confirm, высокая токен-дисциплина с легализованными отступлениями.

**Периферия — предыдущее поколение того же бренда.** Каталог/документы, онбординг, автоматизации, канвас воронки, карточка шаблона документа — «тот же бренд, но продукт на поколение старше»: легаси PageHeader, всегда-видимые инлайн-фильтр-бары, голые таблицы без подложек, самодельные контролы вместо PrimeVue, массовая опора на **несуществующие Bootstrap-утилиты**, и систематический **dark-долг** пре-navy палитры.

### Карта поколений (экран → поколение → суть)

| Экран / зона | Поколение | Суть дрейфа |
|---|---|---|
| **Почта** (InboxPage) | **SAME-GEN** ✅ | Эталон паттерна А (пилюля+Popover); глобальные оверлеи |
| **Сделки — список/канбан** (DealsPage) | **SAME-GEN** ✅ | Эталон тулбара, паттерна Б фильтров, карточной таблицы с зеброй |
| **Контакты** (ContactsPage) | **SAME-GEN** ✅ | Эталон toolbar+segmented+фильтр-бейдж; non-scoped dark-hover |
| **Настройки** (SettingsPage) | **SAME-GEN** ✅ | Легальный остаток PageHeader (санкционирован каноном) |
| **Дашборд/Аналитика — хаб** (HubToolbar, табы Планы/Реестр/График/Рейтинг) | **SAME-GEN** ✅ | HubToolbar сам эталон; widget-card, PctTag, опаковые frozen |
| **Аналитика — таб «Обзор»** | **MIXED** ⚠️ | Legacy `DashboardToolbar` (Card+инлайн-Select'ы) ПОВЕРХ нового `AnalyticsFilterBar` → две разностилевые фильтр-строки с дублями пикеров |
| **Кабинет менеджера** (ManagerCabinetPage) | **SAME-GEN** ✅ | Свежайший DS2; отклонения — `pi-sigma` (несущ.) + санкц. градиент-шкалы |
| **Задачи** (MyTasksPage) | **MIXED** ⚠️ | Силуэт нового поколения (тулбар/канбан/Dialog-окно), но плотность самоделок высокая + системный dark-долг статических `$primary-900`; `?`-аватар на карточке |
| **Карточки сущностей** (Contact/Company Page) | **SAME-GEN** ✅ | Entity Card 2.0 по спеке; дрейф на швах (money-format, тинты, dark-границы) |
| **Каталог — товары** (Products/Product) | **MIXED** ⚠️ | Ближе к канону (карточные подложки, зебра, drawer), но PageHeader + инлайн-фильтр-бар + голые заголовки таблиц |
| **Документы + Шаблоны** (Documents/Templates/Document) | **OLD-GEN** ❌ | PageHeader, инлайн-фильтры, самодельные статус-бейджи, chart-деньги в таблицах, **массово мёртвые Bootstrap-утилиты** |
| **Карточка шаблона документа** | **OLD-GEN** ❌ | Безкарточный фон, нативный file-input, дефолтные заголовки |
| **Онбординг — 5 list/hub-экранов** (Курсы/Назначения/HR-прогресс/Admin) | **OLD-GEN** ❌ | PageHeader, инлайн-фильтры, голые таблицы, свой KPI-рецепт, **массово мёртвые full-Bootstrap-утилиты** (.card/text-*/fw-*/gap-*), синяя browser-ссылка в dark, разнобойная пагинация `«« ‹ 1 › »»` |
| **Плеер курса / билдер курса** | **MIXED** ⚠️ | Формы канонические, но квиз-карточка на мёртвом `.card`, активный урок light-on-light в dark |
| **Логин / публичная лид-форма** | **SAME-GEN** ✅ | Свежий редизайн, почти эталон (кроме `$primary-color`-ссылок в dark) |
| **Автоматизации — 2 страницы** (Runs/List) | **OLD-GEN** ❌ | PageHeader, инлайн-фильтры, голые таблицы, load-more вместо пагинатора |
| **Канвас воронки** (PipelineCanvas) | **MIXED/OLD** ❌ | Целиком на **мёртвых PrimeVue-3 токенах** (--p-surface-card/-border/-ground) → карточный рецепт нод не срабатывает; тулбар без икон-плитки |
| **Оболочка** (AppSidebar, Orbita, AccountMenu) | **SAME-GEN** ✅ | После rgba/dead-selector чисток близко к канону; хвост — дубль флайаута уведомлений + статический `$primary` |

**Две сквозные оси дрейфа периферии:** (1) **поколение шасси** — PageHeader/инлайн-фильтры/голые таблицы вместо тулбар-канона; (2) **dark-долг** — пре-navy палитра (`surface-100/200` как текст, `surface-700/800` как фоны, статический `$primary-900` как акцент), которая после инверсии navy-шкалы даёт невидимый текст и яркие светлые пятна в тёмной теме.

---

## §3. Системные семейства (кросс-модульно)

Это ядро отчёта. Находки сгруппированы не по модулям, а по **корневой причине** — так виден масштаб и точки единого фикса.

### (а) Фантомные Bootstrap-утилиты — стили молча не применяются 🔴

**Суть:** в бандл подключён **только `bootstrap-grid.min.css`** (сетка `row`/`col-*`/`d-flex`), но docs- и onboarding-модули массово свёрстаны на full-Bootstrap-утилитах, которых в бандле **нет** (проверено: 0 вхождений `gap`, нет `.w-100`/`.flex-1`/`.card`/`text-*`/`fw-*`/`ratio-*`). Классы инертны — muted/красные/жирные/ширины/центрирование/зазоры **молча не рендерятся**. Вдобавок глобальный reset `*{font-weight:normal}` в `base.scss:7` гасит и `<strong>`.

Мёртвые классы: `gap-1..4`, `w-100`, `flex-1`, `.card`, `text-center`, `text-secondary`, `text-danger`, `text-muted`, `fw-medium/-semibold/-bold`, `text-end`, `border-*`, `rounded`, `ratio-16x9`, `py-6`, `overflow-hidden`.

**Затронутые файлы (docs):** `DocumentActionBar`, `CreateTemplateDialog`, `CreateDocumentDialog`, `DocumentContextTab`, `DocumentItemsTab`, `GenerateDocumentDialog`, `TemplatesPage`, `DocumentsPage`, `DocumentRemarksTab`, `ApprovalPanel`, `DocumentMetaCard`.
**Затронутые файлы (onboarding):** `QuizQuestion` (`.card`), `OnboardingAssignmentsPage`, `HrProgressTable`, `MyCourseCard` (`text-danger`), `CreateCourseDialog`/`CourseSettingsCard`/`ModuleEditDialog`/`EditDeadlineDialog`/`LessonEditDrawer`/`QuizBuilderDrawer`/`AssignCourseDrawer`/`AiTutorDrawer` (`w-100`), `MyCoursesPage`/`MyOnboardingCertificatesPage`/`CertificateCard`/`CourseCompleteDialog`/`LessonViewQuiz` (`text-center`/`py-6`), `HrProgressTable`/`CourseStructureCard`/`ModulePanel`/`CourseAssignmentsCard` (`text-muted`), `LessonEditDrawer` (`ratio-16x9`/`border`/`rounded`), Skeleton-обёртки (`.card`/`overflow-hidden`).
**Затронутые файлы (automation):** `DryRunDrawer`, `AutomationRunsPage`, `AutomationListPanel`, `wizard/TriggerConfigStep`, `wizard/config/ChangeStageConfig` (`text-*`/`fw-*`/`gap-*`/`w-100`), `DecideDialog` (`text-danger` на req-звёздочке).

**Почему главное:** это единственное семейство, которое ломает UI **уже в light-теме** и **молча** — разметка выглядит правильной, а рантайм — нет. Фикс механический (замена на scoped-токены / локальный `.w-full` / `<span class="req">` / PrimeVue), но масштаб — ~40 файлов.

### (б) PageHeader-поколение vs тулбар-канон

**Суть:** канон list/hub-экрана = однорядный тулбар (икон-плитка 38×38 → h1 → subtitle-каунтер → spacer → segmented → фильтр/⋮/Create). Периферия сидит на легаси `<PageHeader>`.

**На PageHeader:** `DocumentsPage`, `ProductsPage`, `ProductPage`, `TemplatesPage`, `DocumentPage`, `DocumentsPage` (docs-фильтр); все 5 онбординг-list/hub (`OnboardingAdminCoursesPage`, `OnboardingAssignmentsPage`, `HrProgressPage`, `MyCoursesPage`, `MyOnboardingCertificatesPage`); обе страницы автоматизаций (`AutomationRunsPage`, `AutomationListPanel`).
**Легальные остатки (санкц. каноном):** `SettingsPage` + справочники до их редизайна.

### (в) Старые фильтр-бары vs пилюля/триггер+бейдж

**Суть:** канон — паттерн А (пилюля с Popover, Почта) или паттерн Б (кнопка-триггер + бейдж-каунтер + inline-панель, Сделки/Контакты). Периферия — устаревший инлайн-бар из дропдаунов прямо в шапке, без каунтера активных фильтров и без панели.

**Инлайн-фильтр-бары:** `ProductsPage` (поиск+3 Select+reset), `DocumentsFilterPanel` (всегда-видимая Card), `TemplatesPage`, онбординг (`CoursesFilterPanel`, `AssignmentsFilterPanel`, `HrProgressFilterPanel` — дубль), автоматизации (`AutomationRunsPage index:7`, `AutomationListPanel:10`).
**Особый случай — двойная фильтр-строка:** таб «Обзор» аналитики (`DashboardToolbar` legacy ПОВЕРХ нового `AnalyticsFilterBar`) — high-находка, две разностилевые строки с дублями пикеров воронки/менеджера на одном экране (транзиционный остаток по ТЗ §1.1, но единственный кусок старого поколения в свежем рестайле хаба).

### (г) Dark-долг инвертированной navy-шкалы

**Суть:** это самое массовое семейство. Пре-navy палитра неверна после инверсии шкалы (dark: `surface-100`=#111E38 card, `surface-200`=#172847 raised/border, `surface-700/800`=#B4C2DA/#C6D0E2 **светлый ТЕКСТ**, `surface-900`=#EAF0FA текст). Типовые ошибки:
- **`surface-100/200` как цвет текста** → текст цвета фона, невидим. (DealCreateForm, DealAddProductDialog, DealTabMain, DealProductRow, DealFieldGroup, DealContactsGroup — все high).
- **`surface-400/500` как muted-текст** → #3A4F78/#647294 тёмные чернила на тёмной карточке, контраст ~2:1 FAIL. (entity crm/InfoPanel high + ~25 файлов; deal-page muted-подписи; automation оверлеи; analytics `—`-плейсхолдеры; onboarding даты/сертификаты).
- **`surface-700/800` как фоны/бордеры/hover** → яркие светлые плиты и линии в dark. (MyTasksFilterPanel/QuickCreate high; StageEditorItem+визард; DealFieldGroup hover; channel-чипы; dark-бордеры карточных подложек по всем модулям).
- **Статический `$primary-900`/`$primary-color`/`$primary-50/200` как акцент/ссылка/тинт** → бренд-navy не светлеет до #4C7DF0, растворяется на #111E38 или остаётся светлой плашкой. (TasksTopBar икон-плитка high; TasksBulkBar счётчик; MyTasksPresetTabs акцент; WidgetFunnelTable won-бар high; TabOverview/WidgetGrid edit-chrome high; LoginPage ссылки high; CourseSidebar активный урок high; NotificationsButton «прочитать все»; AiTutorDrawer пузырь; QuizTimer/QuickActions иконки).

**Модули по glубине dark-долга:** deal-page (7 high — фикс-проход прошёл частично, «был surface-200 = invisible»-комментарии есть, но половина файлов не тронута), tasks+cabinet (3 high + систематический `$primary-900`), automation (crm/entity high + PipelineSettings light-pair), onboarding (3 high точечно, но больно). entity-cards и analytics-hub — уже с правильным приёмом в собственных эталонах (PlanMatrix → surface-600), но с недофикшенными хвостами.

### (д) Самодельные оверлеи/дропдауны/меню DealPage (и эхо в задачах/автоматизациях)

**Суть:** канон — PrimeVue `Menu popup` / `Popover` / `Select filter show-clear` / общий `SearchPicker`. DealPage несёт **минимум 5 самоделок** там, где даже соседние файлы модуля используют PrimeVue:
- owner/company-дропдауны (`DealTabMain:15` — абсолютный div + свой search + локальная `vClickOutside`, ~200 строк SCSS);
- product-search дропдаун (`DealAddProductDialog:51` — тиражирование запрещённого исключения);
- ⋮-меню на Teleport (`DealInfoHeader:71` — ручное позиционирование, z-index 9999, свой backdrop/Escape);
- второе ⋮-popover меню контакта (`DealContactsGroup:64`);
- `FeedSearchOverlay` — кастомная панель вместо Popover.

**Эхо в других модулях:** tasks entity-picker (`TasksQuickCreate:29`), automation `SearchPicker.vue` (третья самодельная реализация в базе). Плюс DealPage нативные `<input>`/`<select>`/`<textarea>` для суммы/валюты/причины (канон — InputNumber/Select/Textarea).

### (е) Мёртвые PrimeVue-3 токены канваса автоматизаций 🔴

**Суть:** вся канвас-зона построена на токенах, которых **нет ни в PrimeVue 4, ни в теме репо** — резолвятся в `unset`, карточный рецепт нод и hover не срабатывают в обеих темах. Комментарий `theme/scss/foundation/_colors.scss:80` прямо фиксирует, что `--p-surface-card` в PrimeVue не существует.

Мёртвые токены: `--p-surface-card`, `--p-surface-border`, `--p-surface-ground`, `--p-surface-overlay`, `--p-border-radius`, `--p-surface-hover`.
**Файлы:** `PipelineCanvas` (598,642,646,649,660,754…), `ToolPalette` (100,101,120,128,174,202), `StageNode` (75,76), `AutomationNode` (118,119,169), `AnchorNode` (50), `index.vue` (643,644,646,648), `PipelineListItem:153`, `StageSubstageItem:134`.

### (ж) Голые таблицы без карточных подложек/зебры

**Суть:** канон — DataTable в карточной подложке (`$surface-card` + `$radius-lg` + border + `$shadow-card` + `overflow:hidden`), опциональная зебра, uppercase/центрированные заголовки, кастомный пагинатор «Показано {from}–{to} из {total}».

**Голые таблицы:** entity полноразмерные табы (`ContactDealsTab:28`, `CompanyDealsTab:35`, `CompanyDocumentsTab:20`, `CompanyEmployeesTab:256`), docs (все таблицы модуля — дефолтные заголовки+sortable), онбординг (`OnboardingAdminCoursesPage:21`, `OnboardingAssignmentsPage:19` — ни подложки, ни зебры, ни uppercase-th), автоматизации (`AutomationRunsPage:71` + `AutomationListPanel:38` — без подложки, load-more вместо пагинатора).

### (з) Дубли money-format + разные пагинации + битые имена иконок

**Money-format (расхождение уже видимо):**
- **6+ локальных копий** `formatKopecks`/`formatMoney` в entity-cards (ContactPage, ContactDealsPanel, ContactDealsTab, CompanyDealsTab, CompanyMiniDeals…) — вместо `utils/currency`.
- Локальный `formatMoney` в `DealTabFinances:115` (своя таблица символов + конкатенация).
- **Уже разъехались форматы:** entity KPI-чип показывает «1.5M»/«250K» (`index.vue:728`) vs полный «1 200 000 ₽» на сестринской карточке; docs `DocumentItemsTab:37` — chart-форматтер «1,58 млн ₽» без валюты документа (default RUB, хотя валюта KZT).

**Пагинации:** канон — кастомный «Показано {from}–{to} из {total}» + per-page меню. Периферия — «Загрузить/Показать ещё» (AutomationRunsPage, catalog+docs), разнобойный `«« ‹ 1 › »»` (онбординг). Сортировка местами мертва: `MyTasksTable:78` sortable без `@sort`, все docs-таблицы sortable-проп без кастомных sort-кнопок.

**Битые имена иконок (рендерятся пустыми квадратами):**
- `pi-pencil-square` + `pi-arrow-right-circle` (нет в PrimeIcons; есть `pi-pencil`/`pi-arrow-circle-right`) — 5 файлов автоматизаций: `ToolPalette:71,74`, `AutomationNode:78,81`, `AutomationInlineCard:67,70`, `AutomationListPanel:164,167`, `wizard/ActionPickerStep:105,123`.
- `pi-sigma` (нет в пакете) — `MkSalaryTable:108` (Итого) и кабинет (упомянут в гештальте).

**Невалидные severity PrimeVue 4** (`warning`→должно быть `warn`, рендерится дефолтным primary): `ContactRelationsPanel:195` (инвестор-тег), `TerminationDocumentDrawer:156`, `WidgetDealsWithoutTasks:53`.

**Несуществующие токены палитр:** `--p-info-300` (`QuizBuilderDrawer:542` → бордер падает в currentColor), `--p-primary-color-rgb`/`--p-primary-500-rgb` (`HoldingTree:278,282`, `ContactRelationsPanel:417` → hardcoded fallback #172747 мимо brand-invariant).

### (и) Прочее из high/med-находок

- **Локальный `<ConfirmDialog>` в каждой карточке реквизита** (`RequisiteCard:80`, high) — прямое нарушение закона глобальных оверлеев: по экземпляру на КАЖДЫЙ реквизит + глобальный в DefaultLayout; в модуле сосуществуют 3 confirm-стратегии.
- **Самодельные аватары-инициалы** вместо `EntityAvatar` (получил dark-фикс в backlog #26): `DealTabMain:658`, `DealTabDocuments:758`, `MyTasksTable:303`, `TaskCard:514` — статический navy-круг сливается с #111E38 в dark (это и есть `?`-аватар на карточке задачи из визуального прохода).
- **Голые `<button>` для стандартных действий** (канон — PrimeVue Button): TasksTopBar create, TasksBulkBar (вкл. danger), entity «Привязать/Добавить связь/Показать ещё» (×4, 3 стиля одной кнопки на экране), MyTasksFilterPanel reset, ActivityFeed reset.
- **Самодельные чекбоксы** (3 копии, размеры 17/18px вразнобой) — `MyTasksTable:54`, `TasksBulkBar:32`, `TaskCard` — вместо PrimeVue Checkbox.
- **Диалоги без `:draggable="false"`** — все диалоги deal-page, entity-cards, automation таскаются мышью (канон — фикс.).
- **Два разных UI одного действия:** «переместить сделку» (эталонный DealsPage vs кастомный `MoveDealDialog` карточки); выбор цвета этапа (`CreateStageDialog` ColorPicker vs `StageEditDrawer` свотч-палитра).
- **Дубль файла:** `SidebarNotifications` — почти полная копия `Orbita/NotificationsButton` (~250 строк), поведение уже расходится, muted-баги ×2.
- **Голый file-input карточки шаблона** (визуальный проход) — нативный `<input type=file>` без карточного фона.
- **Расхождение not-found:** deals — красивый empty; products — голый `<Message>`; онбординг/automation empty-состояния без заголовка/hint/CTA (только иконка + абзац).
- **Санкц. градиенты** (запрещены брендом, но по спеке ManagerCabinet-v2 §2.10): `TeamBars:151`, `MkDeptPlan:109` — исключение нигде формально не зафиксировано в charter.

---

## §4. Полный список находок по модулям

### 4.1 HIGH (24 — полностью, с file:line)

| # | Модуль | Экран / элемент | file:line | eff | Суть |
|---|---|---|---|---|---|
| H1 | deal-page | DealCreateForm — section-title/лейблы | `DealCreateForm.vue:493` | S | dark-ветка `surface-200` → заголовки секций/лейблы невидимы в navy; база `$surface-700` уже theme-reactive |
| H2 | deal-page | «Добавить продукт» — лейблы/input/опции | `DealAddProductDialog.vue:403` | S | лейбл/триггер/ввод/опции на `surface-100/200` → диалог нечитаем в dark |
| H3 | deal-page | Таб «Основное» — owner/company-имя/input/опции | `DealTabMain.vue:677` | S | owner-name/picker-input `surface-100` (=цвет карточки) невидимы; фикс не дошёл до пикеров |
| H4 | deal-page | DealProductRow — имя продукта | `DealProductRow.vue:117` | S | dark `surface-100` на карточке #111E38 → строки товаров невидимы |
| H5 | deal-page | DealFieldGroup — заголовок группы/hover | `DealFieldGroup.vue:180` | S | заголовок `surface-200` невидим; hover `surface-800` (#C6D0E2) — яркая вспышка |
| H6 | deal-page | DealContactsGroup — ⋮-меню контакта | `DealContactsGroup.vue:808` | S | menu-item `surface-100` на card-popover → пункты меню невидимы (кроме danger) |
| H7 | deal-page | DealComposer — кнопки режимов | `DealComposer.vue:387` | S | неактивная кнопка `surface-100`+`surface-300` → контраст ~1.7:1, подписи невидимы |
| H8 | entity-cards | RequisiteCard — локальный `<ConfirmDialog>` per-card | `RequisiteCard.vue:80` | S | нарушение закона глобальных оверлеев; по экземпляру на каждый реквизит |
| H9 | tasks+cabinet | TasksTopBar — икон-плитка (dark) | `TasksTopBar.vue:224` | S | иконка статич. `$primary-900` на тёмной плитке → контраст ~1.1:1, невидима |
| H10 | tasks+cabinet | MyTasksFilterPanel — панель/поиск (dark) | `MyTasksFilterPanel.vue:195` | M | фон `surface-800` (светлая плита), текст light-on-light ~1.1:1; смешение с тёмными PrimeVue-контролами |
| H11 | tasks+cabinet | TasksQuickCreate — кастомные инпуты (dark) | `TasksQuickCreate.vue:424` | M | светлые плиты `surface-700`+текст `surface-800` рядом с тёмными PrimeVue Select |
| H12 | analytics-hub | DashboardToolbar — legacy фильтр-строка «Обзор» | `DashboardToolbar.vue:2` | M | старый бар ПОД новым `AnalyticsFilterBar` → две строки, дубли пикеров |
| H13 | catalog+docs | Bootstrap-утилиты лейаута gap/w-100/flex-1 | `DocumentActionBar.vue:2` | M | классы мёртвые → кнопки слипаются, `w-100` не растягивает (весь docs-модуль) |
| H14 | catalog+docs | Bootstrap-утилиты текста text-*/fw-*/text-end | `CreateDocumentDialog.vue:14` | M | muted/red/bold/выравнивание не рендерятся; глоб. reset гасит `<strong>` |
| H15 | onboarding | QuizQuestion — `.card` | `QuizQuestion.vue:2` | S | `.card` нет в бандле → вопросы квиза без подложки, плоский текст |
| H16 | onboarding | Просроченный дедлайн `text-danger` | `OnboardingAssignmentsPage/index.vue:73` | S | `text-danger` мёртв → просрочка обычным цветом (+ HrProgressTable:56, MyCourseCard:26) |
| H17 | onboarding | Ширина полей форм `w-100` | `CreateCourseDialog.vue:16` | M | `w-100` мёртв → поля браузерной ширины во всех диалогах/драверах модуля |
| H18 | onboarding | AssignCourseDrawer — лейблы в dark | `AssignCourseDrawer.vue:284` | S | `.app-dark → surface-300` (border-grade) → лейблы почти невидимы |
| H19 | onboarding | LoginPage — ссылки 2FA | `LoginPage/index.vue:409` | S | `$primary-color` статич. → ссылка navy-on-navy в dark, невидима (синяя browser-ссылка) |
| H20 | onboarding | CourseSidebar — активный урок | `CourseSidebar.vue:114` | S | `--p-primary-50` не инвертируется → светлая плашка + светлый текст, light-on-light |
| H21 | automation | ToolPalette — иконки действий | `ToolPalette.vue:71` | S | `pi-pencil-square`/`pi-arrow-right-circle` не существуют → пустые квадраты (5 файлов) |
| H22 | automation | PipelineCanvas — мёртвые PrimeVue-3 токены | `PipelineCanvas.vue:598` | M | `--p-surface-card/-border/-ground/-overlay/-radius` → карточный рецепт нод не работает |
| H23 | automation | Поля форм `class="w-full"` | `CreatePipelineDialog.vue:29` | S | `w-full` не определён локально → контролы не растягиваются (диалоги/драверы воронки) |
| H24 | automation | crm/entity — muted-текст во всей entity-зоне (dark) | `InfoPanel.vue:153` | M | `surface-400/500` пре-navy → тёмные чернила на тёмной карточке (~25 файлов) |

### 4.2 MEDIUM (83 — таблицей, сжато)

> Формат: `[модуль] элемент — file:line [effort] :: суть`. Полная детализация с процитированным правилом эталона — в JSON-источнике код-аудита; здесь — навигационный список.

**deal-page (14):**
- Muted-подписи dark-ветка `surface-400` (2.03:1 FAIL) — `MoveDealDialog.vue:279` [S] (+FeedSearchOverlay, DealAddProductDialog, DealProductsGroup, DealContactsGroup, DealProductRow)
- Недофикшенные muted в `DealFeedItem.vue:651` [S] — __sys-time/__sys-old/__due/__author на `$surface-400` без dark
- Dark-бордеры/hover на `surface-700` — `index.vue:759` [M] (системная идиома модуля, ~15 мест)
- Светлые `surface-800` фоны в dark (channel-чипы/панель/разделители) — `DealContactsGroup.vue:876` [S]
- Empty-состояние ленты + topbar-чипы в dark — `DealFeed.vue:521` [S]
- Самодельные owner/company-дропдауны — `DealTabMain.vue:15` [M]
- Самодельный product-search дропдаун — `DealAddProductDialog.vue:51` [M]
- Самодельное ⋮-меню на Teleport — `DealInfoHeader.vue:71` [M]
- Второе самодельное ⋮-popover — `DealContactsGroup.vue:64` [S]
- Нативные input/select для суммы/валюты — `DealTabFinances.vue:35` [S]
- Локальный formatMoney вместо utils — `DealTabFinances.vue:115` [S]
- Второй MoveDealDialog + section-label не по канону — `MoveDealDialog.vue:26` [M]
- FeedSearchOverlay — кастомная панель вместо Popover — `FeedSearchOverlay.vue:84` [M]
- Самодельные аватары-инициалы — `DealTabMain.vue:658` [S]

**entity-cards (10):**
- Кнопки copy/⋮ канала — dark hover icon=фон (исчезает) — `ContactChannelsBlock.vue:565` [S] (+CompanyChannelsBlock:561)
- Tag «инвестор» severity=`warning` невалидно — `ContactRelationsPanel.vue:195` [S]
- Tag статуса severity=`warning` невалидно — `TerminationDocumentDrawer.vue:156` [S]
- Чип «Сумма сделок» «1.5M» без ₽/ru-RU — `index.vue:728` [S]
- Чип стадии — 3 рецепта тинта (hex+alpha) — `CompanyMiniDealsPanel.vue:25` [M]
- Dark-границы `surface-700` (светлые) вразнобой — `ContactDealsPanel.vue:128` [S] (8 мест)
- Uppercase-лейблы dark `surface-400` (~2:1) — `index.vue:981` [S]
- DataTable полноразмерных табов без подложки/зебры — `ContactDealsTab.vue:28` [M]
- Кнопки «Привязать/Добавить/Показать ещё» голые `<button>` (3 стиля) — `ContactCompaniesPanel.vue:46` [S]
- Тинты через `rgba(--p-*-rgb…)` (токен не существует) — `HoldingTree.vue:278` [S]

**tasks+cabinet (15):**
- Create-кнопка голый `<button>` — `TasksTopBar.vue:101` [S]
- Segmented Мои/Команда голые `<button>`, dark light-plate — `TasksTopBar.vue:21` [M]
- Subtitle ломаный плюрал «12 мои задачи» — `TasksTopBar.vue:13` [S]
- Dark-бордеры `surface-700` (светлые) — `index.vue:871` [S] (5 мест)
- Акцент dark статич. `$primary-300` (steel вместо #4C7DF0) — `MyTasksPresetTabs.vue:107` [S]
- Пресет-табы — третий идиом underline tab-bar — `MyTasksPresetTabs.vue:2` [M]
- Счётчик «Выбрано: N» dark `$primary-900` ~1.3:1 — `TasksBulkBar.vue:196` [S]
- Bulk-действия+чекбокс голые — `TasksBulkBar.vue:32` [M]
- Select-mode чекбоксы самодельные (3-я копия) — `MyTasksTable.vue:54` [M]
- Аватар инициал самодельный (navy-on-navy dark) — `MyTasksTable.vue:303` [S]
- Статус/тип-пилюли самодельные + дубль-рецепт — `MyTasksTable.vue:262` [M]
- Entity-picker дропдаун самодельный — `TasksQuickCreate.vue:29` [L]
- Title-input голый + Отмена голая — `TasksQuickCreate.vue:66` [M]
- `pi-sigma` не существует (Итого зарплаты) — `MkSalaryTable.vue:108` [S]
- Reset голый `<button>`, всегда виден, dark hover=фон — `MyTasksFilterPanel.vue:99` [S]

**analytics-hub (5):**
- Прогресс-бар won `$primary-color` статич. → сливается в dark — `WidgetFunnelTable.vue:271` [S]
- Edit-баннер `$primary-50/200` статич. light в dark — `TabOverview.vue:280` [S]
- WidgetGrid edit-chrome `$primary-50/200` + ложный комментарий — `WidgetGrid.vue:130` [S]
- Кнопка severity=`warning` невалидно + вне палитры — `WidgetDealsWithoutTasks.vue:53` [S]
- Строки «вне зачёта» `$surface-400` в :deep (dead .app-dark) — `RatingTable.vue:198` [S]

**catalog+docs (6):**
- Фильтр-бар инлайн (поиск+3 Select+reset) — `ProductsPage/index.vue:34` [M]
- DocumentsFilterPanel постоянная Card-панель — `DocumentsFilterPanel.vue:2` [M]
- Фильтр-строка инлайн (Select+поиск) — `TemplatesPage/index.vue:15` [M]
- PageHeader на list-экране Документы — `DocumentsPage/index.vue:3` [M]
- Самодельные статус-бейджи/алерты ApprovalPanel — `ApprovalPanel.vue:38` [S]
- formatMoney без валюты документа («1,58 млн ₽») — `DocumentItemsTab.vue:37` [S]

**onboarding+auth (21):**
- Превью видео `ratio-16x9` мёртв → 300×150 — `LessonEditDrawer.vue:104` [S]
- Ошибки валидации `text-danger` мёртв — `CreateCourseDialog.vue:19` [S]
- Заголовки драверов `fw-*` мёртвы — `LessonEditDrawer.vue:13` [S] (6 файлов)
- Центрирование empty/карточек `text-center`/`py-6` мёртвы — `MyCoursesPage/index.vue:44` [M]
- Muted `text-muted` мёртв — `OnboardingAdminCoursesPage/index.vue:111` [M] (~14 мест)
- Зазоры `gap-*` мёртвы → слипание икон-кнопок — `OnboardingAssignmentsPage/index.vue:126` [M]
- Sticky-header `border-bottom`/`gap-3` мёртвы + title `$surface-800` — `MyCoursesPage/index.vue:4` [S]
- Иконка «спасибо» `$primary-color` статич. dark — `MyOnboardingCertificatesPage/index.vue:189` [S]
- Иконки PickList `$primary` статич. dark — `QuickActionsPickerDialog.vue:241` [S]
- Пузырь юзера `--p-primary-50` + time `surface-400` — `AiTutorDrawer.vue:194` [S]
- Дата сертификата `surface-400` (~2:1) — `CertificateCard.vue:73` [S]
- PageHeader вместо тулбара — `OnboardingAdminCoursesPage/index.vue:3` [M]
- PageHeader + кнопка в #actions — `MyCoursesPage/index.vue:3` [M]
- PageHeader на hub-экране (аналог DashboardPage) — `HrProgressPage/index.vue:3` [M]
- PageHeader + Tabs/Badge вместо segmented — `OnboardingAssignmentsPage/index.vue:3` [M]
- PageHeader на list-карточек — `MyOnboardingCertificatesPage/index.vue:3` [M]
- Фильтр-бар инлайн (Select+Select+поиск без pi-search) — `CoursesFilterPanel.vue:2` [M]
- Фильтр-бар инлайн (дубль в HrProgress) — `AssignmentsFilterPanel.vue:2` [M]
- DataTable без карточной подложки/зебры/uppercase — `OnboardingAdminCoursesPage/index.vue:21` [M]
- KPI-плитки свой рецепт (без тайла 28×28, palette-цвета в dark) — `HrKpiCards.vue:7` [M]
- Skeleton-обёртка мёртвый `.card`/`overflow-hidden` — `OnboardingAdminCoursesPage/index.vue:26` [S]

**automation+shell (12):**
- Muted оверлеев `surface-400/500` (dark чернила) — `CommandPalette.vue:443` [M] (~18 мест)
- Акценты «Прочитать все»/каунтеры `$primary` статич. — `NotificationsButton.vue:504` [S] (4 места)
- Флайаут `.nf` дубль-копия Orbita — `SidebarNotifications.vue:36` [M]
- Растровые подложки/бордеры dark `surface-800/700` (светлые) — `StageEditorItem.vue:369` [L] (~10 файлов)
- Фильтр-бар инлайн (4 дропдауна+DatePicker+Применить) — `AutomationRunsPage/index.vue:7` [M]
- DataTable запусков без подложки, load-more вместо пагинатора — `AutomationRunsPage/index.vue:71` [M]
- Инлайн-фильтры в шапке панели + голая таблица — `AutomationListPanel.vue:10` [M]
- Утилити-классы `text-*/fw-*/gap-*/w-100` мёртвы — `DryRunDrawer.vue:11` [S]
- Иконка PageHeader `$primary-900` статич. dark — `PageHeader.vue:46` [S]
- Hover `--p-surface-hover` не эмитится → не работает — `PipelineListItem.vue:153` [S]
- Цвет этапа: ColorPicker vs свотч-палитра (2 UI) — `CreateStageDialog.vue:46` [S]
- Самодельный dropdown SearchPicker (3-я копия) + muted `surface-400` — `SearchPicker.vue:20` [M]

### 4.3 LOW (62 — сжато по модулям)

**deal-page (6):** голый `<textarea>` отклонения (DealTabDocuments:130); самодельные бейджи голосов согласующих (DealTabDocuments:97); хардкод `#c0392b` под ложным «brand invariant» (DealInfoHeader:483); нет `:draggable="false"` во всех диалогах (DealInfoHeader:116); литерал `'#ffffff'` в JS-стилях (DealStageTag:20); активная кнопка режима статич. navy в обеих темах (DealComposer:401).

**entity-cards (10):** empty-иконки `surface-300`/текст `surface-500` (ContactChannelsBlock:623 +5); 6 копий formatKopecks/formatMoney (ContactDealsPanel:76 +5); Dialog без `:draggable`/`:closable` (index:344 +5); confirm-иконка $color-warning vs $color-danger у близнеца (index:1417); 3 варианта TabHead brow (CompanyDocumentsTab:5); required-маркер обычная «*» вместо `<span class="req">` (index:359 +4); звезда «основной» hex-fallback (CompanyEmployeesTab:281); поиск сотрудников без pi-search/clear (index:268); мёртвые стили удалённых payments/files-табов (index:1330); инлайн ProgressSpinner вместо Skeleton (TerminationDocumentDrawer:50).

**tasks+cabinet (10):** `rgba(23,39,71…)`-литералы в dark вместо color-mix (TasksTopBar:218 +6); H1 `$font-size-xl`/`$surface-800` вместо lg/900 (TasksTopBar:235); off-grid `gap:14px 18px` (MyTasksFilterPanel:296); empty-CTA secondary outlined вместо primary (MyTasksTable:21); тинт выбранной строки color-mix с surface-0 «проваливается» (MyTasksTable:1109); sortable без @sort — стрелки мертвы (MyTasksTable:78); 3-клик confirm вместо useConfirm (TaskExpandedPanel:203); дата захардкожен ru-RU (TaskExpandedPanel:365); градиентные health-бары (TeamBars:151 +MkDeptPlan); reset-линк вместо Button (ActivityFeed:39).

**analytics-hub (7):** edit-chrome hover `$primary-color` статич. dark (WidgetGrid:175); `—`-плейсхолдер `surface-400` в :deep dark (ConversionPairsTable:181, PlanMatrixCurrencyCell:102, RegistryTable:414); KPI-плитки облегчённый рецепт без shadow/тайла (TabSchedule:265, TabRegistry:190); off-grid px мини-тегов Won/Lost (WidgetFunnelTable:248); off-grid `gap:3px` (WidgetDealsWithoutTasks:334).

**catalog+docs (11):** PageHeader на остальных экранах (ProductsPage/ProductPage/TemplatesPage/DocumentPage:3); дефолтные заголовки+sortable всех таблиц (ProductsPage:129 +); горизонтальные empty-state вместо колонки (TemplatesPage:170 +); инлайн ProgressSpinner вместо Skeleton (PriceImportDialog:42 +); сырые rem/px мимо $space (DocumentPage:262 +); внеканонные severity кнопок success/danger/warn в тулбаре (DocumentActionBar:60, ApprovalPanel:93); ссылка-товар `$primary-color` статич. (ProductsPage:518); muted `surface-400/500` (ProductsPage:527 +); hex-fallback в req-звёздочке (ProductCreateDrawer:409 +); хардкод русской копи мимо i18n (DocumentRevisionsTab:30 +); section-label не по канону (ProductRightRail:91, DocumentContextTab:229).

**onboarding+auth (8):** empty-таблицы без иерархии/CTA (index:31); Card графиков дублирует widget-card не переиспользуя (HrStatusPieChart:2); инлайн-спиннеры вместо Skeleton (LessonViewQuiz:5 +3); UI-копи мимо i18n (LessonViewQuiz:10 +3); плашка таймера `--p-red-50` статич. light в dark (QuizTimer:47); рамка AI-черновиков `--p-info-300` не существует → currentColor (QuizBuilderDrawer:542); сырые rem/веса-литералы (CourseSidebar:82 +); `border`/`rounded` мёртвы у превью markdown (LessonEditDrawer:90).

**automation+shell (10):** футер визарда div вместо #footer + `is-active`/`is-done` вместо `--active` (AutomationWizardDialog:65); кастомные `.field-error`/`.required` вместо `p-error`/`.req` (ActionConfigStep:13 +); Dialog без `:draggable="false"` (CreatePipelineDialog:5 +); req-звёздочка `text-danger` мёртв (DecideDialog:14); ProgressSpinner вместо Skeleton (DryRunDrawer:58, PipelineCanvas:96); самодельные skeleton-дивы с @keyframes вместо PrimeVue Skeleton (AppSidebar:698 +); empty-state без заголовка/hint/CTA (AutomationRunsPage:78); плейсхолдер-аватар `rgba(23,39,71…)` без комментария (AccountMenu:205); MiniMap mask-color `surface-800` (светлый) в dark (PipelineCanvas:130); `font-weight:600` литералом (AutomationNode:148, AnchorNode:78).

---

## §5. Предложение плана ремонта волнами (юзер решает объём)

> Не исполнять. Порядок — от «молча сломано» к косметике. Оценки грубые (S≈≤0.5д, M≈0.5–1.5д, L≈2–4д на файл-кластер), риск — вероятность регресса при правке.

### Волна 1 — «сломанное молча» (S/M)
Всё, что **не рендерится или рендерится пустым** уже в light-теме — механические замены, низкий риск.
- **Фантомные Bootstrap-утилиты** (семейство §3а, ~40 файлов): замена `w-100`→локальный `.w-full`/scoped-width; `text-danger`/`text-secondary`/`text-muted`→токены/`<span class="req">`/`var(--p-text-muted-color)`; `fw-*`→`$font-weight-*`; `gap-*`→scoped `gap:$space-N`; `.card`→карточный scoped-рецепт; `ratio-16x9`→scoped aspect-ratio; `text-center`→scoped. **eff: L суммарно** (много файлов, но каждая правка S). **риск: низкий** механически, средний по объёму (легко пропустить класс).
- **Мёртвые PrimeVue-3 токены канваса** (§3е): `--p-surface-card/-border/-ground/-overlay/-radius/-hover`→`$surface-card`/`$surface-200`/`$radius-*`. **eff: M.** **риск: средний** (канвас — сложный, нужен визуальный ре-тест обеих тем).
- **Битые имена иконок** (§3з): `pi-pencil-square`→`pi-pencil`, `pi-arrow-right-circle`→`pi-arrow-circle-right`, `pi-sigma`→замена глифа (7 файлов). **eff: S.** **риск: нулевой.**
- **Невалидные severity** `warning`→`warn` (3 файла) + несуществующие токены `--p-info-300`/`--p-*-rgb` (4 файла). **eff: S.** **риск: нулевой.**
- **Синяя browser-ссылка в dark** (LoginPage 2FA, `$primary-color`→`var(--p-primary-color)`) + **`?`-аватар** (самодельные аватары→`EntityAvatar`, 4 файла). **eff: S/M.** **риск: низкий.**

### Волна 2 — «dark-долг» инвертированной шкалы (M)
Семейство §3г — самое массовое; правки типовые, но их много и легко пропустить хвост (что и произошло с частичным фикс-проходом deal-page).
- **Текст на `surface-100/200`**→`var(--p-text-color)`/theme-reactive `$surface-700+` (снять лишние dark-ветки). deal-page 7 high + entity + tasks.
- **Muted на `surface-400/500`**→`var(--p-text-muted-color)` (dark→surface-600); для :deep-зон — non-scoped блок (не мёртвый `.app-dark &` в :deep). crm/entity high + ~50 мест по модулям.
- **Фоны/бордеры/hover на `surface-700/800`**→color-mix-тинты / `surface-200`. tasks high + StageEditorItem + dark-бордеры карточных подложек.
- **Статический `$primary-*` как акцент**→`var(--p-primary-color)`/color-mix. TasksTopBar/WidgetFunnelTable/edit-chrome/CourseSidebar high.
**eff: M на модуль** (deal-page + tasks + automation-entity — крупнейшие). **риск: средний** — обязателен визуальный гейт `qa-tester` в обеих темах (computed-styles); легко недофиксить и оставить хвост.

### Волна 3 — «поколение периферии» (L)
Каталог/документы/онбординг/автоматизации → канонное шасси. Это структурная переработка, не косметика.
- **PageHeader→тулбар-канон** (§3б): икон-плитка 38×38 + h1 + subtitle-каунтер + spacer + segmented + фильтр/Create. ~10 экранов.
- **Инлайн-фильтры→паттерн Б** (§3в): кнопка-триггер + бейдж-каунтер + inline-панель. + разбор двойной фильтр-строки «Обзор» (снять legacy `DashboardToolbar`).
- **Голые таблицы→карточная подложка** (§3ж): `$surface-card`+`$radius-lg`+border+`$shadow-card`, опц. зебра, uppercase/центр. th, кастомный пагинатор.
- **KPI-рецепты→WidgetKpiCard/widget-card** (§3з): единый икон-тайл 28×28 + семантический тинт.
**eff: L** (каждый экран — переверстка шапки+фильтров+таблицы). **риск: высокий** — трогает лейаут; нужен per-screen дизайн-апрув и qa-гейт.

### Волна 4 — «швы» (M)
Унификация того, что каждый компонент изобрёл заново.
- **Единый money-util** (§3з): все локальные `formatKopecks`/`formatMoney`→`utils/currency` (устранить «1.5M» vs «1 200 000 ₽»); передать валюту документа в docs. ~10 файлов.
- **Empty/not-found унификация**: канонная колонка (иконка→заголовок→hint→CTA), `pi-filter-slash` при активных фильтрах; заменить голый `<Message>` products и разнобойные empty онбординга/автоматизаций.
- **Самодельные дропдауны/меню DealPage→PrimeVue** (§3д): owner/company/product-search→`Select filter`/`SearchPicker`; ⋮-меню→`Menu popup`; FeedSearchOverlay→`Popover`. + tasks entity-picker + automation SearchPicker.
- **`RequisiteCard` per-card ConfirmDialog→глобальный `useConfirm`** (§3и); + унификация 3 confirm-стратегий entity; + `:draggable="false"` на все диалоги; + дубль `SidebarNotifications`→общий компонент.
**eff: M.** **риск: средний** (замена контролов меняет поведение — нужен функц. ре-тест).

---

## §6. Что уже эталонно (позитив)

Периферия дрейфует, но **ядро 2.0 держит планку — и служит живым референсом для волн ремонта:**
- **Кабинет менеджера** (`ManagerCabinetPage`) — свежайший DS2: `CabinetToolbar` + `mk-card`/`mk-eyebrow` + `PctTag` + `formatMkMoney` + Skeleton; практически эталон (единичные отклонения: `pi-sigma`, санкц. градиенты).
- **Аналитика-хаб** (`HubToolbar` + табы Планы/Реестр/График/Рейтинг) — `HubToolbar` сам является эталоном тулбар-паттерна; widget-card подложки, `PctTag`, **опаковые frozen-колонки** (`PlanMatrix` — образец приёма), color-mix-тинты, дисциплинированные `.app-dark &`-ветки.
- **Карточки сущностей** (Entity Card 2.0) — `EntityInfoHeader` + KPI-строка + стек `InfoPanel` по апрувнутой спеке; PrimeVue-контролы, образцовая легализация отступлений (stylelint-disable + ссылка на spec §), dark продуман почти в каждом правиле; create-формы и confirm контакта — фактически эталонные.
- **Почта** (`InboxSearchFilters`) — эталон паттерна А (пилюля+Popover) целиком; **закон глобальных оверлеев** зафиксирован комментарием.
- **Сделки — список/канбан** (`DealsToolbar`/`DealsListView`/`DealsFilterOverlay`) — эталон тулбара, паттерна Б фильтров, карточной таблицы с зеброй и sort-кнопками, легализации нестандартных размеров (19px/17px/7px + stylelint-disable).
- **Логин / публичная лид-форма** — свежий редизайн, почти эталон.
- **Оболочка** (`AppSidebar`/`Orbita`/`AccountMenu`) — после rgba/dead-selector чисток ближе всех к канону.

**Вывод:** дрейф локализован в периферии и в двух сквозных осях (шасси-поколение + dark-долг), а не размазан по продукту. Эталоны для каждого нужного паттерна уже существуют в кодовой базе — ремонт волнами = подтягивание периферии к живому референсу, а не изобретение нового.

---

## Приложение A — извлечённый канон (16 паттернов / 20 анти-паттернов)

Полный текст 16 паттернов (с `etalonRef` на file:line эталонов) и 20 анти-паттернов (grep-ловушки) — в JSON-источнике код-аудита (`.result.canon`). Ключевые развилки, зафиксированные из живого кода:
1. **Шапка** — семейство «toolbar» (HubToolbar/CabinetToolbar — свежайшие DS2) вытесняет PageHeader; DealsToolbar/ContactsToolbar/Inbox-header — тот же паттерн с локальными вариациями.
2. **Фильтры** — два равноправных канона: пилюля+Popover (Inbox, компактные) и кнопка-с-бейджем+inline-панель (Deals/Contacts, тяжёлые).
3. **Диалоги** — стандарт `:header`+`#footer` (cancel text/outlined → action primary/danger справа); `show-header=false` — только для «окон» (TaskExpandedPanel); ConfirmDialog/Toast **только** в DefaultLayout.
4. **Таблицы** — карточная подложка `radius-lg`+border+shadow, зебра опциональна, кастомные sort-кнопки в #header, пагинатор «Показано {from}–{to} из {total}», **frozen-опак** из PlanMatrix.
5. **Dark** — приоритет theme-reactive токенов (одно правило читает обе темы); инвертированная navy-шкала; muted=`var(--p-text-muted-color)`; акцент=`var(--p-primary-color)`; 4 запрещённые формы мёртвых dark-селекторов (charter §4); dark-тинты=color-mix.
6. **Нестандартные размеры** легализуются ТОЛЬКО через комментарий-обоснование + `stylelint-disable-next-line` (образец — DealsToolbar 19px/17px/7px).

Документальный канон: `docs/designer-charter.md` (§2 инвентарь shared, §3 «нужда→компонент», §4 токен-дисциплина + 4 мёртвых dark-селектора, §5 формат ТЗ). Бренд-инварианты: `.claude/skills/macroglobal-design/` (navy #172747, Inter, PrimeIcons, деньги «1 200 000 ₽», без пурпура/градиентов/эмодзи). Значения токенов: `front/src/theme` (единственный источник).

---

*Аудит read-only. Код не менялся. §5 — предложение, не исполнение.*
