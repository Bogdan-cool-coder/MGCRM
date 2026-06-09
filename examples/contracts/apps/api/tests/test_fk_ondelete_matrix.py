"""P2 database integrity (audit A3) — FK ondelete матрица на уровне ORM-метаданных.

Чистый тест (без БД): инспектируем SQLAlchemy-метадату и проверяем, что каждая
FK из аудита получила ЯВНОЕ правило ON DELETE с правильной семантикой:

  - SET NULL  — необязательные user/audit ссылки (строка переживает удаление
                родителя; удаление User НЕ убивает его сделки/договоры).
  - RESTRICT  — справочники/структура (product/platform/pipeline/stage —
                удаление блокируется), а также NOT NULL user-FK (author/approver).

Синхронизировано с миграцией 0106. Если кто-то изменит ondelete в models.py
не синхронно с миграцией — тест падёт.
"""
from __future__ import annotations

from app.models import Base

# table.column -> ожидаемое ondelete-правило (uppercase как в SQLAlchemy).
_EXPECTED: dict[str, str] = {
    # --- SET NULL ---
    "audit_log.user_id": "SET NULL",
    "client_notes.author_user_id": "SET NULL",
    "client_tasks.assignee_user_id": "SET NULL",
    "client_tasks.created_by_user_id": "SET NULL",
    "contract_attachments.uploaded_by_user_id": "SET NULL",
    "contract_remarks.resolved_by_user_id": "SET NULL",
    "contract_revisions.created_by_user_id": "SET NULL",
    "deals.owner_user_id": "SET NULL",
    "deal_stage_history.user_id": "SET NULL",
    "client_subscriptions.imp_pm_user_id": "SET NULL",
    "client_subscriptions.sup_pm_user_id": "SET NULL",
    "client_subscriptions.am_user_id": "SET NULL",
    "contract_items.plan_id": "SET NULL",
    "deal_products.plan_id": "SET NULL",
    # --- RESTRICT ---
    "contracts.author_user_id": "RESTRICT",
    "contracts.counterparty_id": "RESTRICT",
    "approvals.user_id": "RESTRICT",
    "contract_remarks.author_user_id": "RESTRICT",
    "contract_items.product_id": "RESTRICT",
    "deal_products.product_id": "RESTRICT",
    "client_subscriptions.platform_id": "RESTRICT",
    "deals.pipeline_id": "RESTRICT",
    "deals.stage_id": "RESTRICT",
    "deal_stage_history.from_stage_id": "RESTRICT",
    "deal_stage_history.to_stage_id": "RESTRICT",
}


def _fk_ondelete_map() -> dict[str, str | None]:
    out: dict[str, str | None] = {}
    for table in Base.metadata.tables.values():
        for col in table.columns:
            for fk in col.foreign_keys:
                out[f"{table.name}.{col.name}"] = fk.ondelete
    return out


def test_audit_fks_have_explicit_ondelete():
    actual = _fk_ondelete_map()
    for key, expected in _EXPECTED.items():
        assert key in actual, f"FK {key} отсутствует в метадате"
        got = (actual[key] or "").upper()
        assert got == expected, f"{key}: ожидался ON DELETE {expected}, получено {got!r}"


def test_no_audit_fk_left_without_ondelete():
    """Ни одна FK из матрицы аудита не должна остаться с неявным ondelete (None)."""
    actual = _fk_ondelete_map()
    missing = [k for k in _EXPECTED if not actual.get(k)]
    assert not missing, f"FK без явного ondelete: {missing}"


def test_cascade_children_unchanged():
    """Дочерние строки (line items / approvals / remarks) остаются CASCADE по contract_id."""
    actual = _fk_ondelete_map()
    for key in (
        "contract_items.contract_id",
        "deal_products.deal_id",
        "approvals.contract_id",
        "contract_remarks.contract_id",
        "contract_revisions.contract_id",
        "deal_stage_history.deal_id",
    ):
        assert (actual.get(key) or "").upper() == "CASCADE", f"{key} должен быть CASCADE"
