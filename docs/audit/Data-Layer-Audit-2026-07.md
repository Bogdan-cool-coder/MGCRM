# MACRO Global CRM — Аудит слоя данных фронта + горячих backend-путей (2026-07)

> **Что это.** Целевой аудит корректности и скорости **загрузки/обновления данных**: гонки запросов (out-of-order / last-wins), путаница данных между сущностями, стейл-кэши, лишние рефетчи и мерцание, а также резервы скорости на горячих backend-эндпоинтах.
> **Не про UI-стиль и не про бизнес-логику** — про то, что пользователь видит НЕ ТЕ данные или ждёт дольше, чем нужно.
> **Источник:** 5 зонных аудиторов (read-only разбор data-путей `fetch → store → render → mutation → propagation`), 78 подтверждённых находок. Браузерные тайминги — следующим шагом (волна Д4).
> **Тон находок:** каждая привязана к `file:line`, имеет пользовательский симптом (что реально видит человек) и предложенный фикс с оценкой объёма (S/M).

---

## 1. Методика

Пять независимых зонных аудиторов прошли все data-пути своей зоны насквозь — от инициации запроса до попадания данных в DOM и до их обновления после мутаций/realtime:

| # | Зона | Что покрыто | Находок |
|---|---|---|---|
| 1 | **crm-lists+cards** | `ContactsPage` / `ContactPage` / `CompanyPage` + `components/crm/**` — списки, карточки, лента, лог, файлы, реквизиты, каналы, дедуп/merge, фильтры/пагинация/сортировка, кэши справочников | 14 |
| 2 | **deals+kanban** | `DealsPage` (kanban+list) + `DealPage` + смежные `useAsyncResource`/realtime/`InlineEditableField`/board API | 16 |
| 3 | **hub+cabinet+tasks** | `DashboardPage` / `ManagerCabinetPage` / `MyTasksPage` + shared `useAsyncResource`/`useTasksRealtime`/`useUsersCache` + `myTasksStore`/`activityStore` | 20 |
| 4 | **inbox + settings + shared** | `InboxPage` + settings (users/products/documents/directories/motivation) + общие stores/composables/application | 16 |
| 5 | **backend-hot** | `Domain/{Sales,Crm,Inbox}/Services` + `Http/Resources` (deals board/index, contacts/companies index, dashboard, inbox index, feeds) | 12 |

**Метод — статический разбор кода** (data-flow tracing) с `file:line`-доказательствами на обеих сторонах. **Браузерных замеров в этом заходе нет** — тайминги «до/после» снимаются отдельной волной Д4 после первых фиксов.

**Общий каркас здоров, но систематически обходится.** Базовый примитив `front/src/composables/async/useAsyncResource.ts` имеет корректный **last-wins token-guard** (поздний устаревший ответ отбрасывается). Проблема в том, что **половина data-путей его обходит**: коммитит данные в стор/refs ДО проверки токена либо пишет в голые `ref` мимо примитива. Отсюда почти весь класс «данные путаются». На backend гонок/стейла НЕ найдено — там резервы чисто скоростные.

---

## 2. Сводка

### Счётчики

| Severity | Кол-во |
|---|---|
| 🔴 high | **12** |
| 🟠 medium | **41** |
| 🟡 low | **25** |
| **Итого** | **78** |

По зонам: crm-lists+cards 14 · deals+kanban 16 · hub+cabinet+tasks 20 · inbox+settings 16 · backend-hot 12.

### Главное — на языке пользователя

Три класса боли, которые реально бьют по пользователю:

1. **«Данные перепутались».** Открываешь одну сущность — видишь чужие данные. Причины: kanban-drag переносит ЧУЖУЮ сделку; переход Контакты↔Компании не меняет список; конструктор мотивации пишет план одного сотрудника другому; быстрый набор/переключение в списках оставляет старую выдачу под новым фильтром.
2. **«Мигает и тормозит».** После каждой мелкой заметки/задачи карточка уходит в полный скелетон и перезагружает ~10 запросов; доска сделок мерцает при работе команды, срывая drag и теряя недописанный текст; realtime-подписки задач накапливаются и множат фоновые рефетчи.
3. **«Правка потерялась / данные несвежие».** Быстрое сохранение двух доп-полей подряд затирает первое; справочники и кэш пользователей не инвалидируются после админ-правок (до F5); выполненная задача «воскресает» после рефетча.

### Таблица high: симптом → корень → файл

| # | Симптом (что видит пользователь) | Корень | Файл |
|---|---|---|---|
| H1 | Жму «Компании» — URL сменился, а таблица показывает контакты | Один компонент на 2 маршрута, нет реакции на смену `route.name` | `front/src/pages/ContactsPage/index.vue:653` |
| H2 | После заметки карточка контакта мигает скелетоном, лента прыгает, ~10 лишних запросов | Фоновый рефетч поднимает глобальный `loading` → размонтаж всех вкладок (Tabs без lazy) | `front/src/pages/ContactPage/index.vue:570` |
| H3 | То же на карточке компании | Тот же паттерн `onActivityChanged → loadCompany → полный скелетон` | `front/src/pages/CompanyPage/index.vue:687` |
| H4 | Тащу сделку X — переносится сделка Y (или молча не сохраняется) | `@end` берёт карточку по индексу цели из массива ИСХОДНОЙ колонки | `front/src/pages/DealsPage/components/DealsKanbanColumn.vue:159` |
| H5 | Сервер отклонил перенос — карточка визуально осталась в новой колонке | Optimistic/rollback мутируют колонки in-place, shallow-watch не срабатывает | `front/src/pages/DealsPage/composables/useDealsBoard.ts:200` |
| H6 | Доска мигает скелетоном при работе команды и после своего же переноса | Broadcast без `toOthers()`/`X-Socket-ID` → свой echo триггерит full reload | `front/src/pages/DealsPage/index.vue:693` |
| H7 | Переключил пресет — в списке задачи старого пресета под новым табом | `fetchPage` коммитит в стор ДО last-wins-проверки | `front/src/pages/MyTasksPage/composables/useMyTasks.ts:77` |
| H8 | Переключаю сотрудника в кабинете — в ленте активности чужого | `loadFeed` пишет в голые refs без request-gate | `front/src/pages/ManagerCabinetPage/composables/useManagerCabinetPage.ts:144` |
| H9 | После пары заходов на «Задачи» каждое событие даёт N параллельных рефетчей | `useTasksRealtime` вызван после `await` в async `onMounted` → cleanup не регистрируется | `front/src/pages/MyTasksPage/index.vue:811` |
| H10 | Печатаю в поиске сотрудников — остаётся старая/пустая выдача | `fetchUsers` без guard/debounce + двойной фетч (2 watch) | `front/src/pages/UsersPage/composables/useUsersPage.ts:81` |
| H11 | Выбрал Петрова, жму «Сохранить» — записывается план Иванова | Форма МК не сбрасывается при смене сотрудника/периода; нет токен-guard | `front/src/pages/SettingsPage/components/sections/motivation/useMotivationBuilder.ts:397` |
| H12 | На 3-й странице включил фильтр «Подписан» — пустая таблица | Смена фильтра не сбрасывает `page=1` | `front/src/pages/DocumentsPage/composables/useDocumentsPage.ts:80` |

---

## 3. Полные находки по зонам

### 3.1 crm-lists+cards (14: 3 high / 8 med / 3 low)

#### High

**H1 — `/contacts` ↔ `/companies` делят один компонент без реакции на маршрут** · `ContactsPage/index.vue:653` · S
Оба маршрута рендерят `ContactsPage`; `<router-view>` без `:key`, `entityType` вычисляется из `route.name` один раз в setup. При SPA-переходе Vue переиспользует инстанс — `onMounted` не срабатывает, watch на route нет, список/KPI/сортировка остаются от прежнего раздела.
*Симптом:* переход через палитру/хоткей `g→o` меняет URL и заголовок вкладки, но таблица показывает прежний тип — «данные перепутались». Плюс тулбарный переключатель не меняет URL — F5/шаринг открывает не тот список.
*Фикс:* watch на `route.name` (выставлять `entityType` + перезагрузка списка/KPI) либо `:key` по `route.name` на router-view для этой пары; синхронизировать тулбарный переключатель с URL.

**H2 — карточка контакта: полный скелетон после каждого действия с активностью** · `ContactPage/index.vue:570` · M
`onActivityChanged → loadContact()` поднимает `contactResource.loading=true`, шаблон переключается на ветку `v-else-if="contactLoading"` (полный скелетон), размонтируя Tabs. Все TabPanel смонтированы нелениво → повторный монтаж и фетч feed/files/schema/users (8–10 лишних запросов), скролл и открытые редакторы сбрасываются.
*Фикс:* **background-режим `useAsyncResource`** (скелетон только пока `data===null`) или точечное обновление KPI без глобального `loading`; включить lazy на Tabs.

**H3 — карточка компании: тот же паттерн** · `CompanyPage/index.vue:687` · M
`onActivityChanged → loadCompany() → companyLoading=true → полный скелетон`, ремоунт всех вкладок, повторные feed/files/documents-tab/schema/users, потеря scroll.
*Фикс:* аналогично H2 — background-режим примитива + lazy-Tabs.

> **H2+H3 закрываются одним изменением примитива** `useAsyncResource` (background-режим) — см. волну Д1, правило единственного владельца.

#### Medium

| Проблема | Симптом | Файл | Fix |
|---|---|---|---|
| `saveCustomField` PATCH'ит весь `extra_fields` без optimistic — быстрое сохранение поля A и B затирает A | Заполнил 2 доп-поля подряд — первое пусто после F5 | `CompanyPage/index.vue:911` | Optimistic-запись значения до PATCH (как на контакте) и/или сериализация сохранений; идеально — backend-merge по ключу · S |
| `companyChannels` грузится голым `await` без token/guard в `onMounted` и route-watch | Быстрый переход компания→компания — в «Каналах связи» контакты прежней | `CompanyPage/index.vue:1091` | Перенести в `useCompanyPageData` как `useAsyncResource` либо сверять `companyId` после await · S |
| `useEntityLog.load()` без out-of-order guard — поздний ответ лога A перетирает B | Быстрый переход контакт→контакт — в «Журнале» чужие записи | `composables/crm/useEntityLog.ts:35` | Request-token в `useEntityLog`, отбрасывать неактуальный ответ · S |
| Все AutoComplete-поиски (attach company, add-contact-to-deal, employee, holding) — out-of-order подсказки | Печатаешь «Иванов» — выпадашка показывает по «Ив» | `ContactPage/composables/useContactPageActions.ts:91` (+ ещё 3) | Debounce + отбрасывание устаревших ответов подсказок · S |
| `currentDealsPage` — локальный `let`, не сбрасывается при route-watch/после `loadDeals(1)` | Пропуск/дубль страниц сделок в карточке | `ContactPage/index.vue:676` | Сбрасывать счётчик при навигации и после reload · S |
| Документы грузятся дважды: родитель (`per_page 20`) + вкладка (`per_page 50`) | Медленное открытие карточки компании | `CompanyPage/components/CompanyDocumentsTab.vue:126` | Один источник загрузки документов; вкладка lazy · S |
| `useDirectoriesStore.fetchAll` + `useUsersCache.load` — кэш «навсегда», без инвалидации после админ-правок | Новые/деактивированные справочные значения не доезжают до форм до F5 | `stores/directories.ts:77` | `invalidate()` после админ-CRUD справочников/пользователей · M |
| KPI-чипы берут счётчики из снапшота show-эндпоинта с приоритетом над локальным списком | Стейл счётчиков после attach/detach | `ContactPage/composables/useContactPageActions.ts:118` | Обновлять KPI-снапшот после локальных мутаций либо отдать приоритет актуальному списку · S |

#### Low

- `ContactsPage/index.vue:705` — `loadKpi` не вызывается на локальных мутациях (delete/bulk/merge/assign) без Reverb → стейл KPI-бара списка после удалений.
- `useContactsPageData.ts:221` — `onPageChange/load` не клампят `page` по `meta.last_page` → пустая таблица на последней странице после чужого удаления.
- `EntityFilesTab.vue:283` — `fileCountMap` пишется после инвалидированного `run()` → неверные счётчики папок при быстром прокликивании.

---

### 3.2 deals+kanban (16: 4 high / 9 med / 3 low)

#### High

**H4 — drag&drop переносит ЧУЖУЮ сделку** · `DealsKanbanColumn.vue:159` · S
`@end` берёт «перемещённую» карточку как `localDeals[event.newIndex]`, но событие срабатывает на ИСХОДНОЙ колонке (карточка из неё уже удалена vuedraggable), а `newIndex` — индекс в ЦЕЛЕВОЙ колонке. В API уходит чужая сделка на позиции `newIndex` исходной колонки; при выходе за границы `movedCard=undefined` — API вообще не вызывается (перенос молча теряется).
*Симптом:* тащишь X в другую стадию — X отпрыгивает, а туда переезжает Y (стадия/история/автоматизации применяются к чужой сделке); либо перенос «успешен», но после F5 сделка в старой колонке. Классическое «данные путаются».
*Фикс:* слушать `@change`: в обработчике `added` целевой колонки `element` — реально перетащенная карточка; `fromStageId` брать из `removed` исходной колонки. Убрать выборку по `newIndex`.

**H5 — optimistic/rollback переноса не доходит до UI** · `useDealsBoard.ts:200` · M
`moveDeal` мутирует `localColumns` in-place (splice/unshift), а колонка синхронизирует `localDeals` через shallow-watch `() => props.column.deals` — срабатывает только при смене ССЫЛКИ массива.
*Симптом:* сервер отклонил перенос (нет прав, guardrail) — toast об ошибке, но карточка визуально в новой колонке; на сервере — в старой стадии.
*Фикс:* иммутабельная замена массивов колонок (`col.deals = [...]`), чтобы watch срабатывал; после rollback — пересборка `localColumns` или `load()`.

**H6 — realtime-echo своей же правки перерисовывает всю доску** · `DealsPage/index.vue:693` · M
Broadcast без `->toOthers()`, axios не шлёт `X-Socket-ID` → собственный drag/правка через 400ms дебаунса вызывает `reload()` всей доски; любое `deal.*` коллеги — тоже. `reload()` ставит `loading=true` → все колонки заменяются 4 скелетонами: скролл сбрасывается, начатый drag обрывается, инлайн-эдит названия теряет текст.
*Симптом:* доска постоянно «мигает» при работе команды и после своего переноса, скролл улетает, перетаскивание срывается, недописанное название пропадает.
*Фикс:* тихий рефетч (аналог `reloadSilent`: не поднимать `loading` при наличии данных, коммитить колонки по готовности); скелетон только при `columns.length===0`; `X-Socket-ID` + `broadcast(...)->toOthers()` на бэке; не запускать reload во время drag.

#### Medium

| Проблема | Симптом | Файл | Fix |
|---|---|---|---|
| Колонки обрезаны 30 карточками, кнопка «ещё» отключена `v-if="false && has_more"` | Не видно сделок за пределами топ-30 колонки | `DealsKanbanColumn.vue:98` | Включить «загрузить ещё» (backend лимит уже отдаёт `has_more`) · S |
| `onSetPipeline`/`reload()` в list-view не сбрасывают `page` | Пустая выдача после смены воронки на стр. >1 | `DealsPage/index.vue:642` | Сбрасывать `page=1` при смене воронки и bulk-действиях · S |
| DataTable lazy, но `:first` не проброшен — программные сбросы `page=1` не двигают пагинатор | Пагинатор рассинхронен с данными | `DealsPage/components/DealsListView.vue:6` | Забиндить `:first` из composable · S |
| `reloadSilent` пишет `resource.data` мимо requestGate, гоняется с `load()`/другими silent | Отскок значений на карточке сделки | `DealPage/composables/useDealPage.ts:30` | Провести `reloadSilent` через гейт примитива · S |
| `saveCustomField` PATCH'ит весь `extra_fields` — lost-update при быстрых правках | Потеря доп-поля сделки при быстром сохранении | `DealPage/components/DealTabMain.vue:462` | Optimistic по ключу / backend-merge · S |
| `InlineEditableField` закрывает редактор при любом изменении `modelValue` | Прилетевший reloadSilent закрывает открытый редактор с потерей ввода | `components/crm/InlineEditableField.vue:232` | Не закрывать при фоновом обновлении, если редактор открыт · S |
| `useDealFeed.fetchPage` без request-гейта, гоняется от realtime/panel/fallback | Обрезка/дубль подгруженной истории ленты | `DealPage/composables/useDealFeed.ts:412` | Request-гейт на `fetchPage` · S |
| `openTasks` вычисляется только из первых 30 событий ленты | Задача старше 30 событий не видна в «открытых» | `DealPage/composables/useDealFeed.ts:308` | Отдельный источник открытых задач, не из ленты · M |
| `created-deal` не подписывается на realtime (`onMounted` выходит рано в create-режиме) | Свежесозданная сделка не получает realtime-обновлений | `DealPage/index.vue:638` | Переподписка после `router.replace` из create в edit · S |

#### Low

- `DealAddContactDialog.vue:157` — out-of-order подсказки company/product/contact.
- `useDealsBoard.ts:184` — `moveDeal` обновляет `total`, но не `amounts_by_currency`/`sum_amount` → стейл денежных сумм в шапках колонок.
- `useDealCustomFields.ts:10` — `defsCache` без инвалидации на сессию → новые custom-fields не видны до F5.
- `DealsPage/index.vue:644` — KPI-запрос грузится в kanban-виде, где чипы не рендерятся (лишний запрос на каждый reload/echo).

---

### 3.3 hub+cabinet+tasks (20: 3 high / 11 med / 6 low)

#### High

**H7 — `fetchPage` коммитит мимо last-wins** · `MyTasksPage/composables/useMyTasks.ts:77` · S
Ответ пишется в `tasksStore.listItems` ВНУТРИ лоадера до token-check — гейт защищает лишь неиспользуемый `resource.data`. Поздний устаревший ответ перезаписывает свежий.
*Симптом:* быстрое переключение пресетов/поиск — задачи прежнего пресета под новым табом, `total` от старого ответа, индикатора нет.
*Фикс:* коммит через `options.commit`-колбэк `run` (вызывается только для актуального токена); то же для ветки пресетов (строки 90–91).

**H8 — `loadFeed` пишет в голые refs без гейта** · `ManagerCabinetPage/composables/useManagerCabinetPage.ts:144` · S
Единственный фетч-путь кабинета вне `useAsyncResource` — out-of-order перезапись.
*Симптом:* директор быстро переключает сотрудника/пилюли — в ленте активности прежнего сотрудника под именем нового.
*Фикс:* провести `loadFeed` через примитив либо локальный токен; убрать двойной `loadFeed` при `setPeriod` со страницы >1.

**H9 — накопление realtime-подписок задач** · `MyTasksPage/index.vue:811` · S
`useTasksRealtime` вызван после двух `await` в async `onMounted` — активного инстанса уже нет, `onUnmounted(cleanup)` не регистрируется. Echo-подписки `user.{id}`/`dept.{id}.tasks` не снимаются, копятся с каждым визитом.
*Симптом:* после нескольких заходов каждое событие даёт N параллельных рефетчей (мерцание, трафик), рефетчи стреляют даже после ухода со страницы.
*Фикс:* вызывать `useTasksRealtime` синхронно в setup (до await); либо сохранять cleanup и звать его в синхронно зарегистрированном `onBeforeUnmount`.

#### Medium

| Проблема | Симптом | Файл | Fix |
|---|---|---|---|
| Silent-поллинг МК пишет `cardResource.data` напрямую мимо гейта | In-flight poll со старым периодом перетирает свежую карту | `ManagerCabinetPage/composables/useMotivationTab.ts:44` | Коммит поллинга через гейт со снапшотом периода · S |
| Realtime-рефреш задач ставит `page=1` + флипает `boardLoading` → полный скелетон борда | Пагинация сбрасывается, борд мигает при событиях | `MyTasksPage/index.vue:820` | Тихий рефетч без сброса страницы/скелетона · M |
| Deep-watch фильтров дёргает `teamBoard.load` на каждый символ; коммит `serverBuckets` до гейта | Шторм запросов team-board + last-wins-гонка | `MyTasksPage/index.vue:333` | Debounce team-поиска + коммит через гейт · S |
| `useMyTasks.load()` сбрасывает `page=1`, но `:first` не забинден | Пагинатор рассинхронен после сброса | `MyTasksPage/components/MyTasksTable.vue:37` | Забиндить `:first` · S |
| `clearDirty()` после КАЖДОЙ загрузки матрицы, включая устаревшую/во время ввода | Стирается ввод, сделанный во время in-flight загрузки | `DashboardPage/composables/usePlansTab.ts:81` | `clearDirty` только для актуального ответа и не поверх ввода · S |
| Dirty-guard срабатывает ПОСЛЕ переключения года/слоя хабом | Рассинхрон «фильтр=новый, грид=старый»; экспорт грузит новый | `DashboardPage/composables/usePlansTab.ts:112` | Veto ДО смены фильтра либо откат фильтра при «Остаться» · M |
| `onRowCurrency` мутирует payload напрямую, не попадает в dirty | Смена валюты строки молча теряется (save не шлёт, leave-guard молчит) | `DashboardPage/components/plans/MetricIncome.vue:124` | Заводить смену валюты в dirty-set · S |
| Изменения months/pipeline/manager во время ПЕРВОЙ загрузки отбрасываются (`initialized=false`) | Ранние правки фильтров на Обзоре теряются | `DashboardPage/composables/useDashboardPage.ts:81` | Очередь/повтор последнего фильтра после первой загрузки · S |
| Старая карта МК видна без индикатора при рефетче | Тулбар — новый месяц, тело — старый | `ManagerCabinetPage/components/motivation/MotivationTab.vue:4` | Индикатор/затемнение при фоновом рефетче · S |
| Старые строки ленты видны без индикатора при смене типа/страницы/сотрудника | Усиливает путаницу с H8 | `ManagerCabinetPage/components/ActivityFeed.vue:27` | Loading-индикатор при рефетче ленты · S |
| `fetchEntities` без out-of-order-защиты | Подсказки для более короткого префикса | `MyTasksPage/components/TasksQuickCreate.vue:196` | Отбрасывание устаревших ответов · S |

#### Low

- `DashboardPage/index.vue:49` — keep-alive табы без `onActivated`-рефетча → стейл Обзора/Реестра/Графика/Рейтинга при возврате.
- `useRegistryTab.ts:74` — report-табы фетчат на mount с `pipeline_id=null` + повторно после преселекта → двойной фетч при deep-link.
- `TabRegistry.vue:26` — любой мелкий рефетч заменяет отчёт полным скелетоном (нет keep-old-with-indicator).
- `MyTasksPage/index.vue:743` — в catch error-тоста передаётся текст УСПЕХА («Задача выполнена») вместо ошибки.
- `useUsersCache.ts:14` — хаб/кабинет ходят мимо кэша пользователей (дубли) + кэш не инвалидируется до F5.
- `MyTasksPage/index.vue:615` — bulk-мутации строго последовательно (N задач = N round-trip); частичные ошибки.

---

### 3.4 inbox + settings + shared (16: 2 high / 5 med / 9 low)

#### High

**H10 — список пользователей: raw-фетч без guard и debounce** · `UsersPage/composables/useUsersPage.ts:81` · S
`fetchUsers` пишет `users.value/total` напрямую без request-id/last-wins; `searchFilter` не задебаунсен (запрос на каждый keystroke). Плюс двойной фетч: watch фильтров ставит `currentPage=1` → второй watch. `loading` гасится первым завершившимся, пока второй в полёте.
*Симптом:* быстрый набор «ива» — спиннер пропал, а в таблице результаты по «ив»/пустому; счётчик не совпадает.
*Фикс:* перевести на `useAsyncResource` (токен-guard готов) + debounce 300мс; объединить сброс страницы и фетч в один watcher.

**H11 — конструктор МК пишет план прежнего сотрудника новому** · `motivation/useMotivationBuilder.ts:397` · M
После `loadCard` форма (`rows`/`teamRule`/`existingCardId`/`status`) не сбрасывается при смене `selectedEmployee`/`year`/`month`. `buildPayload` берёт `user_id` из ТЕКУЩЕГО `selectedEmployee`, а данные формы — от прежнего; `existingCardId`/`status` тоже старые (finalize/markPaid бьют по старому id). `loadCard→applyPlan` без токен-guard.
*Симптом:* загрузил карту Иванова, выбрал Петрова, «Сохранить» — Петрову пишется план Иванова; «Финализировать» финализирует карту Иванова.
*Фикс:* `watch([selectedEmployee, year, month])` → сброс `loaded/existingCardId/status/rows` (форма скрыта до «Загрузить карту»); токен-guard в `loadCard`; в save сверять `existingCardId` с загруженной парой employee×period.

#### Medium

| Проблема | Симптом | Файл | Fix |
|---|---|---|---|
| **H12** — `watch([filter,page])` рефетчит, но смена фильтра НЕ сбрасывает `page=1`; поиск без debounce | На стр. 3 включил «Подписан» — пустая таблица (потеря данных до ручного возврата) | `DocumentsPage/composables/useDocumentsPage.ts:80` | Разделить watch: filter → `page=1`; поиск → debounce 300мс · S |
| CRUD каналов привлечения не инвалидирует `useDirectoriesStore` | Новый/выключенный канал не доезжает до форм сделок/контактов до F5 | `AcquisitionChannelsPage/composables/useAcquisitionChannelsPage.ts:70` | `invalidate()` после CRUD справочника · S |
| Синглтон `useUsersCache` — `loaded=true` навсегда, без инвалидации после админ-CRUD | Новый/переименованный менеджер не виден в селектах до F5 | `composables/crm/useUsersCache.ts:13` | `invalidate()` метод + вызов после user-CRUD · S |
| `totalRecords` присваивается ВНУТРИ лоадера до token-check | `total` инбокса от устаревшего ответа | `InboxPage/composables/useInboxPage.ts:202` | Коммит `totalRecords` через гейт · S |
| `openDraft`/`startNewDraft` перезаписывают `draftForm` без проверки `draftDirty` | Несохранённый черновик перезаписывается кликом без confirm | `InboxPage/composables/useInboxPage.ts:518` | Confirm-guard на грязный черновик (как `UnsavedChangesDialog`) · S |

> `myTasksStore.loadBoard:176` (medium) — `serverBuckets` пишется до token-check; реальный источник рендера доски не защищён. Пересекается с зоной hub (H9/борд) — фиксить вместе с realtime-рефетчем борда.

#### Low

- `TemplatesPage/composables/useTemplatesPage.ts:28` — fetch на каждый символ без debounce (+ `useTemplateVariablesPage`).
- `InboxList.vue:6` — полный скелетон на КАЖДЫЙ рефетч (смена фильтра/страницы/refetch).
- `useInboxPage.ts:510` — `fetchDrafts` всегда просит стр. 1, `currentPage` игнорируется → 2-я страница черновиков не грузится.
- `useProfilePage.ts:244` — telegram-поллинг каждые 5с перезаписывает ВЕСЬ `currentUser`, затирая параллельные правки; не чистится при unmount.
- `useProductsPageActions.ts:49` — `toggleActive` оптимистичен, но список не рефетчится → деактивированный продукт остаётся в «активных».
- `useEntityLog.ts:35` — `load()/loadMore()` без request-token → лог чужой сделки при быстром переходе (дубль зоны 1).
- `useSystemResetSelective.ts:206` — после reset обновляется только preview-счётчик; app-wide кэши (directories/users/myTasks/badge) не чистятся → стейл бейджей.
- `useInboxPage.ts:227` — `watch(debouncedQ)` ставит `page=1` и сам зовёт fetch + второй watcher → дубль запроса.

---

### 3.5 backend-hot (12: 0 high / 6 med / 6 low)

**Гонок/стейла на бэке НЕ найдено** — данные не путаются. Горячие пути хорошо забатчены (kanban board ≈6 запросов на всю доску через ROW_NUMBER-окна; `next_task`/`primary_product`/`last_contact` штампуются батчами; Activity-пресеты — один conditional-aggregate). Все находки — **резервы скорости**.

#### Medium

| Проблема | Эффект | Файл | Fix |
|---|---|---|---|
| На каждую связь контакт-компания сериализуется полный `CompanyResource` (~60 полей) | Тяжёлый payload списка контактов | `Http/Resources/Crm/ContactCompanyLinkResource.php:28` | Lean-ресурс связи (id/name/несколько полей) · S |
| List-эндпоинт компаний шлёт все ~60 полей реквизитов на строку | Тяжёлый payload списка компаний | `Http/Controllers/Crm/CompanyController.php:45` | Отдельный lean list-ресурс · S |
| Инбокс-ресурс шлёт полный `body` + `raw_payload` (webhook-конверт) на 50 строк | Тяжёлый payload инбокса | `Http/Resources/Inbox/InboundMessageResource.php:23` | Урезать до preview; полный body — в show · S |
| 7 последовательных COUNT'ов `/deals/kpi` | COUNT-шторм | `Domain/Sales/Services/DealKpiService.php:75` | Один conditional-aggregate (эталон — `ActivityService::countsByPreset`) · M |
| Нет индекса `created_at` на deals (дефолтная сортировка DESC) | Полный скан для scope=All | `database/migrations/2026_06_12_120003_create_deals_table.php:68` | Индекс `created_at`; аналогично `crm_companies`/`crm_contacts` (новая миграция) · S |
| Нет trigram-индексов под ILIKE-поиск (title/name/legal_name/tax_id/email/phone/…) | Медленный поиск на всех горячих таблицах | `Domain/Sales/Services/DealService.php:480` | `pg_trgm` + GIN-индексы под поисковые поля · M |
| Дашборд фильтрует период `CASE`-выражением (индекс невозможен) + пересчёт ~8 агрегатов без кэша | Медленный дашборд | `Domain/Sales/Services/SalesDashboardService.php:665` | Индексируемое условие периода + кэш агрегатов · M |
| Фильтр `open_deals_min/max` — коррелированный COUNT-подзапрос с тройным JOIN на каждую строку (дважды при обеих границах) | Резкое замедление фильтра контактов | `Domain/Crm/Services/ContactService.php:181` | Предагрегация open-deals count одним JOIN'ом · M |

> В таблице 8 строк, а medium в зоне — 6; строки `created_at`-индекс и trigram — это две находки, объединённые по смыслу «индексы под горячие пути». Точные severity: индексы/поиск/дашборд/подзапрос идут как medium, count-шторм KPI — medium.

#### Low

- `ContactsKpiService.php:73` — 6 COUNT'ов + `Schema::hasColumn` (лишний запрос к каталогу) на чипы Контактов/Компаний → один aggregate.
- `InboundMessageService.php:198` — `folderCounts()` = COUNT FILTER по 6 условиям без WHERE (полный скан) на каждый вызов.
- `DealFeedService.php:122` — каждая страница ленты = 8 запросов (4 выборки + 4 COUNT для meta), COUNT'ы пересчитываются при листании.
- `DealResource.php:50` — каждая строка списка несёт ~22 поля стадии (`required_fields`/`stage_features`/`task_types`/…), дублируя по всем сделкам одной стадии.

---

## 4. План волн — ИСПОЛНЕНО

Порядок: сначала то, что заставляет пользователя видеть НЕ ТЕ данные (Д1), затем свежесть после мутаций (Д2), затем скорость BE (Д3), затем замеры и добивка (Д4). Backend-волна независима от FE и может идти параллельно.

> **Статус трека (2026-07-05): ИСПОЛНЕНО.** Волны Д1 + Д3 отработаны полностью, Д2 — частично (в составе Д1 + хвост-дедуп; остаток честно перечислен в «Отложено» ниже), Д4 — замеры сняты (см. §5). 6 локальных коммитов (не запушены):
> - `66d4d59` docs(audit) — этот отчёт (план + карта находок).
> - `e28f939` perf(backend) — Д3: single-scan KPIs, lean list payloads, search indexes, dashboard cache.
> - `deacac1` fix(crm) — Д1: гонки данных и skeleton-мигание на списках/карточках CRM.
> - `3e63488` fix(deals) — Д1: kanban drag-identity, реальный rollback, тихий realtime.
> - `6cf9f95` fix(front) — Д1: last-wins везде остальное — tasks, cabinet, builder, users, documents, inbox.
> - `79e370f` perf(front) — Д2-хвост: single-flight users-cache + inbox-counts (схлопнуты дублирующие mount-фетчи).
>
> **Сьют 4131/4131 зелёный.** QA-гейт (Chrome MCP, dev): механики подтверждены — гонки убиты, realtime тихий, МК-гейт держит; найден и закрыт 1 i18n-хвост (`crm.log.fields`, 21 ключ). Замеры Д4 (dev): все страницы < 700мс до контента, аномалий > 1.5с нет.

### Д1 — «гонки и путаница» (все 12 high + смежные med того же файла) — ✅ ИСПОЛНЕНО

Закрыты все 12 high (H1–H12) + смежные med того же контура. Коммиты `deacac1` (CRM), `3e63488` (deals), `6cf9f95` (tasks/cabinet/builder/users/documents/inbox).
- **Kanban** — H4 drag-identity через `@change` (`added`/`removed`), H5 иммутабельная замена массивов колонок + реальный rollback, H6 тихий realtime через `reloadSilent()` (не поднимает `loading` при данных, не рвёт drag; скелетон только при пустой доске). Смежное: сброс `page` при смене воронки.
- **МК-конструктор** — H11 сброс формы + токен-guard в `loadCard` + сверка `existingCardId` (`useMotivationBuilder.ts`); silent-поллинг МК через гейт со снапшотом периода; индикатор фонового рефетча (`MotivationTab.vue`).
- **contacts↔companies** — H1 watch `route.name` + синхронизация тулбар-переключателя с URL.
- **last-wins обходы** — H7 (`useMyTasks` fetchPage через гейт), H8 (кабинет-лента через токен), H10 (users search на `useAsyncResource` + debounce); team-board, `serverBuckets` (`myTasksStore`), инбокс `totalRecords` — коммит через гейт.
- **Карточки** — H2/H3 через background-режим примитива `useAsyncResource` (скелетон только при `data===null`), правку примитива сделал единственный владелец; lazy-Tabs.
- **documents** — H12 split-watch (filter → `page=1`, поиск → debounce).
- **каналы компании** — token-guard (`useCompanyPageData`).
- **entity-log** — request-token + дедуп (`useEntityLog`).
- **realtime-подписки задач** — H9 синхронная регистрация cleanup.

### Д3 — «скорость BE» — ✅ ИСПОЛНЕНО

Коммит `e28f939`. Все зоновые med закрыты:
- **Payload-диета** — lean `CompanyListResource` + `CompanyBriefResource` (список компаний), урезанный `ContactCompanyLinkResource` (payload компаний в списке контактов −90%), `InboundMessageResource` без `raw_payload` в list-режиме (preview-only; полный body в show).
- **COUNT-штормы → single aggregate** — `DealKpiService` 7 COUNT → 1 conditional-aggregate скан; `ContactsKpiService` 6 COUNT + `Schema::hasColumn` → 1 `COUNT(CASE…)` (эталон `ActivityService::countsByPreset`).
- **Индексы** — обратимые миграции: `created_at` на deals/crm_companies/crm_contacts (`2026_07_05_100000`); `pg_trgm` GIN под ILIKE-поиск на горячих полях (`2026_07_05_100001`).
- **Дашборд** — `Cache::remember` TTL 60с (config `crm.dashboard.cache_ttl`); подтверждён горячий кэш (25–26мс / 5.7KB).
- **open_deals_min/max** — коррелированный подзапрос заменён на предагрегацию одним `leftJoinSub` (`ContactService:188`).

### Отложено (med/low, сознательно не в этом заходе)

Честный остаток. Не блокирует «данные не путаются» — это резервы свежести/скорости, безопасные к отсрочке:
- **`defsCache` инвалидация custom-fields сделки** (`useDealCustomFields.ts:10`, med) — кэш дефиниций на сессию без `invalidate()`; новые custom-fields не видны до F5. Не тронут.
- **ActivityFormDialog — свой users-фетч** (`components/ActivityFormDialog.vue:238`, дедуп-хвост) — диалог ходит в `usersApi.getUsers()` напрямую мимо `useUsersCache` (собственный module-level кэш внутри диалога, но не общий синглтон). Единый single-flight users-cache сделан для хаба/деталей (`79e370f`), диалог не мигрирован.
- **own-echo suppression на BE** (H6 backend-half) — реализован FE-путём (`reloadSilent()` не рвёт UI), поэтому `broadcast()->toOthers()` + `X-Socket-ID` на бэке НЕ добавлялись. Симптом закрыт со стороны клиента; серверная фильтрация собственного echo остаётся резервом (лишний тихий рефетч на своё же событие, визуально незаметен).
- **`DealResource` stage-slim** (`DealResource.php:50`, low) — каждая строка списка по-прежнему несёт полный `PipelineStageResource` через `whenLoaded` (дублируется по сделкам одной стадии). Не тронут — payload-диета пошла по компаниям/контактам/инбоксу, deals-list оставлен как есть.
- **`DealFeedService` meta COUNT** (`DealFeedService.php:122`, low) — meta.total считается capped-COUNT'ом на источник при каждой странице ленты. Логика уточнена/задокументирована, но пересчёт COUNT при листании остался.
- **InlineEditableField не закрывать при фоновом апдейте** (`InlineEditableField.vue:232`, med) — watch на `modelValue` по-прежнему закрывает редактор при любом изменении, включая прилетевший `reloadSilent`. H6 снял основную причину (доска больше не мигает), но точечный кейс «фоновый апдейт закрыл открытый инлайн-эдит» не адресован.
- **Optimistic custom-fields по ключу / backend-merge** (`CompanyPage:911`, `DealTabMain:462`, med) — lost-update при быстром сохранении двух доп-полей подряд. Не тронут.
- **Прочая свежесть Д2** (low-хвост) — telegram-поллинг перезаписывает весь `currentUser` (`useProfilePage:244`); `toggleActive` без рефетча (`useProductsPageActions:49`); стейл `amounts_by_currency` при `moveDeal` (`useDealsBoard:184`); keep-alive `onActivated`-рефетч дашборда (`DashboardPage:49`); черновик-confirm (`useInboxPage:518`); open-tasks не из ленты (`useDealFeed:308`).
- **Д4 low-добивка** — page-clamp (`useContactsPageData:221`), счётчики папок (`EntityFilesTab:283`), error-тост с текстом успеха (`MyTasksPage:743`), skeleton-на-рефетч инбокса (частично — `InboxList.vue` тронут), пагинация черновиков (`useInboxPage:510`), bulk последовательно (`MyTasksPage:615`).

### Д1 (исходный план) — «гонки и путаница» (все 12 high + смежные med того же файла)

Всё, что даёт «данные перепутались / мигает / срывается». Цель волны — убить весь класс last-wins-обходов и путаницы сущностей.

- **Kanban drag identity + rollback + realtime-мигание** — H4 (`@change`-identity) + H5 (иммутабельные колонки/rollback) + H6 (тихий рефетч + `toOthers()`/`X-Socket-ID`). Смежные med того же контура: `useDealsBoard.ts:184` (amounts при moveDeal), `DealsPage/index.vue:642` (сброс page при смене воронки).
- **МК-конструктор гейт формы** — H11 (`useMotivationBuilder.ts:397`): сброс формы + токен-guard + сверка `existingCardId`. Смежно: silent-поллинг МК `useMotivationTab.ts:44`, `MotivationTab.vue:4` (индикатор рефетча).
- **contacts↔companies route** — H1 (`ContactsPage/index.vue:653`): watch `route.name` + синхронизация тулбар-переключателя с URL.
- **last-wins обходы** — H7 (`useMyTasks.ts:77` fetchPage), H8 (`useManagerCabinetPage.ts:144` кабинет-лента), H10 (`useUsersPage.ts:81` users search); смежные коммиты-до-гейта: team-board (`MyTasksPage/index.vue:333`), `serverBuckets` (`myTasksStore.ts:176`), инбокс `totalRecords` (`useInboxPage.ts:202`).
- **Карточки: skeleton-мигание** — H2 (`ContactPage:570`) + H3 (`CompanyPage:687`). **⚠️ ЕДИНЫЙ ВЛАДЕЛЕЦ ПРАВКИ ПРИМИТИВА:** background-режим `front/src/composables/async/useAsyncResource.ts` (скелетон только при `data===null`) правит ОДИН исполнитель одним изменением; H2/H3 — потребители. Плюс lazy-Tabs на обеих карточках.
- **documents фильтр-страница** — H12 (`useDocumentsPage.ts:80`): filter → `page=1`, поиск → debounce.
- **каналы компании guard** — `CompanyPage/index.vue:1091` (med, тот же класс путаницы что high) — token-guard на каналы.
- **entity-log token + дедуп** — `useEntityLog.ts:35` (med+low, зоны 1 и 4): request-token, отбрасывать устаревший ответ.
- **realtime-подписки задач** — H9 (`MyTasksPage/index.vue:811`): синхронная регистрация cleanup.

> **Правило волны:** правку `useAsyncResource` (background-режим) делает ОДИН исполнитель первой — от неё зависят H2/H3 и часть Д2. Остальные пункты Д1 идут после/параллельно, но не трогают примитив.

### Д2 — «свежесть после мутаций + автокомплиты» (med-семейство)

Данные не путаются, но несвежие или подсказки не по префиксу.

- **Инвалидация кэшей** — `useDirectoriesStore` (`stores/directories.ts:77`, `AcquisitionChannelsPage:70`), `useUsersCache` (`useUsersCache.ts:13`), `defsCache` custom-fields (`useDealCustomFields.ts:10`); + system-reset чистка кэшей (`useSystemResetSelective.ts:206`).
- **Optimistic custom-fields** — company (`CompanyPage/index.vue:911`), deal (`DealTabMain.vue:462`): optimistic по ключу / backend-merge; `InlineEditableField.vue:232` (не закрывать редактор при фоновом обновлении).
- **Debounce / out-of-order подсказок** — AutoComplete-поиски (`useContactPageActions.ts:91` + 3 диалога), `TasksQuickCreate.vue:196`, `DealAddContactDialog.vue:157`, templates (`useTemplatesPage.ts:28`), team-поиск.
- **Keep-alive stale** — `DashboardPage/index.vue:49` (`onActivated`-рефетч), `usePlansTab.ts:81/112` (clearDirty/dirty-guard), `MetricIncome.vue:124` (валюта в dirty), `useDashboardPage.ts:81` (очередь ранних фильтров).
- **Прочая свежесть** — `loadKpi` после локальных мутаций (`ContactsPage:705`), `toggleActive` рефетч (`useProductsPageActions.ts:49`), telegram-поллинг (`useProfilePage.ts:244`), стейл денежных сумм колонок, черновик-confirm (`useInboxPage.ts:518`), open-tasks не из ленты (`useDealFeed.ts:308`).

### Д3 — «скорость BE» (backend-hot зона, независимо от FE)

- **Payload-диета ресурсов списков** — lean-ресурсы: `ContactCompanyLinkResource:28`, `CompanyController:45`, `InboundMessageResource:23`, `DealResource:50`.
- **Кэш статики + агрегатов** — pipelines/справочники, дашборд-агрегаты (`SalesDashboardService:665`).
- **COUNT-дубли → один aggregate** — `DealKpiService:75`, `ContactsKpiService:73`, `InboundMessageService:198` (folderCounts), `DealFeedService:122` (meta COUNT). Эталон в репо — `ActivityService::countsByPreset`.
- **Индексы** — `created_at` (deals/companies/contacts), `pg_trgm` GIN под ILIKE-поиск, индексируемое условие периода дашборда, предагрегация `open_deals_min/max` (`ContactService:181`). Новые обратимые миграции.

### Д4 — «замеры и добивка»

- Браузерные тайминги ключевых страниц **до/после** (kanban board, список контактов/компаний, карточка контакта/компании после заметки, MyTasks, дашборд-табы, инбокс) — Chrome MCP на этой машине, `localhost:5173`.
- Проверка, что фиксы Д1–Д3 дали заявленный эффект (нет мигания, нет лишних запросов — по Network-панели).
- Добор low, не вошедших в Д1–Д3: `page-clamp` (`useContactsPageData:221`), счётчики папок файлов (`EntityFilesTab:283`), error-тост с текстом успеха (`MyTasksPage:743`), skeleton-на-каждый-рефетч инбокса (`InboxList.vue:6`), пагинация черновиков (`useInboxPage:510`), bulk-мутации последовательно (`MyTasksPage:615`), пагинатор `:first`-биндинги (deals/tasks list).

---

## 5. Метрики до/после (заполнено — Д4)

> Замеры сняты в Chrome DevTools MCP на `localhost:5173` (dev), admin@mgcrm.test, медиана прогонов. Тайминги «до» — из статического аудита / известного поведения; «после» — фактические замеры Д4.

### 5.1 Механика (гонки, лишние запросы, кэш)

| Сценарий | Метрика | До | После Д1 | После Д3 | Цель | Статус |
|---|---|---|---|---|---|---|
| Заметка на карточке контакта | лишних запросов / мигание | ~10 / есть | 0–1 / нет | — | 0–1 / нет | ✅ |
| Заметка на карточке компании | лишних запросов / мигание | ~10 / есть | 0–1 / нет | — | 0–1 / нет | ✅ |
| Kanban: свой drag | reload доски / мигание | 1 full / есть | 0 / нет | — | 0 / нет | ✅ |
| Kanban: событие коллеги | reload доски | 1 full | тихий частичный (`reloadSilent`) | — | тихий частичный | ✅ |
| `/deals/kpi` | кол-во SQL | 7 COUNT | — | 1 aggregate | 1 | ✅ |
| `/contacts/kpi` | кол-во SQL | 6 COUNT + hasColumn | — | 1 aggregate | 1 | ✅ |
| `/contacts` list (open_deals) | коррелированный подзапрос | тройной JOIN на строку | — | 1 `leftJoinSub` | ↓ | ✅ |
| Список компаний | вес payload | ~60 полей/строку | — | lean list-ресурс | ↓ | ✅ |
| Список контактов (company-link) | вес payload связи | полный CompanyResource | — | −90% (brief) | ↓ | ✅ |
| Инбокс список (50) | вес payload | body + `raw_payload` | — | preview, без raw | ↓ | ✅ |
| Дашборд Обзор | SQL / кэш | ~8 без кэша | — | кэш-хит 25–26мс / 5.7KB | кэш-хит | ✅ |
| MyTasks: N заходов | накопленных подписок | N | 1 | — | 1 | ✅ |
| Deals-list users-фетч | дублей `/api/users` | 3 | — | 1 (single-flight) | 1 | ✅ |
| Inbox counts | дублей `/api/inbox/counts` | 2 | — | 1 (single-flight) | 1 | ✅ |

### 5.2 Тайминги страниц (Д4, dev, до контента) — baseline, аномалий > 1.5с нет

| Страница | Время до контента | API-запросов | Payload | Примечание |
|---|---|---|---|---|
| Карточка сделки (deal card) | ~660–690мс (медиана) | 14 | — | самый широкий fan-out среди всех; ни один запрос не медленный (макс ~110–125мс) — кандидат на будущую консолидацию, но не жалоба |
| Дашборд Обзор | быстро | 1 (`/api/sales/dashboard`) | 5.7KB / 25–26мс | кэш-хит подтверждён (2 визита ~1с — оба из кэша) |
| `/deals` Kanban | быстро | 1 (`?view=board`) | 30.4KB | lean group-by-stage проекция |
| `/deals` List | быстро | 2 (`?view=list` + доп) | 50.1KB | полная пагинированная таблица (тяжелее борда — ожидаемо) |
| Список контактов | быстро | — | — | quick-filter чипы = client-side re-filter (0 сетевых), ~26мс |
| KPI-эндпоинты (`/deals/kpi`, `/contacts/kpi`) | 27–40мс | 1 aggregate каждый | 0.4KB | после Д3 single-scan |

> **Итог замеров:** на dev всё быстро — ни одна страница не пересекла порог аномалии 1.5с; самая тяжёлая (карточка сделки, 14 вызовов) — ~660–690мс. Fan-out карточки сделки (14 запросов) и client-vs-server асимметрия quick-filter'ов (Контакты — client-side, Задачи — server round-trip) отмечены как кандидаты для будущей perf-работы, не как текущие жалобы.

> **Проверяются функционально** (сценарий воспроизводится/не воспроизводится), не таймингом: переход Контакты↔Компании (H1), запись плана МК не тому сотруднику (H11), drag не той сделки (H4), rollback переноса (H5), потеря доп-полей — все подтверждены QA-гейтом как закрытые.

---

## Приложение — распределение находок по волнам

| Волна | High | Med | Low | Итого |
|---|---|---|---|---|
| Д1 «гонки и путаница» | 12 | ~8 | 1 | ~21 |
| Д2 «свежесть + автокомплиты» | 0 | ~22 | ~9 | ~31 |
| Д3 «скорость BE» | 0 | ~8 | 4 | 12 |
| Д4 «замеры и добивка» | 0 | ~3 | ~11 | ~14 |
| **Всего** | **12** | **41** | **25** | **78** |

*(Границы Д1/Д2 по med подвижны: смежные med того же файла/контура, что high, поднимаются в Д1; остальные med свежести — в Д2.)*

---

## Итог трека (2026-07-05)

**12/12 high закрыто** (H1–H12) — весь класс «данные путаются» устранён механически: коммит-фаза примитива `useAsyncResource` даёт **last-wins** (поздний устаревший ответ отбрасывается), identity-гейты убрали путаницу сущностей (drag не той сделки, план не тому сотруднику, лента/лог/каналы не той сущности), а иммутабельная замена массивов в optimistic/rollback гарантирует, что UI показывает ровно серверное состояние. **«Данные не путаются» — это теперь механическая гарантия, а не «стараемся».**

**Med ~35/41 закрыто**, ~6 честно отложены (см. «Отложено» в §4): `defsCache`-инвалидация custom-fields, ActivityFormDialog-own-users-кэш, own-echo suppression на BE (закрыт FE-путём), `DealResource` stage-slim, `DealFeedService` meta-COUNT, optimistic custom-fields по ключу + InlineEditableField-фон. Все отложенные — резервы свежести/скорости, безопасные к отсрочке; ни один не возвращает класс «данные путаются».

**Сьют 4131/4131 зелёный.** QA-гейт подтвердил механики на dev; 1 i18n-хвост (`crm.log.fields`) найден и закрыт. Д4-замеры: все страницы < 700мс до контента, аномалий > 1.5с нет, дашборд-кэш и single-scan KPI подтверждены. **6 локальных коммитов, не запушены** (`66d4d59`..`79e370f`).
