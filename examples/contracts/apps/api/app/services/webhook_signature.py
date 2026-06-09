"""HMAC-SHA256 подпись outbound webhook'ов (Эпик 11.2).

Header `X-Macro-Signature: sha256=<hex>` где hex =
HMAC_SHA256(secret, body) → hexdigest.

Подписчик валидирует: считает свой HMAC на полученном body и сравнивает с
заголовком через constant-time `hmac.compare_digest`.

NB: pure-функции. body — это raw bytes того JSON, что улетел по wire
(сериализованного один раз и не пересериализованного), чтобы подписчик мог
взять байты ответа as-is и проверить. Двойная сериализация (рекомпактный
JSON-дамп) подпись разорвёт.
"""
from __future__ import annotations

import hashlib
import hmac
from typing import Any

# Имя заголовка с подписью — конвенция проекта. Соответствует тому, что уже
# использует automation_executor._action_webhook (см. также build_webhook_
# signature там).
SIGNATURE_HEADER = "X-Macro-Signature"

# Зарезервированные заголовки, которые admin НЕ может переопределить через
# webhook.headers / automation.action_config["headers"] (баг C4 WARN-3).
# Переопределение Host/auth/подписи усиливает SSRF (virtual-host routing
# внутренних сервисов) и ломает целостность нашей подписи/метаданных доставки.
# Сравнение — по нижнему регистру (HTTP-заголовки case-insensitive).
RESERVED_HEADER_NAMES: frozenset[str] = frozenset(
    {
        "host",
        "content-length",
        "content-type",
        "authorization",
        "user-agent",
        SIGNATURE_HEADER.lower(),
        "x-macro-event",
        "x-macro-delivery-id",
    }
)


def filter_custom_headers(custom: Any) -> dict[str, str]:
    """Отфильтровать admin-заданные кастомные заголовки.

    Оставляет только str→str пары, имя которых НЕ входит в RESERVED_HEADER_NAMES
    (case-insensitive). Пустые/не-строковые имена отбрасываются.

    Pure-функция: чистый словарь на вход/выход, тестируется без I/O.
    """
    if not isinstance(custom, dict):
        return {}
    out: dict[str, str] = {}
    for k, v in custom.items():
        if not isinstance(k, str) or not isinstance(v, str):
            continue
        if not k.strip():
            continue
        if k.strip().lower() in RESERVED_HEADER_NAMES:
            continue
        out[k] = v
    return out


def sign_body(secret: str, body: bytes) -> str:
    """Сформировать значение для заголовка X-Macro-Signature.

    Возвращает строку формата "sha256=<hex>" (формат GitHub/Mailgun/etc).

    Pure-функция: без БД, без сети. Тестируется на known-answer pair.
    """
    if not isinstance(body, (bytes, bytearray)):
        raise TypeError("body должен быть bytes (raw payload что идёт по wire)")
    digest = hmac.new(
        secret.encode("utf-8"), bytes(body), hashlib.sha256
    ).hexdigest()
    return f"sha256={digest}"


def verify_signature(secret: str, body: bytes, signature_header: str) -> bool:
    """Constant-time проверка X-Macro-Signature.

    signature_header — полное значение хедера ("sha256=<hex>").

    Используется на стороне подписчика (либо в наших же inbound webhook'ах из
    каналов, где мы — подписчик). compare_digest защищает от timing-attack.
    """
    if not isinstance(signature_header, str):
        return False
    expected = sign_body(secret, body)
    return hmac.compare_digest(expected, signature_header)
