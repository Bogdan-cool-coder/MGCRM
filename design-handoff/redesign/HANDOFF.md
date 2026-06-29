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

## QA-напоминание
Проверяй ВИЗУАЛЬНОЕ соответствие эталону (а не только функциональность): отступы, цвета токенов,
светлая+тёмная темы, скрытые скроллбары, поведение интерактивных элементов из §11.
