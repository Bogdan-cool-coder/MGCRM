"""Pure-function tests для скрабинга Sentry-событий.

Система держит финансовые + auth данные → секрет/PII скрабинг обязателен.
Эти тесты гарантируют, что before_send (scrub_sentry_event) вычищает cookies
(access_token JWT), Authorization / X-*-Token заголовки и data-ключи с секретами,
не трогая обычные поля.
"""

from app.services.sentry_setup import (
    scrub_sentry_breadcrumb,
    scrub_sentry_event,
)

_REDACTED = "[redacted]"


def test_cookies_stripped_entirely():
    event = {
        "request": {
            "cookies": {"access_token": "eyJ.jwt.token", "theme": "dark"},
        }
    }
    out = scrub_sentry_event(event)
    assert out["request"]["cookies"] == _REDACTED


def test_authorization_header_stripped_dict():
    event = {
        "request": {
            "headers": {
                "Authorization": "Bearer secret-jwt",
                "User-Agent": "curl/8",
            }
        }
    }
    out = scrub_sentry_event(event)
    assert out["request"]["headers"]["Authorization"] == _REDACTED
    assert out["request"]["headers"]["User-Agent"] == "curl/8"


def test_authorization_header_stripped_list():
    event = {
        "request": {
            "headers": [
                ["Authorization", "Bearer secret-jwt"],
                ["Accept", "application/json"],
            ]
        }
    }
    out = scrub_sentry_event(event)
    headers = {k: v for k, v in out["request"]["headers"]}
    assert headers["Authorization"] == _REDACTED
    assert headers["Accept"] == "application/json"


def test_x_token_and_admin_api_key_headers_stripped():
    event = {
        "request": {
            "headers": {
                "X-Admin-API-Key": "topsecret",
                "X-Bot-Token": "abc",
                "Cookie": "access_token=jwt",
            }
        }
    }
    out = scrub_sentry_event(event)
    h = out["request"]["headers"]
    assert h["X-Admin-API-Key"] == _REDACTED
    assert h["X-Bot-Token"] == _REDACTED
    assert h["Cookie"] == _REDACTED


def test_request_body_sensitive_keys_redacted():
    event = {
        "request": {
            "data": {
                "email": "user@example.com",
                "password": "hunter2",
                "jwt_secret": "abc",
                "anthropic_api_key": "sk-ant-xxx",
                "refresh_token": "rt",
                "client_secret": "cs",
                "smtp_password": "p",
                "totp_secret": "t",
                "dsn": "https://x@sentry.io/1",
                "name": "Bob",
            }
        }
    }
    out = scrub_sentry_event(event)
    data = out["request"]["data"]
    for k in (
        "password",
        "jwt_secret",
        "anthropic_api_key",
        "refresh_token",
        "client_secret",
        "smtp_password",
        "totp_secret",
        "dsn",
    ):
        assert data[k] == _REDACTED, k
    # Обычные поля — нетронуты.
    assert data["email"] == "user@example.com"
    assert data["name"] == "Bob"


def test_nested_data_redacted_recursively():
    event = {
        "extra": {
            "payload": {
                "user": {"password": "p", "id": 7},
                # non-sensitive list key → recursion into items must redact
                # api_key inside while keeping safe fields.
                "items": [{"api_key": "k"}, {"safe": "ok"}],
                # sensitive key name ("tokens" contains "token") → whole value
                # is redacted wholesale, never traversed.
                "tokens": [{"api_key": "k"}],
            }
        }
    }
    out = scrub_sentry_event(event)
    payload = out["extra"]["payload"]
    assert payload["user"]["password"] == _REDACTED
    assert payload["user"]["id"] == 7
    assert payload["items"][0]["api_key"] == _REDACTED
    assert payload["items"][1]["safe"] == "ok"
    assert payload["tokens"] == _REDACTED


def test_user_keeps_only_numeric_id():
    event = {
        "user": {
            "id": 42,
            "email": "user@example.com",
            "ip_address": "1.2.3.4",
            "username": "bob",
        }
    }
    out = scrub_sentry_event(event)
    assert out["user"] == {"id": 42}


def test_user_without_id_becomes_empty():
    event = {"user": {"email": "user@example.com", "ip_address": "1.2.3.4"}}
    out = scrub_sentry_event(event)
    assert out["user"] == {}


def test_stacktrace_frame_vars_redacted():
    event = {
        "exception": {
            "values": [
                {
                    "stacktrace": {
                        "frames": [
                            {"vars": {"password": "p", "x": 1}},
                        ]
                    }
                }
            ]
        }
    }
    out = scrub_sentry_event(event)
    frame = out["exception"]["values"][0]["stacktrace"]["frames"][0]
    assert frame["vars"]["password"] == _REDACTED
    assert frame["vars"]["x"] == 1


def test_query_string_with_token_redacted():
    event = {"request": {"query_string": "page=1&token=abc"}}
    out = scrub_sentry_event(event)
    assert out["request"]["query_string"] == _REDACTED


def test_query_string_without_secret_preserved():
    event = {"request": {"query_string": "page=1&limit=20"}}
    out = scrub_sentry_event(event)
    assert out["request"]["query_string"] == "page=1&limit=20"


def test_normal_event_preserved():
    event = {
        "level": "error",
        "message": "boom",
        "request": {"url": "/api/deals", "method": "POST"},
        "tags": {"environment": "production"},
    }
    out = scrub_sentry_event(event)
    assert out["level"] == "error"
    assert out["message"] == "boom"
    assert out["request"]["url"] == "/api/deals"
    assert out["tags"]["environment"] == "production"


def test_non_dict_event_passthrough():
    assert scrub_sentry_event(None) is None
    assert scrub_sentry_event("oops") == "oops"


def test_breadcrumb_data_scrubbed():
    crumb = {"category": "http", "data": {"token": "x", "url": "/api/x"}}
    out = scrub_sentry_breadcrumb(crumb)
    assert out["data"]["token"] == _REDACTED
    assert out["data"]["url"] == "/api/x"


def test_breadcrumb_non_dict_passthrough():
    assert scrub_sentry_breadcrumb(None) is None
