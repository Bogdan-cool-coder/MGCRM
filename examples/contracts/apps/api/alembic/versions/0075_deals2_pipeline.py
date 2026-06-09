"""DEALS 2.0 (Ф0) — перестройка sales-воронки «Продажи» + ремап сделок.

Идемпотентная миграция данных. Приводит существующую воронку «Продажи»
(kind='sales') к канонической структуре NEW_SALES_STAGES и БЕЗОПАСНО ремапит
все существующие Deal на новые этапы.

Алгоритм:
1. Резолв/создание воронки «Продажи» (kind='sales').
2. Upsert этапов по code:
   - если этап с таким code есть — обновляем name/sort/color/флаги/features;
   - иначе — пытаемся «усыновить» старый этап по совпадению имени (старые
     AMO-имена → новый code), проставляя ему code; если и такого нет — создаём.
3. РЕМАП сделок: каждую Deal этой воронки переводим на новый этап по маппингу
   old_stage_name → new_code (remap_old_stage_name). Сделки, чей текущий
   stage уже имеет валидный новый code — не трогаем.
4. parent_stage_id для подстатусов (await_payment/paid → won).
5. Чистка: старые этапы воронки, не вошедшие в NEW_SALES_STAGES и без сделок,
   деактивируются (is_active=false). НЕ удаляем физически (могут быть FK из
   deal_stage_history/automations) — деактивация безопаснее.

🔴 ИНВАРИАНТ: после миграции НИ ОДНА Deal воронки «Продажи» не ссылается на
этап, отсутствующий в NEW_SALES_STAGES. Шаг 3 (ремап) идёт ДО шага 5 (чистка).

Advisory-lock seed-key 74_002 (DEALS 2.0 pipeline rebuild).

Revision ID: 0075_deals2_pipeline  (20 chars ≤32 ✓)
Revises: 0074_deals2_schema
Create Date: 2026-06-02
"""
from __future__ import annotations

from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op

from app.services.deals_v2 import (
    NEW_SALES_STAGES,
    OLD_STAGE_NAME_TO_NEW_CODE,
    remap_old_stage_name,
)

revision: str = "0075_deals2_pipeline"
down_revision: Union[str, None] = "0074_deals2_schema"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None

_SEED_LOCK_DEALS2_PIPELINE = 74_002


def upgrade() -> None:
    conn = op.get_bind()
    conn.execute(
        sa.text("SELECT pg_advisory_xact_lock(:k)"),
        {"k": _SEED_LOCK_DEALS2_PIPELINE},
    )

    # ---- 1. Воронка «Продажи» ----
    pipe_id = conn.execute(
        sa.text(
            "SELECT id FROM pipelines "
            "WHERE name = 'Продажи' AND kind = 'sales' "
            "ORDER BY id LIMIT 1"
        )
    ).scalar()
    if pipe_id is None:
        # Запасной матч: первая sales-воронка (на случай иного имени).
        pipe_id = conn.execute(
            sa.text(
                "SELECT id FROM pipelines WHERE kind = 'sales' ORDER BY id LIMIT 1"
            )
        ).scalar()
    if pipe_id is None:
        pipe_id = conn.execute(
            sa.text(
                "INSERT INTO pipelines (name, kind, is_active, sort_order) "
                "VALUES ('Продажи', 'sales', true, 1) RETURNING id"
            )
        ).scalar()

    # Текущие этапы воронки.
    rows = conn.execute(
        sa.text(
            "SELECT id, name, code FROM pipeline_stages WHERE pipeline_id = :pid"
        ),
        {"pid": pipe_id},
    ).mappings().all()
    by_code: dict[str, int] = {r["code"]: r["id"] for r in rows if r["code"]}
    by_name: dict[str, int] = {r["name"]: r["id"] for r in rows}

    # ---- 2. Upsert этапов по code ----
    code_to_id: dict[str, int] = {}
    for spec in NEW_SALES_STAGES:
        code = spec["code"]
        features_json = sa.text("CAST(:f AS json)")
        params = {
            "name": spec["name"],
            "code": code,
            "sort_order": spec["sort_order"],
            "color": spec["color"],
            "is_won": spec["is_won"],
            "is_lost": spec["is_lost"],
            "hidden_by_default": spec["hidden_by_default"],
            "won_gate": spec["won_gate"],
            "f": _json_list(spec["stage_features"]),
        }

        stage_id = by_code.get(code)
        # Усыновление старого этапа по имени, если code ещё не присвоен.
        if stage_id is None:
            # Старые имена, которые маппятся на этот code и физически есть в воронке.
            adopt_id = None
            for old_name, mapped_code in OLD_STAGE_NAME_TO_NEW_CODE.items():
                if mapped_code != code:
                    continue
                cand = by_name.get(old_name)
                if cand is not None and cand not in code_to_id.values():
                    adopt_id = cand
                    break
            # Прямой матч по новому имени.
            if adopt_id is None:
                cand = by_name.get(spec["name"])
                if cand is not None and cand not in code_to_id.values():
                    adopt_id = cand
            stage_id = adopt_id

        if stage_id is not None:
            conn.execute(
                sa.text(
                    "UPDATE pipeline_stages SET "
                    "name = :name, code = :code, sort_order = :sort_order, "
                    "color = :color, is_won = :is_won, is_lost = :is_lost, "
                    "hidden_by_default = :hidden_by_default, won_gate = :won_gate, "
                    "stage_features = CAST(:f AS json), is_active = true "
                    "WHERE id = :id"
                ),
                {**params, "id": stage_id},
            )
        else:
            stage_id = conn.execute(
                sa.text(
                    "INSERT INTO pipeline_stages "
                    "(pipeline_id, name, code, sort_order, color, is_won, is_lost, "
                    " hidden_by_default, won_gate, stage_features, "
                    " responsible_user_ids, task_types, visible_department_ids, "
                    " visible_user_ids, allowed_task_category_ids, is_active) "
                    "VALUES "
                    "(:pid, :name, :code, :sort_order, :color, :is_won, :is_lost, "
                    " :hidden_by_default, :won_gate, CAST(:f AS json), "
                    " '[]'::json, '[]'::json, '[]'::json, '[]'::json, '[]'::json, true) "
                    "RETURNING id"
                ),
                {**params, "pid": pipe_id},
            ).scalar()
        code_to_id[code] = stage_id

    # ---- 3. РЕМАП сделок ----
    # Снимок сделок воронки с именем текущего этапа.
    deal_rows = conn.execute(
        sa.text(
            "SELECT d.id AS deal_id, s.name AS stage_name, s.code AS stage_code "
            "FROM deals d JOIN pipeline_stages s ON s.id = d.stage_id "
            "WHERE d.pipeline_id = :pid"
        ),
        {"pid": pipe_id},
    ).mappings().all()

    valid_codes = set(code_to_id.keys())
    for d in deal_rows:
        # Сделка уже на валидном новом этапе — не трогаем.
        if d["stage_code"] in valid_codes:
            continue
        new_code = remap_old_stage_name(d["stage_name"])
        target_stage_id = code_to_id[new_code]
        conn.execute(
            sa.text(
                "UPDATE deals SET stage_id = :sid, stage_changed_at = now() "
                "WHERE id = :did"
            ),
            {"sid": target_stage_id, "did": d["deal_id"]},
        )

    # ---- 4. parent_stage_id (подстатусы) ----
    for spec in NEW_SALES_STAGES:
        parent_code = spec.get("parent_code")
        if not parent_code:
            continue
        child_id = code_to_id.get(spec["code"])
        parent_id = code_to_id.get(parent_code)
        if child_id and parent_id:
            conn.execute(
                sa.text(
                    "UPDATE pipeline_stages SET parent_stage_id = :pid WHERE id = :cid"
                ),
                {"pid": parent_id, "cid": child_id},
            )

    # ---- 5. Чистка старых этапов без сделок ----
    # Этапы воронки, чей code НЕ в новой структуре. Деактивируем те, на которых
    # не осталось сделок (ремап уже отработал). Физически НЕ удаляем (FK-safety).
    keep_ids = set(code_to_id.values())
    leftover = conn.execute(
        sa.text(
            "SELECT id FROM pipeline_stages WHERE pipeline_id = :pid"
        ),
        {"pid": pipe_id},
    ).scalars().all()
    for sid in leftover:
        if sid in keep_ids:
            continue
        used = conn.execute(
            sa.text("SELECT count(*) FROM deals WHERE stage_id = :sid"),
            {"sid": sid},
        ).scalar()
        if used:
            # Подстраховка: если по какой-то причине остались сделки — ремапим в
            # «Новые лиды» (инвариант: нет битых stage_id).
            conn.execute(
                sa.text(
                    "UPDATE deals SET stage_id = :new_sid, stage_changed_at = now() "
                    "WHERE stage_id = :sid"
                ),
                {"new_sid": code_to_id["new"], "sid": sid},
            )
        conn.execute(
            sa.text(
                "UPDATE pipeline_stages SET is_active = false, hidden_by_default = true "
                "WHERE id = :sid"
            ),
            {"sid": sid},
        )


def downgrade() -> None:
    # Ремап данных необратим безопасно: мы не храним прежний stage_id каждой
    # сделки. downgrade — no-op (структура этапов остаётся валидной, схема не
    # ломается). Это согласуется с практикой data-миграций проекта (см. 0073).
    pass


def _json_list(values: list) -> str:
    """list[str] → JSON-строка для CAST(... AS json). Без внешних зависимостей."""
    import json
    return json.dumps(list(values), ensure_ascii=False)
