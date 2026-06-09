"""SSRF guard для исходящих webhook'ов (P0 security, аудит C3+C4 CRITICAL).

Проблема: исходящие webhook'и (automation_executor._action_webhook и
webhook_dispatcher.deliver_one) POST'ят на admin/config-supplied URL без
валидации. Это позволяет читать cloud-metadata (169.254.169.254) и стучаться
во внутренние сервисы (db/api/onlyoffice), а ответ частично попадает в логи.

Защита:
- scheme только http/https (никаких file://, gopher://, ftp:// …);
- host обязателен;
- РЕЗОЛВИМ hostname (DNS) и проверяем КАЖДЫЙ полученный IP против блок-листа
  приватных/loopback/link-local/reserved диапазонов. Если ХОТЯ БЫ один
  резолвленный IP в блок-листе — reject (защита от DNS-rebinding-to-internal
  на момент резолва);
- raw-IP хосты, которые сами приватные/loopback/link-local — reject;
- порты по умолчанию только 80/443 (configurable через настройки).

Чистая функция `is_ip_blocked` / `first_blocked_ip` тестируема без DNS —
ей передаётся список IP-строк.
"""
from __future__ import annotations

import ipaddress
import socket
from urllib.parse import urlsplit

from app.config import get_settings

# Разрешённые схемы. Всё остальное (file/gopher/ftp/data/…) — блок.
ALLOWED_SCHEMES = frozenset({"http", "https"})

# Порты по умолчанию. Расширяется через webhook_ssrf_allow_any_port.
DEFAULT_ALLOWED_PORTS = frozenset({80, 443})


class SSRFBlockedError(ValueError):
    """URL заблокирован SSRF-защитой.

    `safe_reason` — короткое сообщение БЕЗ внутренних деталей (для записи в
    delivery-лог / result_json и для отдачи клиенту). `args[0]` может содержать
    более подробную причину для внутреннего логирования.
    """

    safe_reason = "URL заблокирован SSRF-защитой"


def _ip_is_blocked(ip_obj: ipaddress._BaseAddress) -> bool:
    """True если IP в любом запрещённом диапазоне.

    Покрывает (IPv4 и IPv6):
    - loopback: 127.0.0.0/8, ::1
    - private: 10/8, 172.16/12, 192.168/16, fc00::/7
    - link-local: 169.254.0.0/16 (incl. cloud-metadata), fe80::/10
    - unspecified: 0.0.0.0, ::
    - reserved / multicast: 224/4, прочие reserved
    """
    # is_global == False покрывает private/loopback/link-local/reserved/
    # unspecified/multicast разом, но проверяем явно для надёжности и читаемости.
    if (
        ip_obj.is_loopback
        or ip_obj.is_private
        or ip_obj.is_link_local
        or ip_obj.is_multicast
        or ip_obj.is_reserved
        or ip_obj.is_unspecified
    ):
        return True
    # IPv4-mapped IPv6 (::ffff:127.0.0.1) — проверяем вложенный IPv4.
    mapped = getattr(ip_obj, "ipv4_mapped", None)
    if mapped is not None and _ip_is_blocked(mapped):
        return True
    return False


def is_ip_blocked(ip_str: str) -> bool:
    """Pure-helper: True если строковый IP в запрещённом диапазоне.

    Невалидный IP считаем заблокированным (fail-closed).
    """
    try:
        ip_obj = ipaddress.ip_address(ip_str)
    except ValueError:
        return True
    return _ip_is_blocked(ip_obj)


def first_blocked_ip(ip_strs: list[str]) -> str | None:
    """Pure-helper: вернуть первый заблокированный IP из списка, либо None.

    Используется после DNS-резолва: если хоть один резолвленный адрес в
    блок-листе — запрос отклоняется (DNS-rebinding defense). Пустой список
    тоже блокирует (нечего резолвить — fail-closed) — возвращаем sentinel.
    """
    if not ip_strs:
        return ""
    for ip_str in ip_strs:
        if is_ip_blocked(ip_str):
            return ip_str
    return None


def _allowed_ports() -> frozenset[int]:
    settings = get_settings()
    if settings.webhook_ssrf_allow_any_port:
        return frozenset()  # пустой = «любой порт разрешён»
    return DEFAULT_ALLOWED_PORTS


def _resolve_ips(host: str) -> list[str]:
    """Зарезолвить host в список IP-строк (IPv4 + IPv6).

    Бросает socket.gaierror если резолв не удался — caller трактует как блок.
    """
    infos = socket.getaddrinfo(host, None, proto=socket.IPPROTO_TCP)
    ips: list[str] = []
    for info in infos:
        sockaddr = info[4]
        if sockaddr and isinstance(sockaddr[0], str):
            ips.append(sockaddr[0])
    return ips


async def assert_safe_webhook_url(url: str) -> None:
    """Проверить, что URL безопасен для исходящего webhook'а.

    Бросает SSRFBlockedError если:
    - схема не http/https;
    - нет host;
    - порт не разрешён;
    - host — raw-IP в запрещённом диапазоне;
    - DNS-резолв не удался ИЛИ хоть один резолвленный IP в запрещённом диапазоне.

    Хосты из webhook_ssrf_allowlist пропускаются без проверки IP (для self-hosted
    internal-таргетов). Публичные URL продолжают работать без изменений.

    Сетевой DNS-резолв выполняется в thread-pool, чтобы не блокировать event-loop.
    """
    if not url or not isinstance(url, str):
        raise SSRFBlockedError("URL пуст")

    parts = urlsplit(url.strip())
    scheme = (parts.scheme or "").lower()
    if scheme not in ALLOWED_SCHEMES:
        raise SSRFBlockedError(f"Запрещённая схема: {scheme or '(нет)'}")

    host = parts.hostname
    if not host:
        raise SSRFBlockedError("В URL отсутствует host")
    host = host.lower()

    # Порт.
    allowed_ports = _allowed_ports()
    if allowed_ports:
        try:
            port = parts.port
        except ValueError as e:
            raise SSRFBlockedError("Некорректный порт в URL") from e
        effective_port = port if port is not None else (443 if scheme == "https" else 80)
        if effective_port not in allowed_ports:
            raise SSRFBlockedError(f"Запрещённый порт: {effective_port}")

    # Allowlist (self-hosted internal) — пропускаем проверку IP.
    settings = get_settings()
    if host in settings.ssrf_allowlist_hosts:
        return

    # Если host — это уже IP-литерал, проверяем напрямую (без DNS).
    try:
        ipaddress.ip_address(host)
        if is_ip_blocked(host):
            raise SSRFBlockedError(f"IP-host в запрещённом диапазоне: {host}")
        return
    except ValueError:
        pass  # не IP-литерал — резолвим через DNS

    # DNS-резолв + проверка каждого IP.
    import asyncio

    try:
        ips = await asyncio.to_thread(_resolve_ips, host)
    except (socket.gaierror, OSError) as e:
        raise SSRFBlockedError(f"DNS-резолв не удался для host: {host}") from e

    blocked = first_blocked_ip(ips)
    if blocked is not None:
        if blocked == "":
            raise SSRFBlockedError(f"Host не резолвится в IP: {host}")
        raise SSRFBlockedError(
            f"Резолвленный IP в запрещённом диапазоне: {host} -> {blocked}"
        )
