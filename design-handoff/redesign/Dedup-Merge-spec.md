# ТЗ: Единое окно объединения (MergeDialog 2.0)

**Зачем:** единый переиспользуемый диалог для дедупликации и ручного bulk-merge — закрывает задачи
«выбор мастера + per-field выбор источника + append дочерних данных + drill-in в карточку + per-pair
«Не дубль»» без множественных компонентов.

**Где в коде:**
- Основной компонент: `front/src/components/crm/dedup/MergeDialog.vue` (заменяет текущий)
- Composable: `front/src/components/crm/dedup/useDedupFlow.ts` (расширяется)
- Вход из ContactsPage: `front/src/pages/ContactsPage/index.vue` — `<MergeDialog>` (без изменений в props)
- Bulk-вход: `front/src/pages/ContactsPage/components/ContactsBulkToolbar.vue` — кнопка «Объединить»
  (сейчас `selectedCount !== 2`; меняется на `>= 2`)

**Источник фич (old):** `./examples/contracts/` — модели контактов, поля, merge API.

---

## Два режима одного компонента

| Prop | Тип | Описание |
|---|---|---|
| `visible` | `boolean` | `v-model:visible` — стандарт проекта |
| `mode` | `'dedup' \| 'bulk'` | Дефолт `'dedup'`. В bulk-режиме шаг `scan` и шаг `candidates` пропускаются, диалог открывается сразу на шаге `merge`. |
| `bulkEntities` | `DedupCandidate[]` | Только при `mode='bulk'`: уже выбранные записи из bulk-toolbar. Пустой массив при `mode='dedup'`. |
| `entityType` | `'contact' \| 'company'` | Передаётся из родителя, фиксирует scope (в dedup-режиме scope выбирает пользователь внутри). |

Emit: `'update:visible'`, `merged` (без изменений).

---

## Wireframe (ASCII)

### Шаг 1 — Scan (только mode=dedup)

```
┌─ Дедупликация ─────────────────────────────── [×] ─┐
│  [Компании ○] [Контакты ○]          [🔍 Сканировать] │
│                                                      │
│  · · · (ProgressSpinner + «Поиск дублей…»)          │
└──────────────────────────────────────────────────────┘
```

### Шаг 2 — Candidates (только mode=dedup)

```
┌─ Найдено групп: 3 ────────────────────────── [×] ─┐
│  Группа 1 — «ТОО Альфа» (3 записи)                 │
│  ┌────────────────────────────────────────────────┐ │
│  │ ID   Название           Ключ         Дата       │ │
│  │ 12   ТОО Альфа          BIN 123456   12.01.25   │ │
│  │ 34   ТОО Альфа          BIN 123456   05.03.25   │ │
│  │ 78   Альфа ТОО          —            22.04.25   │ │
│  └────────────────────────────────────────────────┘ │
│  [🔗 ID 12] [🔗 ID 34] [🔗 ID 78]  ← drill-in      │
│  [ Объединить ↗ ]  [ Не дубль … ▾ ]  ← per-pair     │
│  ────────────────────────────────────────────────    │
│  Группа 2 — «Иванов Иван» (2 записи)  …             │
│  ────────────────────────────────────────────────    │
├────────────────────────────────────────────────────  │
│  [← Назад к скану]                                   │
└────────────────────────────────────────────────────┘
```

### Шаг 3 — Merge (оба режима)

```
┌─ Объединение записей ───────────────── [×] ─┐
│  ┌─ Шаг 1/2 — Главная запись ──────────────┐ │
│  │  (●) ID 12  ТОО Альфа  bin@123  tel:+7… │ │
│  │  (○) ID 34  ТОО Альфа  bin@123  …       │ │
│  │  (○) ID 78  Альфа ТОО  —        …       │ │
│  │  [🔗 Открыть карточку] (рядом с каждой) │ │
│  └─────────────────────────────────────────┘ │
│                                              │
│  ┌─ Шаг 2/2 — Поля результата ─────────────┐ │
│  │  ПОЛЕ       ID 12 ✓     ID 34     ID 78  │ │
│  │  Название   (●)ТОО Аль  (○)ТОО Аль (○)  │ │
│  │  Юр.наим.   (●)Альфа    (○)—        (○)  │ │
│  │  БИН/ИНН    (●)123456   (●)123456  (○)—  │ │
│  │  E-mail     (●)a@b.com  (○)—        (○)  │ │
│  │  Телефон    (○)—        (●)+77012…  (○)  │ │
│  │  Источник   (●)Реклама  (○)—        (○)  │ │
│  └─────────────────────────────────────────┘ │
│                                              │
│  ┌─ Будут добавлены к главной записи ───────┐ │
│  │  📞 Телефоны: +77012345678, +77098765432 │ │
│  │  ✉ E-mail: a@b.com, c@d.com (2 уник.)   │ │
│  │  🏢 Привязки к компаниям: +1             │ │
│  │  📋 Активности / лента событий: +8       │ │
│  └─────────────────────────────────────────┘ │
│                                              │
│  ┌─ Будут удалены после объединения ────────┐ │
│  │  ⚠ ID 34 — ТОО Альфа  [🔗]              │ │
│  │  ⚠ ID 78 — Альфа ТОО  [🔗]              │ │
│  └─────────────────────────────────────────┘ │
│                                              │
│  [← Назад]                [Объединить ×]     │
└──────────────────────────────────────────────┘
```

---

## Лейаут и размеры диалога

| Параметр | Значение |
|---|---|
| Ширина | `860px` (было 700px — нужно место для per-field таблицы с N колонками) |
| Max-width | `96vw` |
| Max-height | `92vh` с `overflow-y: auto` на `.dedup-dialog__body` |
| Radius | `$radius-lg` (8px) — Dialog-пресет PrimeVue |
| Header | PrimeVue `Dialog` `#header` slot: иконка + заголовок + шаг-индикатор |
| Footer | `#footer` slot: кнопки слева (Назад/Закрыть) + справа (действие) |

---

## Компоненты PrimeVue

| Компонент | Использование |
|---|---|
| `Dialog` | Корневой контейнер. `modal`, `closable`, `:draggable="false"`. |
| `SelectButton` | Выбор scope (Компании/Контакты) на шаге scan. |
| `Button` | Все действия. Drill-in — `severity="secondary" text size="small" icon="pi pi-external-link"`. |
| `DataTable` + `Column` | Таблица кандидатов (шаг 2) + таблица полей (шаг 3). `size="small"`. |
| `RadioButton` | Per-field выбор источника в каждой ячейке таблицы полей (шаг 3). |
| `ProgressSpinner` | Во время сканирования (шаг 1). `style="width:48px;height:48px"` |
| `Tag` | Статус-бейдж в строке кандидата (`severity="secondary" size="small"`). |
| `Message` | Инфо-банер об append-поведении (`severity="info"`). Ошибки merge (`severity="error"`). |
| `Popover` | Дропдаун «Не дубль»: список пар для per-pair dismiss (если группа > 2). |
| `Skeleton` | Пока загружается `bulkEntities` или превью полей — 4 строки `height="20px"`. |

---

## Шаг 1 — Scan

Только в `mode='dedup'`. Без изменений vs текущий (SelectButton scope + кнопка Сканировать).

**Изменение:** переход к шагу candidates происходит автоматически по завершении scan (уже так и есть).

---

## Шаг 2 — Candidates (dedup-режим)

### Строка группы

Каждая группа — карточка (`border: 1px solid $surface-200; border-radius: $radius-md`):

```
┌─ Группа 1 — «ТОО Альфа» (3 записи) ─────────────────────────┐
│  [DataTable строки кандидатов]                                 │
│  ─────────────────────────────────────────────────────────── │
│  Строка действий группы: [🔗 ID 12] [🔗 ID 34] ... [Объед.] [Не дубль ▾] │
└───────────────────────────────────────────────────────────────┘
```

### DataTable кандидатов (как сейчас + drill-in)

Колонки: `ID` (80px) · `Название/ФИО` · `Ключ (БИН/email)` · `Дата создания`.

**Drill-in (3.1):** в колонке `Название/ФИО` — имя является ссылкой:
```html
<a :href="`/contacts/${data.id}`" target="_blank" class="dedup-dialog__entity-link">
  <i class="pi pi-external-link" style="font-size:10px; margin-left:4px" />
  {{ getCandidateName(data) }}
</a>
```
`target="_blank"` — новая вкладка, не прерывает flow диалога.

### Кнопка «Объединить»

`severity="warning" size="small" icon="pi pi-objects-column"` — без изменений. Открывает шаг merge
для данной группы.

### Кнопка «Не дубль» с per-pair (3.2)

**Группа из 2 записей** → `Button` `severity="secondary" text size="small"` «Не дубль» — вызывает
`dismissPair(entityA.id, entityB.id)` напрямую.

**Группа из 3+ записей** → `Button` с иконкой `pi pi-chevron-down` + `Popover` (ref через
`:pt="{ root: { id: 'dismiss-pop-' + group.key } }"`) — при клике открывает Popover со списком
всех уникальных пар группы:

```
┌─ Отметить «Не дубль» ─────────────────────────┐
│  [ ID 12 — ТОО Альфа  ✕  ID 34 — ТОО Альфа ] │
│  [ ID 12 — ТОО Альфа  ✕  ID 78 — Альфа ТОО ] │
│  [ ID 34 — ТОО Альфа  ✕  ID 78 — Альфа ТОО ] │
└───────────────────────────────────────────────┘
```

Каждая строка Popover — кликабельна, вызывает `dismissPair(a, b)`. После успеха:
- Если пара единственная в группе — группа исчезает из списка.
- Если пар осталось ≥ 1 — группа остаётся, но кандидат, входящий во все оставшиеся пары как
  «dismissed», фильтруется из `entities`.
- Toast `severity="success"`.

**useDedupFlow** — нужна замена `dismissGroup(group)` на `dismissPair(entityAId, entityBId, groupKey)`.

---

## Шаг 3 — Merge

### Секция «Главная запись»

Заголовок: `«Выберите главную запись»` — `$font-size-sm font-weight-semibold $surface-700`.

Для каждого кандидата — строка:
```
(RadioButton) [Аватар 32px] Имя  ID-бейдж  email  телефон  [🔗 Открыть]
```

- `RadioButton v-model="masterId" :value="entity.id"` — без изменений.
- Аватар: `CrmAvatar :name="getCandidateName(entity)" :size="32"`.
- ID-бейдж: `Tag :value="\`ID ${entity.id}\`" severity="secondary" size="small"`.
- Ссылка drill-in: `Button severity="secondary" text size="small" icon="pi pi-external-link"
  :as="'a'" :href="entityRoute(entity)" target="_blank"` — новая вкладка.
- Дефолт: `masterId = entities[0].id` (первый в списке).

### Секция «Поля результата» (per-field, 4.1/7.1)

Заголовок: `«Выберите значение для каждого поля»`.

`DataTable :value="mergePreviewRows" size="small"`:

| Колонка | Что содержит |
|---|---|
| `Поле` (140px) | Лейбл поля (`getFieldLabel(row.key)`) |
| По одной на каждого кандидата | Ячейка: RadioButton + значение |

**Ячейка поля:**
```html
<div class="merge-field-cell" :class="{ 'merge-field-cell--empty': isEmpty, 'merge-field-cell--selected': isSelected }">
  <RadioButton
    :model-value="fieldOverrides[row.key]"
    :value="entity.id"
    :name="`field-${row.key}`"
    :disabled="isEmpty"
    @change="setFieldOverride(row.key, entity.id)"
  />
  <span class="merge-field-cell__value">{{ value || '—' }}</span>
</div>
```

**Логика дефолта per-field:**
- При выборе нового `masterId` → все `fieldOverrides[key]` сбрасываются к `masterId`.
- Если `masterId` у данного поля пустое значение (`'—'`), но есть непустое у другого кандидата — RadioButton у мастера доступен (disabled=false), но у заполненного кандидата также доступен. Пользователь сам выбирает.
- Если все кандидаты имеют одинаковое значение поля — RadioButton'ы всё равно показываются (пользователь подтверждает выбор источника, хотя значение одинаково).
- `disabled="true"` только если значение поля у данного кандидата `null/''` — RadioButton некликабелен, ячейка `opacity: 0.4`.

**Визуальное состояние выбранной ячейки:**
```scss
.merge-field-cell--selected {
  background: var(--p-primary-50);
  border-radius: $radius-sm;

  .app-dark & {
    background: rgba($primary-900, 0.18);
  }
}
```

**Тип данных fieldOverrides** (в composable):
```ts
// key = field key (string), value = entity.id (number) — id кандидата-источника
const fieldOverrides = ref<Record<string, number>>({})
```

**Передаётся в endpoint** как `field_overrides: { [fieldKey]: sourceEntityId }`.

### Секция «Будут добавлены» (append-блок)

Заголовок: `«Будут добавлены к главной записи»` + иконка `pi pi-plus-circle` в `$green-700`.

Всегда append (без RadioButton — не редактируется пользователем). Показываем итог:

```
📞 Телефоны: {список всех уникальных из дублей, не существующих у мастера} (+N)
✉  E-mail: {список}                                                           (+N)
🏢 Привязки к сделкам: +N                                                    
🏢 Привязки к компаниям: +N    (только для контактов)
📋 Активности / лента событий: +N
```

Данные для этой секции вычисляются локально из объекта `selectedGroup.entities` (без доп. запроса):
- Считаем уникальные значения телефонов/email из дублей (кандидаты != masterId), которые **отсутствуют** у мастера → показываем с конкретными значениями (через `Tag size="small"`) и счётчиком.
- Для привязок к сделкам/компаниям и активностям — у DedupCandidate есть поля `open_deals_count` / `company_links_count` / `activities_count` — показываем суммированный прирост. Если поля отсутствуют в текущей модели — `backend-блокер B-1`.
- Примечание под блоком (Message severity="info" small): `«Каналы связи, дублирующие существующие, будут пропущены»`.

**Edge: дублирующиеся каналы.** Если телефон/email кандидата совпадает с уже имеющимся у мастера — в списке не показываем, только считаем «пропущено». Бейдж `N уникальных (M пропущено)`.

### Секция «Будут удалены»

Заголовок: `«Будут удалены после объединения»` + иконка `pi pi-trash` в `$red-500`.

Список дублей (кандидаты != masterId) — каждая строка:
```
⚠ Tag[ID N]  Имя записи  [🔗 Открыть]
```

`Message severity="warn" size="small"` под списком: `«После объединения эти записи будут безвозвратно удалены.»`

### Кнопка «Объединить»

`severity="danger"` (необратимое действие — текущее поведение правильное, оставляем).
`:loading="isMerging"`. `:disabled="!masterId"`.

**Payload API:**
```ts
{
  scope: 'contact' | 'company',
  master_id: number,
  duplicate_ids: number[],
  field_overrides: Record<string, number>   // { fieldKey: sourceEntityId }
}
```

`field_overrides` — новый параметр, `backend-блокер B-2`.

---

## Bulk-вход (mode='bulk', 7.1)

### Как открывается

В `ContactsBulkToolbar.vue` кнопка «Объединить»:
- **Текущее ограничение** `selectedCount !== 2` → **снять**: кнопка активна при `selectedCount >= 2`.
- При клике: `emit('merge')` → `index.vue` → `onMergeClick()`:

```ts
function onMergeClick() {
  if (bulk.selectedCount.value < 2) return
  // Передаём выбранные записи в MergeDialog напрямую
  bulkMergeEntities.value = items.value.filter(i => bulk.selectedIds.value.has(i.id)) as DedupCandidate[]
  bulkMergeOpen.value = true
}
```

В template: два экземпляра MergeDialog (или один с вычисляемым `mode`):

```html
<!-- Dedup-вход -->
<MergeDialog
  v-model:visible="dedupOpen"
  mode="dedup"
  :entity-type="entityType"
  @merged="load"
/>
<!-- Bulk-вход -->
<MergeDialog
  v-model:visible="bulkMergeOpen"
  mode="bulk"
  :bulk-entities="bulkMergeEntities"
  :entity-type="entityType"
  @merged="onBulkMerged"
/>
```

`onBulkMerged`: закрывает bulk-режим (`bulk.exitBulk()`) + перезагружает список.

### Поведение в bulk-режиме

- Шаги `scan` и `candidates` **полностью пропускаются** — компонент монтируется сразу на шаге `merge`.
- `selectedGroup` заполняется из `bulkEntities` (без score/key из scan — они не нужны для merge).
- `scope` определяется из `entityType` prop (не SelectButton).
- Заголовок диалога: `«Объединение N записей»`.
- В Footer нет кнопки «← Назад» (нечего возвращаться).
- После успешного merge: `emit('merged')`, закрыть диалог.

**Edge: количество записей.** При 2 записях — таблица полей имеет 2 колонки источника (ширина
диалога 860px достаточна). При 3 — 3 колонки. При 4+ — колонки сужаются (`min-width: 120px`,
горизонтальный скролл в DataTable). Для bulk рекомендовать не более 5 записей — `ContactsBulkToolbar`
показывает `title="Рекомендуется до 5 записей"` при `selectedCount > 5`.

---

## Обе темы (light/dark)

| Элемент | Light | Dark |
|---|---|---|
| Фон диалога | `$surface-card` (`#FFFFFF`) | `var(--p-surface-100)` (NOT `--p-surface-900`) |
| Строки DataTable | `$surface-50` hover | `var(--p-surface-200)` hover |
| Выбранная ячейка поля | `var(--p-primary-50)` | `rgba($primary-900, 0.18)` |
| Append-блок фон | `$green-50` border `$green-200` | `rgba($green-900, 0.15)` border `rgba($green-700, 0.4)` |
| Delete-блок фон | `$red-50` border `$red-200` | `rgba($red-900, 0.15)` border `rgba($red-700, 0.4)` |
| Drill-in ссылка | `$primary-900` | `var(--p-primary-color)` |
| Section header | `$surface-700` | `var(--p-surface-200)` ← не `$surface-700` (инвертирован!) |

**Внимание:** dark-режим PrimeVue инвертирует шкалу поверхностей (100 = тёмный, 900 = светлый).
В `.app-dark &` писать `var(--p-surface-200)` там, где в light был `$surface-700`.

---

## States

| Состояние | Компонент | Поведение |
|---|---|---|
| `loading` (scan) | `ProgressSpinner` центр + текст «Поиск дублей…» | Кнопка «Сканировать» `:loading="scanning"` |
| `loading` (merge) | `Button` «Объединить» `:loading="isMerging"` | Таблица полей остаётся видимой |
| `loading` (dismiss) | `Button` «Не дубль» `:loading="isDismissing"` | |
| `loading` (bulk-mount) | `Skeleton` 4 строки в секции полей | Пока `bulkEntities` prop не пришёл непустым |
| `empty` (candidates) | `pi pi-check-circle` зелёная + «Дубликаты не найдены» | Только в dedup-режиме |
| `error` (merge) | `Message severity="error"` под секцией полей | `mergeError` из composable |
| `error` (dismiss) | Toast `severity="error"` | |
| `error` (scan) | Toast `severity="error"` | |

---

## Interactions

| Элемент | Действие | Результат | Endpoint |
|---|---|---|---|
| Кнопка «Сканировать» | click | scan → шаг candidates | `GET /api/dedup/scan?scope=X` |
| Строка кандидата — имя | click (target=_blank) | Открыть карточку в новой вкладке | — (router route) |
| Кнопка «Объединить» (в группе) | click | Переход к шагу merge | — |
| Кнопка «Не дубль» (2 записи) | click | `dismissPair(a, b)` → удаление группы | `POST /api/dedup/dismiss` |
| Кнопка «Не дубль ▾» (3+ записей) | click | Открыть Popover пар | — |
| Строка пары в Popover | click | `dismissPair(a, b)` → обновление группы | `POST /api/dedup/dismiss` |
| RadioButton мастера | change | Сброс `fieldOverrides` к новому мастеру | — |
| RadioButton поля (per-field) | change | `fieldOverrides[key] = entity.id` | — |
| Кнопка «🔗 Открыть» (в merge) | click | Открыть карточку в новой вкладке | — |
| Кнопка «Объединить» (submit) | click | `submitMerge()` → Toast success → шаг candidates / закрыть | `POST /api/dedup/merge` |
| Кнопка «← Назад» | click | Вернуться на предыдущий шаг | — |
| `[×]` / Overlay click | close | `onHide()` → `reset()` | — |

---

## useDedupFlow — изменения

### Новые параметры opts

```ts
export const useDedupFlow = (opts: {
  onMerged?: () => void
  mode?: 'dedup' | 'bulk'
  bulkEntities?: Ref<DedupCandidate[]>
  entityType?: Ref<'contact' | 'company'>
} = {}) => {
```

При `mode='bulk'`:
- `step` инициализируется `'merge'` (не `'scan'`).
- `scope` берётся из `opts.entityType`.
- `selectedGroup` инициализируется из `opts.bulkEntities` (без scan).

### Новые функции

```ts
// per-field source selection
const fieldOverrides = ref<Record<string, number>>({})

function setFieldOverride(key: string, entityId: number) {
  fieldOverrides.value = { ...fieldOverrides.value, [key]: entityId }
}

function resetFieldOverridesToMaster(masterEntityId: number) {
  const overrides: Record<string, number> = {}
  for (const key of getFieldKeys(selectedGroup.value!.entities[0]!)) {
    overrides[key] = masterEntityId
  }
  fieldOverrides.value = overrides
}
```

Вызов `resetFieldOverridesToMaster` — в `watch(masterId, ...)`.

### Замена dismissGroup → dismissPair

```ts
async function dismissPair(entityAId: number, entityBId: number, groupKey: string) {
  await dismissMutation.run(
    () => dedupApi.dismiss({ scope: scope.value, entity_a_id: entityAId, entity_b_id: entityBId }),
    {
      onSuccess() {
        // Убрать пару из группы или всю группу
        toast.add({ severity: 'success', summary: t('dedup.dialog.merge.dismissSuccess'), life: 4000 })
        const groupIdx = scanResource.data.value.findIndex(g => g.key === groupKey)
        if (groupIdx === -1) return
        const group = scanResource.data.value[groupIdx]!
        // Если в группе только 2 записи — удаляем группу
        if (group.entities.length <= 2) {
          scanResource.data.value = scanResource.data.value.filter(g => g.key !== groupKey)
        } else {
          // Иначе удаляем из entities того, с кем dismissed (entity_b, если a — якорь)
          scanResource.data.value[groupIdx]!.entities =
            group.entities.filter(e => e.id !== entityBId)
        }
      },
      onError(err) { /* toast error */ },
    }
  )
}
```

### submitMerge — добавить fieldOverrides

```ts
await dedupApi.merge({
  scope: scope.value,
  master_id: masterId.value!,
  duplicate_ids: duplicateIds,
  field_overrides: fieldOverrides.value,   // новый параметр
})
```

---

## i18n-ключи

### RU (обязательно)

```json
{
  "dedup": {
    "dialog": {
      "title": "Дедупликация",
      "titleWithCount": "Найдено групп: {count}",
      "merge": {
        "title": "Объединение записей",
        "titleBulk": "Объединение {count} записей",
        "back": "← Назад",
        "masterLabel": "Выберите главную запись",
        "fieldsLabel": "Выберите значение для каждого поля",
        "appendLabel": "Будут добавлены к главной записи",
        "deleteLabel": "Будут удалены после объединения",
        "appendChannelsDupNote": "Каналы связи, дублирующие существующие, будут пропущены",
        "deleteWarning": "После объединения эти записи будут безвозвратно удалены.",
        "phones": "Телефоны",
        "emails": "E-mail",
        "dealLinks": "Привязки к сделкам",
        "companyLinks": "Привязки к компаниям",
        "activities": "Активности / лента событий",
        "uniqueOf": "{unique} уникальных ({skipped} пропущено)",
        "submit": "Объединить",
        "success": "Записи объединены",
        "error": "Ошибка объединения",
        "warning": "Будут удалены записи: ID {id}",
        "wholeRecordNote": "Выберите источник для каждого поля вручную или оставьте значения главной записи",
        "dismissSuccess": "Пара помечена как «не дубль»",
        "openCard": "Открыть карточку"
      },
      "candidates": {
        "group": "Группа {n} — «{name}» ({count} записи)",
        "merge": "Объединить",
        "notDuplicate": "Не дубль",
        "notDuplicatePairs": "Не дубль — выбрать пару",
        "columns": {
          "id": "ID",
          "name": "Название / ФИО",
          "key": "Ключ",
          "createdAt": "Дата создания"
        }
      },
      "scan": {
        "button": "Сканировать",
        "scanning": "Поиск дублей…",
        "empty": "Дубликаты не найдены",
        "emptySubtitle": "Все записи уникальны",
        "error": "Ошибка сканирования",
        "type": {
          "company": "Компании",
          "contact": "Контакты"
        }
      }
    }
  },
  "contacts_bulk": {
    "mergeHint": "Выберите 2 или более записей",
    "mergeHintMax": "Рекомендуется до 5 записей"
  }
}
```

### EN (задел)

```json
{
  "dedup": {
    "dialog": {
      "title": "Deduplication",
      "titleWithCount": "Groups found: {count}",
      "merge": {
        "title": "Merge records",
        "titleBulk": "Merge {count} records",
        "back": "← Back",
        "masterLabel": "Select master record",
        "fieldsLabel": "Select source for each field",
        "appendLabel": "Will be added to master",
        "deleteLabel": "Will be deleted after merge",
        "appendChannelsDupNote": "Channels that duplicate existing ones will be skipped",
        "deleteWarning": "These records will be permanently deleted after merge.",
        "phones": "Phones",
        "emails": "Emails",
        "dealLinks": "Deal links",
        "companyLinks": "Company links",
        "activities": "Activities / event feed",
        "uniqueOf": "{unique} unique ({skipped} skipped)",
        "submit": "Merge",
        "success": "Records merged",
        "error": "Merge error",
        "warning": "Will delete records: ID {id}",
        "wholeRecordNote": "Choose source for each field or keep master values",
        "dismissSuccess": "Pair marked as not a duplicate",
        "openCard": "Open card"
      },
      "candidates": {
        "group": "Group {n} — \"{name}\" ({count} records)",
        "merge": "Merge",
        "notDuplicate": "Not a duplicate",
        "notDuplicatePairs": "Not a duplicate — select pair",
        "columns": {
          "id": "ID",
          "name": "Name",
          "key": "Key",
          "createdAt": "Created"
        }
      },
      "scan": {
        "button": "Scan",
        "scanning": "Searching for duplicates…",
        "empty": "No duplicates found",
        "emptySubtitle": "All records are unique",
        "error": "Scan error",
        "type": {
          "company": "Companies",
          "contact": "Contacts"
        }
      }
    }
  },
  "contacts_bulk": {
    "mergeHint": "Select 2 or more records",
    "mergeHintMax": "Recommended: up to 5 records"
  }
}
```

---

## SCSS-токены (только из системы)

```scss
// Размеры
$dialog-width: 860px;

// Цвета — только токены, никаких hex
.merge-field-cell--selected {
  background: var(--p-primary-50);
  .app-dark & { background: rgba($primary-900, 0.18); }
}

.dedup-dialog__append-block {
  border: 1px solid $green-200;
  background: $green-50;
  border-radius: $radius-md;
  padding: $space-3 $space-4;
  .app-dark & {
    background: rgba(43, 107, 56, 0.15);
    border-color: rgba(54, 160, 75, 0.4);
  }
}

.dedup-dialog__delete-block {
  border: 1px solid $red-200;
  background: $red-50;
  border-radius: $radius-md;
  padding: $space-3 $space-4;
  .app-dark & {
    background: rgba(155, 25, 23, 0.15);
    border-color: rgba(230, 28, 20, 0.4);
  }
}

.dedup-dialog__entity-link {
  color: $primary-900;
  text-decoration: none;
  font-weight: $font-weight-semibold;
  &:hover { text-decoration: underline; }
  .app-dark & { color: var(--p-primary-color); }
}

.dedup-dialog__section-header {
  font-size: $font-size-sm;
  font-weight: $font-weight-semibold;
  color: $surface-700;
  display: flex;
  align-items: center;
  gap: $space-2;
  margin-bottom: $space-3;
  .app-dark & { color: var(--p-surface-200); }   // инвертированная dark-шкала!
}
```

---

## Backend-блокеры (требуется backend)

| ID | Описание | Эндпоинт / поле |
|---|---|---|
| **B-1** | `DedupCandidate` должен содержать `open_deals_count`, `company_links_count`, `activities_count` для append-блока. Сейчас у модели их нет. | `GET /api/dedup/scan` — добавить aggregates в response. |
| **B-2** | `POST /api/dedup/merge` принимает `field_overrides: {[key]: sourceEntityId}` — применяет per-field значение из указанного кандидата. | Контракт: `{ scope, master_id, duplicate_ids, field_overrides }` |
| **B-3** | `POST /api/dedup/dismiss` работает корректно для per-pair — убедиться, что принимает произвольную пару `entity_a_id + entity_b_id`, не только первую из группы. Текущий `dismissGroup` шлёт `entities[0]` и `entities[1]` — может быть нормально, если backend различает пары. | `POST /api/dedup/dismiss` — проверить/задокументировать |

---

## Vizion-эталон

Аналог в Vizion: `./examples/vizion/front/src/pages/` — нет прямого аналога merge-диалога с
per-field выбором, но паттерн DataTable с RadioButton в ячейках идентичен Vizion-у
(`DealsPage/components/DealImportDialog.vue` — шаг mapping). Смотри его как структурный эталон
для per-field таблицы.

---

## Открытые вопросы

1. **ОВ-1 (backend B-1):** нужны aggregates в scan-ответе для append-блока. Backend-специалисту
   уточнить, какие поля включить в `DedupCandidate`.
2. **ОВ-2 (bulk ≥ 3):** при bulk-merge 3+ записей с per-field таблицей 3+ колонками — PrimeVue
   DataTable без `scrollable` может переполниться. Добавить `:scrollable="true"
   scroll-height="300px"` на секцию полей.
3. **ОВ-3 (dismiss behaviour):** после dismiss пары в группе из 3+ — оставшиеся записи
   потенциально больше не являются дублями. Бизнес-решение: убирать только dismissed-запись или
   показывать группу пересчитанной? Сейчас ТЗ убирает `entity_b` — при условии что dismiss
   означает «B не дубль A» и B может ещё быть дублём C.
4. **ОВ-4 (Vizion import mapping):** проверить паттерн per-field RadioButton в Vizion
   `DealImportDialog` — если там DataTable с `RadioButton` в `#body`, использовать ту же
   структуру CSS-классов.
