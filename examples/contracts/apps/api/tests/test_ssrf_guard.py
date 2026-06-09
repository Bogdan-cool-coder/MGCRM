"""Unit-тесты SSRF-guard (P0 security, аудит C3+C4).

Тестируем ЧИСТЫЕ предикаты (без DNS / без сети):
- is_ip_blocked: cloud-metadata, private, loopback, link-local, IPv6 — blocked;
  публичные — allowed.
- first_blocked_ip: блок если хоть один IP в списке плохой; пустой — fail-closed.
- assert_safe_webhook_url для IP-литералов и плохих схем (без DNS).
"""
from __future__ import annotations

import pytest

from app.services.ssrf_guard import (
    SSRFBlockedError,
    assert_safe_webhook_url,
    first_blocked_ip,
    is_ip_blocked,
)


# ---- is_ip_blocked: должны блокироваться ----

@pytest.mark.parametrize(
    "ip",
    [
        "169.254.169.254",   # cloud-metadata (AWS/GCP/Azure) — главная цель SSRF
        "169.254.0.1",        # link-local в целом
        "127.0.0.1",          # loopback
        "127.10.20.30",       # loopback /8
        "10.0.0.5",           # private 10/8
        "10.255.255.255",
        "172.16.0.1",         # private 172.16/12
        "172.31.255.254",
        "192.168.1.1",        # private 192.168/16
        "0.0.0.0",            # unspecified
        "0.1.2.3",            # 0/8 reserved
        "224.0.0.1",          # multicast
        "239.255.255.255",
        "::1",                # IPv6 loopback
        "::",                 # IPv6 unspecified
        "fe80::1",            # IPv6 link-local
        "fc00::1",            # IPv6 private (ULA)
        "fd12:3456::1",       # IPv6 ULA
        "::ffff:127.0.0.1",   # IPv4-mapped loopback
        "::ffff:169.254.169.254",  # IPv4-mapped metadata
        "::ffff:10.0.0.1",    # IPv4-mapped private
        "not-an-ip",          # невалидный → fail-closed
    ],
)
def test_blocked_ips(ip: str) -> None:
    assert is_ip_blocked(ip) is True


# ---- is_ip_blocked: должны пропускаться (публичные) ----

@pytest.mark.parametrize(
    "ip",
    [
        "8.8.8.8",            # Google DNS
        "1.1.1.1",            # Cloudflare
        "93.184.216.34",      # example.com
        "151.101.1.69",       # публичный CDN
        "2606:4700:4700::1111",  # Cloudflare IPv6 public
        "2001:4860:4860::8888",  # Google IPv6 public
    ],
)
def test_allowed_public_ips(ip: str) -> None:
    assert is_ip_blocked(ip) is False


# ---- first_blocked_ip ----

def test_first_blocked_returns_bad_ip() -> None:
    # public + private — должен поймать private.
    assert first_blocked_ip(["8.8.8.8", "10.0.0.1"]) == "10.0.0.1"


def test_first_blocked_all_public_returns_none() -> None:
    assert first_blocked_ip(["8.8.8.8", "1.1.1.1"]) is None


def test_first_blocked_empty_list_fails_closed() -> None:
    # Пустой список = нечего резолвить → блок (sentinel "").
    assert first_blocked_ip([]) == ""


def test_first_blocked_metadata() -> None:
    assert first_blocked_ip(["169.254.169.254"]) == "169.254.169.254"


# ---- assert_safe_webhook_url: схемы и IP-литералы (без DNS) ----

async def test_bad_scheme_file() -> None:
    with pytest.raises(SSRFBlockedError):
        await assert_safe_webhook_url("file:///etc/passwd")


async def test_bad_scheme_gopher() -> None:
    with pytest.raises(SSRFBlockedError):
        await assert_safe_webhook_url("gopher://127.0.0.1:6379/_INFO")


async def test_no_host() -> None:
    with pytest.raises(SSRFBlockedError):
        await assert_safe_webhook_url("http:///path-only")


async def test_empty_url() -> None:
    with pytest.raises(SSRFBlockedError):
        await assert_safe_webhook_url("")


async def test_ip_literal_metadata_blocked() -> None:
    with pytest.raises(SSRFBlockedError):
        await assert_safe_webhook_url("http://169.254.169.254/latest/meta-data/")


async def test_ip_literal_loopback_blocked() -> None:
    with pytest.raises(SSRFBlockedError):
        await assert_safe_webhook_url("http://127.0.0.1:8000/internal")


async def test_ip_literal_private_blocked() -> None:
    with pytest.raises(SSRFBlockedError):
        await assert_safe_webhook_url("https://10.0.0.5/hook")


async def test_ipv6_loopback_literal_blocked() -> None:
    with pytest.raises(SSRFBlockedError):
        await assert_safe_webhook_url("http://[::1]:80/x")


async def test_public_ip_literal_allowed() -> None:
    # Публичный IP-литерал на 443 — проходит (без DNS).
    await assert_safe_webhook_url("https://8.8.8.8/webhook")


async def test_disallowed_port_blocked() -> None:
    # Порт 8000 не входит в DEFAULT_ALLOWED_PORTS {80,443} → блок (публичный IP).
    with pytest.raises(SSRFBlockedError):
        await assert_safe_webhook_url("http://8.8.8.8:8000/hook")


async def test_default_ports_allowed() -> None:
    await assert_safe_webhook_url("http://8.8.8.8:80/hook")
    await assert_safe_webhook_url("https://8.8.8.8:443/hook")
