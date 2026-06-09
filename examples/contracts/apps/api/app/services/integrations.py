"""Эпик 15 — Integration Hub: marketplace + helpers для IntegrationSettings.

Marketplace — hardcoded список доступных integration-провайдеров с
метаданными для UI (название/иконка/category/описание). Реальный enable/
disable хранится в БД (IntegrationSettings).

Чувствительные поля в IntegrationSettings.config шифруются Fernet'ом
(переиспользуем services/totp.py::encrypt_secret/decrypt_secret). Список
sensitive-полей задан per-provider в `SENSITIVE_KEYS`.
"""
from __future__ import annotations

import logging
import secrets
from dataclasses import dataclass
from typing import Any

from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.models import IntegrationSettings

logger = logging.getLogger(__name__)

# ============ Whitelist providers ============

PROVIDERS: frozenset[str] = frozenset({
    "calldown_mango",
    "calldown_uis",
    "whisper",
    "amocrm",
    "telegram",
    "google_drive",
    "yandex_disk",
    "slack",
})


# ============ Per-provider sensitive keys ============
# При записи config'а ключи из этого списка шифруются Fernet'ом.
# При чтении расшифровываются перед возвратом (только админу).

SENSITIVE_KEYS: dict[str, frozenset[str]] = {
    "calldown_mango": frozenset({"api_key", "vpbx_api_key", "vpbx_api_salt"}),
    "calldown_uis": frozenset({"api_key", "access_token"}),
    "whisper": frozenset({"openai_api_key"}),
    "amocrm": frozenset({"access_token", "refresh_token", "client_secret"}),
    "telegram": frozenset({"bot_token"}),
    "google_drive": frozenset({"client_secret", "refresh_token"}),
    "yandex_disk": frozenset({"access_token"}),
    "slack": frozenset({"bot_token", "signing_secret"}),
}


# ============ Marketplace metadata ============

@dataclass(frozen=True)
class MarketplaceEntry:
    """UI-метаданные одной интеграции для admin marketplace страницы."""
    provider: str
    name: str
    category: str       # "calldown" | "ai" | "crm" | "storage" | "messenger"
    icon: str           # Bootstrap Icons class name (e.g. "bi-telephone")
    description: str
    status: str         # "available" | "coming_soon"


# Карта статусов в MVP. По мере реализации обновляем coming_soon → available.
_MARKETPLACE: tuple[MarketplaceEntry, ...] = (
    MarketplaceEntry(
        provider="amocrm",
        name="AmoCRM",
        category="crm",
        icon="bi-arrow-left-right",
        description="Импорт сделок, контактов, компаний из AmoCRM",
        status="coming_soon",
    ),
    MarketplaceEntry(
        provider="telegram",
        name="Telegram",
        category="messenger",
        icon="bi-telegram",
        description="Inbox-канал и уведомления через TG-бот",
        status="available",
    ),
    MarketplaceEntry(
        provider="google_drive",
        name="Google Drive",
        category="storage",
        icon="bi-google",
        description="Выгрузка подписанных договоров",
        status="available",
    ),
    MarketplaceEntry(
        provider="yandex_disk",
        name="Яндекс Диск",
        category="storage",
        icon="bi-cloud",
        description="Альтернативное хранилище документов",
        status="coming_soon",
    ),
    MarketplaceEntry(
        provider="calldown_mango",
        name="Mango Office",
        category="calldown",
        icon="bi-telephone-inbound",
        description="Интеграция Calldown: запись + лог звонков",
        status="available",
    ),
    MarketplaceEntry(
        provider="calldown_uis",
        name="UIS Communications",
        category="calldown",
        icon="bi-telephone-outbound",
        description="Альтернативный Calldown-провайдер",
        status="available",
    ),
    MarketplaceEntry(
        provider="whisper",
        name="OpenAI Whisper",
        category="ai",
        icon="bi-mic",
        description="Транскрипция записей звонков в текст",
        status="available",
    ),
    MarketplaceEntry(
        provider="slack",
        name="Slack",
        category="messenger",
        icon="bi-slack",
        description="Уведомления в каналы Slack",
        status="coming_soon",
    ),
)


def list_marketplace() -> list[MarketplaceEntry]:
    """Хардкод-список доступных интеграций для UI."""
    return list(_MARKETPLACE)


def find_marketplace_entry(provider: str) -> MarketplaceEntry | None:
    for entry in _MARKETPLACE:
        if entry.provider == provider:
            return entry
    return None


def validate_provider(provider: str) -> str:
    """Нормализация имени провайдера. ValueError если незнакомый."""
    if provider not in PROVIDERS:
        raise ValueError(
            f"Неизвестный провайдер: {provider!r}. Допустимые: {sorted(PROVIDERS)}"
        )
    return provider


# ============ Config encryption ============

# Префикс зашифрованных значений в config'е — чтобы при повторной записи не
# шифровать уже шифрованное (idempotency).
_ENC_PREFIX = "enc:"


def _try_encrypt(value: str) -> str:
    """Зашифровать строку Fernet'ом. Бросает если ключ не настроен.

    Если TOTP_ENCRYPTION_KEY не задан — fallback: пишем «as-is с префиксом
    plain:» чтобы не молча терять данные. Это очень-очень не рекомендуется
    для prod (и роутер check'ает в preflight), но запас защиты.
    """
    # Ленивый импорт чтобы избежать circular import (totp импортит config,
    # config никого).
    from app.services.totp import encrypt_secret
    try:
        return _ENC_PREFIX + encrypt_secret(value)
    except ValueError:
        # TOTP_ENCRYPTION_KEY пуст. На MVP — fallback на plaintext с
        # явным префиксом, чтобы при декрипте не упасть. Раннее у админа
        # должен быть warning в UI.
        return "plain:" + value


def _try_decrypt(value: str) -> str:
    """Расшифровать. Принимает только наши форматы enc:/plain:.

    На незнакомый префикс возвращает as-is (для backward-compat с
    ранее сохранёнными без шифрования значениями).
    """
    from app.services.totp import decrypt_secret
    if not isinstance(value, str):
        return value
    if value.startswith(_ENC_PREFIX):
        try:
            return decrypt_secret(value[len(_ENC_PREFIX):])
        except ValueError:
            # Баг C4 WARN-5: НЕ глотаем молча. Повреждённый Fernet-токен или
            # ротированный/потерянный TOTP_ENCRYPTION_KEY → credential нельзя
            # расшифровать. Логируем явно (без самого секрета), возвращаем ""
            # чтобы вызывающий код увидел «нет креда», а не упал; админ по логу
            # поймёт, что нужен перевыпуск ключа/секрета.
            logger.error(
                "Не удалось расшифровать credential интеграции "
                "(повреждён токен или сменился TOTP_ENCRYPTION_KEY) — "
                "требуется перевыпуск секрета"
            )
            return ""
    if value.startswith("plain:"):
        return value[len("plain:"):]
    return value


def encrypt_sensitive_config(
    provider: str, config: dict[str, Any]
) -> dict[str, Any]:
    """Шифровать sensitive-поля в config'е (per-provider)."""
    if not isinstance(config, dict):
        return {}
    sensitive = SENSITIVE_KEYS.get(provider, frozenset())
    out: dict[str, Any] = {}
    for k, v in config.items():
        if k in sensitive and isinstance(v, str) and v:
            # Если уже зашифровано — не шифруем повторно (idempotency).
            if v.startswith(_ENC_PREFIX) or v.startswith("plain:"):
                out[k] = v
            else:
                out[k] = _try_encrypt(v)
        else:
            out[k] = v
    return out


def decrypt_sensitive_config(
    provider: str, config: dict[str, Any]
) -> dict[str, Any]:
    """Расшифровать sensitive-поля в config'е (per-provider)."""
    if not isinstance(config, dict):
        return {}
    sensitive = SENSITIVE_KEYS.get(provider, frozenset())
    out: dict[str, Any] = {}
    for k, v in config.items():
        if k in sensitive and isinstance(v, str) and v:
            out[k] = _try_decrypt(v)
        else:
            out[k] = v
    return out


def mask_sensitive_config(
    provider: str, config: dict[str, Any]
) -> dict[str, Any]:
    """Маска для UI: sensitive-поля → "****<last4>", остальные — как есть.

    Берёт из БД (с шифрованным значением) → расшифровывает → маскирует.
    Использовать на GET /api/integrations/{provider}/settings для admin'а.
    """
    plain = decrypt_sensitive_config(provider, config)
    sensitive = SENSITIVE_KEYS.get(provider, frozenset())
    masked: dict[str, Any] = {}
    for k, v in plain.items():
        if k in sensitive and isinstance(v, str) and v:
            if len(v) <= 4:
                masked[k] = "****"
            else:
                masked[k] = "****" + v[-4:]
        else:
            masked[k] = v
    return masked


# ============ Webhook secret ============

def generate_webhook_secret() -> str:
    """Случайный URL-safe токен для HMAC верификации (32 байта)."""
    return secrets.token_urlsafe(32)


# ============ DB-операции ============

async def get_or_create_settings(
    session: AsyncSession, provider: str
) -> IntegrationSettings:
    """Найти запись для provider, создать если нет (idempotent).

    Caller должен await commit / flush после.
    """
    validate_provider(provider)
    existing = (
        await session.execute(
            select(IntegrationSettings).where(
                IntegrationSettings.provider == provider
            )
        )
    ).scalar_one_or_none()
    if existing:
        return existing
    inst = IntegrationSettings(
        provider=provider,
        is_enabled=False,
        config={},
        webhook_secret=generate_webhook_secret(),
    )
    session.add(inst)
    await session.flush()
    return inst


async def get_settings_by_provider(
    session: AsyncSession, provider: str
) -> IntegrationSettings | None:
    """SELECT-only. Не создаёт пустую запись."""
    if provider not in PROVIDERS:
        return None
    return (
        await session.execute(
            select(IntegrationSettings).where(
                IntegrationSettings.provider == provider
            )
        )
    ).scalar_one_or_none()
