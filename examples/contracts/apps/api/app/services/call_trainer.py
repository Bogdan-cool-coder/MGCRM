"""Task 8 — Call Trainer («тренажёр звонков») доменная логика.

Pure-function helpers (без сети и БД), которые тестируются юнит-тестами:

- `scenario_brief(scenario_type, company_type, company_name)` — собирает текстовый
  бриф сценария, который уходит в system-prompt и показывается менеджеру.
- `build_client_system_prompt(...)` — system-prompt роли клиента (AI играет клиента).
- `build_evaluator_prompt(transcript)` — EVALUATOR-промпт для финальной оценки.
- `parse_scorecard(parsed_json)` — нормализует JSON от Claude в строгий
  ScorecardData (0-10, 4 критерия, рекомендации, удачные решения).
- `can_use_trainer(role, department_id, sales_department_id)` — access predicate
  (только отдел продаж: manager/director/admin или department == sales).

Сетевые вызовы Claude — в роутере (app/routers/me_training.py) через
`app/services/anthropic_client.py`. Здесь только чистая логика, чтобы тесты не
зависели от ANTHROPIC_API_KEY и Postgres.
"""
from __future__ import annotations

from dataclasses import dataclass, field
from typing import Any

from app.models import UserRole

# ============ Scenario library ============

# Ключ — scenario_type из фронта (ScenarioSetup.tsx). Каждый бриф описывает,
# КАК AI должен играть клиента: настроение, типичные возражения, условие «когда
# открыться». Это ядро ролевой игры — AI остаётся в образе проспекта.
SCENARIO_BRIEFS: dict[str, dict[str, str]] = {
    "cold_call": {
        "label": "Холодный звонок",
        "client_role": (
            "Ты — занятой руководитель, которому звонят без предупреждения. Ты "
            "не ждёшь этого звонка и поначалу настроен прохладно: «Кто это? "
            "Откуда у вас мой номер? У меня нет времени». Ты не грубишь, но "
            "торопишься. Если менеджер за 20-30 секунд внятно объяснит ценность "
            "и попросит мало времени — ты можешь дать ему шанс. Если он мямлит "
            "или сразу продаёт — ты вежливо сворачиваешь разговор."
        ),
        "brief": (
            "Холодный звонок. Клиент тебя не ждёт — нужно зацепить за 30 секунд "
            "и продать следующий шаг (встречу/демо), а не сам продукт."
        ),
    },
    "objection_handling": {
        "label": "Возражение по цене",
        "client_role": (
            "Ты — клиент, которому в целом интересен продукт, НО тебя смущает "
            "цена. Твоё ключевое возражение: «Это дорого», «У конкурентов "
            "дешевле», «Не вижу, за что столько платить». Ты давишь на скидку. "
            "Ты открываешься, только если менеджер обоснует ценность через "
            "выгоду/окупаемость, а не просто согласится снизить цену. Если он "
            "сразу даёт скидку без обоснования — ты теряешь доверие к продукту."
        ),
        "brief": (
            "Клиент говорит «дорого». Задача — выяснить причину возражения и "
            "обосновать ценность, а не сразу сбрасывать цену."
        ),
    },
    "ceo_rejection": {
        "label": "Отказ ЛПР",
        "client_role": (
            "Ты — директор (ЛПР), который уже принял решение отказать: «Нам это "
            "не нужно», «У нас уже всё есть», «Решение принято, спасибо». Ты "
            "холоден и хочешь быстро закончить. Ты НЕ меняешь решение от уговоров. "
            "Но если менеджер задаёт правильный вопрос («что должно измениться, "
            "чтобы вы пересмотрели?») или находит реальную боль — ты можешь "
            "приоткрыться и назвать условие пересмотра."
        ),
        "brief": (
            "Директор сказал «нет». Не спорь — выясни условие пересмотра решения "
            "или реальную боль, попробуй выйти на следующий шаг."
        ),
    },
    "follow_up": {
        "label": "Повторный звонок",
        "client_role": (
            "Ты — клиент, с которым уже был контакт ранее, но ты подзабыл детали "
            "и не горишь желанием продолжать: «А, это вы… напомните, о чём мы "
            "говорили?», «Я пока не решил». Ты тёплый, но пассивный. Ты "
            "вовлекаешься, если менеджер коротко напомнит контекст и принесёт "
            "новую ценность (кейс, новость, обновление) + предложит конкретный "
            "следующий шаг."
        ),
        "brief": (
            "Повторный звонок. Напомни контекст коротко, добавь новую ценность и "
            "назначь конкретное следующее действие."
        ),
    },
}

DEFAULT_SCENARIO = "cold_call"

# Человеко-читаемые RU-лейблы критериев (совпадают с фронтом TrainingScorecard).
CRITERIA_KEYS: tuple[str, ...] = (
    "speech_clarity",
    "empathy",
    "objection_handling",
    "deal_closing",
)
CRITERIA_LABELS: dict[str, str] = {
    "speech_clarity": "Грамотность речи / установление контакта",
    "empathy": "Эмпатия / выявление потребности",
    "objection_handling": "Работа с возражениями",
    "deal_closing": "Закрытие сделки / следующий шаг",
}


def resolve_scenario(scenario_type: str | None) -> dict[str, str]:
    """Возвращает бриф сценария по ключу. Неизвестный ключ → DEFAULT_SCENARIO."""
    if scenario_type and scenario_type in SCENARIO_BRIEFS:
        return SCENARIO_BRIEFS[scenario_type]
    return SCENARIO_BRIEFS[DEFAULT_SCENARIO]


def scenario_brief(
    scenario_type: str | None,
    company_type: str,
    company_name: str | None,
) -> str:
    """Короткий текстовый бриф, который показывается менеджеру в чате."""
    base = resolve_scenario(scenario_type)["brief"]
    company = company_type
    if company_name:
        company = f"{company_type} «{company_name}»"
    return f"{base}\nКомпания клиента: {company}."


def build_client_system_prompt(
    scenario_type: str | None,
    company_type: str,
    company_name: str | None,
) -> str:
    """System-prompt: Claude играет КЛИЕНТА (проспекта), а не тренера."""
    sc = resolve_scenario(scenario_type)
    company = company_type
    if company_name:
        company = f"{company_type} «{company_name}»"
    return (
        "Ты — симулятор клиента в тренажёре холодных звонков для отдела продаж "
        "MACRO Global Technologies. Менеджер по продажам звонит тебе. Твоя "
        "задача — реалистично играть роль потенциального клиента.\n\n"
        f"Сфера компании клиента: {company}.\n\n"
        f"Твоя роль и характер:\n{sc['client_role']}\n\n"
        "Жёсткие правила:\n"
        "- Ты КЛИЕНТ, а не помощник и не тренер. Никогда не подсказывай "
        "менеджеру, что ему говорить, не оценивай его, не выходи из роли.\n"
        "- Отвечай короткими живыми репликами (1-3 предложения), как в реальном "
        "телефонном разговоре.\n"
        "- Выдвигай реалистичные возражения по сценарию.\n"
        "- Если менеджер действует убедительно и грамотно — постепенно "
        "теплей и двигайся к согласию на следующий шаг.\n"
        "- Если менеджер слаб, давит или мямлит — оставайся холодным или вежливо "
        "сворачивай разговор.\n"
        "- Пиши только на русском языке."
    )


def transcript_to_text(transcript: list[dict[str, Any]]) -> str:
    """Сериализует транскрипт в читаемый диалог Менеджер/Клиент."""
    lines: list[str] = []
    for m in transcript:
        who = "Менеджер" if m.get("role") == "user" else "Клиент"
        lines.append(f"{who}: {m.get('content', '')}")
    return "\n".join(lines)


def build_evaluator_prompt(transcript: list[dict[str, Any]]) -> str:
    """EVALUATOR-промпт: оценка диалога по 4 критериям + рекомендации + удачные
    решения. Просим строгий JSON (парсится через parse_json_response)."""
    history = transcript_to_text(transcript)
    return (
        "Ты — опытный руководитель отдела продаж. Оцени разговор менеджера с "
        "клиентом в тренажёре холодных звонков.\n\n"
        f"История разговора:\n{history}\n\n"
        "Оцени менеджера по 4 критериям, каждый по шкале 0-10 (целое или с .5):\n"
        "1. speech_clarity — грамотность речи, структура, установление контакта\n"
        "2. empathy — умение слушать, выявление потребности клиента\n"
        "3. objection_handling — работа с возражениями\n"
        "4. deal_closing — попытка закрыть сделку / договориться о следующем шаге\n\n"
        "Также:\n"
        "- score: общая оценка качества 0-10 (можно дробную) — среднее "
        "впечатление, а не строго среднее арифметическое.\n"
        "- recommendations: 2-4 конкретные рекомендации, что улучшить (массив строк).\n"
        "- good_decisions: список удачных решений менеджера, которые стоит "
        "подсветить и закрепить (массив строк, может быть пустым).\n"
        "- feedback: 2-3 предложения общей обратной связи.\n\n"
        "Верни ТОЛЬКО валидный JSON без markdown-обёртки. НЕ используй настоящие "
        'двойные кавычки внутри строковых значений — заменяй на «…» или \'…\'.\n'
        "Формат:\n"
        "{\n"
        '  "score": 7.5,\n'
        '  "criteria_scores": {"speech_clarity": 8, "empathy": 6.5, '
        '"objection_handling": 7, "deal_closing": 5},\n'
        '  "recommendations": ["...", "..."],\n'
        '  "good_decisions": ["..."],\n'
        '  "feedback": "..."\n'
        "}"
    )


# ============ Scorecard parsing ============


@dataclass(frozen=True)
class ScorecardData:
    """Нормализованный результат финальной оценки.

    score — 0-10 (float, 1 знак). criteria_scores — все 4 ключа гарантированно
    присутствуют (0-10). recommendations/good_decisions — списки строк.
    """

    score: float
    criteria_scores: dict[str, float]
    recommendations: list[str] = field(default_factory=list)
    good_decisions: list[str] = field(default_factory=list)
    feedback: str = ""


def _clamp_score(value: Any) -> float:
    """Приводит значение к float в диапазоне 0..10 (1 знак). Мусор → 5.0."""
    try:
        v = float(value)
    except (TypeError, ValueError):
        return 5.0
    if v < 0:
        v = 0.0
    if v > 10:
        v = 10.0
    return round(v, 1)


def _as_str_list(value: Any) -> list[str]:
    """Нормализует поле в список непустых строк (терпит строку или список)."""
    if value is None:
        return []
    if isinstance(value, str):
        s = value.strip()
        return [s] if s else []
    if isinstance(value, (list, tuple)):
        out: list[str] = []
        for item in value:
            if item is None:
                continue
            s = str(item).strip()
            if s:
                out.append(s)
        return out
    return []


def parse_scorecard(parsed: dict[str, Any] | None) -> ScorecardData:
    """Нормализует JSON-ответ EVALUATOR'а в ScorecardData.

    Терпим к недостающим полям и мусору: всегда возвращает все 4 критерия и
    валидный score 0-10 (fallback 5.0). Если общий score не задан — берём
    среднее по критериям.
    """
    parsed = parsed or {}
    raw_criteria = parsed.get("criteria_scores")
    if not isinstance(raw_criteria, dict):
        raw_criteria = {}

    criteria: dict[str, float] = {
        key: _clamp_score(raw_criteria.get(key, parsed.get(key, 5.0)))
        for key in CRITERIA_KEYS
    }

    if "score" in parsed and parsed.get("score") is not None:
        score = _clamp_score(parsed.get("score"))
    elif "total" in parsed and parsed.get("total") is not None:
        score = _clamp_score(parsed.get("total"))
    else:
        score = round(sum(criteria.values()) / len(criteria), 1)

    return ScorecardData(
        score=score,
        criteria_scores=criteria,
        recommendations=_as_str_list(parsed.get("recommendations")),
        good_decisions=_as_str_list(parsed.get("good_decisions")),
        feedback=str(parsed.get("feedback") or "").strip(),
    )


def fallback_scorecard() -> ScorecardData:
    """Нейтральный результат, когда AI недоступен или вернул мусор."""
    return ScorecardData(
        score=5.0,
        criteria_scores={key: 5.0 for key in CRITERIA_KEYS},
        recommendations=[],
        good_decisions=[],
        feedback="Оценка временно недоступна. Попробуйте позже.",
    )


# ============ Access predicate (sales-only) ============

# Отдел продаж определяем как: роль входит в (manager/director/admin) ИЛИ юзер
# приписан к sales-отделу. Отдельной роли `sales` нет — рядовые продажники это
# role='manager'. Прочие роли (lawyer/accountant/cfo) к тренажёру не допускаются,
# если только они не приписаны к sales-департаменту.
_SALES_ROLES: frozenset[UserRole] = frozenset(
    {UserRole.manager, UserRole.director, UserRole.admin}
)


def can_use_trainer(
    role: UserRole,
    department_id: int | None = None,
    sales_department_id: int | None = None,
) -> bool:
    """True если пользователь — из отдела продаж и может пользоваться тренажёром.

    Правило: role in (manager, director, admin) ИЛИ
             (sales_department_id задан И department_id == sales_department_id).
    """
    if role in _SALES_ROLES:
        return True
    if sales_department_id is not None and department_id == sales_department_id:
        return True
    return False
