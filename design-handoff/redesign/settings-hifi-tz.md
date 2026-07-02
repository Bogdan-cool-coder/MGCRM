# ТЗ-дельта: Настройки → hi-fi редизайн (этап 2, визуал)

**Зачем:** довести уже реализованную unified `/settings?section=` до hi-fi эталона `settings.html`
— hero-профиль, header theme-toggle, density-сегмент, обновлённые карточки/списки секций +
пересборка «Сброс системы» в выборочный wipe по 9 категориям.
**Где в коде:** `front/src/pages/SettingsPage/` (index.vue + дети). Скоуп узкий — не трогаем
UsersPage/UsersTable, AccessControl-табы и Directory-табы по существу (у них своя реализация),
только визуальные акценты и обёртки, перечисленные ниже.
**Эталон:** `design-handoff/redesign/settings.html` (обе темы через toggle), JSX-референсы
`users-section.jsx` / `access-section.jsx` / `system-section.jsx` — стек не копируем, берём визуал.
**Токены dark:** те же, что в `design-handoff/redesign/dark-theme-decisions.md` (этап 1).

> **Принцип дельты:** функциональность НЕ меняется, кроме блока «Сброс системы» (§7). Всё
> остальное — чистый визуальный upgrade поверх текущих компонентов. Никаких литералов hex/px —
> только `$scss-var` / `var(--p-*)` / `var(--mg-*)`. Обе темы обязательны.

---

## 0. Сводка визуальных дельт по секциям (TL;DR)

| Секция | Текущее состояние | hi-fi дельта |
|--------|-------------------|--------------|
| **Shell (index.vue)** | `PageHeader` (cog + «Настройки»), sidebar 240px, detail-панель | + **theme-toggle** (☾/☀) в правый край шапки; sidebar → **поиск по пунктам** сверху; контент центрируется `max-width` 680/1080 |
| **Sidebar** | таблетки, active navy-инвертированный, danger-текст уже есть | + поле поиска (фильтр по label); + `meta`-счётчик у «Пользователи»; danger-пункт «Сброс» — как есть |
| **Профиль (hero)** | avatar-row 72px + инпуты, без hero | **NEW hero-карточка** — navy-градиент + аватар 72 + имя + бейдж роли + email + «Изменить фото»; низ — мета-строка (вход / 2FA / язык) |
| **Профиль → Личные данные** | 3 инпута в `row g-4`, save-bar | обернуть в **Card** «Личные данные» + desc; попарные поля Имя/Фамилия, Телефон/Email(disabled), Должность(disabled) |
| **Безопасность** | (проверить SectionSecurity) | Card + 3 `SettingRow` (Пароль / 2FA-switch / Сессии), плашка-иконка 34×34 |
| **Внешний вид** | Тема(SelectButton), NavMode-карточки, QuickActions | + **density-сегмент** Компактная/Просторная; тему оставить, акцент-свотчи — **ОВ-2** (не реализовывать без апрува) |
| **Язык** | список RU/EN(/AR?) | Card + строки: плашка-код 30×30 + название + radio navy |
| **Каналы связи** | (SectionChannels) | карточки-каналы 44×44 плашка + статус-тег + действие; TG подключён/отключить, Email/WA — «Скоро» opacity .6 |
| **Справочники** | 11 таб-справочников, DataTable | визуал уже близок; сверить таб-бар underline + toolbar (Редактировать/Добавить) |
| **Пользователи** | embedded UsersPage + toolbar | + бейдж-счётчик у заголовка, аватар-градиент по роли (визуал UsersTable — вне скоупа, отметить) |
| **Доступ и оргструктура** | 3 таба (Depts/Roles/Visibility) | визуал уже реализован; сверить инфо-баннеры + моно-код прав |
| **Журнал автоматизаций** | (SysTabAutomationRuns) | сегмент-фильтр со счётчиками Все/Успешно/Ошибка/Пропущено + «Обновить»; таблица со статус-тегами |
| **Сброс системы** | hero-баннер + full-wipe диалог | **ПОЛНАЯ ПЕРЕСБОРКА** → выборочный wipe по 9 категориям (§7) |

---

## 1. Shell — `index.vue` + header theme-toggle

### Wireframe
```
┌──────────────────────────────────────────────────────────────────┐
│ [cog] Настройки                                       [ ☾ ] тема    │  ← PageHeader + #actions
├───────────────┬──────────────────────────────────────────────────┤
│ [🔍 Поиск…  ]  │  ┌─ контент, max-width 680 (1080 users/access) ─┐ │
│               │  │  центрирован margin:0 auto                    │ │
│ АККАУНТ        │  │                                              │ │
│  • Профиль     │  │                                              │ │
│ ИНТЕГРАЦИИ      │  └──────────────────────────────────────────────┘ │
│  • Каналы      │                                                    │
│ СИСТЕМА         │                                                    │
│  • Пользоват.14 │                                                    │
│  • Сброс (danger)                                                   │
└───────────────┴──────────────────────────────────────────────────┘
```

### Зоны и компоненты
| Зона | Компонент / элемент | Props / атрибуты |
|------|---------------------|-----------------|
| Шапка | `PageHeader` | `icon="pi pi-cog"` `:title="t('settings.pageTitle')"`; slot `#actions` |
| Theme-toggle | `Button` в `#actions` | `text rounded` · `:icon="theme==='dark' ? 'pi pi-sun' : 'pi pi-moon'"` · `@click="themeStore.toggle()"` · `aria-label` |
| Detail-контейнер | обёртка `.settings-page__detail-inner` | `max-width` 680 (по умолч.) / 1080 (users, access-control) · `margin: 0 auto` |

**Дельта:**
- `PageHeader` уже поддерживает `#actions` — добавить туда одну кнопку-toggle. Иконка `pi-moon` в
  light (переключить в тёмную), `pi-sun` в dark. Toggle идёт через существующий `useThemeStore`
  (тот же, что в SectionAppearance) — тема переключается мгновенно, без save-bar.
- Detail-панель: обернуть контент в inner-контейнер с `max-width` и авто-центрированием. Ширина
  зависит от активной секции: `users` / `access-control` → `$settings-detail-wide` (1080px),
  остальное → `$settings-detail-narrow` (680px). Значения ширины добавить как SCSS-константы (не
  токены-цвета — это layout-константы, допустимы с комментарием).

### Токены
- Шапка: `$space-4 $space-6` (как в текущем PageHeader).
- Detail padding: `$space-6`.
- Фон detail: `$surface-50` / dark `var(--p-surface-50)` (как сейчас).

---

## 2. Sidebar — `SettingsSidebar.vue`

### Дельта (2 добавления, остальное сохранить)
1. **Поле поиска сверху** (над первой группой). Фильтрует пункты по подстроке в переведённом
   label. Пустой результат группы → группа скрывается.
2. **`meta`-счётчик** справа от label (у «Пользователи» — число активных). Опциональное поле в
   `SettingsSection` (`meta?: number | string`).

### Зоны и компоненты
| Зона | Элемент | Атрибуты |
|------|---------|----------|
| Поиск | `IconField` + `InputText` (или нативный, как сейчас у секции) | `iconPosition="left"` `pi pi-search`; `placeholder="settings.nav.searchPlaceholder"`; высота 36; фон `$surface-50` |
| Пункт | `<button class="settings-nav-item">` (как есть) | + `<span class="settings-nav-item__meta">` перед link-icon |

**Что НЕ трогаем:** active-состояние (navy-инвертированное, `box-shadow: inset 3px`), danger-текст
у «Сброс системы», линк-иконку у «Воронка продаж», role-фильтрацию `visibleGroups` — всё уже
реализовано корректно и совпадает с эталоном.

### Состояния
- **empty (поиск без совпадений):** под полем поиска — строка `settings.nav.searchEmpty`
  («Ничего не найдено»), мелкий muted-текст `$font-size-xs` `$surface-500`.

### Токены
- Поиск-поле: `height: 36px`, `border: 1px solid $surface-300` (dark `var(--p-surface-300)`),
  `border-radius: $radius-md`, `background: $surface-50`, паддинг `0 $space-3`.
- `meta`: `$font-size-2xs` `$font-weight-bold` `$surface-500`.

---

## 3. Профиль — hero-карточка (NEW) + `SectionProfile.vue`

Самая крупная визуальная дельта. Сейчас `SectionProfile` — плоский avatar-row + инпуты. Нужна
**hero-карточка** над таб-баром + инпуты-в-Card.

### Wireframe
```
┌─────────────────────────────────────────────────────────────┐  ← hero Card, radius-lg, overflow hidden
│▓▓▓ navy-градиент ▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓│
│▓ (БЯ)   Богдан Ядыкин  [Администратор]        [📷 Изменить]▓│
│▓ 72px   b.yadykin@macroglobal.tech              фото       ▓│
│─────────────────────────────────────────────────────────────│  ← мета-строка (белый фон)
│ 🕐 Вход: сегодня 09:12   ✔ 2FA включена   🌐 Русский         │
└─────────────────────────────────────────────────────────────┘
[ Профиль ][ Безопасность ][ Внешний вид ][ Язык ]     ← таб-бар (есть)
┌─ Card «Личные данные» ──────────────────────────────────────┐
│ Имя [____]        Фамилия [____]                            │
│ Телефон [____]    Email [____] (disabled)                   │
│ Должность [____] (disabled)                                 │
│                              [Отмена]  [✓ Сохранить]         │
└─────────────────────────────────────────────────────────────┘
```

### Место монтирования
Hero-карточка рендерится **внутри `SectionProfileTabs.vue`** над таб-баром (виден на всех 4 под-
вкладках, как в эталоне — hero стоит над subtabs и не меняется при переключении). Либо новый
подкомпонент `ProfileHeroCard.vue` в `sections/profile/`, монтируется в `SectionProfileTabs`.

### Зоны и компоненты hero
| Зона | Компонент / элемент | Props / атрибуты |
|------|---------------------|-----------------|
| Верх (градиент) | `<div class="profile-hero__top">` | навы-градиент; padding `$space-5 $space-6`; flex gap `$space-4` |
| Аватар | `EntityAvatar` | `:name="user.full_name"` `:pixel-size="72"` `on-brand` (полупрозр. белый фон на navy); если есть `avatarPath` — `<img>` как сейчас |
| Имя | `<span>` | `$font-size-xl` `$font-weight-semibold`, цвет `#fff` (на градиенте — brand-инвариант) |
| Роль | `Tag` | `severity="secondary"`; на navy — полупрозрачный белый (кастом класс, `rgba(255,255,255,.18)` — brand-инвариант) |
| Email | `<div>` | `rgba(255,255,255,.82)` (brand-инвариант), `$font-size-sm` |
| «Изменить фото» | `Button` | `outlined size="small"` `icon="pi pi-camera"` `:label="t('profile.avatar.upload')"`; полупрозр. белый на navy → открывает `AvatarCropModal` (существует) |
| Мета-строка | `<div class="profile-hero__meta">` | белый фон `$surface-card`; padding `$space-3 $space-6`; flex gap `$space-6` wrap |
| Мета-пункт «Вход» | icon `pi-clock` + текст | `$surface-600`; `white-space: nowrap` |
| Мета-пункт «2FA» | icon `pi-verified` + текст | `success`-цвет `var(--p-green-600)` / dark адаптив; текст «2FA включена/отключена» по `user` |
| Мета-пункт «Язык» | icon `pi-globe` + текст | `$surface-600`; текущая локаль |

> **Градиент — исключение.** Токен-дисциплина запрещает градиенты, НО hero navy-градиент — это
> прямой бренд-элемент из эталона (`linear-gradient(100deg, $primary-900, $primary-800)`). В dark
> `$primary-*` ремапится (см. dark-theme-decisions §a: `#172747→#4C7DF0`, `#1F2F5A→#6E99FF`) —
> градиент станет сине-акцентным, это ожидаемо. **Реализовать через SCSS-переменные** `$primary-900`
> / `$primary-800` (НЕ литералы), с `// brand hero gradient` комментарием и `stylelint-disable`
> для правила gradient если оно есть. Это единственное допустимое отступление, обосновано эталоном.

### Card «Личные данные» (дельта над текущими инпутами)
- Обернуть текущий `row g-4` в PrimeVue `Card` (или div-карточку `$surface-card` + `$radius-lg` +
  `$shadow-sm`, padding `$space-5 $space-6`) с заголовком «Личные данные» + desc «Как вас видят
  коллеги в системе».
- Поля попарно: Имя / Фамилия (сейчас одно `full_name` — **ОВ-1**: сплит на 2 поля требует backend
  или оставить одно `full_name` full-width). Телефон / Email(disabled). Должность(disabled).
- Save-bar — как есть (`.settings-save-bar`), только внутри Card или под ним.

### Токены hero
- `$radius-lg` (карточка), `overflow: hidden`.
- Аватар-бордер на navy: `3px solid rgba(255,255,255,.5)` (brand-инвариант).
- Мета-иконки: `$font-size-sm`, muted.
- dark: hero-top через ремап `$primary-*`; мета-строка `var(--p-surface-100)` фон.

### i18n
```json
{
  "ru": {
    "settings.profile.hero.lastLogin": "Вход: {when}",
    "settings.profile.hero.twoFaOn": "2FA включена",
    "settings.profile.hero.twoFaOff": "2FA отключена",
    "settings.profile.personalData.title": "Личные данные",
    "settings.profile.personalData.desc": "Как вас видят коллеги в системе"
  },
  "en": {
    "settings.profile.hero.lastLogin": "Login: {when}",
    "settings.profile.hero.twoFaOn": "2FA enabled",
    "settings.profile.hero.twoFaOff": "2FA disabled",
    "settings.profile.personalData.title": "Personal data",
    "settings.profile.personalData.desc": "How colleagues see you in the system"
  }
}
```

---

## 4. Внешний вид — density-сегмент (NEW) + `SectionAppearance.vue`

### Дельта
Добавить блок **«Плотность интерфейса»** — сегмент-контрол Компактная / Просторная. Использует
density-токены `--mg-row-py` / `.mg-cozy` из `spacing.css` (компактная = default `:root`, просторная
= класс `.mg-cozy` на корне приложения).

### Зоны и компоненты
| Зона | Компонент / элемент | Props / атрибуты |
|------|---------------------|-----------------|
| Заголовок блока | `<h3 class="profile-section__title">` | «Плотность интерфейса» (как др. блоки секции) |
| Описание | `<p>` | «Насколько плотно расположены строки в таблицах, списках и на досках…» `$surface-500` `max-width` ~460 |
| Сегмент | `SelectButton` | `:options` `[{label:'Компактная',value:'compact'},{label:'Просторная',value:'cozy'}]` `optionLabel optionValue`; активная — белая пилюля `$shadow-sm` (тот же паттерн, что theme-SelectButton в этой секции) |

### Поведение
- Density хранится в новом Pinia-стор (`useDensityStore`) с persist в localStorage, либо расширить
  `useLayoutStore`. Значение `cozy` вешает класс `.mg-cozy` на корневой `<div class="app-dark?">`
  (тот же уровень, где `.app-dark`). Класс включает cozy-переопределения токенов.
- Меняется мгновенно (preview), уходит в общий `isDirty` save-bar секции «Внешний вид» — как тема и
  nav-mode сейчас.

> **ОВ-2 (акцент-свотчи).** Эталон `AppearanceTab` показывает блок «Акцент» (4 круглых свотча) —
> **НЕ реализовывать.** Смена primary-акцента ломает бренд-инвариант `$primary-900 = #172747` и всю
> DS. Опустить блок «Акцент» из ТЗ. Если бизнес захочет — отдельная большая задача с backend.

### i18n
```json
{
  "ru": {
    "settings.appearance.density.title": "Плотность интерфейса",
    "settings.appearance.density.desc": "Насколько плотно расположены строки в таблицах, списках и на досках. «Компактная» — больше данных на экране, «Просторная» — крупнее и воздушнее.",
    "settings.appearance.density.compact": "Компактная",
    "settings.appearance.density.cozy": "Просторная"
  },
  "en": {
    "settings.appearance.density.title": "Interface density",
    "settings.appearance.density.desc": "How tightly rows are packed in tables, lists and boards. Compact fits more on screen, Cozy is larger and airier.",
    "settings.appearance.density.compact": "Compact",
    "settings.appearance.density.cozy": "Cozy"
  }
}
```

---

## 5. Каналы связи / Язык / Безопасность — сверка (мелкие дельты)

Эти секции уже реализованы. Сверить с эталоном и подтянуть только визуал:

- **Каналы связи (`SectionChannels`):** карточки-каналы с плашкой-иконкой 44×44 (`$primary-100`
  фон, navy-иконка `pi-telegram`/`pi-envelope`/`pi-whatsapp`), название 14/600 + статус-строка.
  TG подключён → `Tag` «Подключено» (success) + `@username` navy + кнопка «Отключить»
  (`severity="danger" outlined` `pi-unlink`). Email/WhatsApp → `opacity: .6`, статус «—», `Tag`
  «Скоро» (secondary). Проверить, что модалки TG (подключение/отключение) существуют.
- **Язык (`SectionLanguage`):** Card «Язык интерфейса» + строки: плашка-код 30×30
  (`$surface-muted` фон, `$font-size-2xs` `$font-weight-bold`) + название + radio-кружок navy у
  выбранного. Проверить, есть ли AR — **ОВ-3**.
- **Безопасность (`SectionSecurity`):** Card + 3 `SettingRow` — Пароль (`pi-key` → модалка смены,
  `ChangePasswordForm` есть) / 2FA (`pi-lock` + Switch) / Активные сессии (`pi-desktop`). Плашка-
  иконка 34×34 `$primary-100`. Сверить, что паттерн SettingRow (плашка + title + sub + контрол
  справа, borderBottom-разделитель) применён.

---

## 6. Пользователи / Доступ / Журнал автоматизаций — сверка

- **Пользователи (`SysTabUsers` → embedded UsersPage):** заголовок + **бейдж-счётчик** активных +
  подзаголовок. Аватар-градиент по роли и таблица — внутри UsersPage/UsersTable (вне узкого
  скоупа этой задачи; отметить как «визуал таблицы — отдельно, если потребуется»). Здесь — только
  toolbar (Редактировать / Добавить) и `max-width: 1080`.
- **Доступ и оргструктура (`SysTabAccessControl`):** 3 таба уже реализованы (Departments+OrgChart,
  RolesMatrix, VisibilityScope). Сверить: инфо-баннер «Роль admin всегда получает все права…»
  (`Message severity="info"`), моно-код прав (`crm.view` — `$font-family-mono`, плашка `$surface-
  hover`), баннер-предупреждение на вкладке Видимость. `max-width: 1080`.
- **Журнал автоматизаций (`SysTabAutomationRuns`):** сегмент-фильтр по статусу со счётчиками
  (Все N · Успешно N · Ошибка N · Пропущено N — `SelectButton` с бейджами) + кнопка «Обновить»
  (`Button outlined icon="pi pi-refresh"`). Таблица (`DataTable` со скроллом, `minWidth` ~860):
  Время (моно) · Автоматизация · Триггер (`Tag` + иконка) · Объект (ссылка) · Действие · Статус
  (`Tag` success/danger/secondary). Триггер-теги и статус-теги по маппингу из `system-section.jsx`
  (`S_TRIG`, `S_STATUS`).

Эти три — сверочные, не переписываем логику. Если визуал уже совпадает — no-op.

---

## 7. Сброс системы — ПОЛНАЯ ПЕРЕСБОРКА (выборочный wipe по 9 категориям)

**ДЕСТРУКТИВНАЯ фича.** Текущий `SectionSystemReset` — hero-баннер + full-wipe диалог с фразой.
Заменяем на **выбор категорий галочками** (эталон `system-section.jsx → ResetTab`). Backend-контракт
(какие категории, endpoint, counts) делает `backend-architect` отдельно — здесь только UI/UX +
предохранители.

### Wireframe
```
Сброс системы
Выборочное удаление данных. Настройки аккаунта и учётные записи сохраняются.

┌─ КРАСНАЯ ЗОНА (danger-баннер) ──────────────────────────────┐
│ ⚠ Операция необратима. Выбранные данные удалятся безвозвратно.│
│   Перед сбросом рекомендуем выгрузить резервную копию.       │
└─────────────────────────────────────────────────────────────┘
┌─ Card выбора ───────────────────────────────────────────────┐
│ ☑ Выбрать всё                          Выбрано: N из 9       │  ← master-строка (hover-фон)
│─────────────────────────────────────────────────────────────│
│ ☐ [🗂] Сделки          Все сделки и история      [1 248 зап.]│  ← строка-категория
│ ☑ [👤] Контакты        Контактные лица…          [3 972 зап.]│  ← выбранная = navy-подсветка
│ ☐ [🏢] Компании        Организации-клиенты       [864 зап.] │
│ ... (9 категорий) ...                                        │
└─────────────────────────────────────────────────────────────┘
┌─ Card подтверждения ────────────────────────────────────────┐
│ Подтверждение                                               │
│ Чтобы удалить выбранные данные (N), введите СБРОСИТЬ ниже.   │
│ [_СБРОСИТЬ______]                        [🗑 Удалить (N)]     │  ← кнопка danger, disabled пока !word || N=0
└─────────────────────────────────────────────────────────────┘
   ↓ (клик «Удалить»)
┌─ ФИНАЛЬНАЯ МОДАЛКА ──────────┐
│ ⚠ Удалить данные?            │
│ Будет безвозвратно удалено N │
│ категории: Сделки, Контакты… │
│        [Отмена] [🗑 Удалить]  │
└──────────────────────────────┘
   ↓ Toast «Выбранные данные удалены»
```

### 9 категорий (из `system-section.jsx S_CATS`)
| key | icon | Название (RU) | Описание | counts |
|-----|------|---------------|----------|--------|
| `deals` | `pi-briefcase` | Сделки | Все сделки и их история изменений | из API |
| `contacts` | `pi-user` | Контакты | Контактные лица и их данные | из API |
| `companies` | `pi-building` | Компании | Организации-клиенты | из API |
| `tasks` | `pi-check-square` | Задачи и активности | Задачи, звонки, встречи, заметки | из API |
| `docs` | `pi-file` | Документы и файлы | Договоры и вложения | из API |
| `finance` | `pi-wallet` | Финансовые операции | Платежи, счета, проводки | из API |
| `logs` | `pi-history` | Журналы и история | Журнал автоматизаций и аудита | из API |
| `automations` | `pi-bolt` | Правила автоматизаций | Настроенные сценарии | из API |
| `directories` | `pi-folder-open` | Справочники | Товары, теги, поля, курсы валют | из API |

### Зоны и компоненты
| Зона | Компонент / элемент | Props / атрибуты |
|------|---------------------|-----------------|
| Красный баннер | `Message severity="error"` (или div danger-баннер) | icon `pi-exclamation-triangle`; фон `var(--p-red-50)` dark адаптив; текст с `<b>` «Операция необратима» danger-цвет |
| Master-строка | `<div>` + PrimeVue `Checkbox` | `Checkbox binary` c `indeterminate` (N>0 && !allOn); «Выбрать всё» + справа «Выбрано: N из 9» (`white-space: nowrap`) |
| Строка-категория | `<div class="reset-cat-row">` + `Checkbox` | клик по строке = toggle; **клик по чекбоксу `@click.stop`** (иначе двойной toggle); выбранная строка — фон `var(--p-primary-50)` / dark `var(--p-primary-950)` |
| Иконка категории | плашка 36×36 | `$surface-muted` фон, `$radius-md`, иконка `$surface-600` |
| Counts-тег | `Tag severity="secondary"` | `$font-size-xs`; `white-space: nowrap` |
| Инпут-фраза | `InputText` | моно-шрифт, `placeholder="СБРОСИТЬ"`; бордер `danger` если введено но неверно и N>0 |
| Кнопка удаления | `Button` | `severity="danger"` `icon="pi pi-trash"`; `:disabled="!canReset"` (canReset = N>0 && word===СБРОСИТЬ); label «Удалить выбранное (N)» |
| Финальная модалка | `Dialog` (~460px) | заголовок «Удалить данные?» + иконка-круг danger; тело со **склонением** («N категорий»); футер Отмена / «Удалить безвозвратно» danger |
| Успех | `useToast().add` | `severity:'success'` «Выбранные данные удалены» |

### UX-предохранители (обязательны — деструктив)
1. **Двойное подтверждение:** (a) ввод слова `СБРОСИТЬ` активирует кнопку → (b) финальная
   `Dialog` с перечнем выбранных категорий + «Удалить безвозвратно». Оба шага обязательны.
2. **Чек-лист с counts:** каждая категория показывает число записей → пользователь видит масштаб.
3. **Красная зона:** danger-баннер сверху + danger-акценты на кнопках/бордерах (`severity="danger"`,
   `var(--p-red-*)`).
4. **Недоступность для не-админа:** секция уже gated `roles: ['admin']` в sidebar + внутри
   компонент проверяет `userStore.getUserRole === 'admin'` → иначе access-denied (сохранить текущий
   `section-system-reset__denied`). Director/manager не видят пункт вовсе.
5. **Кнопка disabled** пока `N===0` ИЛИ фраза неверна — визуально `opacity` + `pointer-events:none`.
6. **Склонение** в финальной модалке: «1 категория» / «2–4 категории» / «5+ категорий» (ru-plural,
   через vue-i18n `{count}` plural-правила, не строковый хардкод).

### Backend (для backend-architect — из открытых вопросов)
- `GET /api/v1/system/reset/categories` → `[{key, count}]` (counts на карточках).
- `POST /api/v1/system/reset` `{categories: string[], confirmation: 'СБРОСИТЬ'}` → выборочный wipe.
  Текущий `systemApi.resetDatabase()` — full-wipe, надо расширить до per-category. **Требуется
  backend.** Пока backend не готов — categories можно замокать (counts «—»), кнопка активна, но
  реальный вызов — заглушка/тост «скоро».

### i18n (system-reset)
```json
{
  "ru": {
    "settings.system.reset.title": "Сброс системы",
    "settings.system.reset.desc": "Выборочное удаление данных из системы. Настройки аккаунта и учётные записи сохраняются.",
    "settings.system.reset.dangerBanner": "Операция необратима. Выбранные данные будут удалены безвозвратно. Перед сбросом рекомендуем выгрузить резервную копию.",
    "settings.system.reset.selectAll": "Выбрать всё",
    "settings.system.reset.selectedCount": "Выбрано: {n} из {total}",
    "settings.system.reset.confirmTitle": "Подтверждение",
    "settings.system.reset.confirmHint": "Чтобы удалить выбранные данные ({n}), введите {phrase} в поле ниже.",
    "settings.system.reset.deleteBtn": "Удалить выбранное ({n})",
    "settings.system.reset.modalTitle": "Удалить данные?",
    "settings.system.reset.modalBody": "Будет безвозвратно удалено {n} категорий данных: {list}. Продолжить?",
    "settings.system.reset.modalConfirm": "Удалить безвозвратно",
    "settings.system.reset.success": "Выбранные данные удалены",
    "settings.system.reset.categories.deals.name": "Сделки",
    "settings.system.reset.categories.deals.desc": "Все сделки и их история изменений",
    "settings.system.reset.categories.contacts.name": "Контакты",
    "settings.system.reset.categories.contacts.desc": "Контактные лица и их данные",
    "settings.system.reset.categories.companies.name": "Компании",
    "settings.system.reset.categories.companies.desc": "Организации-клиенты",
    "settings.system.reset.categories.tasks.name": "Задачи и активности",
    "settings.system.reset.categories.tasks.desc": "Задачи, звонки, встречи, заметки",
    "settings.system.reset.categories.docs.name": "Документы и файлы",
    "settings.system.reset.categories.docs.desc": "Договоры и вложения",
    "settings.system.reset.categories.finance.name": "Финансовые операции",
    "settings.system.reset.categories.finance.desc": "Платежи, счета, проводки",
    "settings.system.reset.categories.logs.name": "Журналы и история",
    "settings.system.reset.categories.logs.desc": "Журнал автоматизаций и аудита",
    "settings.system.reset.categories.automations.name": "Правила автоматизаций",
    "settings.system.reset.categories.automations.desc": "Настроенные сценарии",
    "settings.system.reset.categories.directories.name": "Справочники",
    "settings.system.reset.categories.directories.desc": "Товары, теги, поля, курсы валют"
  },
  "en": {
    "settings.system.reset.title": "System reset",
    "settings.system.reset.desc": "Selective deletion of system data. Account settings and user records are preserved.",
    "settings.system.reset.dangerBanner": "This operation is irreversible. Selected data will be permanently deleted. We recommend exporting a backup first.",
    "settings.system.reset.selectAll": "Select all",
    "settings.system.reset.selectedCount": "Selected: {n} of {total}",
    "settings.system.reset.confirmTitle": "Confirmation",
    "settings.system.reset.confirmHint": "To delete the selected data ({n}), type {phrase} in the field below.",
    "settings.system.reset.deleteBtn": "Delete selected ({n})",
    "settings.system.reset.modalTitle": "Delete data?",
    "settings.system.reset.modalBody": "{n} data categories will be permanently deleted: {list}. Continue?",
    "settings.system.reset.modalConfirm": "Delete permanently",
    "settings.system.reset.success": "Selected data deleted"
  }
}
```

### Токены (system-reset)
- Danger-баннер: `var(--p-red-50)` фон / dark `rgba(red,.15)`, бордер `var(--p-red-200)` /
  `var(--p-red-500)` left-accent, icon/`<b>` — `var(--p-red-500)` / dark `var(--p-red-400)`.
- Строка выбранная: `var(--p-primary-50)` / dark `var(--p-primary-950)`.
- Плашка-иконка: `$surface-muted`, `$radius-md`.
- Карточки: `$surface-card` `$radius-lg` `$shadow-sm`, разделители `$surface-200`.

---

## 8. Общие States (все секции)

- **loading:** `Skeleton` для hero (avatar-круг + 2 строки), `Skeleton` строк для таблиц журнала/
  reset-counts; `ProgressSpinner` overlay при выполнении сброса (кнопка `:loading`).
- **empty:** журнал автоматизаций без записей → icon `pi-clock` `$font-size-icon-lg` + «Записи не
  найдены». Sidebar-поиск без совпадений → см. §2.
- **error:** сохранение профиля / выполнение сброса → `Toast severity="error"` с
  `getApiErrorMessage`.

---

## 9. Общий список токенов/компонентов

**PrimeVue:** `Button` (theme-toggle, actions, danger), `Card`, `Tag`, `Message`, `Checkbox`
(reset), `InputText` / `IconField` (поиск, фраза), `SelectButton` (density, статус-фильтр),
`Dialog` (финальная модалка reset), `DataTable` (журнал), `Skeleton`, `ProgressSpinner`,
`useToast`.

**Reuse (charter §2):** `EntityAvatar` (hero, `on-brand`), `AvatarCropModal` (есть), `PageHeader`
(шапка + `#actions`), `ChangePasswordForm` (есть), `DocumentStatusTag`-паттерн для статус-тегов
журнала (если нужен новый — `AutomationRunStatusTag` через `STATUS_CONFIG`, обосновать).

**Новые компоненты (с обоснованием):**
- `ProfileHeroCard.vue` (`sections/profile/`) — hero нет ни у кого, специфичен для профиля.
- `SystemResetCategories.vue` + пересбор `SectionSystemReset.vue` — паттерн выборочного wipe
  уникален.
- `useDensityStore` (Pinia) — глобальная density, если не расширяем `useLayoutStore`.

**Токены:** `$primary-900/800` (hero-градиент, brand), `$surface-card/50/muted/200`,
`var(--p-primary-50/950)`, `var(--p-red-*)`, `var(--p-green-600)`, `$space-3…6`, `$radius-md/lg`,
`$shadow-sm`, `$font-size-2xs…xl`, density `--mg-row-py`/`.mg-cozy`. Обе темы — dark-оверрайды по
паттерну charter §4 (инвертированная surface-шкала).

---

## 10. Открытые вопросы

1. **[ОВ-1] Имя/Фамилия.** Эталон разбивает на 2 поля, у нас одно `full_name`. Оставляем одно
   full-width поле «Имя» ИЛИ backend добавляет `first_name`/`last_name`? → **требуется решение
   бизнеса**; по умолчанию — одно `full_name` (минимум изменений).
2. **[ОВ-2] Акцент-свотчи.** НЕ реализуем (ломает бренд-инвариант). Подтвердить опускание блока.
3. **[ОВ-3] Язык AR.** Эталон показывает RU/EN/**AR**. У нас RU/EN — добавляем AR или нет?
   (RTL-верстка — большой скоуп, вероятно нет.)
4. **[ОВ-4] Density scope.** Новый глобальный `compact/cozy` (2 значения) конфликтует с
   page-local `ContactsPage` density (3 значения: compact/normal/comfortable). Унифицировать
   (глобальный побеждает, ContactsPage мигрирует) ИЛИ держать раздельно? → **требуется решение**;
   рекомендация — глобальный density как источник, Contactspage слушает его.
5. **[ОВ-5 / backend] System-reset per-category.** `POST /system/reset` сейчас full-wipe.
   Требуется backend: per-category endpoint + `GET .../categories` с counts. До готовности —
   counts «—», реальный вызов заглушён.
6. **[ОВ-6] theme-toggle vs SectionAppearance.** Toggle в шапке меняет тему мгновенно (без save-
   bar), а SectionAppearance → тема идёт через draft+save. Возможен рассинхрон draft. Рекомендация:
   toggle пишет напрямую в `themeStore` (persist), SectionAppearance при монтировании берёт
   актуальное значение как saved-снапшот (уже так). Подтвердить, что двойной источник ок.
```
