"""DEALS 2.0 (Ф0) — pure-function тесты: канон-структура воронки, ремап
старых этапов → новые, маппинг Lead→Deal/Company, реестр причин отказа,
revision-length миграций 0074-0076.

Без БД-фикстуры. Интеграционный прогон ремапа на реальной БД — отдельный slot
(нужен docker-compose test runner); здесь покрыта вся чистая логика, которую
переиспользуют сидер services/deals.py и миграции 0075/0076.
"""
from __future__ import annotations

from pathlib import Path

from app.services.deals_v2 import (
    CODE_COLD,
    CODE_HOT,
    CODE_LOST,
    CODE_MEETING,
    CODE_NEW,
    CODE_QUALIFY,
    CODE_SCHEDULE_MEETING,
    CODE_WARM,
    CODE_WON,
    DEFAULT_LOST_REASONS,
    FEATURE_GENERATE_DOCUMENT,
    FEATURE_MEETING_REPORT,
    FEATURE_SEND_PRESENTATION,
    NEW_SALES_STAGES,
    STAGE_FEATURES_WHITELIST,
    lead_stage_to_sales_code,
    lead_to_company_fields,
    lead_to_deal_fields,
    remap_old_stage_name,
)


# ============ Каноническая структура воронки ============

def test_new_sales_stages_count():
    """11 этапов: 9 верхнеуровневых + 2 подстатуса под «Успех»."""
    assert len(NEW_SALES_STAGES) == 11


def test_stage_codes_unique():
    codes = [s["code"] for s in NEW_SALES_STAGES]
    assert len(codes) == len(set(codes)), "коды этапов должны быть уникальны"


def test_sort_orders_unique_and_sequential():
    """sort_order = 1..11 без дыр и дублей (детерминированный канбан)."""
    orders = sorted(s["sort_order"] for s in NEW_SALES_STAGES)
    assert orders == list(range(1, 12))


def test_lost_stage_is_first_hidden_and_lost():
    lost = next(s for s in NEW_SALES_STAGES if s["code"] == CODE_LOST)
    assert lost["sort_order"] == 1
    assert lost["is_lost"] is True
    assert lost["hidden_by_default"] is True
    assert lost["is_won"] is False


def test_cold_stage_hidden_by_default():
    cold = next(s for s in NEW_SALES_STAGES if s["code"] == CODE_COLD)
    assert cold["hidden_by_default"] is True
    assert cold["is_lost"] is False


def test_won_stage_flags():
    won = next(s for s in NEW_SALES_STAGES if s["code"] == CODE_WON)
    assert won["is_won"] is True
    assert won["won_gate"] is True
    assert won["parent_code"] is None


def test_payment_substages_parent_is_won():
    """«Ожидаем оплату» и «Оплачено» — подстатусы (parent_code='won')."""
    children = [s for s in NEW_SALES_STAGES if s["parent_code"] == CODE_WON]
    codes = {s["code"] for s in children}
    assert codes == {"await_payment", "paid"}
    for c in children:
        assert c["is_won"] is True  # подстатусы успеха тоже won-этапы


def test_stage_features_within_whitelist():
    for s in NEW_SALES_STAGES:
        for f in s["stage_features"]:
            assert f in STAGE_FEATURES_WHITELIST, f"feature {f} не в whitelist"


def test_schedule_meeting_has_send_presentation():
    st = next(s for s in NEW_SALES_STAGES if s["code"] == CODE_SCHEDULE_MEETING)
    assert FEATURE_SEND_PRESENTATION in st["stage_features"]


def test_meeting_has_meeting_report():
    st = next(s for s in NEW_SALES_STAGES if s["code"] == CODE_MEETING)
    assert FEATURE_MEETING_REPORT in st["stage_features"]


def test_warm_and_hot_have_generate_document():
    for code in (CODE_WARM, CODE_HOT):
        st = next(s for s in NEW_SALES_STAGES if s["code"] == code)
        assert FEATURE_GENERATE_DOCUMENT in st["stage_features"]


def test_only_one_won_top_level_and_one_lost():
    top = [s for s in NEW_SALES_STAGES if s["parent_code"] is None]
    assert sum(1 for s in top if s["is_won"]) == 1
    assert sum(1 for s in top if s["is_lost"]) == 1


# ============ Ремап старых этапов → новые ============

def test_remap_inbound_outbound_to_new():
    assert remap_old_stage_name("Входящие лиды") == CODE_NEW
    assert remap_old_stage_name("Исходящие лиды") == CODE_NEW


def test_remap_qualification():
    assert remap_old_stage_name("Квалификация") == CODE_QUALIFY


def test_remap_schedule_meeting():
    assert remap_old_stage_name("Назначить встречу") == CODE_SCHEDULE_MEETING


def test_remap_vyezd_and_meeting_to_meeting():
    assert remap_old_stage_name("Выезд") == CODE_MEETING
    assert remap_old_stage_name("Встреча") == CODE_MEETING


def test_remap_cold():
    assert remap_old_stage_name("Холодные (заморозка)") == CODE_COLD
    assert remap_old_stage_name("Холодные") == CODE_COLD


def test_remap_warm_and_trial():
    assert remap_old_stage_name("Тёплые") == CODE_WARM
    assert remap_old_stage_name("Trial") == CODE_WARM


def test_remap_hot():
    assert remap_old_stage_name("Горячие") == CODE_HOT


def test_remap_success_to_won():
    assert remap_old_stage_name("Успех") == CODE_WON


def test_remap_loss_to_lost():
    assert remap_old_stage_name("Проигрыш") == CODE_LOST


def test_remap_unknown_falls_back_to_new():
    """Неизвестный/пустой этап → «Новые лиды» (не теряем сделку, не won/lost)."""
    assert remap_old_stage_name("какой-то старый этап") == CODE_NEW
    assert remap_old_stage_name("") == CODE_NEW
    assert remap_old_stage_name(None) == CODE_NEW


def test_remap_all_old_amo_stages_resolve_to_valid_new_codes():
    """Каждый старый AMO-этап ремапится в существующий новый code (инвариант:
    нет битых stage_id после миграции)."""
    from app.services.deals import AMO_STAGES

    valid_codes = {s["code"] for s in NEW_SALES_STAGES}
    for name, *_ in AMO_STAGES:
        assert remap_old_stage_name(name) in valid_codes, (
            f"старый этап '{name}' ремапится в несуществующий code"
        )


# ============ Lead → sales-этап ============

def test_lead_stage_new_processing_to_new():
    assert lead_stage_to_sales_code("new") == CODE_NEW
    assert lead_stage_to_sales_code("processing") == CODE_NEW


def test_lead_stage_qualified_and_in_work_to_qualify():
    assert lead_stage_to_sales_code("qualified") == CODE_QUALIFY
    assert lead_stage_to_sales_code("in_work") == CODE_QUALIFY


def test_lead_stage_archived_to_lost():
    assert lead_stage_to_sales_code("archived") == CODE_LOST


def test_lead_status_fallback_when_stage_missing():
    assert lead_stage_to_sales_code(None, "lost") == CODE_LOST
    assert lead_stage_to_sales_code(None, "active") == CODE_NEW


def test_lead_stage_default_new():
    assert lead_stage_to_sales_code(None, None) == CODE_NEW
    assert lead_stage_to_sales_code("странный_код", None) == CODE_NEW


def test_lead_stage_sales_code_passthrough():
    """Legacy-лиды, жившие в sales-воронке, после ремапа 0075 имеют sales-code
    на своём этапе ('qualify'/'lost'/'meeting'/...) — должен быть passthrough,
    а не fallback на статус. Регрессия из DB-прогона 0076."""
    assert lead_stage_to_sales_code("qualify") == CODE_QUALIFY
    assert lead_stage_to_sales_code("lost") == CODE_LOST
    assert lead_stage_to_sales_code(CODE_MEETING) == CODE_MEETING
    # passthrough приоритетнее статуса
    assert lead_stage_to_sales_code("qualify", "active") == CODE_QUALIFY


# ============ Lead → Deal/Company поля ============

def test_lead_to_deal_fields_basic():
    f = lead_to_deal_fields({
        "name": "ООО Ромашка", "owner_id": 7, "department_id": 3,
        "stage_code": "qualified", "status": "active",
    })
    assert f["title"] == "ООО Ромашка"
    assert f["owner_user_id"] == 7
    assert f["department_id"] == 3
    assert f["sales_stage_code"] == CODE_QUALIFY


def test_lead_to_deal_fields_empty_name_fallback():
    f = lead_to_deal_fields({"name": "  ", "owner_id": None})
    assert f["title"] == "Лид без названия"
    assert f["owner_user_id"] is None
    assert f["sales_stage_code"] == CODE_NEW


def test_lead_to_company_fields_basic():
    c = lead_to_company_fields({
        "name": "ООО Ромашка", "contact_email": "a@b.c",
        "contact_phone": "+7700", "source": "form", "tags": ["vip"],
    })
    assert c["name"] == "ООО Ромашка"
    assert c["legal_name"] == "ООО Ромашка"
    assert c["email"] == "a@b.c"
    assert c["phone"] == "+7700"
    assert c["source"] == "form"
    assert c["tags"] == ["vip"]


def test_lead_to_company_fields_source_default():
    c = lead_to_company_fields({"name": "X", "source": None})
    assert c["source"] == "lead"
    assert c["tags"] == []


# ============ Реестр причин отказа ============

def test_default_lost_reasons():
    names = [n for n, _ in DEFAULT_LOST_REASONS]
    assert names == [
        "Дорого", "Используют другую систему", "Закрываются",
        "Не вышли на ЛПР", "Нет бюджета",
    ]


def test_lost_reasons_sort_orders_unique():
    orders = [o for _, o in DEFAULT_LOST_REASONS]
    assert len(orders) == len(set(orders))


# ============ Миграции 0074-0076 ============

_VERSIONS = Path(__file__).parent.parent / "alembic" / "versions"


def test_migration_revisions_fit_varchar_limit():
    """Revision id миграций DEALS 2.0 ≤32 chars."""
    for rev in ("0074_deals2_schema", "0075_deals2_pipeline", "0076_deals2_leads"):
        assert len(rev) <= 32, f"{rev} = {len(rev)} chars > 32"


def test_migration_0074_uses_advisory_lock():
    src = (_VERSIONS / "0074_deals2_schema.py").read_text(encoding="utf-8")
    assert "pg_advisory_xact_lock" in src
    assert "hidden_by_default" in src
    assert "parent_stage_id" in src
    assert "won_gate" in src
    assert "lost_reasons" in src
    assert "pipeline_transitions" in src
    assert "meeting_report_questions" in src
    assert "meeting_report_json" in src


def test_migration_0075_uses_advisory_lock_and_remap():
    src = (_VERSIONS / "0075_deals2_pipeline.py").read_text(encoding="utf-8")
    assert "pg_advisory_xact_lock" in src
    assert "remap_old_stage_name" in src
    # Ремап (UPDATE deals SET stage_id) должен идти — инвариант миграции.
    assert "UPDATE deals SET stage_id" in src


def test_migration_0076_uses_advisory_lock_and_idempotent():
    src = (_VERSIONS / "0076_deals2_leads.py").read_text(encoding="utf-8")
    assert "pg_advisory_xact_lock" in src
    # Идемпотентность: пропуск лидов с уже созданным Deal.
    assert "converted_deal_id IS NULL" in src
    assert "company_to_counterparty_fields" in src


def test_migration_down_revisions_chain():
    s74 = (_VERSIONS / "0074_deals2_schema.py").read_text(encoding="utf-8")
    s75 = (_VERSIONS / "0075_deals2_pipeline.py").read_text(encoding="utf-8")
    s76 = (_VERSIONS / "0076_deals2_leads.py").read_text(encoding="utf-8")
    assert 'down_revision: Union[str, None] = "0073_company_cp_mirror"' in s74
    assert 'down_revision: Union[str, None] = "0074_deals2_schema"' in s75
    assert 'down_revision: Union[str, None] = "0075_deals2_pipeline"' in s76
