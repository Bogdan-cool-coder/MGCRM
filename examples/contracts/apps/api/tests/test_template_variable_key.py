"""Валидация ключа кастомной переменной (тег {{ custom.<key> }})."""

import pytest
from fastapi import HTTPException

from app.routers.template_variables import _validate_key


def test_valid_key_normalized_to_lower():
    assert _validate_key("Warranty_Period") == "warranty_period"
    assert _validate_key("  term2  ") == "term2"


def test_invalid_key_raises():
    for bad in ("1bad", "bad-key", "", "имя", "with space"):
        with pytest.raises(HTTPException):
            _validate_key(bad)


def test_reserved_namespace_rejected():
    for reserved in ("custom", "license", "contract", "product"):
        with pytest.raises(HTTPException):
            _validate_key(reserved)
