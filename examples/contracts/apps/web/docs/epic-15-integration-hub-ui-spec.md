# ТЗ: Эпик 15 — Integration Hub + Calldown

**Версия:** 1.0
**Дата:** 2026-06-02
**Автор:** designer
**Исполнитель:** frontend-specialist
**Зависит от:** Эпик 11 (APIToken, Webhooks — уже в проде), Эпик 2 (Activities kind=call — уже в проде)

---

## Cover

### Цель

Превратить разрозненные разделы `/admin/integrations`, `/admin/api-tokens`, `/admin/webhooks` в единый **Integration Hub** — центральную точку входа для разработчиков и менеджеров, подключающих внешние системы к MACRO CRM.

Пять направлений работы:

1. **Developer Portal** — публичная документация API с OpenAPI embed, markdown-гайдами, примерами кода. Доступна без авторизации.
2. **API Sandbox playground** — интерактивная консоль для тестирования эндпоинтов с sandbox-токеном.
3. **Marketplace коннекторов** — перестройка `/admin/integrations` из одиночного экрана Google Drive → grid карточек всех интеграций.
4. **Calldown Integration** — wizard настройки телефонии (Mango/UIS/Custom webhook) + журнал звонков + детали звонка с MP3 player и транскрипцией.
5. **Logs Viewer** — агрегированная страница логов: webhook deliveries + API request logs + Calldown calls.

### Что уже есть (НЕ переписывать)

- `/admin/api-tokens/page.tsx` — управление токенами. Будем расширять вкладкой «Логи».
- `/admin/webhooks/page.tsx` — webhooks + deliveries. Карточка в Marketplace ссылается сюда.
- `/admin/integrations/page.tsx` — сейчас только Google Drive. **Полностью заменяем** на Marketplace grid.
- `components/ApiTokens/*`, `components/Webhooks/*` — остаются без изменений.
- `ADMIN_ITEMS` в `Sidebar.tsx` уже содержит `{ href: "/admin/integrations", icon: "bi-plug", label: "Интеграции" }` — не трогаем.

### Новые пути

| Путь | Назначение |
|---|---|
| `/developers` | Developer Portal (публичный, без auth) |
| `/developers/sandbox` | Sandbox playground |
| `/admin/integrations` | Marketplace коннекторов (заменяет текущую страницу) |
| `/admin/integrations/calldown` | Настройка Calldown |
| `/admin/integrations/calldown/calls` | Журнал звонков |
| `/admin/integrations/logs` | Logs viewer |

---

## Раздел 1: Developer Portal — `/developers`

### Зачем

Команда интеграторов (1С, Bitrix, собственные скрипты клиентов) должна иметь одно место, куда приходить за документацией API MACRO CRM. Сейчас нет ничего — люди просят Богдана скинуть ссылку на `/api/docs`.

### Где в коде

- Страница: `apps/web/src/app/(app)/developers/page.tsx`
- Подстраница sandbox: `apps/web/src/app/(app)/developers/sandbox/page.tsx`
- Компоненты:
  - `apps/web/src/components/Developers/CodeTabs.tsx`
  - `apps/web/src/components/Developers/QuickStartSection.tsx`
  - `apps/web/src/components/Developers/OpenAPIEmbed.tsx`
  - `apps/web/src/components/Developers/WebhooksGuideSection.tsx`
  - `apps/web/src/components/Developers/OAuthGuideSection.tsx`

**Важно:** страница `/developers` и `/developers/sandbox` доступны **без авторизации** (публичный URL). Логика: если пользователь залогинен — показываем его admin-токен в Quick Start curl-примере. Если не залогинен — токен заменяется на плейсхолдер `<YOUR_API_TOKEN>`.

### Wireframe — `/developers`

```
┌─────────────────────────────────────────────────────────────────────┐
│ [Sidebar]  │  [PageHeader: MACRO CRM API & Integrations]            │
│            │  Документация для разработчиков интеграций             │
│            ├─────────────────────────────────────────────────────────┤
│            │                                                         │
│            │ ┌─────────────────────────────────────────────────────┐│
│            │ │  БЫСТРЫЙ СТАРТ                              card    ││
│            │ │  ┌──────────────┐  ┌───────────────────────────┐   ││
│            │ │  │ 1. Получи    │  │ curl -H "Authorization:   │   ││
│            │ │  │    токен     │  │  Bearer <token>" \        │   ││
│            │ │  │ → /admin/    │  │  https://…/api/leads      │   ││
│            │ │  │   api-tokens │  │                           │   ││
│            │ │  └──────────────┘  │ [Копировать]              │   ││
│            │ │                    └───────────────────────────┘   ││
│            │ └─────────────────────────────────────────────────────┘│
│            │                                                         │
│            │ ┌─────────────────────────────────────────────────────┐│
│            │ │  СПРАВОЧНИК API (OpenAPI)                   card    ││
│            │ │  [iframe: /api/docs — Swagger UI]                   ││
│            │ │  h=600px, border-none, w-full                       ││
│            │ └─────────────────────────────────────────────────────┘│
│            │                                                         │
│            │ ┌─────────────────────────────────────────────────────┐│
│            │ │  ПРИМЕРЫ КОДА                               card    ││
│            │ │  [Python] [Node.js] [curl]   ← табы                 ││
│            │ │  ┌─────────────────────────────────────────────┐    ││
│            │ │  │ import httpx                                 │    ││
│            │ │  │ response = httpx.get(…)                      │    ││
│            │ │  │                        [Копировать]          │    ││
│            │ │  └─────────────────────────────────────────────┘    ││
│            │ └─────────────────────────────────────────────────────┘│
│            │                                                         │
│            │ ┌─────────────────────────────────────────────────────┐│
│            │ │  WEBHOOKS (исходящие уведомления)           card    ││
│            │ │  Как настроить приём событий CRM                    ││
│            │ │  → Перейти к настройке вебхуков (/admin/webhooks)   ││
│            │ └─────────────────────────────────────────────────────┘│
│            │                                                         │
│            │ ┌─────────────────────────────────────────────────────┐│
│            │ │  OAUTH 2.0 ДЛЯ ПАРТНЁРОВ                   card    ││
│            │ │  Пошаговый guide: Authorization Code Flow           ││
│            │ │  [accordion: шаги 1..5]                             ││
│            │ └─────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────────┘
```

### Композиция

- **Layout:** стандартный `(app)/layout.tsx` с Sidebar
- **Корневая страница:** `apps/web/src/app/(app)/developers/page.tsx`
- **Подкомпоненты:**
  - `QuickStartSection` — curl-блок + ссылка на /admin/api-tokens
  - `OpenAPIEmbed` — iframe Swagger
  - `CodeTabs` — три таба Python/Node.js/curl с pre-блоком и кнопкой копировать
  - `WebhooksGuideSection` — статичный markdown-текст + ссылка на /admin/webhooks
  - `OAuthGuideSection` — аккордеон 5 шагов

### UI компоненты

**PageHeader:**
- `title="MACRO CRM API & Integrations"`
- `description="Документация для разработчиков интеграций"`
- Actions: кнопка `btn-secondary` с `bi-box-arrow-up-right` — «Открыть Swagger» (target=_blank на `/api/docs`)

**QuickStartSection** (card p-6):
- Заголовок `text-h4`: «Быстрый старт»
- Двухколоночная сетка `grid grid-cols-1 md:grid-cols-2 gap-4`:
  - Левая: шаги нумерованным списком (`ol list-decimal`), шаг 1 — ссылка на `/admin/api-tokens` с иконкой `bi-key-fill`
  - Правая: `pre` блок с классами `bg-gray-900 text-green-400 rounded-lg p-4 text-sm font-mono overflow-x-auto`, токен подставляется из `useMe()` если залогинен, иначе `<YOUR_API_TOKEN>`
  - Кнопка копировать `btn-ghost text-xs` с `bi-clipboard` — копирует curl в буфер

**OpenAPIEmbed** (card p-0):
- Заголовок внутри `p-4 border-b border-gray-200`: «Справочник API (OpenAPI)» + link «Открыть в новой вкладке»
- `<iframe src="/api/docs" className="w-full border-none rounded-b-lg" style={{ height: "600px" }} title="MACRO CRM OpenAPI docs" />`

**CodeTabs** (card p-6):
- Заголовок: «Примеры кода»
- Три таба: `Python` / `Node.js` / `curl` — стиль как в `/admin/webhooks/page.tsx` (border-b border-gray-200, active — `border-primary text-primary`)
- Pre-блок: `bg-gray-900 text-green-400 rounded-lg p-4 text-sm font-mono overflow-x-auto`
- Кнопка `bi-clipboard` в правом верхнем углу `pre`, `btn-ghost text-xs`
- Контент кода — статичные строки, хардкод в компоненте (не MDX, не запросы к backend)

**WebhooksGuideSection** (card p-6):
- Заголовок: «Webhooks — исходящие уведомления»
- Текст: описание концепции (2-3 параграфа), список поддерживаемых событий (`leads.*`, `deals.*`, `contracts.*`)
- CTA кнопка `btn-secondary` с `bi-broadcast-pin`: «Настроить вебхуки» → `/admin/webhooks`

**OAuthGuideSection** (card p-6):
- Заголовок: «OAuth 2.0 для партнёров»
- `<details>`/`<summary>` аккордеон для каждого шага. 5 шагов:
  1. Регистрация OAuth App (ссылка на /admin/oauth-apps — будущий путь)
  2. Redirect пользователя на `/oauth/authorize`
  3. Получение code и обмен на access_token
  4. Использование Bearer access_token в запросах
  5. Обновление и отзыв токена

### States

- **Loading:** нет (страница статическая, iframe загружается сам)
- **OpenAPI iframe error:** если `/api/docs` недоступен — показать fallback-ссылку «Открыть docs напрямую»
- **Незалогинен:** curl-пример показывает `<YOUR_API_TOKEN>` вместо реального, без лишних сообщений

### Interactions

| Элемент | Действие | Результат |
|---|---|---|
| Кнопка «Открыть Swagger» в PageHeader | click | `window.open("/api/docs", "_blank")` |
| Кнопка `bi-clipboard` в curl-блоке | click | `navigator.clipboard.writeText(...)`, иконка меняется на `bi-check-lg` на 2с |
| Кнопка `bi-clipboard` в CodeTabs | click | аналогично |
| Таб Python/Node.js/curl | click | смена контента pre-блока, состояние в `useState` |
| Ссылка «Настроить вебхуки» | click | переход на `/admin/webhooks` |
| Ссылка «Получить токен» | click | переход на `/admin/api-tokens` |
| Details/summary в OAuth guide | click | toggle раскрытия шага |

### Адаптивность

Desktop-first. Mobile — TBD (эпик mobile). Iframe Swagger на мобиле скроллится горизонтально.

### Тексты (RU)

- Заголовок страницы: `MACRO CRM API & Integrations`
- PageHeader description: `Документация для разработчиков интеграций`
- Кнопка PageHeader: `Открыть Swagger`
- Секция 1 заголовок: `Быстрый старт`
- Шаг 1: `Получи API-токен в разделе API Токены`
- Шаг 2: `Добавь заголовок Authorization: Bearer <токен> к каждому запросу`
- Шаг 3: `Базовый URL: https://contracts.macroglobal.tech/api`
- curl-блок label: `Пример запроса`
- Кнопка копировать: `Копировать`
- Секция OpenAPI заголовок: `Справочник API (OpenAPI)`
- Link рядом: `Открыть в новой вкладке`
- Секция кода заголовок: `Примеры кода`
- Секция webhooks заголовок: `Webhooks — исходящие уведомления`
- Webhooks description: `MACRO CRM отправляет события в твою систему при создании и изменении лидов, сделок и договоров. Подпишись на нужные события и получай данные в реальном времени.`
- Кнопка webhooks: `Настроить вебхуки`
- Секция OAuth заголовок: `OAuth 2.0 для партнёров`
- OAuth description: `Используй Authorization Code Flow, чтобы пользователи MACRO CRM могли авторизовывать твоё приложение без передачи пароля.`
- Шаги OAuth: см. список выше

### Связь с backend

- Нет SWR-запросов. Только `useMe()` для подстановки токена в curl-пример.
- Iframe: `src="/api/docs"` — FastAPI Swagger, уже работает.

---

## Раздел 2: Sandbox Playground — `/developers/sandbox`

### Зачем

Разработчики интеграций должны тестировать запросы к API без риска испортить реальные данные. Sandbox использует отдельный тип токена (prefix `sandbox_`) и возвращает фиктивные данные.

### Где в коде

- Страница: `apps/web/src/app/(app)/developers/sandbox/page.tsx`
- Компоненты:
  - `apps/web/src/components/Developers/SandboxPlayground.tsx`
  - `apps/web/src/components/Developers/RequestHistorySidebar.tsx`
  - `apps/web/src/components/Developers/SnippetSaveModal.tsx`

### Wireframe — `/developers/sandbox`

```
┌─────────────────────────────────────────────────────────────────────┐
│ [Sidebar]  │  [PageHeader: API Sandbox]          [+ Создать токен] │
│            │  Тестируй запросы без риска для реальных данных        │
│            ├──────────────────────────────────────────────┬─────────┤
│            │                                              │История  │
│            │ ┌──────────────────────────────────────────┐│ запросов│
│            │ │ Метод      URL                            ││─────────│
│            │ │ [GET▾]  [input: /api/sandbox/leads  ]    ││GET /api…│
│            │ ├──────────────────────────────────────────┤│POST /ap…│
│            │ │ Заголовки                                 ││GET /api…│
│            │ │ Authorization: Bearer [sandbox_xxx…]      ││─────────│
│            │ │ Content-Type: application/json            ││[Очистить│
│            │ │ [+ Добавить заголовок]                    ││ историю]│
│            │ ├──────────────────────────────────────────┤│         │
│            │ │ Тело запроса (JSON)                       ││         │
│            │ │ ┌────────────────────────────────────┐   ││         │
│            │ │ │ {                                  │   ││         │
│            │ │ │   "name": "Тестовый лид"           │   ││         │
│            │ │ │ }                                  │   ││         │
│            │ │ └────────────────────────────────────┘   ││         │
│            │ │ [Отправить]  [Сохранить сниппет]          ││         │
│            │ ├──────────────────────────────────────────┤│         │
│            │ │ Ответ                     200 OK ● green ││         │
│            │ │ ┌────────────────────────────────────┐   ││         │
│            │ │ │ [{"id":1001,"name":"Тест…"}]        │   ││         │
│            │ │ └────────────────────────────────────┘   ││         │
│            │ └──────────────────────────────────────────┘│         │
└─────────────────────────────────────────────────────────────────────┘
```

### Композиция

- **Layout:** стандартный `(app)/layout.tsx`
- **Корневая страница:** `apps/web/src/app/(app)/developers/sandbox/page.tsx`
- **Подкомпоненты:**
  - `SandboxPlayground` — основная панель (метод + URL + headers + body + response)
  - `RequestHistorySidebar` — боковая история запросов
  - `SnippetSaveModal` — модал сохранения именного сниппета

### UI компоненты

**PageHeader:**
- `title="API Sandbox"`
- `description="Тестируй запросы без риска для реальных данных"`
- Actions: `btn-secondary bi-key-fill` — «Создать sandbox-токен» → открывает `CreateApiTokenModal` с `is_sandbox=true`

**Главная панель (SandboxPlayground):**

Область `card` с тремя секциями через `border-b border-gray-200`:

**1. Строка запроса:**
- Метод: `<select className="input w-24 font-mono">` с вариантами GET / POST / PUT / PATCH / DELETE
- URL: `<input className="input flex-1 font-mono" placeholder="/api/sandbox/leads">` — вводится путь без базового URL (базовый URL подставляется при отправке)
- Подсказка под строкой: `text-xs text-gray-500`: `Базовый URL: https://contracts.macroglobal.tech`

**2. Заголовки запроса:**
- Список пар ключ-значение. Каждая строка — `input.input` для ключа и `input.input` для значения + кнопка `btn-ghost bi-x` удалить
- Authorization заголовок добавляется автоматически, если у пользователя есть sandbox-токен (первый из списка APIToken с `is_sandbox=true` через SWR `/api-tokens`)
- Кнопка `btn-ghost bi-plus` — «Добавить заголовок»

**3. Тело запроса:**
- `<textarea className="input font-mono text-sm w-full" rows={8}>` с placeholder `{}`
- Валидация JSON: если невалидный JSON при отправке — inline `text-danger text-xs` под textarea
- Textarea скрыта если метод GET/DELETE

**4. Кнопки действий:**
- `btn-primary bi-send` — «Отправить»
- `btn-secondary bi-bookmark-plus` — «Сохранить сниппет» → `SnippetSaveModal`
- Loading state при отправке: кнопка disabled + текст «Отправляем…»

**5. Панель ответа:**
- Status badge: `200 OK` — `bg-success/10 text-success`, `4xx` — `bg-danger/10 text-danger`, `5xx` — `bg-danger/20 text-danger`
- Response time: `text-xs text-gray-500`: `241 мс`
- Response body: `pre bg-gray-900 text-green-400 rounded-lg p-4 text-sm font-mono overflow-auto max-h-80`
- Кнопка `btn-ghost bi-clipboard text-xs` — «Копировать ответ»

**RequestHistorySidebar:**
- Фиксированная ширина `w-56`, список последних 20 запросов из `localStorage` (ключ `sandbox_history`)
- Каждая запись: метод в `badge` + сокращённый URL + статус-код
- Click → заполняет поля формы (метод / URL / headers / body)
- Кнопка «Очистить историю» — `btn-ghost text-danger text-xs`

**SnippetSaveModal:**
- Использует компонент `Modal` (существующий)
- Поле «Название сниппета» `input`
- Кнопки: `btn-ghost` Отмена / `btn-primary` Сохранить
- Сохраняет в `localStorage` `sandbox_snippets` (массив)

### States

- **Loading:** кнопка «Отправить» disabled + текст «Отправляем…»
- **Нет sandbox-токена:** баннер `bg-warning/10 text-warning rounded p-3 text-sm` с текстом «Создай sandbox-токен для авторизации запросов» + кнопка `btn-secondary bi-key-fill`
- **Error (невалидный JSON):** inline `text-danger text-xs` под textarea
- **Error (сеть):** inline `text-danger` в панели ответа
- **История пуста:** текст `text-gray-400 text-xs text-center py-4` в боковой панели

### Interactions

| Элемент | Действие | Результат |
|---|---|---|
| Кнопка «Отправить» | click | `fetch` запрос через `api()` или прямой `fetch` с credentials, отображение ответа |
| Метод GET/DELETE | select | textarea тела скрывается (`hidden`) |
| Запись в истории | click | заполнение полей формы из записи |
| «Сохранить сниппет» | click | открытие `SnippetSaveModal` |
| «Очистить историю» | click | `confirm` → очистка `localStorage` → обновление списка |
| «Создать sandbox-токен» | click | открытие `CreateApiTokenModal` с `is_sandbox=true` |

**Важно:** запросы из Sandbox идут напрямую с фронтенда к API (не через Next.js proxy). `fetch(baseUrl + path, { headers, method, body, credentials: "same-origin" })`. Результат — статус + тело — показывается в панели ответа. Таймаут 30с.

### Адаптивность

Desktop-first. Боковая история на mobile — скрыта (TBD эпик mobile).

### Тексты (RU)

- Заголовок страницы: `API Sandbox`
- PageHeader description: `Тестируй запросы без риска для реальных данных`
- Кнопка PageHeader: `Создать sandbox-токен`
- Лейбл метода: `Метод`
- Лейбл URL: `URL запроса`
- Подсказка URL: `Базовый URL: https://contracts.macroglobal.tech`
- Лейбл headers: `Заголовки`
- Кнопка добавить заголовок: `Добавить заголовок`
- Лейбл body: `Тело запроса (JSON)`
- Placeholder body: `{}`
- Кнопка отправить: `Отправить`
- Кнопка loading: `Отправляем…`
- Кнопка сохранить: `Сохранить сниппет`
- Заголовок панели ответа: `Ответ`
- Кнопка копировать ответ: `Копировать`
- Заголовок истории: `История запросов`
- Кнопка очистить: `Очистить историю`
- Баннер нет токена: `Создай sandbox-токен для авторизации запросов`
- Ошибка JSON: `Невалидный JSON — проверь тело запроса`
- История пуста: `Нет запросов`
- Modal сниппета заголовок: `Сохранить сниппет`
- Modal поле: `Название сниппета`
- Error сети: `Ошибка соединения — проверь доступность API`

### Связь с backend

- `GET /api/api-tokens` — получить sandbox-токен пользователя (фильтр `is_sandbox=true` на фронте)
- Sandbox-запросы: прямой `fetch` к `/api/sandbox/*` (новые эндпоинты — требуется правка backend)
- Сниппеты и история: `localStorage` (нет backend)

**Требуется правка backend:** новые эндпоинты `/api/sandbox/*` из Obsidian-плана (`GET /api/sandbox/leads` и др.), поле `is_sandbox` в `APIToken`.

---

## Раздел 3: Marketplace коннекторов — `/admin/integrations`

### Зачем

Текущая страница `/admin/integrations` показывает только Google Drive. С добавлением Calldown, OAuth и потенциальных новых коннекторов нужна единая точка каталога — с понятным статусом каждой интеграции и входом в wizard настройки.

**Текущий файл `/admin/integrations/page.tsx` полностью заменяется.** Google Drive переезжает в одну из карточек Marketplace.

### Где в коде

- Страница: `apps/web/src/app/(app)/admin/integrations/page.tsx` (переписываем)
- Компоненты:
  - `apps/web/src/components/Integrations/IntegrationCard.tsx`
  - `apps/web/src/components/Integrations/IntegrationSetupModal.tsx`
  - `apps/web/src/components/Integrations/GoogleDriveSetup.tsx` (вынести из старого page.tsx)
  - `apps/web/src/components/Integrations/TelephonySetupModal.tsx` (для Mango/UIS)

### Wireframe

```
┌─────────────────────────────────────────────────────────────────────┐
│ [Sidebar]  │  [PageHeader: Интеграции]                              │
│            │  Подключай внешние сервисы к MACRO CRM                 │
│            ├─────────────────────────────────────────────────────────┤
│            │                                                         │
│            │  [Поиск по названию…]  [Все▾]  [Подключено▾]          │
│            │                                                         │
│            │  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐ │
│            │  │ [иконка] │ │ [иконка] │ │ [иконка] │ │ [иконка] │ │
│            │  │ Google   │ │ Telegram │ │ Mango    │ │   UIS    │ │
│            │  │ Drive    │ │          │ │ Office   │ │          │ │
│            │  │Подключено│ │Подключено│ │Доступно  │ │Доступно  │ │
│            │  │[Управлять│ │[Управлять│ │[Подключить│ │[Подключить│ │
│            │  └──────────┘ └──────────┘ └──────────┘ └──────────┘ │
│            │                                                         │
│            │  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐ │
│            │  │ [иконка] │ │ [иконка] │ │ [иконка] │ │ [иконка] │ │
│            │  │ Whisper  │ │ Yandex   │ │   1С:    │ │ Bitrix24 │ │
│            │  │  (OpenAI)│ │   Disk   │ │Предприятие│ │          │ │
│            │  │Доступно  │ │ Скоро    │ │Через API │ │  Скоро   │ │
│            │  │[Настроить│ │  (disabled│ │[Документация│[disabled]│ │
│            │  └──────────┘ └──────────┘ └──────────┘ └──────────┘ │
└─────────────────────────────────────────────────────────────────────┘
```

### Композиция

- **Корневая страница:** `apps/web/src/app/(app)/admin/integrations/page.tsx`
- **Подкомпоненты:**
  - `IntegrationCard` — одна карточка коннектора
  - `IntegrationSetupModal` — оболочка wizard/modal для настройки
  - `GoogleDriveSetup` — перенос логики из старого page.tsx
  - `TelephonySetupModal` — wizard Mango/UIS (переиспользуется из Раздела 4)
- **Реюз:** `PageHeader`, SWR `useSWR<MarketplaceItem[]>("/integrations/marketplace", fetcher)`

### Конфиг карточек (статический массив в коде)

Массив `INTEGRATIONS_CONFIG` в `IntegrationCard.tsx` или отдельном `integrationsConfig.ts`:

```
id | label | icon | description | status-field | cta | href/action
```

Карточки (начальный набор, 8 штук):

| id | Название | Bootstrap Icon / img | Описание | Статус | CTA |
|---|---|---|---|---|---|
| `google_drive` | Google Drive | `bi-google` | Выгрузка договоров в папки Drive | API (`/integrations/google-drive/status`) | Управлять / Подключить |
| `telegram` | Telegram | `bi-telegram` | Уведомления согласований через бота | hardcode Connected | Управлять (link /admin/channels) |
| `mango` | Mango Office | `bi-telephone-fill` | Запись и расшифровка звонков | API `marketplace[id].status` | Подключить (wizard) |
| `uis` | UIS | `bi-headset` | Запись и расшифровка звонков | API `marketplace[id].status` | Подключить (wizard) |
| `whisper` | Whisper (OpenAI) | `bi-mic-fill` | Автоматическая расшифровка записей звонков | API `marketplace[id].status` | Настроить |
| `yandex_disk` | Яндекс Диск | `bi-cloud-fill` | Хранение записей звонков | `coming_soon` | — |
| `1c` | 1С:Предприятие | `bi-box` | Интеграция через Public API | `docs` | Документация |
| `bitrix24` | Bitrix24 | `bi-diagram-2` | Синхронизация контактов | `coming_soon` | — |

### UI компоненты

**Фильтры над grid:**
- `<input className="input" placeholder="Поиск по названию…">` — фильтрация массива на клиенте
- `<select className="input w-40">` — «Все категории» / «Телефония» / «Хранилище» / «Мессенджеры» / «ERP»
- `<select className="input w-40">` — «Все статусы» / «Подключено» / «Доступно» / «Скоро»

**IntegrationCard:**
- `card p-5 flex flex-col gap-3 hover:shadow-md transition-shadow`
- Иконка: `<i className="bi bi-{icon} text-3xl text-primary">` или `<img>` для branded лого
- Название: `font-semibold text-gray-900`
- Описание: `text-sm text-gray-600 flex-1`
- Статус badge:
  - `connected`: `bg-success/10 text-success badge` — «Подключено»
  - `available`: `bg-info/10 text-info badge` — «Доступно»
  - `coming_soon`: `bg-gray-100 text-gray-500 badge` — «Скоро»
  - `docs`: `bg-gray-100 text-gray-700 badge` — «Через API»
- CTA кнопка (внизу карточки):
  - `connected`: `btn-secondary` — «Управлять»
  - `available`: `btn-primary` — «Подключить»
  - `coming_soon`: `btn-secondary disabled opacity-50` — «Скоро»
  - `docs`: `btn-ghost bi-box-arrow-up-right` — «Документация»

**Grid layout:** `grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4`

### States

- **Loading:** скелетон 8 карточек `h-44 bg-gray-100 animate-pulse rounded-xl`
- **Error:** inline `text-danger` над grid
- **Empty (фильтр):** текст по центру «Нет интеграций с такими фильтрами», `bi-plug text-4xl text-gray-300`
- **Статус из API:** каждая карточка независимо обновляет статус через `useSWR("/integrations/marketplace", fetcher)`

### Interactions

| Элемент | Действие | Результат |
|---|---|---|
| Поле поиска | input | фильтрация карточек на клиенте по `name.toLowerCase().includes(q)` |
| Select категория | change | фильтрация по `category` |
| Select статус | change | фильтрация по `status` |
| «Подключить» (Google Drive) | click | открытие `IntegrationSetupModal` с `GoogleDriveSetup` внутри |
| «Подключить» (Mango/UIS) | click | открытие `TelephonySetupModal` (wizard из Раздела 4) |
| «Управлять» (Telegram) | click | переход на `/admin/channels` |
| «Управлять» (Google Drive) | click | открытие `IntegrationSetupModal` в режиме управления |
| «Документация» (1С) | click | `window.open("/developers", "_blank")` |

### Адаптивность

Desktop-first. На мобиле grid падает до 1 колонки.

### Тексты (RU)

- Заголовок страницы: `Интеграции`
- PageHeader description: `Подключай внешние сервисы к MACRO CRM`
- Поиск placeholder: `Поиск по названию…`
- Select категория: `Все категории` / `Телефония` / `Хранилище` / `Мессенджеры` / `ERP`
- Select статус: `Все статусы` / `Подключено` / `Доступно` / `Скоро`
- Статус badge connected: `Подключено`
- Статус badge available: `Доступно`
- Статус badge coming_soon: `Скоро`
- Статус badge docs: `Через API`
- CTA connected: `Управлять`
- CTA available: `Подключить`
- CTA coming_soon: `Скоро`
- CTA docs: `Документация`
- Empty (фильтр): `Нет интеграций с такими фильтрами`
- Описания карточек:
  - Google Drive: `Автоматически выгружай подписанные договоры в папки Google Drive`
  - Telegram: `Уведомления о согласованиях и заданиях через бота`
  - Mango Office: `Запись звонков и автоматическая расшифровка через Whisper`
  - UIS: `Запись звонков и автоматическая расшифровка через Whisper`
  - Whisper: `Автоматическая расшифровка записей разговоров с помощью OpenAI Whisper`
  - Яндекс Диск: `Хранение записей звонков`
  - 1С:Предприятие: `Интеграция через Public API MACRO CRM`
  - Bitrix24: `Синхронизация контактов и компаний`

### Связь с backend

- `GET /api/integrations/marketplace` — список коннекторов со статусами. Response shape:
  ```
  [{ id: string, status: "connected"|"available"|"coming_soon"|"docs" }]
  ```
- `GET /api/integrations/google-drive/status` — детальный статус Google Drive (переиспользуется из GoogleDriveSetup)

**Требуется правка backend:** новый endpoint `GET /api/integrations/marketplace` — агрегирует статусы всех коннекторов в одном запросе.

---

## Раздел 4: Calldown Integration

### Зачем

Команда продаж использует телефонию (Mango Office или UIS). Сейчас звонки не попадают в CRM — менеджер вручную создаёт Activity kind=call после разговора. Calldown webhook автоматизирует это: провайдер отправляет webhook → CRM создаёт Activity + расшифровывает запись через Whisper.

### Где в коде

**Настройка:**
- Страница wizard: `apps/web/src/app/(app)/admin/integrations/calldown/page.tsx`
- Компоненты:
  - `apps/web/src/components/Integrations/Calldown/CalldownWizard.tsx`
  - `apps/web/src/components/Integrations/Calldown/ProviderStep.tsx`
  - `apps/web/src/components/Integrations/Calldown/CredentialsStep.tsx`
  - `apps/web/src/components/Integrations/Calldown/WebhookUrlStep.tsx`
  - `apps/web/src/components/Integrations/Calldown/TranscriptionStep.tsx`

**Журнал звонков:**
- Страница: `apps/web/src/app/(app)/admin/integrations/calldown/calls/page.tsx`
- Компоненты:
  - `apps/web/src/components/Integrations/Calldown/CallsTable.tsx`
  - `apps/web/src/components/Integrations/Calldown/CallDetailModal.tsx`
  - `apps/web/src/components/Integrations/Calldown/AttachToDealModal.tsx`

### 4A. Страница настройки — `/admin/integrations/calldown`

#### Wireframe — настройка

```
┌─────────────────────────────────────────────────────────────────────┐
│ [Sidebar]  │  [PageHeader: Calldown — настройка телефонии]         │
│            │  Подключи Mango Office или UIS для записи звонков      │
│            ├─────────────────────────────────────────────────────────┤
│            │                                                         │
│            │  ── Шаг 1 ── Шаг 2 ── Шаг 3 ── Шаг 4 ──               │
│            │  ● Провайдер  ○ Ключи  ○ Webhook  ○ Транскрипция       │
│            │                                                         │
│            │ ┌─────────────────────────────────────────────────────┐│
│            │ │  Выбери провайдер телефонии                 card    ││
│            │ │                                                     ││
│            │ │  ○  [bi-telephone] Mango Office                     ││
│            │ │      Популярная IP-телефония для бизнеса            ││
│            │ │                                                     ││
│            │ │  ○  [bi-headset] UIS                                ││
│            │ │      Облачная АТС с аналитикой                      ││
│            │ │                                                     ││
│            │ │  ○  [bi-globe2] Custom Webhook                      ││
│            │ │      Любой провайдер через webhook-адаптер          ││
│            │ │                                                     ││
│            │ └─────────────────────────────────────────────────────┘│
│            │                           [btn-ghost: Отмена]          │
│            │                           [btn-primary: Далее →]       │
└─────────────────────────────────────────────────────────────────────┘
```

#### Wireframe — Шаг 3 (webhook URL)

```
┌─────────────────────────────────────────────────────────────────────┐
│            │ ┌─────────────────────────────────────────────────────┐│
│            │ │  Webhook URL для провайдера                 card    ││
│            │ │                                                     ││
│            │ │  Вставь этот URL в настройки Mango Office:         ││
│            │ │                                                     ││
│            │ │  ┌─────────────────────────────────────────────┐   ││
│            │ │  │ https://…/webhooks/telephony/mango          │   ││
│            │ │  │                            [bi-clipboard]   │   ││
│            │ │  └─────────────────────────────────────────────┘   ││
│            │ │                                                     ││
│            │ │  Инструкция для Mango Office:                       ││
│            │ │  1. Войди в личный кабинет Mango                    ││
│            │ │  2. Настройки → Уведомления → Webhook               ││
│            │ │  3. Вставь URL выше в поле «URL уведомлений»        ││
│            │ │  4. Выбери события: «Конец разговора»               ││
│            │ │                                                     ││
│            │ │  [Тестовый webhook →]  ← btn-secondary              ││
│            │ │   Результат теста: 200 OK ✓ (после нажатия)         ││
│            │ └─────────────────────────────────────────────────────┘│
│            │              [← Назад]              [Далее →]          │
└─────────────────────────────────────────────────────────────────────┘
```

#### Wireframe — Шаг 4 (транскрипция)

```
┌─────────────────────────────────────────────────────────────────────┐
│            │ ┌─────────────────────────────────────────────────────┐│
│            │ │  Расшифровка звонков (Whisper)              card    ││
│            │ │                                                     ││
│            │ │  [toggle] Включить расшифровку                      ││
│            │ │                                                     ││
│            │ │  Язык распознавания                                 ││
│            │ │  [select: Русский / English / Казахский]            ││
│            │ │                                                     ││
│            │ │  Минимальная длительность звонка                    ││
│            │ │  [input number: 30] секунд                          ││
│            │ │  Звонки короче — не расшифровываются                ││
│            │ │                                                     ││
│            │ │  OpenAI API Key                                     ││
│            │ │  [input type=password: sk-…]                        ││
│            │ │  Ключ хранится зашифрованным. $0.006/мин.           ││
│            │ └─────────────────────────────────────────────────────┘│
│            │              [← Назад]              [Сохранить]        │
└─────────────────────────────────────────────────────────────────────┘
```

#### Композиция (настройка)

- **CalldownWizard:** управляет состоянием шагов `step: 1|2|3|4`, хранит `formData` в `useState`
- **Stepper:** 4 шага сверху карточки. Нумерованный список горизонтально:
  `flex gap-0 items-center` — шаги соединены линиями `border-t-2`. Активный: `text-primary font-semibold`, завершённый: `text-success`, будущий: `text-gray-400`
- **ProviderStep:** три radio-карточки `border-2 rounded-xl p-4 cursor-pointer` — checked: `border-primary bg-primary/5`, unchecked: `border-gray-200 hover:border-gray-300`
- **CredentialsStep:** поля зависят от провайдера. Mango: API Key + API Salt. UIS: Account ID + API Token. Custom: нет полей, только инструкция.
- **WebhookUrlStep:** копируемый URL (auto-generated из `window.location.origin + /webhooks/telephony/{provider}`), инструкция, кнопка «Тестовый webhook»
- **TranscriptionStep:** toggle + select язык + input min_duration + input OpenAI API key

#### UI компоненты (настройка)

**Stepper:**
```
[1 Провайдер] —— [2 Ключи] —— [3 Webhook] —— [4 Транскрипция]
```
- Компонент вёрстается прямо в `CalldownWizard`, не выносится как отдельный общий `Stepper` (нет переиспользования пока)
- `flex items-center gap-0 mb-6`
- Шаг: `flex items-center gap-2 text-sm`; линия между: `flex-1 border-t-2 mx-2`

**Кнопки навигации:**
- Блок `flex justify-end gap-2 mt-4`
- Шаг 1: `[btn-ghost: Отмена]` → back to `/admin/integrations` / `[btn-primary: Далее]`
- Шаги 2-3: `[btn-ghost: Назад]` / `[btn-primary: Далее]`
- Шаг 4: `[btn-ghost: Назад]` / `[btn-primary: Сохранить]`

**Кнопка «Тестовый webhook» (шаг 3):**
- `btn-secondary bi-play-btn` — «Отправить тестовый webhook»
- Loading: `btn-secondary disabled` + текст «Отправляем…»
- Success inline: `text-success text-sm bi-check-circle` — «200 OK — webhook принят»
- Error inline: `text-danger text-sm bi-x-circle` — «Ошибка: {status} — проверь настройки»

### States (настройка)

- **Loading (сохранение):** кнопка «Сохранить» disabled + «Сохраняем…»
- **Error:** inline `text-danger text-sm` под соответствующим полем
- **Success (сохранение):** redirect на `/admin/integrations` + flash-сообщение через URL `?success=calldown_saved`

### Interactions (настройка)

| Элемент | Действие | Результат |
|---|---|---|
| Radio-карточка провайдера | click | выбор провайдера, `border-primary` |
| «Далее» (шаг 1) | click | валидация выбора провайдера → переход к шагу 2 |
| «Далее» (шаг 2) | click | валидация заполненности полей → переход к шагу 3 |
| Кнопка `bi-clipboard` (webhook URL) | click | копировать URL |
| «Отправить тестовый webhook» | click | POST `/api/integrations/calldown/test-webhook` → показать результат |
| «Далее» (шаг 3) | click | переход к шагу 4 |
| «Сохранить» (шаг 4) | click | POST `/api/integrations/calldown/setup` → redirect |
| «Назад» | click | шаг -= 1 |
| «Отмена» | click | переход на `/admin/integrations` |

---

### 4B. Журнал звонков — `/admin/integrations/calldown/calls`

#### Wireframe — журнал звонков

```
┌─────────────────────────────────────────────────────────────────────┐
│ [Sidebar]  │  [PageHeader: Журнал звонков]     [↗ Настройка]       │
│            │                                                         │
│            │  [Дата от] [Дата до]  [Входящие▾]  [Все менеджеры▾]  │
│            │                                                         │
│            │ ┌─────────────────────────────────────────────────────┐│
│            │ │ Дата/время   Номер         Напр.  Длит.  Менеджер  ││
│            │ ├─────────────────────────────────────────────────────┤│
│            │ │ 02.06 14:32  +7 495 123…   in     4:22   Иванов    ││
│            │ │              ● Транскрипция готова                  ││
│            │ ├─────────────────────────────────────────────────────┤│
│            │ │ 02.06 11:15  +7 916 456…   out    1:05   Петрова   ││
│            │ │              ⏳ Расшифровка…                         ││
│            │ ├─────────────────────────────────────────────────────┤│
│            │ │ 02.06 09:40  +7 800 789…   in     0:18   Сидоров   ││
│            │ │              — (слишком короткий)                    ││
│            │ └─────────────────────────────────────────────────────┘│
│            │              [← 1 2 3 … →]                             │
└─────────────────────────────────────────────────────────────────────┘
```

#### Wireframe — детали звонка (Modal)

```
┌──────────────────────────────────────────────────────────┐
│  Звонок 02.06.2026 14:32            [bi-x]               │
├──────────────────────────────────────────────────────────┤
│  +7 495 123-45-67  →  in  ·  4 мин 22 сек               │
│  Менеджер: Иванов А.А.   ·  Сделка: (не привязана)      │
│  [Прикрепить к сделке]                                   │
├──────────────────────────────────────────────────────────┤
│  Запись разговора                                        │
│  ┌──────────────────────────────────────────────────┐   │
│  │  ▶  ────────────────────────────────  2:15/4:22  │   │
│  └──────────────────────────────────────────────────┘   │
│  [bi-download Скачать запись]                            │
├──────────────────────────────────────────────────────────┤
│  Транскрипция                    ● Готова               │
│  ┌──────────────────────────────────────────────────┐   │
│  │ — Добрый день, MACRO Global, Иван.               │   │
│  │ — Здравствуйте, я хотел узнать…                  │   │
│  │ …                                                │   │
│  └──────────────────────────────────────────────────┘   │
│  [bi-clipboard Копировать транскрипт]                    │
│                                                          │
│  [Запросить расшифровку повторно]  (если failed)         │
├──────────────────────────────────────────────────────────┤
│  Связанная активность в CRM                              │
│  Activity #1234 · kind=call · создана авто               │
│                          [bi-box-arrow-up-right Открыть] │
└──────────────────────────────────────────────────────────┘
```

#### UI компоненты (журнал)

**Фильтры (над таблицей):**
- `<input type="date" className="input">` × 2 (от/до)
- `<select className="input w-36">` — направление: «Все» / «Входящие» / «Исходящие»
- `<select className="input w-44">` — менеджер (SWR `/api/users`)

**CallsTable (card):**
Таблица с колонками:
- Дата/время: `text-sm`
- Номер: `font-mono text-sm`
- Направление badge:
  - `in`: `bg-info/10 text-info` — «Вх.» + `bi-telephone-inbound`
  - `out`: `bg-warning/10 text-warning` — «Исх.» + `bi-telephone-outbound`
- Длительность: `MM:SS`
- Менеджер: текст
- Транскрипция badge (sub-строка):
  - `done`: `bg-success/10 text-success bi-check-circle` — «Транскрипция готова»
  - `pending`: `text-gray-500 bi-hourglass-split` — «Расшифровка…»
  - `failed`: `text-danger bi-x-circle` — «Ошибка расшифровки»
  - нет записи: `text-gray-400 text-xs` — «— (слишком короткий)»
- Click на строку → открывает `CallDetailModal`

**Пагинация:** стандартный паттерн проекта — `[← Предыдущая] [1] [2] [3] [Следующая →]`

**CallDetailModal:**
- Использует компонент `Modal` (существующий)
- Заголовок: `Звонок {date} {time}`
- Секция метаданных: номер + направление + длительность + менеджер + связанная сделка
- Секция аудио: `<audio controls className="w-full">` с `src={call.recording_url}`. Fallback: «Запись недоступна»
- Кнопка скачать: `<a href={recording_url} download>` `btn-ghost bi-download`
- Секция транскрипции: `pre-wrap text-sm bg-gray-50 rounded p-3 max-h-48 overflow-y-auto`
- Кнопка «Прикрепить к сделке»: `btn-secondary bi-link-45deg` → открывает `AttachToDealModal`
- Кнопка «Открыть активность»: `btn-ghost bi-box-arrow-up-right` → `/deals/{deal_id}` или `/leads/{lead_id}` в новой вкладке
- Кнопка «Запросить расшифровку»: видна только если `transcript_status === "failed"` — `btn-secondary bi-arrow-repeat` → POST `/api/integrations/calldown/transcribe/{call_id}`

**AttachToDealModal:**
- `Modal` + поиск сделки: `input placeholder="Поиск по названию сделки или контрагенту"` + SWR-список сделок
- Click на сделку → PATCH `/api/activities/{activity_id}` с `deal_id`

### States (журнал)

- **Loading:** скелетон 10 строк таблицы `h-10 bg-gray-100 animate-pulse`
- **Empty:** `EmptyState` с `bi-telephone-x`, «Нет звонков за этот период», «Настрой Calldown для автоматической записи звонков»
- **Error:** `bg-danger/10 text-danger px-4 py-3 text-sm` над таблицей
- **Audio не загружается:** текст «Запись недоступна» в секции аудио

### Interactions (журнал)

| Элемент | Действие | Результат |
|---|---|---|
| Строка таблицы | click | открыть `CallDetailModal` |
| Фильтры | change | обновить SWR-ключ (query params) |
| «Прикрепить к сделке» | click | открыть `AttachToDealModal` |
| «Запросить расшифровку» | click | POST transcribe → reload modal |
| Кнопка «Настройка» в PageHeader | click | переход на `/admin/integrations/calldown` |
| `audio` controls | interact | нативный браузерный плеер |

### Тексты (RU) — Calldown

- Заголовок wizard: `Calldown — настройка телефонии`
- PageHeader description wizard: `Подключи Mango Office или UIS для записи звонков`
- Шаги: `Провайдер` / `Ключи API` / `Webhook` / `Транскрипция`
- Шаг 1 заголовок: `Выбери провайдер телефонии`
- Radio Mango: `Mango Office` + `Популярная IP-телефония для бизнеса`
- Radio UIS: `UIS` + `Облачная АТС с аналитикой`
- Radio Custom: `Custom Webhook` + `Любой провайдер через webhook-адаптер`
- Шаг 2 заголовок (Mango): `Ключи Mango Office API`
- Поля (Mango): `API Key *` / `API Salt *`
- Поля (UIS): `Account ID *` / `API Token *`
- Шаг 3 заголовок: `Webhook URL для провайдера`
- Шаг 3 инструкция title: `Вставь этот URL в настройки {provider}:`
- Кнопка тест: `Отправить тестовый webhook`
- Тест loading: `Отправляем…`
- Тест success: `200 OK — webhook принят`
- Шаг 4 заголовок: `Расшифровка звонков (Whisper)`
- Toggle label: `Включить расшифровку`
- Select язык label: `Язык распознавания`
- Языки: `Русский` / `English` / `Казахский`
- Min duration label: `Минимальная длительность`
- Min duration suffix: `секунд`
- Min duration hint: `Звонки короче — не расшифровываются`
- OpenAI key label: `OpenAI API Key`
- OpenAI key hint: `Ключ хранится зашифрованным. Стоимость: $0.006 в минуту.`
- Кнопки wizard: `Отмена` / `Назад` / `Далее` / `Сохранить`
- Сохранение loading: `Сохраняем…`
- Заголовок журнала: `Журнал звонков`
- Кнопка в PageHeader журнала: `Настройка`
- Фильтр направление: `Все` / `Входящие` / `Исходящие`
- Колонки таблицы: `Дата/время` / `Номер` / `Направление` / `Длительность` / `Менеджер`
- Значки транскрипции: `Транскрипция готова` / `Расшифровка…` / `Ошибка расшифровки`
- Empty title: `Нет звонков за этот период`
- Empty desc: `Настрой Calldown для автоматической записи звонков`
- Modal заголовок: `Звонок {дата} {время}`
- Секция аудио: `Запись разговора`
- Кнопка скачать: `Скачать запись`
- Секция транскрипции: `Транскрипция`
- Кнопка копировать транскрипт: `Копировать транскрипт`
- Кнопка прикрепить: `Прикрепить к сделке`
- Кнопка открыть активность: `Открыть в CRM`
- Кнопка повтор транскрипции: `Запросить расшифровку повторно`
- Аудио недоступно: `Запись недоступна`
- AttachModal заголовок: `Прикрепить к сделке`
- AttachModal поиск: `Поиск по названию сделки или контрагенту`

### Связь с backend (Calldown)

- `GET /api/integrations/calldown/config` — текущая конфигурация (для предзаполнения wizard)
- `POST /api/integrations/calldown/setup` — сохранение настроек (provider + credentials + transcription config)
- `POST /api/integrations/calldown/test-webhook` — тестовая отправка
- `GET /api/integrations/calldown/calls?from=&to=&direction=&owner_id=&page=` — журнал звонков
- `POST /api/integrations/calldown/transcribe/{call_id}` — повторный запрос расшифровки
- `GET /api/users` — для фильтра менеджеров
- `PATCH /api/activities/{activity_id}` — прикрепить к сделке/лиду

**Требуется правка backend:** все `/api/integrations/calldown/*` эндпоинты, поля `external_call_id`, `call_duration_sec`, `recording_url`, `transcript`, `transcript_status` в таблице `activities`.

---

## Раздел 5: Logs Viewer — `/admin/integrations/logs`

### Зачем

Администратор или разработчик интеграции должен видеть в одном месте: какие webhook-события отправлялись и с каким результатом, какие API-запросы сделаны по каждому токену, сколько звонков обработано.

### Где в коде

- Страница: `apps/web/src/app/(app)/admin/integrations/logs/page.tsx`
- Компоненты:
  - `apps/web/src/components/Integrations/Logs/WebhookDeliveriesTab.tsx` (переиспользует `DeliveriesTab` из Webhooks)
  - `apps/web/src/components/Integrations/Logs/ApiRequestLogsTab.tsx`
  - `apps/web/src/components/Integrations/Logs/CalldownLogsTab.tsx`

### Wireframe

```
┌─────────────────────────────────────────────────────────────────────┐
│ [Sidebar]  │  [PageHeader: Логи интеграций]                        │
│            │                                                         │
│            │  [Webhook доставки] [API запросы] [Calldown]           │
│            │   ─────────────── (табы)                               │
│            ├─────────────────────────────────────────────────────────┤
│            │                                                         │
│            │  == Таб: Webhook доставки ==                           │
│            │  (компонент DeliveriesTab из /admin/webhooks)          │
│            │                                                         │
│            │  == Таб: API запросы ==                                │
│            │  Токен: [All▾]  Статус: [All▾]  Дата: [от][до]        │
│            │                                                         │
│            │ ┌─────────────────────────────────────────────────────┐│
│            │ │ Метод   Путь              Статус   Время   Дата     ││
│            │ ├─────────────────────────────────────────────────────┤│
│            │ │ GET     /api/leads        200      41мс    …        ││
│            │ │ POST    /api/sandbox/…    201      82мс    …        ││
│            │ │ GET     /api/leads        404      12мс    …        ││
│            │ └─────────────────────────────────────────────────────┘│
│            │  Export CSV                                             │
│            │                                                         │
│            │  == Таб: Calldown ==                                   │
│            │  (ссылка на /admin/integrations/calldown/calls)        │
└─────────────────────────────────────────────────────────────────────┘
```

### Композиция

- **Корневая страница:** `apps/web/src/app/(app)/admin/integrations/logs/page.tsx`
- **Три таба:**
  - `webhook_deliveries` — переиспользует компонент `DeliveriesTab` из `components/Webhooks/DeliveriesTab.tsx` (не дублируем логику)
  - `api_requests` — новый `ApiRequestLogsTab`
  - `calldown` — новый `CalldownLogsTab` (краткий список + link на `/admin/integrations/calldown/calls`)

### UI компоненты

**Табы (аналогично `/admin/webhooks`):**
- `flex gap-1 border-b border-gray-200 mb-6`
- Три таба: `bi-send-check Webhook доставки` / `bi-activity API запросы` / `bi-telephone Calldown`

**ApiRequestLogsTab:**
- Фильтры: select токена (SWR `/api/api-tokens`), select статуса (все/2xx/4xx/5xx), date range
- Таблица в `card`:
  - Колонки: Метод / Путь / Статус / Время ответа / Дата
  - Метод badge: GET — `bg-info/10 text-info`, POST — `bg-success/10 text-success`, DELETE — `bg-danger/10 text-danger`
  - Статус: 2xx — `text-success`, 4xx — `text-warning`, 5xx — `text-danger`
  - Время: `{n}мс` обычным текстом
- Кнопка «Экспорт CSV» `btn-ghost bi-download` — GET `/api/integrations/logs/export?format=csv`
- Пагинация: стандартная

**CalldownLogsTab:**
- Краткая сводка: `grid grid-cols-3 gap-4` с тремя `card p-4`:
  - «Звонков сегодня» + число
  - «Расшифровано» + число + success badge
  - «Ошибок расшифровки» + число + danger badge
- Последние 5 звонков (таблица mini, без пагинации)
- Кнопка `btn-secondary bi-box-arrow-up-right` — «Смотреть полный журнал» → `/admin/integrations/calldown/calls`

### States

- **Loading:** skeleton строк в активном табе
- **Empty:** `EmptyState` с иконкой по типу таба:
  - Webhook: `bi-send-check`, «Нет доставок за этот период»
  - API: `bi-activity`, «Нет запросов за этот период»
  - Calldown: `bi-telephone-x`, «Нет данных о звонках»
- **Error:** inline `text-danger` над таблицей

### Interactions

| Элемент | Действие | Результат |
|---|---|---|
| Таб | click | смена активного таба + обновление SWR-ключа |
| Фильтры API | change | обновление SWR-ключа с query params |
| «Экспорт CSV» | click | GET запрос → скачать файл |
| «Смотреть полный журнал» | click | переход на `/admin/integrations/calldown/calls` |
| Строка delivery | click | открыть `DeliveryDetailModal` (существующий компонент) |

### Тексты (RU)

- Заголовок страницы: `Логи интеграций`
- Табы: `Webhook доставки` / `API запросы` / `Calldown`
- Колонки API: `Метод` / `Путь` / `Статус` / `Время` / `Дата`
- Filter select токен: `Все токены`
- Filter select статус: `Все статусы` / `2xx Успех` / `4xx Ошибка клиента` / `5xx Ошибка сервера`
- Кнопка экспорт: `Экспорт CSV`
- Сводка Calldown: `Звонков сегодня` / `Расшифровано` / `Ошибок расшифровки`
- Кнопка журнал: `Смотреть полный журнал`
- Empty webhook: `Нет доставок за этот период`
- Empty API: `Нет запросов за этот период`
- Empty calldown: `Нет данных о звонках`

### Связь с backend

- `GET /api/integrations/logs?token_id=&status=&from=&to=&page=` — API request logs (новый эндпоинт)
- `GET /api/integrations/logs/export?format=csv&...` — экспорт (новый эндпоинт)
- `GET /api/webhook-deliveries?...` — существующий (переиспользуем `DeliveriesTab`)
- `GET /api/integrations/calldown/calls?limit=5` — для краткой сводки

**Требуется правка backend:** новые эндпоинты `/api/integrations/logs`, `/api/integrations/logs/export`, таблица `api_request_logs`.

---

## Обновление Sidebar

Sidebar (`apps/web/src/components/Sidebar.tsx`) уже содержит пункт «Интеграции» (`/admin/integrations`, `bi-plug`) в `ADMIN_ITEMS`. **Ничего не меняем.** Все новые страницы вложены в `/admin/integrations/*` и `/developers/*` — навигация происходит внутри страниц (PageHeader, табы, wizard).

Опционально (на усмотрение): добавить пункт «Developer Portal» в `ADMIN_ITEMS` для удобства, но это не обязательно для первого релиза. Если добавлять — `{ href: "/developers", icon: "bi-code-slash", label: "Developer Portal", roles: ["admin"] }`.

---

## Список новых компонентов

| Компонент | Путь | Описание |
|---|---|---|
| `CodeTabs` | `components/Developers/CodeTabs.tsx` | Табы Python/Node.js/curl с pre-блоком и копированием |
| `QuickStartSection` | `components/Developers/QuickStartSection.tsx` | Секция быстрого старта с curl-примером |
| `OpenAPIEmbed` | `components/Developers/OpenAPIEmbed.tsx` | iframe Swagger UI |
| `WebhooksGuideSection` | `components/Developers/WebhooksGuideSection.tsx` | Статичный гайд по webhook'ам |
| `OAuthGuideSection` | `components/Developers/OAuthGuideSection.tsx` | Аккордеон OAuth 2.0 flow |
| `SandboxPlayground` | `components/Developers/SandboxPlayground.tsx` | Панель управления sandbox-запросом |
| `RequestHistorySidebar` | `components/Developers/RequestHistorySidebar.tsx` | Боковая история запросов (localStorage) |
| `SnippetSaveModal` | `components/Developers/SnippetSaveModal.tsx` | Modal сохранения сниппета |
| `IntegrationCard` | `components/Integrations/IntegrationCard.tsx` | Карточка коннектора в Marketplace |
| `IntegrationSetupModal` | `components/Integrations/IntegrationSetupModal.tsx` | Обёртка modal/wizard настройки |
| `GoogleDriveSetup` | `components/Integrations/GoogleDriveSetup.tsx` | Форма Google Drive (вынести из page.tsx) |
| `TelephonySetupModal` | `components/Integrations/TelephonySetupModal.tsx` | alias/re-export для CalldownWizard в modal |
| `CalldownWizard` | `components/Integrations/Calldown/CalldownWizard.tsx` | Мастер настройки (4 шага) |
| `ProviderStep` | `components/Integrations/Calldown/ProviderStep.tsx` | Шаг 1: выбор провайдера |
| `CredentialsStep` | `components/Integrations/Calldown/CredentialsStep.tsx` | Шаг 2: ключи API |
| `WebhookUrlStep` | `components/Integrations/Calldown/WebhookUrlStep.tsx` | Шаг 3: webhook URL + тест |
| `TranscriptionStep` | `components/Integrations/Calldown/TranscriptionStep.tsx` | Шаг 4: Whisper настройки |
| `CallsTable` | `components/Integrations/Calldown/CallsTable.tsx` | Таблица журнала звонков |
| `CallDetailModal` | `components/Integrations/Calldown/CallDetailModal.tsx` | Детали звонка с аудио и транскриптом |
| `AttachToDealModal` | `components/Integrations/Calldown/AttachToDealModal.tsx` | Прикрепить звонок к сделке |
| `WebhookDeliveriesTab` | `components/Integrations/Logs/WebhookDeliveriesTab.tsx` | Таб доставок (wrapper над DeliveriesTab) |
| `ApiRequestLogsTab` | `components/Integrations/Logs/ApiRequestLogsTab.tsx` | Таб API request logs |
| `CalldownLogsTab` | `components/Integrations/Logs/CalldownLogsTab.tsx` | Таб Calldown сводки |

**Реюзуемые (не трогать):** `PageHeader`, `Modal`, `EmptyState`, `DeliveriesTab`, `DeliveryDetailModal`, `CreateApiTokenModal`, `ApiTokensTable`.

---

## Координация с Эпиком 11 (Webhooks + API Tokens)

Эпик 11 уже в проде. Эпик 15 надстраивается поверх:

| Эпик 11 (уже есть) | Эпик 15 (добавляем) |
|---|---|
| `/admin/api-tokens` — страница токенов | Sandbox playground использует токены. Logs viewer показывает usage по токену |
| `/admin/webhooks` — страница вебхуков с `DeliveriesTab` | Marketplace: карточка «Webhooks» ссылается на `/admin/webhooks`. Logs Viewer: таб «Webhook доставки» переиспользует `DeliveriesTab` без изменений |
| `components/Webhooks/DeliveriesTab.tsx` | Импортируется напрямую в `WebhookDeliveriesTab.tsx` (Эпик 15) без копирования |
| `CreateApiTokenModal` | Переиспользуется в Sandbox playground для создания sandbox-токена |

**Не дублировать:** `DeliveriesTab`, `DeliveryDetailModal`, `ApiTokensTable` — только импортировать.

---

## Открытые вопросы

1. **Публичность `/developers`**: страница доступна без авторизации — требует `(app)/layout.tsx` пустить без auth-guard или выделить отдельный layout `(public)`. Уточнить у backend-specialist — как сейчас настроен auth middleware.

2. **Sandbox endpoint `is_sandbox`**: поле нужно в модели `APIToken`. Требуется Alembic-миграция от backend-specialist.

3. **`GET /api/integrations/marketplace`**: нужен новый endpoint. Альтернатива — статичный конфиг на фронте + точечные запросы статуса для каждого коннектора. Уточнить с product: нужен ли динамический список или hardcode достаточно на первый релиз.

4. **Google Drive карточка в Marketplace**: текущий `/admin/integrations/page.tsx` полностью заменяется. Логику `saveConfig()`, `connect()`, `disconnect()` нужно перенести в `GoogleDriveSetup.tsx` без изменения поведения. Подтвердить, что перенос безопасен.

5. **Аудио-плеер**: браузерный `<audio controls>` будет работать только если `recording_url` — прямая ссылка на MP3 с CORS-заголовками от провайдера. Если провайдер требует auth для скачивания — нужен backend-прокси `/api/integrations/calldown/recording/{call_id}`. Уточнить у backend-specialist.

6. **OAuth 2.0 Admin UI**: в плане есть `GET /admin/oauth-apps` — нужна ли страница управления OAuth Apps в этом эпике или только Developer Portal guide? OAuthGuideSection в `/developers` — статичный текст без backend. Управление Apps — откладывается или входит в scope?

7. **Логирование API-запросов**: `api_request_logs` требует middleware в FastAPI для записи каждого запроса. Это может влиять на latency. Уточнить с backend-specialist: нужен ли async logging (BackgroundTasks) и как настроить retention (30 дней из плана).

8. **Tabs routing**: стоит ли делать `/admin/integrations/logs?tab=api` (query param) для прямых ссылок из других страниц, или достаточно in-memory state? Рекомендация: query param — чтобы можно было скидывать ссылку на конкретный таб.

9. **Stepper компонент**: wizard Calldown использует stepper. Если Эпик 18 (AI Features) или Эпик 19 (SLA Wizard) тоже имеют steppers — стоит вынести в общий `components/Stepper.tsx`. Сейчас — inline в `CalldownWizard`. Уточнить у product: есть ли другие wizard'ы в ближайших эпиках.
