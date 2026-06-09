"""Lead pipeline (Эпик 1.0) — структурные тесты сидера без БД."""
from __future__ import annotations

from app.services.customer_success import _SEED_LOCK_LIFECYCLE
from app.services.leads import LEAD_PIPELINE_NAME, LEAD_STAGES, _SEED_LOCK_LEAD


def test_lead_stages_well_formed():
    """5 этапов, уникальные коды, order 1..5 строго возрастает."""
    assert len(LEAD_STAGES) == 5
    codes = [c for _n, c, _o in LEAD_STAGES]
    assert len(set(codes)) == len(codes), "коды этапов должны быть уникальны"
    orders = [o for _n, _c, o in LEAD_STAGES]
    assert orders == [1, 2, 3, 4, 5], "order должен быть 1..5 в правильной последовательности"
    # Проверка названий — фиксируем продакт-решение
    names = [n for n, _c, _o in LEAD_STAGES]
    assert names == ["Новый", "На обработке", "Квалифицирован", "В работу", "Архив"]


def test_lead_pipeline_name_stable():
    """Имя воронки лидов зафиксировано — менять только по явной правке."""
    assert LEAD_PIPELINE_NAME == "Воронка лидов"


def test_lead_source_values():
    """Допустимый список источников лида — продакт-фиксированный."""
    from app.routers.leads import _ALLOWED_SOURCES
    assert _ALLOWED_SOURCES == {"manual", "form", "import", "api", "email", "tg", "wa"}


def test_lead_status_values():
    """Допустимый список статусов лида (active/converted/archived/lost)."""
    from app.routers.leads import _ALLOWED_STATUSES
    assert _ALLOWED_STATUSES == {"active", "converted", "archived", "lost"}


def test_lead_pipeline_seed_key_unique_from_lifecycle():
    """Защита от случайного конфликта advisory-lock'ов между сидерами."""
    assert _SEED_LOCK_LEAD != _SEED_LOCK_LIFECYCLE
    # Sanity: ключ ровно тот, что в задаче (Эпик 1.0)
    assert _SEED_LOCK_LEAD == 728_274_007
