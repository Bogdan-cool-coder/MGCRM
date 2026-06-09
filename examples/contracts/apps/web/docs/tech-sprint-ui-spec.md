# ТЗ: Tech Sprint — Quick Wins (Фаза 1 MARATHON-2)

**Дата:** 2026-06-02  
**Контекст:** 7 UI-фич для быстрого закрытия долга. Все фичи самодостаточны, порядок реализации произвольный.  
**Стек:** Next.js 14 / TypeScript strict / Tailwind / Bootstrap Icons / SWR / `@/lib/api` (cookie-auth, credentials: same-origin)  
**Тексты:** только RU, inline (без i18n-ключей)  
**Адаптивность:** desktop-first, mobile — будущий эпик

---

## Оглавление

1. [Rotate Secret UI](#1-rotate-secret-ui)
2. [Webhook Retry UI](#2-webhook-retry-ui)
3. [Per-webhook Settings](#3-per-webhook-settings)
4. [Merge 3+ дублей](#4-merge-3-дублей)
5. [Auto-dedup Live Check](#5-auto-dedup-live-check)
6. [Quiz Randomize Checkbox](#6-quiz-randomize-checkbox)
7. [DnD Reorder везде](#7-dnd-reorder-везде)
8. [Компоненты: создать / изменить](#компоненты-создать--изменить)

---

## 1. Rotate Secret UI

**Зачем:** пользователь может сменить HMAC-секрет вебхука через UI без обращения к БД напрямую. Endpoint `POST /webhooks/{id}/regenerate-secret` уже реализован в Эпике 11.

**Где в коде:**
- Компонент (изменить): `apps/web/src/components/Webhooks/CreateWebhookModal.tsx`
- Страница: `apps/web/src/app/(app)/admin/webhooks/page.tsx`

### Wireframe

```
┌──────────────────────────────────────────────────────────┐
│ Modal: Редактировать вебхук                          [×] │
├──────────────────────────────────────────────────────────┤
│ Название *                                               │
│ [________________________]                               │
│                                                          │
│ URL *                                                    │
│ [________________________]                               │
│                                                          │
│ ... (существующие поля события/заголовки/активность) ... │
│                                                          │
│ ─────────── Секрет HMAC ───────────                      │
│ [btn-secondary bi-arrow-clockwise] Сгенерировать         │
│                                       новый секрет       │
│                                                          │
│ ── только после нажатия: confirm overlay ──              │
│ ┌────────────────────────────────────────────────┐       │
│ │ Внимание!                                      │       │
│ │ Старый секрет перестанет работать.             │       │
│ │ Все подписчики получат ошибку HMAC при...      │       │
│ │            [btn-ghost: Отмена] [btn-primary:   │       │
│ │                               Да, сгенерировать│       │
│ └────────────────────────────────────────────────┘       │
│                                                          │
│ ── после confirm: поле с новым секретом ──               │
│ Новый секрет (сохрани — больше не покажем)               │
│ [whsec_aabbcc...________________] [bi-clipboard btn]     │
│ ✓ Скопировано                                            │
│                                                          │
│ [btn-ghost: Отмена]              [btn-primary: Сохранить]│
└──────────────────────────────────────────────────────────┘
```

### State machine

| Состояние | Описание | UI |
|---|---|---|
| `idle` | Кнопка "Сгенерировать новый секрет" видна | `btn-secondary` активна |
| `confirm` | Overlay-предупреждение поверх формы | Overlay `div` с `bg-warning/10`, кнопки Отмена / Да |
| `loading` | Запрос к серверу | Кнопка `disabled` + `animate-spin` на `bi-arrow-clockwise` |
| `revealed` | Показан новый секрет | Поле `input` с `readOnly`, кнопка копирования |
| `copied` | Скопировано в буфер | Иконка меняется на `bi-check2 text-success` на 2 сек |
| `error` | Ошибка запроса | Inline `text-danger` под кнопкой |

### Дизайн компонента

Добавить в `CreateWebhookModal` — в блок `{isEdit && ...}` (там где checkbox "Активен") — секцию перед footer:

```
<div className="border-t border-gray-100 pt-4 mt-4">
  <label className="label">Секрет HMAC</label>
  
  {/* Кнопка инициирует flow */}
  <button type="button" className="btn-secondary" onClick={() => setRotateStep("confirm")}
          disabled={submitting || rotateStep === "loading"}>
    <i className="bi bi-arrow-clockwise mr-2" />
    Сгенерировать новый секрет
  </button>

  {/* Confirm overlay — абсолютный, поверх секции */}
  {rotateStep === "confirm" && (
    <div className="mt-3 rounded-lg bg-warning/10 border border-warning/30 p-4">
      <p className="text-sm text-gray-800 font-medium mb-1">
        <i className="bi bi-exclamation-triangle text-warning mr-1" />
        Старый секрет перестанет работать
      </p>
      <p className="text-sm text-gray-600 mb-3">
        Все подписчики получат ошибку HMAC при следующем событии,
        пока не обновят секрет на своей стороне.
      </p>
      <div className="flex gap-2 justify-end">
        <button className="btn-ghost" onClick={() => setRotateStep("idle")}>Отмена</button>
        <button className="btn-primary" onClick={handleRotateSecret}>Да, сгенерировать</button>
      </div>
    </div>
  )}

  {/* Loading */}
  {rotateStep === "loading" && (
    <div className="mt-2 text-sm text-gray-500">
      <i className="bi bi-arrow-clockwise animate-spin mr-1" /> Генерируем…
    </div>
  )}

  {/* New secret revealed */}
  {rotateStep === "revealed" && newPlaintextSecret && (
    <div className="mt-3 rounded-lg bg-success/10 border border-success/30 p-4">
      <p className="text-xs text-success font-medium mb-2">
        <i className="bi bi-check-circle mr-1" />
        Новый секрет сгенерирован. Сохрани — больше не покажем.
      </p>
      <div className="flex gap-2">
        <input
          className="input flex-1 font-mono text-xs"
          value={newPlaintextSecret}
          readOnly
        />
        <button type="button" className="btn-secondary shrink-0"
                onClick={handleCopySecret}
                title="Скопировать">
          <i className={copied ? "bi bi-check2 text-success" : "bi bi-clipboard"} />
        </button>
      </div>
      {copied && <p className="text-xs text-success mt-1">Скопировано в буфер</p>}
    </div>
  )}

  {/* Error */}
  {rotateError && (
    <p className="text-xs text-danger mt-1">{rotateError}</p>
  )}
</div>
```

**Новые state-переменные** в `CreateWebhookModal`:
- `rotateStep: "idle" | "confirm" | "loading" | "revealed"` — дефолт `"idle"`
- `newPlaintextSecret: string | null`
- `copied: boolean`
- `rotateError: string | null`

**Функция `handleRotateSecret`:**
1. `setRotateStep("loading")`
2. `POST /api/webhooks/{webhook.id}/regenerate-secret`
3. Success → `setNewPlaintextSecret(response.plaintext_secret)` + `setRotateStep("revealed")`
4. Error → `setRotateStep("idle")` + `setRotateError("Не удалось обновить секрет")`

Сброс при закрытии: `useEffect` на `open` → `setRotateStep("idle")`, `setNewPlaintextSecret(null)`.

### API контракт

```
POST /api/webhooks/{id}/regenerate-secret
→ { plaintext_secret: string }
```

### Тексты (RU)

| Элемент | Текст |
|---|---|
| Кнопка | `Сгенерировать новый секрет` |
| Confirm title | `Старый секрет перестанет работать` |
| Confirm body | `Все подписчики получат ошибку HMAC при следующем событии, пока не обновят секрет на своей стороне.` |
| Confirm cancel | `Отмена` |
| Confirm apply | `Да, сгенерировать` |
| Loading | `Генерируем…` |
| Success label | `Новый секрет сгенерирован. Сохрани — больше не покажем.` |
| Copy button tooltip | `Скопировать` |
| Copied feedback | `Скопировано в буфер` |
| Error | `Не удалось обновить секрет` |
| Section label | `Секрет HMAC` |

---

## 2. Webhook Retry UI

**Зачем:** пользователь может повторить упавший automation run прямо из таблицы `/admin/automation-runs` без SQL-доступа.

**Где в коде:**
- Страница (изменить): `apps/web/src/app/(app)/admin/automation-runs/page.tsx`
- Компонент (новый): `apps/web/src/components/Automations/RetryRunButton.tsx`

### Wireframe

```
┌──────────────────────────────────────────────────────────────────────────┐
│ Таблица запусков                                                          │
├─────────────┬────────────┬────────────┬─────────────┬────────────────────┤
│ Когда       │ Автоматиз. │ Цель       │ Статус      │ Сообщение     │ ↗ │
├─────────────┼────────────┼────────────┼─────────────┼────────────────────┤
│ 01.06 14:20 │ Напомните  │ deal #123  │ [✓ success] │ —             │ ↗ │
│ 01.06 12:10 │ Webhook X  │ deal #456  │ [✗ failed]  │ timeout 30s   │ ↗ │
│             │            │            │             │      [↺ Retry]│   │
│ 01.06 11:05 │ Webhook Y  │ lead #89   │ [✗ failed]  │ 502 Bad Gate  │ ↗ │
│             │            │            │             │      [↺ Retry]│   │
└─────────────┴────────────┴────────────┴─────────────┴────────────────────┘
```

Кнопка Retry — вторая строка под строкой `failed`, прижата вправо к колонке сообщения. Альтернатива (чище): добавить отдельную колонку действий справа и показывать `btn-ghost` с иконкой только для `failed`.

**Выбранный подход:** отдельная ячейка "Действие" в существующей крайней правой колонке `<td>`. Сейчас там `bi-arrows-angle-expand`. Заменить на `flex gap-2` — иконка детали + кнопка retry (только для failed).

### Дизайн

В строке таблицы `list.map((r) => ...)` последняя `<td>`:

```
<td className="px-4 py-3 text-right">
  <div className="flex items-center justify-end gap-2">
    {r.status === "failed" && (
      <RetryRunButton
        runId={r.id}
        onRetried={() => void mutate()}
      />
    )}
    <button
      className="btn-ghost text-primary p-1"
      title="Подробности"
      onClick={() => setSelectedRun(r)}
    >
      <i className="bi bi-arrows-angle-expand" />
    </button>
  </div>
</td>
```

**Компонент `RetryRunButton`:**

```
interface Props {
  runId: number;
  onRetried: () => void;
}

// state: idle | loading | done | error
```

- `idle`: `btn-ghost` `bi-arrow-clockwise text-warning` + tooltip `"Повторить"`
- `loading`: `disabled` + `bi-arrow-clockwise animate-spin`
- `done`: иконка `bi-check2 text-success` на 2 секунды → обратно `idle` + вызов `onRetried()`
- `error`: иконка `bi-x-circle text-danger` + title c текстом ошибки на 3 секунды → `idle`

После `onRetried()` страница делает `mutate()` (SWR) — строка обновит статус до `pending`.

### State machine

| Состояние | Описание | UI |
|---|---|---|
| `idle` | Ожидание | `btn-ghost bi-arrow-clockwise text-warning` |
| `loading` | POST в процессе | `disabled animate-spin` |
| `done` | Успешно | `bi-check2 text-success` 2 сек → reset + `onRetried()` |
| `error` | Ошибка | `bi-x-circle text-danger` с title сообщения 3 сек → reset |

### API контракт

```
POST /api/automation-runs/{run_id}/retry
→ { status: "pending" } | { detail: string }
```

Если backend endpoint ещё не реализован — отметить в открытых вопросах.

### Тексты (RU)

| Элемент | Текст |
|---|---|
| Кнопка tooltip | `Повторить` |
| Loading aria | `Запускаем повторно…` |
| Error title | `Не удалось запустить повторно` |

### Открытые вопросы

- Backend: `POST /api/automation-runs/{run_id}/retry` — нужна реализация (требуется backend-specialist).

---

## 3. Per-webhook Settings

**Зачем:** разные вебхуки имеют разный SLA — одному нужно 3 попытки за 30 секунд, другому 10 попыток за час. Сейчас политика хардкодом.

**Где в коде:**
- Компонент (изменить): `apps/web/src/components/Webhooks/CreateWebhookModal.tsx`

### Wireframe

```
┌──────────────────────────────────────────────────────────┐
│ Modal: Редактировать вебхук                              │
├──────────────────────────────────────────────────────────┤
│ ... (существующие поля) ...                              │
│                                                          │
│ ─────────── Настройки доставки ───────────               │
│                                                          │
│ Макс. попыток *                                          │
│ [   5  ▲▼]                                               │
│ Сколько раз пытаться доставить при ошибке (1–20)         │
│                                                          │
│ Задержка между попытками (сек) *                         │
│ [  60  ▲▼]                                               │
│ Пауза перед следующей попыткой (10–3600)                 │
│                                                          │
│ Timeout запроса (сек) *                                  │
│ [  10  ▲▼]                                               │
│ Время ожидания ответа от сервера (1–60)                  │
│                                                          │
│ [btn-ghost: Отмена]              [btn-primary: Сохранить]│
└──────────────────────────────────────────────────────────┘
```

### Дизайн секции

Добавить в `CreateWebhookModal` — только в режиме `isEdit`, после существующего поля "Дополнительные заголовки" и блока "Секрет HMAC":

```
{isEdit && (
  <div className="border-t border-gray-100 pt-4 mt-2">
    <p className="text-sm font-medium text-gray-700 mb-3">Настройки доставки</p>
    <div className="grid grid-cols-3 gap-4">

      <div>
        <label className="label">
          Макс. попыток <span className="text-danger">*</span>
        </label>
        <input
          className="input"
          type="number"
          min={1}
          max={20}
          value={maxRetries}
          onChange={(e) => setMaxRetries(e.target.value)}
        />
        {maxRetriesError && (
          <p className="text-xs text-danger mt-1">{maxRetriesError}</p>
        )}
        <p className="text-xs text-gray-400 mt-1">Сколько раз пытаться доставить при ошибке (1–20)</p>
      </div>

      <div>
        <label className="label">
          Задержка между попытками (сек) <span className="text-danger">*</span>
        </label>
        <input
          className="input"
          type="number"
          min={10}
          max={3600}
          value={retryDelay}
          onChange={(e) => setRetryDelay(e.target.value)}
        />
        {retryDelayError && (
          <p className="text-xs text-danger mt-1">{retryDelayError}</p>
        )}
        <p className="text-xs text-gray-400 mt-1">Пауза перед следующей попыткой (10–3600)</p>
      </div>

      <div>
        <label className="label">
          Timeout запроса (сек) <span className="text-danger">*</span>
        </label>
        <input
          className="input"
          type="number"
          min={1}
          max={60}
          value={timeout}
          onChange={(e) => setTimeout_(e.target.value)}
        />
        {timeoutError && (
          <p className="text-xs text-danger mt-1">{timeoutError}</p>
        )}
        <p className="text-xs text-gray-400 mt-1">Время ожидания ответа от сервера (1–60)</p>
      </div>

    </div>
  </div>
)}
```

**Новые state-переменные:**
- `maxRetries: string` — инициализировать из `webhook.max_retries ?? 5`
- `retryDelay: string` — из `webhook.retry_delay_seconds ?? 60`
- `timeout_: string` — из `webhook.timeout_seconds ?? 10`
- `maxRetriesError / retryDelayError / timeoutError: string | null`

**Инициализация** в `useEffect` на `[open, webhook]` (в существующем блоке):

```js
setMaxRetries(String(webhook.max_retries ?? 5));
setRetryDelay(String(webhook.retry_delay_seconds ?? 60));
setTimeout_(String(webhook.timeout_seconds ?? 10));
```

**Валидация перед submit (добавить в `handleSubmit`):**

```js
const mr = Number(maxRetries);
if (!Number.isInteger(mr) || mr < 1 || mr > 20) {
  setMaxRetriesError("Укажи число от 1 до 20");
  return;
}
const rd = Number(retryDelay);
if (!Number.isInteger(rd) || rd < 10 || rd > 3600) {
  setRetryDelayError("Укажи число от 10 до 3600");
  return;
}
const to = Number(timeout_);
if (!Number.isInteger(to) || to < 1 || to > 60) {
  setTimeoutError("Укажи число от 1 до 60");
  return;
}
```

**Добавить в тело PATCH-запроса (isEdit ветка):**

```js
max_retries: mr,
retry_delay_seconds: rd,
timeout_seconds: to,
```

### State machine

| Состояние | Описание |
|---|---|
| Normal | Поля с числовыми значениями по умолчанию |
| Validation error | Inline `text-danger text-xs` под полем с ошибкой |
| Submitting | Кнопка `Сохранить` disabled + текст `Сохраняем…` |

### API контракт

```
PATCH /api/webhooks/{id}
Body: {
  name, url, event_subscriptions, headers, is_active,
  max_retries: int,
  retry_delay_seconds: int,
  timeout_seconds: int
}
```

Если поля `max_retries / retry_delay_seconds / timeout_seconds` ещё не в модели `Webhook` и схеме `WebhookUpdate` — требуется backend-specialist.

### Тексты (RU)

| Элемент | Текст |
|---|---|
| Секция заголовок | `Настройки доставки` |
| Поле 1 label | `Макс. попыток` |
| Поле 1 help | `Сколько раз пытаться доставить при ошибке (1–20)` |
| Поле 1 error | `Укажи число от 1 до 20` |
| Поле 2 label | `Задержка между попытками (сек)` |
| Поле 2 help | `Пауза перед следующей попыткой (10–3600)` |
| Поле 2 error | `Укажи число от 10 до 3600` |
| Поле 3 label | `Timeout запроса (сек)` |
| Поле 3 help | `Время ожидания ответа от сервера (1–60)` |
| Поле 3 error | `Укажи число от 1 до 60` |

### Открытые вопросы

- Backend: добавить поля `max_retries`, `retry_delay_seconds`, `timeout_seconds` в модель `Webhook`, Pydantic-схему `WebhookUpdate`, и применить в executor'е — требуется backend-specialist.

---

## 4. Merge 3+ дублей

**Зачем:** текущий `MergeModal` умеет объединять только 2 записи и явно показывает предупреждение для 3+. Нужно поддержать слияние группы из 3 и более записей через выбор мастера.

**Где в коде:**
- Компонент (изменить): `apps/web/src/components/Duplicates/MergeModal.tsx`
- Компонент (изменить): `apps/web/src/components/Duplicates/DuplicateGroupCard.tsx`

### Wireframe

**Шаг 1 — Выбор мастера:**

```
┌──────────────────────────────────────────────────────────┐
│ Modal: Объединение 3 дублей                          [×] │
├──────────────────────────────────────────────────────────┤
│ Шаг 1 из 2 · Выбери мастер-запись                        │
│ Остальные будут удалены, их данные перенесены в мастер   │
│                                                          │
│ ○ ┌───────────────────────────────────────────────────┐ │
│   │ MACRO Global Technologies                         │ │
│   │ Email: info@macroglobal.tech  Телефон: +7 727...  │ │
│   │ БИН: 180840019847                                 │ │
│   └───────────────────────────────────────────────────┘ │
│                                                          │
│ ○ ┌───────────────────────────────────────────────────┐ │
│   │ MACRO Global Tech                                 │ │
│   │ Email: —                      Телефон: +7 701...  │ │
│   │ БИН: 180840019847                                 │ │
│   └───────────────────────────────────────────────────┘ │
│                                                          │
│ ○ ┌───────────────────────────────────────────────────┐ │
│   │ Macro Global                                      │ │
│   │ Email: macro@mail.ru         Телефон: —           │ │
│   │ БИН: —                                            │ │
│   └───────────────────────────────────────────────────┘ │
│                                                          │
│ [btn-ghost: Отмена]          [btn-primary: Далее →]      │
│                              (неактивна без выбора)      │
└──────────────────────────────────────────────────────────┘
```

**Шаг 2 — Подтверждение:**

```
┌──────────────────────────────────────────────────────────┐
│ Modal: Объединение 3 дублей                          [×] │
├──────────────────────────────────────────────────────────┤
│ Шаг 2 из 2 · Подтвердить объединение                     │
│                                                          │
│ Останется (мастер):                                      │
│ ┌──────────────────────────────────────────────────────┐ │
│ │ ✓  MACRO Global Technologies                        │ │
│ │    Email: info@macroglobal.tech  БИН: 180840019847  │ │
│ └──────────────────────────────────────────────────────┘ │
│                                                          │
│ Будут удалены (2 записи):                                │
│ ┌──────────────────────────────────────────────────────┐ │
│ │ ✗  MACRO Global Tech                                │ │
│ │ ✗  Macro Global                                     │ │
│ └──────────────────────────────────────────────────────┘ │
│                                                          │
│ Связанные сделки, договоры и активности будут            │
│ перенесены на мастер-запись.                             │
│                                                          │
│ {error banner если есть}                                 │
│                                                          │
│ [btn-ghost: Отмена] [btn-secondary: ← Назад]             │
│                        [btn-primary danger: Объединить]  │
└──────────────────────────────────────────────────────────┘
```

### Дизайн компонентов

#### MergeModal — переработка

Сейчас компонент обрабатывает только 2 записи. Нужно:

1. Убрать ветку `group.records.length > 2` с "заглушкой" — вместо неё полноценный 2-шаговый флоу.
2. Для 2 записей сохранить существующий флоу выбора полей (он остаётся без изменений).
3. Для 3+ записей — новый флоу выбора мастера.

Логика условия:

```tsx
if (group.records.length >= 3) {
  // Новый multi-merge flow
  return <MultiMergeFlow group={group} onClose={onClose} onMerged={onMerged} />;
}
// Существующий 2-way merge flow (без изменений)
```

**Новый компонент `MultiMergeFlow` (inline в том же файле или отдельный файл):**

```tsx
// Props
interface MultiMergeFlowProps {
  group: DuplicateGroup;
  onClose: () => void;
  onMerged: () => void;
}

// State
type MergeStep = "select_master" | "confirm";

// step: MergeStep
// masterId: number | null
// merging: boolean
// error: string | null
```

**Шаг 1 (`select_master`):**

Список карточек записей с радио-кнопками. Карточка:

```tsx
<label
  key={r.id}
  className={`flex gap-3 p-3 rounded-lg border cursor-pointer transition-colors ${
    masterId === r.id
      ? "border-primary bg-primary/5"
      : "border-gray-200 hover:border-gray-300"
  }`}
>
  <input
    type="radio"
    name="master"
    value={r.id}
    checked={masterId === r.id}
    onChange={() => setMasterId(r.id)}
    className="mt-1 accent-primary shrink-0"
  />
  <div className="min-w-0">
    <div className="font-medium text-gray-900 truncate">{r.display_name}</div>
    <div className="text-xs text-gray-500 mt-0.5 space-x-3">
      {r.fields.email && <span>Email: {r.fields.email}</span>}
      {r.fields.phone && <span>Тел: {r.fields.phone}</span>}
      {r.fields.tax_id && <span>БИН: {r.fields.tax_id}</span>}
    </div>
  </div>
</label>
```

Footer шага 1:
```tsx
<>
  <button className="btn-ghost" onClick={onClose}>Отмена</button>
  <button
    className="btn-primary"
    disabled={masterId === null}
    onClick={() => setStep("confirm")}
  >
    Далее <i className="bi bi-arrow-right ml-1" />
  </button>
</>
```

**Шаг 2 (`confirm`):**

Мастер-запись — карточка с `bg-success/5 border-success/30`, иконка `bi-check-circle text-success`.  
Удаляемые — список с иконками `bi-x-circle text-danger`.

```tsx
// masterRecord = group.records.find(r => r.id === masterId)
// toDelete = group.records.filter(r => r.id !== masterId)
```

Кнопка Объединить:
```tsx
<button
  className="btn-primary"
  onClick={handleMultiMerge}
  disabled={merging}
>
  {merging
    ? <><i className="bi bi-arrow-clockwise animate-spin mr-2" />Объединяем…</>
    : <><i className="bi bi-union mr-2" />Объединить</>}
</button>
```

Кнопке добавить цвет опасности через дополнительный класс или `text-white bg-danger hover:bg-danger/90` вместо `btn-primary` — на усмотрение frontend, главное что действие деструктивное.

**Функция `handleMultiMerge`:**

```
POST /api/duplicates/merge
Body: {
  entity_type: group.entity,
  master_id: masterId,
  duplicate_ids: toDelete.map(r => r.id)
}
```

Success → `onMerged()` + `onClose()`  
Error → `setError("Не удалось объединить. Попробуй снова.")`

### State machine

| Шаг | Состояние | Переход |
|---|---|---|
| `select_master` | `masterId === null` | Кнопка "Далее" disabled |
| `select_master` | `masterId !== null` | Кнопка "Далее" активна → `setStep("confirm")` |
| `confirm` | idle | Кнопки Назад / Объединить |
| `confirm` | `merging` | Spinner, Объединить disabled |
| `confirm` | error | Inline banner `bg-danger/10 text-danger` |
| `confirm` | success | `onMerged()` + `onClose()` |

### API контракт

Расширение существующего endpoint:

```
POST /api/duplicates/merge
Body (новый вариант):
{
  entity_type: "counterparty" | "contact" | "company" | "lead",
  master_id: int,
  duplicate_ids: int[]   // массив ID для удаления (2+)
}
```

Под капотом backend делает цепочку `merge(master, dup[0]) → merge(master, dup[1]) → ...`.

Открытый вопрос — поддерживает ли текущий endpoint `master_id + duplicate_ids[]` или только `primary_id + secondary_id`? Если нет — требуется backend-specialist.

### Тексты (RU)

| Элемент | Текст |
|---|---|
| Modal title | `Объединение N дублей` (N = количество записей) |
| Шаг 1 subtitle | `Выбери мастер-запись` |
| Шаг 1 hint | `Остальные будут удалены, их данные перенесены в мастер` |
| Шаг 1 кнопка назад | `Отмена` |
| Шаг 1 кнопка вперёд | `Далее` |
| Шаг 2 subtitle | `Подтвердить объединение` |
| Шаг 2 останется | `Останется (мастер):` |
| Шаг 2 удалится | `Будут удалены (N записей):` |
| Шаг 2 предупреждение | `Связанные сделки, договоры и активности будут перенесены на мастер-запись.` |
| Шаг 2 кнопка отмена | `Отмена` |
| Шаг 2 кнопка назад | `Назад` |
| Шаг 2 кнопка применить | `Объединить` |
| Loading | `Объединяем…` |
| Error | `Не удалось объединить. Попробуй снова.` |
| Breadcrumb | `Шаг 1 из 2` / `Шаг 2 из 2` |

---

## 5. Auto-dedup Live Check

**Зачем:** пользователь видит предупреждение о возможном дубле прямо при вводе email/телефона/БИН — до submit формы, без перехода на страницу дублей.

**Где в коде:**
- Новый компонент (создать): `apps/web/src/components/Duplicates/DupCheckWarning.tsx`
- Новый хук (создать): `apps/web/src/hooks/useDupCheck.ts`
- Форма контрагента (изменить): `apps/web/src/app/(app)/counterparties/page.tsx` — блок создания (inline modal внутри страницы)
- Форма контакта (изменить): `apps/web/src/components/Contacts/ContactFormModal.tsx`
- Форма компании (изменить): `apps/web/src/components/Companies/CompanyFormModal.tsx` (или аналог — найти по коду)

### Wireframe

```
┌────────────────────────────────┐
│ Email *                        │
│ [info@macroglobal.tech_______] │ ← onBlur/debounce запускает проверку
│                                │
│ ┌──────────────────────────────┤
│ │ ⚠ Похоже на существующего:   │ ← DupCheckWarning (badge warning)
│ │   MACRO Global Tech (id 42)  │
│ │   [Открыть карточку ↗]       │
│ │   [×] Я знаю, продолжить     │
│ └──────────────────────────────┘
│
│ ──── если matches.length > 1:
│ ┌──────────────────────────────┐
│ │ ⚠ Похоже на существующего:   │
│ │   MACRO Global Tech (id 42)  │
│ │   и ещё 2 похожих → [показать│
│ │   все на странице Дублей]    │
│ │   [×] Я знаю, продолжить     │
│ └──────────────────────────────┘
```

### Хук `useDupCheck`

```typescript
// apps/web/src/hooks/useDupCheck.ts

interface DupMatch {
  id: number;
  display_name: string;
  entity_type: string;
}

interface UseDupCheckResult {
  matches: DupMatch[];
  checking: boolean;
  dismissed: boolean;
  dismiss: () => void;
  reset: () => void;
  check: (value: string) => void;
}

function useDupCheck(entityType: string, field: "email" | "phone" | "bin"): UseDupCheckResult
```

Логика хука:
- Хранит `dismissed: boolean` — пользователь нажал "Я знаю"
- При `check(value)`:
  - Если `value.trim().length < 3` или `dismissed` — не запрашивает
  - Устанавливает `checking = true`
  - `setTimeout` 500ms debounce (через `useRef` для хранения таймера)
  - `GET /api/duplicates/check?entity_type={entityType}&field={field}&value={value}`
  - Записывает `matches`
- `dismiss()` → `dismissed = true`, `matches = []`
- `reset()` → `dismissed = false`, `matches = []` — вызывать при закрытии формы

### Компонент `DupCheckWarning`

```typescript
// apps/web/src/components/Duplicates/DupCheckWarning.tsx

interface DupCheckWarningProps {
  matches: DupMatch[];
  checking: boolean;
  entityType: string;
  onDismiss: () => void;
}
```

Отображение:
- `checking` → ничего не показывать (не мешать вводу)
- `matches.length === 0` → `null`
- `matches.length >= 1`:

```tsx
<div className="mt-1.5 rounded-md bg-warning/10 border border-warning/30 px-3 py-2 text-sm">
  <div className="flex items-start justify-between gap-2">
    <div className="flex items-start gap-1.5">
      <i className="bi bi-exclamation-triangle text-warning mt-0.5 shrink-0" />
      <div>
        <span className="font-medium text-gray-800">Похоже на существующего: </span>
        <Link
          href={`/${entityType}s/${matches[0].id}`}
          target="_blank"
          className="text-primary underline underline-offset-2 hover:text-primary-light"
        >
          {matches[0].display_name}
        </Link>
        {matches.length > 1 && (
          <span className="text-gray-500">
            {" "}и ещё {matches.length - 1} похожих{" "}
            <Link href="/admin/duplicates" target="_blank"
                  className="text-primary underline underline-offset-2">
              → Дубли
            </Link>
          </span>
        )}
      </div>
    </div>
    <button
      type="button"
      className="text-gray-400 hover:text-gray-600 shrink-0 -mt-0.5"
      onClick={onDismiss}
      title="Закрыть предупреждение"
    >
      <i className="bi bi-x-lg text-xs" />
    </button>
  </div>
  <button
    type="button"
    className="mt-1.5 text-xs text-gray-500 hover:text-gray-700 underline underline-offset-1"
    onClick={onDismiss}
  >
    Я знаю, продолжить
  </button>
</div>
```

### Интеграция в формы

Паттерн добавления в форму (на примере поля Email):

```tsx
// В компоненте формы:
const emailDup = useDupCheck("counterparty", "email");
const phoneDup = useDupCheck("counterparty", "phone");
const binDup = useDupCheck("counterparty", "bin");

// При закрытии формы:
emailDup.reset(); phoneDup.reset(); binDup.reset();

// Поле Email:
<div>
  <label className="label">Email</label>
  <input
    className="input"
    type="email"
    value={email}
    onChange={(e) => { setEmail(e.target.value); emailDup.reset(); }}
    onBlur={(e) => emailDup.check(e.target.value)}
  />
  <DupCheckWarning
    matches={emailDup.matches}
    checking={emailDup.checking}
    entityType="counterparty"
    onDismiss={emailDup.dismiss}
  />
</div>
```

Аналогично для `phone` и `bin` (только в форме контрагента, где есть БИН).

### State machine

| Состояние | Условие | UI |
|---|---|---|
| Пустое поле / < 3 символов | `value.length < 3` | Ничего не показываем |
| Проверка | `checking === true` | Ничего не показываем |
| Нет совпадений | `matches.length === 0` | Ничего не показываем |
| Есть совпадение | `matches.length >= 1` | `DupCheckWarning` |
| Отклонено | `dismissed === true` | Ничего не показываем |

### API контракт

```
GET /api/duplicates/check?entity_type=counterparty&field=email&value=foo@bar.com
→ {
    matches: [
      { id: int, display_name: string, entity_type: string }
    ]
  }
```

Если endpoint не реализован — требуется backend-specialist (упомянуто в Obsidian: расширить существующий `POST /duplicates/scan`).

### Тексты (RU)

| Элемент | Текст |
|---|---|
| Warning prefix | `Похоже на существующего:` |
| Link suffix 1 совпадение | (только имя + ссылка) |
| Link suffix N совпадений | `и ещё N похожих → Дубли` |
| Dismiss link | `Я знаю, продолжить` |
| Close button title | `Закрыть предупреждение` |

### Открытые вопросы

- Backend: `GET /api/duplicates/check?entity_type&field&value` — требуется реализация.
- Для контрагентов поле называется `tax_id` в БД, но параметр `bin` в URL. Нужно согласовать с backend.
- Проверить, есть ли `ContactFormModal` и `CompanyFormModal` — в текущем коде форм создания контактов/компаний может не быть отдельных модалок, создание может быть inline на странице.

---

## 6. Quiz Randomize Checkbox

**Зачем:** при `randomize_questions = true` каждая попытка ученика получает вопросы в случайном порядке — увеличивает честность оценки.

**Где в коде:**
- Компонент (изменить): `apps/web/src/components/Onboarding/Admin/LessonEditorDrawer.tsx`

### Wireframe

```
┌──────────────────────────────────────────────────────────┐
│ Drawer: Редактировать урок [Квиз]                        │
├──────────────────────────────────────────────────────────┤
│ Название *                                               │
│ [_________________________]                              │
│                                                          │
│ Длительность (мин)                                       │
│ [_______]                                                │
│                                                          │
│ ── Настройки квиза ──                                    │
│                                                          │
│ ☐ Перемешивать вопросы при каждой попытке               │
│   Каждая попытка ученика будет получать                  │
│   вопросы в случайном порядке                            │
│                                                          │
│ ── Вопросы (QuizQuestionsBuilder) ──                     │
│ ...                                                      │
└──────────────────────────────────────────────────────────┘
```

### Дизайн

Добавить в `LessonEditorDrawer` в ветке `kind === "quiz"` — перед `<QuizQuestionsBuilder />`:

```tsx
{kind === "quiz" && (
  <div className="space-y-4">
    {/* Настройки квиза */}
    <div className="card bg-gray-50 p-3">
      <label className="flex items-start gap-2.5 cursor-pointer select-none">
        <input
          type="checkbox"
          checked={form.randomize_questions ?? false}
          onChange={(e) =>
            setForm((f) => ({ ...f, randomize_questions: e.target.checked }))
          }
          className="mt-0.5 rounded accent-primary shrink-0"
        />
        <div>
          <span className="text-sm font-medium text-gray-800">
            Перемешивать вопросы при каждой попытке
          </span>
          <p className="text-xs text-gray-500 mt-0.5">
            Каждая попытка ученика будет получать вопросы в случайном порядке
          </p>
        </div>
      </label>
    </div>

    {/* Вопросы */}
    <QuizQuestionsBuilder
      questions={form.questions}
      onChange={(questions) => { setForm((f) => ({ ...f, questions })); setIsDirty(true); }}
    />
  </div>
)}
```

**Изменения в типах:**

В интерфейсе `LessonForm` добавить поле:
```typescript
randomize_questions: boolean;
```

В инициализации `useEffect`:
```typescript
randomize_questions: lesson.randomize_questions ?? false,
```

В дефолтном состоянии:
```typescript
randomize_questions: false,
```

В `onSave` payload:
```typescript
const payload: Partial<CourseLesson> = {
  title: form.title.trim(),
  duration_min: form.duration_min ? Number(form.duration_min) : null,
  // ... existing fields ...
  randomize_questions: form.randomize_questions,  // ДОБАВИТЬ
};
```

### State machine

| Состояние | Описание |
|---|---|
| `false` (дефолт) | Чекбокс не отмечен |
| `true` | Чекбокс отмечен, при сохранении передаётся `randomize_questions: true` |

### API контракт

```
PATCH /api/admin/onboarding/lessons/{id}
Body: {
  ...existing fields...,
  randomize_questions: boolean
}
```

Поле `randomize_questions: bool` должно быть в модели `CourseLesson` и схеме `LessonUpdate`. Если нет — требуется backend-specialist.

### Тексты (RU)

| Элемент | Текст |
|---|---|
| Чекбокс label | `Перемешивать вопросы при каждой попытке` |
| Чекбокс help | `Каждая попытка ученика будет получать вопросы в случайном порядке` |

### Открытые вопросы

- Backend: поле `randomize_questions: bool` в `CourseLesson` и соответствующая логика в endpoint `GET /admin/onboarding/lessons/{id}/attempt` (shuffle при `true`) — требуется backend-specialist.

---

## 7. DnD Reorder везде

**Зачем:** устранить UX-долг — в 8 местах стоят кнопки ↑/↓ которые неудобны при 10+ элементах. Drag-and-drop — стандарт для подобных конструкторов.

**Где в коде:**

| # | Место | Файл |
|---|---|---|
| 1 | Course modules | `src/components/Onboarding/Admin/CourseStructureBuilder.tsx` |
| 2 | Lessons внутри модуля | `src/components/Onboarding/Admin/CourseStructureBuilder.tsx` |
| 3 | Quiz questions | `src/components/Onboarding/Admin/QuizQuestionsBuilder.tsx` |
| 4 | Content blocks | `src/components/Onboarding/Admin/ContentBlocksBuilder.tsx` |
| 5 | Sequence steps | `src/components/Sequences/StepsBuilder.tsx` |
| 6 | Saved filters в Sidebar | `src/components/Segments/SavedFilterPin.tsx` + `src/components/Sidebar.tsx` |
| 7 | Custom fields | `src/app/(app)/admin/custom-fields/page.tsx` |
| 8 | Pipeline stages | `src/app/(app)/admin/pipelines/page.tsx` |

**Библиотека:** `@dnd-kit/core` + `@dnd-kit/sortable` (уже установлена, используется в Kanban).

### Общий паттерн drag-handle item

Каждый перетаскиваемый элемент должен иметь слева drag-handle:

```tsx
// Обёртка пункта (useSortable из @dnd-kit/sortable)
function SortableItem({ id, children }: { id: number | string; children: React.ReactNode }) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } =
    useSortable({ id });

  const style = {
    transform: CSS.Transform.toString(transform),
    transition,
    opacity: isDragging ? 0.5 : 1,
    zIndex: isDragging ? 50 : "auto",
  };

  return (
    <div ref={setNodeRef} style={style} className={isDragging ? "shadow-lg" : ""}>
      <div className="flex items-center gap-2">
        {/* Drag handle */}
        <button
          {...attributes}
          {...listeners}
          className="cursor-grab active:cursor-grabbing p-1 text-gray-400 hover:text-gray-600 shrink-0"
          tabIndex={-1}
          title="Перетащить"
        >
          <i className="bi bi-grip-vertical" />
        </button>
        {/* Содержимое */}
        <div className="flex-1 min-w-0">{children}</div>
      </div>
    </div>
  );
}
```

### Общий паттерн DnD-контейнера

```tsx
import { DndContext, closestCenter, DragEndEvent } from "@dnd-kit/core";
import { SortableContext, verticalListSortingStrategy, arrayMove } from "@dnd-kit/sortable";

function handleDragEnd(event: DragEndEvent) {
  const { active, over } = event;
  if (!over || active.id === over.id) return;

  const oldIdx = items.findIndex((i) => i.id === active.id);
  const newIdx = items.findIndex((i) => i.id === over.id);
  const reordered = arrayMove(items, oldIdx, newIdx);

  // 1. Оптимистично обновить UI
  setItems(reordered);

  // 2. PATCH /api/{entity}/reorder
  void api(`/{entity}/reorder`, {
    method: "PATCH",
    body: reordered.map((item, idx) => ({ id: item.id, sort_order: idx })),
  }).catch(() => {
    // Откатить при ошибке
    setItems(items);
  });
}

<DndContext collisionDetection={closestCenter} onDragEnd={handleDragEnd}>
  <SortableContext items={items.map((i) => i.id)} strategy={verticalListSortingStrategy}>
    {items.map((item) => (
      <SortableItem key={item.id} id={item.id}>
        {/* содержимое */}
      </SortableItem>
    ))}
  </SortableContext>
</DndContext>
```

### Детали по каждому месту

#### 7.1 & 7.2 — `CourseStructureBuilder` (модули + уроки)

**Изменить:** `apps/web/src/components/Onboarding/Admin/CourseStructureBuilder.tsx`

- Модули: обернуть список модулей в `DndContext + SortableContext`. Drag handle — у каждого `div` модуля, слева от названия.
- Уроки: у каждого модуля свой `DndContext` для уроков внутри. Drag handle — у каждой строки урока, слева.
- Убрать существующие кнопки `handleMoveModule` / `handleMoveLesson` (↑ ↓). Функции удалить.
- `PATCH /api/admin/onboarding/modules/reorder` — массив `[{id, order_index}]`
- `PATCH /api/admin/onboarding/lessons/reorder` — массив `[{id, order_index}]`

#### 7.3 — `QuizQuestionsBuilder` (вопросы квиза)

**Изменить:** `apps/web/src/components/Onboarding/Admin/QuizQuestionsBuilder.tsx`

- Drag handle у каждой карточки вопроса.
- При drag-end: обновить массив `editables` через `arrayMove`, вызвать `onChange(editables.map(editableToQuestion))`.
- Backend reorder не нужен — вопросы сохраняются пакетом при сохранении урока. Порядок в `order_index` пересчитывается при `onChange`.

#### 7.4 — `ContentBlocksBuilder` (блоки контента)

**Изменить:** `apps/web/src/components/Onboarding/Admin/ContentBlocksBuilder.tsx`

- Аналогично квизу — drag-end обновляет локальный массив, `onChange` передаёт вверх.
- Backend reorder не нужен — пакетное сохранение при сохранении урока.

#### 7.5 — `StepsBuilder` (шаги последовательности)

**Изменить:** `apps/web/src/components/Sequences/StepsBuilder.tsx`

- Drag handle у каждой карточки шага.
- Drag-end обновляет `steps` через `arrayMove`, вызывает `onChange(reordered)`.
- Reorder сохраняется при сохранении всей последовательности (родительский компонент).

#### 7.6 — Saved Filters в Sidebar

**Изменить:** `apps/web/src/components/Sidebar.tsx` (или `SavedFilterPin.tsx` — найти где рендерится список)

- Drag handle у каждого сохранённого фильтра в Sidebar.
- После drag-end: `PATCH /api/segments/reorder` body `[{id, sort_order}]`.
- Если saved filters отображаются не в Sidebar а в другом месте — применить там.

#### 7.7 — Custom Fields

**Изменить:** `apps/web/src/app/(app)/admin/custom-fields/page.tsx`

- Drag handle у каждой строки в таблице кастомных полей.
- После drag-end: `PATCH /api/custom-field-defs/reorder` body `[{id, sort_order}]`.
- Оптимистичное обновление — откат при ошибке.

#### 7.8 — Pipeline Stages

**Изменить:** `apps/web/src/app/(app)/admin/pipelines/page.tsx`

- Drag handle у каждой строки этапа в `stages.map(...)`.
- После drag-end: `PATCH /api/pipelines/{pid}/stages/reorder` body `[{id, sort_order}]`.
- Оптимистичное обновление локального SWR data.

### Взаимодействие

| Элемент | Действие | Результат |
|---|---|---|
| Drag handle `bi-grip-vertical` | `mousedown` / `touchstart` | cursor: `grab` |
| Активный drag | `dragging` | `opacity-50 shadow-lg` на dragged элементе, остальные нормальные |
| Drop | `DragEndEvent` | `arrayMove` → оптимистичный UI update → PATCH bulk-reorder |
| Ошибка PATCH | `catch` | Откат к предыдущему порядку (вернуть старый массив в state) |

### API контракты (bulk-reorder)

```
PATCH /api/admin/onboarding/modules/reorder
PATCH /api/admin/onboarding/lessons/reorder
PATCH /api/segments/reorder
PATCH /api/custom-field-defs/reorder
PATCH /api/pipelines/{id}/stages/reorder

Body для всех: [{ id: int, sort_order: int }]
→ 200 OK | 4xx error
```

Вопросы/блоки/шаги last — не нужны отдельные reorder endpoints (сохраняются пакетно).

### Тексты (RU)

| Элемент | Текст |
|---|---|
| Drag handle title | `Перетащить` |
| (ошибок нет — оптимистичный UI, тихий откат) | — |

### Открытые вопросы

- Backend: нужно проверить, реализованы ли bulk-reorder endpoints для:
  - `PATCH /api/admin/onboarding/modules/reorder`
  - `PATCH /api/admin/onboarding/lessons/reorder`
  - `PATCH /api/segments/reorder`
  - `PATCH /api/custom-field-defs/reorder`
  - `PATCH /api/pipelines/{id}/stages/reorder`
  
  Если нет — требуется backend-specialist для каждого.
- `SavedFilter.sort_order` — проверить есть ли поле в модели и возвращается ли в API.
- DnD для модулей и уроков — две вложенных DnD-зоны. Нужно убедиться что `@dnd-kit` не конфликтует (использовать разные `DndContext` для каждого уровня).

---

## Компоненты: создать / изменить

### Создать (новые файлы)

| Файл | Назначение |
|---|---|
| `apps/web/src/components/Automations/RetryRunButton.tsx` | Кнопка retry для failed automation run (фича 2) |
| `apps/web/src/components/Duplicates/DupCheckWarning.tsx` | Warning banner под полем формы при нахождении дубля (фича 5) |
| `apps/web/src/hooks/useDupCheck.ts` | Хук debounce-проверки дубля по полю (фича 5) |
| `apps/web/src/components/Duplicates/MultiMergeFlow.tsx` | 2-шаговый флоу слияния 3+ записей (фича 4, можно inline в MergeModal) |

### Изменить (существующие файлы)

| Файл | Что меняется |
|---|---|
| `apps/web/src/components/Webhooks/CreateWebhookModal.tsx` | + Rotate Secret UI (фича 1) + Delivery Settings секция (фича 3) |
| `apps/web/src/app/(app)/admin/automation-runs/page.tsx` | + `RetryRunButton` для `status === "failed"` строк (фича 2) |
| `apps/web/src/components/Duplicates/MergeModal.tsx` | Заменить заглушку 3+ на `MultiMergeFlow` (фича 4) |
| `apps/web/src/app/(app)/counterparties/page.tsx` | + `DupCheckWarning` + `useDupCheck` на полях email/phone/bin (фича 5) |
| `apps/web/src/components/Contacts/ContactFormModal.tsx` | + `DupCheckWarning` + `useDupCheck` на полях email/phone (фича 5) |
| `apps/web/src/components/Companies/CompanyFormModal.tsx` | + `DupCheckWarning` + `useDupCheck` на полях email/phone (фича 5) |
| `apps/web/src/components/Onboarding/Admin/LessonEditorDrawer.tsx` | + чекбокс `randomize_questions` в quiz-ветке (фича 6) |
| `apps/web/src/components/Onboarding/Admin/CourseStructureBuilder.tsx` | Заменить ↑↓ кнопки на DnD для модулей и уроков (фича 7.1 / 7.2) |
| `apps/web/src/components/Onboarding/Admin/QuizQuestionsBuilder.tsx` | + drag handle, DnD для вопросов (фича 7.3) |
| `apps/web/src/components/Onboarding/Admin/ContentBlocksBuilder.tsx` | + drag handle, DnD для блоков (фича 7.4) |
| `apps/web/src/components/Sequences/StepsBuilder.tsx` | + drag handle, DnD для шагов (фича 7.5) |
| `apps/web/src/components/Sidebar.tsx` | + drag handle, DnD для saved filters (фича 7.6) |
| `apps/web/src/app/(app)/admin/custom-fields/page.tsx` | + drag handle, DnD для custom field defs (фича 7.7) |
| `apps/web/src/app/(app)/admin/pipelines/page.tsx` | + drag handle, DnD для pipeline stages (фича 7.8) |

---

## Сводная таблица backend-зависимостей

| Фича | Endpoint | Статус |
|---|---|---|
| 1 | `POST /webhooks/{id}/regenerate-secret` | Готов (Эпик 11) |
| 2 | `POST /automation-runs/{id}/retry` | Требует backend |
| 3 | `PATCH /webhooks/{id}` с новыми полями | Требует backend (поля в модели) |
| 4 | `POST /duplicates/merge` с `master_id + duplicate_ids[]` | Уточнить у backend |
| 5 | `GET /duplicates/check?entity_type&field&value` | Требует backend |
| 6 | `PATCH /lessons/{id}` с `randomize_questions` | Требует backend (поле в модели) |
| 7.1/7.2 | `PATCH /admin/onboarding/modules/reorder` + `/lessons/reorder` | Требует backend |
| 7.5 | `PATCH /segments/reorder` | Уточнить у backend |
| 7.7 | `PATCH /custom-field-defs/reorder` | Уточнить у backend |
| 7.8 | `PATCH /pipelines/{id}/stages/reorder` | Уточнить у backend |
