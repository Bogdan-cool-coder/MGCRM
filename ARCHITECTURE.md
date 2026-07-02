# ARCHITECTURE.md — жёсткие паттерны разработки MACRO Global CRM

> **Обязательно для main-сессии и ВСЕХ агентов. Ни шаг влево, ни шаг вправо.**
> Любой код пишется строго по этим паттернам. Отклонение — это баг, а не «стиль». `reviewer` режет код, нарушающий ARCHITECTURE.md, на ревью.
> **Эталон паттернов/стека — этот файл + `docs/backend-standard.md` (конкретный house-style с worked-примерами) + зрелая реализация в самом репо** (например `Sales/DealService`). Новый код равняется на них. `./examples/vizion/` — архив, стеком больше НЕ рулит и НЕ является tiebreaker'ом при расхождении.

---

## 0. Принцип

Перед любой строкой кода: **(1)** посмотри бизнес-логику/поведение в `./examples/contracts/` → **(2)** найди реализацию того же класса задачи в реальном `src/app/Domain/*` + `docs/backend-standard.md` → **(3)** повтори house-style в корне проекта (`src/`, `front/`), с делением на `app/Domain/<Context>`. **Не изобретаем — равняемся на ARCHITECTURE.md + docs/backend-standard.md + существующий код.**

---

## 0.1. Library-first — весь функционал на готовых решениях (НЕРУШИМО)

**Максимум готовых библиотек, минимум своего кода.** Любую фичу строим на существующей библиотеке, а не пишем вручную.

1. **Сначала ищи в том, что уже стоит.** Зависимости проекта = `composer.json` / `package.json`. Если задачу закрывает уже подключённая библиотека (Laravel/Prism/PrimeVue/ECharts/PHPWord/vue-i18n/Sanctum/spatie-permission/google2fa и т.п.) — **используй её, ничего не ставь.** Как именно эти либы применяются в проекте — `docs/backend-standard.md` + зрелые домены (`Sales/DealService` и т.п.).
2. **Не пиши своё там, где есть библиотечное.** Кастомный код вместо готового хелпера/пакета/компонента — антипаттерн. (Пример: экспорт Excel — PhpSpreadsheet, а не ручная сборка xlsx; даты — встроенные Carbon/date-fns; таблицы/диалоги — PrimeVue, а не самописные.)
3. **Новый пакет — только в случае особой необходимости** и только если функционал реально не покрыт ни одной уже доступной библиотекой. Любой новый пакет — по явной просьбе/аппруву (PLAN §3 — закрытый список; добавление = решение, а не самодеятельность агента).
4. **Порядок выбора:** (а) готовое в уже подключённых либах → (б) библиотека, уже применённая в реальном `src/` → (в) широко используемый maintained-пакет под наш стек (с аппрувом) → (г) свой код — только если (а)–(в) не подходят.

`reviewer` на ревью режет самописный код, дублирующий готовую библиотеку, как нарушение.

---

## 1. Backend — слои и поток данных (НЕРУШИМО)

**Единственный разрешённый путь запроса:**

```
HTTP → Route (/api, auth:sanctum)
     → FormRequest            (валидация + авторизация запроса)
     → Controller             (ТОНКИЙ: парс → вызов 1 Service-метода → return Resource)
     → Domain Service         (ВСЯ бизнес-логика, транзакции, оркестрация)
     → Eloquent Model         (только связи, касты, scopes — НИКАКОЙ бизнес-логики)
     → API Resource           (форма ответа; ручной класс, НЕ spatie/data)
```

**Жёсткие правила слоёв:**
- **Controller** — тонкий. Только: достать валидированные данные из FormRequest, вызвать один метод сервиса, вернуть Resource. **Запрещено:** Eloquent-запросы, бизнес-условия, циклы по данным, формирование сырых массивов ответа.
- **Service** (`app/Domain/<Context>/Services/<Name>Service.php`) — здесь вся логика и все запросы к БД. Constructor injection зависимостей. Транзакции (`DB::transaction`) — здесь. Без фасадов (кроме `DB`/`Log` где тривиально).
- **Model** — только `$fillable`/`$hidden` (как свойства), `casts()`, связи, query-scopes. **Никакой бизнес-логики, никаких сайд-эффектов.**
- **Ответ API — ТОЛЬКО через `<Entity>Resource`** (`app/Http/Resources/`). `return response()->json([...])` с сырым массивом — **запрещено**.
- **Валидация — ТОЛЬКО FormRequest** (`app/Http/Requests/<Action><Entity>Request.php`). `$request->validate([...])` inline — запрещено.

## 2. Backend — DDD-границы (НЕРУШИМО)

- Весь код домена строго в `app/Domain/<Context>/{Models,Data,Enums,Services,Jobs,Policies,Events,Listeners,Actions,Exceptions,Support,Contracts,Renderers,Telegram,ETL}` (набор папок — по надобности домена; каждый вид уже используется в реальном `src/`).
- **Существующие контексты (14, реально в `src/app/Domain/`):** `Activity, Automation, Catalog, Contracts, Crm, Iam, Inbox, Log, Migration, Notification, Onboarding, Org, Sales, SalesPulse`.
- **Запланированные (greenfield — папки ещё нет, создаются при старте спринта):** `CustomerSuccess` (спринт CS), `Finance` (спринт Финансы).
- **Analytics / Integration — отдельных контекстов НЕТ.** Сегодня эта работа вшита в `Sales` / `Inbox` / `Notification`; самостоятельные `Domain/Analytics` и `Domain/Integration` не заводим, пока для них не назрел отдельный срез.
- **Cross-domain — ТОЛЬКО через публичный Service-метод чужого контекста.** НИКОГДА не обращаться к чужим моделям/таблицам напрямую из другого домена. (Sales не делает `FinInvoice::where(...)` — зовёт `InvoiceService` из Finance.)
- Доменные `Enums` — PHP backed enums. **Статус-машины** — enum + метод-гард перехода в сервисе (`assertCanTransition(from, to)`). Никаких «магических строк» статусов в коде.

## 3. Backend — данные, деньги, авторизация

- **Деньги — целые копейки** (`unsignedBigInteger`) везде: БД, расчёты, DTO. Форматирование (рубли, разделители) — только на фронте. `float`/`decimal` для денег — запрещено.
- **Миграции:** обратимые (`up`/`down`); FK `->constrained()->cascadeOnDelete()`; translatable-поля → `jsonb`; индексы на горячих `WHERE`/`ORDER BY`; имя `YYYY_MM_DD_HHMMSS_<verb>_<entity>`. Перед коммитом — `migrate` и `migrate:rollback` оба прошли.
- **Авторизация (канон, работает в проде):** `spatie/laravel-permission` — **6 ролей** (`admin, director, lawyer, manager, accountant, cfo`) + гранулярные permissions, на guard **`sanctum`**, + **Policy на каждую доменную модель**. Проверка — через `$user->can()` / Policy / permission-middleware.
  > **✅ IAM-1 ЗАКРЫТ.** spatie работает на guard `sanctum`; `$user->can()` по permissions срабатывает. Колонка `users.role` **удалена** — `role` теперь виртуальный accessor поверх единственной spatie-роли пользователя. 4 глобальные ability — это spatie-permissions, автозарегистрированные как Gates. Двойного источника роли и переходного долга больше нет.
  > **Разрешение противоречия §3 ↔ §7:** inline-проверка роли **запрещена**. Логика ролей — в **permissions/Gates/Policy**. То есть `if ($user->role === 'admin')` в контроллере/сервисе — баг; роль/право читается только внутри Gate/Policy/`$user->can()`.
- **N+1 запрещён:** связи — через eager-load (`with()`). Запросы живут в сервисах, не в контроллерах и не в ресурсах.
- `env()` — только в `config/`. Проектные значения — `config/crm.php`.

## 4. Frontend — слои (НЕРУШИМО)

**Единственный разрешённый путь данных:**

```
api/<domain>.ts            (axios-клиент + типизированные функции, DTO из entities/)
   → composables/async     (useAsyncResource — GET; useMutation — POST/PUT/DELETE)
   → pages/<Page>/composables/use<Page>.ts   (ЛОГИКА страницы)
   → pages/<Page>/index.vue                   (только шаблон + вызов composable)
   → components/*                             (презентационные)
   → stores/* (Pinia)                         (глобальный стейт)
```

**Жёсткие правила:**
- **Данные — ТОЛЬКО через `useAsyncResource`/`useMutation`.** Голый `fetch`/`axios` в компонентах — запрещено.
- **Логика страницы — в `use<Page>.ts` composable**, не в `<template>`/`<script setup>` страницы. Шаблон только рендерит.
- **Глобальный стейт — Pinia store** (`use<X>Store`); локальный/страничный — composable. Бизнес-логики в шаблонах нет.
- **UI — ТОЛЬКО компоненты PrimeVue + `bootstrap-grid` для раскладки.** Никаких utility-классов, никакого Tailwind, никаких сторонних UI-китов.
- **Графики — ТОЛЬКО ECharts** (`vue-echarts`). Chart.js запрещён.
- **Типизация:** строгий TS, DTO в `entities/`, запрос и ответ типизированы. `<script setup lang="ts">` везде.
- **i18n:** все пользовательские строки — через `vue-i18n` (`ru`/`en`). Хардкод-строк в шаблонах нет.

## 5. Именование (фиксировано)

| Что | Паттерн |
|---|---|
| Контроллер | `<Entity>Controller` (тонкий, `app/Http/Controllers`) |
| Сервис | `<Name>Service` (`app/Domain/<Context>/Services`) |
| FormRequest | `<Action><Entity>Request` |
| API Resource | `<Entity>Resource` |
| Enum | `<Name>` backed enum (`Domain/<Context>/Enums`) |
| Policy | `<Entity>Policy` |
| Vue-страница | `<X>Page` (`pages/<X>Page/index.vue`) |
| Composable страницы | `use<X>Page` |
| Pinia store | `use<X>Store` |
| API-модуль фронта | `<x>Api` / `api/<x>.ts` |

## 6. Тесты (фиксировано)

- **PHPUnit + SQLite `:memory:`**, тройная изоляция (`phpunit.xml` force + `.env.testing` + guard в `TestCase`). Тесты НИКОГДА не ходят в живую БД.
- **Feature-тест на каждый endpoint**, **Unit-тест на каждый Service**. AI/HTTP — мокать. Pest запрещён (только PHPUnit).
- **Внешние HTTP-сервисы (Gotenberg, …):** общий фейк биндится в `TestCase::setUp()` через `$this->app->instance(GotenbergClient::class, new FakeGotenbergClient)` — все тесты без сетевого I/O по умолчанию. Тесты, которые целенаправленно проверяют HTTP-слой (TemplateCheck / ContractGeneration), конструируют `new GotenbergClient` напрямую и добавляют собственный `Http::fake()`. Фейки живут в `tests/Fakes/`.
- **`AiRetryService` (Prism-каскад):** `executeWithRetry(chatType, system, messages, tools)` — стандартный вызов без tool_choice; `executeWithRetryAndToolChoice(...)` — расширение для forced `tool_use` (weekly-отчёт Sonnet). Базовый метод делегирует в расширенный с `toolChoice=null` — non-breaking для всех существующих потребителей. Новые задачи Prism с tool_use обязательно используют `executeWithRetryAndToolChoice`; без tool_use — `executeWithRetry`.

## 6.1 Frontend — PrimeVue тема (конвенции)

**Движок темы:** `@primeuix/themes` + `definePreset(Aura, { ... })`. Конфиг подключения в `main.ts`:

```ts
app.use(PrimeVue, { theme: {
  preset: MgCrmPreset,
  options: { prefix: 'p', darkModeSelector: '.app-dark', cssLayer: true },
}})
```

**Трёхуровневые токены:** primitive → semantic → component. Переопределяем только нужное поверх Aura.

**Токены в SCSS:** CSS-переменные `--p-*` (prefixed как `p`) — мост между PrimeVue и SCSS/Bootstrap-grid без Tailwind. Пример: `var(--p-primary-color)`, `var(--p-surface-500)`.

**Готча colorScheme:** если в исходном пресете токен задан через `colorScheme` (light/dark), плоское переопределение **игнорируется**. Оверрайды **зеркалят структуру** `colorScheme.light` / `colorScheme.dark`.

**Тёмная тема:** через класс `.app-dark` на `<html>` (тоггл в Toolbox), не через media-query.

**Бренд-ассеты:** `brand/` — логотип и брендбук MACRO Global (источник истины по цветам). Primary `#172747` (brand-primary). Дизайн-система и полная спека токенов — vault `MG CRM 2026` (`6. Справочник/Дизайн-система MG CRM…`, `6. Справочник/PrimeVue 4 — тема…`).

**PrimeVue MCP:** инструменты `mcp__primevue__*` доступны после рестарта Claude Code (подключён через `~/.local/primevue-mcp`). `llms.txt`: `https://primevue.org/llms.txt`.

---

## 7. ⛔ Чёрный список (никогда, без явной отдельной просьбы)

- Обход слоёв: Controller → Model напрямую (без Service); компонент → axios напрямую (без composable).
- Смешивание доменов (прямой запрос к чужой модели).
- Сырой массив из API вместо Resource.
- Бизнес-логика в Controller / Model / Vue-шаблоне.
- Inline-проверка роли вместо Policy.
- `float`/`decimal` для денег.
- Пакеты вне PLAN §3: **Tailwind, Inertia, Livewire, Filament, Chart.js, Horizon, Pest, VeeValidate/Zod, spatie/laravel-data**.
- `response()->json([...])` сырым массивом, inline `$request->validate()`.
- Хардкод-строки в UI (мимо i18n), магические строки статусов (мимо enum).

## 8. Процессные паттерны (для main-сессии)

- **Делегирование по правилу №1** (whitelist в CLAUDE.md). Main не пишет код, не дебажит, не гоняет artisan/docker, не читает `src/` для ревью.
- **Цепочка фичи:** агент → (если был UI у `frontend-specialist`) `qa-tester` → `reviewer` (ревью + verify против ARCHITECTURE.md/PLAN.md) → апрув юзера → (по явной просьбе) `deploy-engineer`.
- **Рабочий цикл агента:** `./examples/contracts/` (ТЗ) → `ARCHITECTURE.md` + `docs/backend-standard.md` + реальный `src/app/Domain/*` (паттерн) → корень (`src/`/`front/`).
- **Деплой/push** — только по явной прямой просьбе.

---

> **Эталон стека — этот файл + `docs/backend-standard.md` (конкретный house-style с worked-примерами) + зрелая реализация в репо** (напр. `Sales/DealService`). При расхождении паттерна с реальностью `reviewer` правит этот файл с аппрувом. `./examples/vizion/` — архив, стеком больше НЕ рулит и НЕ tiebreaker. Этот документ — закон проекта.
