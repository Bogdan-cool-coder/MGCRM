"""Sequence executor (Эпик 4.1): многошаговые «cadences» по таймеру.

Architecture:
- `start_sequence_run(sequence_id, target_type, target_id)` — создаёт SequenceRun
  со status='pending', current_step_index=0, next_step_at=now (готов к
  немедленному первому тику). Вызывается из action_kind='start_sequence' и через
  /api/sequences/{id}/start.
- `scan_pending_sequence_runs(session)` — cron-функция (каждый час, см. jobs/
  automation_cron.py). Берёт SequenceRun со status IN ('pending', 'running') и
  next_step_at <= now(), исполняет один шаг, продвигает курсор, ставит
  next_step_at = now + delay_days текущего шага. Когда курсор вышел за пределы
  steps_json — status='completed'.

Шаги (steps_json):
- {"kind": "wait", "config": {}, "delay_days": N} — пауза N дней, шаг success.
- {"kind": "tg_notify", "config": {...}, "delay_days": N} — делегат в
  automation_executor._action_tg_notify (создаётся temp-объект PipelineAutomation).
- {"kind": "email", "config": {...}, "delay_days": N} — делегат в _action_email.
- {"kind": "create_task", "config": {...}, "delay_days": N} — делегат в
  _action_create_task.

Защита от падений:
- Один сломанный шаг → status='failed' для этого SequenceRun, error в result_json.
  Следующие SequenceRun продолжают выполняться (catch на каждый run в скане).
- Неизвестный kind в шаге → пишем error в result_json, помечаем step failed,
  переходим к следующему шагу (более устойчиво, чем валить всю последовательность).
"""
from __future__ import annotations

import logging
from datetime import UTC, datetime
from typing import Any

from sqlalchemy import or_
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.models import (
    ClientSubscription,
    Deal,
    Lead,
    PipelineAutomation,
    Sequence,
    SequenceRun,
    User,
)
from app.services.automation_executor import (
    _action_create_task,
    _action_email,
    _action_tg_notify,
    _fetch_target,
    _get_target_owner_user_id,
    compute_next_step_at,
)

logger = logging.getLogger(__name__)

# Whitelist шагов sequence. 'wait' — спецслучай: пауза без действия.
# Эпик 19: добавлен 'if_else' — branch-condition с вложенными true_steps/false_steps.
SEQUENCE_STEP_KINDS: tuple[str, ...] = (
    "wait",
    "tg_notify",
    "email",
    "create_task",
    "if_else",
)

# Эпик 19: операторы для условий в if_else step.
# Pure-функция `evaluate_condition` поддерживает все эти операторы.
BRANCH_OPERATORS: tuple[str, ...] = (
    "==",
    "!=",
    ">",
    ">=",
    "<",
    "<=",
    "in",
    "not_in",
    "is_null",
    "is_not_null",
)

SEQUENCE_RUN_STATUSES: tuple[str, ...] = (
    "pending",
    "running",
    "completed",
    "failed",
    "cancelled",
)


# ============ Pure helpers (тестируемы без БД) ============


def validate_steps(
    steps_json: list[dict[str, Any]],
    _depth: int = 0,
) -> tuple[bool, str | None]:
    """Pure-функция: валидация steps_json. Возвращает (ok, error_message).

    Каждый шаг должен иметь kind in SEQUENCE_STEP_KINDS, config dict (может быть пуст),
    delay_days int >= 0.

    Эпик 19: добавлена валидация шага 'if_else':
    - step.condition: dict {field, operator, value} (валидируется через
      validate_condition);
    - step.true_steps: list of nested steps (валидируются рекурсивно);
    - step.false_steps: list of nested steps (валидируются рекурсивно).
    Глубина рекурсии ограничена MAX_BRANCH_DEPTH (1 для MVP) — защита от
    бесконечной вложенности и от слишком сложных деревьев.
    """
    if not isinstance(steps_json, list):
        return False, "steps_json должен быть массивом"
    if len(steps_json) == 0 and _depth == 0:
        # Пустой массив на верхнем уровне — невалид; внутри true_steps/false_steps
        # пустой массив разрешён (ветка без действий = no-op).
        return False, "sequence должна содержать хотя бы один шаг"
    for i, step in enumerate(steps_json):
        if not isinstance(step, dict):
            return False, f"шаг {i}: должен быть dict"
        kind = step.get("kind")
        if kind not in SEQUENCE_STEP_KINDS:
            return (
                False,
                f"шаг {i}: неизвестный kind '{kind}', допустимы {list(SEQUENCE_STEP_KINDS)}",
            )
        # if_else имеет особую структуру — config может отсутствовать, зато нужны
        # condition + true_steps + false_steps.
        if kind == "if_else":
            ok, err = _validate_if_else_step(step, i, _depth)
            if not ok:
                return False, err
            continue
        cfg = step.get("config", {})
        if not isinstance(cfg, dict):
            return False, f"шаг {i}: config должен быть dict"
        delay = step.get("delay_days", 0)
        try:
            d = int(delay)
        except (ValueError, TypeError):
            return False, f"шаг {i}: delay_days должен быть int"
        if d < 0:
            return False, f"шаг {i}: delay_days не может быть отрицательным"
    return True, None


# Лимит глубины вложенности if_else. 1 для MVP (root → одна ветка с действиями,
# но без вложенных if_else внутри). Защищает от взрывного роста дерева и от
# stack overflow при рекурсивной валидации/исполнении.
MAX_BRANCH_DEPTH: int = 1


def _validate_if_else_step(
    step: dict[str, Any], index: int, depth: int,
) -> tuple[bool, str | None]:
    """Pure-функция: валидация шага if_else. Возвращает (ok, error_message).

    Проверяет:
    - condition — валидный dict через validate_condition;
    - true_steps / false_steps — списки (могут быть пустыми);
    - вложенные if_else запрещены (depth > MAX_BRANCH_DEPTH).
    """
    if depth >= MAX_BRANCH_DEPTH:
        return (
            False,
            f"шаг {index}: вложенность if_else превышает лимит "
            f"({MAX_BRANCH_DEPTH}) — for MVP",
        )
    cond = step.get("condition")
    if not isinstance(cond, dict):
        return False, f"шаг {index}: if_else требует поле condition (dict)"
    ok, err = validate_condition(cond)
    if not ok:
        return False, f"шаг {index}: condition невалидна — {err}"
    true_steps = step.get("true_steps", [])
    false_steps = step.get("false_steps", [])
    if not isinstance(true_steps, list):
        return False, f"шаг {index}: true_steps должен быть списком"
    if not isinstance(false_steps, list):
        return False, f"шаг {index}: false_steps должен быть списком"
    # Рекурсивно валидируем вложенные ветки. Глубина +1.
    for branch_name, branch in (("true_steps", true_steps), ("false_steps", false_steps)):
        ok, err = validate_steps(branch, _depth=depth + 1)
        if not ok and err and not err.startswith("sequence должна"):
            # допускаем пустые ветки (no-op), но не пропускаем структурные
            # ошибки внутри непустых
            return False, f"шаг {index}.{branch_name}: {err}"
    return True, None


def validate_condition(condition: dict[str, Any]) -> tuple[bool, str | None]:
    """Pure-функция: валидация структуры condition для if_else.

    Структура:
    {
        "field": "deal.amount",      # путь field path (entity.field)
        "operator": ">=" | "==" | "<" | "in" | ...,
        "value": ...                 # значение для сравнения; для is_null/is_not_null
                                     # игнорируется; для "in"/"not_in" — list
    }
    """
    field = condition.get("field")
    operator = condition.get("operator")
    if not field or not isinstance(field, str):
        return False, "condition.field обязателен (str)"
    if operator not in BRANCH_OPERATORS:
        return (
            False,
            f"condition.operator '{operator}' не поддерживается, допустимы {list(BRANCH_OPERATORS)}",
        )
    # in / not_in требуют list value
    if operator in ("in", "not_in"):
        value = condition.get("value")
        if not isinstance(value, list):
            return False, f"operator '{operator}' требует value=list"
    # is_null / is_not_null — value игнорируется (не валидируем)
    return True, None


def evaluate_condition(
    condition: dict[str, Any], entity_context: dict[str, Any]
) -> bool:
    """Pure-функция: вычислить True/False по condition над entity_context.

    entity_context — словарь со снимком сущности (e.g., {"deal": {"amount": 1000000,
    "title": "...", "status": "won"}}). Field path резолвится через точку:
    "deal.amount" → entity_context["deal"]["amount"]; отсутствующее поле → None.

    Операторы:
    - == / != — равенство (с приведением типов через простую попытку int/float, чтобы
      строка "100" сравнилась с числом 100, как в JSON-конфигах)
    - >, >=, <, <= — числовое сравнение; если значение не приводится к числу → False
    - in — value (list) содержит field_value
    - not_in — value (list) НЕ содержит field_value
    - is_null — field_value is None
    - is_not_null — field_value is not None

    Любая ошибка / отсутствующий field → False (fail-safe: не валим автоматизацию
    из-за криво заданного условия).
    """
    try:
        field = condition.get("field", "")
        operator = condition.get("operator")
        value = condition.get("value")
        field_value = _resolve_field_path(entity_context, field)

        if operator == "is_null":
            return field_value is None
        if operator == "is_not_null":
            return field_value is not None

        if operator == "in":
            if not isinstance(value, list):
                return False
            return field_value in value
        if operator == "not_in":
            if not isinstance(value, list):
                return False
            return field_value not in value

        if operator in ("==", "!="):
            # Попытка числового сравнения (для JSON-конфигов где "100" и 100 — одно)
            a, b = _try_numeric(field_value), _try_numeric(value)
            if operator == "==":
                return a == b
            return a != b

        if operator in (">", ">=", "<", "<="):
            a, b = _try_numeric(field_value), _try_numeric(value)
            # Если хоть один не число — False (нельзя сравнивать)
            if not (isinstance(a, (int, float)) and isinstance(b, (int, float))):
                return False
            if operator == ">":
                return a > b
            if operator == ">=":
                return a >= b
            if operator == "<":
                return a < b
            return a <= b
        return False
    except Exception:  # noqa: BLE001
        # Pure-функция, но защищаемся от любых неожиданных TypeError / KeyError
        return False


def _resolve_field_path(context: dict[str, Any], path: str) -> Any:
    """Pure-функция: разрешить точечный путь "a.b.c" в вложенном dict.

    Возвращает None если любой шаг пути отсутствует или не является dict.
    """
    if not path:
        return None
    parts = path.split(".")
    cur: Any = context
    for part in parts:
        if not isinstance(cur, dict):
            return None
        if part not in cur:
            return None
        cur = cur[part]
    return cur


def _try_numeric(v: Any) -> Any:
    """Попытка привести значение к числу. Если не получается — вернёт оригинал.

    Полезно для JSON-конфигов где value может быть "1000" (str) или 1000 (int).
    """
    if isinstance(v, bool):
        # bool — подкласс int в Python; сравнение True==1 нам не нужно для бизнес-полей
        return v
    if isinstance(v, (int, float)):
        return v
    if isinstance(v, str):
        try:
            if "." in v:
                return float(v)
            return int(v)
        except (ValueError, TypeError):
            return v
    return v


# ============ Start ============


async def start_sequence_run(
    session: AsyncSession,
    sequence_id: int,
    target_type: str,
    target_id: int,
) -> SequenceRun | None:
    """Создать SequenceRun. Возвращает None если Sequence не найдена/неактивна.

    next_step_at = now() (готов к первому тику cron). НЕ коммитит сессию.
    """
    seq = (
        await session.execute(select(Sequence).where(Sequence.id == sequence_id))
    ).scalar_one_or_none()
    if seq is None or not seq.is_active:
        return None

    if target_type not in ("deal", "lead", "subscription"):
        return None

    now = datetime.now(UTC)
    run = SequenceRun(
        sequence_id=seq.id,
        target_type=target_type,
        target_id=target_id,
        current_step_index=0,
        status="pending",
        next_step_at=now,
        started_at=now,
        result_json=[],
    )
    session.add(run)
    await session.flush()
    return run


# ============ Step execution ============


def _build_temp_automation(
    name: str, action_kind: str, action_config: dict[str, Any]
) -> PipelineAutomation:
    """Создаёт временный объект PipelineAutomation для передачи в _action_* хендлеры.

    Не присоединяется к сессии — handler читает только .name / .action_kind /
    .action_config / .created_by_user_id (для Activity.created_by_id).
    """
    a = PipelineAutomation()
    a.id = 0
    a.name = name
    a.action_kind = action_kind
    a.action_config = action_config
    a.created_by_user_id = None
    return a


_STEP_HANDLER_MAP = {
    "tg_notify": _action_tg_notify,
    "email": _action_email,
    "create_task": _action_create_task,
}


def build_entity_context(target_type: str, target: Any) -> dict[str, Any]:
    """Pure-функция: построить entity_context для evaluate_condition.

    Возвращает словарь {target_type: {field: value, ...}} со снимком
    публичных полей target'а. Используется в шаге if_else для разрешения
    field path вроде "deal.amount" → entity_context["deal"]["amount"].

    Подгружаемые поля per-target:
    - deal: id, title, amount, currency, status, stage_id, pipeline_id,
      owner_user_id, counterparty_id, contract_id, product_code, won, lost
    - lead: id, name, status, source, owner_id, pipeline_id, stage_id,
      counterparty_id, product_codes
    - subscription: id, fee_actual, fee_contract, currency, health_tier,
      manual_tier_override, lifecycle_stage_id, sup_pm_user_id, am_user_id,
      imp_pm_user_id, platform_code
    """
    fields_by_type = {
        "deal": (
            "id", "title", "amount", "currency", "status",
            "stage_id", "pipeline_id", "owner_user_id", "counterparty_id",
            "contract_id", "product_code", "won", "lost",
        ),
        "lead": (
            "id", "name", "status", "source", "owner_id",
            "pipeline_id", "stage_id", "counterparty_id", "product_codes",
        ),
        "subscription": (
            "id", "fee_actual", "fee_contract", "currency", "health_tier",
            "manual_tier_override", "lifecycle_stage_id",
            "sup_pm_user_id", "am_user_id", "imp_pm_user_id", "platform_code",
        ),
    }
    snapshot: dict[str, Any] = {}
    fields = fields_by_type.get(target_type, ())
    for f in fields:
        try:
            v = getattr(target, f, None)
            # Приводим Decimal к float для JSON-friendly сравнения
            from decimal import Decimal
            if isinstance(v, Decimal):
                v = float(v)
            snapshot[f] = v
        except Exception:  # noqa: BLE001
            snapshot[f] = None
    return {target_type: snapshot}


async def execute_step(
    session: AsyncSession,
    seq: Sequence,
    run: SequenceRun,
    step: dict[str, Any],
) -> dict[str, Any]:
    """Выполнить один шаг sequence. Возвращает result dict для записи в result_json.

    Шаг 'wait' — no-op, status success. Остальные — делегат в automation_executor.

    Эпик 19: шаг 'if_else' — branch-condition. Возвращает result с branch:
    "true" / "false" + список результатов выполненных вложенных шагов из
    выбранной ветки. Вложенные шаги исполняются ВНУТРИ той же транзакции
    последовательно (без задержек — delay_days вложенных шагов игнорируется,
    т.к. шаг if_else атомарен).
    """
    kind = step.get("kind")
    step_cfg = step.get("config", {}) or {}

    # 'wait' — пауза без действия
    if kind == "wait":
        return {
            "step_index": run.current_step_index,
            "kind": "wait",
            "status": "success",
        }

    # Эпик 19: 'if_else' — branch-condition.
    if kind == "if_else":
        return await _execute_if_else_step(session, seq, run, step)

    handler = _STEP_HANDLER_MAP.get(kind)
    if handler is None:
        return {
            "step_index": run.current_step_index,
            "kind": kind,
            "status": "failed",
            "error": f"неизвестный kind: {kind}",
        }

    # Подгружаем target + owner
    target = await _fetch_target(session, run.target_type, run.target_id)
    if target is None:
        return {
            "step_index": run.current_step_index,
            "kind": kind,
            "status": "skipped",
            "reason": f"target {run.target_type}#{run.target_id} не найден",
        }
    owner_id = _get_target_owner_user_id(target)
    owner: User | None = None
    if owner_id:
        owner = (
            await session.execute(select(User).where(User.id == owner_id))
        ).scalar_one_or_none()

    temp_a = _build_temp_automation(
        name=f"sequence#{seq.id} step {run.current_step_index}",
        action_kind=kind,
        action_config=step_cfg,
    )
    try:
        result = await handler(session, temp_a, target, owner)
        # Распознаём skipped из handler result (dict с ключом 'skipped')
        if isinstance(result, dict) and result.get("skipped"):
            return {
                "step_index": run.current_step_index,
                "kind": kind,
                "status": "skipped",
                "reason": result.get("reason"),
            }
        return {
            "step_index": run.current_step_index,
            "kind": kind,
            "status": "success",
            "result": result,
        }
    except Exception as e:  # noqa: BLE001
        import sentry_sdk
        sentry_sdk.capture_exception(e)
        logger.warning(
            "sequence_run %s step %d (%s) failed: %s",
            run.id, run.current_step_index, kind, e,
        )
        return {
            "step_index": run.current_step_index,
            "kind": kind,
            "status": "failed",
            "error": str(e)[:500],
        }


def derive_if_else_status(sub_results: list[dict[str, Any]]) -> str:
    """Pure-функция (C3-фикс): статус шага if_else по результатам вложенных шагов.

    - хотя бы один failed sub_step → 'failed' (run станет failed в сканере,
      шаг виден как сбойный и доступен для retry);
    - иначе (только success/skipped, либо пустая ветка) → 'success'
      (skipped — это не ошибка: ветка отработала, просто без side-effect'а).
    """
    statuses = [
        sr.get("status") for sr in sub_results if isinstance(sr, dict)
    ]
    return "failed" if "failed" in statuses else "success"


async def _execute_if_else_step(
    session: AsyncSession,
    seq: Sequence,
    run: SequenceRun,
    step: dict[str, Any],
) -> dict[str, Any]:
    """Эпик 19: исполнить шаг if_else.

    Алгоритм:
    1. Подгружаем target → строим entity_context;
    2. evaluate_condition(step.condition, context) → bool;
    3. Выбираем true_steps или false_steps;
    4. Исполняем вложенные шаги (вторично через execute_step, но БЕЗ задержек —
       внутри if_else все шаги атомарны и идут синхронно);
    5. Возвращаем result с branch и списком sub_results.

    Защита: если target не найден / condition невалидна / валидация шага упала
    — возвращаем status='failed' с error.
    """
    target = await _fetch_target(session, run.target_type, run.target_id)
    if target is None:
        return {
            "step_index": run.current_step_index,
            "kind": "if_else",
            "status": "skipped",
            "reason": f"target {run.target_type}#{run.target_id} не найден",
        }
    condition = step.get("condition")
    if not isinstance(condition, dict):
        return {
            "step_index": run.current_step_index,
            "kind": "if_else",
            "status": "failed",
            "error": "condition не задан или не dict",
        }
    ok, err = validate_condition(condition)
    if not ok:
        return {
            "step_index": run.current_step_index,
            "kind": "if_else",
            "status": "failed",
            "error": f"condition невалидна: {err}",
        }

    context = build_entity_context(run.target_type, target)
    branch_result = evaluate_condition(condition, context)
    branch_key = "true_steps" if branch_result else "false_steps"
    branch_steps = step.get(branch_key, [])
    if not isinstance(branch_steps, list):
        branch_steps = []

    # Исполняем вложенные шаги. Создаём отдельный sub_results список —
    # они входят в общий result_json как nested массив.
    sub_results: list[dict[str, Any]] = []
    # Временный "виртуальный курсор" для шагов внутри ветки — пишем индексы
    # как "{parent}.{i}" для аудита (но в БД не сохраняем — это derived).
    for i, sub_step in enumerate(branch_steps):
        # Защита: вложенные if_else запрещены по MAX_BRANCH_DEPTH; если кто-то
        # прокрался мимо валидации — пометим failed и не уйдём в рекурсию.
        if sub_step.get("kind") == "if_else":
            sub_results.append({
                "sub_index": i,
                "kind": "if_else",
                "status": "failed",
                "error": "nested if_else запрещён в MVP",
            })
            continue
        # Создаём «псевдо-run» для execute_step — реальный run.current_step_index
        # не меняем, чтобы он указывал на родительский if_else.
        # Передаём sub_step; execute_step резолвит target внутри сам.
        sub_result = await execute_step(session, seq, run, sub_step)
        # Помечаем sub_index в результате, чтобы было видно порядок в логе.
        sub_result["sub_index"] = i
        sub_results.append(sub_result)

    # C3-фикс: статус шага if_else выводим из вложенных результатов
    # (derive_if_else_status). Раньше тут всегда стоял "success" — упавшая ветка
    # (sub_step failed) пряталась внутри sub_results, run помечался
    # running/completed, и сбой был невидим/неретраибл.
    return {
        "step_index": run.current_step_index,
        "kind": "if_else",
        "status": derive_if_else_status(sub_results),
        "branch": "true" if branch_result else "false",
        "condition": condition,
        "sub_results": sub_results,
    }


# ============ Scanner (cron) ============


# Лимит на размер пачки в одном тике сканера. Бережём от монополизации воркера
# одним крупным «всплеском» runs (например, после длинного простоя cron'а).
# При scale=2 — каждый воркер возьмёт до 50 разных runs (см. SKIP LOCKED ниже).
SEQUENCE_SCAN_BATCH_LIMIT: int = 50


async def scan_pending_sequence_runs(session: AsyncSession) -> int:
    """Найти SequenceRun со status pending/running и next_step_at <= now,
    выполнить ОДИН шаг для каждого, продвинуть курсор. Возвращает кол-во
    обработанных runs (для логирования).

    Cron-цикл: один проход = один шаг каждого активного run. Это даёт нам
    мягкое распределение нагрузки (не пытаемся выполнить все шаги run'а в одном
    тике), что важно для долгих cadences.

    Race condition при scale=2 (несколько api-cron'ов одновременно):
    - SELECT ... FOR UPDATE SKIP LOCKED — каждый воркер берёт СВОЙ набор runs
      (Postgres пропускает row'ы, уже залоченные другим SELECT). Без этого два
      воркера видели бы одни и те же runs и дважды исполняли шаг.
    - LIMIT SEQUENCE_SCAN_BATCH_LIMIT — лимит на пачку, чтобы один воркер не
      монополизировал всю очередь.
    - Lock держится до конца транзакции (commit/rollback) — это нормально, т.к.
      execute_step выполняется в той же транзакции и затем session.flush()
      апдейтит SequenceRun (status / current_step_index / next_step_at).

    Защита:
    - Catch на каждый run отдельно — один сбойный не блокирует остальные.
    - Если шаг падает на validate (нет steps в Sequence, current_step_index >=
      len(steps)) — помечаем completed.
    """
    now = datetime.now(UTC)
    stmt = (
        select(SequenceRun)
        .where(
            SequenceRun.status.in_(("pending", "running")),
            or_(
                SequenceRun.next_step_at.is_(None),
                SequenceRun.next_step_at <= now,
            ),
        )
        .order_by(SequenceRun.next_step_at.asc().nullsfirst())
        .with_for_update(skip_locked=True)
        .limit(SEQUENCE_SCAN_BATCH_LIMIT)
    )
    runs = (await session.execute(stmt)).scalars().all()
    processed = 0
    for run in runs:
        try:
            seq = (
                await session.execute(
                    select(Sequence).where(Sequence.id == run.sequence_id)
                )
            ).scalar_one_or_none()
            if seq is None or not seq.is_active:
                run.status = "cancelled"
                run.finished_at = datetime.now(UTC)
                await session.flush()
                continue

            steps = seq.steps_json or []
            # Прошли все шаги?
            if run.current_step_index >= len(steps):
                run.status = "completed"
                run.finished_at = datetime.now(UTC)
                await session.flush()
                continue

            step = steps[run.current_step_index]
            step_result = await execute_step(session, seq, run, step)

            # Прибавляем результат к result_json (списочный аккумулятор)
            log = list(run.result_json or [])
            log.append(step_result)
            run.result_json = log

            # C3-фикс: упавший шаг (любого kind, включая if_else с failed
            # sub-step'ами) обязан перевести run в status='failed' — раньше
            # сканер просто двигал курсор дальше, и сбой оставался невидим
            # (не показывался в UI как failed, нельзя было отретраить). Курсор
            # НЕ продвигаем: failed-run останавливается на сбойном шаге, его
            # можно перезапустить вручную с того же индекса.
            if isinstance(step_result, dict) and step_result.get("status") == "failed":
                run.status = "failed"
                run.finished_at = datetime.now(UTC)
                run.next_step_at = None
                await session.flush()
                processed += 1
                continue

            # Продвигаем курсор; status и next_step_at пересчитываем
            run.current_step_index += 1
            run.status = "running"

            if run.current_step_index >= len(steps):
                # Последний шаг выполнен → завершаем сразу
                run.status = "completed"
                run.finished_at = datetime.now(UTC)
                run.next_step_at = None
            else:
                # Следующий шаг — через delay_days ТЕКУЩЕГО (только что выполненного)
                # шага. То есть delay_days в шаге означает «после выполнения этого
                # шага, подождать N дней перед следующим». Согласовано с ТЗ.
                delay = int(step.get("delay_days", 0))
                run.next_step_at = compute_next_step_at(datetime.now(UTC), delay)

            await session.flush()
            processed += 1
        except Exception as e:  # noqa: BLE001
            logger.exception("sequence_run %s scan iter failed: %s", run.id, e)
            # помечаем как failed, чтобы не зацикливалось в скане
            try:
                run.status = "failed"
                run.finished_at = datetime.now(UTC)
                await session.flush()
            except Exception:  # noqa: BLE001
                pass
    return processed
