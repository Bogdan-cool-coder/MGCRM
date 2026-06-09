"""Task 8 — pure-function тесты тренажёра звонков (без сети/БД)."""
from __future__ import annotations

from app.models import UserRole
from app.services.call_trainer import (
    CRITERIA_KEYS,
    SCENARIO_BRIEFS,
    build_client_system_prompt,
    build_evaluator_prompt,
    can_use_trainer,
    fallback_scorecard,
    parse_scorecard,
    resolve_scenario,
    scenario_brief,
    transcript_to_text,
)


# ============ Scenario lookup ============


def test_resolve_known_scenario():
    sc = resolve_scenario("objection_handling")
    assert sc["label"] == "Возражение по цене"


def test_resolve_unknown_scenario_falls_back_to_cold_call():
    assert resolve_scenario("nonsense") is SCENARIO_BRIEFS["cold_call"]
    assert resolve_scenario(None) is SCENARIO_BRIEFS["cold_call"]


def test_all_scenarios_have_required_keys():
    for sc in SCENARIO_BRIEFS.values():
        assert {"label", "client_role", "brief"} <= set(sc)


def test_scenario_brief_includes_company():
    brief = scenario_brief("cold_call", "IT", "ACME")
    assert "IT «ACME»" in brief
    brief_no_name = scenario_brief("cold_call", "Ритейл", None)
    assert "Ритейл" in brief_no_name
    assert "«" not in brief_no_name.split("Компания клиента:")[1]


# ============ Prompts ============


def test_client_prompt_stays_in_role():
    p = build_client_system_prompt("ceo_rejection", "Производство", None)
    assert "КЛИЕНТ" in p
    assert "Производство" in p
    # роль директора-ЛПР должна быть вшита
    assert "директор" in p.lower()


def test_evaluator_prompt_contains_history_and_criteria():
    transcript = [
        {"role": "assistant", "content": "Алло"},
        {"role": "user", "content": "Здравствуйте, это менеджер"},
    ]
    prompt = build_evaluator_prompt(transcript)
    assert "Менеджер: Здравствуйте, это менеджер" in prompt
    assert "Клиент: Алло" in prompt
    for key in CRITERIA_KEYS:
        assert key in prompt
    assert "good_decisions" in prompt
    assert "recommendations" in prompt


def test_transcript_to_text_roles():
    text = transcript_to_text(
        [
            {"role": "user", "content": "привет"},
            {"role": "assistant", "content": "слушаю"},
        ]
    )
    assert text == "Менеджер: привет\nКлиент: слушаю"


# ============ Scorecard parsing ============


def test_parse_scorecard_full():
    card = parse_scorecard(
        {
            "score": 7.5,
            "criteria_scores": {
                "speech_clarity": 8,
                "empathy": 6.5,
                "objection_handling": 7,
                "deal_closing": 5,
            },
            "recommendations": ["уточняй боль", "не дави"],
            "good_decisions": ["хорошо представился"],
            "feedback": "Неплохо",
        }
    )
    assert card.score == 7.5
    assert card.criteria_scores["speech_clarity"] == 8.0
    assert card.criteria_scores["empathy"] == 6.5
    assert card.recommendations == ["уточняй боль", "не дави"]
    assert card.good_decisions == ["хорошо представился"]
    assert card.feedback == "Неплохо"


def test_parse_scorecard_clamps_out_of_range():
    card = parse_scorecard(
        {"criteria_scores": {"speech_clarity": 99, "empathy": -5}}
    )
    assert card.criteria_scores["speech_clarity"] == 10.0
    assert card.criteria_scores["empathy"] == 0.0
    # отсутствующие критерии → fallback 5.0
    assert card.criteria_scores["deal_closing"] == 5.0


def test_parse_scorecard_score_defaults_to_mean():
    card = parse_scorecard(
        {
            "criteria_scores": {
                "speech_clarity": 4,
                "empathy": 6,
                "objection_handling": 4,
                "deal_closing": 6,
            }
        }
    )
    assert card.score == 5.0  # среднее


def test_parse_scorecard_total_alias():
    card = parse_scorecard({"total": 8.2, "criteria_scores": {}})
    assert card.score == 8.2


def test_parse_scorecard_handles_garbage():
    card = parse_scorecard(None)
    assert card.score == 5.0
    assert set(card.criteria_scores) == set(CRITERIA_KEYS)
    # строка в recommendations должна стать списком из одной строки
    card2 = parse_scorecard({"recommendations": "одна строка"})
    assert card2.recommendations == ["одна строка"]


def test_fallback_scorecard_shape():
    card = fallback_scorecard()
    assert card.score == 5.0
    assert all(v == 5.0 for v in card.criteria_scores.values())
    assert "недоступна" in card.feedback.lower()


# ============ Access predicate ============


def test_sales_roles_allowed():
    assert can_use_trainer(UserRole.manager)
    assert can_use_trainer(UserRole.director)
    assert can_use_trainer(UserRole.admin)


def test_non_sales_roles_denied_without_department():
    assert not can_use_trainer(UserRole.lawyer)
    assert not can_use_trainer(UserRole.accountant)
    assert not can_use_trainer(UserRole.cfo)


def test_non_sales_role_allowed_if_in_sales_department():
    # accountant приписан к sales-отделу id=3
    assert can_use_trainer(UserRole.accountant, department_id=3, sales_department_id=3)
    # но не к другому отделу
    assert not can_use_trainer(UserRole.accountant, department_id=4, sales_department_id=3)
    # sales_department_id не задан → только роль решает
    assert not can_use_trainer(UserRole.accountant, department_id=3, sales_department_id=None)
