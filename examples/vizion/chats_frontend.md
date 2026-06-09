# Чат и генерация отчётов — API для фронтенда

## Общий флоу

```
1. Создать чат          → POST /api/chats
2. Отправить сообщение  → POST /api/chats/{id}/messages   (202 + stream_url)
3. Дождаться завершения → стрим (M5) или polling /messages пока status != pending/running
4. AI создаёт отчёт     → chat.report_id появляется при перечитывании чата
5. Загрузить отчёт      → GET /api/reports/{report_id}
6. Итерации             → отправлять новые сообщения в тот же чат
```

> С M4 (async-флоу) `POST /api/chats/{id}/messages` возвращает 202 и `assistant_message.status=pending` сразу — не блокируется на 30–60 сек как раньше. AI-турн выполняется в фоне в отдельном queue-worker'е.

Все эндпоинты требуют заголовок `Authorization: Bearer {token}`.

Доступ к чату имеют роли: `superadmin`, `admin`, `analyst`. Роль `viewer` получает 403.

---

## Создать чат

```
POST /api/chats
```

**Запрос:**

```json
{
  "type": "report_generation"
}
```

| Поле | Тип | Обязательное | Описание |
|---|---|---|---|
| `type` | string | **да** | `report_generation` или `quick_qa`. При отсутствии — 422. Обязательность намеренна: явное указание типа исключает misroute (quick_qa-запрос не попадёт в полный report flow). |

**Ответ (201):**

```json
{
  "user_id": 1,
  "company_id": 1,
  "type": "report_generation",
  "created_at": "2026-04-10T06:31:18.000000Z",
  "updated_at": "2026-04-10T06:31:18.000000Z",
  "id": 13,
  "messages": []
}
```

> Nullable-поля `title`, `report_id`, `ai_context` **не возвращаются**, когда равны `null`. Проверяйте их наличие через `in_array` / `hasOwnProperty`.
> `title` появится после первого сообщения (автозаполнение из первых 80 символов).

---

## Типы чатов

| Тип | Название | Назначение | Инструменты AI |
|---|---|---|---|
| `report_generation` | AI-конструктор отчётов | Создание **сухих таблиц-отчётов** (без визуализации) | `probe_data`, `probe_custom_attributes`, `create_report`, `update_report` |
| `widget_generation` | AI-генератор виджетов | Создание **одного виджета** (агрегат + чарт) для дашборда | `probe_data`, `create_widget`, `update_widget` |
| `document_template` | AI-генератор документов | Маппинг полей docx ИЛИ генерация HTML-КП | `probe_data`, `propose_document_fields`, `generate_document_template` |
| `quick_qa` | Быстрые ответы | Ответы на вопросы по данным | `probe_data`, `probe_custom_attributes`, `query_data` |

**`quick_qa`** — режим для вопросов вида "сколько сделок в январе?", "средний чек по ЖК Sunny?". AI отвечает текстом, использует `probe_data` / `query_data`. Не создаёт сущности.

> **`probe_custom_attributes`** (новый, read-only) — перечисляет кастомные / EAV-атрибуты MACRO (балкон, терраса, гражданство, состояние и т.п., которые живут в EAV-таблицах `estate_attributes` / `estate_sells_attr` и не видны обычным `probe_data`). Эмитит те же SSE-события `tool_call` / `tool_result`, что и остальные инструменты — фронт рендерит их обобщённо по `name` + summary (`custom_count` / `builtin_count`), отдельного UI не требуется.

**`report_generation`** — режим для создания **сухих табличных отчётов** (многоколоночные таблицы с фильтрами и drill-down). **Без визуализации** — чарты и виджеты больше НЕ часть отчёта (см. ниже).

**`widget_generation`** — режим для создания **виджета**: маленькой агрегированной таблицы под один чарт (`bar` / `line` / `pie` / `doughnut`). Виджет — самостоятельная сущность (`/api/widgets`), которую пользователь потом добавляет на дашборд. Чат `widget_generation` привязан к одному виджету через `chat.widget_id` (зеркало `chat.report_id` у `report_generation`); сам виджет ссылается обратно через `widget.chat_message_id`. Lazy-create — через `POST /api/chats/messages` с `type=widget_generation` (опционально `widget_id` для правки существующего виджета).

**`document_template`** — режим раздела «Документы». Два сценария:
- **Маппинг полей docx:** если чат привязан к загруженному Word-шаблону (`type=document_template` + `scope_type=document` + `document_id` существующего docx-шаблона), AI читает текст документа и его плейсхолдеры `${токен}` и предлагает маппинг плейсхолдер→поле через `propose_document_fields`. Маппинг НЕ сохраняется автоматически — он приходит фронту событием `document_fields_proposed` (см. SSE-секцию), пользователь подтверждает.
- **Генерация HTML-КП:** AI собирает кастомный шаблон коммерческого предложения через `generate_document_template` (создаёт `DocumentTemplate` `type='html'`; зеркало `create_report`). Чат привязывается к шаблону через `chat.document_id`, шаблон ссылается обратно через `document_template.chat_message_id`. Lazy-create свежей генерации — `POST /api/chats/messages` с `type=document_template`, `scope_type=general` (без `document_id`).

> **Важно:** отчёт (`report_generation`) больше НЕ содержит `dashboard_widgets[]` / `chart`. Любая визуализация — это отдельный виджет (`widget_generation`).

> Если пользователь в режиме `quick_qa` просит отчёт или виджет — AI предложит перейти в нужный конструктор через action-маркер (`redirect_to_report_generation` / `redirect_to_widget_generation`).

---

## Список чатов

```
GET /api/chats
GET /api/chats?scope_type=general
GET /api/chats?scope_type=report&report_id=8
GET /api/chats?limit=10
```

Возвращает чаты текущего пользователя в **активной** компании (той, на которую пользователь переключился через `POST /api/active-company/{id}`), новые сверху. Каждый чат содержит только последнее сообщение.

**Query-параметры (опциональные, для mini-chat):**
- `scope_type` — `'report'` | `'general'` | `'dashboard'` | `'document'`. Фильтр по UI-скоупу чата.
- `report_id` — обязателен, если `scope_type=report`. Должен указывать на отчёт, который пользователь может прочитать в активной компании (иначе 403).
- `dashboard_id` — обязателен, если `scope_type=dashboard`. Дашборд должен принадлежать активной компании (иначе 403).
- `document_id` — обязателен, если `scope_type=document`. Шаблон документа должен принадлежать активной компании (иначе 403).
- `limit` — 1..50, по умолчанию 50.

Каждый чат в ответе несёт `report_id`, `widget_id`, `dashboard_id`, `document_id` (любой из них может быть null).

**Поля ответа (mini-chat-агрегаты):**
- `scope_type` — `'report'` | `'general'` | `'dashboard'` | `'document'`.
- `last_message_at` — ISO8601 строка или `null`. MAX(`created_at`) по всем сообщениям (любой роли).
- `user_message_count` — int, COUNT по `role='user'`.
- `is_active_window` — bool. `true` если (а) сообщений нет, либо (б) `last_message_at >= now()-24h` И `user_message_count < 10`. Используется mini-chat'ом для решения "можно ли продолжить этот чат или начинать новый".

Сортировка: `ORDER BY COALESCE(last_message_at, created_at) DESC` — свежий пустой чат всплывает выше старых.

**Ответ (200):**

```json
[
  {
    "id": 13,
    "type": "report_generation",
    "scope_type": "general",
    "title": "Покажи топ 5 ЖК по сумме сделок",
    "report_id": 8,
    "created_at": "2026-04-10T06:31:18.000000Z",
    "updated_at": "2026-04-10T07:15:30.000000Z",
    "last_message_at": "2026-04-10T07:15:30+00:00",
    "user_message_count": 3,
    "is_active_window": true,
    "last_message": {
      "role": "assistant",
      "content": "Готово! Отчёт «Топ-5 ЖК по сумме сделок» создан.",
      "created_at": "2026-04-10T07:15:30.000000Z"
    }
  }
]
```

---

## Auto-resume чата для mini-chat

```
GET /api/chats/resume?scope_type=general
GET /api/chats/resume?scope_type=report&report_id=8
```

Атомарно отдаёт самый свежий «активный» чат текущего пользователя в нужном скоупе — для авто-открытия mini-chat при mount/open. Под «активным» понимается тот же критерий, что и `is_active_window` из списка: либо чат пустой, либо `last_message_at >= now()-24h` И `user_message_count < 10`.

**Query-параметры:**
- `scope_type` (required) — `'report'` | `'general'` | `'dashboard'` | `'document'`.
- `report_id` (required, если `scope_type=report`) — int. Тот же read-ACL что и в `GET /api/chats` (своя компания, system reports видны всем, superadmin видит везде, viewer не видит unpublished).
- `dashboard_id` (required, если `scope_type=dashboard`) — int. Дашборд должен принадлежать активной компании (иначе 403).
- `document_id` (required, если `scope_type=document`) — int. Шаблон документа должен принадлежать активной компании (иначе 403).

**Ответ 200 OK** — полный ChatDetailDto (тот же shape, что `GET /api/chats/{id}`), плюс mini-chat-агрегаты (`last_message_at`, `user_message_count`, `is_active_window`). Eager-loaded: `messages` (ordered by `created_at` asc) и `report`.

**Ответ 204 No Content** — подходящего чата нет. Фронт трактует как «открой новый in-memory чат, lazy-create на первом сообщении».

**Ответ 403** — `report_id` не из активной компании (или viewer пытается резюмить unpublished не-system report).

**Ответ 422** — отсутствует `scope_type`, или `scope_type=report` без `report_id`, или `scope_type=document` без `document_id`, или невалидное значение.

**Пример:**

```json
{
  "id": 17,
  "user_id": 1,
  "company_id": 1,
  "type": "quick_qa",
  "scope_type": "report",
  "title": "Уточни цифры за прошлый месяц",
  "report_id": 8,
  "ai_context": null,
  "created_at": "2026-05-24T10:11:00.000000Z",
  "updated_at": "2026-05-24T10:11:42.000000Z",
  "last_message_at": "2026-05-24T10:11:42+00:00",
  "user_message_count": 1,
  "is_active_window": true,
  "messages": [
    { "id": 41, "role": "user", "content": "...", "status": "done", "created_at": "..." },
    { "id": 42, "role": "assistant", "content": "...", "status": "done", "created_at": "..." }
  ],
  "report": {
    "id": 8,
    "title": { "ru": "...", "en": "..." }
  }
}
```

---

## Inline create + первое сообщение (lazy creation mini-chat)

```
POST /api/chats/messages
```

Атомарно создаёт `Chat` + первое user-сообщение + диспатчит `ProcessChatMessageJob` в одной DB-транзакции. Используется mini-chat'ом: чат живёт только во фронте до первого сообщения пользователя, и этот endpoint материализует его на бэке вместе с первым турном.

Если что-то падает внутри транзакции — чат **не создаётся** (нет orphaned пустых чатов).

**Body:**

```json
{
  "scope_type": "report",
  "report_id": 8,
  "content": "Уточни цифры по отчёту за прошлый месяц",
  "report_context": {
    "primaryModel": "EstateDeals",
    "reportId": 8,
    "reportTitle": "Продажи",
    "columns": ["deal_date", "deal_sum"],
    "filters": { "deal_status": 150 }
  }
}
```

**Поля:**
- `scope_type` (required) — `'report'` | `'general'` | `'dashboard'` | `'document'`.
- `report_id` (required, если `scope_type=report`) — int. Тот же read-ACL.
- `dashboard_id` (required, если `scope_type=dashboard`) — int. Дашборд должен принадлежать активной компании (иначе 403). Используется mini-chat'ом на странице дашборда (`quick_qa` по конфигам виджетов дашборда).
- `document_id` (required, если `scope_type=document`) — int. Шаблон документа должен принадлежать активной компании (иначе 403). Используется на странице документа (маппинг полей docx / правка КП). Для свежей генерации КП через `DocumentGenerationModal` — `scope_type=general` без `document_id` (DocumentTool сам пинит `chat.document_id` после создания).
- `widget_id` (optional) — int. Только для `type=widget_generation`: привязывает чат к существующему виджету для режима правки (`update_widget`). Виджет должен принадлежать активной компании (иначе 403). Для генерации нового виджета — не передавать (WidgetTool сам пинит `chat.widget_id` после создания).
- `content` (required, string, ≤4000) — текст первого сообщения. Автоматически становится title чата (truncated до 80 символов).
- `report_context` (optional, object) — тот же формат, что и у `POST /api/chats/{chat}/messages`. Прокидывается в `ProcessChatMessageJob` для in-report quick_qa system-prompt.

Чат создаётся с типом, который передан в теле запроса (поле `type`, default `quick_qa`). Mini-chat (отчёт/дашборд) шлёт `quick_qa`; модалка генерации отчёта — `report_generation`; модалка генерации виджета — `widget_generation`; модалка генерации документа — `document_template`.

| Поле | Тип | Обязательное | Описание |
|---|---|---|---|
| `type` | string | нет | `quick_qa` (по умолчанию), `report_generation`, `widget_generation` или `document_template`. Определяет системный промпт и доступные инструменты AI. |

**Ответ 202 Accepted** — та же envelope, что у обычного `POST /api/chats/{chat}/messages`, плюс полный объект `chat` (с `report` eager-loaded):

```json
{
  "user_message": {
    "id": 41,
    "chat_id": 17,
    "role": "user",
    "content": "Уточни цифры по отчёту за прошлый месяц",
    "created_at": "2026-05-24T10:11:00.000000Z"
  },
  "assistant_message": {
    "id": 42,
    "chat_id": 17,
    "role": "assistant",
    "status": "pending",
    "content": null,
    "created_at": "2026-05-24T10:11:00.000000Z"
  },
  "stream_url": "/api/chats/17/stream/42",
  "chat": {
    "id": 17,
    "user_id": 1,
    "company_id": 1,
    "type": "quick_qa",
    "scope_type": "report",
    "title": "Уточни цифры по отчёту за прошлый месяц",
    "report_id": 8,
    "report": { "id": 8, "title": { "ru": "...", "en": "..." } }
  }
}
```

Дальше фронт подписывается на SSE по `stream_url` так же, как и в обычном flow `POST /api/chats/{chat}/messages`.

**Ответ 403:**
- роль `viewer` (mini-chat доступен только `analyst` / `admin` / `superadmin`)
- `report_id` не из активной компании / нет read-доступа
- `dashboard_id` не из активной компании
- `widget_id` (для `widget_generation`) не из активной компании

**Ответ 422:**
- отсутствует `content` / `scope_type`
- `scope_type=report` без `report_id`
- `scope_type=dashboard` без `dashboard_id`
- `scope_type=document` без `document_id`
- невалидный `type` (не `report_generation` / `widget_generation` / `document_template` / `quick_qa`) или `scope_type`

**Race / двойной клик:** idempotency-key не реализован — клиентская сторона ответственна за гейт двойных кликов. При случайном двойном POST создастся 2 чата (пользователь увидит дубликат, удалит).

---

## Получить чат (сообщения + отчёт)

```
GET /api/chats/{chat_id}
```

Возвращает чат со всеми сообщениями (по возрастанию) и привязанным отчётом.

**Ответ (200):**

```json
{
  "id": 13,
  "user_id": 1,
  "company_id": 1,
  "type": "report_generation",
  "title": "Покажи топ 5 ЖК по сумме сделок",
  "report_id": 8,
  "ai_context": {
    "last_tool_calls": ["probe_data", "probe_data", "probe_data", "create_report"],
    "total_steps": 5,
    "probed_models": ["EstateDeals"],
    "report_created": true
  },
  "created_at": "...",
  "updated_at": "...",
  "messages": [
    {
      "id": 25,
      "chat_id": 13,
      "user_id": 1,
      "company_id": 1,
      "role": "user",
      "content": "Покажи топ 5 ЖК по сумме сделок",
      "metadata": null,
      "created_at": "...",
      "updated_at": "..."
    },
    {
      "id": 26,
      "chat_id": 13,
      "user_id": 1,
      "company_id": 1,
      "role": "assistant",
      "content": "Готово! Отчёт «Топ-5 ЖК по сумме сделок» создан. ...",
      "metadata": {
        "finish_reason": "stop",
        "usage": {
          "prompt_tokens": 155179,
          "completion_tokens": 1118
        },
        "tool_calls": [
          { "name": "probe_data", "arguments": "..." },
          { "name": "create_report", "arguments": "..." }
        ]
      },
      "created_at": "...",
      "updated_at": "..."
    }
  ],
  "report": {
    "id": 8,
    "title": { "ru": "Топ ЖК по сумме сделок", "en": "Top Complexes by Deal Amount" },
    "config": { ... },
    "is_system": false,
    "is_published": false
  }
}
```

---

## Получить сообщения чата

```
GET /api/chats/{chat_id}/messages
```

**Ответ (200):** массив сообщений, отсортированных по `created_at` по возрастанию. Начиная с M4 каждое сообщение содержит lifecycle-поля для async-флоу:

```json
[
  {
    "id": 26,
    "chat_id": 13,
    "role": "assistant",
    "content": "Готово! Отчёт собран.",
    "status": "done",
    "started_at": "2026-05-20T08:32:11+00:00",
    "finished_at": "2026-05-20T08:32:47+00:00",
    "events_count": 14,
    "metadata": { ... },
    "created_at": "...",
    "updated_at": "..."
  }
]
```

Роли сообщений:

| Роль | Описание |
|---|---|
| `user` | Сообщение пользователя |
| `assistant` | Ответ AI |
| `system` | Ошибка (AI не смог обработать) — legacy, новые ошибки приходят как `assistant` с `status=error` |

Статусы (`status`) — только для `role=assistant`:

| Статус | Описание |
|---|---|
| `pending` | Сообщение создано, job в очереди, работа не начиналась |
| `running` | Job подхвачен worker'ом, AI / tool-calls в работе |
| `done` | Завершено успешно (`content` записан) |
| `error` | Job упал, ошибка в `metadata.error.message` |
| `cancelled` | Отменено пользователем или системой |

`events_count` — число записей в event-log сообщения. Если > 0, фронт может тянуть детали через streaming endpoint (M5).

---

## Отправить сообщение (основной эндпоинт) — async с M4

```
POST /api/chats/{chat_id}/messages
```

**Что происходит на бэкенде:**
1. Создаётся `user` сообщение с введённым текстом.
2. Создаётся `assistant` сообщение с `status=pending`, `content=null`.
3. Диспатчится `ProcessChatMessageJob` в очередь `ai-chat` — отдельный воркер-контейнер выполняет AI-турн.
4. HTTP-ответ возвращается **сразу** (≈ десятки мс), не дожидаясь AI.
5. Фронт следит за прогрессом через streaming endpoint (M5) или polling `GET /api/chats/{id}/messages` — пока поле `status` не станет `done` / `error`.

**Запрос:**

```json
{
  "content": "Покажи топ 5 ЖК по сумме сделок"
}
```

| Поле | Тип | Обязательное | Описание |
|---|---|---|---|
| `content` | string | да | Текст сообщения, макс. 4000 символов |
| `report_context` | object | нет | **In-report quick_qa snapshot** — см. ниже. Передавать ТОЛЬКО когда чат открыт со страницы конкретного отчёта (MiniChat на `/reports/N`). Игнорируется в `report_generation`-чатах. |

#### `report_context` — slim прокидывание отчёта для quick_qa

Опциональное поле для оптимизации system-prompt'а в режиме `quick_qa`. Когда фронт передаёт `report_context`, backend выбрасывает каталог моделей (`QUICK_QA_PROMPT.md`, ~10 KB) из system-prompt'а и инжектит только короткую кураторскую справку для `primaryModel` плюс заголовок отчёта (название, колонки, применённые фильтры). На странице отчёта это убирает дублирование контекста: у AI уже есть конкретный отчёт «перед глазами», полный 64-модельный каталог — пустая нагрузка.

| Поле внутри `report_context` | Тип | Обязательное | Описание |
|---|---|---|---|
| `primaryModel` | string | **да** для активации режима | PascalCase имя модели MacroData (например `"EstateDeals"`, `"Finances"`). Без него `report_context` игнорируется и backend откатывается на общий quick_qa-каталог. |
| `reportId` | integer | нет | ID отчёта. Для логирования / debugging — backend не использует его в prompt'е. |
| `reportTitle` | string | нет | Заголовок отчёта (локализованная строка). Идёт в шапку prompt'а как «Название отчёта». |
| `columns` | array | нет | Список имён колонок (`string[]`) ИЛИ массив объектов с ключом `field`. Идёт в строку «Колонки отчёта». Если пусто — backend подскажет AI про `probe_data`. |
| `filters` | object | нет | Применённые фильтры (произвольный jsonb-словарь). Сериализуется как JSON-блок в шапке prompt'а — AI учитывает их при формулировке `query_data` ответов. |

**Пример payload'а с `report_context` (MiniChat на странице отчёта по дебиторке):**

```json
{
  "content": "А какая динамика просрочки по месяцам?",
  "report_context": {
    "primaryModel": "Finances",
    "reportId": 42,
    "reportTitle": "Дебиторка по сделкам",
    "columns": ["deal_id", "sum", "pay_date", "types_id", "status"],
    "filters": {
      "pay_date": {"from": "2026-01-01", "to": "2026-05-31"},
      "status": 1
    }
  }
}
```

**Когда поле НЕ передавать:**

- Общий чат (главная страница, sidebar) — нет привязки к отчёту → не передавать. Backend использует полный quick_qa-каталог.
- Режим `report_generation` (создание/правка отчёта в AI-конструкторе) — поле игнорируется backend'ом, в этом режиме всегда грузится `REPORTS_GUIDE.md`. Лишний payload не сломает, но и пользы не даст.

**Fallback-поведение backend'а:**

- Если `report_context` отсутствует → общий quick_qa-каталог (старое поведение, не breaking).
- Если `report_context.primaryModel` пустой / не строка / отсутствует → отвал в общий каталог + warning не пишется (валидный кейс «partial payload»).
- Если `primaryModel` есть, но это незнакомая модель (без curated semantic note в `ModelSemanticNotes.php`) → in-report prompt всё равно строится, но с generic-нотой «используй probe_data чтобы изучить структуру». Не падает.
- Если `report_context` нет, но `chat.report_id` пин на отчёт → backend сам подтянет `primary_model` из `report.config` (fallback chain). Полезно для старого фронта, который ещё не передаёт payload-поле.

**Ответ (202 Accepted) — успешный диспатч:**

```json
{
  "user_message": {
    "id": 25,
    "chat_id": 13,
    "role": "user",
    "content": "Покажи топ 5 ЖК по сумме сделок",
    "created_at": "..."
  },
  "assistant_message": {
    "id": 26,
    "chat_id": 13,
    "role": "assistant",
    "status": "pending",
    "content": null,
    "created_at": "..."
  },
  "stream_url": "/api/chats/13/stream/26",
  "chat": {
    "id": 13,
    "title": "Покажи топ 5 ЖК по сумме сделок",
    "report_id": 8,
    "ai_context": { ... },
    "report": { ... }
  }
}
```

> **`stream_url`** — endpoint event-log стриминга (полная реализация в M5). До M5 фронт может его игнорировать и поллить `GET /api/chats/{id}/messages`, пока `assistant.status` не станет `done` / `error`.

**Ответ (409 Conflict) — предыдущий ход ещё не завершён:**

```json
{
  "message": "Предыдущий запрос ещё обрабатывается. ...",
  "code": "turn_in_progress"
}
```

Только один `assistant` сообщение в `pending` / `running` на чат за раз. Фронту имеет смысл отключать кнопку «Отправить», пока активный ход не завершён.

**После завершения** (через стрим или polling) `assistant_message` обновляется:

- `status` → `done` или `error`
- `content` → текст AI (или fallback i18n при `error`)
- `metadata` → `usage`, `tool_calls`, `tool_results`, плюс при ошибке — `error.exception_class` / `error.message`
- `finished_at` → wall-clock

**Ошибочный итог** (`status=error`):

```json
{
  "id": 26,
  "role": "assistant",
  "status": "error",
  "content": "Произошла ошибка при обработке запроса. Попробуйте ещё раз.",
  "metadata": {
    "error": {
      "exception_class": "RuntimeException",
      "message": "You hit a provider rate limit. Details: []"
    }
  },
  "finished_at": "..."
}
```

> Legacy `role=system` ответы (с pre-M4 sync-флоу) могут оставаться в БД у старых чатов. Новые ошибки — `role=assistant`, `status=error`.

---

## Удалить чат

```
DELETE /api/chats/{chat_id}
```

Удаляет чат, все его сообщения и привязанный отчёт.

**Ответ (200):**

```json
{ "message": "Чат удалён" }
```

---

## Как работают итерации

**Только для типа `report_generation`:** один чат = один отчёт. После создания отчёта (`chat.report_id` не null) пользователь может отправлять сообщения для его изменения:

| Пользователь пишет | AI делает |
|---|---|
| «Добавь колонку менеджер» | `update_report` — добавляет колонку в config |
| «Поменяй график на круговой» | `update_report` — меняет `chart.type` на `pie` |
| «Отфильтруй по 2025 году» | `update_report` — добавляет фильтр в config |
| «Покажи только топ 5» | `update_report` — добавляет limit/sort |

AI видит весь контекст: историю сообщений, текущий конфиг отчёта и `ai_context` с информацией о предыдущих вызовах инструментов.

---

## Важное для фронтенда

1. **POST возвращает 202 за десятки мс** — AI-турн выполняется в фоне. Финал получают через стрим (M5) или polling `GET /api/chats/{id}/messages` до тех пор, пока `assistant_message.status` не станет `done` / `error`. AI-турн занимает 30–60 сек типично, до 10 мин на сложных отчётах.
2. **Один активный turn на чат за раз.** Пока `assistant.status` ∈ {`pending`, `running`}, повторный POST вернёт 409. Логично отключить кнопку «Отправить».
3. **Первое сообщение** устанавливает `chat.title` автоматически.
4. **Появление `chat.report_id`** означает, что отчёт создан. Можно показывать кнопку «Открыть отчёт». Учитывай: до завершения turn'а `chat.report_id` ещё `null` — перечитай чат после `status=done`.
5. **`message.metadata.tool_calls`** — массив инструментов, которые вызвал AI (доступен после `status=done`). Полезно для индикации.
6. **`events_count`** на каждом сообщении — число событий в стриминговом логе. Если > 0, есть смысл подключиться к streaming endpoint (M5).
7. **Фильтры и пагинация** — через `GET /api/reports/{id}` (описано в отдельной документации отчётов).

---

## Структуры данных

### Chat

| Поле | Тип | Описание |
|---|---|---|
| `id` | int | — |
| `user_id` | int | Владелец |
| `company_id` | int | Компания |
| `type` | string | `report_generation` / `widget_generation` / `document_template` / `quick_qa` |
| `scope_type` | string | `report` / `general` / `dashboard` / `document` |
| `title` | string\|null | Автозаполняется из первого сообщения (80 символов) |
| `report_id` | int\|null | Привязанный отчёт (только для `report_generation` / `scope=report`) |
| `widget_id` | int\|null | Привязанный виджет (только для `widget_generation`) |
| `dashboard_id` | int\|null | Привязанный дашборд (только для `scope=dashboard` mini-chat) |
| `document_id` | int\|null | Привязанный шаблон документа (только для `document_template` / `scope=document`) |
| `ai_context` | object\|null | Контекст AI: вызванные инструменты, модели и т.д. |
| `created_at` | datetime | — |
| `updated_at` | datetime | — |

### ChatMessage

| Поле | Тип | Описание |
|---|---|---|
| `id` | int | — |
| `chat_id` | int | Родительский чат |
| `user_id` | int | — |
| `company_id` | int | — |
| `role` | string | `user` / `assistant` / `system` (legacy) |
| `content` | text\|null | Текст сообщения. `null` для `assistant` пока `status=pending` |
| `status` | string | `pending` / `running` / `done` / `error` / `cancelled` (только для `assistant`) |
| `started_at` | datetime\|null | Когда worker подхватил job |
| `finished_at` | datetime\|null | Wall-clock завершения (для `done` / `error` / `cancelled`) |
| `events_count` | int | Число строк в `chat_message_events` (только в `GET /messages`) |
| `metadata` | object\|null | Только для `assistant`: токены, tool_calls, tool_results, error |
| `created_at` | datetime | — |
| `updated_at` | datetime | — |

### metadata (для assistant-сообщений)

| Поле | Тип | Описание |
|---|---|---|
| `finish_reason` | string | `stop` — завершено нормально |
| `usage.prompt_tokens` | int | Токены на вход (промпт + история) |
| `usage.completion_tokens` | int | Токены на выход (ответ AI) |
| `tool_calls` | array\|null | Вызванные инструменты: `{name, arguments}` |
| `tool_results` | array\|null | Результаты вызовов инструментов |

### ai_context (в объекте chat)

| Поле | Тип | Описание |
|---|---|---|
| `last_tool_calls` | string[] | Имена инструментов последнего запроса |
| `total_steps` | int | Общее число шагов AI (включая промежуточные) |
| `probed_models` | string[] | Какие модели MacroData AI проверял через `probe_data` |
| `report_created` | boolean | Был ли создан отчёт |
| `report_updated` | boolean | Был ли обновлён отчёт |

---

## Tool responses (server → client)

При успешном вызове инструментов `create_report` / `update_report` ответ AI-сообщения может содержать в `metadata.tool_results` поле `normalized_changes`:

```json
{
  "normalized_changes": [
    {"path": "columns[0].field", "from": "EstateDeal.deal_sum", "to": "deal_sum"},
    {"path": "data_source.model", "from": "EstateDeal", "to": "EstateDeals"}
  ]
}
```

| Поле | Тип | Описание |
|---|---|---|
| `normalized_changes` | array\|null | Список исправлений имён (если AI передал неканонические имена моделей/полей). Опционально — может отсутствовать |
| `normalized_changes[].path` | string | Путь к исправленному полю в конфиге отчёта |
| `normalized_changes[].from` | string | Исходное имя (как написал AI) |
| `normalized_changes[].to` | string | Канонического имя (после нормализации) |

**Для фронтенда:** поле опциональное, его отсутствие — норма. Для роли `analyst` / `admin` можно показывать как дебаг-подсказку «имена были автоисправлены». Для `viewer` — игнорировать.

---

## Action-маркеры в ответах AI (quick_qa → report_generation / widget_generation)

В режиме `quick_qa` AI может предложить пользователю перейти в AI-конструктор отчётов
(`report_generation`) ИЛИ в генератор виджетов (`widget_generation`). Когда уместно, AI
вкладывает в `content` специальный JSON-блок — **action-маркер**, который фронт должен
распарсить и отрисовать как CTA-кнопку.

> **Также из `report_generation`:** если в режиме генерации отчёта пользователь просит
> агрегированный свод / распределение / топ-N / график по измерению, у которого нет
> HasMany-связи на сделки (менеджеры, статусы, рекламные каналы, месяцы), AI больше не
> строит бессмысленный плоский список и не врёт «группировка не поддерживается». Вместо
> этого он эмитит маркер `redirect_to_widget_generation` (тот же формат, что ниже) — фронт
> должен распарсить его и в режиме report_generation тоже, не только в quick_qa. Свод по
> проектам/ЖК (есть HasMany) по-прежнему строится отчётом через `relation_aggregate`.

### Формат маркера

Внутри `assistant.content` встречается fenced code block с языком `json`. Два варианта:

```json
{
  "action": "redirect_to_report_generation",
  "prompt": "<полное ТЗ для конструктора отчёта: primary_model, фильтры, колонки, сортировка>",
  "label": "Открыть в AI-конструкторе отчётов"
}
```

```json
{
  "action": "redirect_to_widget_generation",
  "prompt": "<ТЗ для генератора виджета: primary_model, group_by, агрегат (count/sum/avg), фильтры, тип чарта bar/line/pie/doughnut>",
  "label": "Открыть в генераторе виджетов"
}
```

| Поле | Тип | Описание |
|---|---|---|
| `action` | string | `redirect_to_report_generation` (многоколоночная таблица-отчёт) или `redirect_to_widget_generation` (один чарт-виджет для дашборда) |
| `prompt` | string | Готовый rich-prompt для нового чата с соответствующим `type`. AI сформировал его на основе диалога — фронту НЕ нужно ничего достраивать |
| `label` | string | Текст для кнопки. Локализован под `user.locale` (`ru` / `en`) |

### Что делает фронт

1. После рендеринга markdown ответа найти fenced `json` блоки и попытаться распарсить.
2. Если в блоке есть `"action": "redirect_to_report_generation"` или `"action": "redirect_to_widget_generation"` — спрятать сам JSON-блок из визуального рендера (или показать свёрнутым) и нарисовать кнопку с текстом `label`.
3. По клику:
   - `redirect_to_report_generation` → открыть глобальную модалку `ReportGenerationModal` с `prefillPrompt = prompt`. При первом сенде модалка создаёт чат через `POST /api/chats/messages` с `type=report_generation`, `scope_type=general`.
   - `redirect_to_widget_generation` → открыть модалку генерации виджета (фаза 5) с `prefillPrompt = prompt`. При первом сенде — `POST /api/chats/messages` с `type=widget_generation`, `scope_type=general`.
   Авто-send НЕ выполняется — пользователь видит промпт в поле ввода и сам нажимает «Отправить».
4. Страница `/ai-reports` удалена. Генерация отчётов / виджетов происходит через глобальные модалки, доступные из любой страницы приложения.

> Примечание: паттерн «перейди на `/ai-reports` с router-state `activateChatId`» удалён 2026-05-24. Все новые сценарии генерации идут через модалки.

### Что делать с обычным текстом

Кроме action-маркера в `content` есть обычный markdown — он рендерится как всегда.
Маркер обычно стоит в конце ответа. Не более одного маркера на сообщение.

### Что НЕ делать

- Не вызывать `eval` / `Function()` — `JSON.parse` строго.
- Не отправлять `prompt` пользователю на правку — это уже законченный rich-prompt; если
  пользователь захочет иначе, он скажет это уже в конструкторе.

### Когда парсить маркер

Парсинг action-маркера управляется **prop `enableActionMarker`** на компоненте `ChatMessageBubble` (или через `<ChatMessageList :enable-action-marker="true">`), а **не** по значению `chat.type`. Это принципиально:

- В MiniChatWidget (`scope_type=mini`, `type=quick_qa`) маркер нужен → `enable-action-marker` выставить.
- В `ReportGenerationModal` (чат `type=report_generation`) маркер `redirect_to_widget_generation` тоже обрабатывается → `enable-action-marker` выставить (см. примечание выше про маркер из report_generation).
- При inline-create (lazy-create endpoint) `chat.type` может быть `undefined` в partial-снапшоте — привязка к prop исключает эту ловушку; парсинг работает корректно независимо от того, разрешён ли `chat.type`.
- Компоненты/контексты, где маркер не нужен (например, история без CTA) — `enable-action-marker` не передавать или передать `false`.

Shared composable `useChatActionMarker` гейтирует обработку на `marker.action` (поле в распарсенном JSON), а не на свойстве чата. Это single source of truth для логики маршрутизации маркера.

---

## Streaming активного AI-turn'а

```
GET /api/chats/{chat}/stream/{message}
```

Открывает SSE-поток для конкретного assistant-сообщения. Используется для получения событий в реальном времени пока AI-турн выполняется в очереди.

**Response:**
- `Content-Type: text/event-stream; charset=UTF-8`
- `Cache-Control: no-cache, must-revalidate`
- `X-Accel-Buffering: no`

**Resume (cursor):**

| Приоритет | Механизм | Описание |
|---|---|---|
| 1 | `?since=N` query-param | Явный cursor — перебивает всё остальное |
| 2 | `Last-Event-ID` header | Браузер (EventSource) автоматически отправляет после разрыва соединения |
| 3 | Без cursor | Воспроизводит ВСЕ события с начала (sequence > 0) |

Фронт может явно передать `?since=0` при первом подключении — эффект тот же что и без параметра.

**Структура одного SSE-фрейма:**

```
id: <sequence>
event: <type>
data: {"type":"...", "sequence":N, "payload":{...}, "created_at":"..."}

```

Поле `id` (sequence) отсутствует у sentinel-события `done` — браузер не обновляет `Last-Event-ID` для него, что предотвращает бесконечный reconnect после завершения.

**Типы событий (event: <type>) — из `ChatMessageEvent::TYPE_*`:**

| Тип | Описание |
|---|---|
| `started` | Job подхватил сообщение, AI-турн начался |
| `thinking` | Промежуточный шаг AI (рассуждения, если провайдер поддерживает) |
| `tool_call` | AI вызвал инструмент (probe_data / create_report / update_report / propose_widget_variants / create_widget / update_widget / propose_document_fields / generate_document_template). `payload.tool` + `payload.arguments` |
| `widget_variants` | (только `widget_generation`) AI предложил 2-4 ВАРИАНТА виджета вместо немедленного создания. `payload.variants[]` — `{index, label, config}`. Фронт рендерит карточки превью; выбор → следующий ход создаёт виджет. Подробно ниже. |
| `document_fields_proposed` | (только `document_template`) AI предложил маппинг плейсхолдеров загруженного docx на подставляемые поля. `payload.placeholders[]` — `{token, suggested_field, model, source, confidence}` (`source` = `catalog` \| `macrodata`; `confidence` = 0..1 или null). Фронт рендерит карточки маппинга для подтверждения (M8). Маппинг НЕ сохранён — фронт сохраняет его через `PUT /api/documents/{id}` с `config.field_mapping`. |
| `tool_result` | Результат вызова инструмента. `payload.tool` + `payload.success` (boolean). Type-specific keys: `rows_count`, `total_count`, `fields_count` для `probe_data`; `aggregate_value` или `group_rows_count` для `query_data`; `report_id` для `create_report` / `update_report`; `error` для failure-case. |
| `dry_run_start` | Начало dry-run валидации после create/update_report (`payload.report_id`), create/update_widget (`payload.widget_id`) или generate_document_template (`payload.document_id`) |
| `dry_run_result` | Результат dry-run (success / failed). `payload.success`, `payload.error` + соответствующий id (`report_id` / `widget_id` / `document_id`) |
| `retry` | AI повторяет попытку (rate limit или semantic retry после dry-run failure) |
| `text_delta` | Инкремент текста AI-ответа (живой стрим). `payload.delta` — кусок строки; `payload.kind` — `"content"` или `"thinking"`. Подробнее ниже. |
| `final_message` | Финальный текст ответа AI. `payload.content` |
| `error` | AI-турн завершился ошибкой. `payload.message`, `payload.exception_class` |

#### `text_delta` — посимвольный (точнее, по-чанку) стрим ответа

Эмитится во время генерации финального текста AI. Несколько событий подряд складываются в полный ответ — фронт может рендерить их инкрементально, чтобы пользователь видел typewriter-эффект как в ChatGPT/Claude.

**Структура `payload`:**

```json
{
  "delta": "часть текста ответа, ",
  "kind": "content"
}
```

| Поле | Описание |
|---|---|
| `delta` | Непустая строка-инкремент. Накапливать через конкатенацию в порядке `sequence`. |
| `kind` | `"content"` — кусок основного ответа AI. `"thinking"` — кусок reasoning-блока (если провайдер шлёт). |

**`kind: "content"`** — основная цель. Фронт аккумулирует все `delta` с этим kind'ом в основной content bubble сообщения и обновляет его по мере прихода.

**`kind: "thinking"`** — опциональный reasoning-блок. С переходом на Anthropic Claude как primary-модель (Sonnet для генерации отчётов/виджетов/документов, Haiku для quick_qa) `kind=thinking` теперь приходит **штатно** на primary-пути — Anthropic extended thinking шлёт reasoning-дельты. На GLM-fallback-стадии (Z.AI, `supports_stream=false`) и у большинства OpenAI-моделей таких событий **нет**. То есть: thinking-блок стоит ожидать в типичном случае, но фронт по-прежнему НЕ должен полагаться на его наличие (на GLM-fallback он пропадёт). Если фронт получил хотя бы один `kind=thinking`, имеет смысл отрисовать collapsable-блок «AI рассуждает…» (раскрытый по умолчанию во время генерации, сворачиваемый после `final_message`). Если за весь турн ни одного `thinking`-event'а не было — блок просто не показывается.

После завершения турна (`status ∈ {done, error, cancelled}`) хедер блока меняется на duration-форму: «Думал N секунд» / «Думал N минут» (RU CLDR-плюрализация: one/few/many). Блок автоматически сворачивается. Раскрытие и сворачивание доступны через клик по хедеру; состояние индицирует chevron-иконка.

**Гарантии порядка:**

- Все `text_delta` события приходят строго **между `started` и `final_message`** (никогда после `final_message`).
- Деление текста на чанки определяется провайдером + backend-throttling (≈80 символов / 50 мс на flush). На один турн обычно несколько десятков `text_delta` событий, у коротких ответов может быть один.
- Конкатенация всех `kind=content` delta-payload'ов в порядке `sequence` даёт ТОТ ЖЕ контент, что и `final_message.payload.content`. Фронт может выбрать любой из двух источников:
  - **рендер из дельт** — для живого typewriter-эффекта в процессе;
  - **переписать из `final_message.content`** — для надёжности после завершения турна (canonical source of truth).
  Обычно так и делают: дельты строят progressive UI, по `final_message` происходит финальная синхронизация.

**Buffered fallback (важно для контракта):** если провайдер не поддерживает стриминг (например текущая версия Prism+Z.AI), backend всё равно эмитит **один** `text_delta` (`kind=content`) с полным текстом сразу после генерации, чтобы контракт для фронта оставался однородным. С точки зрения UI это выглядит как мгновенное появление всего ответа за один шаг — но фронту не нужно различать стриминг-режим от буферного.

#### `widget_variants` — двухшаговая генерация виджета (предложить → выбрать → создать)

> Только для чатов `type=widget_generation`.

В режиме генерации виджета AI **не создаёт виджет сразу**. На новый запрос он сначала
предлагает **2-4 варианта** (разные тип чарта / группировка / метрика под один запрос),
пользователь выбирает один, и только после выбора создаётся виджет. Это эмитится событием
`widget_variants`.

**Структура `payload`:**

```json
{
  "variants": [
    {
      "index": 1,
      "label": "Сделки по статусам — кольцевая",
      "config": {
        "primary_model": "EstateDeals",
        "group_by": { "fields": ["estateDealsStatuses.status_name"] },
        "aggregates": [ { "fn": "count", "as": "cnt" } ],
        "chart": { "type": "doughnut", "label_field": "estateDealsStatuses.status_name", "value_field": "cnt" },
        "period_field": "deal_date"
      }
    },
    {
      "index": 2,
      "label": "Выручка по статусам — столбцы",
      "config": { "...": "..." }
    }
  ]
}
```

| Поле | Описание |
|---|---|
| `variants[].index` | 1-based номер варианта (стабильный, для UX «вариант N»). |
| `variants[].label` | Короткое человеческое название варианта (строка, уже локализованная под язык пользователя). Показывать на карточке. |
| `variants[].config` | Полный валидный конфиг виджета (тот же формат, что `widget.config` / `POST /api/widgets`). Уже нормализован и прошёл shape-валидацию backend. |

**Как рендерить превью каждого варианта:**

Виджет ещё НЕ создан (в БД его нет, у него нет `id`). Чтобы показать живое превью чарта по
каждому варианту — отправь его `config` в `POST /api/widgets/preview`:

```
POST /api/widgets/preview
{ "config": { ...variants[i].config... }, "period_from"?: "YYYY-MM", "period_to"?: "YYYY-MM" }
→ 200 { labels[], datasets[], meta{ period_from, period_to, period_applied, row_count, ... } }
```

Эндпоинт ничего не сохраняет — считает chart-payload по эфемерному конфигу с фильтрами активной
компании. Можно запросить превью всех вариантов параллельно и нарисовать N карточек с мини-чартами +
`label` + кнопкой «Выбрать».

**Как выбор пользователя триггерит создание:**

После клика по варианту фронт отправляет обычное сообщение в чат, явно называя выбор —
например `POST /api/chats/{id}/messages` с `content: "Создай вариант 2"` (или
`"Выбрал: <label>"`). AI на следующем ходу вызовет `create_widget` с конфигом именно этого
варианта (он копирует config из результата `propose_widget_variants` как есть). Дальше — обычный
flow создания: `tool_call create_widget` → `dry_run_*` → `final_message`, и `chat.widget_id`
пинится к новому виджету.

> Альтернатива (если не хотите гонять второй AI-ход): фронт уже имеет полный `config` выбранного
> варианта из payload — можно создать виджет напрямую через `POST /api/widgets` с этим `config` +
> `name`. Но тогда виджет не будет привязан к чату (`chat.widget_id` останется пустым). Рекомендуемый
> путь — через сообщение «вариант N», чтобы сохранить связь чат↔виджет и единый timeline.

**Гарантии:**

- `widget_variants` приходит ВМЕСТО (не вместе с) немедленного `tool_call create_widget` на новом
  запросе. На одном турне обычно один `widget_variants`.
- Если AI всё же создал виджет сразу (пользователь явно попросил «просто создай») — события
  `widget_variants` не будет, придёт обычный `tool_call create_widget`.
- Правка существующего виджета («поменяй на pie») идёт через `update_widget` без вариантов.

#### `document_fields_proposed` — маппинг полей docx (предложить → подтвердить → сохранить)

В режиме `document_template`, когда чат привязан к загруженному Word-шаблону (`scope_type=document` +
`document_id` docx-шаблона), AI читает текст документа и его плейсхолдеры `${токен}` и предлагает
маппинг плейсхолдер→поле, эмитя событие `document_fields_proposed` ВМЕСТО немедленного сохранения.

`payload.placeholders[]` — массив объектов:

```json
{
  "token": "agreement_number",
  "suggested_field": "agreement_number",
  "model": "EstateDeals",
  "source": "macrodata",
  "confidence": 0.95
}
```

- `token` — имя плейсхолдера БЕЗ `${}`.
- `suggested_field` — предложенное подставляемое поле: либо ключ field-catalog (`source: "catalog"`),
  либо реальное поле модели MacroData (`source: "macrodata"`, тогда заполнено `model`).
- `confidence` — 0..1 или `null` (если AI не указал уверенность).

**Что делает фронт (M8):**
1. На `document_fields_proposed` отрисовать карточки маппинга (зеркало `WidgetVariantsPanel.vue`) —
   на каждый токен Select с предложенным значением, сгруппированный по бакету field-catalog.
2. Пользователь подтверждает / правит маппинг.
3. Сохранить через `PUT /api/documents/{id}` с `config.field_mapping` (объект `{токен: ключ_поля}`).
   Сам AI маппинг НЕ сохраняет — это эфемерное предложение.

**Гарантии:**
- `document_fields_proposed` приходит ВМЕСТО немедленного сохранения маппинга.
- Токены, на которые AI не нашёл уверенного поля, могут не попасть в `placeholders[]` или прийти с
  низким `confidence` — фронт оставляет их пользователю на ручной маппинг.
- Генерация кастомного HTML-КП (другой сценарий `document_template`) НЕ эмитит это событие — там идёт
  обычный `tool_call generate_document_template` + dry-run.

**Пример накопления на фронте:**

```javascript
let assistantContent = '';
let assistantThinking = '';

es.addEventListener('text_delta', (e) => {
  const { payload } = JSON.parse(e.data);
  if (payload.kind === 'content') {
    assistantContent += payload.delta;
    renderContent(assistantContent); // partial render
  } else if (payload.kind === 'thinking') {
    assistantThinking += payload.delta;
    renderThinking(assistantThinking);
  }
});

es.addEventListener('final_message', (e) => {
  const { payload } = JSON.parse(e.data);
  // canonical content — фронт может перетереть аккумулятор
  assistantContent = payload.content;
  renderContent(assistantContent);
});
```

**Sentinel `event: done` — закрытие потока:**

```
event: done
data: {"status": "done"}

```

Значения `status` в done-sentinel: `done`, `error`, `cancelled`. После получения фронт закрывает `EventSource` и перечитывает сообщение через `GET /api/chats/{id}/messages` для финального контента.

**Wall-clock budget:** 480 секунд на одно подключение. По истечении backend закрывает поток без sentinel — браузер (EventSource) переподключается автоматически с `Last-Event-ID`, и поток возобновляется с последнего отправленного события.

**JavaScript-пример с `EventSource`:**

```javascript
const es = new EventSource(`/api/chats/${chatId}/stream/${messageId}?since=0`, {
  withCredentials: true, // для Sanctum cookie-auth
});

es.addEventListener('tool_call', (e) => {
  const { payload } = JSON.parse(e.data);
  console.log('Tool called:', payload.tool);
});

es.addEventListener('final_message', (e) => {
  const { payload } = JSON.parse(e.data);
  renderAssistantContent(payload.content);
});

es.addEventListener('done', (e) => {
  const { status } = JSON.parse(e.data);
  es.close();
  // перечитать сообщение для финального контента и metadata
  fetchMessage(messageId);
});

es.onerror = () => {
  // EventSource reconnects automatically; если нужно показать индикатор —
  // можно выставить loading-state здесь
};
```

> `?since=lastEventId` при ручном reconnect — для Bearer-token авторизации (EventSource не поддерживает Authorization header). В этом случае используйте `fetch()` + `ReadableStream` вместо `EventSource`, передавая токен в header вручную.

**Возможные ошибки:**

| HTTP-код | Условие |
|---|---|
| 403 | Чат не принадлежит активной компании пользователя |
| 404 | Сообщение не принадлежит указанному чату (или не существует) |
| 422 | `message.role !== 'assistant'` — нельзя стримить user-сообщения |

---

## Reload-восстановление через batch endpoint

```
GET /api/chats/{chat}/messages/{message}/events?since=0&limit=100
```

Возвращает пакет уже записанных событий для assistant-сообщения в JSON-формате. Предназначен для восстановления timeline после перезагрузки страницы — когда AI-турн уже завершён и SSE-подписка не нужна.

**Query-параметры:**

| Параметр | Тип | По умолчанию | Описание |
|---|---|---|---|
| `since` | int | 0 | Cursor: вернуть события с sequence > since. `0` — с начала |
| `limit` | int | 100 | Число событий на страницу. Макс. 500 |

**Response (200):**

```json
{
  "events": [
    {
      "sequence": 1,
      "type": "started",
      "payload": {},
      "created_at": "2026-05-20T08:32:11+00:00"
    },
    {
      "sequence": 2,
      "type": "tool_call",
      "payload": { "name": "probe_data", "arguments": "..." },
      "created_at": "2026-05-20T08:32:14+00:00"
    }
  ],
  "message_status": "done",
  "has_more": false,
  "next_cursor": null
}
```

| Поле | Тип | Описание |
|---|---|---|
| `events` | array | Упорядоченный по sequence массив событий |
| `events[].sequence` | int | Монотонный порядковый номер в рамках сообщения |
| `events[].type` | string | Тип события (те же значения что у SSE-потока) |
| `events[].payload` | object | Данные события (зависят от type) |
| `events[].created_at` | string | ISO 8601 |
| `message_status` | string | Текущий статус сообщения (done / error / cancelled / running / pending) |
| `has_more` | bool | Есть ли следующая страница |
| `next_cursor` | int\|null | Sequence последнего события в текущей странице — передать в `?since=` для следующей |

**Когда использовать:** фронт делает `GET /api/chats/{chat}/messages` при reload, получает список с `status` и `events_count`. Для каждого assistant-сообщения с `status` in (`done`, `error`, `cancelled`) и `events_count > 0` — опционально запрашивает `/events` для рендера timeline (tool-call шаги, сообщения об ошибках и т.п.).

---

## Полная последовательность для фронта

Псевдокод для корректной инициализации при открытии / перезагрузке чата:

```
// Шаг 1: Получить список сообщений
messages = GET /api/chats/{chat}/messages

// Шаг 2: Обработать сообщения по статусу
for each message in messages where role === 'assistant':

  if message.status in ['pending', 'running']:
    // Активный турн — подключаемся к SSE для получения событий в реальном времени
    // since=0 чтобы получить уже накопленные события + хвост
    openEventSource(`/stream/${message.id}?since=0`)

  else if message.status in ['done', 'error', 'cancelled'] and message.events_count > 0:
    // Завершённый турн — опционально восстанавливаем timeline
    events = GET /api/chats/{chat}/messages/${message.id}/events?since=0&limit=100
    renderTimeline(message.id, events.events)
    // если events.has_more — подгружать следующие страницы через next_cursor

// Шаг 3: Новое сообщение
user submits text:
  response = POST /api/chats/{chat}/messages { content: "..." }
  // response.assistant_message.status === 'pending'
  // response.stream_url === '/api/chats/{chat}/stream/{message_id}'
  openEventSource(response.stream_url + '?since=0')

// Шаг 4: SSE done-sentinel получен
on event 'done':
  es.close()
  updatedMessage = GET /api/chats/{chat}/messages (или отдельный endpoint)
  renderFinalContent(updatedMessage)
```

**Когда SSE не нужен (polling-fallback):** если браузер не поддерживает `EventSource` или используется Bearer-token (не cookie), фронт может полить `GET /api/chats/{chat}/messages` с интервалом 2-3 сек до смены `assistant.status` с pending/running на terminal.

---

## Cross-page chat activation (router-state handoff)

Когда нужно создать чат на одной странице и сразу открыть его на другой (например, плитка «Сгенерировать собственный отчёт» на `/reports` → `/ai-reports`), используется паттерн router-state handoff:

1. Source page: `chat.createAndOpenChat('report_generation', { setCurrent: false })` → получить `chatId`.
2. Source page: `router.push({ name: 'AiReports', state: { activateChatId: chatId } })`.
3. Destination page (`useChatPage.initScope`): после `fetchChats()` — читает `history.state.activateChatId`, сразу гасит его через `window.history.replaceState` (one-shot), сверяет что чат есть в списке и `chat.type === scope.type` → `loadChat(id)`.

Почему не query-param: не загрязняет URL, history корректна.
Почему не Pinia: стейт навигационный, не клиентский.
Почему не `pendingFirstMessage`: тот паттерн — для prefill-промпта (action-marker CTA). Здесь промпта нет.
Паттерн реализован в `useChatPage.consumeActivateChatIdFromRouterState` и `useReportsPageActions.generateCustomReport`.

---

## MiniChatWidget — автоинжект контекста отчёта

`MiniChatWidget.vue` (`front/src/components/chat/`) — overlay-чат в Toolbox. При первом сообщении от пользователя на странице отчёта (`/reports/{id}`) фронт **препендит** к тексту сообщения системный prefix с контекстом.

### Формат prefix (полный)

```
[Контекст отчёта: {report.title[locale]}]
Конфиг: {JSON.stringify(report.config)}
Применённые фильтры: {JSON.stringify(report.filters_applied)}
---
{текст пользователя}
```

Итоговое содержимое отправляется в `POST /api/chats/{id}/messages` как обычный `content`-текст. Backend получает стандартный user-message — prefix **не выделен** на уровне API-контракта, но **виден** в `chat_messages.content` в БД и в ответах `GET /api/chats/{id}`.

### Кап на размер

Если `JSON.stringify(report.config)` превышает **2 KB** — вместо полного конфига используется slim-fallback:

```json
{"primary_model": "...", "columns": [{"field": "...", "type": "..."}, ...]}
```

Фронт вычисляет размер строки перед сборкой prefix и выбирает вариант автоматически.

### Правила инжекта

- Prefix инжектируется **только один раз** — в самое первое сообщение чата (или в первое сообщение нового чата при смене отчёта-контекста). Последующие сообщения в том же чате отправляются без prefix.
- На страницах вне `/reports/{id}` (список отчётов, AI-конструктор, настройки) — `reportContext` равен `null`, prefix не добавляется, чат открывается как `quick_qa` без контекста.
- Контекст читается из Pinia store `useReportContextStore` (`front/src/stores/reportContext.ts`). ReportPage обновляет store при каждом изменении отчёта / фильтров / locale (watchEffect, deep:true, immediate:true).

### Expand в новую вкладку

Кнопка `↗` в заголовке MiniChatWidget открывает полноэкранный чат:

```
window.open('/ai-reports?activate={chatId}', '_blank')
```

AiReportsPage в `onMounted` → `initScope()` читает `route.query.activate`, вызывает `loadChat(id)`, затем `router.replace({ query: {} })` (one-shot, убирает параметр из адресной строки). Этот паттерн дополняет `history.state` handoff (описан выше) — применяется именно для cross-tab, так как разные вкладки не разделяют history stack.
