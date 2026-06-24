# Аудит MACRO Global CRM — индекс

> Полный сквозной аудит системы (Laravel 13 API + Vue 3.5 SPA), дата **2026-06-24**.
> Три фазы: структурное картирование 17 доменов → адверсариальная верификация blocker+major (live-probe) → живой браузерный прогон (3 роли, 12 journey).
> Severity везде — **пост-верификационная** (`finalSeverity`).

## Начать отсюда

➡️ **[00-MASTER.md](00-MASTER.md)** — мастер-отчёт: резюме, методология, карта доменов, сквозные паттерны, глобальный бэклог (17 blocker + 65 major), что работает, RBAC, план актуализации vault, дорожная карта починки.

## Итоговые цифры

| | |
|---|---|
| Доменов | 17 |
| 🔴 Blocker | 17 |
| 🟠 Major | 65 |
| 🟡 Minor | 119 |
| ⚪ Trivial | 29 |
| **Всего проблем** | **230** |

## Сквозные документы

- **[RBAC-matrix.md](RBAC-matrix.md)** — как авторизация устроена на самом деле, матрица «действие × роль», единый authz-бэклог, приоритеты.
- **[process-map.md](process-map.md)** — карта бизнес-процессов: сквозной жизненный цикл, mermaid-схемы, статусы узлов по всему потоку.

## Доменные отчёты

| Домен | Файл |
|---|---|
| IAM + Org | [domains/iam.md](domains/iam.md) |
| CRM — Контакты | [domains/crm-contacts.md](domains/crm-contacts.md) |
| CRM — Компании | [domains/crm-companies.md](domains/crm-companies.md) |
| Каталог | [domains/catalog.md](domains/catalog.md) |
| Продажи — Сделки/Kanban | [domains/sales-deals.md](domains/sales-deals.md) |
| Продажи — KPI | [domains/sales-kpi.md](domains/sales-kpi.md) |
| Продажи — Дашборд | [domains/sales-dashboard.md](domains/sales-dashboard.md) |
| Активности/Задачи | [domains/activity.md](domains/activity.md) |
| Inbox/Интейк | [domains/inbox.md](domains/inbox.md) |
| Договоры — Шаблоны | [domains/contracts-templates.md](domains/contracts-templates.md) |
| Договоры — Документы | [domains/contracts-documents.md](domains/contracts-documents.md) |
| Онбординг | [domains/onboarding.md](domains/onboarding.md) |
| Автоматизации | [domains/automation.md](domains/automation.md) |
| Уведомления | [domains/notification.md](domains/notification.md) |
| SalesPulse | [domains/salespulse.md](domains/salespulse.md) |
| Миграция AMO ETL | [domains/migration.md](domains/migration.md) |
| Сквозное — Лог + оболочка | [domains/log-shell.md](domains/log-shell.md) |

## Live ground-truth

`/tmp/mgcrm_audit/`: `schema.sql` (живая схема), `rowcounts.txt` (наполнение), `live-qa.md` (браузерный прогон), `verify/*.json` (вердикты верификации).

> Зеркало отчёта в Obsidian-vault: `MG CRM 2026/8. Аудит/00 — Мастер-отчёт аудита.md`.
