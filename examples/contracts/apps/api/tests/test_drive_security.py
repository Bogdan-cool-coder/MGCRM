"""C5 — Google Drive: шифрование секретов at-rest + allowlist папок.

Pure / file-based проверки (без сети, без БД):
- _write_config шифрует refresh_token / client_secret на диск (не plaintext);
- _read_config прозрачно расшифровывает их обратно;
- бэк-компат: старый plaintext-конфиг (без enc-префикса) читается как есть;
- allowlist папок: пустой = разрешено всё; заданный = только перечисленные;
- extract_folder_id нормализует ссылки в id (для allowlist + upload).
"""
from __future__ import annotations

import json

import pytest
from cryptography.fernet import Fernet

import app.services.drive as drive_module
import app.services.google_calendar as gcal_module
from app.services.drive import (
    _ENC_PREFIX,
    _read_config,
    _write_config,
    extract_folder_id,
    get_allowed_folder_ids,
    is_folder_allowed,
    set_allowed_folder_ids,
)


@pytest.fixture(autouse=True)
def _drive_env(monkeypatch, tmp_path):
    """Свежий Fernet ключ + временный путь конфига перед каждым тестом."""
    key = Fernet.generate_key().decode("ascii")

    class _S:
        gcal_encryption_key = key
        public_base_url = "https://contracts.macroglobal.tech"
        google_service_account_json_path = str(tmp_path / "google_oauth.json")

    # drive._read/_write используют модульный settings (google_service_account_json_path)
    monkeypatch.setattr(drive_module, "settings", _S())
    # encrypt_token/decrypt_token берут ключ через gcal get_settings()
    monkeypatch.setattr(gcal_module, "get_settings", lambda: _S())
    yield


# ============ Шифрование at-rest ============


def test_refresh_token_encrypted_on_disk():
    _write_config({"client_id": "cid", "refresh_token": "1//super-secret-refresh"})
    raw = json.loads(
        (drive_module._config_path()).read_text(encoding="utf-8")
    )
    # На диске — ciphertext с префиксом, НЕ plaintext.
    assert raw["refresh_token"].startswith(_ENC_PREFIX)
    assert "super-secret-refresh" not in raw["refresh_token"]
    # client_id — не секрет, остаётся как есть.
    assert raw["client_id"] == "cid"


def test_client_secret_encrypted_on_disk():
    _write_config({"client_id": "cid", "client_secret": "GOCSPX-plaintext"})
    raw = json.loads((drive_module._config_path()).read_text(encoding="utf-8"))
    assert raw["client_secret"].startswith(_ENC_PREFIX)
    assert "GOCSPX-plaintext" not in raw["client_secret"]


def test_read_decrypts_secrets_back_to_plaintext():
    _write_config({"refresh_token": "1//rt", "client_secret": "GOCSPX-sec"})
    cfg = _read_config()
    assert cfg["refresh_token"] == "1//rt"
    assert cfg["client_secret"] == "GOCSPX-sec"


def test_roundtrip_preserves_non_secret_fields():
    _write_config({
        "client_id": "cid",
        "connected_email": "ops@macroglobaltech.com",
        "refresh_token": "1//rt",
    })
    cfg = _read_config()
    assert cfg["client_id"] == "cid"
    assert cfg["connected_email"] == "ops@macroglobaltech.com"
    assert cfg["refresh_token"] == "1//rt"


# ============ Бэк-компат: старый plaintext конфиг ============


def test_legacy_plaintext_config_read_as_is():
    """Конфиг, записанный ДО фикса (plaintext, без enc-префикса), должен
    читаться корректно (значение без префикса = plaintext)."""
    p = drive_module._config_path()
    p.parent.mkdir(parents=True, exist_ok=True)
    p.write_text(json.dumps({
        "client_id": "cid",
        "refresh_token": "1//legacy-plain",
        "client_secret": "GOCSPX-legacy",
    }), encoding="utf-8")
    cfg = _read_config()
    assert cfg["refresh_token"] == "1//legacy-plain"
    assert cfg["client_secret"] == "GOCSPX-legacy"


def test_legacy_plaintext_reencrypted_on_next_write():
    """После чтения legacy plaintext и повторной записи — значение шифруется."""
    p = drive_module._config_path()
    p.parent.mkdir(parents=True, exist_ok=True)
    p.write_text(json.dumps({"refresh_token": "1//legacy-plain"}), encoding="utf-8")
    cfg = _read_config()  # decrypt -> plaintext
    _write_config(cfg)    # encrypt
    raw = json.loads(p.read_text(encoding="utf-8"))
    assert raw["refresh_token"].startswith(_ENC_PREFIX)


# ============ Allowlist папок ============


def test_allowlist_empty_allows_everything():
    assert get_allowed_folder_ids() == []
    assert is_folder_allowed("anyFolderId123") is True


def test_set_allowlist_normalizes_urls_to_ids():
    ids = set_allowed_folder_ids([
        "https://drive.google.com/drive/folders/ABC123_-def",
        "PLAINID456",
        "  ",  # пустые отбрасываются
    ])
    assert ids == ["ABC123_-def", "PLAINID456"]
    assert get_allowed_folder_ids() == ["ABC123_-def", "PLAINID456"]


def test_allowlist_blocks_unlisted_folder():
    set_allowed_folder_ids(["ABC123_-def"])
    assert is_folder_allowed("ABC123_-def") is True
    assert is_folder_allowed("EVILfolder999") is False


def test_set_empty_allowlist_lifts_restriction():
    set_allowed_folder_ids(["ABC123_-def"])
    assert is_folder_allowed("EVILfolder999") is False
    set_allowed_folder_ids([])
    assert is_folder_allowed("EVILfolder999") is True


# ============ extract_folder_id (используется allowlist + upload) ============


def test_extract_folder_id_from_url():
    assert extract_folder_id(
        "https://drive.google.com/drive/folders/1aBcD_-xyz?usp=sharing"
    ) == "1aBcD_-xyz"


def test_extract_folder_id_plain():
    assert extract_folder_id("1aBcD_-xyz") == "1aBcD_-xyz"
