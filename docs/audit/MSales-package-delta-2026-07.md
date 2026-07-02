# Дельта-анализ обновлённого дизайн-пакета vs репо (2026-07-03)

**Пакет:** `/Users/bogdanadykin/Downloads/exports/mgcrm-package/` (84 файла)
**Репо:** `design-handoff/` + `.claude/skills/macroglobal-design/` + `.claude/agents/*`

**Общий вывод:** пакет — это **более старый snapshot** документов redesign, но с **новым слоем токенов** (base/dark/logical + density + extended-status) и **новым визуальным ТЗ на Настройки**. Спеки redesign в пакете НЕ надо тащить (репо ушёл вперёд на 47 as-built-пометок). Реально ценное и НОВОЕ для нас: **3 новых токен-файла + доп. значения в colors/semantic/spacing**, **Settings-redesign-visual.md + 3 JSX-секции**, **skill-гайды dark/rtl**. Agents-patches — уже применены, дельты ноль.

---

## 1. Токены (`tokens/*.css`)

Пакет несёт **8 токен-файлов**, репо — **5**. `styles.css` пакета импортирует все 8; репо-`styles.css` (обе локации) — только 5. `.mg-app` из `base.css` нужен как opt-in reset.

| Файл | Статус | Дельта |
|---|---|---|
| `fonts.css` | идентичен | — |
| `typography.css` | идентичен | — |
| `spacing.css` | **ИЗМЕНЁН** | Пакет добавляет **density-токены** в `:root`: `--mg-row-py:7px`, `--mg-cell-py:10px`, `--mg-control-h:38px`, `--mg-control-h-sm:31px`, `--mg-card-pad:16px` + opt-in класс **`.mg-cozy`** (11/14/44/36/22). Репо-копия обрывается на `--mg-web-gutter`. |
| `colors.css` | **ИЗМЕНЁН** | Пакет добавляет **extended status scale** (funnel/chessboard из MSales 2.0): `--mg-gold-50/300/500/900` (marketing reserve), `--mg-pink-50/300/500/900` (marketing deal), `--mg-neutral-50/500/900` (done/archived). Блок «Pipeline-stage palette» (teal/blue/amber/pink/purple) — в обоих есть. |
| `semantic.css` | **ИЗМЕНЁН** | Пакет добавляет: (а) **extended status триады** `--mg-status-reserve-*` / `--mg-status-mdeal-*` / `--mg-status-done-*`; (б) **focus-ring токены** `--mg-focus-ring-color` (color-mix 25%) + `--mg-focus-ring` (0 0 0 3px). Остальное 1:1. |
| `base.css` | **НОВЫЙ** | z-index-шкала (`--mg-z-sticky/dropdown/overlay/drawer/modal/toast/tooltip`), opt-in reset `.mg-app`, focus-ring util `.mg-focusable`, скрытие скроллбара `.mg-no-scrollbar`, tabular-nums `.mg-tnum`, типо-утилиты (`.mg-h1/.mg-h2/.mg-body/.mg-label/.mg-caption/.mg-link/.mg-eyebrow`), keyframes (shimmer/fade-in/slide-up/scale-in). |
| `dark.css` | **НОВЫЙ** | Полная navy dark-палитра (порт MSales 2.0). Активация `[data-theme="dark"]` / `.mg-dark` / `.surface.dark`. Ремапит семантику + page-мост `--c-*` + примитивные тинт-ремапы (`--mg-green-100` и т.п. → тёмные тинты). Бренд-акцент светлеет `#172747→#4C7DF0`; сайдбар темнеет но остаётся navy `#091020`. |
| `logical.css` | **НОВЫЙ** | RTL/i18n-хелперы (EN·RU·AR / рынок ОАЭ): `.mg-flip-rtl` (флип chevron/arrow), `.mg-ltr-nums` (телефоны/цены/даты в LTR внутри RTL), `.mg-text-start/.mg-text-end`. |

**Важно:** значения `dark.css` совпадают с рантайм-темой репо (`front/src/theme/adapters/primevue/`). Эти CSS — эталон-справка, не подключаются в рантайм Vue. Но и `design-handoff/`, и `.claude/skills/macroglobal-design/tokens/` должны их получить, чтобы .html-макеты и dark-режим работали и чтобы designer/qa могли на них ссылаться.

**Скилл-копия токенов (`.claude/skills/macroglobal-design/tokens/`)** — тоже старая 5-файловая: НЕ содержит extended-status в colors, НЕ содержит density в spacing, НЕТ base/dark/logical. Нужен тот же апдейт, что и `design-handoff/tokens/`.

---

## 2. Спеки / макеты redesign

**Главный вывод: пакет — старее репо. НИ ОДНОЙ as-built-пометки (`grep` → 0 в пакете vs 47 в 12 файлах репо).** Копировать спеки из пакета в репо **НЕЛЬЗЯ** — затрёт наши as-built-дельты.

### Совпадающие имена (пакет ⊂ репо, репо ушёл вперёд)
| Файл | Вердикт |
|---|---|
| `Contacts-spec.md` | Репо = пакет + as-built (путь `pages/ContactsPage/`, task-mgmt-дельты). **НЕ трогать.** |
| `EntityCard-spec.md` | Репо + as-built §§2/5 (агрегация активностей связанных сделок, реальный enum-статус, KPI-реактивность). **НЕ трогать.** |
| `DealCard-spec.md` | Репо + as-built §7.2 (4-state enum-бейдж). **НЕ трогать.** |
| `SalesFunnel-spec.md` | Репо + impl-plan рядом. **НЕ трогать.** |
| `Tasks-spec.md` | Репо + as-built (2026-06-27: «Выполненные», transition-gated dropdown, серверные бакеты Asia/Dubai, rejected=closed). **НЕ трогать.** |
| `HANDOFF.md` | **Пакетный — старейший snapshot** (обрывается на составе + промпте; без единой ветки обновлений). Репо-`HANDOFF.md` содержит хронику 2026-06-27 … 2026-07-02 (Settings Ф1–Ф5, EntityCreate, MergeDialog, MotivationCard, CustomFields, Волна 6). **НЕ трогать репо-версию.** |
| `deal-card.html` / `entity-card.html` / `contacts.html` / `sales-funnel.html` / `tasks.html` / `styles.css`(redesign) / `tweaks-panel.jsx` | Есть в обоих. Различий по составу нет; .html-эталоны специально не трогались (источник истины по «свежим правкам» — .md). **Не тащить.** |

### Файлы, которых в пакете НЕТ (репо шире)
`SalesFunnel-impl-plan.md`, `EntityCard-impl-plan.md`, `Contacts-impl-plan.md`, `QA-backend-backlog-report.md`, `Inbox-spec.md`, `Settings-spec.md`, `Dedup-Merge-spec.md`, `EntityCreate-spec.md`, `TaskWindow-spec.md`, `MSales-v2-analysis.md`, `custom-fields-ui-tz.md`, `motivation-card/SPEC.md`. Пакет их не содержит → ничего не суперсидит.

### Файлы, которые в пакете НОВЫЕ vs репо
- **`Settings-redesign-visual.md`** — новый hi-fi визуальный ТЗ Настроек (см. п.6). Репо-`Settings-spec.md` описывает master-detail-**механику** (Ф1–Ф5, реализовано), а этот файл — **визуальный редизайн** того же экрана. Дополняют друг друга, не совпадают.
- **`users-section.jsx` / `access-section.jsx` / `system-section.jsx`** — React-референсы 3 системных разделов Настроек (в репо есть только `tweaks-panel.jsx`).
- **`pipeline.html`** — эталон отдельного экрана воронки-настройки (в репо в redesign нет; в проде есть `PipelineSettingsPage`).

### Суперсиды апрувнутых макетов
**Пакет НИЧЕГО не суперсидит.** Наоборот — репо-`HANDOFF.md` содержит апрувы/шипы (Settings Ф1–Ф5 PM APPROVED, Волна 6 QA PASS, EntityCreate/MergeDialog), которых в пакетном HANDOFF нет. Единственное, что пакет **добавляет** поверх апрувнутого — визуальный слой Настроек (не конфликт, а надстройка над уже-реализованной механикой).

---

## 3. Skill (`skills/macroglobal-design/`)

| Область | Дельта пакет vs репо-скилл |
|---|---|
| `tokens/*.css` | Те же 3 новых файла + density/extended-status (см. п.1). Репо-скилл — старые 5. |
| `guidelines/` | Пакет добавляет **`theme-dark.html`** и **`rtl.html`** (специмены). В репо-скилле их нет (11 гайдов vs 13). Остальные 11 совпадают по именам. |
| `README-DARK-THEME.md` | **НОВЫЙ** в скилле пакета (в репо-скилле лежит только под `handoff/`, не в корне скилла). Полноценный гайд по dark: активация, 3 слоя ремапа, ключевые значения, чек-лист QA. |
| `readme.md` (нижний регистр) | **ИЗМЕНЁН**: документирует 8-файловую систему (строки про `dark.css`/`logical.css`/`base.css` + density L203-213). Репо-`README.md` (верхний регистр) — старая версия без этих строк. |
| `ABOUT-THIS-COPY.md` | **НОВЫЙ**: явно объясняет, что React-исходники (`.jsx`/`.d.ts`) в handoff-копию НЕ включены (цель — Vue+PrimeVue, нужны токены + `.prompt.md`-контракты). |
| `components/**` | Пакет содержит только `.prompt.md` + `.card.html` (без `.jsx`/`.d.ts`). Репо-скилл содержит и `.jsx`/`.d.ts`. `.prompt.md`/`.card.html` совпадают. **Репо богаче — React-исходники оставить.** |
| `ui_kits/crm/` | Пакет — только `index.html`. Репо — `index.html` + `Shell/Sidebar/ContactsView/DealsView/TasksView.jsx`. **Репо богаче.** |
| `SKILL.md` / `styles.css` / `_adherence.oxlintrc.json` / `assets/` | Есть в обоих. `styles.css` пакета импортирует 8 (репо-скилл — 5, требует апдейта). |

**Итог по скиллу:** тащим ТОЛЬКО токен-апдейт (3 новых файла + density/extended-status/focus-ring + 8-import `styles.css`), 2 новых гайда (`theme-dark.html`, `rtl.html`), обновлённый `readme.md`, `README-DARK-THEME.md`, `ABOUT-THIS-COPY.md`. React-исходники компонентов и ui_kits в репо-скилле **НЕ удалять** (пакет их просто не несёт — это не сигнал к удалению).

---

## 4. Agents-patches

Прочитаны все 3 append'а + README. **Все уже применены в наших агентах — дельта нулевая.**

| Патч | Целевой агент | Статус в репо |
|---|---|---|
| `designer.append.md` (🅐 главный эталон / 🅑 воркфлоу / 🅒 формат выхода / 🅓 reuse-first) | `designer.md` | **Применён** — блоки присутствуют почти дословно (система-промпт §🎨/«Наш воркфлоу»/«Формат выхода»/«Reuse-first»). |
| `frontend-specialist.append.md` (токены↔репо, reuse, обе темы, `lint:ds`, EN-код) | `frontend-specialist.md` | **Применён.** |
| `qa-tester.append.md` (🅥 ШАГ 4.5 визуальный проход, computed-styles, обе темы, отчёт-секция) | `qa-tester.md` | **Применён** — «### 4.5 🅥 ВИЗУАЛЬНЫЙ ПРОХОД» + computed-styles на L61/67. |
| README (иерархия эталонов, воркфлоу) | — | Учтён; наш CLAUDE.md формулирует иерархию точнее (source-of-truth-цепочка). |

**Дополнительно (важно — покрыто, но патч про них не знает):** `crm-frontender.md` и `sales-frontender.md` **уже несут** те же DS-блоки (skill-эталон, токены↔репо, обе темы, `lint:ds` в чек-листе остановки — подтверждено grep'ом L34-64/103-122). Новых per-module-фронтендеров патчить не требуется. Единственное отличие вектора: наши агенты уже отражают, что **Vizion — архив** (стеком не рулит) и эталон визуала — **design-handoff-цепочка**, тогда как пакетный README всё ещё называет Vizion эталоном структуры кода. Наши формулировки новее — пакетный README не применять.

---

## 5. Пакетный `CLAUDE.md`

Это generic DS-onboarding-файл. Смысловая дельта против нашего корневого `CLAUDE.md` (наш НЕ перезаписываем):

- **Совпадает:** «дизайн-система = источник истины», порядок чтения (readme → tokens → components/ui_kits), запрет hex/px мимо токенов, PrimeIcons-only, обе темы, `1 200 000 ₽`, `npm run lint:ds`, роли агентов.
- **Ново/полезно как формулировка (не как замена):**
  - Явно называет **8 токен-файлов** (colors·typography·spacing·semantic·fonts·**base·dark·logical**) — наш CLAUDE.md количество файлов не фиксирует.
  - Ссылка на **`README-DARK-THEME.md`** как обязательное чтение перед работой с темами.
  - Копирайт-блок: деньги/даты формат, глаголы sentence-case, «вы».
- **Устарело в пакете vs наш:** пакетный CLAUDE.md ещё считает `.claude/skills/.../README.md` (верхний регистр) точкой входа; наш проект перешёл на цепочку source-of-truth (`front/src/theme` = значения · `design-handoff/redesign/` = лейаут экрана · skill = бренд-инварианты) + `HANDOFF.md` как живой индекс. **Наш новее — не перезаписывать.**

**Действие:** в наш CLAUDE.md можно опционально добавить одну строку — упоминание 8 токен-файлов и `README-DARK-THEME.md` — но только после того, как эти файлы реально лягут в репо (см. п.1/3). Сам пакетный CLAUDE.md не мёржим целиком.

---

## 6. Settings-redesign (7 разделов)

`Settings-redesign-visual.md` — **hi-fi визуальный редизайн** экрана Настроек, двухколоночный (рельс 264px + detail flex, header с theme-toggle). Разделы навигации:

| Группа → раздел | Есть ли у нас (unified `/settings` master-detail) | Ново в этом ТЗ |
|---|---|---|
| Аккаунт → **Профиль** (4 под-вкладки: Профиль/Безопасность/Внешний вид/Язык) | Да — Ф1 + Ф5 (`SectionProfileTabs`, аватар-кроп, смена пароля). | Визуал: **hero-карточка** с navy-градиентом, аватар 72px, мета-строка (вход/2FA/язык), выбор акцента 4 свотча, **сегмент плотности Компактная/Просторная** (это и есть density-токены из п.1!). |
| Интеграции → **Каналы связи** | Да — Ф1 (Channels). | Визуал карточек-каналов (Telegram подключён/Email/WhatsApp «Скоро»), модалки подключения. |
| Справочники → **Справочники** (11 подвкладок) + link-out «Воронка продаж» | Да — Ф2 (`SectionDirectories` + DirTab-обёртки, 11 справочников), Ф4 (документы), link-out на pipeline. | Визуал таб-бара + inline-режим правки (toggle колонки «Действия»). |
| Система → **Пользователи** | Да — Ф3 (`SysTabUsers`, embedded UsersPage). | `users-section.jsx`: таблица с градиент-аватарами по роли, тулбар (поиск+роль+отдел), архивный блок, модалка 544px, **сброс пароля разовым показом** (совпадает с нашим decision). |
| Система → **Доступ и оргструктура** | Да — Ф3 (`SysTabAccessControl`, 3 таба Departments+OrgChart/RolesMatrix/VisibilityScope). | `access-section.jsx`: Отделы (Дерево/Схема) · Роли-матрица × чекбоксы (admin-права задизейблены) · Видимость (Роль × дропдаун scope). Совпадает с нашим access-control-ui ТЗ. |
| Система → **Журнал автоматизаций** | Да — Ф3 (`SysTabAutomationRuns`). | `system-section.jsx`→`AutomationsTab`: сегмент-фильтр по статусу со счётчиками, таблица overflow-x. |
| Система → **Сброс системы** | Да — Ф3 (`SectionSystemReset`, admin-only). | `system-section.jsx`→`ResetTab`: **выборочный** сброс 9 категорий (чекбоксы + indeterminate «Выбрать всё»), ввод слова `СБРОСИТЬ`, финальная модалка со склонением. **Это апгрейд** над текущим (у нас system-reset action-based — проверить, выборочный ли). |

**Вывод по Settings:** вся **механика** 7 разделов у нас реализована (Ф1–Ф5, PM APPROVED). Пакет даёт **визуальный редизайн-слой** (hero-профиль, density-сегмент, единый header-theme-toggle, hi-fi карточки/модалки) + один функциональный апгрейд (**выборочный** system-reset по категориям vs полный). Это НЕ переделка, а visual-polish + одна фича. Реализовывать — отдельной задачей через `designer`→ТЗ, если PM захочет привести Настройки к этому hi-fi. JSX — только референс поведения (наш стек Vue+PrimeVue).

---

## Итоговый список действий

### Копировать как есть (в обе локации: `design-handoff/tokens/` И `.claude/skills/macroglobal-design/tokens/`)
1. `tokens/base.css` — новый.
2. `tokens/dark.css` — новый.
3. `tokens/logical.css` — новый.
4. Обновить `tokens/colors.css` — добавить extended status scale (gold/pink/neutral).
5. Обновить `tokens/semantic.css` — добавить extended status триады + focus-ring.
6. Обновить `tokens/spacing.css` — добавить density-токены + `.mg-cozy`.
7. Обновить оба `styles.css` (design-handoff + skill) — импорт 8 файлов вместо 5.

### Копировать в skill (`.claude/skills/macroglobal-design/`)
8. `guidelines/theme-dark.html`, `guidelines/rtl.html` — новые специмены.
9. `README-DARK-THEME.md` — в корень скилла.
10. `ABOUT-THIS-COPY.md` — новый.
11. Обновить `readme.md`/`README.md` — до 8-файловой версии (строки про dark/logical/base/density).

### Копировать в `design-handoff/redesign/` (новый визуал Настроек)
12. `Settings-redesign-visual.md` + `users-section.jsx` + `access-section.jsx` + `system-section.jsx` + `settings.html` + `pipeline.html` — как визуальный референс (реализация — отдельной designer-задачей по решению PM).

### НЕ трогать (репо ушёл вперёд — затрёт as-built)
- `HANDOFF.md` (репо-версия), `Contacts-spec.md`, `EntityCard-spec.md`, `DealCard-spec.md`, `SalesFunnel-spec.md`, `Tasks-spec.md` — все .md-спеки redesign.
- `.html`-эталоны redesign (deal-card/entity-card/contacts/sales-funnel/tasks) — источник истины сместился в .md.
- Все агенты (`designer/frontend-specialist/qa-tester/crm-frontender/sales-frontender`) — патчи уже применены.
- Корневой `CLAUDE.md` — новее пакетного; опционально +1 строка про 8 токенов после копирования.
- React-исходники (`.jsx`/`.d.ts`) и `ui_kits/*.jsx` в репо-скилле — оставить (пакет их просто не несёт).

### Мёржить (не тупой overwrite)
- `colors.css` / `semantic.css` / `spacing.css` — добавляем НОВЫЕ блоки в конец `:root`, существующие значения идентичны (можно overwrite целиком — расхождений в старых значениях нет; проверено построчно).
- `readme.md`/`README.md` скилла — заменить на 8-файловую версию.

### Требует решения PM (не designer)
- Приводить ли Настройки к hi-fi `Settings-redesign-visual.md` (visual polish поверх готовой Ф1–Ф5).
- Апгрейд system-reset до выборочного по 9 категориям.
- Активировать ли density-сегмент (Компактная/Просторная) в Профиле — тянет за собой применение density-токенов на таблицах/бордах.
