"""P1 concurrency follow-up (audit S6 B1): move_deal — единый commit под row-lock.

Раньше move_deal коммитил основной переход СРАЗУ (отпуская row-lock), затем блок
substage делал второй read-modify-write уже без блокировки — два одинаковых
won+substage запроса могли оба до него дойти и продублировать DealStageHistory.

Фикс: весь move (основной этап + подстатус + audit) идёт под одним row-lock'ом и
ОДНИМ commit'ом в конце. row-lock держится до финального commit.

Тесты — source-inspection (pure, без DB), по паттерну test_race_fixes.py:
проверяем что в теле move_deal остался единственный `session.commit()` и что
substage-блок предшествует этому commit'у.
"""
from __future__ import annotations

import inspect

from app.routers import deals as deals_router


def _move_deal_source() -> str:
    return inspect.getsource(deals_router.move_deal)


def test_move_deal_has_single_commit_in_body():
    """В теле move_deal ровно один await session.commit() — основной переход и
    подстатус коммитятся вместе, а не двумя отдельными транзакциями."""
    src = _move_deal_source()
    assert src.count("await session.commit()") == 1, (
        "move_deal должен иметь ровно один commit (основной этап + substage под "
        "одним row-lock'ом). Промежуточный commit отпустил бы блокировку до "
        "substage-перехода → дубль DealStageHistory при гонке."
    )


def test_move_deal_locks_row_for_update():
    """P0/P1: исходная сделка читается с row-lock (for_update=True), иначе две
    реплики могли бы двигать одну сделку одновременно."""
    src = _move_deal_source()
    assert "for_update=True" in src, (
        "move_deal обязан брать row-lock на сделку (_deal_or_403(..., "
        "for_update=True))"
    )


def test_substage_block_before_final_commit():
    """substage read-modify-write выполняется ДО единственного commit'а — значит
    под тем же row-lock'ом, что и основной переход."""
    src = _move_deal_source()
    substage_idx = src.find("data.substage_id and target.is_won")
    commit_idx = src.find("await session.commit()")
    assert substage_idx != -1, "substage-блок должен присутствовать в move_deal"
    assert commit_idx != -1, "финальный commit должен присутствовать"
    assert substage_idx < commit_idx, (
        "substage-переход должен идти ДО commit'а — иначе он бы выполнялся "
        "без row-lock'а (отпущенного предыдущим commit'ом)"
    )


def test_idempotency_no_op_preserved():
    """P1: idempotent no-op (is_redundant_stage_move) сохранён — повторный move в
    тот же этап без substage не пишет дубль и не перефайривает автоматизации."""
    src = _move_deal_source()
    assert "is_redundant_stage_move(" in src, (
        "idempotency-гейт is_redundant_stage_move обязан оставаться в move_deal"
    )


def test_automations_dispatch_after_commit_once():
    """Эпик 4/11.2: автоматизации и outbound-вебхуки диспатчатся ПОСЛЕ commit'а и
    только при реальной смене этапа (from_id != target.id) — ровно один раз за
    move (target мог быть переназначен на substage)."""
    src = _move_deal_source()
    commit_idx = src.find("await session.commit()")
    auto_idx = src.find("_fire_automations_on_enter_stage")
    dispatch_idx = src.find("_dispatch_deal_stage_events")
    assert commit_idx != -1
    assert auto_idx != -1 and dispatch_idx != -1
    # Диспатч идёт после единственного commit'а.
    assert commit_idx < auto_idx
    assert commit_idx < dispatch_idx
    # Гейт по реальной смене этапа присутствует ровно для блока диспатча.
    assert "if from_id != target.id:" in src


def test_bulk_deals_uses_ordered_lock_ids():
    """bulk_deals лочит сделки в детерминированном порядке (ordered_lock_ids) —
    защита от deadlock при пересекающихся id в обратном порядке."""
    src = inspect.getsource(deals_router.bulk_deals)
    assert "ordered_lock_ids(data.ids)" in src, (
        "bulk_deals обязан итерироваться по ordered_lock_ids(data.ids), а не по "
        "сырому data.ids — иначе lock-ordering deadlock"
    )
    assert "except DBAPIError" in src, (
        "bulk_deals должен ловить DBAPIError (deadlock) per-row и деградировать "
        "в errors, а не валить весь batch 500"
    )
