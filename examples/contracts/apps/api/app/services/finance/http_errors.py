"""Маппинг исключений posting-движка → HTTP-статус + сообщение (Ф0, ЧАНК 3).

Чистая функция `posting_status` (тестируема без FastAPI) переводит исключение
движка/курса в (status_code, detail). Роутеры ловят PostingError/FxRateMissing и
зовут это, чтобы все эндпоинты отвечали одинаково:

  UnbalancedEntry      → 422  «проводка не сбалансирована»
  FxRateMissing        → 422  «нет курса X→Y на дату»
  PeriodLocked         → 409  «период закрыт»
  ImmutablePosted      → 409  «проведено, только сторно»
  MissingCounterparty  → 422  «счёт требует контрагента»
  CashflowWithoutMoney → 422  «статья ДДС только на денежной строке»
  PostingError (база)  → 422  прочие ошибки построения проводки

409 (Conflict) для иммутабельности/закрытого периода — это конфликт состояния
ресурса (а не невалидный ввод); 422 (Unprocessable Entity) — для семантически
невалидной проводки (дисбаланс/нет курса/нет контрагента).
"""

from __future__ import annotations

from app.services.finance.fx import FxRateMissing
from app.services.finance.posting import (
    CashflowWithoutMoney,
    ImmutablePosted,
    MissingCounterparty,
    PeriodLocked,
    PostingError,
    UnbalancedEntry,
)

# Конфликт состояния ресурса (нельзя изменить/постить из-за состояния) → 409.
_CONFLICT = (ImmutablePosted, PeriodLocked)


def posting_status(exc: Exception) -> tuple[int, str]:
    """(http_status, detail) по исключению движка/курса. Pure — без FastAPI.

    Порядок проверки важен: подклассы PostingError проверяются ДО базового
    PostingError, иначе все схлопнулись бы в 422-«прочее». FxRateMissing — НЕ
    подкласс PostingError (отдельная иерархия в fx.py), обрабатывается явно.
    """
    if isinstance(exc, FxRateMissing):
        return 422, str(exc)
    if isinstance(exc, _CONFLICT):
        return 409, str(exc)
    if isinstance(
        exc, (UnbalancedEntry, MissingCounterparty, CashflowWithoutMoney)
    ):
        return 422, str(exc)
    if isinstance(exc, PostingError):
        return 422, str(exc)
    # Не наше исключение — пусть всплывает (вызывающий не должен сюда попадать).
    raise exc


def phase2_status(exc: Exception) -> tuple[int, str]:
    """(http_status, detail) по исключению флоу Ф2 (заявки/реестр/согласование). Pure.

    Конфликт состояния (иммутабельность/заморозка/повторное решение) → 409;
    семантически-невалидный ввод (нет сценария / неподходящая позиция / нельзя
    подать) → 422. Импорт исключений локальный — избегаем циклов на уровне модуля.
    """
    from app.services.finance.fin_approval import (
        AlreadyDecided,
        ApprovalError,
        NoScenarioMatched,
        NotAnApprover,
        SelfApproval,
    )
    from app.services.finance.registry import (
        RegistryError,
        RegistryFrozen,
        RegistryMemberInvalid,
    )
    from app.services.finance.requests import RequestError, RequestImmutable

    # 409 — конфликт состояния ресурса.
    if isinstance(exc, (RequestImmutable, RegistryFrozen, AlreadyDecided)):
        return 409, str(exc)
    # 422 — невалидный запрос/ввод.
    if isinstance(exc, NoScenarioMatched):
        return 422, str(exc)
    if isinstance(exc, (NotAnApprover, SelfApproval)):
        return 403, str(exc)
    if isinstance(
        exc,
        (RequestError, RegistryError, RegistryMemberInvalid, ApprovalError),
    ):
        return 422, str(exc)
    raise exc


def phase5_status(exc: Exception) -> tuple[int, str]:
    """(http_status, detail) по исключению флоу Ф5 (инвойсы/акты/вендор-счета). Pure.

    Конфликт состояния (иммутабельность/уже выставлено/нельзя отменить оплаченное) → 409;
    семантически-невалидный ввод (оплата сверх остатка / не выставлен / overpay) → 422.
    PostingError/FxRateMissing внутри issue/pay делегируются posting_status (422/409).
    """
    from app.services.finance.invoicing import (
        DocumentError,
        DocumentImmutable,
        DocumentNotIssued,
        OverPayment,
    )

    if isinstance(exc, (FxRateMissing, PostingError)):
        return posting_status(exc)
    if isinstance(exc, DocumentImmutable):
        return 409, str(exc)
    if isinstance(exc, (DocumentNotIssued, OverPayment, DocumentError)):
        return 422, str(exc)
    raise exc


def phase3_status(exc: Exception) -> tuple[int, str]:
    """(http_status, detail) по исключению Ф3-интеграции факта оплаты. Pure.

    Конфигурационные сбои интеграции (нет op_type/юрлица/денежного счёта) → 422;
    PostingError/FxRateMissing внутри проводки делегируются posting_status (422/409).
    """
    from app.services.finance.pay_integration import IntegrationError

    if isinstance(exc, (FxRateMissing, PostingError)):
        return posting_status(exc)
    if isinstance(exc, IntegrationError):
        return 422, str(exc)
    raise exc


def phase4_status(exc: Exception) -> tuple[int, str]:
    """(http_status, detail) по исключению Ф4 (признание/переоценка/смена базы). Pure.

    Семантически-невалидный ввод (уже признано/нет контрагента/нулевая дельта/смена базы) →
    422; PostingError/FxRateMissing внутри проводки делегируются posting_status (422/409),
    PeriodLocked → 409. Импорт исключений локальный — избегаем циклов на уровне модуля.
    """
    from app.services.finance.base_currency import BaseCurrencyError
    from app.services.finance.recognition import RecognitionError
    from app.services.finance.revaluation import RevaluationError

    if isinstance(exc, (FxRateMissing, PostingError)):
        return posting_status(exc)
    if isinstance(exc, (RecognitionError, RevaluationError, BaseCurrencyError)):
        return 422, str(exc)
    raise exc
