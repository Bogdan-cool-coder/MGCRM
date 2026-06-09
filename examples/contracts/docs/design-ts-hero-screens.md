# ТЗ: Design v2 — Hero-экраны (Login · Дашборд · Канбан)

> Версия: 1.0 · Дата: 2026-06-04 · Автор: designer  
> Фаза: D2 (Hero-зоны). Предполагает, что **D0 (tokens v2)** уже внедрён — шкалы success/warning/danger/info {50..700}, shadow-elev-{1..4}, rounded-xl/2xl, `.lift`, motion-токены.  
> Стек: Next.js 14 / TS strict / Tailwind / SWR / Bootstrap Icons (bi-*) / `motion` (framer-motion) / Radix точечно (B1)  
> Иконки: **Bootstrap Icons остаются**; места, где их позже можно заменить на Iconsax, помечены `[→ Iconsax]`.  
> i18n: нет. Все тексты — RU напрямую в JSX.  
> Mobile: desktop-first. Адаптив — будущий эпик 10.

---

## Приоритет реализации

1. Login (самый изолированный, нет зависимостей)
2. Дашборд (зависит от D0 + существующих виджетов)
3. Канбан (зависит от D0 + существующих DealCard/KanbanColumn)

---

---

# Экран 1 — Login (v2)

## 1. Цель и что меняется vs текущая реализация

**Цель:** первое впечатление о продукте — «серьёзный современный инструмент», не «корп-форма».

**Текущее** (`apps/web/src/app/login/page.tsx`): центрированная `.card` на `bg-gray-100`, подпись «Генератор договоров MACRO Global», поля без иконок, кнопка без shimmer, нет анимации появления.

**Что меняем:**
- Раскладка: одна колонка → split-screen (lg: 2 колонки, левая брендовая, правая форма)
- Левая панель: тёмный фон `#101d3a`, анимированный `dot-pattern` + 3 дрейфующих градиентных блоба, статистика продукта, подпись
- Правая панель (форма): карточка с Border Beam, поля с leading-иконкой + focus-glow + show/hide пароля, shimmer-кнопка «Войти», stagger-появление элементов (Blur Fade / fade-up)
- Подпись обновлена: «MACRO CRM» вместо «Генератор договоров»
- Добавить чекбокс «Запомнить меня» + ссылку «Забыли пароль?» (визуальная, логика — открытый вопрос)
- Mobile: левая панель скрыта (`hidden lg:flex`), логотип появляется над формой

## 2. Раскладка

```
 sm/md (< lg)               lg+
┌─────────────────────┐    ┌────────────────┬──────────────────────┐
│                     │    │                │                      │
│  [Logo MACRO CRM]   │    │  BRAND PANEL   │  [Форма входа]       │
│                     │    │  (тёмный bg)   │                      │
│  ┌─────────────┐    │    │  · dot-pattern │  ┌────────────────┐  │
│  │ [Форма]     │    │    │  · 3 блоба     │  │ Border Beam    │  │
│  │ Border Beam │    │    │  · MACRO CRM   │  │ card           │  │
│  └─────────────┘    │    │  · слоган      │  └────────────────┘  │
│                     │    │  · статистика  │                      │
│ © MACRO Global      │    │  · © копирайт  │  © MACRO Global      │
└─────────────────────┘    └────────────────┴──────────────────────┘
```

- `min-h-screen grid lg:grid-cols-2`
- Левая — `hidden lg:flex flex-col justify-between p-12`
- Правая — `flex items-center justify-center p-6`
- Форма внутри: `w-full max-w-md`
- Брейкпоинты: `sm` — без изменений (форма на весь экран), `lg` — split

## 3. Компоненты и состояния

### 3.1 Левая брендовая панель

**Структура:**
- Корневой `div.brand-bg` с `position: relative; overflow: hidden; background: #101d3a`
- `DotPattern` из Magic UI (motion) — поверх фона ([→ magicui.design/docs/components/dot-pattern](https://magicui.design/docs/components/dot-pattern))
  - Применять как абсолютно позиционированный слой с `mask` radial-gradient (центр непрозрачен, края прозрачные, `opacity: .08`)
  - `prefers-reduced-motion`: компонент рендерится, анимация (если есть pulse) отключается
- 3 градиентных блоба (абсолютные `div`): `blur-[60px] opacity-50 rounded-full animate-[drift_18s_ease-in-out_infinite]`
  - `.b1`: `w-[420px] h-[420px] bg-[#2B4987] top-[-60px] left-[-40px]`
  - `.b2`: `w-[360px] h-[360px] bg-[#3b6fd4] bottom-[-80px] right-[-30px] [animation-delay:-6s]`
  - `.b3`: `w-[300px] h-[300px] bg-[#1b3263] top-[40%] left-[45%] [animation-delay:-11s]`
  - `@keyframes drift`: `0%,100%: translate(0,0) scale(1)` → `33%: translate(40px,-30px) scale(1.08)` → `66%: translate(-30px,25px) scale(.95)`
  - `prefers-reduced-motion`: `animation: none`

**Логотип-шапка (`z-10 relative`):**
```
┌─────────────────────────┐
│ [M] MACRO CRM           │
└─────────────────────────┘
```
- `div.h-11.w-11.rounded-xl.bg-white/10.backdrop-blur` + `span` «M» `font-extrabold text-lg text-white`
- `span.text-lg.font-bold.tracking-tight.text-white` «MACRO CRM»

**Основной контент (центр/низ, `z-10 relative max-w-md`):**
- `h2.text-3xl.font-bold.leading-tight.text-white`:  
  «Единая система продаж, договоров и финансов MACRO Global»
- `p.mt-4.text-white/70.text-sm`:  
  «Воронки, реестр клиентов, документооборот, аналитика и финучёт — в одном окне.»
- Статистика (3 столбца, `mt-8 flex items-center gap-6 text-white/80`):
  ```
  [121+]          [128]           [14]
  контрагентов    подписок        этапов воронки
  ```
  Разделители: `div.h-8.w-px.bg-white/15`

**Копирайт (низ):** `p.text-xs.text-white/40` «© MACRO Global Technologies»

### 3.2 Правая панель — карточка с Border Beam

**Обёртка Border Beam:**
```tsx
// псевдо-CSS реализация (или Magic UI компонент):
// magicui.design/docs/components/border-beam
// Вариант: CSS @property --a + conic-gradient + mask-composite: exclude
```
- Карточка: `rounded-2xl bg-white dark:bg-gray-800/80 dark:backdrop-blur border border-gray-200 dark:border-white/10 shadow-elev-4 p-8`
- Border Beam: анимированный контур (синий тон `#4f86ff` → `#9cc2ff`), скорость 6s, `pointer-events: none`
- `prefers-reduced-motion`: `animation: none` на Border Beam

**Логотип (только mobile, `lg:hidden`):**
- `div.mb-8.flex.justify-center` с `<Logo />` или инлайн-версией

**Заголовок формы (Blur Fade, stagger шаг 0):**
- `h1.text-2xl.font-bold.tracking-tight` «Вход в систему»
- `p.text-gray-500.dark:text-gray-400.text-sm.mt-1.mb-6` «Рады видеть снова»

**Поле Email (Blur Fade, stagger шаг 1):**
- `label.text-sm.font-medium.text-gray-700.dark:text-gray-300.mb-1.5.block` «Email»
- Обёртка: `div.relative`
  - Leading-иконка: `i.bi.bi-envelope.absolute.left-3.top-1/2.-translate-y-1/2.text-gray-400` [→ Iconsax `Sms` Linear]
  - `input.w-full.rounded-xl.border.border-gray-300.dark:border-white/10.bg-white.dark:bg-gray-900/50.pl-10.pr-3.py-2.5.text-[15px].outline-none.transition`
  - Focus: `focus:border-primary-light focus:ring-4 focus:ring-primary-light/15`
- Состояния: default / focus (кольцо primary-light) / error (border-danger + ring-danger/15 + сообщение под полем)

**Поле Пароль (Blur Fade, stagger шаг 2):**
- Аналогично Email, leading-иконка `bi-lock` [→ Iconsax `Lock` Linear]
- Trailing-иконка (toggle show/hide): `button.absolute.right-3.top-1/2.-translate-y-1/2.text-gray-400.hover:text-gray-600`
  - State `showPassword`: `bi-eye` → `bi-eye-slash`
  - `type="button"` (не submit)

**Строка «Запомнить меня» + «Забыли пароль?» (Blur Fade, stagger шаг 3):**
- `div.flex.items-center.justify-between.text-sm`
- Слева: `label.inline-flex.items-center.gap-2.text-gray-600.dark:text-gray-400`
  - `input[type="checkbox"].rounded.border-gray-300.text-primary-light.focus:ring-primary-light`
  - «Запомнить меня»
- Справа: `a.text-primary-light.dark:text-blue-300.hover:underline` «Забыли пароль?» (href=`#` пока)

**Кнопка «Войти» — Shimmer Button (Blur Fade, stagger шаг 3):**
- Magic UI: [magicui.design/docs/components/shimmer-button](https://magicui.design/docs/components/shimmer-button)
- Реализация: либо через Magic UI компонент, либо CSS-анимация:
  ```
  background: linear-gradient(110deg, #172747 0%, #2B4987 45%, #3b6fd4 50%, #2B4987 55%, #172747 100%)
  background-size: 220% 100%
  animation: shimmer 3.5s linear infinite
  @keyframes shimmer { to { background-position: -220% 0 } }
  ```
- Классы: `w-full text-white font-medium rounded-xl py-2.5 shadow-elev-2 hover:shadow-elev-3 hover:-translate-y-0.5 transition`
- Loading state: `disabled` + текст «Входим…» + shimmer продолжает идти (или переключается на спиннер через `bi-arrow-clockwise animate-spin`)
- `prefers-reduced-motion`: shimmer-анимация `none`, кнопка — обычная `bg-primary`

**Разделитель «или»:**
```
──────── или ────────
```
- `div.relative.my-6`
- `div.absolute.inset-0.flex.items-center > div.w-full.border-t.border-gray-200.dark:border-white/10`
- `div.relative.flex.justify-center.text-xs.uppercase.tracking-wide > span.bg-white.dark:bg-gray-800.px-3.text-gray-400`

**SSO-кнопки:**
- Компонент `<SsoButtons />` — **логику не трогаем**, только обновляем классы:
  - `btn-secondary` → `rounded-xl border border-gray-300 dark:border-white/10 py-2.5 text-sm font-medium hover:bg-gray-50 dark:hover:bg-white/5 transition inline-flex items-center justify-center gap-2 flex-1`
  - `bi-google` / `bi-yandex` иконки остаются

**Футер страницы (вне карточки):**
- `p.text-center.text-xs.text-gray-400.mt-6` «Защищено 2FA · MACRO Global Technologies»

### 3.3 Animations (Blur Fade + stagger)

- Magic UI: [magicui.design/docs/components/blur-fade](https://magicui.design/docs/components/blur-fade)
- Реализация: `motion` (`AnimatePresence` + `m.div`) или CSS `@keyframes fadeUp`
- Каждый элемент формы — `fadeUp` с нарастающим `animation-delay`:
  - Заголовок h1+p: `delay: 0ms`
  - Поле Email: `delay: 60ms`
  - Поле Пароль: `delay: 120ms`
  - Строка remember/forgot + кнопка: `delay: 180ms`
  - Разделитель + SSO: `delay: 240ms`
- `@keyframes fadeUp`: `from { opacity:0; transform: translateY(10px) } to { opacity:1; transform:none }`
- Длительность: `0.6s cubic-bezier(.2,.8,.2,1)` (= `motion-token: base` = 200ms × 3 = ~600ms total, медленнее для первого экрана)
- `prefers-reduced-motion`: все анимации `none`, элементы видимы сразу

## 4. Magic UI компоненты

| Компонент | Где | Ссылка |
|---|---|---|
| **Dot Pattern** | Фон левой панели (слой за блобами) | [magicui.design/docs/components/dot-pattern](https://magicui.design/docs/components/dot-pattern) |
| **Border Beam** | Контур карточки формы | [magicui.design/docs/components/border-beam](https://magicui.design/docs/components/border-beam) |
| **Shimmer Button** | Кнопка «Войти» | [magicui.design/docs/components/shimmer-button](https://magicui.design/docs/components/shimmer-button) |
| **Blur Fade** | Stagger-появление полей формы | [magicui.design/docs/components/blur-fade](https://magicui.design/docs/components/blur-fade) |

> Все Magic UI компоненты используют `motion` (framer-motion). Если выбирать CSS-реализацию без доп. зависимостей — аналоги описаны выше через `@keyframes`.

## 5. Анимации / микровзаимодействия

| Эффект | Параметры | Fallback (prefers-reduced-motion) |
|---|---|---|
| Блобы drift | `18s ease-in-out infinite`, opacity .55, blur 60px | `animation: none`, блобы статичны |
| fadeUp полей | 600ms total, stagger 60ms, `cubic-bezier(.2,.8,.2,1)` | `animation: none`, всё видимо сразу |
| Border Beam кольцо | `6s linear infinite` conic-gradient spin | `animation: none`, обычный border |
| Shimmer кнопки | `3.5s linear infinite` | `animation: none`, обычная bg-primary |
| Hover кнопки | `translateY(-2px) + shadow-elev-3`, duration fast (120ms) | `transition: none` |
| Focus-glow инпутов | `ring-4 ring-primary-light/15`, `transition 200ms` | Остаётся ring (визуально работает) |
| Show/hide пароля | смена иконки, `transition-opacity 120ms` | Работает без анимации |

## 6. Tailwind-классы / токены

```
// Левая панель
.brand-bg: bg-[#101d3a] относительный контейнер

// Блобы (пример b1)
absolute rounded-full blur-[60px] opacity-50
w-[420px] h-[420px] bg-[#2B4987] top-[-60px] left-[-40px]
animate-[drift_18s_ease-in-out_infinite]

// Карточка формы
rounded-2xl bg-white dark:bg-gray-800/80 dark:backdrop-blur
border border-gray-200 dark:border-white/10
shadow-elev-4 p-8

// Input поля
w-full rounded-xl border border-gray-300 dark:border-white/10
bg-white dark:bg-gray-900/50 pl-10 pr-3 py-2.5 text-[15px] outline-none transition
focus:border-primary-light focus:ring-4 focus:ring-primary-light/15
// error state:
border-danger focus:ring-danger/15

// Shimmer button
w-full text-white font-medium rounded-xl py-2.5
shadow-elev-2 hover:shadow-elev-3 hover:-translate-y-0.5 transition

// Статистика (левая панель)
text-2xl font-bold / text-xs text-white/50
```

## 7. Тексты (RU)

- Заголовок левой панели: `Единая система продаж, договоров и финансов MACRO Global`
- Подзаголовок левой панели: `Воронки, реестр клиентов, документооборот, аналитика и финучёт — в одном окне.`
- Статистика: `121+ контрагентов` · `128 подписок` · `14 этапов воронки`
- Копирайт левой панели: `© MACRO Global Technologies`
- Заголовок формы: `Вход в систему`
- Подзаголовок формы: `Рады видеть снова`
- Лейбл Email: `Email`
- Лейбл Пароль: `Пароль`
- Плейсхолдер Email: (пустой, не нужен — лейбл сверху)
- Чекбокс: `Запомнить меня`
- Ссылка: `Забыли пароль?`
- Кнопка входа default: `Войти`
- Кнопка входа loading: `Входим…`
- Разделитель: `или`
- SSO Google: `Войти через Google` (не трогаем)
- SSO Yandex: `Войти через Yandex` (не трогаем)
- Ошибка домена (SSO): `Вход разрешён только для аккаунтов @macroglobaltech.com` (не трогаем)
- Ошибка SSO generic: `Не удалось войти через внешний аккаунт. Попробуй ещё раз.` (не трогаем)
- Ошибка login: оставляем из backend ответа / `Не удалось войти` (не трогаем)
- Футер страницы: `Защищено 2FA · MACRO Global Technologies`

## 8. Accessibility

- `<form>` с правильными `id` + `htmlFor` на всех `<label>`
- `autoComplete="email"` / `autoComplete="current-password"`
- Кнопка show/hide пароля: `aria-label="Показать пароль"` / `"Скрыть пароль"`, `type="button"`
- Кнопка входа: `aria-busy={loading}` при загрузке
- Error сообщение: `role="alert"` или `aria-live="polite"` для inline-ошибки под формой
- Фокус-кольцо: `focus-visible:ring-2 focus-visible:ring-primary-light` на кнопках/ссылках (не `focus:` — только keyboard)
- Контраст: текст `text-gray-700` на `bg-white` — WCAG AA; `text-white/70` на `#101d3a` — WCAG AA (проверить при реализации)
- Dot Pattern и блобы: `aria-hidden="true"` (декоративные)
- Tab order: Email → Password → remember-checkbox → forgot-link → Войти → SSO

## 9. Что НЕ трогаем

- Логику `useEffect` для sso_error из query params
- `router.push("/auth/2fa")` при `requires_2fa`
- `router.push("/contracts")` после успешного логина
- `api<LoginResponse>` вызов
- `SsoButtons.tsx` — только переоформляем классы кнопок
- Cookie-auth, credentials: same-origin
- `SsoIcons.tsx` (GoogleIcon, YandexIcon)

---

---

# Экран 2 — Дашборд (v2)

## 1. Цель и что меняется vs текущая реализация

**Цель:** превратить набор виджетов в «информационный центр управления» с глубиной, числами и движением.

**Текущее** (`apps/web/src/app/(app)/dashboard/page.tsx` + `components/Dashboard/*`):
- KpiRow: 5 карточек `card p-4`, числа через `text-h2` (без анимации), кнопка Link
- StatusGroupTiles: 4 плитки `card p-5` с `text-h2` числами
- MyTasksWidget + HotDealsWidget: плоские карточки, text «Загружаем…» / skeleton `animate-pulse`
- PageHeader: плоская полоса, нет eyebrow
- Нет Bento-раскладки, нет Number Ticker, нет спарклайнов, нет Magic Card, нет Border Beam на HOT

**Что меняем:**
- `PageHeader` — добавить eyebrow «Обзор» над заголовком, backdrop-blur при скролле (sticky)
- KPI-карточки → Magic Card + Number Ticker + спарклайн + тренд-чип
- HOT-deals widget → Border Beam оранжевый
- Tasks widget → Animated List stagger
- StatusGroupTiles → семантические surface-цвета (success-50/warning-50/info-50)
- Все виджеты → `rounded-2xl shadow-elev-1 hover:shadow-elev-2 lift`
- Loading → скелетоны рядов (не «Загружаем…»)
- AI-teaser блок — новый виджет (градиентный, primary→primary-light)
- DashboardCustomizer и PDF-экспорт — **не трогаем** (логика сохранена)

## 2. Раскладка — Bento Grid

```
┌─────────────────────────────────────────────────────────────────┐
│ [Sidebar 240px]  │  [PageHeader: sticky backdrop-blur]          │
│                  │  eyebrow «Обзор» · «Дашборд»  [Настроить][PDF]│
│                  ├─────────────────────────────────────────────-─┤
│                  │ p-8 space-y-6                                  │
│                  │                                                │
│                  │ ┌─────┐ ┌─────┐ ┌─────┐ ┌─────┐             │
│                  │ │ KPI │ │ KPI │ │ KPI │ │ KPI │  ← KPI row  │
│                  │ └─────┘ └─────┘ └─────┘ └─────┘             │
│                  │                                                │
│                  │ ┌──────────────────────┐ ┌──────────────┐    │
│                  │ │ Воронка (lg:col-2)   │ │ HOT-сделки   │    │
│                  │ │ FunnelConversionWgt  │ │ Border Beam  │    │
│                  │ └──────────────────────┘ └──────────────┘    │
│                  │                                                │
│                  │ ┌──────────────┐ ┌──────────────┐ ┌────────┐ │
│                  │ │ Мои задачи   │ │ Договоры по  │ │ AI     │ │
│                  │ │ AnimatedList │ │ статусу      │ │ Teaser │ │
│                  │ └──────────────┘ └──────────────┘ └────────┘ │
└─────────────────────────────────────────────────────────────────┘
```

- Все виджеты рендерятся через существующий `renderVisible()` — порядок и видимость сохраняются
- Контейнер `div#dashboard-root` → `space-y-6`
- KPI row: `grid grid-cols-2 lg:grid-cols-4 gap-4`
- Bento middle row: `grid grid-cols-1 lg:grid-cols-3 gap-4` (воронка `lg:col-span-2`, HOT `col-span-1`)
- Bento bottom row: `grid grid-cols-1 lg:grid-cols-3 gap-4`

## 3. Компоненты и состояния

### 3.1 PageHeader (обновление)

Файл: `apps/web/src/components/PageHeader.tsx`

**Изменения:**
- Добавить `sticky top-0 z-10 backdrop-blur bg-white/80 dark:bg-gray-900/80`  
  (сейчас: `bg-white dark:bg-gray-800`, нет sticky, нет blur)
- Добавить eyebrow над title (опциональный prop `eyebrow?: string`):
  ```tsx
  {eyebrow && (
    <div className="text-[11px] uppercase tracking-wider text-gray-400 font-semibold mb-0.5">
      {eyebrow}
    </div>
  )}
  ```
- Заголовок `h1` → `text-xl font-bold leading-tight` (сейчас `text-h3`)
- Высота `min-h-[76px]` → `h-[68px]` (чуть компактнее, как в прототипе)
- Добавить поиск-кнопку в header (вместо `/` в sidebar):
  ```
  [search trigger: "Поиск… ⌘K"]  [bell]  [theme]  [+ Новая сделка]
  ```
  - search: `div.hidden.md:flex.items-center.gap-2.rounded-xl.border.border-gray-200.dark:border-white/10.px-3.py-2.text-sm.text-gray-400.cursor-text.hover:bg-gray-50.transition` — click → `openSearch()` из SearchContext
  - bell: иконка `bi-bell` в `button.h-9.w-9.grid.place-items-center.rounded-xl.border`
  - Notification dot: `span.absolute.top-2.right-2.h-1.5.w-1.5.rounded-full.bg-danger-500` (если есть unread)
  - Кнопка «Новая сделка»: `btn-primary rounded-xl h-9 px-4 text-sm inline-flex items-center gap-2 shadow-elev-1 hover:shadow-elev-2 hover:-translate-y-0.5 transition`

**Применение для дашборда:**
```tsx
<PageHeader
  title="Дашборд"
  eyebrow="Обзор"
  actions={...}
/>
```

### 3.2 KPI-карточки — Magic Card + Number Ticker + Sparkline

**Компонент:** переписать `KpiRow` → `KpiCard` в `apps/web/src/components/Dashboard/KpiCard.tsx`

**Структура KpiCard:**
```tsx
interface KpiCardProps {
  label: string;
  value: number | string | undefined;
  suffix?: string;
  trend?: { value: number; label: string }; // напр. { value: 18, label: "к прошлому" }
  trendDown?: boolean;
  sparklineData?: number[];
  sparklineColor?: string; // "#039855" / "#1570EF" / "#D92D20" / "#DC6803"
  iconClass?: string; // "bi-graph-up-arrow"
  iconBg?: string; // "bg-success-50 dark:bg-success-500/10"
  iconColor?: string; // "text-success-600"
  href?: string;
}
```

**Визуал (Magic Card):**
- Magic UI: [magicui.design/docs/components/magic-card](https://magicui.design/docs/components/magic-card)
- Spotlight: radial-gradient за курсором через `onMouseMove` + CSS variable `--x / --y`
  ```css
  .magic::before {
    background: radial-gradient(420px circle at var(--x,50%) var(--y,50%),
      rgba(43,73,135,.10), transparent 60%);
    opacity: 0; transition: opacity .3s;
  }
  .magic:hover::before { opacity: 1 }
  .dark .magic::before { background: radial-gradient(420px circle at var(--x,50%) var(--y,50%),
    rgba(120,160,255,.12), transparent 60%) }
  ```
- Базовые классы карточки: `rounded-2xl bg-white dark:bg-gray-800/60 border border-gray-200 dark:border-white/10 shadow-elev-1 hover:shadow-elev-2 p-5 lift magic`
- Icon-badge (правый верхний угол): `h-8 w-8 grid place-items-center rounded-lg {iconBg}`

**Number Ticker:**
- Magic UI: [magicui.design/docs/components/number-ticker](https://magicui.design/docs/components/number-ticker)
- Запускается при монтировании когда `value !== undefined` (данные пришли от SWR)
- Длительность накрутки: `1100ms cubic-bezier(0,0,.58,1)` (ease-out)
- `prefers-reduced-motion`: показать финальное значение сразу, без анимации
- Классы: `text-3xl font-bold tabular-nums mt-3`

**Тренд-чип:**
```tsx
{trend && (
  <div className={`mt-1 text-xs font-medium inline-flex items-center gap-1 ${trendDown ? "text-danger-600" : "text-success-600"}`}>
    <i className={`bi ${trendDown ? "bi-arrow-down-short" : "bi-arrow-up-short"}`} />
    {trendDown ? "−" : "+"}{trend.value}% {trend.label}
  </div>
)}
```

**Sparkline (mini SVG):**
```tsx
<svg className="mt-3 w-full h-8" viewBox="0 0 100 30" preserveAspectRatio="none">
  <polyline
    points={...}  // вычисляется из sparklineData
    fill="none"
    stroke={sparklineColor}
    strokeWidth="2"
    vectorEffect="non-scaling-stroke"
  />
</svg>
```
- Данные: последние 7-8 значений (откуда брать — открытый вопрос, см. раздел 11)
- Если данных нет — спарклайн не рендерится

**Состояния KpiCard:**
- Loading: `div.rounded-2xl.animate-pulse.bg-gray-100.dark:bg-gray-700.h-[140px]` (скелетон всей карточки)
- Empty (value = undefined / `—`): показать `—` без тикера, без тренда
- Hover: spotlight + translateY(-3px) + shadow-elev-2

**Применение в KpiRow:**
- Карточка «Всего договоров» → `KpiCard` с `href="/contracts"`, `iconClass="bi-file-earmark-text"`, `iconBg="bg-info-50"`, `iconColor="text-info-600"`
- Карточка «Ждут согласования» → `KpiCard`, `iconBg="bg-warning-50"`, `iconColor="text-warning-600"`
- Карточка «Ср. время согласования» → `KpiCard`, `iconBg="bg-primary/5"`, `iconColor="text-primary"`
- Карточка «Ср. цикл до подписания» → `KpiCard`, `iconBg="bg-success-50"`, `iconColor="text-success-600"`
- `DealsWithoutTasksTile` — адаптировать под тот же `rounded-2xl shadow-elev-1 lift` стиль, но логику не трогать

### 3.3 HotDealsWidget — Border Beam

Файл: `apps/web/src/components/Dashboard/HotDealsWidget.tsx`

**Изменения (только визуал):**
- Карточка `card p-5` → `relative rounded-2xl bg-white dark:bg-gray-800/60 border border-gray-200 dark:border-white/10 shadow-elev-2 p-6 lift`
- Добавить Border Beam **оранжевый** — `warning-500` → `warning-300`:
  ```css
  /* conic-gradient вариация для warning */
  background: conic-gradient(from var(--a,0deg),
    transparent 0 72%, #F79009 86%, #fec84b 92%, transparent 100%)
  ```
  (или через Magic UI `<BorderBeam color="#F79009" />` если компонент будет в проекте)
- Иконка `bi-fire text-danger` → `span.h-7.w-7.grid.place-items-center.rounded-lg.bg-warning-50.dark:bg-warning-500/10.text-warning-600 > i.bi.bi-fire`
- Строки сделок: добавить цветную точку слева (уже есть `stage_color`) как `w-2 h-2 rounded-full`
- Deadline: `text-danger-600` если просрочено, `text-warning-600` если <= 3 дней

**Loading state:** 3 строки-скелетона вместо `animate-pulse div`:
```tsx
{[1,2,3].map(i => (
  <div key={i} className="flex items-center gap-2 py-2">
    <div className="w-2 h-2 rounded-full bg-gray-200 dark:bg-gray-700 animate-pulse shrink-0" />
    <div className="flex-1 h-4 bg-gray-100 dark:bg-gray-700 rounded animate-pulse" />
    <div className="w-14 h-4 bg-gray-100 dark:bg-gray-700 rounded animate-pulse" />
  </div>
))}
```

### 3.4 MyTasksWidget — Animated List (stagger)

Файл: `apps/web/src/components/Dashboard/MyTasksWidget.tsx`

**Изменения:**
- Карточка `card p-5` → `rounded-2xl bg-white dark:bg-gray-800/60 border border-gray-200 dark:border-white/10 shadow-elev-1 hover:shadow-elev-2 p-6 lift`
- Шапка: добавить badge-счётчик задач рядом с заголовком: `span.text-xs.bg-info-50.text-info-700.dark:bg-info-500/10.dark:text-info-500.rounded-full.px-2.py-0.5.font-medium`
- Задачи rендерятся с Animated List stagger:
  - Magic UI: [magicui.design/docs/components/animated-list](https://magicui.design/docs/components/animated-list)
  - Реализация через `motion`: каждый элемент `m.li` с `initial={{ opacity:0, y:8 }}` → `animate={{ opacity:1, y:0 }}` + `transition={{ delay: index * 0.05, duration: 0.2 }}`
  - `prefers-reduced-motion`: обычный `ul` без анимации
- Статус-иконка задачи: `bi-check2-square` для task, `bi-telephone` для call, `bi-camera-video` для meeting — с `text-primary` окраской
- Дедлайн-чип: оставляем логику `fmtDue` + классы, добавляем `font-semibold` для overdue

**Loading state:**
```tsx
{[1,2,3,4].map(i => (
  <li key={i} className="flex items-center gap-2 py-1.5">
    <div className="w-4 h-4 rounded bg-gray-100 dark:bg-gray-700 animate-pulse shrink-0" />
    <div className="flex-1 h-4 bg-gray-100 dark:bg-gray-700 rounded animate-pulse" />
    <div className="w-12 h-3 bg-gray-100 dark:bg-gray-700 rounded animate-pulse" />
  </li>
))}
```

**Empty state:**
- `div.flex-1.flex.flex-col.items-center.justify-center.gap-2.py-6`
- `i.bi.bi-check2-circle.text-4xl.text-success-500` [→ Iconsax `TickCircle` Bold]
- `div.text-sm.font-medium` «Всё сделано»
- `div.text-xs.text-gray-500` «На сегодня задач нет»

### 3.5 StatusGroupTiles (обновление)

Файл: `apps/web/src/components/Dashboard/StatusGroupTiles.tsx`

**Изменения:**
- Карточки `card p-5` → `rounded-2xl shadow-elev-1 hover:shadow-elev-2 lift p-5 block transition`
- Добавить семантические surface по group.code:
  ```
  draft_group:      bg-gray-50 dark:bg-white/5         (нейтральный)
  in_review_group:  bg-warning-50 dark:bg-warning-500/10
  approved_group:   bg-success-50 dark:bg-success-500/10
  archived_group:   bg-gray-50 dark:bg-white/5
  ```
- Числа `text-h2 text-primary` → Number Ticker (см. 3.2) с семантической окраской:
  ```
  in_review_group: text-warning-700 dark:text-warning-500
  approved_group:  text-success-700 dark:text-success-500
  ```
- Иконки (GROUP_ICONS) → добавить `iconBg` в тайле:
  ```
  in_review_group: h-8 w-8 rounded-lg bg-warning-100 dark:bg-warning-500/20 text-warning-600
  approved_group:  h-8 w-8 rounded-lg bg-success-100 dark:bg-success-500/20 text-success-600
  ```

**Loading state:** 4 скелетона `rounded-2xl h-28 animate-pulse bg-gray-100 dark:bg-gray-700` (уже есть, только уточнить классы)

### 3.6 AI-Teaser Widget (новый)

Файл: `apps/web/src/components/Dashboard/AiTeaserWidget.tsx`

**Структура:**
- `div.rounded-2xl.p-6.lift.text-white.relative.overflow-hidden`
- Background: `background: linear-gradient(135deg, #172747, #2B4987 60%, #3b6fd4)`
- Декоративный блоб: `div.absolute.-right-6.-top-6.h-28.w-28.rounded-full.bg-white/10.blur-xl`
- Icon + заголовок:
  ```tsx
  <span className="h-8 w-8 grid place-items-center rounded-lg bg-white/15 backdrop-blur">
    <i className="bi bi-stars" /> {/* [→ Lordicon sparkle loop при idle] */}
  </span>
  <h3 className="font-semibold">AI-ассистент</h3>
  ```
- Текст `p.text-sm.text-white/80`:  
  «Спрашивай голосом или текстом — создавай сделки, задачи и договоры в диалоге.»
- Кнопка `button.mt-4.w-full.rounded-xl.bg-white/15.hover:bg-white/25.backdrop-blur.py-2.text-sm.font-medium.transition.inline-flex.items-center.justify-center.gap-2`:  
  `bi-chat-dots` «Открыть ассистента» → `onClick`: открывает `AiAssistantButton` (из layout)

**Взаимодействие:** при клике — диспатчим кастомный event `crm:open-ai` (аналогично `crm:open-search`), layout перехватывает

**Состояния:** нет loading/empty (статический виджет)

### 3.7 FunnelConversionWidget (обновление стиля)

Файл: `apps/web/src/components/Dashboard/FunnelConversionWidget.tsx`

**Изменения (только визуал):**
- Карточка `card` → `rounded-2xl bg-white dark:bg-gray-800/60 border border-gray-200 dark:border-white/10 shadow-elev-1 p-6 lift`
- Шапка: добавить eyebrow «Воронка продаж» над заголовком
- Бары `h-2` → `h-2.5 rounded-full`, с анимацией ширины при монтировании (motion `initial={{ width:0 }}` → `animate={{ width: '...%' }}`, duration 600ms stagger 100ms)
- Последний бар (success): `bg-success-500 text-success-600 font-semibold`
- Бар trial/hot: `bg-warning-500`
- Остальные: `bg-info-500` (ранние этапы) / `bg-primary-light` (встреча+)
- `prefers-reduced-motion`: ширина сразу, без transition

## 4. Magic UI компоненты

| Компонент | Где | Ссылка |
|---|---|---|
| **Magic Card** (spotlight) | KPI-карточки | [magicui.design/docs/components/magic-card](https://magicui.design/docs/components/magic-card) |
| **Number Ticker** | KPI-числа + StatusGroupTiles | [magicui.design/docs/components/number-ticker](https://magicui.design/docs/components/number-ticker) |
| **Border Beam** | HotDealsWidget контур | [magicui.design/docs/components/border-beam](https://magicui.design/docs/components/border-beam) |
| **Animated List** | MyTasksWidget — stagger задач | [magicui.design/docs/components/animated-list](https://magicui.design/docs/components/animated-list) |
| **Blur Fade** | fade-in виджетов при монтировании | [magicui.design/docs/components/blur-fade](https://magicui.design/docs/components/blur-fade) |

> **Про Bento Grid:** Magic UI [bento-grid](https://magicui.design/docs/components/bento-grid) — это обёртка для разноразмерных плиток. В нашем случае Bento реализуется через стандартные Tailwind `grid`-классы (col-span). Если frontend-specialist захочет использовать компонент — ок, но `grid grid-cols-3 gap-4` с `lg:col-span-2` достаточно.

## 5. Анимации / микровзаимодействия

| Эффект | Параметры | Fallback |
|---|---|---|
| fade-in виджетов | `fadeUp 500ms stagger 50ms`, при монтировании | видимы сразу |
| Number Ticker KPI | `1100ms ease-out`, при наличии данных | число сразу |
| Magic Card spotlight | CSS `radial-gradient` через mousemove, `transition opacity 300ms` | нет spotlight |
| Border Beam HOT | `5s linear infinite` conic-gradient | обычный border |
| Animated List задачи | `delay: index*50ms, duration 200ms` motion | обычный ul |
| `.lift` hover | `translateY(-3px)`, `shadow-elev-1→elev-2`, fast 120ms | нет transform |
| Funnel bars | ширина `0→N%`, `600ms stagger 100ms` | ширина сразу |
| Sparklines | SVG статичные (нет анимации) | — |

## 6. Tailwind-классы / токены

```
// Виджет-карточка (базовый стиль)
rounded-2xl bg-white dark:bg-gray-800/60
border border-gray-200 dark:border-white/10
shadow-elev-1 hover:shadow-elev-2
p-5 lift

// HOT widget (дополнительно)
shadow-elev-2 + Border Beam

// Status tile surfaces
bg-warning-50 dark:bg-warning-500/10   → in_review
bg-success-50 dark:bg-success-500/10   → approved
bg-gray-50 dark:bg-white/5             → draft/archived

// KPI icon badge
h-8 w-8 grid place-items-center rounded-lg
{bg-success-50 | bg-info-50 | bg-warning-50 | bg-primary/5}
{text-success-600 | text-info-600 | text-warning-600 | text-primary}

// Number Ticker
text-3xl font-bold tabular-nums mt-3

// Trend chip
text-xs font-medium inline-flex items-center gap-1
{text-success-600 | text-danger-600}

// PageHeader update
sticky top-0 z-10
bg-white/80 dark:bg-gray-900/80 backdrop-blur
border-b border-gray-200 dark:border-white/10
h-[68px] flex items-center justify-between px-8
```

## 7. Тексты (RU)

- Eyebrow PageHeader: `Обзор`
- Заголовок страницы: `Дашборд`
- Кнопка настройки: `Настроить` (без изменений)
- Кнопка PDF: `Экспорт в PDF` / `Готовим PDF…` (без изменений)
- KPI «Всего договоров» — лейбл: `Всего договоров`
- KPI «Ждут согласования»: `На согласовании`
- KPI «Ср. время согласования»: `Ср. время согласования, дн`
- KPI «Ср. цикл до подписания»: `Ср. цикл до подписания, дн`
- Тренд up: `+{N}% к прошлому` / Тренд down: `−{N}% к плану`
- Виджет HOT: заголовок `HOT-сделки` (без изменений по сути)
- Виджет задачи: заголовок `Мои задачи на сегодня`
- Empty задачи title: `Всё сделано`
- Empty задачи description: `На сегодня задач нет`
- AI-teaser title: `AI-ассистент`
- AI-teaser description: `Спрашивай голосом или текстом — создавай сделки, задачи и договоры в диалоге.`
- AI-teaser кнопка: `Открыть ассистента`
- Empty дашборда: `Все виджеты скрыты` / `Включи хотя бы один виджет, чтобы видеть данные.` / `Настроить дашборд` (без изменений)
- Поиск placeholder: `Поиск…`
- Kbd: `⌘K`
- Кнопка «Новая сделка»: `Новая сделка`

## 8. Accessibility

- `id="dashboard-root"` сохранить (нужен для PDF-экспорта)
- Magic Card spotlight — `aria-hidden="true"` на `::before` (CSS, не DOM)
- Number Ticker: итоговое число должно быть в DOM (скринридер читает конечное значение)
- Border Beam: `aria-hidden="true"` на `::after` псевдоэлементе
- Animated List: `role="list"` + `role="listitem"` на `ul/li`
- Фокус-кольцо на кнопках header: `focus-visible:ring-2 focus-visible:ring-primary`
- Скелетоны: `aria-busy="true"` на контейнере + `aria-label="Загружаем данные"`
- Ссылки в виджетах: текст ссылки понятен без контекста («Открыть все сделки» — ок; «здесь» — нет)
- Кнопка «Настроить»: `aria-haspopup="dialog"` + `aria-expanded={customizerOpen}`

## 9. Что НЕ трогаем

- `DashboardCustomizer` — логика, API-вызов, порядок виджетов
- `exportDashboardToPdf` — логика и `id="dashboard-root"` на контейнере
- `DEFAULT_LAYOUT` / `mergeLayout` / `DashboardWidgetConfig` / `DashboardWidgetId`
- SWR-ключи `/analytics/contracts`, `/me/dashboard-config`, `/pipelines`
- `renderVisible()` — логика парного рендера tasks+hot-deals
- `BreakdownWidget`, `RevenueForecastWidget`, `AwaitingPaymentWidget`, `PaidDealsWidget`, `HotForecastWidget` — логику не трогать (только те же базовые классы карточки через обновлённый `.card` из D0)

---

---

# Экран 3 — Канбан (v2)

## 1. Цель и что меняется vs текущая реализация

**Цель:** канбан должен выглядеть «как у Notion/Linear» — структурированные колонки с характером, карточки с глубиной и «температурой».

**Текущее** (`DealKanbanView.tsx` + `DealCard.tsx`):
- Колонки: заголовок `rounded-t-lg` с `backgroundColor: stage.color` белый текст, тело `bg-gray-50 rounded-b-lg border`
- Карточки: `bg-white border rounded-lg p-2.5`, нет left-accent, нет аватара ответственного, нет прогресса этапа
- DnD: нативный HTML5 (`draggable onDragStart onDragOver onDrop`) — **не трогаем**
- Нет sticky-заголовка колонки, нет суммы с Number Ticker, нет drag-placeholder явно в DOM

**Что меняем:**
- Колонки: `rounded-2xl` целиком (не отдельно header + body), sticky-заголовок с backdrop-blur, цветовая семантика колонки по типу, Sigma суммы
- Карточки: `left-accent` полоска по «температуре», hover-lift, drag-state (rotate + shadow), аватар ответственного (инициалы), дедлайн-чип с семантикой, прогресс-бар этапа
- Drag-placeholder: явный `div.placeholder` с пунктирной рамкой
- Empty-column: аккуратный минималистичный empty state
- Все изменения — **только визуальный слой**, DnD-логика и handlers не трогаем

## 2. Раскладка

```
┌────────────────────────────────────────────────────────────────────────┐
│ [Sidebar 240px] │ [PageHeader sticky: eyebrow «Воронка продаж» + Сделки│
│                 │  · 47 в работе] [Kanban|Список] [Filter] [+ Сделка] │
│                 ├──────────────────────────────────────────────────────┤
│                 │ overflow-x-auto overflow-y-hidden flex h-[calc...] p-6│
│                 │                                                       │
│                 │ ┌─────────┐ ┌─────────┐ ┌─────────┐ ┌─────────┐ …  │
│                 │ │Квалиф.  │ │ Встреча │ │HOT deals│ │Успешно  │    │
│                 │ │ dot·14  │ │ dot·9   │ │ fire·6  │ │check·61 │    │
│                 │ │Σ 4,1 млн│ │Σ 5,6 млн│ │Σ 7,4 млн│ │Σ 24,8 мл│    │
│                 │ ├─────────┤ ├─────────┤ ├─────────┤ ├─────────┤    │
│                 │ │[card]   │ │[card]   │ │[card HOT]│ │[card]   │    │
│                 │ │[card]   │ │...      │ │[card]    │ │...      │    │
│                 │ │[+ Добав]│ │         │ │          │ │         │    │
│                 │ └─────────┘ └─────────┘ └─────────┘ └─────────┘    │
└────────────────────────────────────────────────────────────────────────┘
```

- Board scroll: `flex-1 overflow-x-auto overflow-y-hidden`
- Inner: `flex gap-4 h-full min-w-max p-6`
- Колонка: `w-[300px] flex flex-col rounded-2xl` (не min-w, фиксированная ширина)
- Высота main: `h-[calc(100vh-68px)]` (вычитаем PageHeader)

## 3. Компоненты и состояния

### 3.1 KanbanColumn — обновление

Файл: `apps/web/src/components/Deals/DealKanbanView.tsx` (`KanbanColumn`)

**Колонка wrapper:**
```
rounded-2xl
{нейтральная: bg-gray-50 dark:bg-white/[.03] border border-gray-200 dark:border-white/10}
{HOT/warning: bg-warning-50/50 dark:bg-warning-500/[.05] border border-warning-500/30}
{success: bg-success-50/40 dark:bg-success-500/[.04] border border-success-500/25}
{lost/danger: bg-danger-50/40 dark:bg-danger-500/[.04] border border-danger-500/25}
```

**Определение семантики колонки:**
- `is_won` → success
- `is_lost` → danger
- Этапы с «HOT» в названии ИЛИ с `stage.color` близким к warning → warning
- Остальные → нейтральный

**Sticky-заголовок:**
```tsx
<div className="sticky top-0 px-4 py-3 border-b border-{semantic}/20 rounded-t-2xl bg-{semantic-50}/90 dark:bg-{semantic}/[.06] backdrop-blur z-10">
  <div className="flex items-center justify-between">
    <div className="flex items-center gap-2">
      {/* Иконка/точка статуса */}
      {isWon && <i className="bi bi-check-circle-fill text-success-600" />}
      {isLost && <i className="bi bi-x-circle-fill text-danger-600" />}
      {isHot && <i className="bi bi-fire text-warning-600" />}
      {isNeutral && <span className="h-2 w-2 rounded-full bg-info-500" />}
      {/* или stage.color через style */}
      <span className="font-semibold text-sm">{stage.name}</span>
      <span className="text-xs text-gray-400">{deals.length}</span>
    </div>
    <button onClick={() => onAddToStage(stage.id)}
      className="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
      <i className="bi bi-plus-lg" />
    </button>
  </div>
  {/* Σ суммы — Number Ticker */}
  {colAmount && (
    <div className="mt-1 text-xs text-gray-500 tabular-nums font-medium">
      Σ {colAmount} {/* colAmount уже вычислен в useMemo */}
    </div>
  )}
</div>
```

- Number Ticker на `colAmount`: запускать при монтировании если `colAmount !== ""`. Длительность `800ms`.
- `prefers-reduced-motion`: число сразу

**Тело колонки:**
```
flex-1 overflow-y-auto p-2.5 space-y-2.5
scrollbar-thin scrollbar-thumb-gray-200 dark:scrollbar-thumb-white/10
```
- Убрать `min-h-[120px]`, заменить на `flex-1` (высота задаётся через родительский flex)

**Drag-placeholder:**
```tsx
{/* Показывать когда dragOver этой колонки */}
<div className="rounded-xl h-[92px] border-2 border-dashed border-gray-300 dark:border-white/20 bg-gray-50/50 dark:bg-white/5 grid place-items-center text-xs text-gray-400 dark:text-gray-500">
  отпустите здесь
</div>
```
- Добавить `isDragOver` state в `KanbanColumn`: `onDragOver` → `setIsDragOver(true)`, `onDragLeave` → `setIsDragOver(false)`, `onDrop` → `setIsDragOver(false)` + вызов `onMove`
- Placeholder показывается когда `isDragOver && deals.length === 0` ИЛИ всегда в конце списка при `isDragOver`

**Empty column state:**
```tsx
{deals.length === 0 && !isDragOver && !isWon && (
  <div className="flex flex-col items-center justify-center py-8 text-gray-400 dark:text-gray-500">
    <i className="bi bi-inbox text-2xl mb-2" /> {/* [→ Lordicon inbox wired loop] */}
    <span className="text-xs">Нет сделок</span>
  </div>
)}
```

### 3.2 DealCard — обновление

Файл: `apps/web/src/components/Deals/DealCard.tsx`

**Базовые классы карточки:**
```tsx
"relative group bg-white dark:bg-gray-800 border rounded-xl shadow-elev-1 p-3 pl-4 text-sm select-none overflow-hidden " +
(!bulkMode ? "cursor-grab active:cursor-grabbing kcard " : "cursor-pointer ") +
(selected
  ? "border-primary ring-1 ring-primary shadow-elev-2"
  : "border-gray-200 dark:border-white/10 hover:shadow-elev-2 hover:-translate-y-0.5")
```

- `rounded-lg` → `rounded-xl` (D0 токен)
- `p-2.5` → `p-3 pl-4` (место под left-accent)
- `hover:shadow-sm` → `hover:shadow-elev-2 hover:-translate-y-0.5` (lift)
- `.kcard` CSS:
  ```css
  .kcard { transition: transform .18s cubic-bezier(.2,.8,.2,1), box-shadow .18s }
  .kcard:hover { transform: translateY(-2px) }
  .kcard:active { cursor: grabbing; transform: scale(.99) rotate(-.5deg); box-shadow: 0 16px 28px -8px rgba(16,24,40,.30) }
  ```
- `prefers-reduced-motion`: `.kcard:hover { transform: none }` / `.kcard:active { transform: none }`

**Left-accent полоска (по «температуре»):**
```tsx
<span
  className="absolute left-0 top-0 bottom-0 w-1"
  style={{ backgroundColor: accentColor }}
/>
```

`accentColor` — вычислять из `stage` (props `currentStage`):
- `stage.is_won` → `#12B76A` (success-500)
- `stage.is_lost` → `#F04438` (danger-500)
- Название содержит «HOT» ИЛИ этап — финальный sales этап (warm/hot/trial) → `#F79009` (warning-500)
- Дедлайн просрочен (`deal.next_task?.is_overdue`) → `#F04438` (danger-500)
- Иначе → `stage.color ?? "#2E90FA"` (info-500 fallback)

**Контент карточки (без изменений по данным, обновить разметку):**
```tsx
<div className={bulkMode ? "pl-6 pr-1" : "pr-5"}>
  {/* Название сделки */}
  <div className="font-medium text-sm truncate text-gray-900 dark:text-gray-100">
    {deal.title}
  </div>

  {/* Компания (если есть — второй строкой) */}
  {deal.company_name && (
    <div className="text-xs text-gray-500 dark:text-gray-400 mt-0.5 truncate">
      {deal.company_name}
    </div>
  )}

  {/* Сумма */}
  {deal.amount != null && (
    <div className="text-sm font-semibold tabular-nums mt-1.5 text-gray-900 dark:text-gray-100">
      {fmtAmount(deal.amount, deal.currency)}
    </div>
  )}

  {/* Прогресс этапа (если stage.total_steps > 1) */}
  {stage?.total_steps && stage.total_steps > 1 && (
    <div className="mt-2">
      <div className="flex justify-between text-[10px] text-gray-400 mb-1">
        <span>этап {stage.order}/{stage.total_steps}</span>
        <span>{Math.round((stage.order / stage.total_steps) * 100)}%</span>
      </div>
      <div className="h-1 rounded-full bg-gray-100 dark:bg-white/10">
        <div
          className="h-full rounded-full transition-[width]"
          style={{
            width: `${Math.round((stage.order / stage.total_steps) * 100)}%`,
            backgroundColor: stage.color ?? '#2B4987'
          }}
        />
      </div>
    </div>
  )}

  {/* Футер: аватар + дедлайн */}
  <div className="flex items-center justify-between mt-2.5">
    {/* Аватар ответственного */}
    {deal.owner_initials && (
      <div
        className="h-6 w-6 rounded-full text-white grid place-items-center text-[10px] font-semibold shrink-0"
        style={{ backgroundColor: deal.owner_color ?? '#2B4987' }}
        title={deal.owner_name ?? ""}
      >
        {deal.owner_initials}
      </div>
    )}
    {!deal.owner_initials && <span />}

    {/* Дедлайн-чип */}
    {deal.next_task && (
      <span className={`text-[11px] inline-flex items-center gap-1 ${deadlineChipClass}`}>
        <i className={`bi ${deadlineIcon} shrink-0`} />
        {fmtTaskDate(deal.next_task.due_at)}
      </span>
    )}
  </div>
</div>
```

**deadlineChipClass + deadlineIcon:**
```tsx
const isOverdue = deal.next_task?.is_overdue;
const due = deal.next_task?.due_at;
const daysLeft = due ? Math.round((new Date(due).getTime() - Date.now()) / 86400000) : null;

const deadlineChipClass = isOverdue
  ? "text-danger-600 font-semibold"
  : daysLeft !== null && daysLeft <= 3
  ? "text-warning-600 font-medium"
  : "text-gray-400";

const deadlineIcon = isOverdue
  ? "bi-exclamation-circle"
  : daysLeft !== null && daysLeft <= 3
  ? "bi-clock-history"
  : "bi-calendar3";
```

**«Просрочено»-бейдж (если deal уже просрочен — в начале карточки):**
```tsx
{isOverdue && (
  <div className="mb-1.5">
    <span className="text-[10px] font-semibold bg-danger-50 text-danger-700 dark:bg-danger-500/15 dark:text-danger-500 rounded px-1.5 py-0.5">
      просрочено
    </span>
  </div>
)}
```

**Drag state через CSS:**
```css
/* В globals.css или tailwind plugin */
.kcard[data-dragging="true"] {
  transform: scale(.99) rotate(-.5deg);
  box-shadow: 0 16px 28px -8px rgba(16,24,40,.30);
  cursor: grabbing;
  z-index: 50;
}
```
- `data-dragging` выставлять через `onDragStart` → `e.currentTarget.dataset.dragging = "true"`, `onDragEnd` → `"false"`

**Состояния карточки:**
- Default: `shadow-elev-1 border-gray-200`
- Hover: `shadow-elev-2 translateY(-2px)`
- Drag-active: `rotate(-.5deg) shadow-elev-3 scale(.99)`
- Selected (bulk): `border-primary ring-1 ring-primary shadow-elev-2`
- Overdue: left-accent danger, бейдж «просрочено»
- HOT: left-accent warning

**Kebab-меню:**
- `opacity-0 group-hover:opacity-100 transition-opacity` — без изменений
- Dropdown (`div.rounded-lg.shadow-elev-3`) — `shadow-lg` → `shadow-elev-3`
- Пункты меню hover: `hover:bg-gray-50 dark:hover:bg-white/5` (не `gray-700`)

**Открытый вопрос по данным:**
- `deal.owner_initials`, `deal.owner_name`, `deal.owner_color` — нужны в `BoardDealOut`. Сейчас этих полей нет (см. открытые вопросы).
- `deal.company_name` — нужен для отображения под названием сделки. Возможно есть через `deal.counterparty_name` или аналог.
- `stage.total_steps`, `stage.order` — для прогресс-бара. Возможно нужно добавить в `PipelineStage`.

## 4. Magic UI компоненты

На экране Канбан Magic UI компоненты **минимальны** — приоритет простоты и производительности.

| Компонент | Где | Ссылка |
|---|---|---|
| **Number Ticker** | Σ суммы в sticky-заголовке колонки | [magicui.design/docs/components/number-ticker](https://magicui.design/docs/components/number-ticker) |

> Все остальные эффекты — CSS: `.kcard` hover/drag через `@keyframes` / CSS transitions, left-accent через абсолютный `span`, placeholder через `border-dashed`.  
> **Не добавляем** motion layout-анимации на DnD — это совместимость с нативным HTML5 DnD не гарантирована.

## 5. Анимации / микровзаимодействия

| Эффект | Параметры | Fallback |
|---|---|---|
| Hover lift карточки | `translateY(-2px) shadow-elev-1→elev-2`, fast 120ms | `transition: none` |
| Drag rotate | `scale(.99) rotate(-.5deg) shadow-elev-3`, мгновенно при `dragstart` | нет rotate |
| Drag placeholder appear | fade-in `opacity 0→1 200ms` при `dragOver` | видим сразу |
| Empty column icon | статичная `bi-inbox` (место для Lordicon loop позже) | — |
| Progress bar width | нет анимации (статично) | — |
| Column Number Ticker | `800ms ease-out` при монтировании | число сразу |
| Sticky header reveal | нет специальной анимации — CSS `position: sticky` | — |

## 6. Tailwind-классы / токены

```
// Колонка wrapper (нейтральная)
w-[300px] flex flex-col rounded-2xl
bg-gray-50 dark:bg-white/[.03]
border border-gray-200 dark:border-white/10

// Колонка wrapper (HOT/warning)
bg-warning-50/50 dark:bg-warning-500/[.05]
border border-warning-500/30

// Колонка wrapper (success)
bg-success-50/40 dark:bg-success-500/[.04]
border border-success-500/25

// Колонка wrapper (lost/danger)
bg-danger-50/40 dark:bg-danger-500/[.04]
border border-danger-500/25

// Sticky header
sticky top-0 px-4 py-3 rounded-t-2xl
border-b border-gray-200 dark:border-white/10
bg-gray-50/90 dark:bg-white/[.03] backdrop-blur z-10

// Карточка (базовая)
relative group bg-white dark:bg-gray-800
border border-gray-200 dark:border-white/10
rounded-xl shadow-elev-1 p-3 pl-4 text-sm
select-none overflow-hidden

// Left accent
absolute left-0 top-0 bottom-0 w-1

// Drag placeholder
rounded-xl h-[92px]
border-2 border-dashed border-gray-300 dark:border-white/20
bg-gray-50/50 dark:bg-white/5
grid place-items-center text-xs text-gray-400

// Avatar инициалы
h-6 w-6 rounded-full text-white
grid place-items-center text-[10px] font-semibold

// Deadline chip (overdue)
text-[11px] inline-flex items-center gap-1
text-danger-600 font-semibold

// Deadline chip (warning ≤3дн)
text-warning-600 font-medium

// Progress bar track
h-1 rounded-full bg-gray-100 dark:bg-white/10

// «просрочено» badge
text-[10px] font-semibold
bg-danger-50 text-danger-700
dark:bg-danger-500/15 dark:text-danger-500
rounded px-1.5 py-0.5
```

## 7. Тексты (RU)

- Eyebrow PageHeader (Сделки): `Воронка продаж`
- Заголовок PageHeader: `Сделки`
- Подзаголовок (deals count): `· {N} в работе`
- Toggle Kanban: `Канбан`
- Toggle Список: `Список`
- Кнопка создать: `Сделка` (с иконкой `bi-plus-lg`)
- Кнопка добавить в колонку (tooltip): `Добавить сделку`
- Drag placeholder: `отпустите здесь`
- Empty column: `Нет сделок`
- Badge «просрочено»: `просрочено`
- Дедлайн чип: `{N} дней` / `{N} дн.` / `сегодня` / `завтра` / `{дата}`
- Пункт меню: `Открыть карточку` / `Перевести в этап` / `Отчёт по встрече` / `Отправить презентацию` / `Создать КП / договор` / `Вернуть в работу` / `Удалить` — **не трогаем**
- Sub-stage label: `{name} · {count}` — **не трогаем**

## 8. Accessibility

- Drag & drop: нативный HTML5 DnD — для keyboard users нет доступного аналога (ограничение текущей реализации, фиксируется в открытых вопросах)
- Карточки: `tabindex="0"` + `onKeyDown Enter → onOpen(deal)` (добавить)
- Kebab-кнопка: `aria-label="Действия со сделкой"` + `aria-expanded={menuOpen}`
- Dropdown: Radix `DropdownMenu` как будущий upgrade (сейчас самописный div — оставляем)
- Sticky-заголовок: не нужен `aria-label` (он видимый)
- Left-accent: `aria-hidden="true"` на декоративном `span`
- Аватар: `title={deal.owner_name}` (уже описан)
- Progress bar: `role="progressbar" aria-valuenow={...} aria-valuemax={100}`
- Контраст left-accent: success-500 / warning-500 / danger-500 / info-500 — все WCAG AA как декоративный элемент (не несёт текста)

## 9. Что НЕ трогаем

- Нативный HTML5 DnD: `draggable`, `onDragStart`, `onDragOver`, `onDrop`
- Все `on*` handlers: `onMove`, `onOpen`, `onDelete`, `onAddToStage`, `onMeetingReport`, `onSendPresentation`, `onGenerateDoc`, `onReturnToWork`
- `wonBySubstage` группировка sub-stages в won-колонке
- `colAmount` вычисление (useMemo)
- `StagePopover` — только добавить `shadow-elev-3` вместо `shadow-lg`
- `TASK_KIND_ICONS` маппинг
- `fmtAmount` / `fmtTaskDate` функции
- `allStages` / `topLevelColumns` / `subColsByParent` — useMemo в DealKanbanView

---

---

# Общие компоненты (для трёх экранов)

## Файловая структура новых компонентов

```
apps/web/src/components/
  Dashboard/
    KpiCard.tsx              ← новый (Magic Card + Number Ticker + Sparkline)
    AiTeaserWidget.tsx       ← новый (gradient + chat-trigger)
  MagicUI/                   ← новая папка для Magic UI обёрток
    BorderBeam.tsx           ← обёртка CSS @property или Magic UI import
    NumberTicker.tsx         ← обёртка motion
    BlurFade.tsx             ← обёртка motion
    MagicCard.tsx            ← обёртка CSS spotlight
    ShimmerButton.tsx        ← обёртка CSS shimmer
    DotPattern.tsx           ← обёртка Magic UI или SVG
    AnimatedList.tsx         ← обёртка motion stagger
```

Все `MagicUI/` компоненты — тонкие обёртки с `prefers-reduced-motion` fallback и строгим TypeScript.

## Глобальные CSS-дополнения (`globals.css`)

```css
/* Drift анимация для login blobs */
@keyframes drift {
  0%, 100% { transform: translate(0,0) scale(1) }
  33%       { transform: translate(40px,-30px) scale(1.08) }
  66%       { transform: translate(-30px,25px) scale(.95) }
}

/* Border Beam */
@property --beam-angle {
  syntax: '<angle>';
  inherits: false;
  initial-value: 0deg;
}

/* Kanban card drag */
.kcard { transition: transform .18s cubic-bezier(.2,.8,.2,1), box-shadow .18s; cursor: grab }
.kcard:hover { transform: translateY(-2px) }
.kcard:active { cursor: grabbing; transform: scale(.99) rotate(-.5deg); box-shadow: 0 16px 28px -8px rgba(16,24,40,.30) }

/* Shimmer button */
@keyframes shimmer { to { background-position: -220% 0 } }

/* prefers-reduced-motion: глобальный override */
@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after {
    animation-duration: 0.01ms !important;
    animation-iteration-count: 1 !important;
    transition-duration: 0.01ms !important;
  }
}
```

---

---

# Открытые вопросы

## Login
1. **«Забыли пароль?»** — функциональность (email-восстановление) не реализована. Кнопка сейчас `href="#"`. Нужна ли страница `/forgot-password` или это вне scope D2? → **Решение продукта**
2. **«Запомнить меня»** — влияет ли на длительность JWT cookie? Сейчас `access_token` без remember-me логики в backend. → **Требуется уточнение у backend-specialist**
3. **Статистика на левой панели** (`121+ контрагентов`, `128 подписок`, `14 этапов`) — хардкодить? Или подтягивать с API? → **Рекомендую хардкод** — это маркетинговые числа первого экрана, не live-данные. Если продукт хочет live — требуется публичный endpoint `/api/public/stats` без авторизации.

## Дашборд
4. **Sparkline данные для KPI-карточек** — сейчас `/analytics/contracts` возвращает плоские числа без исторических рядов. Для спарклайна нужен ряд `[N1, N2, ... N8]` за 8 недель/дней. → **Требуется правка backend**: добавить `sparkline` поле в `ContractsAnalytics` DTO.
5. **Тренд-чип KPI** (+18% / −3%) — откуда брать сравнение? → **Требуется правка backend**: поле `trend_pct: float` в KPI-объектах.
6. **AiTeaserWidget → `crm:open-ai` event** — нужно добавить в `(app)/layout.tsx` обработчик этого события, который открывает `AiAssistantButton`. Сейчас `AiAssistantButton` самостоятельно открывает панель по клику на FAB. → **Требуется небольшое изменение layout** (не backend).
7. **«Новая сделка» кнопка в PageHeader** — универсальна ли? На странице договоров она должна быть «Создать договор». Как передавать кастомный primary CTA? → Добавить `primaryAction?: ReactNode` prop в `PageHeader` (дополнительно к `actions`).

## Канбан
8. **`deal.owner_initials` / `deal.owner_name` / `deal.owner_color`** — этих полей нет в `BoardDealOut`. → **Требуется правка backend**: добавить в `/deals/board` ответ поля `owner_id`, `owner_name`, `owner_initials` (первые буквы имени+фамилии).
9. **`deal.company_name`** — для второй строчки карточки. → **Уточнить**: есть ли аналог в `BoardDealOut`? Возможно `deal.counterparty_name` или `deal.contact_name`. Если нет — **требуется правка backend**.
10. **`stage.total_steps` / `stage.order`** — для прогресс-бара в карточке. → **Уточнить**: есть ли эти поля в `PipelineStage`? Если нет — прогресс-бар реализовывать только в колонках Where it applies (won, например), не в карточке.
11. **HOT-определение** — сейчас нет формального признака «HOT stage» в `PipelineStage`. Left-accent warning-цвета определяем по: `stage.color` близкий к оранжевому ИЛИ название содержит «HOT» / «hot» / «горяч». → Рекомендую добавить `is_hot: bool` в `PipelineStage` на backend. Пока — эвристика по `stage.color`.
12. **Keyboard DnD** — нативный HTML5 DnD не поддерживает клавиатурный перенос. Это известное A11y-ограничение. Будущий эпик: заменить на `@dnd-kit` (который уже в package.json!) с keyboard-поддержкой. Пока — добавить `tabindex="0"` + Enter → `onOpen(deal)` как минимальный workaround.

---

---

# Чеклист приёмки для qa-tester

## Экран 1 — Login

### Функциональность (не трогали, проверить регрессию)
- [ ] Ввод email + пароль → успешный логин → редирект на `/contracts`
- [ ] Неверный пароль → inline-ошибка под формой (текст на RU)
- [ ] `?sso_error=domain_not_allowed` в URL → показывает ошибку SSO
- [ ] Кнопка «Войти через Google» → редирект на `/api/auth/sso/google/start`
- [ ] Кнопка «Войти через Yandex» → редирект на `/api/auth/sso/yandex/start`
- [ ] 2FA: при `requires_2fa` → редирект на `/auth/2fa`

### Визуал v2
- [ ] Desktop (≥1024px): split-screen — левая тёмная панель + правая с формой
- [ ] Mobile (<1024px): левая панель скрыта, логотип MACRO CRM над формой, форма по центру
- [ ] Левая панель: 3 анимированных блоба (drift), dot-pattern фон виден
- [ ] Карточка формы: Border Beam видна (светящийся контур)
- [ ] Поля Email и Password: leading-иконки `bi-envelope` / `bi-lock` видны слева
- [ ] Password: кнопка show/hide справа — работает, тип input меняется
- [ ] Focus на поле Email/Password: голубое кольцо `ring-4 ring-primary-light/15`
- [ ] Кнопка «Войти»: shimmer-эффект (бегущий блик) виден
- [ ] Кнопка «Войти» в loading: текст «Входим…», disabled
- [ ] Stagger-появление: поля появляются с лёгким fade+translateY сверху вниз
- [ ] Подпись «MACRO CRM» — НЕ «Генератор договоров»
- [ ] Футер «Защищено 2FA · MACRO Global Technologies»
- [ ] Dark mode: форма — `bg-gray-800/80 backdrop-blur`, поля `bg-gray-900/50`
- [ ] `prefers-reduced-motion`: нет анимаций, блобы статичны, форма видима сразу

### Accessibility
- [ ] Tab order: Email → Password → remember → forgot → Войти → Google → Yandex
- [ ] Error message: скринридер получает сообщение (`role="alert"` или `aria-live`)
- [ ] Кнопка show/hide: `aria-label` обновляется при смене состояния
- [ ] Консоль: 0 ошибок, 0 TypeScript-ошибок

---

## Экран 2 — Дашборд

### Функциональность (не трогали, проверить регрессию)
- [ ] DashboardCustomizer открывается, сохранение конфига работает
- [ ] PDF-экспорт запускается, файл скачивается
- [ ] Виджеты рендерятся согласно saved layout
- [ ] SWR данные: KPI числа загружаются из `/analytics/contracts`
- [ ] MyTasksWidget: задачи загружаются из `/activities?responsible_id=me...`
- [ ] HotDealsWidget: сделки загружаются из `/deals/hot?owner=me&limit=10`
- [ ] StatusGroupTiles: данные из `/contracts/status-groups`
- [ ] «Все виджеты скрыты» empty state работает, кнопка «Настроить» открывает customizer

### Визуал v2
- [ ] PageHeader: sticky при скролле, backdrop-blur виден, eyebrow «Обзор» над «Дашборд»
- [ ] PageHeader: поиск-триггер «Поиск… ⌘K» справа, клик открывает SearchModal
- [ ] KPI-карточки: `rounded-2xl shadow-elev-1`, hover-lift работает
- [ ] KPI числа: Number Ticker «накручивает» при загрузке данных
- [ ] KPI иконки-badges: видны в правом верхнем углу с семантическими цветами
- [ ] HotDealsWidget: Border Beam (оранжевый контур) виден
- [ ] MyTasksWidget: задачи появляются с stagger (fade+translateY) последовательно
- [ ] MyTasksWidget empty state: иконка `bi-check2-circle` + «Всё сделано» + «На сегодня задач нет»
- [ ] StatusGroupTiles: `in_review` — желтый фон `bg-warning-50`, `approved` — зелёный `bg-success-50`
- [ ] AiTeaserWidget: градиентный блок primary→blue виден, кнопка «Открыть ассистента» кликабельна
- [ ] Loading state: скелетоны-прямоугольники вместо текста «Загружаем…»
- [ ] Dark mode: карточки `bg-gray-800/60 backdrop-blur`
- [ ] `prefers-reduced-motion`: нет Number Ticker анимации, числа видны сразу; нет fade-up

### Accessibility
- [ ] Скелетоны: `aria-busy="true"` на контейнерах
- [ ] Ссылки в виджетах: «Открыть все сделки →» / «Открыть все задачи →» работают
- [ ] Консоль: 0 ошибок. `tsc --noEmit` = 0

---

## Экран 3 — Канбан

### Функциональность (не трогали, проверить регрессию)
- [ ] Drag & drop: перетащить карточку из одной колонки в другую → сделка меняет этап
- [ ] Клик по карточке → переход на `/deals/{id}`
- [ ] Kebab-меню: «Открыть карточку», «Перевести в этап» (StagePopover), «Удалить» работают
- [ ] Кнопка `bi-plus-lg` в заголовке колонки → `onAddToStage` срабатывает
- [ ] Won-колонка: substage группировка работает (sub-stage разделы видны)
- [ ] Bulk mode: чекбоксы в карточках работают

### Визуал v2
- [ ] Колонки: `rounded-2xl` (не отдельно header + body)
- [ ] HOT-колонка: желтоватый фон `bg-warning-50/50`, жёлтая рамка `border-warning-500/30`
- [ ] Success-колонка: зелёный фон `bg-success-50/40`, зелёная рамка
- [ ] Lost-колонка: красный фон `bg-danger-50/40`
- [ ] Sticky-заголовок: при скролле колонки заголовок остаётся видимым (`position: sticky`)
- [ ] HOT-заголовок: иконка `bi-fire text-warning-600`
- [ ] Σ суммы: Number Ticker при монтировании колонки
- [ ] Карточки: left-accent полоска видна (info/warning/danger/success по «температуре»)
- [ ] Hover карточки: `translateY(-2px) + shadow-elev-2` виден
- [ ] Drag карточки: rotate(-.5deg) + shadow-elev-3 виден при захвате
- [ ] Просроченная карточка: left-accent danger, badge «просрочено» виден
- [ ] Дедлайн-чип: `text-danger-600` если просрочено, `text-warning-600` если ≤3 дня
- [ ] Drag placeholder: пунктирная рамка появляется при наведении с карточкой
- [ ] Empty column: «Нет сделок» с иконкой (не пустая колонка без индикации)
- [ ] `prefers-reduced-motion`: нет hover-lift, нет rotate при drag
- [ ] Dark mode: карточки `bg-gray-800 border-white/10`

### Accessibility
- [ ] Карточки: `tabindex="0"`, Enter/Space → открывает карточку сделки
- [ ] Progress bar: `role="progressbar"` + `aria-valuenow` (если рендерится)
- [ ] Консоль: 0 ошибок. `tsc --noEmit` = 0

---

*ТЗ готово. Передавай `frontend-specialist`. Если есть правки — возвращай сюда.*
