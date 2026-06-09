"""Загрузка файлов в Google Drive через OAuth 2.0.

Org-политика macroglobaltech.com запрещает ключи сервисных аккаунтов,
поэтому используем OAuth (user-delegated). Админ вводит client_id/secret,
один раз проходит согласие Google → храним refresh_token → обновляем access.
"""

from __future__ import annotations

import json
import logging
import re
import secrets
from pathlib import Path
from typing import Any

from google.auth.transport.requests import Request as GoogleRequest
from google.oauth2.credentials import Credentials
from googleapiclient.discovery import build
from googleapiclient.http import MediaFileUpload

from app.config import get_settings

logger = logging.getLogger(__name__)
settings = get_settings()

SCOPES = ["https://www.googleapis.com/auth/drive"]
AUTH_URI = "https://accounts.google.com/o/oauth2/auth"
TOKEN_URI = "https://oauth2.googleapis.com/token"

FOLDER_ID_PATTERNS = [
    re.compile(r"folders/([A-Za-z0-9_-]+)"),
    re.compile(r"id=([A-Za-z0-9_-]+)"),
]


def extract_folder_id(url_or_id: str) -> str | None:
    if not url_or_id:
        return None
    if "/" not in url_or_id and "?" not in url_or_id:
        return url_or_id.strip()
    for pat in FOLDER_ID_PATTERNS:
        m = pat.search(url_or_id)
        if m:
            return m.group(1)
    return None


# ============ Хранилище OAuth-конфига ============

# C5 CRITICAL: секреты Google OAuth (refresh_token, client_secret) НЕ хранятся
# в открытом виде на диске. Шифруем Fernet'ом (тот же ключ, что для gcal/totp
# tokens — services/google_calendar.encrypt_token). На диск кладём ciphertext с
# префиксом, на чтении прозрачно расшифровываем. Бэк-компат: старые plaintext
# значения (без префикса) читаются как есть и перешифровываются при следующей
# записи (любой save_*/exchange_*/disconnect перезапишет конфиг).
_ENC_PREFIX = "enc:v1:"

# Поля, которые шифруем at-rest. oauth_state/connected_email/client_id —
# не секреты (client_id публичен по дизайну OAuth), их не шифруем.
_SECRET_FIELDS = ("refresh_token", "client_secret")


def _config_path() -> Path:
    base = Path(settings.google_service_account_json_path or "/data/storage/secrets/google_oauth.json").parent
    return base / "google_oauth.json"


def _maybe_encrypt(value: str) -> str:
    """Зашифровать секрет для хранения. Если Fernet-ключ не настроен —
    оставляем как есть (fail-open на запись: лучше сохранить рабочий конфиг,
    чем уронить интеграцию; продакшен ключ задан, dev — нет)."""
    if not value or value.startswith(_ENC_PREFIX):
        return value
    try:
        from app.services.google_calendar import encrypt_token
        return _ENC_PREFIX + encrypt_token(value)
    except Exception as e:  # noqa: BLE001
        logger.warning("drive: encryption key unavailable, storing secret as-is: %s", e)
        return value


def _maybe_decrypt(value: str) -> str:
    """Расшифровать секрет при чтении. Бэк-компат: значения без префикса —
    старый plaintext, возвращаем как есть."""
    if not value or not value.startswith(_ENC_PREFIX):
        return value
    try:
        from app.services.google_calendar import decrypt_token
        return decrypt_token(value[len(_ENC_PREFIX):])
    except Exception as e:  # noqa: BLE001
        logger.error("drive: failed to decrypt stored secret: %s", e)
        raise


def _read_config() -> dict[str, Any]:
    p = _config_path()
    if not p.exists():
        return {}
    try:
        data = json.loads(p.read_text())
    except Exception:  # noqa: BLE001
        return {}
    # Прозрачная расшифровка секретных полей в plaintext для рантайма.
    for f in _SECRET_FIELDS:
        if isinstance(data.get(f), str):
            data[f] = _maybe_decrypt(data[f])
    return data


def _write_config(data: dict[str, Any]) -> None:
    # Шифруем секретные поля перед записью на диск (at-rest).
    to_store = dict(data)
    for f in _SECRET_FIELDS:
        if isinstance(to_store.get(f), str) and to_store[f]:
            to_store[f] = _maybe_encrypt(to_store[f])
    p = _config_path()
    p.parent.mkdir(parents=True, exist_ok=True)
    p.write_text(json.dumps(to_store, ensure_ascii=False, indent=2))
    p.chmod(0o600)


def redirect_uri() -> str:
    return f"{settings.public_base_url}/api/integrations/google-drive/oauth/callback"


def save_client_config(client_id: str, client_secret: str) -> None:
    cfg = _read_config()
    cfg["client_id"] = client_id.strip()
    cfg["client_secret"] = client_secret.strip()
    _write_config(cfg)


def has_client_config() -> bool:
    cfg = _read_config()
    return bool(cfg.get("client_id") and cfg.get("client_secret"))


# ============ C5: allowlist разрешённых папок (anti arbitrary-folder upload) ============


def get_allowed_folder_ids() -> list[str]:
    """Список folder_id, в которые разрешено выгружать. Пустой = ограничения
    нет (бэк-компат: текущее поведение, любая папка, доступная подключённому
    Google-аккаунту)."""
    cfg = _read_config()
    raw = cfg.get("allowed_folder_ids") or []
    if not isinstance(raw, list):
        return []
    return [str(x).strip() for x in raw if str(x).strip()]


def set_allowed_folder_ids(urls_or_ids: list[str]) -> list[str]:
    """Сохранить allowlist папок. Принимает ссылки или id, нормализует в id.
    Возвращает итоговый список id. Пустой список снимает ограничение."""
    normalized: list[str] = []
    for item in urls_or_ids or []:
        fid = extract_folder_id(str(item).strip()) if item else None
        if fid and fid not in normalized:
            normalized.append(fid)
    cfg = _read_config()
    cfg["allowed_folder_ids"] = normalized
    _write_config(cfg)
    return normalized


def is_folder_allowed(folder_id: str) -> bool:
    """True если папка разрешена. Пустой allowlist → разрешено всё (бэк-компат)."""
    allowed = get_allowed_folder_ids()
    if not allowed:
        return True
    return folder_id in allowed


def is_configured() -> bool:
    """Полностью настроено — есть refresh_token (пройдено согласие)."""
    return bool(_read_config().get("refresh_token"))


def get_connected_email() -> str | None:
    return _read_config().get("connected_email")


def build_auth_url() -> str:
    """Генерит URL согласия Google + сохраняет CSRF-state."""
    cfg = _read_config()
    if not cfg.get("client_id"):
        raise RuntimeError("Сначала введите Client ID и Client Secret")
    state = secrets.token_urlsafe(24)
    cfg["oauth_state"] = state
    _write_config(cfg)

    from urllib.parse import urlencode
    params = {
        "client_id": cfg["client_id"],
        "redirect_uri": redirect_uri(),
        "response_type": "code",
        "scope": " ".join(SCOPES + ["https://www.googleapis.com/auth/userinfo.email"]),
        "access_type": "offline",
        "prompt": "consent",  # гарантирует refresh_token при каждом подключении
        "state": state,
        "include_granted_scopes": "true",
    }
    return f"{AUTH_URI}?{urlencode(params)}"


def exchange_code(code: str, state: str) -> str:
    """Обменивает authorization code на refresh_token. Возвращает email аккаунта."""
    import httpx

    cfg = _read_config()
    if not cfg.get("oauth_state") or cfg["oauth_state"] != state:
        raise ValueError("Неверный state (CSRF-защита или истёкшая сессия). Повторите подключение.")

    resp = httpx.post(TOKEN_URI, data={
        "code": code,
        "client_id": cfg["client_id"],
        "client_secret": cfg["client_secret"],
        "redirect_uri": redirect_uri(),
        "grant_type": "authorization_code",
    }, timeout=30)
    resp.raise_for_status()
    tok = resp.json()

    if "refresh_token" not in tok:
        raise ValueError("Google не вернул refresh_token. Отзовите доступ приложению в настройках Google-аккаунта и попробуйте снова.")

    cfg["refresh_token"] = tok["refresh_token"]
    cfg.pop("oauth_state", None)

    email = None
    try:
        creds = _build_credentials(cfg)
        creds.refresh(GoogleRequest())
        service = build("oauth2", "v2", credentials=creds, cache_discovery=False)
        info = service.userinfo().get().execute()
        email = info.get("email")
    except Exception:  # noqa: BLE001
        pass
    cfg["connected_email"] = email
    _write_config(cfg)
    return email or "подключено"


def disconnect() -> None:
    cfg = _read_config()
    for k in ("refresh_token", "connected_email", "oauth_state"):
        cfg.pop(k, None)
    _write_config(cfg)


def _build_credentials(cfg: dict[str, Any]) -> Credentials:
    return Credentials(
        token=None,
        refresh_token=cfg["refresh_token"],
        client_id=cfg["client_id"],
        client_secret=cfg["client_secret"],
        token_uri=TOKEN_URI,
        scopes=SCOPES + ["https://www.googleapis.com/auth/userinfo.email"],
    )


def _get_service() -> Any:
    cfg = _read_config()
    if not cfg.get("refresh_token"):
        raise RuntimeError("Google Drive не подключён. Зайдите в /admin/integrations → «Подключить Google».")
    creds = _build_credentials(cfg)
    creds.refresh(GoogleRequest())
    return build("drive", "v3", credentials=creds, cache_discovery=False)


def upload_file(local_path: Path, folder_url_or_id: str, filename: str | None = None, mime_type: str | None = None) -> dict[str, str]:
    """Загружает файл в указанную папку Drive. supportsAllDrives=True для Общих дисков."""
    folder_id = extract_folder_id(folder_url_or_id)
    if not folder_id:
        raise ValueError("Не удалось извлечь id папки из ссылки")

    # C5: если админ задал allowlist папок — выгрузка только в разрешённые
    # (защита от arbitrary-folder upload в обход контрактной структуры). Пустой
    # allowlist = ограничения нет (бэк-компат с текущим поведением).
    if not is_folder_allowed(folder_id):
        raise ValueError(
            "Эта папка Google Drive не входит в список разрешённых. "
            "Обратитесь к администратору."
        )

    service = _get_service()
    media = MediaFileUpload(str(local_path), mimetype=mime_type, resumable=True)
    metadata = {"name": filename or local_path.name, "parents": [folder_id]}
    file = service.files().create(
        body=metadata,
        media_body=media,
        fields="id, webViewLink",
        supportsAllDrives=True,
    ).execute()
    return {"id": file["id"], "webViewLink": file.get("webViewLink", "")}
