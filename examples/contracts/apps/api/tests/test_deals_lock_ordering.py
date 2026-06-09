"""P1 concurrency follow-up (audit S6 B1): детерминированный lock-ordering.

bulk_deals лочит сделки по очереди в одной транзакции. Если два конкурентных
bulk-запроса передают пересекающиеся id в обратном порядке, Postgres ловит
deadlock (40P01). ordered_lock_ids сортирует id по возрастанию (+дедуп), чтобы
все запросы брали row-lock'и в одном порядке. Чистые unit-тесты ниже.
"""
from __future__ import annotations

from app.services.deals import ordered_lock_ids


def test_sorts_ascending():
    assert ordered_lock_ids([3, 1, 2]) == [1, 2, 3]


def test_reverse_order_inputs_yield_same_lock_order():
    # Ядро фикса: два запроса с обратным порядком id берут блокировки одинаково.
    a = ordered_lock_ids([1, 2, 3])
    b = ordered_lock_ids([3, 2, 1])
    assert a == b == [1, 2, 3]


def test_overlapping_sets_share_prefix_order():
    # Пересекающиеся (не идентичные) наборы лочат общие id в одном порядке,
    # значит deadlock по lock-ordering невозможен.
    req_a = ordered_lock_ids([5, 2, 8])      # → [2, 5, 8]
    req_b = ordered_lock_ids([8, 5, 1])      # → [1, 5, 8]
    common = sorted(set(req_a) & set(req_b))  # {5, 8}
    order_a = [i for i in req_a if i in common]
    order_b = [i for i in req_b if i in common]
    assert order_a == order_b == [5, 8]


def test_dedupes_duplicate_ids():
    # Дубль id в data.ids не должен обрабатываться (и лочиться) дважды.
    assert ordered_lock_ids([4, 4, 1, 1]) == [1, 4]


def test_empty_list():
    assert ordered_lock_ids([]) == []


def test_single_id():
    assert ordered_lock_ids([7]) == [7]


def test_already_sorted_unchanged():
    assert ordered_lock_ids([1, 2, 3, 4]) == [1, 2, 3, 4]


def test_returns_new_list_not_mutating_input():
    src = [3, 1, 2]
    out = ordered_lock_ids(src)
    assert src == [3, 1, 2]  # вход не мутирован
    assert out == [1, 2, 3]
