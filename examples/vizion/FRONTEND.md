# Vizion — Инструкция для фронтенда

## Тестовый аккаунт (dev)

| Имя | Email | Пароль | Роль |
|---|---|---|---|
| TTeqwwd | e.vetrov@macroglobaltech.com | Z1X2C3V4B5N6 | superadmin |

## Стек

- **Vue 3 + TypeScript** — в отдельном контейнере, папка `front/`
- **Vite** — сборщик, конфиг `front/vite.config.ts`
- **Pinia** — state management
- **Vue Router** — роутинг
- **PrimeVUE + Bootstrap** — ставить самостоятельно
- **vuedraggable 4** (`@[email protected]`) — drag-and-drop порядка карточек отчётов на странице `/reports`

## Начало работы

Фронтенд живёт в отдельном Docker-контейнере (`vizion-frontend`).

```bash
# Поднять все контейнеры
docker compose up -d

# Установить зависимости (если первый запуск или новые пакеты)
docker compose exec frontend npm install

# Фронт доступен на http://localhost:3030
# API проксируется через Vite: /api → бэкенд контейнер
```

> Фронт в docker-compose всегда собирается в режиме `production` (`npm run build` → `dist/`). Для разработки с hot-reload запускай `npm run dev` напрямую из `front/` — не через docker.

Корневой компонент: `front/src/App.vue`
Точка входа: `front/src/main.ts`
Роутер: `front/src/router/index.ts`
Сторы: `front/src/stores/`

## Авторизация

Все API-запросы (кроме `/api/login`) требуют заголовок:

```
Authorization: Bearer <token>
```

Токен получаешь при логине.

---

## API-методы

Базовый URL: `/api`

---

### Авторизация

#### `POST /api/login`

Логин. Без токена.

**Тело запроса:**
```json
{
    "email": "e.vetrov@macroglobaltech.com",
    "password": "Z1X2C3V4B5N6"
}
```

**Ответ 200:**
```json
{
    "user": {
        "id": 1,
        "name": "TTeqwwd",
        "email": "e.vetrov@macroglobaltech.com",
        "role": "superadmin",
        "locale": "ru",
        "home_path": "/reports",
        "company_id": 1,
        "active_company_id": 2,
        "active_company": {
            "id": 2,
            "name": "Застройщик 1",
            "is_system": false,
            "crm_url": "https://macroserver.kz"
        },
        "company_accesses": [
            {"company_id": 1, "role": "superadmin"}
        ]
    },
    "token": "1|abc123..."
}
```

`active_company_id` — ID компании, активной на момент логина (может отличаться от `company_id`). Используется для начального scoping'а запросов.
`home_path` — относительный путь роутера домашней страницы пользователя. После логина SPA редиректит на этот путь. Дефолт `/reports`; null трактуется так же.

**Ответ 422:** Неверный email или пароль.

---

#### `POST /api/logout`

Логаут. Удаляет текущий токен.

**Ответ 200:**
```json
{"message": "Вы вышли из системы."}
```

---

### Профиль

#### `GET /api/user`

Текущий пользователь с компанией.

**Ответ 200:**
```json
{
    "id": 1,
    "name": "TTeqwwd",
    "email": "e.vetrov@macroglobaltech.com",
    "role": "superadmin",
    "locale": "ru",
    "home_path": "/reports",
    "company_id": 1,
    "active_company_id": 2,
    "active_company": {
        "id": 2,
        "name": "Застройщик 1",
        "is_system": false,
        "crm_url": "https://macroserver.kz",
        "currency_code": "KZT",
        "timezone": "Asia/Almaty"
    },
    "company_accesses": [
        {"company_id": 1, "role": "superadmin"}
    ],
    "company": {
        "id": 1,
        "name": "Vizion",
        "is_system": true,
        "crm_url": null,
        "currency_code": null,
        "timezone": null
    }
}
```

`active_company_id` — ID активной компании (та, на которую пользователь переключился). Может отличаться от `company_id`. При первом входе равен `company_id`.
`home_path` — относительный путь роутера «домашней» страницы (персональная звезда). Присутствует во всех user-объектах (login, GET /api/user, POST /api/active-company/{id}). Дефолт `/reports`.

---

#### `PUT /api/user`

Обновить свой профиль. Все поля необязательные.

**Тело запроса:**
```json
{
    "name": "Новое имя",
    "locale": "en",
    "password": "newpassword123"
}
```

**Ответ 200:** Обновлённый пользователь (как в GET /api/user).

---

#### `PUT /api/profile/home`

Сохранить «домашнюю страницу» текущего пользователя — относительный путь роутера, куда SPA редиректит после логина. Доступно любой аутентифицированной роли.

**Тело запроса:**
```json
{ "path": "/reports/42" }
```

Валидация (422):
- `path` обязателен, строка, max 255.
- Должен начинаться ровно с одного `/` (одно ведущее слэш, не `//evil.com`).
- Допустимые символы: `A-Za-z0-9-_/.~?=&%`.

**Ответ 200:**
```json
{ "home_path": "/reports/42" }
```

**Ошибки:** 401 не авторизован, 422 невалидный путь (абсолютный URL, protocol-relative, недопустимые символы).

> HomeStar — звёздочка в Toolbox. Клик по незвёздной странице вызывает `PUT /api/profile/home` с текущим роутерным путём и обновляет `userStore.homePath`. Звёздная страница — повторный клик сбрасывает в дефолт `/reports`.

---

### Компании

Доступ: **superadmin** — полный, остальные — только список своих компаний (из `company_accesses`).

#### `GET /api/companies`

Список компаний.

**Ответ 200:**
```json
[
    {"id": 1, "name": "Vizion", "is_system": true, "crm_url": null, "currency_code": null, "timezone": null},
    {"id": 2, "name": "Застройщик 1", "is_system": false, "crm_url": "https://macroserver.kz", "currency_code": "KZT", "timezone": "Asia/Almaty"}
]
```

---

#### `POST /api/companies`

Создать компанию. Только superadmin.

**Тело запроса:**
```json
{
    "name": "Новый застройщик",
    "macrodata_host": "db.example.com",
    "macrodata_port": 3306,
    "macrodata_database": "macrodata",
    "macrodata_username": "reader",
    "macrodata_password": "secret",
    "crm_url": "https://macroserver.kz",
    "currency_code": "KZT",
    "timezone": "Asia/Almaty"
}
```

`crm_url` — необязательный. Базовый URL CRM-системы MACRO для построения ссылок на сделки/объекты в отчётах. Per-company. Validation: nullable URL, max 255.

`currency_code` — ISO 4217, ровно 3 символа (например `"RUB"`, `"KZT"`, `"UZS"`, `"AED"`). Nullable. Дефолт на уровне БД: `"RUB"`. Используется фронтом для форматирования сумм через `Intl.NumberFormat`.

`timezone` — IANA timezone name, max 64 символа (например `"Europe/Moscow"`, `"Asia/Almaty"`, `"Asia/Dubai"`). Nullable. Дефолт на уровне БД: `"Europe/Moscow"`. Используется фронтом для форматирования дат через `Intl.DateTimeFormat`.

**Ответ 200:** Созданная компания.

---

#### `GET /api/companies/{id}`

Одна компания по ID. Ответ включает поля `crm_url` (string|null), `currency_code` (string|null, ISO 4217) и `timezone` (string|null, IANA).

---

#### `PUT /api/companies/{id}`

Обновить компанию. Только superadmin. Все поля необязательные, включая `crm_url` (nullable URL, max 255).

---

#### `DELETE /api/companies/{id}`

Удалить компанию. Только superadmin. Системную компанию удалить нельзя.

**Ответ 200:**
```json
{"message": "Компания удалена."}
```

---

### MacroData Mappings

Каждая клиентская компания использует собственную внутреннюю нумерацию справочников MacroData (например, ID типов финансовых операций). Чтобы системные отчёты работали для всех клиентов без хардкода, конфиг отчёта может содержать placeholder-объекты вида `{"$company_var": "<semantic_key>"}`, которые `ConfigResolver` разворачивает в реальные значения перед запросом. Управление маппингами — задача superadmin/admin через страницу настроек компании.

**ACL:** superadmin — любая компания; admin — только своя; analyst/viewer — запрещено (403).

#### `GET /api/companies/{id}/macrodata-mappings`

Получить все маппинги компании, отсортированные по `semantic_key`.

**Ответ 200:**
```json
{
  "data": [
    {
      "id": 1,
      "semantic_key": "finance_type_sale_ids",
      "value": [3786, 3788],
      "notes": "auto-probed via RU name match",
      "auto_probed_at": "2026-05-21T10:00:00+00:00",
      "updated_at": "2026-05-21T10:00:00+00:00"
    }
  ]
}
```

---

#### `PUT /api/companies/{id}/macrodata-mappings`

Bulk-upsert маппингов (partial update — строки, не попавшие в payload, не трогаются). Все изменения в одной транзакции. Возвращает актуальный список маппингов в формате GET.

**Тело запроса:**
```json
{
  "mappings": [
    {
      "semantic_key": "finance_type_sale_ids",
      "value": [3786, 3788],
      "notes": "manual override",
      "auto_probed_at": null
    }
  ]
}
```

Правила валидации:
- `semantic_key` — snake_case, `/^[a-z][a-z0-9_]*$/`, max 100 символов.
- `value` — обязательно (даже если `null`), тип свободный: `int[]`, `int`, `string`.
- `notes` — nullable string.
- `auto_probed_at` — nullable ISO-8601 datetime; отсутствие ключа = «не трогать».

**Ответ 200:** та же структура, что `GET /api/companies/{id}/macrodata-mappings`.

---

#### `DELETE /api/companies/{id}/macrodata-mappings/{semantic_key}`

Удалить один маппинг. 204 при успехе, 404 если ключ не найден, 422 если `semantic_key` не соответствует формату.

**Ответ 204:** тело пустое.

---

#### `POST /api/companies/{id}/macrodata-mappings/probe`

Запустить автопробинг: сервис подключается к MacroData компании, обходит справочные таблицы (например, `finances_types`), матчит RU/EN-имена по шаблонам из `config/macrodata_probe.php` и возвращает **предложения** (ничего не сохраняется). Пользователь подтверждает нужные строки и отправляет их через PUT.

**Запрос:** тело не требуется.

**Ответ 200:**
```json
{
  "data": {
    "probed_at": "2026-05-21T10:00:00+00:00",
    "mappings": [
      {
        "semantic_key": "finance_type_sale_ids",
        "value": [3786],
        "matched_by": "RU: '%Поступления от продажи%'",
        "candidates": [
          {"id": 3786, "name": "Поступления от продажи недвижимости"}
        ]
      }
    ],
    "unresolved": ["finance_type_some_other_ids"]
  }
}
```

**Коды ошибок:**

| Код | Причина |
|---|---|
| 403 | Нет прав (analyst / viewer) |
| 503 | MacroData недоступна (нет подключения, неверные креды) — `error: "macrodata_unavailable"` |

> При 503 фронт должен показывать пользователю сообщение «Не удалось подключиться к MacroData. Проверьте настройки подключения.» (не показывать технические детали).

---

### Активная компания

Активная компания — компания, в контексте которой работает пользователь в данный момент. Хранится на сервере в `users.active_company_id` (персистируется при смене, не теряется при перезагрузке страницы).

Текущее значение приходит в `GET /api/user` → поля `active_company_id` (int) и `active_company` (объект Company).

#### `POST /api/active-company/{id}`

Переключить активную компанию. Сохраняет выбор на сервере.

**Запрос:** тело не требуется, ID компании передаётся в URL.

**Ответ 200:** обновлённый пользователь (та же структура что `GET /api/user`, с новыми `active_company_id` и `active_company`).

**Коды ошибок:**

| Код | Причина |
|---|---|
| 401 | Не авторизован |
| 403 | Нет доступа к этой компании (не в `company_accesses`) |
| 404 | Компания не найдена |

> Все последующие запросы (отчёты, чаты) автоматически получают scoping по активной компании — без передачи `company_id` в параметрах.

---

### Пользователи

Доступ: **superadmin** — пользователи **активной** компании, **admin** — пользователи своей компании.

#### `GET /api/users`

Список пользователей.

**Ответ 200:**
```json
[
    {
        "id": 1,
        "name": "TTeqwwd",
        "email": "e.vetrov@macroglobaltech.com",
        "role": "superadmin",
        "locale": "ru",
        "company_id": 1,
        "company": {"id": 1, "name": "Vizion"}
    }
]
```

---

#### `POST /api/users`

Создать пользователя.

**Тело запроса:**
```json
{
    "name": "Иван Иванов",
    "email": "ivan@example.com",
    "password": "password123",
    "role": "analyst",
    "company_id": 2,
    "locale": "ru"
}
```

Допустимые роли:
- superadmin может назначить: `superadmin`, `admin`, `analyst`, `viewer`
- admin может назначить: `admin`, `analyst`, `viewer`

**Ответ 200:** Созданный пользователь.

---

#### `GET /api/users/{id}`

Один пользователь.

---

#### `PUT /api/users/{id}`

Обновить пользователя. Все поля необязательные.

**Тело запроса:**
```json
{
    "name": "Новое имя",
    "role": "admin",
    "company_accesses": [
        {"company_id": 1, "role": "admin"},
        {"company_id": 2, "role": "viewer"}
    ]
}
```

**Peer-guard (superadmin):** если `target.role === 'superadmin'` и `target.id !== actor.id`, применяются три блока (self и не-суперадмин-таргеты не затронуты):

- `password` присутствует в запросе → 403 `users.cannot_change_superadmin_password` (проверка по наличию поля, без сравнения значения).
- `role` присутствует и значение отличается от текущего → 403 `users.cannot_change_superadmin_role` (echo-back той же роли без изменения не блокируется).
- `company_accesses` присутствует и содержимое изменилось (с учётом порядка ключей и элементов) → 403 `users.cannot_change_superadmin_access` (echo-back с иным порядком ключей/элементов не считается изменением).

Редактирование только поля `name` другого суперадмина не блокируется.

---

#### `DELETE /api/users/{id}`

Удалить пользователя. Себя удалить нельзя → 403. Суперадмин не может удалить другого суперадмина → 403 `users.cannot_delete_superadmin`.

**Ответ 200:**
```json
{"message": "Пользователь удалён."}
```

---

### Отчёты

Единая таблица `reports`. Системные (`is_system: true`) создаются через сидеры, пользовательские — через чат или API.

Доступ по ролям:
- **superadmin** — все отчёты любой компании + системные
- **admin** — все отчёты своей компании + системные, может публиковать (`is_published`)
- **analyst** — системные + свои + опубликованные своей компании
- **viewer** — системные + опубликованные своей компании

#### `GET /api/reports`

Список отчётов. Scoping идёт через активную компанию (`users.active_company_id`). Чтобы увидеть отчёты другой компании — сначала переключись через `POST /api/active-company/{id}`.

**Ответ 200:**
```json
[
    {
        "id": 1,
        "title": {"ru": "Продажи", "en": "Sales"},
        "description": {"ru": "Описание", "en": "Description"},
        "config": {"type": "bar", "data": {}, "options": {}},
        "is_system": true,
        "is_published": false,
        "user_id": null,
        "company_id": null,
        "chat_message_id": null,
        "chat_id": null,
        "created_at": "2026-03-01T10:15:00+00:00",
        "updated_at": "2026-03-01T10:15:00+00:00",
        "author": null
    },
    {
        "id": 2,
        "title": {"ru": "Мой отчёт", "en": "My Report"},
        "description": null,
        "config": {"type": "line", "data": {}},
        "is_system": false,
        "is_published": false,
        "user_id": 3,
        "company_id": 1,
        "chat_message_id": 15,
        "chat_id": 13,
        "created_at": "2026-05-12T08:22:14+00:00",
        "updated_at": "2026-05-12T08:22:14+00:00",
        "author": {
            "id": 3,
            "name": "Иван Петров",
            "email": "ivan@example.com"
        }
    }
]
```

Поле `author` — `{id, name, email} | null`. `null` для системных отчётов (`is_system: true`, `user_id: null`). Используется в меню действий с отчётом для подсказки «Кто создал».

`created_at` — ISO 8601 в UTC.

---

#### `POST /api/reports`

Создать отчёт (сохранить из чата). Доступ: superadmin, admin, analyst.

**Тело запроса:**
```json
{
    "title": {"ru": "Мой отчёт", "en": "My Report"},
    "description": {"ru": "Описание"},
    "config": {"type": "bar", "data": {}, "options": {}},
    "chat_message_id": 15
}
```

`title` и `description` — JSON-объект с ключами языков.
`chat_message_id` — необязательный, ссылка на сообщение чата.

---

#### `GET /api/reports/{id}`

Один отчёт. Возвращает полный shape с данными из MacroData (columns / rows / meta / filters_available / filters_applied / totals / config) плюс поля для меню действий: `created_at` (ISO 8601), `updated_at` (ISO 8601), `is_system` (bool), `is_published` (bool), `user_id` (int|null — null для системных), `chat_message_id` (int|null), `chat_id` (int|null — ID связанного чата типа `report_generation`; null для системных и legacy-отчётов без чата), `author` ({id, name, email}|null — null для системных). Все поля живут на верхнем уровне ответа рядом с `columns` и `rows`.

Полный пример shape данных см. §«Страница: Отчёт» → «Ответ API (реальные данные из MacroData)».

---

#### `PUT /api/reports/{id}`

Обновить отчёт. Системные (`is_system: true`) редактировать нельзя — 403.

**Тело запроса:**
```json
{
    "title": {"ru": "Новое название"},
    "config": {"type": "pie", "data": {}},
    "is_published": true
}
```

`is_published` — могут менять только admin и superadmin.

---

#### `DELETE /api/reports/{id}`

Удалить отчёт. Системные (`is_system: true`) удалить нельзя — 403.

**ACL:**
- superadmin — любой отчёт активной компании
- admin — отчёт своей активной компании
- analyst — только свой отчёт (`user_id` совпадает)
- viewer — 403

**Каскадно удаляет связанный чат.** Backend в одной транзакции (`DB::transaction`) находит связанный `Chat` через `Report::chat()` (hasOne по `chats.report_id` FK) и удаляет его вместе со всеми его `chat_messages` (FK cascade). Если у отчёта нет pinned-чата (старые данные — `report_id` не заполнен), операция no-op (защита от случайного удаления чужих чатов).

**Ответ 200:**
```json
{"message": "Отчёт удалён."}
```

---

#### `POST /api/reports/{id}/publish`

Опубликовать пользовательский отчёт — выставляет `is_published=true`. После публикации отчёт виден всем пользователям компании (включая `viewer` и других `analyst`-ов).

**ACL:**
- superadmin — любой пользовательский отчёт
- admin — пользовательский отчёт своей активной компании
- analyst / viewer — 403
- Системный отчёт (`is_system: true`) — 403 (системные отчёты и так видны всем, флаг публикации к ним неприменим)

Тело запроса пустое.

**Ответ 200** — обновлённый Report (тот же shape, что у `GET /api/reports/{id}` в индекс-форме, со всеми model-полями + `author`):

```json
{
    "id": 17,
    "title": {"ru": "Продажи Q1", "en": "Sales Q1"},
    "description": null,
    "config": {...},
    "is_system": false,
    "is_published": true,
    "user_id": 3,
    "company_id": 1,
    "chat_message_id": 42,
    "created_at": "2026-05-12T08:22:14+00:00",
    "updated_at": "2026-05-24T11:00:00+00:00",
    "author": {"id": 3, "name": "Иван Петров", "email": "ivan@example.com"}
}
```

**Ошибки:** 401 не авторизован, 403 нет прав / системный отчёт, 404 отчёт не найден.

---

#### `POST /api/reports/{id}/unpublish`

Зеркальный к `publish` — снимает с публикации, `is_published=false`. ACL и формат ответа полностью совпадают с `POST /api/reports/{id}/publish`.

---

#### `GET /api/reports/{report}/dashboard-data` — [REMOVED 2026-05-24]

Эндпоинт удалён. Dashboard-режим более не привязан к отдельному отчёту — виджеты теперь самостоятельные сущности `widgets`, данные подгружаются через `GET /api/widgets/{id}/data`. Подробности — §Widgets API ниже.

---

#### `GET /api/reports/{report}/filter-options/{field}`

Асинхронная загрузка вариантов для фильтра типа `async_select`. Используется для полей с большим числом уникальных значений, которые нельзя загрузить целиком при открытии страницы.

**Query Parameters:**

| Параметр | Описание |
|---|---|
| `search` | Строка поиска (необязательный), фильтрует варианты |
| `page` | Номер страницы (необязательный, default: 1) |

**Ответ 200:**
```json
[
    {"value": "ЖК Smart", "label": "ЖК Smart"},
    {"value": "Солнечный", "label": "Солнечный"}
]
```

**Ошибки:** 403 если нет доступа к отчёту, 404 если поле не найдено в `filters_available`.

---

### User report preferences API

Persist UI-настройки конкретного пользователя для конкретного отчёта на бэке. Хранит порядок и видимость колонок таблицы — настройки переезжают с устройства на устройство.

> Dashboard-режим, расположение виджетов и группировка виджетов перенесены в самостоятельные сущности `Dashboard` / `Widget`. Report preferences теперь хранят только колоночные настройки.

Видимость preferences = видимость отчёта (тот же ACL, что у `GET /api/reports/{id}`): если фронт не может открыть отчёт — preferences тоже 403. Активная компания берётся из middleware (`users.active_company_id`).

#### `GET /api/reports/{id}/preferences`

Текущие preferences пользователя для этого отчёта. Если записи ещё нет — **возвращает 200 с дефолтами** (а не 404), чтобы фронт не ветвился.

**Ответ 200:**
```json
{
  "report_id": 42,
  "column_order": {"order": ["field_a", "field_b", "field_c"], "hidden": ["field_b"]}
}
```

Если записи нет:
```json
{
  "report_id": 42,
  "column_order": null
}
```

| Поле | Тип | Значение по умолчанию |
|---|---|---|
| `column_order` | `{order: string[], hidden?: string[]} \| null` | `null` (естественный порядок, все колонки видны). `order` — массив `field`-имён в нужном порядке; `hidden` — массив скрытых `field`-имён (опционально). Устаревший ключ `groups` (был до 2026-05-21) backend игнорирует. |

**Ошибки:** 401 не авторизован, 403 нет доступа к отчёту.

#### `PUT /api/reports/{id}/preferences`

Partial upsert. Поля, которых **нет в теле запроса**, не трогаются; поля, отправленные явно как `null`, очищаются.

**Запрос:**
```json
{
  "column_order": {"order": ["field_a", "field_b", "field_c"], "hidden": ["field_b"]}
}
```

Сброс порядка:
```json
{ "column_order": null }
```

**Ответ 200:** полный документ preferences (та же форма, что `GET`).

**Валидация (422):**
- `column_order`: объект с обязательным ключом `order` (массив строк) и опциональным `hidden` (массив строк); или `null`

**Ошибки:** 401 не авторизован, 403 нет доступа к отчёту, 422 невалидный payload.

> Один документ на пару (`user_id`, `report_id`) — повторный `PUT` обновляет ту же запись (unique index гарантирует one-row-per-pair).

**Стратегия кеширования на фронте (singleton `useReportPreferences`):**
- `GET /api/reports/{id}/preferences` вызывается при монтировании/смене отчёта (`watch(reportId, immediate: true)`, guard на `id <= 0`). До ответа сервера фронт показывает данные из localStorage (мгновенный первый paint без мигания). После получения ответа — localStorage перезаписывается актуальными данными сервера.
- `PUT` отправляется с debounce 600ms после последнего изменения. Optimistic update: фронт применяет изменение немедленно, откатывает при ошибке сети. Явный `null` в теле PUT сбрасывает поле на бэке; отсутствующий ключ поле не трогает.

---

### Widgets API

Виджет — самостоятельная сущность с chart-конфигом, привязанная к отчёту как источнику данных. Виджеты группируются в Dashboards. Данные виджета подгружаются через отдельный endpoint.

**ACL:** superadmin — любые виджеты активной компании; admin — виджеты своей компании; analyst — виджеты опубликованных отчётов и своих; viewer — виджеты опубликованных и системных отчётов. Виджеты системных отчётов (`is_system: true`) видны всем.

#### `GET /api/widgets`

Список виджетов текущего пользователя/компании.

**Query Parameters:**

| Параметр | Тип | Описание |
|---|---|---|
| `report_id` | int | Фильтр по отчёту (необязательный) |
| `dashboard_id` | int | Фильтр по дашборду (необязательный) |

**Ответ 200:**
```json
[
  {
    "id": 1,
    "title": {"ru": "Продажи по ЖК", "en": "Sales by Complex"},
    "report_id": 5,
    "chart_type": "bar",
    "value_field": "deal_sum",
    "label_field": "geo_complex_name",
    "aggregation": "sum",
    "is_published": false,
    "created_at": "2026-05-24T10:00:00+00:00",
    "updated_at": "2026-05-24T10:00:00+00:00"
  }
]
```

---

#### `POST /api/widgets`

Создать виджет.

**Тело запроса:**
```json
{
  "title": {"ru": "Продажи по ЖК", "en": "Sales by Complex"},
  "report_id": 5,
  "chart_type": "bar",
  "value_field": "deal_sum",
  "label_field": "geo_complex_name",
  "aggregation": "sum"
}
```

Допустимые значения `chart_type`: `"bar"`, `"line"`, `"pie"`, `"doughnut"`.

Допустимые значения `aggregation`: `"sum"`, `"count"`, `"avg"`, `"max"`, `"min"`.

**Ответ 200:** созданный виджет (полная форма как в GET).

---

#### `GET /api/widgets/{id}`

Один виджет.

**Ошибки:** 401, 403, 404.

---

#### `PUT /api/widgets/{id}`

Обновить виджет. Все поля необязательные.

**Тело запроса:**
```json
{
  "title": {"ru": "Новое название"},
  "chart_type": "pie",
  "aggregation": "count"
}
```

**Ответ 200:** обновлённый виджет.

---

#### `DELETE /api/widgets/{id}`

Удалить виджет. Системные виджеты и чужие виджеты удалять нельзя (403). Viewer — 403.

**Query Parameters:**

| Параметр | Тип | Описание |
|---|---|---|
| `force` | boolean | `true` — каскадно открепить виджет из всех дашбордов и удалить. Без `force`, если виджет используется хотя бы в одном дашборде, возвращается 409. |

**Ответ 200:**
```json
{"message": "Виджет удалён."}
```

**Ошибка 409** (виджет используется, `force` не передан):
```json
{"message": "Виджет используется в дашбордах.", "used_in_dashboards_count": 2}
```

Флаг `force` не обходит ACL: системный/чужой/viewer → 403 в любом случае.

---

#### `POST /api/widgets/preview`

Эфемерный chart-payload по произвольному конфигу без сохранения в БД. Используется двухшаговым flow генерации виджета: frontend получает `widget_variants` event с 2-4 конфигами, запрашивает превью каждого параллельно и показывает карточки для выбора.

**Тело запроса:**
```json
{
  "config": {
    "primary_model": "EstateDeals",
    "group_by": { "fields": ["estateDealsStatuses.status_name"] },
    "aggregates": [{ "fn": "count", "as": "cnt" }],
    "chart": { "type": "doughnut", "label_field": "estateDealsStatuses.status_name", "value_field": "cnt" }
  },
  "period_from": "2025-06",
  "period_to": "2026-05"
}
```

`period_from` / `period_to` — необязательные, `YYYY-MM`.

**Ответ 200:** та же форма, что `GET /api/widgets/{id}/data` (новый shape: `labels[]`, `datasets[]`, `meta`).

**Ошибки:** 401 не авторизован, 403 нет активной компании, 422 невалидный конфиг.

**ACL:** любая аутентифицированная роль — данные берутся в контексте активной компании. Ничего не сохраняется.

---

#### `POST /api/widgets/{id}/publish`

Опубликовать виджет. ACL: admin/superadmin. Системные — 403.

**Ответ 200:** обновлённый виджет с `is_published: true`.

---

#### `POST /api/widgets/{id}/unpublish`

Снять виджет с публикации. ACL и формат ответа идентичны `publish`.

---

#### `GET /api/widgets/{id}/data`

Данные для рендера виджета. Backend выполняет GROUP BY по `group_by.fields`, агрегацию (`aggregates`), top-N (если `chart.limit`), temporal label formatting (если поле содержит `|month`), фильтрацию пустых лейблов (дефолт: исключать). Возвращает готовый chart-payload.

**Query Parameters:**

| Параметр | Тип | Описание |
|---|---|---|
| `filters` | array | Те же фильтры, что у связанного отчёта (необязательные) |
| `period_from` | string | Начало периода `YYYY-MM` (включительно). Только для виджетов с `period_field` в конфиге. |
| `period_to` | string | Конец периода `YYYY-MM` (включительно). |

Если `period_field` задан в конфиге виджета, но `period_from`/`period_to` не переданы — backend применяет дефолтный диапазон последних 12 месяцев. Виджеты без `period_field` игнорируют эти параметры.

**Ответ 200:**
```json
{
  "labels": ["ЖК Smart", "Солнечный", "ЖК Лазурный"],
  "datasets": [
    {"label": "Сумма сделок", "data": [15000000, 12000000, 8000000]}
  ],
  "meta": {
    "widget_limited": false,
    "period_from": "2025-06",
    "period_to": "2026-05",
    "period_applied": true,
    "others_count": 0
  }
}
```

`datasets[].label` — может быть плоской строкой (`"Сумма сделок"`) **или** локализованным объектом (`{"ru": "Сумма сделок", "en": "Deal Amount"}`). Фронт резолвит по активной локали через `getLocalizedText()`. Backward-compatible: плоская строка отображается как есть.

`meta.others_count` — сколько групп было свёрнуто в «Другие» (top-N режим, если `chart.others_label` задан). Сам лейбл «Другие» / «Others» формируется бэком по локали запроса (заголовок `Accept-Language`).

`meta.period_applied` — `true`, если период фактически применён к запросу. `false` для виджетов без `period_field`.

Если сработал cap 5000:
```json
{
  "labels": [...],
  "datasets": [...],
  "meta": {
    "widget_limited": true,
    "widget_limit": 5000,
    "widget_total_estimate": 17234,
    "period_applied": false
  }
}
```

`widget_total_estimate` может быть `null`, если точный COUNT недоступен. Фронт при `widget_limited === true` показывает предупреждение о неполноте данных.

**Устаревший shape (до 2026-05-24):** ранее endpoint возвращал `rows[]` + `filters_applied`. Новый shape — `labels[]` + `datasets[]` + `meta`. Фронт использует новый shape.

**Live-превью в конструкторе виджета:** endpoint `GET /api/widgets/{id}/data` используется также для предпросмотра во время редактирования. Composable `useWidgetPreviewData` кэширует последний ответ и дебаунсирует запросы при изменении конфига. Чипы-пресеты (aggregation, chart_type) в форме конструктора применяются мгновенно — превью обновляется после дебаунса.

**Ошибки:**

| Код | Когда |
|---|---|
| 401 | Не авторизован |
| 403 | Нет доступа к виджету / отчёту |
| 404 | Виджет не найден |

---

### Dashboards API

Dashboard — набор виджетов с позиционированием на grid-сетке (drag/resize через `grid-layout-plus`). Dashboard может агрегировать виджеты из разных отчётов. Настройки layout (поля `x`, `y`, `w`, `h`, `sort`, `visible`) хранятся в pivot-таблице `dashboard_widget`, не в отдельной таблице layouts.

**ACL:** superadmin — любые дашборды активной компании; admin — дашборды своей компании; analyst — свои + опубликованные; viewer — только опубликованные.

#### `GET /api/dashboards`

Список дашбордов.

**Ответ 200:**
```json
[
  {
    "id": 1,
    "name": {"ru": "Главный дашборд", "en": "Main Dashboard"},
    "is_published": false,
    "is_system": false,
    "user_id": 3,
    "company_id": 1,
    "author": {"id": 3, "name": "Иван Иванов"},
    "created_at": "2026-05-24T10:00:00+00:00",
    "updated_at": "2026-05-24T10:00:00+00:00",
    "widgets_count": 4
  }
]
```

---

#### `POST /api/dashboards`

Создать дашборд.

**Тело запроса:**
```json
{
  "name": {"ru": "Главный дашборд", "en": "Main Dashboard"}
}
```

**Ответ 200:** созданный дашборд.

---

#### `GET /api/dashboards/{id}`

Один дашборд с виджетами и их layout для текущего пользователя.

**Ответ 200:**
```json
{
  "id": 1,
  "name": {"ru": "Главный дашборд", "en": "Main Dashboard"},
  "is_published": false,
  "is_system": false,
  "user_id": 3,
  "company_id": 1,
  "author": {"id": 3, "name": "Иван Иванов"},
  "created_at": "2026-05-24T10:00:00+00:00",
  "updated_at": "2026-05-24T10:00:00+00:00",
  "widgets": [
    {
      "id": 1,
      "name": {"ru": "Продажи по ЖК", "en": "Sales by Complex"},
      "config": { "primary_model": "EstateDeals", "group_by": { "fields": ["geo_complex_name"] }, "aggregates": [{ "fn": "sum", "field": "deal_sum", "as": "deal_sum" }], "chart": { "type": "bar", "label_field": "geo_complex_name", "value_field": "deal_sum" } },
      "is_system": false,
      "is_published": false,
      "user_id": 3,
      "pivot": {"x": 0, "y": 0, "w": 6, "h": 4, "sort": 0, "visible": true}
    }
  ]
}
```

Поле `pivot` содержит позицию и видимость виджета в данном дашборде (из pivot-таблицы `dashboard_widget`). При первом прикреплении виджета `sort` = порядковый номер, остальные поля — дефолтные.

---

#### `PUT /api/dashboards/{id}`

Обновить дашборд. Системные (`is_system: true`) редактировать нельзя — 403.

**Тело запроса** (все поля необязательны; admin/superadmin могут менять `is_published` через этот endpoint, либо через специализированные publish/unpublish):
```json
{
  "name": {"ru": "Новое название", "en": "New Title"},
  "is_published": true
}
```

**Ответ 200:** обновлённый дашборд.

---

#### `DELETE /api/dashboards/{id}`

Удалить дашборд. Системные удалять нельзя — 403.

**Ответ 200:**
```json
{"message": "Дашборд удалён."}
```

---

#### `POST /api/dashboards/{id}/clone`

Клонировать дашборд (создаёт копию с `is_system: false`, `is_published: false`). Виджеты привязки копируются; layout сбрасывается в дефолтный.

**Тело запроса:** пустое.

**Ответ 200:** новый дашборд (полная форма).

---

#### `POST /api/dashboards/{id}/publish`

Опубликовать дашборд.

**ACL:** admin — только своя компания; superadmin — любой дашборд активной компании. Системные (`is_system: true`) — 403. Viewer/analyst — 403.

**Ответ 200:** dashboard payload **без поля `widgets`** (только скалярные поля + `author`), с `is_published: true`.

---

#### `POST /api/dashboards/{id}/unpublish`

Снять дашборд с публикации.

**ACL и форма ответа** — идентичны `/publish`, с `is_published: false`.

---

#### `POST /api/dashboards/{id}/widgets/{widget_id}`

Прикрепить виджет к дашборду.

**Тело запроса:** пустое (или `{"layout": {"x": 0, "y": 0, "w": 6, "h": 4}}`).

**Ответ 200:** обновлённый дашборд с полным списком виджетов.

**Ошибки:** 404 виджет или дашборд не найден, 422 виджет уже прикреплён к этому дашборду.

---

#### `DELETE /api/dashboards/{id}/widgets/{widget_id}`

Открепить виджет от дашборда (виджет не удаляется, только связь).

**Ответ 200:** обновлённый дашборд.

---

#### `PUT /api/dashboards/{id}/layout`

Сохранить позиции виджетов на дашборде для текущего пользователя. Вызывается после drag/resize (с debounce 600ms).

**Тело запроса:**
```json
{
  "widgets": [
    {"widget_id": 1, "x": 0, "y": 0, "w": 6, "h": 4},
    {"widget_id": 2, "x": 6, "y": 0, "w": 6, "h": 4}
  ]
}
```

**Ответ 200:**
```json
{"message": "Layout сохранён."}
```

**Валидация (422):**
- `layout`: массив объектов с `widget_id` (int), `x` `y` (int ≥ 0), `w` `h` (int ≥ 1).

**ACL:** `canManageDashboardLayout` — viewer получает layout read-only (drag запрещён, PUT возвращает 403).

---

#### `GET /api/dashboards/{id}/data`

Сводный payload данных для всех виджетов дашборда за один запрос. Backend получает данные для каждого виджета и возвращает словарь `widget_id → data`.

**Query Parameters:**

| Параметр | Тип | Описание |
|---|---|---|
| `period_from` | string | `YYYY-MM` — применяется ко всем виджетам дашборда, у которых задан `period_field`. Необязательный. |
| `period_to` | string | `YYYY-MM` — конец диапазона (включительно). Необязательный. |

**Ответ 200:**
```json
{
  "widgets": {
    "1": {
      "labels": ["ЖК Smart", "Солнечный"],
      "datasets": [{"label": "Сумма", "data": [15000000, 12000000]}],
      "meta": {"widget_limited": false, "period_applied": true}
    },
    "2": {
      "labels": ["..."],
      "datasets": [{"label": "...", "data": [...]}],
      "meta": {"widget_limited": false, "period_applied": false}
    }
  },
  "meta": {
    "period_from": "2025-06",
    "period_to": "2026-05"
  }
}
```

Ключи в `widgets` — строковые `widget_id`. Формат каждого значения — как в `GET /api/widgets/{id}/data` (`labels[]`, `datasets[]`, `meta`).

Ошибки отдельных виджетов не прерывают весь запрос — вместо `labels/datasets` приходит объект `{"error": "..."}`.

---

### Чат

Реализован. Подробный API-контракт — в `chats_frontend.md`.

Ключевые эндпоинты:
- `POST /api/chats` — создать чат
- `POST /api/chats/{id}/messages` — отправить сообщение → 202 Accepted + `stream_url`
- `GET /api/chats/{id}/messages` — получить сообщения (с `status`, `events_count`)
- `GET /api/chats/{id}/stream/{message_id}` — SSE-поток активного AI-турна
- `GET /api/chats/{id}/messages/{message_id}/events` — batch JSON событий для reload-восстановления timeline
- `GET /api/chats` — список чатов
- `GET /api/chats/{id}` — чат с сообщениями и привязанным отчётом
- `DELETE /api/chats/{id}` — удалить чат

---

---

## Структура интерфейса

### Layout

```
┌──────────────────────────────────────────────────┐
│  Header                    [Компания ▾] [Профиль ▾] │
├──────────┬───────────────────────────────────────┤
│ Sidebar  │                                       │
│          │                                       │
│ Отчёты   │           Контент страницы            │
│ Чат      │                                       │
│ Компания │                                       │
│          │                                       │
└──────────┴───────────────────────────────────────┘
```

### Header (правая часть)

**Переключатель компаний `[Компания ▾]`:**
- Данные: `GET /api/user` → поля `company_accesses`, `active_company_id`, `active_company`
- Одна компания → переключатель **скрыт**
- Несколько компаний → дропдаун для переключения
- superadmin → дропдаун со всеми компаниями (`GET /api/companies`) + кнопка "Управление" (открывает модалку CRUD компаний)
- Текущая активная компания хранится **на сервере** (`users.active_company_id`), персистируется при смене через `POST /api/active-company/{id}`
- При смене → `POST /api/active-company/{id}` → обновить стор → перезагрузить данные страницы

**API для переключателя:**
- `GET /api/user` — получить `company_accesses`, `active_company_id`, `active_company`
- `POST /api/active-company/{id}` — переключить активную компанию (server-side persist)
- `GET /api/companies` — список всех компаний (superadmin)
- `POST /api/companies` — создать компанию (superadmin, модалка)
- `PUT /api/companies/{id}` — редактировать (superadmin, модалка)
- `DELETE /api/companies/{id}` — удалить (superadmin)

**Профиль `[Профиль ▾]`:**
- Всплывающее окно / модалка
- Показывает: имя, email, язык, роль
- Можно менять: имя, язык, пароль
- Кнопка "Выйти"

**API для профиля:**
- `GET /api/user` — данные профиля
- `PUT /api/user` — обновить (name, locale, password)
- `POST /api/logout` — выйти

### Sidebar

| Раздел | Видят | Описание |
|---|---|---|
| 📊 Отчёты | все роли | Стандартные + кастомные отчёты |
| 💬 Чат | superadmin, admin, analyst | AI-чат |
| 🏢 Компания | superadmin, admin | Настройки компании + пользователи |

---

### Страница: Логин (`/login`)

Форма: email + пароль. Без sidebar и header.

**API:**
- `POST /api/login` → получить токен → сохранить в localStorage → редирект на `user.homePath` (поле `home_path` из API-ответа; дефолт `/reports`)

---

### Корневой URL

`/` → router guard redirect → `user.homePath` (дефолт `/reports`). Реализовано через `resolveNavigation` в `policy.ts` — НЕ через статический `redirect` в маршруте, чтобы guard мог обратиться к user store. Защита от redirect-loop: если `homePath === '/'`, фолбэк на `/reports`.
Прямые переходы на `/company`, `/ai-chat` и т.д. работают без изменений — guards по ролям сохранены.

---

### Страница: Отчёты (`/reports`)

Список всех доступных отчётов (карточки/плитки). Один источник — таблица `reports`:
- Системные отчёты (`is_system: true`) — созданы через сидеры
- Пользовательские отчёты (`is_system: false`) — созданы из чата

Клик по карточке → переход на `/reports/{id}`.

**API:**
- `GET /api/reports` — все отчёты (системные + свои + опубликованные) в контексте активной компании
- `PUT /api/reports/order` — сохранить пользовательский порядок карточек (drag-and-drop)

**`PUT /api/reports/order`:**

Тело запроса: `{"order": [42, 1, 7, 3]}` — массив `id` отчётов в желаемом порядке (только те, которые пользователь видит в своём контексте).

Ответ 200: `{"company_id": 2, "order": [42, 1, 7, 3]}`

Порядок хранится в таблице `user_report_orders` (per-user, per-company). Когда запись существует — она перекрывает глобальный `reports.sort_order` (дефолтный порядок из сидеров: 10 / 20 / 30 / 40 / 50 / 60). Если пользователь ни разу не перетаскивал — список отдаётся по `sort_order` аsc.

Drag-and-drop реализован через `vuedraggable 4` на странице `/reports`. После drop фронт немедленно обновляет локальный порядок (optimistic update) и вызывает `PUT /api/reports/order`.

**Видимость по ролям:**
- superadmin — системные + все отчёты активной компании
- admin — системные + все отчёты своей компании
- analyst — системные + свои + опубликованные
- viewer — системные + опубликованные

---

### Страница: Отчёт (`/reports/{id}`)

Внутри отчёта:
- **Фильтры** (период, статус и т.д.)
- **ECharts (vue-echarts)** — рендер виджет-карточек на Dashboard-странице. Chart.js выпилен из зависимостей (2026-05-24). Тема `VIZION_ECHARTS_PALETTE` в `plugins/echarts.ts`. Форматтеры: деньги (млн/млрд + валюта), даты (месяцы), подписи серий — `front/src/utils/chartFormatters.ts`.
- **Таблица** с данными

**API:**
- `GET /api/reports/{id}?page=1&per_page=100&filters[deal_date][from]=2024-01-01&filters[deal_status][0]=150`
- `PUT /api/reports/{id}` — обновить (title, config, is_published)
- `DELETE /api/reports/{id}` — удалить (нельзя для `is_system: true`)

#### Ответ API (реальные данные из MacroData):

```json
{
    "id": 1,
    "title": {"ru": "Реестр сделок", "en": "Deals Registry"},
    "description": {"ru": "...", "en": "..."},
    "columns": [
        {"field": "deal_date", "header": {"ru": "Дата договора", "en": "Contract Date"}, "type": "date", "sortable": true},
        {"field": "estateSells.estateHouses.geoCityComplex.geo_complex_name", "header": {"ru": "ЖК", "en": "Complex"}, "sortable": true},
        {"field": "estateSells.geo_flatnum", "header": {"ru": "Квартира", "en": "Apartment"}, "sortable": true},
        {"field": "deal_sum", "header": {"ru": "Стоимость", "en": "Price"}, "type": "currency", "sortable": true},
        {"field": "to_pay", "header": {"ru": "К оплате", "en": "To Pay"}, "type": "currency", "expression": "deal_sum - finances_income"}
    ],
    "rows": [
        {
            "deal_date": "2024-03-15T00:00:00.000000Z",
            "estateSells.estateHouses.geoCityComplex.geo_complex_name": "ЖК Smart",
            "estateSells.geo_flatnum": "45",
            "deal_sum": "8000000.00",
            "finances_income": "2400000.00",
            "to_pay": 5600000
        }
    ],
    "meta": {
        "total": 1834,
        "page": 1,
        "per_page": 100,
        "last_page": 19
    },
    "totals": {
        "deal_sum": 14600000000,
        "to_pay": 3200000000
    },
    "chart": {
        "type": "bar",
        "labels": ["ЖК Smart", "Солнечный", "Лазурный"],
        "datasets": [{"label": "Сумма сделок", "data": [15000000, 12000000, 8000000]}]
    },
    "filters_available": {
        "deal_date": {"type": "date_range"},
        "deal_status": {
            "type": "multiselect",
            "source": "enum",
            "options": [
                {"value": 100, "label": {"ru": "Бронь", "en": "Reservation"}},
                {"value": 150, "label": {"ru": "Сделка завершена", "en": "Deal completed"}}
            ]
        }
    },
    "filters_applied": {
        "deal_date": {"from": "-90 days", "to": "today"},
        "deal_status": [100, 110, 120, 150]
    }
}
```

**`totals` — итоговая строка футера:**

Объект `totals: {<field>: <value>}` появляется в ответе, если хотя бы одна колонка отчёта содержит ключ `footer`. Значение `footer` задаётся в `config.columns[].footer` как объект с агрегирующей функцией, например `{"agg": "sum"}`. Backend вычисляет агрегат по всей выборке (без учёта пагинации) и помещает результат в `totals[<field>]`. Фронт рендерит строку-футер в `<DataTable>` с этими значениями.

Поддерживаемые значения `footer.agg`: `sum`, `avg`, `min`, `max`, `count`.

Если в конфиге нет ни одной `footer`-колонки — ключ `totals` отсутствует в ответе (backward-compatible).

#### Фильтры: как работает

**1. Frontend получает `filters_available`** — какие фильтры есть и их типы:
- `date_range` — выбор периода (from/to)
- `multiselect` — множественный выбор из списка
- `select` — одиночный выбор
- `async_select` — server-side поиск (lazy-load, GET `{search_endpoint}?q=...`). `multiple: true` → `<MultiSelect>` (payload как `string[]`, operation `has_any_pivot`). Реализован через `AsyncSelectFilter.vue`.

**Дефолтные значения фильтров (`filter_default`):**
Backend может передавать поле `default` в каждом фильтре `filters_available`. Фронт на первой загрузке страницы применяет эти дефолты автоматически: они распаковываются в `localFilters` / `currentFilters`, и запрос повторяется уже с дефолтами. Кнопка "Сбросить фильтры" обнуляет до `{}` (не до дефолтов) — «reset = показать всё».

**2. Frontend показывает UI фильтров** с дефолтными значениями из `filters_applied`

**3. Пользователь меняет фильтры**, жмёт "Применить"

**4. Frontend шлёт запрос:**
```
GET /api/reports/{id}?filters[deal_date][from]=2024-01-01&filters[deal_date][to]=2024-12-31&filters[deal_status][0]=150
```

**5. Backend применяет фильтры и возвращает отфильтрованные данные**

#### Типы колонок:

| type | Описание | Формат |
|------|----------|--------|
| `date` | Дата | ISO 8601 |
| `datetime` | Дата и время | ISO 8601 |
| `currency` | Валюта | Число с 2 знаками |
| `number` | Число | С форматом из `format` |
| `badge` | Статус | Подсветка |
| `text` | Текст (default) | — |
| `link` | Кликабельная ссылка | см. ниже |
| `concat_relation` | Агрегация значений m2m/hasMany в одну строку | Backend собирает через PHP (eager-loaded коллекция → implode), фронт получает строку — рендерится как `text` |
| `payment_schedule` | Мини-таблица платёжного графика | Backend возвращает структурированный объект (header row + items + summary). Фронт рендерит через `PaymentScheduleCell.vue`: header строка, expandable items, tfoot с итогами. |

**Форматирование дат (`useFormatter`):**

Даты во всех ячейках таблицы отображаются в формате `dd.mm.yyyy` независимо от UI-локали (RU или EN). При наличии времени (`datetime`-колонки) — `dd.mm.yyyy HH:mm`.

Реализовано в `front/src/composables/useFormatter.ts` (`formatDate`): используется `Intl.DateTimeFormat` с фиксированным locale `en-GB` (источник цифровых частей, не зависит от активной локали) + `formatToParts()` для ручной сборки строки в порядке `day.month.year`. ISO-строки из MacroData приходят в UTC и конвертируются в timezone активной компании перед форматированием.

**Опциональный флаг `badge` на колонке (overdue-индикатор):**

Любая колонка может содержать поле `badge` с описанием условия для отображения бейджа рядом со значением:

```json
{
  "field": "date_to",
  "type": "datetime",
  "badge": {
    "condition": {
      "type": "overdue",
      "date_field": "date_to",
      "status_field": "status",
      "unpaid_status": [3]
    },
    "severity": "danger",
    "label": {"ru": "Просрочено {days} д.", "en": "Overdue {days}d"}
  }
}
```

Когда условие выполнено, backend добавляет в строку `rows` ключ `_badge_<field>: {severity, label}`. Фронт проверяет наличие этого ключа и рендерит PrimeVue `<Badge>` рядом со значением ячейки. Поддерживается подстановка `{days}` — количество дней просрочки.

Сейчас поддержан единственный тип условия: `overdue` (дата платежа < сегодня И статус входит в `unpaid_status`).

**Тип `link` — дополнительные поля конфига колонки:**

- `link_template` (string, обязательно) — шаблон URL с плейсхолдерами `{crm_url}` и `{<field>}` (любое поле строки данных, поддерживает dot-notation, например `{estateSells.geo_flatnum}`). Пример: `"{crm_url}/account/estate/view/{deal_id}/"`.
- `label_field` (string, обязательно) — имя поля строки, значение которого используется как текст ссылки. Поддерживает dot-notation (`estateSells.geo_flatnum`). Если значение `null` или пустое → рендерится plain-text без `<a>` (graceful fallback).
- `is_crm_id` (boolean, опционально) — признак того, что вся ячейка является кликабельной ссылкой (оборачивается `<a>` с `position: absolute; inset: 0`). Иконка `pi pi-external-link` отображается рядом с текстом. Используется для ID-полей, у которых нет отдельного текста-лейбла, и вся ячейка должна вести на CRM-объект.
- `sortable` — обычно `false` (link-колонка не сортируется по URL).
- Колонка не участвует в авто-фильтрах.

**Как фронт собирает URL:**
1. Берёт `crm_url` из активной компании пользователя (`companiesStore.currentCompany.crm_url`).
2. Подставляет `{crm_url}` в шаблон.
3. Для каждого `{<field>}` — берёт значение из `tableData[rowIndex][<field>]`.
4. Если `crm_url` пуст или любой плейсхолдер не резолвится → рендерит plain-text без `<a>`.

Backend гарантирует, что значение `label_field` присутствует в строке `rows` (даже если сам `label_field` не объявлен как отдельная visible-колонка).

**Опциональное поле `description` — Tooltip к заголовку колонки:**

- `description?: LocalizedText | null` — tooltip-текст для заголовка колонки. Backend прокидывает jsonb из конфига без обработки. При `null` или отсутствии поля — иконки нет (backward-compatible).
- Формат jsonb: `{"ru": "...", "en": "..."}` (аналогично `header`). Допускается также plain string (отображается без i18n-резолва).
- Фронт рендерит иконку `pi pi-question-circle` (muted-gray) в slot `#header` каждой `<Column>`, только если `description != null && description != ''`. При наведении — PrimeVue `v-tooltip.top` с локализованным текстом через `getLocalizedText()`. Клик на иконку блокируется (`@click.stop`), чтобы не триггерить сортировку столбца.
- Работает во всех четырёх точках рендера заголовков: flat-колонки, ungrouped-колонки в ColumnGroup, grouped sub-header bottom row, дочерние колонки master/detail.
- `ReportDataService::buildColumns()` — passthrough (не имеет whitelist), `description` попадает в ответ API автоматически.
- AI (REPORTS_GUIDE.md ALWAYS-правило): для финансовых и расчётных колонок всегда генерировать `description`.

**Column display flags — дополнительные поля для форматирования:**

Следующие опциональные поля задаются в `config.columns[]` и влияют исключительно на отображение (backend их прокидывает passthrough, фронт интерпретирует):

| Поле | Тип | Описание |
|---|---|---|
| `currency_in_header` | `boolean` | На колонке типа `currency`: символ валюты (берётся из `currency_code` активной компании) уходит в заголовок (`, AED` / `, ₸`) и в строку Итого. Ячейки рендерят голое число без символа валюты. |
| `currency_suffix` | `{ru,en} \| string` | Суффикс после символа валюты, напр. `"/м²"` → заголовок принимает вид «Ст./м², AED/м²». Отражается и в заголовке, и в строке Итого. Используется совместно с `currency_in_header`. |
| `unit` | `{ru,en} \| string` | Единица измерения, отображается **только** в строке Итого рядом с агрегированным значением (напр. «75 шт.», «4 134,3 м²»). В ячейках строк не отображается. |
| `value_type` | `'number' \| 'currency' \| 'date' \| string` | Подсказка фронту для форматирования значений колонок типа `relation_aggregate` и `window_aggregate`. Backend не обрабатывает. Фронт резолвит формат через `resolveColumnType(value_type)`. |
| `label_fallback` | `{ru,en} \| string` | Текст-заглушка когда значение ячейки пустое / null (напр. `{"ru": "Не указан", "en": "Not specified"}`). Отображается в ячейке вместо пустоты. |

**Top-level поле `config.primary_filter` — quick-фильтр в хедере отчёта:**

`primary_filter: string` — `field` фильтра из `filters_available`, который рендерится непосредственно в шапке страницы отчёта (справа от названия), а не только в боковой панели фильтров. Работает в двустороннем режиме: любое изменение в хедер-контроле синхронизируется с панелью и наоборот. Apply происходит немедленно без кнопки «Применить».

Пример в конфиге:
```json
{
  "primary_model": "Finances",
  "primary_filter": "date_to",
  "columns": [...]
}
```

`buildPublicConfigProjection` (backend) пробрасывает `primary_filter` в публичный `config`-ответ наряду с `dashboard_widgets`.

**Опциональный флаг `truncate` для текстовых колонок:**

- `truncate: 'first_word'` — применяется к колонкам типа `text`. Фронт обрезает значение до первого слова (`split(/\s+/)[0]`), полное значение отображается в PrimeVue Tooltip при наведении.
- Если значение `null` или пустое — ячейка пустая, без Tooltip.
- Не сочетается с `link`-колонками (только для `text`).
- Backend ничего не меняет: флаг обрабатывается исключительно на фронте.
- Пример использования: колонка "Контрагент" (ФИО/название ЮЛ) в "Реестре договоров".

#### Expression (computed-поля):

Колонки с `expression` вычисляются на backend:
```json
{"field": "to_pay", "expression": "deal_sum - finances_income"}
```
Frontend получает уже вычисленное значение.

#### Скрытые колонки (`visible: false`):

Колонки с `"visible": false` в конфиге отчёта **не попадают** в ответ API: ни в `columns`, ни в `rows`. Они участвуют в запросе на бэке (нужны для join'ов и expression-полей), но фронт их не видит и не обязан их отображать. Если такой колонки нет в ответе — это нормальное поведение.

#### Dynamic date placeholders в `where`-условиях:

В значениях `where`-условий конфига отчёта поддерживаются динамические плейсхолдеры, которые backend резолвит в Carbon на момент запроса:

| Плейсхолдер | Значение |
|---|---|
| `{today}` | Текущая дата (Y-m-d) |
| `{now}` | Текущая дата+время |
| `{start_of_month}` | Первый день текущего месяца |
| `{end_of_month}` | Последний день текущего месяца |
| `{start_of_day}` | Начало текущего дня (00:00:00) |
| `{end_of_day}` | Конец текущего дня (23:59:59) |
| `{minus_30_days}` | Сегодня минус 30 дней |
| `{start_of_prev_month}` | Первый день прошлого месяца |
| `{minus_2_months}` | Сегодня минус 2 месяца |

Фронт эти строки не резолвирует — они существуют только в `config`, backend подставляет реальные значения перед выполнением запроса.

#### Group_by — master/detail группировка:

Если в конфиге отчёта присутствует ключ `group_by`, backend возвращает сгруппированные строки вместо плоского списка.

**Конфиг `group_by`:**

```json
{
  "group_by": {
    "fields": [
      "estateSells.estateHouses.geoCityComplex.geo_complex_name",
      "estateSells.geo_flatnum_postoffice",
      "estateDeals.contactsBuy.contacts_buy_name"
    ],
    "aggregates": {
      "overdue_count": {
        "type": "count",
        "where": {
          "type": "overdue",
          "date_field": "date_to",
          "unpaid_status": [3],
          "status_field": "status"
        }
      },
      "overdue_sum": {
        "type": "sum",
        "field": "summa",
        "where": {"type": "overdue", "date_field": "date_to", "unpaid_status": [3], "status_field": "status"}
      },
      "group_total": {"type": "sum", "field": "summa"}
    },
    "collapsible": true,
    "collapsed_by_default": true
  }
}
```

Поддерживаемые типы агрегатов: `count`, `sum`, `avg`, `min`, `max`. Поле `where` в агрегате поддерживает тип `overdue` (та же семантика, что у колонки `badge`).

**Форма grouped-ответа API (`GET /api/reports/{id}`):**

Дочерние строки (`children[]`) в ответе `getData` отсутствуют. Вместо них — скалярные флаги:

```json
{
  "rows": [
    {
      "group_key": "ЖК Smart|||45|||Иванов Иван",
      "group_meta": {
        "fields": {
          "estateSells.estateHouses.geoCityComplex.geo_complex_name": "ЖК Smart",
          "estateSells.geo_flatnum_postoffice": "45",
          "estateDeals.contactsBuy.contacts_buy_name": "Иванов Иван Иванович"
        },
        "aggregates": {
          "overdue_count": 2,
          "overdue_sum": 150000,
          "group_total": 500000
        }
      },
      "children_count": 12,
      "has_children": true
    }
  ],
  "meta": {"total": 3, "page": 1, "per_page": 50, "last_page": 1, "grouped": true, "group_by": {...}}
}
```

`children_count` — количество строк в группе (int). `has_children` — всегда `true` для группы. Дочерние строки загружаются лениво через `GET /api/reports/{id}/group-rows`.

`meta.total` — количество групп (не отдельных строк). Пагинация работает по группам.

**Поле `meta.group_by`** в ответе содержит параметры группировки из конфига:
```json
{
  "meta": {
    "total": 12,
    "page": 1,
    "per_page": 50,
    "last_page": 1,
    "grouped": true,
    "group_by": {
      "collapsible": true,
      "collapsed_by_default": true,
      "fields": ["estateDeals.contactsBuy.contacts_buy_name", "estateSells.geo_flatnum"]
    }
  }
}
```

Фронт читает `collapsed_by_default` из `meta.group_by` (не из `config`, который в ответе не передаётся).

Поле `group_meta.aggregate_labels` — параллельный массив локализованных лейблов агрегатов:
```json
{
  "group_meta": {
    "fields": { "...": "..." },
    "aggregates": { "paid": 55000, "to_pay": 12000 },
    "aggregate_labels": { "paid": "Оплачено", "to_pay": "К оплате" }
  }
}
```

`ReportGroupHeader.vue` отображает агрегаты в формате `"label: value"` (если label есть в `aggregate_labels`). Fallback — ключ агрегата.

Поддерживается также inline-форма агрегата `{value, label}` (если backend вернул объект вместо числа).

**Как фронт отображает grouped-отчёт (lazy drill-down):**

- Два `<DataTable>`: для grouped-режима (expandedRows, expander-колонка, expansion-слот с вложенным DataTable для дочерних строк) и flat-режима (обычная логика).
- Master-строка: рендерится компонентом `ReportGroupHeader.vue`, показывает `group_meta.fields` и `group_meta.aggregates` с лейблами из `aggregate_labels`.
- `collapsible: true` — группы можно сворачивать/разворачивать.
- `collapsed_by_default: true` — читается из `meta.group_by.collapsed_by_default`.
- **Lazy drill-down:** при раскрытии группы (`onRowExpand`) — запрос `GET /api/reports/{id}/group-rows?group_key=...&page=1&per_page=50`. Ответ подставляется в `row.children` локально, без перезагрузки всего отчёта. Expansion-слот отображает loader-state во время запроса и error-state при ошибке. Если `meta.last_page > 1` — показывается кнопка «Загрузить ещё» для подгрузки следующей страницы группы.

---

#### `GET /api/reports/{report}/group-rows` — дочерние строки группы

Лениво загружает дочерние строки для конкретной группы grouped-отчёта.

**Request:**

| Параметр | Тип | Обязательно | Описание |
|---|---|---|---|
| `group_key` | string | да | Composite key группы из поля `group_key` в `getData`-ответе |
| `page` | integer | нет | Страница (default: 1) |
| `per_page` | integer | нет | Строк на странице (default: 50, max: 500) |
| `filters` | array | нет | Те же фильтры, что и в `getData` (применяются поверх группировки) |
| `sort[field]` | string | нет | Поле сортировки |
| `sort[direction]` | string | нет | `asc` или `desc` |

**Пример запроса:**
```
GET /api/reports/7/group-rows?group_key=ЖК%20Smart%7C%7C%7C45%7C%7C%7CИванов&page=1&per_page=50
```

**Response 200:**

```json
{
  "group_key": "ЖК Smart|||45|||Иванов Иван",
  "group_meta": {
    "fields": {
      "estateSells.estateHouses.geoCityComplex.geo_complex_name": "ЖК Smart",
      "estateSells.geo_flatnum_postoffice": "45",
      "estateDeals.contactsBuy.contacts_buy_name": "Иванов Иван Иванович"
    },
    "aggregates": { "overdue_count": 2, "overdue_sum": 150000, "group_total": 500000 },
    "aggregate_labels": { "overdue_count": "Просрочено", "group_total": "Итого" }
  },
  "rows": [
    { "date_to": "2024-03-15T00:00:00.000000Z", "summa": "250000.00", "_row_index": 1 },
    { "date_to": "2024-04-15T00:00:00.000000Z", "summa": "250000.00", "_row_index": 2 }
  ],
  "meta": {
    "total": 12,
    "page": 1,
    "per_page": 50,
    "last_page": 1
  }
}
```

`group_meta` в drill-down ответе вычисляется через SQL-агрегацию над суженным набором строк (не кешируется из `getData`). Агрегаты будут актуальны при применении `filters`.

**Ошибки:**

| Код | Когда |
|---|---|
| 400 | Отчёт не имеет `group_by` в конфиге (`config.group_by` пустой или отсутствует) |
| 403 | Нет доступа к отчёту (не своя компания, не system, не published при role=user) |
| 404 | Отчёт не найден |
| 422 | Ошибка валидации (например `group_key` не передан, `sort.direction` не `asc`/`desc`) |

---

#### [REMOVED] `GET /api/reports/{report}/dashboard-data` — удалён 2026-05-24

Этот эндпоинт удалён. Dashboard больше не является режимом отчёта — это отдельная сущность `Dashboard` с набором самостоятельных `Widget`-сущностей, каждый из которых имеет свои данные. Данные виджета подгружаются через `GET /api/widgets/{id}/data`. Спецификация — §Widgets API и §Dashboards API ниже.

#### whereHas-фильтры в конфиге отчёта:

В `config.where` отчёта может присутствовать условие типа `whereHas` — структурированный фильтр по связи. Это серверная логика (пре-фильтрация датасета), фронт с ней не работает напрямую. Полная спецификация — в `src/REPORTS_GUIDE.md`.

#### extra_filters — самостоятельные фильтры не привязанные к колонке

В `filters_available` могут присутствовать ключи из `config.extra_filters` — фильтры, которые не соответствуют ни одной колонке отчёта. Пример: фильтр по тегам лида в отчёте типа «Реестр заявок».

Такой фильтр приходит с `multiple: true` и `operation: 'has_any_pivot'`:

```json
{
  "tags_any": {
    "type": "async_select",
    "async": true,
    "multiple": true,
    "operation": "has_any_pivot",
    "label": {"ru": "Теги", "en": "Tags"},
    "search_endpoint": "/api/reports/42/filter-options/tags_any"
  }
}
```

Фронт шлёт массив ID: `filters[tags_any][0]=42&filters[tags_any][1]=99`.

`AsyncSelectFilter.vue` рендерит `<MultiSelect>` когда `config.multiple === true`, иначе одиночный `<Select>`.

#### Relation chains:

Поля через точку — вложенные связи:
```
estateSells.estateHouses.geoCityComplex.geo_complex_name
↓
EstateDeals → EstateSells → EstateHouses → GeoCityComplex
```

Кнопка "Опубликовать" (только admin, superadmin):
- `PUT /api/reports/{id}` с `{"is_published": true}`

#### Системные отчёты (is_system: true):

| Название | Описание |
|---|---|
| Реестр договоров | Все договоры с информацией об оплатах. primary_model=EstateDeals. Flat-таблица с expression "К оплате". |
| Дебиторская задолженность | Неоплаченные плановые платежи (status=3, types_id IN [3786,3787,3788], date_to ≤ конец месяца). primary_model=Finances. Grouped (group_by по ЖК + номер объекта + контрагент) с overdue badge на колонке "Дата платежа". |
| Ежедневник поступлений | Проведённые платежи текущего месяца (status=1, types_id IN [3786,3787,3788]). primary_model=Finances. Flat-таблица, последняя колонка "Оплачено". |
| Акты сверки | primary_model=Finances, grouped по deal_id. Master: контрагент, ссылка на договор, итого оплачено / к оплате (через `aggregate_labels`). Children: дата, split paid/to_pay через expression на основе status. Ссылки на сделку и на объект. |
| Непроданные объекты | primary_model=EstateSells, flat. where: estate_sell_status IN (20, 30). Колонки: Проект, Тип объекта (link), № объекта (link, label=geo_flatnum), Стоимость публичная, Статус. Totals по estate_price. |
| Свод по проектам | primary_model=EstateSells, grouped по geo_complex_name. Aggregates: total_area, total_price, unsold_price, sold_deal_sum, sold_paid + derived aggregate_expressions: to_pay, avg_price_m2. whereHas estateHouses.geoCityComplex с geo_complex_name NOT NULL/непустой. |

#### Кастомные отчёты компаний (is_system: false, per-company seeders):

Некоторые компании получают дополнительные системные отчёты, специфичные для их продукта. Такие отчёты помечены `is_system: false` и сидируются отдельно (per-company logic в `ReportSeeder`).

| Название | Компания | Описание |
|---|---|---|
| SABA — Реестр заявок | SABA | primary_model=EstateBuys, flat. Колонки: лид (link), контакт, телефон, менеджер, статус, кастомный статус, дом, ЖК, дата создания, теги (concat_relation), UTM Source/Medium/Campaign/Term/Content. extra_filters: tags_any (has_any_pivot по тегам). Sort default: created_at DESC. |

---

### Страница: Чат (`/ai-chat`)

> Маршрут `/ai-reports` удалён 2026-05-24. Генерация отчётов (тип `report_generation`) перенесена в глобальную модалку `ReportGenerationModal`, доступную из любой страницы приложения.

Диалоговый интерфейс quick_qa. Полный API-контракт — в `chats_frontend.md`.

Async-флоу (с M4): `POST /api/chats/{id}/messages` → 202 + `stream_url` → `EventSource(stream_url)` → события в реальном времени → sentinel `done` → финальный контент.

При reload: `GET /api/chats/{id}/messages` → для каждого `status=pending/running` — переподключить SSE; для `done/error` с `events_count > 0` — опционально восстановить timeline через `/events`.

**Доступ:** superadmin, admin, analyst. Viewer не видит этот раздел.

---

### Страница: Компания (`/company`)

Две секции на одной странице:

**1. Настройки компании** (верхняя часть)
- Название компании, параметры MacroData
- Редактирование через модалку

**API:**
- `GET /api/companies/{id}` — данные текущей компании
- `PUT /api/companies/{id}` — обновить (модалка)

**2. Пользователи компании** (нижняя часть)
- Таблица: имя, email, роль
- Кнопки: создать, редактировать (модалка), удалить

**API:**
- `GET /api/users` — список пользователей (фильтруется по роли на бэке)
- `POST /api/users` — создать (модалка): name, email, password, role, company_id
- `PUT /api/users/{id}` — редактировать (модалка)
- `DELETE /api/users/{id}` — удалить

**Доступ:** superadmin, admin. Остальные не видят раздел.

---

### Страница: Документы (`/documents`, `/documents/:id`)

Раздел «Документы» — генератор PDF-коммерческих предложений (КП) и Word-шаблонов. Фаза 1 — HTML-КП флоу (M1–M4). Фаза 2 — Word (docx) флоу (M5–M6). AI-интеграция — M7 (backend) + M8 (frontend, реализована).

**Toolbox-пункт:** виден всем ролям.

**`/documents` — библиотека шаблонов:**

Трёхсекционный список: системные / опубликованные / личные. Фильтр типа (`all` / `html` / `docx`). Кнопка «+ Создать» (`CreateDocumentDialog`) — только для `canManageDocuments` (analyst+).

- `GET /api/documents` — список шаблонов активной компании (без `config`-блоба). Видимость: viewer — системные + опубликованные; analyst — + свои; admin/superadmin — + все компании.
- `POST /api/documents` — создать шаблон (analyst+). Body: `{name: LocalizedText, type: 'html'|'docx', config: object, description?: LocalizedText}`. `config` — `present|array` (может быть `{}`).
- `PUT /api/documents/{id}` — обновить (owner / admin своей компании / superadmin). 403 для системных.
- `DELETE /api/documents/{id}` — удалить (owner / admin / superadmin). 403 для системных.
- `POST /api/documents/{id}/publish` / `unpublish` — admin своей компании / superadmin.

**`/documents/:id` — HTML-КП флоу:**

- `GET /api/documents/{id}` — полный шаблон (с `config`). Ответ включает `source_path` для docx-флоу.
- `GET /api/macrodata/estate-sells/search?q=&limit=` — async-search объектов: `[{value: estate_sell_id, label: "кв.45, ЖК X, 65.40 м²"}]`. Debounce 300ms.
- `GET /api/promotions?active=1` — список активных акций компании. Фронт показывает промо-калькулятор только при `selectedPromotion !== null`.
- `POST /api/documents/{id}/preview-html` — синхронный HTML-рендер КП для `<iframe :srcdoc>` (без Gotenberg). Body: `{estate_sell_id?, promotion_id?, discount?, locale?}`. Ответ: `{html: string}`. Debounce 400ms при изменении объекта / промо / скидки / locale. ACL = read-ACL шаблона.
- `POST /api/documents/{id}/generate` — старт async-генерации. Body: `{estate_sell_id?, promotion_id?, discount?, title?}`. Ответ 202: `{generated_document_id, message}`. При выбранном промо — discount обязан попадать в `[discount_min, discount_max]`, иначе 422.
- `GET /api/documents/generated/{id}` — статус: `{status: 'pending'|'processing'|'done'|'error', pdf_path, docx_path, error}`. Polling 1500ms до `done`|`error` или timeout 120s.
- `GET /api/documents/generated/{id}/download?format=pdf|docx` — blob-скачивание. `responseType: 'blob'` + `utils/fileDownload.downloadBlob()`. `format=docx` возвращает 409 если `docx_path` отсутствует (html-тип генерации).

Кнопка-шестерёнка (gear) → `/company?tab=promotions` — только для `canManagePromotions`.

**`/documents/:id` — Word (docx) флоу (M5–M6):**

Видно только при `template.type === 'docx'`. Загрузка/редактирование шаблона — только для `canManageDocuments && !isSystem` (analyst+ / non-viewer, не системный). Viewer без source-файла видит заглушку «нет шаблона».

- `POST /api/documents/{id}/source-file` — загрузить `.docx` (multipart, поле `file`). Write-ACL = update-ACL (owner / admin / superadmin). 403 для системных. 422 для html-типа и нон-docx файлов. Ответ: `{message, source_path}`.
- `GET /api/documents/{id}/placeholders` — список `${...}` токенов из загруженного `.docx`. 422 если source_path не задан. Ответ: `{placeholders: string[]}`.
- `GET /api/documents/field-catalog` — справочник подставляемых полей, 3 группы: `object` / `branding` / `discount`. Только auth + company.access (viewer включён). Ответ: `{groups: {object: [{key, label:{ru,en}, group}], branding: [...], discount: [...]}}`. Source of truth — `config/documents.php['field_catalog']`. Маршрут зарегистрирован **до** `apiResource('documents')` во избежание коллизии.

**Маппинг плейсхолдеров (`config.field_mapping`):**

- Хранится в `document_templates.config['field_mapping']` — объект `{token: catalogKey}`.
- Сохраняется через `PUT /api/documents/{id}` с телом `{config: {...existingConfig, field_mapping: {...}}}`.
- Авто-маппинг при загрузке: токены, точно совпадающие с ключом из каталога, маппятся автоматически. `req_*` — wildcard, исключён из авто-маппинга и select-опций.
- При генерации (`GenerateDocumentJob`) маппинг читается из `config.field_mapping` и передаётся в `DocxTemplateService::fill()`.

**Токены branding в docx (M6):**

В docx branding доступен только через текстовые токены (не CSS/цвета): `${brand_header}`, `${brand_footer}`, `${req_<ключ>}` (динамически из `CompanyBranding.requisites`). Логотип/палитра в docx не подставляются.

**Настройки компании `/company` — табы Брендинг и Акции (M4):**

- `GET /api/companies/{id}/branding` — получить брендинг. Все роли (нужен для рендера КП).
- `PUT /api/companies/{id}/branding` — обновить. Body: `{colors?: {primary, secondary, accent, text, bg}, fonts?: {heading, body}, header?: LocalizedText, footer?: LocalizedText, requisites?: object}`. ACL: admin своей компании / superadmin.
- `POST /api/companies/{id}/branding/logo` — загрузить логотип. Multipart `FormData`, поле `logo`. Ответ: обновлённый `BrandingDto`. ACL: admin / superadmin. Заголовок `Content-Type` не ставить вручную — браузер сам добавит boundary.
- `GET /api/promotions` — список всех акций компании. Без `?active=1` — для CRUD-таблицы (admin).
- `POST /api/promotions` — создать. Body: `{name: LocalizedText, discount_type: 'percent'|'absolute', discount_min: number, discount_max: number, is_active?: bool, description?: LocalizedText}`.
- `PUT /api/promotions/{id}` — обновить. Все поля `sometimes`.
- `DELETE /api/promotions/{id}` — удалить. admin своей компании / superadmin.

**MacroData lookup (дополнительные endpoints, M2):**

- `GET /api/macrodata/estate-sells/{id}` — детальный field-map объекта. Ответ: `{data: {estate_sell_id, geo_flatnum, estate_area, ...}, label: string}`. 404 если не найден, 503 если MacroData недоступна.
- `GET /api/macrodata/schema?model=EstateDeals` — schema Eloquent-модели из whitelist. Ответ: `{model, table, fields: [{name, type}]}`. 422 если модель вне whitelist.

**AI-интеграция в Documents (M8):**

Два AI-сценария реализованы на фронте:

**Сценарий 1 — Генерация HTML-КП через `DocumentGenerationModal`:**
- Глобальная модалка (зеркало `ReportGenerationModal`), монтируется в `DefaultLayout`.
- Вызывается через store `documentGenerationModal.open({prefillPrompt?})` из `CreateDocumentDialog` (кнопка «Сгенерировать через AI»).
- Lazy-create чата: первый send → `POST /api/chats/messages` с `type='document_template'`, `scope_type='general'`.
- После settle: модалка показывает кнопку «Открыть шаблон» → `router.push('/documents/{createdDocumentId}')`.
- Composable: `composables/useDocumentGenerationModalChat.ts`.

**Сценарий 2 — AI авто-расстановка полей docx (`DocumentPage`, кнопка «AI: расставить поля»):**
- Видна только при `canManageDocuments && isDocx && !isSystem && source_path существует`.
- Отправляет фиксированный инструкционный запрос через `POST /api/chats/messages` с `type='document_template'`, `scope_type='document'`, `document_id`.
- Подписывается на SSE-поток — ждёт событие `document_fields_proposed`.
- Рендер карточек маппинга: `DocumentFieldsProposedPanel.vue` + `DocumentFieldProposalCard.vue` (зеркало `WidgetVariantsPanel` / `WidgetVariantCard`).
- Payload: `{placeholders: [{token, suggested_field, model, source: 'catalog'|'macrodata', confidence: 0..1|null}]}`.
- Принятие предложений → merge в `mappingDraft` → `PUT /api/documents/{id}` с `config.field_mapping`.
- Composable: `composables/useDocumentFieldsProposal.ts`.

**Document mini-chat scope:**
- `stores/documentContext.ts` — snapshot текущей страницы документа (`documentId`, `type`, `title`, `placeholderCount`, `mappedCount`). Пишет `DocumentPage`, читает `MiniChatWidget`.
- `composables/useMiniChat.ts` — ветка `scope_type='document'` (resume + sendInline с `document_id`).

**Action-marker `redirect_to_document_generation`:**
- Зарегистрирован в `useChatActionMarker.ts` (→ открывает `DocumentGenerationModal`).
- **Статус: dormant** — backend (M7) ещё не эмитит этот маркер.

**Доступ (Documents):**

| Действие | viewer | analyst | admin | superadmin |
|---|---|---|---|---|
| Просматривать / генерировать / скачивать из системных/опубликованных | ✅ | ✅ | ✅ | ✅ |
| Применить скидку промо | ✅ | ✅ | ✅ | ✅ |
| Создать / редактировать свой шаблон | ❌ | ✅ | ✅ | ✅ |
| Загрузить docx-шаблон / редактировать маппинг | ❌ | ✅ (свои) | ✅ (компания) | ✅ |
| Publish / unpublish шаблона | ❌ | ❌ | ✅ (своя компания) | ✅ |
| CRUD акций | ❌ | ❌ | ✅ (своя компания) | ✅ |
| Редактировать брендинг | ❌ | ❌ | ✅ (своя компания) | ✅ |

---

## Доступ по ролям (сводная таблица)

| Элемент | superadmin | admin | analyst | viewer |
|---|---|---|---|---|
| Header: переключатель компаний | Все + CRUD | Свои из accesses | Свои из accesses | Скрыт (если одна) |
| Header: профиль | ✅ | ✅ | ✅ | ✅ |
| Sidebar: Отчёты | ✅ | ✅ | ✅ | ✅ |
| Sidebar: Чат | ✅ | ✅ | ✅ | ❌ |
| Sidebar: Компания | ✅ | ✅ | ❌ | ❌ |
| Toolbox: MiniChatWidget | ✅ | ✅ | ✅ | ❌ |
| Отчёт: Dashboard drag/resize | ✅ | ✅ | ✅ | ❌ (read-only) |

### Capabilities (`front/src/shared/auth/capabilities.ts`)

Фронт проверяет права не через роль напрямую, а через capability-функции из `shared/auth/capabilities.ts`. Актуальный список:

| Capability | Доступно ролям | Описание |
|---|---|---|
| `canManageDashboardLayout` | superadmin, admin, analyst | Drag/resize виджетов дашборда; viewer видит layout но не может менять |
| `canUseMiniChat` | superadmin, admin, analyst | Иконка мини-чата в Toolbox; viewer не видит виджет |
| `canManageDashboards` | superadmin, admin | Создание, редактирование, удаление дашбордов |
| `canManageWidgets` | superadmin, admin | Создание, редактирование, удаление виджетов |
| `canPublishDashboard` | superadmin, admin | POST /api/dashboards/{id}/publish|unpublish |
| `canPublishWidget` | superadmin, admin | POST /api/widgets/{id}/publish|unpublish |
| `canUseDashboardMiniChat` | superadmin, admin, analyst | Мини-чат типа `scope_type=dashboard` на странице дашборда |
| `canDeleteDashboard` | superadmin, admin | Удаление дашборда (системные — 403 у всех) |
| `canDeleteWidget` | superadmin, admin | Удаление виджета (системные — 403 у всех) |
| `canManageDashboardPublication` | superadmin, admin | Алиас для `canPublishDashboard`; функционально идентичны — подлежат консолидации (tech-debt) |
| `canManageDocuments` | superadmin, admin, analyst | Создание / редактирование шаблонов документов; viewer — read-only |
| `canManageDocumentPublication` | superadmin, admin | Publish / unpublish шаблона документа (аналог `canManageReportPublication`) |
| `canDeleteDocument(role, isOwner, isSystem)` | superadmin, admin; analyst (только свои) | Удаление шаблона (аналог `canDeleteReport`); системные — никому |
| `canManageBranding` | superadmin, admin | Редактирование брендинга компании; analyst/viewer — read-only |
| `canManagePromotions` | superadmin, admin | CRUD акций; analyst/viewer не видят редактор |
| `canSetDiscount` | все роли | Установить скидку в диапазоне выбранного промо при генерации КП |

Capabilities дополняются по мере появления новых ограничений. Не дублируй проверку роли вручную — всегда используй capabilities.ts.

---

## i18n — соглашения

Локализация через **vue-i18n**. Конфигурация (locale, fallback, `pluralRules`) — `front/src/plugins/i18n.ts`.

### Плюрализация (RU)

В файле `i18n.ts` зарегистрирован кастомный `pluralRules.ru` по правилам CLDR:

- `one` — 1, 21, 31 … (заканчивается на 1, кроме 11)
- `few` — 2–4, 22–24 … (заканчивается на 2–4, кроме 12–14)
- `many` — всё остальное (0, 5–20, 11–14 …)

**Требование:** все новые RU-строки с переменным числом должны быть записаны в **трёх формах** через pipe-нотацию vue-i18n:

```
"<one> | <few> | <many>"
```

Пример:

```json
{
  "seconds": "{count} секунда | {count} секунды | {count} секунд",
  "minutes": "{count} минута | {count} минуты | {count} минут"
}
```

Строки с двумя формами (как в английском: `singular | plural`) для RU **не использовать** — третья форма обязательна.

---

## Общие ошибки

| Код | Значение |
|---|---|
| 401 | Не авторизован (нет токена или невалидный) |
| 403 | Нет прав доступа |
| 422 | Ошибка валидации (неверные данные) |

**Формат ошибки 422:**
```json
{
    "message": "The email field is required.",
    "errors": {
        "email": ["The email field is required."],
        "password": ["The password field must be at least 8 characters."]
    }
}
```
