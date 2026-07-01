# ТЗ: Создание через полную карточку — Контакт / Компания / Сделка

**Зачем:** заменить три отдельных мини-drawer'а (Contacts quick-create + DealCreateDrawer) на открытие полноценной пустой карточки в режиме `create` — чтобы менеджер сразу видел все поля, заполнял то, что нужно, и оставался в карточке после сохранения.

**Где в коде:**
- `front/src/pages/ContactPage/` — режим создания контакта
- `front/src/pages/CompanyPage/` — режим создания компании
- `front/src/pages/DealPage/` — режим создания сделки
- `front/src/pages/ContactsPage/composables/useContactsPageActions.ts` — удалить `openQuickCreate` / `submitQuickCreate` / `closeQuickCreate`
- `front/src/pages/ContactsPage/index.vue` — удалить `<Drawer>` quick-create (строки 544–701), удалить `quickCreateOpen` из script
- `front/src/pages/DealsPage/components/DealCreateDrawer.vue` — удалить файл полностью
- `front/src/pages/DealsPage/index.vue` — удалить `<DealCreateDrawer>`, `createDrawerOpen`
- `front/src/pages/CompanyPage/index.vue` — удалить `<DealCreateDrawer>`, `dealCreateOpen`, `dealCreatePipelines`, `onCreateDeal` (заменить на навигацию)
- `front/src/router/routes/base.ts` — добавить три новых роута

**Источник фич (old):** логика полей — из `StoreContactRequest.php`, `StoreCompanyRequest.php`, `StoreDealRequest.php`; состав полей quick-create — `front/src/pages/ContactsPage/composables/useContactsPageActions.ts`, `front/src/pages/DealsPage/components/DealCreateDrawer.vue`.

---

## Анализ текущего состояния (что есть, что удаляем)

### 1. Мини-панели создания (УДАЛЯЕМ)

| Сущность | Компонент | Триггеры |
|----------|-----------|----------|
| Контакт | `Drawer` 420px в `ContactsPage/index.vue` (строки 544–701) | `ContactsToolbar` → `@create` → `openQuickCreate()` · кнопка «+» в empty-state · `uiTriggers.pendingDrawer === 'contact_create'` (из Орбиты/QuickActionsCluster) |
| Компания | Тот же `Drawer` c `quickCreateType==='company'` — SelectButton внутри | То же через `entityType === 'company'` |
| Сделка | `DealCreateDrawer.vue` 500px | `DealsToolbar` → `createDrawerOpen = true` · `DealsKanbanBoard` `@create` · `DealsListView` `@create` · `CompanyPage` `onCreateDeal()` · `QuickActionsCluster` `deal_create` |

### 2. Межмодульные вызовы (НЕ ломаем, мигрируем)

| Место | Текущее поведение | Новое поведение |
|-------|-------------------|-----------------|
| `CompanyPage` → создание сделки | `DealCreateDrawer` с `initialCompany` prefill | `router.push('/deals/new?company_id=<id>&company_name=<name>')` |
| `ContactPage/ContactDealsTab` → создание сделки | кнопка `disabled` (TODO B-CONTACT-DEAL-CREATE) | `router.push('/deals/new?contact_id=<id>')` |
| `QuickActionsCluster` deal_create | `DRAWER_ROUTES['deal_create'] = '/deals'` + `uiTriggers.triggerDrawer('deal_create')` | `router.push('/deals/new')` (без триггера) |
| `QuickActionsCluster` contact_create | `router.push('/contacts')` + `triggerDrawer('contact_create')` | `router.push('/contacts/new')` |
| `uiTriggersStore` pendingDrawer watcher в `ContactsPage` | `openQuickCreate()` | удалить watcher; `DrawerTrigger` сужается до `null \| 'deal_create'` (до полного удаления) или тип убираем совсем |

---

## Решение: роут-based create-страница (НЕ диалог)

### Обоснование выбора роута vs. модала

**Выбираем новый роут** (`/contacts/new`, `/companies/new`, `/deals/new`), а не полноэкранный Dialog, по следующим причинам:

1. После сохранения нужно **остаться в карточке** — это навигация (`/contacts/42`, `/companies/7`, `/deals/15`), что органично для роутера. Из диалога придётся `router.push()` с закрытием — лишний слой.
2. Ссылку на «создание» можно скопировать, шарить по команде, передать в почту — роут это обеспечивает автоматически.
3. `DealPage` уже двухпанельный (280px left + flex right feed). Режим создания сделки может легко использовать тот же лейаут, скрыв правую ленту до момента сохранения.
4. Для Контакта и Компании карточки — полноэкранные страницы (`ContactPage`, `CompanyPage`). Повторить их лейаут в диалоге сложнее без дублирования.

**Исключение: создание сделки из CompanyPage** — здесь prefill (`company_id`) передаётся через query-параметр, а не диалог. Это сохраняет контекст и не требует хранения данных между страницами.

---

## Роутер: новые маршруты

```typescript
// base.ts — добавить ПЕРЕД существующими :id-роутами

{ path: '/contacts/new', name: 'ContactCreate', component: () => import('@/pages/ContactPage'), meta: { requiresAuth: true } },
{ path: '/companies/new', name: 'CompanyCreate', component: () => import('@/pages/CompanyPage'), meta: { requiresAuth: true } },
{ path: '/deals/new',     name: 'DealCreate',    component: () => import('@/pages/DealPage'),    meta: { requiresAuth: true } },
```

**Важно:** роут `/contacts/new` должен быть зарегистрирован до `/contacts/:id`, иначе Vue Router разберёт `new` как числовой id и промахнётся. Аналогично для `/companies` и `/deals`.

---

## СУЩНОСТЬ 1: Контакт (`/contacts/new`)

### Wireframe (create mode)

```
┌─────────────────────────────────────────────────────────────────────────┐
│ [← Назад]  Новый контакт                                [Отмена]       │ ← EntityInfoHeader в create-режиме
│ #172747 bg │ Не сохранён                                                │   title="Новый контакт", subtitle пусто
├─────────────────────────────────────────────────────────────────────────┤
│  [Обзор]                                    (только одна вкладка)       │ ← Tabs, остальные скрыты до сохранения
├─────────────────────────────────────────────────────────────────────────┤
│  ┌─ ОБЯЗАТЕЛЬНОЕ ──────────────────────────────────────────────────┐   │
│  │  ФИО *          [____________________________________]           │   │
│  │  ⚠ красная обводка + «Введите ФИО» если пустое                  │   │
│  └──────────────────────────────────────────────────────────────────┘   │
│                                                                          │
│  ┌─ КОНТАКТЫ ──────────────────────────────────────────────────────┐   │
│  │  Телефон        [+7 777 000 00 00___________]                   │   │
│  │  Email          [email@example.com__________]                   │   │
│  └──────────────────────────────────────────────────────────────────┘   │
│                                                                          │
│  ┌─ ДОПОЛНИТЕЛЬНО ─────────────────────────────────────────────────┐   │
│  │  Должность      [____________________________________]           │   │
│  │  Источник       [Выберите ▼_________________________]           │   │
│  │  Ответственный  [Выберите ▼_________________________]           │   │
│  └──────────────────────────────────────────────────────────────────┘   │
│                                                                          │
│                       [Отмена (secondary)]  [Сохранить (primary) →]    │
└─────────────────────────────────────────────────────────────────────────┘
```

### Обязательные поля (backend: `StoreContactRequest`)

| Поле | Обязательность | Компонент PrimeVue |
|------|---------------|-------------------|
| `full_name` | **required** | `InputText` |
| `phone` | nullable | `InputText` |
| `email` | nullable | `InputText` |
| `position` | nullable | `InputText` |
| `source` | nullable | `Select` (из `directoriesStore.activeSources`) |
| `owner_id` | nullable (auto = текущий пользователь) | `Select` (из `usersCache`) |

### Поведение `ContactPage` в режиме `new`

**Определение режима:** `const isCreateMode = computed(() => route.name === 'ContactCreate')`

В create-режиме:
- `EntityInfoHeader` получает `title="Новый контакт"` (i18n: `contact.create.title`), `subtitle` пустой, кнопка `back` → `router.push('/contacts')`.
- `EntityKpiStrip` — скрыть (`v-if="!isCreateMode"`).
- `Tabs` — отображать только вкладку «Обзор» (`v-if="!isCreateMode"`-гейт на вкладках Активности/Сделки/Файлы).
- Панели внутри Обзора — показывать только блок создания (форма полей), скрывать журнал активностей, задачи, связанные сделки, файлы.
- Меню `menuItems` — пустой массив (сохранения нет, нечего редактировать/удалять).

**Форма создания** — вместо `ContactOverviewTab` в create-режиме рендерить `ContactCreateForm.vue` (новый компонент):

```
front/src/pages/ContactPage/components/ContactCreateForm.vue
```

Подробный состав: секции «Обязательное» + «Контакты» + «Дополнительно» (wireframe выше). Инлайн-валидация: `p-invalid` на `InputText` + `<small class="p-error">` под полем после попытки сохранить. Ошибки с бэкенда (422) — подсвечивают соответствующее поле.

**Сохранение:**
```
POST /api/contacts → 201 { id: 42, ... }
→ router.replace(`/contacts/${created.id}`)
// replace, не push — чтобы «назад» вело не на /contacts/new, а на список
```

**Состояния кнопки «Сохранить»:**
- default: `icon="pi pi-check" label="Сохранить" severity="primary"`
- loading: `:loading="saving"` (текст «Сохранение…» скрыт spinner'ом)
- disabled: если `full_name.trim() === ''` (клиентская проверка в реальном времени)

**Отмена:** `router.back()` — оба варианта (кнопка «Отмена» внизу и `← Назад` в хедере).

### Точки входа в `/contacts/new`

| Откуда | Текущий механизм | Новый |
|--------|-----------------|-------|
| `ContactsToolbar` кнопка «Создать контакт» | `emit('create')` → `openQuickCreate()` | `router.push('/contacts/new')` |
| Empty-state кнопка «+» | `@click="openQuickCreate"` | `router.push('/contacts/new')` |
| Орбита QuickActionsCluster `contact_create` | `router.push('/contacts')` + `triggerDrawer('contact_create')` | `router.push('/contacts/new')` (убрать triggerDrawer) |

**`uiTriggersStore`:** убрать тип `'contact_create'` из `DrawerTrigger`. Watcher в `ContactsPage` (`pendingDrawer === 'contact_create'` → `openQuickCreate()`) — удалить.

---

## СУЩНОСТЬ 2: Компания (`/companies/new`)

### Wireframe (create mode)

```
┌─────────────────────────────────────────────────────────────────────────┐
│ [← Назад]  Новая компания                               [Отмена]       │ ← EntityInfoHeader
│ #172747 bg │ Не сохранена                                               │
├─────────────────────────────────────────────────────────────────────────┤
│  [Обзор]                                                                │
├─────────────────────────────────────────────────────────────────────────┤
│  ┌─ ОБЯЗАТЕЛЬНОЕ ──────────────────────────────────────────────────┐   │
│  │  Название *       [____________________________________]          │   │
│  └──────────────────────────────────────────────────────────────────┘   │
│                                                                          │
│  ┌─ РЕКВИЗИТЫ ─────────────────────────────────────────────────────┐   │
│  │  Правовая форма   [ТОО / ООО / ИП_____________]                  │   │
│  │  БИН / ИНН        [____________________________________]          │   │
│  │  Телефон          [____________________________________]          │   │
│  │  Email            [____________________________________]          │   │
│  └──────────────────────────────────────────────────────────────────┘   │
│                                                                          │
│  ┌─ КЛАССИФИКАЦИЯ ─────────────────────────────────────────────────┐   │
│  │  Тип компании     [Выберите ▼_____________]                      │   │
│  │  Страна           [Выберите ▼_____________]                      │   │
│  │  Источник         [Выберите ▼_____________]                      │   │
│  └──────────────────────────────────────────────────────────────────┘   │
│                                                                          │
│  ┌─ ОТВЕТСТВЕННОСТЬ ───────────────────────────────────────────────┐   │
│  │  Владелец         [Выберите ▼_____________]                      │   │
│  │  Ответственный    [Выберите ▼_____________]                      │   │
│  └──────────────────────────────────────────────────────────────────┘   │
│                                                                          │
│                       [Отмена (secondary)]  [Сохранить (primary) →]    │
└─────────────────────────────────────────────────────────────────────────┘
```

### Обязательные поля (backend: `StoreCompanyRequest`)

| Поле | Обязательность | Компонент PrimeVue |
|------|---------------|-------------------|
| `name` | **required** | `InputText` |
| `legal_form` | nullable | `InputText` |
| `tax_id` | nullable | `InputText` |
| `phone` | nullable | `InputText` |
| `email` | nullable | `InputText` |
| `company_type_id` | nullable | `Select` (из `directoriesStore.activeCompanyTypes`) |
| `country_code` | nullable | `Select` (из `directoriesStore.activeCountries`) |
| `source` | nullable | `Select` (из `directoriesStore.activeSources`) |
| `owner_user_id` | nullable (auto = текущий) | `Select` |
| `responsible_user_id` | nullable | `Select` |

### Поведение `CompanyPage` в режиме `new`

**Определение:** `const isCreateMode = computed(() => route.name === 'CompanyCreate')`

В create-режиме:
- `EntityInfoHeader` → title `"Новая компания"` (i18n: `company.create.title`).
- Скрыть KPI-strip, все вкладки кроме «Обзор», панели (реквизиты/холдинг/сотрудники/сделки/документы) — они пустые и не имеют смысла до создания.
- Рендерить `CompanyCreateForm.vue` в теле вкладки Обзор.

**Новый компонент:**
```
front/src/pages/CompanyPage/components/CompanyCreateForm.vue
```

**Сохранение:**
```
POST /api/companies → 201 { id: 7, ... }
→ router.replace(`/companies/${created.id}`)
```

**Точки входа:**

| Откуда | Текущий механизм | Новый |
|--------|-----------------|-------|
| `ContactsToolbar` кнопка «Создать компанию» (`entityType === 'company'`) | `emit('create')` → `openQuickCreate()` | `router.push('/companies/new')` |
| Empty-state кнопка «+» (страница Компании) | `@click="openQuickCreate"` | `router.push('/companies/new')` |

**Нет межмодульных входов** — компанию создают только из списка. Орбита не имеет `company_create` в `QuickActionRegistry` (только `deal_create` + `contact_create`).

---

## СУЩНОСТЬ 3: Сделка (`/deals/new`)

Это самый сложный случай — сделку создают из многих мест, и DealCreateDrawer используется как в DealsPage, так и в CompanyPage. Разбираем детально.

### Wireframe (create mode)

Сделка имеет двухпанельный лейаут (280px left + flex right). В create-режиме:
- Левая панель: только форма создания (обязательные поля + опциональные)
- Правая панель: скрыта до сохранения (`v-if="!isCreateMode"`)

```
┌───────────────────────────────────────────────────────────────────────────────┐
│  [← Назад]  Новая сделка                          (хедер отсутствует)        │
│  сделок-хедер в create-режиме — упрощённый: нет стадий, нет прогресс-бара    │
├──────────────────────────────────────────────────────────────────────────────┤
│  ┌── ЛЕВАЯ ПАНЕЛЬ (280px) ─────┐  ┌── ПРАВАЯ (скрыта до сохранения) ──┐     │
│  │  ┌─ ОБЯЗАТЕЛЬНОЕ ─────────┐ │  │                                    │     │
│  │  │ Компания *             │ │  │  [Заполните поля слева             │     │
│  │  │ [AutoComplete  ▼]      │ │  │   и нажмите «Сохранить»           │     │
│  │  │ ⚠ если пусто           │ │  │   чтобы продолжить работу]        │     │
│  │  │                        │ │  │                                    │     │
│  │  │ Название *             │ │  └────────────────────────────────────┘     │
│  │  │ [________________]     │ │                                              │
│  │  │                        │ │                                              │
│  │  │ Воронка *              │ │                                              │
│  │  │ [Select ▼_____]        │ │                                              │
│  │  │                        │ │                                              │
│  │  │ Валюта *               │ │                                              │
│  │  │ [Select ▼_____]        │ │                                              │
│  │  └────────────────────────┘ │                                              │
│  │                              │                                              │
│  │  ┌─ ДОПОЛНИТЕЛЬНО ────────┐ │                                              │
│  │  │ Стадия (авто=первая)   │ │                                              │
│  │  │ [Select ▼_____]        │ │                                              │
│  │  │                        │ │                                              │
│  │  │ Ответственный          │ │                                              │
│  │  │ [Select ▼_____]        │ │                                              │
│  │  │                        │ │                                              │
│  │  │ Ожидаемое закрытие     │ │                                              │
│  │  │ [DatePicker___]        │ │                                              │
│  │  └────────────────────────┘ │                                              │
│  │                              │                                              │
│  │  [Отмена]  [Сохранить →]    │                                              │
│  └──────────────────────────────┘                                              │
└───────────────────────────────────────────────────────────────────────────────┘
```

**Альтернатива для мобильного (<768px) и планшета (768–1023px):** одна колонка, без левой панели. Правая (лента) скрыта. Форма на весь экран.

### Поля и обязательность (backend: `StoreDealRequest`)

| Поле | Обязательность | Компонент |
|------|---------------|-----------|
| `company_id` | **required** | `AutoComplete` (search `GET /api/companies?search=`) |
| `title` | **required** | `InputText` |
| `pipeline_id` | **required** | `Select` (из `salesApi.getPipelines()`) |
| `currency` | **required** | `Select` (из `CURRENCY_WHITELIST`) |
| `stage_id` | nullable — backend устанавливает первую стадию автоматически; Select позволяет переопределить | `Select` (опции из `salesStore.getCachedStages(pipeline_id)`) |
| `owner_user_id` | nullable (auto = текущий); manager — только себя | `Select` |
| `expected_close_date` | nullable | `DatePicker` |

**Важно:** `stage_id` намеренно НЕ принимается `StoreDealRequest` (комментарий в PHP: «service sets the first stage»). Поле `Select` для стадии — только подсказка пользователю; если backend не поддерживает `stage_id` в create, поле показываем disabled с подсказкой «Будет установлена первая стадия». **Открытый вопрос ОВ-1.**

### Prefill через query-параметры

```
/deals/new?company_id=42&company_name=MACRO+Global
/deals/new?pipeline_id=3&stage_id=17
/deals/new?contact_id=88   ← пока не prefill в форме, только для будущего логирования
```

Обработка в `DealPage` (create mode): `onMounted` читает `route.query` и инициализирует форм-значения.

### Поведение `DealPage` в режиме `new`

**Определение:** `const isCreateMode = computed(() => route.name === 'DealCreate')`

В create-режиме:
- `DealInfoHeader` — упрощён: нет прогресс-бара стадий (`DealStageProgressBar`), нет `DealHealthChip`, нет `DealKeyActionsBar`. Только `← Назад` + заголовок «Новая сделка».
- `DealInfoPanel` (280px left) — показывает только `DealCreateForm.vue` (новый компонент), без `DealFieldGroup`/`DealCompanyGroup`/`DealContactsGroup`/`DealProductsGroup`/`DealDatesGroup`.
- Правая панель (`DealFeed` + `DealComposer`) — скрыта (`v-if="!isCreateMode"`).
- `DealInfoTabs` — скрыты (`v-if="!isCreateMode"`).

**Новый компонент:**
```
front/src/pages/DealPage/components/DealCreateForm.vue
```

Содержит форму из wireframe выше. Данные из DealCreateDrawer.vue можно перенести 1-в-1.

**Сохранение:**
```
POST /api/deals → 201 { id: 15, ... }
→ router.replace(`/deals/${created.id}`)
```

### Точки входа в `/deals/new` (все — замена DealCreateDrawer)

| Откуда | Текущий механизм | Новый механизм |
|--------|-----------------|---------------|
| `DealsToolbar` кнопка «Создать сделку» | `emit('create')` → `createDrawerOpen = true` | `router.push('/deals/new')` |
| `DealsKanbanBoard` — кнопка «+» в колонке | `emit('create')` → `createDrawerOpen = true` | `router.push('/deals/new?pipeline_id=X&stage_id=Y')` (prefill стадии) |
| `DealsKanbanBoard` → `DealsKanbanColumn` | аналогично | с `stage_id` текущей колонки |
| `DealsListView` empty-state | `emit('create')` → `createDrawerOpen = true` | `router.push('/deals/new')` |
| `CompanyPage` → `onCreateDeal()` | открывает `DealCreateDrawer` с `initialCompany` prefill | `router.push('/deals/new?company_id=<id>&company_name=<encName>')` |
| `ContactPage/ContactDealsTab` → кнопка (сейчас `disabled`) | `disabled` | `router.push('/deals/new?contact_id=<id>')` — кнопку разблокировать |
| Орбита `deal_create` | `router.push('/deals')` + `triggerDrawer('deal_create')` | `router.push('/deals/new')` (убрать trigger) |

**`uiTriggersStore`:** После удаления обоих триггеров тип `DrawerTrigger` = `null` (или убрать store совсем).

---

## PrimeVue-компоненты (для всех трёх форм)

| Элемент | Компонент | Ключевые props |
|---------|-----------|---------------|
| Поле ФИО / Название / Название сделки | `InputText` | `v-model`, `:class="{'p-invalid': errors.field}"`, `class="w-full"` |
| AutoComplete (Компания) | `AutoComplete` | `option-label="name"`, `force-selection`, `dropdown`, `delay=300`, `@complete`, `@option-select` |
| Выпадающий список | `Select` | `option-label`, `option-value`, `show-clear` (для nullable), `class="w-full"` |
| Дата | `DatePicker` | `date-format="dd.mm.yy"`, `show-clear`, `show-icon`, `class="w-full"` |
| Ошибка под полем | `<small class="p-error">` | вне PrimeVue — нативный тег |
| Кнопка сохранения | `Button` | `icon="pi pi-check"`, `:label`, `:loading="saving"`, `severity="primary"` |
| Кнопка отмены | `Button` | `severity="secondary"`, `text` |
| Звёздочка обязательного | `<span class="req">*</span>` | CSS: `color: $red-500` |

---

## Общие States

### Loading (пока форма инициализируется: загрузка pipelines / sources / users)

```
Skeleton height="44px" × количество полей
```
Показывать если данные для selects ещё не пришли. Pipelines/источники грузятся параллельно, форма блокируется до их загрузки.

### Empty (форма пустая, ещё не было попытки сохранить)

Обязательные поля без ошибок, CTA кнопка «Сохранить» — `disabled` пока `full_name`/`name`/`title` пусты.

### Validation error (попытка submit с незаполненными required)

- `p-invalid` на соответствующем `InputText` / `AutoComplete` / `Select`
- Под полем: `<small class="p-error">{{ t('...') }}</small>`
- `Toast` не показывать — инлайн-ошибки достаточно
- Фокус перемещается на первое невалидное поле

### Saving

- `Button` `:loading="saving"` = true
- Все поля `:disabled="saving"` = true
- Кнопка «Отмена» `:disabled="saving"`

### Server error (500 / сетевая ошибка)

```
Toast({ severity: 'error', summary: t('errors.server_error'), detail: message, life: 4000 })
```
Форма остаётся заполненной.

### Server validation (422)

Ответ `{ errors: { field: ['message'] } }` → инлайн под каждым полем (аналогично клиентской валидации).

### Success

```
Toast({ severity: 'success', summary: t('<entity>.create.success'), life: 3000 })
router.replace(`/<entity>/${created.id}`)
```

---

## Interactions

### Контакт

| Элемент | Действие | Результат |
|---------|----------|-----------|
| Поле ФИО | input | реакт. кнопка «Сохранить» enabled/disabled |
| ФИО | blur с пустым | `p-invalid` + ошибка под полем |
| Кнопка «Сохранить» | click | валидация → `POST /api/contacts` → `router.replace('/contacts/:id')` |
| Кнопка «Отмена» / `← Назад` | click | `router.back()` |
| 422 с backend | — | `p-invalid` по полям из `errors` |
| 201 Created | — | Toast success + navigate to card |

### Компания

| Элемент | Действие | Результат |
|---------|----------|-----------|
| Поле Название | input | enabled/disabled «Сохранить» |
| Кнопка «Сохранить» | click | `POST /api/companies` → `router.replace('/companies/:id')` |
| Кнопка «Отмена» | click | `router.back()` |

### Сделка

| Элемент | Действие | Результат |
|---------|----------|-----------|
| AutoComplete Компания | @complete(query) | `GET /api/companies?search=query&per_page=10` → suggestions |
| @option-select | — | `form.company_id = option.id`, ошибка company_id сбрасывается |
| Select Воронка | @change | обновить `stageOptions` (из `salesStore.getCachedStages(pipeline_id)`) |
| Кнопка «Сохранить» | click | `POST /api/deals` → `router.replace('/deals/:id')` |
| Query-параметр `company_id` | `onMounted` | prefill AutoComplete |
| Query-параметр `pipeline_id` | `onMounted` | prefill Select Воронка |

---

## i18n-ключи (RU обязательно, EN задел)

```json
{
  "contact": {
    "create": {
      "title": "Новый контакт",
      "success": "Контакт создан",
      "sections": {
        "required": "Обязательные поля",
        "contacts": "Контакты",
        "additional": "Дополнительно"
      },
      "saveBtn": "Сохранить",
      "cancelBtn": "Отмена",
      "saving": "Сохранение...",
      "pendingHint": "Заполните поля и нажмите «Сохранить»",
      "errors": {
        "fullNameRequired": "Введите ФИО",
        "serverError": "Ошибка при создании контакта"
      }
    }
  },
  "company": {
    "create": {
      "title": "Новая компания",
      "success": "Компания создана",
      "sections": {
        "required": "Обязательные поля",
        "requisites": "Реквизиты",
        "classification": "Классификация",
        "responsibility": "Ответственность"
      },
      "saveBtn": "Сохранить",
      "cancelBtn": "Отмена",
      "errors": {
        "nameRequired": "Введите название компании"
      }
    }
  },
  "sales": {
    "deal": {
      "create": {
        "title": "Новая сделка",
        "success": "Сделка создана",
        "pendingHint": "Заполните поля слева и нажмите «Сохранить», чтобы продолжить работу",
        "saveBtn": "Сохранить",
        "cancelBtn": "Отмена",
        "sections": {
          "required": "Обязательные поля",
          "additional": "Дополнительно"
        },
        "fields": {
          "company": "Компания",
          "title": "Название",
          "pipeline": "Воронка",
          "currency": "Валюта",
          "stage": "Стадия",
          "owner": "Ответственный",
          "expectedCloseDate": "Ожидаемое закрытие"
        },
        "stageAutoNote": "Будет установлена первая стадия воронки",
        "errors": {
          "companyRequired": "Выберите компанию",
          "titleRequired": "Введите название сделки",
          "pipelineRequired": "Выберите воронку",
          "currencyRequired": "Выберите валюту"
        }
      }
    }
  }
}
```

**EN задел** (структура идентична, значения на EN):
```json
{
  "contact": { "create": { "title": "New contact", "success": "Contact created", "saveBtn": "Save", "cancelBtn": "Cancel" } },
  "company": { "create": { "title": "New company", "success": "Company created" } },
  "sales": { "deal": { "create": { "title": "New deal", "success": "Deal created" } } }
}
```

---

## Токены и стили

Никаких новых токенов. Используем существующие из `front/src/theme/`:

| Зона | Токен |
|------|-------|
| Заголовок (EntityInfoHeader) | `$primary-900` = `#172747` background (бренд-инвариант) |
| Поверхность формы | `$surface-card` (light) / `var(--p-surface-100)` (dark — инвертированная шкала) |
| Граница секции | `$surface-200` (light) / `var(--p-surface-700)` (dark) |
| Красная звёздочка | `$red-500` |
| Ошибка под полем | `var(--p-red-500)` через PrimeVue `.p-error` |
| Лейбл поля | `$surface-700` (light) / `var(--p-surface-200)` (dark) |
| Отступ между секциями | `$space-6` |
| Отступ между полями | `$space-4` |
| Отступ label→input | `$space-1` |
| Радиус секции | `$radius-lg` = 8px |
| Тень секции | `$shadow-card` |

**Dark mode:** Все переменные семантические. `$surface-card` в dark = `var(--p-surface-100)` (инвертированная шкала PrimeVue: поверхность становится темнее, текст — светлее).

---

## Что удалить (чеклист для frontend-specialist)

### Файлы к удалению

```
front/src/pages/DealsPage/components/DealCreateDrawer.vue   ← УДАЛИТЬ целиком
```

### Правки в существующих файлах

**`front/src/pages/ContactsPage/index.vue`**
- Удалить весь блок `<!-- Quick-create Drawer -->` (строки 543–701 текущей версии)
- Удалить из `<script>`: импорт `Drawer`, `SelectButton`, переменные `quickCreateOpen`, `quickCreateType`, `contactForm`, `companyForm`, `formErrors`, `isCreating`, функции `openQuickCreate`, `closeQuickCreate`, `submitQuickCreate`
- Удалить watcher `pendingDrawer === 'contact_create'`
- В `ContactsToolbar @create` → `router.push('/contacts/new')` (или `router.push('/companies/new')` по `entityType`)
- В empty-state `@click="openQuickCreate"` → `router.push('/contacts/new')` / `router.push('/companies/new')`

**`front/src/pages/ContactsPage/composables/useContactsPageActions.ts`**
- Удалить: `quickCreateOpen`, `quickCreateType`, `contactForm`, `companyForm`, `formErrors`, `validateContactForm`, `validateCompanyForm`, `submitQuickCreate`, `openQuickCreate`, `closeQuickCreate`, `createMutation`
- Оставить: `deleteOpen`, `deleteLoading`, `dedupOpen`, `openDedup`, `confirmDelete`, `executeDelete`, `openCard`

**`front/src/pages/DealsPage/index.vue`**
- Удалить `<DealCreateDrawer ...>` (строки 89–94)
- Удалить `import DealCreateDrawer`
- Заменить `createDrawerOpen = true` → `router.push('/deals/new')` или с query-параметрами
- Удалить `const createDrawerOpen = ref(false)` и `const createDrawerStageId = ref(...)`

**`front/src/pages/CompanyPage/index.vue`**
- Удалить `<DealCreateDrawer ...>` (строки 471–481)
- Удалить `import DealCreateDrawer`
- Заменить `onCreateDeal()`:
  ```typescript
  function onCreateDeal() {
    if (!company.value) return
    const query: Record<string, string> = {
      company_id: String(company.value.id),
      company_name: company.value.name,
    }
    void router.push({ path: '/deals/new', query })
  }
  ```
- Удалить `dealCreateOpen`, `dealCreatePipelines`, `onDealCreated`

**`front/src/pages/ContactPage/components/ContactDealsTab.vue`**
- Убрать `disabled` с кнопки «Создать сделку», заменить `@click` на `router.push('/deals/new?contact_id=' + contactId)`

**`front/src/stores/uiTriggers.ts`**
- Убрать `'contact_create'` из типа `DrawerTrigger` (остаётся только `'deal_create'` → потом тоже убрать)
- После полного перехода: удалить `triggerDrawer('deal_create')` из `QuickActionsCluster` и весь watcher в DealsPage (если был)

**`front/src/components/Orbita/QuickActionsCluster.vue`**
- `DRAWER_ROUTES['contact_create']` — удалить (больше нет)
- `DRAWER_ROUTES['deal_create']` — изменить: вместо `router.push('/deals') + triggerDrawer('deal_create')` делать `router.push('/deals/new')`

**`front/src/router/routes/base.ts`**
- Добавить три новых роута (см. секцию «Роутер» выше), ПЕРЕД `:id`-роутами

---

## Новые компоненты (файлы создать)

```
front/src/pages/ContactPage/components/ContactCreateForm.vue
front/src/pages/CompanyPage/components/CompanyCreateForm.vue
front/src/pages/DealPage/components/DealCreateForm.vue
```

Каждый — выделенная форма для create-режима, получает emit `saved(entity)` и `cancel()`. Родительская страница слушает `saved` и делает `router.replace`.

---

## Vizion-эталон

Роут-based create без отдельных create-страниц в Vizion не реализован, так как Vizion использует drawer-паттерн. Визуальный паттерн секций формы — из `examples/vizion/front/src/pages/` (inline-форм с разделёнными секциями). Структура guard create/edit-режима: `route.params.id === 'new'` vs числовой id — стандартный паттерн Vue Router.

---

## Ключевые межмодульные риски и как избежать

### Риск 1: Канбан-колонка теряет prefill стадии

**Текущее:** `DealsKanbanBoard` передаёт `@create` в `DealsPage`, который устанавливает `createDrawerStageId`. DealCreateDrawer получает `:initial-stage-id`.

**После миграции:** Kanban-колонка при клике `+` делает `router.push('/deals/new?pipeline_id=X&stage_id=Y')`. `DealCreateForm` читает query и pre-fills Select стадии. Убедиться что `stage_id` корректно читается в `onMounted`.

**Важно:** backend `StoreDealRequest` не принимает `stage_id` (комментарий в PHP). Если prefill нужен — требуется backend-изменение (ОВ-1 ниже).

### Риск 2: CompanyPage DealCreateDrawer с initialCompany не передаётся

**Текущее:** `DealCreateDrawer` получает `initialCompany: { id, name }` как prop, AutoComplete pre-filled.

**После миграции:** query-параметр `?company_id=42&company_name=MACRO+Global`. `DealCreateForm` читает их в `onMounted`. `encodeURIComponent` для имени обязателен.

### Риск 3: uiTriggers watcher в ContactsPage

**Текущее:** ContactsPage слушает `pendingDrawer === 'contact_create'` и открывает drawer. Если убрать drawer, но не убрать watcher + source в QuickActionsCluster — роут не сработает.

**Решение:** одновременно убрать watcher из ContactsPage И изменить QuickActionsCluster, чтобы тип `contact_create` вёл на `/contacts/new` без triggerDrawer.

### Риск 4: ContactDealsTab кнопка была disabled

**Текущее:** кнопка «Создать сделку» в ContactDealsTab была `disabled` с TODO. После миграции её разблокируем — но нужно убедиться, что `contactId` доступен в компоненте (он приходит как prop из ContactPage).

### Риск 5: DealsKanbanBoard `@create` без stage_id

**Текущее:** `DealsKanbanBoard` также эмитит `@create` без стадии (из floating кнопки в toolbar). После миграции → `router.push('/deals/new?pipeline_id=currentPipelineId')` без stage_id. Первая стадия будет установлена бэкендом автоматически.

---

## Открытые вопросы

**ОВ-1 (backend):** `StoreDealRequest` не принимает `stage_id` (комментарий «service sets the first stage»). Если нужен prefill стадии при создании из колонки Kanban — требуется backend-изменение: добавить `stage_id` как nullable в `StoreDealRequest` и передавать его в `DealService::create()`. Без этого поле Select «Стадия» в create-форме — только UI-подсказка, реально не влияет на создание.

**ОВ-2 (UX):** При создании сделки из карточки Компании — перейти на `/deals/new?company_id=X` означает уход из CompanyPage. После создания сделки пользователь окажется на DealPage. Возврат — только через `← Назад`. Альтернатива: для контекста CompanyPage оставить modal (DealCreateDialog) без DealCreateDrawer, но меньший по функционалу. **Решение:** использовать `router.replace` при сохранении сделки из CompanyPage, и кнопкой «← Назад» в DealPage возвращаться на CompanyPage — это обеспечивает history.back(). Если такое поведение не устраивает — нужен дополнительный апрув PM.

**ОВ-3 (роли):** Текущий `DealCreateDrawer` скрывает Select «Ответственный» для менеджера (только себя). `DealCreateForm` должен сохранить это поведение: `isManagerRole` → Select disabled с единственным значением (текущий пользователь).

**ОВ-4 (Орбита DrawerTrigger):** После удаления обоих quick-create типов из `uiTriggersStore` — store теряет смысл. Уточнить с PM: удалить store совсем или оставить для будущих фич.
