# ТЗ: Эпик 11.3 — API Tokens + Webhooks UI

**Зачем:** дать admin/director управлять API tokens (для интеграций 1C, Bitrix, custom scripts) и outbound webhooks (Zapier, Make.com, кастомные системы) — без программиста на каждую интеграцию.

**Backend готов (integration-specialist делает параллельно)**: модели APIToken/Webhook/WebhookDelivery, миграция 0030, endpoints под `/api/api-tokens`, `/api/webhooks`, `/api/webhooks/{id}/deliveries`, `/api/webhooks/{id}/test`, `/api/webhook-deliveries/{id}/retry`, dispatcher + cron + HMAC + retry (exponential backoff).

## TypeScript типы (добавить в `lib/types.ts`)

```ts
export type APITokenScope =
  | "*"
  | "read:leads"   | "write:leads"
  | "read:deals"   | "write:deals"
  | "read:contacts"  | "write:contacts"
  | "read:companies" | "write:companies"
  | "read:counterparties" | "write:counterparties"
  | "read:contracts"    | "write:contracts"
  | "read:subscriptions" | "write:subscriptions"
  | "inbox:write";

export interface APIToken {
  id: number;
  user_id: number;
  name: string;
  scopes: APITokenScope[];
  expires_at: string | null;
  last_used_at: string | null;
  last_used_ip: string | null;
  is_active: boolean;
  created_at: string;
  revoked_at: string | null;
}

export interface APITokenCreateResponse extends APIToken {
  plaintext_token: string;
}

export type WebhookEvent =
  | "lead.created"   | "lead.converted"
  | "deal.created"   | "deal.stage_changed" | "deal.won" | "deal.lost"
  | "contract.signed" | "contract.created"
  | "subscription.created" | "subscription.health_changed"
  | "counterparty.created"
  | "*";

export type WebhookDeliveryStatus = "pending" | "success" | "failed" | "retrying";

export interface Webhook {
  id: number;
  name: string;
  url: string;
  event_subscriptions: WebhookEvent[];
  is_active: boolean;
  headers: Record<string, string> | null;
  created_at: string;
  updated_at: string;
}

export interface WebhookCreateResponse extends Webhook {
  plaintext_secret: string;
}

export interface WebhookDelivery {
  id: number;
  webhook_id: number;
  event: string;
  payload: Record<string, unknown>;
  status: WebhookDeliveryStatus;
  attempt: number;
  next_retry_at: string | null;
  last_http_code: number | null;
  last_error: string | null;
  last_response_body: string | null;
  created_at: string;
  finished_at: string | null;
}
```

## Файлы

| Путь | Назначение |
|---|---|
| `app/(app)/admin/api-tokens/page.tsx` | Страница CRUD токенов |
| `app/(app)/admin/webhooks/page.tsx` | Страница CRUD webhooks + tabs |
| `components/ApiTokens/ApiTokensTable.tsx` | Таблица токенов |
| `components/ApiTokens/CreateApiTokenModal.tsx` | Модалка создания + ScopeCheckboxGrid |
| `components/ApiTokens/TokenRevealModal.tsx` | Показ plaintext_token |
| `components/ApiTokens/ScopeBadge.tsx` | Chip для scope |
| `components/Webhooks/WebhooksTable.tsx` | Таблица webhooks |
| `components/Webhooks/DeliveriesTab.tsx` | Tab с filters + table |
| `components/Webhooks/CreateWebhookModal.tsx` | Modal create/edit + EventCheckboxGrid |
| `components/Webhooks/WebhookSecretModal.tsx` | Показ plaintext_secret + Python пример |
| `components/Webhooks/DeliveryDetailModal.tsx` | Детали одной доставки |
| `components/Webhooks/EventBadge.tsx` | Chip для event |
| `components/Sidebar.tsx` | + 2 пункта в admin-секции |

## Sidebar

В `ADMIN_ITEMS` после «Интеграции»:
```ts
{ href: "/admin/api-tokens", icon: "bi-key-fill",       label: "API Токены",  roles: ["admin", "director"] },
{ href: "/admin/webhooks",   icon: "bi-broadcast-pin",  label: "Webhooks",    roles: ["admin", "director"] },
```

## API контракт (готов от integration-specialist)

| Метод | Путь |
|---|---|
| `GET` | `/api/api-tokens` |
| `POST` | `/api/api-tokens` → `APITokenCreateResponse` (plaintext только здесь) |
| `PATCH` | `/api/api-tokens/{id}/revoke` |
| `DELETE` | `/api/api-tokens/{id}` (admin only) |
| `GET` | `/api/webhooks` |
| `POST` | `/api/webhooks` → `WebhookCreateResponse` |
| `PATCH` | `/api/webhooks/{id}` |
| `DELETE` | `/api/webhooks/{id}` |
| `POST` | `/api/webhooks/{id}/test` body `{ event }` |
| `GET` | `/api/webhooks/{id}/deliveries?status=&limit=&offset=` |
| `GET` | `/api/webhook-deliveries?webhook_id=&status=&event=&limit=&offset=` (глобальный список) |
| `POST` | `/api/webhook-deliveries/{id}/retry` |

## Страница /admin/api-tokens

### Таблица колонки
Название | Доступы (chips) | Создан | Last used (relative + IP) | Истекает | Статус | Действия

**ScopeBadge** — цвет по типу:
- `*` → `bg-primary text-white` + «Полный доступ»
- `read:*` → `bg-info/10 text-info` + scope без префикса
- `write:*` → `bg-warning/10 text-warning` + scope без префикса
- max 3 chips + `+N ещё`

**Статус-chip**:
- Активен → `bg-success/10 text-success`
- Истёк (`expires_at < now`) → `bg-warning/10 text-warning`
- Отозван (`revoked_at != null`) → `bg-danger/10 text-danger` + дата
- Неактивен → `bg-gray-100 text-gray-500`

**Действия**:
- Активный → [Отозвать] (PATCH `/revoke`, без confirm — обратимо)
- Истёкший/отозванный + admin → [Удалить] (DELETE с confirm)

### CreateApiTokenModal
- Название (input, required)
- Доступы — ScopeCheckboxGrid (категории: Лиды/Сделки/Контакты/Компании/Контрагенты/Договоры/Подписки/Прочее, read+write в одной строке). Scope `*` — только для admin (RoleGate).
- При выборе `*` — disable других checkboxes
- Действует до (date, optional, hint: «Без даты — токен бессрочный»)

### TokenRevealModal (модалка после создания)
- bi-shield-check-fill (success, 4xl)
- Caption: «Сохрани токен сейчас — больше он нигде не появится»
- Mono-block с plaintext + кнопка «Скопировать» (на 2 сек → «Скопировано!»)
- Пример curl: `curl -H "Authorization: Bearer mc_..." https://contracts.macroglobal.tech/api/leads`
- Закрытие ТОЛЬКО через кнопку «Сохранил, закрыть» (Escape/backdrop click заблокированы — пробросить `disableBackdropClose` prop в Modal или использовать isDirty workaround)

## Страница /admin/webhooks

### Tabs
[Webhooks] [Доставки]

### Tab Webhooks — таблица
Название | URL (truncate + title) | События (chips) | Статус | [Test][✎][🗑]

**EventBadge** — цвет по группе:
- `lead.*` → `bg-info/10 text-info`
- `deal.*` → `bg-primary/10 text-primary`
- `contract.*` → `bg-warning/10 text-warning`
- `subscription.*` → `bg-success/10 text-success`
- `counterparty.*` → `bg-gray-100 text-gray-600`
- `*` → `bg-primary text-white`

**Test кнопка**: `POST /webhooks/{id}/test { event: event_subscriptions[0] }`. Loading: spinner. Результат: inline chip 3 сек («OK 200» green / «Ошибка 500» red).

### Tab Доставки
Фильтры: select webhook (all/{name}) + select status + select event + кнопка Сбросить (visible при активных)

Таблица: Вебхук | Событие | Status | HTTP | Попытки | Создан | [Подробнее] [Retry если failed/retrying]

Pagination: «Загрузить ещё» (offset += 25)

**DeliveryStatusBadge**:
- pending → `bg-info/10 text-info bi-clock` «Ожидание»
- success → `bg-success/10 text-success bi-check-circle` «Доставлено»
- failed → `bg-danger/10 text-danger bi-x-circle` «Ошибка»
- retrying → `bg-warning/10 text-warning bi-arrow-clockwise animate-spin` «Повтор»

### CreateWebhookModal (create/edit)
- Название (input required)
- URL (input type="url" https only)
- Подписаться на события (EventCheckboxGrid с категориями)
- Дополнительные заголовки (textarea JSON optional, валидация JSON)
- Секрет HMAC (input + кнопка генерации `bi-arrow-clockwise`, default empty = auto-generated на бэке)

### WebhookSecretModal (после create)
- bi-broadcast-pin (success, 4xl)
- Plaintext secret в mono-block + копировать
- Python пример HMAC verification:
```python
import hmac, hashlib
SECRET = "whsec_..."
body = request.body()
expected = hmac.new(SECRET.encode(), body, hashlib.sha256).hexdigest()
is_valid = hmac.compare_digest(expected, request.headers["X-Macro-Signature"].removeprefix("sha256="))
```
- Header пример: `X-Macro-Signature: sha256=<hex>`

### DeliveryDetailModal
- Header: `{event} · #{id}`
- Grid 2 cols: Вебхук, Событие, Статус, HTTP, Попытка, Created, Finished, Next retry
- Section Payload (pretty JSON, max-h-64 scroll)
- Section Response (если есть, banner если truncated 2KB)
- Section Error (text-danger если есть)
- Footer кнопка Повторить (если failed/retrying)

## States
- Loading: skeleton 3-5 rows animate-pulse
- Empty webhooks: EmptyState + CTA
- Empty deliveries: EmptyState без CTA
- Error: inline `bg-danger/10 text-danger` banner

## Принципы
- TypeScript strict, tsc 0 errors, no `any`
- Все fetch через `api` / `fetcher`, cookie auth
- SWR с правильными keys
- Tailwind tokens, Bootstrap Icons (`bi-key-fill`, `bi-broadcast-pin`, `bi-send-check`, `bi-clipboard`, `bi-shield-check-fill`, `bi-arrow-clockwise`, `bi-clock`, `bi-check-circle`, `bi-x-circle`, `bi-pencil`, `bi-trash`)
- Все тексты RU
- Reuse EmptyState, Modal, RoleGate, UserSelect

## Open questions (решения для frontend-specialist)
1. **TokenRevealModal/WebhookSecretModal** — backdrop close блокировать через `isDirty=true` или пробросить новый prop `disableBackdropClose` в Modal (мини-рефакторинг). Решение: используй existing isDirty паттерн.
2. **Test кнопка event** — отправлять `event_subscriptions[0]`. Если `*` — backend сам выбирает demo event.
3. **director видимость токенов** — backend решит (probably только свои). Колонка user_id скрыта если все строки одного user.
4. **is_active toggle на webhook** — НЕ inline в таблице, только через edit modal (избегаем accidental toggle).
5. **Лимит токенов на user** — пока без UI лимита (backend сам решит и вернёт 400 если нужно).
6. **DeliveriesTab global endpoint** — backend подтвердил `/api/webhook-deliveries?webhook_id=` (без path id).
7. **max_attempts** — не показывать, просто `attempt`.
