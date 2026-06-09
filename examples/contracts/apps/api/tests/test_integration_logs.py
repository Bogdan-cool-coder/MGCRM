"""Unit-тесты для helpers /api/integrations/logs (Эпик 15 hotfix).

Pure-function: проверяем mapping AIRequestLog.status → HTTP-код и
bucket-классификатор (2xx/3xx/4xx/5xx). Без DB.
"""
from __future__ import annotations

import pytest

from app.routers.integrations import (
    _ai_status_to_http_code,
    _bucket_for_status,
)


# ============ AIRequestLog status → HTTP-код ============

def test_ai_status_success_to_200():
    assert _ai_status_to_http_code("success", None) == 200


def test_ai_status_rate_limited_to_429():
    assert _ai_status_to_http_code("rate_limited", None) == 429


def test_ai_status_not_configured_to_503():
    assert _ai_status_to_http_code("not_configured", None) == 503


def test_ai_status_error_to_500():
    assert _ai_status_to_http_code("error", "Anthropic API timeout") == 500


def test_ai_status_unknown_to_500():
    """Незнакомый статус → 500 (consistent fallback)."""
    assert _ai_status_to_http_code("weird_value", None) == 500


def test_ai_status_none_with_error_to_500():
    """None статус + есть error_message → 500."""
    assert _ai_status_to_http_code(None, "some error") == 500


# ============ Status bucket классификатор ============

@pytest.mark.parametrize("code,expected", [
    (200, "2xx"),
    (201, "2xx"),
    (204, "2xx"),
    (299, "2xx"),
    (300, "3xx"),
    (302, "3xx"),
    (304, "3xx"),
    (400, "4xx"),
    (401, "4xx"),
    (403, "4xx"),
    (404, "4xx"),
    (429, "4xx"),
    (499, "4xx"),
    (500, "5xx"),
    (502, "5xx"),
    (503, "5xx"),
    (599, "5xx"),
    # Edge: 0 (pending webhook), 100, 999 → bucket'ы fall back в 5xx
    (0, "5xx"),
    (100, "5xx"),  # информационные коды не используются → bucket 5xx (consistent)
    (999, "5xx"),
])
def test_bucket_for_status(code: int, expected: str):
    assert _bucket_for_status(code) == expected
