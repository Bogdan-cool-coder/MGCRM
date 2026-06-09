# ТЗ: Эпик 17 — Onboarding Analytics + Team Progress

**Версия:** 1.0  
**Дата:** 2026-06-02  
**Автор:** designer  
**Исполнитель:** frontend-specialist  
**Зависимости:** Эпик 13 (Онбординг, в проде), Эпик 14 (Departments, мягкая зависимость)

---

## Cover

Аналитический дашборд поверх данных онбординга. Решает задачу: director/admin видит, кто из команды прошёл / застрял / бросил курс, какие вопросы самые сложные, где воронка отваливается. Без этого экрана нет обратной связи — курсы назначаются вслепую.

**Состоит из двух страниц:**

1. `/admin/onboarding/analytics` — отдельная страница дашборда
2. `/admin/onboarding/courses` (существующая) — расширить вкладку «Прогресс команды» новыми фильтрами + bulk-action

**Sidebar:** в группе «Онбординг» добавить пункт «Аналитика» (`bi-bar-chart`) со ссылкой `/admin/onboarding/analytics`.

---

## 1. Sidebar — добавить пункт «Аналитика»

### Что менять

Файл: `apps/web/src/components/Sidebar.tsx`

В массиве `ADMIN_ITEMS` рядом с существующим пунктом «Курсы и онбординг» добавить новый элемент:

```
{ href: "/admin/onboarding/analytics", icon: "bi-bar-chart", label: "Аналитика онбординга", roles: ["admin", "director"] }
```

Порядок в списке: сразу после `{ href: "/admin/onboarding/courses", ... }`.

### Результат в Sidebar

```
┌─────────────────────────────────────┐
│  [Admin секция] (expandable)        │
│  ...                                │
│  bi-collection-fill  Курсы и        │
│                      онбординг      │
│  bi-bar-chart        Аналитика      │  ← НОВЫЙ пункт
│                      онбординга     │
│  ...                                │
└─────────────────────────────────────┘
```

Поведение активного пункта наследуется из стандартного `NavItem`: `bg-primary text-white` когда `pathname.startsWith("/admin/onboarding/analytics")`.

---

## 2. Страница `/admin/onboarding/analytics`

### Файлы

- `apps/web/src/app/(app)/admin/onboarding/analytics/page.tsx` — серверный shell, `RoleGate` + рендер клиентского компонента
- `apps/web/src/components/Onboarding/Analytics/OverviewKpiRow.tsx`
- `apps/web/src/components/Onboarding/Analytics/CourseCompletionBars.tsx`
- `apps/web/src/components/Onboarding/Analytics/ActivitySparkline.tsx`
- `apps/web/src/components/Onboarding/Analytics/StatusDonut.tsx`
- `apps/web/src/components/Onboarding/Analytics/HardQuestionsList.tsx`
- `apps/web/src/components/Onboarding/Analytics/DropOffFunnel.tsx`

### Wireframe

```
┌────────────────────────────────────────────────────────────────────────────────┐
│ [Sidebar]  │ [PageHeader: «Аналитика онбординга»]              [Экспорт Excel] │
│            ├────────────────────────────────────────────────────────────────── │
│ - Дашборд  │                                                                   │
│ - ...      │  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌────────┐ │
│ - Курсы и  │  │ Всего    │ │Назначений│ │Прохождений│ │Ср. время │ │Актив-  │ │
│   онбординг│  │ курсов   │ │ всего    │ │  всего   │ │ прохожд. │ │ных 30д │ │
│ - Аналит.  │  │ [N]      │ │ [N]      │ │ [N] / N% │ │  Nч Nмин │ │  [N]   │ │
│   онбординг│  │ +sparkline│ │ +30д: +X │ │completion│ │          │ │        │ │
│            │  └──────────┘ └──────────┘ └──────────┘ └──────────┘ └────────┘ │
│            │  ┌──────────┐                                                     │
│            │  │Просроч.  │                                                     │
│            │  │mandatory │                                                     │
│            │  │  [N]     │                                                     │
│            │  └──────────┘                                                     │
│            │                                                                   │
│            │  ┌──────────────────────────┐  ┌──────────────────────────────┐  │
│            │  │ Прохождения по курсам    │  │ Распределение статусов       │  │
│            │  │ (топ-10, bar chart)      │  │ (donut / legend)             │  │
│            │  │                          │  │                              │  │
│            │  └──────────────────────────┘  └──────────────────────────────┘  │
│            │                                                                   │
│            │  ┌───────────────────────────────────────────────────────────┐   │
│            │  │ Активность учеников по дням (последние 90 дней)           │   │
│            │  │ [line sparkline / bar chart CSS]                          │   │
│            │  └───────────────────────────────────────────────────────────┘   │
│            │                                                                   │
│            │  ┌────────────────────────────────────────────────────────┐      │
│            │  │ Топ-5 самых сложных вопросов                           │      │
│            │  │ [список карточек]                                      │      │
│            │  └────────────────────────────────────────────────────────┘      │
│            │                                                                   │
│            │  ┌────────────────────────────────────────────────────────┐      │
│            │  │ Воронка отвала [dropdown: выбор курса]                 │      │
│            │  │ [горизонтальные бары со ступенчатым уменьшением]       │      │
│            │  └────────────────────────────────────────────────────────┘      │
└────────────────────────────────────────────────────────────────────────────────┘
```

### 2.1 KPI cards — `OverviewKpiRow`

**SWR:** `GET /api/admin/onboarding/analytics/overview`

**Response shape (ожидаемый DTO):**

```ts
interface OverviewDTO {
  total_courses: number;
  total_assignments: number;
  new_assignments_30d: number;
  total_completed: number;
  completion_rate_pct: number;
  avg_time_to_complete_hours: number | null;
  active_learners_30d: number;
  overdue_mandatory: number;
  courses_sparkline_30d: number[];        // 30 значений по дням (создание курсов)
  activity_by_day_90d: { date: string; count: number }[];  // 90 дней активности
  completions_by_course: { course_id: number; title: string; completed: number }[];  // топ-10
  status_distribution: {
    assigned: number;
    in_progress: number;
    completed: number;
    overdue: number;
  };
}
```

**Макет карточек (6 штук в строку):**

```
┌────────────────┐ ┌────────────────┐ ┌────────────────┐
│ Всего курсов   │ │ Назначений     │ │ Прохождений    │
│                │ │                │ │                │
│  [24]          │ │  [142]         │ │  [87]  61.3%   │
│  [sparkline]   │ │  +16 за 30д    │ │ completion     │
└────────────────┘ └────────────────┘ └────────────────┘

┌────────────────┐ ┌────────────────┐ ┌────────────────┐
│ Среднее время  │ │ Активных 30д   │ │ Просроч.       │
│ прохождения    │ │                │ │ mandatory      │
│  2ч 40мин      │ │  [34]          │ │  [6]           │
│                │ │  уникальных    │ │  text-danger   │
└────────────────┘ └────────────────┘ └────────────────┘
```

**UI компоненты `OverviewKpiRow`:**

- Контейнер: `div` с классами `grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-6`
- Каждая карточка: `card p-4` + `flex flex-col gap-1`
- Лейбл карточки: `text-xs text-gray-500 font-medium uppercase tracking-wide`
- Значение KPI: `text-2xl font-bold text-gray-900 tabular-nums`
- Подпись под значением: `text-xs text-gray-500`
- Карточка «Просроченные mandatory»: значение в `text-danger`, если > 0
- Карточка «Completion rate»: после числа `text-xs font-semibold text-success` / `text-warning` (< 50% = warning, >= 50% = success)
- Sparkline в карточке «Всего курсов»: реюзать существующий `Sparkline` из `@/components/Sparkline` (он уже есть, `apps/web/src/components/Sparkline.tsx`)
- Loading: 6 карточек `card p-4 animate-pulse h-24 bg-gray-100`

### 2.2 Графики

#### Bar chart «Прохождения по курсам» — `CourseCompletionBars`

Нет Recharts в стеке. Рендерим CSS-бары как в `FunnelConversionWidget`. Данные из `completions_by_course` (топ-10 из overview).

```
Прохождения по курсам                     ← заголовок text-h5 mb-3
─────────────────────────────────────────
MacroSales Intro      ████████████  42
Юридическая база      ██████        18
Product Knowledge     ████          12
...
```

**UI:**

- Контейнер: `card p-5`
- Заголовок: `text-h5 mb-3`
- Каждая строка: `flex items-center gap-3 mb-2`
  - Название курса: `text-sm text-gray-700 w-48 truncate`
  - Бар-контейнер: `flex-1 h-2 bg-gray-100 rounded-full overflow-hidden`
  - Бар-заполнение: `h-full bg-primary-light rounded-full` с inline `style={{ width: pct + "%" }}`
  - Число: `text-sm tabular-nums text-gray-600 w-8 text-right`
- Loading: 10 строк `animate-pulse h-4 bg-gray-100 rounded mb-2`
- Empty: `text-sm text-gray-400 py-4 text-center` — «Нет данных о прохождениях»

#### Line chart «Активность учеников по дням» — `ActivitySparkline`

90 дней, данные из `activity_by_day_90d`. Рендерим через inline SVG (без внешних библиотек).

```
Активность учеников (последние 90 дней)
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
[SVG polyline, viewBox="0 0 900 60"]
[подпись: минимальная дата — сегодня]
```

**UI:**

- Контейнер: `card p-5`
- Заголовок: `text-h5 mb-3`
- SVG: `w-full h-16 overflow-visible`
  - Полилайн цвет: `stroke-primary-light`, `fill: none`, `strokeWidth: 2`
  - Точки (circle): показывать только при hover (через CSS `opacity-0 hover:opacity-100` на group)
  - Подложка: тонкая горизонтальная линия `stroke-gray-200` на нуле
- Подписи дат по оси X: только первая и последняя (`text-xs text-gray-400`)
- Loading: `card p-5 animate-pulse h-24 bg-gray-100`
- Empty (все нули): «Нет активности за последние 90 дней» `text-sm text-gray-400 text-center py-4`

**Примечание по SVG:** нормализуй Y-ось (max value → полная высота). Формула: `y = height - (value / maxValue) * height`. X = `(index / (points.length - 1)) * width`.

#### Donut chart «Распределение статусов» — `StatusDonut`

Простой SVG donut (circle + stroke-dasharray). 4 статуса. Данные из `status_distribution`.

```
┌────────────────────────────────────────┐
│  [Donut SVG 120×120]  Назначено   [N]  │
│                       В процессе  [N]  │
│                       Завершено   [N]  │
│                       Просрочено  [N]  │
└────────────────────────────────────────┘
```

**UI:**

- Контейнер: `card p-5`
- Заголовок: `text-h5 mb-3`
- Внутренний layout: `flex items-center gap-6`
- SVG donut: `w-28 h-28 flex-shrink-0`
  - SVG viewBox="0 0 36 36", radius=15.915 (чтобы circumference = 100)
  - Один `circle` на статус через `stroke-dasharray` и `stroke-dashoffset`
  - Цвета сегментов: `assigned` → `#2B4987` (primary-light), `in_progress` → `#F59E0B` (warning), `completed` → `#1F9D55` (success), `overdue` → `#C0392B` (danger)
- Легенда (справа): `flex flex-col gap-1.5`
  - Каждая строка: `flex items-center gap-2 text-sm`
  - Цветная точка: `w-3 h-3 rounded-full flex-shrink-0` с `backgroundColor`
  - Лейбл: `text-gray-600`, число: `ml-auto font-semibold tabular-nums text-gray-900`
- Loading: `animate-pulse rounded-full w-28 h-28 bg-gray-100`
- Empty (все нули): «Нет назначений» `text-sm text-gray-400 py-4 text-center`

**Порядок статусов в легенде:** Назначено → В процессе → Завершено → Просрочено

### 2.3 Топ-5 сложных вопросов — `HardQuestionsList`

**SWR:** `GET /api/admin/onboarding/analytics/hard-questions?limit=5`

**Response shape:**

```ts
interface HardQuestion {
  question_id: number;
  question_text: string;
  course_id: number;
  course_title: string;
  lesson_id: number;
  lesson_title: string;
  total_attempts: number;
  success_rate_pct: number;  // % правильных (100 - error_rate)
}
type HardQuestionsResponse = HardQuestion[];
```

**Wireframe:**

```
┌──────────────────────────────────────────────────────────────────────────────┐
│ Топ-5 самых сложных вопросов                                                 │
├──────────────────────────────────────────────────────────────────────────────┤
│ ┌──────────────────────────────────────────────────────────────────────────┐ │
│ │ #1  «Какой тип лицензии использует MACRO при работе с субдилерами?»     │ │
│ │     MacroSales Intro / Урок 3: Типы лицензий  [Открыть урок →]          │ │
│ │     [══════════░░░░░░░░░░░░] 28% успешных  ·  142 попытки               │ │
│ └──────────────────────────────────────────────────────────────────────────┘ │
│ ... (ещё 4 карточки)                                                         │
└──────────────────────────────────────────────────────────────────────────────┘
```

**UI компоненты:**

- Внешний контейнер: `card p-5 mb-6`
- Заголовок: `text-h5 mb-4`
- Список: `flex flex-col gap-3`
- Каждая карточка вопроса: `rounded-lg border border-gray-200 p-4`
  - Шапка карточки: `flex items-start justify-between gap-3`
    - Слева: `flex items-start gap-2`
      - Номер: `text-xs font-bold text-gray-400 mt-0.5 w-5 flex-shrink-0` — «#1», «#2», ...
      - Текст вопроса: `text-sm font-medium text-gray-800 leading-snug`
    - Справа: кнопка «Открыть урок →» — `btn-ghost text-xs flex items-center gap-1 flex-shrink-0`; ссылка `href="/admin/onboarding/courses/[course_id]/edit"` (переход в редактор)
  - Подпись (курс / урок): `text-xs text-gray-500 mt-1 ml-7`
    - Шаблон: `{course_title} / {lesson_title}`
  - Прогресс-строка: `mt-2 ml-7 flex items-center gap-3`
    - Бар-контейнер: `flex-1 h-1.5 bg-gray-100 rounded-full overflow-hidden`
    - Бар-заполнение: `h-full rounded-full` + цвет динамически: success_rate < 40 → `bg-danger`, 40-60 → `bg-warning`, > 60 → `bg-success`; width = `success_rate_pct + "%"`
    - Текст: `text-xs tabular-nums font-medium` + тот же цвет-класс → «28% успешных»
    - Разделитель: `text-gray-300 text-xs mx-1` → «·»
    - Попытки: `text-xs text-gray-500` → «142 попытки»
- Loading: 5 `animate-pulse h-20 bg-gray-100 rounded-lg mb-3`
- Empty: `text-sm text-gray-400 py-4 text-center` — «Данных о сложных вопросах пока нет»
- Error: `text-danger text-sm py-2` — «Не удалось загрузить вопросы»

### 2.4 Воронка отвала — `DropOffFunnel`

**SWR:** `GET /api/admin/onboarding/analytics/funnel/{course_id}`

**Response shape:**

```ts
interface FunnelStep {
  step_key: "assigned" | "started" | "halfway" | "almost_done" | "completed";
  step_label: string;   // «Назначено», «Начато», «Прошёл 50%», «Прошёл 90%», «Завершил»
  count: number;
  pct_of_total: number;  // % от первого шага (assigned)
  users?: { user_id: number; user_name: string }[];  // только при drill-down запросе
}
interface FunnelResponse {
  course_id: number;
  course_title: string;
  steps: FunnelStep[];
}
```

**Wireframe:**

```
┌─────────────────────────────────────────────────────────────────────────────┐
│ Воронка отвала            [select: выбери курс ▾]                           │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  Назначено         ████████████████████████████████████  142  100%          │
│  Начато            ████████████████████████████  112  78.9%   (-20.1%)      │
│  Прошёл 50%        ████████████████████  94   66.2%   (-12.7%)             │
│  Прошёл 90%        █████████████  62   43.7%   (-22.5%)                    │
│  Завершил          ████████  38   26.8%   (-16.9%)                         │
│                                                                             │
│  [Клик на строку «Прошёл 90%»]                                              │
│  ┌──────────────────────────────────────────────────────────────────────┐  │
│  │ Список пользователей, застрявших на «Прошёл 90%» (24 чел.)          │  │
│  │ Иван Петров · Начат 12.05 · 91% · [Написать]                        │  │
│  │ ...                                                                  │  │
│  └──────────────────────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────────────────────┘
```

**Dropdown курса:**

- `select.input w-auto min-w-[220px] text-sm`
- SWR для списка курсов: `GET /api/admin/onboarding/courses?is_published=true` (уже существует)
- Плейсхолдер: `<option value="">Выбери курс</option>`
- При выборе → новый SWR-ключ

**Строки воронки:**

- Контейнер строк: `flex flex-col gap-2 mt-4`
- Каждая строка: `flex items-center gap-3 cursor-pointer hover:bg-gray-50 rounded-md px-2 py-1.5 transition-colors group`
  - Лейбл: `w-28 text-sm text-gray-700 flex-shrink-0`
  - Бар-контейнер: `flex-1 h-4 bg-gray-100 rounded`
  - Бар-заполнение: `h-full bg-primary-light rounded transition-all duration-300`; width = `pct_of_total + "%"`
  - Число: `text-sm tabular-nums font-medium text-gray-800 w-10 text-right flex-shrink-0`
  - Процент: `text-sm tabular-nums text-gray-500 w-16 text-right flex-shrink-0`
  - Delta (падение vs предыдущий шаг): `text-xs tabular-nums text-danger flex-shrink-0 w-16 text-right` — не показывать для первого шага

**Drill-down (список застрявших):**

- Клик на строку → раскрывается `expandedStep` state
- Inline-раскрытие под строкой: `rounded-lg border border-gray-100 bg-gray-50 p-3 mt-1 mb-2`
- При раскрытии — новый SWR-ключ: `GET /api/admin/onboarding/analytics/funnel/{course_id}?step={step_key}&include_users=true`
- Loading внутри: `text-xs text-gray-400` — «Загружаем…»
- Список пользователей: `flex flex-col gap-1.5`
  - Строка: `flex items-center gap-2 text-xs`
    - Имя: `text-gray-800 font-medium`
    - Дата начала: `text-gray-400` — «начат 12.05»
    - Прогресс: `badge bg-info/10 text-info` — «91%»
- Empty в drill-down: «Все пользователи прошли дальше» `text-xs text-gray-400`

**States:**

- Loading (нет курса выбран): `text-sm text-gray-400 py-8 text-center bi-funnel mr-2` — «Выбери курс, чтобы увидеть воронку»
- Loading (данные грузятся): 5 строк `animate-pulse h-6 bg-gray-100 rounded mb-2`
- Empty (курс выбран, нет назначений): `bi-funnel text-4xl text-gray-300` + «У этого курса пока нет назначений»
- Error: `text-danger text-sm py-2` — «Не удалось загрузить воронку»

---

## 3. Расширение вкладки «Прогресс команды»

### Файлы (изменения существующих)

- `apps/web/src/app/(app)/admin/onboarding/courses/page.tsx` — добавить вкладку «Аналитика», расширить вкладку «Прогресс команды»
- `apps/web/src/components/Onboarding/Admin/ProgressMatrix.tsx` — расширить фильтрами + bulk select
- `apps/web/src/components/Onboarding/Admin/TeamProgressTable.tsx` — **новый компонент** (плоская таблица вместо матрицы; матрица остаётся, но для вкладки «Прогресс» нужна другая визуализация — строки = люди, колонки = метрики)

**Примечание:** текущий `ProgressMatrix` — матрица «человек × курс» с цветными ячейками. Для эпика 17 нужна другая таблица: одна строка = один человек, с агрегированными метриками по всем назначенным курсам. Создаём `TeamProgressTable` — отдельный компонент, не заменяем матрицу.

### Структура вкладок (после изменений)

```
[Курсы] [Прогресс команды] [Матрица прогресса]   ← 3 вкладки
```

Было 2 вкладки: «Курсы» и «Прогресс команды» (матрица). Станет 3: разделить «Прогресс» на «Прогресс команды» (плоская таблица с фильтрами) + «Матрица прогресса» (существующая `ProgressMatrix`).

### Wireframe вкладки «Прогресс команды»

```
┌─────────────────────────────────────────────────────────────────────────────┐
│ [Курсы] [Прогресс команды] [Матрица прогресса]                              │
├─────────────────────────────────────────────────────────────────────────────┤
│ Фильтры:                                                                    │
│ [Отдел ▾ disabled*] [Менеджер ▾] [Курс ▾] [Период ▾] [Сбросить] [Экспорт]│
│                                                          bulk-кнопка →      │
│ ┌─────────────────────────────────────────────────────────────────────────┐ │
│ │ ☐ │ ФИО          │ Отдел   │ Назначено │ Завершено │ В пр. │ Просроч. │%│ │
│ ├───┼──────────────┼─────────┼───────────┼───────────┼───────┼──────────┼─┤ │
│ │ ☐ │ Иван Петров  │Продажи  │     3     │     1     │   1   │    1    │50│ │
│ │ ☐ │ Анна Сидорова│Юр.отдел │     2     │     2     │   0   │    0    │100│ │
│ │ ☐ │ ...          │         │           │           │       │          │ │ │
│ └─────────────────────────────────────────────────────────────────────────┘ │
│ Выбрано: 2  [Назначить курс] [Снять выбор]                                  │
└─────────────────────────────────────────────────────────────────────────────┘
```

*Фильтр «Отдел» — disabled с `title="Доступно после настройки отделов (Эпик 14)"`, если backend не возвращает список отделов.

### 3.1 Компонент `TeamProgressTable`

**SWR:** `GET /api/admin/onboarding/analytics/team-progress?department_id=&manager_id=&course_id=&period=`

**Response shape:**

```ts
interface TeamProgressRow {
  user_id: number;
  user_name: string;
  user_role: string;
  department_id: number | null;
  department_name: string | null;
  assigned_count: number;
  completed_count: number;
  in_progress_count: number;
  overdue_count: number;
  progress_pct: number;  // completed / assigned * 100, округлено
}
type TeamProgressResponse = TeamProgressRow[];
```

**Фильтры:**

- `department_id` — `DepartmentSelect` из Эпика 14. До готовности Эпика 14 — `select.input` disabled с `title="Доступно после настройки отделов"`. Если endpoint `/api/admin/departments` возвращает 404 → disabled.
- `manager_id` — `UserSelect` (реюзать существующий, если есть, иначе `select.input` с `GET /api/users`)
- `course_id` — `select.input` с курсами из `GET /api/admin/onboarding/courses`
- `period` — `select.input` с опциями: «За всё время», «30 дней», «90 дней», «Этот год» → передаётся как query-param `period=30d/90d/1y` или пусто

**Фильтр-строка UI:**

- Контейнер: `flex items-center gap-3 mb-4 flex-wrap`
- Каждый select: `input text-sm py-1.5 w-auto`
- Кнопка «Сбросить»: `btn-ghost text-sm flex items-center gap-1` — показывать только если хотя бы один фильтр активен
- Кнопка «Экспорт Excel»: `btn-secondary text-sm flex items-center gap-1 ml-auto` + иконка `bi-file-earmark-excel`

**Таблица:**

- Контейнер: `card p-0 overflow-hidden`
- Таблица: `w-full text-sm`
- Шапка: `border-b border-gray-200 bg-gray-50`
  - Ячейки шапки: `px-4 py-2.5 text-gray-600 font-semibold text-left`
  - Сортировка: иконка `bi-chevron-expand` рядом с заголовком, при клике — sort state (`asc`/`desc`/`none`)
- Строки: `border-b border-gray-100 last:border-0 hover:bg-gray-50 transition-colors`

**Колонки (в таблице слева направо):**

| Колонка | Ширина | Примечание |
|---|---|---|
| Checkbox | `w-10` | bulk-select |
| ФИО | `min-w-[160px]` | `font-medium text-gray-900` + роль ниже `text-xs text-gray-400` |
| Отдел | `w-32` | `text-gray-600` или «—» если нет |
| Назначено | `w-24 text-right tabular-nums` | sortable |
| Завершено | `w-24 text-right tabular-nums text-success` | sortable |
| В процессе | `w-24 text-right tabular-nums text-info` | sortable |
| Просрочено | `w-24 text-right tabular-nums text-danger` | sortable, только если > 0 — в `text-danger`, иначе `text-gray-400` |
| Прогресс % | `w-28` | progress bar + число; бар: `h-1.5 bg-gray-100 rounded-full` + fill по цвету (< 40% danger, 40-70% warning, > 70% success) |

**Bulk select:**

- Чекбокс в шапке таблицы: `select all / deselect all`
- При выборе 1+ строк — появляется нижняя строка (`sticky bottom-0 bg-white border-t border-gray-200 p-3 flex items-center gap-3`):
  - «Выбрано: N»
  - Кнопка «Назначить курс» — `btn-primary text-sm`
  - Кнопка «Снять выбор» — `btn-ghost text-sm`

**Кнопка «Назначить курс» (bulk):**

- Открывает `Modal` (существующий компонент)
- Внутри модала: `select.input` для выбора курса из `GET /api/admin/onboarding/courses?is_published=true`
- Кнопки модала: `[btn-ghost: Отмена]` ... `[btn-primary: Назначить]`
- `POST /api/admin/onboarding/assignments/bulk` с body `{ user_ids: number[], course_id: number }`
- После успеха: закрыть модал + `mutate()` SWR

**Export:**

- Кнопка «Экспорт Excel» → `POST /api/admin/onboarding/analytics/export` с текущими фильтрами в body
- Response: `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet` — инициируем download через `URL.createObjectURL`
- Loading state кнопки: `disabled` + «Готовим…»

**States:**

- Loading: 5 skeleton-строк `animate-pulse h-12 bg-gray-100 rounded mb-1`
- Empty (нет данных по фильтрам): `EmptyState icon="bi-people" title="Нет сотрудников по выбранным фильтрам" description="Попробуй изменить фильтры или назначить курсы команде"`
- Empty (вообще нет назначений): `EmptyState icon="bi-mortarboard" title="Курсы ещё не назначены" description="Перейди во вкладку «Курсы» и назначь команде обучение" cta=<Link btn-primary>`
- Error: `text-danger text-sm py-4 text-center` — «Не удалось загрузить прогресс команды»

---

## 4. Взаимодействие фильтров

**Фильтры на странице `/admin/onboarding/analytics` не глобальные** — каждый виджет независим и имеет свои параметры. Это сознательное решение: KPI overview — всегда по всем данным (никаких глобальных фильтров), воронка — фильтруется по выбору курса, hard-questions — глобально по всем курсам.

Исключение: страница «Прогресс команды» (`TeamProgressTable`) — там фильтры `department_id`, `manager_id`, `course_id`, `period` применяются к одному эндпоинту и синхронизированы через общий state компонента.

**Схема применения фильтров в `TeamProgressTable`:**

```
[department_id filter] ──┐
[manager_id filter]    ──┤──→ SWR key: /analytics/team-progress?...params
[course_id filter]     ──┤         ↕ mutate on filter change
[period filter]        ──┘
                           ↓
                    [TeamProgressTable рендер]
                           ↓
          [Export button] → POST same params as body
```

Фильтры меняют SWR-ключ через `useMemo` — не через `useEffect`. При изменении любого фильтра → новый ключ → SWR re-fetch. Сброс всех фильтров → базовый ключ без params.

---

## 5. Список всех компонентов

### Новые компоненты

| Файл | Тип | SWR endpoint | Назначение |
|---|---|---|---|
| `components/Onboarding/Analytics/OverviewKpiRow.tsx` | client | `/admin/onboarding/analytics/overview` | KPI cards + sparkline |
| `components/Onboarding/Analytics/CourseCompletionBars.tsx` | client | из overview | Топ-10 bar chart |
| `components/Onboarding/Analytics/ActivitySparkline.tsx` | client | из overview | Line chart 90 дней |
| `components/Onboarding/Analytics/StatusDonut.tsx` | client | из overview | Donut статусов |
| `components/Onboarding/Analytics/HardQuestionsList.tsx` | client | `/admin/onboarding/analytics/hard-questions` | Топ-5 сложных вопросов |
| `components/Onboarding/Analytics/DropOffFunnel.tsx` | client | `/admin/onboarding/analytics/funnel/{id}` | Воронка отвала + drill-down |
| `components/Onboarding/Admin/TeamProgressTable.tsx` | client | `/admin/onboarding/analytics/team-progress` | Плоская таблица прогресса |
| `app/(app)/admin/onboarding/analytics/page.tsx` | server shell | — | Страница аналитики |

### Изменяемые файлы

| Файл | Что меняется |
|---|---|
| `components/Sidebar.tsx` | Добавить пункт «Аналитика онбординга» в `ADMIN_ITEMS` |
| `app/(app)/admin/onboarding/courses/page.tsx` | Добавить 3-ю вкладку «Матрица прогресса», переименовать вкладку «Прогресс команды» → рендерит `TeamProgressTable` |

### Реюзаемые компоненты (без изменений)

| Компонент | Где реюзается |
|---|---|
| `components/Sparkline` | В `OverviewKpiRow` для мини-спарклайна курсов |
| `components/PageHeader` | Шапка страницы analytics |
| `components/EmptyState` | Empty states во всех компонентах |
| `components/Modal` | Bulk-назначение курса |
| `Dashboard/FunnelConversionWidget` | Паттерн CSS-баров (переиспользовать код, не сам компонент) |
| `Onboarding/Admin/ProgressMatrix` | Выносится в отдельную вкладку «Матрица прогресса» |

---

## 6. States (сводная таблица)

| Компонент | Loading | Empty | Error |
|---|---|---|---|
| `OverviewKpiRow` | 6 skeleton `card animate-pulse h-24` | — (всегда есть хотя бы нули) | inline `text-danger` над строкой KPI |
| `CourseCompletionBars` | 10 строк `h-4 animate-pulse` | «Нет данных о прохождениях» | inline `text-danger` |
| `ActivitySparkline` | `card animate-pulse h-24` | «Нет активности за последние 90 дней» | inline `text-danger` |
| `StatusDonut` | skeleton круга `animate-pulse rounded-full w-28 h-28` | «Нет назначений» | inline `text-danger` |
| `HardQuestionsList` | 5 карточек `animate-pulse h-20` | «Данных о сложных вопросах пока нет» | inline `text-danger` |
| `DropOffFunnel` | 5 строк `animate-pulse h-6` | «Выбери курс» / «Нет назначений» | inline `text-danger` |
| `TeamProgressTable` | 5 skeleton строк таблицы | `EmptyState` (два варианта: нет данных / нет назначений) | `text-danger py-4 text-center` |

---

## 7. Interactions

| Элемент | Действие | Результат |
|---|---|---|
| Пункт «Аналитика онбординга» в Sidebar | click | Переход `/admin/onboarding/analytics` |
| `OverviewKpiRow` | mount | SWR fetch `/api/admin/onboarding/analytics/overview` |
| Dropdown курса в `DropOffFunnel` | change | SWR re-fetch `/api/admin/onboarding/analytics/funnel/{id}` |
| Строка воронки | click | Раскрыть drill-down с пользователями (expand state toggle) |
| Строка воронки (повторный клик) | click | Свернуть drill-down |
| Кнопка «Открыть урок →» в `HardQuestionsList` | click | `href="/admin/onboarding/courses/{course_id}/edit"` |
| Вкладки «Курсы» / «Прогресс команды» / «Матрица прогресса» | click | Переключить `activeTab` state |
| Фильтр в `TeamProgressTable` | change | Обновить SWR-ключ → refetch |
| Кнопка «Сбросить» фильтров | click | Сбросить все filters → базовый SWR-ключ |
| Checkbox строки таблицы | check | Добавить `user_id` в `selectedIds` set |
| Checkbox шапки | check | Выбрать все / снять выделение |
| Кнопка «Назначить курс» (bulk) | click | Открыть `Modal` с select курса |
| Modal «Назначить» → «Назначить» | click | `POST /api/admin/onboarding/assignments/bulk` → `mutate()` → закрыть |
| Modal «Отмена» | click | Закрыть без запроса |
| Кнопка «Экспорт Excel» | click | `POST /api/admin/onboarding/analytics/export` → download `.xlsx` |
| Заголовок колонки таблицы (sortable) | click | Цикл: none → asc → desc → none; сортировка client-side |
| Заголовок `TeamProgressTable` | mount | SWR fetch с текущими params |

---

## 8. Тексты (RU, без i18n)

### Страница `/admin/onboarding/analytics`

- Заголовок страницы: `Аналитика онбординга`
- Описание PageHeader: `Прогресс команды, сложные вопросы и воронка отвала`
- Кнопка экспорта (PageHeader actions): `Экспорт Excel`

### KPI cards

- «Всего курсов» | подпись: `активных`
- «Назначений всего» | подпись: `+{N} за 30 дней`
- «Прохождений» | подпись: `{N}% completion`
- «Среднее время» | подпись: `на прохождение`
- «Активных учеников» | подпись: `за последние 30 дней`
- «Просрочено» | подпись: `обязательных курсов`

### Раздел «Прохождения по курсам»

- Заголовок: `Прохождения по курсам`
- Подпись: `Топ-10 курсов по количеству завершений`
- Empty: `Нет данных о прохождениях`

### Раздел «Активность учеников»

- Заголовок: `Активность учеников`
- Подпись: `Уникальные попытки за последние 90 дней`
- Empty: `Нет активности за последние 90 дней`

### Donut «Распределение статусов»

- Заголовок: `Распределение статусов`
- Легенда: `Назначено`, `В процессе`, `Завершено`, `Просрочено`
- Empty: `Нет назначений`

### Hard questions

- Заголовок блока: `Топ-5 самых сложных вопросов`
- Подпись блока: `Вопросы с наименьшим процентом правильных ответов`
- Кнопка-ссылка на урок: `Открыть урок`
- Формат метрик: `{N}% успешных · {N} попыток`
- Empty: `Данных о сложных вопросах пока нет — quiz ещё не проходили`
- Error: `Не удалось загрузить вопросы`

### Drop-off funnel

- Заголовок блока: `Воронка отвала`
- Подпись блока: `Сколько учеников проходит каждый этап курса`
- Placeholder dropdown: `Выбери курс`
- Состояние «нет выбора»: `Выбери курс, чтобы увидеть воронку отвала`
- Empty (курс без назначений): `У этого курса пока нет назначений`
- Loading drill-down: `Загружаем список…`
- Drill-down заголовок: `Застряли на этом этапе ({N} чел.)`
- Drill-down empty: `Все ученики прошли дальше`
- Error: `Не удалось загрузить воронку`
- Лейблы шагов воронки: `Назначено`, `Начато`, `Прошёл 50%`, `Прошёл 90%`, `Завершил`

### Team progress table (вкладка)

- Заголовок вкладки: `Прогресс команды`
- Заголовок вкладки матрицы: `Матрица прогресса`
- Фильтр отдел (disabled): `Отдел` + tooltip `Доступно после настройки отделов (Эпик 14)`
- Фильтр менеджер: `Менеджер`
- Фильтр курс: `Все курсы`
- Фильтр период — опции: `За всё время`, `30 дней`, `90 дней`, `Этот год`
- Кнопка сброса: `Сбросить`
- Кнопка экспорта: `Экспорт Excel`
- Заголовки колонок: `ФИО`, `Отдел`, `Назначено`, `Завершено`, `В процессе`, `Просрочено`, `Прогресс`
- Bulk-строка: `Выбрано: {N}`
- Кнопка bulk: `Назначить курс`
- Кнопка снять выбор: `Снять выбор`
- Modal заголовок: `Назначить курс`
- Modal подпись: `Выбранные сотрудники получат доступ к курсу`
- Modal select placeholder: `Выбери курс`
- Modal кнопки: `Отмена` / `Назначить`
- Кнопка Export loading: `Готовим…`
- Empty (нет данных по фильтрам): `Нет сотрудников по выбранным фильтрам`
- Empty description: `Попробуй изменить фильтры или назначить курсы команде`
- Empty (нет назначений вообще): `Курсы ещё не назначены`
- Empty description: `Перейди во вкладку «Курсы» и назначь команде обучение`
- Error: `Не удалось загрузить прогресс команды`

---

## 9. Связь с backend

| Endpoint | Метод | Кто вызывает | Статус |
|---|---|---|---|
| `/api/admin/onboarding/analytics/overview` | GET | `OverviewKpiRow` | требуется от backend |
| `/api/admin/onboarding/analytics/hard-questions?limit=5` | GET | `HardQuestionsList` | требуется от backend |
| `/api/admin/onboarding/analytics/funnel/{course_id}` | GET | `DropOffFunnel` | требуется от backend |
| `/api/admin/onboarding/analytics/funnel/{course_id}?step={key}&include_users=true` | GET | drill-down | требуется от backend |
| `/api/admin/onboarding/analytics/team-progress` | GET | `TeamProgressTable` | требуется от backend |
| `/api/admin/onboarding/analytics/export` | POST | Export button | требуется от backend |
| `/api/admin/onboarding/courses` | GET | dropdown курсов | уже есть |
| `/api/admin/onboarding/assignments/bulk` | POST | bulk-назначение | требуется проверить |

**Все запросы через `api`/`fetcher` из `@/lib/api` с `credentials: "same-origin"`.**

**Замечания к backend:**

- В Obsidian-плане API-префикс `/api/admin/...` — уточни у backend-specialist, используется ли `/api/admin/onboarding/analytics/...` или `/api/onboarding/analytics/...` (фронтенд вызывает без `/api` через fetcher, proxy через Next.js). В ТЗ используется `/admin/onboarding/analytics/...` как SWR-ключ (prefix `/api` добавляет fetcher).
- Endpoint `/api/admin/onboarding/analytics/funnel/{course_id}` — **отдельный** от существующего `FunnelConversionWidget` (`/api/analytics/funnel/{pipeline_id}`). Разные доменные области.
- `include_users=true` param для drill-down — потенциально тяжёлый запрос. Backend должен ограничить выборку (например, 50 пользователей).
- Export endpoint возвращает бинарный файл — frontend инициирует скачивание через `Blob + URL.createObjectURL`, не redirect.

---

## 10. Адаптивность

Desktop-first. Mobile — TBD (эпик 10 / будущий эпик). Сейчас:

- KPI cards: `grid-cols-2 md:grid-cols-3 lg:grid-cols-6` — на мобиле 2 в ряд (терпимо)
- Таблица `TeamProgressTable`: горизонтальный скролл `overflow-x-auto`
- Графики (bars, SVG): `w-full` — тянутся на всю ширину контейнера
- Donut: фиксированный размер `w-28 h-28`, легенда рядом — на мобиле возможен перенос (не критично сейчас)

---

## 11. Открытые вопросы

1. **API-prefix:** Уточнить у backend-specialist — SWR-ключи должны быть `/admin/onboarding/analytics/...` или `/onboarding/analytics/...`? Без `/api/` prefix (fetcher добавляет). Критично для соответствия маршрутам FastAPI.

2. **`bulk assignments` endpoint:** Существует ли `POST /api/admin/onboarding/assignments/bulk`? Если нет — требуется добавить в backend-specialist'а.

3. **Drill-down users:** Нужен ли отдельный endpoint для списка пользователей на шаге воронки, или это query-param к существующему funnel endpoint? В ТЗ предложен вариант `?step=&include_users=true` — backend должен подтвердить.

4. **Фильтр «Отдел»:** Эпик 14 (Departments) реализован (папка `/admin/departments` существует). Нужно проверить — есть ли `GET /api/admin/departments` для заполнения select'а. Если есть — фильтр сразу активен, тултип не нужен. Если нет — disabled.

5. **`UserSelect` компонент:** Существует ли готовый переиспользуемый `UserSelect` для фильтра «Менеджер»? Если нет — сделать inline `select.input` с `GET /api/users`.

6. **Формат avg_time:** backend возвращает `avg_time_to_complete_hours: number | null` (float, например 2.67). Фронтенд конвертирует: `Math.floor(h)` часов + `Math.round((h % 1) * 60)` минут → «2ч 40мин». Подтвердить единицу измерения с backend.

7. **`courses_sparkline_30d`:** В overview нужен массив из 30 значений для спарклайна. Если backend не отдаёт его — `OverviewKpiRow` рендерит без спарклайна (просто число).

8. **Bulk export body:** Что передавать в `POST /api/admin/onboarding/analytics/export` — текущие фильтры (department_id, manager_id, course_id, period) или список user_ids? Предпочтительно фильтры, чтобы экспорт соответствовал тому, что видит пользователь.

---

ТЗ готово, передавай `frontend-specialist`. Если есть правки — кидай мне.
