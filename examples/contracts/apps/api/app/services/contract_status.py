"""Wave 2a — производная (derived) группировка статусов договора.

ДИЗАЙН (SAFE, без новой колонки, без ремапа существующих строк):
fine-grained `ContractStatus` остаётся источником истины в БД. Этот модуль —
чистые функции, которые ДЛЯ ЛЮБОГО статуса выводят:
  - первичную группу (primary group): код / RU-метка / порядок сортировки;
  - подстатус (substatus) — дополнительная RU-метка или None.

Владелец хочет видеть такой жизненный цикл (первичный статус + подстатусы как
доп. информация):
  0. Архив
  1. Черновик
  2. На согласовании
     2.1. На доработке  (подстатус)
     2.2. Отклонён       (подстатус)
  3. Согласован
     3.1. В Drive        (подстатус)

Маппинг status → (группа, подстатус):
  archived      → Архив(0)
  draft         → Черновик(1)
  submitted     → На согласовании(2)
  in_review     → На согласовании(2)
  needs_rework  → На согласовании(2) / «На доработке»
  rejected      → На согласовании(2) / «Отклонён»
  approved      → Согласован(3)
  signed        → Согласован(3)
  uploaded      → Согласован(3) / «В Drive»

Все функции — pure (вход → выход), без БД и без сети; покрыты unit-тестами
(tests/test_contract_status.py).
"""
from __future__ import annotations

from typing import TypedDict

from app.models import ContractStatus


class PrimaryGroup(TypedDict):
    code: str
    label: str
    order: int


# Коды первичных групп. Суффикс «_group» в коде in_review-группы — осознанный
# (фронт уже использует ?status_group=...); остальные коды по смыслу группы.
GROUP_ARCHIVED = "archived_group"
GROUP_DRAFT = "draft_group"
GROUP_IN_REVIEW = "in_review_group"
GROUP_APPROVED = "approved_group"


# Единый реестр первичных групп: код → (label, order, входящие статусы).
# Порядок ключей = порядок отображения (order). Это total-структура: объединение
# `statuses` всех групп ОБЯЗАНО покрывать все значения ContractStatus (проверяется
# тестом test_groups_are_exhaustive_and_disjoint).
STATUS_GROUPS: dict[str, dict] = {
    GROUP_ARCHIVED: {
        "label": "Архив",
        "order": 0,
        "statuses": (ContractStatus.archived,),
    },
    GROUP_DRAFT: {
        "label": "Черновик",
        "order": 1,
        "statuses": (ContractStatus.draft,),
    },
    GROUP_IN_REVIEW: {
        "label": "На согласовании",
        "order": 2,
        "statuses": (
            ContractStatus.submitted,
            ContractStatus.in_review,
            ContractStatus.needs_rework,
            ContractStatus.rejected,
        ),
    },
    GROUP_APPROVED: {
        "label": "Согласован",
        "order": 3,
        "statuses": (
            ContractStatus.approved,
            ContractStatus.signed,
            ContractStatus.uploaded,
        ),
    },
}


# Обратный индекс status → group-code (строится один раз на импорте модуля).
_STATUS_TO_GROUP: dict[ContractStatus, str] = {
    st: code
    for code, g in STATUS_GROUPS.items()
    for st in g["statuses"]
}


# Подстатусы: status → RU-метка. Статусы, которых здесь нет, подстатуса не имеют.
_SUBSTATUS_LABELS: dict[ContractStatus, str] = {
    ContractStatus.needs_rework: "На доработке",
    ContractStatus.rejected: "Отклонён",
    ContractStatus.uploaded: "В Drive",
}


def _coerce(status: ContractStatus | str) -> ContractStatus:
    """Привести вход к ContractStatus (принимаем и enum, и raw .value-строку)."""
    if isinstance(status, ContractStatus):
        return status
    return ContractStatus(status)


def primary_group(status: ContractStatus | str) -> PrimaryGroup:
    """Вернуть первичную группу статуса: {code, label, order}.

    Тотальна для всех значений ContractStatus. На неизвестную строку бросит
    ValueError (через ContractStatus(...)), что считаем багом вызывающего кода.
    """
    st = _coerce(status)
    code = _STATUS_TO_GROUP[st]
    g = STATUS_GROUPS[code]
    return PrimaryGroup(code=code, label=g["label"], order=g["order"])


def substatus_label(status: ContractStatus | str) -> str | None:
    """RU-метка подстатуса для статуса, либо None если подстатуса нет."""
    st = _coerce(status)
    return _SUBSTATUS_LABELS.get(st)


def statuses_for_group(group_code: str) -> tuple[ContractStatus, ...]:
    """Развернуть код первичной группы в кортеж входящих статусов.

    Используется фильтром /contracts (?status_group=...) для расширения группы
    в WHERE status IN (...). Неизвестный код → KeyError (баг вызывающего).
    """
    return tuple(STATUS_GROUPS[group_code]["statuses"])


def group_codes_in_order() -> list[str]:
    """Коды первичных групп, отсортированные по order (для UI-фильтра)."""
    return [
        code
        for code, _ in sorted(STATUS_GROUPS.items(), key=lambda kv: kv[1]["order"])
    ]


def groups_payload() -> list[dict]:
    """Сериализуемое описание групп для фронта (фильтр «все + 4 группы»).

    Каждый элемент: {code, label, order, statuses: [<value>, ...], substatuses:
    [{status, label}, ...]}. Отсортировано по order.
    """
    out: list[dict] = []
    for code in group_codes_in_order():
        g = STATUS_GROUPS[code]
        statuses = list(g["statuses"])
        out.append({
            "code": code,
            "label": g["label"],
            "order": g["order"],
            "statuses": [s.value for s in statuses],
            "substatuses": [
                {"status": s.value, "label": _SUBSTATUS_LABELS[s]}
                for s in statuses
                if s in _SUBSTATUS_LABELS
            ],
        })
    return out


def describe(status: ContractStatus | str) -> dict:
    """Полное производное описание статуса для одной строки/ответа API.

    {status, primary_group: {...}, substatus_label}. Удобно подмешивать в
    ответы, не дублируя логику маппинга на каждом эндпоинте.
    """
    st = _coerce(status)
    return {
        "status": st.value,
        "primary_group": primary_group(st),
        "substatus_label": substatus_label(st),
    }


# ============ Переходы статусов (pure-предикаты) ============
#
# Источник истины по статусу — БД-колонка Contract.status. Эти функции описывают,
# из каких статусов разрешён тот или иной переход. Используются роутером
# /contracts перед записью, покрыты unit-тестами (test_contract_status.py).

# Статусы, из которых автор может (пере)отправить договор на согласование
# (submit / resubmit). `needs_rework` сюда добавлен в Wave 2b: согласователь
# вернул на доработку → автор правит → снова отправляет. `rejected` остаётся
# (окончательное отклонение, но повторная отправка возможна — новый attempt).
SUBMITTABLE_FROM: tuple[ContractStatus, ...] = (
    ContractStatus.draft,
    ContractStatus.rejected,
    ContractStatus.needs_rework,
)

# Статус, в котором согласователь принимает решение (approve / reject /
# return_for_rework). Решение возможно только пока договор активно на review.
DECIDABLE_FROM: tuple[ContractStatus, ...] = (ContractStatus.in_review,)

# P1 concurrency (audit S6 B3): терминальные для текущего цикла согласования
# статусы, достижимые ИЗ in_review через decide/return_for_rework. Если под
# row-lock'ом мы видим договор уже в одном из них — значит другой согласователь
# (или вторая реплика api) финализировал решение, пока мы ждали блокировку.
# Это race, а не пользовательская ошибка → отвечаем 409 «уже обработан», а не 400.
FINALIZED_FROM_REVIEW: tuple[ContractStatus, ...] = (
    ContractStatus.approved,
    ContractStatus.rejected,
    ContractStatus.needs_rework,
)


def can_submit_from(status: ContractStatus | str) -> bool:
    """True, если из `status` можно (пере)отправить договор на согласование."""
    return _coerce(status) in SUBMITTABLE_FROM


def can_decide_from(status: ContractStatus | str) -> bool:
    """True, если из `status` согласователь может принять решение по договору."""
    return _coerce(status) in DECIDABLE_FROM


def already_finalized_by_other(status: ContractStatus | str) -> bool:
    """True, если решение по договору уже принято в текущем цикле согласования.

    P1 concurrency (audit S6 B3): под row-lock'ом в decide/return_for_rework
    отличаем «гонку двух согласователей» (статус уже approved/rejected/
    needs_rework → 409) от «договор вообще не на согласовании» (draft/signed/…
    → 400). Чистая функция — тестируется без БД.
    """
    return _coerce(status) in FINALIZED_FROM_REVIEW


def rework_comment_valid(comment: str | None) -> bool:
    """True, если комментарий «что поправить» непустой (после strip).

    «Вернуть на доработку» обязательно требует комментарий — иначе автор не
    поймёт, что исправлять. Зеркалит требование к причине при reject.
    """
    return bool(comment and comment.strip())
