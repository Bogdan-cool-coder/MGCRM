# MACRO CRM — План

> **Реальный план хранится в Obsidian vault** — там его удобнее редактировать в Obsidian-граф и он не засоряет git-историю мелкими формулировочными правками.

**Single source of truth:**
- Главный roadmap (12 эпиков, 4-5 месяцев до отказа от AmoCRM): `/Users/bogdanadykin/Documents/Obsidian Vault/Contracts MACRO/5. Планы/MACRO CRM — Master Roadmap.md`
- Backlog (отложенные идеи): `/Users/bogdanadykin/Documents/Obsidian Vault/Contracts MACRO/5. Планы/Backlog.md`
- Текущая активная задача: `/Users/bogdanadykin/Documents/Obsidian Vault/Contracts MACRO/4. Активная работа/SESSION_STATE.md`
- Журнал изменений по дням: `/Users/bogdanadykin/Documents/Obsidian Vault/Contracts MACRO/3. Журнал/`

## Кратко — что строим

**MACRO CRM** — собственная CRM-система как полная замена AmoCRM для команды продаж MACRO Global Technologies. Не интеграция, не bridge — замена.

Репо называется `MACRO Contract generator` исторически (изначальный фокус был на договорах). После переосмысления 30 мая 2026 — это полноценная CRM с уникальным юр.документооборотом + CS-реестром + replacement AmoCRM-функционалом.

## 12 эпиков (заголовки)

| # | Эпик | Срок | Статус |
|---|---|---|---|
| 0 | Закрыть хвосты (OnlyOffice финал, стабилизация Ф4) | 1-2 дня | ⏳ в работе |
| 1 | Contact + Company + Lead (фундамент) | 5-7 дней | ⏳ старт |
| 2 | Activities + Timeline (фундамент) | 4-5 дней | планы |
| 3 | Документооборот 2.0 | 3 дня | планы |
| 4 | PipelineAutomation (стратегический core) | 5-7 дней | планы |
| 5 | Inbox + каналы (TG/WA/Email/Forms) | 5-7 дней | планы |
| 6 | Renewal + Bulk + Конверсии | 3-4 дня | планы |
| 7 | TG-бот команды продаж (порт MACRO Auto) | 4-5 дней | планы |
| 8 | Карточка 2.0 (custom fields, дубли, audit) | 4-5 дней | планы |
| 9 | Импорт AmoCRM + миграция | 5-7 дней | планы (КРИТИЧНО) |
| 10 | KPI + plan vs fact + Mobile | 4-5 дней | планы |
| 11 | Public API + Webhooks + интеграции | 4-5 дней | планы |
| 12 | Enterprise + AI | постоянно | планы |

Детали и архитектурные решения — в Obsidian Master Roadmap.

## Уже отгружено (на 30 мая 2026, HEAD `07d1959`)

- Backend FastAPI + SQLAlchemy 2.0 + Alembic + cookie JWT
- Frontend Next.js 14 app router + SWR + Tailwind
- Auth + роли (admin/lawyer/director/manager)
- Sales pipeline (12 AmoCRM-style этапов: Входящие лиды / Исходящие лиды / Квалификация / Назначить встречу / Выезд / Встреча / Холодные (заморозка) / Тёплые / Trial / Горячие / Успех / Проигрыш — см. `seed_pipeline` в `apps/api/app/services/deals.py`)
- Lifecycle pipeline (B0–B6 / A1–A6 / C0)
- Counterparty / Contract / Subscription
- ApprovalRoute / Approval engine
- Шаблоны (docxtpl + LibreOffice → PDF)
- OnlyOffice WYSIWYG (с одним нефатальным `onError` для диагностики)
- Аналитика + Excel экспорт
- TG-бот approvals
- CI/CD (GHA → rolling-restart zero-downtime)
- Импорт реестра (121+9 КА / 128 подписок)
