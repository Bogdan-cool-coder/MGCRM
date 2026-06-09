"""Whitelist scope'ов для APIToken (Эпик 11.1).

Scope-формат: "<action>:<resource>", где:
- action ∈ {read, write}
- resource — единственное число существительного (leads / deals / contracts / ...).

Особый scope: "*" — admin-уровень, перекрывает всё. Используется для
интеграций, которые легитимно должны ходить во все endpoint'ы (например,
backup-агент или административный bridge на старую AmoCRM).

`scope_satisfies` — pure-функция: проверяет, удовлетворяет ли набор scope'ов
конкретному required scope. "*" в наборе всегда удовлетворяет. write:X также
автоматически подразумевает read:X (пишущий имеет право читать ту же сущность
— иначе невозможно сделать PATCH без чтения GET).
"""
from __future__ import annotations

# Полный whitelist. Любой scope, не входящий в этот набор, отвергается на
# create APIToken (HTTPException 400). "*" — admin-уровень.
ALLOWED_SCOPES: frozenset[str] = frozenset(
    {
        "*",
        # Lead-сущности
        "read:leads", "write:leads",
        # Сделки воронки продаж
        "read:deals", "write:deals",
        # Контакты (Эпик 1.2)
        "read:contacts", "write:contacts",
        # Компании (Эпик 1.2)
        "read:companies", "write:companies",
        # Counterparty (legacy, для обратной совместимости)
        "read:counterparties", "write:counterparties",
        # Договоры
        "read:contracts", "write:contracts",
        # Подписки CS реестра
        "read:subscriptions", "write:subscriptions",
        # Inbox webhook (только запись — INSERT InboundMessage)
        "inbox:write",
    }
)


def scope_satisfies(token_scopes: list[str] | tuple[str, ...], required: str) -> bool:
    """Проверка: удовлетворяет ли набор scope'ов токена required scope.

    Правила:
    - "*" в наборе — всегда True (admin override).
    - Точное совпадение — True.
    - write:X удовлетворяет read:X (пишущий читает та же сущность).
    """
    if not required:
        return True
    if "*" in token_scopes:
        return True
    if required in token_scopes:
        return True
    # write:X → read:X implication
    if required.startswith("read:"):
        write_variant = "write:" + required.removeprefix("read:")
        if write_variant in token_scopes:
            return True
    return False


def validate_scopes(scopes: list[str]) -> list[str]:
    """Валидация списка scope'ов на create/update APIToken.

    Возвращает нормализованный (без дублей, отсортированный) список или
    бросает ValueError при первом неизвестном scope.

    Пустой список допустим (токен без прав — read-only-зомби, но legal).
    """
    if not isinstance(scopes, list):
        raise ValueError("scopes должен быть списком строк")
    seen: set[str] = set()
    for s in scopes:
        if not isinstance(s, str):
            raise ValueError(f"scope должен быть строкой: {s!r}")
        if s not in ALLOWED_SCOPES:
            raise ValueError(
                f"Неизвестный scope: {s!r}. Разрешённые: "
                f"{sorted(ALLOWED_SCOPES)}"
            )
        seen.add(s)
    return sorted(seen)
