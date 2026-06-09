# Vizion — План: Dashboards и Widgets как самостоятельные сущности

> Создан 2026-05-24. Источник решений — диалог с владельцем продукта (этот разворот отменяет
> модель «дашборд = вид отчёта» из `DEVELOPMENT_PLAN_CAPITALDATA.md` п.1).
> Фактурная база по конкуренту — `COMPETITIVE_ANALYSIS.md`.
>
> **Этот документ — спецификация для исполняющей сессии.** Работа сильно пересекается с уже
> построенной «дашборд-как-вид-отчёта» и с in-flight «модалкой генерации отчёта», поэтому
> исполнять его должна сессия, у которой этот код в контексте (основное дерево `dev`).

---

## 0. Разворот (что меняется концептуально)

**Было (текущая прод-модель):** одна сущность `Report` несёт в себе И таблицу, И дашборд-вид.
Переключатель «Таблица / Дашборд» (SelectButton), `report.config.dashboard_widgets[]` как чарт-срезы
**одного** датасета отчёта, layout в `user_report_preferences` / localStorage.

**Стало (capitaldata-way):** три раздельные сущности.

- **Report** — сухая таблица. Никакой визуализации.
- **Widget** — маленькая таблица + тип чарта. Самостоятельная сущность с библиотекой
  (системный / опубликованный / персональный), генерится чатом. Переиспользуется по ссылке.
- **Dashboard** — персональный конфиг-композиция: визуальный вывод выбранных пользователем
  виджетов на одной странице. Собирается **пользователем вручную** из виджетов; AI дашборд
  целиком не генерит.

**Почему это отдаётся одной сессии:** план одновременно (а) выпиливает «дашборд-на-отчёте»,
которую соседняя сессия только что достроила (view_mode, dashboard_layout, widget_groups,
`dashboard_widgets[]`, `/dashboard-data`), и (б) зеркалит «модалку генерации отчёта»
(in-flight в той же сессии) для генерации виджетов. Это фактически один пласт работы.

---

## 1. Терминология (зафиксировать жёстко)

| Термин | Что это | Хранение |
|---|---|---|
| **Report (отчёт)** | Сухая табличная сущность. Конфиг → пагинированная таблица с фильтрами и drill-down. **Без чартов.** | `reports` (как сейчас, минус виз-функционал) |
| **Widget (виджет)** | Маленькая таблица + тип чарта. Свой запрос (обычно агрегирующий), своя visibility, генерится/правится чатом. Переиспользуемый. | **новая таблица `widgets`** |
| **Dashboard (дашборд)** | Персональная композиция: какие виджеты, где, какого размера, видимы ли. По сути конфиг визуального вывода. Собирает пользователь. | **новая таблица `dashboards`** + pivot |
| **report_generation** | Тип чата: генерация конфига **таблицы (отчёта)**. Существует. | `chats.type` |
| **widget_generation** | Тип чата: генерация конфига **виджета**. **НОВЫЙ.** (Изначально обсуждался как `dashboard_generation` — переименован: чат генерит виджет, не дашборд.) | `chats.type` |
| **quick_qa** | Тип чата: вопрос-ответ без генерации. Существует. | `chats.type` |
| **scope=dashboard** | Новый scope для мини-чата **на странице дашборда** (quick_qa, контекст = конфиги всех виджетов дашборда). **НОВЫЙ.** | `chats.scope_type` |

**Ключевое разграничение, которое надо держать в голове всей сессии:**
- Чат **генерирует виджет** (`widget_generation`, привязан к одному `widget_id`).
- Чат **отвечает на вопросы по дашборду** (`quick_qa` + `scope=dashboard`, привязан к `dashboard_id`).
- **Дашборд никто не генерит чатом** — его собирает пользователь, добавляя/убирая виджеты.

---

## 2. Принятые решения (2026-05-24)

| # | Вопрос | Решение |
|---|---|---|
| 1 | Виджет как сущность | **Отдельная таблица `widgets`** (зеркалит структуру reports: visibility, config, AI-генерация). |
| 2 | Добавление виджета в дашборд | **Ссылка-shared.** Правка виджета меняет его во всех дашбордах, где он используется. |
| 3 | Третий тип чата | **`widget_generation`** (генерация/правка конфига виджета). Привязан к `widget_id`. |
| 4 | Тоггл «Таблица/Дашборд» на отчёте | **Выпилить полностью** — и тоггл, и весь дашборд-функционал отчёта. |
| 5 | Вкладка «График» на отчёте | **Тоже убрать.** Отчёт = только сухая таблица, без визуализации вообще. |
| 6 | Видимость дашборда | **Системные** (доступны всем юзерам компании) **+ персональные** (можно добавить свой). Свой дашборд создаётся с чистого листа. |
| 7 | Видимость виджета | Библиотека как у отчётов: **системный / опубликованный / персональный.** |
| 8 | Cross-handoff из ai-chat | CTA в quick_qa открывает **модалку генерации виджета** (`widget_generation`) с предзаполненным промптом. |
| 9 | Layout дашборда | Хранится **на сервере** (дашборд — сущность), в pivot-строках. Не localStorage. |
| 10 | Мини-чат дашборда | `quick_qa` + `scope=dashboard`, контекст = конфиги всех виджетов текущего дашборда. |

---

## 3. Целевая модель данных

### 3.1 Таблица `widgets` (новая)

```
widgets
  id              bigint PK
  company_id      FK companies (cascade) — виджет принадлежит компании
  user_id         FK users (nullable) — автор; null для системных
  name            jsonb (translatable {ru,en})
  config          jsonb — табличный конфиг + презентация чарта (см. ниже)
  chart_type      varchar(16) — bar | line | pie | doughnut (можно держать внутри config)
  is_system       boolean default false
  is_published    boolean default false  — опубликован на всю компанию
  chat_message_id FK chat_messages (nullable) — сообщение, которым виджет сгенерён (как у reports)
  metadata        jsonb (nullable) — dry_run-флаги и пр., по аналогии с reports
  timestamps
```

**`widgets.config`** = это report-подобный конфиг, который даёт **агрегированный** датасет под чарт:
```jsonc
{
  "primary_model": "Deal",
  "where": [ ... ],                  // фильтры
  "group_by": { "fields": ["geo_complex_name"] },
  "aggregates": [ { "field": "deal_sum", "fn": "sum", "as": "value" } ],
  "chart": {
    "type": "bar",                   // bar | line | pie | doughnut
    "label_field": "geo_complex_name",
    "value_field": "value"
  },
  "description": { "ru": "...", "en": "..." }   // опц.
}
```
Принцип: «виджет = маленькая таблица». Данные считаются тем же `ReportDataService`
(SQL GROUP BY path), результат — небольшой агрегат, который фронт рисует чартом.

> **Анти-дубль:** `widgets` зеркалит `reports`, но НЕ копипастить машинерию. Переиспользовать
> `ConfigResolver` (`$company_var`), `ConfigNormalizer`, dry-run policy, и обобщить
> `AssertsReportReadAccess` → общий read-ACL trait (например `AssertsConfigEntityReadAccess`),
> применимый и к reports, и к widgets.

### 3.2 Таблица `dashboards` (новая)

```
dashboards
  id           bigint PK
  company_id   FK companies (cascade)
  user_id      FK users (nullable) — владелец; null для системных
  name         jsonb (translatable)
  is_system    boolean default false   — системный дашборд (виден всем юзерам компании, read-only клон при «сделать своим»)
  is_published boolean default false   — опубликован пользователем на компанию (опц., по аналогии с reports)
  timestamps
```

Видимость (решение #6): системные (всем компании) + персональные. «Добавить свой» → пустой
персональный дашборд. Системный дашборд используется как есть (read-only) либо клонируется
в персональный при попытке правки (см. открытый вопрос O3).

### 3.3 Pivot `dashboard_widget` (новая) — layout + ссылки

```
dashboard_widget
  id            bigint PK
  dashboard_id  FK dashboards (cascade)
  widget_id     FK widgets (restrict/null) — ССЫЛКА, не копия (решение #2)
  x, y, w, h    int — позиция/размер в grid-layout-plus
  sort          int
  visible       boolean default true — вкл/выкл отображение
  timestamps
  UNIQUE (dashboard_id, widget_id)   — один виджет в дашборде один раз
```

Layout живёт здесь (server-side, решение #9). Drag/resize/visible → PUT по этим строкам.

### 3.4 Расширение `chats`

```
chats.type        + 'widget_generation'   (было: report_generation, quick_qa)
chats.scope_type  + 'dashboard'            (было: general, report, mini)
chats.widget_id    FK widgets (nullable)    — привязка widget_generation-чата к виджету (как report_id у report_generation)
chats.dashboard_id FK dashboards (nullable) — привязка scope=dashboard мини-чата к дашборду
```

Композитный resume-индекс (как `chats_scope_lookup_idx`) расширить/добавить под
`(user_id, company_id, scope_type, dashboard_id, updated_at)` и под widget-генерацию.

> Если соседняя сессия делает lazy report_generation chat (видно в git status — модалка) —
> widget_generation должен лечь по тому же паттерну: lazy-create через `POST /api/chats/messages`
> с `type=widget_generation` + `widget_id`.

---

## 4. Что выпиливаем (пересекается с работой соседней сессии)

Отчёт становится сухой таблицей. Удалить/депрекейтить:

**Frontend (`ReportPage`):**
- SelectButton «Таблица / Дашборд».
- Вкладку «График» (`<Tabs>` → остаётся только таблица). `useReportPresentation` chartConfig/chartOptions — удалить.
- Рендер дашборд-вида (`ReportDashboardView` / dashboard widgets grid на отчёте).
- Использование `useReportViewMode`, `useDashboardLayout`, `useWidgetGroups` на странице отчёта.

**Backend:**
- `report.config.dashboard_widgets[]` — депрекейт (перестать читать; в системных отчётах из
  `ReportSeeder.php` убрать). `buildPublicConfigProjection` (проекция `dashboard_widgets`) — убрать.
- Endpoint `GET /api/reports/{id}/dashboard-data` (`ReportDataService::getDashboardData()`) —
  либо удалить, либо **обобщить и переиспользовать** для данных виджета (рекомендация: обобщить,
  логика «полный/агрегированный датасет с фильтрами» нужна виджету).
- `report.config.chart` — если он использовался ТОЛЬКО для вкладки «График», убрать из обработки
  и из системных отчётов. **Проверить, не используется ли где-то ещё.**

**`user_report_preferences`:**
- Поля `view_mode`, `dashboard_layout`, `hidden_widget_groups` — депрекейт (миграция-cleanup или
  оставить колонки, но перестать использовать). `column_order` / `hidden` (порядок и скрытие
  колонок таблицы) — **оставить**, это валидно для сухих таблиц.

> **Это самая чувствительная часть.** Соседняя сессия только что зафиксировала эти фичи в
> CLAUDE.md (Dashboard view, widget groups, preferences sync). После выпила — синхронизировать
> CLAUDE.md / FRONTEND.md через product-manager (удалить устаревшие записи).

---

## 5. Backend — план

### 5.1 Миграции
1. `widgets` (см. 3.1).
2. `dashboards` (см. 3.2).
3. `dashboard_widget` pivot (см. 3.3).
4. `chats`: добавить `widget_id`, `dashboard_id`, расширить enum/строки `type` и `scope_type`,
   индексы.
5. (опц.) cleanup-миграция `user_report_preferences`.

### 5.2 Модели
- `Widget` — translatable `name`, casts `config`/`metadata` jsonb, связи `company()`, `user()`,
  `chatMessage()`, `dashboards()` (belongsToMany через pivot).
- `Dashboard` — translatable `name`, связи `company()`, `user()`,
  `widgets()` (belongsToMany через pivot с pivot-полями x/y/w/h/sort/visible).
- `Report` — убрать поддержку `dashboard_widgets`/`chart` (см. §4).
- `Chat` — добавить `widget()`, `dashboard()`.

### 5.3 Контроллеры / endpoints
**Widgets** (зеркало ReportController, kind=widget):
```
GET    /api/widgets                 — библиотека (system + published + personal), фильтр по visibility/роли
GET    /api/widgets/{id}            — show (read-ACL)
POST   /api/widgets                 — create (обычно создаётся через widget_generation chat tool)
PUT    /api/widgets/{id}            — update
DELETE /api/widgets/{id}            — delete (ACL как у reports)
POST   /api/widgets/{id}/publish | /unpublish
GET    /api/widgets/{id}/data?filters=...   — агрегированные данные под чарт (через ReportDataService)
```

**Dashboards:**
```
GET    /api/dashboards              — список (system company-wide + personal)
POST   /api/dashboards              — create (пустой персональный)
GET    /api/dashboards/{id}         — show (метаданные + список pivot-виджетов с layout)
PUT    /api/dashboards/{id}         — rename / прочие метаданные
DELETE /api/dashboards/{id}
POST   /api/dashboards/{id}/widgets         — добавить виджет (ссылка) {widget_id, x,y,w,h}
DELETE /api/dashboards/{id}/widgets/{wid}   — убрать виджет из дашборда
PUT    /api/dashboards/{id}/layout          — batch-сохранение позиций/размеров/visible
GET    /api/dashboards/{id}/data            — данные всех видимых виджетов (batch; см. 5.4)
POST   /api/dashboards/{id}/clone           — клон системного → персональный (если выбран вариант клонирования)
```

ACL: системные дашборды/виджеты видны всем компании; персональные — только автору;
опубликованные — всей компании; superadmin — любой компании; analyst — свои + published + system;
viewer — read-only. Обобщить существующий `AssertsReportReadAccess`.

### 5.4 Данные виджетов
- Per-widget: `GET /api/widgets/{id}/data` — `ReportDataService` считает агрегат (SQL GROUP BY path),
  возвращает chart-ready форму `{labels, datasets}` либо плоский агрегат (фронт строит чарт).
- Dashboard batch: `GET /api/dashboards/{id}/data` — паттерн capitaldata `?widgets=csv` (см.
  `COMPETITIVE_ANALYSIS.md` §3.2): один запрос → данные всех видимых виджетов, key per widget_id.
  **Рекомендация:** начать с per-widget endpoint (проще, кэшируется per widget), batch — оптимизация
  следующим шагом. Не блокирует MVP.
- Фильтры дашборда (период и пр.) — открытый вопрос O4.

---

## 6. AI-слой — план

### 6.1 Тип `widget_generation`
- Каскад в `config/ai.php`: новый профиль `widget_generation` (с tools, по образцу
  `report_generation`).
- Инструмент `WidgetTool` (по образцу `ReportTool`): `probe_data` → `create_widget` /
  `update_widget`. Конфиг виджета = агрегирующий запрос + `chart`. Та же dry-run policy
  (после save — `getData(... 1,1)`, флаг `metadata.dry_run_failed`, семантический retry).
- System prompt: либо отдельный `WIDGETS_GUIDE.md`, либо секция в `REPORTS_GUIDE.md` про widget-конфиг
  (агрегаты + chart presentation). Меньше отчётного, акцент на «маленькая таблица под один чарт».
- Привязка чата: `widget_generation` chat ↔ `widget_id` (как report_generation ↔ report_id).
  Lazy-create по паттерну соседней сессии (`POST /api/chats/messages`).

### 6.2 scope=dashboard мини-чат (quick_qa)
- Контекст-инжект: при первом сообщении на странице дашборда — prefix с конфигами **всех видимых
  виджетов** дашборда (по образцу report-context инжекта мини-чата отчёта, кап ~2KB → slim-fallback).
- Это НЕ генерация — отвечает на вопросы по уже собранным виджетам. Тип `quick_qa`.

### 6.3 Cross-handoff (решение #8)
- В quick_qa AI может предложить action-marker «создать виджет» → CTA-кнопка открывает
  **модалку генерации виджета** (`widget_generation`) с предзаполненным промптом. Симметрично
  существующему «создать отчёт». Виджет после генерации пользователь добавляет на дашборд сам.

---

## 7. Frontend — план

### 7.1 Новая страница `/dashboards` (зеркало `/reports` ReportsPage)
- Список карточек дашбордов: системные (company-wide) + персональные.
- Кнопка «+ Новый дашборд» → создаёт пустой персональный, открывает его.
- Карточка системного дашборда → открыть; правка → клон в персональный (O3).

### 7.2 Страница дашборда `/dashboards/:id` (зеркало ReportPage по структуре)
- Grid из виджет-карточек через **`grid-layout-plus`** (уже в зависимостях). Drag/resize.
  Layout persist на сервер (`PUT /api/dashboards/{id}/layout`, debounce).
- Вкл/выкл видимости виджета.
- Виджет-карточка: заголовок + чарт (Chart.js через PrimeVue `<Chart>`) + меню «три точки»:
  - **Редактировать** → модалка `widget_generation` chat, привязанная к этому виджету
    (правка shared — меняет виджет везде; предупредить пользователя).
  - **Удалить** → убрать ссылку из дашборда (DELETE pivot; саму сущность widget не трогаем,
    если используется в других дашбордах).
- Кнопка **«+ Добавить виджет»** → модалка библиотеки виджетов (7.3).
- Мини-чат (Toolbox) на этой странице: scope=dashboard, контекст всех виджетов (через новый
  Pinia store `useDashboardContextStore`, по образцу `useReportContextStore`).

### 7.3 Модалка «Библиотека виджетов» (add widget)
- Collapse-секции (взять логику со страницы отчётов): **Системные / Опубликованные / Персональные**.
- Клик по виджету в библиотеке → добавить ссылку на дашборд (`POST /api/dashboards/{id}/widgets`).
- Внизу «**+**» (создать виджет) → открывает чат `widget_generation` для генерации нового виджета;
  после успешной генерации — предложить сразу добавить на дашборд.

### 7.4 Выпил с `ReportPage` (см. §4)
- Убрать тоггл, вкладку «График», дашборд-вид, dashboard-preferences. Отчёт = одна таблица.

### 7.5 Навигация
- В Toolbox/сайдбаре добавить раздел «Дашборды» рядом с «Отчёты» (capitaldata-layout:
  DASHBOARDS / REPORTS два раздела).

### 7.6 Capabilities / RBAC
- `canManageDashboards`, `canManageWidgets`, `canPublishWidget`/`canPublishDashboard` —
  по образцу report-capabilities. viewer — read-only (видит системные дашборды, не правит).

---

## 8. Совместимость (железное правило)
- 6 системных отчётов из `ReportSeeder.php` после выпила виз-функционала остаются рабочими
  **как таблицы**. Из их конфигов убрать `dashboard_widgets`/`chart` (если есть) — это снятие
  полей, не поломка данных.
- Новые таблицы аддитивны; на отсутствие виджетов/дашбордов фронт показывает empty-state.
- Системные дашборды/виджеты — отдельным сидером (например `DashboardSeeder` / `WidgetSeeder`),
  upsert-стиль, не трогает клиентские данные.

---

## 9. Координация с соседней сессией (важно)
- Соседняя сессия сейчас держит в контексте: модалку генерации отчёта (`ReportGenerationModal.vue`,
  `useReportGenerationModalChat.ts`, `reportGenerationModal.ts`), lazy report_generation chat,
  и только что построенную дашборд-на-отчёте.
- **Этот план переиспользует её модалку как образец** для модалки генерации виджета и
  **выпиливает** её дашборд-на-отчёте. Поэтому исполнять должна она же (один контекст), иначе
  будут конфликты по `ReportController`, `chats`, `ReportPage`, preferences.
- Эта (параллельная) сессия в worktree `dev-parallel-1` кода НЕ трогает — только этот план-док.
  Мерджа от неё не будет; план передаётся содержимым.

---

## 10. Порядок фаз и агенты
1. **Миграции + модели** (`backend-specialist`): widgets, dashboards, pivot, chats-расширение.
2. **Выпил виз-функционала с отчёта** (`backend-specialist` + `frontend-specialist`): §4.
   Сделать рано, чтобы убрать конфликтующую модель до строительства новой.
3. **Backend widgets/dashboards CRUD + ACL + data endpoints** (`backend-specialist`,
   data — `macrodata-engineer` для `ReportDataService`-обобщения).
4. **AI `widget_generation`** (`chat-ai-engineer`): WidgetTool, каскад, system prompt, scope=dashboard.
5. **Frontend** (`frontend-specialist`): страницы `/dashboards`, `/dashboards/:id`, библиотека-модалка,
   модалка генерации виджета, мини-чат scope=dashboard, выпил с ReportPage.
6. **Сидеры** (`report-author` / `backend-specialist`): системные виджеты + системные дашборды.
7. **qa-tester** → **product-manager** (sync CLAUDE.md/FRONTEND.md/chats_frontend.md, удалить
   устаревшие записи про дашборд-на-отчёте).

---

## 11. Открытые вопросы (подтвердить перед стартом)
- **O1.** Видимость дашбордов: только system + personal, или ещё и «опубликованный пользователем»
  (как у отчётов)? В §3.2 заложено опционально `is_published`. — *уточнить.*
- **O2.** Правка shared-виджета меняет его во всех дашбордах. Нужен ли явный варнинг
  «виджет используется в N дашбордах»? — *UX-деталь, не блокирует.*
- **O3.** Системный дашборд: при попытке правки клонировать в персональный, или системные
  read-only без клона? — *уточнить (в §3.2/§7.1 заложен клон).*
- **O4.** Фильтр периода/общие фильтры на уровне дашборда (как `?period=YYYY-MM` у capitaldata),
  или каждый виджет несёт свои `where`? — *уточнить; влияет на data-endpoint.*
- **O5.** `chart_type` хранить отдельной колонкой `widgets.chart_type` или только внутри
  `config.chart.type`? — *рекомендация: только в config (одно место правды), колонку не заводить.*
