# MACRO Contract Generator

Внутренний инструмент MACRO Global для генерации сублицензионных договоров
по продуктам (MacroCRM, MacroSales, MACRO ERP) и странам (KZ, UZ).

## Архитектура

- **api/** — FastAPI + SQLAlchemy 2 async + PostgreSQL + docxtpl + LibreOffice headless
- **web/** — Next.js 14 (App Router) + TypeScript + Tailwind + shadcn-style компоненты
- **bot/** — aiogram 3 (Telegram согласования)
- **templates/contracts_master/** — Master skeleton + продуктовые/страновые YAML
- **deploy/** — bootstrap-скрипт для первичной установки на VPS

## Роли

| Роль | Что может |
|---|---|
| Admin | Всё, включая управление пользователями и шаблонами |
| Director | Согласовывает договоры, генерирует |
| Lawyer | Работает с шаблонами и продуктовыми конфигами, генерирует |
| Manager | Только создаёт и генерирует свои договоры |

## Локальный запуск

### Вариант 1 — только локальная БД через docker, api/web на хосте (рекомендуется для разработки)

```bash
# Поднять Postgres локально
docker compose -f docker-compose.dev.yml up -d db

# Создать .env для api
cp .env.dev.example apps/api/.env

# Применить миграции
cd apps/api && .venv/bin/alembic upgrade head

# Запустить api (с auto-reload)
cd apps/api && .venv/bin/uvicorn app.main:app --reload --port 8000

# Запустить frontend (в другом терминале)
cd apps/web && npm run dev
# web: http://localhost:3000  /  api: http://localhost:8000
```

### Вариант 2 — всё в docker (как в проде)

```bash
cp .env.example .env  # заполнить переменные
docker compose up -d --build
# api: http://localhost:8000
# web: http://localhost:3000
```

## Деплой

Прод: VPS Selectel `153.80.193.132`, путь `/opt/macro-contracts`, reverse-proxy через общий Traefik
(`/opt/traefik`), домен задаётся в `.env` и подставляется в Traefik labels.

Первичная установка: `./deploy/bootstrap.sh`. Дальше — push в main → GitHub Actions деплоит сам.

См. `deploy/README.md` для деталей.
