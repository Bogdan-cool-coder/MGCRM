# Аудит домена: Автоматизации — движок PipelineAutomation (триггеры/действия, прогоны, round-robin)

> Источники: Phase-1 `/tmp/mgcrm_audit/domains/automation.json`, верификация Phase-2 `/tmp/mgcrm_audit/verify/automation__majors.json` (блокеров нет — файлов `automation__b*.json` не существует), live-QA Phase-3 `/tmp/mgcrm_audit/live-qa.md` (домен Автоматизации в браузерном прогоне НЕ затрагивался), ground-truth `schema.sql` / `rowcounts.txt`.

## 1. Назначение

Домен **Автоматизации** (milestone **M7 «PipelineAutomation engine»**) — это правила «триггер → действие», привязанные к воронке (`pipeline`) и опционально к стадии (`stage`). Бизнес-смысл: автоматически реагировать на события сделок — уведомить в Telegram при входе в стадию, создать задачу, перевести сделку на другую стадию, сменить владельца по round-robin, сгенерировать документ, дёрнуть внешний webhook, изменить поле, отправить e-mail. Триггеры бывают inline (мгновенные: `on_create`, `on_enter_stage`) и cron (по расписанию: `idle_in_stage_days`, `date_field_approaching`). Движок гарантирует идемпотентность (partial-unique индекс на `trigger_event_ts`), изолирует ошибки в очереди `automation`, ведёт журнал прогонов (`automation_runs`) и чистит его по ретеншну.

**Зрелость: каркас-готов, но мёртв в проде (полностью построен, ни разу не запущен).** Обоснование: код реализован end-to-end и высокого качества — backend (`AutomationEngine` resolve+claim+finalize, `AutomationScanner` cron, `ActionDispatcher` с 8 обработчиками действий, 4 вида триггеров, SSRF-guard, ретеншн) и frontend (3-шаговый мастер, canvas-редактор воронки, журнал прогонов). НО по live-данным **`pipeline_automations = 0` и `automation_runs = 0`** при 13 сделках — ни одно правило никогда не создавалось, ни один cron-фаер не происходил. Поэтому ни один из runtime-edge-кейсов (ретеншн-рефайр, просроченные даты, round-robin, `tags`, потеря inline-прогонов) в реальности не воспроизводился. Все найденные дефекты — латентные: «выстрелят» только при первом боевом использовании.

## 2. Карта процессов

| Процесс | Кто (роли) | Где (UI + endpoint) | Как (кратко) | Статус | Примечание |
|---|---|---|---|---|---|
| Inline-триггер `on_create` | система (любой создающий сделку через `DealService::createInbound`) | нет прямого UI; событие `Sales\Events\DealCreated` → listener `RunOnCreateAutomations` | `engine.resolveFor(OnCreate)` → `dispatcher.claimAndQueue` (`trigger_event_ts=deal.created_at`) → `ExecuteAutomationActionJob` → `handler.execute` → `finalize` | 🟡 частично | Построен, юнит-покрыт; 0 прогонов в live — в проде не фаерился |
| Inline-триггер `on_enter_stage` | система (любой двигающий сделку) | Kanban / карточка сделки; событие `Sales\Events\DealStageChanged` → listener `RunOnEnterStageAutomations` | `resolveFor(OnEnterStage, toStageId)` + правила со `stage_id IS NULL` → `claimAndQueue` (`trigger_event_ts=event.occurredAt`) → job → execute | 🟡 частично | Зависит от события `DealStageChanged` (в коде есть). 0 прогонов live |
| Cron-триггер `idle_in_stage_days` | планировщик (ежечасно) | `Schedule::command('automation:scan-idle')->hourly()->withoutOverlapping()` (`console.php:42`) | `AutomationScanner.scanIdleInStage` → сделки с `stage_changed_at <= now-days` и не в архиве → `claimAndQueue` (`trigger_event_ts=stage_changed_at`, детерминированный) → job | 🟡 частично | Построен/протестирован; 0 прогонов live. Подвержен ретеншн-рефайр-багу для долго-простаивающих сделок |
| Cron-триггер `date_field_approaching` | планировщик (ежечасно) | `Schedule::command('automation:scan-date-field')->hourly()->withoutOverlapping()` (`console.php:43`) | `AutomationScanner.scanDateFieldApproaching` → сделки, у кого whitelisted дата-поле в окне `[now, now+days]` → `claimAndQueue` (`trigger_event_ts=date value`) → job | 🟡 частично | Только окно вперёд: уже-просроченные даты (`field < now`) не фаерятся и не имеют catch-up (`AutomationScanner.php:191`) |
| Жизненный цикл прогона / идемпотентность | движок | `AutomationEngine.claimRunSlot/finalize` | pending-строка вставляется ДО side-effect; `success/skipped/queued` ДЕРЖАТ слот; `failed` ОСВОБОЖДАЕТ его (обнуляет `trigger_event_ts`, `AutomationEngine.php:118-119) | 🟡 частично | Корректно для cron (re-scan переклеймит). Для inline нет re-scan → упавшее сетевое действие потеряно навсегда |
| Dry-run / Execute-now | admin, director | `DryRunDrawer` на `AutomationRunsPage` → `POST /api/automations/{id}/test` (без side-effect) / `/execute` (реальный) | `AutomationTestService.simulate` → `handler.dryRun()` по матчам → `actions_plan`; execute прогоняет `handler.execute()` с реальными side-effect'ами | 🟡 частично | Подключено и за-gate'ено; ни разу не запускалось live |
| Ретеншн-прун журнала | планировщик (ежедневно 03:00) | `Schedule::command('automation:prune-runs')->dailyAt('03:00')` (`console.php:48`) | `AutomationRunRetentionService.prune(days)` → `DELETE automation_runs WHERE created_at < now-retention_days` (по умолч. 90), **БЕЗ фильтра по status** (`AutomationRunRetentionService.php:36-38`) | 🔴 сломан | Удаляет слот-держащие `success/queued`-прогоны независимо от статуса → риск дубль-фаера для cron-детерминированных событий старше `retention_days` |

> Поправок live-QA нет: домен Автоматизации в браузерном прогоне (журналы A.1–C.9) не открывался. Статусы взяты из `processes` Phase-1 с учётом верификации.

## 3. Модель данных и реальность БД

| Модель | Таблица | Назначение | Строк в live БД | Статус |
|---|---|---|---|---|
| `PipelineAutomation` | `pipeline_automations` | Одно правило: триггер → действие, scope = воронка (+опц. стадия; `stage_id NULL` = вся воронка) | **0** | построен, не используется |
| `AutomationRun` | `automation_runs` | Audit-строка + слот идемпотентности на одно исполнение; pending вставляется ДО side-effect | **0** | построен, не используется |

**Ключевые поля.** `pipeline_automations`: `id, pipeline_id, stage_id(nullable), name, description, trigger_kind, trigger_config(json), action_kind, action_config(json), is_active, round_robin_cursor(int default 0), created_by_user_id, last_run_at, created_at, updated_at`. `automation_runs`: `id, automation_id(cascade), target_type, target_id, status, trigger_event_ts(nullable), payload(json), result(json), error_message, started_at, finished_at, created_at` (**без `updated_at`** — намеренно).

**Расхождения migration ↔ live-schema ↔ model ↔ vault:**

- **`pipeline_automations.round_robin_cursor`** — в live-схеме есть (`integer DEFAULT 0 NOT NULL`), потребляется `ChangeOwnerAction`. В vault-плане §1.1 колонки нет (курсор спецился «в settings», §4.3). Вердикт: **код = source of truth**, vault §1.1/§4.3 надо дополнить колонкой. Доброкачественное расширение, model/migration/live согласованы.
- **Индекс идемпотентности** — live имеет partial-unique `ux_automation_runs_idem` на `(automation_id, target_type, target_id, trigger_event_ts) WHERE trigger_event_ts IS NOT NULL`, плюс `ix_automation_runs_target` и `(automation_id, created_at)`. Точно по vault §1.2 (PG partial-unique; SQLite-fallback по vault-заметке). **OK.**
- **`automation_runs.updated_at`** — отсутствует намеренно (vault §1.2 + docstring ретеншн-сервиса). **Match.**
- **`pipeline_automations.description`** — `text nullable`, совпадает с vault §1.1. **OK.**
- **Config-whitelist `set_field` (не схема, но трёхсторонний дрейф):** vault §3 = `{title, notes, tags}`; код `config/automation.php:28` = `['title','tags']`; FE `SetFieldConfig.vue:62` = `['notes','title']`. **DRIFT** — см. бэклог #4.

**Пустые-при-наличии-кода таблицы:** обе таблицы домена (`pipeline_automations`, `automation_runs`) — пусты при полностью реализованном движке и FE. Это и есть главная характеристика зрелости домена: «построено, не обкатано».

## 4. Эндпоинты и покрытие фронтом

| Метод + Path | Контроллер@метод | Авторизация | Вызывается FE? | Примечание |
|---|---|---|---|---|
| `GET /api/automations` | `AutomationController@index` | policy `viewAny` (admin/director по `users.role`) + `auth:sanctum`+2fa+locale+visibility | да | `automation.ts` `list()`. `PipelineSettingsPage` шлёт `pipeline_id`; `AutomationRunsPage` — без фильтра (для dropdown). Фильтры `trigger_kind/stage_id/is_active` объявлены, но FE их не шлёт (фильтрация панели клиентская) |
| `POST /api/automations` | `AutomationController@store` | `StoreAutomationRequest::authorize` → policy `create` (admin/director); webhook-действие доп. admin-only в `ValidatesAutomationConfig` | да | `AutomationWizardDialog.onSubmit` (ветка создания) |
| `GET /api/automations/{automation}` | `AutomationController@show` | policy `view` (admin/director) | **нет** | `automationsApi.get()` определён (`automation.ts:56`), но **нет вызывающего** — edit префиллится из закешированного list-объекта (`initFromProps`), не рефетчит. **Мёртвый FE-враппер + endpoint без UI-потребителя** (бэклог minor) |
| `PATCH/PUT /api/automations/{automation}` | `AutomationController@update` | `UpdateAutomationRequest::authorize` → policy `update` (admin/director) | да | `AutomationWizardDialog` (edit) + `AutomationListPanel/AutomationInlineCard` (тоггл `is_active`) |
| `DELETE /api/automations/{automation}` | `AutomationController@destroy` | policy `delete` (admin/director); каскадно удаляет `automation_runs` | да | `AutomationListPanel/AutomationInlineCard/AutomationNode` delete (с confirm) |
| `POST /api/automations/{automation}/test` | `AutomationController@test` | policy `test` (admin/director); dry-run, без side-effect | да | `DryRunDrawer.runDryRun` |
| `POST /api/automations/{automation}/execute` | `AutomationController@execute` | policy `test` (admin/director); **реальные side-effect'ы** | да | `DryRunDrawer.doExecute` (после confirm) |
| `GET /api/automation-runs` | `AutomationRunController@index` | policy `viewAny` на классе `PipelineAutomation` (admin/director); read-only журнал | да | `AutomationRunsPage` `useAutomationRuns`. FE не шлёт фильтры `target_type/target_id` (BE их поддерживает) |
| `GET /api/automation-runs/{id}/retry` | — (нет роута) | — | нет | **Мёртвый endpoint, обещанный docstring'ом `AutomationRunResource`.** Live-проба: `GET /api/automation-runs/1/retry → 404`. Per-run retry в API отсутствует (см. бэклог #6) |

**Orphaned FE-вызовы:** `automationsApi.get(id)` (без потребителя). **Мёртвые/отсутствующие endpoint'ы:** `show` (`GET /api/automations/{id}`) без UI-потребителя; per-run retry — не существует, но анонсирован docstring'ом.

## 5. RBAC домена

| Действие | Кому разрешено | Где реально проверяется | Дыра / замечание |
|---|---|---|---|
| Просмотр / создание / правка / удаление автоматизации, dry-run, execute-now, просмотр журнала | **admin, director** | `PipelineAutomationPolicy::manages()` через `in_array(user->role, [Admin, Director])` (`PipelineAutomationPolicy.php:70`); route-группа `auth:sanctum`+2fa+locale+visibility (`api.php:145`, автоматизации `470-473`) | Дыры доступа нет. НО проверка идёт по **enum `users.role`**, а не по seeded spatie-ability `automation.manage` — двойной источник истины (см. ниже) |
| Настройка webhook-действия | **только admin** | `ValidatesAutomationConfig::validateWebhook` (`ValidatesAutomationConfig.php:214`: `$this->user()?->role !== Role::Admin`) | Корректно усилено — director webhook настроить не может |
| `set_field` защищённых колонок (`stage_id, owner_user_id, amount, currency, role, department_id, password`) | **никто** (блок) | `ValidatesAutomationConfig::validateSetField` blocklist (`:166`) + config-whitelist `['title','tags']` (`config/automation.php:28`) | Корректно заблокировано на уровне валидации + whitelist |

**Где «дыра» (конвенциональная, не дыра доступа):** policy `PipelineAutomationPolicy::manages()` в docstring заявляет, что `automation.manage` — «канонический ability и точка его enforcement», но фактически читает enum роли, а не `$user->can('automation.manage')`. Permission **засеян** и выдан admin+director (`RolePermissionSeeder.php:102`), оба источника сегодня согласованы, но `automation.manage` мёртв в runtime — его отзыв НЕ заблокирует никого. Это расходится и с vault §7 («spatie-ability, НЕ inline if role»), и с собственным контрактом policy. Severity: minor (см. бэклог).

> Важно: live-QA нашла междоменные RBAC-утечки (NEW-5 `/api/admin/*` доступен manager; sales-kpi#1 lawyer → manager-cabinet; crm-contacts/companies), но **ни одна не касается роутов Автоматизаций** — `/api/automations*` и `/api/automation-runs` стабильно за admin/director-гейтом. Утечек доступа внутри домена не выявлено.

## 6. Бэклог проблем

Блокеров нет. Ниже — финальные severity ПОСЛЕ верификации (мажоры/миноры — из вердиктов Phase-2; миноры/тривиальные без отдельной верификации — из Phase-1 json с тегом).

### Сводная таблица

| Severity (FINAL) | Тип | Заголовок | Проверка |
|---|---|---|---|
| major | BUG | Ретеншн-прун освобождает слоты идемпотентности → дубль-фаер cron-автоматизаций | ✅ подтверждено (static) |
| major | BUG | `change_owner` шлёт `pool` (выбранные user-id), backend читает только `user_pool_filter` → пул игнорируется, round-robin по всем активным | ✅ подтверждено (static) |
| major | SPEC-DRIFT | `set_field` FE-whitelist `['notes','title']` ≠ backend `['title','tags']` — `notes` no-op, `tags` недостижим | ✅ подтверждено (static) |
| minor | BUG | `date_field_approaching` не фаерит уже-просроченные даты (нет grace, нет catch-up) | ⚠️ частично (понижено major→minor: as-designed окно) |
| minor | DEAD-CODE | `change_owner` предлагает `by_product/by_country/by_department`, backend жёстко скипает как «Unsupported» | ⚠️ частично (понижено major→minor: benign no-op) |
| minor | MISSING | Журнал анонсирует per-run «Retry» (docstring) без UI-контрола и без per-run endpoint; inline-падения теряются | ⚠️ частично (live 404; уже мажорные claims подтверждены, «потеряны навсегда» — уже) |
| minor | BUG | `change_owner` round-robin: курсор двигается и `department_id` переписывается даже на no-op; общий позиционный курсор перетасовывается при смене пула | не верифицировано (Phase-1) |
| minor | BUG | `change_owner` `user_pool_filter.role` — невалидируемая строка; неверный кейс → пустой пул → вечный тихий «skipped» | не верифицировано (Phase-1) |
| minor | BUG | `set_field` позволяет писать скаляр в `deals.tags` (array-cast) без проверки формы | не верифицировано (Phase-1) |
| minor | BUG | `tg_notify` плейсхолдер `{target_type}` не подставляется `MessageFormatter` — уходит литералом | не верифицировано (Phase-1) |
| minor | STUB | `email`-действие настраивается/сохраняется/активируется, но runtime — no-op («skipped»); UI его не дизейблит | не верифицировано (Phase-1) |
| minor | DEAD-CODE | `automationsApi.get(id)` (`GET /api/automations/{id}`) без вызывающего — edit живёт на устаревшем list-объекте | не верифицировано (Phase-1) |
| minor | CONVENTION | Policy гейтит по enum `users.role`, а не по seeded `automation.manage` (двойной источник истины) | не верифицировано (Phase-1) |
| minor | SECURITY | Webhook может POST'ить данные сделки на любой публичный хост, аудит только в строке прогона | не верифицировано (Phase-1) |
| minor | STUB | Вся фича построена (BE+FE), но ни разу не запущена — 0 автоматизаций, 0 прогонов в live | не верифицировано (Phase-1, но подтверждено rowcounts) |

---

### MAJOR-1 — Ретеншн-прун освобождает слоты идемпотентности → дубль-фаер cron-автоматизаций

**Severity: major · Тип: BUG · Проверка: ✅ подтверждено (static, code-path, мутацию не гоняли)**

Файлы:
- `src/app/Domain/Automation/Services/AutomationRunRetentionService.php:36-38`
- `src/routes/console.php:48` (ежедневный прун)
- `src/config/automation.php:78` (90d по умолчанию)
- `src/app/Domain/Automation/Services/AutomationScanner.php:160` (idle) / `:205` (date)
- `src/app/Domain/Automation/Enums/RunStatus.php:38-41` (`holdsIdemSlot`)

Что происходит: `prune()` выполняет голый `AutomationRun::query()->where('created_at','<',$cutoff)->delete()` — **без WHERE по `status` или `trigger_event_ts`**. Прогон `success/queued/skipped` держит слот идемпотентности ТОЛЬКО фактом существования строки (partial-unique индекс на `trigger_event_ts`). Cron-сканеры используют ДЕТЕРМИНИРОВАННЫЙ `trigger_event_ts` (`stage_changed_at` для idle; значение даты для date_field). У сделки, простаивающей в стадии дольше `retention_days`, её единственная dedup-строка пруна́ется; следующий ежечасный скан переисчисляет тот же ключ, не находит конфликта, клеймит НОВЫЙ слот и ПЕРЕЗАПУСКАЕТ действие — повторное Telegram/webhook/задача/документ для того же уже-отработавшего события. Верификатор отдельно проверил, что partial-unique индекс сам по себе не спасает: он дедупит только против СУЩЕСТВУЮЩИХ строк, удаление слот-держащей строки освобождает ключ `(automation, target, trigger_event_ts)`.

Repro: создать `idle_in_stage_days` (days=7) с `tg_notify`; оставить сделку простаивать в стадии. После того как `created_at` прогона перешагнёт `retention_days` (90), ночной прун удаляет строку; следующий ежечасный скан повторно фаерит уведомление для той же сделки.

Предлагаемый фикс: пруна́ть только терминальные строки, не держащие слот:
`DELETE WHERE created_at < cutoff AND (trigger_event_ts IS NULL OR status='failed')`;
либо удерживать слот-держащие строки заведомо дольше любого правдоподобного idle/date-окна; либо развязать идемпотентность с audit-строкой (отдельная dedup-таблица). Вердикт верификации: предложенный фикс корректен.

---

### MAJOR-2 — `change_owner` шлёт `pool` (выбранные user-id), backend читает только `user_pool_filter` → пул игнорируется

**Severity: major · Тип: BUG · Проверка: ✅ подтверждено (static, confidence 0.95)**

Файлы:
- `front/src/pages/PipelineSettingsPage/components/wizard/config/ChangeOwnerConfig.vue:57,67`
- `src/app/Domain/Automation/Actions/ChangeOwnerAction.php:47` (читает `user_pool_filter`), `:111-124` (`pool()`)
- `src/app/Http/Requests/Automation/Concerns/ValidatesAutomationConfig.php:139` (`ChangeOwner => null`)

Что происходит: FE эмитит `action_config { rule, pool: number[] }`, где `pool` — `MultiSelect` конкретных user-id. `ChangeOwnerAction` читает только `$config['user_pool_filter']` (role/department), а `pool()` НИКОГДА не читает ключ `pool` — при отсутствии role/department-фильтра возвращает ВСЕХ активных пользователей (`User::where('is_active', true)`). Итог: выбранный пользователем пул молча отбрасывается, round-robin крутится по всей организации. У `change_owner` НЕТ дискриминированной валидации (`ValidatesAutomationConfig.php:139` маппит `ChangeOwner => null`), поэтому несоответствие формы никогда не отдаёт 422. Дополнительно i18n `poolHint` (`en.json:2693`) обещает «если пул пуст — берётся весь отдел продаж», что BE тоже не соблюдает (берёт ВСЕХ активных, не отдел).

Repro: Мастер → `change_owner` → rule `round_robin` → выбрать 2 пользователей в пул → save. Dry-run/execute: `next_owner` берётся из ВСЕХ активных, игнорируя пул из 2 человек.

Предлагаемый фикс: согласовать контракт — либо FE эмитит `user_pool_filter { role?, department? }` с селекторами роли/отдела, либо BE читает явный `pool: int[]` user-id. Добавить дискриминированную валидацию `change_owner`, чтобы неверная форма отдавала 422, а не молча врала.

---

### MAJOR-3 — `set_field` FE-whitelist `['notes','title']` ≠ backend `['title','tags']`

**Severity: major · Тип: SPEC-DRIFT · Проверка: ✅ подтверждено (static, confidence 0.95)**

Файлы:
- `front/src/pages/PipelineSettingsPage/components/wizard/config/SetFieldConfig.vue:62`
- `src/config/automation.php:28`
- `src/app/Domain/Automation/Actions/SetFieldAction.php:53,64,76`
- `src/app/Http/Requests/Automation/Concerns/ValidatesAutomationConfig.php:146-174`

Что происходит: FE `FIELD_WHITELIST = ['notes','title']` (+ i18n `dealField.{notes,title}`, без `tags`). Backend `config('automation.set_field.deal') = ['title','tags']`. `notes` — ни whitelisted-колонка, ни реальная колонка `deals`, поэтому `SetFieldAction.execute` уходит в custom-field-ветку (`:64`) и (если нет CustomFieldDef с кодом `notes`) возвращает `skipped` (`:76`). `validateSetField` НЕ режет `notes` на сохранении (только блокирует sensitive-список, `:166`), поэтому правило сохраняется 200 и затем ничего не делает. И наоборот, `tags` (единственная реально-записываемая доп-колонка) отсутствует в FE-списке → недостижима из UI. Трёхсторонний дрейф: vault `{title, notes, tags}` vs BE `{title, tags}` vs FE `{notes, title}`.

Repro: Мастер → `set_field` → field `notes`, value `x` → save (200). Триггер/Dry-run → прогон `skipped` (фактическое сообщение: **"Field 'notes' is not writable on a deal."**, `SetFieldAction.php:76`), сделка без изменений.

Предлагаемый фикс: привести FE-whitelist к backend-source-of-truth (`['title','tags']` с массивным input для `tags`), в идеале экспонировать whitelist из API/общего конфига, чтобы они не дрейфовали. Удалить мёртвую опцию `notes` + i18n-ключ, либо реализовать реальный `notes/extra_fields`-путь на BE по vault-спеке.

---

### MINOR-4 — `date_field_approaching` не фаерит уже-просроченные даты (нет grace, нет catch-up)

**Severity: minor (понижено major→minor) · Тип: BUG · Проверка: ⚠️ частично**

Файл: `src/app/Domain/Automation/Services/AutomationScanner.php:191-193` (+ docstring `:96-99`).

`fireDateFieldAutomation` запрашивает `where($field,'>=',$now) AND where($field,'<=',$windowEnd)`. Любая сделка с уже-прошедшей датой (дата прошла, пока был выключен планировщик/воркер, или правило создали после даты) молча исключается навсегда. Так как `trigger_event_ts` keyed на значение даты, catch-up нет: пропущенное окно пропущено перманентно. Верификатор подтвердил поведение, но понизил до minor: окно `[now, now+days]` явно заспецено в vault §5.2 — это **задокументированное проектное ограничение (forward-only), а не тихий сбой контракта**. Idle-сделки фаерятся вечно; страдают только просроченные даты. Дефект — невыявленный UX-gap (нет предупреждающей подписи в мастере), а не корректностный слом.

Repro: `date_field_approaching` (field `expected_payment_date`, days=3); поставить сделке `expected_payment_date = вчера`; запустить `automation:scan-date-field` → 0 claimed.

Предлагаемый фикс: либо расширить нижнюю границу до `>= now-grace`, либо добавить отдельный overdue-триггер; как минимум — текст в мастере, что триггер строго forward-looking.

---

### MINOR-5 — `change_owner` предлагает `by_product/by_country/by_department`, backend жёстко скипает как «Unsupported»

**Severity: minor (понижено major→minor) · Тип: DEAD-CODE · Проверка: ⚠️ частично**

Файлы: `front/src/pages/PipelineSettingsPage/components/wizard/config/ChangeOwnerConfig.vue:59-64`; `src/app/Domain/Automation/Actions/ChangeOwnerAction.php:42-45,83-84`.

FE `ruleOptions` перечисляет `round_robin, by_product, by_country, by_department` (i18n все есть). `ChangeOwnerAction.execute` для любого `rule !== round_robin` возвращает `ActionResult::skipped("Unsupported change_owner rule: {rule}")`; `dryRun` — `ActionPreview::wont`. На FE три не-MVP правила не дизейблены/без badge. Save-time 422 нет (`change_owner` не дискриминирован). Vault §3/§9 явно помечает эти правила post-MVP. Понижено до minor: эффект — benign no-op (skipped, заауди́чено), не порча данных и не неверный side-effect; UI просто показывает ещё-не-реализованные опции.

Repro: Мастер → `change_owner` → rule `by_department` → save → Dry-run: «Unsupported change_owner rule: by_department»; каждый фаер — skip.

Предлагаемый фикс: убрать `by_product/by_country/by_department` из FE-опций до реализации на BE, либо дизейблить их с badge «скоро».

---

### MINOR-6 — Журнал анонсирует per-run «Retry» без UI-контрола и без per-run endpoint; inline-падения теряются

**Severity: minor (понижено major→minor) · Тип: MISSING · Проверка: ⚠️ частично (live 404)**

Файлы: `src/app/Http/Resources/Automation/AutomationRunResource.php:13,18`; `front/src/pages/AutomationRunsPage/index.vue:86-139`; `src/routes/api.php:470-473`; `src/app/Domain/Automation/Services/AutomationEngine.php:118-119`.

Docstring `AutomationRunResource` утверждает, что `status/result/error_message` «back the Retry button on a failed run». DataTable журнала имеет только колонки `automation/action/target/status/startedAt/error` — никакого retry-контрола. Per-run endpoint'а для повторного прогона одной упавшей строки нет (execute — per-automation). **Live-проба: `GET /api/automation-runs/1/retry → 404`.** В сочетании с `AutomationEngine.finalize`, освобождающим слот на `failed`: для inline-триггеров (`on_create/on_enter_stage`) нет re-scan, поэтому транзиентный сбой Telegram/webhook после исчерпания queue-ретраев просто роняет уведомление — освобождённый слот не переиспользуется, и нет UI/endpoint для retry. Понижено до minor: два факта (docstring обещает Retry; UI/endpoint нет) подтверждены полностью (live 404); «потеряны навсегда» — реально, но вторично относительно job-queue-ретраев и кусается только после их исчерпания. Это устаревший docstring + отсутствующее удобство, а не сломанный core-flow.

Repro: настроить `on_enter_stage` `tg_notify`; двинуть сделку при выключенном Telegram; после ретраев прогон `failed`, дальнейших авто-попыток нет. Открыть `/admin/automation-runs` — retry-аффорданса нет.

Предлагаемый фикс: либо добавить per-run retry-endpoint + кнопку И для inline-прогонов НЕ освобождать упавший слот (чтобы retry был идемпотентен); либо убрать/уточнить docstring-claim и задокументировать inline-сетевые действия как best-effort.

---

### Прочие minor/trivial (не верифицировано — Phase-1)

- **`change_owner` round-robin: курсор/department на no-op** (`ChangeOwnerAction.php:59-69,111-124`). `execute()` всегда обновляет `owner_user_id` + `department_id` и двигает `round_robin_cursor`, даже когда `pool[cursor]` уже владелец (no-op смена владельца + ре-штамп отдела). Курсор — один integer по `ORDER BY id`; добавление/удаление/деактивация пользователя пула сдвигает все последующие назначения (нет стабильной per-user ротации). Фикс: пропускать запись (и не двигать курсор), когда `picked === current`; ротацию keyить на стабильный set user-id.
- **`change_owner` `user_pool_filter.role` — невалидируемая строка** (`ChangeOwnerAction.php:115`, `ValidatesAutomationConfig.php:139`). `where('role', $filter['role'])` без проверки по enum `Role`. Неверный кейс (`'Manager'` vs enum `'manager'`) → пустой пул → `skipped('Candidate pool is empty.')` на каждом прогоне без save-time ошибки. Сегодня бьёт только hand-crafted/API-конфиг (FE шлёт `pool`, не `user_pool_filter`). Фикс: валидировать `change_owner`-конфиг (role ∈ enum, department exists).
- **`set_field` пишет скаляр в `deals.tags` (array-cast) без проверки формы** (`config/automation.php:28`, `SetFieldAction.php:55`, `Deal.php:108`, `ValidatesAutomationConfig.php:146-173`). `update([$field => $value])` сырым value; `validateSetField` не проверяет, что значение array-колонки — массив. `{field:'tags', value:'urgent'}` затолкает скаляр в array-cast (достижимо через API; из UI нет, т.к. FE не предлагает `tags`). Фикс: type-валидация value по cast колонки.
- **`tg_notify` плейсхолдер `{target_type}` не подставляется** (`TgNotifyConfig.vue:94`, `MessageFormatter.php:26-30`). FE предлагает чип `{target_type}`, `MessageFormatter` подставляет только `{target_id},{target_title},{owner_name}` — `{target_type}` уходит литералом. Фикс: добавить подстановку или убрать чип.
- **`email`-действие — savable/activatable runtime no-op** (`EmailConfig.vue`, `ActionPickerStep.vue:137`, `EmailAction.php:35,43`). Карточка email выбираема (в отличие от webhook для non-admin), но `execute` всегда `skipped('Email delivery is not yet available…')`. Vault §4.3/§12 скоупит email как forward-compatible no-op до integrations-спринта — намеренно, но UI даёт собрать/сохранить/активировать молча-не-шлющую автоматизацию; `EmailConfig.validate` всегда `true` несмотря на `*`-маркеры. Фикс: дизейблить карточку с «скоро» (как webhook), либо скрыть; если оставлять — провязать validate.
- **`automationsApi.get(id)` без вызывающего** (`automation.ts:56`, `AutomationWizardDialog.vue:186`). Враппер экспортирован, но edit префиллится из `props.editAutomation` (закешированный list-объект). list-Resource не `loadCount('runs')`, а `show()` — да, поэтому форма работает на потенциально-тонких данных. Не сломано, но враппер мёртв и `show`-endpoint без UI-потребителя. Фикс: либо звать `get(id)` при открытии edit, либо удалить враппер + роут `show`.
- **Policy гейтит по enum `users.role`, а не по `automation.manage`** (`PipelineAutomationPolicy.php:70`, `RolePermissionSeeder.php:102`). Permission засеян и выдан admin+director, но мёртв в runtime: его отзыв никого не заблокирует. Расходится с vault §7 и собственным docstring. Фикс: либо enforce `$user->can('automation.manage')`, либо поправить docstring под role-enum-конвенцию проекта.
- **Webhook может POST'ить данные сделки на любой публичный хост** (`ValidatesAutomationConfig.php:211`, `config/automation.php:61-62`). Admin-only + SSRF-guard (private/loopback/link-local заблокированы, порты 80/443), но любой публичный IP/host достижим; эксфильтрация payload'а сделки by-design, аудит только в строке прогона. Low risk (admin-only + 0 live usage). Фикс (опц.): allowlist назначений + отдельный egress-аудит.
- **Вся фича построена, но ни разу не запущена** (`rowcounts.txt`, `AutomationEngine.php:33`). `pipeline_automations=0, automation_runs=0, deals=13`. Ни один runtime-edge-кейс в реальности не воспроизводился. Перед опорой на домен: создать автоматизацию на каждый вид триггера, прогнать сканеры, проверить журнал + идемпотентность под реальными данными; сначала починить retention/overdue/FE-drift-баги.

> **Live-QA:** домен Автоматизации в браузерном прогоне (журналы A.1–C.9) не открывался — относящихся к домену NEW-1…NEW-9 нет. Междоменные NEW-5 (`/api/admin/*` у manager), sales-kpi#1, crm-contacts/companies к роутам `/api/automations*` отношения не имеют (домен стабильно за admin/director-гейтом).

## 7. Расхождения со спекой (vault) и предложения по актуализации

Все правки — в `5. Планы/Автоматизации (движок M7) — backend plan.md`.

1. **§3 — `SET_FIELD_WHITELIST`.** Спека: `deal => {title, notes(extra_fields), tags…}`, `set_field` в `extra_fields.*` — основной кейс. Реальность: `config('automation.set_field.deal') = ['title','tags']` (нет пути `notes`-колонки; кастомы — через `CustomFieldService`); FE предлагает `['notes','title']` (`notes` — no-op, `tags` недостижим). Правка: зафиксировать фактический whitelist (`['title','tags']` + custom-field-путь) и пометить FE↔BE-дрейф как известный дефект; решить, в скоупе ли `notes`, и привести FE+BE к одному источнику.
2. **§3 / §9 — `CHANGE_OWNER_RULES`.** Спека корректна (реализован только `round_robin`; `by_department/by_product/by_country` — post-MVP). Реальность: FE экспонирует все четыре правила, BE скипает три forward-правила в runtime без save-time-ошибки; FE дополнительно шлёт hand-picked `pool`, который BE не читает. Правка: добавить заметку, что FE преждевременно показывает post-MVP-правила и использует форму `pool`, игнорируемую BE; оба — дрейф к примирению до опоры на `change_owner`.
3. **§7 — Policy.** Спека: настраивать может admin/director через spatie-ability (`automation.manage`), НЕ inline `if role` (ARCHITECTURE §3). Реальность: `PipelineAutomationPolicy::manages()` использует `in_array(user->role, [Admin, Director])` — enum роли, не `$user->can('automation.manage')`; permission засеян, но мёртв в runtime. Правка: либо пометить требование spatie-ability как не-выполненное (дефект), либо обновить под проектную role-enum-конвенцию — нужен один источник истины.
4. **§5.2 Cron `date_field_approaching` / §11 Риски.** Спека: цели с whitelisted дата-полем в окне `[now, now+{days}]`. Реальность: реализовано ровно так, но уже-просроченные даты никогда не фаерятся и нет catch-up после downtime — реальный операционный gap, не отмеченный как риск. Правка: добавить в §11 пункт «пропущенное/просроченное дата-окно» (downtime планировщика/воркера или создание правила после даты ⇒ перманентно пропущено) и решить про grace-окно или отдельный overdue-триггер.
5. **§11 Риски — «Рост `automation_runs` … ретеншн … поздняя фаза».** Спека: ретеншн/архивация журнала — поздняя фаза. Реальность: ретеншн РЕАЛИЗОВАН (`AutomationRunRetentionService` + `automation:prune-runs` ежедневно), но удаляет по `created_at` без фильтра по статусу → освобождает слоты идемпотентности всё-ещё-релевантных cron-прогонов и может вызвать дубль-фаер. Правка: обновить на «ретеншн поставлен» + жёсткая заметка, что прун ОБЯЗАН исключать слот-держащие прогоны (`status != failed AND trigger_event_ts IS NOT NULL`), иначе гарантия §11 («Rerun удваивает действия») сломана для долгоживущих детерминированных событий.
6. **§1.1 / §4.3 — `round_robin_cursor`.** Спека: курсор round-robin «в settings», колонки нет в §1.1. Реальность: live-схема и модель имеют выделенную колонку `round_robin_cursor integer DEFAULT 0`, потребляется `ChangeOwnerAction`. Правка: код = source of truth; добавить колонку `round_robin_cursor` в §1.1/§4.3 (доброкачественное расширение, model/migration/live согласованы).
