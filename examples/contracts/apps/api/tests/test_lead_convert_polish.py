"""Lead convert polish (Эпик 4.2) — pure-function проверки.

Покрывает:
1. `infer_country_from_phone` edge cases — Казахстан +7, Узбекистан +998,
   Германия +49, мусор, пустота, без префикса, формат 00 → +.
2. `_merge_lead_notes` — формат «Из лида #N (source=...)» + оригинальные notes
   через двойной перенос строки.
3. `_build_deal_extra_fields` — propagation lead_tags, lead_source, lead_id;
   сохранение существующих extra_fields лида.
4. Pydantic-схема `LeadConvert` — country_code uppercase, confirm_create_new default.
5. Score CHECK constraint в миграции 0027.
6. Default automations seed: структура корректна, advisory-lock key уникален.
7. Lead model: новые колонки score / converted_deal_id на месте.
8. Deal model: новые колонки lost_reason / expected_close_date на месте.
"""
from __future__ import annotations

from datetime import UTC, datetime
from pathlib import Path

import pytest
from pydantic import ValidationError

from app.models import Deal, Lead
from app.services.country_resolver import (
    PHONE_PREFIX_TO_COUNTRY,
    infer_country_from_phone,
)


# ============ country_resolver ============


def test_infer_country_kz_plus7():
    """+7 — Казахстан (в нашем сегменте, не Россия). См. модульный docstring resolver'а."""
    assert infer_country_from_phone("+7 (701) 234-56-78") == "KZ"
    assert infer_country_from_phone("+77012345678") == "KZ"


def test_infer_country_uz_plus998():
    """+998 — Узбекистан. Жадный матч: должен сматчить 998 раньше любого 9-префикса."""
    assert infer_country_from_phone("+998 90 123 45 67") == "UZ"
    assert infer_country_from_phone("+998901234567") == "UZ"


def test_infer_country_de_plus49():
    """+49 — Германия."""
    assert infer_country_from_phone("+49 30 1234567") == "DE"


def test_infer_country_ae_plus971():
    """+971 — ОАЭ. Граница: +97 не должен заматчиться (его нет в карте)."""
    assert infer_country_from_phone("+971 50 123 4567") == "AE"


def test_infer_country_us_plus1():
    """+1 — США (наш сегмент). Канада тоже на +1, но редко — отдельный override
    в карточке КА при необходимости."""
    assert infer_country_from_phone("+1 415 555 0100") == "US"


def test_infer_country_empty_input():
    """Пустой или None телефон → None."""
    assert infer_country_from_phone(None) is None
    assert infer_country_from_phone("") is None
    assert infer_country_from_phone("   ") is None


def test_infer_country_no_prefix():
    """Без ведущего + (например, «8(701)234-56-78») → None.
    Старый российский/казахстанский формат с 8 НЕ додумываем — менеджер дозаполнит."""
    assert infer_country_from_phone("8 (701) 234-56-78") is None
    assert infer_country_from_phone("7012345678") is None


def test_infer_country_garbage():
    """Мусор без цифр и + → None."""
    assert infer_country_from_phone("abc") is None
    assert infer_country_from_phone("---") is None


def test_infer_country_double_zero_prefix():
    """00 → + (международный формат записи). +44 = Великобритания."""
    assert infer_country_from_phone("004420 7946 0958") == "GB"


def test_infer_country_unknown_prefix():
    """Префикс не в карте → None (например, +999 Marshall Islands отсутствует)."""
    # Берём гарантированно отсутствующий префикс
    assert infer_country_from_phone("+999 12345") is None


def test_phone_prefix_map_iso_codes_uppercase():
    """Все значения карты — uppercase ISO-2 (2 буквы)."""
    for prefix, code in PHONE_PREFIX_TO_COUNTRY.items():
        assert prefix.startswith("+"), f"префикс {prefix} должен начинаться с +"
        assert len(code) == 2, f"ISO-2 код {code} для {prefix} должен быть 2 символа"
        assert code.isupper(), f"ISO-2 код {code} должен быть uppercase"


def test_phone_prefix_map_has_core_markets():
    """Минимум: основные рынки MACRO Global Technologies."""
    core = {"+7", "+998", "+971", "+966", "+90", "+49", "+1"}
    for p in core:
        assert p in PHONE_PREFIX_TO_COUNTRY, f"префикс {p} должен быть в карте"


# ============ leads.py helpers ============


def test_merge_lead_notes_with_notes():
    """notes Lead → '«Из лида #N (source=X)»\\n\\n<оригинальные notes>'."""
    from app.routers.leads import _merge_lead_notes
    lead = Lead(
        id=42,
        name="Test",
        contact_email="a@b.c",
        source="form",
        owner_id=None,
        pipeline_id=1,
        stage_id=1,
        status="active",
        tags=[],
        notes="Хочет купить 5 лицензий, бюджет ~$10k.",
        extra_fields={},
    )
    result = _merge_lead_notes(lead)
    assert result.startswith("Из лида #42 (source=form)")
    assert "Хочет купить 5 лицензий" in result
    assert "\n\n" in result  # разделитель между префиксом и notes


def test_merge_lead_notes_without_notes():
    """Если notes пусто — только префикс «Из лида #N (source=X)»."""
    from app.routers.leads import _merge_lead_notes
    lead = Lead(
        id=99,
        name="Test",
        source="manual",
        owner_id=None,
        pipeline_id=1,
        stage_id=1,
        status="active",
        tags=[],
        notes=None,
        extra_fields={},
    )
    result = _merge_lead_notes(lead)
    assert result == "Из лида #99 (source=manual)"


def test_build_deal_extra_fields_propagation():
    """Эпик 4.2: lead_tags / lead_source / lead_id попадают в Deal.extra_fields."""
    from app.routers.leads import _build_deal_extra_fields
    lead = Lead(
        id=15,
        name="ACME",
        source="tg",
        owner_id=None,
        pipeline_id=1,
        stage_id=1,
        status="active",
        tags=["hot", "enterprise"],
        notes=None,
        extra_fields={"custom_kw": "value1"},  # custom fields клонируются
    )
    extra = _build_deal_extra_fields(lead)
    # Сохранены custom fields из лида
    assert extra["custom_kw"] == "value1"
    # Новые поля из конверсии
    assert extra["lead_tags"] == ["hot", "enterprise"]
    assert extra["lead_source"] == "tg"
    assert extra["lead_id"] == 15


def test_build_deal_extra_fields_no_tags():
    """Если у лида нет тегов — поле lead_tags НЕ попадает в extra_fields
    (не засоряем пустыми списками)."""
    from app.routers.leads import _build_deal_extra_fields
    lead = Lead(
        id=20,
        name="X",
        source="api",
        owner_id=None,
        pipeline_id=1,
        stage_id=1,
        status="active",
        tags=[],
        notes=None,
        extra_fields={},
    )
    extra = _build_deal_extra_fields(lead)
    assert "lead_tags" not in extra
    assert extra["lead_source"] == "api"
    assert extra["lead_id"] == 20


def test_build_deal_extra_fields_no_existing_extra():
    """Если у лида extra_fields=None — не падаем, начинаем с пустого dict."""
    from app.routers.leads import _build_deal_extra_fields
    lead = Lead(
        id=21,
        name="Y",
        source="manual",
        owner_id=None,
        pipeline_id=1,
        stage_id=1,
        status="active",
        tags=[],
        notes=None,
        extra_fields=None,  # type: ignore[arg-type]  # симуляция legacy строки без extra
    )
    extra = _build_deal_extra_fields(lead)
    assert extra["lead_id"] == 21


# ============ Pydantic LeadConvert ============


def test_lead_convert_country_code_optional():
    """LeadConvert.country_code опционален — резолвер заполнит из телефона."""
    from app.routers.leads import LeadConvert
    c = LeadConvert()
    assert c.country_code is None
    assert c.confirm_create_new is False


def test_lead_convert_country_code_2_letters():
    """country_code — min 2 / max 2 (ISO-2 enforced на Pydantic-уровне)."""
    from app.routers.leads import LeadConvert
    # Валидные
    valid = LeadConvert(country_code="KZ")
    assert valid.country_code == "KZ"

    # Невалидные — 3 символа или 1
    with pytest.raises(ValidationError):
        LeadConvert(country_code="KAZ")
    with pytest.raises(ValidationError):
        LeadConvert(country_code="K")


def test_lead_convert_confirm_create_new_default():
    """confirm_create_new по умолчанию False — без явного флага мы возвращаем 409
    при найденном дубле КА."""
    from app.routers.leads import LeadConvert
    assert LeadConvert().confirm_create_new is False
    assert LeadConvert(confirm_create_new=True).confirm_create_new is True


def test_lead_convert_full_payload():
    """LeadConvert принимает все поля разом."""
    from app.routers.leads import LeadConvert
    c = LeadConvert(
        counterparty_name="ACME LLC",
        counterparty_id=None,
        sales_pipeline_id=1,
        sales_stage_id=5,
        country_code="UZ",
        confirm_create_new=True,
    )
    assert c.country_code == "UZ"
    assert c.confirm_create_new is True
    assert c.counterparty_name == "ACME LLC"


# ============ LeadCreate / LeadUpdate score ============


def test_lead_create_score_optional():
    """LeadCreate.score опционален и валидируется 0..100."""
    from app.routers.leads import LeadCreate
    # default None
    c = LeadCreate(name="X")
    assert c.score is None
    # 0..100 — валидно
    assert LeadCreate(name="X", score=0).score == 0
    assert LeadCreate(name="X", score=50).score == 50
    assert LeadCreate(name="X", score=100).score == 100
    # вне диапазона — ValidationError
    with pytest.raises(ValidationError):
        LeadCreate(name="X", score=-1)
    with pytest.raises(ValidationError):
        LeadCreate(name="X", score=101)


def test_lead_update_score_optional():
    """LeadUpdate.score — то же 0..100, опционально, partial-friendly."""
    from app.routers.leads import LeadUpdate
    u = LeadUpdate(score=75)
    patch = u.model_dump(exclude_unset=True)
    assert patch == {"score": 75}

    # Без score — partial update без него
    u2 = LeadUpdate(name="rename")
    patch2 = u2.model_dump(exclude_unset=True)
    assert "score" not in patch2


# ============ LeadOut new fields ============


def test_lead_out_has_new_fields():
    """LeadOut: добавлены score / converted_deal_id (Эпик 4.2)."""
    from app.routers.leads import LeadOut
    fields = LeadOut.model_fields
    assert "score" in fields
    assert "converted_deal_id" in fields


# ============ DealOut / DealPatch new fields ============


def test_deal_out_has_new_fields():
    """DealOut: добавлены lost_reason / expected_close_date (Эпик 4.2)."""
    from app.schemas import DealOut
    fields = DealOut.model_fields
    assert "lost_reason" in fields
    assert "expected_close_date" in fields


def test_deal_patch_has_new_fields():
    """DealPatch: добавлены lost_reason / expected_close_date (Эпик 4.2)."""
    from app.schemas import DealPatch
    fields = DealPatch.model_fields
    assert "lost_reason" in fields
    assert "expected_close_date" in fields


# ============ Models ============


def test_lead_model_has_new_columns():
    """Lead: новые колонки score / converted_deal_id (Эпик 4.2)."""
    cols = {c.name for c in Lead.__table__.columns}
    assert "score" in cols
    assert "converted_deal_id" in cols
    # Старые поля на месте
    assert "converted_to_counterparty_id" in cols
    assert "converted_at" in cols


def test_deal_model_has_new_columns():
    """Deal: новые колонки lost_reason / expected_close_date (Эпик 4.2)."""
    cols = {c.name for c in Deal.__table__.columns}
    assert "lost_reason" in cols
    assert "expected_close_date" in cols
    # Старые поля на месте
    assert "amount" in cols
    assert "currency" in cols


# ============ Migration 0027 ============


def test_migration_0027_structure():
    """Миграция 0027_epic_4_2_fields: правильная revision/down_revision, все 4 ADD COLUMN
    + соответствующие индексы + CHECK constraint + downgrade зеркальный."""
    migration_path = (
        Path(__file__).resolve().parents[1]
        / "alembic"
        / "versions"
        / "0027_epic_4_2_fields.py"
    )
    assert migration_path.exists(), "миграция 0027 должна существовать"
    src = migration_path.read_text(encoding="utf-8")

    # Revision metadata
    assert 'revision: str = "0027_epic_4_2_fields"' in src
    assert 'down_revision: Union[str, None] = "0026_card2_extensions"' in src

    # Upgrade: 4 add_column
    assert '"converted_deal_id"' in src
    assert '"score"' in src
    assert '"lost_reason"' in src
    assert '"expected_close_date"' in src

    # CHECK constraint для score
    assert "ck_leads_score_range" in src
    assert "score >= 0 AND score <= 100" in src

    # Индексы
    for ix in (
        "ix_leads_converted_deal_id",
        "ix_leads_score",
        "ix_deals_expected_close_date",
    ):
        assert ix in src, f"индекс {ix} должен быть в миграции 0027"

    # FK: converted_deal_id → deals(id) ON DELETE SET NULL
    assert 'ForeignKey("deals.id", ondelete="SET NULL")' in src

    # Downgrade
    assert "def downgrade()" in src
    assert 'drop_column("leads", "converted_deal_id")' in src
    assert 'drop_column("leads", "score")' in src
    assert 'drop_column("deals", "lost_reason")' in src
    assert 'drop_column("deals", "expected_close_date")' in src
    assert 'drop_constraint("ck_leads_score_range"' in src


def test_migration_0027_ddl_only_no_seed():
    """Миграция 0027 — DDL only, без seed-данных. Advisory-lock НЕ нужен
    (alembic_version блокирует параллельные старты сама)."""
    migration_path = (
        Path(__file__).resolve().parents[1]
        / "alembic"
        / "versions"
        / "0027_epic_4_2_fields.py"
    )
    src = migration_path.read_text(encoding="utf-8")
    # Не должно быть pg_advisory_xact_lock (DDL-only)
    assert "pg_advisory" not in src, (
        "DDL-only миграция не должна включать advisory-lock"
    )
    # Не должно быть INSERT (нет seed-данных)
    assert "INSERT" not in src.upper().replace("# INSERT", "")


# ============ automation_seed ============


def test_automation_seed_advisory_lock_unique():
    """advisory-lock key 728_274_010 не пересекается с уже занятыми."""
    from app.services.automation_seed import _SEED_LOCK_AUTOMATIONS
    from app.services.categories import _SEED_LOCK_KEY as CAT_KEY
    from app.services.customer_success import (
        _SEED_LOCK_CS_REF,
        _SEED_LOCK_LIFECYCLE,
    )
    from app.services.deals import _SEED_LOCK_KEY as DEALS_KEY
    from app.services.leads import _SEED_LOCK_LEAD
    from app.services.pricing import _SEED_LOCK_KEY as PRICING_KEY
    from app.services.renewal import _SEED_LOCK_RENEWAL

    assert _SEED_LOCK_AUTOMATIONS == 728_274_010
    occupied = {
        CAT_KEY,
        DEALS_KEY,
        PRICING_KEY,
        _SEED_LOCK_CS_REF,
        _SEED_LOCK_LIFECYCLE,
        _SEED_LOCK_LEAD,
        _SEED_LOCK_RENEWAL,
    }
    assert _SEED_LOCK_AUTOMATIONS not in occupied, (
        f"advisory-lock {_SEED_LOCK_AUTOMATIONS} пересекается с {occupied}"
    )


def test_automation_seed_default_count():
    """6 базовых автоматизаций по спеке Эпика 4.2."""
    from app.services.automation_seed import _DEFAULT_AUTOMATIONS
    assert len(_DEFAULT_AUTOMATIONS) == 6


def test_automation_seed_unique_keys():
    """Все 6 автоматизаций имеют уникальные unique-keys
    (pipeline_kind, stage_code, trigger_kind, action_kind, name) — иначе
    idempotency не сработает."""
    from app.services.automation_seed import (
        _DEFAULT_AUTOMATIONS,
        _automation_unique_key,
    )
    keys = [_automation_unique_key(spec) for spec in _DEFAULT_AUTOMATIONS]
    assert len(set(keys)) == len(keys), (
        f"unique-keys не уникальны: {[k for k in keys if keys.count(k) > 1]}"
    )


def test_automation_seed_covers_required_pipelines():
    """Сидер покрывает 3 воронки: lead, sales, renewal (по спеке Эпика 4.2)."""
    from app.services.automation_seed import _DEFAULT_AUTOMATIONS
    kinds = {spec["pipeline_kind"] for spec in _DEFAULT_AUTOMATIONS}
    assert kinds == {"lead", "sales", "renewal"}


def test_automation_seed_triggers_in_whitelist():
    """Все trigger_kind в сидере — в AUTOMATION_TRIGGERS whitelist'е executor'а."""
    from app.services.automation_executor import AUTOMATION_TRIGGERS
    from app.services.automation_seed import _DEFAULT_AUTOMATIONS
    for spec in _DEFAULT_AUTOMATIONS:
        assert spec["trigger_kind"] in AUTOMATION_TRIGGERS, (
            f"trigger {spec['trigger_kind']} не в whitelist"
        )


def test_automation_seed_actions_in_whitelist():
    """Все action_kind в сидере — в AUTOMATION_ACTIONS whitelist'е executor'а."""
    from app.services.automation_executor import AUTOMATION_ACTIONS
    from app.services.automation_seed import _DEFAULT_AUTOMATIONS
    for spec in _DEFAULT_AUTOMATIONS:
        assert spec["action_kind"] in AUTOMATION_ACTIONS, (
            f"action {spec['action_kind']} не в whitelist"
        )


def test_automation_seed_specs_well_formed():
    """Каждый spec имеет обязательные ключи: pipeline_kind, trigger_kind,
    action_kind, name, trigger_config, action_config."""
    from app.services.automation_seed import _DEFAULT_AUTOMATIONS
    required_keys = {
        "pipeline_kind",
        "trigger_kind",
        "action_kind",
        "name",
        "trigger_config",
        "action_config",
    }
    for spec in _DEFAULT_AUTOMATIONS:
        missing = required_keys - set(spec.keys())
        assert not missing, f"spec {spec.get('name')} без ключей: {missing}"


def test_automation_seed_idle_in_stage_has_days():
    """idle_in_stage_days trigger обязан иметь days в trigger_config (default 7)."""
    from app.services.automation_seed import _DEFAULT_AUTOMATIONS
    idle_specs = [
        s for s in _DEFAULT_AUTOMATIONS
        if s["trigger_kind"] == "idle_in_stage_days"
    ]
    assert len(idle_specs) >= 1, "должна быть хотя бы одна idle_in_stage_days"
    for spec in idle_specs:
        cfg = spec["trigger_config"]
        assert "days" in cfg, f"idle-spec {spec['name']} без days"
        assert cfg["days"] >= 1, f"days в {spec['name']} должно быть >=1"


def test_automation_seed_tg_notify_has_recipient_and_message():
    """tg_notify action обязан иметь recipient + message в action_config."""
    from app.services.automation_seed import _DEFAULT_AUTOMATIONS
    tg_specs = [
        s for s in _DEFAULT_AUTOMATIONS if s["action_kind"] == "tg_notify"
    ]
    assert len(tg_specs) >= 1
    for spec in tg_specs:
        cfg = spec["action_config"]
        assert cfg.get("recipient"), f"tg_notify spec {spec['name']} без recipient"
        assert cfg.get("message"), f"tg_notify spec {spec['name']} без message"


def test_automation_seed_create_task_has_title():
    """create_task action обязан иметь title в action_config."""
    from app.services.automation_seed import _DEFAULT_AUTOMATIONS
    task_specs = [
        s for s in _DEFAULT_AUTOMATIONS if s["action_kind"] == "create_task"
    ]
    for spec in task_specs:
        cfg = spec["action_config"]
        assert cfg.get("title"), f"create_task spec {spec['name']} без title"


# ============ CONTACTS 2.0 Ф4 — Company-based dedup + hot deal name ============


def test_find_counterparty_signature_returns_company():
    """CONTACTS 2.0 Ф4: _find_counterparty_by_contact аннотирована как Company | None."""
    import inspect
    from app.routers.leads import _find_counterparty_by_contact
    hints = _find_counterparty_by_contact.__annotations__
    # return annotation должен содержать 'Company' (строковая форма из __future__ annotations)
    ret = hints.get("return", "")
    assert "Company" in str(ret), (
        f"_find_counterparty_by_contact должна возвращать Company | None, got: {ret}"
    )


def test_hot_deal_out_has_company_id():
    """CONTACTS 2.0 Ф4: HotDealOut содержит company_id (nullable)."""
    from app.schemas import HotDealOut
    fields = HotDealOut.model_fields
    assert "company_id" in fields, "HotDealOut должен иметь поле company_id"
    # company_id опционален (None для сделок без Company)
    out = HotDealOut(
        id=1, title="T", amount=None, currency=None,
        stage_name="Trial", stage_color=None,
        idle_days=4, days_to_close=None, heat_reason="idle",
        counterparty_name="ACME",
    )
    assert out.company_id is None  # default None


def test_hot_deal_out_company_id_populated():
    """CONTACTS 2.0 Ф4: HotDealOut.company_id может быть заполнен."""
    from app.schemas import HotDealOut
    out = HotDealOut(
        id=5, title="Deal", amount=50000.0, currency="KZT",
        stage_name="HOT deals", stage_color="#FF4444",
        idle_days=2, days_to_close=5, heat_reason="deadline",
        counterparty_name="ТОО «Ромашка»",
        company_id=42,
    )
    assert out.company_id == 42
    assert out.counterparty_name == "ТОО «Ромашка»"


def test_lead_convert_409_shape_has_company_id():
    """CONTACTS 2.0 Ф4: 409 duplicate_found ответ содержит company_id (источник истины).

    Проверяем через inspect исходника (pure-function, без HTTP запроса).
    """
    import ast
    import inspect
    from app.routers import leads as leads_module
    src = inspect.getsource(leads_module.convert_lead)
    # После Ф4 в 409 response должен быть "company_id"
    assert '"company_id"' in src, (
        "409 response в convert_lead должен содержать 'company_id' для Ф4"
    )
    # backward-compat: counterparty_id зеркало тоже должно быть
    assert '"counterparty_id"' in src, (
        "409 response должен содержать 'counterparty_id' для backward-compat"
    )
