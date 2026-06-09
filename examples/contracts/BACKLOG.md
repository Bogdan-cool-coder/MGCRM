# MACRO CRM — Backlog

> **Реальный backlog хранится в Obsidian vault.**

**Single source of truth:**
- `/Users/bogdanadykin/Documents/Obsidian Vault/Contracts MACRO/5. Планы/Backlog.md`

В Obsidian:
- Секция «MACRO CRM — за рамками 12 эпиков (новое, 2026-05-30)» — все идеи усилений, не вошедшие в Master Roadmap
- Секции legacy ниже — отложенные идеи из периода «Contract Generator» (до переосмысления 30 мая 2026)

## Технический долг из Эпика 13 (онбординг)

Реализован MVP. Следующие пункты выходят за рамки MVP и откладываются до явного запроса.

- **Spaced repetition** — через 7 и 30 дней после прохождения квиза переспрашивать top-3 вопроса, по которым была ошибка. Требует scheduler + поле `next_review_at` в `quiz_attempts`.
- **Sandbox/practice mode (Bloom L3)** — режим «попробовать в системе» с тестовыми данными; изолированная среда. Требует отдельного контекста данных.
- **Text answers с AI evaluation** — открытые вопросы в квизе + оценка через Claude Haiku (regex/embedding match). Требует эпика 7 (Claude API) как зависимости.
- **Сертификаты PDF** — генерация сертификата о прохождении через docxtpl шаблон по аналогии с договорами.
- **Версионирование курсов** — `course_versions` таблица при изменении содержимого; назначения привязаны к версии.
- **Drag-and-drop reorder** — заменить кнопки ↑↓ в `CourseStructureBuilder` на `@dnd-kit`. MVP — кнопки.
- **Перемешивание вопросов** — randomize порядка вопросов на каждой попытке (сейчас только options перемешиваются).
- **Manager dashboard «Прогресс моих подчинённых»** — таблица подчинённых × курсы с drill-down. Актуально при росте команды до 25+ человек.
- **Background recompute overdue cron** — сейчас `has_overdue_mandatory()` = live SQL на каждый запрос. При росте таблиц нужен материализованный `assignment_status` + hourly cron.
- **Sidebar badge debounce 60s** — `useOnboardingBadge` сейчас на `SWR refreshInterval`. Можно сократить hits через debounce на сервере.
- **`course.unassigned` webhook event** — `DELETE /admin/onboarding/assignments/{id}` уже есть, но event идёт только в `logger.info`. Добавить в webhook whitelist если потребуется outbound.
- **TG-напоминания об overdue курсах** — зависит от Эпика 7 (TG-бот). Цепочка: `automation_executor` dispatch `tg_notify` при `has_overdue_mandatory`.
- **Геймификация (badges/XP/leaderboard)** — не приоритет для команды 10-20 чел.
- **RBAC-блокировка разделов CRM** при overdue — hard-gate (сейчас только soft-gate на bulk/contracts). Принято сознательное решение не блокировать.

## Финансы (модуль double-entry, Ф0-Ф6)

Технический долг и отложенные на следующие фазы пункты модуля «Финансы».

- **Per-entity scope в list-эндпоинтах (Ф2)** — `GET /finance/operations`, `/journals`, `/entries` при отсутствии `legal_entity_id` возвращают данные ВСЕХ юрлиц. Сейчас безопасно: все фин-роли (accountant/cfo/director/admin) имеют `view_*` со scope=NULL (на все юрлица), на проде KZ+UZ видны всем по матрице прав — утечки нет. Когда в Ф2 появятся per-entity права (юзер видит только своё юрлицо), эти list-эндпоинты ОБЯЗАНЫ фильтровать выборку по доступным юрлицам ИЛИ требовать `legal_entity_id`. Нужен helper `accessible_legal_entity_ids(user, capability)` в `services/finance/access.py` (перечисление юрлиц, по которым `fin_can` → True). В роутере оставлены W-комментарии в местах фильтрации.
- **Защита от self-grant прав (Ф2)** — матрица прав `fin_permission` не защищена от self-grant: юзер с `manage_settings` может через `PUT /finance/permissions` расширить себе scope/capabilities (включая на чужое юрлицо). Закрыть при введении per-entity прав в Ф2 — запретить модификацию строк, где `user_id == текущий_юзер`, либо требовать отдельную capability `manage_own_permissions` (по умолчанию выкл).

## Главный roadmap

См. [PLAN.md](./PLAN.md) и `/Users/bogdanadykin/Documents/Obsidian Vault/Contracts MACRO/5. Планы/MACRO CRM — Master Roadmap.md`
