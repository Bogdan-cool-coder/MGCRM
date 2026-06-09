"""C4 WARN-3 — фильтр кастомных заголовков outbound webhook'ов.

Pure-function тесты filter_custom_headers: admin не может переопределить
reserved-заголовки (Host/Authorization/подпись/метаданные доставки).
"""
from __future__ import annotations

from app.services.webhook_signature import (
    RESERVED_HEADER_NAMES,
    SIGNATURE_HEADER,
    filter_custom_headers,
)


def test_passes_through_safe_custom_header():
    out = filter_custom_headers({"X-Partner-Token": "abc"})
    assert out == {"X-Partner-Token": "abc"}


def test_blocks_host_override_case_insensitive():
    out = filter_custom_headers({"Host": "internal-svc", "HOST": "x", "host": "y"})
    assert out == {}


def test_blocks_authorization_override():
    out = filter_custom_headers({"Authorization": "Bearer stolen"})
    assert "Authorization" not in out
    assert out == {}


def test_blocks_signature_and_delivery_meta():
    out = filter_custom_headers(
        {
            SIGNATURE_HEADER: "sha256=forged",
            "X-Macro-Event": "evil",
            "X-Macro-Delivery-Id": "999",
            "Content-Type": "text/evil",
        }
    )
    assert out == {}


def test_drops_non_string_pairs():
    out = filter_custom_headers({"X-Ok": "1", "X-Bad": 5, 7: "v", "": "blank"})
    assert out == {"X-Ok": "1"}


def test_non_dict_returns_empty():
    assert filter_custom_headers(None) == {}
    assert filter_custom_headers("nope") == {}
    assert filter_custom_headers(["a", "b"]) == {}


def test_reserved_set_contains_expected():
    for name in ("host", "authorization", "content-type", SIGNATURE_HEADER.lower()):
        assert name in RESERVED_HEADER_NAMES
