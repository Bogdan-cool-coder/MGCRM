# MACRO Global CRM — Полный аудит системы (2026-06-24)

> Сквозной аудит Laravel 13 API (`src/`) + Vue 3.5 SPA (`front/`) по 17 доменам.
> Три фазы: структурное картирование → адверсариальная верификация blocker+major c live-probe → живой браузерный прогон на `localhost:5173`.
> **Источник истины по серьёзности — пост-верификационный (`finalSeverity`)**, не Phase-1-догадка.
> Теги проверки: ✅ подтверждено (static/probe) · ⚠️ частично · ❌ опровергнуто · 🌐 подтверждено в браузере (live-QA) · «не верифицировано (Phase-1)» для minor/trivial.

---

## 1. Резюме для занятого читателя

**Общая зрелость: каркас с одним зрелым ядром.** Архитектура (FormRequest → тонкий Controller → Domain Service → Model → API Resource, деньги в копейках, DDD-границы) выдержана образцово — особенно в Продажах. Но **система не готова к продакшену из-за класса критических дыр видимости (PII-утечки), мёртвого ядра генерации договоров и недопрогнанных сквозных петель (онбординг, интейк, автоматизации).** Бэкенды богаче фронтов: множество доменов имеют полный API и пустой/частичный UI.

### Главные цифры

| Метрика | Значение |
|---|---|
| Доменов в аудите | **17** |
| Процессов (узлов) всего | **159** |
| — ✅ работает | **37** (23%) |
| — 🟡 частично | **54** (34%) |
| — 🔴 сломан | **45** (28%) |
| — ⚪ отсутствует | **13** (8%) |
| — не верифицировано | **10** (6%) |
| 🔴 **Blocker** (FINAL) | **17** |
| 🟠 **Major** (FINAL) | **65** |
| 🟡 **Minor** | **119** |
| ⚪ **Trivial** | **29** |
| **Итого проблем** | **230** |

> Blocker+major (82 шт.) верифицированы независимо (static + live-probe + браузер). Minor/trivial (148 шт.) перенесены из Phase-1 **без независимой верификации**.

### 5–7 самых опасных вещей (закрыть первыми)

1. **Системная PII-утечка через list/export.** Списки контактов, компаний и документов **не скоупятся по владельцу** — любой `manager`/`accountant`/`cfo`/`lawyer` видит и выгружает всю клиентскую базу с телефонами/почтами/реквизитами. 🌐 подтверждено живьём: `manager1` (владеет 0 компаний) видит все 13 + 3 контакта с телефонами. Это **один архитектурный пробел** (`ResolveVisibility` — M0-заглушка, scope не применяется), а не 5 отдельных багов. → **CRM-1, CRM-2, CRM-3, DOC-1**.
2. **IDOR на дочерних ресурсах документов.** `DocumentItem`/`DocumentRemark` update/destroy/resolve **не проверяют принадлежность ребёнка** `{document}` — авторизуется только родитель → кросс-документная мутация/удаление. → **DOC-2**.
3. **Нет throttle/lockout на боевом входе.** `POST /api/login` и `/2fa/validate` — неограниченный brute-force кред и TOTP. ✅ подтверждено: 8 неверных паролей → все 422, ни одного 429. → **IAM-2**.
4. **Финансовая некорректность: `discount_percent` не сворачивается в `deals.amount`.** Каждый денежный агрегат (board/list/KPI/company/export/FE-карты) **завышает выручку** для сделок со скидкой. ✅ live: 2 из 13 сделок затронуты. + фильтр бюджета шлёт рубли как копейки (под-фильтрует 100×) + float-курсы FX. → **SALES-deals#1**.
5. **Ядро генерации договоров мертво в проде.** `template_versions = 0`, `current_version_id = NULL` для всех 6 шаблонов → любая генерация → 422 «Шаблон не загружен». Весь каскад (items/approvals/numbering/attachments/won-gate) недостижим. → **DOC-root, TPL-1**.
6. **`Route [login] not defined` 500 для API-гостей.** `auth`-middleware редиректит на несуществующий named route → полный Laravel stack-trace (information disclosure) вместо `401 {"message":"Unauthenticated."}`. 🌐 подтверждено. → **NEW-4**.
7. **Сломанная петля онбординга студента.** Плеер урока рендерит пусто (`content` не отдаётся в `AssignmentDetailResource`), правка вопроса квиза → 404, AI-черновики идут студенту в score без HR-ревью. 🌐 подтверждено. → **ONB-1/2/3**.

> Дополнительно класс «дыр доступа без утечки данных, но с неверным статусом»: `/api/me/*` (кабинет менеджера) и `/api/admin/*` (справочники) **без role-гейта** — `lawyer`/`manager` получают 200 вместо 403. → **KPI-1, CRM-5/NEW-5**.

---

## 2. Методология и доверие к выводам

### Три фазы

1. **Структурное картирование 17 доменов.** По каждому: entities, endpoints, uiFlows, processes, issues, vaultUpdates, dataModelDiff, rbac. Сверка кода с живой схемой (`schema.sql`) и наполнением (`rowcounts.txt`).
2. **Адверсариальная верификация blocker + major.** Каждый blocker/major получил отдельный вердикт (`confirmed | refuted | partly | needs-browser`) с пост-верификационной `finalSeverity`. Где возможно — live-probe (HTTP-запросы к dev API, SQL-замеры, grep-доказательства).
3. **Живой браузерный прогон** (Chrome MCP, `localhost:5173`) под тремя ролями (`manager1`, `admin`, `lawyer`): 12 journey, сетевой/консольный перехват, 13 скриншотов. Surface-нул 9 новых проблем (NEW-1..9).

### Честно о доверии

- **0 опровержений на этапе адверсариальной верификации.** Каждый заявленный blocker/major подтвердился (полностью или сузился до `partly`). Это **не** «всё точно» — это значит, что Phase-1 был консервативен и не нашёл ложных тревог; остаточная неопределённость в том, что верификация била по самым опасным гипотезам, а не по всему.
- **Единственное опровержение пришло из живого QA:** `sales-dashboard#1` («дашборд рендерится пустым») — ❌ **опровергнуто**. Дашборд **НЕ пустой**: он корректно рендерит структуру воронки и показывает 0 сделок, потому что у менеджера их нет в выбранной воронке. Это поведение по дизайну, не баг.
- **Minor/trivial (≈148 шт.) НЕ верифицированы независимо** — перенесены из Phase-1 json как есть, тег «не верифицировано (Phase-1)». Их следует перепроверять перед фиксом.
- **«Пусто» ≠ «сломано».** Домены **salespulse / automation / inbox / migration** показывают 0 строк в своих таблицах не из-за поломки, а потому что **конфиг выключен или фича не прогонялась в dev**:
  - `salespulse` — `SALESPULSE_TEAMS_JSON` не задан → `teams=[]` → 12 cron-джоб no-op (в проде по памяти LIVE).
  - `automation` — 0 правил создано (движок построен end-to-end, ни разу не запущен).
  - `inbox` — `inbound_messages=0`, фронт интейка отсутствует целиком.
  - `migration` — `external_refs=0`, реального прогона ETL не было.
  Эти домены помечены как «каркас», а не «сломан», там где код зрелый.

---

## 3. Карта системы по доменам

> Зрелость — пост-верификационная. Колонка проблем — FINAL severity (🔴blocker / 🟠major / 🟡minor).

| Домен | Зрелость | 🔴 / 🟠 / 🟡 | Ключевой блокер / дефект | Файл |
|---|---|---|---|---|
| **IAM + Org** | частично | 1 / 6 / 8 | Нет throttle/lockout на `/login`+`/2fa/validate` (brute-force) | [iam.md](domains/iam.md) |
| **CRM — Контакты** | частично | 2 / 5 / 6 | Список+экспорт контактов отдают все PII любой роли | [crm-contacts.md](domains/crm-contacts.md) |
| **CRM — Компании** | частично | 3 / 3 / 6 | Список+экспорт компаний утекают всю базу; merge сиротит связи | [crm-companies.md](domains/crm-companies.md) |
| **Каталог** | частично | 2 / 4 / 5 | FX-подсистема мертва; price-import «preview» пишет в БД | [catalog.md](domains/catalog.md) |
| **Продажи — Сделки/Kanban** | частично (зрелейший) | 1 / 5 / 7 | `discount_percent` игнорится в `amount` — все агрегаты завышены | [sales-deals.md](domains/sales-deals.md) |
| **Продажи — KPI** | частично | 0 / 2 / 9 | Нет role-гейта на `/api/me/*` (lawyer→200); сравнение с командой мертво | [sales-kpi.md](domains/sales-kpi.md) |
| **Продажи — Дашборд** | частично | 1 / 3 / 10 | Export xlsx → `window.open` без Bearer → 500 | [sales-dashboard.md](domains/sales-dashboard.md) |
| **Активности/Задачи** | частично | 0 / 4 / 7 | FTM нельзя записать; report не штампует engagement/log | [activity.md](domains/activity.md) |
| **Inbox/Интейк** | каркас | 0 / 2 / 4 | Публичная страница лид-формы отсутствует; route() не атомарен | [inbox.md](domains/inbox.md) |
| **Договоры — Шаблоны** | каркас | 1 / 8 / 5 | Генерация мертва (template_versions=0); нет UI лицензиаров | [contracts-templates.md](domains/contracts-templates.md) |
| **Договоры — Документы** | каркас | 3 / 10 / 7 | template_versions=0 (корень); IDOR; кросс-юзер утечка списка | [contracts-documents.md](domains/contracts-documents.md) |
| **Онбординг** | каркас/частично | 3 / 3 / 10 | Плеер урока пуст (content не отдаётся); правка квиза 404; AI без ревью | [onboarding.md](domains/onboarding.md) |
| **Автоматизации** | каркас | 0 / 3 / 12 | Ретеншн-прун освобождает слоты идемпотентности → дубль-фаер | [automation.md](domains/automation.md) |
| **Уведомления** | частично | 0 / 2 / 6 | Telegram-link бьётся на контракте; колокольчик только в «Орбите» | [notification.md](domains/notification.md) |
| **SalesPulse** | частично (config-off в dev) | 0 / 1 / 6 | `vacation()` игнорит kind → дни застревают как skip | [salespulse.md](domains/salespulse.md) |
| **Миграция AMO ETL** | каркас (dormant) | 0 / 2 / 4 | `amo_product_mappings`/`migration_maps` не читаются ETL | [migration.md](domains/migration.md) |
| **Сквозное — Лог + оболочка** | частично | 0 / 2 / 11 | EntityLogTab читает неверные поля → каждая строка «Система» | [log-shell.md](domains/log-shell.md) |

---

## 4. Сквозные системные проблемы (паттерны)

> Это закономерности, а не отдельные баги. Один фикс на паттерн закрывает кластер issue.

### (а) Системная дыра видимости / scope — архитектурный пробел №1

**Масштаб:** контакты, компании, документы, справочники `/api/admin/*`, кабинет менеджера `/api/me/*`. Это **не 5 багов — это один пробел**: `ResolveVisibility` — M0-заглушка, которая штампует `visibility_scope`, но **никто его не читает**; `*Service::list` и `*ExportService` многих доменов не применяют owner/visibility-scope, а `viewAny()` в политиках возвращает `true`. Эталон уже есть — `DealService::scopedQuery` + `VisibilityScope::forRole()` скоупятся корректно (live: `manager`→чужая сделка = 403). Остальные домены просто его не используют.

- **list/export-утечки PII:** CRM-1, CRM-2, CRM-3, DOC-1 (4 blocker одной природы).
- **read без role-гейта (статус неверный, утечки BI):** CRM-5/NEW-5 (`/api/admin/*`), KPI-1 (`/api/me/*`).
- **усугубляет:** docblock `CompanyPolicy` и vault-спека **врут**, что список фильтруется по видимости — ложная документация маскировала дыру.

**Рекомендация:** единый трейт/global scope `BelongsToVisibility` на `Contact`/`Company`/`Document` (по образцу `DealService`), плюс `authorize('viewAny')` с реальным role-гейтом на index/show справочников и `/api/me/*`. Снести `ResolveVisibility`-заглушку или довести до рабочего состояния. **Это P0 — закрывается одним вертикальным срезом за ~неделю.**

### (б) Деньги — три независимых дефекта, все занижают доверие к цифрам

**Масштаб:** все денежные агрегаты системы.

- **`discount_percent` не сворачивается в `deals.amount`** (SALES-deals#1, blocker ✅ live) — board/list/KPI/company/export/FE-карты завышают выручку. 2 из 13 живых сделок затронуты.
- **Фильтр бюджета шлёт рубли как копейки** (sales-deals major ✅) — под-фильтрует 100×.
- **Toolbar-итог суммирует разные валюты** и хардкодит «₽/млн/тыс.» (sales-deals major ✅).
- **FX-конвертация на float** + сама FX-подсистема мертва (catalog blocker ✅): курсы не наполняются, `convert` всегда 422.
- **`score_pct` KPI игнорирует валюту плана** (USD/EUR планы считаются неверно, minor).

**Рекомендация:** ввести единый `DealAmountCalculator` (line-sum − deal-discount, в копейках), переиспользовать его во всех агрегаторах; фильтр бюджета умножать на 100 на BE-границе; итоги группировать по валюте; FX перевести на целочисленную арифметику и оживить наполнение курсов. **P1.**

### (в) Массовый FE↔BE контрактный дрейф (имена полей / параметров / shape ответа)

**Масштаб:** ~15+ подтверждённых рассинхронов в 8 доменах — самый частый класс багов.

- Telegram-link: FE читает `res.link_url`, API отдаёт `{deeplink}` (IAM + notification, major ✅).
- EntityLogTab/MiniTimeline читают неверные поля → каждая строка «Система» (log-shell, major ✅ live).
- ChannelHistoryDrawer читает неверные поля → каналы всегда пусто (log-shell, major ✅ live).
- TemplatesPage kind-фильтр шлёт `DocumentKind`, таблица хранит `docx/yaml/text` → 0 строк (contracts, major).
- TemplateVariablesPage фильтры (active/type/search) no-op из-за рассинхрона имён (contracts, major).
- Dashboard «open list» — params игнорируются + `only_no_task` vs `no_tasks` (dashboard, major).
- Onboarding AI-спиннер поллит `ai_generation_status`, которого resource не отдаёт + `done` vs `completed` (onboarding, major).
- Automation `set_field` FE-whitelist `[notes,title]` ≠ BE `[title,tags]` (automation, major).
- Generate-from-deal/company: shape mismatch — `doc.id` undefined после генерации (documents, blocker).

**Рекомендация:** это симптом отсутствия общего слоя контрактов. Минимум — генерировать TS-типы из API Resources (или зафиксировать shape в общих DTO) и добавить контрактные тесты на ключевые ресурсы. Без этого дрейф будет воспроизводиться при каждой правке.

### (г) Мёртвые слои конфигурации (built, never wired)

**Масштаб:** функционал написан, surfaced в UI/БД, но **никем не читается в runtime**.

- **Видимость воронок/стадий** (`visible_role`/`visible_user_ids`/`visible_department_ids`) хранится и кастится — никем не читается (SALES-1, major ✅).
- **Валидация custom-fields** мертва на company-пути; `extra_fields` — free-form mass-assignment (crm-companies, major).
- **Модель мотивации** (`commission_rules`/`team_targets`) спит до M10 (sales-kpi, by design).
- **spatie permissions** (19 прав / 53 гранта) не читаются нигде (IAM-1, см. паттерн (е)).
- **`amo_product_mappings`** (94 строки) / **`migration_maps`** не читаются ETL (migration, major).
- **LicensorService::forCountry()/primaryAccountForCurrency()** мертвы; per-currency банк-счёт не подключён → USD-договор рендерит KZT-счёт (contracts, major).
- **`VisibilityScope::Department`** недостижим (`forRole` не возвращает) — все department-ветки политик мёртвые.

**Рекомендация:** по каждому слою — решение «подключить или удалить». Мёртвая access-config (SALES-1, spatie) особенно опасна: создаёт ложное ощущение защиты. Либо wire-up в сервисах, либо вычистить из БД/UI и из спеки.

### (д) Контур генерации договоров мёртв (нет template_versions)

**Масштаб:** весь домен Договоров (Шаблоны + Документы).

Корень — `template_versions = 0`, `current_version_id = NULL` для всех 6 шаблонов: ни один docx не загружен. → любая генерация 422 → весь каскад (items/approvals/numbering/attachments/won-gate) пуст и недостижим. Поверх лежат: отсутствие UI лицензиаров (8 endpoint без фронта), `termination_agreement` отсутствует в live DB (сидер=7, live=6), фейк-approved демо-документы, обходящие won-gate, double-increment `attempt`.

**Рекомендация:** это **не код-баг, а пустой операционный слой** — нужно загрузить реальные docx-версии шаблонов и сделать smoke-генерацию end-to-end. Только после этого фиксить каскадные дефекты (IDOR DOC-2 — независимо и срочно). **P1.**

### (е) Двойной источник роли + role-enum Gates вместо spatie

**Масштаб:** вся авторизация.

В проекте **два параллельных источника роли и два механизма authz, из которых работает только один**:
- **Реально работает:** enum `users.role` через 3 глобальных Gate (`admin-write`, `dedup-scan-all`, `system-reset`) + ~15 Policy + `VisibilityResolver(All|Own)`.
- **Мёртвый:** spatie/laravel-permission — 19 прав / 53 гранта, **0 вхождений** `permission:`-middleware / `hasPermissionTo` / `->can('x.y')` в коде. **Все роли на `guard=web`, а API на Sanctum c `sanctum.guard=[]`** → любой будущий `permission:`-middleware молча не сматчит principal (латентная мина: код «защиты» добавят, тесты пройдут на web-guard, прод вернёт always-false/true).
- Роль хранится дважды (`users.role` + `model_has_roles`); `VisibilityResolver` местами предпочитает spatie-роль, Policies — колонку → рассинхрон при будущей смене роли (сегодня не эксплуатируется, т.к. пути смены роли вообще нет).

**Рекомендация (IAM-1):** принять решение и зафиксировать в спеке: **либо** подключить spatie на Sanctum guard и заменить Gate'ы на permission-проверки, **либо** снести permission-таблицы и официально закрепить role-enum Gate RBAC. Текущее состояние — худшее из двух (мёртвый код, выглядящий как защита).

---

## 5. Глобальный бэклог (severity-ranked)

> Полный список **всех blocker и major** (FINAL severity) по системе. file:line — точка фикса. minor/trivial — сведены числом ниже.

### 🔴 BLOCKER (17)

| # | Домен | Тип | Заголовок | Файлы (file:line) | Проверка | Кратко-фикс |
|---|---|---|---|---|---|---|
| 1 | iam | SECURITY | Нет rate-limit/lockout на `/api/login` и `/2fa/validate` → brute-force | `routes/api.php:111,135-137` · `bootstrap/app.php:18-26` · `AuthService.php:44-61,93-114` | ✅ (8 паролей→422, 0×429) | Именованный `throttle:login` (email+IP, 5/min) |
| 2 | crm-contacts | SECURITY | Список контактов без owner-scope → все PII любой роли | `ContactService.php:49,149` · `ContactPolicy.php:19` · `ContactController.php:34-43` | 🌐 + ✅ probe | Visibility-scope в `ContactService::list` |
| 3 | crm-contacts | SECURITY | `export()` без `authorize()`; пустой `ids`→дамп всех PII | `ContactBulkController.php:67-82` · `ContactExportService.php:57-58` | ✅ | `authorize()` + scope; запрет пустого `ids` |
| 4 | crm-companies | SECURITY | Список+экспорт компаний без visibility-scope | `CompanyService.php:58-181` · `CompanyExportService.php:45-66` · `CompanyController.php:37-46` | 🌐 | Visibility-scope в list+export |
| 5 | crm-companies | SECURITY | Утёкший список кликабелен → manager на 403-dead-end CompanyPage | `CompanyPolicy.php:24-27` · FE CompanyPage | 🌐 | Закрытие #4 устранит источник |
| 6 | crm-companies | DATA-INCONSISTENCY | Merge сиротит deals/documents/requisites/channels/status-log/subsidiaries | `CompanyMergeService` (см. crm-companies.md) | ✅ | Транзакционный re-parent всех связей |
| 7 | catalog | BUG | FX-подсистема мертва: курсы не наполняются, `convert` всегда 422 | FX job + `ExchangeRateService` (catalog.md) | ✅ | Оживить наполнение курсов; целочисл. конверсия |
| 8 | catalog | BUG | Price-import «preview» реально пишет в БД (BE игнорит `dry_run`) | `PriceImportService` / `ImportPriceRequest` (catalog.md) | ✅ | Уважать `dry_run` — не коммитить в preview |
| 9 | sales-deals | BUG | `discount_percent` игнорится в `deals.amount` → агрегаты завышают выручку | `DealService` amount-расчёт (sales-deals.md) | ✅ live | Единый `DealAmountCalculator` (line-sum − discount) |
| 10 | sales-dashboard | BUG | Export xlsx сломан — `window.open` без Bearer → HTTP 500 `Route [login] not defined` | `DashboardController@export` + FE export composable | 🌐 + ✅ | Скачивание через axios+Bearer (blob), не `window.open` |
| 11 | contracts-templates | BUG | Генерация мертва — docx-версия не загружалась (`template_versions` пуст)→422 | `template_versions=0` (live DB) | ✅ | Загрузить docx-версии + smoke-генерация |
| 12 | contracts-documents | DATA-INCONSISTENCY | Генерация невозможна — `current_version_id=NULL` для всех 6 шаблонов (корень) | `templates` (live DB) · `DocumentService` generate | ✅ | См. #11 (тот же корень) |
| 13 | contracts-documents | SECURITY | IDOR — items.update/destroy и remarks.resolve не проверяют принадлежность к `{document}` | `DocumentItemController.php:58,70` · `DocumentRemarkController.php:66-68` · `routes/api.php:700-720` (нет `scopeBindings`) | ✅ | `scopeBindings()` + проверка `child->document_id` |
| 14 | contracts-documents | BUG | Generate-from-deal/company: shape mismatch — `doc.id` undefined после генерации | `DocumentService` generate response + FE | ✅ | Выровнять shape ответа FE↔BE |
| 15 | onboarding | BUG | Плеер урока студента рендерит пусто — `content` не отдаётся в `AssignmentDetailResource` | `AssignmentDetailResource` (onboarding.md) | 🌐 + ✅ | Отдавать `content`/blocks в студ-resource |
| 16 | onboarding | DEAD-CODE | Правка вопроса квиза 404 — FE patch/delete + option-CRUD на несуществующих путях | FE quiz routes + `routes/api.php` (onboarding.md) | ✅ (probe 404 vs 405) | Реализовать вложенные роуты или выровнять FE |
| 17 | onboarding | BUG | AI-черновики отдаются студентам и идут в score без HR-ревью (`is_draft` не фильтруется) | quiz student resolve (onboarding.md) | ✅ | Фильтр/очистка `is_draft` на студ-путях |

### 🟠 MAJOR (65)

| # | Домен | Тип | Заголовок | Файлы (file:line) | Проверка | Кратко-фикс |
|---|---|---|---|---|---|---|
| 1 | iam | SPEC-DRIFT | RBAC-дрейф: spatie слой мёртв (19/53), authz на enum-Gate; guard=web vs Sanctum [] | `AppServiceProvider.php:243-260` · `config/sanctum.php:48` · `config/auth.php:19` | ✅ (grep 0 + DB) | Решить: spatie-on-Sanctum ИЛИ снести + зафиксировать в спеке |
| 2 | iam | MISSING | User mgmt create+read only — нет edit/деактивации/удаления/смены роли/менеджера | `UserManagementController.php:26` · `routes/api.php:374` · FE `UsersPage/index.vue:66` | 🌐 | Добавить update/deactivate/role/dept endpoints + FE |
| 3 | iam | BUG | Telegram-link кнопка → about:blank — FE читает `res.link_url`, BE отдаёт `{deeplink}` | profile FE + Telegram link controller | ✅ | Выровнять имя поля (`deeplink`) |
| 4 | iam | DEAD-CODE | `profileApi.uploadAvatar` → несуществующий `/api/profile/avatar` (404), 0 callers | `profileApi` (iam.md) | ✅ | Реализовать endpoint ИЛИ убрать |
| 5 | iam | STUB | Созданный юзер получает throwaway-пароль без инвайта/доставки и без reset | `UserService::create` (iam.md) | ✅ | Инвайт/password-reset flow |
| 6 | iam | BUG | Профиль: full_name/locale/telegram отбрасываются — пишется только `nav_quick_actions` | `UpdateProfileRequest` (iam.md) | ✅ | Расширить allowlist полей профиля |
| 7 | crm-contacts | BUG | `/contacts` всегда открывается в режиме «Компании» (тождественный тернарник) | `front/.../contacts/index.vue:792` | ✅ | Исправить тернарник режима |
| 8 | crm-contacts | DATA-INCONSISTENCY | KPI-полоса (owner-scope) противоречит списку (unscoped) — total 0 над видимыми | FE KPI bar + `ContactService` | ✅ | Согласовать scope KPI↔list (закрытие #2) |
| 9 | crm-contacts | BUG | `created_by_id` не пишется при создании → сорт/фильтр по автору мертвы | `ContactService::create` (crm-contacts.md) | ✅ | Писать `created_by_id` |
| 10 | crm-contacts | SECURITY | Вложенные channels/relations не `scoped()` — IDOR + нет ролевого UI-гейтинга | nested routes (crm-contacts.md) | ✅ | `scopeBindings()` + проверка владельца |
| 11 | crm-contacts | SECURITY | (см. RBAC CRM-5) справочники `/api/admin/*` без authorize в index/show | `Crm/Admin/*Controller.php` index/show | 🌐 | `Gate admin-write` на read |
| 12 | crm-companies | SPEC-DRIFT | Docblock CompanyPolicy + спека утверждают фильтрацию списка, которой нет | `CompanyPolicy.php` docblock | ✅ | Исправить docblock + спеку (или wire scope) |
| 13 | crm-companies | DEAD-CODE | Валидация custom-fields мертва на company-пути; `extra_fields` — free-form mass-assignment | `CompanyService` extra_fields (crm-companies.md) | ✅ | Подключить custom-field validation |
| 14 | crm-companies | PERF | Dedup scan грузит почти-полные таблицы в память (orWhereNotNull + PHP-фильтр) | `CompanyDedupService` (crm-companies.md) | ✅ | SQL-side нормализация/индекс |
| 15 | catalog | SECURITY | Вложенные plan/price-роуты не скейпятся на родителя (binding leak, live 200) | nested plan/price routes (catalog.md) | ✅ | `scopeBindings()` |
| 16 | catalog | DATA-INCONSISTENCY | Unique цены игнорит окно валидности; NULL-plan базовые цены не дедуплятся | migration unique + `PriceService` | ✅ | Расширить unique-ключ окном валидности |
| 17 | catalog | BUG | Price-upsert принимает `plan_id` чужого продукта | `PriceService` upsert (catalog.md) | ✅ | Проверять plan.product_id == price.product_id |
| 18 | catalog | DEAD-CODE | Refresh-кнопка курсов → 405 и видна всем ролям | FE `ExchangeRatesPage:9-16` | ✅ | Endpoint или скрыть + `canWrite` |
| 19 | sales-deals | DEAD-CODE | Видимость воронок/стадий хранится/surfaced, но нигде не применяется | pipeline/stage модели (sales-deals.md) | ✅ | Wire-up в `DealService::scopedQuery` или удалить |
| 20 | sales-deals | BUG | Фильтры Owner и Tags рендерятся с пустыми опциями — мертвы в UI | FE deals filters (sales-deals.md) | ✅ | Наполнять опции фильтров |
| 21 | sales-deals | BUG | Фильтр бюджета шлёт рубли как копейки → под-фильтрует 100× | FE filter + `DealService` query | ✅ | ×100 на BE-границе |
| 22 | sales-deals | STUB | `deal_audits` пуст + узкий whitelist → feed пропускает поля | `deal_audits` writer (sales-deals.md) | ✅ | Расширить whitelist + писать аудит |
| 23 | sales-deals | BUG | Toolbar-итог суммирует валюты и хардкодит ₽/млн/тыс. | FE deals toolbar (sales-deals.md) | ✅ | Группировка по валюте |
| 24 | sales-kpi | DATA-INCONSISTENCY | Сравнение с командой мертво: у план-менеджеров `department_id=NULL` — solo-таблица | `users.department_id` (live) · `ManagerKpiService` | ✅ | Заполнить department_id; fallback-логика |
| 25 | sales-kpi | SECURITY | Нет role-гейта на `/api/me/*` — lawyer/любой получает 200, не 403 | `routes/api.php:402-405` · `ManagerKpiService.php:343,350` | 🌐 | Role-middleware на `/api/me/*` |
| 26 | sales-dashboard | DATA-INCONSISTENCY | Дефолтная воронка промахивается мимо живых сделок (FE pre-select неактивной) | FE pipeline pre-select · `/api/pipelines` | ✅ | active-first сортировка + pre-select активной |
| 27 | sales-dashboard | BUG | «Open list» из виджета ведёт на нефильтрованный список (`only_no_task` vs `no_tasks`) | FE widget link + `DealController` params | ✅ | Выровнять имя параметра |
| 28 | sales-dashboard | BUG | Тренд прошлого периода игнорит soft-delete/visibility/manager_id (SQL 12 vs 3) | `SalesDashboardService` trend query | ✅ | Применить тот же scope к прошлому периоду |
| 29 | activity | BUG | FTM нельзя записать через конструктор отчёта (FE не шлёт ftm_*, BE дропает) | FE report builder + `saveReport` | ✅ | Прокинуть ftm_* поля FE↔BE |
| 30 | activity | MISSING | У admin CRUD вопросов отчёта нет FE-UI (4 мёртвых endpoint; реестр seed-only) | meeting-report question admin (activity.md) | ✅ | Построить admin-UI реестра |
| 31 | activity | BUG | Inline status→done оставляет задачу открытой, пропускает engagement/log | `changeStatus` ≠ `complete` (activity.md) | ✅ | Маршрутизировать done→complete |
| 32 | activity | BUG | Сохранение отчёта о встрече пропускает engagement-штамп и entity-log | `saveReport` (activity.md) | ✅ | Штамповать engagement + писать log |
| 33 | inbox | DEAD-CODE | Публичная страница лид-формы отсутствует — `forms/public/{slug}` без UI | FE (нет страницы) · `FormController@public` | ✅ | Построить публичную форму |
| 34 | inbox | BUG | INSERT + route() не атомарны, route() без try/catch → orphaned NULL + 500 анону | `InboundRoutingService` (inbox.md) | ✅ | Транзакция + try/catch + 202 анону |
| 35 | contracts-templates | MISSING | Нет фронтенда для CRUD LicensorEntity/LicensorBankAccount (8 endpoint) | FE (нет) · Licensor controllers | ✅ | Построить admin-UI лицензиаров |
| 36 | contracts-templates | DEAD-CODE | `LicensorService::forCountry()/primaryAccountForCurrency()` мертвы → USD-договор рендерит KZT-счёт | `LicensorService` (contracts-templates.md) | ✅ | Подключить per-currency выбор счёта |
| 37 | contracts-templates | BUG | kind-фильтр TemplatesPage шлёт DocumentKind, БД хранит docx/yaml/text → 0 строк | FE TemplatesPage filter + `templates.kind` | ✅ | Выровнять словарь kind FE↔BE |
| 38 | contracts-templates | BUG | Фильтры TemplateVariablesPage (active/type/search) no-op — рассинхрон имён | FE TemplateVariablesPage + BE | ✅ | Выровнять имена параметров |
| 39 | contracts-templates | DATA-INCONSISTENCY | `termination_agreement` отсутствует в live DB (сидер=7, live=6) → генерация деградирует→422 | `templates` (live) · seeder | ✅ | Засидить недостающий шаблон |
| 40 | contracts-templates | (+3 major) | прочие major домена шаблонов | см. файл | mixed | [contracts-templates.md](domains/contracts-templates.md) |
| 41 | contracts-documents | SECURITY | `documents.index` unscoped — managers видят ВСЕ документы | `DocumentPolicy.php:16,23-26` · `DocumentController.php:32-40` · `DocumentService.php:48-88` | ✅ | Author/visibility-scope в list |
| 42 | contracts-documents | DATA-INCONSISTENCY | Фейк-approved демо-документы обходят машину состояний и won-gate | seeder demo docs (contracts-documents.md) | ✅ | Пересидить через легальные переходы |
| 43 | contracts-documents | BUG | attempt double-increment — generation и submit оба инкрементят `document_revisions.attempt` | `DocumentService` generate+submit | ✅ | Инкремент в одной точке |
| 44 | contracts-documents | (+7 major) | прочие major домена документов (FE↔BE имена, numbering, attachments) | см. файл | mixed | [contracts-documents.md](domains/contracts-documents.md) |
| 45 | onboarding | SECURITY | Студент видит неопубл./draft-уроки и неопубликованный курс — нет publish-gate | student controllers (onboarding.md) | 🌐 | `is_published`-gate на студ-путях |
| 46 | onboarding | DEAD-CODE | Спиннер AI-генерации не завершается — FE поллит `ai_generation_status`, resource не отдаёт (+ done vs completed) | FE poll + quiz resource | ✅ | Отдавать статус + выровнять значение |
| 47 | onboarding | BUG | Quiz builder молча теряет правки опций существующих вопросов (Sync: not implemented) | `QuizService` sync options | ✅ | Реализовать sync опций |
| 48 | automation | BUG | Ретеншн-прун освобождает слоты идемпотентности → дубль-фаер cron | `AutomationRun` retention + idempotency | ✅ | Не прунить idempotency-ключи |
| 49 | automation | BUG | change_owner шлёт hand-picked pool, BE читает только `user_pool_filter` → round-robin по всем | FE change_owner + `ChangeOwnerAction` | ✅ | Читать hand-picked pool на BE |
| 50 | automation | SPEC-DRIFT | set_field FE-whitelist [notes,title] ≠ BE [title,tags] — notes no-op, tags недостижим | FE set_field + `SetFieldAction` | ✅ | Согласовать whitelist |
| 51 | notification | BUG | Кнопка «Привязать Telegram» нерабочая: FE `res.link_url` vs API `{deeplink}` | FE notification settings + link controller | ✅ | Выровнять поле (дубль IAM #3) |
| 52 | notification | MISSING | In-app уведомления недостижимы в sidebar-режиме — колокольчика нет вне «Орбиты» | FE shell (sidebar nav) | 🌐 | Вынести колокольчик в sidebar shell |
| 53 | log-shell | BUG | EntityLogTab/MiniTimeline читают неверные поля — каждая строка «Система» | FE EntityLogTab/MiniTimeline + EntityLog resource | ✅ live | Выровнять имена полей actor/details |
| 54 | log-shell | BUG | ChannelHistoryDrawer читает неверные поля — каналы/редактор всегда пусто | FE ChannelHistoryDrawer + resource | ✅ live | Выровнять имена полей |
| 55 | salespulse | BUG | `vacation()` pre-check игнорит kind → дни застревают как skip, мислейбл в /progress | `SkipService.php:91-94` | ✅ | Учитывать kind в pre-check |
| 56 | migration | SPEC-DRIFT | `amo_product_mappings` (94 строки) не читается ETL — товары дропаются при импорте | ETL loader (migration.md) | ✅ | Подключить чтение mappings |
| 57 | migration | SPEC-DRIFT | `migration_maps` мертва — не пишется/не читается ни одним ETL-кодом | ETL (migration.md) | ✅ | Подключить или удалить из плана |
| 58 | cross/NEW-4 | SECURITY | `auth`-middleware → `Route [login] not defined` 500 со stack-trace вместо 401 JSON | `bootstrap/app.php` (нет `redirectGuestsTo`) | 🌐 | `redirectGuestsTo`→401 JSON для API |
| 59 | cross/NEW-1 | BUG | `CompanyChannelsBlock` не resolved (Vue warn ×3 на company page) | `front/.../CompanyPage/` | 🌐 | Импортировать/зарегистрировать компонент |
| 60 | cross/NEW-5 | SECURITY | `/api/admin/*` доступны manager-роли (7 endpoint 200) | `Crm/Admin/*Controller` index/show | 🌐 | (дубль #11) role-гейт на read |

> Строки 40 и 44 свёрнуты — полные перечни оставшихся major по доменам Договоров см. в `contracts-templates.md` (8 major) и `contracts-documents.md` (10 major). Сумма всех major по системе = **65**.

### 🟡 MINOR / ⚪ TRIVIAL — сведено числом

| Домен | minor | trivial | Файл |
|---|---|---|---|
| iam | 8 | 2 | [iam.md](domains/iam.md) |
| crm-contacts | 6 | 3 | [crm-contacts.md](domains/crm-contacts.md) |
| crm-companies | 6 | 1 | [crm-companies.md](domains/crm-companies.md) |
| catalog | 5 | 1 | [catalog.md](domains/catalog.md) |
| sales-deals | 7 | 2 | [sales-deals.md](domains/sales-deals.md) |
| sales-kpi | 9 | 7 | [sales-kpi.md](domains/sales-kpi.md) |
| sales-dashboard | 10 | 1 | [sales-dashboard.md](domains/sales-dashboard.md) |
| activity | 7 | 0 | [activity.md](domains/activity.md) |
| inbox | 4 | 1 | [inbox.md](domains/inbox.md) |
| contracts-templates | 5 | 2 | [contracts-templates.md](domains/contracts-templates.md) |
| contracts-documents | 7 | 1 | [contracts-documents.md](domains/contracts-documents.md) |
| onboarding | 10 | 1 | [onboarding.md](domains/onboarding.md) |
| automation | 12 | 0 | [automation.md](domains/automation.md) |
| notification | 6 | 2 | [notification.md](domains/notification.md) |
| salespulse | 6 | 2 | [salespulse.md](domains/salespulse.md) |
| migration | 4 | 2 | [migration.md](domains/migration.md) |
| log-shell | 11 | 1 | [log-shell.md](domains/log-shell.md) |
| **Итого** | **119** | **29** | — |

> Все minor/trivial — «не верифицировано (Phase-1)», кроме отдельно помеченных ✅/⚠️ в доменных файлах. Перепроверять перед фиксом.

---

## 6. Что работает безошибочно сегодня

Честный список зрелых, реально эксплуатируемых процессов — чтобы не сложилось впечатление, что всё плохо.

- **Аутентификация + 2FA (TOTP)** — ядро зрелое, подтверждено живьём: login, enroll, validate, Sanctum Bearer. (Единственная дыра — отсутствие throttle, не сама логика.)
- **Item-level авторизация сделок** — `manager` → чужая сделка = 403 (🌐 live). Эталон scope для всей системы.
- **Воронки и канбан сделок** — создание сделки в стадии «Новые лиды», перемещение по стадиям, карточка сделки (стадия-бар, поля, фид, продукты, контакты) открывается полностью (🌐 live deal #10).
- **Won-gate как граница безопасности** — `won_gate_contract_required` корректно возвращает 409.
- **Деньги в копейках + корректная item-level арифметика** (дефект только в сворачивании deal-level скидки).
- **Товарная часть каталога** — нагружена живыми данными (8 групп, 32 продукта, 21 план, 164 цены), слои и RBAC корректны; write гейтится (`manager`→403 live).
- **Кабинет менеджера** (`/api/me/kpi`, `/activity-feed`) — ядро `ManagerKpiService` соблюдает контракт (дефект — отсутствие role-гейта, не расчёт).
- **Дашборд продаж** — single-aggregator с корректным RBAC (visibility-scope до агрегации, `manager_id` защищён 422); рендерится с данными (опровергнут миф о «пустом дашборде»).
- **Задачная половина Активностей** — 24 живых активности, согласованные миграция/модель/DDL, тонкие контроллеры, authz на уровне FormRequest.
- **Backend-конвейер интейка** (Inbox) — 12 эндпоинтов, `InboundRoutingService` → Company+Deal в стадии new (не хватает только фронта).
- **Движок автоматизаций** — построен end-to-end (мастер/canvas/журнал), качественно написан (не запущен в проде, но не сломан).
- **Серверная часть уведомлений** (in-app + Telegram-бот) — зрелая, схема 1:1 с миграциями.
- **SalesPulse** — Slice 0–4 APPROVED, 4 таблицы 1:1 со схемой, в проде LIVE (в dev config-off).
- **AMO ETL loader** — идемпотентность через `external_refs`, копейки, бэкдейченная история (dormant, но добротный).
- **Read-авторизация журнала/ленты** — дыр нет (дефекты только в FE-чтении полей).
- **Настройки** (`/profile`: Профиль/Безопасность/Справочники/Каталог) — рендерятся (🌐 live PASS).

---

## 7. RBAC — резюме

Реальная авторизация = **enum `users.role`** через **3 глобальных Gate** (`admin-write`, `dedup-scan-all`, `system-reset`) + **~15 Policy** + **`VisibilityResolver(All|Own)`**. Slat-слой spatie (19 прав / 53 гранта) — **мёртвый код на чужом guard'е** (`web` vs Sanctum `[]`).

6 ролей: **admin** (полный + единственный system-reset), **director** (как admin кроме reset), **lawyer** (намеренно All-видимость — отсюда дыры доступа к менеджерским поверхностям), **manager/accountant/cfo** (Own-видимость; заявленные «финправа» accountant/cfo **в коде не реализованы** — это manager-эквивалент).

Item-level доступ корректен; провал — на **list/export-путях** (не скоупятся) и на **read без role-гейта** (`/api/admin/*`, `/api/me/*`). Ветка `VisibilityScope::Department` недостижима (зарезервирована под M1).

**Полная матрица «действие × роль», единый authz-бэклог (CRM-1..5, DOC-1/2/3, IAM-1/2/3, KPI-1, SALES-1/2, ACT-1, ONB-1/2) и приоритеты — в [RBAC-matrix.md](RBAC-matrix.md).**

---

## 8. План актуализации vault (ответ на «актуализировать спеку»)

Сводно: какие документы в `2. Модули` / `5. Планы` Obsidian-vault `MG CRM 2026` разошлись с кодом и что поправить. Это материал для `product-manager`.

| Документ vault | Расхождение с кодом | Что поправить |
|---|---|---|
| **`2. Модули/Iam` (RBAC)** | Спека декларирует «RBAC через spatie/laravel-permission (6 ролей + permissions)». В коде spatie мёртв; authz на enum-Gate. | Зафиксировать **реальный** механизм (role-enum Gate + Policy + Visibility) ИЛИ план миграции на spatie-on-Sanctum. Описать: финправа cfo/accountant **не реализованы**; токены не истекают (by design); user mgmt = create+read only. |
| **`2. Модули/CRM — Контакты/Компании`** | Спека + docblock `CompanyPolicy` утверждают фильтрацию списка по видимости, которой нет. Множ. каналы/связи замигрированы, но 0 строк (сосуществуют с legacy-скалярами). | Убрать ложное утверждение о scope (или пометить как баг-to-fix). Описать дуализм legacy-скаляры ↔ новые M2M-таблицы. |
| **`2. Модули/Каталог`** | FX-подсистема описана как рабочая — в коде мертва (0 строк, job no-op, convert 422). | Пометить FX как «каркас, не запущен»; описать price-import `dry_run`-баг. |
| **`2. Модули/Продажи — Сделки`** | Видимость воронок/стадий описана как фича — в коде не применяется. `discount_percent` подразумевается свёрнутым в amount — не свёрнут. | Пометить pipeline-visibility как мёртвую конфигурацию; зафиксировать баг скидки в backlog. |
| **`2. Модули/Продажи — KPI`** | Сравнение с командой описано как рабочее; модель мотивации (commission/targets) — спит до M10. | Описать: team-compare мертво (department_id=NULL); мотивация = M10. |
| **`2. Модули/Продажи — Дашборд`** | (исправление) Миф «дашборд пустой» — **опровергнут**. Период keyится на `stage_changed_at` вместо close/fact-даты. | Удалить «blank dashboard» из known-issues; описать period-keying нюанс. |
| **`2. Модули/Активности`** | Отчёт о встрече + FTM описаны как рабочие — мертвы-холодны (0 отчётов, 0 FTM). | Пометить report/FTM-половину как «каркас, сломан сквозняком». |
| **`2. Модули/Inbox`** | Публичная страница лид-формы (core-flow) подразумевается — не построена и не в отложенном. | Добавить в backlog/deferred явно; описать BE-only состояние (фронт отсутствует). |
| **`2. Модули/Договоры` (Шаблоны + Документы)** | Генерация описана как рабочая — мертва (template_versions=0). `termination_agreement` в спеке=6/сидер=7/live=6. Per-currency банк-счёт описан подключённым — мёртв. | Пометить генерацию как «нерабочую в live (нет docx-версий)»; согласовать число шаблонов; описать licensor per-currency как мёртвый слой. |
| **`2. Модули/Онбординг`** | Студенческий learning loop описан как рабочий — сломан end-to-end (content не отдаётся, quiz-edit 404, AI без ревью; все activity-таблицы пусты). | Пометить студ-цикл как «сломан»; backend-авторскую часть — как «зрелую, не прогнанную». |
| **`2. Модули/Автоматизации`** | Описана как рабочая — построена, но 0 правил/прогонов; кластер FE↔BE config-дрейфов. | Пометить «built, never run»; перечислить config-дрейфы. |
| **`2. Модули/Уведомления` (Log + spec)** | Vault Log-спека устарела целиком (колонки/ресурс/путь компонента/«заменяет Статистики»). Telegram-link контракт. In-app только в «Орбите». | Переписать Log-спеку под реальный resource; описать колокольчик-only-в-Орбите как баг. |
| **`2. Модули/SalesPulse`** | Описание не отражает config-off в dev (teams=[]). | Добавить ремарку: prod LIVE, dev config-off; баг `vacation()` kind. |
| **`2. Модули/Миграция` (runbook)** | `amo_product_mappings`/`migration_maps` описаны как используемые — не читаются ETL. Фаза rollback в runbook — не реализована. | Описать неподключённые слои; пометить rollback как «не реализован (fallback = restore бэкапа)»; убрать табличный авто-карт-механизм из `config/amo_migration.php`-описания. |
| **`5. Планы/Master Roadmap`** | Зрелость доменов в плане оптимистичнее реальности (многие «done» = «built, not verified live»). | Ввести статус «built, not verified live» отдельно от «done»; пересчитать readiness с учётом 17 blocker. |

---

## 9. Мои рекомендации и приоритетная дорожная карта починки

Прямо и критично. Архитектура у проекта **хорошая** — слои, копейки, DDD выдержаны. Проблема не в стиле кода, а в **трёх системных пробелах** (видимость, контракты FE↔BE, недопрогнанные петли) и в **операционно пустых ядрах** (договоры, онбординг).

### P0 — неделя 1 (безопасность; **до любого продакшена**)

1. **Закрыть утечки видимости одним базовым scope.** Единый трейт/global scope `BelongsToVisibility` на `Contact`/`Company`/`Document` по образцу уже работающего `DealService::scopedQuery`. Это **одним срезом** закрывает CRM-1/2/3 + DOC-1 (4 blocker) — не нужно чинить их как 4 отдельных бага. Параллельно — `authorize('viewAny')` с реальным role-гейтом на index/show справочников `/api/admin/*` и на `/api/me/*` (KPI-1, CRM-5/NEW-5).
2. **Закрыть IDOR DOC-2** — `scopeBindings()` на nested-группе документов + проверка `child->document_id === {document}` в сервисах.
3. **Throttle на вход** (IAM-2) — именованный `throttle:login` (email+IP, 5/min) на `/login` и `/2fa/validate`.
4. **401-вместо-500 для API-гостей** (NEW-4) — `redirectGuestsTo`→401 JSON, убрать stack-trace (information disclosure).
5. **Merge компаний** (CRM-4) — обернуть в транзакцию с re-parent всех связей (сейчас сиротит deals/documents/requisites).

### P1 — недели 2–4 (корректность данных + оживление ядер)

6. **Деньги.** Единый `DealAmountCalculator` (line-sum − deal-discount, копейки) во всех агрегаторах; фильтр бюджета ×100 на границе; итоги по валютам; FX на целочисленную арифметику + оживить наполнение курсов; price-import уважать `dry_run`.
7. **Генерация договоров.** Загрузить реальные docx-версии 6 шаблонов + smoke-генерация end-to-end (это операционный, не код-баг). Параллельно — double-increment attempt, фейк-approved демо-доки, shape `doc.id`.
8. **Петля онбординга студента.** Отдавать `content` в `AssignmentDetailResource`, publish-gate на студ-путях, фильтр `is_draft`, реализовать вложенные quiz-роуты.

### P2 — недели 5+ (контрактный дрейф + мёртвые слои + UX)

9. **FE↔BE дрейф системно.** Генерация TS-типов из API Resources + контрактные тесты на ключевые ресурсы — иначе ~15 дрейфов воспроизведутся. Точечно: Telegram-link `deeplink`, EntityLogTab/ChannelHistoryDrawer поля, фильтры шаблонов/переменных, automation whitelists.
10. **Мёртвые слои — решение «wire или delete»:** pipeline-visibility, custom-fields validation, spatie permissions, migration_maps, licensor per-currency.
11. **User management** — достроить edit/deactivate/role/dept + инвайт-flow.
12. **UX:** колокольчик в sidebar, custom-fields, i18n-ключ, NEW-1/6/8.

### Где текущие архитектурные решения рискованны (что бы я изменил)

- **`ResolveVisibility` как M0-заглушка, штампующая неиспользуемый `visibility_scope`** — худший вариант: создаёт **иллюзию** контроля видимости. Либо довести middleware до рабочего scope-инжектора, либо удалить и положиться на per-Service scope. Сейчас он маскирует 4 blocker.
- **Двойной источник роли + мёртвый spatie на `guard=web`** — латентная мина. Будущий `permission:`-middleware молча не сматчит Sanctum-principal: тесты на web-guard зелёные, прод дырявый. **Решить сейчас**, не «когда дойдём до прав»: либо spatie-on-Sanctum, либо снести permission-таблицы и зафиксировать role-enum Gate в спеке. Полумера хуже любого из двух.
- **Богатые бэкенды при отсутствующих фронтах** (inbox, licensors, meeting-report admin, user-mgmt edit) — риск «спека говорит done, пользователь не может». Ввести в roadmap статус **«built, not verified live»** отдельно от «done» и не закрывать домен без живого прогона (как опроверг миф о пустом дашборде только Phase 3).
- **Отсутствие контрактного слоя FE↔BE** — самый частый класс багов (~15). Это не отдельные баги, а отсутствие границы. Без TS-типов из Resources дрейф вечен.
- **Demo/seed-данные, обходящие машину состояний** (фейк-approved документы, минующие won-gate) — опасный прецедент: маскирует, работает ли реальный переход. Сидеры должны идти через легальные сервис-методы.

---

## 10. Приложения — индекс файлов отчёта

### Сквозные документы
- **[RBAC-matrix.md](RBAC-matrix.md)** — авторизация: как устроена на самом деле, матрица «действие×роль», единый authz-бэклог, приоритеты.
- **[process-map.md](process-map.md)** — карта бизнес-процессов: сквозной жизненный цикл, mermaid-схемы, статусы узлов (✅/🟡/🔴/⚪) по всему потоку.
- **live-qa.md** (`/tmp/mgcrm_audit/live-qa.md`) — живой браузерный прогон: journey-таблица, NEW-1..9, CONFIRMED/DENIED, скриншоты.

### Доменные отчёты (`docs/audit/domains/`)
- [iam.md](domains/iam.md) · [crm-contacts.md](domains/crm-contacts.md) · [crm-companies.md](domains/crm-companies.md) · [catalog.md](domains/catalog.md)
- [sales-deals.md](domains/sales-deals.md) · [sales-kpi.md](domains/sales-kpi.md) · [sales-dashboard.md](domains/sales-dashboard.md)
- [activity.md](domains/activity.md) · [inbox.md](domains/inbox.md)
- [contracts-templates.md](domains/contracts-templates.md) · [contracts-documents.md](domains/contracts-documents.md)
- [onboarding.md](domains/onboarding.md) · [automation.md](domains/automation.md) · [notification.md](domains/notification.md)
- [salespulse.md](domains/salespulse.md) · [migration.md](domains/migration.md) · [log-shell.md](domains/log-shell.md)

### Live ground-truth (`/tmp/mgcrm_audit/`)
- `schema.sql` — живая схема БД · `rowcounts.txt` — наполнение таблиц · `verify/*.json` — вердикты адверсариальной верификации.

---

*Аудит: Phase 1 (картирование 17 доменов) → Phase 2 (адверсариальная верификация blocker+major, 0 опровержений кроме dashboard#1) → Phase 3 (живой браузерный QA, 3 роли, 12 journey). Severity — пост-верификационная. Дата: 2026-06-24.*
