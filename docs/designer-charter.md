# Designer Charter — MACRO Global CRM Frontend

> Это — настольная книга любого, кто пишет UI для MGCRM. Прочти её прежде чем создавать новый компонент, добавлять цвет или писать первый `px` в стилях. Документ актуален на 2026-07-02 и должен обновляться после каждой крупной поставки.

---

## 1. Источники истины (по типу вопроса)

| Что нужно | Куда смотреть |
|-----------|--------------|
| Значения токенов (цвет, типографика, отступы, радиусы) | `front/src/theme/scss/` — единственный ground truth |
| Визуальный замысел и раскладка конкретного экрана | Апрувнутые мокапы + спеки в `design-handoff/redesign/` (индекс — `design-handoff/redesign/HANDOFF.md`) |
| Бренд-инварианты и общая дизайн-система | `.claude/skills/macroglobal-design/` |
| Состав и поведение фич | `./examples/contracts/apps/web` (источник бизнес-логики, не стека) |
| API PrimeVue 4.5 (props, события, slots) | PrimeVue MCP: инструменты `list_components`, `get_component`, `get_example`, `get_component_tokens` |

### Что НЕ является источником кода

**21st.dev/magic** — React + Tailwind, чужой стек. Допустим только как рыхлая визуальная инспирация для компоновки. Код оттуда **не копируется**.

**Storybook** — отложен; живого экземпляра нет. До его появления источник истины по реальным компонентам — этот документ + файлы в `front/src/components/`.

---

## 2. Живой инвентарь shared-компонентов

Компоненты сгруппированы по назначению. Каждый описан: путь, назначение, ключевые props, когда применять.

---

### 2.1 Лейаут и навигация

#### `PageHeader`
**Путь:** `front/src/components/AppShell/PageHeader.vue`
**Назначение:** стандартная шапка листовых страниц (списки, справочники). Высота 56 px, padding 16 px 24 px, фон `$surface-card`, нижняя граница `$surface-200`.
**Props:** `title: string`, `subtitle?: string`, `icon?: string` (PrimeIcon class). Slot `#actions` — кнопки справа.
**Когда применять:** любая новая страница-список или страница справочника. НЕ используется на страницах сущностей (Deal/Contact/Company) — там своя шапка `EntityInfoHeader`.

#### `AppSidebar`
**Путь:** `front/src/components/AppShell/AppSidebar.vue`
**Назначение:** левая навигационная колонка. Фиксированный бренд-цвет `$sidebar-bg = #172747` — не меняется в dark-mode. Управляется через `front/src/shared/nav/navItems.ts`.
**Когда применять:** компонент оркестровый, не трогается при реализации фич. Новые пункты меню — только через `navItems.ts`.

#### `InfoPanel`
**Путь:** `front/src/components/crm/entity/InfoPanel.vue`
**Назначение:** сворачиваемая секция в правом рейле карточки сущности. Состояние (collapsed/expanded) сохраняется в localStorage по `panelKey`.
**Props:** `title: string`, `icon?: string` (PrimeIcon без префикса `pi`), `panelKey: string` (уникальный ключ), `defaultCollapsed?: boolean`, `count?: number | null`.
Slot `#header-action` — иконочные кнопки в заголовке (добавить связь и т.д.).
**Когда применять:** любая именованная группа полей/отношений в правом рейле: «Контакты», «Сделки», «Документы», «Кастомные поля», «История».

---

### 2.2 Данные и таблицы (display)

#### `KeyFactsBlock` + `KeyFactsItem`
**Пути:** `front/src/components/crm/entity/KeyFactsBlock.vue`, `KeyFactsItem.vue`
**Назначение:** grid-таблица ключ–значение (2 колонки: auto + 1fr). `KeyFactsItem` принимает prop `label: string`, слот — значение (в т.ч. `InlineEditableField`).
**Когда применять:** список полей сущности в InfoPanel (имя, телефон, email, geo, кастомные поля).

#### `EntityRow`
**Путь:** `front/src/components/crm/entity/EntityRow.vue`
**Назначение:** строка связанной сущности в InfoPanel (контакт в компании, компания у контакта и т.д.). Аватар + заголовок + подзаголовок + теги + quick-action слот.
**Props:** `title`, `subtitle?`, `icon?`, `linkTo?`, `isPrimary?: boolean`, `tagLabel?`, `tagSeverity?`.
Slots: `#avatar`, `#tags`, `#actions`.
**Когда применять:** любой список связанных объектов в рейле.

#### `EntityKpiStrip`
**Путь:** `front/src/components/crm/entity/EntityKpiStrip.vue`
**Назначение:** горизонтальная полоса KPI-пилюль под шапкой карточки сущности. Поддерживает loading-скелетон и 8 вариантов акцента (neutral/brand/success/teal/amber/info/warning/danger).
**Props:** `items: KpiItem[]`, `loading?: boolean`.
Интерфейс `KpiItem`: `{ key, icon: 'pi-...', label: i18n-ключ, value, accent?, tooltip?, clickable?, onClick? }`.
**Когда применять:** метрики карточки Deal, Contact, Company под `EntityInfoHeader`.

#### `EntityLogTab`
**Путь:** `front/src/components/crm/entity/EntityLogTab.vue`
**Назначение:** вкладка «История» с 2-колоночным grid метрик и плоским списком лог-строк. Принимает `log: UseEntityLogReturn` из composable `useEntityLog`.
**Props:** `log`, `metrics?: LogMetric[]`.
**Когда применять:** вкладка журнала на любой карточке сущности.

#### `EntityMiniTimeline`
**Путь:** `front/src/components/crm/entity/EntityMiniTimeline.vue`
**Назначение:** компактная версия лога (max N строк, ссылка «перейти в журнал») для отображения в InfoPanel или табе рейла.
**Props:** `log: UseEntityLogReturn`, `maxItems?: number` (по умолчанию 5), `onGoToLog?: () => void`.
**Когда применять:** превью истории в правом рейле, когда полный `EntityLogTab` доступен на другой вкладке.

---

### 2.3 Карточка сущности (entity-card shell)

#### `EntityInfoHeader`
**Путь:** `front/src/components/crm/entity/EntityInfoHeader.vue`
**Назначение:** шапка карточки контакта / компании. Фон бренд-инвариант `$brand-header-bg = #172747` (не меняется в dark-mode). Компоновка: один flex-ряд avatar-col → info-col → control-col.
**Props:** `entityId`, `title`, `subtitle?`, `authorName?`, `companyName?`, `responsibleName?`, `categoryCode?`, `engagementTier?`, `lastActivityAt?`, `menuItems: MenuItem[]`, `tags?`, `avatarInitials?`, `sourceLabel?`, `createdAt?`, `updatedAt?`.
Emit: `back`.
Slots: `#status` (ClientStatusBadge), `#meta` (дополнительные мета-элементы).
**Когда применять:** ContactPage, CompanyPage. На DealPage — аналогичная структура, но специфичная (DealCard).

#### `EntityAvatar`
**Путь:** `front/src/components/crm/entity/EntityAvatar.vue`
**Назначение:** единственный аватар-компонент проекта (backlog #26: `CrmAvatar` удалён, объединён сюда). Круглый или квадратный аватар с инициалами. Цвет фона: при наличии `entityId` — детерминированный из палитры 8 фирменных оттенков (`entityId % 8`); без `entityId` — `$primary-900`. В `onBrand`-режиме (на navy-панели) — полупрозрачный белый фон. Инициалы: авто-вычисляются из `name` (до 3 слов), либо передаются напрямую через `initials`. Размер: именованный (`sm` 32 / `md` 56 / `lg` 72 px) или произвольный через `pixelSize` (перекрывает `size`).
**Props:** `name?: string`, `initials?: string`, `size?: 'sm' | 'md' | 'lg'` (default `'md'`), `pixelSize?: number`, `entityId?: number`, `onBrand?: boolean`, `square?: boolean`.
**Миграция с CrmAvatar:** старый `CrmAvatar :size="N"` → `EntityAvatar :pixel-size="N"`; `name` и `square` — те же. Без `entityId` поведение идентично (фон `$primary-900`).
**Когда применять:** шапки карточек (`EntityInfoHeader` — `size="md" on-brand`), строки таблиц (`:pixel-size="32"` или `22`), профиль (`:pixel-size="72"`), диалоги дедупликации. Везде — один компонент.

#### `EntityActionMenu`
**Путь:** `front/src/components/crm/entity/EntityActionMenu.vue`
**Назначение:** тонкая обёртка над PrimeVue `Menu` (popup). Управляется через `ref.toggle($event)`.
**Props:** `items: MenuItem[]`.
**Когда применять:** меню «⋮» в control-col шапки карточки.

#### `EntityComposer`
**Путь:** `front/src/components/crm/entity/EntityComposer.vue`
**Назначение:** блок создания заметки / задачи внизу ленты активностей. Два режима (note / task), фиксированная ширина кнопок-режимов 108 px.
**Props:** `entityType: 'company' | 'contact'`, `entityId: number`, `usersList?: Array<{id, name}>`.
Emit: `created: [ActivityDto]`.
Expose: `focusNote()`, `focusTask()`.
**Когда применять:** ContactPage и CompanyPage (нижняя зона Activities). На DealPage аналог — DealComposer.

#### `EntityActivitiesTab`
**Путь:** `front/src/components/crm/entity/EntityActivitiesTab.vue`
**Назначение:** полная лента активностей: фильтр-чипы (Все / События / Изменения), bottom-up feed, `OpenTasksList`, `EntityComposer`.
**Когда применять:** центральная зона карточек Contact/Company.

#### `EntityFilesTab`
**Путь:** `front/src/components/crm/entity/EntityFilesTab.vue`
**Назначение:** двухпанельный таб файлов (левая колонка — папки, правая — файлы). Включает кнопки создания папки и загрузки.
**Когда применять:** вкладка «Файлы» на карточках сущностей.

---

### 2.4 Формы и инпуты

#### `InlineEditableField`
**Путь:** `front/src/components/crm/InlineEditableField.vue`
**Назначение:** поле вида «отображение → двойной клик → редактирование». Режимы: `text`, `textarea`, `select`, `geo-country`, `geo-city`.
**Props:** `modelValue`, `fieldKey`, `fieldType?`, `options?`, `optionLabel?`, `optionValue?`, `saving?`, `placeholder?`, `countryCode?`.
Emit: `save: [fieldKey, value]`.
**Когда применять:** все редактируемые поля в `KeyFactsBlock` (контакт/компания/сделка).

#### `CustomFieldRenderer`
**Путь:** `front/src/components/crm/entity/CustomFieldRenderer.vue`
**Назначение:** авто-рендеринг кастомных полей сущности по схеме из API. Поддерживает типы: text, textarea, number, url, date, select, multiselect, boolean, user_ref. Использует `KeyFactsBlock` + `KeyFactsItem` + `InlineEditableField` внутри.
**Props:** `entityScope: CustomFieldScope`, `entityId`, `extraFields: Record<string, unknown>`, `onSave: (code, value) => Promise<void>`, `users?`.
**Когда применять:** секция «Кастомные поля» в InfoPanel любой сущности. Новые типы полей добавляются только здесь, не дублируются в страницах.

#### `DateField`
**Путь:** `front/src/components/crm/DateField.vue`
**Назначение:** поле ввода даты с авто-форматированием ДД.ММ.ГГГГ и выпадающим календарём. Эмитит ISO-строку (`YYYY-MM-DD`) или null. Поддерживает ограничение `min`.
**Props:** `modelValue?: string | null`, `placeholder?`, `min?: string | null`.
**Когда применять:** дата в `EntityComposer`, дата задачи в формах. Для полноценных форм создания записей — PrimeVue `DatePicker` напрямую.

#### `SearchPicker`
**Путь:** `front/src/components/crm/SearchPicker.vue`
**Назначение:** компактный select с фильтрацией по вводу. Открывает popover с поиском и списком. Используется в composer-компонентах для выбора типа задачи и ответственного.
**Props:** `modelValue?`, `options: PickerOption[]`, `optionLabel?`, `optionValue?`, `placeholder?`, `displayLabel?`.
Slots: `#trigger-content`, `#option`.
**Кандидат на замену:** функционально близок к PrimeVue `Select` с `filter`. Рекомендуется использовать `Select filter show-clear fluid` там, где нет жёстких размерных ограничений composer'а.

---

### 2.5 Статусы и тэги (badge/tag-компоненты)

Все статусные тэги строятся поверх PrimeVue `Tag` c `severity`. Новые статус-тэги должны следовать этому же шаблону — отдельный SFC с `STATUS_CONFIG: Record<Status, { severity, icon }>`.

#### `DocumentStatusTag`
**Путь:** `front/src/components/shared/DocumentStatusTag.vue`
**Props:** `status: ContractStatus`, `archived?: boolean`.
Статусы: draft / submitted / in_review / needs_rework / approved / rejected / signed / uploaded / archived.

#### `AssignmentStatusTag`
**Путь:** `front/src/components/shared/AssignmentStatusTag.vue`
**Props:** `status: AssignmentStatus`.
Статусы: pending / in_progress / completed / overdue / archived.

#### `CourseStatusTag`
**Путь:** `front/src/components/shared/CourseStatusTag.vue`
**Props:** `isPublished: boolean`.

#### `DealStageTag`
**Путь:** `front/src/pages/DealPage/components/DealStageTag.vue`
**Назначение:** тэг текущей стадии сделки с цветовым маппингом.

#### `ChannelKindTag`
**Путь:** `front/src/components/inbox/ChannelKindTag.vue`
**Props:** `kind: ChannelKind` (tg / wa / email / web_form / api), `size?`, `showLabel?`.
Каждый канал имеет свою цветовую пару (bg + text) в light и dark темах через `$blue-100/$blue-900` и т.д.

#### `ClientStatusBadge`
**Путь:** `front/src/components/crm/ClientStatusBadge.vue`
**Назначение:** тэг клиентского статуса компании (active / disconnected) с Popover-историей изменений.
**Props:** `status: ClientStatus | null`, `since?`, `disconnectedAt?`, `companyId?`.

#### `EngagementChip`
**Путь:** `front/src/components/crm/entity/EngagementChip.vue`
**Назначение:** чип вовлечённости (fresh / cooling / cold) с v-tooltip. В `dotOnly`-режиме — цветная точка для ячейки списка.
**Props:** `tier: EngagementTier`, `lastActivityAt?`, `dotOnly?`.

---

### 2.6 Оверлеи и диалоги

#### `DecideDialog`
**Путь:** `front/src/components/shared/DecideDialog.vue`
**Назначение:** модалка «решение с комментарием» (28rem, PrimeVue Dialog). Комментарий обязателен при `required=true`.
**Props:** `modelValue: boolean`, `loading?`, `required?`.
Emit: `confirm: [comment]`.
**Когда применять:** согласование/отклонение документов, любое действие требующее ввод причины.

#### `MergeDialog`
**Путь:** `front/src/components/crm/dedup/MergeDialog.vue`
**Назначение:** диалог объединения сущностей (860px). Режимы `dedup` (сканирование дублей) и `bulk` (слияние выбранных записей).
**Когда применять:** дедупликация контактов и компаний.

#### `CreateContactInlineDialog`
**Путь:** `front/src/components/crm/CreateContactInlineDialog.vue`
**Назначение:** быстрое создание контакта из контекста сделки без перехода на отдельную страницу.

#### `ActivityFormDialog`
**Путь:** `front/src/components/ActivityFormDialog.vue`
**Назначение:** полная форма создания/редактирования активности в Dialog.

#### `MeetingReportDialog`
**Путь:** `front/src/components/MeetingReportDialog.vue`
**Назначение:** диалог отчёта по встрече.

#### `ChannelHistoryDrawer`
**Путь:** `front/src/components/crm/ChannelHistoryDrawer.vue`
**Назначение:** Drawer с историей переписки по каналу.

---

### 2.7 Обратная связь и состояния

#### `TaskQuickForm`
**Путь:** `front/src/components/tasks/TaskQuickForm.vue`
**Назначение:** AMO-style компактная карточка задачи. Режимы: `create` (новая задача) и `complete` (результат + перенос). Entity-agnostic — принимает `targetType`/`targetId`.

#### `OpenTasksList`
**Путь:** `front/src/components/crm/entity/OpenTasksList.vue`
**Назначение:** компактный список открытых задач над composer'ом. Строки кликабельны для раскрытия, поддерживает inline-выполнение.

---

### 2.8 Навигация (Orbita)

#### `Orbita` + `OrbitaPanel` + `CommandPalette`
**Путь:** `front/src/components/Orbita/`
**Назначение:** плавающая панель быстрых действий (аналог Toolbox в Vizion). Содержит CommandPalette, QuickActionsCluster, NotificationsButton, HotkeysCheatsheet.
**Когда применять:** оркестровые компоненты, не трогаются при реализации фич. Новые быстрые действия — через `front/src/shared/nav/quickActionRegistry.ts`.

---

### 2.9 Хелперы (shared, не компоненты)

#### `taskKindColors.ts`
**Путь:** `front/src/shared/taskKindColors.ts`
**Экспорты:**
- `TASK_KIND_COLORS: Partial<Record<ActivityKind, string>>` — карта вид→hex (brand-константы).
- `taskKindColor(kind) → string` — цвет для иконки в picker'е.
- `taskKindChipStyle(kind, isDark) → CSSProperties` — inline-стиль chip'а (light/dark через `color-mix`).
- `TASK_BUCKET_COLORS: Record<MyBoardBucket, string>` — цвета колонок Kanban задач.
**Когда применять:** любой компонент, который красит иконку или фон типа задачи. НЕ дублировать цвета в компонентах.

#### `navItems.ts`
**Путь:** `front/src/shared/nav/navItems.ts`
**Назначение:** декларативный список пунктов сайдбара (icon, label, to, roles).

#### `quickActionRegistry.ts`
**Путь:** `front/src/shared/nav/quickActionRegistry.ts`
**Назначение:** реестр действий Orbita.

---

### 2.10 Near-дубли и кандидаты на дрейф (требуют внимания)

| Проблема | Что делать |
|----------|-----------|
| ~~`EntityAvatar` vs `CrmAvatar`~~ — **ЗАКРЫТО** (backlog #26, 2026-07-02): `CrmAvatar` удалён, API объединён в `EntityAvatar`. | — |
| `SearchPicker` vs PrimeVue `Select filter` — пересекающийся функционал | В новых компонентах предпочитать `Select filter` (PrimeVue native). `SearchPicker` оставить только в `EntityComposer` / `DealComposer` где нужна кастомная компоновка trigger'а. |
| `DateField` vs PrimeVue `DatePicker` — два поля даты | `DateField` — compact inline (в composer'е, в ячейке таблицы). `DatePicker` — в стандартных формах. Не использовать `DateField` в Dialog/Drawer-формах. |
| Статус-тэги (DocumentStatusTag / AssignmentStatusTag / CourseStatusTag / DealStageTag) имеют идентичную структуру STATUS_CONFIG | Потенциальный universal `StatusTag` компонент через generic props. Пока — держать разделёнными (домен-специфичные типы). |

---

## 3. Маппинг «нужда → компонент»

| Задача | Решение |
|--------|---------|
| Список записей (контакты, компании, сделки, продукты) | PrimeVue `DataTable` + фильтры в `PageHeader` или отдельной панели |
| Шапка страницы-списка | `AppShell/PageHeader` (`title` + `icon` + `#actions`) |
| Шапка карточки сущности (Contact/Company) | `EntityInfoHeader` (navy-панель) |
| KPI-полоса под шапкой карточки | `EntityKpiStrip` |
| Секция в правом рейле | `InfoPanel` (`panelKey` обязателен) |
| Пара ключ-значение в рейле | `KeyFactsBlock` + `KeyFactsItem` |
| Редактируемое поле inline | `InlineEditableField` |
| Рендер кастомных полей | `CustomFieldRenderer` (готов, переиспользуй) |
| Статус документа | `DocumentStatusTag` |
| Статус назначения курса | `AssignmentStatusTag` |
| Статус курса | `CourseStatusTag` |
| Статус клиента компании | `ClientStatusBadge` (с историей) |
| Вовлечённость контакта/компании | `EngagementChip` |
| Тип канала входящего | `ChannelKindTag` |
| Строка связанной сущности в рейле | `EntityRow` |
| Аватар сущности с initials | `EntityAvatar` (с `entityId` — палитра; без — `$primary-900`; `square` для компаний в таблице) |
| Лента активностей + composer | `EntityActivitiesTab` |
| Composer заметки/задачи | `EntityComposer` |
| Список открытых задач | `OpenTasksList` |
| Журнал истории | `EntityLogTab` |
| Мини-превью журнала | `EntityMiniTimeline` |
| Меню действий «⋮» | `EntityActionMenu` (обёртка PrimeVue Menu) |
| Подтверждение удаления | PrimeVue `ConfirmDialog` + `useConfirm` |
| Действие с комментарием (отклонить, на доработку) | `DecideDialog` |
| Объединение дублей | `MergeDialog` |
| Форма создания активности | `ActivityFormDialog` |
| Toast-уведомление | `useToast().add(...)` |
| Загрузка (inline) | PrimeVue `Skeleton` (для блоков) / `ProgressSpinner` (оверлей) |
| Empty-state | icon (`$font-size-icon-xl`) + заголовок + hint + CTA-кнопка |
| Выбор из списка (форма) | PrimeVue `Select` (с `filter` при >10 элементов) |
| Мультивыбор | PrimeVue `MultiSelect` |
| Дата в форме | PrimeVue `DatePicker` |
| Дата в composer'е / ячейке | `DateField` |
| Select с поиском в compact-режиме | `SearchPicker` (только в composer-контексте) |
| Графики / аналитика | `vue-echarts` (ECharts). Никакого Chart.js. |
| Цвет типа задачи | `taskKindColor()` / `taskKindChipStyle()` из `shared/taskKindColors.ts` |

---

## 4. Токен-дисциплина

### Правило без исключений

В `.vue` и `.scss` — **всегда переменная репо**, никогда литерал hex/px/rem.

```scss
// ПРАВИЛЬНО
color: $surface-700;
background: $surface-card;
padding: $space-3 $space-4;
border-radius: $radius-md;
font-size: $font-size-sm;

// НЕПРАВИЛЬНО
color: #64748b;
padding: 12px 16px;
border-radius: 6px;
```

### Единственные допустимые хардкоды

Это **бренд-инварианты** — задокументированы в кодовой базе с комментарием `// brand header invariant` или `// brand invariant`:

| Значение | Где | Почему |
|----------|-----|--------|
| `#172747` | `$sidebar-bg`, `$brand-header-bg`, `$primary-900` | Сайдбар и шапка карточки сделки не адаптируются к теме |
| `rgba(255,255,255,0.12)` | hover на navy-панели, тег на navy-шапке | Полупрозрачный белый на тёмном бренд-фоне |
| `rgba(255,255,255,0.14)` | аватар на navy-панели | То же |
| Цвета `TASK_KIND_COLORS` в `taskKindColors.ts` | brand-акценты типов задач | Зафиксированы как константы, видны в TS-файле (stylelint не смотрит TS) |

### Переменные поверхностей

```scss
// Карточки и панели (реактивный, НЕ $surface-0)
background: $surface-card;     // light: #fff, dark: surface-100 = #444547

// Hover в рейле/InfoPanel
background: var(--mg-surface-hover); // light: surface-50, dark: #3a3b3d

// Кастомная карточка kanban/list
background: var(--mg-surface-card);  // то же, но через CSS custom property для color-mix()
```

### Шкала отступов

`$space-1` (4px) → `$space-2` (8px) → `$space-3` (12px) → `$space-4` (16px) → `$space-5` (20px) → `$space-6` (24px) → `$space-7` (28px) → `$space-8` (32px).

Используй шаг на основе сетки 4px. Промежуточные значения (3px, 6px, 7px, 13px) допустимы только как бренд-инварианты с явным комментарием.

### Шкала радиусов

`$radius-sm` → `$radius-md` → `$radius-lg` → `$radius-xl` → `$radius-pill` (999px) → `$radius-circle` (50%).
Мелкие константы: `$radius-xs` (3px, тайт-бейдж), `$radius-badge` (9px, пилюля с цифрой), `$radius-2xs` (2px, hairline).

### Типографика

```scss
$font-size-3xs  // 10px — micro-label, rotting-chip
$font-size-2xs  // 11px — badge count, tight caption
$font-size-xs   // 12px — метка в KeyFactsItem, временная метка
$font-size-sm   // ~13-14px — основной текст в компонентах, кнопки
$font-size-md   // ~15px — значение метрики
$font-size-lg   // ~16px — крупный текст
$font-size-xl   // ~18px — заголовок PageHeader
```

Иконки используют отдельную шкалу: `$font-size-icon-sm` (18px) → `$font-size-icon-lg` (32px, секция empty) → `$font-size-icon-xl` (40px, страница empty).

### Обе темы — обязательно

Каждый компонент с кастомным цветом должен иметь dark-вариант:

```scss
.my-component {
  background: $surface-card;       // авто через $surface-card
  color: $surface-700;             // light
  border: 1px solid var(--p-surface-200);

  .app-dark & {
    color: var(--p-surface-200);   // dark — инвертированная шкала!
    border-color: var(--p-surface-700);
  }
}
```

> **Важно об инвертированной шкале dark-темы:** в `.app-dark` шкала surface PrimeVue инвертирована — `surface-100` в dark-теме тёмный (#444547), а `surface-900` — светлый (#F8F9FA). Для текста в dark-режиме используй `surface-100`/`surface-200` (светлые), не `surface-700`/`surface-800`.

### Деньги

Формат: `1 200 000 ₽` (пробел как разделитель тысяч, символ рубля). Используй `Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB' })`.

### Иконки

Только PrimeIcons: `pi pi-*`. Никакого Font Awesome, никаких эмодзи, никаких SVG-файлов в компонентах (кроме логотипа).

### Что запрещено

- Hex-цвета вне токенов и бренд-инвариантов
- Tailwind-классы (`text-sm`, `bg-gray-100`, `flex items-center` и т.д.) — только Bootstrap-grid (`row`, `col-md-6`, `d-flex`, `gap-3`)
- Градиенты
- Цветные тени (только `$shadow-sm`, `$shadow-lg` из токенов)
- `bluish-purple` оттенки вне palette
- Новые hex-цвета без явного добавления в `_colors.scss`

---

## 5. Формат компонентного ТЗ

Designer выдаёт фронтендеру ТЗ в следующем формате. Фронтендер реализует строго по нему, не додумывает.

```markdown
## ТЗ: <Название компонента / экрана>

**Зачем:** <Бизнес-цель, одно предложение>
**Где в коде:** `front/src/pages/<Page>/components/<Component>.vue`
**Reuse:** <список существующих компонентов, которые используются внутри>

### Wireframe (ASCII)
[Схема раскладки с обозначением зон]

### Зоны и компоненты
| Зона | Компонент / элемент | Props / атрибуты |
|------|---------------------|-----------------|
| Заголовок | `PageHeader` | `title="…" icon="pi pi-…"` `#actions` |
| ... | ... | ... |

### Состояния
- **loading:** `Skeleton` (N строк по X px) или `ProgressSpinner` (overlay)
- **empty:** иконка `pi-…` `$font-size-icon-xl` + заголовок + hint + CTA `Button severity="secondary" outlined`
- **error:** `Toast severity="error"` / `Message severity="error"` + retry

### Interactions
| Элемент | Действие | Результат | Endpoint |
|---------|----------|-----------|---------|
| Кнопка «Создать» | click | открывает Drawer | — |
| Строка таблицы | click | router.push к карточке | — |
| ... | ... | ... | `PATCH /api/v1/...` |

### Токены и компоненты
- Отступы: `$space-4 $space-6` (шапка), `$space-3 $space-4` (тело)
- Радиусы: `$radius-md` (карточки), `$radius-sm` (инпуты)
- Цвета: `$surface-card` (фон), `$surface-700` / dark: `var(--p-surface-200)` (текст)
- PrimeVue: `DataTable`, `Button`, `Tag severity="…"`, `Dialog`

### i18n-ключи
```json
{
  "ru": {
    "domain.entity.action": "Действие",
    "domain.entity.empty.title": "Нет записей",
    "domain.entity.empty.hint": "Создайте первую запись"
  },
  "en": {
    "domain.entity.action": "Action"
  }
}
```

### Открытые вопросы
1. [ОВ-1] Нужен ли фильтр по дате или только по статусу?
2. Требуется backend: `GET /api/v1/...` (поле X пока не возвращается)
```

---

## 6. Контроль качества

### Автоматический гейт

```bash
npm run lint:ds
```

Запускается в pre-commit (husky) и CI. Проверяет через stylelint:
- Запрет литеральных hex-значений вне `_colors.scss` (правило `scale-unlimited/declaration-strict-value`)
- Запрет Tailwind-классов
- Форматирование SCSS

Если lint падает — коммит блокируется.

### Визуальный гейт (qa-tester)

После каждой реализации, затрагивающей UI, `qa-tester` проверяет:
1. **Обе темы** — computed-styles в light (`body`) и dark (`.app-dark`).
2. **Токены** — цвета через DevTools → `getComputedStyle` (не должно быть литеральных hex).
3. **Состояния** — loading, empty, error воспроизведены и выглядят корректно.
4. **Тёмная тема** — особое внимание инвертированной шкале surface (текст на тёмном фоне читаем).

Визуальное отклонение от апрувнутого мокапа = FAIL.

---

## 7. Рабочий процесс designer → frontend-specialist

```
[запрос фичи]
  → designer читает § 2 (есть ли готовый компонент?)
  → если есть — указывает в ТЗ какой и с какими props
  → если нет — обосновывает создание нового (§ 5 «Открытые вопросы» или отдельная строка)
  → пишет ТЗ по формату § 5
  → frontend-specialist реализует строго по ТЗ
  → qa-tester проверяет по § 6
  → designer при необходимости правит ТЗ (не код)
```

**Designer НЕ пишет код.** Если фронтендер сталкивается с UX-неоднозначностью при реализации — возвращает вопрос designer'у, не решает самостоятельно.

---

## 8. Как добавить новый компонент в инвентарь

1. Компонент создан и принят на ревью.
2. Designer обновляет раздел § 2 этого документа: добавляет описание по шаблону (путь, назначение, props, когда применять).
3. Если компонент решает задачу, для которой раньше не было решения — добавить строку в § 3 «Маппинг».
4. Если компонент создаёт потенциальный дубль — отметить в § 2.10 «Near-дубли».

---

*Последнее обновление: 2026-07-02. Владелец: agent `designer` (DS-owner). Следующее плановое обновление: после поставки Entity Card 2.0 + Contacts List 2.0.*
