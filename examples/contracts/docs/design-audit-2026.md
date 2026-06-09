# MACRO CRM — Дизайн-аудит и план визуального обновления (v2)

> Дата: 2026-06-04 · Автор анализа: design-сессия · Статус: предложение к согласованию
> Источник истины по коду на момент анализа: `apps/web/tailwind.config.ts`, `apps/web/src/app/globals.css`, `apps/web/src/components/Sidebar.tsx`, репрезентативные страницы dashboard/deals/finance/login.

---

## 0. TL;DR

Текущий UI — **функционально богатый, но визуально «плоский и служебный»**: базовые `btn/input/card/badge`, Bootstrap Icons, Inter, почти нет теней/глубины, ноль анимаций и микровзаимодействий, монотонная плотность. Это «честный enterprise-инструмент», но без характера и ощущения современности.

Предлагаю **3-слойное обновление**:
1. **Фундамент (design tokens v2)** — единственное, что трогает все 100+ страниц разом: цвет-семантика, тени/elevation, радиусы, motion-токены, плотность. Низкий риск, максимальный эффект.
2. **Иконки** — мигрировать с Bootstrap Icons на **Iconsax** (статика, 6 стилей, MIT) + точечно **Lordicon wired/outline** (анимация на hover в навигации, в empty-states, онбординге, AI-кнопке).
3. **Компоненты-«вау»** — точечно внедрить отдельные паттерны из **Magic UI** (Number Ticker, Border Beam, Bento Grid, Animated List, Shimmer/Shine) и **21st.dev** (Tables, Sidebar, Dialogs, Empty States, Toasts) там, где это даёт максимум воспринимаемого качества: дашборд, кабинет, login, AI, канбан.

**Главное архитектурное решение, которое нужно принять до старта:** добавляем ли в стек `motion` (бывш. framer-motion). Без него Magic UI и многие 21st.dev-компоненты не работают. Рекомендация — **да**, это де-факто стандарт и основа «современного» ощущения.

---

## 1. Аудит текущей дизайн-системы

### 1.1 Что есть (фактически в коде)

| Слой | Текущее состояние | Файл |
|---|---|---|
| **Цвет** | Брендовая палитра: primary `#172747`, primary-light `#2B4987`, gray-шкала, 4 семантики (success/danger/warning/info) — но как **плоские hex без шкал** (нет success-50/600 и т.п.) | `tailwind.config.ts` |
| **Типографика** | Inter (fallback SF), заданы h1–h6 + lead. Используется мало — почти везде `text-sm`/`text-xs` | `tailwind.config.ts` |
| **Радиусы** | `DEFAULT: 8px`, локально `rounded-md/lg/full` | — |
| **Тени** | Только `shadow-sm` на карточках и `shadow-lg` на дропдаунах. Нет системы elevation | `globals.css` |
| **Компоненты** | `btn / btn-primary / btn-secondary / btn-danger / btn-ghost`, `input`, `label`, `card`, `badge`, `link-table` — и всё | `globals.css` |
| **Иконки** | Bootstrap Icons (`bi-*`), статичные, моно-вес | `globals.css` |
| **Motion** | Только CSS `transition-colors` + пара `animate-pulse` скелетонов. **Нет анимационной библиотеки** | — |
| **Тема** | Полноценный dark mode (`darkMode: class`) — хорошо | везде |
| **Density** | Высокая, ровная. Таблицы `px-4 py-3`, карточки канбана `p-2` | finance/deals |

### 1.2 Зависимости (важно для решений)

В `package.json` фронта: `next 14`, `swr`, `react-hook-form`, `@dnd-kit/*`, `bootstrap-icons`, `clsx`, `date-fns`, `html2canvas`, `jspdf`, `react-markdown`.
**Нет:** framer-motion/motion, Radix UI, shadcn/ui, CVA, tailwind-merge, lucide. → Любой компонент из Magic UI / 21st.dev = добавление зависимостей.

### 1.3 Диагноз: почему «выглядит просто»

1. **Нет глубины** — почти всё на одном уровне (border + bg-white), один `shadow-sm`. Глаз не видит иерархию слоёв.
2. **Нет движения** — интерфейс «мёртвый»: нет hover-lift, fade-in списков, счётчиков, состояний загрузки кроме pulse.
3. **Монохром-иконки** — статичные `bi-*` одного веса не создают акцентов.
4. **Семантический цвет используется как заливка badge**, но нет тонких surface-тонов (success-50 фон + success-700 текст) → статусы выглядят грубовато.
5. **KPI/числа поданы как обычный текст** — нет «дашбордного» ощущения (тикеры, спарклайны, акцентные карточки).
6. **Заголовок страницы (`PageHeader`)** — узкая полоса, низкий визуальный вес. Нет «hero»-зоны разделов.
7. **Пустые состояния и загрузка** — текстовые («Загрузка…», «Нет данных») вместо иллюстраций/скелетонов.

### 1.4 Что НЕ трогаем (сильные стороны)

- Брендовая палитра primary/primary-light — ядро айдентики, сохраняем.
- Dark mode — оставляем, все предложения обязаны его поддерживать.
- Cookie-auth, RU-тексты, Tailwind-токены, плотность данных (это enterprise-CRM, не лендинг — не «раздуваем» воздух везде).
- Sidebar-архитектура (группы, collapse, бейджи) — логика хорошая, обновляем только «шкуру».

---

## 2. Стратегические решения (согласовать до старта)

### Решение A — Motion-библиотека
**Предложение: добавить `motion` (npm `motion`, бывш. `framer-motion`).**
- За: основа Magic UI и многих 21st.dev; даёт fade/slide/lift/stagger, layout-анимации канбана, плавные модалки. Это и есть «современность».
- Против: +~50KB gzip, нужно следить за `prefers-reduced-motion`.
- Альтернатива-лайт: ограничиться CSS `@keyframes` + `tailwindcss-animate` (без JS-библиотеки). Покроет ~60% эффектов, но не layout/stagger/Magic UI.

### Решение B — shadcn/ui + Radix как базовый слой примитивов
21st.dev-компоненты в массе своей — это **shadcn/ui поверх Radix**. Варианты:
- **B1 (рекомендую):** точечно вносить Radix-примитивы (`@radix-ui/react-dialog`, `-popover`, `-dropdown-menu`, `-tooltip`, `-tabs`, `-toast`) под наши классы, без полного shadcn-каркаса. Даёт доступность (focus-trap, ARIA, клавиатура), которой сейчас нет в самописных модалках/дропдаунах.
- **B2:** завести полноценный shadcn (`components/ui/*`) — мощно, но крупная унификация всех форм.
- **B3:** ничего не вносить, копировать только разметку/Tailwind из 21st.dev вручную (0 новых зависимостей, но теряем a11y и качество).

### Решение C — Иконки
**Предложение: Bootstrap Icons → Iconsax (статика) + Lordicon wired/outline (анимация точечно).**
- Iconsax: 6 стилей (Linear/Outline/Bold/Bulk/Broken/TwoTone), 24px-грид, MIT, есть React-пакет. Один набор покрывает все `bi-*` с более «продуктовым» видом и возможностью играть весами (Linear для меню, Bold для активного состояния).
- Lordicon wired/outline: анимированные Lottie-иконки. Бесплатный тариф — 8 900 иконок без атрибуции для embed. Применять **дозированно** (nav-hover, empty states, онбординг, AI) — не везде.

> **Статус решений (согласовано 2026-06-04):**
> - **A = ДА** — добавляем `motion` (framer-motion). ✅ одобрено.
> - **B = B1** — точечные Radix-примитивы (Dialog/Popover/Dropdown/Toast/Tooltip). ✅ одобрено.
> - **C = НЕ РЕШЕНО** — миграция иконок Bootstrap Icons → Iconsax + Lordicon ещё не подтверждена; вернуться к вопросу перед фазой D1.
> - **Lordicon PRO** — не подтверждён; пока исходим из free-набора (8 900 иконок), если дойдём до Lordicon.
>
> Дальше отчёт исходит из «A=да, B=B1»; пункты, зависящие от C (иконки), помечены и ждут отдельного решения.

---

## 3. Дизайн-язык v2 — фундамент (применяется ВЕЗДЕ)

Это самый важный раздел: правки в `tailwind.config.ts` + `globals.css` меняют все страницы разом, без переписывания каждой.

### 3.1 Цвет: от плоских hex к семантическим шкалам
Сейчас `success: "#A7EFAA"` — один тон. Предлагаю каждую семантику развернуть в мини-шкалу `{50,100,500,600,700}`, чтобы статусы/алерты были тоньше:

```
success:  50 #ECFDF3 · 500 #12B76A · 600 #039855 · 700 #027A48
warning:  50 #FFFAEB · 500 #F79009 · 600 #DC6803 · 700 #B54708
danger:   50 #FEF3F2 · 500 #F04438 · 600 #D92D20 · 700 #B42318
info:     50 #EFF8FF · 500 #2E90FA · 600 #1570EF · 700 #175CD3
```
+ ввести **surface-токены**: `surface` (фон страницы), `surface-raised` (карточка), `surface-overlay` (модалка/поповер) — вместо прямых `bg-gray-100/bg-white`. Это даёт управляемую глубину и чистый dark mode.
(Нынешние «неоновые» success/warning остаются доступны как brand-акценты, но статусы переходят на шкалы выше — они спокойнее и профессиональнее.)

### 3.2 Elevation (тени) — 5 уровней
Сейчас 2 тени. Вводим систему:
```
elev-0  none                         — фон
elev-1  0 1 2 rgba(16,24,40,.06)     — карточка покоя
elev-2  0 4 8 -2 rgba(16,24,40,.10)  — карточка hover / KPI
elev-3  0 12 16 -4 rgba(16,24,40,.12)— поповер/дропдаун
elev-4  0 20 24 -4 rgba(16,24,40,.14)— модалка
```
Тени мягкие, «продуктовые» (низкая насыщенность, синеватый отлив). В dark mode — заменяются на тонкий border + чуть светлее фон.

### 3.3 Радиусы
`sm 6 · DEFAULT 8 · md 10 · lg 12 · xl 16 · 2xl 20`. Карточки → `lg(12)`, модалки → `xl(16)`, кнопки → `md(10)`. Чуть крупнее радиусы = мягче и современнее.

### 3.4 Типографика
- Подключить вариативный **Inter** через `next/font` (сейчас, судя по конфигу, просто font-family — лучше self-host через next/font для скорости и `font-feature-settings`).
- Ввести «eyebrow» (мелкий uppercase-лейбл секции), которого не хватает, и реально использовать `text-h4/h5` в заголовках карточек (сейчас почти всё `text-sm`).
- Числа — везде `tabular-nums` (частично уже есть) + опц. вариативная толщина для KPI.

### 3.5 Spacing / density
Сохраняем плотность списков/таблиц (это плюс CRM), но вводим **2 режима плотности** через data-атрибут: `comfortable` (дашборд/карточки) и `compact` (таблицы/реестры). Не «надуваем воздух» в финансах и реестре.

### 3.6 Motion-токены (если Решение A=да)
```
duration: fast 120ms · base 200ms · slow 320ms
easing:   standard cubic-bezier(.2,.8,.2,1) · emphasized (.3,.7,0,1)
```
Базовые паттерны: hover-lift карточек (`translateY(-2px)` + elev-1→elev-2), fade-in списков (stagger 30ms), плавное раскрытие модалок (scale .98→1 + fade), счётчики чисел. Всегда уважать `prefers-reduced-motion`.

---

## 4. Иконки: детальная стратегия

### 4.1 Iconsax (статика — замена Bootstrap Icons)
- Пакет: `iconsax-react` (MIT). Стили: Linear/Outline/Bold/Bulk/Broken/TwoTone, грид 24px, props `color/size/variant`.
- Где: вся навигация, кнопки, таблицы, формы.
- Приём: **Linear** для неактивных пунктов меню, **Bold** для активного — даёт «современный» эффект смены веса (сейчас все иконки одного веса).
- Каталог для подбора: https://iconsax.io · https://iconsax-react.pages.dev
- Карта-замена (примеры `bi-*` → Iconsax):

| Раздел | Сейчас (bi) | Iconsax (Linear/Bold) |
|---|---|---|
| Дашборд | `bi-speedometer2` | `Element3` / `Chart` |
| Сделки | `bi-kanban` | `Kanban` / `Category` |
| Контакты | `bi-person-rolodex` | `Profile2User` |
| Задачи | `bi-clipboard-check` | `TaskSquare` |
| Реестр | `bi-clipboard-data` | `DocumentText` |
| Входящие | `bi-inbox-fill` | `Sms` / `Direct` |
| Договоры | `bi-file-earmark-text` | `DocumentText1` |
| Финансы | `bi-cash-coin` | `Wallet` / `MoneyRecive` |
| Аналитика | `bi-grid-3x3` | `Chart2` / `Diagram` |
| Обучение | `bi-mortarboard-fill` | `Teacher` |
| Настройки | `bi-sliders` | `Setting2` |

### 4.2 Lordicon wired/outline (анимация — точечно)
- React: `@lordicon/react` (плеер бесплатен). Free-набор 8 900 иконок без атрибуции для embed; PRO $29/мес за полный.
- Триггеры: hover / click / loop / morph / in-out.
- Каталог: https://lordicon.com/icons/wired/outline (фильтр «Free» + по категории).
- **Где применять (дозированно):**
  1. **Sidebar nav** — hover-анимация активного пункта (микро-морф иконки). 5–7 ключевых разделов.
  2. **Empty states** — крупная анимированная иконка вместо текста («нет сделок», «пустой инбокс», «нет задач»).
  3. **Онбординг / WelcomeWizard** — приветственные шаги.
  4. **AI-ассистент** — анимированная «искра»/«звезда» на FAB-кнопке (loop on idle).
  5. **Success-моменты** — после генерации договора, оплаты, закрытия сделки (галочка-морф).

---

## 5. Покомпонентные улучшения (с вариантами)

Ниже по ключевым элементам даю широкий набор вариантов (≥10 там, где просили — кнопки, карточки, навигация), с источником и рекомендацией.

### 5.1 Кнопки — 12 вариантов
Текущая `btn-primary` = плоская заливка `bg-primary` + `hover:bg-primary-light`. Опции усиления:

1. **Soft elevation** — добавить `elev-1`, на hover `elev-2 + translateY(-1px)`. (CSS, 0 deps) — *рекомендую как базу для всех btn.*
2. **Gradient primary** — `bg-gradient-to-b from-primary-light to-primary` (тонкий вертикальный). (CSS)
3. **Inner highlight** — `shadow-[inset_0_1px_0_rgba(255,255,255,.12)]` для «объёма». (CSS)
4. **Shimmer Button** (Magic UI) — бегущий блик. Для главных CTA (например «Сгенерировать договор»). → `magicui.design/docs/components/shimmer-button`
5. **Shine Border / Border Beam на кнопке** — светящийся контур для премиум-CTA. → `/border-beam`, `/shine-border`
6. **Interactive Hover Button** (Magic UI) — стрелка-reveal на hover. → `/interactive-hover-button`
7. **Pulsating Button** (Magic UI) — пульс для привлечения (напр. «Начать день» в кабинете). → `/pulsating-button`
8. **Rainbow Button** (Magic UI) — радужный CTA (осторожно, только 1 место — напр. upgrade/AI). → `/rainbow-button`
9. **Ripple Button** (Magic UI) — material-волна по клику. → `/ripple-button`
10. **Icon-leading с весовой сменой** — Iconsax Linear→Bold при hover.
11. **Loading-state со спиннером** (сейчас просто текст «Вход…») — встроенный спиннер + disabled-shimmer.
12. **Segmented / split-button** для действий с дефолтом (напр. «Создать ▾»). → 21st.dev «Buttons».

Каталоги: https://21st.dev (категория Buttons) · https://magicui.design/docs/components
**Рекомендация:** базу (1+3+11) применить ко всем `btn-*` через `globals.css`; 4–9 — точечно на 3–5 «геройских» CTA.

### 5.2 Sidebar / навигация — 10 вариантов
1. **Активный пункт с цветовым «pill» + left-accent bar** (сейчас сплошная заливка primary — норм, но «тяжёлая»): тонкая полоса слева + мягкий фон `primary/10`.
2. **Iconsax Linear→Bold** при активности (см. 4.1).
3. **Lordicon hover-морф** на иконке пункта (см. 4.2).
4. **Group header с micro-collapse анимацией** (высота + chevron rotate) — сейчас мгновенно.
5. **Hover-подсветка с скользящим индикатором** (layout-анимация motion).
6. **Командный/воронкий switcher вверху** (как в Linear/Notion) — для будущих multi-team.
7. **Badge-redesign** — точечные счётчики с pulse при изменении (Number Ticker).
8. **Свернутый режим: «floating» поповеры групп** с elev-3 + fade (уже есть hover-меню — улучшить тень/анимацию).
9. **Поиск-кнопка как command-palette trigger** с анимированным placeholder.
10. **Профиль-блок снизу** — мягкая карточка с online-индикатором + hover-lift.
Источник: 21st.dev «Sidebars», «Navigation Menus». **Рекомендация:** 1+2+4+7 (низкий риск, большой эффект), 3 — на 5 пунктов.

### 5.3 Карточки (`.card`, KPI-плитки, deal-card) — 12 вариантов
1. **Elevation-rest + hover-lift** (elev-1→elev-2, translateY-2). *База.*
2. **Magic Card** (Magic UI) — radial-spotlight за курсором. Для KPI/виджетов дашборда. → `/magic-card`
3. **Border Beam** — бегущий контур на «важных» карточках (hot deals, просрочка). → `/border-beam`
4. **Neon Gradient Card** — для AI/премиум-блоков. → `/neon-gradient-card`
5. **Shine Border** — мягкий статусный контур. → `/shine-border`
6. **Bento Grid** — пересобрать дашборд в bento-раскладку (разноразмерные плитки). → `/bento-grid`
7. **KPI с Number Ticker** — числа «накручиваются» при загрузке. → `/number-ticker`
8. **Спарклайн в углу KPI** (мини-тренд) — 21st.dev «Numbers»/charts.
9. **Цветовой left-accent** по семантике (зелёный/красный кант слева).
10. **Skeleton-загрузка** карточек вместо «Загрузка…».
11. **Status-dot + Animated Circular Progress** для прогресса (внедрение B0–B6). → `/animated-circular-progress-bar`
12. **Glare Hover** — блик-наклон на hover (для немногих hero-карточек). → `/glare-hover`
**Рекомендация:** 1+7+10 везде; 2/3/6 — на дашборде и кабинете; 11 — в реестре/lifecycle.

### 5.4 Kanban (сделки) — 8 вариантов
Сейчас: `bg-white border rounded-lg p-2`, drag через нативный HTML5 DnD, kebab-меню.
1. **Hover-lift + elev** карточки, тень при drag (drag-overlay).
2. **Цветовой left-accent по этапу/температуре** (cold/warm/hot).
3. **Layout-анимация перестановки** (motion `layout`) — плавный перенос вместо скачка.
4. **Колонка: «стеклянный» sticky-заголовок** со счётчиком + суммой (Number Ticker).
5. **Аватар ответственного** (есть `Avatar`) на карточке + дедлайн-чип с семантикой.
6. **Прогресс-бар по этапу** внизу карточки.
7. **Drag placeholder** — пунктирная зона приземления.
8. **Empty-column state** — анимированная Lordicon иконка.
Источник: 21st.dev «Cards»/«Application UI». **Рекомендация:** 1+2+4+5+7.

### 5.5 Таблицы / списки — 9 вариантов
Сейчас: `thead` с uppercase, `px-4 py-3`, zebra нет.
1. **Sticky header** + лёгкая тень при скролле.
2. **Row hover** с мягкой подсветкой + cursor (есть частично).
3. **Zebra опционально** для длинных финансовых таблиц.
4. **Density toggle** comfortable/compact.
5. **Inline-статусы** на семантических шкалах (5.7).
6. **Skeleton-строки** при загрузке.
7. **Sortable-заголовки** с Iconsax-стрелками + анимация.
8. **Sticky-итоговая строка** (в балансах уже есть — стилизовать как «total bar»).
9. **Row-actions on hover** (kebab/быстрые кнопки появляются справа).
Источник: 21st.dev «Tables». **Рекомендация:** 1+2+6+9 на всех реестрах; 3+4 в финансах.

### 5.6 Формы / инпуты — 8 вариантов
Сейчас `.input` — простой border + focus-ring primary.
1. **Floating label** (анимированный) — современнее статичных лейблов. 21st.dev «Inputs».
2. **Focus-glow** (мягкая тень primary/20 вместо ring 1px).
3. **Leading/trailing иконки** (Iconsax) в инпутах (поиск, валюта, дата).
4. **Inline-валидация** с иконкой-морфом (Lordicon check/error).
5. **Select → Radix Combobox** с поиском (многие наши select — длинные справочники).
6. **Date Picker** аккуратный (сейчас нативный) — 21st.dev «Date Pickers».
7. **File upload dropzone** с прогрессом (договоры/аватары) — 21st.dev «File Uploads».
8. **Segmented control** вместо радио в фильтрах.
**Рекомендация:** 2+3 везде; 5+6 в формах сделок/договоров/финансов.

### 5.7 Badge / статусы
- Перевести `StatusBadge` и `.badge` на семантические шкалы: фон `*-50`, текст `*-700`, dot `*-500`. Сразу аккуратнее текущих «неоновых».
- Варианты: dot-badge, outline-badge, soft-badge (рек.), solid-badge, с иконкой Iconsax.
Источник: 21st.dev «Badges», «Tags».

### 5.8 Модалки / диалоги / поповеры
- Перевести самописные на **Radix Dialog/Popover/DropdownMenu** (Решение B1): focus-trap, ESC, ARIA, клик-вне — сейчас это в каждом компоненте руками.
- Анимация: scale .98→1 + fade (motion). Overlay — blur backdrop.
- Источник: 21st.dev «Dialogs/Modals», «Popovers», «Dropdowns».

### 5.9 Empty states — 6 вариантов
Сейчас текст («Нет данных»). Варианты: Lordicon-иллюстрация + заголовок + CTA; иллюстрированный SVG; «первый запуск» с подсказками; компактный inline; ошибка-состояние; offline-состояние.
Источник: 21st.dev «Empty States». **Рекомендация:** единый компонент `<EmptyState icon cta>` + Lordicon.

### 5.10 Toasts / уведомления
Сейчас в CLAUDE.md прямо записано: «Глобального toast пока нет, используем inline». Предлагаю **завести toaster** (Radix Toast или sonner-подобный) — это заметный апгрейд UX (сохранение, ошибки, фоновые операции). → 21st.dev «Toasts», «Notifications».

### 5.11 Login / auth (первое впечатление)
Сейчас: центрированная `.card` на сером фоне. Варианты:
1. **Split-screen**: слева форма, справа брендовый паттерн (Magic UI Backgrounds: Dot Pattern / Animated Grid / Flickering Grid / Light Rays).
2. **Анимированный фон** (Particles / Ripple) за карточкой.
3. **Border Beam** на карточке логина.
4. **Blur Fade** появление формы.
5. Обновлённая типографика (h2) + актуализировать подпись («Генератор договоров» → «MACRO CRM»).
Источник: Magic UI Backgrounds + 21st.dev «Sign Ins». **Рекомендация:** 1+4 + тонкий Dot Pattern.

### 5.12 AI-ассистент (`AiAssistantButton`)
- FAB: Lordicon «sparkles/AI» с idle-loop; на hover — Shimmer/Glow.
- Панель чата: Animated List для сообщений (stagger), Typing Animation для ответа ИИ, Magic Card-фон.
- Источник: Magic UI Text Animations + Animated List; 21st.dev «AI Chats».

### 5.13 Dashboard / аналитика
- **Bento Grid** раскладка (5.3.6) + **Number Ticker** на KPI + спарклайны.
- Воронка/forecast: акцентные Magic Card; «hot» карточки — Border Beam.
- Когортная аналитика — оставить плотной, но добавить hover-tooltip и плавную загрузку (Blur Fade).

---

## 6. Карта применения по модулям

| Модуль (раздел) | Что внедряем (приоритетно) |
|---|---|
| **Login / 2FA** | Split-screen + Dot Pattern фон, Blur Fade, Border Beam карточки, ребрендинг подписи (5.11) |
| **Дашборд** | Bento Grid, Number Ticker KPI, Magic Card виджеты, Border Beam на hot/overdue, skeletons (5.3, 5.13) |
| **Кабинет (`/me`)** | Pulsating «Начать день», Animated Circular Progress (план/факт), Number Ticker метрик, активити-feed Animated List |
| **Сделки (Kanban)** | Hover-lift, layout-анимация DnD, sticky-заголовки колонок с суммой, accent по температуре, аватар+дедлайн (5.4) |
| **Сделки (список)** | Sticky header, row-actions on hover, skeleton, density toggle (5.5) |
| **Контакты / Компании** | Карточки с hover-lift, Iconsax, empty states, аккуратные badge типов (5.3, 5.7, 5.9) |
| **Задачи** | Animated List, статус-чипы на шкалах, inline-валидация, Lordicon на пустом списке |
| **Реестр клиентов** | Density compact, lifecycle-прогресс (Circular Progress), цветовые tier-акценты, sticky totals (5.3.11, 5.5) |
| **Входящие (Inbox)** | Animated List новых обращений, empty state, channel-иконки Iconsax |
| **Договоры** | File-upload dropzone, статус-флоу на шкалах, success-морф после генерации/PDF (5.6.7, 5.7, 4.2) |
| **Финансы** | Density compact таблиц, zebra, sticky totals, семантические суммы, KPI Number Ticker на дашборде финансов |
| **Аналитика/когорты** | Blur Fade загрузка, hover-tooltip, спарклайны |
| **Admin/справочники** | Radix Dialog/Toast, sortable tables, единый EmptyState, Iconsax — массовая унификация форм |
| **Онбординг/обучение** | Lordicon wired в шагах, прогресс-бары, Confetti при завершении курса (`/confetti`) |
| **AI-ассистент** | Lordicon FAB + Animated List + Typing Animation (5.12) |
| **Глобально (все)** | Tokens v2 (цвет/elevation/радиусы/motion), Iconsax, toaster, skeletons, hover-lift (раздел 3–4) |

---

## 7. План внедрения по фазам и риски

**Фаза D0 — Фундамент (1 спринт, риск низкий, эффект максимальный)**
Tokens v2 в `tailwind.config.ts` + `globals.css`: цвет-шкалы, elevation, радиусы, motion-токены, обновление `btn/card/badge/input`, self-host Inter (next/font). Решение A (motion) и B1 (Radix-примитивы) подключаются здесь. → меняет вид всех 100+ страниц без переписывания.

**Фаза D1 — Иконки**
Ввести Iconsax, маппинг `bi-*`→Iconsax по словарю (раздел 4.1), Linear/Bold для nav. Lordicon — nav-hover + AI FAB.

**Фаза D2 — Hero-зоны (максимум «вау» на ключевых экранах)**
Login (split + фон), Дашборд (Bento + Number Ticker + Magic Card), Кабинет, AI-панель.

**Фаза D3 — Рабочие экраны**
Kanban (lift + layout-анимация), таблицы/реестры (sticky/skeleton/row-actions), формы (focus-glow, Combobox, dropzone), EmptyState + Toaster везде.

**Фаза D4 — Полировка**
Confetti/success-морфы, спарклайны, density toggle, аудит `prefers-reduced-motion` и a11y-контраста.

**Риски / ограничения**
- **Размер бандла**: motion + Lottie-плеер + иконки. Митигировать: динамический импорт Magic UI, Lordicon только на нужных экранах, tree-shaking Iconsax.
- **Lordicon лицензия**: бесплатно 8 900 иконок (без атрибуции для embed), полный набор — PRO $29/мес. До старта согласовать, хватает ли free-набора.
- **TS strict / i18n**: все компоненты — строгий TS, тексты RU (по CLAUDE.md).
- **Перегрузить анимацией легко** — правило: «вау» дозированно (hero-экраны), рабочие экраны (финансы/реестр) остаются быстрыми и спокойными.
- **A11y**: при переходе на Radix-примитивы выигрываем доступность; самописные модалки/дропдауны постепенно заменить.

---

## 8. Источники и каталоги

- **Magic UI** — компоненты и доки: https://magicui.design/docs/components
  Ключевые: number-ticker, border-beam, shine-border, magic-card, bento-grid, animated-list, shimmer-button, interactive-hover-button, pulsating-button, neon-gradient-card, animated-circular-progress-bar, confetti, blur-fade, dot-pattern, particles, ripple, marquee, text-animate, typing-animation.
- **21st.dev** — маркетплейс: https://21st.dev (категории UI: Buttons, Cards, Tables, Sidebars, Dialogs/Modals, Inputs, Selects, Date Pickers, File Uploads, Empty States, Toasts, Badges, Tabs, Tooltips, AI Chats).
- **Lordicon wired/outline** — https://lordicon.com/icons/wired/outline · React: `@lordicon/react` (https://www.npmjs.com/package/@lordicon/react) · доки https://lordicon.com/docs/react
- **Iconsax** — https://iconsax.io · React: `iconsax-react` (MIT, https://www.npmjs.com/package/iconsax-react) · превью https://iconsax-react.pages.dev
- **motion (framer-motion)** — https://motion.dev · **Radix UI** — https://www.radix-ui.com

---

> Следующий шаг по workflow проекта: после выбора приоритетов — `designer` пишет детальное ТЗ по выбранным экранам (Tailwind-токены, состояния, RU-копирайт), затем `frontend-specialist` реализует, `qa-tester` проверяет.
