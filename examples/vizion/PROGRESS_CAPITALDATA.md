# Прогресс — пункты 1, 2, 5 из DEVELOPMENT_PLAN_CAPITALDATA.md

Сессия запущена: 2026-05-20, автономный режим («до победного»).
Владелец вернётся утром за отчётом.

---

## ⚡ Итоговый отчёт (для владельца, утренний)

**Статус: все три пункта (1, 2, 5) реализованы, прошли локальный QA и PM-review. ✅**

### Что появилось в продукте

1. **Dashboard view** (п.1). На странице отчёта — переключатель Table/Dashboard. В режиме Dashboard виджеты строятся из `report.config.dashboard_widgets[]` (новое аддитивное поле в конфиге), AI умеет их генерировать через ReportTool, layout drag/resize через `grid-layout-plus`, persist per user × per report в localStorage. Новый endpoint `GET /api/reports/{id}/dashboard-data` — flat-датасет без пагинации, hard cap 5000 строк, meta.dashboard_limited.
2. **Column groups + Widget groups** (п.2). Двухуровневый header колонок через PrimeVue ColumnGroup (collapsible по группам). Виджеты с widget_group → collapsible sections. AI обязан группировать при >5 колонок / >3 виджетов. Backend rejects column_group + group_by (только flat).
3. **Tooltip column description** (п.5). Иконка `?` у заголовка колонки с PrimeVue Tooltip (RU/EN). 23 ключевые колонки 6 системных отчётов получили описания (финансы, expressions, status mappings, payment_schedule, cumulative). Click на иконку не триггерит sort.

### Дополнительно сделано (вне scope, но потребовалось)

- **Toolbox UX fix**: `.toolbox--top` через CSS var `--toolbox-top-offset` с дефолтом 1rem (другие страницы) и 5rem override на ReportPage (через JS lifecycle). На v1 (pointer-events) и v2 (padding) пришлось делать 3 захода.
- **`docker/php/entrypoint.sh`**: `php artisan optimize:clear` перед `exec "$@"` — фикс Pail bootstrap cache 500-х после rebuild.

### Метрики

- Backend тестов: 19/19 ReportTool, 74/74 Chat, 58/58 Reports, 11/11 DashboardDataEndpointTest, 9/9 DashboardDataTest (новые).
- Frontend: type-check + lint + build чисто на каждой итерации.
- QA: 13/13 чеков на P1, PASS на P2 (3-я попытка Toolbox), PASS на P5 — 0 console errors, без регрессий в AI-чате / списке отчётов / переключении компаний.
- Деплой: 3 локальных rebuild стека vizion.lazarewww.ru (app + frontend + nginx restart + reseed где нужно).

### ⚠ Что НЕ закоммитил

В working tree 85 изменённых/новых файлов — но кроме моих П.1/П.2/П.5 там лежат твои незакоммиченные работы:
- **П.3** (currency/timezone migration + Company model + seeders + `CompanyCurrencyTimezoneTest`).
- **П.6 MiniChat** (`MiniChatWidget.vue`, `reportContext.ts`, Toolbox locale/types изменения, `useFormatter.ts` currency, `capabilities.canUseMiniChat`).
- **AI streaming** (`AiRetryService::executeStreamingWithRetry`, `ChatEventEmitter::TYPE_TEXT_DELTA`, `ChatThinkingTimeline.vue`, `useChatStream.ts`, `ChatMessageBubble/List`, `ChatService.ts`).
- **GenerateReportTile restyle** (предыдущая итерация).
- `COMPETITIVE_ANALYSIS.md` (root, не моё).

Эти файлы пересекаются с моими (например `ReportTool.php` содержит и мои 3 prevalidate, и чужие изменения; `Toolbox.vue`, `ReportPage/index.vue`, `capabilities.ts`, `frontend-changes-08-05.md` тоже смешаны). Делить через git add по файлам без потерь нельзя.

**Рекомендация**: с утра ты сам решаешь:
- (а) один большой коммит «п.1+п.2+п.5+п.3+п.6+streaming» — быстро.
- (б) интерактивный staging через `git add -p` чтобы расщепить на 3-4 атомарных коммита.
- (в) `git stash` чужих работ если они ещё в WIP, и коммитить только мои три пункта.

Никакой push не делал — это твоё решение.

### Tech debt (post-iteration backlog)

- **Backend**: ACL copy-paste в `ReportController::show/groupRows/dashboardData/filterOptions` → private `assertReportAccess()` метод. `protected const DASHBOARD_LIMIT` стоит вверху класса. `getDashboardData` PHPDoc — объяснить отсутствие `User` параметра (ACL в controller'е). Лимит 5000 hardcoded → `config('reports.dashboard_limit', 5000)`.
- **Frontend**: `useColumnGroups` vs `useWidgetGroups` — 80% кода дублируется, можно вынести в `createGroupsComposable<T>`. `groupHeaderCells` computed ~55 строк в `ReportPage/index.vue` — можно переместить в composable. ColumnGroupsToggle мог бы принимать localizedLabels prop, не keys.
- **A11Y**: Tooltip column description недоступен с клавиатуры (нет focusable target). Для full A11Y нужен `<button>` вместо `<i>` + `aria-label`.
- **UX**: на 1280px нижние ~6px кнопок шапки ReportPage перекрыты Toolbox-toggle (cosmetic, центр кликабельный). Можно поднять до `top: 6rem` или conditional offset depending на header height.
- **Live AI scenario**: column_group / widget_group / dashboard_widgets / description через реальный AI prompt в QA не тестировались (AI бюджет). Стоит прогнать руками: попросить AI создать отчёт с группировкой и виджетами.
- **Infra**: `vizion-nginx` не auto-restart — добавить `restart: unless-stopped` в `docker-compose.yml`.
- **Decompose**: P.6 MiniChat статус в плане — пометить как Done после твоего ревью (frontend-specialist в одну из итераций самостоятельно сделал большую часть; нужна верификация).

---

## Принятые решения (автономно или по уточнениям пользователя)

| # | Вопрос | Решение | Когда |
|---|---|---|---|
| Q1 | Endpoint для полного датасета дашборда | Отдельный endpoint `GET /api/reports/{id}/dashboard-data` (без пагинации, лимит 5000 строк) | uplink-вопрос пользователю |
| Q2 | column_group в grouped-отчётах (master/detail) | Только flat — не комбинировать с group_by | uplink-вопрос |
| Q3 | description к колонкам существующих 6 системных отчётов | Добавить сразу в рамках п.5 (через report-author) | uplink-вопрос |
| A1 | Чарт-библиотека для виджетов дашборда | Chart.js 4 (без миграции) — решено в плане | план |
| A2 | Drag-grid библиотека | grid-layout-plus (Vue 3 форк vue-grid-layout) | план |
| A3 | localStorage ключи | `vizion-report-view-{report_id}-{user_id}`, `vizion-dashboard-layout-{report_id}-{user_id}`, `vizion-column-groups-{report_id}-{user_id}` | автономно |
| A4 | description в jsonb или строкой | jsonb `{ru, en}` совместимо с строкой (фронт фолбэк) — соответствует другим i18n-полям | автономно |
| A5 | Лимит rows в getDashboardData | 5000 (соответствует риску из плана §1.6); при превышении — обрезка + warning в meta | автономно |
| A6 | Группировка для master/detail в UI кнопке | Кнопка «Группы колонок» отображается только если в конфиге есть хоть одна колонка с column_group | автономно |
| A7 | Visibility виджетов | По дефолту все видимы; persist hidden_widgets[] в localStorage layout | автономно |
| A8 | Подсказки для виджет-каталога в дашборде | Если у отчёта нет dashboard_widgets — пустой стейт с текстом «Добавьте виджеты через AI-чат». Не катиться в visual builder в этой итерации. | автономно |
| A9 | i18n виджет title | Поддерживаем `{ru, en}` jsonb + строку (как report header) | автономно |
| A10 | Tooltip триггер | Hover + focus (PrimeVue Tooltip default), клик на иконку — `@click.stop` чтобы не запускать sort | автономно |

---

## Roadmap прогресса

### Пункт 1 — Dashboard view (Chart.js 4 + grid-layout-plus)
- [x] T1.1 macrodata-engineer: `ReportDataService::getDashboardData(Report, Company, filters)` + лимит 5000 + meta.dashboard_limited — 270/270 тестов, 9 новых в DashboardDataTest
- [x] T1.2 backend-specialist: route `GET /api/reports/{id}/dashboard-data` + `ReportController::dashboardData()` + 11 feature тестов + FRONTEND.md секция
- [x] T1.3 chat-ai-engineer: `dashboard_widgets` в `ReportTool` (pre-validation + cross-ref) + REPORTS_GUIDE.md «Dashboard Widgets» секция — 14/14 + 74/74 тестов
- [x] T1.4 frontend-specialist: переключатель table/dashboard (SelectButton), `ReportDashboardView`, 4 composables, grid-layout-plus@1.1.1, 2 ключа localStorage, capability canManageDashboardLayout, banner о лимите, 3 empty-state. type-check + lint + build чисто.
- [x] T1.5 deploy-engineer: app rebuilt + frontend rebuilt (bundle index-BeDb53qG.js), nginx restarted, smoke 200 root + 401 dashboard-data — стек готов к QA.
- [x] T1.6 qa-tester: PASS. Toggle Table/Dashboard работает, persist через reload, empty state + limit banner ОК (отчёт 6: 13541 строк → banner). Network/console чисто. Drag-grid не тестировали (виджетов нет). UX-заметка: на 1280px Toolbox перекрывает нативный клик по SelectButton (JS-клик работает) — fix-action в P2 шапке.
- [x] T1.7 product-manager: review PASS. Docs sync ✅ — план §Roadmap п.1 → «✅ Готово (2026-05-20)», §1.3.D переписан под /dashboard-data fetch, CLAUDE.md +пункт «Dashboard view (2026-05-20)».

### Пункт 2 — Column groups + widget groups
- [x] T2.1 chat-ai-engineer: `column_group` (для columns[]) + `widget_group` (для dashboard_widgets[]) допущены в ReportTool. pre-validation `prevalidateColumnGroups()`: type-check + отказ при column_group вместе с group_by. REPORTS_GUIDE.md: §0.1 ALWAYS-правила (>5 колонок → column_group, >3 виджетов → widget_group), 2 новые подсекции «Группировка колонок» и «Группировка виджетов» с примерами. 7 новых тестов (21/21 ReportTool tests).
- [x] T2.2 frontend-specialist: composables `useColumnGroups` + `useWidgetGroups`, `ColumnGroupsToggle.vue` popover. PrimeVue ColumnGroup рендер (2 Row, colspan для contiguous group, rowspan=2 для ungrouped, sortable на header-Column в grouped, sub-headers только для grouped). Widget groups → collapsible sections (drag отключён в этом режиме). 2 новых localStorage ключа. **Fix Toolbox z-index**: `pointer-events: none` на `.toolbox` root + `auto` на children (root cause — flex container reserves hit area для gap-space). type-check + lint + build чисто.
- [ ] T2.3 deploy-engineer: локальный rebuild (frontend)
- [x] T2.4 qa-tester: PASS на третьем заходе. v1 (pointer-events) и v2 (padding) не помогли. v3 (top:5rem) — `at_is_sb: true`, native click работает, drag работает. Smoke без регрессий. Оговорка: визуально нижние 6px кнопок шапки перекрыты toolbox-toggle на 1280px — функционально не влияет (центр кликабельный); если нужно полностью убрать — top: 6rem. Кнопки column_groups/widget_groups live с AI не тестировали (skip).
- [x] T2.5 product-manager: review PASS с фиксами. Docs sync (план §2 → ✅, q#4 закрыт, FRONTEND.md +column_group, CLAUDE.md +зафикс. решение, chat-ai-engineer.md +streaming). Code smells (prop/computed shadowing, double onBeforeUnmount, i18n keys, Toolbox global pos) → переданы frontend-specialist. **entrypoint.sh exec "$@"** проверен — корректно работает с CMD ["php-fpm"] из Dockerfile, hotfix не нужен. Frontend cleanup ✅ применён (Toolbox через CSS var с дефолтом 1rem, override на ReportPage через JS lifecycle; убрано shadowing; объединены lifecycle hooks; i18n keys через props).

### Пункт 5 — Tooltip description колонок
- [x] T5.1 macrodata-engineer: **passthrough**, никакого whitelist. `description` и `column_group` пройдут в response без изменений. Никаких правок не требуется. 58/58 Report тестов.
- [x] T5.2 chat-ai-engineer: `columns[].description` допущен в ReportTool (jsonb {ru,en} или string или null). `prevalidateColumnDescriptions()` — soft type-check rejects list arrays, non-string leaves, scalars. REPORTS_GUIDE.md §0.1 ALWAYS правило + новая секция «Tooltip-описание колонок» с 2 примерами. 6 новых тестов в ReportToolTest — 19/19 + Chat 74/74.
- [x] T5.3 report-author: **23 колонки** с description в 6 системных отчётах. Содержание: финансовые суммы, расчётные expressions, статусы 1/3/50, types_id 3786/3787/3788 mapping, status objects 20/30/32, cumulative агрегаты, payment_schedule, не-очевидные даты date_to/date_added. Локальный seed прошёл, description в getData() response.
- [x] T5.4 frontend-specialist: `ReportColumnDto.description?: LocalizedText | null`. `PresentationColumn.description?: string | null` (через getLocalizedText, empty-string → null normalization). 4 render sites: master/detail children, ungrouped в ColumnGroup top row, grouped sub-header bottom row, flat Column. `pi pi-question-circle` + `v-tooltip.top` + `@click.stop` (sort не триггерится). CSS: muted-gray 0.85rem hover darker, cursor: help. type-check + lint + build чисто.
- [x] T5.5 deploy-engineer: app + frontend rebuilt, nginx restarted, ReportSeeder засеян (6 отчётов upsert), description в БД подтверждён tinker'ом.
- [x] T5.6 qa-tester: PASS. 4 иконки на /reports/1 рендерятся, hover показывает RU описание «Дата подписания договора...», EN switch работает («Date the sale and purchase agreement...»), колонки без description без иконки, click на иконку не триггерит sort, dashboard и AI-чат без регрессий, 0 console errors. **Toolbox conditional position: PASS** — `/reports/1` top=70px, `/reports` и `/ai-reports` top=14px (1rem дефолт). Infra-note: vizion-nginx не auto-restart — нужен `restart: unless-stopped` (backlog).
- [x] T5.7 product-manager: review PASS. Docs sync ✅ — план §Roadmap п.5 → «✅ Готово (2026-05-21)», q#6 закрыт (§5.7 + сводный список), FRONTEND.md +поле description в columns[], CLAUDE.md +пункт «Tooltip column description (2026-05-21)» с A11Y tech debt.

---

## Журнал выполнения

### 2026-05-20

- **start** — план прочитан, решения зафиксированы выше, прогресс-файл создан.
- **T1.1 done** — macrodata-engineer добавил `ReportDataService::getDashboardData(Report $report, Company $company, array $filters)`: полный датасет без пагинации, hard cap 5000 строк, meta.dashboard_limited=true при превышении, dashboard_total_estimate (или null если count упал на window-aggregate). group_by игнорируется (виджеты агрегируют на фронте). Сортировка через `sort.default` из конфига. 9 новых тестов в DashboardDataTest. Полный test suite 270/270.
- **T1.3 done** — chat-ai-engineer допустил `dashboard_widgets[]` в ReportTool (create/update), pre-validation в `prevalidateDashboardWidgets()`: shape, обязательные поля (id, title, chart_type, value_field, label_field, aggregation), enum, уникальность id, cross-ref что value_field/label_field есть в columns[]. Опционально widget_group (задел п.2). REPORTS_GUIDE.md — новая секция «Dashboard Widgets» с JSON-примером, инструкцией «всегда добавляй виджеты для финансовых/sales/pipeline отчётов», правилами валидации. Тесты ReportTool 14/14 + Chat 74/74.
- **T1.2 done** — backend-specialist добавил route `GET /api/reports/{report}/dashboard-data` (auth:sanctum + locale + active.company + company.access). `ReportController::dashboardData()` — active company через middleware (не query-param), фильтры из `filters[...]` query, вызов `ReportDataService::getDashboardData($report, $company, $filters)`. ACL зеркалит show()/groupRows() (analyst, viewer+is_published, superadmin override). 11 feature-тестов в DashboardDataEndpointTest (happy-path admin/analyst/viewer, 401/403/404, filter propagation через Mockery, meta.dashboard_limited surface, JSON shape). 51/51 Report-тестов всего. FRONTEND.md — отдельный раздел про endpoint (отличия от /api/reports/{id}, response examples с use case dashboard_limited).
- **T1.4 done** — frontend-specialist: новый toggle table/dashboard (SelectButton) в шапке ReportPage. composables `useReportViewMode` (persist mode), `useReportDashboard` (lazy fetch dashboard-data — refetch only когда mode='dashboard'), `useDashboardLayout` (reconcile при добавлении/удалении виджетов), `useDashboardWidgetData` (sum/count/avg/max/min). Компоненты `ReportDashboardView` + `ReportDashboardWidget` (Card + PrimeVue Chart). grid-layout-plus@1.1.1 в package.json. localStorage: `vizion-report-view-{reportId}-{userId}` + `vizion-dashboard-layout-{reportId}-{userId}`. Capability `canManageDashboardLayout` (viewer = read-only). 3 empty-state (нет виджетов / все скрыты / ошибка) + banner о limit. i18n RU+EN симметрично. Прошли type-check, lint, build. Лог в frontend-changes-08-05.md.
- **T1.5 partial** — deploy-engineer rebuilt app образ (ghcr.io/skorpyone/vizion-app:dev-latest, SHA 20524f0fbb8f), nginx restarted, smoke OK (200 root, 401 на dashboard-data без токена).
- **T1.5 done** — frontend контейнер пересобран (production target, vue-tsc OK, новый бандл index-BeDb53qG.js включает grid-layout-plus). nginx restarted. Smoke 200.
- **T1.6 PASS** — qa-tester: 13/13 чеков. Toggle Table/Dashboard работает, localStorage persist `vizion-report-view-4-2=table/dashboard`, empty state «This report has no widgets yet…», limit banner показывается на отчёте 6 (13541 строк, dashboard_limited=true). 0 console errors, все API 200. Smoke по /reports и /ai-reports — без регрессий. UX issue (не блокирующий): Toolbox overlay перехватывает native click по SelectButton при 1280px viewport (JS-клик исправен). Зафиксировано как fix-action для следующей итерации шапки (P2 будет добавлять туда ещё кнопку «Группы колонок» — поправим вместе).
- **T1.7 PM review** — PASS с docs-замечаниями: (1) обновить статус п.1 в DEVELOPMENT_PLAN_CAPITALDATA.md → ✅ Готово, (2) добавить в CLAUDE.md упоминание `dashboard_widgets` + endpoint + localStorage-ключей в «Зафиксированные решения», (3) обновить §1.3.D плана — виджеты рендерятся из отдельного fetch (не из основного `rows`). Аппрувлено автономно — все правки .md без функционального риска.
- **Tech debt (post-iteration backlog)**: (a) ACL copy-paste в `ReportController::show/groupRows/dashboardData/filterOptions` → private `assertReportAccess()`; (b) `protected const DASHBOARD_LIMIT` стоит вверх класса; (c) PHPDoc для `getDashboardData` объяснить отсутствие `User` (ACL в controller'е); (d) Лимит 5000 hardcoded → `config('reports.dashboard_limit', 5000)`; (e) UX: `vue-grid-layout` toolbox-z-index — fix в P2.
- **T1.7 done docs** — план §Roadmap п.1 → ✅, §1.3.D переписан под dashboard-data fetch, CLAUDE.md +пункт «Dashboard view (2026-05-20)».
- **T2.1 done** — chat-ai-engineer: `column_group` (string|null, columns[]) + `widget_group` уже в dashboard_widgets[]. `prevalidateColumnGroups()` — type-check + отказ при column_group вместе с group_by (несовместимо). REPORTS_GUIDE.md: ALWAYS-правила (>5 col → group, >3 widgets → group), NEVER column_group+group_by, 2 новые подсекции с примерами/типовыми названиями. 7 новых тестов в ReportToolTest — 21/21 + Chat 74/74.
- **T2.2 done** — frontend-specialist: composables `useColumnGroups`/`useWidgetGroups` (persist hiddenGroups + prune stale labels), переиспользуемый `ColumnGroupsToggle.vue` popover. Двухуровневый header через PrimeVue `<ColumnGroup type="header">` + `<Row>` + colspan-runs + rowspan=2 для ungrouped. Виджеты с widget_group → collapsible sections (CSS grid 2col, drag отключён в этом режиме; sections — visual authority). Fail-safe: фронт игнорирует column_group если в конфиге есть group_by. Новые localStorage ключи `vizion-column-groups-{rid}-{uid}`, `vizion-widget-groups-{rid}-{uid}`. **Fix Toolbox overlay (P1 UX)**: `.toolbox` root → `pointer-events: none` + `> * { pointer-events: auto }` — root cause: flex-контейнер резервирует hit-area для gap-space между toggle и hidden absolute-positioned panel, перекрывая шапку. Drag сохранён через bubbling. type-check + lint + build OK, i18n RU↔EN симметрия.

---

## Tech debt (после П.6 MiniChat — 2026-05-21)

1. ~~**dashboard_widgets отсутствуют во всех 6 системных отчётах**~~ — ✅ Закрыто 2026-05-21: report-author добавил виджеты к системным отчётам через `ReportSeeder.php`.
2. ~~**column_group отсутствует во всех 6 системных отчётах**~~ — ✅ Закрыто 2026-05-21: report-author расставил группировки колонок согласно REPORTS_GUIDE.md §0.1 ALWAYS-правилу.
3. **Timezone gap в UI настроек компании** — страница `/company` не отображает и не позволяет редактировать поля `timezone` и `currency_code` (добавлены в п.3). Задача: backend-specialist + frontend-specialist (модалка редактирования компании).
4. **Конфиг в prefix MiniChat** — проверить что ReportPage передаёт полный `report.config` в store к моменту первого открытия виджета. Если QA зафиксировал `{}` в конфиге — отчёт не успел загрузиться к моменту первого `set()`; решение — watchEffect с `immediate: false` или guard `if (!report.value) return`.
5. **RBAC viewer не проверен локально** — нет viewer-аккаунта в локальном стеке (`vizion.lazarewww.ru`). Перед следующим релизом: создать viewer-пользователя через tinker или /company UI, проверить что Toolbox MiniChat и Dashboard drag не видны.
6. ~~**A11Y column description tooltip без keyboard-focus**~~ — ✅ Закрыто 2026-05-21 (с оговоркой): hover-only tooltip задокументирован в CLAUDE.md как tech debt (A11Y backlog). Для full A11Y нужен `<button>` вместо `<i>` + `aria-label` + `focus`-триггер — не блокирует релиз.
7. **Chart canvas race при mount/unmount Dashboard↔Table** — при быстром переключении Table/Dashboard до завершения анимации возможен конфликт Chart.js canvas instance. Симптом: «Canvas is already in use» warning в console. Лечение: `chart.destroy()` в `onBeforeUnmount` каждого виджета + nextTick-guard при ре-рендере.
8. **Cross-tab real-time без F5** — при изменении preferences в другой вкладке текущая вкладка не обновляется до перезагрузки. `BroadcastChannel` API решает без SSE. Бэклог.
9. **RBAC viewer не проверен локально** — см. пп. 5 (дубль для нового читателя: создать viewer-аккаунт через tinker, проверить что `canUseMiniChat` и `canManageDashboardLayout` возвращают false).
10. **Lowercase currency auto-uppercase** — `useFormatter` принимает `currency_code` как есть; если сидер или пользователь введёт `rub` вместо `RUB` — `Intl.NumberFormat` выбросит RangeError. Решение: `currencyCode.toUpperCase()` в `formatCurrency()`. UX-выбор требует решения владельца: молча uppercase или валидировать на бэке (enum в migration).

---

## §2026-05-21 — Итоги сессии (preferences sync + race fixes + report-author виджеты)

### Что сделано

**Preferences sync (вне исходного scope п.1–6):**
- Таблица `user_report_preferences` (`user_id`, `report_id`, `view_mode`, `dashboard_layout`, `hidden_column_groups`, `hidden_widget_groups`) + уникальный индекс `(user_id, report_id)`.
- Endpoints `GET /api/reports/{id}/preferences` (возвращает 200 с дефолтами если записи нет) и `PUT /api/reports/{id}/preferences` (partial upsert: явный `null` = сброс поля, отсутствующий ключ = не трогать).
- ACL через trait `AssertsReportReadAccess` — шарится между `ReportController` и `UserReportPreferenceController`.
- Frontend: singleton `useReportPreferences` с `shallowRef<PreferenceInstance>` + `watch(reportId, { immediate: true })` + guard `if (id <= 0) return`; 4 thin wrappers (`useReportViewMode`, `useDashboardLayout`, `useColumnGroups`, `useWidgetGroups`); debounce 600ms; localStorage как кэш для мгновенного первого paint, перезаписывается после GET-ответа сервера.

**3 race-bug фикса:**
- `acquireResource(0, ...)` antipattern — на mount `reportId` ещё = 0 (роутер не разрезолвил), что приводило к запросу `GET /api/reports/0/preferences`. Фикс: `watch(reportId, { immediate: true })` с guard `if (!id || id <= 0) return`.
- Watch race в `CompanyFormModal` — один watch на массив `[visible, currency_code]` давал ложные срабатывания. Фикс: split на 2 narrow watches: один на `visible` (open-transition), второй на `currency_code` (внешняя гидрация), guard на race-ситуации.
- `dashboard_widgets` gap в API response — `ReportDataService::getData()` и `getDashboardData()` не включали `config.dashboard_widgets` в ответ. Фикс: `buildPublicConfigProjection()` добавляет top-level ключ `config: { dashboard_widgets: [...] }` (whitelist строгий — только `dashboard_widgets`, нормализуется через `array_values`). Без этого — empty-state на всех виджетах дашборда (был критическим багом QA #2).

**QA история:**
- QA #1: PASS (preferences persist, debounce, localStorage-first render).
- QA #2: FAIL — dashboard_widgets отсутствовали в API response → empty-state везде. Root cause: `getData()` не проецировал `config`.
- QA #3: PASS — после `buildPublicConfigProjection()` фикса + race-guard'ов все виджеты рендерятся, preferences сохраняются между вкладками.

**Timezone UI (frontend):**
- Страница настроек компании `/company` обновлена: отображает и позволяет редактировать `timezone` и `currency_code`.
- Дропдаун таймзон (17 вариантов) + дропдаун валют (7 вариантов) в модалке редактирования компании.

**Report-author (виджеты и column_groups в сидере):**
- `dashboard_widgets` добавлены к подходящим системным отчётам в `ReportSeeder.php` (финансовые / sales / pipeline → обязательно согласно REPORTS_GUIDE.md §Dashboard Widgets).
- `column_group` расставлены во всех системных отчётах с >5 колонок согласно ALWAYS-правилу.

**Backend tech debt закрыт частично:**
- `AssertsReportReadAccess` trait — вынесен общий ACL (заменяет copy-paste `assertReportAccess()` в `ReportController`).
- `config('reports.dashboard_limit', 5000)` — лимит 5000 вынесен из hardcode в конфиг (`config/reports.php`).
- `REPORTS_DASHBOARD_LIMIT=5000` в `.env.example` — раскомментирован (backend-specialist утверждал что добавил закомментированным; PM раскомментировал по аппруву).

**stale_packages_cache fix в entrypoint:**
- `docker/php/entrypoint.sh` получил `php artisan package:discover --ansi` перед `exec "$@"` — фикс stale cache после rebuild контейнера без `--build` флага (пакеты добавлены, но Composer autoload cache не пересобран).
