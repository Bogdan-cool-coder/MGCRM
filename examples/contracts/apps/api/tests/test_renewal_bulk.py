"""Renewal pipeline + Bulk-generation + Funnel/Forecast (Эпик 6 MVP) — pure-function tests.

Тесты без БД: проверяем структуру сидера renewal-воронки, whitelist'ы bulk,
ZIP-упаковку и forecast/funnel-логику (compute_funnel_metrics,
compute_forecast_revenue, probability_for_stage).
"""
from __future__ import annotations

import json
import zipfile
from datetime import datetime, timedelta, timezone
from pathlib import Path

import pytest

from app.services.analytics import (
    PROBABILITY_KEYWORDS,
    compute_forecast_revenue,
    compute_funnel_metrics,
    probability_for_stage,
)
from app.services.bulk_generator import (
    BULK_KINDS,
    BULK_STATUSES,
    BULK_TARGET_TYPES,
    ResolvedParty,
    _company_country_code,
    _safe_filename,
    pack_zip,
)
from app.services.customer_success import _SEED_LOCK_CS_REF, _SEED_LOCK_LIFECYCLE
from app.services.deals import _SEED_LOCK_KEY as _SEED_LOCK_DEALS
from app.services.leads import _SEED_LOCK_LEAD
from app.services.renewal import (
    RENEWAL_PIPELINE_NAME,
    RENEWAL_STAGES,
    _SEED_LOCK_RENEWAL,
)


# ============ Renewal pipeline ============


def test_renewal_pipeline_name_stable():
    """Имя renewal-воронки зафиксировано — менять только по явной правке."""
    assert RENEWAL_PIPELINE_NAME == "Продления"


def test_renewal_stages_well_formed():
    """6 этапов, уникальные коды, order 1..6 строго возрастает."""
    assert len(RENEWAL_STAGES) == 6
    codes = [c for _n, c, _o in RENEWAL_STAGES]
    assert len(set(codes)) == len(codes), "коды этапов должны быть уникальны"
    orders = [o for _n, _c, o in RENEWAL_STAGES]
    assert orders == [1, 2, 3, 4, 5, 6], "order должен быть строго 1..6"
    names = [n for n, _c, _o in RENEWAL_STAGES]
    assert names == [
        "Готов к продлению", "КП отправлено", "На согласовании",
        "Подписан", "Закрыт-оплачен", "Отказ",
    ]


def test_renewal_stages_won_lost_flags():
    """signed/paid → won, lost → lost; остальные нейтральные."""
    expected = {
        "renew_ready": (False, False),
        "proposal_sent": (False, False),
        "negotiation": (False, False),
        "signed": (True, False),
        "paid": (True, False),
        "lost": (False, True),
    }
    for _name, code, _order in RENEWAL_STAGES:
        # signed/paid должны быть won, lost — lost. Логика в seed_renewal_pipeline.
        is_won = code in ("signed", "paid")
        is_lost = code == "lost"
        assert expected[code] == (is_won, is_lost), f"flags for {code}"


def test_renewal_stages_codes_fit_db_column():
    """Защита от регрессии: PipelineStage.code = VARCHAR(16).
    Прод-инцидент 31 мая 2026: `ready_for_renewal` (17 символов) валил INSERT
    в lifespan. Этот тест должен ловить такие коды до деплоя.
    """
    MAX_CODE_LEN = 16
    for name, code, _order in RENEWAL_STAGES:
        assert len(code) <= MAX_CODE_LEN, (
            f"code '{code}' (этап «{name}») = {len(code)} > {MAX_CODE_LEN} "
            f"символов; превысит pipeline_stages.code VARCHAR({MAX_CODE_LEN})"
        )


def test_renewal_seed_lock_unique_from_other_seeds():
    """Защита от случайного конфликта advisory-lock'ов между сидерами."""
    other_locks = {
        _SEED_LOCK_CS_REF,        # 728_274_005
        _SEED_LOCK_LIFECYCLE,     # 728_274_006
        _SEED_LOCK_DEALS,         # 728_274_004
        _SEED_LOCK_LEAD,          # 728_274_007
    }
    assert _SEED_LOCK_RENEWAL == 728_274_008
    assert _SEED_LOCK_RENEWAL not in other_locks


# ============ Bulk generator whitelists ============


def test_bulk_kinds_whitelist():
    """Whitelist kind строго ограничен document_generation на MVP."""
    assert BULK_KINDS == ("document_generation",)
    assert "document_generation" in BULK_KINDS


def test_bulk_statuses_whitelist():
    """Статусы покрывают полный lifecycle: pending → running → success|failed|cancelled."""
    expected = {"pending", "running", "success", "failed", "cancelled"}
    assert set(BULK_STATUSES) == expected


def test_bulk_target_types_whitelist():
    """target_type разрешён только для counterparty/subscription в MVP."""
    assert set(BULK_TARGET_TYPES) == {"counterparty", "subscription"}


def test_safe_filename_strips_bad_chars():
    """Опасные для FS символы заменяются на _, кириллица сохраняется."""
    assert _safe_filename("a/b\\c:d*e", 1) == "a_b_c_d_e"
    assert _safe_filename('ООО "Ромашка"', 2) == 'ООО _Ромашка_'
    assert _safe_filename("", 5) == "id_5"
    assert _safe_filename(None, 7) == "id_7"  # type: ignore[arg-type]


def test_safe_filename_truncates_long():
    """Длинные имена обрезаются до 120 символов."""
    long = "a" * 300
    assert len(_safe_filename(long, 1)) == 120


# ============ CONTACTS 2.0 Ф4: party-резолв bulk (Company-first) ============


def test_company_country_code_prefers_iso_country():
    """Страна Company для licensor: ISO `country` важнее зеркала `country_code`."""
    from app.models import Company
    co = Company(legal_name="X", name="X", country="KZ", country_code="ru")
    assert _company_country_code(co) == "kz"


def test_company_country_code_falls_back_to_country_code():
    """Если ISO `country` пуст — берём зеркало `country_code`."""
    from app.models import Company
    co = Company(legal_name="X", name="X", country=None, country_code="UAE")
    assert _company_country_code(co) == "uae"


def test_company_country_code_none_when_empty():
    """Нет ни одного — None (бережём дефолт 'kz' в _build_render_context)."""
    from app.models import Company
    co = Company(legal_name="X", name="X", country=None, country_code=None)
    assert _company_country_code(co) is None


def test_resolved_party_carries_company_first_requisites():
    """ResolvedParty несёт реквизиты из Company-маппинга (общий services.party).

    Защита от регрессии: bulk-генерация должна класть в шаблон реквизиты Company,
    а не пустой/legacy dict. Здесь — структурная проверка контейнера без БД.
    """
    from app.services.party import sublicensee_from_company
    from app.models import Company
    co = Company(
        legal_name='ТОО "Тест"', name="Тест", country="kz",
        tax_id_label="БИН", tax_id="111", bank="Kaspi",
    )
    party = ResolvedParty(
        sublicensee=sublicensee_from_company(co),
        display_name="Тест",
        city="Алматы",
        country_code="kz",
        company_id=5,
        counterparty_id=9,
    )
    # Реквизиты пришли из Company, не пустые.
    assert party.sublicensee["tax_id"] == "111"
    assert party.sublicensee["bank"] == "Kaspi"
    assert party.sublicensee["name"] == "Тест"
    # Оба id зеркалятся (company_id новый источник, counterparty_id legacy).
    assert party.company_id == 5
    assert party.counterparty_id == 9


# ============ ZIP packing ============


def test_zipfile_packs_docx_and_manifest(tmp_path: Path):
    """pack_zip собирает .docx-файлы + manifest.json в один валидный ZIP."""
    # Создаём фейковые .docx (просто файлы с произвольным содержимым)
    docx1 = tmp_path / "doc1.docx"
    docx1.write_bytes(b"FAKE_DOCX_CONTENT_1")
    docx2 = tmp_path / "doc2.docx"
    docx2.write_bytes(b"FAKE_DOCX_CONTENT_2")
    manifest = [
        {"target_id": 1, "status": "success", "file": "doc1.docx"},
        {"target_id": 2, "status": "success", "file": "doc2.docx"},
    ]
    out_zip = tmp_path / "result.zip"
    size = pack_zip(out_zip, [docx1, docx2], manifest)

    assert size > 0
    assert out_zip.exists()
    with zipfile.ZipFile(out_zip) as zf:
        names = set(zf.namelist())
        assert names == {"doc1.docx", "doc2.docx", "manifest.json"}
        with zf.open("manifest.json") as f:
            decoded = json.loads(f.read().decode("utf-8"))
        assert decoded == manifest
        assert zf.read("doc1.docx") == b"FAKE_DOCX_CONTENT_1"


def test_zipfile_skips_missing_docx(tmp_path: Path):
    """Если .docx не существует — pack_zip не падает, просто пропускает."""
    missing = tmp_path / "doesnt_exist.docx"
    out_zip = tmp_path / "result.zip"
    size = pack_zip(out_zip, [missing], [{"target_id": 1, "status": "failed"}])
    assert size > 0
    with zipfile.ZipFile(out_zip) as zf:
        assert "manifest.json" in zf.namelist()
        assert "doesnt_exist.docx" not in zf.namelist()


# ============ Probability heuristic ============


def test_probability_for_stage_won_lost_flags():
    """Флаги имеют приоритет над названиями."""
    assert probability_for_stage("Холод", is_won=True) == 1.0
    assert probability_for_stage("Горяч", is_lost=True) == 0.0


def test_probability_for_stage_keywords_ru_en():
    """Keyword-маппинг работает и для русских, и для английских этапов."""
    assert probability_for_stage("HOT deals") == 0.7
    assert probability_for_stage("Горячие") == 0.7
    assert probability_for_stage("Тёплые") == 0.4
    assert probability_for_stage("warm leads") == 0.4
    assert probability_for_stage("Trial") == 0.5
    assert probability_for_stage("Квалификация") == 0.15
    assert probability_for_stage("Cold deals") == 0.1


def test_probability_for_stage_unknown_returns_zero():
    """Неизвестное название — 0.0 (не вносит в forecast)."""
    assert probability_for_stage("Произвольный этап") == 0.0
    assert probability_for_stage("") == 0.0


def test_probability_keywords_sorted_priority():
    """Won-keyword'ы должны идти раньше менее вероятных — иначе 'успех' матчнется на cold."""
    # Проверяем что 'успех' встречается раньше любого ключевого слова с probability < 1.0
    success_idx = next(i for i, (kw, _) in enumerate(PROBABILITY_KEYWORDS) if kw == "успех")
    cold_idx = next(i for i, (kw, _) in enumerate(PROBABILITY_KEYWORDS) if kw == "холод")
    assert success_idx < cold_idx


# ============ Funnel metrics ============


def test_compute_funnel_metrics_basic_shape():
    """Возвращает list[dict] с правильными ключами для каждой стадии."""
    now = datetime.now(timezone.utc)
    stages = [
        {"id": 1, "name": "Квалификация", "code": "q", "sort_order": 1, "is_won": False, "is_lost": False},
        {"id": 2, "name": "Горячие", "code": "h", "sort_order": 2, "is_won": False, "is_lost": False},
        {"id": 3, "name": "Успех", "code": "w", "sort_order": 3, "is_won": True, "is_lost": False},
        {"id": 4, "name": "Проигрыш", "code": "l", "sort_order": 4, "is_won": False, "is_lost": True},
    ]
    deals = [
        {"stage_id": 1, "updated_at": now - timedelta(days=10), "amount": 100},
        {"stage_id": 1, "updated_at": now - timedelta(days=5), "amount": 200},
        {"stage_id": 2, "updated_at": now - timedelta(days=2), "amount": 500},
        {"stage_id": 3, "updated_at": now - timedelta(days=1), "amount": 1000},
    ]
    metrics = compute_funnel_metrics(stages, deals)
    assert len(metrics) == 4
    required_keys = {
        "stage_id", "stage_name", "stage_code", "sort_order", "count",
        "avg_days_in_stage", "transition_to_next_pct",
        "is_won", "is_lost", "probability",
    }
    for m in metrics:
        assert required_keys.issubset(m.keys())
    # Counts: stage 1 → 2, stage 2 → 1, stage 3 → 1, stage 4 → 0
    counts_by_id = {m["stage_id"]: m["count"] for m in metrics}
    assert counts_by_id == {1: 2, 2: 1, 3: 1, 4: 0}


def test_compute_funnel_metrics_won_lost_transition():
    """Won = 100% transition, lost = 0% transition."""
    stages = [
        {"id": 10, "name": "Успех", "sort_order": 1, "is_won": True, "is_lost": False},
        {"id": 11, "name": "Lost", "sort_order": 2, "is_won": False, "is_lost": True},
    ]
    metrics = compute_funnel_metrics(stages, [])
    by_id = {m["stage_id"]: m for m in metrics}
    assert by_id[10]["transition_to_next_pct"] == 100.0
    assert by_id[11]["transition_to_next_pct"] == 0.0


def test_compute_funnel_metrics_transition_pct():
    """Transition % = late_total / (current + late_total) для нон-won/нон-lost."""
    stages = [
        {"id": 1, "name": "A", "sort_order": 1, "is_won": False, "is_lost": False},
        {"id": 2, "name": "B", "sort_order": 2, "is_won": False, "is_lost": False},
        {"id": 3, "name": "C", "sort_order": 3, "is_won": True, "is_lost": False},
    ]
    # 10 на A, 5 на B, 3 на C → transition A = 8/18 = 44.4%, B = 3/8 = 37.5%, C = 100%
    deals = (
        [{"stage_id": 1, "updated_at": None, "amount": None}] * 10
        + [{"stage_id": 2, "updated_at": None, "amount": None}] * 5
        + [{"stage_id": 3, "updated_at": None, "amount": None}] * 3
    )
    metrics = compute_funnel_metrics(stages, deals)
    by_id = {m["stage_id"]: m for m in metrics}
    assert by_id[1]["transition_to_next_pct"] == 44.4
    assert by_id[2]["transition_to_next_pct"] == 37.5
    assert by_id[3]["transition_to_next_pct"] == 100.0


def test_compute_funnel_metrics_empty_deals():
    """Без сделок — counts = 0, transitions = 0/100% по флагам."""
    stages = [
        {"id": 1, "name": "Q", "sort_order": 1, "is_won": False, "is_lost": False},
    ]
    metrics = compute_funnel_metrics(stages, [])
    assert len(metrics) == 1
    assert metrics[0]["count"] == 0
    assert metrics[0]["avg_days_in_stage"] == 0.0
    assert metrics[0]["transition_to_next_pct"] == 0.0


# ============ Forecast ============


def test_compute_forecast_revenue_basic():
    """Basic shape + расчёт по weighted sum."""
    stages = [
        {"id": 1, "name": "Cold", "is_won": False, "is_lost": False, "sort_order": 1},
        {"id": 2, "name": "HOT", "is_won": False, "is_lost": False, "sort_order": 2},
        {"id": 3, "name": "Успех", "is_won": True, "is_lost": False, "sort_order": 3},
        {"id": 4, "name": "Lost", "is_won": False, "is_lost": True, "sort_order": 4},
    ]
    deals = [
        {"stage_id": 1, "amount": 100},
        {"stage_id": 2, "amount": 200},
        {"stage_id": 2, "amount": 300},
        {"stage_id": 3, "amount": 1000},     # won → влияет на avg
        {"stage_id": 3, "amount": 1500},     # won
        {"stage_id": 4, "amount": 999999},   # lost → игнорим
    ]
    result = compute_forecast_revenue(stages, deals)
    # avg_won = (1000 + 1500) / 2 = 1250
    assert result["avg_value_per_won"] == 1250.0
    assert result["won_count"] == 2
    # active_by_name: Cold → 1, HOT → 2 (Успех и Lost не активные)
    assert result["active_deals_by_stage"] == {"Cold": 1, "HOT": 2}
    # probability: Cold=0.1, HOT=0.7
    assert result["probability_by_stage"]["Cold"] == 0.1
    assert result["probability_by_stage"]["HOT"] == 0.7
    # estimated = 1 × 1250 × 0.1 + 2 × 1250 × 0.7 = 125 + 1750 = 1875
    assert result["estimated_revenue"] == 1875.0
    # breakdown содержит 2 элемента в порядке sort_order
    assert len(result["by_stage_breakdown"]) == 2
    assert result["by_stage_breakdown"][0]["stage_name"] == "Cold"
    assert result["by_stage_breakdown"][1]["stage_name"] == "HOT"
    # Контракт ключей breakdown (фронт RevenueForecastWidget читает count/estimated,
    # НЕ deals_count/estimated_revenue — иначе колонки на дашборде пустые).
    cold = result["by_stage_breakdown"][0]
    hot = result["by_stage_breakdown"][1]
    assert set(cold.keys()) == {"stage_id", "stage_name", "count", "probability", "estimated"}
    assert cold["count"] == 1
    assert cold["estimated"] == 125.0       # 1 × 1250 × 0.1
    assert hot["count"] == 2
    assert hot["estimated"] == 1750.0       # 2 × 1250 × 0.7
    # Итого = сумме строк breakdown
    assert result["estimated_revenue"] == sum(
        s["estimated"] for s in result["by_stage_breakdown"]
    )


def test_compute_forecast_revenue_currency_primary_no_mix():
    """Мульти-валютность: avg чек берётся из primary-валюты won-сделок,
    KZT и RUB не складываются в одно число; currency возвращается в ответе."""
    stages = [
        {"id": 1, "name": "HOT", "is_won": False, "is_lost": False, "sort_order": 1},
        {"id": 2, "name": "Успех", "is_won": True, "is_lost": False, "sort_order": 2},
    ]
    deals = [
        {"stage_id": 1, "amount": 100, "currency": "KZT"},
        # won-сделки в двух валютах: KZT суммарно больше → primary = KZT
        {"stage_id": 2, "amount": 1_000_000, "currency": "KZT"},
        {"stage_id": 2, "amount": 2_000_000, "currency": "KZT"},
        {"stage_id": 2, "amount": 50, "currency": "RUB"},
    ]
    result = compute_forecast_revenue(stages, deals)
    assert result["currency"] == "KZT"
    # avg только по KZT-won: (1_000_000 + 2_000_000) / 2 = 1_500_000 (RUB не примешан)
    assert result["avg_value_per_won"] == 1_500_000.0
    # won_count считает все won-сделки (для подписи «Выиграно сделок»)
    assert result["won_count"] == 3


def test_compute_forecast_revenue_currency_none_when_absent():
    """Если у сделок нет валюты — currency=None (фронт покажет число без символа)."""
    stages = [
        {"id": 1, "name": "Успех", "is_won": True, "is_lost": False, "sort_order": 1},
        {"id": 2, "name": "HOT", "is_won": False, "is_lost": False, "sort_order": 2},
    ]
    deals = [
        {"stage_id": 1, "amount": 1000},
        {"stage_id": 2, "amount": 200},
    ]
    result = compute_forecast_revenue(stages, deals)
    assert result["currency"] is None
    assert result["avg_value_per_won"] == 1000.0


def test_compute_forecast_revenue_no_won_uses_default():
    """Если ещё не было won-сделок — берём default_avg_value."""
    stages = [
        {"id": 1, "name": "HOT", "is_won": False, "is_lost": False, "sort_order": 1},
    ]
    deals = [{"stage_id": 1, "amount": None}]
    result = compute_forecast_revenue(stages, deals, default_avg_value=500.0)
    assert result["avg_value_per_won"] == 500.0
    assert result["won_count"] == 0
    # estimated = 1 × 500 × 0.7 = 350
    assert result["estimated_revenue"] == 350.0


def test_compute_forecast_revenue_empty_stages():
    """Пустой список — пустой результат, без падения."""
    result = compute_forecast_revenue([], [])
    assert result["estimated_revenue"] == 0.0
    assert result["won_count"] == 0
    assert result["active_deals_by_stage"] == {}


def test_compute_forecast_revenue_ignores_invalid_amount():
    """Невалидные amount (строки и т.п.) трактуются как 0.0."""
    stages = [
        {"id": 1, "name": "Успех", "is_won": True, "is_lost": False, "sort_order": 1},
        {"id": 2, "name": "HOT", "is_won": False, "is_lost": False, "sort_order": 2},
    ]
    deals = [
        {"stage_id": 1, "amount": "not_a_number"},
        {"stage_id": 1, "amount": 1000},
        {"stage_id": 2, "amount": None},
    ]
    result = compute_forecast_revenue(stages, deals)
    # avg_won = (0 + 1000) / 2 = 500
    assert result["avg_value_per_won"] == 500.0
    assert result["won_count"] == 2
