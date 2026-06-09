# Deploy

## Production
- **VPS**: Selectel `153.80.193.132`
- **Path**: `/opt/macro-contracts/`
- **Reverse proxy**: общий Traefik на сервере (`/opt/traefik/`) с auto-SSL через Let's Encrypt
- **Network**: подключаемся к external Docker network `proxy`

## Контейнеры
- `macro-contracts-db` — Postgres 16
- `macro-contracts-api-1` / `-2` — FastAPI + LibreOffice + pandoc (генерация .docx/.pdf). **2 реплики** (replicas:2), масштабируются → нет фикс-имени. Поллинг ВЫКЛ (`RUN_TELEGRAM_POLLING=false`).
- `macro-contracts-bot` — тот же образ, что api, но `command: python -m app.run_bot`: только Telegram long-polling (1 экземпляр, иначе Telegram 409). HTTP-ингресса нет.
- `macro-contracts-web` — Next.js standalone build (1 экземпляр)

## Поток деплоя (zero-downtime)
1. `git push origin main`
2. GitHub Actions (`.github/workflows/deploy.yml`) — SSH на VPS
3. `git fetch && git reset --hard origin/main`
4. `bash deploy/rolling-restart.sh` — собирает api+web, поднимает НОВЫЕ api-реплики, ждёт их healthy, гасит старые (Traefik всё время балансит на живые → нет окна 404), затем обновляет bot и web.
5. Миграции: каждая api-реплика прогоняет `alembic upgrade head` перед `uvicorn`, сериализуясь через `pg_advisory_xact_lock` (`alembic/env.py`) — двойного применения нет.

## Первый запуск
```bash
# 1. Создать .env из .env.example, заполнить все секреты
cp .env.example .env
# отредактировать .env

# 2. Положить Google service account JSON
cp /path/to/sa.json deploy/secrets/google_sa.json

# 3. Запустить bootstrap (сделает SSH-ключ, GitHub Secrets, clone на VPS, docker up)
./deploy/bootstrap.sh
```

## DNS
Перед первым `docker compose up` нужно настроить A-запись:
```
contracts.macroglobal.tech  →  153.80.193.132
```
иначе Let's Encrypt не выпустит сертификат.

## Бэкапы
`deploy/backup.sh` делает ежедневный `pg_dump`. Поставить в cron:
```bash
ssh root@153.80.193.132 'echo "0 3 * * * /opt/macro-contracts/deploy/backup.sh >> /var/log/macro-contracts-backup.log 2>&1" | crontab -'
```

## Полезные команды
```bash
# Логи
ssh -i ~/.ssh/macro_contracts_deploy root@153.80.193.132 \
  'cd /opt/macro-contracts && docker compose logs -f api'

# Подключиться к Postgres
ssh -i ~/.ssh/macro_contracts_deploy root@153.80.193.132 \
  'cd /opt/macro-contracts && docker compose exec db psql -U contracts -d contracts'

# Принудительный recreate (перечитывает .env)
ssh -i ~/.ssh/macro_contracts_deploy root@153.80.193.132 \
  'cd /opt/macro-contracts && docker compose up -d --force-recreate'

# Ручной запуск миграции
ssh -i ~/.ssh/macro_contracts_deploy root@153.80.193.132 \
  'cd /opt/macro-contracts && docker compose exec api alembic upgrade head'
```

## Откат
```bash
ssh -i ~/.ssh/macro_contracts_deploy root@153.80.193.132 \
  'cd /opt/macro-contracts && git log --oneline -10'

# Откатиться на предыдущий sha
ssh -i ~/.ssh/macro_contracts_deploy root@153.80.193.132 \
  'cd /opt/macro-contracts && git reset --hard <sha> && docker compose up -d --build'
```
