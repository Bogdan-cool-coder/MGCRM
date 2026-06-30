# ТЗ: Страница «Настройки» — master-detail shell (Фаза 1)

> **Канонический источник правды по визуалу:** токены `front/src/theme/`, SCSS-переменные
> репо (`$space-*`, `$radius-*`, `$surface-*`, `$primary-*`, `--p-*`). Hex-литералы
> запрещены. Бренд-инварианты (`#172747` для sidebar, хедера карточки сделки) — единственное
> допустимое исключение.
>
> **Reuse-first:** весь контент разделов переселяется из существующего
> `ProfilePage/index.vue`. Логику (composables, сторы, компоненты) НЕ переписывать — только
> подключать в новый шелл.

---

## Зачем

Заменить плоский `ProfilePage` (hub-карточки + section-режим без deep-link) на
**постоянный master-detail**: список разделов слева всегда виден, раздел открывается справа
без перехода на новый URL-path. Единый канонический URL `/settings?section=<key>`,
deep-link работает на старте. Все старые URL редиректятся сюда — пользователь не замечает
переезда.

**User story:** «Я открываю настройки через `/settings`, вижу слева структуру, кликаю на
любой раздел и сразу вижу его контент справа, без перезагрузки. Ссылку могу скопировать и
отправить коллеге — он попадёт прямо в нужный раздел.»

---

## Где в коде

```
front/src/pages/SettingsPage/         ← НОВАЯ папка (заменяет ProfilePage)
  index.vue                           ← корневой компонент, экспортирует SettingsLayout
  composables/
    useSettings.ts                    ← active section, deep-link sync, dirty-guard
  components/
    SettingsSidebar.vue               ← левая панель (список групп + пунктов)
    SettingsDetail.vue                ← правая панель (router-outlet для секций)
    sections/
      SectionProfile.vue              ← переселён из ProfilePage: profile-tab
      SectionSecurity.vue             ← security-tab
      SectionAppearance.vue           ← appearance + quickActions (слиты, см. §5)
      SectionLanguage.vue             ← locale-tab
      SectionChannels.vue             ← telegram + заглушки Email/WhatsApp
      SectionComingSoon.vue           ← переиспользуемый placeholder для Ф2/Ф3
```

> `ProfilePage/index.vue` остаётся в репо до Ф2, но превращается в тонкий редирект
> `/profile → /settings?section=profile`. Удалять не сейчас.

---

## Wireframe (ASCII)

```
┌──────────────────────────────────────────────────────────────────────────────┐
│  PageHeader  [pi pi-cog]  «Настройки»                                        │
│              ────────────────────────────────────── 56px, border-bottom      │
├──────────────────┬───────────────────────────────────────────────────────────┤
│  SIDEBAR ~240px  │  DETAIL (flex: 1, overflow-y: auto)                       │
│  (sticky, full   │                                                           │
│   height)        │  ┌────────────────────────────────────────────────────┐  │
│                  │  │  <SectionProfile> / <SectionSecurity> / …          │  │
│  АККАУНТ         │  │                                                    │  │
│  ──────────────  │  │  [секция-заголовок]                                │  │
│  • Профиль   ←── │  │  [поля/контролы раздела]                          │  │
│  • Безопасность  │  │                                                    │  │
│  • Внешний вид   │  │  ─────────────────────────────────────────────    │  │
│  • Язык          │  │  [Сохранить]  [Отменить]  (только для форм)       │  │
│                  │  └────────────────────────────────────────────────────┘  │
│  ИНТЕГРАЦИИ      │                                                           │
│  ──────────────  │                                                           │
│  • Каналы        │                                                           │
│                  │                                                           │
│  СПРАВОЧНИКИ (Ф2)│                                                           │
│  ──────────────  │                                                           │
│  • … (disabled)  │                                                           │
│                  │                                                           │
│  СИСТЕМА (Ф3)    │                                                           │
│  ──────────────  │                                                           │
│  • … (disabled)  │                                                           │
├──────────────────┴───────────────────────────────────────────────────────────┤
│  адаптив <768px: sidebar → select сверху (PrimeVue Select), detail — full    │
└──────────────────────────────────────────────────────────────────────────────┘
```

---

## 1. Шелл `SettingsLayout` (index.vue)

### Сетка

```scss
.settings-page {
  display: flex;
  flex-direction: column;
  height: 100%;
  // компенсирует внешний padding AppShell (как ProfilePage)
  margin: calc(-1 * $space-4) calc(-1 * $space-6) 0;
}

.settings-page__body {
  display: flex;
  flex: 1;
  overflow: hidden;
}

.settings-page__sidebar {
  width: 240px;         // $mg-sidebar-settings — новый локальный токен; НЕ $mg-sidebar-width
  flex-shrink: 0;
  border-right: 1px solid $surface-200;
  overflow-y: auto;
  background: $surface-card;

  .app-dark & {
    background: var(--p-surface-800);
    border-right-color: var(--p-surface-700);
  }
}

.settings-page__detail {
  flex: 1;
  overflow-y: auto;
  background: $surface-50;   // чуть «заглублённее» чем sidebar-card

  .app-dark & {
    background: var(--p-surface-900);
  }
}
```

### PageHeader

```vue
<PageHeader
  icon="pi pi-cog"
  :title="t('settings.pageTitle')"
/>
```

Без subtitle, без action-slot на Ф1.

### Адаптив (< 768px)

На ширине `< 768px` сайдбар скрывается. Вместо него — `Select` (PrimeVue) в верхней части
detail-зоны, options = полный плоский список разделов. Выбор в Select = смена раздела.
Паттерн: смотри как entity-страницы скрывают левый рейл на мобиле.

```scss
@media (max-width: 767px) {
  .settings-page__sidebar { display: none; }
  .settings-page__detail-mobile-select {
    display: block;
    padding: $space-3 $space-4;
    border-bottom: 1px solid $surface-200;
    background: $surface-card;
  }
}
@media (min-width: 768px) {
  .settings-page__detail-mobile-select { display: none; }
}
```

---

## 2. Sidebar `SettingsSidebar.vue`

### Структура данных (внутренний массив, НЕ из API)

```ts
interface SettingsSection {
  key: string           // id раздела, значение ?section=
  labelKey: string      // i18n
  icon: string          // pi pi-*
  phase: 1 | 2 | 3     // Ф1 активны, Ф2/Ф3 disabled
  roles?: string[]      // если не указано — доступно всем авторизованным
}

interface SettingsGroup {
  key: string
  labelKey: string      // АККАУНТ / ИНТЕГРАЦИИ / СПРАВОЧНИКИ / СИСТЕМА
  sections: SettingsSection[]
}
```

### Полная таксономия (source-of-truth для шелла)

```
АККАУНТ
  profile       «Профиль»        pi-user          Ф1  все роли
  security      «Безопасность»   pi-lock          Ф1  все роли
  appearance    «Внешний вид»    pi-sliders-h     Ф1  все роли
  language      «Язык»           pi-globe         Ф1  все роли

ИНТЕГРАЦИИ
  channels      «Каналы»         pi-share-alt     Ф1  все роли

СПРАВОЧНИКИ  (roles: admin, director)
  countries     «Страны»         pi-globe         Ф2
  acq-channels  «Каналы привлеч.» pi-megaphone    Ф2
  disc-reasons  «Причины отказа» pi-ban           Ф2
  catalog       «Каталог»        pi-box           Ф2
  exchange-rates «Курсы валют»   pi-dollar        Ф2
  pipeline-stg  «Воронка продаж» pi-sliders-h     Ф2
  doc-templates «Шаблоны документов» pi-file-edit Ф2
  tpl-variables «Перем. шаблонов» pi-list         Ф2
  approval-routes «Маршруты согласования» pi-sitemap Ф2
  msg-templates «Шаблоны сообщений» pi-envelope   Ф2

СИСТЕМА  (roles: admin, director)
  users         «Пользователи»   pi-users         Ф3
  access-control «Доступ и оргструктура» pi-shield Ф3
  automation-runs «Журнал автоматизаций» pi-clock  Ф3
  system-reset  «Сброс системы»  pi-refresh       Ф3  roles: admin
```

### Поведение пунктов

- **Ф1, доступен:** обычная строка, кликабельна. Активный — navy-тинт фон + левая полоска 3px.
- **Ф2/Ф3:** строка disabled (opacity 0.5, cursor default), без hover, справа тег
  `<Tag value="Скоро" severity="secondary" />` (11px).
- Группа целиком состоит из Ф2/Ф3 → её заголовок всё равно виден (сортирует навигацию), но
  opacity 0.6.
- Если пользователь не admin/director — группы СПРАВОЧНИКИ и СИСТЕМА не рендерятся совсем
  (скрываются через `v-if`, не disable).

### CSS-эталон строки

```scss
.settings-nav-item {
  display: flex;
  align-items: center;
  gap: $space-3;
  padding: $space-2 $space-4;
  border-radius: $radius-md;
  margin: 2px $space-2;
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: $surface-700;
  cursor: pointer;
  transition: background var(--app-transition-fast), color var(--app-transition-fast);
  min-height: 36px;
  text-decoration: none;
  border: none;
  background: transparent;
  width: calc(100% - $space-4);  // учитывает margin-x

  &:hover {
    background: var(--mg-surface-hover);
    color: $surface-900;
  }

  &--active {
    background: var(--p-primary-50);
    color: $primary-900;
    font-weight: $font-weight-semibold;
    box-shadow: inset 3px 0 0 $primary-900;

    .app-dark & {
      background: rgba($primary-900, 0.25);
      color: var(--p-primary-300);
      box-shadow: inset 3px 0 0 var(--p-primary-300);
    }
  }

  &--disabled {
    opacity: 0.5;
    cursor: default;
    pointer-events: none;
  }

  .app-dark & {
    color: var(--p-surface-300);

    &:hover {
      background: var(--mg-surface-hover);
      color: var(--p-surface-100);
    }
  }
}

.settings-nav-group__label {
  font-size: $font-size-2xs;   // ~11px
  font-weight: $font-weight-semibold;
  text-transform: uppercase;
  letter-spacing: 0.07em;
  color: $surface-400;
  padding: $space-4 $space-4 $space-1;
  margin: 0;

  .app-dark & { color: var(--p-surface-500); }
}
```

Иконка пункта: `font-size: $font-size-sm`, `color: inherit`, `opacity: 0.7`, flex-shrink 0.

---

## 3. Composable `useSettings.ts`

```ts
// Отвечает за:
// 1. activeSection: ref<string> — текущий раздел
// 2. deep-link sync (читает ?section= при mount, пишет при смене)
// 3. dirty-guard (beforeRouteLeave + внутреннее событие)
// 4. setSection(key) — публичный метод

interface UseSettingsReturn {
  activeSection: Ref<string>
  setSection: (key: string) => void
  isDirty: Ref<boolean>          // устанавливается секцией через provide/inject
  markDirty: () => void
  markClean: () => void
}
```

### Deep-link

При `onMounted`: читаем `route.query.section`. Если значение есть и раздел Ф1 и доступен
пользователю → `activeSection.value = value`. Иначе → дефолт `'profile'`.

При смене раздела: `router.replace({ query: { section: key } })` (не push, чтобы не
засорять history).

### Dirty-guard

Применяется ТОЛЬКО для разделов с формами (profile, appearance, language). Алгоритм:

1. Секция через `inject('settingsMarkDirty')` / `inject('settingsMarkClean')` обновляет
   `isDirty`.
2. При попытке сменить раздел: если `isDirty.value === true` → показываем `ConfirmDialog`
   («Есть несохранённые изменения. Покинуть раздел?» — кнопки «Остаться» / «Покинуть»).
3. «Покинуть» → `markClean()` + смена раздела. «Остаться» → ничего.
4. `beforeRouteLeave` (навигация браузером назад/вперёд) — та же проверка через тот же
   `ConfirmDialog`.

---

## 4. Раздел «Профиль» (`SectionProfile.vue`)

> Переселяется 1-в-1 из ProfilePage `activeTab === 'profile'`. Исходный код — в
> `front/src/pages/ProfilePage/index.vue`.

### Макет

```
[profile-section]  ← .profile-section (margin-bottom $space-6)
  [avatar-row]
    <CrmAvatar> или <img> 72px circle
    [upload-btn]  [remove-btn?]

[profile-field grid]  ← .row.g-4
  col-md-6: ФИО — InputText + «Сохранить» (inline, рядом)
  col-md-6: Email — InputText disabled
  col-md-6: Роль — InputText disabled

[save-bar]  ← .settings-save-bar (внизу раздела, sticky bottom 0)
  [Сохранить]  [Отменить]   ← ТОЛЬКО если fullNameDirty === true
```

### Save-модель (профиль)

- `fullNameDraft` — локальный ref, инициируется из `user.full_name`.
- `fullNameDirty = computed(() => draft !== user.full_name)`.
- При изменении → `markDirty()` (в `useSettings`).
- «Сохранить» → `saveFullName(draft)` → `useMutation.run(...)` → `markClean()`.
- «Отменить» → `fullNameDraft = user.full_name`, `markClean()`.
- Аватар — action-based (upload/remove немедленно), без драфта, без save-bar.

### Save-bar CSS

```scss
.settings-save-bar {
  display: flex;
  gap: $space-2;
  justify-content: flex-end;
  padding: $space-4 $space-6;
  border-top: 1px solid $surface-200;
  background: $surface-card;
  margin-top: $space-4;
  // sticky bottom — чтобы не прокручивать длинные разделы
  position: sticky;
  bottom: 0;
  z-index: 1;

  .app-dark & {
    background: var(--p-surface-800);
    border-top-color: var(--p-surface-700);
  }
}
```

Кнопки:
- «Сохранить» — `Button severity="primary" icon="pi pi-check"`, `:loading="savingProfile"`,
  `:disabled="!fullNameDirty"`.
- «Отменить» — `Button severity="secondary" text icon="pi pi-times"`.

### Состояния

| Состояние | Поведение |
|-----------|-----------|
| loading | `Skeleton` в полях (высота полей, rounded `$radius-sm`) |
| empty (нет user) | `Message severity="error"` «Не удалось загрузить профиль» + кнопка «Повторить» |
| dirty — смена раздела | `ConfirmDialog` (см. §3) |
| save success | `Toast severity="success"` «Профиль сохранён» |
| save error | `Toast severity="error"` с текстом ошибки |

---

## 5. Раздел «Безопасность» (`SectionSecurity.vue`)

> Переселяется из ProfilePage `activeTab === 'security'`. TOTP-визард без изменений.

### Макет

```
[profile-section]
  <h3> «Двухфакторная аутентификация» (profile-section__title)
  
  — если 2FA включена:
    Tag severity="success" «Включена»
    [Перегенерировать коды]  [Отключить]
    → при клике: inline-форма ввода кода (totp-manage-confirm)

  — если 2FA не включена:
    Tag severity="secondary" «Не включена»
    [Включить 2FA]

  — шаг setup (QR):
    заголовок + hint + QR/secret + поле кода + [Подтвердить] [Отмена]

  — шаг backup-codes:
    Message success + список кодов (.totp-backup-codes grid)
```

### Save-модель (безопасность)

**Action-based, без драфта и без save-bar.** Каждое действие (startTotpSetup,
verifyTotpSetup, confirmDisableTotp, confirmRegenerateCodes) — отдельный `useMutation.run()`.
`markDirty()` / `markClean()` не вызываются. Dirty-guard при смене раздела НЕ применяется.

Исключение: если пользователь в середине wizard (QR-шаг) → при попытке уйти показываем
обычный `ConfirmDialog` («Настройка 2FA не завершена. Прервать?») через внутренний флаг
`isTotpSetupStarted`.

### Состояния

| Состояние | Поведение |
|-----------|-----------|
| loading | `Skeleton` в секции |
| setup pending | `Button :loading="isSettingUpTotp"` |
| manage pending | `Button :loading="isManagingTotp"` |
| error | inline `<small class="login-field__error">` под полем кода |

---

## 6. Раздел «Внешний вид» (`SectionAppearance.vue`)

> **ОБЪЕДИНЯЕТ** бывший `appearance`-таб и бывший `quickActions`-таб. Причина: быстрые
> действия логически принадлежат режиму Орбиты — они живут именно там. Держать их отдельным
> разделом без контекста сбивает. В новом макете они — подсекция «Внешний вид», визуально
> акцентированная когда выбран orbit.

### Макет

```
[profile-section]  «Тема интерфейса»
  SelectButton  light / dark  (двухвариантный)

[profile-section]  «Режим навигации»
  .nav-mode-cards  (flex, gap $space-3)
    [sidebar-card]  [orbit-card]  ← nav-mode-card / nav-mode-card--active

[profile-section orbit-accent?]  «Быстрые действия»
  — если navMode === 'orbit': подсказка «Эти действия появятся в панели Орбиты»
    (Info Message, pi-info-circle, severity info, closable false)
  — если navMode === 'sidebar': подсказка серым текстом «Доступны только в режиме Орбиты»
  
  .quick-actions-preview  (flex-wrap, gap $space-2)
    [чип действия]…   ← quick-actions-preview__item
  
  Button «Настроить» severity="secondary" outlined icon="pi pi-cog"
    → открывает QuickActionsPickerDialog (уже существует)

[save-bar]  (sticky bottom)
  [Сохранить]  [Отменить]
```

### Save-модель (внешний вид)

Тема и режим навигации переключаются **как ПРЕВЬЮ** (немедленно применяются визуально, чтобы
пользователь видел результат). Однако в стор/бэкенд коммитятся только по кнопке «Сохранить».

Реализация:
```ts
// черновики
const themeDraft = ref<'light' | 'dark'>(themeStore.theme)
const navModeDraft = ref<NavMode>(layoutStore.navMode)

// watch черновиков → применяем как превью немедленно (только визуально)
watch(themeDraft, (v) => themeStore.setTheme(v))     // превью
watch(navModeDraft, (v) => layoutStore.setNavMode(v)) // превью

const isDirty = computed(
  () => themeDraft.value !== savedTheme.value || navModeDraft.value !== savedNavMode.value
)

// savedTheme / savedNavMode — значения на момент открытия раздела (snapshot)

function save() {
  // записываем в бэкенд (PATCH /api/user/preferences или только Pinia persist)
  savedTheme.value = themeDraft.value
  savedNavMode.value = navModeDraft.value
  markClean()
  toast.success(t('settings.appearance.saved'))
}

function discard() {
  // откатываем превью
  themeStore.setTheme(savedTheme.value)
  layoutStore.setNavMode(savedNavMode.value)
  themeDraft.value = savedTheme.value
  navModeDraft.value = savedNavMode.value
  markClean()
}
```

- «Сохранить» — `Button severity="primary" icon="pi pi-check"`, `:disabled="!isDirty"`.
- «Отменить» — `Button severity="secondary" text`, вызывает `discard()`.
- `markDirty()` при изменении черновика, `markClean()` при save/discard.
- Dirty-guard: при уходе со страницы → ConfirmDialog «Изменения не сохранены. Отменить
  превью и покинуть?» — «Покинуть» вызывает `discard()` + переход.

### Подсекция «Быстрые действия»

Быстрые действия хранятся в `user.nav_quick_actions` (массив ключей). Редактирование — через
`QuickActionsPickerDialog` (уже существует, реиспользуем без изменений). Диалог открывается
по кнопке «Настроить», сохраняет сразу в стор/бэкенд — он action-based, не часть черновика
темы/navMode.

Акцент «orbit»:
```scss
.quick-actions-orbit-hint {
  // отображается через <Message severity="info" :closable="false"> только если navMode === 'orbit'
  // иначе: небольшой серый .appearance-hint (pi-info-circle)
}
```

### Состояния

| Состояние | Поведение |
|-----------|-----------|
| loading | `Skeleton` в секции темы, nav-mode-cards скелетоны (два прямоугольника) |
| dirty | save-bar виден |
| уход с несохранёнными | ConfirmDialog + discard() при «Покинуть» |
| save success | `Toast severity="success"` «Настройки внешнего вида сохранены» |

---

## 7. Раздел «Язык» (`SectionLanguage.vue`)

> Переселяется из ProfilePage `activeTab === 'locale'`.

### Макет

```
[profile-section]
  <p> hint «Выберите язык интерфейса»  (text-muted)
  
  .locale-options  (flex, gap $space-3, flex-wrap)
    [locale-card «Русский»]  [locale-card «English»]

    locale-card — аналог nav-mode-card:
      .locale-card  (padding $space-4, width 160px, border 2px, radius $radius-md)
      .locale-card--active  (border-color $primary, bg rgba($primary, 0.06))
      Флаг-иконка: текстовый символ (RU: «RU», EN: «EN») или pi-globe
      Label: «Русский» / «English»

[save-bar]
  [Сохранить]  [Отменить]
```

### Save-модель (язык)

Язык переключается **как ПРЕВЬЮ** (немедленно через `setLocale(draft)`) — пользователь
видит интерфейс на выбранном языке. Коммитируется в бэкенд только по «Сохранить» (PATCH).

```ts
const localeDraft = ref<'ru' | 'en'>(currentLocale())
const savedLocale = ref<'ru' | 'en'>(currentLocale())
const isDirty = computed(() => localeDraft.value !== savedLocale.value)

watch(localeDraft, (v) => setLocale(v))  // превью

function save() {
  void changeLocale(localeDraft.value)     // API-вызов (уже в useProfilePage)
  savedLocale.value = localeDraft.value
  markClean()
}

function discard() {
  setLocale(savedLocale.value)
  localeDraft.value = savedLocale.value
  markClean()
}
```

Dirty-guard: аналогично §6. При уходе → «Отменить превью и покинуть?» → discard() + переход.

### Состояния

| Состояние | Поведение |
|-----------|-----------|
| loading | `Skeleton` два прямоугольника (locale-cards) |
| save pending | `Button :loading="savingLocale"` |
| save success | `Toast severity="success"` «Язык изменён» |
| save error | `Toast severity="error"` |

---

## 8. Раздел «Каналы» (`SectionChannels.vue`)

> Объединяет Telegram (из ProfilePage `activeTab === 'telegram'`) + заготовки Email/WhatsApp.

### Философия раздела

Раздел — **СПИСОК каналов** с единым паттерном строки. Новый канал добавляется как новая
строка в массив. Это делает добавление Email и WhatsApp тривиальным в Ф2/Ф3.

### Макет

```
[profile-section]  «Подключённые каналы»
  .channel-list  (flex-col, gap $space-3)

    [channel-row: Telegram]   ← активный, Ф1
    [channel-row: Email]      ← заглушка «Скоро», disabled
    [channel-row: WhatsApp]   ← заглушка «Скоро», disabled
```

### Компонент строки канала `.channel-row`

```
┌─────────────────────────────────────────────────────────────────────┐
│ [иконка 40px]  [название + статус]           [action-кнопка]        │
│ pi-telegram /  «Telegram»                    [Подключить] /         │
│ pi-envelope /  Tag «Подключён @username»     [Отключить]            │
│ pi-whatsapp    Tag «Скоро» (secondary)       (disabled)             │
└─────────────────────────────────────────────────────────────────────┘
```

```scss
.channel-row {
  display: flex;
  align-items: center;
  gap: $space-4;
  padding: $space-4;
  background: $surface-card;
  border: 1px solid $surface-200;
  border-radius: $radius-lg;
  transition: border-color var(--app-transition-fast);

  .app-dark & {
    background: var(--p-surface-800);
    border-color: var(--p-surface-700);
  }

  &--disabled {
    opacity: 0.55;
    pointer-events: none;
  }
}

.channel-row__icon {
  width: 40px;
  height: 40px;
  border-radius: $radius-md;
  background: var(--p-primary-50);
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  font-size: $font-size-xl;
  color: var(--p-primary-600);

  .app-dark & {
    background: rgba($primary-900, 0.3);
    color: var(--p-primary-300);
  }
}

.channel-row__body { flex: 1; min-width: 0; }
.channel-row__name {
  font-size: $font-size-base;
  font-weight: $font-weight-semibold;
  color: $surface-900;
  margin: 0 0 $space-1;
  .app-dark & { color: var(--p-surface-50); }
}
.channel-row__status {
  font-size: $font-size-sm;
  color: $surface-500;
  .app-dark & { color: var(--p-surface-400); }
}
.channel-row__action { flex-shrink: 0; }
```

### Telegram: action-кнопки (action-based, без save-bar)

- Не подключён: `Button label="Подключить" icon="pi pi-link" severity="primary" outlined`
  `:loading="telegramLinking"` → `linkTelegram()`.
- Подключён: статус «Подключён · @username», `Button label="Отключить" icon="pi pi-unlink"
  severity="danger" outlined` `:loading="telegramUnlinking"` → `unlinkTelegram()`.

### Email / WhatsApp (Ф2/Ф3)

Строки с `channel-row--disabled`, action-кнопка заменена на `Tag value="Скоро"
severity="secondary"`. Контент хардкоден как placeholder, не из API.

### Состояния

| Состояние | Поведение |
|-----------|-----------|
| loading | `Skeleton` три строки канала |
| Telegram linking | Button :loading |
| link success | Toast «Telegram подключён» + обновление строки |
| link error | Toast severity="error" |
| unlink confirm | ConfirmDialog «Отключить Telegram?» → unlinkTelegram() |

**Save-bar здесь нет.** Раздел полностью action-based.

---

## 9. Разделы Ф2/Ф3 (заглушки)

Для пунктов СПРАВОЧНИКИ и СИСТЕМА в Ф1 при попытке перейти → рендерится `SectionComingSoon`:

```vue
<template>
  <div class="coming-soon-block">
    <i class="pi pi-clock coming-soon-block__icon" />
    <p>{{ t('settings.comingSoon') }}</p>
  </div>
</template>
```

> **Ф2/Ф3 пункты не кликабельны** (disabled) — они никогда не станут активным разделом в
> SettingsDetail на Ф1. `SectionComingSoon` — запасной fallback на случай прямого deep-link.

---

## 10. Список редиректов (frontend только)

Все редиректы регистрируются в **`front/src/router/routes/base.ts`**. Формат:
`{ path: '<old>', redirect: (to) => '/settings?section=<key>' }`.

### `ProfilePage`-редиректы

| Старый путь | Новый URL |
|-------------|-----------|
| `/profile` | `/settings?section=profile` |
| `/profile?tab=profile` | `/settings?section=profile` |
| `/profile?tab=security` | `/settings?section=security` |
| `/profile?tab=appearance` | `/settings?section=appearance` |
| `/profile?tab=quickActions` | `/settings?section=appearance` (слито) |
| `/profile?tab=telegram` | `/settings?section=channels` |
| `/profile?tab=locale` | `/settings?section=language` |
| `/profile?tab=*` (прочие) | `/settings?section=profile` (дефолт) |

Реализация в router:
```ts
{
  path: '/profile',
  redirect: (to) => {
    const tab = to.query.tab as string | undefined
    const sectionMap: Record<string, string> = {
      profile: 'profile', security: 'security',
      appearance: 'appearance', quickActions: 'appearance',
      telegram: 'channels', locale: 'language',
    }
    const section = (tab && sectionMap[tab]) ?? 'profile'
    return { path: '/settings', query: { section } }
  },
},
```

### Admin-редиректы (Ф2/Ф3, регистрируем сразу — будут работать когда секции появятся)

| Старый путь | Новый URL (целевой, Ф2/Ф3) |
|-------------|---------------------------|
| `/admin/countries` | `/settings?section=countries` |
| `/admin/acquisition-channels` | `/settings?section=acq-channels` |
| `/admin/disconnect-reasons` | `/settings?section=disc-reasons` |
| `/admin/products` | `/settings?section=catalog` |
| `/admin/exchange-rates` | `/settings?section=exchange-rates` |
| `/settings/pipeline` | `/settings?section=pipeline-stg` |
| `/admin/templates` | `/settings?section=doc-templates` |
| `/admin/template-variables` | `/settings?section=tpl-variables` |
| `/admin/approval-routes` | `/settings?section=approval-routes` |
| `/admin/message-templates` | `/settings?section=msg-templates` |
| `/admin/users` | `/settings?section=users` |
| `/admin/access-control` | `/settings?section=access-control` |
| `/admin/access-control/departments` | `/settings?section=access-control` |
| `/admin/access-control/roles` | `/settings?section=access-control` |
| `/admin/access-control/visibility` | `/settings?section=access-control` |
| `/admin/automation-runs` | `/settings?section=automation-runs` |

> Замечание: эти 16 редиректов НЕ активируются сразу — на Ф1 соответствующие страницы
> продолжают работать как самостоятельные. Регистрируем редиректы в коде, но добавляем
> `meta: { phase: 2 }` и гейтируем через feature-flag или просто не активируем пока секции
> не готовы. Архитектурно — прописываем, чтобы Ф2 было trivial.

---

## 11. PrimeVue-компоненты

| Компонент | Где используется | Props |
|-----------|-----------------|-------|
| `PageHeader` | шелл — шапка страницы | `icon="pi pi-cog"`, `title` |
| `Button` | save/discard/actions | см. каждый раздел выше |
| `InputText` | профиль (ФИО/email/роль) | `:disabled` для read-only |
| `SelectButton` | тема (light/dark) | `v-model`, `optionLabel`, `optionValue` |
| `Tag` | статусы 2FA, «Скоро», telegram | `severity`, `value` |
| `Message` | orbit-hint, backup-codes success | `severity="info"/"success"`, `:closable="false"` |
| `ConfirmDialog` | dirty-guard, unlink telegram, wizard interrupt | глобальный, `useConfirm()` |
| `Toast` | save success/error | глобальный, `useToast()` |
| `Skeleton` | loading-states разделов | `height`, `border-radius` |
| `Select` | mobile nav (< 768px) | `v-model="activeSection"`, `optionValue`, `optionLabel` |
| `InfoPanel` | НЕ используется в шелле | (только в entity-картах) |

**НЕ используются:** DataTable, Tabs, Stepper, Drawer — в настройках не нужны.

---

## 12. i18n-ключи

### RU (обязательно)

```json
{
  "settings": {
    "pageTitle": "Настройки",

    "groups": {
      "account": "Аккаунт",
      "integrations": "Интеграции",
      "directories": "Справочники",
      "system": "Система"
    },

    "sections": {
      "profile":        { "title": "Профиль",             "desc": "Имя, фото, контактные данные" },
      "security":       { "title": "Безопасность",        "desc": "Двухфакторная аутентификация" },
      "appearance":     { "title": "Внешний вид",         "desc": "Тема, навигация, быстрые действия" },
      "language":       { "title": "Язык",                "desc": "Язык интерфейса" },
      "channels":       { "title": "Каналы",              "desc": "Telegram, Email, WhatsApp" },
      "countries":      { "title": "Страны",              "desc": "Справочник стран" },
      "acq-channels":   { "title": "Каналы привлечения",  "desc": "" },
      "disc-reasons":   { "title": "Причины отказа",      "desc": "" },
      "catalog":        { "title": "Каталог продуктов",   "desc": "" },
      "exchange-rates": { "title": "Курсы валют",         "desc": "" },
      "pipeline-stg":   { "title": "Воронка продаж",      "desc": "Этапы и настройки воронки" },
      "doc-templates":  { "title": "Шаблоны документов",  "desc": "" },
      "tpl-variables":  { "title": "Переменные шаблонов", "desc": "" },
      "approval-routes":{ "title": "Маршруты согласования","desc": "" },
      "msg-templates":  { "title": "Шаблоны сообщений",   "desc": "" },
      "users":          { "title": "Пользователи",        "desc": "Управление учётными записями" },
      "access-control": { "title": "Доступ и оргструктура","desc": "Отделы, роли, видимость" },
      "automation-runs":{ "title": "Журнал автоматизаций","desc": "" },
      "system-reset":   { "title": "Сброс системы",       "desc": "Только для администратора" }
    },

    "appearance": {
      "saved": "Настройки внешнего вида сохранены",
      "quickActionsOrbitHint": "Эти действия появятся в панели Орбиты",
      "quickActionsNonOrbitHint": "Быстрые действия доступны только в режиме навигации «Орбита»"
    },

    "language": {
      "saved": "Язык изменён",
      "hint": "Выберите язык интерфейса"
    },

    "channels": {
      "sectionTitle": "Подключённые каналы",
      "telegram": {
        "name": "Telegram",
        "connected": "Подключён",
        "notConnected": "Не подключён",
        "connectBtn": "Подключить",
        "disconnectBtn": "Отключить",
        "disconnectConfirm": "Отключить Telegram?",
        "connectSuccess": "Telegram подключён",
        "disconnectSuccess": "Telegram отключён"
      },
      "email": { "name": "Email",     "comingSoon": true },
      "whatsapp": { "name": "WhatsApp", "comingSoon": true }
    },

    "comingSoon": "Этот раздел находится в разработке",

    "dirtyGuard": {
      "title": "Несохранённые изменения",
      "message": "Есть несохранённые изменения. Покинуть раздел?",
      "stay": "Остаться",
      "leave": "Покинуть"
    },

    "save": "Сохранить",
    "discard": "Отменить"
  }
}
```

### EN (задел)

```json
{
  "settings": {
    "pageTitle": "Settings",
    "groups": {
      "account": "Account", "integrations": "Integrations",
      "directories": "Directories", "system": "System"
    },
    "sections": {
      "profile":    { "title": "Profile",    "desc": "Name, photo, contact details" },
      "security":   { "title": "Security",   "desc": "Two-factor authentication" },
      "appearance": { "title": "Appearance", "desc": "Theme, navigation, quick actions" },
      "language":   { "title": "Language",   "desc": "Interface language" },
      "channels":   { "title": "Channels",   "desc": "Telegram, Email, WhatsApp" }
    },
    "appearance": {
      "saved": "Appearance settings saved",
      "quickActionsOrbitHint": "These actions will appear in the Orbit panel",
      "quickActionsNonOrbitHint": "Quick actions are only available in Orbit navigation mode"
    },
    "channels": {
      "telegram": {
        "name": "Telegram", "connectBtn": "Connect", "disconnectBtn": "Disconnect",
        "connectSuccess": "Telegram connected", "disconnectSuccess": "Telegram disconnected"
      }
    },
    "dirtyGuard": {
      "title": "Unsaved changes", "message": "You have unsaved changes. Leave this section?",
      "stay": "Stay", "leave": "Leave"
    },
    "save": "Save", "discard": "Cancel"
  }
}
```

---

## 13. Interactions — таблица

| Элемент | Действие | Результат | Endpoint |
|---------|----------|-----------|----------|
| Пункт сайдбара (Ф1, не dirty) | click | `activeSection = key`, `?section=key` в URL | — |
| Пункт сайдбара (Ф1, dirty) | click | ConfirmDialog грязной охраны | — |
| «Сохранить» (профиль) | click | `PATCH /api/user` `{full_name}` → Toast success / error | `PATCH /api/user` |
| «Отменить» (профиль) | click | `fullNameDraft = user.full_name`, `markClean()` | — |
| Upload аватара | file change | `POST /api/user/avatar` → обновить `avatarPath` | `POST /api/user/avatar` |
| Удалить аватар | click | `DELETE /api/user/avatar` | `DELETE /api/user/avatar` |
| SelectButton тема | change | `themeStore.setTheme(draft)` (превью), `markDirty()` | — |
| nav-mode-card | click | `layoutStore.setNavMode(draft)` (превью), `markDirty()` | — |
| «Сохранить» (внешний вид) | click | `PATCH /api/user/preferences` `{theme, nav_mode}` → Toast, `markClean()` | `PATCH /api/user/preferences` |
| «Отменить» (внешний вид) | click | откат prevью, `markClean()` | — |
| locale-card | click | `setLocale(draft)` (превью), `markDirty()` | — |
| «Сохранить» (язык) | click | `PATCH /api/user` `{locale}` → Toast, `markClean()` | `PATCH /api/user` |
| «Настроить» быстрые действия | click | открыть `QuickActionsPickerDialog` | `PATCH /api/user` (внутри диалога) |
| «Включить 2FA» | click | `POST /api/user/2fa` → QR-шаг | `POST /api/user/2fa` |
| Подтвердить TOTP-код | click | `POST /api/user/2fa/verify` → backup-codes | `POST /api/user/2fa/verify` |
| «Отключить 2FA» | click | inline confirm-form | — |
| Confirm disable | click | `DELETE /api/user/2fa` | `DELETE /api/user/2fa` |
| «Перегенерировать коды» | click | inline confirm-form | — |
| Confirm regenerate | click | `POST /api/user/2fa/regenerate` | `POST /api/user/2fa/regenerate` |
| «Подключить Telegram» | click | `POST /api/user/telegram/link` (flow) | `POST /api/user/telegram/link` |
| «Отключить Telegram» | click | ConfirmDialog → `DELETE /api/user/telegram` | `DELETE /api/user/telegram` |
| Уход с раздела-формы (dirty) | route change / back | ConfirmDialog → «Покинуть» = discard + переход | — |
| Deep-link `/settings?section=security` | page load | `activeSection = 'security'` | — |
| `< 768px` Select | change | `activeSection = value` | — |

---

## 14. Состояния страницы целиком

| Состояние | Поведение |
|-----------|-----------|
| Страница loading | `Skeleton` в sidebar (5–6 строк) + `Skeleton` в detail (2 секции) |
| Нет разрешения на раздел (напр. admin-only) | пункт не рендерится в sidebar; прямой deep-link → редирект на `/settings?section=profile` |
| Пустой `?section=` или неизвестный ключ | дефолт `profile` |
| Ф2/Ф3 пункт disabled | pointer-events none, Tag «Скоро» |
| dark-тема | все классы через `--p-surface-*` и `$surface-*`; никаких hex-литералов |

---

## 15. Визуальный эталон

**Структура лейаута:** `./examples/vizion/front/src/pages/ProfilePage/` — паттерн
«tab-like single-page», отсюда берём способ организации composables и передачи состояния.

**Стиль строк сайдбара:** аналогичен `./examples/vizion/front/src/components/AppShell/AppSidebar.vue` —
nav-item паттерн (таблетки с иконкой, active-полоска слева).

**Стиль channel-row:** аналогичен `./examples/vizion/front/src/pages/SettingsPage/` (если
есть), иначе — строка в духе `settings-card` из текущего ProfilePage (`front/src/pages/ProfilePage/index.vue` ~L812).

---

## 16. Открытые вопросы

1. **`PATCH /api/user/preferences`** — эндпоинт для сохранения темы и nav_mode в бэкенд ещё
   не существует (сейчас тема/navMode хранится только в Pinia + localStorage через persist).
   **Требуется backend:** либо добавить поле `preferences` в `users` и эндпоинт, либо
   договориться что тема/navMode — только клиентские (тогда «Сохранить» пишет только в
   localStorage через store, без HTTP-запроса). **Вопрос к product-manager / backend.**

2. **Роутер: сохранять ли существующие страницы** (`/admin/products`, `/admin/users` и т.д.)
   активными или редиректить их в `/settings?section=…` уже на Ф1? Сейчас в ТЗ — редиректы
   только для `/profile`; admin-страницы продолжают работать как самостоятельные роуты до Ф2.
   Нужно подтверждение.

3. **Сброс системы (admin):** `SystemResetDialog` сейчас живёт в ProfilePage. Переселить в
   `SectionComingSoon` или в отдельную `SectionSystemReset`? На Ф1 предлагаю оставить на
   `/profile` → редирект на `/settings?section=system-reset` → `SectionComingSoon`; сам
   диалог переедет на Ф3.

4. **`QuickActionsPickerDialog`** открывается кнопкой «Настроить» и сохраняет напрямую в
   бэкенд, минуя черновик appearance. Это нарушает единую save-модель раздела. Предложение: на
   Ф1 оставить текущее поведение (диалог action-based) и пометить в ТЗ. На Ф2 интегрировать
   picker в черновик раздела (inline picker без диалога). Нужно решение PM.

5. **Минимальная ширина sidebar:** 240px или адаптироваться под контент? Если появятся длинные
   названия разделов (например, «Маршруты согласования») — может потребоваться 260px или
   `overflow: hidden + text-overflow: ellipsis` на пунктах. Решить при первом рендере Ф2.

---

## 17. Что вынесено в Ф2/Ф3

### Фаза 2 (СПРАВОЧНИКИ — admin/director)

Реализация контента для разделов: Страны, Каналы привлечения, Причины отказа, Каталог
продуктов, Курсы валют, Воронка продаж (перенести из PipelineSettingsPage), Шаблоны
документов, Переменные шаблонов, Маршруты согласования, Шаблоны сообщений.

Каждый раздел — перенос существующей standalone-страницы (`/admin/*`) в `SectionXxx.vue`
внутри шелла. Логика и API-вызовы не меняются — только обрамление.

### Фаза 3 (СИСТЕМА — admin/director)

Пользователи (перенос `UsersPage`), Доступ и оргструктура (перенос `AccessControlPage`),
Журнал автоматизаций (перенос `AutomationRunsPage`), Сброс системы (перенос
`SystemResetDialog` из ProfilePage).

### Email / WhatsApp каналы

Строки-заглушки в `SectionChannels.vue` уже есть в Ф1. Активация — отдельная задача
по готовности backend-интеграции.

---

---

## Статус реализации

### Фаза 1 — РЕАЛИЗОВАНА (2026-06-29, незакоммичено)

**Реализовано:**
- Шелл `SettingsPage` (index.vue): master-detail layout, мобильный Select (<768px), PageHeader.
- `SettingsSidebar.vue`: все 4 группы + полная таксономия (Ф1 кликабельны, Ф2/Ф3 disabled с тегом «Скоро»); группы СПРАВОЧНИКИ/СИСТЕМА скрыты для не-admin/director через `v-if`.
- `useSettings.ts`: activeSection, deep-link sync (?section=), provide/inject dirty-ключей как no-op заглушки.
- 5 разделов Ф1: `SectionProfile.vue`, `SectionSecurity.vue`, `SectionAppearance.vue`, `SectionLanguage.vue`, `SectionChannels.vue`.
- `SectionComingSoon.vue`: fallback-заглушка.
- Редиректы `/profile?tab=*` → `/settings?section=*` зарегистрированы в `base.ts`; `/admin/*` продолжают работать как самостоятельные роуты до Ф2.
- Save-bar (Сохранить / Отменить) на форм-разделах: Profile, Appearance, Language.
- Preview-save для темы и navMode (немедленное применение, commit по «Сохранить»).
- Quick-actions интегрированы в Appearance как draft: dialog переведён в `draftMode=true`, сохранение только при «Сохранить» раздела.
- i18n: `settings.*` блок полностью заполнен в ru.json и en.json.
- ProfilePage оставлен как redirect-shim (не удалён — Ф2).

**Осознанно отложено (было):**
- `confirm-on-leave` (navigation-guard) — вынесен отдельной задачей; `markDirty`/`markClean` на Ф1 были no-op заглушками.

**Dirty-guard — РЕАЛИЗОВАН (2026-06-30, QA PASS 5 сценариев, PM APPROVED):**
- Причина phantom'а на Ф1: PrimeVue `ConfirmService` держит глобальное реактивное состояние — единственный `<ConfirmDialog>` переотрисовывался в новой destination во время async-навигации. Ни удаление дублей, ни `appendTo="body"` не помогали.
- Решение: кастомный `UnsavedChangesDialog.vue` (PrimeVue `<Dialog>`, НЕ `useConfirm`/`ConfirmService`) + Promise-based guard в `useSettings.ts`.
- `markDirty()`/`markClean()` восстановлены как реальные сеттеры `isDirty.value` (были no-op).
- Единственный `onBeforeRouteLeave` в `useSettings.ts` — return-форма (без `next()`), ловит уход со страницы `/settings` целиком.
- Смена раздела внутри `/settings` перехватывается `setSection()` через `askUserToConfirmLeave()` (Promise); `router.replace()` не триггерит `onBeforeRouteLeave` (та же страница).
- `dialogVisible` закрывается явно ДО `resolve()` в `onDialogLeave`/`onDialogStay` — исключает re-trigger guard при `router.replace`.
- `onDialogLeave` дополнительно сбрасывает `isDirty.value = false` до резолва — `setSection` не видит грязного состояния при замене URL.
- `UnsavedChangesDialog.vue` монтируется единожды в `SettingsPage/index.vue` (`v-model:visible` + `@leave`/`@stay`).
- i18n: `settings.dirtyGuard.{title,message,stay,leave}` добавлены в ru.json + en.json.
- QA PASS: внешняя навигация (Покинуть/Остаться) + смена раздела (Покинуть/Остаться) + нет-dirty (нет диалога) + обе темы + DOM-счётчик ровно 1 диалог на вызов.

### Фаза 2 — РЕАЛИЗОВАНА (2026-06-29, незакоммичено)

**Реализовано:**
- `SectionDirectories.vue`: горизонтальные табы (PrimeVue Tabs, line-style), v-if lazy-mount, роль-гейт внутри компонента (`!isAdminOrDirector` → access-denied).
- 5 DirTab-обёрток: `DirTabCountries.vue`, `DirTabAcqChannels.vue`, `DirTabDiscReasons.vue`, `DirTabCatalog.vue`, `DirTabExchangeRates.vue` — каждая с sub-toolbar (кнопки действий) + `<PageXxx :embedded="true">`.
- Существующие страницы (CountriesPage, AcquisitionChannelsPage, DisconnectReasonsPage, ProductsPage, ExchangeRatesPage) получили проп `embedded?: boolean` (`v-if="!embedded"` на PageHeader + Toast + ConfirmDialog) + `defineExpose` экшенов. CRUD-логика не тронута.
- `useSettings.ts`: расширен `VALID_KEYS` до 10 (5 Ф1 + 5 Ф2), добавлен экспорт `DIRECTORIES_KEYS`, роль-проверка в `resolveSection` (директ-линк от non-admin → 'profile').
- `SettingsSidebar.vue`: 5 пунктов справочников переведены с `phase: 2` → `phase: 1`; остальные (pipeline-stg, doc-templates и т.д.) — `phase: 2`.
- `SettingsPage/index.vue`: ветвь `v-else-if` для `SectionDirectories` + расширен `mobileSectionOptions` (admin/director-only).
- Редиректы Ф2 активированы в `base.ts`: `/admin/countries`, `/admin/acquisition-channels`, `/admin/disconnect-reasons`, `/admin/products` → `/settings?section=*`. `/admin/products/:id` (ProductDetail) сохранён как самостоятельный роут.
- i18n: `settings.directories.*` заполнен в ru.json и en.json (sectionTitle, sectionDesc, tabs × 5).

**Прочие пункты группы** (PipelineStg) — остаётся `phase: 2` (link-out на standalone /settings/pipeline). DocTemplates, TplVariables, ApprovalRoutes, MsgTemplates — активированы в Ф4 (см. ниже).

### Фаза 3 — РЕАЛИЗОВАНА (2026-06-30, QA PASS, PM APPROVED)
СИСТЕМА: 4 раздела активированы в шелле.

- `SysTabUsers.vue` — wraps `UsersPage` (embedded=true), sub-toolbar «+ Добавить пользователя» через `defineExpose`.
- `SysTabAccessControl.vue` — wraps `AccessControlPage` (embedded=true); internal tab state (`internalTab`) для изоляции URL-синка (ОВ-1 закрыт).
- `SysTabAutomationRuns.vue` — wraps `AutomationRunsPage` (embedded=true).
- `SectionSystemReset.vue` — action-based, реиспользует `useSystemReset` + `SystemResetDialog`; роль-гейт `v-if="!isAdmin"` + `resolveSection` admin-only guard.
- Toast-дубли устранены: `<Toast v-if="!embedded" />` в DepartmentsTab / RolesPermissionsTab / VisibilityScopeTab / AutomationRunsPage; `<Toast>` удалён из `SettingsPage/index.vue`.
- Редиректы активированы: `/admin/users`, `/admin/access-control/*`, `/admin/automation-runs` → `/settings?section=*`.
- `PipelineSettingsPage` остаётся standalone (`/settings/pipeline`), `pipeline-stg` — `phase: 2`.
- Открытые вопросы Ф3 закрыты: ОВ-1 (internalTab), ОВ-2 (Toast/v-if), ОВ-3 (редиректы активированы), ОВ-4 (director→'profile'), ОВ-5 (маппинг `/profile?tab=system → 'profile'` — обновление до `'system-reset'` отложено, ненагруженный маршрут).

**Незакрытые хвосты (некритичные, из Ф3):**
- `/profile?tab=system` маппинг: `base.ts` строка 132 возвращает `'profile'`, по ТЗ должен вернуть `'system-reset'` (только admin через `resolveSection`). Обновить при следующем проходе.
- Dark-токен `var(--p-surface-900)` в `SectionSystemReset.vue` (строки 108, 181) — должен быть `var(--p-surface-50)` как в Ф2 `SectionDirectories.vue`; сейчас заголовки тёмные на тёмном фоне. Исправить при следующем DS-проходе.
- `rgba(var(--p-red-500-rgb, 239 68 68), 0.08)` — fallback hex-числа; `--p-red-500-rgb` не определён в теме; предпочтительно `rgba($red-500, 0.08)` через SCSS-переменную.

### Фаза 4 — РЕАЛИЗОВАНА (2026-06-30, QA PASS, PM APPROVED, незакоммичено)

**Что сделано:**
- **4 новых DirTab-обёртки** (паттерн Ф2): `DirTabDocTemplates.vue`, `DirTabTplVariables.vue`, `DirTabApprovalRoutes.vue`, `DirTabMsgTemplates.vue` — каждая с sub-toolbar (кнопка «+ Добавить» через `pageRef?.openDrawer/openCreate`) и встроенной страницей `embedded=true`.
- **4 standalone-страницы обновлены** (TemplatesPage, TemplateVariablesPage, ApprovalRoutesPage, MessageTemplatesPage): добавлен проп `embedded?: boolean`, `v-if="!embedded"` на PageHeader + `defineExpose` экшенов. CRUD-логика не тронута.
- **Пер-итемная роль-логика СПРАВОЧНИКОВ**: `DOCUMENTS_KEYS` + `DOCUMENT_SECTION_ROLES` в `useSettings.ts`; `resolveSection` фаллбэкит на `'profile'` при чужом deep-link; `SettingsSidebar.vue` — группа `directories.adminOnly:false`, per-item `roles`, группа рендерится если есть хотя бы один доступный пункт; `SectionDirectories.vue` — `canSee*` computed + `hasAnyAccess`.
- **Роли по пунктам:** `doc-templates`/`tpl-variables` = admin+lawyer+director; `approval-routes` = admin+lawyer; `msg-templates` = admin+lawyer+director+manager; `pipeline-stg` = admin+director (link-out).
- **Воронка продаж (link-out):** `pipeline-stg` переведён с `phase:2` в `phase:1` с `linkOut:'/settings/pipeline'`; `SettingsSidebar.vue` emit `linkOut`, `index.vue` слушает `@link-out="settings.navigateOutOf"`.
- **Дубль automation-runs убран из AppSidebar:** `navItems.ts` — `automationNavGroup.items` очищен (пустой экспорт); блок группы из `AppSidebar.vue` удалён.
- **Редиректы активированы** в `base.ts`: `/admin/templates` → `?section=doc-templates`; `/admin/template-variables` → `?section=tpl-variables`; `/admin/approval-routes` → `?section=approval-routes`; `/admin/message-templates` → `?section=msg-templates`. `/admin/templates/:id` (TemplateDetail) сохранён как самостоятельный роут.
- **Dirty-guard regression fix:** `navigateOutOf` проверяет dirty явно + сбрасывает `isDirty=false` до `router.push`; `UnsavedChangesDialog.vue` — `:pt` instant-leave; `base.scss` — 2 правила 0ms только для `unsaved-dialog--instant-leave` и `unsaved-dialog-mask`.
- **i18n:** `settings.directories.tabs.{docTemplates,tplVariables,approvalRoutes,msgTemplates}` в ru.json + en.json.

**Незакоммичено — ждёт коммита от main.**

*ТЗ для `frontend-specialist` готово. Ф1+Ф2+Ф3+Ф4 реализованы. Весь срез Настроек завершён.*

### Фаза 5 — Профиль 2.0 — РЕАЛИЗОВАНА (2026-06-30, QA PASS, PM APPROVED, незакоммичено)

**Что сделано:**
- **Reorg АККАУНТ (ОВ-3):** 4 пункта (Профиль/Безопасность/Внешний вид/Язык) схлопнуты в 1 пункт «Профиль» в `SettingsSidebar.vue`; активность пункта через `isSectionActive()` (проверяет `isProfileSection()`). Существующие Section* подключены в `SectionProfileTabs.vue` через `v-if` (не TabPanels — lazy-mount).
- **`SectionProfileTabs.vue`** (NEW): PrimeVue Tabs (line-underline, паттерн Ф2), 4 под-вкладки (`profile`/`security`/`appearance`/`language`), emit `tab-change` → `setSection()`. Данные пробрасываются из `index.vue` через props (без дублирования composable).
- **`AvatarCropModal.vue`** (NEW): `vue-advanced-cropper` (явно запрошен юзером) + `CircleStencil` 1:1; клиентский downscale ≤1024px + quality-retry (0.85→0.7 при >2МБ); `URL.revokeObjectURL` при закрытии (watch + onUnmounted); `POST /api/profile/avatar`; валидация типа/размера до кропа в родителе (`SectionProfile.vue`).
- **`ChangePasswordForm.vue`** (NEW): action-based (dirty-guard не задет); PrimeVue `Password` + toggle-mask; клиентская валидация (minlength 8, match); 422 `current_password` → inline error под полем; `POST /api/me/password`; success → clear fields + Toast.
- **`SectionSecurity.vue`**: добавлена секция «Смена пароля» с `<ChangePasswordForm />`; dark-fix заголовка `profile-section__title`: `var(--p-surface-50)` → `var(--p-surface-900)` (инвертированная шкала Aura — near-white в dark).
- **`SectionProfile.vue`**: интеграция кроп-флоу (`onAvatarFileSelected` → objectURL → `AvatarCropModal`); файловый input сбрасывается на `target.value = ''`.
- **`useSettings.ts`**: `PROFILE_TAB_KEYS`, `isProfileSection()`, `ProfileTabKey` type — без изменения существующей логики deep-link/dirty-guard.
- **`profile.ts`**: добавлен `changePassword(ChangePasswordRequest)` → `POST /api/me/password`.
- **i18n**: `settings.profile.tabs.*`, `settings.profile.avatarCrop.*`, `settings.security.password.*` в ru.json + en.json.

**Незакоммичено — ждёт коммита от main.**

---

---

## Фаза 2 — Справочники (ТЗ для `frontend-specialist`)

> Автор: designer · Дата: 2026-06-29

### Зачем

Активировать группу СПРАВОЧНИКИ в шелле `/settings`: переселить 5 существующих
standalone-страниц (`/admin/*`) под единый `SectionDirectories.vue` со вложенными
под-вкладками. Логика, API-вызовы, диалоги/дроверы — **не трогаем, переиспользуем 1-в-1**.
Только убираем дублирующий `<PageHeader>` из каждой страницы и обёртываем их в таб-навигацию.

**User story:** «Я открываю `/settings?section=countries` и вижу справочник стран прямо
внутри Настроек, переключаюсь на "Каталог" одним кликом по табу, не уходя со страницы.»

---

### Где в коде (новые файлы)

```
front/src/pages/SettingsPage/
  components/
    sections/
      SectionDirectories.vue          ← НОВЫЙ: таб-контейнер (5 под-вкладок)
      directories/
        DirTabCountries.vue           ← НОВЫЙ: wraps CountriesPage без PageHeader
        DirTabAcqChannels.vue         ← НОВЫЙ: wraps AcquisitionChannelsPage без PageHeader
        DirTabDiscReasons.vue         ← НОВЫЙ: wraps DisconnectReasonsPage без PageHeader
        DirTabCatalog.vue             ← НОВЫЙ: wraps ProductsPage без PageHeader
        DirTabExchangeRates.vue       ← НОВЫЙ: wraps ExchangeRatesPage без PageHeader
```

**Существующие страницы (`CountriesPage`, `AcquisitionChannelsPage`, `DisconnectReasonsPage`,
`ProductsPage`, `ExchangeRatesPage`) — НЕ изменяем.** Они продолжают работать как автономные
роуты `/admin/*` до активации редиректов Ф2.

---

### Wireframe (ASCII)

```
┌──────────────────────────────────────────────────────────────────────────────┐
│  PageHeader «Настройки»  [pi pi-cog]              (шелл, неизменён)          │
│  ───────────────────────────────────────────────────────────────────────────  │
├──────────────────┬───────────────────────────────────────────────────────────┤
│  SIDEBAR ~240px  │  DETAIL (flex:1, overflow-y:auto)                         │
│                  │                                                           │
│  АККАУНТ         │  ┌─────────────────────────────────────────────────────┐  │
│  • Профиль       │  │  «Справочники»  sub-header  (dir-section__header)   │  │
│  • Безопасность  │  │  ─────────────────────────────────────────────────  │  │
│  • Внешний вид   │  │  [Страны] [Каналы привлечения] [Причины] [Каталог]  │  │
│  • Язык          │  │  [Курсы валют]                                       │  │
│                  │  │  ← горизонтальные табы PrimeVue Tabs line-style     │  │
│  ИНТЕГРАЦИИ      │  │  ─────────────────────────────────────────────────  │  │
│  • Каналы        │  │                                                     │  │
│                  │  │  [sub-toolbar: кнопки действий активного таба]      │  │
│  СПРАВОЧНИКИ     │  │  ─────────────────────────────────────────────────  │  │
│  ►Справочники ←──│  │                                                     │  │
│                  │  │  ┌───────────────────────────────────────────────┐  │  │
│  СИСТЕМА (Ф3)    │  │  │  <DirTabCountries> / <DirTabCatalog> / …     │  │  │
│  • … disabled    │  │  │  DataTable + диалоги/дроверы страницы        │  │  │
│                  │  │  └───────────────────────────────────────────────┘  │  │
└──────────────────┴───────────────────────────────────────────────────────────┘
```

---

### Решение 1: навигация внутри detail — горизонтальные табы vs. вторичный список

**Выбор: горизонтальные табы (PrimeVue `Tabs` с `value` + `TabList` + `Tab`), стиль
`line` (underline).**

Обоснование:
- Количество под-вкладок небольшое (5), всё помещается в одну строку на десктопе без
  прокрутки.
- Пользователь ориентируется «где я сейчас» — underline-индикатор даёт немедленную обратную
  связь.
- Вторичный sidebar-список создал бы трёхуровневую иерархию (основной sidebar →
  вторичный список → контент) — это избыточно для 5 пунктов.
- PrimeVue `Tabs` уже подключён в стеке, переиспользуем без новых зависимостей.

Таб-бар — **не** стандартный PrimeVue card-tabs (boxed). Используем вариант `line` через
CSS-модификатор `.dir-tabs` (см. §CSS ниже). 14px, активный — 600 вес + navy underline.

---

### Решение 2: deep-link схема

**Схема: `?section=countries`, `?section=catalog`, `?section=exchange-rates` и т.д.**
(каждая под-вкладка — отдельное значение `?section=`, без второго query-параметра).

Обоснование:
- Ф1 уже резервирует ключи `countries`, `acq-channels`, `disc-reasons`, `catalog`,
  `exchange-rates` в таксономии `SettingsSidebar.vue` как отдельные `section.key`.
- Добавление параметра `?sub=` усложняет `useSettings.ts` и логику мобильного Select.
- Пользователь может поделиться ссылкой `/settings?section=catalog` — она работает
  сразу без дополнительных параметров.
- Сайдбар в Ф2 отображает каждую из 5 под-вкладок как отдельный пункт (они уже есть
  в GROUPS disabled). После активации — пункт кликабелен и ведёт напрямую.

Альтернатива `?section=directories&sub=countries` отклонена: она потребовала бы
рефакторинга `useSettings.ts` и Sidebar, тогда как текущая схема работает «из коробки»
после одного изменения — переключения `phase: 2` → `phase: 1` для 5 пунктов.

---

### Решение 3: как убрать двойной PageHeader

Каждая существующая standalone-страница (`CountriesPage`, `ProductsPage` и т.д.) рендерит
свой `<PageHeader>` в верхней части шаблона. Шелл `SettingsPage` уже имеет PageHeader
«Настройки». Встраивание напрямую даст двойной заголовок.

**Решение: DirTab-обёртки скрывают PageHeader страницы через CSS prop `headless`.**

Вместо модификации исходных компонентов страниц каждый `DirTabXxx.vue` монтирует
вложенную страницу через слот/импорт, а родительский `SectionDirectories.vue` передаёт
`headless` CSS-класс родителю, который скрывает первый `.p-card` или `PageHeader`:

```vue
<!-- DirTabCountries.vue -->
<template>
  <div class="dir-tab-body">
    <CountriesPage />
  </div>
</template>

<style lang="scss" scoped>
.dir-tab-body {
  // Скрываем встроенный PageHeader страницы
  :deep(.countries-page > .page-header),
  :deep(.countries-page > [class*="page-header"]) {
    display: none;
  }
  // Убираем внешний padding страницы (шелл даёт свой)
  :deep(.countries-page) {
    padding: 0;
    margin: 0;
  }
}
</style>
```

> **Замечание:** классы `.page-header` и `.countries-page` (и аналоги для других страниц)
> задокументированы в коде. Если PageHeader рендерится не как прямой дочерний элемент
> корневого `.xxx-page`, а глубже — уточняется при реализации (см. открытые вопросы §ОВ-1).

Действия, которые PageHeader страницы содержал (кнопки «Добавить», «Обновить» и т.д.),
переносятся в `sub-toolbar` внутри `SectionDirectories.vue` через slot-механизм или
напрямую (см. §Sub-toolbar ниже).

---

### Структура `SectionDirectories.vue`

```
SectionDirectories.vue
  ├── .dir-section (display: flex, flex-direction: column, height: 100%)
  │   ├── .dir-section__header  (sub-заголовок + описание раздела)
  │   ├── .dir-tabs  (PrimeVue Tabs value="activeTab")
  │   │     ├── TabList
  │   │     │    ├── Tab value="countries"     «Страны»
  │   │     │    ├── Tab value="acq-channels"  «Каналы привлечения»
  │   │     │    ├── Tab value="disc-reasons"  «Причины отказа»
  │   │     │    ├── Tab value="catalog"       «Каталог»
  │   │     │    └── Tab value="exchange-rates" «Курсы валют»
  │   └── .dir-tab-content  (TabPanels — НЕ используем, рендерим напрямую)
  │         └── <DirTabCountries v-if="activeTab==='countries'" />
  │             <DirTabAcqChannels v-else-if="activeTab==='acq-channels'" />
  │             …
```

> **Рендеринг:** используем `v-if`/`v-else-if` для условного рендеринга вкладок, а НЕ
> PrimeVue `TabPanels` с несколькими `TabPanel`. Причина: каждая страница-вкладка
> содержит свой `onMounted` с `load()` — `v-if` гарантирует, что загрузка стартует
> только при первом показе вкладки, не заранее для всех 5.

---

### Интеграция с `useSettings.ts` и Sidebar

**Принципиальное изменение в `useSettings.ts`:**

```ts
// Расширяем PHASE1_KEYS, добавляя 5 новых ключей Ф2
const VALID_KEYS = [
  'profile', 'security', 'appearance', 'language', 'channels',
  'countries', 'acq-channels', 'disc-reasons', 'catalog', 'exchange-rates',
] as const
```

`resolveSection()` теперь принимает все 10 ключей. Ключи Ф2 ведут на
`SectionDirectories.vue` — он сам читает `activeSection` и устанавливает активный таб.

**Изменение в `SettingsSidebar.vue`:**

Для 5 пунктов справочников меняем `phase: 2` → `phase: 1`. Больше ничего. Класс
`settings-nav-item--disabled` убирается автоматически, тег «Скоро» пропадает.

```ts
// SettingsSidebar.vue — GROUPS, секция directories:
{ key: 'countries',       ..., phase: 1 },  // было phase: 2
{ key: 'acq-channels',    ..., phase: 1 },  // было phase: 2
{ key: 'disc-reasons',    ..., phase: 1 },  // было phase: 2
{ key: 'catalog',         ..., phase: 1 },  // было phase: 2
{ key: 'exchange-rates',  ..., phase: 1 },  // было phase: 2
// Остальные пункты группы (pipeline-stg, doc-templates, …) остаются phase: 2
```

**Изменение в `index.vue` (SettingsPage):**

Добавляем 5 ветвей `v-else-if` для рендеринга `SectionDirectories.vue`:

```vue
<SectionDirectories
  v-else-if="isDirectoriesSection(settings.activeSection.value)"
  :active-tab="settings.activeSection.value"
  @tab-change="settings.setSection($event)"
/>
```

Хелпер:
```ts
const DIRECTORIES_KEYS = ['countries', 'acq-channels', 'disc-reasons', 'catalog', 'exchange-rates']
function isDirectoriesSection(key: string) {
  return DIRECTORIES_KEYS.includes(key)
}
```

**Мобильный Select** в `index.vue` расширяется — добавляем 5 новых опций в
`mobileSectionOptions`.

---

### Sub-toolbar (кнопки действий таба)

Каждая DirTab-обёртка предоставляет слот `#toolbar` — кнопки, которые ранее были в
PageHeader страницы. `SectionDirectories.vue` рендерит этот слот в
`.dir-section__sub-toolbar`.

**Примеры:**

| Таб | Кнопки в sub-toolbar |
|-----|----------------------|
| Страны | `[+ Добавить страну]` (только canManage) |
| Каналы привлечения | `[+ Добавить канал]` (только canManage) |
| Причины отказа | `[+ Добавить причину]` (только canManage) |
| Каталог | `[pi pi-upload Импортировать ▼]` `[+ Создать]` (только canWrite) |
| Курсы валют | `[pi pi-refresh Обновить]` `[+ Добавить вручную]` (только canWrite) |

Реализация: DirTabXxx.vue через `defineExpose` или slot раскрывает ссылки на функции
`openCreate`, `openImportDialog` и т.д. из composable. Либо проще — DirTabXxx.vue
содержит как кнопки (в шаблоне), так и контент. `SectionDirectories.vue` не знает о
деталях каждого таба — кнопки — это ответственность самого DirTabXxx.

**Упрощённый вариант** (рекомендован): каждый `DirTabXxx.vue` — полноценный компонент
с собственным toolbar-рядом внутри (не через слот в SectionDirectories). Это минимальный
рефакторинг.

```
DirTabCatalog.vue
  ├── .dir-tab-toolbar  (display: flex, gap: $space-3, padding: $space-3 $space-4,
  │                       border-bottom: 1px solid $surface-200, justify-content: space-between)
  │   ├── .dir-tab-toolbar__filters  (IconField search + Select group + Select type + …)
  │   └── .dir-tab-toolbar__actions  (Button «Импорт» + Button «Создать»)
  └── .dir-tab-body
      └── <DataTable …> (тело ProductsPage без PageHeader и внешних padding)
```

---

### CSS — стиль табов

```scss
// SectionDirectories.vue <style scoped>

.dir-section {
  display: flex;
  flex-direction: column;
  height: 100%;
  overflow: hidden;
}

.dir-section__header {
  padding: $space-4 $space-6 $space-3;
  border-bottom: 1px solid $surface-200;
  background: $surface-card;

  .app-dark & {
    background: var(--p-surface-800);
    border-bottom-color: var(--p-surface-700);
  }
}

.dir-section__title {
  font-size: $font-size-lg;
  font-weight: $font-weight-semibold;
  color: $surface-900;
  margin: 0 0 $space-1;

  .app-dark & { color: var(--p-surface-50); }
}

.dir-section__desc {
  font-size: $font-size-sm;
  color: $surface-500;
  margin: 0;

  .app-dark & { color: var(--p-surface-400); }
}

// Таб-бар line-style поверх дефолтного PrimeVue Tabs
.dir-tabs {
  flex-shrink: 0;

  // Убираем border-bottom у TabList, рисуем свой separator через content
  :deep(.p-tablist) {
    padding: 0 $space-6;
    background: $surface-card;
    border-bottom: 1px solid $surface-200;

    .app-dark & {
      background: var(--p-surface-800);
      border-bottom-color: var(--p-surface-700);
    }
  }

  :deep(.p-tab) {
    font-size: $font-size-sm;
    font-weight: $font-weight-medium;
    padding: $space-3 $space-4;
    color: $surface-600;
    border-bottom: 2px solid transparent;
    transition: color var(--app-transition-fast), border-color var(--app-transition-fast);
    cursor: pointer;
    white-space: nowrap;

    &:hover {
      color: $surface-900;
    }

    .app-dark & {
      color: var(--p-surface-400);
      &:hover { color: var(--p-surface-100); }
    }
  }

  :deep(.p-tab[data-p-active="true"]),
  :deep(.p-tab.p-tab-active) {
    color: $primary-900;
    font-weight: $font-weight-semibold;
    border-bottom-color: $primary-900;

    .app-dark & {
      color: var(--p-primary-200);
      border-bottom-color: var(--p-primary-200);
    }
  }

  // Скрываем стандартный active-indicator PrimeVue (он поверх нашего)
  :deep(.p-tablist-active-bar) {
    display: none;
  }
}

.dir-tab-content {
  flex: 1;
  overflow-y: auto;
  background: $surface-50;

  .app-dark & { background: var(--p-surface-900); }
}
```

---

### Wireframe детально — SectionDirectories с табом «Каталог»

```
┌─────────────────────────────────────────────────────────────────────────────┐
│  «Справочники»                           dir-section__header                │
│  Управление системными справочниками                                        │
├─────────────────────────────────────────────────────────────────────────────┤
│  [Страны] [Каналы привлечения] [Причины отказа] [Каталог▪] [Курсы валют]  │
│  ─────────────────────────────────────────────────────────────────────────  │
├─────────────────────────────────────────────────────────────────────────────┤
│  .dir-tab-toolbar                                                           │
│  [🔍 Поиск...]  [Группа ▼]  [Тип цены ▼]  [Статус ▼]  [Сбросить ×]       │
│                                        [Импортировать ▼] [+ Создать]        │
├─────────────────────────────────────────────────────────────────────────────┤
│  DataTable (ProductsPage body без PageHeader)                               │
│  ┌──┬─────────┬──────────────┬──────┬───────┬─────┬─────┬─────┬────┬────┐ │
│  │  │ Код     │ Название     │Группа│ Тип   │ KZT │ RUB │ USD │    │    │ │
│  ├──┼─────────┼──────────────┼──────┼───────┼─────┼─────┼─────┼────┼────┤ │
│  │▶ │ PROD-01 │ Услуга А     │ …    │ fixed │ …   │ …   │ …   │ ⚡ │ ⋮  │ │
│  └──┴─────────┴──────────────┴──────┴───────┴─────┴─────┴─────┴────┴────┘ │
│                                                              Paginator      │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

### Редиректы Ф2 (активировать в `base.ts`)

В Ф1 эти редиректы закомментированы / отмечены `meta: { phase: 2 }`. В Ф2 —
**раскомментировать / активировать**:

| Старый путь | Новый URL |
|-------------|-----------|
| `/admin/countries` | `/settings?section=countries` |
| `/admin/acquisition-channels` | `/settings?section=acq-channels` |
| `/admin/disconnect-reasons` | `/settings?section=disc-reasons` |
| `/admin/products` | `/settings?section=catalog` |
| `/admin/exchange-rates` | `/settings?section=exchange-rates` |

> Замечание: пункты, которые остаются `phase: 2` (pipeline-stg, doc-templates и т.д.),
> продолжают работать как самостоятельные роуты до готовности их Section-компонентов.

**Detail-роут товаров `/admin/products/:id`:**

Роут `/admin/products/:id` (`ProductDetail`) **сохраняется как самостоятельный роут** —
он не редиректится в settings и не встраивается в шелл. Ссылки из DataTable каталога
(`<router-link :to="'/admin/products/' + id">`) продолжают открывать `ProductPage`
на весь экран. Это корректное поведение: карточка товара — детальная страница сущности,
не список-справочник. После активации редиректа `/admin/products` → settings ссылка на
деталь товара остаётся `/admin/products/:id` — она не затронута.

---

### Состояния компонентов

| Состояние | Где | Поведение |
|-----------|-----|-----------|
| loading | каждый DirTab | страница-вкладка сама управляет loading (Skeleton/Spinner в DataTable, унаследован от исходной страницы) |
| empty | каждый DirTab | empty-state исходной страницы (иконка + текст + CTA), без изменений |
| error | каждый DirTab | Toast из исходного composable, без изменений |
| первый рендер таба | v-if mount | `onMounted → load()` вызывается при первом показе вкладки |
| переключение таба | Tab click | `activeTab = newKey` + `setSection(newKey)` → URL обновляется |
| deep-link `?section=catalog` | page load | `useSettings.resolveSection` распознаёт ключ → `SectionDirectories` получает `activeTab="catalog"` |
| мобильный Select | < 768px | Select включает 5 новых опций; выбор ведёт напрямую на нужный таб |

---

### Роль-гейтинг

Секция СПРАВОЧНИКИ уже скрыта в сайдбаре для не-admin/director через `v-if` (Ф1).
`SectionDirectories.vue` добавляет дополнительную проверку в шаблоне:

```vue
<div v-if="!isAdminOrDirector" class="coming-soon-block">
  <i class="pi pi-lock" />
  <p>{{ t('common.accessDenied') }}</p>
</div>
<template v-else>
  <!-- tabs + content -->
</template>
```

Прямой deep-link `/settings?section=countries` от non-admin → `resolveSection` вернёт
`'profile'` (дефолт) — для этого нужно добавить роль-проверку в `resolveSection`:

```ts
function resolveSection(key: string | undefined, isAdminOrDirector: boolean): string {
  if (!key) return 'profile'
  if (DIRECTORIES_KEYS.includes(key) && !isAdminOrDirector) return 'profile'
  if (VALID_KEYS.includes(key as ValidKey)) return key
  return 'profile'
}
```

---

### PrimeVue-компоненты Ф2

| Компонент | Где | Props |
|-----------|-----|-------|
| `Tabs` | `SectionDirectories.vue` — таб-бар | `v-model:value="activeTab"` |
| `TabList` | внутри `Tabs` | — |
| `Tab` | 5 штук, по одному на вкладку | `value="countries"` и т.д., `pt:root={{ … }}` не нужен |
| `Button` | sub-toolbar каждого DirTab | severity, icon, label — как в исходной странице |
| `Menu` | `DirTabCatalog` (import-menu, row-menus) | popup — как в ProductsPage |
| `DataTable`, `Column`, `Paginator` | все вкладки | без изменений — унаследованы |
| `ToggleSwitch` | Страны, Каналы, Причины | без изменений |
| `Tag` | ExchangeRates (source manual/api) | без изменений |
| `DatePicker` | ExchangeRates (фильтры) | без изменений |
| `ExchangeRateAgeWarning` | DirTabExchangeRates | внутри таба, как в исходной странице |
| `Toast`, `ConfirmDialog` | все вкладки | уже глобальные в шелле |

**Toast и ConfirmDialog:** исходные страницы рендерят `<Toast>` и `<ConfirmDialog>` внутри
своего шаблона. При встраивании в шелл они будут дублироваться (шелл тоже рендерит
`<Toast>`). Решение: в DirTab-обёртках убираем `<Toast>` и `<ConfirmDialog>` через `:deep`
скрытие или — предпочтительнее — используем глобальный синглтон из шелла (PrimeVue
регистрирует `Toast`/`ConfirmDialog` как глобальные сервисы, но рендерит тот, что ближе
в DOM). Безопаснее всего — скрыть дублирующие через CSS в DirTab-обёртке:

```scss
.dir-tab-body {
  :deep(.p-toast),
  :deep(.p-confirmdialog) {
    display: none; // используем глобальные из шелла
  }
}
```

---

### i18n-ключи Ф2

Добавить в `ru.json` и `en.json` в блок `settings.directories`:

```json
// ru.json
"settings": {
  "directories": {
    "sectionTitle": "Справочники",
    "sectionDesc": "Управление системными справочниками",
    "tabs": {
      "countries":      "Страны",
      "acqChannels":    "Каналы привлечения",
      "discReasons":    "Причины отказа",
      "catalog":        "Каталог",
      "exchangeRates":  "Курсы валют"
    }
  }
}

// en.json
"settings": {
  "directories": {
    "sectionTitle": "Directories",
    "sectionDesc": "Manage system directories",
    "tabs": {
      "countries":      "Countries",
      "acqChannels":    "Acquisition Channels",
      "discReasons":    "Disconnect Reasons",
      "catalog":        "Catalog",
      "exchangeRates":  "Exchange Rates"
    }
  }
}
```

Остальные строки (таблицы, диалоги, фильтры) — без изменений, из исходных ключей
`admin.countries.*`, `admin.acquisitionChannels.*`, `admin.disconnectReasons.*`,
`catalog.products.*`, `catalog.exchangeRates.*`.

---

### Таблица Interactions (Ф2)

| Элемент | Действие | Результат | Endpoint |
|---------|----------|-----------|----------|
| Пункт «Страны» в сайдбаре | click | `activeSection='countries'`, `?section=countries` | — |
| Tab «Каталог» в dir-tabs | click | `activeTab='catalog'`, `setSection('catalog')`, URL | — |
| Deep-link `?section=acq-channels` | page load | `activeSection='acq-channels'`, показывает DirTabAcqChannels | — |
| Deep-link от non-admin | page load | resolveSection → 'profile', redirect | — |
| «+ Добавить страну» | click | `openCreate()` → `CountryDialog` открывается | — |
| Сохранить в CountryDialog | submit | `POST /api/directories/countries` → Toast + reload | `POST /api/directories/countries` |
| ToggleSwitch is_active (страны) | change | `PATCH /api/directories/countries/:id` | `PATCH /api/directories/countries/:id` |
| «Удалить» строку (страны) | click | ConfirmDialog → `DELETE /api/directories/countries/:id` | `DELETE /api/directories/countries/:id` |
| Row-link «название товара» | click | router-push `/admin/products/:id` (ProductPage — отдельный роут) | — |
| «Обновить» (курсы валют) | click | `refreshRates()` → `POST /api/exchange-rates/refresh` | `POST /api/exchange-rates/refresh` |
| ExchangeRateAgeWarning banner | visible | когда isStale=true | — |
| Мобильный Select — выбор «Каталог» | change | `setSection('catalog')` | — |

---

### Vizion-эталон

Структура: `./examples/vizion/front/src/pages/SettingsPage/` (если есть); иначе —
таб-паттерн из `./examples/vizion/front/src/pages/ProfilePage/` (tabs + lazy-load по
первому показу).
Стиль DataTable-вкладок: `./examples/vizion/front/src/pages/` — любая страница с
DataTable + toolbar (фильтры слева, действия справа).

---

### Открытые вопросы (Ф2)

1. **ОВ-1: Скрытие PageHeader через `:deep()`** — если `<PageHeader>` в
   `CountriesPage/index.vue` не является прямым дочерним (обёрнут в дополнительный div),
   CSS-селектор нужно уточнить. Frontend-specialist: проверь рендеренный DOM при встраивании
   и скорректируй селектор. Альтернатива — выделить body-контент каждой страницы в
   отдельный composable/slot и рендерить без PageHeader, но это больший рефакторинг.

2. **ОВ-2: Toast/ConfirmDialog дублирование** — проверить в браузере (DevTools), что
   при встраивании `ProductsPage` внутрь `DirTabCatalog` не появляется два Toast-контейнера.
   Если появляется — применить CSS-скрытие из §PrimeVue-компоненты выше.

3. **ОВ-3: Мобильный таб-бар** — если 5 табов не помещаются в одну строку на 375–768px,
   TabList должен прокручиваться горизонтально (`overflow-x: auto; white-space: nowrap`).
   Реализовать через `.dir-tabs :deep(.p-tablist) { overflow-x: auto; }`.

4. **ОВ-4: /admin/products остаётся активным?** — Подтвердить с PM: после Ф2 редиректа
   `/admin/products` → `/settings?section=catalog` ссылки из AppSidebar (nav.catalog) тоже
   должны вести в `/settings?section=catalog`. Требует правки AppSidebar-пункта «Каталог».
   Пока Ф2 — оставляем AppSidebar без изменений.

---

### Что переиспользуется без изменений

- Composables: `useCountriesPage`, `useAcquisitionChannelsPage`, `useDisconnectReasonsPage`,
  `useProductsPageData`, `useProductsPageActions`, `useExchangeRatesPage`, `useExchangeRatesActions` — **не трогать**.
- Dialog/Drawer-компоненты: `CountryDialog`, `ChannelDialog`, `ReasonDialog`,
  `ProductCreateDrawer`, `PriceImportDialog`, `ManualRateDialog` — **не трогать**.
- SCSS-стили исходных страниц — **не трогать** (scoped, не влияют на шелл).
- Роуты `/admin/products`, `/admin/products/:id`, `/admin/exchange-rates`,
  `/admin/countries`, `/admin/acquisition-channels`, `/admin/disconnect-reasons` —
  **остаются активными** до активации редиректов Ф2 (или параллельно, если решено
  оставить оба варианта доступа).

---

### Порядок реализации (рекомендованные шаги)

```
Ш1. Обновить useSettings.ts — добавить 5 ключей в VALID_KEYS + роль-проверку.
Ш2. Обновить SettingsSidebar.vue — phase: 2 → phase: 1 для 5 пунктов.
Ш3. Создать SectionDirectories.vue — Tabs + условный рендеринг 5 DirTab-компонентов.
Ш4. Создать DirTabCountries.vue (наипростейший) — verify скрытие PageHeader, отступы.
Ш5. Создать остальные DirTab*.vue по аналогии с Ш4.
Ш6. Добавить SectionDirectories в index.vue (v-else-if + mobileSectionOptions).
Ш7. Добавить i18n-ключи settings.directories.* в ru.json и en.json.
Ш8. Активировать 5 редиректов в base.ts (раскомментировать / убрать phase-guard).
Ш9. QA: проверить deep-link, таб-переключение, роль-гейтинг, обе темы, < 768px.
```

---

---

## Фаза 3 — Система (ТЗ для `frontend-specialist`)

> Автор: designer · Дата: 2026-06-29

### Зачем

Активировать группу СИСТЕМА в шелле `/settings`: переселить 4 существующих
standalone-страницы/компонента в детейл-панель шелла. Для трёх из них применяем тот
же `embedded`-паттерн Ф2 (`embedded?: boolean`, скрытие PageHeader/Toast/ConfirmDialog,
обнуление внешних отступов). Четвёртый (Сброс системы) — action-based: выносим контент
в новый `SectionSystemReset.vue` (без встраивания страницы, composable подключается напрямую).

**User story:** «Я открываю `/settings?section=users` и вижу список пользователей прямо
внутри Настроек; `/settings?section=automation-runs` — журнал автоматизаций; кнопка
"Сброс системы" — только у меня (admin) в отдельном разделе настроек, а не в "Профиле".»

---

### Где в коде (новые файлы)

```
front/src/pages/SettingsPage/
  components/
    sections/
      SectionSystem.vue              ← НОВЫЙ: обёртка-роутер системных разделов
                                       (v-if/v-else-if по activeSection — как index.vue)
      system/
        SysTabUsers.vue              ← НОВЫЙ: wraps UsersPage с embedded=true
        SysTabAccessControl.vue      ← НОВЫЙ: wraps AccessControlPage с embedded=true
        SysTabAutomationRuns.vue     ← НОВЫЙ: wraps AutomationRunsPage с embedded=true
      SectionSystemReset.vue         ← НОВЫЙ: инлайн-страница сброса (без встраивания страницы)
```

**Существующие страницы изменяются минимально:**
- `UsersPage/index.vue` — добавить `embedded?: boolean` prop
- `AccessControlPage/index.vue` — добавить `embedded?: boolean` prop
- `AutomationRunsPage/index.vue` — добавить `embedded?: boolean` prop
- `ProfilePage/components/SystemResetDialog.vue` — **не трогать**
- `ProfilePage/composables/useSystemReset.ts` — **не трогать**

---

### Фактический состав группы СИСТЕМА

| Ключ | Страница | Роль-гейт | Действие |
|------|----------|-----------|----------|
| `users` | `UsersPage` (существует, `/admin/users`) | admin + director | встраиваем по Ф2-паттерну |
| `access-control` | `AccessControlPage` (существует, `/admin/access-control/*`) | admin + director | встраиваем по Ф2-паттерну |
| `automation-runs` | `AutomationRunsPage` (существует, `/admin/automation-runs`) | admin + director | встраиваем по Ф2-паттерну |
| `system-reset` | `useSystemReset` + `SystemResetDialog` (существуют в ProfilePage) | **admin only** | выносим в `SectionSystemReset.vue` |

**Что не переезжает в Ф3 и почему:**
- `PipelineSettingsPage` (`/settings/pipeline`) — существует, но это canvas-приложение (vue-flow, StageNode, AnchorNode, AutomationWizardDialog, drawer). Встраивание в шелл создаст конфликт по высоте/overflow (canvas должен занимать весь экран) и не даст функционального преимущества. **Решение: оставить `/settings/pipeline` как самостоятельный роут, пункт `pipeline-stg` остаётся `phase: 2` в сайдбаре.**
- `TemplatesPage`, `TemplateVariablesPage`, `ApprovalRoutesPage`, `MessageTemplatesPage` — уже намечены в ТЗ как `doc-templates`, `tpl-variables`, `approval-routes`, `msg-templates` в группе СПРАВОЧНИКИ (`phase: 2`). Ф3 их не трогает.

---

### Wireframe (ASCII)

```
┌──────────────────────────────────────────────────────────────────────────────┐
│  PageHeader «Настройки»  [pi pi-cog]              (шелл, неизменён)          │
│  ───────────────────────────────────────────────────────────────────────────  │
├──────────────────┬───────────────────────────────────────────────────────────┤
│  SIDEBAR ~240px  │  DETAIL (flex:1, overflow-y:auto)                         │
│                  │                                                           │
│  АККАУНТ         │  ── Вариант А: section=users ──────────────────────────── │
│  • Профиль       │  ┌─────────────────────────────────────────────────────┐  │
│  • Безопасность  │  │  [sub-toolbar] [+ Добавить пользователя]            │  │
│  • Внешний вид   │  │  ─────────────────────────────────────────────────  │  │
│  • Язык          │  │  [🔍 ФИО/Email...]  [Роль ▼]  [Отдел ▼]  [Статус▼] │  │
│                  │  │  ─────────────────────────────────────────────────  │  │
│  ИНТЕГРАЦИИ      │  │  DataTable  (ФИО · Email · Телефон · Отдел · Роль)  │  │
│  • Каналы        │  │  Paginator                                          │  │
│                  │  └─────────────────────────────────────────────────────┘  │
│  СПРАВОЧНИКИ     │                                                           │
│  • Страны        │  ── Вариант Б: section=access-control ─────────────────── │
│  • … (×5)        │  ┌─────────────────────────────────────────────────────┐  │
│                  │  │  [Отделы] [Роли и права] [Видимость записей]        │  │
│  СИСТЕМА         │  │  ← горизонтальные табы страницы (встроенные)        │  │
│  ►Пользователи◄──│  │  ─────────────────────────────────────────────────  │  │
│  • Доступ        │  │  <DepartmentsTab> / <RolesPermissionsTab> / …       │  │
│  • Авт-зации     │  └─────────────────────────────────────────────────────┘  │
│  • Сброс         │                                                           │
│    (только admin)│  ── Вариант В: section=automation-runs ──────────────────  │
│                  │  ┌─────────────────────────────────────────────────────┐  │
│                  │  │  [sub-toolbar] [▶ Dry-run] (disabled если нет id)   │  │
│                  │  │  [Автоматизация▼] [Статус▼] [Действие▼] [Период▼]  │  │
│                  │  │  [Применить]                                        │  │
│                  │  │  DataTable (read-only) + load-more                  │  │
│                  │  └─────────────────────────────────────────────────────┘  │
│                  │                                                           │
│                  │  ── Вариант Г: section=system-reset (admin only) ──────── │
│                  │  ┌─────────────────────────────────────────────────────┐  │
│                  │  │  [hero-danger block]                                │  │
│                  │  │  «Сброс системы»  Tag severity=danger «Только admin»│  │
│                  │  │  p «Эта операция удалит…»                          │  │
│                  │  │  [Что будет удалено]  [Что останется]              │  │
│                  │  │  Message severity=warn «После сброса — выход»       │  │
│                  │  │  [Выполнить сброс] → открывает SystemResetDialog    │  │
│                  │  └─────────────────────────────────────────────────────┘  │
└──────────────────┴───────────────────────────────────────────────────────────┘
```

---

### Раздел А: «Пользователи» (`SysTabUsers.vue`)

#### embedded-паттерн (по аналогии с Ф2)

Добавляем в `UsersPage/index.vue` проп `embedded?: boolean` и скрываем за `v-if="!embedded"`:
- `<PageHeader>` — скрывается
- Нет отдельного `<Toast>` в UsersPage (не обнаружен в шаблоне) — скрывать нечего
- `<ConfirmDialog>` (есть ли — уточнить по коду; если есть — `v-if="!embedded"`)
- Внешний padding — сбрасывается через `.users-page` CSS или класс-модификатор

`defineExpose` в `UsersPage/index.vue`:
```ts
defineExpose({ openCreate, canManage })
```

Обёртка `SysTabUsers.vue`:
```
SysTabUsers.vue
  ├── .sys-tab-toolbar  (аналог .dir-tab-toolbar)
  │   ├── [spacer]
  │   └── [+ Добавить пользователя]  ← видна только если pageRef?.canManage
  └── .sys-tab-body
      └── <UsersPage ref="pageRef" :embedded="true" />
```

> Кнопка «+ Добавить пользователя» переносится из PageHeader страницы в sub-toolbar обёртки через `defineExpose`. Логика (openCreate) не меняется.

#### deep-link ключ: `users`

#### Роль-гейт: admin + director (как в SettingsSidebar, `roles: ['admin', 'director']`)

#### Состояния

| Состояние | Поведение |
|-----------|-----------|
| loading | DataTable `:loading="true"` встроенная страница (унаследовано) |
| empty | empty-state UsersPage (унаследовано) |
| error | Toast из usersPage composable (унаследовано) |
| non-admin direct link | `SectionSystem` → `resolveSection` → `'profile'` |

---

### Раздел Б: «Доступ и оргструктура» (`SysTabAccessControl.vue`)

#### embedded-паттерн

Добавляем в `AccessControlPage/index.vue` проп `embedded?: boolean`:
- `<PageHeader>` — скрывается
- `<Message v-if="!isAllowed">` — остаётся (внутренняя защита страницы сохраняется)
- Внешний padding — сбрасывается

Специфика: `AccessControlPage` уже содержит собственный **PrimeVue Tabs** (3 таба:
`departments`, `roles`, `visibility`). При встраивании эти табы будут видны внутри
detail-панели шелла — **это корректное поведение**. Не нужны дополнительные обёртки
табов: страница сама управляет переключением своих табов через `?` query-параметр
(или через `activeTab` ref — уточнить в коде).

> Важно: у AccessControlPage есть свой URL-синк (?tab=departments и т.д.). При встраивании
> в шелл этот синк конфликтует с `?section=access-control`. Решение: передать `embedded=true`
> и внутри AccessControlPage при `embedded === true` управлять активным табом через
> внутренний `ref` (не через router.push/replace). `defineExpose({ activeTab })` — опционально.

`defineExpose` в `AccessControlPage/index.vue`:
```ts
defineExpose({ activeTab, setActiveTab })
```

Обёртка `SysTabAccessControl.vue`:
```
SysTabAccessControl.vue
  └── .sys-tab-body
      └── <AccessControlPage ref="pageRef" :embedded="true" />
```

Нет sub-toolbar (все действия внутри самих табов страницы).

#### deep-link ключ: `access-control`

#### Роль-гейт: admin + director

---

### Раздел В: «Журнал автоматизаций» (`SysTabAutomationRuns.vue`)

#### embedded-паттерн

Добавляем в `AutomationRunsPage/index.vue` проп `embedded?: boolean`:
- `<PageHeader>` — скрывается: `v-if="!embedded"` на `<PageHeader>` в шаблоне
- `<Toast />` — скрывается: `v-if="!embedded"` (шелл уже рендерит `<Toast>`)
- `DryRunDrawer` — **остаётся** (не затронут: Drawer рендерится поверх всего, не конфликтует с шеллом)
- Padding `.automation-runs-page__content` — обнулять не нужно (`padding: $space-6` остаётся, визуально консистентно с Ф2)

`defineExpose` в `AutomationRunsPage/index.vue`:
```ts
// Нет экспонируемых экшенов — страница read-only
// defineExpose не требуется
```

Обёртка `SysTabAutomationRuns.vue`:
```
SysTabAutomationRuns.vue
  └── .sys-tab-body
      └── <AutomationRunsPage :embedded="true" />
```

Нет sub-toolbar: все фильтры и кнопка «Dry-run» уже живут внутри страницы в
`.automation-runs-page__filters`. Save-bar не нужен (read-only журнал).

#### deep-link ключ: `automation-runs`

#### Роль-гейт: admin + director

#### Состояния

| Состояние | Поведение |
|-----------|-----------|
| loading | DataTable `:loading` встроенная страница |
| empty | empty-block с pi-clock (унаследовано) |
| error | `<Message severity="error">` встроенная страница (унаследовано) |
| «Dry-run» disabled | когда filterAutomationId не выбран (логика страницы, унаследована) |

---

### Раздел Г: «Сброс системы» (`SectionSystemReset.vue`)

#### Концепция

Не встраиваем существующую страницу — она не существует. Существует только
`SystemResetDialog.vue` (модальное окно) + `useSystemReset.ts` (composable).
Создаём новый `SectionSystemReset.vue` — инлайн-страница в detail-панели шелла,
которая описывает опасность операции и содержит единственную кнопку-триггер.
`SystemResetDialog.vue` и `useSystemReset.ts` — **не трогать**, только импортируем.

#### Wireframe раздела

```
┌─────────────────────────────────────────────────────────────────────────────┐
│  .sys-reset-section  (padding: $space-6, max-width: 640px)                   │
│                                                                              │
│  .sys-reset-header                                                           │
│  [pi pi-exclamation-triangle, 32px, danger]                                  │
│  «Сброс системы»  (h2, font-size-xl, font-weight-semibold)                  │
│  Tag severity="danger" value="Только для администратора"  (справа)          │
│                                                                              │
│  p «Эта операция безвозвратно удалит все бизнес-данные из системы.»         │
│    «Используйте только на тестовых стендах.»                                │
│                                                                              │
│  ─────────────────────────────────────────────────────────────────────────  │
│                                                                              │
│  .sys-reset-lists  (row gap-4)                                               │
│  col-md-6:                            col-md-6:                             │
│  «Будет удалено»                      «Останется»                           │
│  (pi-trash, danger-tint border)       (pi-check-circle, success-tint)       │
│  • Сделки и активности               • Учётные записи и роли                │
│  • Контакты и компании               • Настройки воронки                    │
│  • Документы                         • Каталог продуктов                    │
│  • Автоматизации                     • Маршруты согласования                │
│  • Онбординг и прогресс              • Причины отказа                       │
│  • Уведомления                                                              │
│  • Кастомные поля                                                           │
│                                                                              │
│  ─────────────────────────────────────────────────────────────────────────  │
│                                                                              │
│  Message severity="warn" :closable="false"                                  │
│  «После сброса вы будете автоматически выведены из системы.»               │
│                                                                              │
│  Button severity="danger" outlined icon="pi pi-refresh"                     │
│  label="Выполнить сброс…"                                                   │
│  → @click="resetState.openDialog()"                                          │
│                                                                              │
│  <SystemResetDialog                                                          │
│    v-model:visible="resetState.dialogVisible.value"                          │
│    v-model:confirm-input="resetState.confirmInput.value"                     │
│    :is-confirmed="resetState.isConfirmed.value"                              │
│    :is-pending="resetState.isPending.value"                                  │
│    :RESET_CONFIRM_PHRASE="resetState.RESET_CONFIRM_PHRASE"                   │
│    @confirm="resetState.executeReset()"                                      │
│    @cancel="resetState.closeDialog()"                                        │
│  />                                                                          │
└─────────────────────────────────────────────────────────────────────────────┘
```

#### Детали компонента

```vue
<!-- SectionSystemReset.vue — структура -->
<script setup lang="ts">
import { useSystemReset } from '@/pages/ProfilePage/composables/useSystemReset'
import SystemResetDialog from '@/pages/ProfilePage/components/SystemResetDialog.vue'

const resetState = useSystemReset()
</script>
```

Save-bar — **нет**. Раздел полностью action-based (один триггер → диалог → выполнить).

Toast из `useSystemReset` работает через `useToast()` — глобальный синглтон.
ConfirmDialog здесь не используется — подтверждение происходит в `SystemResetDialog`.

#### CSS

```scss
.sys-reset-section {
  padding: $space-6;
  max-width: 640px;

  // dark-тема: весь контент через токены, нет hex-литералов
}

.sys-reset-header {
  display: flex;
  align-items: center;
  gap: $space-3;
  margin-bottom: $space-4;

  &__icon {
    font-size: 2rem;            // $font-size-icon-xl
    color: var(--p-red-500);
    flex-shrink: 0;
  }

  &__title {
    flex: 1;
    font-size: $font-size-xl;
    font-weight: $font-weight-semibold;
    color: $surface-900;
    margin: 0;

    .app-dark & { color: var(--p-surface-50); }
  }
}

.sys-reset-desc {
  font-size: $font-size-base;
  color: $surface-600;
  margin: 0 0 $space-5;

  .app-dark & { color: var(--p-surface-400); }
}

.sys-reset-trigger {
  margin-top: $space-5;
}
```

#### deep-link ключ: `system-reset`

#### Роль-гейт: **только admin** (не director)

В `SettingsSidebar.vue` пункт `system-reset` уже имеет `roles: ['admin']`.
`resolveSection` должен учесть: для `system-reset` недостаточно `isAdminOrDirector` —
нужна отдельная проверка `role === 'admin'`. При прямом deep-link от director →
`resolveSection` возвращает `'profile'` (или вариант: `'users'` — см. Открытые вопросы).

В `useSettings.ts` добавить в массив SYSTEM_ADMIN_ONLY_KEYS:
```ts
const SYSTEM_ADMIN_ONLY_KEYS = ['system-reset'] as const
```

И в `resolveSection`:
```ts
if ((SYSTEM_ADMIN_ONLY_KEYS as readonly string[]).includes(key) && role !== 'admin') {
  return 'profile'
}
```

---

### Контейнер `SectionSystem.vue` (опционально)

По аналогии с `SectionDirectories.vue` можно создать `SectionSystem.vue` —
thin router-компонент, который рендерит нужный `SysTab*` или `SectionSystemReset` по
`activeSection`. Либо обойтись прямыми `v-else-if` в `index.vue` (предпочтительнее,
меньше файлов — 4 пункта, не 10).

**Рекомендация: прямые `v-else-if` в `index.vue`, без `SectionSystem.vue`.**

```vue
<!-- index.vue — добавить в detail-панель после SectionDirectories -->

<SysTabUsers
  v-else-if="settings.activeSection.value === 'users'"
/>

<SysTabAccessControl
  v-else-if="settings.activeSection.value === 'access-control'"
/>

<SysTabAutomationRuns
  v-else-if="settings.activeSection.value === 'automation-runs'"
/>

<SectionSystemReset
  v-else-if="settings.activeSection.value === 'system-reset'"
/>
```

---

### Обновление `useSettings.ts`

```ts
// Добавить константы
export const SYSTEM_KEYS = ['users', 'access-control', 'automation-runs'] as const
export const SYSTEM_ADMIN_ONLY_KEYS = ['system-reset'] as const

// Обновить VALID_KEYS
const VALID_KEYS = [
  ...ACCOUNT_KEYS,
  ...DIRECTORIES_KEYS,
  ...SYSTEM_KEYS,
  ...SYSTEM_ADMIN_ONLY_KEYS,
] as const

// Обновить resolveSection
function resolveSection(key: string | undefined): string {
  if (!key) return 'profile'
  const role = userStore.getUserRole

  if ((DIRECTORIES_KEYS as readonly string[]).includes(key)) {
    if (role !== 'admin' && role !== 'director') return 'profile'
  }
  if ((SYSTEM_KEYS as readonly string[]).includes(key)) {
    if (role !== 'admin' && role !== 'director') return 'profile'
  }
  if ((SYSTEM_ADMIN_ONLY_KEYS as readonly string[]).includes(key)) {
    if (role !== 'admin') return 'profile'
  }
  if ((VALID_KEYS as readonly string[]).includes(key as ValidKey)) return key
  return 'profile'
}
```

---

### Обновление `SettingsSidebar.vue`

Для 4 пунктов группы СИСТЕМА меняем `phase: 3` → `phase: 1`:

```ts
// SettingsSidebar.vue — GROUPS, секция system:
{ key: 'users',           ..., phase: 1, roles: ['admin', 'director'] },  // было 3
{ key: 'access-control',  ..., phase: 1, roles: ['admin', 'director'] },  // было 3
{ key: 'automation-runs', ..., phase: 1, roles: ['admin', 'director'] },  // было 3
{ key: 'system-reset',    ..., phase: 1, roles: ['admin'] },              // было 3
```

Тег «Скоро» пропадает автоматически (условие `section.phase !== 1`).

> `system-reset` должен рендериться только для admin, не director. Текущий `visibleGroups`
> фильтрует только по `adminOnly` (группа целиком). Для пункта с `roles: ['admin']` нужно
> дополнительно скрывать строку: `v-if="!section.roles || section.roles.includes(getUserRole)"`.
> Иначе director увидит строку «Сброс системы» в сайдбаре, кликнет и попадёт на 'profile'.

Добавить в шаблон `SettingsSidebar.vue`:
```vue
<button
  v-for="section in group.sections"
  v-show="!section.roles || section.roles.includes(userStore.getUserRole)"
  ...
>
```

(заменить `v-show` на `v-if` если хотим полностью убрать из DOM)

---

### Редиректы Ф3 (активировать в `base.ts`)

| Старый путь | Новый URL | Текущий статус в base.ts |
|-------------|-----------|--------------------------|
| `/admin/users` | `/settings?section=users` | самостоятельный роут (работает) |
| `/admin/access-control` | `/settings?section=access-control` | самостоятельный роут (redirect → /departments) |
| `/admin/access-control/departments` | `/settings?section=access-control` | самостоятельный роут |
| `/admin/access-control/roles` | `/settings?section=access-control` | самостоятельный роут |
| `/admin/access-control/visibility` | `/settings?section=access-control` | самостоятельный роут |
| `/admin/automation-runs` | `/settings?section=automation-runs` | самостоятельный роут (работает) |

> Старые роуты остаются активными до активации редиректов. Активировать редиректы после
> QA-проверки Ф3 — заменой route-definition на `{ path: '/admin/users', redirect: ... }`.

**Роут `/profile?tab=system`:**

В `base.ts` уже есть маппинг `system: 'profile'` — временный фолбэк (ProfilePage не содержала
отдельной страницы, только диалог). После Ф3 можно обновить маппинг:
```ts
// было:
system: 'profile',
// станет (после Ф3):
system: 'system-reset',  // но только для admin; resolveSection отфильтрует для director
```

---

### Обновление мобильного Select в `index.vue`

Добавить 4 новые опции в `mobileSectionOptions` (только для admin/director — как Ф2):

```ts
// Расширить mobileSectionOptions с учётом роли
const mobileSectionOptions = computed(() => {
  const base = [
    { value: 'profile',    label: t('settings.sections.profile.title') },
    { value: 'security',   label: t('settings.sections.security.title') },
    { value: 'appearance', label: t('settings.sections.appearance.title') },
    { value: 'language',   label: t('settings.sections.language.title') },
    { value: 'channels',   label: t('settings.sections.channels.title') },
  ]
  const role = userStore.getUserRole
  const isAdminOrDirector = role === 'admin' || role === 'director'
  const isAdmin = role === 'admin'

  if (isAdminOrDirector) {
    base.push(
      { value: 'countries',       label: t('settings.sections.countries.title') },
      { value: 'acq-channels',    label: t('settings.sections.acq-channels.title') },
      { value: 'disc-reasons',    label: t('settings.sections.disc-reasons.title') },
      { value: 'catalog',         label: t('settings.sections.catalog.title') },
      { value: 'exchange-rates',  label: t('settings.sections.exchange-rates.title') },
      // Ф3:
      { value: 'users',           label: t('settings.sections.users.title') },
      { value: 'access-control',  label: t('settings.sections.access-control.title') },
      { value: 'automation-runs', label: t('settings.sections.automation-runs.title') },
    )
  }
  if (isAdmin) {
    base.push({ value: 'system-reset', label: t('settings.sections.system-reset.title') })
  }
  return base
})
```

---

### PrimeVue-компоненты Ф3

| Компонент | Где | Props |
|-----------|-----|-------|
| `Button` | sub-toolbar `SysTabUsers` | `icon="pi pi-plus"`, `label`, `v-if="pageRef?.canManage"` |
| `Button` | `SectionSystemReset` | `severity="danger" outlined`, `icon="pi pi-refresh"` |
| `Message` | `SectionSystemReset` | `severity="warn"`, `:closable="false"` |
| `Tag` | `SectionSystemReset` (admin-badge) | `severity="danger"`, `value` |
| `Dialog` (SystemResetDialog) | `SectionSystemReset` | `v-model:visible`, `modal`, `:closable="!isPending"` |
| `InputText` | внутри SystemResetDialog | фразовое подтверждение (без изменений) |
| `DataTable`, `Column`, `Paginator` | UsersPage (встроена) | унаследовано |
| `Tabs`, `TabList`, `Tab`, `TabPanels`, `TabPanel` | AccessControlPage (встроена) | унаследовано |
| `Select`, `DatePicker` | AutomationRunsPage (встроена) | унаследовано |
| `Tag` | AutomationRunsPage (статус прогона) | унаследовано |
| `Drawer` (DryRunDrawer) | AutomationRunsPage (встроена) | `:show-close-icon="false"`, унаследовано |

**Toast и ConfirmDialog:**
- `AutomationRunsPage` рендерит `<Toast />` → скрыть через `v-if="!embedded"` (шелл рендерит глобальный)
- `UsersPage` — проверить наличие `<Toast>` и `<ConfirmDialog>` в шаблоне; если есть — `v-if="!embedded"` по Ф2-паттерну
- `AccessControlPage` — аналогично

---

### Состояния Ф3 (полная таблица)

| Элемент | Состояние | Поведение |
|---------|-----------|-----------|
| Все 4 раздела | loading | Встроенная страница управляет loading-state самостоятельно (унаследовано) |
| `SysTabUsers` | empty (нет пользователей) | EmptyState с иконкой pi-users + CTA «Добавить» (унаследовано) |
| `SysTabAutomationRuns` | empty (нет прогонов) | EmptyState с pi-clock + текст (унаследовано) |
| `SectionSystemReset` | default | кнопка «Выполнить сброс…» активна |
| `SectionSystemReset` | dialog open | `SystemResetDialog` visible=true; фоновый контент блокирован |
| `SectionSystemReset` | reset pending | кнопка `:loading="true"` внутри диалога; фраза вводится |
| `SectionSystemReset` | reset success | Toast «Сброс выполнен» + router redirect на `/login?reason=reset` |
| `SectionSystemReset` | reset error | Toast severity="error" |
| non-admin на `system-reset` (deep-link) | page load | `resolveSection` → `'profile'` |
| director на `system-reset` (sидбар) | v-if | строка «Сброс системы» скрыта в sidebar |

---

### Interactions — таблица

| Элемент | Действие | Результат | Endpoint |
|---------|----------|-----------|----------|
| Пункт «Пользователи» в сайдбаре | click | `activeSection='users'`, `?section=users` | — |
| Пункт «Доступ и оргструктура» | click | `activeSection='access-control'`, `?section=access-control` | — |
| Пункт «Журнал автоматизаций» | click | `activeSection='automation-runs'`, `?section=automation-runs` | — |
| Пункт «Сброс системы» (только admin) | click | `activeSection='system-reset'`, `?section=system-reset` | — |
| Sub-toolbar «+ Добавить пользователя» | click | `pageRef.openCreate()` → `CreateUserDialog` | — |
| CreateUserDialog → сохранить | submit | `POST /api/users` → Toast + reload | `POST /api/users` |
| Кнопка «Деактивировать» в UsersPage | click | ConfirmDialog → `PATCH /api/users/:id {is_active:false}` | `PATCH /api/users/:id` |
| Tab «Роли и права» в AccessControl | click | `AccessControlPage.setActiveTab('roles')` | — |
| Фильтр автоматизаций + «Применить» | click | `page.fetchRuns()` | `GET /api/automation-runs` |
| Кнопка «Dry-run» | click | `DryRunDrawer` visible=true | — |
| Dry-run submit | click | `POST /api/automations/:id/dry-run` | `POST /api/automations/:id/dry-run` |
| «Выполнить сброс…» | click | `resetState.openDialog()` → `SystemResetDialog` | — |
| SystemResetDialog: ввод фразы | input | `isConfirmed` вычисляется, кнопка активируется | — |
| SystemResetDialog: «Выполнить» | click | `POST /api/system/reset` → Toast + logout | `POST /api/system/reset` |
| Deep-link `/admin/users` (до редиректа Ф3) | navigate | роут работает как самостоятельный | — |
| Deep-link `/admin/users` (после редиректа Ф3) | navigate | `→ /settings?section=users` | — |
| Deep-link `/settings?section=system-reset` от director | page load | `resolveSection` → `'profile'` | — |
| Мобильный Select — «Журнал автоматизаций» | change | `setSection('automation-runs')` | — |

---

### i18n-ключи Ф3

Добавить в `ru.json` и `en.json` в блок `settings.system`:

```json
// ru.json
"settings": {
  "system": {
    "sectionTitle": "Система",
    "sectionDesc": "Управление пользователями, доступом и системными операциями",
    "users": {
      "subToolbarAddBtn": "Добавить пользователя"
    },
    "automationRuns": {
      "subDesc": "Журнал запусков автоматизаций и диагностика"
    },
    "systemReset": {
      "pageTitle": "Сброс системы",
      "adminBadge": "Только для администратора",
      "descPrimary": "Эта операция безвозвратно удалит все бизнес-данные из системы.",
      "descSecondary": "Используйте только на тестовых стендах или при переходе на чистую инсталляцию.",
      "willDeleteTitle": "Будет удалено",
      "willKeepTitle": "Останется",
      "triggerBtn": "Выполнить сброс…"
    }
  }
}
```

```json
// en.json
"settings": {
  "system": {
    "sectionTitle": "System",
    "sectionDesc": "Manage users, access control and system operations",
    "users": {
      "subToolbarAddBtn": "Add user"
    },
    "automationRuns": {
      "subDesc": "Automation run history and diagnostics"
    },
    "systemReset": {
      "pageTitle": "System Reset",
      "adminBadge": "Administrators only",
      "descPrimary": "This action will permanently delete all business data from the system.",
      "descSecondary": "Only use on test environments or when starting fresh.",
      "willDeleteTitle": "Will be deleted",
      "willKeepTitle": "Will remain",
      "triggerBtn": "Perform reset…"
    }
  }
}
```

Строки для `system.reset.*` уже существуют в `ru.json`/`en.json` (ключи `system.reset.dialog_title`,
`system.reset.will_delete.*`, `system.reset.will_keep.*` и т.д.) — переиспользуются без изменений.

---

### Порядок реализации (рекомендованные шаги)

```
Ш1. Обновить useSettings.ts — добавить 4 ключа в VALID_KEYS + роль-проверку для
     system-reset (admin only) в resolveSection.
Ш2. Обновить SettingsSidebar.vue — phase: 3 → phase: 1 для 4 пунктов;
     добавить v-if/v-show для system-reset (только admin, не director).
Ш3. Добавить embedded prop в UsersPage/index.vue (v-if="!embedded" на PageHeader,
     Toast/ConfirmDialog если есть; defineExpose openCreate/canManage).
Ш4. Создать SysTabUsers.vue — sub-toolbar + <UsersPage :embedded="true">.
Ш5. Добавить embedded prop в AccessControlPage/index.vue (v-if="!embedded" на
     PageHeader); при embedded=true управлять табами через internal ref, не router.
Ш6. Создать SysTabAccessControl.vue — <AccessControlPage :embedded="true">.
Ш7. Добавить embedded prop в AutomationRunsPage/index.vue (v-if="!embedded" на
     PageHeader и Toast).
Ш8. Создать SysTabAutomationRuns.vue — <AutomationRunsPage :embedded="true">.
Ш9. Создать SectionSystemReset.vue — hero-danger block + trigger button +
     <SystemResetDialog>; импортировать useSystemReset из ProfilePage.
Ш10. Обновить SettingsPage/index.vue — добавить 4 v-else-if ветви + mobileSectionOptions.
Ш11. Добавить i18n-ключи settings.system.* в ru.json и en.json.
Ш12. Активировать редиректы Ф3 в base.ts (или оставить старые роуты активными — см. ОВ-3).
Ш13. QA: deep-link все 4 ключа, роль-гейт (admin vs director), обе темы, < 768px,
      SystemResetDialog (фраза, pending, success/error), DryRunDrawer встроенный.
```

---

### Визуальный эталон

Структура sub-toolbar (toolbar внутри встраиваемого раздела) — `SysTabUsers.vue`:
аналогично `DirTabCountries.vue` из Ф2 (`.dir-tab-toolbar` паттерн — toolbar + spacer + actions).

Стиль `SectionSystemReset.vue` — опасный action-based блок: эталон — `.system-reset-section`
и `.system-reset-section--danger/--safe` из `SystemResetDialog.vue` (переиспользуем те же
классы-блоки для списков «будет удалено» / «останется»).

Табы `AccessControlPage` встроенные: без изменений, PrimeVue Tabs — `.p-tabs` с PrimeVue
default styling (не line-style как в Ф2 — это внутренняя навигация страницы, не settings-tabs).

---

### Открытые вопросы (Ф3)

1. **ОВ-1: AccessControlPage — конфликт URL-синка при embedded=true.** Текущая реализация
   `AccessControlPage/index.vue` синхронизирует активный таб с URL (через `route.path` —
   `/admin/access-control/departments` и т.д.). При `embedded=true` нужно отключить URL-синк
   и управлять `activeTab` через internal ref. Требует правки `AccessControlPage/index.vue`.
   Если это сложно (много зависимостей), альтернатива — не отключать синк, но добавить
   условие: при `embedded=true` router.replace не вызывается.

2. **ОВ-2: Toast/ConfirmDialog в UsersPage и AccessControlPage.** Проверить в коде — есть ли
   `<Toast>` и `<ConfirmDialog>` в шаблонах (не обнаружены при беглом чтении).
   Если есть — `v-if="!embedded"` по Ф2-паттерну. Если нет — ничего делать не нужно.

3. **ОВ-3: Активация редиректов Ф3 — сроки.** `/admin/users` и `/admin/automation-runs`
   существуют как рабочие роуты в `base.ts`. После Ф3 нужно решить: оставить оба варианта
   доступа (старый роут + settings-шелл) или заменить на redirect. Рекомендация: заменять
   redirect только после QA-апрува Ф3 — не в тот же PR.

4. **ОВ-4: Куда редиректить director при попытке зайти на `system-reset`?** Текущее ТЗ
   указывает `resolveSection → 'profile'`. Альтернатива: `'users'` (более логично —
   director видит список пользователей, который ему доступен). Решение за PM.

5. **ОВ-5: Редирект `/profile?tab=system` (в base.ts уже `system: 'profile'`).** После Ф3
   можно поменять на `system: 'system-reset'`, но это даст 403 для director (resolveSection
   вернёт profile). Безопаснее: `system: isAdmin ? 'system-reset' : 'users'` — но redirect-
   функция в base.ts не имеет доступа к userStore. Решение: оставить `system: 'profile'`
   или `system: 'users'` — PM решает.

---

### Статус реализации

### Фаза 3 — pending

СИСТЕМА (admin/director): Users, AccessControl, AutomationRuns, SystemReset — ТЗ готово.
Ожидает реализации `frontend-specialist`.

*ТЗ для `frontend-specialist` (Ф3) готово. Передавай `frontend-specialist`. Если есть правки — кидай мне.*

---

---

## Фаза 5 — Профиль 2.0: под-вкладки + аватар-кроп + смена пароля (ТЗ для `frontend-specialist`)

> Автор: designer · Дата: 2026-06-30

### Зачем

Три улучшения группы АККАУНТ одним проходом:

1. **Reorg сайдбара:** 4 пункта (Профиль / Безопасность / Внешний вид / Язык) схлопываются
   в один пункт «Профиль» с горизонтальными под-вкладками внутри detail-панели.
2. **Аватар-кроп:** большие фото пользователи не могут загрузить без ошибки (≤2МБ).
   Добавляем клиентский кроп + сжатие перед отправкой на `POST /api/profile/avatar`.
3. **Смена пароля:** новый блок в под-вкладке «Безопасность» рядом с 2FA.

---

### Где в коде

```
front/src/pages/SettingsPage/
  components/
    sections/
      SectionProfile.vue           ← ИЗМЕНЯЕТСЯ: добавляются под-вкладки + AvatarCropModal
      SectionSecurity.vue          ← ИЗМЕНЯЕТСЯ: добавляется блок ChangePasswordForm
      SectionAppearance.vue        ← НЕ трогается (переселяется в под-вкладку, код не меняется)
      SectionLanguage.vue          ← НЕ трогается (то же)
      profile/
        AvatarCropModal.vue        ← НОВЫЙ компонент: PrimeVue Dialog + vue-advanced-cropper
        ChangePasswordForm.vue     ← НОВЫЙ компонент: форма смены пароля (action-based)
    SettingsSidebar.vue            ← ИЗМЕНЯЕТСЯ: reorg АККАУНТ
  composables/
    useSettings.ts                 ← ИЗМЕНЯЕТСЯ: новые ключи, логика под-вкладок
  index.vue                        ← ИЗМЕНЯЕТСЯ: маршрутизация в SectionProfileTabs
```

> `SectionAppearance.vue` и `SectionLanguage.vue` — переиспользуются 1-в-1 как содержимое
> под-вкладок. Логику и разметку не трогаем, только передаём в контейнер под-вкладки.

---

### Часть A — Reorg сайдбара: один пункт «Профиль» с под-вкладками

#### Решение deep-link (под-вкладка как под-ключ `?section=`)

**Выбор: отдельные `?section=`-ключи для каждой под-вкладки, точно как в Ф2 (СПРАВОЧНИКИ).**

Схема:
```
?section=profile        → под-вкладка «Профиль»       (как сейчас)
?section=security       → под-вкладка «Безопасность»  (как сейчас)
?section=appearance     → под-вкладка «Внешний вид»   (как сейчас)
?section=language       → под-вкладка «Язык»          (как сейчас)
```

Отличие от текущего: `SettingsSidebar.vue` больше **не показывает 4 отдельных пункта**
в группе АККАУНТ — показывает один пункт «Профиль», активный когда `activeSection` —
любой из `PROFILE_TAB_KEYS`. Под-вкладки рендерятся горизонтально внутри `SectionProfileTabs`.

**Почему не `?section=profile&tab=security`:**
- Существующий `useSettings.ts` уже знает ключи `security`, `appearance`, `language` как
  VALID_KEYS; редиректы `/profile?tab=security` → `/settings?section=security` уже работают.
  Менять схему = регрессия редиректов.
- Более простая логика мобильного Select (один уровень options).
- Dirty-guard уже написан для смены `?section=` — работает без изменений.

**Изменения в `useSettings.ts`:**
```ts
// Добавляем константу (уже есть эти ключи в ACCOUNT_KEYS, просто группируем)
export const PROFILE_TAB_KEYS = ['profile', 'security', 'appearance', 'language'] as const
export type ProfileTabKey = (typeof PROFILE_TAB_KEYS)[number]

// Хелпер
export function isProfileSection(key: string): key is ProfileTabKey {
  return (PROFILE_TAB_KEYS as readonly string[]).includes(key)
}
```

`VALID_KEYS` — не меняется (все 4 ключа уже там). Логика `resolveSection` — не меняется.

#### Wireframe под-вкладок (ASCII)

```
┌──────────────────┬──────────────────────────────────────────────────────────────┐
│  SIDEBAR ~240px  │  DETAIL (flex:1)                                             │
│                  │                                                              │
│  АККАУНТ         │  ┌──────────────────────────────────────────────────────┐   │
│  ──────────────  │  │  [Профиль] [Безопасность] [Внешний вид] [Язык]       │   │
│  • Профиль   ←── │  │  ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━  │   │
│    (active,      │  │                                                      │   │
│     любой таб)   │  │  [контент активного таба]                            │   │
│                  │  │                                                      │   │
│  ИНТЕГРАЦИИ      │  │  ────────────────────────────────────────────────    │   │
│  • Каналы        │  │  [Сохранить]  [Отменить]  (если таб с формой dirty) │   │
│                  │  └──────────────────────────────────────────────────────┘   │
│  СПРАВОЧНИКИ     │                                                              │
│  …               │                                                              │
└──────────────────┴──────────────────────────────────────────────────────────────┘
```

#### Изменения в `SettingsSidebar.vue`

Группа АККАУНТ в массиве `GROUPS`:

**До:**
```
АККАУНТ
  { key: 'profile',    label: «Профиль»,       icon: pi-user,      phase: 1 }
  { key: 'security',   label: «Безопасность»,  icon: pi-lock,      phase: 1 }
  { key: 'appearance', label: «Внешний вид»,   icon: pi-sliders-h, phase: 1 }
  { key: 'language',   label: «Язык»,          icon: pi-globe,     phase: 1 }
```

**После:**
```
АККАУНТ
  { key: 'profile', label: «Профиль», icon: pi-user, phase: 1 }
  // isActive: activeSection ∈ PROFILE_TAB_KEYS → пункт подсвечен как active
```

Логика активности пункта:
```ts
// В шаблоне .settings-nav-item:
:class="{ 'settings-nav-item--active': isProfileTabKey(activeSection) }"
// для key === 'profile'
```

При клике на пункт «Профиль»: `setSection('profile')` (переход на под-вкладку «Профиль»
как дефолт). Если пользователь уже находится в `security`/`appearance`/`language` и кликает
«Профиль» в сайдбаре снова — `setSection('profile')`.

#### Новый компонент `SectionProfileTabs.vue`

```
SectionProfileTabs.vue
  ├── .profile-tabs-container (display: flex, flex-direction: column, height: 100%)
  │   ├── .profile-tabs  (PrimeVue Tabs :value="activeTab" @tab-change)
  │   │     TabList
  │   │       Tab value="profile"    «Профиль»
  │   │       Tab value="security"   «Безопасность»
  │   │       Tab value="appearance" «Внешний вид»
  │   │       Tab value="language"   «Язык»
  │   └── .profile-tab-content  (v-if рендеринг)
  │         <SectionProfile    v-if="activeTab==='profile'"    …props />
  │         <SectionSecurity   v-else-if="activeTab==='security'"  …props />
  │         <SectionAppearance v-else-if="activeTab==='appearance'" />
  │         <SectionLanguage   v-else-if="activeTab==='language'"  />
```

Props:
```ts
defineProps<{
  activeTab: ProfileTabKey      // из useSettings.activeSection
  // данные для SectionProfile и SectionSecurity пробрасываются через useProfilePage
}>()

defineEmits<{
  'tab-change': [key: ProfileTabKey]
}>()
```

Таб-переключение:
```ts
function onTabChange(e: TabChangeEvent) {
  emit('tab-change', e.value as ProfileTabKey)
  // → settings.setSection(key) в index.vue
  // → setSection вызовет dirty-guard если форма грязная
  // → router.replace({ query: { section: key } })
}
```

**Рендеринг `v-if` (не `TabPanels`)** — по Ф2-паттерну: каждый дочерний компонент монтируется
только когда его таб активен. Это важно для `SectionSecurity` (держит own TOTP-стейт).

CSS:
```scss
// SectionProfileTabs.vue <style scoped>
.profile-tabs-container {
  display: flex;
  flex-direction: column;
  height: 100%;
  overflow: hidden;
}

.profile-tabs {
  flex-shrink: 0;
  border-bottom: 1px solid $surface-200;
  background: $surface-card;
  padding: 0 $space-6;

  .app-dark & {
    background: var(--p-surface-800);
    border-bottom-color: var(--p-surface-700);
  }

  // line-underline стиль как в SectionDirectories (Ф2)
  :deep(.p-tablist) {
    background: transparent;
    border: none;
  }
  :deep(.p-tab) {
    font-size: $font-size-sm;
    font-weight: $font-weight-medium;
    padding: $space-3 $space-4;
    color: $surface-600;

    &[aria-selected="true"] {
      color: $primary-900;
      font-weight: $font-weight-semibold;
    }

    .app-dark & {
      color: var(--p-surface-400);
      &[aria-selected="true"] {
        color: var(--p-primary-300);
      }
    }
  }
  :deep(.p-tablist-active-bar) {
    background: $primary-900;
    .app-dark & { background: var(--p-primary-300); }
  }
}

.profile-tab-content {
  flex: 1;
  overflow-y: auto;
  min-height: 0;
}
```

#### Изменения в `SettingsPage/index.vue`

```ts
// Импорт нового компонента
import SectionProfileTabs from './components/SectionProfileTabs.vue'

// Хелпер (добавить рядом с isDirectoriesSection)
function isProfileTabSection(key: string): key is ProfileTabKey {
  return isProfileSection(key)
}
```

Шаблон — заменяем 4 отдельных `v-else-if` одним:
```vue
<!-- БЫЛО: -->
<SectionProfile    v-else-if="settings.activeSection.value === 'profile'"    …/>
<SectionSecurity   v-else-if="settings.activeSection.value === 'security'"   …/>
<SectionAppearance v-else-if="settings.activeSection.value === 'appearance'" />
<SectionLanguage   v-else-if="settings.activeSection.value === 'language'"   />

<!-- СТАЛО: -->
<SectionProfileTabs
  v-else-if="isProfileTabSection(settings.activeSection.value)"
  :active-tab="settings.activeSection.value"
  :user="profilePage.user.value"
  :avatar-path="profilePage.avatarPath.value"
  :avatar-uploading="profilePage.avatarUploading"
  :saving-profile="profilePage.savingProfile"
  :save-full-name="profilePage.saveFullName"
  :upload-avatar="profilePage.uploadAvatar"
  :remove-avatar="profilePage.removeAvatar"
  @tab-change="settings.setSection($event)"
/>
```

**Dirty-guard:** работает без изменений. `setSection()` перехватывает `isDirty` до
`router.replace` — `UnsavedChangesDialog` показывается как обычно при переключении
между под-вкладками с несохранёнными формами (profile / appearance / language).
`SectionSecurity` — action-based, `markDirty` не вызывает → guard не мешает.

#### Мобильный Select

```ts
// В mobileSectionOptions заменяем 4 отдельных варианта:
// БЫЛО: { label: 'Профиль', value: 'profile' }, { label: 'Безопасность', value: 'security' }, …
// СТАЛО: оставить все 4 как плоский список — мобильный Select остаётся без изменений.
// Пользователь выбирает конкретный «Безопасность» → setSection('security') → SectionProfileTabs
// показывает нужный таб. Поведение идентично до reorg.
```

Мобильный Select — **не изменяется**. Все 4 ключа остаются отдельными опциями.

---

### Часть B — Аватар-кроп

#### Обоснование нового пакета

**`vue-advanced-cropper`** — явно запрошен юзером. Пакет нет в проекте, требует установки.
Обоснование (новый компонент = новая зависимость):
- Задача: нативный `<canvas>`-кроп с зумом и сдвигом круглой/квадратной области.
- В PrimeVue нет встроенного кроппера; реализовывать самостоятельно нецелесообразно.
- `vue-advanced-cropper` — зрелая (v2), Vue 3 native, без Tailwind, Canvas API. Размер бандла ~50KB gzip.
- Альтернативы (`cropperjs` без Vue-обёртки, `vue-cropperjs` v4 только для Vue 2) хуже.

**Требуется:** `npm install vue-advanced-cropper` в `front/`.

#### UX-флоу аватар-кропа

```
[пользователь нажимает «Загрузить фото»]
  → <input type="file" accept="image/jpeg,image/png,image/webp"> открывается
  → клиентская валидация ДО открытия кропа:
      - тип: не jpeg/png/webp → Toast error «Допустимые форматы: JPEG, PNG, WebP»
      - размер оригинала > 20MB → Toast error «Файл слишком большой (макс. 20 МБ)»
        (лимит на raw до кропа; после кропа цель < 2МБ)
  → при OK → открывается AvatarCropModal (PrimeVue Dialog, modal=true, :draggable="false")

[AvatarCropModal]
  ├── заголовок «Загрузить фото»  (pi pi-user-edit)
  ├── .cropper-body (высота 400px, overflow hidden)
  │   └── <Cropper> (vue-advanced-cropper)
  │         :src="objectURL"
  │         :stencil-component="CircleStencil"  ← круглая область кропа
  │         :stencil-props="{ aspectRatio: 1 }" ← квадратный кроп (1:1)
  │         :resize-image="{ adjustStencil: false }"
  │         background-class="cropper-bg"
  │         ref="cropperRef"
  ├── .cropper-hint  «Перетащите и прокрутите колесо для масштабирования»
  │   (text-muted, font-size-xs, text-center, $space-2 top)
  └── .p-dialog-footer
        [Отмена]  severity=secondary text  → closeModal(), revoke objectURL
        [Сохранить фото]  severity=primary icon=pi-check  :loading="uploading"
          → cropAndUpload()

[cropAndUpload()]
  1. cropperRef.value.getResult() → { canvas }
  2. Downscale если canvas.width > 1024 или canvas.height > 1024:
       создать новый <canvas> 1024×1024 (или пропорционально меньшую сторону),
       ctx.drawImage(canvas, 0, 0, targetW, targetH)
  3. targetCanvas.toBlob(blob => { … }, 'image/jpeg', 0.85)
       — качество 0.85 даёт < 200KB для большинства фото 1024px
       — если blob.size > 2_000_000 (страховка) → повторить с quality 0.7
  4. new File([blob], 'avatar.jpg', { type: 'image/jpeg' })
  5. props.uploadAvatar(file)  → POST /api/profile/avatar
  6. При успехе: closeModal(), revoke objectURL, Toast success «Фото обновлено»
  7. При ошибке: Toast error (текст из BE), диалог остаётся открытым

[после cropAndUpload — успех]
  → userStore обновляется (uploadAvatar обновляет avatarPath через userStore)
  → preview в profile-avatar-row обновляется реактивно (avatarPath computed)
```

#### Wireframe AvatarCropModal (ASCII)

```
┌─────────────────────────────────────────────────────────────┐
│  [pi pi-user-edit]  Загрузить фото              [×]         │
│  ─────────────────────────────────────────────────────────  │
│                                                             │
│  ┌─────────────────────────────────────────────────────┐   │
│  │                   ╭───────╮                         │   │
│  │     ░░░░░░░░░░░░░ │       │ ░░░░░░░░░░░░            │   │  400px
│  │     ░░░░░░░░░░░░░ │  ☺    │ ░░░░░░░░░░░░            │   │
│  │     ░░░░░░░░░░░░░ │       │ ░░░░░░░░░░░░            │   │
│  │                   ╰───────╯                         │   │
│  └─────────────────────────────────────────────────────┘   │
│  Перетащите и прокрутите колесо для масштабирования         │
│  ─────────────────────────────────────────────────────────  │
│  [Отмена]                         [pi pi-check Сохранить]  │
└─────────────────────────────────────────────────────────────┘
```

#### Компонент `AvatarCropModal.vue`

```
front/src/pages/SettingsPage/components/sections/profile/AvatarCropModal.vue
```

Props:
```ts
defineProps<{
  visible: boolean
  imageSrc: string     // objectURL от File
}>()

defineEmits<{
  'update:visible': [v: boolean]
  'upload': [file: File]           // готовый сжатый blob как File
}>()
```

Стейт:
```ts
const cropperRef = ref<CropperInstance | null>(null)
const uploading = ref(false)
```

CSS:
```scss
.cropper-body {
  height: 400px;
  background: var(--p-surface-900);
  overflow: hidden;
  border-radius: $radius-md;

  .app-dark & {
    background: var(--p-surface-950, #0a0a0a);
  }
}

.cropper-hint {
  margin-top: $space-2;
  font-size: $font-size-xs;
  color: $surface-500;
  text-align: center;

  .app-dark & { color: var(--p-surface-400); }
}
```

PrimeVue Dialog:
```vue
<Dialog
  v-model:visible="localVisible"
  :header="t('settings.profile.avatarCrop.title')"
  :modal="true"
  :draggable="false"
  :closable="!uploading"
  :style="{ width: '520px', maxWidth: '95vw' }"
  append-to="body"
>
```

#### Изменения в `SectionProfile.vue`

```vue
<!-- Заменяем прямой onAvatarSelected → cropFlow -->

<input
  ref="avatarInput"
  type="file"
  accept="image/jpeg,image/png,image/webp"
  class="d-none"
  @change="onAvatarFileSelected"
/>
<Button
  icon="pi pi-upload"
  :label="t('profile.avatar.upload')"
  severity="secondary"
  outlined
  size="small"
  :loading="avatarUploading"
  @click="avatarInput?.click()"
/>

<!-- Кроп-модал -->
<AvatarCropModal
  v-model:visible="cropModalVisible"
  :image-src="cropImageSrc"
  @upload="onCropUpload"
/>
```

Новый стейт и функции:
```ts
const cropModalVisible = ref(false)
const cropImageSrc = ref('')

function onAvatarFileSelected(event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0]
  if (!file) return
  ;(event.target as HTMLInputElement).value = ''

  // Клиентская валидация
  const ALLOWED = ['image/jpeg', 'image/png', 'image/webp']
  if (!ALLOWED.includes(file.type)) {
    toast.add({ severity: 'error', summary: t('settings.profile.avatarCrop.invalidType'), life: 4000 })
    return
  }
  if (file.size > 20 * 1024 * 1024) {
    toast.add({ severity: 'error', summary: t('settings.profile.avatarCrop.fileTooLarge'), life: 4000 })
    return
  }

  // Открываем кроп
  cropImageSrc.value = URL.createObjectURL(file)
  cropModalVisible.value = true
}

async function onCropUpload(croppedFile: File) {
  const success = await props.uploadAvatar(croppedFile)
  if (success) {
    cropModalVisible.value = false
    URL.revokeObjectURL(cropImageSrc.value)
    cropImageSrc.value = ''
  }
}
```

> **Важно: `URL.revokeObjectURL`** вызывается при успехе, при закрытии диалога «Отмена»
> и при `onUnmounted` компонента (страховка от утечек памяти).

#### Состояния аватар-блока

| Состояние | Поведение |
|-----------|-----------|
| нет аватара | `<CrmAvatar :name="user.full_name" :size="72" />` + кнопка «Загрузить фото» |
| есть аватар | `<img>` 72px circle + кнопка «Загрузить фото» + кнопка «Удалить» |
| выбор файла с ошибкой типа | Toast error «Допустимые форматы: JPEG, PNG, WebP», модал не открывается |
| выбор файла с ошибкой размера | Toast error «Файл слишком большой (макс. 20 МБ)», модал не открывается |
| кроп-модал открыт | Dialog с Cropper, кнопки Отмена / Сохранить |
| загрузка кропа | `Button :loading="true"` внутри диалога, closable=false |
| загрузка успешна | Toast success «Фото обновлено», диалог закрывается, preview обновляется |
| ошибка загрузки BE | Toast error с текстом из BE, диалог остаётся открытым |
| удаление аватара | `Button :loading="avatarUploading"` → DELETE /api/profile/avatar → Toast |

---

### Часть C — Смена пароля в «Безопасность»

#### Новый блок в `SectionSecurity.vue`

Добавляется **ниже** существующего 2FA-блока, отделён горизонтальным разделителем `<hr>`.

##### Wireframe блока смены пароля (ASCII)

```
┌──────────────────────────────────────────────────────────────────┐
│  [profile-section]                                               │
│  ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━  │
│  <h3>  Смена пароля  </h3>                                       │
│                                                                  │
│  Текущий пароль *                                                │
│  [●●●●●●●●●●●●    ] [eye icon]                                   │
│                                                                  │
│  Новый пароль *                                                  │
│  [●●●●●●●●●●●●    ] [eye icon]                                   │
│  Минимум 8 символов                         (hint text, мелкий) │
│                                                                  │
│  Повторите новый пароль *                                        │
│  [●●●●●●●●●●●●    ] [eye icon]                                   │
│  <small class="error">  Пароли не совпадают  </small>  (если)   │
│                                                                  │
│  [pi pi-key  Сменить пароль]    ← primary, справа               │
│                                                                  │
└──────────────────────────────────────────────────────────────────┘
```

##### Компонент `ChangePasswordForm.vue`

```
front/src/pages/SettingsPage/components/sections/profile/ChangePasswordForm.vue
```

**Action-based** (не save-bar / черновик). Аналог TOTP-блока — каждое действие отдельный
`useMutation.run()`.

Props: нет (использует собственный стейт + прямой API-вызов).

Стейт:
```ts
const currentPassword = ref('')
const newPassword = ref('')
const confirmPassword = ref('')
const showCurrent = ref(false)
const showNew = ref(false)
const showConfirm = ref(false)

// Клиентская валидация
const newPasswordError = computed(() => {
  if (!newPassword.value) return ''
  if (newPassword.value.length < 8) return t('settings.security.password.tooShort')
  return ''
})

const confirmPasswordError = computed(() => {
  if (!confirmPassword.value) return ''
  if (confirmPassword.value !== newPassword.value) return t('settings.security.password.mismatch')
  return ''
})

const isFormValid = computed(
  () =>
    !!currentPassword.value &&
    !!newPassword.value &&
    newPassword.value.length >= 8 &&
    newPassword.value === confirmPassword.value,
)

const changePasswordMutation = useMutation<void>()

async function changePassword() {
  if (!isFormValid.value) return
  await changePasswordMutation.run(
    async () => {
      await profileApi.changePassword({
        current_password: currentPassword.value,
        new_password: newPassword.value,
        new_password_confirmation: confirmPassword.value,
      })
    },
    {
      onSuccess: () => {
        toast.add({ severity: 'success', summary: t('settings.security.password.successToast'), life: 4000 })
        // Сброс формы
        currentPassword.value = ''
        newPassword.value = ''
        confirmPassword.value = ''
      },
      onError: (error) => {
        const msg = getApiErrorMessage(error, t('errors.server_error'))
        // Если BE вернул 422 с ключом current_password → показываем под полем
        const validationErrors = getValidationErrors(error)
        if (validationErrors?.current_password) {
          currentPasswordApiError.value = validationErrors.current_password[0]
        } else {
          toast.add({ severity: 'error', summary: msg, life: 5000 })
        }
      },
    },
  )
}
```

`currentPasswordApiError` — `ref<string>('')`, сбрасывается при изменении `currentPassword`.

Шаблон (скелет):
```vue
<div class="change-password-form">
  <div class="change-password-form__field">
    <label class="profile-field__label">
      {{ t('settings.security.password.currentLabel') }} <span class="required">*</span>
    </label>
    <Password
      v-model="currentPassword"
      :feedback="false"
      :toggle-mask="true"
      class="w-100"
      :invalid="!!currentPasswordApiError"
      @input="currentPasswordApiError = ''"
    />
    <small v-if="currentPasswordApiError" class="login-field__error">
      {{ currentPasswordApiError }}
    </small>
  </div>

  <div class="change-password-form__field">
    <label class="profile-field__label">
      {{ t('settings.security.password.newLabel') }} <span class="required">*</span>
    </label>
    <Password
      v-model="newPassword"
      :feedback="false"
      :toggle-mask="true"
      class="w-100"
      :invalid="!!newPasswordError && !!newPassword"
    />
    <small v-if="newPasswordError && newPassword" class="login-field__error">
      {{ newPasswordError }}
    </small>
    <small v-else class="profile-field__hint">
      {{ t('settings.security.password.hint') }}
    </small>
  </div>

  <div class="change-password-form__field">
    <label class="profile-field__label">
      {{ t('settings.security.password.confirmLabel') }} <span class="required">*</span>
    </label>
    <Password
      v-model="confirmPassword"
      :feedback="false"
      :toggle-mask="true"
      class="w-100"
      :invalid="!!confirmPasswordError && !!confirmPassword"
    />
    <small v-if="confirmPasswordError && confirmPassword" class="login-field__error">
      {{ confirmPasswordError }}
    </small>
  </div>

  <div class="change-password-form__actions">
    <Button
      icon="pi pi-key"
      :label="t('settings.security.password.submitBtn')"
      :loading="changePasswordMutation.isLoading.value"
      :disabled="!isFormValid"
      @click="changePassword"
    />
  </div>
</div>
```

> Используем PrimeVue `Password` (не `InputText`) — он уже даёт `toggleMask` (иконка глаза)
> и `feedback=false` (отключаем score-индикатор стрелочка). `Password` уже в стеке проекта.

CSS:
```scss
.change-password-form {
  display: flex;
  flex-direction: column;
  gap: $space-4;
  max-width: 400px;

  &__actions {
    display: flex;
    justify-content: flex-end;
    padding-top: $space-2;
  }
}

.profile-field__hint {
  font-size: $font-size-xs;
  color: $surface-500;
  margin-top: $space-1;

  .app-dark & { color: var(--p-surface-400); }
}
```

**Требуется backend:** `POST /api/me/password` (backend-specialist делает отдельно).
Параметры запроса: `{ current_password: string, new_password: string, new_password_confirmation: string }`.
Ответ 200 (empty body) на успех; 422 с `{ errors: { current_password: ['Неверный пароль'] } }` при ошибке.

**Добавить в `profileApi`:**
```ts
interface ChangePasswordRequest {
  current_password: string
  new_password: string
  new_password_confirmation: string
}

async changePassword(data: ChangePasswordRequest): Promise<void> {
  await apiClient.post('/api/me/password', data)
}
```

##### Интеграция в `SectionSecurity.vue`

```vue
<!-- В конце <div class="section-security">, после блока 2FA: -->
<hr class="security-divider" />
<div class="profile-section">
  <h3 class="profile-section__title">{{ t('settings.security.password.sectionTitle') }}</h3>
  <ChangePasswordForm />
</div>
```

```scss
.security-divider {
  border: none;
  border-top: 1px solid $surface-200;
  margin: $space-6 0;

  .app-dark & { border-top-color: var(--p-surface-700); }
}
```

##### Состояния блока смены пароля

| Состояние | Поведение |
|-----------|-----------|
| idle | все поля пустые, кнопка disabled |
| клиентская валидация | `<small class="login-field__error">` под полем (inline, немедленно при blur/input) |
| `!isFormValid` | `Button :disabled="true"` |
| отправка | `Button :loading="true"`, поля read-only (визуально) |
| успех | Toast success «Пароль успешно изменён» · форма сбрасывается |
| ошибка «неверный текущий» | `<small class="login-field__error">` под «Текущий пароль» (из 422 BE) |
| ошибка сети/сервера | Toast error с текстом ошибки |

**`markDirty` НЕ вызывается.** Блок полностью транзакционный — грязность нет, dirty-guard
не мешает переключению под-вкладок.

---

### Часть D — Справочно: Админ-сброс пароля в «Пользователи»

> Реализуется отдельным FE-проходом в рамках раздела «Пользователи» (`SysTabUsers.vue` /
> `UsersPage`). Описание — только набросок; детальное ТЗ при старте того прохода.

На строке пользователя в DataTable добавляется действие в меню «⋮» (или явная кнопка):
«Сбросить пароль». Клик → `ConfirmDialog` «Сбросить пароль для {{name}}? Пользователь
получит новый временный пароль». «Подтвердить» → `POST /api/admin/users/{id}/reset-password`
→ BE генерирует случайный пароль (бэкенд-ответственность) → BE возвращает `{ password: '…' }`
→ **один раз показываем модал** с текстом нового пароля:

```
┌─────────────────────────────────────────────────────┐
│  Новый пароль пользователя                [×]       │
│  ─────────────────────────────────────────────────  │
│  ⚠ Сохраните пароль — он больше не будет показан.  │
│                                                     │
│  [  xK9!mP2qRw4v  ]  [pi pi-copy Скопировать]     │
│                                                     │
│  [Закрыть]                                          │
└─────────────────────────────────────────────────────┘
```

Поле пароля read-only (`InputText :disabled`). Кнопка «Скопировать» → `navigator.clipboard.writeText()`
→ иконка меняется на `pi pi-check` на 2 секунды. После закрытия модала пароль нигде не сохраняется.
**Плейнтекст-видимость существующих паролей не делаем** — только разовый показ нового.

---

### PrimeVue-компоненты Ф5

| Компонент | Где | Props/особенности |
|-----------|-----|-------------------|
| `Tabs` + `Tab` + `TabList` | `SectionProfileTabs.vue` | `:value="activeTab"`, `@tab-change`, line-underline |
| `Dialog` | `AvatarCropModal.vue` | `:modal="true"`, `:draggable="false"`, `append-to="body"` |
| `Password` | `ChangePasswordForm.vue` | `:feedback="false"`, `:toggle-mask="true"` |
| `Button` | `ChangePasswordForm.vue` | `icon="pi pi-key"`, `:loading`, `:disabled="!isFormValid"` |
| `Button` | `AvatarCropModal.vue` | `icon="pi pi-check"`, `:loading="uploading"` |
| `Toast` | оба новых компонента | `useToast()`, глобальный |
| `Cropper` + `CircleStencil` | `AvatarCropModal.vue` | из `vue-advanced-cropper` |

> **НЕ добавляем:** нового Toast-рендера; `AvatarCropModal` и `ChangePasswordForm` используют
> глобальный `useToast()` — Toast монтирован в `SettingsPage/index.vue`.

---

### i18n-ключи Ф5

#### RU (обязательно)

```json
{
  "settings": {
    "sections": {
      "profile": { "title": "Профиль", "desc": "Фото, имя, безопасность, интерфейс" }
    },

    "profile": {
      "tabs": {
        "profile":    "Профиль",
        "security":   "Безопасность",
        "appearance": "Внешний вид",
        "language":   "Язык"
      },
      "avatarCrop": {
        "title":        "Загрузить фото",
        "hint":         "Перетащите и прокрутите колесо для масштабирования",
        "saveBtn":      "Сохранить фото",
        "cancelBtn":    "Отмена",
        "invalidType":  "Допустимые форматы: JPEG, PNG, WebP",
        "fileTooLarge": "Файл слишком большой (макс. 20 МБ)",
        "uploadSuccess":"Фото обновлено"
      }
    },

    "security": {
      "password": {
        "sectionTitle":  "Смена пароля",
        "currentLabel":  "Текущий пароль",
        "newLabel":      "Новый пароль",
        "confirmLabel":  "Повторите новый пароль",
        "hint":          "Минимум 8 символов",
        "submitBtn":     "Сменить пароль",
        "tooShort":      "Пароль должен содержать минимум 8 символов",
        "mismatch":      "Пароли не совпадают",
        "wrongCurrent":  "Неверный текущий пароль",
        "successToast":  "Пароль успешно изменён"
      }
    }
  }
}
```

#### EN (задел)

```json
{
  "settings": {
    "sections": {
      "profile": { "title": "Profile", "desc": "Photo, name, security, interface" }
    },
    "profile": {
      "tabs": {
        "profile": "Profile", "security": "Security",
        "appearance": "Appearance", "language": "Language"
      },
      "avatarCrop": {
        "title": "Upload photo", "hint": "Drag and scroll to zoom",
        "saveBtn": "Save photo", "cancelBtn": "Cancel",
        "invalidType": "Allowed formats: JPEG, PNG, WebP",
        "fileTooLarge": "File too large (max 20 MB)",
        "uploadSuccess": "Photo updated"
      }
    },
    "security": {
      "password": {
        "sectionTitle": "Change password",
        "currentLabel": "Current password",
        "newLabel": "New password",
        "confirmLabel": "Confirm new password",
        "hint": "At least 8 characters",
        "submitBtn": "Change password",
        "tooShort": "Password must be at least 8 characters",
        "mismatch": "Passwords do not match",
        "wrongCurrent": "Current password is incorrect",
        "successToast": "Password changed successfully"
      }
    }
  }
}
```

---

### Interactions Ф5 — таблица

| Элемент | Действие | Результат | Endpoint |
|---------|----------|-----------|----------|
| Пункт «Профиль» в сайдбаре | click (любое состояние) | `setSection('profile')` → `SectionProfileTabs` таб profile | — |
| Пункт «Профиль» (dirty таб) | click | `UnsavedChangesDialog` → «Покинуть» = discard + setSection | — |
| Таб «Безопасность» | click | `setSection('security')` → `SectionProfileTabs` показывает `SectionSecurity` | — |
| Таб «Внешний вид» (dirty profile) | click | `UnsavedChangesDialog` → confirm/stay | — |
| «Загрузить фото» | click | `<input type="file">` открывается | — |
| file input change (invalid type) | — | Toast error «Допустимые форматы…» | — |
| file input change (> 20MB) | — | Toast error «Файл слишком большой…» | — |
| file input change (OK) | — | objectURL → `AvatarCropModal` открывается | — |
| «Отмена» в кроп-моdale | click | диалог закрывается, `URL.revokeObjectURL` | — |
| «Сохранить фото» (кроп) | click | `getResult()` → downscale → toBlob → uploadAvatar | `POST /api/profile/avatar` |
| upload success | — | Toast success, диалог закрывается, `avatarPath` обновляется | — |
| upload error | — | Toast error, диалог остаётся открытым | — |
| «Текущий пароль» поле | input | `currentPasswordApiError = ''` | — |
| «Сменить пароль» | click (`isFormValid=false`) | кнопка disabled — нет эффекта | — |
| «Сменить пароль» | click (`isFormValid=true`) | `POST /api/me/password` | `POST /api/me/password` |
| password change success | — | Toast success «Пароль успешно изменён», форма сбрасывается | — |
| password change 422 current | — | `<small>` под «Текущий пароль» «Неверный текущий пароль» | — |
| password change error иное | — | Toast error | — |

---

### Что переиспользуется

| Что | Откуда | Как |
|-----|--------|-----|
| `SectionProfile.vue` | существующий | расширяется: новый flow аватара + `AvatarCropModal` |
| `SectionSecurity.vue` | существующий | расширяется: + `ChangePasswordForm` + `<hr>` |
| `SectionAppearance.vue` | существующий | 1-в-1, только переселяется в под-вкладку |
| `SectionLanguage.vue` | существующий | 1-в-1, только переселяется в под-вкладку |
| `UnsavedChangesDialog.vue` | существующий (Ф1) | без изменений |
| `useSettings.ts` | существующий | минимальное расширение: `PROFILE_TAB_KEYS` + `isProfileSection()` |
| `useMutation` | существующий | в `ChangePasswordForm.vue` |
| `profileApi` | существующий | + метод `changePassword` |
| `Password` PrimeVue | уже в стеке | в `ChangePasswordForm.vue`, `feedback=false` |
| `Tabs`/`Tab`/`TabList` | уже в стеке (Ф2) | CSS-паттерн line-underline из `SectionDirectories.vue` |
| `Toast` (глобальный) | существующий | `useToast()` без нового рендера |

**Новые компоненты (3):** `SectionProfileTabs.vue` · `AvatarCropModal.vue` · `ChangePasswordForm.vue`

**Новый пакет (1):** `vue-advanced-cropper` (явно запрошен юзером; обоснование выше в §B).

---

### Открытые вопросы Ф5

1. **ОВ-1: `POST /api/me/password` — backend-блокер.** Эндпоинт не существует. `ChangePasswordForm`
   не работает до его реализации. Передать `backend-specialist`.

2. **ОВ-2: Форма сброса — сессия после смены пароля.** Нужно ли разлогинивать другие сессии
   после смены пароля (`revokeTokens`)? Вопрос к PM и backend. Если да — BE-задача.

3. **ОВ-3: Сайдбар АККАУНТ — 1 пункт или 4?** ТЗ предлагает схлопнуть в 1 пункт «Профиль».
   Если PM решит, что 4 пункта в сайдбаре удобнее (прямой переход без под-вкладок) —
   `SectionProfileTabs` не нужен; под-вкладки не нужны; только A–C (аватар-кроп и пароль).
   Нужен апрув PM.

4. **ОВ-4: CircleStencil vs квадратный.** ТЗ предлагает CircleStencil (круглый, 1:1) для
   аватара. Если дизайн предполагает квадратный аватар — поменять на `RectangleStencil`
   с `aspectRatio: 1`. Нужно подтверждение.

5. **ОВ-5: Сжатие до 512px vs 1024px.** ТЗ предлагает сторону ≤1024px. Если место хранения
   критично — уменьшить до 512px. Вопрос к PM/backend (ограничения S3-бакета или CDN).

---

### Статус реализации Ф5

**Pending.** Ожидает:
- апрув PM по ОВ-3 (1 пункт в сайдбаре vs 4)
- реализации `POST /api/me/password` (backend-specialist, ОВ-1)
- `npm install vue-advanced-cropper` во `front/`

*ТЗ Ф5 готово. Передавай `frontend-specialist`. Если есть правки — кидай мне.*
