# Имплемент-план: редизайн карточки контакта и компании (EntityCard v2)

> Эталон: `design-handoff/redesign/EntityCard-spec.md` + `entity-card.html`.
> Источник истины по токенам: `front/src/theme/scss/foundation/_colors.scss` + `_spacing.scss` + `_radius.scss`.
> Бренд-инвариант: `$brand-header-bg` = `#172747` (navy-хедер не меняется ни в какой теме).
>
> **Принцип dark-темы в этом плане:** используй идиому `.app-dark &` (НЕ `:global(.app-dark) &`).
> Инверсия surface-шкалы в dark: поверхности = `var(--p-surface-50/100/200)`, НЕ 800/900/0.
> Лучший способ — реактивный семантический токен (`$surface-card`, `var(--p-surface-*)`) без
> dark-оверрайда вообще.

---

## Дельты current → target (сводка)

| Область | Current | Target |
|---|---|---|
| `ContactRightRail` | Используется отдельным файлом, НЕ смонтирован в `index.vue` (grep подтвердил: 0 вхождений) | **Удалить файл** — данные уже в хедере |
| `CompanyRightRail` | Аналогично — не импортируется в `index.vue` | **Удалить файл** — данные уже в хедере |
| `EntityInfoHeader` — мета-строка | Отсутствует: Источник, Создан, Изменён; есть только Автор, Должность/WorksWith | Добавить: `source_label` · `created_at` · `updated_at`; переставить должность первой строкой (для контакта) |
| `EntityInfoHeader` — управление | Кнопки только `pi-arrow-left` + `pi-ellipsis-v`; корректно | ОК — ничего не менять |
| `EntityInfoHeader` — бордер кнопок | Нет явного бордера у `.entity-header__btn-icon` | Добавить `border: 1px solid rgba(255,255,255,0.22)` |
| `EntityInfoHeader` — иконка pi-tag перед тегами | Отсутствует | Добавить перед тегами-чипами |
| `ContactPage/index.vue` — таб «Журнал» | Есть (`value="log"`, `EntityLogTab`) | **Убрать таб** — логирование слить в «Активность» |
| `CompanyPage/index.vue` — таб «Журнал» | Есть (`value="log"`, `EntityLogTab`) | **Убрать таб** |
| `CompanyPage/index.vue` — таб «Платежи» | Есть (плейсхолдер) | Оставить (M9) — не трогать |
| `EntityActivitiesTab` — фильтр-чипы | Отсутствует | Добавить три пилюли «Все / События / Изменения» |
| `EntityActivitiesTab` — отображение лог-строк | `fieldChanges` рендерится компактно ✓, но без кружка 22px | Довести до спеки §5 |
| `EntityComposer` — режим-переключатель | `Select`-дропдаун | Заменить на **два Button-режима** вертикально (Заметка / Задача), активный = navy/белый |
| `EntityComposer` — компоновка | Вертикальный стек | Горизонт: левая колонка 110px (кнопки режима) + правая (рамка + textarea + «Добавить» справа) |
| `EntityKpiStrip` — визуал | Inline strip без пилюль | Перевести на pill-чипы (borderRadius 999px, padding 6px 13px, тинт-фон) |
| `ContactPage/overview` — «Сейчас» панель | `InfoPanel` с `EntityNowStrip` | **Убрать** — дублирует KPI-полосу (по спеке §4 в Обзоре её нет) |
| `ContactPage/overview` — порядок панелей | Каналы связи · Компании · Связи · Сделки · Маркетинг · Заметки · Хронология · Доп. поля | По спеке §4: Каналы · Компании · Связи · Участвует в сделках · Заметки · История событий |
| `ContactPage/overview` — `ContactMarketingPanel` | Отдельная нестандартная панель | Убрать как самостоятельный блок; данные канала привлечения — в хедере (источник) |
| `CompanyPage/overview` — двухколоночная сетка | `col-12 col-xl-6` — две колонки | **Одна колонка** (`col-12`): Реквизиты · Сотрудники (обзор) · Сделки в работе · Холдинг · История событий |
| `CompanyPage/overview` — `EntityNowStrip` | Отдельная `InfoPanel` | **Убрать** — данные уже в KPI-полосе |
| `CompanyPage/overview` — `MiniPipelinePanel` | Отдельная панель | Заменить на `InfoPanel` «Сделки в работе» (§4 компания) с мини-таблицей в 3 колонки |
| `CompanyPage/overview` — `CompanyMarketingPanel` | Отдельная нестандартная панель | Убрать — маркетинг-канал = Источник в хедере |
| `CompanyDealsTab` — заголовок | Нет `TabHead` с кнопкой «Создать сделку» | Добавить `TabHead` с `pi-plus` кнопкой |
| `CompanyDealsTab` — колонки | Название · Этап · Сумма · Статус · Ответственный · Иконка | По спеке §6: Название · Этап · Сумма · Ответственный · Создана |
| `CompanyEmployeesTab` — строки | Плоские, без раскрытия | Добавить раскрытие строки → контакты/чипы (§7) |
| `ContactDealsPanel` — вкладка «Сделки» | Используется и в обзоре, и на вкладке | Вкладка «Сделки» — выделить `ContactDealsTab` с `TabHead` + «Добавить в сделку» |
| Диалог «Привязать компанию» | Простой `Dialog` 480px без тумблера | Добавить тумблер «Основная компания» |
| Вкладка «Файлы» (обе страницы) | Плейсхолдер | Реализовать двухпанельный `FilesTab` (§9) |
| Вкладка «Холдинг» (компания) | `HoldingTree` с кнопкой «Привязать» | Добавить `TabHead` + кнопка «Привязать компанию» |
| `EntityLogTab` | Отдельный компонент с метрикой | После слияния — встроить лог-строки в `EntityActivitiesTab` (filter=«Изменения») |

---

## Часть 1. ОБЩИЕ компоненты (`front/src/components/crm/entity/`)

### 1.1 `EntityInfoHeader.vue` — расширение мета-строки

**Файл:** `front/src/components/crm/entity/EntityInfoHeader.vue`

**Что делать:**

1. Добавить props:
   ```
   sourceLabel?: string | null        // Источник (directoriesStore.getSourceLabel)
   createdAt?: string | null          // Создан (форматировать DD.MM.YYYY)
   updatedAt?: string | null          // Изменён
   ```

2. В `entity-header__meta-row` расширить порядок отображения (контакт):
   ```
   [должность — первой строкой выше meta-row, 13px rgba(255,255,255,0.6)]
   [Автор: xxx] [Компания: xxx (для контакта = primary company из companies[])] [Источник: xxx] [Создан: DD.MM] [Изменён: DD.MM]
   ```
   Для компании:
   ```
   [Автор: xxx] [Ответственный: xxx] [Источник: xxx] [Создан: DD.MM] [Изменён: DD.MM]
   ```
   Мета-лейбл: `rgba(255,255,255,0.4)`, значение: `rgba(255,255,255,0.8)` font-weight 500.
   Gap 18px между группами, font-size 12px.

3. В `entity-header__tags-row` добавить иконку `pi pi-tag` (10px) перед чипами:
   ```html
   <i class="pi pi-tag entity-header__tags-icon" />
   ```
   SCSS: `font-size: 10px; color: rgba(255,255,255,0.4);`

4. В `.entity-header__btn-icon` добавить бордер:
   ```scss
   border: 1px solid rgba(255,255,255,0.22); // brand header overlay — static border on navy
   ```

5. `entity-header__title` — увеличить до 22px (текущее `$font-size-lg` может быть меньше):
   ```scss
   font-size: 22px; // brand invariant — entity card header title, не токен
   ```
   Проверить значение `$font-size-lg` в `_typography.scss`; если оно >= 22px — использовать токен.

**i18n-ключи (новые):**
```
crm.entity.source: { ru: "Источник", en: "Source" }
crm.entity.createdAt: { ru: "Создан", en: "Created" }
crm.entity.updatedAt: { ru: "Изменён", en: "Updated" }
```

**Dark:** хедер инвариантен (`$brand-header-bg = #172747`) — dark-оверрайды не нужны. Новые props отображаются теми же `rgba(255,255,255,*)` константами (stylelint-disable-next-line как уже сделано в компоненте).

---

### 1.2 `EntityKpiStrip.vue` — pill-чипы

**Файл:** `front/src/components/crm/entity/EntityKpiStrip.vue`

**Что делать:** Изменить визуальный стиль каждого `.entity-kpi-strip__item` с inline-строки на пилюлю:

```scss
.entity-kpi-strip__item {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  padding: $space-2 $space-3;    // 6px 13px (space-2=8px? проверить; используй буквально 6px 13px если нет точного токена)
  border-radius: 999px;
  background: /* тинт по accent — см. ниже */;
  color: /* текст по accent */;
  font-size: $font-size-sm;      // 13px
  white-space: nowrap;
  cursor: default;
  // hover: inset box-shadow 1px accent33
}
```

Тинт-маппинг (расширить уже существующую логику `accentClass`/`accentIconClass` на фон + текст чипа):

| accent | bg | text |
|---|---|---|
| info | `$blue-100` → `var(--p-blue-100)` | `var(--p-blue-700)` |
| brand | `$primary-100` | `$primary-900` |
| success | `$green-100` | `$green-900` |
| teal | `$teal-100` | `$teal-700` |
| amber | `$orange-100` | `$orange-900` |
| danger | `$red-100` | `$red-700` |
| neutral | `var(--p-surface-100)` | `$surface-600` |

**Dark:** используй `var(--p-*)` семантические токены — они уже инвертированы PrimeVue. Для neutral: `.app-dark &` → `background: var(--p-surface-200); color: var(--p-surface-300)`.

Удалить текущий `.entity-kpi-strip__divider` — у пилюль разделитель не нужен.

---

### 1.3 `InfoPanel.vue` — «+»-кнопка в шапке

**Файл:** `front/src/components/crm/entity/InfoPanel.vue`

Текущий слот `#header-action` уже есть — это верно. **Добавить** поддержку стандартного «+ Действие» в виде prop-shortcut:

```
addLabel?: string     // если передан — рендерится link-кнопка 12px/600 цвет $primary-color
addIcon?: string      // по умолчанию 'pi-plus'
```

Это устраняет необходимость каждой странице передавать `<Button text ...>` через слот — вместо этого:

```html
<InfoPanel add-label="Создать канал" @add="openCreateChannel" />
```

Если текущий `#header-action`-подход уже используется повсюду — **не трогать**, только добавить SCSS для корректного hover (12px/600, `$primary-color`, при hover — фон `var(--p-primary-100)`).

**Dark:** `.app-dark &` для hover-фона «+»-кнопки → `var(--p-primary-900)`.

---

### 1.4 `EntityActivitiesTab.vue` — фильтр + лог-строки

**Файл:** `front/src/components/crm/entity/EntityActivitiesTab.vue`

**1. Фильтр-чипы** (добавить над лентой):

```html
<div class="entity-activities__filter-chips">
  <button ... :class="{'--active': filter === 'all'}" @click="filter='all'">Все</button>
  <button ... :class="{'--active': filter === 'events'}" @click="filter='events'">События</button>
  <button ... :class="{'--active': filter === 'changes'}" @click="filter='changes'">Изменения</button>
</div>
```

Стиль: пилюля, padding `4px 12px`, font-size `$font-size-sm` (13px). Активная — `background: $primary-100; color: $primary-900`. Неактивная — `background: transparent; color: $surface-600`.

Dark: активная → `.app-dark &` = `background: var(--p-primary-900); color: var(--p-primary-200)`.

Логика фильтрации: `filter === 'events'` — скрывает `fieldChanges`-элементы; `filter === 'changes'` — скрывает `activity`-элементы. `filter === 'all'` — всё.

**2. Лог-строка (fieldChanges) — доработать визуал:**

Текущий `.entity-activities__field-change` — просто строка с иконкой. По спеке §5:
- Кружок 22×22, `border-radius: 50%`, `background: var(--p-surface-100)`, внутри `pi-pencil` 10px, цвет `$primary-color`.
- Текст: `**Кто** изменил поле «X»: старое → новое`, font-size 12px `$surface-600`.

```scss
.entity-activities__fc-circle {
  width: 22px;
  height: 22px;
  border-radius: $radius-circle;
  background: var(--p-surface-100);
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;

  .app-dark & {
    background: var(--p-surface-200);  // инвертированная шкала — НЕ surface-800
  }

  i {
    font-size: 10px;
    color: var(--p-primary-color);
  }
}
```

**3. После слияния журнала** — `EntityActivitiesTab` дополнительно подгружает лог-строки через `useEntityFeed`. Если `useEntityFeed` уже объединяет activities + field_changes — только добавить фильтр-чипы. Если лог отдельный endpoint — требуется backend (см. §backend ниже).

**i18n-ключи:**
```
crm.entity.feed.filterAll: { ru: "Все", en: "All" }
crm.entity.feed.filterEvents: { ru: "События", en: "Events" }
crm.entity.feed.filterChanges: { ru: "Изменения", en: "Changes" }
```

---

### 1.5 `EntityComposer.vue` — перекомпоновка

**Файл:** `front/src/components/crm/entity/EntityComposer.vue`

**Текущее:** вертикальный стек, тип переключается через `Select`.

**Target (§5 спеки):**

```
[composer-wrap]
  [mode-col — 110px, flex-shrink:0]
    [btn "Заметка" — pi-comment, full-width, 8px 12px]
    [btn "Задача" — pi-check-square, full-width, 8px 12px]
  [content-col — flex:1]
    [content-frame — border $radius-md, min-height 78px, padding 8px]
      [textarea — прозрачная, без своей рамки] + [кнопка "Добавить" справа по вертикали по center]
```

```scss
.entity-composer {
  display: flex;
  gap: $space-3;
  background: var(--p-surface-50);   // --c-hover аналог
  padding: $space-3 $space-4;        // 12px 16px

  .app-dark & {
    background: var(--p-surface-100);  // инвертированная шкала
  }
}

.entity-composer__mode-col {
  width: 110px;
  flex-shrink: 0;
  display: flex;
  flex-direction: column;
  gap: $space-2;
}

.entity-composer__mode-btn {
  width: 100%;
  padding: $space-2 $space-3;   // 8px 12px
  border-radius: $radius-md;
  border: 1px solid var(--p-surface-300);
  background: transparent;
  cursor: pointer;
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: $surface-600;
  display: flex;
  align-items: center;
  gap: $space-2;
  transition: background var(--app-transition-fast), color var(--app-transition-fast);

  &--active {
    background: $brand-header-bg;  // navy, бренд-инвариант
    color: $sidebar-text-active;   // #ffffff
    border-color: $brand-header-bg;
  }

  .app-dark &:not(&--active) {
    color: var(--p-surface-300);
    border-color: var(--p-surface-600);
  }
}

.entity-composer__content-col {
  flex: 1;
  display: flex;
  flex-direction: column;
}

.entity-composer__frame {
  flex: 1;
  border: 1px solid var(--p-surface-300);
  border-radius: $radius-md;
  padding: $space-2;
  min-height: 78px;
  display: flex;
  align-items: flex-end;
  gap: $space-2;

  .app-dark & {
    border-color: var(--p-surface-600);
  }
}

.entity-composer__textarea-wrap {
  flex: 1;
  min-width: 0;
}
```

Кнопка «Добавить»: `<Button label="Добавить" />` — primary, размер small, align-self: flex-end.

Режим «Задача»: внутри `.entity-composer__frame` — верхняя строка с тремя элементами (`DatePicker` + `Select(responsible)` + `Select(type)`, display:flex, gap:$space-2) + нижняя — textarea. «Добавить» по-прежнему справа.

**Удалить** старый `.entity-composer__header` с `Select`.

---

### 1.6 `EntityLogTab.vue` — судьба компонента

После слияния «Журнал → Активность» (`EntityLogTab`) как отдельная вкладка **удаляется** с обеих страниц. Файл компонента **НЕ удалять** — он может понадобиться позже или в других контекстах. Просто убрать его импорт и использование из обеих `index.vue`.

Вместо этого: лог-строки (`field_changes`) попадают в ленту через `useEntityFeed` (если endpoint объединяет — ничего не делать; если нет — см. backend §B-1).

---

## Часть 2. КОНТАКТ-специфика (`front/src/pages/ContactPage/`)

### 2.1 `ContactPage/index.vue` — глобальные правки

**Убрать:**
- `Tab value="log"` + `TabPanel value="log"` (и `EntityLogTab` импорт)
- `contactLog` и `contactMetrics` (`useEntityLog` остаётся — нужен для `EntityMiniTimeline` → «История событий» в Обзоре)
- `tabOptions` — убрать `log`-элемент
- `EntityNowStrip` + `InfoPanel «Сейчас»` из Overview

**Добавить в `<EntityInfoHeader>`:**
```html
:source-label="directoriesStore.getSourceLabel(contact.source)"
:created-at="contact.created_at"
:updated-at="contact.updated_at"
:company-name="primaryCompanyName"  // computed: первая компания с is_primary=true
```

**KPI-чипы контакта по спеке §2:**

| Ключ | icon | label | accent |
|---|---|---|---|
| deals | `pi-briefcase` | `contact.kpi.deals` | info |
| sum | `pi-wallet` | `contact.kpi.sum` | brand |
| open_tasks | `pi-check-square` | `contact.kpi.openTasks` | amber |
| companies | `pi-building` | `contact.kpi.companies` | teal |
| last_activity | `pi-clock` | `contact.kpi.lastActivity` | success/warning/danger |

Добавить `sum` (пока `0 ₽` если нет данных — backend блокер §B-2).

**Порядок вкладок (TabList):** Обзор · Активность · Сделки · Файлы  
(убрать «Журнал»)

**Меню `pi-ellipsis-v` (по спеке §1 меню):**
```
Добавить заметку (pi-comment) → открывает вкладку «Активность» + фокус на composer
Добавить связь (pi-link) → activeTab = 'overview', прокрутка до панели «Связи»
Скопировать ссылку (pi-copy) → copyLink()
---
Удалить (pi-trash, danger)
```
Убрать из текущего меню: «Добавить задачу», «Позвонить», «Написать», «Добавить связь» (дублируется выше).

---

### 2.2 `ContactPage/overview` — перекомпоновка панелей

**Убрать:**
- `<InfoPanel «Сейчас»> с EntityNowStrip`
- `<ContactMarketingPanel>` (данные об источнике → хедер; если нужен inline-редактор канала — оставить в доп. полях)
- `<CustomFieldRenderer>` в `InfoPanel «Доп. поля»` — **оставить** (сворачивается по умолчанию)

**Итоговый порядок панелей (сверху вниз):**

1. Каналы связи (`pi-phone`, `+Создать канал`)
2. Компании (`pi-building`, `+Добавить в компанию`)
3. Связи (`pi-share-alt`, `+Добавить связь`)
4. Участвует в сделках (`pi-briefcase`, `+Добавить в сделку`)
5. Заметки (`pi-comment`, `+Добавить заметку` → открывает composer в активности)
6. История событий (`pi-history`) — переиспользует `EntityMiniTimeline` + `contactLog`

Обернуть всё в `.contact-page-v2__panels` (уже есть — карточка с бордером и тенью).

---

### 2.3 `ContactChannelsBlock.vue` — hover-иконки действий

**Файл:** `front/src/pages/ContactPage/components/ContactChannelsBlock.vue`

По спеке §4 «Каналы связи»: иконка-кружок 30×30 на hover становится navy с белой иконкой, подпись справа меняется на действие.

**Текущее:** есть `contact-channels__link-btn` (22px, без кружка-фона). Доработать:

```scss
.contact-channels__action-circle {
  width: 30px;
  height: 30px;
  border-radius: $radius-circle;
  background: $primary-100;    // фон плитки
  color: $primary-900;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  transition: background var(--app-transition-fast), color var(--app-transition-fast);

  i { font-size: $font-size-sm; }

  &:hover {
    background: $brand-header-bg;  // navy — бренд-инвариант, hover на канале
    color: $sidebar-text-active;   // белая иконка
  }

  .app-dark & {
    background: var(--p-primary-900);
    color: var(--p-primary-200);

    &:hover {
      background: $brand-header-bg;
      color: #ffffff;
    }
  }
}
```

Справа от значения: подпись (12px $surface-500) на hover меняется на action-label («Позвонить» / «Написать» / «Открыть чат»). Реализовать через `:hover` на parent + CSS visibility/opacity переключение между двумя span.

---

### 2.4 Новый `ContactDealsTab.vue` — вкладка «Сделки»

**Файл (новый):** `front/src/pages/ContactPage/components/ContactDealsTab.vue`

**Обоснование создания нового компонента:** текущий `ContactDealsPanel` — мини-виджет без заголовка для обзора. Вкладка «Сделки» требует полный `DataTable` с `TabHead`.

Состав:
```
[TabHead: «СДЕЛКИ» 12px/700 uppercase] [Кнопка «Добавить в сделку» pi-plus, primary]
[DataTable]
  Название (RouterLink, $primary-color) | Этап (Tag) | Сумма (700 $primary-900, справа) | Ответственный | Создана
  строки: hover --c-hover, border-bottom, ячейки 10px 14px, 13px
```

Переиспользует данные `deals` из `useContactPageData`. Кнопка «Добавить» → требует диалог (backend §B-3).

SCSS-утилита `TabHead` — либо сделать отдельный общий компонент (если нужен и компании), либо локальный `.contact-deals-tab__head`.

---

### 2.5 `ContactPage/files` — вкладка «Файлы»

**Файл:** `front/src/pages/ContactPage/components/ContactFilesTab.vue` (новый)

По спеке §9 — двухпанельный layout:
```
[FilesTab]
  [TabHead] [кнопка «Создать папку» pi-folder-plus secondary] [кнопка «Загрузить» pi-upload primary]
  [row g-0]
    [col — 46%, border-right 1px $surface-200]
      список папок: pi-folder/pi-folder-open 15px | название 13px | счётчик-пилюля | pi-chevron-right
    [col — 54%]
      список файлов: иконка по типу 16px | имя 13px/500 | размер·дата 11px | pi-download | pi-ellipsis-v
```

Backend: требуется API файлов (`GET /api/contacts/{id}/files`) — **backend блокер §B-4**.
До появления API — реализовать skeleton + empty-state.

---

### 2.6 Диалог «Привязать компанию» — доработка

**Файл:** `front/src/pages/ContactPage/index.vue` (inline `<Dialog>`)

Добавить `ToggleSwitch` (или `Checkbox`) «Основная компания» (по умолчанию false).

```html
<div class="contact-page-v2__dialog-field">
  <label class="contact-page-v2__dialog-label">{{ t('contact.page.companies.isPrimary') }}</label>
  <ToggleSwitch v-model="attachCompanyIsPrimary" />
</div>
```

Передать в API при submit (`is_primary: attachCompanyIsPrimary`).

**i18n-ключ:**
```
contact.page.companies.isPrimary: { ru: "Основная компания", en: "Primary company" }
```

---

## Часть 3. КОМПАНИЯ-специфика (`front/src/pages/CompanyPage/`)

### 3.1 `CompanyPage/index.vue` — глобальные правки

**Убрать:**
- `Tab value="log"` + `TabPanel value="log"` + `EntityLogTab` импорт
- `companyLog` / `companyMetrics` (лог-composable оставить для `EntityMiniTimeline`)
- `tabOptions` — убрать `log`
- `EntityNowStrip` + `InfoPanel «Сейчас»` из Overview

**Добавить в `<EntityInfoHeader>`:**
```html
:source-label="directoriesStore.getSourceLabel(company.source)"
:created-at="company.created_at"
:updated-at="company.updated_at"
```

**Добавить `sourceLabel` в props для `EntityInfoHeader`** (см. §1.1 выше).

**Порядок вкладок CompanyPage:**
Обзор · Активность · Сотрудники · Сделки · Документы · Файлы · Холдинг
(убрать «Журнал», убрать «Платежи» на этом этапе — оставить как плейсхолдер в конце или скрыть совсем; уточнить с PO)

**Меню компании** — оставить текущий набор; убрать «Экспорт» если не реализовано.

---

### 3.2 `CompanyPage/overview` — однаколонная перекомпоновка

**Убрать двухколоночный layout** (`col-12 col-xl-6`). Заменить на `col-12`:

```html
<div class="row g-0">
  <div class="col-12">
    <div class="company-page-v2__panels">
      <CompanyRequisitesPanel ... />
      <CompanyEmployeesPanel ... />
      <!-- новый InfoPanel «Сделки в работе» -->
      <InfoPanel title="Сделки в работе" icon="pi-briefcase" add-label="Создать сделку" @add="onCreateDeal">
        <CompanyMiniDealsPanel :deals="deals" />
      </InfoPanel>
      <HoldingTree ... />
      <!-- История событий (мини) -->
      <InfoPanel title="История событий" icon="pi-history">
        <EntityMiniTimeline :log="companyLog" :max-items="5" />
      </InfoPanel>
    </div>
  </div>
</div>
```

**Убрать:** `MiniPipelinePanel` (заменить на `CompanyMiniDealsPanel`), `CompanyMarketingPanel`, `InfoPanel «Сейчас»`, `InfoPanel «Доп. поля»` (добавить в конец как свёрнутую).

**Новый `CompanyMiniDealsPanel`** — мини-таблица без заголовка, 3 колонки: Сделка (flex:1, ссылка) · Этап (Tag, 130px) · Сумма (справа, font-weight 700, `$primary-900`). Строки без `DataTable` — просто `div`-строки с hover `var(--p-surface-50)`.

---

### 3.3 `CompanyDealsTab.vue` — TabHead + правка колонок

**Файл:** `front/src/pages/CompanyPage/components/CompanyDealsTab.vue`

Добавить `TabHead` в начало шаблона:
```html
<div class="company-deals-tab__head">
  <span class="company-deals-tab__head-title">{{ t('company.page.tabs.deals') }}</span>
  <Button icon="pi pi-plus" :label="t('company.page.deals.createDeal')" size="small" @click="$emit('createDeal')" />
</div>
```

Колонки по спеке §6 (убрать «Статус», добавить «Создана»):
```
Название | Этап | Сумма (справа, 700) | Ответственный | Создана (DD.MM.YYYY)
```

```scss
.company-deals-tab__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: $space-3 $space-4;
  border-bottom: 1px solid var(--p-surface-200);

  .app-dark & {
    border-bottom-color: var(--p-surface-600);
  }
}

.company-deals-tab__head-title {
  font-size: $font-size-xs;        // 12px
  font-weight: $font-weight-bold;  // 700
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: $surface-500;
}
```

---

### 3.4 `CompanyEmployeesTab.vue` — раскрытие строк

**Файл:** `front/src/pages/CompanyPage/components/CompanyEmployeesTab.vue`

Добавить раскрытие строки (по §7 спеки). Текущая `DataTable` с меню — доработать:

- В колонке ФИО слева — `pi-chevron-right` / `pi-chevron-down` (по состоянию `expandedRows`)
- Клик по строке → раскрытие под строкой (PrimeVue `DataTable` expandedRows feature или ручное)
- Клик по имени (ссылка) → переход, `@click.stop`
- Раскрытый блок: чипы-каналы (телефон/email/Telegram), padding-left 48px

PrimeVue `DataTable` поддерживает `v-model:expandedRows` + slot `#expansion`:
```html
<DataTable v-model:expanded-rows="expandedRows" :value="employees">
  <template #expansion="{ data }">
    <div class="employees-tab__expansion">
      <!-- контактные данные из data.contact.channels -->
    </div>
  </template>
</DataTable>
```

Меню `pi-ellipsis-v` по спеке §7 (добавить «Перейти в карточку», «Изменить статус»):
```
Перейти в карточку (pi-user) → router.push(`/contacts/${data.contact_id}`)
Сделать основным (pi-star) → emit setPrimary
Изменить статус (pi-sync) → emit toggleStatus
---
Отвязать контакт (pi-user-minus, danger)
```

```scss
.employees-tab__expansion {
  padding: $space-2 $space-4 $space-2 48px;
  display: flex;
  flex-wrap: wrap;
  gap: $space-2;
  background: var(--p-surface-50);
  border-top: 1px solid var(--p-surface-100);

  .app-dark & {
    background: var(--p-surface-100);  // инвертированная шкала
    border-top-color: var(--p-surface-200);
  }
}
```

**i18n-ключи:**
```
company.page.employees.actions.goToCard: { ru: "Перейти в карточку", en: "Go to contact" }
```

---

### 3.5 `CompanyPage/files` — вкладка «Файлы»

Аналогично `ContactFilesTab` (§2.5). Создать `CompanyFilesTab.vue` с тем же двухпанельным layout. Backend блокер §B-4 общий.

---

### 3.6 `CompanyPage/holding` — TabHead

**Файл:** `front/src/pages/CompanyPage/index.vue` (TabPanel holding)

Добавить `TabHead` с кнопкой «Привязать компанию»:
```html
<div class="company-page-v2__holding-head">
  <span>{{ t('company.page.tabs.holding') }}</span>
  <Button icon="pi pi-plus" :label="t('crm.company.holding.addParent')" size="small" @click="showAttachHolding = true" />
</div>
<HoldingTree ... />
```

---

### 3.7 `CompanyPage/documents` — `DocumentStatusTag` проверка

`CompanyDocumentsTab` уже реализован. Убедиться что используется `DocumentStatusTag` (из S2.10 ТЗ) с корректными severity. Колонки должны быть в порядке: № · Статус · Вид · Дата · Действия. Проверить порядок колонок — при необходимости скорректировать.

---

## Часть 4. Общие диалоги

### 4.1 Диалог «Добавить сотрудника / контакт» (`CreateContactInlineDialog`)

**Файл:** `front/src/components/crm/CreateContactInlineDialog.vue`

Проверить поля по спеке §11: ФИО* · Телефон* · Email · Должность* · Заметки · тумблер «Основной контакт».

Текущий вариант использует `show-is-primary` prop — проверить что он рендерит `ToggleSwitch`.

Размер: `style="width: 520px"` (текущий — 480px). Скорректировать.

---

### 4.2 `RequisiteFormDialog.vue` — проверка полей

**Файл:** `front/src/pages/CompanyPage/components/RequisiteFormDialog.vue`

Проверить наличие всех полей по спеке §11:
Метка · Юр. название* · Полная форма (ТОО/ООО/АО) · Метка налог. ID · Налоговый ID · Страна · Директор · Директор (род. падеж) · Адрес · раздел «Банк» (Банк, Счёт, БИК) · Действует с · тумблер «Установить текущим» · Примечание.

Ширина: `style="width: 600px"`.

---

## Часть 5. Файлы к удалению / переименованию

| Действие | Файл |
|---|---|
| **Удалить** | `front/src/pages/ContactPage/components/ContactRightRail.vue` (не используется — grep 0 вхождений) |
| **Удалить** | `front/src/pages/CompanyPage/components/CompanyRightRail.vue` (не используется — grep 0 вхождений) |
| **Убрать импорт + использование** | `EntityLogTab` из `ContactPage/index.vue` |
| **Убрать импорт + использование** | `EntityLogTab` из `CompanyPage/index.vue` |
| **Убрать** | `ContactMarketingPanel` из `ContactPage/overview` (данные → хедер) |
| **Убрать** | `CompanyMarketingPanel` из `CompanyPage/overview` |
| **Убрать** | `MiniPipelinePanel` из `CompanyPage/overview` (заменить `CompanyMiniDealsPanel`) |
| **Создать новые** | `ContactDealsTab.vue`, `ContactFilesTab.vue`, `CompanyFilesTab.vue`, `CompanyMiniDealsPanel.vue` |

---

## Часть 6. Порядок реализации (фазы)

### Фаза A — Общие компоненты (без риска регрессии)
1. `EntityInfoHeader`: добавить props `sourceLabel`, `createdAt`, `updatedAt`, иконку `pi-tag`, бордер кнопок, 22px title
2. `EntityKpiStrip`: pill-стиль
3. `InfoPanel`: уточнить SCSS header-actions (кнопка «+»)
4. `EntityComposer`: перекомпоновка (mode-кнопки вместо Select)

### Фаза B — Активность (слияние журнала)
5. `EntityActivitiesTab`: фильтр-чипы «Все / События / Изменения», доработка fc-кружка
6. Убрать вкладку «Журнал» из обеих страниц (безопасно после фазы B)

### Фаза C — ContactPage
7. `ContactPage/index.vue`: убрать Rail-файлы, Now-strip, перекомпоновать меню, обновить KPI
8. `ContactPage/overview`: порядок панелей, убрать Marketing
9. `ContactChannelsBlock`: hover-иконки-кружки
10. `ContactDealsTab.vue`: новый компонент для вкладки «Сделки»
11. Диалог «Привязать компанию»: добавить ToggleSwitch

### Фаза D — CompanyPage
12. `CompanyPage/index.vue`: убрать Log, Now-strip, убрать 2-колонки overview
13. `CompanyMiniDealsPanel.vue`: новый (заменяет MiniPipelinePanel)
14. `CompanyDealsTab`: TabHead + правка колонок
15. `CompanyEmployeesTab`: раскрытие строк, доработка меню
16. Холдинг: TabHead

### Фаза E — Файлы (зависит от backend)
17. `ContactFilesTab.vue` + `CompanyFilesTab.vue` (скелетоны до API)

### Фаза F — Диалоги
18. `RequisiteFormDialog`: проверка полей
19. `CreateContactInlineDialog`: ширина 520px

---

## Backend-пробелы (блокеры)

| # | Блокер | Нужно для |
|---|---|---|
| B-1 | `GET /api/contacts/{id}/feed` и `GET /api/companies/{id}/feed` должны возвращать **объединённую** ленту активностей + field_changes в одном ответе (единый `type: 'activity' | 'field_change'`). Сейчас `useEntityFeed` работает с активностями, `useEntityLog` — отдельно. | Слияние вкладки «Журнал» в «Активность»; фильтр-чипы |
| B-2 | `contact.kpi.sum` (сумма сделок контакта в базовой валюте) — отсутствует в текущем ответе `/api/contacts/{id}`. | KPI-чип «Сумма» у контакта |
| B-3 | `POST /api/deals` с `contact_id` (добавить контакт в сделку при создании) + `POST /api/deals/{id}/contacts` (привязать контакт к существующей сделке). | Кнопка «Добавить в сделку» в панели «Участвует в сделках» |
| B-4 | `GET /api/contacts/{id}/files` и `GET /api/companies/{id}/files` — отсутствуют. | Вкладка «Файлы» |
| B-5 | `contact.source` должен возвращаться в ответе `/api/contacts/{id}` (текущий ответ — проверить). | Поле «Источник» в хедере контакта |

---

## i18n-ключи (сводка новых)

```js
// Хедер
'crm.entity.source': 'Источник',
'crm.entity.createdAt': 'Создан',
'crm.entity.updatedAt': 'Изменён',
'crm.entity.primaryCompany': 'Компания',

// Фид / фильтры
'crm.entity.feed.filterAll': 'Все',
'crm.entity.feed.filterEvents': 'События',
'crm.entity.feed.filterChanges': 'Изменения',

// Composer
'crm.entity.composer.note': 'Заметка',     // уже есть: sales.deal.composer.note
'crm.entity.composer.task': 'Задача',      // уже есть: sales.deal.composer.task
'crm.entity.composer.add': 'Добавить',

// Вкладки
'crm.contact.tabs.files': 'Файлы',         // возможно уже есть
'crm.company.tabs.files': 'Файлы',

// Сотрудники
'company.page.employees.actions.goToCard': 'Перейти в карточку',

// Привязать компанию
'contact.page.companies.isPrimary': 'Основная компания',

// Файлы
'crm.files.createFolder': 'Создать папку',
'crm.files.upload': 'Загрузить файл',
'crm.files.emptyFiles': 'Нет файлов',
'crm.files.emptyFolders': 'Нет папок',
```

---

## Обе темы — сводная шпаргалка

| Элемент | Light | Dark |
|---|---|---|
| KPI-чип нейтральный | `var(--p-surface-100)` / `$surface-600` | `.app-dark &` → `var(--p-surface-200)` / `var(--p-surface-300)` |
| Composer bg | `var(--p-surface-50)` | `.app-dark &` → `var(--p-surface-100)` |
| Composer mode-btn (inactive) | `border: surface-300` | `.app-dark &` → `border: surface-600; color: surface-300` |
| fc-кружок (лог) | `var(--p-surface-100)` | `.app-dark &` → `var(--p-surface-200)` |
| Expansion row | `var(--p-surface-50)` | `.app-dark &` → `var(--p-surface-100)` |
| FilesTab border-right | `var(--p-surface-200)` | `.app-dark &` → `var(--p-surface-600)` |
| Navy-хедер | `$brand-header-bg` = `#172747` | **Инвариантен** — dark-оверрайд НЕ нужен |
| Панель «Обзор» | `$surface-card` + `border: 1px solid var(--p-surface-200)` | `.app-dark &` → `border-color: var(--p-surface-700)` (уже есть в `.contact-page-v2__panels`) |
