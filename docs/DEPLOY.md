# MGCRM — First Deploy Runbook

This document covers the one-time VPS bootstrap and all subsequent deploys
via GitHub Actions for **mgcrm.macroglobal.tech**.

> Claude does not have SSH access. All VPS commands below are run **by you** in
> an SSH session to the server.

---

## 0. Pre-conditions

| What | Status |
|---|---|
| DNS `mgcrm.macroglobal.tech` → VPS IP | already done |
| Traefik running on VPS (80/443, Let's Encrypt) | assumed (shared VPS) |
| You know the Traefik external network name and certresolver name | see §2 |

---

## 1. SSH into the VPS

```bash
ssh <user>@<vps-ip>
# e.g. ssh root@153.80.x.x
```

---

## 2. Find Traefik network and certresolver names

```bash
# External Traefik network name:
docker network ls | grep traefik

# Certresolver name (check Traefik static config):
cat /opt/traefik/traefik.yml | grep certResolver   # or .toml equivalent
# Common values: letsencrypt | le | myresolver
```

Write these down — you will need them in step 4.

---

## 3. Create the project directory and clone

```bash
mkdir -p /opt/mgcrm
cd /opt/mgcrm
git clone https://github.com/Bogdan-cool-coder/MGCRM.git .
```

---

## 4. Create the root `.env` (compose vars + Traefik)

```bash
cp .env.example .env
nano .env   # or vim
```

Fill in:

```dotenv
COMPOSE_PROJECT_NAME=macro-crm
IMAGE_TAG=latest
APP_BIND=127.0.0.1
APP_PORT=8080
FRONTEND_PORT=3000
POSTGRES_HOST_PORT=5432
REDIS_HOST_PORT=6379

# Your domain (DNS already points here)
APP_DOMAIN=mgcrm.macroglobal.tech

# FROM STEP 2:
TRAEFIK_NETWORK=<traefik network name>     # e.g. traefik-public
TRAEFIK_CERTRESOLVER=<resolver name>       # e.g. letsencrypt

# DB credentials (keep in sync with src/.env below)
DB_DATABASE=macro_crm
DB_USERNAME=macro_crm
DB_PASSWORD=<generate a strong random password>
```

---

## 5. Create `src/.env` (Laravel / application secrets)

```bash
cp src/.env.example src/.env
nano src/.env
```

Set the following for production — **get secret values from your local `src/.env`**:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://mgcrm.macroglobal.tech
APP_KEY=                         # generate below

# DB — match root .env
DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=macro_crm
DB_USERNAME=macro_crm
DB_PASSWORD=<same strong password as root .env>

# Redis
REDIS_CLIENT=predis
REDIS_HOST=redis
REDIS_PASSWORD=null
REDIS_PORT=6379

QUEUE_CONNECTION=redis
CACHE_STORE=redis

# Sanctum
SANCTUM_TOKEN_EXPIRATION=

# 2FA
TWOFA_ISSUER="MACRO Global CRM"
TWOFA_WINDOW=1
TWOFA_BACKUP_CODES=8

# Admin seed (first boot only)
ADMIN_EMAIL=<your admin email>
ADMIN_PASSWORD=<strong password>
ADMIN_NAME="MGCRM Admin"

# Anthropic (AI features)
ANTHROPIC_API_KEY=<your key>

# Telegram bot
TELEGRAM_BOT_TOKEN=<token>
TELEGRAM_BOT_USERNAME=<@username>
TELEGRAM_APPROVAL_CHAT_ID=<chat id>
TELEGRAM_LINK_TTL_MINUTES=10
TELEGRAM_WEB_BASE_URL=https://mgcrm.macroglobal.tech
RUN_TELEGRAM_POLLING=false        # bot container sets its own override

# Mail (SMTP)
MAIL_MAILER=smtp
MAIL_HOST=<smtp host>
MAIL_PORT=465
MAIL_USERNAME=<smtp user>
MAIL_PASSWORD=<smtp password>
MAIL_FROM_ADDRESS=noreply@macroglobal.tech
MAIL_FROM_NAME="MACRO Global CRM"

# Gotenberg — injected by compose
GOTENBERG_URL=http://gotenberg:3000

# Log level for production
LOG_LEVEL=warning
```

---

## 6. Generate the application key

```bash
# php is not on the host — run through the image
docker compose run --rm --no-deps -e APP_KEY= app php artisan key:generate --show
# Copy the output (base64:xxx) and paste into src/.env → APP_KEY=base64:xxx
```

---

## 7. Create required host directories

```bash
mkdir -p /opt/mgcrm/data/backups
```

---

## 8. Connect the Traefik network

The `frontend` container must join the existing Traefik external network.
The name is what you set as `TRAEFIK_NETWORK` in root `.env`.
No extra commands needed — compose does this automatically on first `up`.

If Traefik requires the network to already exist as external:
```bash
# Only if compose errors with "network not found":
docker network create traefik-public   # use your actual network name
```
Usually the network already exists because Traefik created it.

---

## 9. Build and start the stack (first boot)

```bash
cd /opt/mgcrm

# Build all images (this takes a few minutes — PHP ext compile + npm ci + vite build)
docker compose build

# Start everything
docker compose up -d
```

Expected running containers:
- `macro-crm-postgres`
- `macro-crm-redis`
- `macro-crm-app`
- `macro-crm-nginx`
- `macro-crm-frontend`
- `macro-crm-queue-worker`
- `macro-crm-scheduler`
- `macro-crm-gotenberg`
- `macro-crm-bot`

```bash
docker compose ps
```

---

## 10. Run first-time migrations and seeds

```bash
# Wait for postgres to be ready
docker compose exec postgres pg_isready -U macro_crm

# Migrate
docker compose exec app php artisan migrate --force

# Seed admin user + roles
docker compose exec app php artisan db:seed --force
```

---

## 11. Verify the site

```bash
# Check TLS + SPA is served
curl -I https://mgcrm.macroglobal.tech

# Check backend /up
curl https://mgcrm.macroglobal.tech/api/up
# Expected: HTTP 200, body: {"status":"ok"}

# Check Traefik assigned a certificate
# Open https://mgcrm.macroglobal.tech in your browser — should show a valid LE cert
```

---

## 12. Set up GitHub Actions secrets

Go to: **GitHub → MGCRM repo → Settings → Secrets → Actions → New repository secret**

| Secret name | Value |
|---|---|
| `SSH_HOST` | VPS IP or hostname |
| `SSH_USER` | SSH login user (e.g. `root`) |
| `SSH_PRIVATE_KEY` | Contents of your private key (`cat ~/.ssh/id_ed25519`) |
| `SSH_PORT` | SSH port (usually `22`) |

No other secrets are required by default (src/.env is on the server, not in GitHub).

Optional — if you want the deploy workflow to write `src/.env` automatically:

| `ENV_PROD` | Full contents of your prod `src/.env` |

---

## 13. Trigger a deploy

1. Go to **GitHub → MGCRM → Actions → Deploy**.
2. Click **Run workflow** → select branch `main` → **Run workflow**.
3. Watch the run. Expected duration: 3–8 min (cold build) or ~2 min (layer-cached rebuild).
4. After success, re-check `https://mgcrm.macroglobal.tech`.

---

## 14. Bot service note

The Telegram bot (`macro-crm-bot`) long-polls and must run as a **single instance**.
It is restarted separately by the rolling-restart script via `--force-recreate`.
If the bot is down, restart manually:

```bash
docker compose up -d --force-recreate bot
docker compose logs -f bot
```

---

## Rollback

If a deploy breaks something:

```bash
cd /opt/mgcrm

# Roll back to the previous commit
git log --oneline -5       # find the last good SHA
git reset --hard <sha>

# Rebuild and restart
bash deploy/rolling-restart.sh
```

---

## Useful day-to-day commands

```bash
# Tail all logs
docker compose logs -f

# Tail specific service
docker compose logs -f app
docker compose logs -f frontend

# Container status
docker compose ps

# Manual migration (after a hotfix)
docker compose exec -T app php artisan migrate --force

# Clear caches
docker compose exec -T app php artisan config:clear
docker compose exec -T app php artisan cache:clear
```
