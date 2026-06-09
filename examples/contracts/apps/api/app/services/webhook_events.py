"""Whitelist событий для outbound webhooks (Эпик 11.2).

Любое событие, не входящее в WEBHOOK_EVENTS, не может быть подписано на
Webhook (валидируется в роутере) и не диспатчится (защита от случайно дёрнутого
несуществующего event_name).

Формат: "<entity>.<action>" — единственное число entity (lead/deal/contract).

Соглашение по payload (см. webhook_dispatcher.build_event_payload):
- payload верхнего уровня: {"event": str, "data": {...}, "occurred_at": iso}
- data — словарь с минимальным набором полей сущности (id, name, status и т.п.)
  плюс контекст события (для stage_changed — from_stage_id/to_stage_id;
  для health_changed — old_tier/new_tier).
"""
from __future__ import annotations

# Все поддерживаемые события.
WEBHOOK_EVENTS: frozenset[str] = frozenset(
    {
        # Lead lifecycle.
        #
        # ВНИМАНИЕ (DEALS 2.0): "lead.created" эмитится ТОЛЬКО при ручном
        # создании лида через POST /api/leads (routers/leads.py). Это редкий
        # путь — большинство лидов команда заводит руками крайне нечасто.
        #
        # ВХОДЯЩИЙ ПОТОК (Telegram/WhatsApp/Email/Forms/webhooks-in) после
        # слияния DEALS 2.0 НЕ создаёт Lead — он создаёт Company+Deal и эмитит
        # "deal.created" (+ "counterparty.created" для новой компании), см.
        # services/inbox.py. Поэтому внешние интеграции, ранее подписанные на
        # "lead.created" ради входящих заявок, теперь должны подписываться на
        # "deal.created". Дубль-событие сознательно НЕ вводим (не плодим шум) —
        # "lead.created" остаётся как есть для backward-compat ручного пути.
        "lead.created",
        "lead.converted",
        # Deal lifecycle
        "deal.created",
        "deal.stage_changed",
        "deal.won",
        "deal.lost",
        # Contract lifecycle
        "contract.created",
        "contract.signed",
        # Subscription / CS lifecycle
        "subscription.created",
        "subscription.health_changed",
        # Counterparty
        "counterparty.created",
        # Эпик 13: Onboarding lifecycle (course.assigned + course.completed).
        # lesson.completed НЕ добавляем — слишком много шума (по 5-15 уроков на курс).
        "course.assigned",
        "course.completed",
    }
)

# Специальный wildcard в Webhook.event_subscriptions — подписка на все события.
WILDCARD_EVENT = "*"


def validate_event_subscriptions(events: list[str]) -> list[str]:
    """Валидация event_subscriptions на create/update Webhook.

    Допускает специальный "*". Дедуплицирует и сортирует. Бросает ValueError
    при первом неизвестном.
    """
    if not isinstance(events, list):
        raise ValueError("event_subscriptions должен быть списком строк")
    seen: set[str] = set()
    for e in events:
        if not isinstance(e, str):
            raise ValueError(f"event должен быть строкой: {e!r}")
        if e != WILDCARD_EVENT and e not in WEBHOOK_EVENTS:
            raise ValueError(
                f"Неизвестное событие: {e!r}. Разрешённые: "
                f"{sorted(WEBHOOK_EVENTS)} либо '*'"
            )
        seen.add(e)
    return sorted(seen)


def event_matches_subscription(
    event: str, subscriptions: list[str] | tuple[str, ...]
) -> bool:
    """Проверка: подходит ли event под список подписок Webhook'а."""
    if not event:
        return False
    if WILDCARD_EVENT in subscriptions:
        return True
    return event in subscriptions
