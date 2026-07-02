# Handoff — обновление CRM-экранов (для Claude Code)

Этот пакет — эталонные макеты (HTML) и технические задания (MD) для пересборки экранов MGCRM.
Положи папку в репозиторий: `/Users/bogdanadykin/Desktop/Claude/MGCRM/design-handoff/`.

## Состав
- **Эталоны (открыть в браузере):** `deal-card.html`, `entity-card.html` (контакт/компания),
  `contacts.html` (список), `sales-funnel.html` (воронка), `tasks.html` (задачник) + `styles.css`,
  `tweaks-panel.jsx`.
- **ТЗ (источник правды):** `DealCard-spec.md`, `EntityCard-spec.md`, `Contacts-spec.md`,
  `SalesFunnel-spec.md`, `Tasks-spec.md`, `Inbox-spec.md`.

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

## QA-напоминание
Проверяй ВИЗУАЛЬНОЕ соответствие эталону (а не только функциональность): отступы, цвета токенов,
светлая+тёмная темы, скрытые скроллбары, поведение интерактивных элементов из §11.
