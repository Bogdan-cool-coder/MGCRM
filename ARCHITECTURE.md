# ARCHITECTURE.md — жёсткие паттерны разработки MACRO Global CRM

> **Обязательно для main-сессии и ВСЕХ агентов. Ни шаг влево, ни шаг вправо.**
> Любой код пишется строго по этим паттернам. Отклонение — это баг, а не «стиль». `product-manager` режет код, нарушающий ARCHITECTURE.md, на ревью.
> Эталон всех паттернов — **`./examples/vizion/`** (копия Vizion). Если паттерн ниже расходится с тем, как сделано у Vizion, — **прав Vizion**, обнови этот файл через product-manager.

---

## 0. Принцип

Перед любой строкой кода: **(1)** посмотри бизнес-логику/поведение в `./examples/contracts/` → **(2)** найди реализацию того же класса задачи в `./examples/vizion/` → **(3)** скопируй паттерн Vizion в корень проекта (`src/`, `front/`), изменив только деление на `app/Domain/<Context>`. **Не изобретаем — копируем Vizion.**

---

## 0.1. Library-first — весь функционал на готовых решениях (НЕРУШИМО)

**Максимум готовых библиотек, минимум своего кода.** Любую фичу строим на существующей библиотеке, а не пишем вручную.

1. **Сначала ищи в том, что уже стоит.** Зависимости проекта = `composer.json` / `package.json` (адаптируем с Vizion под LV13/PHP8.5, не 1-в-1) + то, что есть в `./examples/vizion/` и `./examples/contracts/`. Если задачу закрывает уже подключённая библиотека (Laravel/Prism/PrimeVue/ECharts/PHPWord/vue-i18n/Sanctum и т.п.) — **используй её, ничего не ставь.**
   > ⚠️ **`spatie/laravel-permission` и `pragmarx/google2fa` — НЕ в Vizion (эталона нет).** Vizion использует простую строковую колонку `role`, а 2FA у него отсутствует. Паттерн авторизации (роли/permissions) и 2FA берём из `./examples/contracts/` + офиц. доки пакетов, **а не grep'ом по Vizion**. Это два осознанных NEW-пакета (см. PLAN §3.1).
2. **Не пиши своё там, где есть библиотечное.** Кастомный код вместо готового хелпера/пакета/компонента — антипаттерн. (Пример: экспорт Excel — PhpSpreadsheet, а не ручная сборка xlsx; даты — встроенные Carbon/date-fns; таблицы/диалоги — PrimeVue, а не самописные.)
3. **Новый пакет — только в случае особой необходимости** и только если функционал реально не покрыт ни одной уже доступной библиотекой. Любой новый пакет — по явной просьбе/аппруву (PLAN §3 — закрытый список; добавление = решение, а не самодеятельность агента).
4. **Порядок выбора:** (а) готовое в уже подключённых либах → (б) библиотека, использованная у Vizion (`./examples/vizion/`) → (в) широко используемый maintained-пакет под наш стек (с аппрувом) → (г) свой код — только если (а)–(в) не подходят.

`product-manager` на ревью режет самописный код, дублирующий готовую библиотеку, как нарушение.

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

- Весь код домена строго в `app/Domain/<Context>/{Models,Data,Enums,Services,Jobs,Policies}`. Контексты — из PLAN §4.2 (`Iam, Org, Crm, Sales, Inbox, Contracts, Activity, Notification, Automation, CustomerSuccess, Finance, Analytics, Integration, Onboarding, Catalog`).
- **Cross-domain — ТОЛЬКО через публичный Service-метод чужого контекста.** НИКОГДА не обращаться к чужим моделям/таблицам напрямую из другого домена. (Sales не делает `FinInvoice::where(...)` — зовёт `InvoiceService` из Finance.)
- Доменные `Enums` — PHP backed enums. **Статус-машины** — enum + метод-гард перехода в сервисе (`assertCanTransition(from, to)`). Никаких «магических строк» статусов в коде.

## 3. Backend — данные, деньги, авторизация

- **Деньги — целые копейки** (`unsignedBigInteger`) везде: БД, расчёты, DTO. Форматирование (рубли, разделители) — только на фронте. `float`/`decimal` для денег — запрещено.
- **Миграции:** обратимые (`up`/`down`); FK `->constrained()->cascadeOnDelete()`; translatable-поля → `jsonb`; индексы на горячих `WHERE`/`ORDER BY`; имя `YYYY_MM_DD_HHMMSS_<verb>_<entity>`. Перед коммитом — `migrate` и `migrate:rollback` оба прошли.
- **Авторизация:** `spatie/permission` (роли) + **Policy на каждую доменную модель**. Проверка — через `$user->can()` / Policy / middleware. **Inline `if ($user->role === 'admin')` в контроллерах/сервисах — запрещено** (только в Policy/гейтах).
- **N+1 запрещён:** связи — через eager-load (`with()`). Запросы живут в сервисах, не в контроллерах и не в ресурсах.
- `env()` — только в `config/`. Проектные значения — `config/crm.php`.

## 4. Frontend — слои (как Vizion, НЕРУШИМО)

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

- **PHPUnit + SQLite `:memory:`**, тройная изоляция как Vizion (`phpunit.xml` force + `.env.testing` + guard в `TestCase`). Тесты НИКОГДА не ходят в живую БД.
- **Feature-тест на каждый endpoint**, **Unit-тест на каждый Service**. AI/HTTP — мокать. Pest запрещён (только PHPUnit).

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

- **Делегирование по правилу №1** (whitelist в CLAUDE.md). Main не пишет код, не дебажит, не гоняет artisan/docker.
- **Цепочка фичи:** агент → (если был UI у `frontend-specialist`) `qa-tester` → `product-manager` (ревью + verify против ARCHITECTURE.md/PLAN.md) → апрув юзера → (по явной просьбе) `deploy-engineer`.
- **Рабочий цикл агента:** `./examples/contracts/` (ТЗ) → `./examples/vizion/` (паттерн) → корень (`src/`/`front/`).
- **Деплой/push** — только по явной прямой просьбе.

---

> Если ARCHITECTURE.md и реальный паттерн Vizion (`./examples/vizion/`) расходятся — Vizion прав; `product-manager` правит этот файл с аппрувом. Этот документ — закон проекта.
