"""Cleanup phantom active-leads + drop empty test pipeline.

Зона: backend (общий cleanup прод-данных). НЕ затрагивает cs/deals/contracts/
inbox-логику — только удаляет «фантомные» сущности, которые миграция 0076
насоздавала из СЫРЫХ лидов (включая status='active'), раздув воронку/реестр.

ФОН. Миграция 0076 сконвертировала ВСЕ лиды (в т.ч. сырые, status='active') в
Company + Counterparty-зеркало + Deal на этапе «Новые лиды» (code='new').
Лиды, которые ни разу не работались, превратились в «фантомных» клиентов:
пустые карточки на доске продаж и в реестре, искажающие конверсии/счётчики.

ЦЕЛЬ. Удалить ТОЛЬКО гарантированно-пустые фантомы, сохранив ВСЁ с любой
реальной активностью. Критерии кандидата на удаление (ВСЕ обязаны выполняться):

  1. Deal создан ИЗ лида — на него ссылается какой-то lead.converted_deal_id
     (НЕ inbound-сделка, у которой converted_deal_id никто не ставит).
  2. Deal стоит на этапе sales-воронки с code='new' («Новые лиды») — никогда
     не двигался дальше первого этапа.
  3. У Deal НЕТ ни одной Activity (target_type='deal' AND target_id=deal.id).
  4. У Deal НЕТ ни одной DealStageHistory (не было переходов по этапам).
  5. У Deal НЕТ contract_id И нет Contract по его company/counterparty.
  6. У связанной Company НЕТ: других Deal (кроме этого), ClientSubscription,
     Contract, ContactCompanyLink (сотрудников), файлов/папок (crm_files/crm_folders).
  7. У Counterparty-зеркала Company НЕТ: ClientSubscription, Contract, ClientNote,
     ClientTask, LegacyContact (contacts).

ДЛЯ таких фантомов:
  - DELETE Deal, Company, Counterparty-зеркало.
  - У соответствующего Lead — сбросить converted_*-поля в NULL, status='active'
    (СОХРАНЯЕМ lead-запись: оригинальный контакт не теряем, лид не дропаем).

Защита от удаления реальных данных:
  - Семь независимых NOT EXISTS-условий; любая активность (звонок/заметка/
    переход/договор/подписка/сотрудник/файл) — кандидат отбрасывается.
  - Проверяем активность и со стороны Company (company_id), и со стороны
    Counterparty-зеркала (counterparty_id) — обе стороны зеркала покрыты.
  - «Другие Deal у Company» считаются и по company_id, и по counterparty_id.
  - Работаем по СНИМКУ id (CREATE TEMP TABLE) внутри одной транзакции под
    advisory-lock — параллельный старт scale=2 безопасен.

ПЛЮС: удалить пустую тест-воронку — Pipeline (kind='sales') с именем ILIKE
'%тест%' / '%test%', у которой 0 Deal на всех этапах. Удаляем её
PipelineStage + Pipeline. Если есть хоть одна сделка — пропускаем (RAISE NOTICE).
Лиды могут ссылаться на её этапы (lead.stage_id), но pipeline_stages.id у Lead —
ondelete не задан (RESTRICT по умолчанию через FK leads.stage_id), поэтому
тест-воронку удаляем ТОЛЬКО если на её этапы не ссылается ни Deal, ни Lead.

RAISE NOTICE логирует найденное/удалённое (видно в деплой-логах).

Идемпотентность: повторный прогон не находит фантомов (0076-следы уже убраны;
сброшенные лиды снова в status='active' без converted_deal_id, но Deal уже нет —
условие 1 не выполнится). DDL отсутствует. Advisory-lock seed-key 74_008.

downgrade — no-op: data-cleanup необратим безопасно (восстановить пустые
фантомы бессмысленно и небезопасно; реальные данные не трогались).

Revision ID: 0083_cleanup_phantoms  (20 chars ≤32 ✓)
Revises: 0082_inbox_dedup
Create Date: 2026-06-03
"""
from __future__ import annotations

from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op

revision: str = "0083_cleanup_phantoms"
down_revision: Union[str, None] = "0082_inbox_dedup"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None

_SEED_LOCK_CLEANUP_PHANTOMS = 74_008


def upgrade() -> None:
    conn = op.get_bind()
    conn.execute(
        sa.text("SELECT pg_advisory_xact_lock(:k)"),
        {"k": _SEED_LOCK_CLEANUP_PHANTOMS},
    )

    _cleanup_phantom_leads(conn)
    _drop_empty_test_pipeline(conn)


def _cleanup_phantom_leads(conn) -> None:
    """Найти и удалить фантомные Deal/Company/Counterparty, сбросить их лиды."""
    conn.execute(
        sa.text(
            r"""
DO $$
DECLARE
    v_found INT;
BEGIN
    -- Снимок фантомов: (deal_id, company_id, counterparty_id, lead_id).
    -- ВСЕ семь критериев — как AND-условия NOT EXISTS.
    CREATE TEMP TABLE _phantoms ON COMMIT DROP AS
    SELECT
        d.id              AS deal_id,
        d.company_id      AS company_id,
        c.counterparty_id AS counterparty_id,
        l.id              AS lead_id
    FROM deals d
    -- (1) Deal создан из лида.
    JOIN leads l ON l.converted_deal_id = d.id
    -- (2) этап sales-воронки с code='new'.
    JOIN pipeline_stages ps ON ps.id = d.stage_id
    JOIN pipelines pl ON pl.id = ps.pipeline_id
    JOIN crm_companies c ON c.id = d.company_id
    WHERE ps.code = 'new'
      AND pl.kind = 'sales'
      AND d.contract_id IS NULL
      -- (3) у Deal нет Activity.
      AND NOT EXISTS (
          SELECT 1 FROM activities a
          WHERE a.target_type = 'deal' AND a.target_id = d.id
      )
      -- (4) у Deal нет переходов по этапам.
      AND NOT EXISTS (
          SELECT 1 FROM deal_stage_history dsh WHERE dsh.deal_id = d.id
      )
      -- (5) нет Contract ни по company_id, ни по counterparty_id зеркала.
      AND NOT EXISTS (
          SELECT 1 FROM contracts ct
          WHERE ct.company_id = c.id
             OR (c.counterparty_id IS NOT NULL AND ct.counterparty_id = c.counterparty_id)
      )
      -- (6a) у Company нет ДРУГИХ Deal (по company_id или counterparty_id зеркала).
      AND NOT EXISTS (
          SELECT 1 FROM deals d2
          WHERE d2.id <> d.id
            AND (
                d2.company_id = c.id
                OR (c.counterparty_id IS NOT NULL AND d2.counterparty_id = c.counterparty_id)
            )
      )
      -- (6b) у Company нет ClientSubscription (по company_id или counterparty_id).
      AND NOT EXISTS (
          SELECT 1 FROM client_subscriptions cs
          WHERE cs.company_id = c.id
             OR (c.counterparty_id IS NOT NULL AND cs.counterparty_id = c.counterparty_id)
      )
      -- (6c) у Company нет сотрудников (ContactCompanyLink).
      AND NOT EXISTS (
          SELECT 1 FROM crm_contact_company_links ccl WHERE ccl.company_id = c.id
      )
      -- (6d) у Company нет файлов.
      AND NOT EXISTS (
          SELECT 1 FROM crm_files f
          WHERE f.owner_entity_type = 'company' AND f.owner_entity_id = c.id
      )
      -- (6e) у Company нет папок (в т.ч. лениво-созданных «Системная»/«Документы»).
      AND NOT EXISTS (
          SELECT 1 FROM crm_folders fo
          WHERE fo.owner_entity_type = 'company' AND fo.owner_entity_id = c.id
      )
      -- (7) у Counterparty-зеркала нет CS-/договорной активности.
      AND (
          c.counterparty_id IS NULL
          OR (
              NOT EXISTS (SELECT 1 FROM client_notes cn WHERE cn.counterparty_id = c.counterparty_id)
              AND NOT EXISTS (SELECT 1 FROM client_tasks cct WHERE cct.counterparty_id = c.counterparty_id)
              AND NOT EXISTS (SELECT 1 FROM contacts lc WHERE lc.counterparty_id = c.counterparty_id)
          )
      );

    SELECT COUNT(*) INTO v_found FROM _phantoms;
    RAISE NOTICE '[0083] phantom candidates found: %', v_found;

    IF v_found = 0 THEN
        RAISE NOTICE '[0083] nothing to clean (idempotent re-run or clean DB)';
        RETURN;
    END IF;

    -- Сброс лидов: сохраняем lead-запись, возвращаем в active без конверсии.
    UPDATE leads l
    SET converted_deal_id = NULL,
        converted_to_company_id = NULL,
        converted_to_counterparty_id = NULL,
        converted_at = NULL,
        status = 'active'
    FROM _phantoms p
    WHERE l.id = p.lead_id;
    RAISE NOTICE '[0083] leads reset to active: %', v_found;

    -- Удаление в порядке FK: Deal → Company → Counterparty.
    -- deal_stage_history(deal_id) ON DELETE CASCADE — но истории у фантомов нет.
    DELETE FROM deals d USING _phantoms p WHERE d.id = p.deal_id;
    RAISE NOTICE '[0083] phantom deals deleted: %', v_found;

    DELETE FROM crm_companies c USING _phantoms p
    WHERE c.id = p.company_id AND p.company_id IS NOT NULL;
    GET DIAGNOSTICS v_found = ROW_COUNT;
    RAISE NOTICE '[0083] phantom companies deleted: %', v_found;

    DELETE FROM counterparties cp USING _phantoms p
    WHERE cp.id = p.counterparty_id AND p.counterparty_id IS NOT NULL;
    GET DIAGNOSTICS v_found = ROW_COUNT;
    RAISE NOTICE '[0083] phantom counterparty mirrors deleted: %', v_found;
END $$;
"""
        )
    )


def _drop_empty_test_pipeline(conn) -> None:
    """Удалить пустую тест-воронку (sales, имя ~ 'тест'/'test', 0 Deal/Lead)."""
    conn.execute(
        sa.text(
            r"""
DO $$
DECLARE
    r RECORD;
    v_deals INT;
    v_leads INT;
BEGIN
    FOR r IN
        SELECT id, name FROM pipelines
        WHERE kind = 'sales'
          AND (name ILIKE '%тест%' OR name ILIKE '%test%')
    LOOP
        SELECT COUNT(*) INTO v_deals FROM deals WHERE pipeline_id = r.id;
        SELECT COUNT(*) INTO v_leads FROM leads WHERE pipeline_id = r.id;

        IF v_deals > 0 OR v_leads > 0 THEN
            RAISE NOTICE '[0083] test pipeline "%" (id=%) NOT empty (deals=%, leads=%) — skipped',
                r.name, r.id, v_deals, v_leads;
            CONTINUE;
        END IF;

        DELETE FROM pipeline_stages WHERE pipeline_id = r.id;
        DELETE FROM pipelines WHERE id = r.id;
        RAISE NOTICE '[0083] empty test pipeline "%" (id=%) deleted with its stages', r.name, r.id;
    END LOOP;
END $$;
"""
        )
    )


def downgrade() -> None:
    # Data-cleanup необратим безопасно: пустые фантомы восстанавливать
    # бессмысленно и небезопасно, реальные данные не трогались. No-op.
    pass
