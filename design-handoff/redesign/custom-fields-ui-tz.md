# ТЗ: Управление кастомными полями (CustomFields Management UI)

**Зачем:** Admin/Director управляет схемой дополнительных полей (CustomFieldDef) для сущностей
Deal / Contact / Company / Contract — создаёт, редактирует, переупорядочивает и деактивирует
без правки кода; менеджеры видят поля на карточках автоматически.

**Где в коде:**
- Экран управления: `front/src/pages/CustomFieldsPage/` (новая страница, embedded-паттерн)
- Таб в настройках: `front/src/pages/SettingsPage/components/sections/directories/DirTabCustomFields.vue`
- Рендер в карточках: `front/src/components/crm/entity/CustomFieldRenderer.vue` (существующий — доработка)

**Источник фич (old):**
`examples/contracts/apps/api/app/routers/custom_fields.py` +
`examples/contracts/apps/api/app/services/custom_fields.py`

**Reuse:**
`PageHeader`, `DataTable` / `Column`, `Card`, `Dialog`, `Button`, `InputText`, `InputNumber`,
`Select`, `MultiSelect`, `ToggleSwitch`, `Tag`, `Skeleton`, `ConfirmDialog`, `useConfirm`,
`useMutation`, `useTagsPage` (composable-паттерн), `DirTabTags` (embedded-паттерн),
`CustomFieldRenderer` (расширить), `KeyFactsBlock`, `KeyFactsItem`, `InlineEditableField`

---

## Часть 1 — Экран управления CustomFieldsPage

### Wireframe (ASCII)

```
┌──────────────────────────────────────────────────────────────┐
│  PageHeader (pi pi-sliders-h)  «Кастомные поля»             │
│                                          [+ Добавить поле]   │
├──────────────────────────────────────────────────────────────┤
│  ┌────────────────────────────────────────────────────────┐  │
│  │ Card                                                   │  │
│  │  Scope-фильтр (TabList line-style):                    │  │
│  │  [Все] [Сделки] [Контакты] [Компании] [Договоры]       │  │
│  │ ─────────────────────────────────────────────────────  │  │
│  │  DataTable (sortable, row-hover, size="small"):         │  │
│  │  ☰  │ Название    │ Тип    │ Сущность │ Обязат. │ Акт.│ ⋯│  │
│  │  ─  │ ───────────│ ──────│ ────────│ ───────│ ───│───│  │
│  │  ⠿  │ ИНН        │ text   │ Компания │  ●     │ ✓  │ … │  │
│  │  ⠿  │ Дата начала │ date  │ Сделка   │        │ ✓  │ … │  │
│  │  ⠿  │ Тариф      │ select │ Сделка   │  ●     │ ✓  │ … │  │
│  └────────────────────────────────────────────────────────┘  │
└──────────────────────────────────────────────────────────────┘

[Dialog — создать / редактировать поле, 32rem]
┌─────────────────────────────────┐
│ Добавить кастомное поле      ×  │
├─────────────────────────────────┤
│ Название *                      │
│ [InputText________________]     │
│                                 │
│ Код (snake_case) *              │
│ [InputText________________]     │
│ < Не изменяется после создания  │
│                                 │
│ Сущность *                      │
│ [Select: Сделка / Контакт / …]  │
│                                 │
│ Тип поля *                      │
│ [Select: Текст / Число / …]     │
│                                 │
│ Варианты (для select/multiselect)│
│ [Chips-input: добавить + Enter] │
│                                 │
│ Порядок сортировки              │
│ [InputNumber: 0]                │
│                                 │
│ ○ Обязательное поле             │
│ ○ Активно                       │
├─────────────────────────────────┤
│          [Отмена] [Сохранить]   │
└─────────────────────────────────┘
```

### Зоны и компоненты

| Зона | Компонент / элемент | Props / атрибуты |
|------|---------------------|-----------------|
| Шапка страницы | `PageHeader` | `title="Кастомные поля"` `icon="pi pi-sliders-h"` `#actions` |
| Кнопка создания | `Button` (в `#actions`) | `icon="pi pi-plus"` `label="Добавить поле"` `@click="openCreate"` — видна только admin/director |
| Фильтр-табы | PrimeVue `Tabs` + `TabList` + `Tab` (line-style) | values: `all / deal / contact / company / contract`; под TabList — `DataTable` |
| Карточка-обёртка | PrimeVue `Card` | — |
| Список полей | PrimeVue `DataTable` | `:value="filteredList"` `:loading="loading"` `row-hover` `size="small"` |
| Колонка перетаскивания | `Column` (иконка ⠿) | `style="width:40px"` `pi pi-ellipsis-v` — курсор grab; drag=только в пределах scope (UX: перетаскивание без DnD-библиотеки, через row-reorder DataTable) |
| Колонка Название | `Column` | header `t('customFields.columns.label')` |
| Колонка Тип | `Column` | `style="width:120px"` → `FieldKindTag` (новый, см. §Новые компоненты) |
| Колонка Сущность | `Column` | `style="width:110px"` → scope-пилюля (аналог `tags-page__scope-badge`) |
| Колонка Обязательное | `Column` | `style="width:90px"` → иконка `pi pi-circle-fill` (●) если true, иначе пусто |
| Колонка Активно | `Column` | `style="width:90px"` → `ToggleSwitch` для admin/director, `pi pi-check` / `pi pi-times` иначе |
| Колонка Действия | `Column` | `style="width:80px"` → кнопки `pi pi-pencil` (text/secondary/small) + `pi pi-trash` (text/danger/small); скрыта для не-admin/director |
| Empty-state | `DataTable #empty` slot | иконка `pi pi-sliders-h` + «Нет полей» + кнопка «Добавить» (text/secondary, если canManage) |
| Диалог | PrimeVue `Dialog` | `:style="{ width: '32rem' }"` `:draggable="false"` `modal` |
| Поле Название | `InputText` | `v-model="form.label"` `class="w-100"` `:class="{'p-invalid': errors.label}"` `autofocus` (при create) `maxlength="255"` |
| Поле Код | `InputText` | `v-model="form.code"` `class="w-100"` `:disabled="isEditing"` `placeholder="snake_case"` `:class="{'p-invalid': errors.code}"` `maxlength="64"` |
| Hint под кодом | `small` / `.cf-dialog__hint` | «Код нельзя изменить после создания» — `color: var(--p-text-muted-color)` `font-size: $font-size-xs` |
| Поле Сущность | PrimeVue `Select` | `v-model="form.entity_scope"` `:options="scopeOptions"` `option-label="label"` `option-value="value"` `:disabled="isEditing"` `class="w-100"` |
| Поле Тип | PrimeVue `Select` | `v-model="form.kind"` `:options="kindOptions"` `option-label="label"` `option-value="value"` `class="w-100"` |
| Блок Варианты (conditional) | `v-if="needsOptions"` → `InputChips` (PrimeVue) | `v-model="form.options_json"` `add-on-blur` `separator=","` `class="w-100"` — показывается только когда `kind === 'select' || kind === 'multiselect'` |
| Поле Порядок | PrimeVue `InputNumber` | `v-model="form.sort_order"` `:min="0"` `class="w-100"` |
| Чекбокс Обязательное | `ToggleSwitch` + `label` | `v-model="form.is_required"` |
| Чекбокс Активно | `ToggleSwitch` + `label` | `v-model="form.is_active"` |
| Ошибки полей | `small.p-error` | под каждым невалидным полем |
| Footer диалога | `Button` x2 | Отмена: `severity="secondary"` `text` `@click="cancel"`; Сохранить: `:loading="saveMutation.isPending.value"` `@click="submit"` |
| Confirm удаления | PrimeVue `ConfirmDialog` + `useConfirm` | стандартный паттерн из `useTagsPage` |

### Ограничения полей формы

| Поле | Правило |
|------|---------|
| `label` | Обязательно, 1–255 символов |
| `code` | Обязательно при create, 1–64, regex `[a-z][a-z0-9_]*` (snake_case). Disabled при edit |
| `entity_scope` | Обязательно. Disabled при edit (нарушит data integrity в extra_fields) |
| `kind` | Обязательно |
| `options_json` | Показывается только для kind=select или multiselect; каждый элемент строка 1–255 |
| `sort_order` | Integer ≥ 0, default 0 |

### Новые компоненты (обоснование)

**`FieldKindTag`** — `front/src/components/crm/FieldKindTag.vue`
Аналог `tags-page__scope-badge`: отображает тип поля иконкой + лейблом. Таблица маппинга:

| kind | icon | label RU | цвет (light/dark) |
|------|------|----------|-------------------|
| text | pi-align-left | Текст | surface-100 / surface-700 |
| textarea | pi-list | Текстовая область | surface-100 / surface-700 |
| number | pi-hashtag | Число | blue-50 / blue-700 / dark: blue-950 / blue-300 |
| date | pi-calendar | Дата | orange-50 / orange-700 / dark: orange-950 / orange-300 |
| select | pi-chevron-down | Список | teal-50 / teal-700 / dark: teal-950 / teal-300 |
| multiselect | pi-list-check | Мультисписок | teal-50 / teal-700 / dark: teal-950 / teal-300 |
| url | pi-link | Ссылка | purple-50 / purple-700 / dark: purple-950 / purple-300 |
| checkbox | pi-check-square | Чекбокс | green-50 / green-700 / dark: green-950 / green-300 |
| user_ref | pi-user | Пользователь | indigo-50 / indigo-700 / dark: indigo-950 / indigo-300 |

Props: `kind: CustomFieldKind`, `showIcon?: boolean = true`, `showLabel?: boolean = true`.
Структура: `<span class="field-kind-tag field-kind-tag--{kind}"><i class="pi pi-{icon}" /><span>…</span></span>`.

Обоснование нового компонента: `DealStageTag` и `tags-page__scope-badge` не покрывают типы полей; компонент будет переиспользован в диалоге (превью типа) и в карточках сущностей.

**`DirTabCustomFields`** — `front/src/pages/SettingsPage/components/sections/directories/DirTabCustomFields.vue`
Embedded-обёртка по паттерну `DirTabTags.vue`: toolbar с кнопкой «Добавить» + `<CustomFieldsPage :embedded="true" />`. Не является новым самостоятельным компонентом, это обёртка; обоснование не требуется.

### States

**loading:** `DataTable` `:loading="loading"` — встроенный Skeleton DataTable.

**empty (нет ни одного поля для scope):**
```
иконка pi-sliders-h  ($font-size-icon-xl, $surface-300)
«Кастомных полей нет»  ($font-size-md, $surface-500)
«Создайте первое поле для этой сущности»  ($font-size-sm, $surface-400)
[Кнопка «Добавить поле» severity="secondary" outlined icon="pi pi-plus"] — только canManage
```

**error (не удалось загрузить):**
`Toast severity="error"` summary=«Ошибка загрузки» + retry-кнопка в строке.

**saving (Dialog):** кнопка «Сохранить» `:loading="saveMutation.isPending.value"`.

**code-conflict (422):** под полем Код — `<small class="p-error">Поле с таким кодом уже существует для этой сущности</small>`.

---

### Интеграция в настройки

`CustomFieldsPage` встраивается в `SectionDirectories.vue` как новый таб **«Кастомные поля»** после «Теги» (позиция: до «Источники»). Доступ: только admin/director (как другие admin-табы). Роут не нужен — таб работает через `?section=directories&tab=custom-fields`.

Изменения в `SectionDirectories.vue`:
1. Добавить `<Tab v-if="isAdminOrDirector" value="custom-fields">{{ t('settings.directories.tabs.customFields') }}</Tab>`
2. Добавить `<DirTabCustomFields v-else-if="activeTab === 'custom-fields' && isAdminOrDirector" />`
3. Добавить import `DirTabCustomFields`.

---

### Interactions

| Элемент | Действие | Результат | Endpoint |
|---------|----------|-----------|---------|
| Таб scope-фильтра | click | фильтрует список по entity_scope (клиентски, данные загружены все) | — |
| Кнопка «Добавить поле» | click | открывает Dialog (create mode), форма пустая | — |
| Строка → кнопка ✏ | click | открывает Dialog (edit mode), форма заполнена | — |
| Строка → кнопка 🗑 | click | `useConfirm` → подтверждение → DELETE | `DELETE /api/custom-field-defs/{id}` |
| ToggleSwitch «Активно» | toggle | мгновенный PATCH is_active, без перезагрузки | `PATCH /api/custom-field-defs/{id}` body `{ is_active }` |
| Dialog → Submit (create) | click | валидация → POST → закрыть Dialog → Toast «Сохранено» → reload | `POST /api/custom-field-defs` |
| Dialog → Submit (edit) | click | валидация → PATCH → закрыть Dialog → Toast «Сохранено» → reload | `PATCH /api/custom-field-defs/{id}` |
| DataTable row-reorder | drop | PATCH reorder для текущего scope | `PATCH /api/custom-field-defs/reorder?entity_scope={scope}` body `[{id, sort_order}]` |

**Примечание по reorder:** DataTable PrimeVue поддерживает нативный `reorderableRows` + `@row-reorder` — использовать его. Reorder доступен только внутри одного scope; если activeTab = 'all', кнопки drag отключены (cursor: default, opacity 0.3) с tooltip «Выберите конкретную сущность для сортировки».

---

### Токены и компоненты

- Отступы: `$space-3 $space-4` (тело таблицы), `$space-4 $space-6` (PageHeader)
- Радиусы: `$radius-md` (Card, Dialog), `$radius-pill` (scope/kind пилюли)
- Цвета текста: `$surface-700` / dark: `var(--p-surface-200)`
- Фон карточки: `$surface-card`
- Hover строки: `var(--mg-surface-hover)`
- PrimeVue: `DataTable`, `Column`, `Button`, `Dialog`, `InputText`, `InputNumber`, `Select`, `InputChips`, `ToggleSwitch`, `Tag`, `ConfirmDialog`, `Card`, `Tabs`, `TabList`, `Tab`
- Обе темы: все цвета через `$surface-*` / `var(--p-*)` токены; `.app-dark &` — инвертированная шкала

---

## Часть 2 — Рендер в карточках сущностей (CustomFieldRenderer доработка)

Существующий `CustomFieldRenderer.vue` уже охватывает: text, textarea, number, url, date,
select, multiselect, boolean/bool, user_ref. Требуется согласовать с новой схемой API и
закрыть несколько gap'ов.

### Gap-анализ (current → target)

| Пункт | Текущее состояние | Target |
|-------|------------------|--------|
| Тип `checkbox` из old API | поддержан как `boolean/bool` | добавить `checkbox` в switch (alias к bool-ветке) |
| `entity_scope` в типе | тип `CustomFieldScope = 'deal' / 'company' / 'contact'` | добавить `'contract'` |
| Поле `is_required` | не показывается в рендере | добавить красную звёздочку `*` после label в `KeyFactsItem` если `def.is_required` |
| Группировка | группировка по `group` — комментарий «No group field in current entity» | когда backend вернёт поле `group` — раскомментировать; до этого: один блок без заголовка |
| Scope props | `entityScope: 'contact' | 'company'` | расширить до `'deal' | 'contact' | 'company' | 'contract'` |

### Props (расширенный)

```typescript
defineProps<{
  entityScope: CustomFieldScope   // 'deal' | 'contact' | 'company' | 'contract'
  entityId: number
  extraFields: Record<string, unknown>
  onSave: (code: string, value: unknown) => Promise<void>
  users?: Array<{ id: number; name: string }>
}>()
```

### Required-индикатор

В `KeyFactsItem` для полей с `def.is_required === true` добавить справа от label:

```html
<span v-if="def.is_required" class="cf-required-star" aria-hidden="true">*</span>
```

```scss
.cf-required-star {
  color: var(--p-red-500);
  margin-left: $space-1;
  font-size: $font-size-xs;
  line-height: 1;
}
```

### Секция в InfoPanel

В `InfoPanel` карточки с кастомными полями — заголовок панели «Кастомные поля» (icon `pi-sliders-h`, panelKey `custom-fields-{entityType}`). Кнопка `#header-action` — ссылка `router.push('/settings?section=directories&tab=custom-fields')` только для admin/director с tooltip «Настроить поля» (`pi pi-cog`, severity="secondary" text small).

---

## i18n-ключи

```json
{
  "ru": {
    "customFields.pageTitle": "Кастомные поля",
    "customFields.add": "Добавить поле",
    "customFields.edit": "Редактировать поле",
    "customFields.columns.label": "Название",
    "customFields.columns.kind": "Тип",
    "customFields.columns.scope": "Сущность",
    "customFields.columns.isRequired": "Обязательное",
    "customFields.columns.isActive": "Активно",
    "customFields.empty.title": "Нет кастомных полей",
    "customFields.empty.hint": "Создайте первое поле для этой сущности",
    "customFields.deleteConfirm": "Удалить поле «{label}»? Данные в существующих записях сохранятся, но поле перестанет отображаться.",
    "customFields.saved": "Поле сохранено",
    "customFields.deleted": "Поле удалено",
    "customFields.errors.codeConflict": "Поле с таким кодом уже существует для этой сущности",
    "customFields.errors.codeFormat": "Код должен быть в формате snake_case: только латиница, цифры, _, начинается с буквы",
    "customFields.errors.required": "Обязательное поле",
    "customFields.fields.label": "Название",
    "customFields.fields.code": "Код (snake_case)",
    "customFields.fields.codeHint": "Код нельзя изменить после создания",
    "customFields.fields.scope": "Сущность",
    "customFields.fields.kind": "Тип поля",
    "customFields.fields.options": "Варианты",
    "customFields.fields.optionsHint": "Добавьте варианты для выбора",
    "customFields.fields.sortOrder": "Порядок",
    "customFields.fields.isRequired": "Обязательное поле",
    "customFields.fields.isActive": "Активно",
    "customFields.scopes.deal": "Сделка",
    "customFields.scopes.contact": "Контакт",
    "customFields.scopes.company": "Компания",
    "customFields.scopes.contract": "Договор",
    "customFields.scopes.all": "Все",
    "customFields.kinds.text": "Текст",
    "customFields.kinds.textarea": "Текстовая область",
    "customFields.kinds.number": "Число",
    "customFields.kinds.date": "Дата",
    "customFields.kinds.select": "Список",
    "customFields.kinds.multiselect": "Мультисписок",
    "customFields.kinds.url": "Ссылка",
    "customFields.kinds.checkbox": "Чекбокс",
    "customFields.kinds.user_ref": "Пользователь",
    "customFields.reorderDisabledHint": "Выберите конкретную сущность для сортировки",
    "customFields.configureLink": "Настроить поля",
    "settings.directories.tabs.customFields": "Кастомные поля",
    "crm.customFields.schemaError": "Не удалось загрузить схему кастомных полей",
    "crm.customFields.empty": "Кастомные поля не настроены"
  },
  "en": {
    "customFields.pageTitle": "Custom Fields",
    "customFields.add": "Add field",
    "customFields.edit": "Edit field",
    "customFields.columns.label": "Name",
    "customFields.columns.kind": "Type",
    "customFields.columns.scope": "Entity",
    "customFields.columns.isRequired": "Required",
    "customFields.columns.isActive": "Active",
    "customFields.empty.title": "No custom fields",
    "customFields.empty.hint": "Create the first field for this entity",
    "customFields.deleteConfirm": "Delete field «{label}»? Data in existing records will remain but the field will no longer be displayed.",
    "customFields.saved": "Field saved",
    "customFields.deleted": "Field deleted",
    "settings.directories.tabs.customFields": "Custom Fields"
  }
}
```

---

## Файловая структура

```
front/src/pages/CustomFieldsPage/
  index.vue                         ← Page-компонент (embedded-prop, DataTable, scope-tabы)
  composables/
    useCustomFieldsPage.ts          ← Composable (fetchAll, openCreate, openEdit, save, toggleActive, delete, reorder)
  components/
    CustomFieldDialog.vue           ← Dialog создания/редактирования

front/src/components/crm/
  FieldKindTag.vue                  ← Новый: иконка + лейбл типа поля

front/src/pages/SettingsPage/components/sections/directories/
  DirTabCustomFields.vue            ← Embedded-обёртка (toolbar + <CustomFieldsPage :embedded="true" />)

front/src/pages/SettingsPage/components/sections/
  SectionDirectories.vue            ← +Tab и +DirTabCustomFields (минимальная правка)

front/src/entities/crm.ts           ← +CustomFieldKind type; +CustomFieldScope расширить на 'contract'
front/src/api/crm/customFields.ts   ← +create, update, delete, toggleActive, reorder методы
```

---

## Открытые вопросы

**[ОВ-1] Backend API management endpoint.**
`GET /api/custom-field-defs` без фильтра `is_active` — нужен ли отдельный admin-endpoint
или фронт получает всё (включая inactive) через существующий `GET /api/custom-field-defs?scope=`?
Требуется backend: уточнить у `backend-architect`, возвращает ли текущий
`GET /api/crm/custom-fields` неактивные записи для admin-режима.

**[ОВ-2] Расхождение типов: old API vs current.**
Old API (contracts) использует `kind` + `entity_scope`; current frontend (`crm.ts`) — `field_type` + `scope`.
`CustomFieldDef.field_type` в крм-коде включает `user_ref` и `bool`, которых нет в old-сервисе.
При написании management UI нужно использовать тот же shape, что возвращает MGCRM API.
Требуется backend: уточнить у `backend-architect` финальный shape `CustomFieldDefOut` (поля, enum).

**[ОВ-3] Scope `contract`.**
В old-сервисе `CUSTOM_FIELD_SCOPES` включает `contract`, в current `CustomFieldScope` — нет.
Добавлять ли `contract` в UI сейчас? Если да — нужна поддержка в `CustomFieldRenderer` на CardsPage.

**[ОВ-4] Drag-reorder в режиме «Все».**
Решение: drag-handles отключены (disabled visual) при `activeTab === 'all'` с tooltip.
Нужно ли показывать reorder вообще, или спрятать колонку при `all`? Оставить на усмотрение PM.

**[ОВ-5] PrimeVue `InputChips`.**
`InputChips` — компонент PrimeVue 4.5 для ввода массива строк (варианты select/multiselect).
Если компонент не подходит по UX — альтернатива: `MultiSelect` в `editable`-режиме или кастомный chips-input. Уточнить при реализации.

**[ОВ-6] `user_ref` type в management screen.**
Тип `user_ref` в old-сервисе отсутствует (`checkbox` — есть). В текущем MGCRM `user_ref` есть в `CustomFieldType`.
Показывать `user_ref` в Select типов или скрыть? Требуется позиция PM.

---

## Vizion-эталон

Ближайший аналог — `./examples/vizion/front/src/pages/TagsPage/` и `./examples/vizion/front/src/pages/AcquisitionChannelsPage/` (directory-page pattern: PageHeader + Card + DataTable + Dialog). Embedded-паттерн — `DirTabTags.vue` в settings.

---

*ТЗ готово 2026-07-02. Ожидает ответов на ОВ-1/ОВ-2 от `backend-architect` перед стартом реализации.*
