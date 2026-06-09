"""Эпик 16 — Security: pure-function тесты TOTP сервиса.

Без сети, без БД. Тестируем:
- generate_secret() — base32 длина и формат
- build_otpauth_uri() — корректный URI с issuer
- get_qr_data_url() — data:image/png;base64,... префикс
- verify_totp() — успех/неуспех с tolerance, защита от nonsense ввода
- generate_backup_codes() — длина и уникальность
- hash_backup_code() / verify_backup_code() — bcrypt round-trip
- find_matching_backup_code_index() — поиск + None для несовпадений
- encrypt_secret() / decrypt_secret() — Fernet round-trip + bad ciphertext
"""
from __future__ import annotations

import base64
import re

import pyotp
import pytest
from cryptography.fernet import Fernet

from app.services import totp as totp_module
from app.services.totp import (
    TOTP_ISSUER,
    build_otpauth_uri,
    decrypt_secret,
    encrypt_secret,
    find_matching_backup_code_index,
    generate_backup_codes,
    generate_secret,
    get_qr_data_url,
    hash_backup_code,
    verify_backup_code,
    verify_totp,
)


@pytest.fixture(autouse=True)
def _fernet_key(monkeypatch):
    """Каждый тест получает чистый Fernet ключ в settings."""
    key = Fernet.generate_key().decode("ascii")

    class _S:
        totp_encryption_key = key

    monkeypatch.setattr(totp_module, "get_settings", lambda: _S())
    yield


# ============ generate_secret / build URI / QR ============


def test_generate_secret_is_base32_length_32():
    s = generate_secret()
    assert len(s) == 32
    # base32 alphabet: A-Z, 2-7
    assert re.match(r"^[A-Z2-7]+$", s), f"not base32: {s!r}"


def test_build_otpauth_uri_contains_issuer_and_email():
    uri = build_otpauth_uri("JBSWY3DPEHPK3PXP", "test@example.com")
    assert uri.startswith("otpauth://totp/")
    assert "test%40example.com" in uri or "test@example.com" in uri
    # issuer присутствует и в path, и в query
    assert TOTP_ISSUER.replace(" ", "%20") in uri or TOTP_ISSUER in uri
    assert "secret=JBSWY3DPEHPK3PXP" in uri


def test_get_qr_data_url_returns_png_base64():
    url = get_qr_data_url("JBSWY3DPEHPK3PXP", "test@example.com")
    assert url.startswith("data:image/png;base64,")
    body = url.removeprefix("data:image/png;base64,")
    # должен декодироваться без ошибок
    decoded = base64.b64decode(body)
    assert decoded.startswith(b"\x89PNG"), "result must be a PNG image"


# ============ verify_totp ============


def test_verify_totp_accepts_current_code():
    s = generate_secret()
    code = pyotp.TOTP(s).now()
    assert verify_totp(s, code) is True


def test_verify_totp_rejects_wrong_code():
    s = generate_secret()
    # "000000" статистически почти всегда не равно current TOTP (1 шанс из 1M).
    # Если совпало — взять другую константу.
    current = pyotp.TOTP(s).now()
    wrong = "000000" if current != "000000" else "111111"
    assert verify_totp(s, wrong) is False


def test_verify_totp_rejects_non_digit_input():
    s = generate_secret()
    assert verify_totp(s, "abcdef") is False
    assert verify_totp(s, "12345 ") is False  # 5 digits + space
    assert verify_totp(s, "") is False


def test_verify_totp_rejects_wrong_length():
    s = generate_secret()
    assert verify_totp(s, "12345") is False  # 5 digits
    assert verify_totp(s, "1234567") is False  # 7 digits


def test_verify_totp_strips_whitespace():
    s = generate_secret()
    code = pyotp.TOTP(s).now()
    # вокруг кода пробелы
    assert verify_totp(s, f" {code} ") is True


def test_verify_totp_with_empty_secret_returns_false():
    code = "123456"
    assert verify_totp("", code) is False


# ============ backup codes ============


def test_generate_backup_codes_returns_n_unique_codes():
    codes = generate_backup_codes(8)
    assert len(codes) == 8
    assert len(set(codes)) == 8, "backup codes must be unique"
    for c in codes:
        assert len(c) == 8
        assert re.match(r"^[0-9a-f]{8}$", c), f"not hex: {c!r}"


def test_generate_backup_codes_respects_count():
    assert len(generate_backup_codes(3)) == 3
    assert len(generate_backup_codes(16)) == 16


def test_backup_code_round_trip():
    plain = "abcdef12"
    h = hash_backup_code(plain)
    assert h != plain
    assert verify_backup_code(plain, h) is True
    assert verify_backup_code("zzzzzzzz", h) is False


def test_find_matching_backup_code_index_returns_index():
    codes = generate_backup_codes(5)
    hashed = [hash_backup_code(c) for c in codes]
    idx = find_matching_backup_code_index(codes[2], hashed)
    assert idx == 2


def test_find_matching_backup_code_index_returns_none_on_miss():
    codes = generate_backup_codes(3)
    hashed = [hash_backup_code(c) for c in codes]
    assert find_matching_backup_code_index("00000000", hashed) is None


def test_find_matching_backup_code_index_handles_none_list():
    assert find_matching_backup_code_index("abcdef12", None) is None
    assert find_matching_backup_code_index("abcdef12", []) is None


# ============ encrypt / decrypt ============


def test_encrypt_decrypt_round_trip():
    secret = "JBSWY3DPEHPK3PXP"
    enc = encrypt_secret(secret)
    assert enc != secret
    assert isinstance(enc, str)
    assert decrypt_secret(enc) == secret


def test_encrypt_secret_produces_different_ciphertext_each_time():
    """Fernet включает random nonce → каждый encrypt разный, но decrypt'ся к тому же."""
    secret = "JBSWY3DPEHPK3PXP"
    e1 = encrypt_secret(secret)
    e2 = encrypt_secret(secret)
    assert e1 != e2
    assert decrypt_secret(e1) == secret
    assert decrypt_secret(e2) == secret


def test_decrypt_secret_raises_on_tampered_token():
    secret = "JBSWY3DPEHPK3PXP"
    enc = encrypt_secret(secret)
    # модифицируем последний символ — должна быть InvalidToken → ValueError
    bad = enc[:-1] + ("A" if enc[-1] != "A" else "B")
    with pytest.raises(ValueError, match="Invalid TOTP secret"):
        decrypt_secret(bad)


def test_encrypt_secret_raises_when_key_not_configured(monkeypatch):
    class _S:
        totp_encryption_key = ""

    monkeypatch.setattr(totp_module, "get_settings", lambda: _S())
    with pytest.raises(ValueError, match="not configured|TOTP_ENCRYPTION_KEY"):
        encrypt_secret("JBSWY3DPEHPK3PXP")
