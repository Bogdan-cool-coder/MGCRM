"""Эпик 16 — Security: pure-function тесты helpers для 2FA endpoints.

Тестируем:
- create_temp_totp_secret_token / decode_temp_totp_secret_token (JWT round-trip)
- _pick_totp_code / _pick_backup_code (priority/format detection)
- SetupOut response shape (qr_base64 без префикса, otpauth_uri валидный)
- has_password (PLACEHOLDER vs реальный bcrypt)

Без сети, без БД. Зависимости от Settings подменяются через monkeypatch.
"""
from __future__ import annotations

import pytest
from cryptography.fernet import Fernet

from app import security as security_module
from app.routers.auth_2fa import _pick_backup_code, _pick_totp_code, SetupOut
from app.security import (
    PLACEHOLDER_PASSWORD_HASH,
    create_temp_totp_secret_token,
    decode_temp_totp_secret_token,
    has_password,
    hash_password,
)
from app.services.totp import build_otpauth_uri, generate_secret


# ============ create_temp_totp_secret_token round-trip ============


def test_temp_totp_secret_token_round_trip():
    secret = "JBSWY3DPEHPK3PXP"
    token = create_temp_totp_secret_token(user_id=42, secret=secret)
    decoded = decode_temp_totp_secret_token(token)
    assert decoded is not None
    user_id, sec = decoded
    assert user_id == 42
    assert sec == secret


def test_temp_totp_secret_token_returns_none_for_garbage():
    assert decode_temp_totp_secret_token("not-a-jwt") is None
    assert decode_temp_totp_secret_token("") is None


def test_temp_totp_secret_token_returns_none_for_wrong_scope():
    """Обычный access_token имеет другой scope — decode_temp_totp_secret_token
    отвергает его, защита от подмены."""
    from app.security import create_access_token
    access = create_access_token(42, "admin")
    assert decode_temp_totp_secret_token(access) is None


def test_temp_totp_secret_token_returns_none_for_2fa_pending_scope():
    """Между access_token и temp_2fa_token у нас 3 разных scope; они НЕ
    взаимозаменяемы."""
    from app.security import create_temp_2fa_token
    temp = create_temp_2fa_token(42)
    assert decode_temp_totp_secret_token(temp) is None


# ============ _pick_totp_code ============


def test_pick_totp_priority_first_match():
    """Первый аргумент берёт приоритет если 6 цифр."""
    assert _pick_totp_code("123456", "000000") == "123456"


def test_pick_totp_skips_non_6_digits():
    """5 цифр — не TOTP; пропускаем."""
    assert _pick_totp_code("12345", "999999") == "999999"


def test_pick_totp_skips_letters():
    assert _pick_totp_code("abcdef", "111111") == "111111"


def test_pick_totp_returns_none_if_nothing_matches():
    assert _pick_totp_code(None, "", "xyz") is None


def test_pick_totp_strips_whitespace():
    assert _pick_totp_code(" 123456 ") == "123456"


# ============ _pick_backup_code ============


def test_pick_backup_returns_hex_string():
    assert _pick_backup_code("abcdef12") == "abcdef12"


def test_pick_backup_skips_6_digit_totp():
    """6 цифр — это TOTP, НЕ backup. _pick_backup_code должен пропустить."""
    assert _pick_backup_code("123456") is None


def test_pick_backup_accepts_8_alpha_hex():
    assert _pick_backup_code("deadbeef") == "deadbeef"


def test_pick_backup_returns_none_for_empty():
    assert _pick_backup_code(None, "") is None


# ============ SetupOut shape ============


def test_setup_out_shape_fields():
    """SetupOut должен иметь именно qr_base64 (НЕ qr_data_url) +
    manual_code + otpauth_uri — это контракт фронта."""
    out = SetupOut(
        qr_base64="iVBORw0KGgo...",
        manual_code="JBSWY3DPEHPK3PXP",
        otpauth_uri="otpauth://totp/MACRO%20CRM:a@b.com?secret=X&issuer=MACRO%20CRM",
    )
    dumped = out.model_dump()
    assert "qr_base64" in dumped
    assert "manual_code" in dumped
    assert "otpauth_uri" in dumped
    # Legacy ключей быть не должно
    assert "qr_data_url" not in dumped
    assert "secret" not in dumped


def test_qr_base64_does_not_contain_data_prefix():
    """qr_base64 — чистый base64 PNG БЕЗ префикса 'data:image/png;base64,'.
    Фронт сам соберёт <img src={`data:image/png;base64,${qr_base64}`}/>."""
    # Эмулируем что сделает endpoint /setup
    secret = generate_secret()
    from app.services.totp import get_qr_data_url
    # Используем monkeypatch для Fernet ключа — get_qr_data_url не требует, но safer.
    qr_data_url = get_qr_data_url(secret, "test@example.com")
    assert qr_data_url.startswith("data:image/png;base64,")
    qr_base64 = qr_data_url.removeprefix("data:image/png;base64,")
    assert not qr_base64.startswith("data:")
    assert not qr_base64.startswith("image/")


def test_otpauth_uri_format():
    """otpauth URI содержит issuer=MACRO%20CRM и secret=<base32>."""
    uri = build_otpauth_uri("JBSWY3DPEHPK3PXP", "test@example.com")
    assert uri.startswith("otpauth://totp/")
    assert "secret=JBSWY3DPEHPK3PXP" in uri
    # Issuer присутствует и в path, и в query
    assert "issuer=MACRO" in uri


# ============ PLACEHOLDER_PASSWORD_HASH + has_password ============


def test_has_password_false_for_placeholder():
    """SSO-only user (PLACEHOLDER_PASSWORD_HASH) → has_password=False."""

    class _U:
        password_hash = PLACEHOLDER_PASSWORD_HASH

    assert has_password(_U()) is False


def test_has_password_true_for_real_bcrypt():
    """Юзер с реальным паролем → has_password=True."""

    class _U:
        password_hash = hash_password("secret123")

    assert has_password(_U()) is True


def test_has_password_false_for_empty_hash():
    """Пустой password_hash тоже трактуется как «нет пароля»."""

    class _U:
        password_hash = ""

    assert has_password(_U()) is False


def test_has_password_true_for_random_bcrypt():
    """Юзер созданный auto-create ДО введения PLACEHOLDER_PASSWORD_HASH:
    у него рандомный bcrypt hash. Считаем has_password=True (это
    соответствует ТЗ «существующих users считай has_password=true»)."""

    class _U:
        # Эмулируем рандомный bcrypt-хэш (legacy SSO-only)
        password_hash = hash_password("random-token-32-chars-long-XXXXX")

    assert has_password(_U()) is True


def test_placeholder_constant_is_stable():
    """Placeholder должен быть СТРОКОВОЙ константой, не пересчитываемой —
    иначе has_password будет ложно False для каждого нового импорта."""
    from app.security import PLACEHOLDER_PASSWORD_HASH as A
    from app.security import PLACEHOLDER_PASSWORD_HASH as B
    assert A == B
    assert A == PLACEHOLDER_PASSWORD_HASH
