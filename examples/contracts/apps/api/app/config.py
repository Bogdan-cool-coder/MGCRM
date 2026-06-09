from functools import lru_cache
from pathlib import Path

from pydantic_settings import BaseSettings, SettingsConfigDict

# P0 Security: значения jwt_secret, которые недопустимы в production.
# Любой из них (плюс слишком короткий секрет) → отказ загрузки в проде.
WEAK_JWT_SECRETS = frozenset(
    {
        "change-me",
        "changeme",
        "changeme_use_openssl_rand_hex_64",
        "dev-secret-replace-in-production",
        "secret",
        "",
    }
)
# Минимальная длина «сильного» секрета. openssl rand -hex 64 → 128 chars,
# но для HS256 достаточно 32+ байт энтропии. Берём 32 как нижнюю границу.
MIN_JWT_SECRET_LENGTH = 32


def is_weak_jwt_secret(secret: str) -> bool:
    """Pure-predicate: True если jwt_secret слабый/дефолтный.

    Используется в startup-guard (validate_production_secrets) и в тестах.
    Слабым считаем: пустой, из чёрного списка дефолтов, или короче
    MIN_JWT_SECRET_LENGTH символов.
    """
    s = (secret or "").strip()
    if s in WEAK_JWT_SECRETS:
        return True
    return len(s) < MIN_JWT_SECRET_LENGTH


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_file=".env", extra="ignore")

    # App
    app_env: str = "production"
    log_level: str = "INFO"
    public_base_url: str = "http://localhost:8000"
    tz: str = "Asia/Almaty"

    # DB
    db_user: str = "contracts"
    db_password: str = "contracts"
    db_name: str = "contracts"
    db_host: str = "db"
    db_port: int = 5432

    # Auth
    jwt_secret: str = "change-me"
    jwt_algorithm: str = "HS256"
    jwt_expire_hours: int = 12

    # First admin
    admin_email: str = "admin@example.com"
    admin_password: str = "admin"
    admin_name: str = "Admin"

    # Telegram
    telegram_bot_token: str = ""
    telegram_approval_chat_id: str = ""
    # Поллер запускается только там, где этот флаг включён. В проде polling живёт в
    # отдельном bot-сервисе (replicas:1), а api-реплики ставят RUN_TELEGRAM_POLLING=false,
    # иначе несколько getUpdates конфликтуют (Telegram 409). Локально по умолчанию True.
    run_telegram_polling: bool = True

    # Google Drive
    # По умолчанию — в writable storage volume (грузится через UI /admin/integrations).
    # Можно переопределить через ENV (например /run/secrets/google_sa.json при ручной заливке).
    google_service_account_json_path: str = "/data/storage/secrets/google_sa.json"

    # Storage
    storage_path: str = "/data/storage"
    templates_path: str = "/app/templates/contracts_master"

    # SMTP (Эпик 4.1: email-action в автоматизациях).
    # Опционально: если SMTP_HOST не задан — action 'email' пишет в AutomationRun
    # status='failed' + result_json={"status":"smtp_not_configured"} и не падает
    # наружу. Полноценный SMTP-pool/queue — отложено (см. integration-specialist).
    smtp_host: str | None = None
    smtp_port: int = 587
    smtp_user: str | None = None
    smtp_pass: str | None = None
    smtp_from: str | None = None
    smtp_use_tls: bool = True

    # Epic 16 — Security: Redis для rate-limit + debounce last_used.
    # Если не задано / Redis недоступен → graceful fallback (всегда allowed,
    # last_used обновляется каждый раз). См. app/services/redis_client.py.
    redis_url: str = ""

    # Epic 16 — Security: TOTP-secret encryption key (Fernet base64).
    # Генерится `Fernet.generate_key()` → base64-URL-safe 44 chars. Если
    # пусто → /api/auth/2fa/* возвращают 503 «2FA not configured».
    # См. app/services/totp.py::encrypt_secret/decrypt_secret.
    totp_encryption_key: str = ""

    # Epic 16 — Security: 2FA temp token TTL. После пароля выдаём
    # temp_2fa_token cookie на 5 минут; за это окно юзер должен ввести код.
    totp_temp_token_ttl_minutes: int = 5

    # Epic 16 — Security: Google / Yandex OAuth 2.0 для SSO.
    # Если client_id пуст → /api/auth/sso/{provider}/* возвращают 503.
    google_client_id: str = ""
    google_client_secret: str = ""
    google_allowed_hd: str = "macroglobaltech.com"  # ограничение Google Workspace домена
    yandex_client_id: str = ""
    yandex_client_secret: str = ""

    # Эпик 24.2 — Google Calendar 2-way sync (per-user OAuth).
    # Отдельные client_id/secret от SSO — потому что scope разный (нужен
    # calendar.events + offline_access для refresh_token), и в Google Console
    # это обычно отдельный OAuth Client. Если пусто — НАСЛЕДУЕМ google_client_id/
    # google_client_secret (используем тот же OAuth Client что и SSO; разрешено,
    # если добавить https://www.googleapis.com/auth/calendar.events в его scope).
    # Если оба пути пусты → /api/me/google-calendar/* возвращают 503.
    google_oauth_client_id: str = ""
    google_oauth_client_secret: str = ""
    # redirect_uri зарегистрированный в Google Console (https://contracts.
    # macroglobal.tech/api/me/google-calendar/callback). Если пусто — собираем
    # из public_base_url.
    google_oauth_redirect_uri: str = ""
    # Fernet ключ для шифрования access/refresh tokens. Если пусто —
    # fallback на totp_encryption_key (тот же Fernet алгоритм, переиспользуем
    # ключ чтобы не плодить env'ы). Если оба пусты → 503.
    google_oauth_encryption_key: str = ""

    @property
    def gcal_ready(self) -> bool:
        """Google Calendar sync доступен только когда настроены OAuth client
        + encryption ключ (fallback на totp_encryption_key OK)."""
        client_id = self.google_oauth_client_id or self.google_client_id
        client_secret = (
            self.google_oauth_client_secret or self.google_client_secret
        )
        enc_key = self.google_oauth_encryption_key or self.totp_encryption_key
        return bool(client_id and client_secret and enc_key)

    @property
    def gcal_client_id(self) -> str:
        """Effective Google OAuth client_id для Calendar (fallback на SSO)."""
        return self.google_oauth_client_id or self.google_client_id

    @property
    def gcal_client_secret(self) -> str:
        """Effective Google OAuth client_secret для Calendar (fallback на SSO)."""
        return self.google_oauth_client_secret or self.google_client_secret

    @property
    def gcal_encryption_key(self) -> str:
        """Effective Fernet ключ для шифрования gcal tokens (fallback на TOTP)."""
        return self.google_oauth_encryption_key or self.totp_encryption_key

    @property
    def gcal_redirect_uri(self) -> str:
        """Effective redirect URI; fallback — собираем из public_base_url."""
        if self.google_oauth_redirect_uri:
            return self.google_oauth_redirect_uri
        base = (self.public_base_url or "").rstrip("/")
        return f"{base}/api/me/google-calendar/callback"

    # OnlyOffice Document Server (WYSIWYG-редактор master_skeleton.docx).
    # Включается только когда заданы секрет и публичный URL (см. onlyoffice_ready).
    # Деплой кода инертен: пока ENV не выставлен — эндпоинты отдают 503, кнопка скрыта.
    onlyoffice_enabled: bool = False
    onlyoffice_jwt_secret: str = ""
    # DS → наш API (внутренняя docker-сеть proxy/default): скачивание исходника + callback
    onlyoffice_internal_api_url: str = "http://api:8000"
    # наш API → DS (внутренняя сеть): забрать отредактированный .docx из кэша DS
    onlyoffice_internal_ds_url: str = "http://onlyoffice"
    # браузер → DS (через Traefik/TLS): откуда грузить api.js редактора
    onlyoffice_public_url: str = ""

    # Эпик 18 — AI Features: Anthropic Claude API.
    # Если ANTHROPIC_API_KEY пусто → AI-эндпоинты возвращают 503 «AI not
    # configured» (graceful — фронт показывает понятное сообщение, кнопка
    # disabled). Модель по умолчанию — Sonnet 4.5 (для contract-analyze).
    # Для лёгких задач (deal-prefill, summarize) можно перекрыть параметром
    # `model=...` в call_claude() — пока хардкодим Sonnet везде, оптимизация
    # на Haiku — в эпике 10.5 (AI Assistant Chat).
    anthropic_api_key: str = ""
    anthropic_model: str = "claude-sonnet-4-5"

    # Epic 10.5 — Мультивалютность: exchangerate-api.com v6.
    # Если пусто → fetch_rates_from_api() возвращает {} (graceful), курсы
    # нужно вводить вручную через POST /api/admin/currency-rates.
    # Бесплатный ключ: 1500 запросов/мес, обновление раз в сутки в норме.
    exchange_rate_api_key: str = ""

    # Эпик 24.3 — TG Bot API: секрет для защиты /api/tg-bot/* эндпоинтов.
    # Бот-сервис передаёт в Authorization: Bearer <tg_bot_api_secret>.
    # P0 Security: FAIL-CLOSED — если пусто, /api/tg-bot/intent отдаёт 503
    # для ВСЕХ (раньше fail-open пропускал всех → impersonation). В проде
    # ОБЯЗАТЕЛЬНО задать TG_BOT_API_SECRET (для api И bot контейнеров).
    tg_bot_api_secret: str = ""

    # Эпик 15 — Whisper integration: OpenAI API key для транскрипции записей звонков.
    # Если пусто — /api/integrations/calldown/calls/{id}/transcribe возвращает 503
    # (graceful fallback — фронт скрывает кнопку). Сервис services/whisper.py читает
    # из os.environ напрямую, но дублируем в Settings для visibility.
    openai_api_key: str = ""

    # Sentry error-tracking (SaaS). Полный no-op при пустом sentry_dsn —
    # init пропускается, dev + текущий прод работают без изменений. НЕ хардкодим
    # DSN: SENTRY_DSN задаётся в .env (секрет пишет только main-сессия).
    sentry_dsn: str = ""
    # Доля транзакций для performance-трейсинга. 0.05 = 5% — дёшево, но даёт
    # видимость медленных эндпоинтов. Только при заданном DSN.
    sentry_traces_sample_rate: float = 0.05
    # Release-тег (обычно git SHA), передаётся через SENTRY_RELEASE. Если пусто —
    # release=None (Sentry не привяжет ошибки к конкретному деплою, это ОК).
    sentry_release: str = ""

    # UptimeRobot → Telegram alert bridge.
    # uptimerobot_webhook_secret защищает PUBLIC webhook /api/integrations/
    # uptime-webhook (передаётся как ?secret= или заголовок X-Webhook-Secret).
    # FAIL-CLOSED: если пусто → endpoint отдаёт 503 для ВСЕХ (никакого
    # unauthenticated forwarding в Telegram). telegram_alert_* — те же креды,
    # что использует deploy/scripts/lib-telegram-alert.sh (один alert-бот для
    # мониторинга). Если alert-креды пусты → endpoint логирует и отдаёт 200
    # (не падает), но сообщение не уходит.
    uptimerobot_webhook_secret: str = ""
    telegram_alert_bot_token: str = ""
    telegram_alert_chat_id: str = ""

    @property
    def uptime_webhook_ready(self) -> bool:
        """Webhook принимает вызовы только когда задан shared-secret."""
        return bool((self.uptimerobot_webhook_secret or "").strip())

    @property
    def sentry_environment(self) -> str:
        """Окружение для Sentry — выводим из app_env (development|production)."""
        return (self.app_env or "production").strip().lower() or "production"

    @property
    def sentry_enabled(self) -> bool:
        """Sentry активен только при заданном DSN (иначе полный no-op)."""
        return bool((self.sentry_dsn or "").strip())

    # P0 security — SSRF guard для исходящих webhook'ов.
    # По умолчанию исходящие webhook'и НЕ могут стучаться в приватные/loopback/
    # link-local адреса (защита от чтения cloud-metadata 169.254.169.254 и
    # внутренних сервисов db/api/onlyoffice). Если self-hosted-сетапу нужны
    # internal-таргеты — задать явный allowlist хостов (comma-separated):
    #   WEBHOOK_SSRF_ALLOWLIST=internal-hook.local,10.0.0.5
    # Хосты из allowlist пропускаются БЕЗ проверки IP-диапазонов.
    webhook_ssrf_allowlist: str = ""
    # Разрешать произвольные порты (по умолчанию только 80/443). Включать
    # только если подписчики реально слушают нестандартные порты.
    webhook_ssrf_allow_any_port: bool = False

    @property
    def ssrf_allowlist_hosts(self) -> frozenset[str]:
        """Множество хостов, для которых SSRF-проверка IP-диапазонов пропускается."""
        return frozenset(
            h.strip().lower()
            for h in self.webhook_ssrf_allowlist.split(",")
            if h.strip()
        )

    @property
    def whisper_ready(self) -> bool:
        """Whisper integration активен только при наличии OPENAI_API_KEY."""
        return bool(self.openai_api_key)

    @property
    def ai_ready(self) -> bool:
        """AI-эндпоинты включены только при наличии Anthropic API key."""
        return bool(self.anthropic_api_key)

    @property
    def onlyoffice_ready(self) -> bool:
        """Редактор доступен только при включённом флаге, секрете и публичном URL DS."""
        return bool(
            self.onlyoffice_enabled
            and self.onlyoffice_jwt_secret
            and self.onlyoffice_public_url
        )

    @property
    def is_production(self) -> bool:
        """True если НЕ dev-окружение. Совпадает с конвенцией cookie-secure
        (`app_env != "development"`) — всё, что не явный development, считаем
        production (включая дефолт app_env='production')."""
        return (self.app_env or "").strip().lower() != "development"

    def validate_production_secrets(self) -> None:
        """P0 startup-guard: в production отказываем загрузку при слабом
        jwt_secret. Вызывается из app lifespan — misconfigured prod падает
        на старте, а не работает с подделываемым HS256.

        В dev (app_env=development) — no-op (дефолтные секреты допустимы).

        tg_bot_api_secret НЕ обязателен (интент fail-CLOSED 503 если пуст —
        см. routers/tg_bot.py), но если он задан и слабый — логируем warning.
        """
        if not self.is_production:
            return
        if is_weak_jwt_secret(self.jwt_secret):
            raise RuntimeError(
                "SECURITY: JWT_SECRET слабый или дефолтный в production. "
                "Сгенерируйте сильный секрет: `openssl rand -hex 64` и "
                "задайте JWT_SECRET в .env. Отказ запуска."
            )

    @property
    def database_url(self) -> str:
        return f"postgresql+asyncpg://{self.db_user}:{self.db_password}@{self.db_host}:{self.db_port}/{self.db_name}"

    @property
    def storage_dir(self) -> Path:
        p = Path(self.storage_path)
        p.mkdir(parents=True, exist_ok=True)
        return p

    @property
    def templates_dir(self) -> Path:
        return Path(self.templates_path)


@lru_cache
def get_settings() -> Settings:
    return Settings()
