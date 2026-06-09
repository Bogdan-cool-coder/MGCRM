# Frontend audit (08-05 → 16-05-2026)

Read-only ревью изменений за время отпуска. Каждое замечание ссылается на путь и
строку — точечно фиксируем расхождения с концепцией `front/src/`, найденные при
сравнении с тем, как сделано всё остальное.

Условные обозначения:

- ❌ — концептуальное расхождение / архитектурный red flag. Стоит исправить
  до возвращения, иначе ляжет в legacy.
- ⚠️ — мелкое замечание / nice-to-have. Можно отложить, но лучше зафиксировать.
- ✅ — сделано в духе кодовой базы, отмечаю явно.

---

## 1. PaymentScheduleCell + footer totals

**Что трогалось:**
- `front/src/pages/ReportPage/components/PaymentScheduleCell.vue` (NEW)
- `front/src/pages/ReportPage/composables/useReportPresentation.ts`
- `front/src/pages/ReportPage/index.vue`
- `front/src/assets/styles/_payment-schedule-footer.scss` (NEW)
- `front/src/assets/styles/main.scss`
- `front/src/pages/ReportPage/locale/{ru,en}.json`

**Концептуально:** в духе фронта. Cell-компонент уехал в
`pages/ReportPage/components/` — корректно (это page-specific renderer, общим
он не станет). `useLocalI18n({ en, ru })` для локализованного namespace —
эталонный паттерн (используется в `LoginPage`, `AiChatPage`, остальных).

**Findings:**

- ✅ Date-parsing `YYYY-MM-DD` руками без `new Date(string)` — соответствует
  канону из `[[date-no-iso]]` (DateRangeFilter повторяет тот же подход).
- ✅ Сам cell корректно отделён от формат-пайплайна: `resolveColumnType` для
  `'payment_schedule'` → `'string'`, чтобы `useFormatter` не лез в объект, а
  template ловит `rawType === 'payment_schedule'` и рендерит компонент. Это
  чистое решение — никакого магического обхода `format()`.
- ⚠️ Глобальный SCSS `_payment-schedule-footer.scss` импортирован из
  `main.scss` с именем `payment-schedule-footer` (без подчёркивания) —
  `@use '@/assets/styles/payment-schedule-footer'`. По Dart Sass это
  корректно (partial с `_` префиксом адресуется без префикса), но в комментах
  внутри `index.vue` (строки 1034–1038) и в журнале именован с
  подчёркиванием. Не баг — просто читабельность. Тут менять ничего не нужно,
  flag для Жени, чтобы не запутался при чтении.
- ⚠️ `_payment-schedule-footer.scss:29` — селектор `.p-datatable-tfoot tr:has(.ps-footer-row) > td`
  накладывается глобально на любую страницу с DataTable. Это **намеренно**
  (`:has()` сам себя scopes — иначе не будет цеплять td-padding). Но если
  завтра появится второй отчёт с своим payment-schedule-like footer, нужно
  будет помнить о коллизии классов. Документировать в `[[absolute-footer-pattern]]`.
- ⚠️ `PaymentScheduleCell.vue:122` — `min-width: 24rem` magic number с
  комментом обоснования. Лучше бы вынести в SCSS-токен (например
  `$payment-schedule-cell-min-width`), но это nit. Сейчас читается нормально
  благодаря коммент-блоку.
- ⚠️ ✅ FIXED 2026-05-17 — `useReportPresentation.ts:214–240` — `footerCells` computed ловит
  `rawType === 'payment_schedule'` через прямой строковый литерал. В
  `PresentationColumn.rawType` хранится `col.type` сырьём (без типизации
  enum). Если бэк когда-нибудь поменяет имя типа — отвалится молча. Не
  блокирует, но `const PAYMENT_SCHEDULE_TYPE = 'payment_schedule' as const`
  + использовать константу в обоих местах (template + composable) — было бы
  чище. Сейчас литерал встречается в **5 местах** (template `index.vue:176,
  179, 269, 272`, composable `useReportPresentation.ts:217, 421`).
- ❌ ✅ FIXED 2026-05-17 — `useReportPresentation.ts:298` — `linkRefs` computed корректно обрабатывает
  flat-rows для link-колонок (использует `buildLabelLinesLabel` и
  `resolveLabelFallback`), но в `index.vue:552–610` для grouped children
  определена **полностью дублирующая** функция `getChildLinkRef` с
  собственными вариантами `buildLabelLinesLabel` (inline 567–590) и
  `resolveChildLabelFallback` (543–550). Это явное расхождение с принципом
  «логика — в composable, компонент — тонкий». Концептуально это должно
  быть **одной** функцией в `useReportPresentation.ts`, например
  `buildLinkRef(col, row, locale, crmUrl): LinkRef`, и оба места (flat
  `linkRefs.value[colIndex]` и grouped `getChildLinkRef`) звали бы её. Ровно
  тот же баг — расхождение в `buildLabelLinesLabel` (composable) vs
  inline-вариант в `index.vue` (572–590). Если завтра кто-то поменяет
  fallback-логику только в одном месте — grouped и flat начнут показывать
  разные label'ы. **Рекомендую переделать перед merge'ом.**

---

## 2. AsyncSelectFilter

**Что трогалось:**
- `front/src/components/filters/AsyncSelectFilter.vue` (NEW)
- `front/src/entities/report/filters.ts`
- `front/src/entities/report/index.ts`
- `front/src/api/reports.ts` (fetchFilterOptions)
- `front/src/components/filters/locale/{ru,en}.json`

**Концептуально:** в духе фронта. Компонент в `components/filters/`,
тип-конфиг в `entities/report/filters.ts`, API-метод в `api/reports.ts`,
локализация рядом с компонентом — всё на местах.

**Findings:**

- ✅ Типы `FilterDefault*` чётко документированы (комменты в
  `entities/report/filters.ts:30–56`), `buildDefaultFilters` helper покрывает
  все типы фильтров — корректное место (entity-слой, не component).
- ✅ Single-flight на дебоунс через `debounceTimer` + cleanup в
  `onUnmounted` — правильно для async input'а.
- ⚠️ ✅ FIXED 2026-05-17 — `AsyncSelectFilter.vue:140–166` — `loadOptions` использует прямой
  `pending` ref и try/catch, **не** через `useAsyncResource`. Это
  несоответствие с эталоном (`useReportPageData` использует
  `useAsyncResource<ReportItem | null>`). Race-condition тоже не закрыт:
  если debounce-таймер сработал и пользователь сразу переоткрыл dropdown,
  два `loadOptions` могут гоняться. `useAsyncResource` (через `requestGate`)
  закрыл бы это «бесплатно». Не блокер — нынешний код работает, но
  отрицает паттерн `composables/async/useAsyncResource`. Подумать о
  миграции.
- ⚠️ `AsyncSelectFilter.vue:228` — функция `label` называется так же, как
  HTML-`<label>`, легко перепутать при чтении. Переименовать в
  `displayLabel` (только в этом компоненте) было бы яснее.
- ⚠️ `AsyncSelectFilter.vue:226–231` — fallback на `props.field.replace(...)`
  если конфиг не дал `label`. Та же логика дублирована в
  `DateRangeFilter.vue:122–128` и `FilterPanel.vue` — кандидат на helper
  `formatFilterFieldLabel(field, config)` в `components/filters/`. Не блокер
  (3 строки кода), но для будущего консумера фильтров.
- ⚠️ `AsyncSelectFilter.vue:283–298` — два scoped `:deep()` правила на
  `.p-select-label` / `.p-multiselect-label`. PrimeVue v4 классы могут
  меняться от версии к версии — лучше внести в `theme/` или
  `assets/styles/primevue-responsive` (там уже есть такие правки), чем
  scoped per-component. Но это nit, паттерн `:deep` для PrimeVue классов в
  проекте уже встречается (например ReportPage `:deep(.p-button)`).
- ❌ ✅ FIXED 2026-05-17 — `entities/report/filters.ts:113–122` — `AsyncSelectFilterConfig` имеет
  `async?: boolean` (комментарий: «always true for async_select»). Поле
  бессмысленное — type-tag `'async_select'` уже несёт эту информацию.
  Удалить — иначе встаёт вопрос «а можно ли `async: false` при type
  `async_select`?». Сейчас игнорируется бэком (`isReportFilterConfig`
  guard его не проверяет), но загромождает контракт.

---

## 3. Grouped reports — lazy drill-down

**Что трогалось:**
- `front/src/pages/ReportPage/composables/useReportGroupDrillDown.ts` (NEW)
- `front/src/pages/ReportPage/composables/useReportPresentation.ts` (guard `isGroupRow`)
- `front/src/pages/ReportPage/index.vue` (expansion-слот)
- `front/src/api/reports.ts` (`fetchGroupRows`)
- `front/src/api/types/reports.ts` (`ReportGroupRowDto`, `GroupRowsResponseDto`, `GroupRowsMetaDto`)
- `front/src/entities/report/types.ts` (`ReportGroupRow`)

**Концептуально:** в духе фронта. Per-group state живёт **в composable**
(`useReportGroupDrillDown`), не в Pinia — корректно: это страница-local
state, не глобальный.

**Findings:**

- ✅ Сброс всех group-states на `resetAllGroupStates()` при изменении
  фильтров/сорта — корректное освобождение stale cache.
- ✅ `onRowCollapse` удаляет state записи целиком — это разумно: при
  повторном развороте всегда свежий fetch, фильтры могут измениться пока
  группа закрыта.
- ⚠️ ✅ FIXED 2026-05-17 — `useReportGroupDrillDown.ts:77–84` — error-обработка достаёт `status`
  через ручной cast `err as { response?: { status?: number } }`. В проекте
  есть `getApiErrorStatus(error)` в `utils/errors.ts` (используется в
  `stores/companies.ts:115`). Использовать его — однообразнее и
  type-safer.
- ⚠️ `useReportGroupDrillDown.ts:70–71` — `response.rows as ReportTableRow[]`
  обходит entity-mapping. По договору `entities/report/mappers.ts` сейчас
  делает только `mapReportDtoToReport`, и для отдельных строк маппера
  нет — это окей. Но cast выглядит подозрительно; обернуть в helper
  `mapGroupRowsResponse(...)` в `entities/report/` было бы чище. Не
  обязательно сейчас.
- ⚠️ `useReportGroupDrillDown.ts:46` — `loadGroupRows(groupKey, page = 1)`
  ловит конкурентные fetch'и через флаги `state.loading` / `state.loadingMore`.
  Но если изменился фильтр **во время** load — старый запрос всё равно
  закончится и запишет stale `rows`. Поскольку `resetAllGroupStates()`
  чистит `groupStates.value = {}` синхронно в watch'е, а старый запрос
  попадёт в уже отброшенный объект, гонка не визуализируется (объект
  потерян). Так что фактически чисто, но опять — паттерн
  `useAsyncResource` + `requestGate` сделал бы это явно. Сейчас это
  «случайно правильно» через ref reassignment.
- ✅ Endpoint endpoint contract (без `?company_id=`, query-params для
  фильтров / sort) — соответствует FRONTEND.md.

---

## 4. Active company server-side switch

**Что трогалось:**
- `front/src/api/companies.ts`
- `front/src/api/reports.ts`, `front/src/api/users.ts` (выпил `?company_id=`)
- `front/src/api/types/users.ts`
- `front/src/entities/user/{types,mappers}.ts`
- `front/src/services/CompanyService.ts`
- `front/src/stores/companies.ts`
- `front/src/components/Company/CompanySwitcher.vue`
- `front/src/application/session/sessionCoordinator.ts`
- `front/src/locales/{ru,en}.json`
- `front/src/mocks/data.ts`

**Концептуально:** в основном в духе фронта, но **store берёт на себя
лишнее**. Логично, что Pinia держит local-state + single-flight; нелогично,
что store **сам решает какой i18n-ключ показать** и **сам вызывает
notificationCenter**. По концепции `application/` (coordinators) такие
side-effects живут в session/auth-coordinator. Стор должен только
вернуть `false` + `error`, а компонент / coordinator решает что показать.

**Findings:**

- ✅ Server-side single-flight через `isSwitching` flag — корректно. Защита
  от double-click + race между табами.
- ✅ `setActiveCompanyLocal` rename (бывший `setActiveCompany`) + комментарий
  «use ONLY for internal flows» — отличный rename, ясно отделяет server-side
  flow от локального reconcile.
- ✅ `getPreferredCompanyId()` в `sessionCoordinator.ts:91–93` — server >
  persisted; правильный приоритет.
- ❌ ✅ FIXED 2026-05-17 — `stores/companies.ts:114–145` — error-handling в store. Store знает
  про `i18n.global.t`, `i18n.global.te`, `notificationCenter`, status-code
  → key mapping. Это смешение слоёв. По концепции
  `application/notificationCenter` живёт сверху, а stores — данные. Должно
  быть так:

  ```ts
  // в store
  async switchActiveCompany(id): Promise<{ ok: true } | { ok: false; status: number | null }> {
    // ... try { return { ok: true } } catch (e) { return { ok: false, status: getApiErrorStatus(e) } }
  }

  // в CompanySwitcher / coordinator
  const result = await companiesStore.switchActiveCompany(id)
  if (!result.ok) notifyCompanySwitchError(result.status)
  ```

  Чтобы не плодить апи store'а — компромисс: вынести `notifyCompanySwitchError(status)`
  в `application/session/` (новый `companySwitchNotifications.ts`), и
  store вызывает его. Сейчас store **импортит `@/plugins/i18n` напрямую**
  — это первый прецедент в проекте (ни один другой store так не делает,
  проверял `grep`). Это плохой trend, его лучше остановить сейчас.

- ❌ ✅ FIXED 2026-05-17 — `stores/companies.ts:10` — `import { notificationCenter } from '@/application/notificationCenter'`
  через **deep path**, в то время как принятый паттерн — `@/application`
  barrel (см. `composables/useNotifications.ts:2`, `components/base/AppNotifications.vue:9`).
  Минор, но если когда-то поменяется внутренний layout `application/` —
  сломается только это место.

- ⚠️ `stores/companies.ts:121` — `console.error(...)` гарденено
  `import.meta.env.DEV`, и это правильный способ. Но в проекте на
  такие случаи есть устоявшийся pattern `if (import.meta.env.DEV) console.warn(...)`
  (см. `entities/user/mappers.ts:16`). У нас тут `console.error`, что в принципе
  норм для unexpected failure. Не блок.

- ⚠️ `CompanySwitcher.vue:139–151` — `selectCompany` сначала закрывает
  popover (`closePopover()`), затем `await switchActiveCompany(id)`. На
  спринтер UX это хорошо («оптимистично», popover мгновенно скрывается);
  но если switch упал, селектор остаётся на старой компании (это
  корректно), а пользователь не видит индикатора процесса — toast прилетит,
  но эфемерно. Можно подумать о disabled-state на самой кнопке
  CompanySwitcher (через `:loading="isSwitching"`), но это nit. Сейчас
  только пункты дропдауна получают `is-disabled` — а сам popover уже
  закрыт. Минимально приемлемо.

- ⚠️ ✅ FIXED 2026-05-17 — `api/reports.ts:34` / `api/users.ts:18` — параметр `_companyId?: number`
  оставлен «как scope-key для `useScopedResource`». Это работает, но
  читается странно — `_`-префикс обычно означает «unused». Лучше было бы
  сменить сигнатуру: убрать параметр из API-метода вообще, а triggers
  в `useScopedResource` дёргать через `scope: () => companyId.value` (это
  уже и так делает scope в `useScopedResource`). Реактивность scope-key
  держится через `composables/async/useScopedResource`, а API-метод
  не должен иметь dummy-параметра «just for reactivity». Сейчас комментарий
  объясняет, но это всё равно концептуальная неряшливость.

- ❌ ✅ FIXED 2026-05-17 — `mocks/data.ts:327–331` — `active_company` стуб (`{ id: 1, name: 'Mock Company', is_system: false }`) **не соответствует** структуре `CompanyDto`, у которого optional `crm_url`, `macrodata_*` поля.
  Type-check проходит, потому что они optional. Но смысловая дыра: моки
  стали врать — `active_company` теперь полноценный объект на бэке, а в
  моке это огрызок. Если MSW начнут использовать в тестах
  `CompanySwitcher` или `useReportLink` (через `crmUrl`) — баг будет
  плавающий. Тривиальный фикс: переиспользовать существующий моковый
  Company объект (если он есть в `data.ts`) или хотя бы прописать
  `crm_url: null` явно. Сейчас имитация неполная.

---

## 5. AI-chat: markdown render, action-marker CTA, axios timeout 600s

**Что трогалось:**
- `front/src/utils/markdown.ts` (NEW)
- `front/src/components/chat/ChatMessageBubble.vue`
- `front/src/components/chat/ChatMessageList.vue`, `ChatPageShell.vue`
- `front/src/pages/shared/useChatPage.ts`
- `front/src/pages/AiChatPage/composables/useAiChatPage.ts`
- `front/src/pages/AiChatPage/index.vue`
- `front/src/stores/chats.ts`
- `front/src/api/chats.ts`

**Концептуально:** в духе фронта. In-house markdown без deps — соответствует
проектной политике. Action-marker распарсивается в utils, рисуется в bubble,
event пробрасывается до `useChatPage`, который владеет router-навигацией и
сетевой логикой — корректное разделение.

**Findings:**

- ✅ `utils/markdown.ts` — безопасный escape, whitelist для href (http/mailto),
  выносит inline-code до bold/italic трансформов через placeholder. Тестопригодно,
  без внешних deps. Качественная in-house реализация.
- ✅ `pendingFirstMessage` в `stores/chats.ts` — корректное use-of-Pinia
  (one-shot payload, переживающий route navigation, **не персистится**).
  `consumePendingFirstMessage(chatId)` атомарно читает + чистит — чисто.
- ✅ `ChatMessageBubble.enableActionMarker?: boolean` (default false) +
  passthrough через `ChatMessageList` / `ChatPageShell` — расширение API
  компонента без сломанной обратной совместимости.
- ❌ ✅ FIXED 2026-05-17 — `pages/shared/useChatPage.ts:135–155` — `handleActionMarker` зовёт
  **напрямую** `chatService.createChat('report_generation')` + ручной
  `chatsStore.prependChat` + `chatsStore.setActive`. Все эти три действия
  уже выполняет `chat.createAndOpenChat` (см. `composables/useChatQueries.ts:51–62`).
  Получается дублирование canonical-flow. Разница только в том, что
  `createAndOpenChat` ещё пишет `currentChat.value = chat`, что нам в
  source page (`AiChatPage`) не нужно — но это можно решить флагом
  `setCurrent: false` либо отдельной helper-функцией в `useChatQueries`.
  В нынешнем виде — два разных пути создания чата, которые **могут
  разъехаться** (например, если в `createAndOpenChat` добавят
  optimistic-state). Рекомендую DRY.

- ⚠️ ✅ FIXED 2026-05-17 — `pages/shared/useChatPage.ts:55–75` — `initScope` вызывает
  `fetchChats()` (которая `setChats`, что трогает `reconcileActiveChats`),
  потом `loadChat(activeChat.value.id)`. Race-условие: между
  `chatsStore.setActive` (в `handleActionMarker`) и `fetchChats` (в
  destination), если бэк по каким-то причинам ещё не вернул свежий chat в
  list — `reconcileActiveChats` его **обнулит**. Тогда `activeChat.value`
  будет null и pending не сработает. Митигация — `prependChat` в
  `handleActionMarker` локально добавляет chat ещё до навигации, и
  `fetchChats` потом перетирает. После `setChats(...)` reconcile
  проверяет наличие `id` в новых данных — если бэк не вернул чат
  (eventual consistency), то setActive обнуляется. Edge-case, но возможный.
  Workaround — после `fetchChats` всегда **искать pendingFirstMessage по
  его собственному `chatId`**, а не по `activeChat.value.id`. Тогда даже
  если active обнулился, pending запустится. Сейчас флоу работает в 99%
  случаев — если у бэка нет лагов. Решить можно через перевернутую
  логику: `consumePendingFirstMessage()` без аргумента, тогда зовём
  `loadChat(pending.chatId)` явно.
- ⚠️ `ChatMessageBubble.vue:9` — `v-html="renderedHtml"`. Безопасно при
  условии что `renderChatMarkdown` корректно escape'ит — что и делает.
  Желательно вынести unit-тест на edge cases (`<script>`, `onerror`,
  `javascript:` schemas) в `utils/markdown.test.ts`. Сейчас никаких
  тестов нет, политика проекта — fronend тесты редкие, но **на парсер
  v-html** обычно есть. Минимум — manual QA на injection-векторы.
- ⚠️ `api/chats.ts:11` — timeout 600_000 ms (10 минут). Зафиксирован
  per-request, не глобально — это правильно. Но магическое число.
  Лучше `const CHAT_SEND_TIMEOUT_MS = 10 * MINUTE` если в проекте есть
  time-константы. Сейчас нет — nit.

---

## 6. Корневой URL `/` → `/reports`

**Что трогалось:**
- `front/src/router/routes/base.ts`
- `front/src/application/bootstrap/bootstrapApp.ts`

**Концептуально:** в духе фронта. Route-level redirect — самый
«vue-router native» способ, попадает в beforeEach естественно.

**Findings:**

- ✅ Route-redirect `{ path: '/', redirect: '/reports' }` поставлен в начале
  массива — корректно (порядок не критичен, но логически правильно).
- ✅ Удалена ветка `if (initialPath === '/')` в bootstrap — устраняет
  расхождение `getDefaultRoute(role)` vs роутер.
- ⚠️ `bootstrapApp.ts:7` — импорт `getDefaultRoute` оставлен (используется
  в iframe-ветке line 82). Это окей — функция всё ещё нужна для
  fallback. Тут чисто.
- ⚠️ Косвенное последствие: `DEFAULT_ROUTE_BY_ROLE` теперь dead-code-почти
  (используется только в iframe-fallback). Если в будущем понадобится
  per-role landing — придётся либо вернуть, либо удалить. Сейчас стоит
  отметить, что эта константа теряет смысл по мере того как `/` →
  `/reports` единое поведение. Не блок.

---

## 7. `filter_default` из metadata

**Что трогалось:**
- `front/src/entities/report/filters.ts` (типы `FilterDefault*`, `buildDefaultFilters`)
- `front/src/pages/ReportPage/composables/useReportPageData.ts`

**Концептуально:** в духе фронта. Типы дефолтов в entity-слое, helper
там же, страница вызывает helper.

**Findings:**

- ✅ `buildDefaultFilters` корректно распаковывает разные shape'ы
  (`{ value: X }` vs `{ values: [X] }` vs `{ from, to }`) в единый
  `ReportFiltersApplied` — правильное место для нормализации.
- ⚠️ ✅ FIXED 2026-05-17 — `useReportPageData.ts:96` — `await fetchReport()` рекурсивно из
  самого `fetchReport`. Это работает (второй вызов не зайдёт во второй
  if-branch потому что `originalFiltersAvailable.value` уже установлен),
  но самовызов через рекурсию вместо явного двух-этапного запроса
  читается с напрягом. Альтернатива: вынести «второй проход» в отдельный
  function (`fetchWithDefaults`) или сделать `applyDefaultFiltersAndRefetch`.
  Не баг, но усложняет debugging при будущих правках. Защита от
  бесконечной рекурсии — `originalFiltersAvailable.value` уже
  truthy на втором проходе, и второй if-branch не выполнится. Не блок,
  но чище — explicit flow.
- ⚠️ `useReportPageData.ts:97` — после применения defaults `await fetchReport()`
  делает второй запрос. Это **двойной round-trip** на load. Альтернатива:
  на первом запросе бэкенд должен принимать `?apply_defaults=true` и
  отдавать данные уже с применёнными дефолтами. Сейчас это нагрузка на API.
  Можно отложить, но flag.
- ⚠️ `entities/report/filters.ts:73–80` — `BaseFilterConfig.default?: FilterDefault`
  и **каждый** дискриминированный union-вариант **повторно** сужает
  `default?: FilterDefaultDateRange` (или подобный). Это правильное
  type-narrowing, но дублирует объявление поля в 6+ местах. Можно сделать
  generic `BaseFilterConfig<D = FilterDefault>` — но это будет
  microoptimization, читается и так.

---

## 8. Мелкие правки (объединённый блок)

### 8.1. DateRangeFilter (`dd.mm.yy` + локальная TZ)

`DateRangeFilter.vue:62–119`

- ✅ Симметричная сериализация в локальной TZ — единственный правильный
  способ для date-only. Не `toISOString()`, не `new Date('YYYY-MM-DD')`.
  Идентично подходу в `PaymentScheduleCell.formatDate`.
- ⚠️ Логика `parseDateValue` для относительных дат (`'today'`, `'-90 days'`)
  фактически unreachable из текущего UI (бэк не присылает такое), но
  оставлено как future-proof. Если останется без пользователя дольше — выпилить.

### 8.2. Overdue badge — inline-flex row

`pages/ReportPage/index.vue:986–992` (CSS `.badge-cell`)

- ✅ Простой inline-flex layout, ничего экзотического.

### 8.3. Sortable для dot-path/link cell

`useReportPresentation.ts:126`

- ✅ Снят `!col.field.includes('.')` guard — backend стал source-of-truth.
  Соответствует канону `[[frontend-trust-backend]]`.

### 8.4. Link cells: `label_fallback`, `label_lines`, brand color, empty cells

`useReportPresentation.ts:276–315`, `index.vue:280–301, 962–984`

- ❌ См. п. 1 — flat-row логика в composable, child-row логика inline в
  `index.vue` (552–610). Дублирование `buildLabelLinesLabel` и
  `resolveLabelFallback`. Тот же fix — единый helper в composable.
- ✅ `template v-if="ref.label"` для пустых ссылок — отлично, убирает
  пустые `<span>`/`<a>` из DOM.

### 8.5. Option-mapping на text-колонках

`useReportPresentation.ts:155–169`

- ✅ `resolveOptionLabel` реактивен на `locale.value` через computed,
  локализация через `entry[locale]` → `entry['en']` → first value.
  Fallback chain корректный.
- ⚠️ `useReportPresentation.ts:168` — final fallback `Object.values(entry)[0] ?? rawKey`
  возвращает первое значение объекта. Это работает но непредсказуемо для
  не-`{ru,en}` ключей. Достаточно — `'en'` всегда задан по контракту.

### 8.6. OverlayBadge на кнопке Filters

`pages/ReportPage/index.vue:47–69`

- ⚠️ ✅ FIXED 2026-05-17 — Кнопка дублируется (внутри `v-if="activeFiltersCount > 0"` и `v-else`).
  Альтернатива: всегда `<OverlayBadge :value="activeFiltersCount" :class="{ 'd-none-zero': activeFiltersCount === 0 }">`
  с CSS-trick. Сейчас две Button-копии с одинаковыми пропсами — DRY-проблема,
  но 7 строк. Не блок.
- ✅ icon-only + tooltip + aria-label — accessibility OK.

### 8.7. Vue DevTools off в prod

`vite.config.ts:9`

- ✅ `command === 'serve' ? [vueDevTools()] : []` — корректный паттерн.

---

## 9. FilterPanel.vue — мёртвый код (вне журнала, но в зоне аудита)

`front/src/components/filters/FilterPanel.vue`

- ⚠️ ✅ FIXED 2026-05-17 (удалён) — Файл **не используется ни в одном месте проекта** (поиск `grep -rn
  "FilterPanel"` находит только сам файл). При этом он **обновлён** в
  ходе изменений (импорт `AsyncSelectFilter`, ветка в `getFilterComponent`).
  Это либо забытый orphan, либо ожидаемая dead-code для будущих
  отчётов вне `ReportPage/`. Если это второе — стоит документировать; если
  первое — удалить. Сейчас он плодит конфьюжн: новичок может скопировать
  его как «эталон фильтр-панели», а на деле в `ReportPage/index.vue`
  логика рендеринга встроена напрямую (inline `getFilterComponent`).

---

## Сводка приоритетов

### Блокирующее (рекомендую исправить ДО merge / при возвращении Жени)

1. ✅ FIXED 2026-05-17 — **`useReportPresentation` vs `index.vue` дублирование link-cell logic**
   (`buildLabelLinesLabel`, `resolveLabelFallback` — два набора, flat vs
   children). Объединить в один helper в composable. — пункт 1, 8.4.
2. ✅ FIXED 2026-05-17 — **`stores/companies.ts` — i18n + notificationCenter в store.** Унести
   error-display в coordinator или helper в `application/session/`. Store
   должен возвращать `{ ok: boolean; status?: number }`. — пункт 4.
3. ✅ FIXED 2026-05-17 — **`mocks/data.ts:327` — incomplete `active_company` стуб.** Заполнить
   `crm_url: null` минимум, лучше переиспользовать существующий mock
   Company. — пункт 4.

### Желательное (приоритет средний, можно отложить)

4. ✅ FIXED 2026-05-17 — `useReportGroupDrillDown.ts` — `getApiErrorStatus` вместо ручного cast;
   рассмотреть `useAsyncResource` для race-safety. — пункт 3.
5. ✅ FIXED 2026-05-17 — `AsyncSelectFilter` — миграция на `useAsyncResource`; убрать поле
   `async?: boolean` из конфига. — пункт 2.
6. ✅ FIXED 2026-05-17 — `useChatPage.handleActionMarker` — переиспользовать `chat.createAndOpenChat`
   вместо дублирования `prependChat + setActive`. — пункт 5.
7. ✅ FIXED 2026-05-17 — `useChatPage.initScope` — fire pending message по `pending.chatId`, не
   по `activeChat.value.id` (eventual consistency защита). — пункт 5.
8. ✅ FIXED 2026-05-17 — `'payment_schedule'` литерал в 5 местах → константа. — пункт 1.
9. ✅ FIXED 2026-05-17 — `api/reports.ts` / `api/users.ts` `_companyId?` параметр — убрать из
   API-методов вообще, передавать через `useScopedResource.scope`. —
   пункт 4.

### Косметика / nit

10. ✅ FIXED 2026-05-17 — `notificationCenter` deep import vs barrel — пункт 4. (Реализовано неявно: store больше не импортит notificationCenter вообще, см. blocking #2. Все остальные внешние консумеры уже используют barrel `@/application`.)
11. ✅ FIXED 2026-05-17 — `FilterPanel.vue` — orphan dead-code? — пункт 9. (Удалён. `grep` подтвердил отсутствие консумеров. Заодно почистил FilterPanel-specific ключи `filterTitle`, `expand`, `collapse`, `apply`, `reset` в `components/filters/locale/{ru,en}.json`.)
12. ✅ FIXED 2026-05-17 — `OverlayBadge` дубль кнопки в template — пункт 8.6. (Обёрнут в `<component :is="...OverlayBadge / 'div'">` — одна `<Button>` под обоими ветками.)
13. ✅ FIXED 2026-05-17 — `useReportPageData.fetchReport` — самовызов через рекурсию — пункт 7. (Выделил `runFetch()` для одного запроса; `fetchReport()` вызывает `runFetch` дважды — initial → applyDefaults → second call. Поведение идентично.)

---

## Общая оценка уровня хаоса: **4/10**

Аргументация:

- Большинство фич **корректно расположены** по слоям (entity / api /
  composable / page / component). Архитектура `application/` не нарушена,
  никаких прямых `axios.post(...)` из компонента, бизнес-логика страниц в
  composables.
- Найдено **3 концептуально-блокирующих** расхождения (см. сводку выше)
  — это много для месяца отпуска, но **все локализованы** в новых
  файлах и **исправляются точечно**, не затрагивая остальной фронт.
- Самая крупная фича (PaymentScheduleCell) — очень аккуратна; видно, что
  итераций было 15, но финал чистый. Канонические паттерны (global SCSS
  для PrimeVue-customize, `:has()` для td-padding, нет
  `pt.root.class`-хака) **зафиксированы в коммент-блоках** — это сильно
  упростит ревью Жене.
- Дублирование link-ref логики (flat vs children) — единственная серьёзная
  архитектурная червоточина. Но она **очевидна при первом же взгляде** на
  index.vue (две почти идентичные функции рядом — 280 строк template +
  60 строк помощника); fix занимает 30 минут.
- `companies` store — взял на себя i18n + notifications, что **создаёт
  прецедент**. Если Женя начнёт переиспользовать этот паттерн в других
  stores — фронт быстро уедет от концепции `application/` как
  side-effect-сoordinator. Это важно зафиксировать сейчас.

Никаких Tailwind / Inertia / TipTap / новых deps. Никакого
`v-html="props.unsafeData"`. Никаких axios-вызовов из шаблонов. Базовые
правила соблюдены.

Заключение: на ревью с Женей выделить ~2 часа — разрулить блокирующее
(пункты 1–3), остальное можно жить с ним пока. Внутреннее качество новых
файлов в среднем выше, чем у того что они заменили (overdue badge layout,
filter button overflow hack — обоюдные апгрейды, не регрессии).
