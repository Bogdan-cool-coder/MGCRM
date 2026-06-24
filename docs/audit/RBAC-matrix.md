# RBAC / Авторизация — матрица «кто что может»

> Кросс-доменный срез авторизации MACRO Global CRM по итогам аудита (Phase 1 → adversarial verify → live-QA 2026-06-24).
> Severity ниже — **пост-верификационная** (`finalSeverity`), не Phase-1-догадка. Теги проверки: ✅ подтверждено · ⚠️ частично · ❌ опровергнуто · 🌐 подтверждено в браузере · «не верифицировано (Phase-1)» для minor/trivial.

---

## 1. Как авторизация устроена на самом деле

В проекте **два параллельных источника роли и два несвязанных механизма авторизации**, из которых работает только один.

### 1.1. Реально работающий механизм: role-enum Gates + per-domain Policies

Вся серверная авторизация держится на **enum-колонке `users.role`** (`App\Domain\Iam\Enums\Role`: `admin|director|lawyer|manager|accountant|cfo`) и читается двумя способами:

- **3 глобальных Gate** в `src/app/Providers/AppServiceProvider.php`:
  - `admin-write` (`:243`) → `in_array($user->role, [Admin, Director], strict)` — запись в справочники (company-types, sources, countries, cities, contact-positions), CustomFieldDef, импорт прайса.
  - `dedup-scan-all` (`:251`) → `[Admin, Director]` — глобальный дедуп-скан.
  - `system-reset` (`:260`) → `$user->role === Role::Admin` — «Сброс настроек» (самая деструктивная операция, **только admin**).
- **Per-domain Policies** (`app/Domain/<Context>/Policies/*`) — `DealPolicy`, `CompanyPolicy`, `ContactPolicy`, `DocumentPolicy`, `PipelineAutomationPolicy`, `CoursePolicy` и т.д. Все они читают `$user->role` enum напрямую (через `isAdminOrDirector()` или `VisibilityResolver`), **не** spatie-права.
- **Row-level видимость** — `VisibilityScope::forRole()` (`app/Domain/Iam/Enums/VisibilityScope.php`): `All` для admin/director/**lawyer**, `Own` для manager/accountant/cfo. Ветка `Department` **никогда не возвращается** (зарезервирована под M1) — все department-ветки в политиках мёртвые.

### 1.2. Мёртвый механизм: spatie/laravel-permission

Vault-спека `Iam` декларирует RBAC «через spatie/laravel-permission (6 ролей + permissions)». В реальности slat-слой **посеян, но никем не читается**:

- В `app/` и `routes/` **0 вхождений** `permission:`-middleware, `hasPermissionTo`, `->can('x.y')`, `hasAnyPermission`, `role:`-middleware (grep ✅).
- Живая БД: `permissions = 19`, `role_has_permissions = 53`, `model_has_permissions = 0`. Тоггл любой строки `role_has_permissions` **не меняет поведение**.
- **Все 6 ролей имеют `guard_name = web`**, а API аутентифицируется Sanctum c `config/sanctum.php:48 → 'guard' => []` (чистый Bearer-токен, web-fallback намеренно отключён). → **Любой будущий `permission:`/`role:`-middleware молча не сматчит principal** (роли на guard `web`, запрос — на Sanctum). Это латентная мина: код «защиты» добавят, тесты пройдут на web-guard, а в проде проверка вернёт всегда-false/всегда-true в зависимости от направления.

### 1.3. Двойной источник роли и чем грозит

Роль хранится **дважды**: колонка `users.role` (enum) **и** spatie `model_has_roles`. Живо 14/14 строк консистентны, но синхронизирует их **только** `UserService::create()` + `syncRoles()`. Любой другой write-путь (а смены роли через API вообще нет — см. ниже) рассинхронизирует источники. `VisibilityResolver` местами **предпочитает spatie-роль** (sales-dashboard, sales-kpi), тогда как Policies/Gates читают **колонку** → при рассинхроне видимость и доступ разойдутся. Сегодня не эксплуатируется (роль пишется атомарно при создании, пути смены роли нет), severity **minor** ⚠️.

### 1.4. Guard и токены

- **Guard:** Sanctum Bearer, `sanctum.guard = []` — никакого web/session fallback. Tokenable = `User` (`HasApiTokens`).
- **Токены не истекают:** `SANCTUM_TOKEN_EXPIRATION` пуст → `expiration = null`. Живо 63 токена, 0 с `expires_at`. Verify понизил до **minor** ⚠️ (это документированное проектное решение для SPA: коммент `sanctum.php:59-65` обосновывает `null`; остаточный риск — утёкший full-токен и temp-2FA-токен валидны вечно).
- **Две ability-категории токенов:** full `['*']` и temp `['2fa:validate']`. Маршрут `/api/2fa/validate` **не** требует `ability:2fa:validate` → держатель full-токена может ротировать токен (minor, не верифицировано до конца).

**Итог:** реальный RBAC = `users.role` enum через 3 Gate + ~15 Policy + `VisibilityResolver(All|Own)`. 19 permissions / 53 grant'а — **мёртвый код на чужом guard'е**. Решение требуется (см. issue IAM-1): либо подключить spatie на Sanctum guard и заменить Gate'ы на permission-проверки, либо снести permission-таблицы и зафиксировать role-enum Gate RBAC в спеке.

---

## 2. Роли

| Роль | Enum | Видимость (`forRole`) | Назначение по факту кода |
|---|---|---|---|
| **admin** | `admin` | All | Полный доступ: справочники (write), system-reset (единственный), управление пользователями, дедуп-скан, все домены. |
| **director** | `director` | All | Почти как admin, **кроме** system-reset. Справочники write, user-create, дедуп, все домены All-scope. |
| **lawyer** | `lawyer` | **All** | Юрист. Намеренно All-scope по видимости (видит все сделки/компании/контакты). Полные права в Documents/Templates (write наравне с admin). **Дыра:** All-видимость + отсутствие role-гейтов даёт ему доступ к кабинету менеджера и прочим «менеджерским» поверхностям. |
| **manager** | `manager` | Own | Продавец. Должен видеть только своё. По факту item-доступ Own-scope, но **list/export многих доменов не скоупится** → видит всё (см. §4). |
| **accountant** | `accountant` | Own | Бухгалтер. Own-scope. Отдельных финансовых прав в коде нет — ведёт себя как manager по видимости. |
| **cfo** | `cfo` | Own | Финдиректор. Own-scope. **Особых финправ нет** — несмотря на заявленные «финправа как 2 исключения», в коде cfo = обычный Own-юзер. |

> Заявленные «финправа» (accountant/cfo) в авторизационном слое **не реализованы** — это manager-эквивалент по доступу.

---

## 3. Матрица «действие × роль»

Легенда ячеек: ✅ разрешено · ❌ запрещено · 🟡 разрешено, но **шире, чем должно** (дыра) · — нет такого пути ни у кого.
Колонка **«Где проверяется»**: `Gate` / `Policy` / `Visibility` / `middleware` / **нет** (не проверяется) / `FE-only` (только клиентский гейт, API открыт).
🔴 — дыра авторизации/видимости со ссылкой на баг в §4.

### 3.1. Продажи (Deals / Pipelines / Dashboard / KPI)

| Действие | admin | director | lawyer | manager | accountant | cfo | Где проверяется |
|---|---|---|---|---|---|---|---|
| Видеть сделки (list/board) | ✅ all | ✅ all | ✅ all | ✅ own | ✅ own | ✅ own | `DealService::scopedQuery` + `VisibilityScope` ✅ |
| Открыть чужую сделку | ✅ | ✅ | ✅ | ❌ 403 | ❌ | ❌ | `DealPolicy@view` ✅ (live: manager→deal#12 = 403) |
| Создать / редактировать сделку | ✅ | ✅ | ✅ | ✅(own) | ✅(own) | ✅(own) | `DealPolicy@create/update` (`create=true` всем) ✅ |
| Pipeline/Stage/LostReason write | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | `PipelinePolicy` (users.role); **FE не прячет кнопки**, полагается на 403 |
| Row-level pipeline/stage gating (`visible_role/users/departments`) | — | — | — | — | — | — | 🔴 **SALES-1**: поля хранятся/кастятся, но **никем не читаются** — мёртвая access-config (major ✅) |
| Просмотр дашборда (own scope) | ✅ | ✅ | ✅(all) | ✅ own | ✅ own | ✅ own | `SalesDashboardService::baseQuery` Visibility ✅ |
| Фильтр дашборда по чужому `manager_id` | ✅ | ✅ | ❌ 422 | ❌ 422 | ❌ | ❌ | `DashboardRequest::passedValidation` ✅ (live: manager→422) |
| Экспорт дашборда в Excel | ✅ | ✅ | ✅ | 🟡 | 🟡 | 🟡 | 🔴 **SALES-2**: FE-путь экспорта **без Bearer** (`window.open`), токена в URL нет → 401/попап заблокирован (blocker 🌐) |
| Кабинет менеджера `/api/me/kpi`, `/activity-feed` | ✅ | ✅ | 🟡 200 | ✅ own | 🟡 200 | 🟡 200 | 🔴 **KPI-1**: **нет role-middleware** — любой 2FA-юзер получает 200 вместо 403 (major 🌐) |

### 3.2. CRM — Контакты / Компании

| Действие | admin | director | lawyer | manager | accountant | cfo | Где проверяется |
|---|---|---|---|---|---|---|---|
| Список контактов | ✅ all | ✅ all | 🟡 all | 🟡 **all** | 🟡 all | 🟡 all | 🔴 **CRM-1**: `ContactService` list **без owner-scope** → каждый видит все PII (blocker 🌐) |
| Открыть/изменить/удалить контакт (item) | ✅ | ✅ | own | own | own | own | `ContactPolicy@view/update/delete` (item-scope ✅) |
| Экспорт контактов (PII) | ✅ | ✅ | 🟡 | 🟡 **all** | 🟡 | 🟡 | 🔴 **CRM-2**: `ContactBulkController@export` **без `authorize()`**, пустой `ids` → дамп всех PII (blocker ✅) |
| Список / экспорт компаний | ✅ all | ✅ all | 🟡 all | 🟡 **all** | 🟡 all | 🟡 all | 🔴 **CRM-3**: `CompanyService::list`/`CompanyExportService` **без scope** (blocker 🌐) |
| Открыть/изменить компанию (item) | ✅ | ✅ | own/resp | own/resp | own/resp | own/resp | `CompanyPolicy::canAccess` (owner/responsible ✅) |
| Удалить компанию | ✅ | ✅ | own | own(owner) | own | own | `CompanyPolicy::delete` (responsible не может ✅) |
| Bulk update/delete компаний | ✅ | ✅ | own | own | own | own | `BulkCompanyService::authorizeCompanies` (all-or-nothing ✅) |
| Слияние (merge) компаний | ✅ | ✅ | own | own | own | own | 🔴 **CRM-4**: merge **сиротит** deals/documents/requisites/channels (blocker ✅ — data-loss, не authz) |
| Глобальный дедуп-скан | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | `Gate dedup-scan-all` (users.role ✅) |
| Чтение справочников `/api/admin/*` (company-types, sources, countries, cities, contact-positions, acquisition-channels, disconnect-reasons) | ✅ | ✅ | 🟡 200 | 🟡 **200** | 🟡 200 | 🟡 200 | 🔴 **CRM-5 / NEW-5**: `index()`/`show()` **без `authorize()`** (только write гейтится `admin-write`); FE прячет страницу, API открыт (major 🌐) |
| Запись в справочники / CustomFieldDef | ✅ | ✅ | ❌ | ❌ 403 | ❌ | ❌ | `Gate admin-write` (live: manager→403 на write ✅) |

### 3.3. Каталог (Products / Plans / Prices / FX)

| Действие | admin | director | lawyer | manager | accountant | cfo | Где проверяется |
|---|---|---|---|---|---|---|---|
| Чтение товаров/групп/планов/цен/курсов | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | Policies `viewAny=true` всем (read открыт) |
| Создать/изменить/удалить товар/группу/цену/курс | ✅ | ✅ | ❌ | ❌ 403 | ❌ | ❌ | FormRequest→Policy `isAdminOrDirector` (live: manager→403 ✅) |
| Импорт прайса (Excel, real+preview) | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | `ImportPriceRequest` → `Gate admin-write` ✅ ⚠️ **но preview пишет в БД** (blocker CAT-2 ✅, data-integrity не authz) |
| Конвертация валют (GET) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | `authorize('viewAny')` (но FX мёртв — blocker CAT-1 ✅) |
| Кнопка «Обновить курсы» (FE) | видна всем | | | | | | ⚠️ FE без `canWrite`-гейта; endpoint 405 (minor, не верифиц.) |

### 3.4. Документы / Шаблоны (Contracts)

| Действие | admin | director | lawyer | manager | accountant | cfo | Где проверяется |
|---|---|---|---|---|---|---|---|
| Список документов | ✅ all | ✅ all | ✅ all | 🟡 **all** | 🟡 all | 🟡 all | 🔴 **DOC-1**: `documents.index` **без author-scope**, `viewAny=true`, нет global scope на модели — manager видит ВСЕ чужие документы (blocker ✅; docblock врёт «own only») |
| Открыть один документ | ✅ | ✅ | ✅ | own | own | own | `DocumentPolicy@view` (item ✅) |
| Write (update/submit/generate/sign/archive) | ✅ | ✅(автор) | ✅ | автор | автор | автор | `DocumentPolicy` (admin/lawyer/author ✅) |
| Update/delete **дочерних** items/remarks/revisions | ✅ | ✅ | ✅ | автор-род. | автор-род. | автор-род. | 🔴 **DOC-2 (IDOR)**: route-binding `{item}/{remark}` **не скоупится** к `{document}` — авторизуется только родитель → cross-document мутация/удаление (blocker ✅) |
| Удалить документ (только draft) | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | `DocumentPolicy@delete` admin-only ✅ |
| unsign / unarchive / upload-drive | ✅ | ❌ | ✅ | ❌ | ❌ | ❌ | admin/lawyer ✅ |
| Голос согласования (decide) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | любой кроме автора; сервис проверяет членство в стадии (403) ✅; self-approval заблокирован ✅ |
| `showApproval` (детали согласования) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ⚠️ **DOC-3**: **inline role-check в контроллере** (`$user->role->value` сравнение строк) вместо Policy — нарушает ARCHITECTURE.md §3 (minor ✅) |
| Чтение/CRUD реестра вопросов meeting-report | read: все / CRUD: ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | `MeetingReportQuestionPolicy` (users.role; BE-only, нет UI) |
| Licensors / Templates / Variables write | ✅ | ❌(BE 403) | ✅ | ❌ | ❌ | ❌ | `TemplatePolicy/LicensorPolicy` canWrite=admin/lawyer ⚠️ **director видит кнопки (FE nav adminOnly), но BE 403** — рассинхрон FE↔BE (minor) |

### 3.5. Активности / Задачи

| Действие | admin | director | lawyer | manager | accountant | cfo | Где проверяется |
|---|---|---|---|---|---|---|---|
| Просмотр активностей | ✅ all | ✅ all | ✅ all | own | own | own | `ActivityPolicy` + `ActivityService::scopedQuery` (Visibility ✅) |
| Создать активность | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | `ActivityPolicy@create=true` ✅ |
| Update / complete / reopen | all-scope ✅ | ✅ | ✅ | resp/creator | resp/creator | resp/creator | `ActivityPolicy@update/complete` → `canAccess` ✅ |
| Удалить активность | ✅ | ✅ | creator | creator | creator | creator | `ActivityPolicy@delete` (all-scope OR creator ✅) |
| Переназначить `responsible_id` | ✅ | ✅ | ✅(если can update) | ✅(если can update) | ✅ | ✅ | 🔴 **ACT-1**: можно назначить на **ЛЮБОГО** юзера — `exists:users,id` без department/scope-проверки (minor, не верифиц.) |

### 3.6. Автоматизация (Pipeline Automation)

| Действие | admin | director | lawyer | manager | accountant | cfo | Где проверяется |
|---|---|---|---|---|---|---|---|
| View/CRUD/dry-run/execute/journal автоматизаций | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | `PipelineAutomationPolicy::manages()` (`in_array role [Admin,Director]`) ✅ |
| Настроить webhook-action | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | `ValidatesAutomationConfig::validateWebhook` (admin-only ✅) |
| `set_field` защищённых колонок (stage/owner/amount/role/password/department) | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | blocklist + whitelist `['title','tags']` (никто ✅) |

### 3.7. Inbox / Каналы / Формы

| Действие | admin | director | lawyer | manager | accountant | cfo | anon | Где проверяется |
|---|---|---|---|---|---|---|---|---|
| Channels CRUD/reveal/regenerate | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | `ChannelPolicy` (isManager=admin/director ✅) |
| Forms CRUD | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | `FormPolicy` (admin/director ✅) |
| Inbox log (list/show) | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | admin/director ✅ |
| Публичные `forms/public/*`, `inbox/webhook/*` | — | — | — | — | — | — | ✅ | **намеренно анонимно**; `throttle:inbound` + `X-Channel-Token` `hash_equals` ✅ |

### 3.8. Onboarding

| Действие | admin | director | lawyer | manager | accountant | cfo | Где проверяется |
|---|---|---|---|---|---|---|---|
| Авторство курсов/уроков/квизов, publish, assign, HR-дашборд | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | `CoursePolicy/QuizPolicy/AssignmentPolicy` + router meta `[admin,director]` ✅ |
| Проходить назначенные курсы/уроки/квизы | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ownership (`assignment.user_id`) ✅ |
| Доступ к **неопубликованному** контенту | 🟡 | 🟡 | 🟡 | 🟡 | 🟡 | 🟡 | 🔴 **ONB-1**: student-пути **не проверяют `is_published`** — черновики достижимы (major, не верифиц.) |
| AI-тьютор на **чужом** уроке | — | — | — | — | — | — | 🔴 **ONB-2**: нет `authorize('view', lesson)` — возвращает 200 (пусто) вместо 403; данных не утекает, но статус неверный (minor) |

### 3.9. IAM / Профиль / Пользователи / Система

| Действие | admin | director | lawyer | manager | accountant | cfo | Где проверяется |
|---|---|---|---|---|---|---|---|
| Login | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | `AuthService::authenticate` — 🔴 **IAM-2**: **нет throttle/lockout** (blocker ✅) |
| Свой 2FA enroll/validate | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | `TwoFactorController` + `Verify2FA` ✅ |
| Редактировать свой профиль | nav_quick_actions only | | | | | | `UpdateProfileRequest` — только `nav_quick_actions`; full_name/locale/telegram **отбрасываются** (major DEAD-CODE ✅) |
| Читать справочник коллег `/api/users` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | `UserController@index` — нет policy, фильтр только `is_active` (🔴 не исключает `is_service` — minor) |
| Список/создание пользователей; список отделов | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | `Gate admin-write` (users.role) + route meta `[admin,director]` fail-closed ✅ |
| Редактировать/деактивировать/удалить юзера; сменить роль/отдел/менеджера | — | — | — | — | — | — | 🔴 **IAM-3**: **эндпоинта нет вообще** (только index+store). Управление — create+read only, DB-only правки (major 🌐) |
| Назначить `manager_id` при создании | — | — | — | — | — | — | 🔴 нет поля → иерархия неюзабельна (все NULL, minor) |
| System reset | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | `Gate system-reset` (admin-only ✅); 🔴 danger-карточка видна не-админам на `/profile?tab=system` (UI-leak, диалог admin-gated — minor) |
| Granular spatie permission gating | — | — | — | — | — | — | 🔴 **IAM-1**: 19 perms / 53 grant'а **не читаются нигде**; roles guard `web` vs Sanctum guard `[]` (major ✅) |

---

## 4. Сводка дыр авторизации / видимости (единый бэклог)

> Severity = пост-верификационная. file:line — точка фикса. Сортировка: blocker → major → minor.

### 🔴 BLOCKER

| ID | Дыра | Severity | Проверка | file:line |
|---|---|---|---|---|
| **CRM-1** | Список контактов без owner-scope → любая роль (manager) видит **все PII** (телефоны/почты) | blocker | 🌐 live-QA + ✅ probe (manager1→3 чужих контакта с телефонами) | `src/app/Domain/Crm/Services/ContactService.php:49,149` · `ContactPolicy.php:19 (viewAny=true)` · `ContactController.php:34-43` |
| **CRM-2** | `export()` контактов **без `authorize()`**; пустой `contact_ids` → дамп **всех** PII в Excel | blocker | ✅ confirmed (код + прошлый live 200/6566B) | `src/app/Http/Controllers/Crm/ContactBulkController.php:67-82` · `ContactExportService.php:57-58` |
| **CRM-3** | Список **и** экспорт компаний без visibility-scope → manager видит/выгружает все 13 компаний | blocker | 🌐 live-QA (manager1 видит все, owns 0) | `src/app/Domain/Crm/Services/CompanyService.php:58-181` · `CompanyExportService.php:45-66` · `CompanyController.php:37-46` · `CompanyPolicy.php:24-27` |
| **DOC-1** | `documents.index` без author-scope, `viewAny=true`, нет global scope → manager видит **все** чужие документы (docblock «own only» врёт) | blocker | ✅ confirmed (static + live read) | `src/app/Domain/Contracts/Policies/DocumentPolicy.php:16,23-26` · `DocumentController.php:32-40` · `DocumentService.php:48-88` · `Document.php:153-164` |
| **DOC-2** | **IDOR** — `DocumentItem`/`DocumentRemark` update/destroy/resolve не проверяют принадлежность ребёнка `{document}` (route-binding не скоупится) → cross-document мутация | blocker | ✅ confirmed | `DocumentItemController.php:58,70` · `DocumentRemarkController.php:66-68` · `DocumentService.php:201-234` · `routes/api.php:700-720 (нет scopeBindings)` |
| **IAM-2** | Нет rate-limit/lockout на `/api/login` и `/2fa/validate` → неограниченный brute-force кред/TOTP | blocker | ✅ confirmed (8 неверных паролей → все 422, ни одного 429) | `routes/api.php:111,135-137` · `bootstrap/app.php:18-26 (нет api-throttle)` · `AuthService.php:44-61,93-114` |

> Смежные blocker'ы, не являющиеся чисто authz, но усиливающие утечки: **CRM-4** merge сиротит связи (`crm-companies__b2`), **CAT-2** preview прайса пишет в БД (`catalog__b1`), **CRM-1b** утёкший список компаний кликабелен → 403-dead-end (`crm-companies__b1`).

### 🟠 MAJOR

| ID | Дыра | Severity | Проверка | file:line |
|---|---|---|---|---|
| **IAM-1** | spatie permission-слой мёртв (19 perms/53 grant не читаются); roles `guard=web` vs Sanctum guard `[]` — будущий `permission:`-middleware молча не сматчит | major | ✅ confirmed (grep 0 hits + DB) | `AppServiceProvider.php:243-260` · `config/sanctum.php:48` · `config/auth.php:19` |
| **CRM-5 / NEW-5** | Справочники `/api/admin/*` (company-types/sources/countries/cities/contact-positions/acquisition-channels/disconnect-reasons): `index()`/`show()` **без `authorize()`** → любой 2FA-юзер читает BI-чувствительные данные; FE прячет только страницу | major | 🌐 live-QA (manager → все 7 endpoint'ов 200) | `src/app/Http/Controllers/Crm/Admin/*Controller.php` (напр. `CompanyTypeController.php:18-23,34-37` — нет authorize в index/show) |
| **KPI-1** | Нет role-middleware на `/api/me/kpi`, `/api/me/activity-feed`, `/api/me/profile` → lawyer/любой получает кабинет менеджера (200, не 403); FE nav-item тоже не гейтится | major | 🌐 live-QA (lawyer → /manager-cabinet полностью открыт) | `routes/api.php:402-405` (нет role-middleware в группе `:145`) · `ManagerKpiService.php:343,350` |
| **SALES-1** | Row-level pipeline/stage gating (`visible_role`/`visible_user_ids`/`visible_department_ids`) хранится и кастится, но **никем не читается** — мёртвая access-config | major | ✅ confirmed | `src/app/Domain/Sales/...` (pipeline/stage модели — поля surfaced, не consumed) |
| **SALES-2** | Экспорт дашборда: FE-путь `window.open` **без Bearer-токена** → запрос неаутентифицирован, попап блокируется, юзеру нет фидбэка | blocker→ см. sales-dashboard | 🌐 live-QA (CONFIRMED, popup blocked, no token in URL) | `DashboardController@export` + FE export composable |
| **IAM-3** | User management = create+read only: нет edit/deactivate/delete/смены роли/отдела/менеджера ни в BE, ни в FE; `is_active` нефлипаемо | major | 🌐 live-QA (row-click ничего не делает) | `UserManagementController.php:26` (только index+store) · `routes/api.php:374` · `front/.../UsersPage/index.vue:66` |
| **ONB-1** | Student-пути onboarding не проверяют `is_published` → неопубликованный контент достижим по ownership | major | не верифицировано (Phase-1) | onboarding student controllers (CoursePage/lesson resolve) |
| **CRM-doc-drift** | Docblock `CompanyPolicy` + vault-спека утверждают visibility-фильтрацию списка, которой нет — ложная документация маскирует CRM-3 | major | ✅ confirmed | `CompanyPolicy.php` (docblock) |

### 🟡 MINOR / TRIVIAL

| ID | Дыра | Severity | Проверка | file:line |
|---|---|---|---|---|
| **IAM-svc** | `GET /api/users` не исключает `is_service` (сейчас замаскировано `is_active=false` единственного сервис-аккаунта); активный сервис-аккаунт утечёт в dropdown'ы | minor | не верифицировано (Phase-1) | `src/app/Http/Controllers/Iam/UserController.php:29` |
| **IAM-2fa-ability** | `/api/2fa/validate` без `ability:2fa:validate` → full-токен может ротировать токен | minor | не верифицировано (needs-live) | `routes/api.php:135` · `TwoFactorController.php:90` |
| **IAM-sysreset-ui** | Danger-карточка system-reset видна не-админам на `/profile?tab=system` (диалог admin-gated, BE-Gate блокирует → UI-leak, не эскалация) | minor | не верифицировано (Phase-1) | `front/.../ProfilePage/index.vue:318` · `useProfilePage.ts:45` |
| **ACT-1** | Переназначение `responsible_id` активности на **любого** юзера (`exists:users,id` без scope) | minor | не верифицировано (Phase-1) | `Store/UpdateActivityRequest` |
| **DOC-3** | `showApproval` — inline role-check (`$user->role->value`) вместо Policy (нарушение ARCHITECTURE.md §3) | minor | ✅ confirmed | контроллер approval (см. `contracts-documents` rbac#showApproval) |
| **DOC-msgtpl** | message-templates: BE разрешает admin/lawyer/director/manager read, FE router режет до admin/lawyer → director/manager не доходят (over-restriction) | minor | не верифицировано (Phase-1) | FE router + `MessageTemplatePolicy` |
| **TPL-director** | director видит кнопки write в Templates (FE nav adminOnly), но BE Policy=admin/lawyer → 403 (FE↔BE рассинхрон) | minor | не верифицировано (Phase-1) | `navItems.ts:307` · `base.ts:145/154` · `TemplatePolicy` |
| **ONB-2** | AI-тьютор без `authorize('view', lesson)` → 200(пусто) вместо 403 на чужом уроке (нет утечки, неверный статус) | minor | не верифицировано (Phase-1) | onboarding AI-tutor controller |
| **DUAL-ROLE** | Двойной источник роли (`users.role` колонка vs spatie `model_has_roles`); `VisibilityResolver` предпочитает spatie, Policies — колонку → рассинхрон при будущей смене роли | minor ⚠️ | ⚠️ partly (реально, но сегодня не эксплуатируется) | `VisibilityResolver` · `CompanyPolicy` · все Policies |
| **CAT-FE-refresh** | Кнопка «Обновить курсы» (FX) видна всем ролям без `canWrite` (endpoint 405) | minor | не верифицировано (Phase-1) | `front/.../ExchangeRatesPage/index.vue:9-16` |
| **NEW-4** | `auth`-middleware пытается редиректить на несуществующий route `login` → 500 со стек-трейсом вместо 401 JSON для API (information disclosure) | major (live-QA P1) | 🌐 live-QA (GET /api/deals без Auth → полный Laravel exception) | `bootstrap/app.php` (нет `redirectGuestsTo`/401-JSON для API) |
| **VIS-deadcode** | `ResolveVisibility` middleware штампует `visibility_scope`, который **никто не читает**; `VisibilityScope::Department` недостижим (`forRole` не возвращает) | minor/trivial | не верифицировано (Phase-1) | `ResolveVisibility.php:30` · `VisibilityScope.php:31` |

---

## 5. Что сделать в первую очередь (authz-приоритет)

1. **Закрыть list/export-утечки** (CRM-1/2/3, DOC-1) — добавить обязательный visibility-scope в `*Service::list` и `*ExportService`, перевести на global scope/трейт (паттерн `DealService` уже есть). Это 4 blocker'а одной природы.
2. **Закрыть IDOR** (DOC-2) — `scopeBindings()` на nested-группе + проверка `child->document_id === {document}` в сервисах.
3. **Throttle на auth** (IAM-2) — именованный `throttle:login` (email+IP, 5/min) на `/login` и `/2fa/validate`.
4. **Role-middleware на админ/кабинет-поверхности** (KPI-1, CRM-5/NEW-5) — `Gate admin-write` на `index()`/`show()` справочников; role-гейт на `/api/me/kpi|activity-feed`.
5. **401-JSON для API-гостей** (NEW-4) — `redirectGuestsTo` → 401, убрать stack-trace.
6. **Решить судьбу spatie** (IAM-1) — подключить на Sanctum guard ИЛИ снести permission-таблицы и зафиксировать role-enum Gate RBAC в спеке `Iam`.
