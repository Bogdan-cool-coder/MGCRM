# Global Toolbar Refactor Task

## Task

Нужно убрать текущий глобальный `sidebar` и заменить его на компактный глобальный `toolbar` / `navigation-action bar`, который располагается поверх layout и перекрывает контент, а не участвует в обычной раскладке страницы.

Новый `toolbar` должен полностью заменить по функционалу текущий глобальный sidebar:

- навигация по разделам
- переключение компании
- доступ к профилю пользователя
- все связанные `popover` / `dropdown` сценарии
- `role-based visibility` пунктов

Важно: речь идет именно о глобальном sidebar layout-уровня. Локальные sidebar-области внутри AI-страниц в рамках этой задачи не перерабатываются.

## Target Behavior

Новый глобальный `toolbar` должен:

- быть всегда поверх layout-контента на любых экранах
- перекрывать контент, а не резервировать под себя место
- быть видимым и доступным и на desktop, и на mobile
- иметь очень ограниченную настраиваемость позиции
- поддерживать 2 режима позиционирования:
  - `top` — горизонтальная панель
  - `left` — вертикальная панель
- при `top` быть горизонтальным рядом иконок
- при `left` быть вертикальным рядом иконок
- не показывать текстовые подписи внутри панели
- показывать `tooltip` по `hover` и `focus` на иконке
- быть выше обычного контента и page-level overlay-элементов
- быть ниже модальных окон и dialog-компонентов
- иметь архитектурный задел под будущую возможность `drag and drop`, но без полноценной реализации DnD сейчас

## What Must Be Removed

Нужно удалить или вывести из использования всё, что относится к старому глобальному sidebar:

- сам компонент глобального sidebar
- `desktop collapse behavior`
- `mobile open/close behavior`
- мобильную кнопку открытия sidebar
- overlay, который использовался для mobile sidebar
- sidebar-centric state в `layout store`, если он больше не нужен

После завершения не должно остаться UX, в котором toolbar "открывается как sidebar". Toolbar всегда существует как floating global control layer.

## UX Requirements

### 1. Toolbar overlays content

- Контент страницы не должен сдвигаться и не должен знать о существовании toolbar.
- Toolbar рендерится как отдельный floating layer.
- Layout должен остаться простым: основной `main` занимает доступное пространство без левой колонки.

### 2. Two placement modes

- Нужно предусмотреть переключение позиции toolbar между:
  - `top`
  - `left`
- В верхней позиции toolbar горизонтальный.
- В левой позиции toolbar вертикальный.
- В обоих случаях toolbar остается компактным.

### 3. Limited position configurability

- Не нужен свободный arbitrary positioning по экрану.
- Нужен только контролируемый набор вариантов расположения.
- На текущем этапе достаточно поддержать именно `top` и `left`.
- Архитектура должна допускать расширение позже, но не должна быть переусложнена.

### 4. Icon-only UI

- Внутри toolbar не нужен текст.
- Все элементы должны быть `icon-only`.
- Назначение элемента должно раскрываться через `tooltip`.

### 5. Tooltip behavior

- Tooltip должен показываться при наведении и при фокусе с клавиатуры.
- Tooltip нужен для всех `icon-only` action/navigation items.
- Tooltip должен корректно работать и в горизонтальном, и в вертикальном режиме.

### 6. Layering behavior

- Toolbar должен быть поверх обычного layout-контента.
- Toolbar должен быть поверх page-level dropdown/popover элементов, если это ожидается по UX.
- Toolbar не должен перекрывать `modal` / `dialog` окна.
- Нужно аккуратно выстроить `z-index`.

## Functional Scope

Toolbar должен включать весь функционал текущего глобального sidebar.

### 1. Navigation

Нужно перенести текущие nav actions:

- `reports`
- `company`
- `ai-reports`
- `ai-chat`

Требования:

- `active route` должен быть визуально заметен
- доступность пунктов должна остаться `role-based`, как сейчас
- логика определения активного маршрута должна сохраниться

### 2. Company switcher

Нужно перенести текущий `CompanySwitcher` в компактный toolbar-формат.

Требования:

- сам `trigger` должен быть компактным и подходить для `icon-only toolbar`
- `popover` / `dropdown` с выбором компании должен сохранить функциональность
- сценарий `manage companies` для `superadmin` должен сохраниться
- логика открытия/закрытия должна быть корректной во floating toolbar-контексте

### 3. Profile menu

Нужно перенести текущий `ProfileMenu` в компактный toolbar-формат.

Требования:

- `trigger` компактный
- сохраняются:
  - данные пользователя
  - роль
  - смена языка
  - `logout`
- `popover` должен корректно работать в новом positioning context

## Architectural Requirements

### 1. Do not mutate old Sidebar in place

Нужно создать новую сущность `Toolbox` / `Toolbar`, а не превращать старый `Sidebar.vue` в новый компонент через хаотичный refactor.

Рекомендуемый подход:

- создать новый модуль `components/Toolbox` или `components/Toolbar`
- перевести layout на него
- после этого удалить legacy sidebar

### 2. Split container and presentation

Нельзя смешивать в одном компоненте:

- layout shell
- route / permission logic
- overlay orchestration
- visual rendering

Желательная структура:

- container-компонент собирает данные и состояние
- presentational-компонент рендерит toolbar
- overlay orchestration вынесена в `composable`

### 3. Extract overlay coordination

Текущая логика согласованного открытия `CompanySwitcher` и `ProfileMenu` не должна оставаться привязанной к sidebar.

Нужно вынести её в отдельный `composable`, например:

- `useToolboxOverlays`

Он должен отвечать за:

- единое состояние открытого overlay
- закрытие одного `popover` при открытии другого
- закрытие overlay по `route change`
- синхронизацию `trigger` / `popover state`

### 4. Prepare for future drag and drop

Сейчас полноценный `drag and drop` не нужен.
Но нужно заложить architecture hooks, чтобы потом его можно было добавить без нового крупного рефакторинга.

Нужно предусмотреть:

- собственный `root ref` у toolbar
- абстракцию позиции toolbar, а не хардкод только в SCSS
- явный `placement` contract:
  - `top`
  - `left`
- возможность в будущем добавить controlled coordinates / state
- при необходимости отдельную `drag-handle` area contract, даже если она пока не активна

Важно:

- не реализовывать полусырой drag and drop
- не делать свободное перемещение сейчас
- только подготовить чистую основу

## State Requirements

Нужно предусмотреть состояние toolbar, отделенное от legacy sidebar terminology.

Желательно:

- убрать или постепенно вывести из использования `isSidebarCollapsed`
- убрать или вывести из использования `isSidebarOpen`
- заменить это на более корректную модель toolbar state

Минимально стоит предусмотреть:

- `placement: 'top' | 'left'`

В перспективе:

- `mode`
- `isDragging`
- `customPosition`

Если state хранится в store:

- именование должно быть `toolbar` / `toolbox`, а не `sidebar`
- API должно быть пригодным для будущего расширения

## Visual Requirements

### 1. Toolbar as compact control surface

- единый floating container
- компактные кнопки
- понятное `active-state` выделение
- хороший `hover` / `focus state`
- аккуратные отступы и скругления
- без текстовых лейблов

### 2. Horizontal mode

- элементы идут в строку
- toolbar читается как глобальная `navigation/action bar`
- не должен занимать слишком много вертикального пространства

### 3. Vertical mode

- элементы идут в колонку
- toolbar остается компактным
- `spacing` и размеры кликабельных зон адаптированы под вертикальную композицию

### 4. Accessibility

- у всех `icon-only` кнопок должны быть `aria-label`
- `tooltip` не должен быть единственным источником смысла для `screen reader`
- клавиатурная навигация должна работать
- `focus ring` должен быть видимым

## Constraints

- Не трогать бизнес-логику без необходимости.
- Не ломать текущие права доступа.
- Не дублировать логику `CompanySwitcher` / `ProfileMenu`, если можно переиспользовать существующую.
- Не использовать текстовые подписи внутри toolbar.
- Не оставлять старую mobile-sidebar механику.
- Не смешивать глобальный toolbar с локальными chat sidebars.
- Не внедрять полноценный DnD в этом этапе.

## Definition of Done

Задача считается выполненной, если:

1. Глобального sidebar больше нет.
2. Mobile-кнопки открытия sidebar больше нет.
3. Вместо sidebar есть компактный floating toolbar поверх layout.
4. Toolbar поддерживает `top` и `left` placement.
5. В `top` toolbar горизонтальный, в `left` вертикальный.
6. В toolbar нет текста, только иконки.
7. На иконках работают `tooltip`'ы.
8. Toolbar полностью заменяет функционал старого глобального sidebar.
9. Toolbar выше контента, но ниже модальных окон.
10. Архитектура допускает дальнейшее добавление drag and drop без нового большого рефакторинга.

## Post-Implementation Verification

После реализации обязательно проверить:

- `active route states`
- `role-based visibility` nav items
- корректность открытия `CompanySwitcher`
- корректность открытия `ProfileMenu`
- закрытие `popover` по `route change`
- mobile behavior без old sidebar logic
- `z-index` относительно `popover` / `dialog` / `modal`
- `tooltip behavior` в `top` и `left`
- что toolbar действительно перекрывает контент, а не участвует в layout
- что layout не содержит legacy sidebar dependencies

---

## Implementation Brief

### Goal

Заменить текущий глобальный layout sidebar на компактный floating toolbar с двумя placement-режимами (`top`, `left`), сохранив весь функционал sidebar и убрав зависимость layout от левой колонки.

### Non-goals

- Не перерабатывать локальные sidebar-области AI-страниц.
- Не внедрять полноценный `drag and drop`.
- Не делать произвольное позиционирование toolbar по экрану.
- Не переписывать бизнес-логику company/profile flows без необходимости.

### Engineering approach

Рекомендуется реализовать задачу как controlled refactor:

1. Добавить новый toolbar-модуль как отдельную сущность.
2. Вынести overlay orchestration из legacy sidebar.
3. Адаптировать `CompanySwitcher` и `ProfileMenu` под compact `icon-only trigger`.
4. Переключить `DefaultLayout` на новый toolbar.
5. Удалить legacy sidebar и связанный mobile UX.
6. Почистить store / tokens / exports.

### Key design decisions

- Toolbar должен рендериться независимо от layout flow через `position: fixed`.
- Layout не должен резервировать под него пространство.
- Toolbar placement должен быть определен явно и централизованно.
- Overlay logic должна быть переиспользуемой и не привязанной к конкретной оболочке.
- UI должен быть `icon-only`, а семантика должна сохраняться через `aria-label` и `tooltip`.

### Risks

- Некорректное позиционирование `popover` при `fixed` toolbar.
- Конфликты `z-index` между toolbar, `popover` и `modal`.
- Переполнение toolbar на узких экранах.
- Ломка существующего mobile UX из-за неполного удаления sidebar state.
- Непреднамеренное смешение visual refactor с business logic changes.

### Recommended acceptance strategy

- Сначала проверить визуальное и функциональное поведение `top` placement.
- Затем проверить `left` placement.
- Затем проверить `mobile`, `popover`, `modal` и `route change` сценарии.
- После этого удалить legacy sidebar artifacts.

---

## Files To Create / Update

### Create

- `front/src/components/Toolbox/Toolbox.vue`
- `front/src/components/Toolbox/index.ts`
- `front/src/components/Toolbox/composables/useToolboxOverlays.ts`
- `front/src/components/Toolbox/types.ts`

Опционально, если потребуется разделение:

- `front/src/components/Toolbox/components/ToolboxNavButton.vue`
- `front/src/components/Toolbox/components/ToolboxActionButton.vue`
- `front/src/components/Toolbox/components/ToolboxShell.vue`

### Update

- `front/src/layouts/DefaultLayout/index.vue`
- `front/src/stores/layout.ts`
- `front/src/components/Company/components/CompanySwitcher.vue`
- `front/src/components/ProfileMenu/ProfileMenu.vue`
- `front/src/components/Sidebar/sidebarOverlay.ts`

В зависимости от решения по namespace:

- либо переиспользовать `sidebarOverlay.ts` временно
- либо перенести его в toolbox namespace и обновить импорты

Также возможно потребуется обновить:

- `front/src/components/Company/index.ts`
- `front/src/components/ProfileMenu/index.ts`
- `front/src/theme/tokens/layout.ts`
- `front/src/theme/css/appVariables.ts`
- `front/src/theme/tokens/zIndex.ts`
- `front/src/theme/index.ts`

### Delete after migration

- `front/src/components/Sidebar/Sidebar.vue`
- `front/src/components/Sidebar/index.ts`

При необходимости также зачистить:

- `front/src/components/Sidebar/locale/*`
- legacy sidebar styles / exports / references

---

## Recommended Component Structure

### High-level structure

```text
components/
  Toolbox/
    Toolbox.vue
    index.ts
    types.ts
    composables/
      useToolboxOverlays.ts
    components/
      ToolboxShell.vue
      ToolboxNavButton.vue
      ToolboxActionButton.vue
```

### Responsibilities

#### `Toolbox.vue`

Container-level component.

Отвечает за:

- получение данных из `route`, `userStore`, `layoutStore`
- вычисление доступных nav items
- управление placement
- связывание `CompanySwitcher` / `ProfileMenu`
- orchestration overlays через `useToolboxOverlays`

#### `ToolboxShell.vue`

Presentational shell.

Отвечает за:

- floating container markup
- orientation classes (`top`, `left`)
- container styling
- layout групп кнопок

#### `ToolboxNavButton.vue`

Отвечает за:

- единообразный рендер icon-only navigation button
- `active`, `hover`, `focus`, `disabled` states
- `tooltip`
- `aria-label`

#### `ToolboxActionButton.vue`

Отвечает за:

- единообразный рендер action trigger button
- `tooltip`
- compact/icon-only presentation для profile/company triggers

#### `useToolboxOverlays.ts`

Отвечает за:

- `openOverlay`
- registration / sync popover controls
- закрытие активного overlay при открытии другого
- закрытие overlays на `route change`
- вспомогательный API для `CompanySwitcher` / `ProfileMenu`

#### `types.ts`

Минимальные типы:

- `ToolboxPlacement = 'top' | 'left'`
- `ToolboxOverlayName`
- `ToolboxNavItem`
- `ToolboxActionItem`

---

## Step-by-Step Implementation Plan

### Step 1. Audit and isolate sidebar responsibilities

- Зафиксировать, какой функционал текущего sidebar должен быть перенесен.
- Отделить responsibilities:
  - navigation
  - overlay controls
  - company/profile actions
  - mobile sidebar mechanics
- Убедиться, что в scope только глобальный sidebar.

### Step 2. Introduce toolbox types and placement model

- Создать новый toolbox namespace.
- Добавить типы для:
  - placement
  - overlay names
  - nav item model
- Решить, где живет `placement`: локально в компоненте или в `layout store`.

### Step 3. Extract overlay coordination into composable

- Вынести из legacy `Sidebar.vue` логику:
  - `openOverlay`
  - `overlayTriggerEvent`
  - `syncOverlayState`
  - `handleOverlayToggle`
  - `handleOverlayVisibility`
- Оформить в `useToolboxOverlays`.
- Протестировать composable отдельно на сценариях company/profile.

### Step 4. Adapt company/profile triggers to compact icon-only mode

- Обновить `CompanySwitcher` так, чтобы он поддерживал compact trigger mode.
- Обновить `ProfileMenu` так, чтобы он поддерживал compact trigger mode.
- Сохранить существующее popover content behavior.
- Добавить корректные `aria-label` и `tooltip-friendly` triggers.

### Step 5. Build new Toolbox component

- Создать floating toolbar container.
- Реализовать `top` и `left` placement через явный prop / state contract.
- Добавить nav actions с active-state.
- Добавить action triggers для company/profile.
- Реализовать icon-only UI и tooltip support.

### Step 6. Integrate Toolbox into DefaultLayout

- Удалить из layout левую колонку sidebar.
- Удалить mobile sidebar open button.
- Подключить новый `Toolbox`.
- Убедиться, что `main` больше не зависит от sidebar width.

### Step 7. Clean up layout state

- Удалить или мигрировать sidebar-centric state из `layout store`.
- Ввести `toolbar` / `toolbox` naming.
- Оставить только тот state, который нужен для placement и будущего расширения.

### Step 8. Reconcile z-index and theme tokens

- Проверить `z-index` toolbar относительно:
  - контента
  - `popover`
  - `modal`
- При необходимости обновить theme tokens.
- Убедиться, что toolbar стабильно читается поверх layout.

### Step 9. Remove legacy sidebar artifacts

- Удалить старый sidebar-компонент и связанные exports.
- Удалить legacy mobile overlay behavior.
- Зачистить неиспользуемые импорты, locale references и стили.

### Step 10. Verify behavior end-to-end

- Проверить `top` placement.
- Проверить `left` placement.
- Проверить desktop.
- Проверить mobile.
- Проверить company/profile flows.
- Проверить `tooltip`, `focus`, `keyboard navigation`.
- Проверить layering и отсутствие layout regressions.

---

## Suggested Work Breakdown For Agent

### Phase 1. Foundation

- создать toolbox types
- вынести overlay orchestration
- определить placement contract

### Phase 2. UI migration

- собрать новый toolbar
- адаптировать company/profile triggers
- подключить toolbar в layout

### Phase 3. Cleanup

- удалить sidebar
- убрать mobile sidebar UX
- почистить store, exports, tokens

### Phase 4. Validation

- проверить interaction flows
- проверить responsive behavior
- проверить accessibility и layering

---

## Expected Final Outcome

После завершения в проекте должен быть один компактный глобальный floating toolbar, который:

- полностью заменяет глобальный sidebar
- не участвует в layout flow
- поддерживает `top` и `left` placement
- работает как `icon-only` navigation/action bar с tooltip'ами
- находится поверх контента, но ниже модальных окон
- имеет чистую архитектурную основу для будущего добавления `drag and drop`
