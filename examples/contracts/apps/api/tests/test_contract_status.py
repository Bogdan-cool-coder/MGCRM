"""Wave 2a — чистые unit-тесты для производной группировки статусов договора.

Покрывают:
- primary_group(): каждый статус → правильная группа (code/label/order);
- substatus_label(): подстатусы и их отсутствие;
- exhaustiveness/disjointness: STATUS_GROUPS покрывают ВСЕ ContractStatus
  ровно по одному разу (total + disjoint);
- statuses_for_group(): разворот группы;
- регрессия: signed в группе «Согласован» (раньше отсутствовал в UI-фильтре).
"""
from __future__ import annotations

import pytest

from app.models import ContractStatus
from app.services.contract_status import (
    GROUP_APPROVED,
    GROUP_ARCHIVED,
    GROUP_DRAFT,
    GROUP_IN_REVIEW,
    STATUS_GROUPS,
    already_finalized_by_other,
    can_decide_from,
    can_submit_from,
    describe,
    group_codes_in_order,
    groups_payload,
    primary_group,
    rework_comment_valid,
    statuses_for_group,
    substatus_label,
)

# Ожидаемый эталонный маппинг status → (group_code, order, substatus|None).
EXPECTED: dict[ContractStatus, tuple[str, int, str | None]] = {
    ContractStatus.archived: (GROUP_ARCHIVED, 0, None),
    ContractStatus.draft: (GROUP_DRAFT, 1, None),
    ContractStatus.submitted: (GROUP_IN_REVIEW, 2, None),
    ContractStatus.in_review: (GROUP_IN_REVIEW, 2, None),
    ContractStatus.needs_rework: (GROUP_IN_REVIEW, 2, "На доработке"),
    ContractStatus.rejected: (GROUP_IN_REVIEW, 2, "Отклонён"),
    ContractStatus.approved: (GROUP_APPROVED, 3, None),
    ContractStatus.signed: (GROUP_APPROVED, 3, None),
    ContractStatus.uploaded: (GROUP_APPROVED, 3, "В Drive"),
}


@pytest.mark.parametrize("status", list(ContractStatus))
def test_primary_group_matches_expected(status: ContractStatus):
    code, order, _sub = EXPECTED[status]
    pg = primary_group(status)
    assert pg["code"] == code
    assert pg["order"] == order
    # label непустой и соответствует группе из реестра
    assert pg["label"] == STATUS_GROUPS[code]["label"]
    assert pg["label"]


@pytest.mark.parametrize("status", list(ContractStatus))
def test_substatus_label_matches_expected(status: ContractStatus):
    _code, _order, sub = EXPECTED[status]
    assert substatus_label(status) == sub


def test_primary_group_accepts_raw_value_string():
    # Принимаем и enum, и raw .value-строку (на случай сериализованного входа).
    assert primary_group("needs_rework")["code"] == GROUP_IN_REVIEW
    assert substatus_label("uploaded") == "В Drive"


def test_unknown_status_raises():
    with pytest.raises(ValueError):
        primary_group("nonexistent_status")


# ============ Wave 2b: переходы «вернуть на доработку» / resubmit ============

@pytest.mark.parametrize("status", list(ContractStatus))
def test_can_submit_from_only_draft_rejected_needs_rework(status: ContractStatus):
    expected = status in (
        ContractStatus.draft,
        ContractStatus.rejected,
        ContractStatus.needs_rework,
    )
    assert can_submit_from(status) is expected
    # принимает и raw .value-строку
    assert can_submit_from(status.value) is expected


@pytest.mark.parametrize("status", list(ContractStatus))
def test_can_decide_from_only_in_review(status: ContractStatus):
    expected = status == ContractStatus.in_review
    assert can_decide_from(status) is expected


def test_needs_rework_is_resubmittable_but_not_decidable():
    # Ключевой инвариант фичи: вернули на доработку → автор может отправить
    # повторно, но согласователь решать по нему (decide) уже не может.
    assert can_submit_from(ContractStatus.needs_rework) is True
    assert can_decide_from(ContractStatus.needs_rework) is False


@pytest.mark.parametrize("comment,ok", [
    ("Поправьте сумму НДС", True),
    ("  есть текст  ", True),
    ("", False),
    ("   ", False),
    (None, False),
])
def test_rework_comment_valid(comment, ok):
    assert rework_comment_valid(comment) is ok


def test_groups_are_exhaustive_and_disjoint():
    """Объединение statuses всех групп = ВСЕ ContractStatus, без пересечений."""
    seen: list[ContractStatus] = []
    for g in STATUS_GROUPS.values():
        seen.extend(g["statuses"])
    # disjoint: ни один статус не попал в две группы
    assert len(seen) == len(set(seen)), "статус попал более чем в одну группу"
    # total: покрыты все значения enum
    assert set(seen) == set(ContractStatus)


def test_group_order_is_unique_and_sorted():
    orders = [g["order"] for g in STATUS_GROUPS.values()]
    assert sorted(orders) == [0, 1, 2, 3]
    assert group_codes_in_order() == [
        GROUP_ARCHIVED, GROUP_DRAFT, GROUP_IN_REVIEW, GROUP_APPROVED,
    ]


def test_statuses_for_group_in_review_expansion():
    expanded = set(statuses_for_group(GROUP_IN_REVIEW))
    assert expanded == {
        ContractStatus.submitted,
        ContractStatus.in_review,
        ContractStatus.needs_rework,
        ContractStatus.rejected,
    }


def test_statuses_for_group_unknown_raises():
    with pytest.raises(KeyError):
        statuses_for_group("no_such_group")


def test_signed_in_approved_group_regression():
    """Регрессия: signed обязан быть в группе «Согласован» (раньше выпадал из UI-фильтра)."""
    assert primary_group(ContractStatus.signed)["code"] == GROUP_APPROVED
    assert ContractStatus.signed in statuses_for_group(GROUP_APPROVED)


def test_groups_payload_shape():
    payload = groups_payload()
    assert [g["code"] for g in payload] == group_codes_in_order()
    in_review = next(g for g in payload if g["code"] == GROUP_IN_REVIEW)
    assert in_review["label"] == "На согласовании"
    assert set(in_review["statuses"]) == {
        "submitted", "in_review", "needs_rework", "rejected",
    }
    # подстатусы группы — только needs_rework и rejected
    sub_statuses = {s["status"] for s in in_review["substatuses"]}
    assert sub_statuses == {"needs_rework", "rejected"}
    sub_labels = {s["label"] for s in in_review["substatuses"]}
    assert sub_labels == {"На доработке", "Отклонён"}


def test_describe_shape():
    d = describe(ContractStatus.uploaded)
    assert d["status"] == "uploaded"
    assert d["primary_group"]["code"] == GROUP_APPROVED
    assert d["substatus_label"] == "В Drive"


# ============ P1 concurrency (audit S6 B3): finalize-race precondition ============


def test_already_finalized_by_other_true_for_decided_statuses():
    """Статусы, достижимые из in_review через decide/return_for_rework → 409-ветка.

    Если под row-lock'ом договор уже в одном из них, значит другой согласователь
    финализировал решение, пока мы ждали блокировку — это гонка, не draft.
    """
    for st in (
        ContractStatus.approved,
        ContractStatus.rejected,
        ContractStatus.needs_rework,
    ):
        assert already_finalized_by_other(st) is True


def test_already_finalized_by_other_false_for_in_review():
    # in_review — нормальный рабочий путь (не финализирован), decide продолжается.
    assert already_finalized_by_other(ContractStatus.in_review) is False


def test_already_finalized_by_other_false_for_non_review_statuses():
    # draft/submitted/signed/uploaded/archived — это «не на согласовании» (400),
    # а не «уже обработан другим согласователем» (409).
    for st in (
        ContractStatus.draft,
        ContractStatus.submitted,
        ContractStatus.signed,
        ContractStatus.uploaded,
        ContractStatus.archived,
    ):
        assert already_finalized_by_other(st) is False


def test_already_finalized_accepts_string():
    # _coerce принимает и строковый статус (на случай сырого значения из БД).
    assert already_finalized_by_other("approved") is True
    assert already_finalized_by_other("draft") is False


def test_finalized_disjoint_from_decidable():
    """Инвариант: «уже финализирован» и «можно решать» не пересекаются.

    Гарантирует, что 409-ветка и happy-path decide взаимоисключающи — иначе
    под lock'ом было бы двойное толкование статуса.
    """
    for st in ContractStatus:
        assert not (can_decide_from(st) and already_finalized_by_other(st))
