# Аудит домена: IAM + Org — аутентификация, 2FA, RBAC, видимость, отделы, пользователи

> Аудит от 2026-06-24. Backend: Laravel 13 (`/src`), Frontend: Vue 3.5 SPA (`/front`).
> Severity у blocker/major — это **финальная** оценка после adversarial-верификации (Phase 2), а не первичная догадка Phase 1. minor/trivial не верифицировались независимо — перенесены из Phase-1 json.

## 1. Назначение

Домен IAM + Org — это фундамент доступа всей CRM: он отвечает за аутентификацию (email+пароль через Sanctum Bearer-токены), двухфазную TOTP-2FA с одноразовыми backup-кодами, ролевую авторизацию (RBAC), правила видимости записей (All/Own), а также за справочники организации — пользователей и отделы. Это «привратник» — от него зависит, кто вообще войдёт в систему и какие сделки/компании/документы увидит.

**Зрелость: частично (auth + visibility — зрелые, RBAC и user-management — каркас, Org — осознанный M0-скелет).** Обоснование: ядро аутентификации и 2FA работает корректно и подтверждено живой проверкой; live-схема `users`/`departments` совпадает с моделями 1-в-1; резолвер видимости fail-closed и реально применяется в доменных политиках. Но административный слой — каркасный: управление пользователями create+read only (live: 14 пользователей, все `manager_id` NULL — иерархия не используется); spatie-permission-слой засеян (6 ролей, 19 прав, 53 гранта), но **никогда не вызывается в коде** — авторизация держится на трёх `Gate::define()` поверх enum `users.role`; отделы (live: 4 строки) — только read-only index, без CRUD (это задокументированный M0-задел под M1). Плюс набор security-провалов на боевом пути входа: нет rate-limit/lockout на `/api/login`, токены бессрочны.

## 2. Карта процессов

| Процесс | Кто (роли) | Где (UI + endpoint) | Как (шаги) | Статус | Примечание |
|---|---|---|---|---|---|
| Email+пароль вход | любой active-пользователь | LoginPage step1 → `POST /api/login` | `AuthService::authenticate` проверяет creds + `is_active`; 2FA off → полный `['*']` токен; 2FA on → temp `['2fa:validate']` токен | ✅ работает | **Нет rate-limit/lockout** — подтверждено живьём (8 неверных паролей → все 422, ни одного 429) |
| Двухфазный 2FA-вход | пользователи с `totp_enabled` | LoginPage step2 → `POST /api/2fa/validate` | temp-токен → проверка TOTP/backup-кода → `completeTwoFactor` удаляет temp, выдаёт полный токен; `Verify2FA` блокирует temp-токены на защищённых роутах | ✅ работает | На роуте нет `ability:2fa:validate`: владелец полного токена может повторно дёрнуть и ротировать токен |
| 2FA-энролл | пользователи без 2FA | ProfilePage Security → `POST /api/2fa/setup` + `/verify-setup` | setup отдаёт secret + `otpauth_uri`; verify-setup включает TOTP и один раз показывает 8 backup-кодов | ✅ работает | FE рендерит `otpauth_uri` как **текст**, а не QR-картинку (vault говорил про QR) |
| Отключить 2FA / перевыпустить backup-коды / admin-reset 2FA | никто | нет endpoint, нет UI | существуют только setup/verify-setup/validate; backup-коды одноразовые и исчерпаемы | ⚪ отсутствует | Реальный риск self-lockout: потеря устройства + исчерпанные коды = восстановление только через БД |
| Редактирование своего профиля | любой auth (self) | ProfilePage Quick Actions → `PATCH /api/me/profile` | BE валидирует и применяет **только** `nav_quick_actions`; `full_name`/`locale`/`telegram` отбрасываются на сервере | 🟡 частично | FE декларирует `full_name`/`locale`/`telegram` + `ChangePasswordRequest`, но никогда их не шлёт |
| Смена локали (account-level) | любой auth (self) | ProfilePage Locale tab | `setLocale` → `localeManager.changeLocale` → i18n + localStorage; **никогда не PATCHится**; `users.locale` не меняется | 🔴 сломан | Подаётся как настройка аккаунта, но это per-browser; вход с другого устройства возвращает старую DB-локаль |
| Смена / сброс пароля | никто | нет endpoint, нет UI | FE имеет интерфейс `ChangePasswordRequest` (0 ссылок); BE-роута нет; `password_reset_tokens` пуста | ⚪ отсутствует | Созданный пользователь не может сменить/восстановить пароль через приложение |
| Загрузка аватара | никто (orphan) | `profileApi.uploadAvatar` → `POST /api/profile/avatar` | FE-метод шлёт multipart на несуществующий роут (404); ни одного вызова в UI; `avatar_path` рендерится, но не наполняется | 🔴 сломан | Подтверждено: роута нет в `api.php`, метод без callers |
| Привязка/отвязка Telegram | любой auth (self) | ProfilePage Telegram → `POST /api/me/telegram-link`, `DELETE /api/me/telegram` | `linkTelegram` открывает `res.link_url` (undefined; BE отдаёт `{deeplink}`) → `window.open(undefined)`; затем 60с поллит `/api/me`; unlink работает | 🔴 сломан | Кнопка открывает about:blank; привязка никогда не завершается; `telegram_link_tokens` = 0 строк |
| Создание CRM-пользователя | admin, director | UsersPage CreateUserDialog → `POST /api/admin/users` | `UserService::create` → дефолтный отдел → `syncRoles` (зеркало `users.role` + spatie) → случайный `Str::password(16)` если пароль не задан | 🟡 частично | Нет `manager_id` (иерархия не работает), нет доставки пароля/инвайта, нет последующего edit-пути |
| Edit/деактивация/удаление пользователя, смена роли/отдела/менеджера | никто | нет endpoint, нет UI | `UserManagementController` = только index+store; FE без actions-колонки; `is_active` не переключить | ⚪ отсутствует | 🌐 подтверждено в браузере: клик по строке в `/admin/users` ничего не делает |
| Справочник отделов / CRUD | admin, director (read only) | `GET /api/admin/departments` | только index; нет create/edit/delete роута или UI | 🟡 частично | Vault явно откладывает Department CRUD + org-chart + visibility на M1 — read-only это намеренное M0-состояние |
| Фильтрация видимости записей (All/Own) | All: admin/director/lawyer; Own: manager/accountant/cfo | доменные политики/сервисы | `VisibilityResolver::resolve` → `VisibilityScope::forRole`; потребляется напрямую в Deal/Company-политиках и `SalesDashboardService` | ✅ работает | `VisibilityScope::Department` `forRole` никогда не возвращает (резерв M1); middleware `ResolveVisibility` ставит атрибут, который никто не читает |
| System reset | admin | ProfilePage system tab → `POST /api/system/reset` | фраза-подтверждение → Gate `system-reset` (`role===Admin`) → reset → `requires_relogin` чистит auth | ✅ работает | Danger-карта+кнопка видны не-админам на `?tab=system`, но диалог admin-gated; безвредная UI-утечка |

## 3. Модель данных и реальность БД

| Модель | Таблица | Назначение | Строк в живой БД | Статус |
|---|---|---|---|---|
| `User` | `users` | Auth-принципал: креды, enum-зеркало роли, зашифрованные TOTP-secret + backup-коды, org-указатели (department/manager), `nav_quick_actions`, locale | 14 | ✅ built |
| `Department` | `departments` | Скелет org-дерева; FK-цель для `users.department_id` и `Company.department_id`; инфра под будущий `VisibilityScope::Department` | 4 | 🟡 partial |
| `Role` | `roles` | 6 spatie-ролей, зеркалятся в enum `users.role`; гранты привязаны, но для authz не используются | 6 | ✅ built (но мёртв для authz) |
| `Permission` | `permissions` | 19 прав засеяны, но нигде в коде не проверяются | 19 | ⚪ stub |
| — | `role_has_permissions` | Матрица role→permission на 53 строки, которую не читает ни одна строка рантайма | 53 | ⚪ stub |
| — | `model_has_roles` | Привязка User→role; держится в синхроне с зеркалом `users.role` через `UserService` | 14 | ✅ built |
| — | `model_has_permissions` | Прямые права пользователя; не используются | 0 | ⚪ stub |
| `PersonalAccessToken` | `personal_access_tokens` | Sanctum-токены (полные `['*']` и temp `['2fa:validate']`); `expires_at` всегда NULL | **6** (Phase-1 json указал 63 — расхождение, см. ниже) | ✅ built |
| `TelegramLinkToken` | `telegram_link_tokens` | Бэкенд для `POST /api/me/telegram-link` deeplink-флоу; 0 строк → end-to-end никогда не отработал (FE-кнопка сломана) | 0 | 🟡 partial |
| — | `password_reset_tokens` | Дефолтная Laravel-таблица; ни один reset-флоу её не использует | 0 | ⚪ missing-feature |
| — | `sessions` | Дефолтный скелет; stateless Bearer-API почти не использует | 3 | 🟡 partial |

**Расхождения migration ↔ live-schema ↔ model:**
- `users` и `departments`: live-схема совпадает с моделями **точно** (migration ↔ live ↔ model — без дрейфа).
- **`users` без `deleted_at` и без `email_verified_at`** — в связке с create-only управлением нет soft-offboarding и нет пути верификации email. (minor)
- **Двойной источник роли** (`users.role` enum vs spatie `model_has_roles`): live консистентен 14/14, но синхронизируется **только** через `UserService::create` + `syncRoles` — любой другой write-путь может рассинхронизировать. (minor)
- **`guard_name=web` у spatie-ролей/прав**, в то время как API аутентифицируется через Sanctum (`config/sanctum.php` guard `[]`) — любой будущий `permission:`/`role:` middleware не совпадёт с principal. (major — см. бэклог)
- **`personal_access_tokens`: все строки с `expires_at = NULL`** → конфиг TTL (`SANCTUM_TOKEN_EXPIRATION`) пуст/null.
- **Расхождение rowcount:** Phase-1 json и verdict «tokens never expire» оперируют числом **63** токена, но авторитетный live `rowcounts.txt` показывает **6**. Это не меняет сути находки (0 строк с `expires_at` в любом случае), но при цитировании опирайтесь на 6.
- **Пустые-при-наличии-кода таблицы:** `model_has_permissions` (0), `role_has_permissions` (53 строки, но 0 рантайм-чтений — «наполнено, но мертво»), `telegram_link_tokens` (0 — флоу сломан), `password_reset_tokens` (0 — фичи нет).

## 4. Эндпоинты и покрытие фронтом

| Метод+Path | Контроллер@метод | Авторизация | Вызывается FE? | Примечание |
|---|---|---|---|---|
| `POST /api/login` | `AuthController@login` | public, только locale, **NO throttle** (подтверждено live) | ✅ да | `authApi.login` (`front/src/api/auth.ts:20`), LoginPage |
| `POST /api/2fa/validate` | `TwoFactorController@validateCode` | `auth:sanctum` + locale; **нет** `ability:2fa:validate` на роуте | ✅ да | `authApi.validateTwoFactor` (`auth.ts:43`), LoginPage step 2 |
| `POST /api/logout` | `AuthController@logout` | sanctum+2fa+locale+visibility | ✅ да | `authApi.logout` (`auth.ts:28`), `AccountMenu.vue:154` |
| `GET /api/me` | `AuthController@me` / `ProfileController` | sanctum+2fa+locale+visibility | ✅ да | `bootstrapApp.ts:24` + Telegram-poll + ProfilePage |
| `PATCH /api/me/profile` | `ProfileController@update` | self; `UpdateProfileRequest` валидирует **ТОЛЬКО** `nav_quick_actions` | ✅ да | `profileApi.updateProfile` — FE шлёт только `{nav_quick_actions}` (QuickActionsPickerDialog) |
| `POST /api/2fa/setup` | `TwoFactorController@setup` | полный токен (sanctum+2fa) | ✅ да | `authApi.setupTwoFactor` (`auth.ts:51`), ProfilePage Security |
| `POST /api/2fa/verify-setup` | `TwoFactorController@verifySetup` | полный токен | ✅ да | `authApi.verifySetup` (`auth.ts:58`) |
| `POST /api/me/telegram-link` | (Notification) telegram link | sanctum+2fa | ✅ да (но сломано) | `authApi.telegramLink` — **SHAPE MISMATCH**: FE читает `{link_url}`, BE отдаёт `{deeplink, expires_in_minutes}` |
| `DELETE /api/me/telegram` | telegram unlink | sanctum+2fa | ✅ да | `authApi.telegramUnlink` (`auth.ts:77`) |
| `GET /api/users` | `UserController@index` | любой auth; фильтрует **только** `is_active=true`, не `is_service` | ✅ да | `usersApi.getUsers` (`users.ts:16`) — дропдауны assignee/owner в crm/sales (НЕ UsersPage) |
| `GET /api/admin/users` | `UserManagementController@index` | Gate `admin-write` (enum admin/director) | ✅ да | `adminUsersApi.getUsers` (`adminUsers.ts:32`), UsersPage |
| `POST /api/admin/users` | `UserManagementController@store` | Gate `admin-write`; **`manager_id` не принимается** | ✅ да | `adminUsersApi.createUser`; CreateUserDialog без `manager_id`/password |
| `GET /api/admin/departments` | `DepartmentController@index` | Gate `admin-write`; **read-only (только index)** | ✅ да | `adminUsersApi.getDepartments`, фильтры UsersPage / CreateUserDialog |
| `POST /api/system/reset` | `SystemResetController@store` | Gate `system-reset` (`role===Admin`) | ✅ да | `systemApi.resetDatabase` (`system.ts:17`), SystemResetDialog (admin-gated) |
| `POST /api/profile/avatar` | **НЕТ (роут отсутствует)** | n/a — роута нет (live 404) | ⚠️ FE декларирует, **0 callers** | `profileApi.uploadAvatar` (`profile.ts:38`) — **мёртвый orphan** |

**Orphaned FE-вызовы:** `profileApi.uploadAvatar` (роут не существует, 0 callers); `ChangePasswordRequest` интерфейс (`profile.ts:12`) — 0 ссылок, нет BE-роута.
**Мёртвые/частичные endpoint'ы:** `POST /api/me/telegram-link` существует, но FE читает неправильный ключ ответа → флоу никогда не завершался (0 строк в `telegram_link_tokens`).
**Отсутствующие, но нужные:** `PATCH/DELETE /api/admin/users/{id}` (edit/деактивация/смена роли), password-reset/change, 2FA-disable/regenerate, avatar-upload, Department CRUD.

## 5. RBAC домена

**Что есть реально (механизм авторизации = enum `users.role` через `Gate::define()` + доменные Policy):**
- `Gate admin-write` (`AppServiceProvider.php:243`) — `role ∈ {admin, director}`: листинг/создание пользователей, листинг отделов.
- `Gate dedup-scan-all` (`AppServiceProvider.php:251`) — подмножество enum-ролей: scan-all дедупа.
- `Gate system-reset` (`AppServiceProvider.php:260`) — `role === Admin`: сброс системы.
- **Видимость записей**: `VisibilityResolver::resolve` → `VisibilityScope::forRole` (All для admin/director/lawyer; Own для manager/accountant/cfo; Department — никогда). Применяется напрямую в Deal/Company-политиках и `SalesDashboardService`.

**Где дыры:**
- **spatie permission-слой полностью не задействован**: `grep` по `app/` и `routes/` даёт **0** вхождений `permission:`/`hasPermissionTo`/`can('x.y')`. 19 прав / 53 гранта / 0 прямых прав — мёртвая инфраструктура. Любой будущий `permission:`/`role:` middleware **молча провалится** из-за `guard_name=web` vs Sanctum guard `[]`.
- **`GET /api/users` без policy** — любой авторизованный читает справочник коллег; фильтрует только `is_active`, не `is_service` (latent-утечка active service-аккаунтов в дропдауны).
- **🌐 NEW-5 (live-QA): `/api/admin/*` справочники доступны manager-роли** — manager1 успешно GET-ит `/api/admin/company-types`, `/sources`, `/countries`, `/cities`, `/contact-positions`, `/acquisition-channels`, `/disconnect-reasons` (все 200). Эти каталоги/настройки должны быть admin-only; `acquisition-channels` и `disconnect-reasons` — чувствительная бизнес-аналитика. **Это реальная дыра RBAC в смежных доменах, корень — отсутствие единого permission-гейта (см. RBAC-дрейф).**
- **System-reset danger-карта видна не-админам** на `/profile?tab=system` (UI-утечка, не privilege escalation — BE Gate блокирует запрос).
- **Edit/деактивация/удаление пользователя — никто не может** (endpoint отсутствует, только DB).

## 6. Бэклог проблем

### Сводная таблица

| Severity (FINAL) | Тип | Заголовок | Проверка |
|---|---|---|---|
| 🔴 blocker | SECURITY | Нет rate-limit/lockout на `POST /api/login` (и `/2fa/validate`) | ✅ подтверждено (live-проба + код) |
| 🟠 major | SPEC-DRIFT | RBAC-дрейф: vault декларирует spatie permissions движком, а authz — enum-Gates; 19 прав/53 гранта мертвы + `guard=web` vs Sanctum `[]` | ✅ подтверждено (статически) |
| 🟠 major | MISSING | Управление пользователями create+read only — нет edit/деактивации/удаления/роли/менеджера (BE+FE) | ✅ подтверждено + 🌐 в браузере |
| 🟠 major | DEAD-CODE | `PATCH /api/me/profile` молча отбрасывает `full_name`/`locale`/`telegram`; FE декларирует поля + `ChangePasswordRequest`, не подключённые | ✅ подтверждено (статически) |
| 🟠 major | DEAD-CODE | `profileApi.uploadAvatar` шлёт на несуществующий `/api/profile/avatar` (404), 0 callers; `avatar_path` ненаполняем | ✅ подтверждено (статически; роут отсутствует) |
| 🟠 major | BUG | Telegram-link кнопка сломана — FE читает `res.link_url`, BE отдаёт `{deeplink}` | ✅ подтверждено (статически) |
| 🟠 major | STUB | Созданный пользователь получает случайный throwaway-пароль без инвайта/доставки | ✅ подтверждено (статически) |
| 🟡 minor | SECURITY | Sanctum-токены бессрочны | ⚠️ частично (намеренное, задокументированное решение; риск уже у leaked-токена) |
| 🟡 minor | SPEC-DRIFT | Смена локали device-only (localStorage), не персистится в аккаунт | ✅ подтверждено (статически) |
| 🟠 high (live-QA) | SECURITY | NEW-5: `/api/admin/*` справочники читаются manager-ролью | 🌐 подтверждено в браузере |
| 🟡 minor | MISSING | Нельзя задать `manager_id` при создании; org-иерархия не работает | не верифицировано (Phase-1) |
| 🟡 minor | MISSING | Нет способа отключить 2FA / перевыпустить backup-коды (self/admin) | не верифицировано (Phase-1) |
| 🟡 minor | BUG | `GET /api/users` не исключает `is_service` (latent-утечка под маской `is_active`) | не верифицировано (Phase-1) |
| 🟡 minor | SECURITY | `/api/2fa/validate` без `ability:2fa:validate` на роуте | не верифицировано (Phase-1) |
| 🟡 minor | SECURITY | System-reset danger-карта видна не-админам на `/profile?tab=system` | не верифицировано (Phase-1) |
| 🟡 minor | DEAD-CODE | Middleware `ResolveVisibility` ставит атрибут, который никто не читает | не верифицировано (Phase-1) |
| 🟡 minor | MISSING | Справочник отделов read-only — нет CRUD (намеренный M0-задел) | не верифицировано (Phase-1) |
| ⚪ trivial | DEAD-CODE | `VisibilityScope::Department` недостижим; `forRole` его не возвращает | не верифицировано (Phase-1) |
| ⚪ trivial | DATA | `password_reset_tokens` + `sessions` присутствуют, но не используются stateless Bearer-API | не верифицировано (Phase-1) |

---

### BLOCKER-1 · SECURITY · ✅ подтверждено (live-проба + статически)
**Нет rate-limit/lockout на `POST /api/login` (и `/2fa/validate`)**

- **Файлы:** `src/routes/api.php:111` (`/login` = только locale), `src/routes/api.php:135-137` (`/2fa/validate` = только auth:sanctum+locale), `src/bootstrap/app.php:18-26` (нет api-throttle-группы), `src/app/Providers/AppServiceProvider.php:265` (единственный лимитер — `inbound`), `src/app/Domain/Iam/Services/AuthService.php:44-61` (`authenticate` без lockout), `src/app/Domain/Iam/Services/AuthService.php:93-114` (`completeTwoFactor` без cap), `src/app/Http/Requests/Auth/LoginRequest.php:19-25` (нет throttle-правила), `front/src/utils/errors.ts:27-42` (нет ветки под 429).
- **Что происходит:** `/login` несёт только locale-middleware; нет `throttle:api`/`throttle:login`. Live-проба: 8 подряд POST с неверным паролем → все 8 HTTP **422**, ни одного **429**, без кулдауна. `AuthService::authenticate` делает обычный `User::where` + `Hash::check` без `RateLimiter::tooManyAttempts`/Lockout-события/счётчика попыток. В схеме нет колонок `failed_login_attempts`/`locked_until` на `users`. Роут `/2fa/validate` — без cap на попытки TOTP. FE не обрабатывает 429.
- **Repro:** `curl -X POST /api/login` с неверным паролем в цикле → неограниченные 422, никакого 429/кулдауна. Аналогично brute-force 6-значного TOTP на `/2fa/validate`.
- **Предлагаемый фикс:** добавить именованный лимитер `throttle:login` (по email+IP, напр. 5/мин) на `/login` и на `/2fa/validate`; добавить 429-ветку в `getApiErrorMessage` на FE. Опционально — Lockout-событие/уведомление.

### MAJOR-1 · SPEC-DRIFT · ✅ подтверждено (статически)
**RBAC-дрейф: vault декларирует spatie permissions движком, а реально authz — три enum-Gate; 19 прав/53 гранта мертвы + `guard=web` vs Sanctum `[]`**

- **Файлы:** `src/app/Providers/AppServiceProvider.php:243` (Gate-замыкания), `src/config/sanctum.php:48` (guard `[]`), `src/config/auth.php:19`.
- **Что происходит:** `grep -rE 'hasPermissionTo|middleware('permission|->hasAnyPermission'` по `app/`+`routes/` → **0** вхождений. Авторизация = `Gate::define('admin-write'|'dedup-scan-all'|'system-reset')` поверх enum `$user->role` + доменные Policy. DB: все 6 ролей `guard_name=web`; `permissions=19`, `role_has_permissions=53`, `model_has_permissions=0`. Vault-модуль «Iam» утверждает «6 фиксированных ролей через spatie/laravel-permission» и подразумевает permission-based RBAC — противоречит реальности. `guard_name=web` vs Sanctum guard `[]` — мина: любой будущий `permission:`/`role:` middleware молча не совпадёт с principal.
- **Repro:** переключить любую строку `role_has_permissions` → поведение не меняется. Гипотетический `Route::middleware('permission:users.manage')` провалился бы (гранты на guard `web`, запрос на sanctum).
- **Предлагаемый фикс:** выбрать **одну** модель: либо (a) подключить spatie на sanctum-guard и заменить 3 Gate на permission-проверки (тогда оживёт и закроет NEW-5), либо (b) удалить неиспользуемые permission-таблицы/гранты и обновить vault-спеку Iam, задокументировав role-enum Gate-RBAC как фактический механизм.

### MAJOR-2 · MISSING · ✅ подтверждено + 🌐 в браузере
**Управление пользователями create+read only — нет edit/деактивации/удаления/смены роли/отдела/менеджера (BE+FE)**

- **Файлы:** `src/app/Http/Controllers/Iam/Admin/UserManagementController.php:26` (только index+store), `src/routes/api.php:374` (только GET+POST), `front/src/pages/UsersPage/index.vue:66` (нет actions-колонки), `front/src/api/adminUsers.ts:23` (только getUsers/createUser/getDepartments).
- **Что происходит:** BE отдаёт только index+store. FE-DataTable без actions-колонки. `is_active` не переключить, роль/отдел/менеджер не сменить, нет soft-delete (`users` без `deleted_at`). 🌐 Live-QA (B.7): страница `/admin/users` рендерится, но клик по строке ничего не делает — нет edit/деактивации, только «+ Добавить пользователя».
- **Repro:** `/admin/users` → создать пользователя → попытаться деактивировать или отредактировать — контролов нет.
- **Предлагаемый фикс:** добавить `PATCH/DELETE /api/admin/users/{id}` (soft-деактивация + ресинк роли/отдела/менеджера) и FE-actions (edit-диалог на базе CreateUserDialog, toggle деактивации).

### MAJOR-3 · DEAD-CODE · ✅ подтверждено (статически)
**`PATCH /api/me/profile` молча отбрасывает `full_name`/`locale`/`telegram`; FE декларирует поля + `ChangePasswordRequest`, которые не подключены**

- **Файлы:** `src/app/Http/Requests/Iam/UpdateProfileRequest.php:30` (валидирует только `nav_quick_actions`), `src/app/Domain/Iam/Services/ProfileService.php:27` (трогает только `nav_quick_actions`), `front/src/api/profile.ts:4`.
- **Что происходит:** `UpdateProfileRequest::rules()` — allowlist только `nav_quick_actions` (+ `nav_quick_actions.*`), поэтому `full_name`/`locale`/`telegram` в теле отбрасываются валидацией; `ProfileService` их не читает в любом случае. FE-интерфейс `UpdateProfileRequest` (`profile.ts:4-10`) объявляет `full_name?`/`locale?`/`telegram_user_id?`, но единственный вызов шлёт только `{nav_quick_actions}` (QuickActionsPickerDialog.vue:154). `ChangePasswordRequest` (`profile.ts:12`) — 0 ссылок, BE-роута нет.
- **Repro:** послать `full_name`/`locale` в `PATCH /api/me/profile` → 200, но в БД ничего не меняется.
- **Предлагаемый фикс:** либо расширить BE+FE для персиста `full_name`/`locale` (+ добавить change-password роут), либо удалить неиспользуемые поля интерфейса и `ChangePasswordRequest`.

### MAJOR-4 · DEAD-CODE · ✅ подтверждено (статически; роут отсутствует)
**`profileApi.uploadAvatar` шлёт на несуществующий `/api/profile/avatar` (live 404), 0 callers; `avatar_path` ненаполняем**

- **Файлы:** `front/src/api/profile.ts:38`, `src/routes/api.php:148`.
- **Что происходит:** `grep 'avatar'` по `routes/` + контроллерам → нет роута/контроллера. `uploadAvatar` имеет 0 вызовов (только определение на `profile.ts:38`). `avatar_path` рендерится в AppSidebar/AccountMenu/Orbita, но всегда падает в инициалы. Live POST на `/api/profile/avatar` → 404 (зафиксировано в аудите; верификатор не выполнял живой POST из-за boundary, но отсутствие роута — диспозитивно статически).
- **Repro:** `curl -X POST /api/profile/avatar -H 'Authorization: Bearer ...'` → 404; `grep` callers `uploadAvatar` → нет.
- **Предлагаемый фикс:** реализовать роут+контроллер аплоада аватара и подключить контрол в ProfilePage, либо удалить мёртвый метод `uploadAvatar`.

### MAJOR-5 · BUG · ✅ подтверждено (статически)
**Telegram-link кнопка сломана — FE читает `res.link_url`, BE отдаёт `{deeplink}`**

- **Файлы:** `front/src/api/auth.ts:69`, `front/src/pages/ProfilePage/composables/useProfilePage.ts:180`, `src/app/Http/Resources/Notification/TelegramLinkResource.php:34` (`$wrap=null`).
- **Что происходит:** `authApi.telegramLink` типизирован как `Promise<{link_url}>`, `linkTelegram` делает `window.open(res.link_url, '_blank')`. `TelegramLinkResource::toArray()` возвращает плоско `{deeplink, expires_in_minutes}` (`$wrap=null` → ключ верхнеуровневый, без `{data:}`-обёртки). Значит `res.link_url` = undefined → `window.open(undefined)` → about:blank; стартует 60с поллинг `/api/me`, который не завершается. `telegram_link_tokens` = 0 строк (флоу никогда не отрабатывал end-to-end).
- **Repro:** Profile → Telegram → «Привязать Telegram» открывает пустую вкладку; привязка не происходит.
- **Предлагаемый фикс:** читать `res.deeplink` (переименовать тип в `{deeplink: string; expires_in_minutes: number}`) и `window.open(res.deeplink)`.

### MAJOR-6 · STUB · ✅ подтверждено (статически)
**Созданный пользователь получает случайный throwaway-пароль без инвайта/доставки**

- **Файлы:** `src/app/Domain/Iam/Services/UserService.php:48` (хеш `Str::password(16)` при отсутствии пароля), `src/app/Http/Requests/Iam/StoreUserRequest.php:40` (password `nullable|min:8`).
- **Что происходит:** `UserService::create` хеширует `Str::password(16)`, когда пароль не передан; нет invite-email / set-password флоу; CreateUserDialog без поля пароля (только подсказка). В связке с отсутствием password-reset роута пользователь, созданный без явного пароля, не может войти и не может восстановиться. **Частичный митигант:** `StoreUserRequest` принимает опциональный пароль — админ может задать его вручную и сообщить out-of-band; аккаунт не «строго непригоден» в каждом флоу. Но дефолтный UI (без поля пароля) даёт непригодный аккаунт.
- **Repro:** создать пользователя через `/admin/users` без пароля → войти он не может, восстановиться сам тоже.
- **Предлагаемый фикс:** добавить invite/set-password флоу (подписанная ссылка или admin-set initial password) + password-reset endpoint.

### NEW-5 (live-QA) · SECURITY · 🌐 подтверждено в браузере
**`/api/admin/*` справочники доступны manager-роли**

- **Что происходит:** manager1 успешно GET-ит `/api/admin/company-types`, `/api/admin/sources`, `/api/admin/countries`, `/api/admin/cities`, `/api/admin/contact-positions`, `/api/admin/acquisition-channels`, `/api/admin/disconnect-reasons` — все **200 OK**. Это каталоги/настройки, которые должны быть admin-only; `acquisition-channels` и `disconnect-reasons` — чувствительная бизнес-аналитика. Корень — отсутствие единого permission-гейта на справочниках (прямое следствие MAJOR-1: пока authz держится на точечных enum-Gate, легко забыть закрыть новый admin-роут).
- **Repro:** под manager-токеном `curl /api/admin/acquisition-channels` → 200 + данные.
- **Предлагаемый фикс:** навесить Gate `admin-write` (или будущий permission) на всю группу `/api/admin/*` справочников; перепроверить, какие именно read-only нужны не-админам (если нужны вообще). *Примечание: основная локализация фикса — домены crm/каталога, но первопричина — RBAC IAM.*

---

### minor / trivial (не верифицировано — Phase-1)

- **minor · SECURITY · Sanctum-токены бессрочны** — `config/sanctum.php:69` `expiration = env('SANCTUM_TOKEN_EXPIRATION') ?: null`, env пуст → null. **⚠️ частично:** verdict понизил до minor — это намеренное, задокументированное решение (комментарий `sanctum.php:59-65` доказывает, что null — «единственное корректное прод-значение» для Bearer-SPA, чтобы не разлогинивать в середине сессии; web-guard fallback уже отключён через guard `[]`). Остаточный риск — leaked полный токен валиден вечно и temp 2FA-токен без TTL — реален, но уже. Фикс: задать TTL для temp 2FA-токена явно; рассмотреть refresh-стратегию для полных. *(rowcount: live 6 токенов, 0 с `expires_at`; Phase-1 json указывал 63 — расхождение.)*
- **minor · SPEC-DRIFT · Локаль device-only** — `localeCoordinator.ts:21` → i18n + localStorage, без PATCH; `UpdateProfileRequest` не принимает `locale`; `users.locale` ставится один раз `'ru'` при создании. ✅ подтверждено в verdict. Фикс: добавить `locale` в `UpdateProfileRequest` (BE+FE) и PATCH при смене, либо переименовать таб как device-preference.
- **minor · MISSING · Нельзя задать `manager_id` при создании** — `StoreUserRequest.php:33` без `manager_id`, `UserService::create:33` его не ставит, CreateUserDialog без поля. FK+relation есть, но live все `manager_id` NULL. Фикс: добавить `manager_id` в request+persist + поле в диалог.
- **minor · MISSING · Нет disable-2FA / regenerate backup-codes** — `TwoFactorController.php:25`, `TwoFactorService.php:121`: только setup/verify-setup/validate. Риск self-lockout. Фикс: `POST /api/2fa/disable`, `/regenerate-codes`, admin-reset-2fa.
- **minor · BUG · `GET /api/users` не исключает `is_service`** — `UserController.php:29` фильтрует только `is_active=true`. Сейчас единственный service-аккаунт (id 13, `import-amo@mgcrm.local`) скрыт лишь потому, что `is_active=false`; активный service-аккаунт утёк бы в дропдауны assignee. Фикс: `->where('is_service', false)`.
- **minor · SECURITY · `/api/2fa/validate` без `ability:2fa:validate`** — `api.php:135`, `TwoFactorController.php:90`: группа только `auth:sanctum`+locale; контроллер сторожит только `totp_enabled`. Владелец полного токена может повторно дёрнуть и ротировать токен. Фикс: `->middleware('ability:2fa:validate')` на роут.
- **minor · SECURITY · System-reset danger-карта видна не-админам** — `ProfilePage/index.vue:318`, `useProfilePage.ts:45`: `VALID_TABS` включает `'system'` для любого auth; карта+кнопка рендерятся не-админам, но `SystemResetDialog` `v-if=isAdmin` → диалог не открывается, BE Gate блокирует запрос. UI-утечка, не privilege escalation. Фикс: гейтить весь system-таб за `isAdmin`.
- **minor · DEAD-CODE · `ResolveVisibility` middleware ставит непрочитанный атрибут** — `ResolveVisibility.php:30`, `api.php:145`: ставит `request.attributes['visibility_scope']`, потребителя нет (видимость резолвится напрямую в политиках/сервисах). Фикс: потреблять атрибут в query-scope'ах или удалить middleware (vault помечает как M0-скелет).
- **minor · MISSING · Справочник отделов read-only** — `DepartmentController.php:22`, `api.php:378`: только index. Vault явно откладывает Department CRUD + org-chart + visibility на M1 — это задокументированный M0-скелет (находка «намеренна», не дрейф).
- **trivial · DEAD-CODE · `VisibilityScope::Department` недостижим** — `VisibilityScope.php:31`, `DealPolicy.php:62`: `forRole` возвращает только All/Own; Department-ветки (`departmentSubtreeIds`) в DealPolicy/SalesDashboardService не исполняются. Vault резервирует Department-видимость под M1. Фикс: оставить как задокументированный резерв M1 либо удалить мёртвые ветки до M1.
- **trivial · DATA · `password_reset_tokens`(0) + `sessions`(3) не используются** stateless Bearer-API — дефолтный Laravel-скелет. Оставить `password_reset_tokens` до появления reset-фичи; `sessions` можно дропнуть, если web-session-флоу не планируется.

## 7. Расхождения со спекой (vault) и предложения по актуализации

Документ: **`2. Модули/Iam — Аутентификация, 2FA, RBAC, Visibility.md`** и **`2. Модули/Org — Отделы.md`**. Целевой раздел планов — **`5. Планы`** (Master Roadmap).

1. **RBAC-механизм (раздел «Что делает / RBAC»).**
   - Спека говорит: «6 фиксированных ролей через spatie/laravel-permission», подразумевая permission-based RBAC.
   - Реальность: authz исключительно через 3 `Gate::define()` поверх enum `users.role` (`admin-write`, `dedup-scan-all`, `system-reset`) + доменные Policy. spatie-таблицы (19 прав / 53 гранта) засеяны, но не проверяются; роли `guard_name=web` при Sanctum guard `[]`.
   - Правка: задокументировать role-enum Gate-RBAC как фактический механизм, перечислить 3 Gate и доменные Policy; добавить follow-up «spatie permission-слой сейчас мёртв — решить: подключить на sanctum-guard или удалить». **Это же закрывает NEW-5 (admin-справочники без гейта) на уровне первопричины.**

2. **Безопасность (раздел «реализовано»).**
   - Спека перечисляет контролы безопасности, но не упоминает login-throttling и истечение токенов.
   - Реальность: нет rate-limit/lockout на `/api/login` (подтверждено live); Sanctum-токены бессрочны (0 строк с `expires_at`).
   - Правка: добавить явный блок «Known gaps»: нет login-throttle, бессрочные токены, нет TTL у temp-токена, нет password reset/change, нет 2FA disable/regenerate. *NB: бессрочность полных токенов — осознанное решение (см. комментарий в `sanctum.php`); задокументировать как design-decision, а не баг, но temp 2FA-токен без TTL — действительно дыра.*

3. **Сущности / `nav_quick_actions` + `PATCH /api/me/profile`.**
   - Спека: `PATCH /api/me/profile` — эндпоинт профиля (добавлен `nav_quick_actions`).
   - Реальность: принимает **только** `nav_quick_actions`; FE дополнительно декларирует `full_name`/`locale`/`telegram` + `ChangePasswordRequest`, не подключённые; локаль device-localStorage-only; `/api/profile/avatar` отдаёт 404.
   - Правка: указать, что `PATCH /api/me/profile` строго ограничен `nav_quick_actions`, и что account-level персист локали, edit имени, change-password и avatar-upload **не реализованы**.

4. **API-эндпоинты (добавить user-management).**
   - Спека: таблица перечисляет auth + `/api/users`; нет user-management CRUD.
   - Реальность: `/api/admin/users` (index+store) и `/api/admin/departments` (index) за Gate `admin-write`, но нет edit/деактивации/удаления/роли/менеджера.
   - Правка: добавить admin user/department-эндпоинты в таблицу и пометить, что управление пользователями create+read only (нет PATCH/DELETE), `manager_id` не задаётся.

5. **`Org — Отделы.md` (раздел «Follow-ups»).**
   - Спека перечисляет Department CRUD, Department-visibility (subtree-traversal), org-chart как будущие follow-ups.
   - Реальность совпадает (read-only index) — это задокументированный M0-скелет, поэтому связанные MISSING-находки намеренны, не дрейф.
   - Правка: подтвердить статус скелета; опционально отметить, что `GET /api/admin/departments` (read-only index) теперь существует.

### Открытые вопросы для продукта
- **Модель RBAC:** подключать spatie на sanctum-guard (оживить 19 прав/53 гранта) или дропнуть permission-таблицы в пользу существующих enum-Gate? (Прямо влияет на закрытие NEW-5.)
- **Login-throttling/lockout** — недосмотр или отложено? Это прод-blocker для credential-stuffing/brute-force TOTP.
- Нужен ли конечный TTL для Sanctum-токенов (и короткий TTL для temp 2FA-токена) + refresh-стратегия?
- Планируется ли в ближайшем спринте account-level управление пользователями (edit/деактивация/удаление, смена роли/отдела/менеджера, `manager_id` при создании)?
- Как созданный пользователь должен получать креды — invite-ссылка, admin-set пароль или self-service reset? Сейчас ничего нет.
- Строить ли avatar-upload (`avatar_path` рендерится, но ненаполняем) или удалить мёртвый FE-метод?
