"""Эпик 15 — Whisper integration: транскрибация записи звонка через OpenAI.

Чем занимается:
- `is_configured()` — есть ли OPENAI_API_KEY в env (graceful 503 если нет).
- `transcribe_audio_url(url, lang)` — скачать запись через httpx,
  отправить в OpenAI Whisper API, вернуть текст.

Зачем:
Записи звонков от Mango/UIS — URL'ы вида `https://provider.com/rec/xxx.mp3`.
Чтобы менеджер не слушал 5-минутный диалог, есть транскрипция → текстовый
поиск + автоматизации (sentiment, keyword-detection в плане).

Архитектурно:
- HTTP-client (httpx) для скачивания файла и для OpenAI запроса.
- Whisper API endpoint: POST https://api.openai.com/v1/audio/transcriptions
  - multipart/form-data: file=<audio>, model="whisper-1", language="ru"
- Async via httpx.AsyncClient — наш api FastAPI async.
- Background task в роутере через asyncio.create_task — звонок завершён,
  транскрипция гонится фоном.

Pure-функции (для тестов в test_whisper_client.py):
- `validate_audio_url(url)` — проверить URL на схему http/https и расширение.
- `validate_lang(lang)` — whitelist языков (ru/en/kk/uz по умолчанию).
- `parse_whisper_response(json_resp)` — извлечь text из ответа OpenAI.

NB: При отсутствии OPENAI_API_KEY роутер /transcribe возвращает 503 —
тестируется в роутере, не здесь.
"""
from __future__ import annotations

import logging
from dataclasses import dataclass
from typing import Any
from urllib.parse import urlparse

import httpx

from app.config import get_settings

logger = logging.getLogger(__name__)

# OpenAI Whisper API endpoint. Не менять без согласования — модель whisper-1
# единственная (на 2026 ещё нет whisper-2).
OPENAI_WHISPER_ENDPOINT = "https://api.openai.com/v1/audio/transcriptions"
OPENAI_WHISPER_MODEL = "whisper-1"

# Whitelist языков для Whisper API (ISO 639-1).
# https://platform.openai.com/docs/guides/speech-to-text/supported-languages
WHISPER_SUPPORTED_LANGS: frozenset[str] = frozenset({
    "ru", "en", "kk", "uz", "ky", "uk", "be", "az", "hy", "ka",
    "de", "fr", "es", "it", "pt", "tr", "zh", "ja", "ko", "ar",
})

# Допустимые аудио-расширения (Whisper принимает многое, но фильтруем
# заведомо левые URL'ы вроде .html).
VALID_AUDIO_EXTENSIONS: frozenset[str] = frozenset({
    ".mp3", ".mp4", ".wav", ".m4a", ".ogg", ".flac", ".webm", ".mpga", ".mpeg",
})

# Timeout на скачивание аудио. Большие WAV-файлы могут быть до 5+ MB.
DOWNLOAD_TIMEOUT_SECONDS = 60.0
# Timeout на сам Whisper API запрос. Транскрипция 5-минутной записи —
# обычно ≤30 сек, но даём запас.
WHISPER_TIMEOUT_SECONDS = 120.0


# ============ Exceptions ============

class WhisperNotConfiguredError(Exception):
    """OPENAI_API_KEY не задан — caller'у это сигнал отдать 503."""


class WhisperDownloadError(Exception):
    """Не удалось скачать аудио по URL."""


class WhisperAPIError(Exception):
    """OpenAI API вернул ошибку."""


# ============ Pure validators ============

def is_configured() -> bool:
    """True если OPENAI_API_KEY задан в env."""
    return bool(get_settings().__dict__.get("openai_api_key", "")) or _env_openai_key() != ""


def _env_openai_key() -> str:
    """Прочитать OPENAI_API_KEY из env (через os.environ, в обход Settings).

    Settings класс не имеет поля openai_api_key — добавлять не хотим,
    чтобы не разрастать config.py. Берём напрямую из os.environ
    (для test override через monkeypatch.setenv).
    """
    import os
    return os.environ.get("OPENAI_API_KEY", "") or ""


def validate_audio_url(url: str) -> bool:
    """Валидация URL: http/https + расширение из whitelist.

    Pure-функция. Не делает HTTP-запрос, только синтаксическая проверка.
    """
    if not url or not isinstance(url, str):
        return False
    try:
        parsed = urlparse(url)
    except ValueError:
        return False
    if parsed.scheme not in ("http", "https"):
        return False
    if not parsed.netloc:
        return False
    # Берём последний путь segment и проверяем суффикс.
    path = (parsed.path or "").lower()
    # Допускаем URL без расширения (например, ?file=...) — но если есть
    # точка в последнем segment'е, расширение должно быть из whitelist.
    last = path.rsplit("/", 1)[-1] if path else ""
    if "." in last:
        ext = "." + last.rsplit(".", 1)[-1]
        if ext not in VALID_AUDIO_EXTENSIONS:
            return False
    return True


def validate_lang(lang: str | None) -> str:
    """Вернуть валидный lang ISO-639-1 или дефолт "ru".

    Не raise'ит — на невалидном lang просто откатывается на ru.
    """
    if not lang or not isinstance(lang, str):
        return "ru"
    normalized = lang.strip().lower()
    if normalized not in WHISPER_SUPPORTED_LANGS:
        return "ru"
    return normalized


def parse_whisper_response(resp_json: dict[str, Any]) -> str:
    """Извлечь text из ответа OpenAI Whisper API.

    Whisper response: {"text": "..."}. Если структура другая (ошибка/
    изменение API) — raise WhisperAPIError.
    """
    if not isinstance(resp_json, dict):
        raise WhisperAPIError("Whisper response должен быть dict")
    text = resp_json.get("text")
    if not isinstance(text, str):
        raise WhisperAPIError(
            f"Whisper response не содержит 'text': {resp_json!r}"
        )
    return text


# ============ Result dataclass ============

@dataclass
class TranscriptionResult:
    """Результат транскрипции одной записи."""
    text: str
    lang: str
    duration_seconds: float | None = None


# ============ Main transcription function ============

async def transcribe_audio_url(
    url: str, lang: str = "ru",
) -> TranscriptionResult:
    """Скачать запись по URL и отправить в OpenAI Whisper API.

    Args:
        url: HTTP(S) URL аудио-файла. Должен быть доступен снаружи.
        lang: ISO-639-1 язык. Default "ru".

    Returns:
        TranscriptionResult с text и lang.

    Raises:
        WhisperNotConfiguredError: OPENAI_API_KEY не задан.
        WhisperDownloadError: download failed.
        WhisperAPIError: OpenAI вернул не-2xx или невалидный JSON.

    Pure-функция от сети: не пишет в БД, не обновляет ORM. Caller
    обновляет CalldownCall.transcript_text сам.
    """
    api_key = _env_openai_key()
    if not api_key:
        raise WhisperNotConfiguredError(
            "OPENAI_API_KEY не задан — Whisper integration disabled"
        )

    if not validate_audio_url(url):
        raise WhisperDownloadError(f"Невалидный audio URL: {url!r}")

    lang_norm = validate_lang(lang)

    # Шаг 1: скачать аудио.
    try:
        async with httpx.AsyncClient(
            timeout=DOWNLOAD_TIMEOUT_SECONDS, follow_redirects=True,
        ) as client:
            audio_resp = await client.get(url)
            audio_resp.raise_for_status()
            audio_bytes = audio_resp.content
    except httpx.HTTPError as e:
        raise WhisperDownloadError(
            f"Не удалось скачать аудио: {e}"
        ) from e

    if not audio_bytes:
        raise WhisperDownloadError("Аудио пустое")

    # Имя файла из URL — Whisper API требует имя с расширением для
    # определения формата. Если URL без расширения — фоллбэк на .mp3.
    filename = _extract_filename(url)

    # Шаг 2: POST в OpenAI Whisper.
    headers = {"Authorization": f"Bearer {api_key}"}
    files = {"file": (filename, audio_bytes, "application/octet-stream")}
    data = {"model": OPENAI_WHISPER_MODEL, "language": lang_norm}
    try:
        async with httpx.AsyncClient(
            timeout=WHISPER_TIMEOUT_SECONDS,
        ) as client:
            api_resp = await client.post(
                OPENAI_WHISPER_ENDPOINT,
                headers=headers, files=files, data=data,
            )
    except httpx.HTTPError as e:
        raise WhisperAPIError(
            f"Whisper API сетевая ошибка: {e}"
        ) from e

    if api_resp.status_code >= 400:
        # Логируем тело — обычно OpenAI возвращает JSON с error.message.
        body_preview = (api_resp.text or "")[:512]
        raise WhisperAPIError(
            f"Whisper API HTTP {api_resp.status_code}: {body_preview}"
        )

    try:
        resp_json = api_resp.json()
    except (ValueError, TypeError) as e:
        raise WhisperAPIError(
            f"Whisper response не JSON: {e}"
        ) from e

    text = parse_whisper_response(resp_json)
    return TranscriptionResult(text=text, lang=lang_norm)


def _extract_filename(url: str) -> str:
    """Извлечь basename из URL для multipart filename. Fallback "recording.mp3".

    Whisper API использует расширение для определения формата (он сам не
    парсит magic-bytes), поэтому фильтруем что без расширения → .mp3.
    """
    try:
        parsed = urlparse(url)
        path = parsed.path or ""
        last = path.rsplit("/", 1)[-1] if path else ""
        if last and "." in last:
            return last
    except ValueError:
        pass
    return "recording.mp3"
