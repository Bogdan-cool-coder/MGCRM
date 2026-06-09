# ТЗ: Эпик 18 — AI Features (Анализ договоров + Prefill полей)

**Версия:** 1.0
**Дата:** 2026-06-02
**Автор:** designer
**Исполнитель:** frontend-specialist
**Зависит от:** Эпик 3 (Contract docs, `/contracts/[id]`), Эпик 2 (Activities/Timeline), Эпик 10.5 (Claude API setup — anthropic SDK, паттерн вызова)

---

## Cover

### Цель

Два AI-инструмента поверх Claude API, встроенные в уже существующие страницы:

1. **AI Contract Analysis** — кнопка «AI: анализ» на странице договора открывает модал с флагами рисков, сгруппированными по severity. Договор не нужно никуда загружать — backend сам читает `.docx` из `contract_revisions`.
2. **AI Deal/Lead Prefill** — кнопка «AI: заполнить из переписки» на карточке сделки или лида открывает двухшаговый модал: выбор источника данных → список предложений с чекбоксами. Пользователь принимает только то, что считает верным.

Оба инструмента: строго opt-in (пользователь сам нажимает), без автоматического применения, с явным loading-state и graceful degradation при недоступности Claude API.

### Что уже есть (НЕ переписывать)

- `Modal.tsx` — используем как есть, `width="lg"` для обоих модалов.
- `PageHeader.tsx` — без изменений.
- `/contracts/[id]/page.tsx` — добавляем только кнопку в шапку действий и монтируем `ContractAnalysisModal`.
- `/leads/[id]/page.tsx` — добавляем только кнопку и монтируем `AIPrefillModal`.
- `Timeline`, `AuditLogTimeline` — без изменений.

---

## Раздел 1: AI Contract Analysis — модал в `/contracts/[id]`

### Бизнес-цель

Менеджер или юрист видит договор в статусе `draft`/`on_approval`/`approved` и хочет быстро понять риски: нестандартные сроки оплаты, отсутствие штрафных санкций, нечёткий объём лицензии. Вместо ручного разбора — одна кнопка, Claude читает текст договора и выдаёт список флагов с рекомендациями.

### Где в коде

- Кнопка: `apps/web/src/app/(app)/contracts/[id]/page.tsx` — добавить в блок actions рядом с «Скачать PDF»
- Модал: `apps/web/src/components/AI/ContractAnalysisModal.tsx` (новый компонент)
- Типы: `apps/web/src/lib/types.ts` — добавить `ContractAnalysis`, `AnalysisFlag`, `OverallRisk`

### Wireframe

```
┌────────────────────────────────────────────────────────────────────────┐
│ [Sidebar]  │ [PageHeader: Договор #1234 / ООО «Ромашка»]              │
│            │ [Статус: draft]                [btn-secondary: AI: анализ]│
│            │                                [btn-secondary: Скачать PDF]│
│            │                                [btn-primary:  Отправить]  │
│            ├────────────────────────────────────────────────────────────┤
│            │ [Основные поля договора — без изменений]                  │
└────────────────────────────────────────────────────────────────────────┘

── Модал «AI анализ договора» (width="lg") ──────────────────────────────
┌────────────────────────────────────────────────────────────────────────┐
│ AI анализ договора            [overall_risk badge]          [x]        │
│ Договор #1234 · Последний анализ: 5 минут назад  [Обновить]           │
├────────────────────────────────────────────────────────────────────────┤
│ [tab: Замечания (3)]  [tab: Стандартные пункты (5)]  [tab: Советы (2)]│
├────────────────────────────────────────────────────────────────────────┤
│                                                                        │
│  -- таб «Замечания» активен --                                         │
│                                                                        │
│  ┌──────────────────────────────────────────────────────────────────┐  │
│  │ [i bi-exclamation-triangle-fill text-danger]  Нестандартный     │  │
│  │ срок оплаты               [badge: error]  [п. 3.2]             │  │
│  │ ┌────────────────────────────────────────────────────────────┐  │  │
│  │ │ «Покупатель обязуется оплатить в течение 30 дней...»       │  │  │
│  │ └────────────────────────────────────────────────────────────┘  │  │
│  │ Срок оплаты 30 дней превышает норму (5–10 банковских дней).     │  │
│  │ [▼ Рекомендация] Сократить до 10 банковских дней.              │  │
│  └──────────────────────────────────────────────────────────────────┘  │
│                                                                        │
│  ┌──────────────────────────────────────────────────────────────────┐  │
│  │ [bi-exclamation-circle text-warning]  Отсутствует форс-мажор   │  │
│  │                           [badge: warning]  [п. —]             │  │
│  │ Договор не содержит форс-мажорной оговорки.                    │  │
│  │ [▼ Рекомендация] Добавить стандартный пункт 7 из шаблона.      │  │
│  └──────────────────────────────────────────────────────────────────┘  │
│                                                                        │
├────────────────────────────────────────────────────────────────────────┤
│ [btn-ghost: Закрыть]          [btn-secondary: Отправить на доработку]  │
└────────────────────────────────────────────────────────────────────────┘

── Loading state ────────────────────────────────────────────────────────
│ [spinner bi-arrow-repeat animate-spin]  AI анализирует договор...     │
│ Это займёт несколько секунд                                           │

── Error state ──────────────────────────────────────────────────────────
│ [bi-exclamation-octagon text-danger]  Не удалось выполнить анализ.    │
│ Попробуй ещё раз или обратись к администратору.  [Повторить]          │
```

### State machine

```
IDLE
  └─ click «AI: анализ»
       └─ проверить кэш (есть cached_at на backend)
            ├─ кэш свежий (< 1h) → открыть модал сразу в состоянии RESULT (cached)
            └─ кэш устарел / нет кэша → POST /api/contracts/{id}/ai-analyze
                  └─ LOADING (spinner, кнопка disabled)
                        ├─ success → RESULT
                        └─ error (4xx/5xx/timeout) → ERROR

RESULT
  └─ click «Обновить» → POST /api/contracts/{id}/ai-analyze → LOADING

ERROR
  └─ click «Повторить» → POST /api/contracts/{id}/ai-analyze → LOADING
```

### Компонент ContractAnalysisModal

**Props:**
```typescript
interface ContractAnalysisModalProps {
  open: boolean;
  onClose: () => void;
  contractId: number;
  contractTitle: string;  // для заголовка модала, например «Договор #1234»
}
```

**Внутренние типы (добавить в `@/lib/types.ts`):**
```typescript
export type OverallRisk = 'low' | 'medium' | 'high' | 'critical';

export interface AnalysisFlag {
  id: string;
  severity: 'info' | 'warning' | 'error';
  category: string;           // 'payment_terms' | 'missing_clauses' | etc.
  section: string;            // 'п. 3.2' или '—'
  flag_text: string;
  recommendation: string;
  original_text?: string;     // цитата из договора
}

export interface ContractAnalysis {
  flags: AnalysisFlag[];
  overall_risk: OverallRisk;
  analyzed_at: string;        // ISO datetime
  model: string;
  cached?: boolean;           // backend-поле: был ли результат из кэша
}
```

**Состояние компонента:**
```typescript
type ModalState = 'idle' | 'loading' | 'result' | 'error';

const [state, setState] = useState<ModalState>('idle');
const [analysis, setAnalysis] = useState<ContractAnalysis | null>(null);
const [activeTab, setActiveTab] = useState<'issues' | 'standard' | 'recommendations'>('issues');
const [expandedFlags, setExpandedFlags] = useState<Set<string>>(new Set());
const [apiError, setApiError] = useState<string | null>(null);
```

**Логика вызова:**
```typescript
async function runAnalysis(forceRefresh = false) {
  setState('loading');
  setApiError(null);
  try {
    const result = await api(`/contracts/${contractId}/ai-analyze`, {
      method: 'POST',
      body: JSON.stringify({ force_refresh: forceRefresh }),
    });
    setAnalysis(result as ContractAnalysis);
    setState('result');
  } catch (e) {
    setApiError(e instanceof ApiError ? e.message : 'Неизвестная ошибка');
    setState('error');
  }
}
```

**При открытии модала** (`useEffect` на `open`) — вызвать `runAnalysis()` автоматически. Пользователь не нажимает отдельную кнопку «Запустить» внутри модала.

### UI компоненты и Tailwind

**Кнопка на странице договора** (добавляется в actions-блок `PageHeader`):
```
<button className="btn-secondary" onClick={() => setAnalysisOpen(true)}>
  <i className="bi bi-stars mr-2" />
  AI: анализ
</button>
```
Кнопка доступна при любом статусе договора (не только draft). Если предыдущий анализ был кэширован — показывать тултип «Последний анализ: X минут назад» через `title` атрибут.

**Overall Risk Badge** (в заголовке модала, рядом с title):

| `overall_risk` | Tailwind | Текст |
|---|---|---|
| `low` | `bg-success/10 text-success` | Низкий риск |
| `medium` | `bg-warning/10 text-warning` | Средний риск |
| `high` | `bg-danger/10 text-danger` | Высокий риск |
| `critical` | `bg-danger text-white` | Критический |

**Severity иконки в карточке флага:**

| `severity` | Иконка | Цвет |
|---|---|---|
| `error` | `bi-exclamation-triangle-fill` | `text-danger` |
| `warning` | `bi-exclamation-circle` | `text-warning` |
| `info` | `bi-info-circle` | `text-info` |

**Карточка флага:**
```
card p-4 mb-3 border-l-4 border-l-[цвет severity]
  ─ Шапка: flex items-center gap-2
    [иконка severity]
    [flag_text — font-medium]
    [badge severity справа]
    [span text-xs text-gray-500: section]
  ─ Цитата (если есть original_text):
    blockquote: italic text-sm bg-gray-50 px-3 py-2 rounded border-l-2 border-gray-300 mt-2
  ─ Объяснение = flag_text подробно? нет — flag_text это уже объяснение.
    Рекомендация (collapsible):
    [▼/▲ Рекомендация] → text-sm text-gray-700 mt-2
```

Флаги внутри таба **«Замечания»** — сортировать: error → warning → info.

**Таб «Стандартные пункты»** — список строк из `standard_sections` (массив `{ section: string; status: 'ok' | 'warning' }`):
```
строка: flex items-center gap-2 py-2 border-b border-gray-100
  [bi-check-lg text-success / bi-exclamation text-warning]
  [section — text-sm]
```

**Таб «Советы»** — список `recommendations` (массив строк):
```
строка: flex items-start gap-2 py-2 border-b border-gray-100
  [bi-lightbulb text-info flex-shrink-0 mt-0.5]
  [text-sm]
```

**Loading state** (внутри body модала, вместо контента):
```
flex flex-col items-center justify-center py-16 text-gray-500
  [i.bi-arrow-repeat animate-spin text-3xl text-primary mb-4]
  [p: «AI анализирует договор...»]
  [p text-xs text-gray-400: «Это займёт несколько секунд»]
```

**Error state** (внутри body модала):
```
flex flex-col items-center justify-center py-12 text-center
  [i.bi-exclamation-octagon text-danger text-3xl mb-3]
  [p font-medium: «Не удалось выполнить анализ»]
  [p text-sm text-gray-500 mb-4: {apiError}]
  [button.btn-secondary onClick=runAnalysis: «Повторить»]
```

**Строка кэш-индикатора** (под заголовком модала, только если `analysis?.cached`):
```
text-xs text-gray-400 flex items-center gap-2
  [«Последний анализ: {N} минут назад»]
  [button.btn-ghost text-xs px-2 py-0.5 onClick={() => runAnalysis(true)}: «Обновить»]
```

**Footer модала:**
- Слева: `[btn-ghost: Закрыть]`
- Справа (только в state=result, только если есть флаги severity=error или warning): `[btn-secondary: Отправить на доработку]`

«Отправить на доработку» — открывает `ApprovalPanel` или создаёт Remark через `PATCH /api/contracts/{id}` с `status: 'on_approval'` и `remark` prefilled из первых двух флагов (severity=error). Этот флоу — **открытый вопрос** (см. ниже).

### States — сводка

| State | Что показываем в body модала |
|---|---|
| `loading` | spinner + «AI анализирует договор...» |
| `result` (флаги есть) | табы + карточки флагов |
| `result` (флагов нет) | EmptyState: bi-check-circle-fill text-success, «Договор выглядит чисто. Замечаний нет.» |
| `error` | иконка danger + текст ошибки + «Повторить» |

### Interactions

| Элемент | Действие | Результат |
|---|---|---|
| Кнопка «AI: анализ» на странице договора | click | открыть ContractAnalysisModal, автозапуск runAnalysis() |
| Таб «Замечания» / «Стандартные пункты» / «Советы» | click | переключить activeTab |
| Карточка флага — «▼ Рекомендация» | click | expand/collapse блок рекомендации (toggle в expandedFlags Set) |
| Кнопка «Обновить» (кэш-индикатор) | click | runAnalysis(true) — force_refresh=true |
| Кнопка «Повторить» (error state) | click | runAnalysis() |
| Кнопка «Закрыть» | click | onClose() |
| Кнопка «Отправить на доработку» | click | см. открытые вопросы |
| Esc / клик на backdrop | - | Modal.tsx сам закрывает |

### Backend — Раздел 1

- **Endpoint:** `POST /api/contracts/{id}/ai-analyze`
- **Body:** `{ force_refresh?: boolean }`
- **Response:** `ContractAnalysis` (типы выше)
- **Кэш:** backend кэширует в Redis TTL 1h; если `force_refresh=true` — игнорирует кэш
- **Индикатор недоступности:** если API недоступен → `503` → кнопка «AI: анализ» показывает `title="AI временно недоступен"` + `disabled` (опционально, см. открытые вопросы — нужен ли ping endpoint)
- **Права:** уточнить в открытых вопросах — любой пользователь или только roles

---

## Раздел 2: AI Deal/Lead Prefill — модал в `/leads/[id]` и `/deals/[id]`

### Бизнес-цель

Менеджер провёл несколько звонков и написал заметки в Activities. Перед квалификацией сделки или лида — нажимает одну кнопку, AI читает историю и предлагает заполнить BANT-поля: бюджет, дату закрытия, описание потребности, контактное лицо. Менеджер принимает или отклоняет каждое предложение отдельно — никакой автоматики.

### Где в коде

- Кнопка на Lead: `apps/web/src/app/(app)/leads/[id]/page.tsx` — добавить в actions PageHeader или в блок основных полей
- Кнопка на Deal (когда появится `/deals/[id]`): `apps/web/src/app/(app)/deals/[id]/page.tsx`
- Модал: `apps/web/src/components/AI/AIPrefillModal.tsx` (новый, универсальный для Lead и Deal)
- Типы: `apps/web/src/lib/types.ts` — добавить `PrefillSuggestion`, `PrefillResult`, `PrefillSource`, `ConfidenceLevel`

### Wireframe

```
┌──────────────────────────────────────────────────────────────────────────┐
│ [Sidebar]  │ [PageHeader: Лид #42 / Алексей Иванов]                     │
│            │ [Статус: active]    [btn-secondary: AI: заполнить]          │
│            │                     [btn-primary: Редактировать]            │
│            ├──────────────────────────────────────────────────────────────┤
│            │ [Основные поля лида — без изменений]                        │
└──────────────────────────────────────────────────────────────────────────┘

── Модал «AI предзаполнение» — Шаг 1 (width="md") ─────────────────────────
┌──────────────────────────────────────────────────────────────────────────┐
│ AI предзаполнение                                             [x]        │
│ Шаг 1 из 2 · Выбери источник данных                                     │
├──────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│  Источник                                                                │
│  ○  История активностей (звонки, встречи, задачи, заметки)              │
│  ○  Входящие сообщения (Telegram, Email, Formы)                          │
│  ●  Все источники  [рекомендуется]                                       │
│                                                                          │
│  Период                                                                  │
│  [select: Последние 7 дней / 30 дней / 90 дней / Всё время]             │
│                                                                          │
│  [text-sm text-gray-500] Найдено активностей: 12 · Сообщений: 5         │
│                                                                          │
├──────────────────────────────────────────────────────────────────────────┤
│ [btn-ghost: Отмена]                       [btn-primary: Анализировать →] │
└──────────────────────────────────────────────────────────────────────────┘

── Loading (между шагами) ───────────────────────────────────────────────────
│ [bi-arrow-repeat animate-spin text-primary text-3xl]                    │
│ AI читает историю переписки...                                          │
│ Анализируем 12 активностей и 5 сообщений                                │

── Шаг 2 — результат ───────────────────────────────────────────────────────
┌──────────────────────────────────────────────────────────────────────────┐
│ AI предзаполнение                                             [x]        │
│ Шаг 2 из 2 · Выбери, что применить                                      │
├──────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│  «Клиент ищет CRM для 15 менеджеров, бюджет ~500K RUB, хотят к июлю»   │
│  [bg-gray-50 text-sm italic px-3 py-2 rounded mb-4]                     │
│                                                                          │
│  ┌────────────────────────────────────────────────────────────────────┐  │
│  │ [✓] [bi-currency-ruble]  Бюджет                                   │  │
│  │     AI предлагает: 500 000 RUB                   [badge: Высокая] │  │
│  │     [▼ Источник] «хотим бюджет около 500К» (Заметка, 28 мая)     │  │
│  └────────────────────────────────────────────────────────────────────┘  │
│  ┌────────────────────────────────────────────────────────────────────┐  │
│  │ [✓] [bi-calendar]  Дата закрытия                                  │  │
│  │     AI предлагает: 01.07.2026                    [badge: Средняя] │  │
│  │     [▼ Источник] «хотим к июлю» (Звонок, 25 мая)                 │  │
│  └────────────────────────────────────────────────────────────────────┘  │
│  ┌────────────────────────────────────────────────────────────────────┐  │
│  │ [ ] [bi-person]  Контактное лицо                                  │  │
│  │     AI предлагает: Алексей Иванов               [badge: Низкая]  │  │
│  └────────────────────────────────────────────────────────────────────┘  │
│                                                                          │
│  [← Назад]                [btn-primary: Применить выбранные (2)]        │
└──────────────────────────────────────────────────────────────────────────┘

── Empty state (нет активностей) ────────────────────────────────────────────
│ [bi-chat-dots text-gray-300 text-5xl]                                   │
│ Недостаточно данных для анализа                                         │
│ Добавь хотя бы одну активность или сообщение, чтобы AI мог               │
│ проанализировать переписку.                                              │
│ [btn-ghost: Закрыть]                                                    │
```

### State machine

```
STEP_1 (выбор источника + периода)
  └─ click «Анализировать»
       └─ POST /api/{entity}/{id}/ai-prefill
            └─ LOADING («AI читает историю...»)
                  ├─ success, suggestions.length > 0 → STEP_2
                  ├─ success, suggestions.length === 0 → EMPTY
                  └─ error → ERROR (в шаге 1, под кнопкой)

STEP_2 (список предложений с чекбоксами)
  └─ click «Применить выбранные»
       └─ PATCH /api/{entity}/{id} с выбранными полями
            ├─ success → onClose() + mutate SWR ключа
            └─ error → inline error под кнопкой в footer

  └─ click «← Назад» → STEP_1 (сбрасываем suggestions, сохраняем source/period)

ERROR (в step 1)
  └─ inline text-danger под кнопкой «Анализировать»

EMPTY
  └─ показать empty state, footer: только [btn-ghost: Закрыть]
```

### Компонент AIPrefillModal

**Props:**
```typescript
interface AIPrefillModalProps {
  open: boolean;
  onClose: () => void;
  entityType: 'lead' | 'deal';
  entityId: number;
  onApplied: () => void;  // вызывается после успешного PATCH → обычно mutate() SWR
}
```

**Внутренние типы (добавить в `@/lib/types.ts`):**
```typescript
export type PrefillSource = 'activities' | 'messages' | 'all';
export type PrefillPeriod = '7d' | '30d' | '90d' | 'all';
export type ConfidenceLevel = 'high' | 'medium' | 'low';

export interface PrefillSuggestion {
  field: string;               // 'estimated_budget', 'expected_close_date', 'description', 'contact_name', etc.
  label: string;               // человеко-читаемый RU label поля
  suggested_value: string | number | null;
  confidence: ConfidenceLevel;
  reasoning: string;           // источник/объяснение AI
  source_activity_id?: number;
  source_text?: string;        // цитата из источника
}

export interface PrefillResult {
  suggestions: PrefillSuggestion[];
  raw_summary: string;         // краткое резюме AI (1-2 предложения)
  ai_tokens_used?: number;
}
```

**Состояние компонента:**
```typescript
type PrefillStep = 'step1' | 'loading' | 'step2' | 'empty' | 'error';

const [step, setStep] = useState<PrefillStep>('step1');
const [source, setSource] = useState<PrefillSource>('all');
const [period, setPeriod] = useState<PrefillPeriod>('30d');
const [result, setResult] = useState<PrefillResult | null>(null);
const [checked, setChecked] = useState<Set<string>>(new Set());
const [expandedSources, setExpandedSources] = useState<Set<string>>(new Set());
const [applying, setApplying] = useState(false);
const [applyError, setApplyError] = useState<string | null>(null);
const [fetchError, setFetchError] = useState<string | null>(null);
```

**Логика «Анализировать»:**
```typescript
async function fetchSuggestions() {
  setStep('loading');
  setFetchError(null);
  try {
    const endpoint = `/${entityType}s/${entityId}/ai-prefill?source=${source}&period=${period}`;
    const data = await api(endpoint, { method: 'POST' });
    const prefill = data as PrefillResult;
    setResult(prefill);
    // по умолчанию — всё с высокой и средней уверенностью отмечаем
    const defaultChecked = new Set(
      prefill.suggestions
        .filter(s => s.confidence === 'high' || s.confidence === 'medium')
        .map(s => s.field)
    );
    setChecked(defaultChecked);
    setStep(prefill.suggestions.length > 0 ? 'step2' : 'empty');
  } catch (e) {
    setFetchError(e instanceof ApiError ? e.message : 'Не удалось выполнить анализ');
    setStep('error');
  }
}
```

**Логика «Применить выбранные»:**
```typescript
async function applySelected() {
  if (!result) return;
  setApplying(true);
  setApplyError(null);
  const payload: Record<string, unknown> = {};
  result.suggestions
    .filter(s => checked.has(s.field))
    .forEach(s => { payload[s.field] = s.suggested_value; });
  try {
    await api(`/${entityType}s/${entityId}`, {
      method: 'PATCH',
      body: JSON.stringify(payload),
    });
    onApplied();
    onClose();
  } catch (e) {
    setApplyError(e instanceof ApiError ? e.message : 'Не удалось применить изменения');
    setApplying(false);
  }
}
```

### UI компоненты и Tailwind — Раздел 2

**Кнопка на странице лида** (добавить в actions рядом с «Редактировать»):
```
<button className="btn-secondary" onClick={() => setPrefillOpen(true)}>
  <i className="bi bi-magic mr-2" />
  AI: заполнить
</button>
```

**Шаг 1 — форма выбора источника:**

Radio-группа источников:
```
fieldset label.flex.items-center.gap-2.cursor-pointer.py-2
  input[type=radio] accent-primary
  span.text-sm: «История активностей (звонки, встречи, задачи, заметки)»
```
Опции: «История активностей», «Входящие сообщения», «Все источники» (по умолчанию выбрано «Все источники»).

Select периода — нативный `<select className="input mt-1 w-full">`:
- «Последние 7 дней»
- «Последние 30 дней» (дефолт)
- «Последние 90 дней»
- «Всё время»

Счётчик данных (если нужен — через отдельный SWR-запрос `GET /api/{entity}/{id}/activity-count?source={source}&period={period}` или просто убрать, если дорого):
```
text-sm text-gray-500 mt-3
«Найдено активностей: N · Сообщений: M»
```
Этот счётчик — **опциональный** (см. открытые вопросы).

**Confidence Badge:**

| `confidence` | Tailwind | Текст |
|---|---|---|
| `high` | `bg-success/10 text-success` | Высокая |
| `medium` | `bg-warning/10 text-warning` | Средняя |
| `low` | `bg-gray-100 text-gray-500` | Низкая |

**Иконки полей** — маппинг field → Bootstrap Icon:

| field | Иконка |
|---|---|
| `estimated_budget` / `amount` | `bi-currency-ruble` |
| `expected_close_date` | `bi-calendar` |
| `contact_name` | `bi-person` |
| `description` / `need` | `bi-chat-left-text` |
| `country` | `bi-globe` |
| `company` | `bi-building` |
| _прочие_ | `bi-tag` |

**Карточка предложения (Шаг 2):**
```
card p-3 mb-2 flex items-start gap-3
  ─ чекбокс: input[type=checkbox] accent-primary mt-0.5 flex-shrink-0
  ─ иконка поля: bi-* text-gray-400 mt-0.5 flex-shrink-0
  ─ контент (flex-1):
      ─ строка 1: [label — text-sm font-medium] + [confidence badge ml-auto]
      ─ строка 2: text-sm text-gray-700 «AI предлагает: {suggested_value}»
      ─ collapsible «Источник»: text-xs text-gray-400 cursor-pointer
          expanded: text-xs text-gray-500 bg-gray-50 px-2 py-1 rounded italic mt-1
            «{source_text}» (Активность #{source_activity_id}, дата)
```

Карточки с `confidence='low'` — opacity-70, чекбокс по умолчанию unchecked.

**Счётчик применяемых в кнопке footer:**
```
«Применить выбранные ({checked.size})»
```
Если `checked.size === 0` — кнопка `disabled`.

**Кнопка «← Назад»:**
```
<button className="btn-secondary" onClick={() => setStep('step1')}>
  <i className="bi bi-arrow-left mr-2" />Назад
</button>
```

**Apply error (под footer кнопками):**
```
text-sm text-danger text-right mt-2
```

**Loading state** (внутри body модала):
```
flex flex-col items-center justify-center py-16
  [bi-arrow-repeat animate-spin text-primary text-3xl mb-4]
  [p font-medium: «AI читает историю переписки...»]
  [p text-sm text-gray-500: «Анализируем {source_label} за выбранный период»]
```

**Empty state** (внутри body модала):
```
flex flex-col items-center text-center py-12
  [bi-chat-dots text-gray-300 text-5xl mb-4]
  [p font-medium: «Недостаточно данных для анализа»]
  [p text-sm text-gray-500: описание]
```

**Error state** (встраивается в step1 под кнопку «Анализировать»):
```
text-sm text-danger mt-2
«Не удалось выполнить анализ: {fetchError}»
```

### States — сводка

| Step | Body | Footer |
|---|---|---|
| `step1` | форма выбора источника + периода + (опц.) счётчик | [Отмена] [Анализировать →] |
| `loading` | spinner + «AI читает...» | нет |
| `step2` | raw_summary + список предложений с чекбоксами | [← Назад] [Применить выбранные (N)] |
| `empty` | empty state с иконкой | [Закрыть] |
| `error` | step1-форма + inline error под кнопкой | [Отмена] [Анализировать →] |

### Interactions — Раздел 2

| Элемент | Действие | Результат |
|---|---|---|
| Кнопка «AI: заполнить» на странице лида/сделки | click | открыть AIPrefillModal, шаг 1 |
| Radio-группа источника | change | setSource(...) |
| Select периода | change | setPeriod(...) |
| Кнопка «Анализировать» | click | fetchSuggestions() → loading → step2/empty/error |
| Чекбокс предложения | change | toggle в checked Set |
| «▼ Источник» в карточке | click | toggle в expandedSources Set |
| Кнопка «← Назад» | click | setStep('step1'), result сохраняется, checked сохраняется |
| Кнопка «Применить выбранные» | click | applySelected() → PATCH → onApplied() + onClose() |
| Кнопка «Отмена» / «Закрыть» | click | onClose() |
| Esc / backdrop | - | Modal.tsx закрывает |

### Backend — Раздел 2

- **Lead endpoint:** `POST /api/leads/{id}/ai-prefill?source=activities|messages|all&period=7d|30d|90d|all`
- **Deal endpoint:** `POST /api/deals/{id}/ai-prefill?source=activities|messages|all&period=7d|30d|90d|all`
- **Response:** `PrefillResult` (типы выше)
- **Без кэша** — контекст активностей постоянно меняется, кэш не нужен
- **Apply:** `PATCH /api/leads/{id}` или `PATCH /api/deals/{id}` — существующий endpoint, фронт передаёт только выбранные поля
- **Недоступность:** `503` → inline error в модале, кнопка «AI: заполнить» не disabled (пользователь узнает об ошибке только при клике)

---

## Раздел 3: Координация с Эпиком 10.5 (AI Assistant Chat)

Эпик 10.5 — «Личный кабинет менеджера + KPI + AI» — включает полноценный AI-чат в отдельном разделе. Эпик 18 и Эпик 10.5 **не пересекаются по компонентам** — это разные точки входа:

| | Эпик 18 | Эпик 10.5 |
|---|---|---|
| Точка входа | Кнопка на странице договора / лида / сделки | Отдельный раздел «Ассистент» в sidebar |
| Паттерн взаимодействия | Modal, одноразовый запрос → структурированный ответ | Многоходовой чат с историей |
| Claude API клиент | Переиспользует `anthropic.AsyncAnthropic()` из Эпик 10.5 | Создаётся в Эпик 10.5 |
| Модели | Contract Analysis: Sonnet. Prefill: Haiku | Определяется в Эпик 10.5 |

**Зависимость:** Эпик 18 требует, чтобы backend setup Claude API (SDK, ключ, `anthropic.AsyncAnthropic()` singleton) был выполнен. Если Эпик 10.5 идёт раньше — Эпик 18 переиспользует инфраструктуру. Если Эпик 18 идёт первым — backend-specialist создаёт minimal setup (только SDK + вызов), без chat-endpoint.

**Что НЕ делать в Эпик 18:**
- Не добавлять AI-чат в договор или лид (это Эпик 10.5)
- Не создавать `useAIChat` hook — это будет в Эпик 10.5
- Не хранить историю диалога — Эпик 18 stateless

---

## Список новых компонентов

| Компонент | Путь | Описание |
|---|---|---|
| `ContractAnalysisModal` | `apps/web/src/components/AI/ContractAnalysisModal.tsx` | Модал анализа договора. Props: `open`, `onClose`, `contractId`, `contractTitle` |
| `AIPrefillModal` | `apps/web/src/components/AI/AIPrefillModal.tsx` | Двухшаговый модал prefill. Props: `open`, `onClose`, `entityType`, `entityId`, `onApplied` |
| `AnalysisFlagCard` | `apps/web/src/components/AI/AnalysisFlagCard.tsx` | Карточка одного флага (severity, цитата, рекомендация). Используется в ContractAnalysisModal |
| `PrefillSuggestionRow` | `apps/web/src/components/AI/PrefillSuggestionRow.tsx` | Строка одного предложения с чекбоксом. Используется в AIPrefillModal |
| `AIRiskBadge` | `apps/web/src/components/AI/AIRiskBadge.tsx` | Бейдж overall_risk (low/medium/high/critical). Используется в ContractAnalysisModal |
| `ConfidenceBadge` | `apps/web/src/components/AI/ConfidenceBadge.tsx` | Бейдж confidence (high/medium/low). Используется в AIPrefillModal |

Все компоненты — в новой папке `apps/web/src/components/AI/`.

**Изменения в существующих файлах:**
- `apps/web/src/app/(app)/contracts/[id]/page.tsx` — добавить кнопку «AI: анализ» + монтирование `ContractAnalysisModal`
- `apps/web/src/app/(app)/leads/[id]/page.tsx` — добавить кнопку «AI: заполнить» + монтирование `AIPrefillModal`
- `apps/web/src/lib/types.ts` — добавить типы `ContractAnalysis`, `AnalysisFlag`, `OverallRisk`, `PrefillSuggestion`, `PrefillResult`, `PrefillSource`, `PrefillPeriod`, `ConfidenceLevel`

---

## Тексты (RU, без i18n)

### Общие

- Кнопка анализа договора: `AI: анализ`
- Кнопка prefill: `AI: заполнить`
- Тултип при недоступности AI: `AI временно недоступен`

### ContractAnalysisModal

- Заголовок модала: `AI анализ договора`
- Таб 1: `Замечания`
- Таб 2: `Стандартные пункты`
- Таб 3: `Советы`
- Кэш-индикатор: `Последний анализ: {N} минут назад`
- Кнопка обновить: `Обновить`
- Severity badge error: `Критично`
- Severity badge warning: `Внимание`
- Severity badge info: `Информация`
- Overall risk low: `Низкий риск`
- Overall risk medium: `Средний риск`
- Overall risk high: `Высокий риск`
- Overall risk critical: `Критический риск`
- Toggle рекомендации (закрыта): `Рекомендация`
- Toggle рекомендации (открыта): `Скрыть`
- Loading title: `AI анализирует договор...`
- Loading subtitle: `Это займёт несколько секунд`
- Empty state title: `Замечаний нет`
- Empty state desc: `Договор выглядит чисто — нестандартных пунктов не обнаружено`
- Error title: `Не удалось выполнить анализ`
- Error subtitle: `Попробуй ещё раз или обратись к администратору`
- Кнопка повторить: `Повторить`
- Кнопка закрыть: `Закрыть`
- Кнопка отправить на доработку: `Отправить на доработку`
- Стандартный пункт status ok: `` (только иконка bi-check-lg text-success)
- Стандартный пункт status warning: `` (только иконка bi-exclamation text-warning)

### AIPrefillModal

- Заголовок модала: `AI предзаполнение`
- Шаг 1 subtitle: `Шаг 1 из 2 · Выбери источник данных`
- Шаг 2 subtitle: `Шаг 2 из 2 · Выбери, что применить`
- Лейбл источника: `Источник данных`
- Radio «Все источники»: `Все источники`
- Radio «Только активности»: `История активностей (звонки, встречи, задачи, заметки)`
- Radio «Только сообщения»: `Входящие сообщения (Telegram, Email, Формы)`
- Бейдж рекомендуется: `рекомендуется`
- Лейбл периода: `Период`
- Select option 7d: `Последние 7 дней`
- Select option 30d: `Последние 30 дней`
- Select option 90d: `Последние 90 дней`
- Select option all: `Всё время`
- Кнопка анализировать: `Анализировать`
- Loading title: `AI читает историю переписки...`
- Loading subtitle (activities): `Анализируем активности за выбранный период`
- Loading subtitle (messages): `Анализируем входящие сообщения за выбранный период`
- Loading subtitle (all): `Анализируем все источники за выбранный период`
- Prefix предложения: `AI предлагает:`
- Confidence high: `Высокая`
- Confidence medium: `Средняя`
- Confidence low: `Низкая`
- Toggle источника (закрыт): `Источник`
- Toggle источника (открыт): `Скрыть`
- Кнопка назад: `Назад`
- Кнопка применить (0 checked): `Применить выбранные` (disabled)
- Кнопка применить (N checked): `Применить выбранные ({N})`
- Apply error prefix: `Не удалось применить:`
- Empty state title: `Недостаточно данных для анализа`
- Empty state desc: `Добавь хотя бы одну активность или сообщение, чтобы AI мог проанализировать переписку`
- Error prefix: `Не удалось выполнить анализ:`
- Кнопка отмена: `Отмена`
- Кнопка закрыть: `Закрыть`

### Маппинг field → label (для PrefillSuggestionRow)

| field | label |
|---|---|
| `estimated_budget` | `Бюджет` |
| `amount` | `Сумма сделки` |
| `expected_close_date` | `Дата закрытия` |
| `contact_name` | `Контактное лицо` |
| `description` | `Описание потребности` |
| `country` | `Страна` |
| `company_name` | `Компания` |
| `qualification_score` | `Оценка квалификации` |
| _прочие_ | `{field}` (как есть) |

---

## Адаптивность

Desktop-first. Mobile — TBD (эпик 10), сейчас не приоритет. Модалы используют стандартный `Modal.tsx`, который уже имеет `overflow-y-auto` и корректно работает на viewport шириной от 768px.

---

## Открытые вопросы

1. **Права на Contract Analysis.** Любой авторизованный пользователь или только роли `lawyer`/`director`/`admin`? Если ограничение — нужен `RoleGate` вокруг кнопки. Требует уточнения у продукта.

2. **«Отправить на доработку» из модала анализа.** Что именно происходит при клике? Варианты:
   - (a) `PATCH /api/contracts/{id}` с `status: 'on_approval'` + `remark` prefilled из флагов severity=error
   - (b) просто закрыть модал и открыть уже существующий `ApprovalPanel`
   - (c) убрать эту кнопку совсем — достаточно «Закрыть» и ручной доработки
   Нужна позиция продукта. Пока оставляем кнопку как disabled-заглушку.

3. **Кэш-индикатор: формат времени.** «5 минут назад» — нужна ли полная дата/время для давних анализов (> 1 дня)? Предложение: < 60 мин → «{N} минут назад», < 24ч → «{N} часов назад», иначе → дата `дд.мм.гггг чч:мм`.

4. **Ping endpoint для кнопки «AI: анализ».** Нужен ли `GET /api/ai/status` чтобы показывать кнопку `disabled` при недоступности Claude API? Или достаточно узнавать об ошибке при первом клике (текущее ТЗ)? Ping добавляет complexity, текущее решение проще.

5. **Счётчик активностей в шаге 1 Prefill.** «Найдено активностей: N · Сообщений: M» — нужен дополнительный SWR-запрос `GET /api/{entity}/{id}/activity-count`. Если это дорого (2 лишних запроса при открытии модала) — убрать счётчик совсем. Нужна позиция продукта.

6. **Audit trail для prefill.** Если менеджер применил AI-предложение и оно оказалось неверным — нет записи в `AuditLog`. Добавить в PATCH `ai_prefill: true` метку, чтобы backend писал `AuditLog` с источником `ai_prefill`? Это полезно для ретроспектив, но требует правки backend. **Требуется правка backend если решим делать.**

7. **Deal page.** Сейчас `/deals/[id]/page.tsx` не существует (только Kanban-карточки). Когда появится страница сделки — туда встраивается та же `AIPrefillModal` с `entityType='deal'`. Пока — только на `/leads/[id]`.

8. **Модели Claude.** Obsidian-план указывает Sonnet для анализа договора и Haiku для prefill. Финальные model id (`claude-haiku-4-5`, `claude-sonnet-4-5` или другие актуальные) — уточнить у backend-specialist при реализации. Эпик 10.5 может переопределить.

9. **`standard_sections` и `recommendations` в ContractAnalysis.** В Obsidian-плане они есть, но исходный ТЗ-запрос называет их «Стандартные пункты» и «Рекомендации». Если backend возвращает `standard_sections: Array<{ section: string; status: 'ok' | 'warning' }>` и `recommendations: string[]` — UI готов. Если response shape изменится — **требуется правка ТЗ**.

---

*Desktop-first. Всё на RU. i18n — будущий эпик.*
