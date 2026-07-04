# Inbox / «Почта» — СРЕЗ B backend contract (star · important · snooze · drafts · counts · date-range)

> **Spec-author:** `backend-architect`. **Implementer:** `sales-backender` (Inbox не сплитнут — доменную
> логику по этому контракту пишет sales-backender; миграции/тесты — backend-architect).
> **Status:** DRAFT — contract-first, код ещё не написан.
> **FE consumer:** `frontend-specialist` строит СРЕЗ B UI (`front/src/pages/InboxPage/*`) ТОЛЬКО после
> появления endpoint'ов + response-shape ниже.
>
> **Что закрывает:** ГЭП-1..6 из `design-handoff/redesign/Mail-v2-spec.md` §8 и §15 «Требуется backend».
> Референс UI: `design-handoff/redesign/mail.html` (папки со счётчиками ⭐/Важные/Отложенные/Черновики,
> звёзды в строках и тулбаре читалки, дата-фильтр).
>
> **Эталон паттерна:** `docs/backend-standard.md` §1 (layering), §4 (authz), §6 (reuse). Канонический
> CRUD-срез — `crm_tags` (§1.1). Cross-domain — только через Service.

---

## 0. Что в скоупе / чего НЕТ (жёсткие границы)

**В скоупе (юзер явно запросил «закреп, черновики, отложенные + механики на бэке»):**
1. **Помеченные (star)** — `starred_at` + toggle-endpoint + фильтр `starred=1`. → ГЭП-1.
2. **Важные (important)** — `important` bool + toggle-endpoint + фильтр `important=1`. → ГЭП-2.
3. **Отложенные (snooze)** — `snoozed_until` + snooze/unsnooze endpoints + семантика скрытия/возврата. → ГЭП-3.
4. **Черновики (drafts)** — минимальная честная сущность `inbox_drafts` (CRUD) без изобретения outbound-домена. → часть ГЭП-6, урезанная.
5. **Счётчики (counts)** — `GET /api/inbox/counts`, один агрегирующий запрос, per-folder + per-channel. → ГЭП-4.
6. **Дата-фильтр** — `date_from`/`date_to` уже работают на бэке; в контракте только фиксируем форму для FE. → ГЭП-5.

**Вне скоупа (юзер НЕ просил — НЕ делаем):**
- Полноценная исходящая почта: **Отправленные / Спам / Корзина / кнопка «Написать» с реальной отправкой**
  (`sent`/`spam`/`trash`/`compose`). Это отдельный **спринт исходящей почты**. Черновик здесь = локальная
  НЕотправленная заметка-ответ; **фактическая отправка появится со спринтом исходящей почты** (§4.6).
- Авто-правила «Важное» (auto-flagging по ключевым словам/AI) — `important` ставит **только человек вручную**,
  как star. Авто-логика — отдельная задача (`automation-specialist`), не в этом контракте.
- Snooze через фоновый Job/Scheduler — сознательно **без-джобовый** вариант (§4.3, обоснование).
- Per-user star/important/snooze/read. Inbox — **SHARED mailbox** (см. §1); все триаж-флаги общие для
  admin/director, ровно как существующий `read_at`. Drafts — **единственное исключение** (per-author, §4.6).

---

## 1. Инвариант видимости: Inbox — SHARED mailbox (несущий принцип)

Существующий `read_at` — **общий на сообщение, не per-user** (`2026_06_28_100000_add_read_at…` docblock:
«once anyone opens a message it is read for everyone»). Policy `InboundMessagePolicy` гейтит `viewAny`/
`view`/`manage` **одним** правом `inbox.manage` (admin/director; сидер-коммент допускает manager — см. §7).
Row-level VisibilityResolver к Inbox **НЕ применяется** — это единый триаж-лог, не owner-scoped сущность.

**Следствие для СРЕЗА B:** `star` / `important` / `snooze` — тоже **общие флаги на сообщении**, мутирует их
любой, кто прошёл `manage` (тот же гейт, что read/unread/reroute). Никаких `user_id` в этих колонках, никакого
pivot. Это консистентно с уже задеплоенным read-state и не плодит per-user-инфру.

> **Почему не per-user:** ввести per-user флаги = pivot-таблица + переписать unread-count + сломать модель
> «общий ящик». Юзер просил механики папок, не персональные ящики. Держим shared-модель. Если продукт позже
> захочет персональные звёзды — это отдельный контракт (breaking), не тихая замена.

**Drafts — единственное исключение:** черновик привязан к автору (`user_id`), потому что это «моя незаконченная
заметка», а не общий флаг триажа. Листинг черновиков — только свои (§4.6).

---

## 2. Обязательный рефактор-долг (закрыть В ХОДЕ реализации, не отдельно)

`InboundMessageController::index` сегодня держит **все запросы и бизнес-логику инлайн в контроллере** и
использует **ручной `escapeLike`** — оба пункта нарушают `docs/backend-standard.md` §1 (layering) и §6.1
(mandatory `whereLikeCi`). СРЕЗ B добавляет ещё больше фильтров/агрегаций — если лить их тем же способом,
контроллер станет неремонтопригодным.

**Требование контракта:**
- Ввести **`app/Domain/Inbox/Services/InboundMessageService.php`** и перенести туда весь query-build из
  `index` + `unreadCount` + новые `counts`/`toggle`-методы. Контроллер становится тонким (§1 канон).
- Заменить ручной `escapeLike` на канонические **`whereLikeCi`/`orWhereLikeCi`** макросы (§6.1) — как
  `DealService`/`CompanyService`. Удалить приватный `escapeLike` из контроллера.
- `date_from`/`date_to`/`starred`/`important`/`snoozed`/`drafts` — валидация уходит в **FormRequest**
  (`IndexInboundMessageRequest`), а не инлайн `$request->validate`.

> Это НЕ расширение скоупа, а обязательное условие чистой посадки СРЕЗА B. `reviewer` режет инлайн-логику
> в контроллере и raw-LIKE. Делаем сразу правильно.

---

## 3. Миграция (единая, backend-architect)

**Имя:** `YYYY_MM_DD_HHMMSS_add_triage_flags_to_inbound_messages.php`
(один файл на три флага сообщения; drafts — отдельной миграцией, §4.6).

```php
Schema::table('inbound_messages', function (Blueprint $table): void {
    // Помеченные (звезда). NULL = не помечено; timestamp = момент пометки.
    // timestamp (не bool) — чтобы сортировать «недавно помеченные» и симметрично read_at.
    $table->timestamp('starred_at')->nullable()->after('read_at');

    // Важные. Ставит человек вручную (как star). bool достаточно — порядок не нужен.
    $table->boolean('important')->default(false)->after('starred_at');

    // Отложенные. NULL = не отложено; timestamp = «показать снова после».
    $table->timestamp('snoozed_until')->nullable()->after('important');
});

// Partial-индексы (горячие WHERE для папок-фильтров). Guard PG-DDL для sqlite-набора.
if (DB::getDriverName() === 'pgsql') {
    DB::statement('CREATE INDEX ix_inbound_messages_starred
        ON inbound_messages (starred_at) WHERE starred_at IS NOT NULL');
    DB::statement('CREATE INDEX ix_inbound_messages_snoozed
        ON inbound_messages (snoozed_until) WHERE snoozed_until IS NOT NULL');
}
// important — низкая кардинальность, но папка «Важные» частая → обычный индекс, обе СУБД:
$table->index('important', 'ix_inbound_messages_important'); // внутри Schema::table выше
```

**Обоснование типов:**
- `starred_at timestamp` (не `boolean starred`) — симметрия с `read_at`, плюс возможность сортировки/показа
  «когда помечено» без второй колонки. Toggle = `now()` ↔ `null`.
- `important boolean` — порядок не нужен, папка = булев флаг. `default(false)` (не nullable) — трёх-значность
  не нужна.
- `snoozed_until timestamp` — сама точка возврата; NULL = не отложено (§4.3).

**Индексы:** partial по `starred_at`/`snoozed_until` (как существующий `ix_inbound_messages_read_at`) — фильтры
папок бьют ровно в эти колонки. `important` — обычный индекс (partial по bool бессмысленен). PG-only raw DDL
завёрнут в `DB::getDriverName()==='pgsql'` guard (§5 backend-standard: «Guard raw PG DDL … so the sqlite suite
survives»). В sqlite-наборе фильтры отработают и без partial-индекса.

**down():** dropIndex обоих partial (внутри pgsql-guard) + `important`-индекс + dropColumn трёх колонок.
Оба `migrate` + `migrate:rollback` прогнать на pgsql перед commit.

**Модель `InboundMessage`:** добавить в `$fillable` `starred_at, important, snoozed_until`; в `casts()` —
`'starred_at' => 'datetime'`, `'important' => 'boolean'`, `'snoozed_until' => 'datetime'`.
`$timestamps=false` — без изменений.

---

## 4. API-контракт (endpoints + response shape)

Все — под `auth:sanctum`, гейт `manage`/`viewAny` (тот же `inbox.manage`). Ответ мутаций = **свежий
`InboundMessageResource`** (как read/unread/reroute сегодня — FE перезаписывает строку из ответа).
**Роут-порядок:** статические сегменты (`counts`, `drafts`) идут ПЕРЕД `{inboundMessage}` (иначе съест как id —
как уже сделано для `unread-count`).

### 4.1 Star (toggle-пара, канон соседних read/unread)

Соседний канон — **пара endpoint'ов** (`POST …/read` + `POST …/unread`), НЕ единый toggle. Держим тот же
паттерn для консистентности и идемпотентности (FE знает целевое состояние из клика по звезде).

```
POST   /api/inbox/{inboundMessage}/star     → InboundMessageResource   (starred_at = now(), idempotent)
DELETE /api/inbox/{inboundMessage}/star     → InboundMessageResource   (starred_at = null,  idempotent)
```
- Идемпотентно: `star` ставит `now()` только если сейчас null (не двигает timestamp при повторе) — ровно как
  `read()` не двигает `read_at`. `unstar` = `null`.
- Гейт: `$this->authorize('manage', $inboundMessage)`.

### 4.2 Important (toggle-пара, симметрично star)

```
POST   /api/inbox/{inboundMessage}/important  → InboundMessageResource  (important = true,  idempotent)
DELETE /api/inbox/{inboundMessage}/important  → InboundMessageResource  (important = false, idempotent)
```
- Ставит человек вручную (как star). Авто-правил нет (§0).
- Гейт: `manage`.

### 4.3 Snooze (отложить / вернуть) — БЕЗ-ДЖОБОВЫЙ возврат

```
POST   /api/inbox/{inboundMessage}/snooze   body: { "until": "2026-07-05T09:00:00Z" }  → InboundMessageResource
DELETE /api/inbox/{inboundMessage}/snooze                                              → InboundMessageResource
```
- `POST …/snooze`: `snoozed_until = <until>`. Валидация `until` — **FormRequest** `SnoozeInboundMessageRequest`:
  `required|date|after:now` (нельзя отложить в прошлое). Формат — ISO-8601; парсинг Carbon.
- `DELETE …/snooze`: `snoozed_until = null` (ручной досрочный возврат).
- Гейт: `manage`.

**Семантика скрытия/возврата (РЕШЕНИЕ spec-author — без-джобовый вариант):**
- **«Входящие» (папка `all`) СКРЫВАЮТ активно-отложенные:** запрос по-умолчанию добавляет
  `WHERE (snoozed_until IS NULL OR snoozed_until <= now())`. То есть отложенное «до завтра 9:00» не мозолит
  глаза во «Входящих» до срока.
- **Папка «Отложенные» (`snoozed`)** = `WHERE snoozed_until > now()` — только ещё-активно-отложенные.
- **Авто-возврат = чистый фильтр по времени, без Job.** Когда `snoozed_until <= now()`, сообщение
  **автоматически снова видно во «Входящих»** (условие скрытия перестаёт выполняться). Никакого scheduled-job
  «пометить unread по истечении» — время само «проявляет» письмо. `read_at` НЕ трогаем: если письмо было
  непрочитано до snooze — оно и вернётся непрочитанным (unread-бейдж подсветит), если было прочитано — вернётся
  прочитанным. Это честно и предсказуемо.

> **Почему без Job (обоснование, как просил ТЗ):** джоб-вариант («по истечении пометить unread + broadcast»)
> добавил бы scheduler-инфру, гонки таймингов и лишний write-путь ради косметики. Без-джобовый вариант даёт
> тот же UX: вернувшееся письмо снова во «Входящих», а раз unread-бейдж уже реагирует на `read_at`, отдельная
> «подсветка возврата» не нужна. **UX не страдает.** Единственный нюанс — счётчики (§4.5) и unread-count
> должны считаться с тем же snooze-фильтром на «Входящих», иначе цифра разъедется с видимым списком (см. §4.5).
> Триггер пересчёта — обычный поллинг/refetch на фронте (Inbox и так pull-based, broadcast'а нет — §6).

**Взаимодействие snooze × другие папки:** snooze-скрытие применяется ТОЛЬКО к папке `all` (Входящие) и к
`unread`-счётчику Входящих. Папки `failed`/`deals`/`starred`/`important`/`snoozed` показывают сообщение
независимо от `snoozed_until` (иначе отложенное failed-письмо пропадёт из «Не разобрано» — неверно). То есть
snooze прячет из «общего потока», но не из целевых рабочих папок.

### 4.4 Фильтры списка (`GET /api/inbox`) — добавляемые к существующим

Существующие (не трогаем): `q`, `channel_id`, `channel`(kind, FE-side), `routing_status`, `has_deal`, `unread`,
`date_from`, `date_to`, `page`, `per_page`. **Добавляем:**

| param | тип | семантика |
|---|---|---|
| `starred` | bool | `1` → `WHERE starred_at IS NOT NULL`; `0` → `IS NULL` |
| `important` | bool | `1` → `WHERE important = true`; `0` → `= false` |
| `snoozed` | bool | `1` → `WHERE snoozed_until > now()` (папка «Отложенные»); `0` → активных-отложенных нет |
| `folder` | enum? | **опционально** — см. ниже |

**Модель папки (важное решение):** папки FE-маппит на комбинацию уже-существующих + новых param'ов (как в
СРЕЗЕ A: `all`/`failed`/`deals` = отсутствие/`routing_status`/`has_deal`). Продолжаем ту же линию — **НЕ вводим
серверный `folder`-enum**, папка = набор param'ов:

| Папка (mail.html) | param'ы GET /api/inbox | snooze-скрытие Входящих |
|---|---|---|
| Входящие (`all`) | (нет status-фильтров) | **ДА** — `(snoozed_until IS NULL OR <= now())` применяется по-умолчанию |
| Не разобрано (`failed`) | `routing_status=failed` | нет |
| В сделках (`deals`) | `has_deal=1` | нет |
| Помеченные (`starred`) | `starred=1` | нет |
| Важные (`important`) | `important=1` | нет |
| Отложенные (`snoozed`) | `snoozed=1` | сам по себе `>now()` |
| Черновики (`drafts`) | **отдельный endpoint** `GET /api/inbox/drafts` (§4.6) | н/п |

**Ключевой нюанс snooze-дефолта:** «Входящие» (все триаж-фильтры пусты) скрывают активно-отложенные ВСЕГДА
по-умолчанию. Но как только выбран любой явный фильтр (`starred`/`important`/`snoozed`/`failed`/`deals`) —
snooze-скрытие НЕ применяется. Формально: `applySnoozeHiding = !starred && !important && !snoozed && !failed
&& !deals` (т.е. только «чистые Входящие»). Реализовать в Service одним флагом.

> **Обоснование «нет folder-enum»:** СРЕЗ A уже маппит папки на param'ы client-side, тесты и FE это знают.
> Ввести серверный `folder` = дублировать ту же логику в двух местах. Держим единый механизм param'ов;
> Service вычисляет `applySnoozeHiding` из уже присутствующих флагов. Один источник правды.

### 4.5 Counts — `GET /api/inbox/counts` (ОДИН агрегирующий запрос)

```
GET /api/inbox/counts  → 200
{
  "data": {
    "folders": {
      "inbox_unread": 12,   // Входящие непрочитанные, С УЧЁТОМ snooze-скрытия (см. ниже)
      "starred":      4,     // starred_at IS NOT NULL
      "important":    3,     // important = true
      "failed":       2,     // routing_status = 'failed'
      "in_deals":     18,    // target_deal_id IS NOT NULL
      "snoozed":      5,     // snoozed_until > now()
      "drafts":       1      // inbox_drafts WHERE user_id = <me> (per-author, см. §4.6)
    },
    "channels": {            // per-channel UNREAD (для бейджей чипов каналов)
      "tg":      3,
      "wa":      1,
      "email":   6,
      "web_form": 2,
      "api":     0
    }
  }
}
```

- **`inbox_unread`** считается с тем же snooze-фильтром, что и папка «Входящие»:
  `read_at IS NULL AND (snoozed_until IS NULL OR snoozed_until <= now())`. Иначе бейдж «Входящие·N» разойдётся
  с видимым списком (см. предупреждение в §4.3). Это заменяет/дополняет существующий `GET /inbox/unread-count`
  для сайдбар-бейджа — **см. §4.5.1**.
- **Один SQL-запрос** — агрегация через условные суммы (Postgres `FILTER` / `SUM(CASE WHEN …)`), НЕ 7 отдельных
  `count()`. Пример формы (Service, псевдо):
  ```php
  InboundMessage::query()->selectRaw("
    COUNT(*) FILTER (WHERE read_at IS NULL AND (snoozed_until IS NULL OR snoozed_until <= now())) AS inbox_unread,
    COUNT(*) FILTER (WHERE starred_at IS NOT NULL)                                                AS starred,
    COUNT(*) FILTER (WHERE important)                                                             AS important,
    COUNT(*) FILTER (WHERE routing_status = 'failed')                                             AS failed,
    COUNT(*) FILTER (WHERE target_deal_id IS NOT NULL)                                            AS in_deals,
    COUNT(*) FILTER (WHERE snoozed_until > now())                                                 AS snoozed
  ")->first();
  ```
  > **sqlite-набор:** `FILTER (WHERE …)` поддерживается в SQLite ≥ 3.30 (наш `:memory:` набор его понимает).
  > Если CI-версия окажется старее — фолбэк `SUM(CASE WHEN … THEN 1 ELSE 0 END)` (портируемо везде). Проверить
  > на первом прогоне; предпочесть `CASE`-форму, если есть сомнение в версии sqlite.
- **per-channel unread** — второй агрегат `GROUP BY channel_id` + JOIN `channels.kind` (или map в Service):
  `SELECT channel_id, COUNT(*) FILTER (WHERE read_at IS NULL) … GROUP BY channel_id`, затем свернуть в
  `kind → count` в Service. **Итого 2 запроса** (folders-агрегат + channels-агрегат) — это и есть «один
  агрегирующий» в смысле «не N отдельных count'ов на папку». Держим 2, не 12.
- `drafts` в `folders` = `COUNT inbox_drafts WHERE user_id = auth()->id()` — отдельный мелкий count (drafts —
  другая таблица), приклеивается к ответу в Service.
- **Resource:** ручной `InboxCountsResource` (не raw array — §1 canon). Гейт: `viewAny` (`inbox.manage`).

#### 4.5.1 Отношение к существующему `GET /inbox/unread-count`

Сайдбар-бейдж сейчас дёргает `GET /inbox/unread-count` (`{count}`). **Оставляем его** как есть (сайдбар грузится
не на странице Inbox, ему не нужен весь counts-объект). НО его число теперь ДОЛЖНО учитывать snooze:
изменить `unreadCount()` на `read_at IS NULL AND (snoozed_until IS NULL OR snoozed_until <= now())`, чтобы
сайдбар и `counts.folders.inbox_unread` были одинаковы. Единый helper в Service
(`InboundMessageService::inboxUnreadCount()`) используют оба endpoint'а — один источник числа.

### 4.6 Drafts — минимальная честная сущность (без outbound-домена)

Черновик = **НЕотправленная заметка-ответ**, привязанная к автору и (опционально) к исходному сообщению.
**Фактической отправки НЕТ** — она появится со **спринтом исходящей почты** (тогда draft станет источником для
`compose`/send). Сейчас drafts — локальное хранилище «набросков ответа», чтобы папка «Черновики» из мокапа была
не пустой муляжной, а реально работала как CRUD заметок.

**Миграция:** `YYYY_MM_DD_HHMMSS_create_inbox_drafts_table.php`
```php
Schema::create('inbox_drafts', function (Blueprint $table): void {
    $table->id();
    // Автор черновика — per-user (ЕДИНСТВЕННОЕ per-user в Inbox, §1). Свой ящик набросков.
    $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
    // Опциональная привязка к входящему, на который отвечаем. nullOnDelete —
    // удаление исходного письма не рушит черновик (остаётся «свободным» наброском).
    $table->foreignId('related_message_id')->nullable()
        ->constrained('inbound_messages')->nullOnDelete();
    $table->string('subject', 255)->nullable();
    $table->text('body')->nullable();
    $table->timestamps(); // created_at/updated_at нужны (сортировка «последние черновики»)
    $table->index(['user_id', 'updated_at'], 'ix_inbox_drafts_user_updated');
});
```

**Endpoints (CRUD, per-author scope):**
```
GET    /api/inbox/drafts                → AnonymousResourceCollection<InboxDraftResource>  (только свои, order updated_at desc, paginated)
POST   /api/inbox/drafts                → InboxDraftResource  201   body: { related_message_id?, subject?, body? }
GET    /api/inbox/drafts/{draft}        → InboxDraftResource
PATCH  /api/inbox/drafts/{draft}        → InboxDraftResource         body: { subject?, body?, related_message_id? }
DELETE /api/inbox/drafts/{draft}        → 204
```
- **Модель** `App\Domain\Inbox\Models\InboxDraft` (fillable: user_id, related_message_id, subject, body).
- **Service** `InboxDraftService` (list/create/update/delete, все запросы тут).
- **FormRequest** `StoreInboxDraftRequest` / `UpdateInboxDraftRequest`:
  `related_message_id` → `nullable|integer|exists:inbound_messages,id`; `subject` → `nullable|string|max:255`;
  `body` → `nullable|string`.
- **Resource** `InboxDraftResource` (id, user_id, related_message_id, subject, body, created_at, updated_at;
  опционально embed краткую мету связанного сообщения при `whenLoaded`).
- **Policy** `InboxDraftPolicy` — **per-author** (не shared): `view`/`update`/`delete` только когда
  `$draft->user_id === $user->id`; `viewAny`/`create` — `$user->can('inbox.manage')`. Это отличается от общей
  shared-модели Inbox сознательно (черновик — личный набросок, §1).
- **Роут-порядок:** блок `inbox/drafts…` объявить ПЕРЕД `inbox/{inboundMessage}` (иначе `drafts` съест как id).
  `apiResource('inbox/drafts', InboxDraftController::class)` с `only([index,store,show,update,destroy])` — но
  проверить, что regex `{inboundMessage}` не перехватит; при необходимости `->whereNumber('inboundMessage')` на
  wildcard-роутах (рекомендуется добавить для устойчивости).

> **Явная граница (зафиксировано):** отправка черновика (превращение в исходящее письмо, статусы sent/failed,
> SMTP/канал-транспорт) — **НЕ в этом контракте**. Появится отдельным **спринтом исходящей почты** вместе с
> Отправленными/Спамом/Корзиной. Сейчас draft — только сохраняемый набросок; кнопка «Отправить» на FE в СРЕЗЕ B
> либо отсутствует, либо disabled с тултипом «Отправка — в следующем релизе». Папки sent/spam/trash в мокапе
> `mail.html` — НЕ реализуем (юзер их не просил).

### 4.7 Date-range (ГЭП-5) — уже работает, только фиксируем

`date_from`/`date_to` (`Y-m-d`, Дубай-окно, `received_at` bounds) **уже реализованы** в контроллере и валидации.
СРЕЗ B **не меняет backend** здесь — просто FE добавляет поле «Дата получения» в панель фильтров и шлёт эти
param'ы. При выносе логики в Service (§2) — перенести date-обработку (`operationalDayStart/End`) в Service
как есть.

---

## 5. Resource-дельта (`InboundMessageResource`)

Добавить 3 поля (после `read_at`):
```php
'starred_at'    => $this->starred_at?->toISOString(),   // null = не помечено
'important'     => (bool) $this->important,
'snoozed_until' => $this->snoozed_until?->toISOString(), // null = не отложено
```
Порядок/стиль — как существующие `read_at`/`received_at` (ISO-8601). Остальные поля не трогаем.

**FE-тип (`front/src/api/inbox.ts`) — дельта для frontend-specialist:**
```ts
export interface InboundMessage {
  // …существующие…
  starred_at: string | null      // null = не помечено
  important: boolean
  snoozed_until: string | null   // null = не отложено; > now = активно отложено
}
export interface InboundMessageListParams {
  // …существующие…
  starred?: boolean
  important?: boolean
  snoozed?: boolean
}
// новые:
export interface InboxCounts {
  folders: { inbox_unread: number; starred: number; important: number;
             failed: number; in_deals: number; snoozed: number; drafts: number }
  channels: Partial<Record<ChannelKind, number>>
}
export interface InboxDraft {
  id: number; user_id: number; related_message_id: number | null
  subject: string | null; body: string | null
  created_at: string; updated_at: string
}
```

---

## 6. Событие / broadcast (badge-синк) — НЕ нужен

Inbox сегодня **pull-based**: сайдбар-бейдж и страница дёргают `unread-count`/`counts` на refetch. В домене
Inbox **нет** ShouldBroadcast-событий, и вводить их для СРЕЗА B **не требуется** — счётчики обновляются тем же
refetch'ем, что и список (кнопка «Обновить» / после мутации / поллинг). Snooze-возврат — чисто по времени
(§4.3), broadcast там нечему слать. **Решение: без Reverb/broadcast.** Если продукт позже захочет live-badge —
отдельный контракт (подписка на `App\Domain\Inbox\Events\InboxChanged`), не в этом скоупе.

---

## 7. Authz / Policy (сводка)

| Действие | Policy-метод | Гейт |
|---|---|---|
| list / show / counts / unread-count | `viewAny` / `view` | `inbox.manage` |
| star/unstar, important on/off, snooze/unsnooze, read/unread, reroute | `manage` | `inbox.manage` |
| drafts: list/create | (Policy `viewAny`/`create`) | `inbox.manage` |
| drafts: show/update/delete | (Policy `view`/`update`/`delete`) | `inbox.manage` **И** `draft.user_id === user.id` |

- **Новое право НЕ заводим.** `inbox.manage` покрывает весь триаж (star/important/snooze — тот же класс
  действий, что read/reroute). Расширять матрицу прав ради флагов — избыточно.
- **ОВ по роли (из Mail-v2-spec §15 ОВ-6):** сидер-коммент `RolePermissionSeeder:114` даёт `inbox.manage`
  → **admin, director** (manager НЕ в списке `:266`), хотя FE-комментарий `inbox.ts` пишет «admin/director/
  manager». **Фактический гейт = admin/director** (по сидеру). Это продуктовый вопрос (кто видит «Почту»), не
  решается этим контрактом — но реализатор гейтит по `inbox.manage` (Policy уже так делает), а расширение на
  manager, если нужно, — правка сидера отдельной задачей. **Флаг для main: сверить с продуктом, кто видит Почту.**

---

## 8. Тесты (backend-architect, PHPUnit + SQLite :memory:)

**Feature (per endpoint):**
- `star`/`unstar`: ставит/снимает `starred_at`, идемпотентность (повтор не двигает timestamp), 403 без
  `inbox.manage`, ответ = свежий Resource с `starred_at`.
- `important` on/off: аналогично, `important` в ответе.
- `snooze`: `until` в будущем → `snoozed_until` установлен; `until` в прошлом/сейчас → 422 (FormRequest
  `after:now`); `DELETE …/snooze` → null.
- **snooze-скрытие (критичный тест):** отложенное «в будущее» сообщение НЕ приходит в `GET /api/inbox` (папка
  Входящие); приходит в `?snoozed=1`; приходит в `?starred=1`/`?failed`/`?deals` если подходит (skrytie только
  на «чистых Входящих»); после того как `snoozed_until <= now()` — снова во Входящих (тест с прошлым timestamp,
  выставленным напрямую).
- **counts:** сид набора → проверить каждое число `folders.*` и `channels.*`; `inbox_unread` учитывает
  snooze-скрытие (отложенное непрочитанное НЕ считается во Входящих, но считается в `snoozed`); 403 без гейта.
- `unread-count`: обновлённая формула учитывает snooze (регресс существующего теста + новый на snooze).
- **drafts CRUD:** create/list(только-свои)/show/update/delete; чужой черновик → 403 (per-author Policy);
  `related_message_id` невалидный → 422; удаление связанного сообщения → черновик остаётся, `related_message_id`
  становится null (nullOnDelete).
- **Регресс:** существующие фильтры (`unread`/`channel`/`routing_status`/`has_deal`/`q`/`date_from`/`date_to`)
  продолжают работать после выноса в Service; `whereLikeCi` не сломал поиск по кириллице.

**Unit (Service):**
- `InboundMessageService::inboxUnreadCount()` — единый helper (§4.5.1), snooze-aware.
- counts-агрегат: корректность `FILTER`/`CASE`-формы на sqlite (если `FILTER` не поддержан — падёт здесь, тогда
  фолбэк на `CASE`).
- `applySnoozeHiding`-флаг: включён только для «чистых Входящих», выключен при любом явном фильтре.

**Factory:** расширить `InboundMessageFactory` состояниями `starred()`, `important()`, `snoozed(?Carbon)`,
`snoozedPast()` для тестов. Новый `InboxDraftFactory`.

---

## 9. Порядок реализации (секвенс, contract-first)

1. **[backend-architect]** миграция флагов + миграция `inbox_drafts` (migrate/rollback на pgsql). Model casts.
2. **[sales-backender]** `InboundMessageService` — вынести index/unreadCount + `whereLikeCi` (долг §2),
   добавить star/important/snooze/counts методы + `applySnoozeHiding`.
3. **[sales-backender]** FormRequest'ы (`IndexInboundMessageRequest`, `SnoozeInboundMessageRequest`, drafts).
   Тонкий контроллер: методы star/unstar/important±/snooze/unsnooze/counts.
4. **[sales-backender]** `InboxDraft` model/service/controller/policy/resource.
5. **[sales-backender]** Resource-дельта (3 поля) + `InboxCountsResource` + `InboxDraftResource`.
6. **[backend-architect]** роуты (порядок: `inbox/counts`, `inbox/drafts…` ПЕРЕД `inbox/{inboundMessage}`;
   `whereNumber` на wildcard). Policy-регистрация `InboxDraft` в `AppServiceProvider`.
7. **[backend-architect]** все тесты (§8) + factory-состояния. Pint.
8. **→ FE-gate:** только теперь `frontend-specialist` строит СРЕЗ B UI по shape §4/§5.

---

## 10. Оценка

**Backend (BE):** ~1.5–2 дня.
- Миграции + модель-casts: 0.5д (два файла, partial-индексы, rollback-проверка).
- Service-рефактор (долг §2) + star/important/snooze/counts: 0.5–0.75д (основная работа — аккуратный вынос
  index в Service без регресса + counts-агрегат).
- Drafts CRUD (model/service/controller/policy/resource/2 FormRequest): 0.25–0.5д (типовой crm_tags-подобный
  срез).
- Тесты (§8, самый объёмный пункт — snooze-скрытие, counts, drafts per-author, регресс): 0.5–0.75д.

**Frontend (FE):** ~1.5–2 дня (отдельно, `frontend-specialist`, ПОСЛЕ BE).
- Звёзды в строке + тулбаре читалки (toggle), «Важные»-флаг: 0.5д.
- Snooze-UI (пикер «отложить до», кнопка возврата) + папка «Отложенные»: 0.5д.
- Папки со счётчиками-бейджами (7 папок из `counts.folders` + per-channel чипы из `counts.channels`),
  date-range поле: 0.5д.
- Черновики (список папки + мини-редактор заметки, БЕЗ «Отправить»): 0.25–0.5д.
- DS-гейт + обе темы + qa-tester визуальный гейт.

**Риски / breaking:**
- **Не breaking для FE:** новые поля Resource аддитивны; существующий СРЕЗ A UI игнорирует их. Старые фильтры
  без изменений.
- **Поведенческое изменение `unread-count`:** формула сайдбар-бейджа начинает учитывать snooze → отложенные
  непрочитанные перестают попадать в бейдж «Входящие». Это ЗАДУМАННО (§4.3/§4.5.1), но фиксируем как изменение
  поведения существующего endpoint'а — flag для PLAN.md sync через reviewer.
- **Рефактор index→Service (§2):** несёт регресс-риск на существующих фильтрах — закрывается регресс-тестами
  (§8). Обязателен, т.к. без него контроллер нарушает layering.
- **sqlite `FILTER`-совместимость** (§4.5): если CI-версия sqlite старая — фолбэк на `CASE`. Проверить первым
  прогоном.
- **Продуктовый вопрос (не блокер):** кто видит «Почту» — admin/director (сидер) vs +manager (FE-коммент), §7
  ОВ-6. Реализация гейтит по `inbox.manage`; расширение — правка сидера отдельно.
</content>
</invoke>
