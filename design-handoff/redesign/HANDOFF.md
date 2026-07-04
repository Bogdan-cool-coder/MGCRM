# Handoff — обновление CRM-экранов (для Claude Code)

Этот пакет — эталонные макеты (HTML) и технические задания (MD) для пересборки экранов MGCRM.
Положи папку в репозиторий: `/Users/bogdanadykin/Desktop/Claude/MGCRM/design-handoff/`.

## Состав
- **Эталоны (открыть в браузере):** `deal-card.html`, `entity-card.html` (контакт/компания),
  `contacts.html` (список), `sales-funnel.html` (воронка), `tasks.html` (задачник),
  `settings.html` (настройки hi-fi) + `styles.css`, `tweaks-panel.jsx`.
- **ТЗ (источник правды):** `DealCard-spec.md`, `EntityCard-spec.md`, `Contacts-spec.md`,
  `SalesFunnel-spec.md`, `Tasks-spec.md`, `Inbox-spec.md`, `Settings-redesign-visual.md`.
- **JSX-референсы поведения (Settings hi-fi, стек — референс, не копировать):** `users-section.jsx`,
  `access-section.jsx`, `system-section.jsx`.

## Что нового в этой версии
- Карточка сделки доведена: лента только со свершившимися действиями, системка одной строкой,
  цветные значки типов задач, интерактивные открытые задачи (раскрытие/выполнить/3-клик-удаление),
  единые поля даты с авто-форматом, согласование документов, фиксация оплаты, скрытые скроллбары.
  Всё — в `DealCard-spec.md` §11.
- Карточки контактов/компаний нужно привести к тем же правилам ленты/задач — `DealCard-spec.md`
  §12 + `EntityCard-spec.md`.
- **Задачник (`Tasks-spec.md` + `tasks.html`)** — пересобран в стиле воронки: канбан по дедлайн-
  бакетам (с авто-скрытием «Просрочено»), табличный вид с табами-пресетами и порядком колонок
  Срок · Сделка · Этап · Тип · Текст · Статус · Ответственный, быстрое создание с автоподбором
  сущности (компания/контакт), фильтр-панель и **массовые действия** (канбан + список).

### Обновление 2026-06-27 — task-mgmt hardening (отшипперено в прод, HEAD `8ef51bf`)
Спеки сверены с кодом после прохода по задачам; правки внесены инлайн как «as-built»-пометки
(сами .html-эталоны не трогали — источник истины по этим пунктам теперь .md):
- **Задачник:** пресет **«Выполненные»** отшипперен (5-й таб, `counts.completed` +
  `GET /api/activities/presets/completed`); статус-колонка — **transition-gated dropdown**
  (`ACTIVITY_STATUS_TRANSITIONS`, патч `/status`); бакеты **серверные, Asia/Dubai**, `rejected`
  считается закрытой; выполненная задача покидает open-список; реальные 4-state лейблы; guard от
  двойного Ctrl+Enter. — `Tasks-spec.md` §§5/6 + «As-built deltas».
- **Карточки контакта/компании:** лента **агрегирует активности связанных сделок** (чип «из
  сделки» `deal_id`/`deal_title`); статус элемента — реальное enum-поле `payload.status`; KPI
  «Открытых задач» реактивно перечитывается после действий; `OpenTasksList` чистит per-task-стейт и
  выполненная задача уходит из открытых. — `EntityCard-spec.md` §§2/5.
- **Новые entity-log события:** `note_added` / `task_reopened` / `task_rejected` (event-driven
  аудит-лог, `RecordActivityAuditLogListener`).
- **Карточка сделки:** бейдж статуса в ленте — реальный 4-state enum, не бинарный done/open
  (`deal-card.html` ~L530 устарел). — `DealCard-spec.md` §7.2.
- **Contacts-spec:** исправлен путь «Где в коде» → `front/src/pages/ContactsPage/` (каталога
  `pages/crm/` нет).

## Промпт для Claude Code (вставь целиком)

> Контекст: дизайн-система — источник правды по визуалу, лежит в
> `.claude/skills/macroglobal-design/` (токены в `styles.css` + `tokens/*.css`). Эталоны макетов и
> ТЗ — в `design-handoff/`.
>
> Задача 1 — Карточка сделки. Пересобери строго по `design-handoff/DealCard-spec.md` (особое
> внимание разделам §11 «Свежие правки» и §1–§9), визуальный эталон —
> `design-handoff/deal-card.html`. Используй ТОЛЬКО токены дизайн-системы (`--mg-*` / `--c-*`),
> без хардкода цветов/радиусов/размеров. Сверься с текущим кодом `front/src/pages/DealPage/`
> (DealInfoHeader, DealInfoPanel, DealInfoTabs, DealTabMain/Documents/Finances, DealFeed,
> DealFeedItem, DealComposer, OpenTasksList, DealProductsGroup/ContactsGroup/CompanyGroup,
> MoveDealDialog, DealAddProductDialog/AddContactDialog). Поддержи светлую и тёмную темы.
>
> Задача 2 — Карточки контакта и компании. Приведи ленту активности, карточки задач/событий,
> composer и редактирование контакта к единому виду со сделкой — по `design-handoff/DealCard-spec.md`
> §12 и `design-handoff/EntityCard-spec.md`, эталон `design-handoff/entity-card.html`. Файлы:
> `front/src/pages/ContactPage`, `front/src/pages/CompanyPage`, `front/src/components/crm/entity/*`.
>
> Задача 3 — Раздел «Мои задачи» (задачник). Пересобери строго по `design-handoff/Tasks-spec.md`,
> визуальный эталон — `design-handoff/tasks.html`. Используй ТОЛЬКО токены дизайн-системы
> (`--mg-*` из `.claude/skills/macroglobal-design/styles.css` + `tokens/*.css`, плюс поверхностные
> `--c-*`), без хардкода. Сверься с текущим кодом `front/src/pages/MyTasksPage/` (index.vue;
> components: TasksKanbanBoard, TaskCard, MyTasksTable, MyTasksPresetTabs, MyTasksFilterPanel;
> composables: useTaskBoard, useMyTasks) и `components/tasks/TaskQuickForm.vue`; паттерн массовых
> действий возьми из `front/src/pages/DealsPage/components/DealsBulkToolbar.vue` +
> `stores/salesStore` (bulkMode/bulkSelection). Общую «хромку» (TopBar/FilterPanel/тема) держи
> идентичной воронке — сверяйся с `design-handoff/SalesFunnel-spec.md`. Поддержи светлую и тёмную темы.
>
> Требования к обеим задачам:
> - Лента событий читается снизу вверх; в ленте только свершившиеся действия (открытые задачи —
>   отдельным списком); системные изменения — одной строкой «Автор · дата · время · действие ·
>   ~~старое~~ → новое».
> - Значок типа задачи подсвечивается цветом (звонок синий, встреча зелёный, КП жёлтый, договор
>   красный), рамка карточки тонко в цвет типа.
> - Поля даты: ручной ввод с авто-форматом ДД.ММ.ГГГГ + календарь по клику; план/факт без времени.
> - Полоса прокрутки скрыта везде, прокрутка работает.
> - По завершении прогони линт/`vue-tsc` и проверь обе темы скриншот-тестами; не пушь, пока не
>   совпадает с эталоном.

### Обновление 2026-06-29 — Settings master-detail shell (Фаза 1 РЕАЛИЗОВАНА, незакоммичено)
- **Настройки (`Settings-spec.md`)** — шелл `SettingsPage` реализован (master-detail: sidebar
  240px + detail flex:1); глубокий линк `?section=`; 5 разделов Ф1 (Profile / Security /
  Appearance / Language / Channels); save-bar (Сохранить/Отменить) на форм-разделах; preview-save
  для темы и языка; quick-actions интегрированы в «Внешний вид» через draft-режим диалога;
  /profile?tab=* → редиректы зарегистрированы; ProfilePage оставлен как shim до Ф2.
  Confirm-on-leave (navigation-guard) осознанно отложен: save-bar и кнопки «Сохранить/Отменить»
  работают, dirty-guard при навигации вынесен отдельной задачей. Ф2/Ф3 — pending.

### Обновление 2026-06-29 — Settings Фаза 2: Справочники РЕАЛИЗОВАНА (незакоммичено)
- **Настройки Ф2 (`Settings-spec.md` § «Фаза 2»)** — DONE: `SectionDirectories.vue` (PrimeVue Tabs
  line-style, v-if lazy-mount, роль-гейт) + 5 DirTab-обёрток; embedded-проп на 5 standalone-страницах
  (PageHeader/Toast/ConfirmDialog за `v-if="!embedded"`); `useSettings.ts` расширен до 10 ключей +
  роль-проверка resolveSection; редиректы `/admin/*` → `/settings?section=*` активированы;
  `/admin/products/:id` сохранён. PM approved. Ф3 (СИСТЕМА) — pending.

### Обновление 2026-06-30 — Settings Фаза 3: Система РЕАЛИЗОВАНА (QA PASS, PM APPROVED)
- **Настройки Ф3 (`Settings-spec.md` § «Фаза 3 — Система»)** — DONE: 4 раздела группы СИСТЕМА
  активированы в шелле: `SysTabUsers` / `SysTabAccessControl` / `SysTabAutomationRuns` (embedded-паттерн Ф2)
  + `SectionSystemReset.vue` (action-based, `useSystemReset` + `SystemResetDialog`). Роль-гейт
  system-reset — admin-only (`resolveSection` + sidebar-фильтр + `v-if="!isAdmin"` guard).
  Toast-дубли устранены (`v-if="!embedded"`). Редиректы активированы. `PipelineSettingsPage`
  остаётся standalone (canvas). Весь срез Настроек (Ф1+Ф2+Ф3) завершён.
  Некритичные хвосты: `/profile?tab=system` → `'system-reset'` маппинг не обновлён (сейчас `'profile'`);
  dark-заголовки SectionSystemReset: `var(--p-surface-900)` → должно быть `var(--p-surface-50)`;
  red-fallback hex в rgba. Всё в очереди DS-прохода.

### Обновление 2026-06-30 — Settings Фаза 5: Профиль 2.0 (РЕАЛИЗОВАНА, QA PASS, PM APPROVED, незакоммичено)
- **Настройки Ф5 (`Settings-spec.md` § «Фаза 5»)** — ТЗ написано: (A) reorg АККАУНТ в
  один пункт «Профиль» с горизонтальными под-вкладками (`SectionProfileTabs.vue`, Tabs
  line-underline, deep-link через существующие `?section=`-ключи без рефакторинга
  `useSettings`); (B) аватар-кроп — новый пакет `vue-advanced-cropper` (явно запрошен),
  `AvatarCropModal.vue` (Dialog + CircleStencil 1:1, downscale ≤1024px + quality 0.85,
  клиентская валидация типа/размера до кропа, `URL.revokeObjectURL` при закрытии);
  (C) смена пароля — `ChangePasswordForm.vue` (action-based, PrimeVue `Password`,
  inline 422-ошибка под «Текущий пароль», `POST /api/me/password` — backend-блокер);
  (D) набросок админ-сброса пароля в «Пользователи» (разовый показ, Copy-кнопка).
  Pending: ОВ-3 (1 пункт vs 4 в сайдбаре — апрув PM) + ОВ-1 (backend POST /api/me/password).

### Обновление 2026-07-01 — Создание через полную карточку (РЕАЛИЗОВАНО, QA PASS, PM APPROVED, незакоммичено)
- **EntityCreate (`EntityCreate-spec.md`)** — замена мини-drawer'ов полноценными create-страницами. Реализовано:
  три роута `/contacts/new`, `/companies/new`, `/deals/new` зарегистрированы перед `:id`-роутами;
  `ContactCreateForm.vue` / `CompanyCreateForm.vue` / `DealCreateForm.vue` — новые компоненты с инлайн-валидацией, blur-гарды, 422-обработка;
  `isCreateMode` в каждом Page-компоненте; P0-фикс `watch(route.params.id)` — save-in-place через `router.replace`;
  `DealCreateDrawer.vue` удалён; quick-create `<Drawer>` из ContactsPage удалён;
  `useContactsPageActions.ts` очищен от `openQuickCreate`/`submitQuickCreate`/`pendingDrawer`-watcher;
  `uiTriggers.ts` — `DrawerTrigger = null`, `triggerDrawer` no-op;
  все 7 межмодульных точек входа (ContactsPage, DealsPage toolbar+kanban+list, CompanyPage, ContactDealsTab, CommandPalette, QuickActionsCluster) мигрированы на `router.push('/...new')`;
  prefill-параметры `company_id/company_name/pipeline_id/stage_id/contact_id` по spec;
  i18n RU+EN: `contact.create.*`, `company.create.*`, `sales.deal.create.*`;
  QA PASS: 3 create-флоу грузят созданную карточку (POST 201 + GET /:id), обе темы, регресс.

### Обновление 2026-07-03 — Мотивационные карты Фаза A: РЕАЛИЗОВАНА (незакоммичено, review PASS)
- **МК (`motivation-card/SPEC.md` v1.1)** — оба экрана зашипперены под navy-тему. SPEC актуализирован до v1.1 (navy-токены + закон dark-селекторов; hero-число через `var(--p-primary-color)`; role-check → permission-gate; интерим-факт Фаза A = won_deals). Backend по контракту `docs/contracts/motivation-cards-api-contract.md` (3 миграции, `Domain/Sales` модели/сервис/policy/FactSource-сейм, 2 permission `motivation.manage`+`motivation.status`, endpoints B-1…B-5). Frontend: кабинет `/manager-cabinet/motivation` (read-only, hero-ЗП, план отдела, live прогноз бонуса, мультивалюта, PctTag) + конструктор `/settings/motivation-builder` (единый пункт «Продажи» в сайдбаре, permission-gate `motivation.manage`, статус-переходы `motivation.status`). Гейты — через `useMotivationPermissions` (единый permission→role маппинг, не scattered role-strings). Тесты зелёные (Feature+Unit); vue-tsc 0; lint:ds 0; QA e2e PASS (обе темы, после 2 фиксов: dev-seeder, params-validated-обрезание). **Деплой-заметка: 2 новых permission придут в прод разовым `db:seed --class=RolePermissionSeeder --force` (deploy гоняет migrate, не seed).**
- **Наименование `motivation.transition` (SPEC) ↔ `motivation.status` (backend):** SPEC/frontend упоминают право «перехода статуса» как `motivation.transition`; фактическое backend-permission — `motivation.status`. Функционально идентично (accountant/director/admin), но имя расходится — при переезде фронта на реальный `can()` из `/api/me` использовать `motivation.status` (канон — контракт §3.4).

### Обновление 2026-07-03 — Settings-гэпы (4) РЕАЛИЗОВАНЫ (незакоммичено, review PASS)
- **Settings-гэпы (`settings-gaps-tz.md`)** — все 4 гэпа закрыты. Гэп-1: справочники свёрнуты в единый пункт «Справочники» (+ danger-пункт «Сброс системы»); Гэп-2: drag-reorder справочников (5 директорий: countries/acquisition-channels/disconnect-reasons/tags/lost-reasons) через общий примитив `SortOrderReorderer`; Гэп-3: AccessControlPage OrgChartNode rewrite (top-down + inline-members, edit-mode); Гэп-4: PipelineSettingsPage рельс. QA PASS обе темы. vue-tsc 0; lint:ds 0.

### Обновление 2026-07-02 — Управление кастомными полями (ТЗ готово, в очереди)
- **CustomFields UI (`custom-fields-ui-tz.md`)** — два среза: (1) `CustomFieldsPage` — directory-экран управления схемой (DataTable, scope-табы, Dialog create/edit, row-reorder, toggleActive, delete), встраивается в SectionDirectories как новый таб «Кастомные поля»; (2) доработка `CustomFieldRenderer` — `checkbox`-alias, scope `contract`, required-звёздочка. Новый компонент `FieldKindTag` (тип поля иконкой + лейблом). 6 открытых вопросов — ОВ-1/ОВ-2 (backend shape, admin-endpoint) требуют ответа от `backend-architect` перед стартом.

### Обновление 2026-07-01 — Единое окно объединения MergeDialog 2.0 (ТЗ готово, в очереди)
- **MergeDialog 2.0 (`Dedup-Merge-spec.md`)** — ТЗ написано. Два режима одного компонента:
  `mode='dedup'` (скан → группы → merge) и `mode='bulk'` (сразу merge из выбранных N записей).
  Ключевые изменения vs текущего: per-field RadioButton в таблице полей (`fieldOverrides`),
  append-блок дочерних коллекций (всегда, без выбора), delete-блок с перечнем удаляемых,
  drill-in в карточку (`target="_blank"`) из любого шага, per-pair «Не дубль» с Popover для
  групп 3+. Bulk-вход снимает ограничение `selectedCount === 2` → `>= 2`. 3 backend-блокера
  (aggregates в scan, `field_overrides` в merge endpoint, per-pair dismiss). Диалог расширен
  до 860px. Незакоммичено, ждёт backend-блокеров B-1/B-2.

### Обновление 2026-06-30 — Settings dirty-guard РЕАЛИЗОВАН (QA PASS 5 сценариев, PM APPROVED)
- **Confirm-on-leave** — полностью реализован кастомным диалогом. Причина phantom'а: `ConfirmService` держал глобальный реактивный стейт и переотрисовывался на destination-компоненте во время async-навигации. Заменено на `UnsavedChangesDialog.vue` (PrimeVue `<Dialog>`, не `useConfirm`) + Promise-based guard в `useSettings.ts`. Один `onBeforeRouteLeave` (return-форма); `setSection()` перехватывает грязность до `router.replace`; `dialogVisible` закрывается явно до `resolve()`. `markDirty`/`markClean` восстановлены как реальные сеттеры. QA PASS: 5 сценариев, обе темы, DOM-счётчик 1 диалог. Незакоммичено.

### Обновление 2026-06-30 — Settings Фаза 4: Документы + link-out + dirty-fix (QA PASS, PM APPROVED)
- **Настройки Ф4 (`Settings-spec.md` § «Фаза 4»)** — DONE: 4 новых DirTab-обёртки (DocTemplates/TplVariables/ApprovalRoutes/MsgTemplates, паттерн Ф2); пер-итемная роль-логика (lawyer/director/manager видят соответствующие вкладки); pipeline-stg переведён в phase:1 как link-out на /settings/pipeline; дубль automation-runs убран из AppSidebar; редиректы /admin/templates|template-variables|approval-routes|message-templates активированы; dirty-guard regression fix (navigateOutOf + instant-leave CSS). Незакоммичено.

### Обновление 2026-07-01 — Волна 6 РЕАЛИЗОВАНА (QA PASS 10 сценариев + dark-контраст, PM APPROVED, незакоммичено)
- **TaskWindow + Канбан (пп. 10.1–10.5)** — DONE:
  - 10.1: overdue-колонка видима когда count>0; chip `countsByPreset.overdue` выровнен с колонкой (task-like + scopeOpen + due < operationalTodayStart)
  - 10.2: drag-and-drop цел; overdue заблокирован как drop-target; перенос вызывает reschedule
  - 10.3: reschedule сохраняет time-of-day (h/m/s из исходного due_at); `nextMondayKeepingTime` отдельный helper
  - 10.4: `stampTargetContext` — batch contact/company lookup ≤2 запроса, visibility-scoped; target {type,id,label} в обоих Resources + entities/activity.ts; TaskCard рендерит RouterLink (@click.stop) для deal/contact/company
  - 10.5: `TaskExpandedPanel.vue` (mode=dialog/inline) — единый компонент: OpenTasksList (CRM-карточки) использует mode=inline, kanban/список используют mode=dialog 540px; гейт «нельзя выполнить без итога» только фронт (server complete без result — допустимо по risk-note); 3-step delete; related entity RouterLink
  - QA: 10 функциональных сценариев PASS (регресс 3 CRM-карточек чист) + dark-контраст PASS
  - 3439 PHPUnit зелёных

### Обновление 2026-07-03 — MSales 2.0: navy dark theme + settings hi-fi (этапы 0–3)
- **Settings hi-fi (`Settings-redesign-visual.md` + `settings.html`)** — визуальный редизайн-слой
  unified `/settings` поверх реализованной механики Ф1–Ф5: двухколоночный шелл (рельс 264px +
  detail), hero-карточка профиля (navy-градиент, аватар 72px, мета-строка вход/2FA/язык), выбор
  акцента (4 свотча), сегмент плотности «Компактная/Просторная» (density-токены `spacing.css`),
  единый header с theme-toggle, hi-fi карточки каналов и модалки. Функциональный апгрейд:
  **выборочный** system-reset по 9 категориям (чекбоксы + indeterminate, ввод `СБРОСИТЬ`).
- **JSX-референсы поведения:** `users-section.jsx` (таблица пользователей, тулбар
  поиск+роль+отдел, архивный блок, модалка 544px, сброс пароля разовым показом),
  `access-section.jsx` (Отделы Дерево/Схема · Роли-матрица · Видимость), `system-section.jsx`
  (AutomationsTab сегмент-фильтр со счётчиками + ResetTab выборочный сброс). Стек референсов —
  React; реализация — Vue+PrimeVue по нашим паттернам.
- Эталон `settings.html` открывается против `../styles.css` (8-файловая токен-система:
  + `base.css`/`dark.css`/`logical.css`, extended-status, density, focus-ring).
- Статус: **этапы 0–2 РЕАЛИЗОВАНЫ — ждёт прод.** Этап 1 (hi-fi шелл/hero/акцент/плотность,
  density-store `mgcrm_density` + токен `--mg-row-py`) QA PASS (navy dark 8/8 + 6/6 recheck).
  Этап 2 (**выборочный** system-reset по 9 категориям — backend по контракту
  `docs/contracts/system-reset-api-contract.md`, live e2e) QA PASS. Backend-паритет: 3737
  PHPUnit зелёных (reset-сьют 37 + доменные purge-тесты). Незакоммичено.
- **Этап 1 (navy dark repaint, `b97e55c`) — РЕАЛИЗОВАН, reviewer PASS.** Dark-тема перекрашена
  grey→navy (инверсия сохранена): surface-шкала `#0A1426…#F5F8FE`; акцент в dark светлеет
  `#172747 → #4C7DF0`. Статусы/тени/brand-chrome вынесены в глобальные `--app-*` объявления
  (`base.scss`, `:root` light + `.app-dark` dark) — ушли с inline-эмиссии на `<html>`, которая
  била `.app-dark`-каскад. ECharts navy-палитра (10 цветов, порядок 1:1 light). Устранён
  анти-паттерн мёртвых `:deep(.app-dark)`-веток (19 в 9 файлах). Sidebar dark `#091020`,
  deal-header dark `#111E38`; light `#172747` — бренд-инвариант, сохранён. Light-тема не тронута.
  **QA navy dark 8/8 + 6/6 recheck PASS.** Канон navy: `front/src/theme/tokens/colors.ts`.
- **Этап 3 (section-level navy delta, `08b8e2e`) — РЕАЛИЗОВАН, reviewer PASS.** CRM+Sales-свип
  ~58 инвертированных dark-оверрайдов; odd-row-dim fix (ссылки → `--p-primary-color`); deal-header
  отвязан к токену (`$brand-header-bg`, `#111E38` в dark); kanban hover поднят (`surface-200`);
  бордеры колонок унифицированы (`surface-300`); `DS_STAGE_PALETTE` → 5 канонических `$stage-color-*`;
  dark row-hover перенесён в non-scoped namespaced-блок (`.contacts-page__table`). **QA этап-3 PASS
  обе темы (после 3 итераций hover).**
- **Navy dark — действующая тема проекта.** reviewer PASS-with-nits 2026-07-03; `type-check` +
  `lint:ds` зелёные; ноль бизнес-логики (единственный JS-тач — theme-флаг в `macroCrmBarColor`).
  Закон о 3 мёртвых dark-селекторах + 2 рабочих паттернах записан в `docs/designer-charter.md`
  §«Обе темы». Незакоммичено → в main локально (этапы 1–3).
- **Известные не-блокеры (nits, код не тронут):** (1) комментарий-аннотация `iconColor: {surface.400}`
  в `foundation.ts` двусмыслен рядом с muted-семантикой (остальные muted ушли на `{surface.500}`);
  (2) `DS_STAGE_PALETTE` — 5 сырых hex продублированы литералами в JS (1:1 совпадают с `$stage-color-*`,
  но дрейф-риск от `_colors.scss`).

### Обновление 2026-07-04 — DS2 (вторая генерация)
- **3 новых мокапа** в `design-handoff/redesign/`: `manager-cabinet.html` — кабинет менеджера v2;
  `dashboard.html` — виджет-сетка дашборда; `mail.html` — редизайн Inbox (почта).
- **Navy-dark обновление 6 общих эталонов** (перезаписаны той же генерацией):
  `contacts.html`, `deal-card.html`, `entity-card.html`, `sales-funnel.html`, `tasks.html`,
  `pipeline.html`.
- **Токены:** новый файл `tokens/surface.css` (слой поверхностей) + дельты в `tokens/colors.css`
  (`--mg-orange-600` light/dark, фикс `--mg-pink-300`) и `tokens/dark.css` (`--mg-stage-amber-ink`);
  `styles.css` обновлён (импорт `surface.css`). Зеркально обновлён skill
  `.claude/skills/macroglobal-design/` (те же tokens/styles + манифест/бандл).
- **Skill-компоненты:** 9 обновлённых + **18 новых** jsx+d.ts (AvatarGroup, DataTable,
  NotificationBadge, StatCard, Stepper, EmptyState, Skeleton, Toast, Switch, PageHeader,
  Pagination, SegmentedControl, Tabs, CommandPalette, Dialog, Menu, Tooltip, Tree) + card-витрины;
  новая директория `templates/` (5 шаблонов: crm-shell, crm-page, data-table-page, kanban-board,
  settings). UI-kit `ui_kits/crm/` сведён к `index.html` + `Sidebar.jsx` (iframe-ссылки ведут на
  мокапы `design-handoff/redesign/` — без дублирования); retired Shell/DealsView/ContactsView/TasksView удалены.
- **Статус (обновлено 2026-07-04, HEAD `b1bbb34`):** все три экрана DS2 **РЕАЛИЗОВАНЫ**:
  - **Кабинет менеджера v2** — Э10, коммит `9f8c4ee` (`ManagerCabinet-v2-spec.md`): `ResultsHero`
    (МК%-кольцо), `MoodHead`, `TeamList` градиент-бары, лента v2 (pill-фильтры), полиш Motivation-таба.
    Backend не тронут (данные в существующем API кабинета).
  - **Dashboard v2 Фаза 1** — Э11, коммит `68788cf` (`Dashboard-v2-spec.md`, **вариант Б**: порядок +
    видимость виджетов без drag-resize): 12-колоночная сетка с сохранением раскладки, честная сквозная
    конверсия, обновлённые Funnel/Forecast, `NoTaskWidget`-превью. Без изменений backend. **Фаза 2**
    (валютный `CcyPopover`) — в очереди за расширением `DashboardResponse` (см. `docs/audit/…` §7).
  - **Mail v2 срез A** — Э12, коммит `b1bbb34` (`Mail-v2-spec.md`): двухпанельный триаж (список+читалка),
    unread-тогл, ChannelDot, фильтр-панель Входящие/Не разобрано/В сделках + канал-чипы + failed-баннер.
    Без новых полей БД (текущий `/api/inbox`). **Срезы B/C** (звёзды/snooze/Отправленные/Черновики) — в
    очереди за новыми полями/доменом исходящей почты (см. §7 аудита).
- **ВАЖНО:** литералы navy в мокапах DS2 (`#12213E`/`#243358`/`#E8EDF6`) — **reference-only**;
  runtime-значения темы (`#111E38`/`#27395C`/`#EAF0FA` из `front/src/theme`) — **закон**.

## Analytics-hub (`analytics-hub-tz.md`) — СПРИНТ ЗАВЕРШЁН (Ф1–Ф5 DONE, reviewer PASS 2026-07-04)
- **Итог спринта «Планы и отчёты»:** все report-табы теперь живые (не стабы `TabComingSoon`): «Планы» (P-1 матрица + 4 метрики: НП/Поступления-по-линейкам/Задачи/Конверсии), «Реестр+Дожим» (R1), «График НП» (R2), «Рейтинг» (R3), + R4 «Закрытие задач» / R5 «Конверсии» / R6 «Поступления по линейкам». Ф5 добавил R6 + **Excel-экспорт всех отчётов** (кнопка «Экспорт в Excel» на report-табах + «Планы»; 7 export-роутов, blob-download через `@/utils/download`). Полный статус фаз + as-built — контракт `docs/contracts/plan-targets-api-contract.md` §9 и `PLAN.md` (строка «Планы и отчёты»). Следующее по контуру: Ф6 МК-линк, Ф7 payment-fact (post-Finance). `type-check`+`lint:ds`+3944 PHPUnit зелёные, QA e2e PASS.
- **Dark-nit из Ф1-снапшота ниже — статус:** мёртвый `.app-dark &`-внутри-`:deep()` в `plans/PlanMatrix.vue` был отдельным треком; при финальном ревью Ф5 новый код (`MetricProductIncome.vue`) закон dark-селекторов соблюдает (top-level `.app-dark &`). Если исходный nit ещё в дереве — остаётся на frontender (не блок Ф5).

### (историческое) Ф1-снапшот — reviewer PASS-with-nits (2026-07-03)
- **Что сделано:** `DashboardPage/index.vue` переписан в хаб аналитики (таб-стрип `SelectButton` +
  `<keep-alive>` + `?tab=` + `AnalyticsFilterBar` сквозные фильтры). Старый Обзор перенесён **без
  изменений** в `TabOverview.vue` (те же 5 виджетов + `useDashboardPage` + `DashboardToolbar` — регресса
  нет). Живой таб «Планы» (`TabPlans` + `plans/PlanMatrix|PlanMatrixCurrencyCell|PlanSaveBar`,
  композаблы `useAnalyticsHub`/`usePlansTab`): inline-грид ввода плана НП, план/факт/%, мультивалюта,
  copy-previous, dirty-guard (route-leave + beforeunload + in-hub tab-switch через provide/inject).
  Таб «Планы» гейтится `plans.manage` (admin/director). Report-табы (Реестр/График/Рейтинг) — стабы
  `TabComingSoon` до Ф2–Ф5. Бэкенд — контракт `docs/contracts/plan-targets-api-contract.md` (Ф1
  `new_income scope=user` e2e). `type-check` + `lint:ds` зелёные.
- **Dark-nit к правке (frontender, параллельно):** `plans/PlanMatrix.vue` (~стр. 274-280) — `.app-dark &`
  вложен **внутри** `:deep(.plan-matrix__input-el--dirty)` = мёртвый dark-селектор (закон charter §«Обе
  темы», dead-pattern #1): navy-бордер dirty-инпута в тёмной теме не применяется. `lint:ds` это не ловит.
  Фикс — theme-reactive токен или non-scoped namespaced-блок.
- **Заметка по ТЗ:** `analytics-hub-tz.md` в шапке помечен «Ф0» (spec-фаза) — реализация закрывает
  Ф1-скоуп; остальные табы по ТЗ (реестр/дожим/NpCalendar/LeaderCard/RatingTable) ждут Ф2–Ф5.

## QA-напоминание
Проверяй ВИЗУАЛЬНОЕ соответствие эталону (а не только функциональность): отступы, цвета токенов,
светлая+тёмная темы, скрытые скроллбары, поведение интерактивных элементов из §11.
