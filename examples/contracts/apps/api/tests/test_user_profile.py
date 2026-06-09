"""Эпик 21 — UX Upgrade: pure-function тесты UpdateMeIn валидации + whitelists.

Без сети, без БД. Тестируем:
- ALLOWED_THEME_PREFERENCES / ALLOWED_LOCALES whitelists содержат ожидаемое;
- UpdateMeIn pydantic-схема принимает новые поля как optional;
- Логика валидации theme/locale в роутере /me (что invalid → 400).
"""
from __future__ import annotations

import pytest
from fastapi import HTTPException

from app.routers.users import (
    ALLOWED_LOCALES,
    ALLOWED_SIGNATURE_TYPES,
    ALLOWED_THEME_PREFERENCES,
    MAX_SIGNATURE_SIZE,
)
from app.schemas import LoginOut, UpdateMeIn, UserOut


# ============ Whitelists ============


def test_theme_whitelist_has_three_options():
    """system | light | dark — других вариантов в UI быть не должно."""
    assert ALLOWED_THEME_PREFERENCES == {"system", "light", "dark"}


def test_locale_whitelist_has_ru_and_en():
    """Сейчас два варианта; новые добавляются вместе с i18n-эпиком."""
    assert ALLOWED_LOCALES == {"ru", "en"}


def test_signature_types_only_png_jpeg():
    """SVG / WEBP не принимаем — рендер документов работает с PNG/JPEG надёжнее.
    Дополнительная мера защиты от XSS через SVG."""
    assert ALLOWED_SIGNATURE_TYPES == {"image/jpeg", "image/png"}


def test_signature_size_limit_1mb():
    """Подпись это маленький PNG; 1 МБ покрывает с запасом."""
    assert MAX_SIGNATURE_SIZE == 1 * 1024 * 1024


# ============ UpdateMeIn pydantic schema ============


def test_update_me_in_all_fields_optional():
    """Полностью пустой payload должен парситься (partial PATCH)."""
    payload = UpdateMeIn()
    assert payload.full_name is None
    assert payload.email is None
    assert payload.theme_preference is None
    assert payload.locale is None
    assert payload.job_title is None


def test_update_me_in_partial_profile_fields():
    """Можем поменять только тему, не трогая остальное."""
    payload = UpdateMeIn(theme_preference="dark")
    assert payload.theme_preference == "dark"
    assert payload.locale is None
    assert payload.job_title is None


def test_update_me_in_accepts_long_job_title_pydantic_level():
    """Pydantic схема не лимитирует длину (это БД-уровень VARCHAR(128))."""
    payload = UpdateMeIn(job_title="A" * 500)
    assert payload.job_title is not None
    assert len(payload.job_title) == 500


# ============ Validation logic (имитация роутера) ============


def _validate_theme(value: str) -> None:
    """Чистая копия проверки из routers/users.py::update_me для тестов.

    Если в роутере поправят whitelist — этот тест НЕ заметит расхождения
    (мы тестируем константу через ALLOWED_THEME_PREFERENCES напрямую).
    """
    if value not in ALLOWED_THEME_PREFERENCES:
        raise HTTPException(
            400,
            f"theme_preference должно быть одним из {sorted(ALLOWED_THEME_PREFERENCES)}",
        )


def _validate_locale(value: str) -> None:
    if value not in ALLOWED_LOCALES:
        raise HTTPException(
            400, f"locale должно быть одним из {sorted(ALLOWED_LOCALES)}",
        )


def test_validate_theme_accepts_all_whitelist():
    for valid in ("system", "light", "dark"):
        _validate_theme(valid)  # не должен бросить


def test_validate_theme_rejects_random_value():
    with pytest.raises(HTTPException) as exc:
        _validate_theme("midnight")
    assert exc.value.status_code == 400
    assert "theme_preference" in exc.value.detail


def test_validate_theme_rejects_empty_string():
    with pytest.raises(HTTPException):
        _validate_theme("")


def test_validate_locale_accepts_ru_en():
    _validate_locale("ru")
    _validate_locale("en")


def test_validate_locale_rejects_unknown():
    with pytest.raises(HTTPException) as exc:
        _validate_locale("fr")
    assert exc.value.status_code == 400


def test_validate_locale_case_sensitive():
    """RU != ru — мы храним нижний регистр (canonical form)."""
    with pytest.raises(HTTPException):
        _validate_locale("RU")


# ============ UserOut / LoginOut shape ============


def test_user_out_has_new_profile_fields():
    """Pydantic UserOut должен содержать новые поля как опциональные."""
    fields = UserOut.model_fields
    assert "theme_preference" in fields
    assert "locale" in fields
    assert "job_title" in fields
    assert "signature_url" in fields


def test_login_out_has_new_profile_fields():
    fields = LoginOut.model_fields
    assert "theme_preference" in fields
    assert "locale" in fields
    assert "job_title" in fields
    assert "signature_url" in fields


def test_user_out_default_theme_is_system():
    """Default 'system' соответствует server-default в миграции 0041."""
    fields = UserOut.model_fields
    assert fields["theme_preference"].default == "system"
    assert fields["locale"].default == "ru"
