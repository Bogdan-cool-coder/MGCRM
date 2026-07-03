# ТЗ-дополнение: Settings hi-fi — закрытие 4 гэпов против эталонов

**Зачем:** довести уже отшипперенный Settings hi-fi (`settings-hifi-tz.md`, этапы 0–3) до полного
соответствия апрувнутым мокапам. Юзер отловил 4 расхождения между реализацией и эталонами
`settings.html` / `access-section.jsx` / `pipeline.html`. Здесь — точечная дельта по каждому:
целевой вид из мокапа → текущее состояние → что менять → компоненты/состояния/i18n/токены/темы.

**Где в коде:** `front/src/pages/SettingsPage/` + `front/src/pages/AccessControlPage/` +
`front/src/pages/PipelineSettingsPage/`.

**Эталоны:**
- Гэп 1, 2 → `design-handoff/redesign/settings.html` (`GROUPS`, `DirectoriesTab` L259–408).
- Гэп 3 → `design-handoff/redesign/access-section.jsx` (`A_ChartNode` L143–161,
  `A_DepartmentsTab` L234–306).
- Гэп 4 → `design-handoff/redesign/pipeline.html` (репо-копия — уже лежит).

> **Токен-дисциплина (charter §2/§4):** ни одного hex/px мимо токенов. Пиши `$scss-var` /
> `var(--p-*)` / `var(--mg-*)`. Обе темы обязательны. Инвертированная dark surface-шкала: muted-
> текст на тёмном сайдбаре — `var(--p-surface-600)`+ (не `surface-400`). 3 мёртвых dark-селектора
> (`:deep(.app-dark)`, nested `.app-dark &` внутри `:deep()`, dark-ветка на theme-reactive
> токене) — запрещены. Бренд-инвариант navy `#172747` — единственный допустимый хардкод.

---

## Гэп 1 — Группа СПРАВОЧНИКИ = ровно 2 пункта (сайдбар-реорг)

### Целевой вид (мокап `settings.html` L43–59, `GROUPS`)

Сайдбар состоит из **4 групп** с точным составом:

```
🔍 Поиск по настройкам…

АККАУНТ
  • Профиль

ИНТЕГРАЦИИ
  • Каналы связи

СПРАВОЧНИКИ
  • Справочники                        ← открывает таб-стрип (Страны · Теги · Кастомные поля ·
  • Воронка продаж          ↗          ← Каналы привлечения · Причины отказа · Каталог …)
                                          link-out, иконка pi-external-link

СИСТЕМА
  • Пользователи                  14   ← meta-счётчик активных
  • Доступ и оргструктура
  • Журнал автоматизаций
  • Сброс системы  (danger, красный текст)
```

Ключевое: под **СПРАВОЧНИКИ** — **два** пункта. Первый («Справочники») ведёт на единый экран с
таб-стрипом всех справочников (`DirectoriesTab` в мокапе, `SectionDirectories.vue` у нас — уже
существует). Второй («Воронка продаж») — link-out со стрелкой.

### Текущее состояние (`SettingsSidebar.vue` L126–151)

Группа `directories` **разворачивает все 11 справочников отдельными пунктами** сайдбара:
`countries / tags / acq-channels / disc-reasons / catalog / exchange-rates / pipeline-stg /
doc-templates / tpl-variables / approval-routes / msg-templates`. Плюс отдельная группа `sales`
с `motivation-builder`. То есть сайдбар «раздут», а компонент `SectionDirectories.vue` (единый
таб-экран, уже реализованный — L17–33) в сайдбаре **вообще не имеет своего пункта** и недостижим.

**Расхождение:** мокап = 2 пункта; реализация = 11 пунктов + недостижимый единый экран.

### Дельта (чисто-фронт)

1. **Свернуть все Directory-ключи в один пункт `directories`.** В `GROUPS` группа
   `directories.sections` = **2 элемента**:
   - `{ key: 'directories', labelKey: 'settings.sections.directories.title', icon: 'pi pi-folder-open', phase: 1, roles: ['admin','lawyer','director','manager'] }`
     — открывает `SectionDirectories.vue`. Роли — объединение всех per-tab ролей (внутри
     компонента per-tab роль-гейт уже есть, L85–108; сайдбарный пункт виден, если пользователю
     доступен хотя бы один таб — как `hasAnyAccess`).
   - `{ key: 'pipeline-stg', …, linkOut: '/settings/pipeline' }` — оставить как есть
     (link-иконка + navigateOutOf уже работают).
2. **Активность пункта «Справочники».** По аналогии с `isProfileSection` — пункт `directories`
   активен, если `activeSection` ∈ (`DIRECTORIES_KEYS` ∪ `DOCUMENTS_KEYS`). Добавить хелпер
   `isDirectoriesSection(key)` в `useSettings.ts` и использовать в `isSectionActive`
   (`SettingsSidebar.vue` L238–243, тем же паттерном, что профиль).
3. **Маршрутизация `?section=`.** Deep-link `?section=directories` → `SectionDirectories` с
   первым доступным табом. Существующие `?section=countries|tags|…` продолжают работать (открывают
   `SectionDirectories` с соответствующим активным табом) — `resolveSection` возвращает ключ,
   `index.vue` при любом directory/document-ключе рендерит `SectionDirectories` и прокидывает
   `activeTab`. **Требуется правка `index.vue`:** ветка рендера для группы directory/document-
   ключей → всегда `<SectionDirectories :active-tab="…" />`, где `activeTab` = `activeSection`,
   если он directory/document-ключ, иначе первый доступный. **ОВ-1** (ниже).
4. **`motivation-builder`.** Мокап `settings.html` НЕ показывает группу «Продажи» /
   «Мотивационные карты» (её нет в `GROUPS`). Но МК-конструктор — реальная отшипперенная фича
   (`SALES_KEYS`, `SectionMotivationBuilder`). **Не удалять.** Оставить группу `sales` как есть —
   это осознанное расширение поверх мокапа, отметить в ОВ-2. (Мокап — срез на момент рисования;
   МК добавлен позже.)
5. **`custom-fields` в сайдбаре.** Сейчас `custom-fields` есть как таб в `SectionDirectories`
   (L22, L39), но в `SettingsSidebar.GROUPS` его нет отдельным пунктом (и не надо). После
   сворачивания — он просто один из табов под «Справочники». Ничего не трогаем.

### Что сохраняем (уже совпадает с мокапом)

- meta-счётчик «Пользователи 14» — `sectionMeta()` + `activeUsersCount` уже реализованы
  (L214–232). ✔
- danger-пункт «Сброс системы» — `roles: ['admin']` + danger-класс уже есть. ✔ (визуально:
  красный текст `$surface`→ мокап `var(--mg-status-danger-text)`; у нас должен быть
  `--p-red-*` — сверить, что danger-текст применён к пункту `system-reset`, сейчас в шаблоне
  спец-класса под danger нет — **добавить** модификатор `settings-nav-item--danger` для пунктов
  с `danger: true` флагом, текст `var(--p-red-500)` / dark `var(--p-red-400)`, только когда
  не active).
- link-иконка «Воронка продаж» — `section.linkOut` + `pi-external-link` уже есть (L47). ✔
- поле поиска сверху — уже есть (L4–13). ✔

### Компоненты / состояния

| Зона | Компонент | Дельта |
|------|-----------|--------|
| Пункт «Справочники» | `<button class="settings-nav-item">` | новый единый пункт, active при directory/doc-ключах |
| Пункт «Сброс системы» | `settings-nav-item--danger` | новый модификатор — красный текст вне active |
| Detail-рендер | `index.vue` | directory/doc-ключ → `<SectionDirectories :active-tab>` |

- **empty (поиск):** без изменений (`isSearchEmpty` уже есть).
- **active (Справочники):** navy-инвертированный фон (`--p-primary-50` light / `--p-primary-950`
  dark) + `inset 3px` — как у других пунктов.

### i18n

```json
{
  "ru": {
    "settings.sections.directories.title": "Справочники"
  },
  "en": {
    "settings.sections.directories.title": "Directories"
  }
}
```
(Остальные ключи справочников — `settings.sections.countries.title` и т.д. — сохраняются для
таб-стрипа, просто больше не рендерятся пунктами сайдбара.)

### Токены

- danger-пункт: `color: var(--p-red-500)` / dark `var(--p-red-400)`; при active → навы-инверт
  как обычный пункт (danger только в состоянии покоя).
- Остальное — существующие токены сайдбара (без изменений).

### Обе темы

- Пункт «Справочники» active: light `--p-primary-50` фон + `$primary-900` текст; dark
  `--p-primary-950` фон + `--p-primary-200` текст (паттерн уже в `&--active` L357–372).
- danger-текст: light `--p-red-500`, dark `--p-red-400` (инверт: в dark red светлеет).

---

## Гэп 2 — Режим «Редактировать» в справочниках

### Целевой вид (мокап `settings.html` `DirectoriesTab` L360–402)

**Вне режима редактирования (default):** таб-стрип + toolbar справа (`[✎ Редактировать]
[+ Добавить]`). В таблице — **чистые строки:** только данные + тумблер «Активна» (для табов с
`toggle`-колонкой). Колонки-действия (`menu`/`edit`/`editdel`) и drag-handle **скрыты**
(`visCols = cur.cols.filter(!isAction)`, L353).

**В режиме редактирования** (клик «Редактировать»):
- кнопка меняется на **«Завершить редактирование»** с чек-иконкой (`pi-check`), становится
  активно-подсвеченной (navy border+text: `{...btn('ghost'), color: primary, borderColor: primary}`).
- у каждой строки **слева появляется drag-handle** (`pi pi-bars`, колонка width 34, только для
  табов с `drag: true` — Страны/Теги/Каналы привлечения/Причины отказа); строка становится
  `draggable`, cursor `grab`.
- **справа появляется кебаб-меню** (`pi pi-ellipsis-v`) → выпадашка: «Редактировать» (`pi-pencil`) /
  «Удалить» (`pi-trash`, красный). У каталога — расширенный набор (Редактировать/Дублировать/
  Архивировать/Удалить). У некоторых справочников (`edit`/`editdel`) вместо кебаба — прямые
  иконки карандаш/корзина.

Каким табам применимо (по мокапу): **всем табам, где `cur.rows.length > 0`** кнопка
«Редактировать» показывается (L369: `{cur.rows.length > 0 && …}`). drag-handle — только табам с
`drag: true` (простые справочники: Страны, Теги, Каналы привлечения, Причины отказа; НЕ у
Каталога/Курсов валют/Шаблонов — там свои тулбары `tb` и порядок не драгается).

### Текущее состояние

- `SectionDirectories.vue` — таб-стрип есть; каждый таб — обёртка (`DirTabCountries` и т.д.),
  которая рендерит standalone-страницу (`CountriesPage`) с `:embedded="true"` + toolbar-кнопка
  «+ Добавить» (`DirTabCountries.vue` L6–13).
- **Режима «Редактировать» НЕТ.** Кнопки «Редактировать»/«Завершить редактирование» нет. Строки
  таблицы всегда показывают действия/reorder так, как их рендерит standalone-страница (у каждой
  свой паттерн — где-то always-on action-колонка, где-то row-reorder всегда включён).

**Расхождение:** мокап прячет действия/drag за режимом «Редактировать» (чистый режим просмотра по
умолчанию); у нас действия всегда видны, единого режима нет.

### Дельта

Реализуется **на уровне обёрток DirTab*** (единый паттерн) — не переписываем standalone-страницы
по существу, а вводим **edit-mode toggle**, который стандартные страницы получают пропом.

1. **Toolbar каждого DirTab** (`dir-tab-toolbar`, паттерн `DirTabCountries.vue` L3–13): слева от
   «+ Добавить» добавить кнопку-переключатель:
   - вне режима: `Button` `severity="secondary" outlined size="small" icon="pi pi-pencil"
     :label="t('common.edit')"`.
   - в режиме: `icon="pi pi-check" :label="t('settings.directories.finishEditing')"`, `outlined`
     снять / подсветить navy (`:outlined="false"` + класс active, либо `severity` primary-outline).
   - показывать только если строк > 0 (`v-if="pageRef?.rowCount > 0"`).
2. **Проброс режима в standalone-страницу.** Обёртка держит `const editing = ref(false)` и
   передаёт `:edit-mode="editing"` в `<CountriesPage :embedded :edit-mode>`. Standalone-страница
   (уже поддерживает `embedded`) расширяется пропом `editMode?: boolean`:
   - **колонка действий (кебаб/карандаш/корзина) рендерится `v-if="editMode"`.**
   - **row-reorder (`reorderableRows` / drag-handle) активен `:reorderable-rows="editMode"`** —
     только для справочников с сортировкой (Страны, Теги, Каналы привлечения, Причины отказа).
   - тумблер «Активна» — **виден всегда** (и в режиме, и вне; это не «действие», а свойство).
3. **Список DirTab, к которым применяем edit-mode:**
   - **С drag + kebab:** `DirTabCountries`, `DirTabTags`, `DirTabAcqChannels`, `DirTabDiscReasons`.
   - **Только kebab/actions (без drag — свой тулбар):** `DirTabCatalog`, `DirTabExchangeRates`,
     `DirTabDocTemplates`, `DirTabTplVariables`, `DirTabApprovalRoutes`, `DirTabMsgTemplates`,
     `DirTabCustomFields`. У них порядок не драгается — edit-mode прячет/показывает только
     action-колонку. (У Каталога кебаб уже расширенный — сохранить его пункты.)
4. **Сброс режима при смене таба.** `SectionDirectories.onTabChange` → сбросить `editing` в каждой
   обёртке (обёртки размонтируются/перемонтируются по `v-if activeTab` — L37–47, так что режим
   сбрасывается автоматически; проверить, что `v-if` а не `v-show`). ✔ (сейчас `v-if`).

### Компоненты / состояния

| Элемент | Действие | Результат | Состояния |
|---------|----------|-----------|-----------|
| Кнопка «Редактировать» | click | `editing = true`; появляются drag-handle + action-колонка; кнопка → «Завершить редактирование» | default / hover / active(navy) / disabled(строк 0 → скрыта) |
| Кнопка «Завершить редактирование» | click | `editing = false`; строки чистые | default / hover |
| drag-handle (`pi pi-bars`) | drag&drop | reorder → `PATCH …/reorder` (sort_order) | видна только в edit + drag-таб; hover меняет цвет `$surface-400`→`$surface-500` |
| кебаб `pi-ellipsis-v` | click | Popover: Редактировать / Удалить(danger) | виден только в edit |
| Пункт «Удалить» | click | ConfirmDialog → `DELETE …/:id` | danger-текст |
| тумблер «Активна» | toggle | `PATCH …/:id {is_active}` | виден всегда (не за режимом) |

- **loading:** `Skeleton`-строки (как сейчас в standalone).
- **empty:** кнопка «Редактировать» скрыта (нет строк); показ empty-state страницы.
- **error:** `Toast` из standalone-страницы (не в embedded — за `v-if="!embedded"`, дубли уже
  устранены).

### Interactions (endpoints — уже существуют у standalone-страниц)

| Действие | Endpoint |
|----------|----------|
| Реордер (drag) | `PATCH /api/admin/{dir}/reorder` (или per-item `sort_order`) — **см. ОВ-3** |
| Редактировать | `PATCH /api/admin/{dir}/:id` |
| Удалить | `DELETE /api/admin/{dir}/:id` |
| Toggle активна | `PATCH /api/admin/{dir}/:id` `{is_active}` |

### i18n

```json
{
  "ru": {
    "settings.directories.finishEditing": "Завершить редактирование"
  },
  "en": {
    "settings.directories.finishEditing": "Finish editing"
  }
}
```
(`common.edit` = «Редактировать» уже есть.)

### Токены

- Кнопка active (edit-режим): navy-outline — `severity` primary + `outlined`, или класс с
  `border-color: var(--p-primary-color)` + `color: var(--p-primary-color)`.
- drag-handle: `color: var(--p-surface-400)`, hover `var(--p-surface-500)`.
- кебаб-пункт danger: `var(--p-red-500)` / dark `var(--p-red-400)`.

### Обе темы

- Кнопка active navy: light `#172747` (`--p-primary-color`), dark `#4C7DF0` — из токена, инверт
  автоматом.
- Действия/drag — muted surface-шкала, инверт-безопасна (`var(--p-surface-*)`).

---

## Гэп 3 — Оргструктура: вид «Схема» = настоящий орг-чарт с раскрытием

### Целевой вид (мокап `access-section.jsx` `A_ChartNode` L143–161, toolbar L267–279)

**Вкладки:** Отделы / Роли и права / Видимость (у нас есть). Внутри **Отделы**:
- **toolbar:** поиск + тумблер вида **«Дерево | Схема»** (segmented, `pi-sitemap` / `pi-share-alt`)
  + кнопка **«Редактировать»** (edit-mode toggle, как гэп 2) + **«+ Добавить отдел»**.
- **вид «Схема» = top-down орг-чарт карточками:**
  - карточка отдела (width 210): **название** (13.5/600) + **руководитель** (12, muted) +
    **бейдж-счётчик** сотрудников (`pi-users` + N) с **шевроном** (`pi-chevron-down/up`).
  - **соединительные линии:** от родителя вниз вертикальная линия (1px, `--c-border2`); при 2+
    детях — горизонтальная перекладина сверху над рядом детей; к каждому ребёнку — своя
    вертикальная линия сверху по центру (L154–159).
  - **иерархия:** Руководство → (Отдел продаж, Финансы, Юридический) → подотделы (Группа B2B под
    Отдел продаж; Бухгалтерия под Финансы). Дети — горизонтальный ряд по центру под родителем.
  - **КЛИК по карточке разворачивает список сотрудников ПРЯМО В карточке** (L150–152):
    аватар (24px, градиент по роли) + имя, в столбик, под разделителем. Повторный клик —
    сворачивает. Бордер карточки при раскрытии → navy (`--mg-primary-900`).

### Текущее состояние (`OrgChartView.vue` + `OrgChartNode.vue`)

- Вид «Схема» есть (тумблер Дерево/Схема в `DepartmentsTab` L15–32 — ✔), но `OrgChartNode` — это
  **вертикальный вложенный список с отступом** (`marginLeft: depth*32px`, дети в столбик с
  `border-left` — L2, L24–32, `.org-node__children` L102–109). Это НЕ top-down орг-чарт: нет
  горизонтальной раскладки детей, нет центрирующих connector-линий, нет перекладины.
- **Клик по карточке → `openEdit(n.data)`** (`DepartmentsTab` L146), т.е. открывает side-panel
  редактирования. **НЕТ inline-раскрытия списка сотрудников** — карточка не показывает участников
  вообще (только `members_count`).
- В toolbar **НЕТ кнопки «Редактировать»** (edit-mode) — только поиск + тумблер + «+ Добавить
  отдел».

**Расхождения (3):** (a) раскладка — вертикальный список вместо top-down чарта с линиями;
(b) клик открывает edit вместо inline-раскрытия сотрудников; (c) нет edit-mode toggle.

### Дельта

1. **Переписать `OrgChartNode.vue` в top-down раскладку** (эталон `A_ChartNode`):
   - контейнер узла: `flex-direction: column; align-items: center`.
   - карточка: `width: 210px`, центрированный текст; бордер → `var(--p-primary-color)` при
     раскрытии, иначе `var(--p-surface-300)`.
   - бейдж-счётчик: плашка-пилюля `var(--p-primary-50)` фон, navy-текст, `pi-users` + N + шеврон.
   - **дети:** после карточки — вертикальный connector (1px, `$space-5` высота, `--p-surface-300`);
     ряд детей `display:flex; gap: $space-6`; при >1 ребёнке — верхняя перекладина
     (`border-top`); каждый ребёнок в обёртке с абсолютной вертикальной линией сверху по центру.
   - рекурсивный `<OrgChartNode>` для детей — как сейчас, но горизонтально.
2. **Inline-раскрытие сотрудников** (эталон L150–152):
   - `const open = ref(false)`; клик по карточке (или по бейджу) — `open = !open` (НЕ emit
     `select`/openEdit).
   - при `open`: под разделителем — список `node.data.members` (аватар `EntityAvatar` 24px + имя).
   - **members нужны в узле.** Сейчас `DeptTreeNode.data` содержит `members_count`, но НЕ список
     `members`. **Backend-зависимость (ОВ-4):** либо дерево возвращает `members[]` на узлах, либо
     lazy-подгрузка по клику (`GET /api/admin/departments/:id/members` — уже есть, используется в
     `deptDetail`). Рекомендация — **lazy on expand** (как `deptDetail`): при первом раскрытии
     дёрнуть members, показать `Skeleton` пока грузится. Это не требует backend-правок (endpoint
     есть). Кэшировать в узле.
3. **Клик больше не открывает edit.** `DepartmentsTab` L144–148: `@select` из OrgChart больше НЕ
   вызывает `openEdit`. Редактирование из схемы — только в **edit-mode** (см. п.4): в режиме на
   карточке появляется мелкая иконка-карандаш (как в дереве, `A_TreeNode` L133–136), она и
   открывает side-panel.
4. **Edit-mode toggle в toolbar** (эталон L276): добавить кнопку «Редактировать» ↔ «Завершить
   редактирование» рядом с «+ Добавить отдел» — тем же паттерном, что гэп 2. В режиме:
   - в **дереве** (`DepartmentTree`): action-иконки (карандаш/корзина) показываются только в
     edit-mode (сейчас — по hover всегда, L129–139: `opacity:0` → hover `opacity:1`; заменить
     на `v-if="editMode"` + hover внутри режима).
   - в **схеме**: на карточке в edit-mode — иконка-карандаш (open side-panel), клик по остальной
     карточке всё равно раскрывает сотрудников.
   Прокинуть `editMode` в `DepartmentTree` и `OrgChartView`/`OrgChartNode` пропом.

### Компоненты / состояния

| Элемент | Действие | Результат | Состояния |
|---------|----------|-----------|-----------|
| Карточка отдела (схема) | click | toggle раскрытие → inline-список сотрудников | свёрнут(бордер surface-300) / развёрнут(бордер primary) / hover / focus-visible(outline primary) |
| Бейдж-счётчик | click | то же (раскрытие) | шеврон down↔up |
| Список сотрудников | — | аватар 24 + имя, столбик | loading(Skeleton) / loaded / empty(«Нет участников») |
| Тумблер «Дерево\|Схема» | click | смена вида | active-пилюля navy |
| Кнопка «Редактировать» | click | edit-mode on → карандаши/корзины видны | default / active(navy) |
| Карандаш на карточке (edit) | click | open side-panel редактирования отдела | виден только в edit-mode |

- **loading:** дерево/схема — `Skeleton` (уже есть, `DepartmentsTab` L140–143); members при
  раскрытии — `Skeleton` строк.
- **empty:** отделов нет → empty-state (уже есть в `DepartmentTree`); отдел без участников →
  «Нет участников» (`accessControl.departments.noMembers`).
- **error:** `Toast` / inline error (как в `deptDetail`).

### Interactions (endpoints)

| Действие | Endpoint | Статус |
|----------|----------|--------|
| Раскрыть сотрудников | `GET /api/admin/departments/:id/members` | **существует** (used by deptDetail) |
| Редактировать отдел | side-panel → `PATCH /api/admin/departments/:id` | существует |
| Дерево/схема | клиентский тумблер | — |

### i18n (уже есть в `accessControl.departments.*`)

```json
{
  "ru": {
    "accessControl.departments.finishEditing": "Завершить редактирование"
  },
  "en": {
    "accessControl.departments.finishEditing": "Finish editing"
  }
}
```
(`viewTree`/`viewChart`/`noMembers`/`edit`/`addDepartment` — уже есть.)

### Токены

- Карточка: `var(--p-surface-card)` фон, бордер `var(--p-surface-300)` → раскрыта
  `var(--p-primary-color)`; `$radius-md`; `$shadow-sm`; hover `$shadow-card-hover`.
- Connector-линии: `1px`/`2px` `var(--p-surface-300)` (в мокапе `--c-border2`).
- Бейдж: `var(--p-primary-50)` фон / `var(--p-primary-color)` текст; dark `var(--p-primary-950)`.
- Аватар — `EntityAvatar` (reuse, градиент по роли уже в компоненте).
- Разделитель в карточке: `var(--p-surface-200)`.

### Обе темы

- Карточка: surface-card theme-reactive → базовое правило читается в dark (navy). Бордер
  раскрытия `--p-primary-color` инверт-safe.
- Connector-линии `--p-surface-300` — инвертированная шкала, читаемы в обеих.
- Бейдж dark → `--p-primary-950` фон (как active-паттерн сайдбара).

> **Важно:** connector-линии рисуются через `border`/`div`-полоски на `--p-surface-300`, НЕ через
> SVG. Никаких новых цветов. Горизонтальный overflow схемы — `overflow-x:auto` на контейнере
> (`DepartmentsTab` L298–300 — уже есть скролл-обёртка).

---

## Гэп 4 — Настройки Воронки продаж: сверка Form-вида с мокапом

### Целевой вид (мокап `pipeline.html`)

Отдельная страница `/settings/pipeline` (link-out из сайдбара). Структура:
- **шапка:** иконка + «Воронка продаж» + подзаголовок «Этапы и автоматизации · {воронка}» +
  тумблер **«Форма | Канвас»** (`pi-list` / `pi-sitemap`) + theme-toggle.
- **левый сайдбар «ВОРОНКИ»** (width 250): список воронок (иконка `pi-filter` + имя +
  счётчик этапов) + внизу «+ Новая воронка».
- **контент «Этапы воронки «{имя}»»:** карточка со строками этапов:
  - drag-handle (`pi-bars`) + цветная точка (10×10, `borderRadius:3`) + название + чипы
    автоматизаций (`pi-bolt/envelope/telegram/file` + текст) + бейджи **Успех**(success) /
    **Отказ**(danger) / **Скрыт**(muted + `pi-eye-slash`) + ссылка **«Автоматизация»** справа
    (по hover) + иконки карандаш/корзина по hover.
  - **вложенные подэтапы** — с отступом (`padding-left: 40px`), напр. «Согласование цены» /
    «Юридическая проверка» под «Переговоры».
  - кнопка **«+ Этап»** справа сверху.
- **секция «Автоматизации воронки»** ниже: строки (плашка-иконка + название + описание триггера
  «При входе на этап «X»» + тумблер вкл/выкл) + кнопка «Добавить».

### Текущее состояние (`PipelineSettingsPage/`)

**Почти всё уже реализовано** — это НЕ greenfield:
- ✔ тумблер **Форма/Канвас** — `SelectButton` `viewModeOptions` (`index.vue` L28–34, L54–62,
  L213–216). Канвас-режим (vue-flow) существует и сохраняется как второй вид.
- ✔ **этапы** — `StageEditorList` + `StageEditorItem` + `StageSubstageItem`: drag-handle,
  цветная точка, чипы автоматизаций, бейджи, подэтапы с отступом, ссылка «Автоматизация»,
  edit/delete по hover, «+ Этап» (`StageEditorList.vue`).
- ✔ **автоматизации воронки** — `AutomationListPanel` (строки + тумблер + «Добавить»).
- ✔ **список воронок** + счётчики этапов + «Новая воронка» — `PipelineList`.
- ✔ шапка `PageHeader` + подзаголовок.

**Единственное структурное расхождение с мокапом:** список воронок у нас — **верхняя карточка**
(`PipelineList`, `index.vue` L39–51), а в мокапе — **левый сайдбар-рельс 250px** (`pipeline.html`
L166–176). Плюс мелкие визуальные сверки.

**Расхождение:** раскладка «рельс воронок слева» vs «карточка воронок сверху». Юзер: «мы его
вообще не трогали» — по факту Form-вид уже есть, но layout иной и визуал не выверен по мокапу.

### Дельта (визуальная сверка + опциональный re-layout)

Поскольку функционал полный, дельта = **визуальная сверка Form-вида с `pipeline.html`** +
**решение по layout** (рельс vs карточка):

1. **Layout воронок — рельс слева (рекомендуется, ОВ-5).** Перевести Form-вид в двухколоночную
   раскладку: слева sticky-рельс 250px («ВОРОНКИ» + список + «Новая воронка» внизу), справа —
   контент этапов + автоматизаций (`max-width` ~780 как в мокапе L180). Сейчас всё в один столбец
   `max-width: 900px` (`index.vue` L556). Канвас-режим НЕ трогаем (у него своя compact-chrome).
   - **Альтернатива (минимум работы):** оставить `PipelineList` верхней карточкой, только
     подтянуть визуал. Реляйаут в рельс — если PO подтвердит (ОВ-5).
2. **Строка этапа — сверка `StageEditorItem` с мокапом `StageRow` (L107–126):**
   - цветная точка `10×10 borderRadius:3` (не круг) — сверить.
   - чипы автоматизаций: фон `color-mix(primary 10%, card)`, navy-текст, иконка + текст — сверить
     токены (у нас должно быть `--p-primary-50`/`--p-primary-color`).
   - бейджи: Успех `success` / Отказ `danger` / Скрыт (muted + `pi-eye-slash`) — сверить severity.
   - ссылка «Автоматизация» справа (`pi-bolt` + текст, navy, появляется по hover) — сверить.
   - карандаш/корзина по hover (`opacity` 0→1) — сверить.
3. **Подэтап — `StageSubstageItem`** отступ `padding-left: 40px` ($space-8+ish), без ссылки
   «Автоматизация» и без чипов (в мокапе подэтап — только drag+точка+имя, L110 `sub`). Сверить.
4. **Секция «Автоматизации воронки» — `AutomationListPanel`** vs мокап L191–202: плашка-иконка
   32×32 (`--p-primary-50`), название + «При входе на этап «X»» + тумблер + «Добавить». Сверить.
5. **Диалоги** — `CreateStageDialog` (цвет+тип+родитель+«скрыт по умолчанию») и
   `AutomationWizardDialog` (триггер + сетка действий + параметры) уже богаче мокапа. Не трогаем.

### Компоненты / состояния (сверочные — всё существует)

| Элемент | Компонент | Сверка |
|---------|-----------|--------|
| Тумблер Форма/Канвас | `SelectButton` | ✔ есть — active-пилюля navy |
| Рельс воронок | `PipelineList` (→ рельс?) | **ОВ-5** layout |
| Строка этапа | `StageEditorItem` | визуал-сверка токенов |
| Подэтап | `StageSubstageItem` | отступ 40px |
| Чип автоматизации | внутри `StageEditorItem` | `--p-primary-*` |
| Автоматизации воронки | `AutomationListPanel` | плашка+тумблер |

- **loading:** `Skeleton` этапов (`StageEditorList` L24–34 — есть).
- **empty:** нет этапов → empty-state (`StageEditorList` L37–51 — есть).
- **error:** `Toast` (index.vue — есть).

### i18n

Уже полностью покрыто (`sales.pipelineEditor.*`, `sales.stageEditor.*`, `automation.canvas.*`,
`automation.toast.*`). Новых ключей не требуется, кроме заголовка рельса, если реляйаут:
```json
{
  "ru": { "sales.pipelineEditor.railTitle": "Воронки" },
  "en": { "sales.pipelineEditor.railTitle": "Pipelines" }
}
```

### Токены

- Рельс: `var(--p-surface-card)` фон, `borderInlineEnd: 1px var(--p-surface-200)`, `width:250px`.
- Активная воронка в рельсе: `--p-primary-50` фон / navy-текст / `inset 3px` (как sidebar-паттерн);
  dark `--p-primary-950` / `--p-primary-200`.
- Чипы/бейджи/точки — существующие stage-токены (`$stage-color-*`, `--p-primary-*`, status-теги).

### Обе темы

- Уже покрыто navy-свипом (этапы 1–3 HANDOFF): stage-палитра, deal/kanban токены, status-теги.
  Рельс (если делаем) — surface-card + primary-active, инверт-safe.

---

## Backend-зависимости (сводно)

| Гэп | Зависимость | Тип |
|-----|-------------|-----|
| 1 | нет | **чисто-фронт** (сайдбар-реорг + маршрутизация) |
| 2 | reorder-endpoint для sort_order справочников — **проверить наличие** у каждого dir | фронт, если endpoints есть (ОВ-3); иначе `backend-architect` |
| 3 | список `members[]` на узлах дерева ЛИБО lazy `GET /departments/:id/members` (существует) | **чисто-фронт** при lazy-подходе; если хотим eager — Org-домен добавляет members в tree-resource |
| 4 | нет (весь функционал есть) | **чисто-фронт** (визуал-сверка + опц. реляйаут) |

**Итого:** гэпы 1 и 4 — чисто-фронт. Гэп 3 — чисто-фронт при lazy-раскрытии (endpoint есть).
Гэп 2 — чисто-фронт при наличии reorder-endpoint'ов у справочников (нужно проверить —
`backend-architect`, ОВ-3).

---

## Открытые вопросы

1. **[ОВ-1] Маршрутизация directory-ключей.** После сворачивания сайдбара — `?section=countries`
   и т.д. должны открывать `SectionDirectories` с активным табом `countries`. Требуется правка
   `index.vue`: directory/doc-ключ → всегда `SectionDirectories`, `activeTab = activeSection`.
   Подтвердить, что deep-link'и на конкретные табы сохраняем (да — рекомендация).
2. **[ОВ-2] Группа «Продажи» (МК).** Мокап `settings.html` её не содержит. Оставляем
   `motivation-builder` (реальная фича, добавлена после мокапа) — подтвердить, что группа
   «Продажи» остаётся сверх мокапа.
3. **[ОВ-3 / backend] Reorder справочников.** У каких dir есть reorder-endpoint для drag&drop?
   Если нет — drag-handle в edit-mode показываем только там, где reorder поддержан; для остальных
   edit-mode = только action-колонка (кебаб), без drag. `backend-architect` — инвентаризация.
4. **[ОВ-4] Members в орг-чарте.** Lazy-подгрузка по клику (endpoint есть, рекомендация) ИЛИ eager
   `members[]` в tree-resource (Org-домен)? Рекомендация — lazy (0 backend-правок).
5. **[ОВ-5] Layout воронок.** Рельс-250 слева (как мокап, рекомендуется) ИЛИ оставить
   `PipelineList` верхней карточкой (минимум работы)? Реляйаут — заметная правка `index.vue`.
   PO-решение.
6. **[ОВ-6] Состав таб-стрипа «Справочники».** Мокап `DirectoriesTab` = 11 табов (совпадает с
   нашими 11). Текущий `SectionDirectories` — те же 11. ✔ совпадает, вопросов нет — фиксирую как
   проверенное.
