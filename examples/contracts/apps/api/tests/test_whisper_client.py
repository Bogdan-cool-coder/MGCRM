"""Эпик 15 — Whisper integration: URL/lang validation + response parsing (pure-function).

Без сети: проверяем валидаторы и parse_whisper_response. Реальный HTTP-вызов
к OpenAI mock'ается отдельно (integration-test, не здесь).
"""
from __future__ import annotations

import pytest

from app.services.whisper import (
    OPENAI_WHISPER_ENDPOINT,
    OPENAI_WHISPER_MODEL,
    VALID_AUDIO_EXTENSIONS,
    WHISPER_SUPPORTED_LANGS,
    WhisperAPIError,
    parse_whisper_response,
    validate_audio_url,
    validate_lang,
)


# ============ validate_audio_url ============

@pytest.mark.parametrize(
    "url,expected",
    [
        ("https://provider.com/rec/x.mp3", True),
        ("http://provider.com/rec/y.wav", True),
        ("https://provider.com/x.m4a", True),
        ("https://provider.com/audio", True),  # без расширения — OK
        ("https://provider.com/track.flac", True),
        ("https://provider.com/x.html", False),  # не audio
        ("https://provider.com/x.exe", False),
        ("ftp://provider.com/x.mp3", False),    # не http(s)
        ("file:///etc/passwd", False),
        ("", False),
        (None, False),
        ("not-a-url", False),
        ("https://", False),  # нет netloc
    ],
)
def test_validate_audio_url(url, expected):
    assert validate_audio_url(url) is expected


def test_valid_audio_extensions_constant():
    """Whitelist расширений содержит mp3/wav/m4a минимум."""
    required = {".mp3", ".wav", ".m4a"}
    assert required.issubset(VALID_AUDIO_EXTENSIONS)


# ============ validate_lang ============

@pytest.mark.parametrize(
    "lang,expected",
    [
        ("ru", "ru"),
        ("en", "en"),
        ("RU", "ru"),       # case insensitivity
        ("  en  ", "en"),   # пробелы
        ("kk", "kk"),
        ("uz", "uz"),
        ("xx", "ru"),       # неизвестный → дефолт ru
        ("", "ru"),
        (None, "ru"),
        ("fr", "fr"),
    ],
)
def test_validate_lang(lang, expected):
    assert validate_lang(lang) == expected


def test_whisper_supported_langs_includes_ru_en():
    """RU и EN обязательны (наша основная аудитория)."""
    assert "ru" in WHISPER_SUPPORTED_LANGS
    assert "en" in WHISPER_SUPPORTED_LANGS
    assert "kk" in WHISPER_SUPPORTED_LANGS  # KZ важен — наш регион
    assert "uz" in WHISPER_SUPPORTED_LANGS  # UZ важен — наш регион


# ============ parse_whisper_response ============

def test_parse_whisper_response_ok():
    """Стандартный OK-ответ Whisper: {'text': '...'}"""
    text = parse_whisper_response({"text": "Привет, как дела?"})
    assert text == "Привет, как дела?"


def test_parse_whisper_response_no_text_field():
    with pytest.raises(WhisperAPIError, match="text"):
        parse_whisper_response({"error": "something"})


def test_parse_whisper_response_not_dict():
    with pytest.raises(WhisperAPIError, match="dict"):
        parse_whisper_response(["array"])  # type: ignore[arg-type]


def test_parse_whisper_response_text_not_string():
    with pytest.raises(WhisperAPIError, match="text"):
        parse_whisper_response({"text": 123})


# ============ Constants ============

def test_whisper_endpoint_constant():
    assert OPENAI_WHISPER_ENDPOINT == "https://api.openai.com/v1/audio/transcriptions"
    assert OPENAI_WHISPER_MODEL == "whisper-1"


# ============ _extract_filename ============

def test_extract_filename_with_extension():
    from app.services.whisper import _extract_filename
    assert _extract_filename("https://p.com/rec/abc.mp3") == "abc.mp3"
    assert _extract_filename("https://p.com/path/to/file.wav") == "file.wav"


def test_extract_filename_without_extension():
    from app.services.whisper import _extract_filename
    # URL без файла → fallback
    assert _extract_filename("https://p.com/api/v1/audio?id=x") == "recording.mp3"
    assert _extract_filename("https://p.com/") == "recording.mp3"
