"""Merge service (Эпик 8 / Card 2.0): объединение записей с перепривязкой FK.

Логика:
1. Найти primary и secondary (404 если кто-то не существует).
2. Для каждого поля из field_choices ('primary' или 'secondary') — взять значение.
3. Перепривязать FK с secondary → primary на связанных таблицах.
4. Удалить secondary.
5. Записать audit-лог merge на primary с diff_json = {merged_from, field_choices}.

ВСЁ В ОДНОЙ ТРАНЗАКЦИИ — flush на каждом шаге, commit в конце. Если что-то
падает — caller делает rollback.

Поддерживаемые сущности: counterparty / contact / company / lead.

FK-перепривязка — модуль-специфичная: для Counterparty есть много связей
(contracts, subscriptions, deals, leads, и т.п.), для Contact / Company / Lead —
меньше. Все relinker'ы — внутренние helper'ы _relink_*.
"""
from __future__ import annotations

from fastapi import HTTPException
from sqlalchemy import update
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.models import (
    Activity,
    ClientNote,
    ClientSubscription,
    ClientTask,
    Company,
    Contact,
    ContactCompanyLink,
    Contract,
    Counterparty,
    Deal,
    File as CrmFile,
    Folder,
    Lead,
    LegacyContact,
)
from app.services.audit import log_change


# Whitelist полей, которые можно выбирать при merge (по entity). Required-поля
# должны быть в этом списке. Поля типа id / created_at — НЕ выбираются.
MERGE_FIELDS: dict[str, list[str]] = {
    "counterparty": [
        "name",
        "full_legal_form",
        "legal_form",
        "country_code",
        "city",
        "director_position",
        "director_genitive",
        "director_short",
        "acts_basis",
        "tax_id_label",
        "tax_id",
        "address",
        "bank",
        "bank_code_label",
        "bank_code",
        "account",
        "phone",
        "email",
        "website",
        "notes",
        "group_id",
        "category_code",
        "extra_fields",
    ],
    "contact": [
        "full_name",
        "email",
        "phone",
        "position",
        "company_id",
        "is_primary",
        "owner_id",
        "tg_username",
        "notes",
        "extra_fields",
    ],
    "company": [
        "legal_name",
        "short_name",
        "tax_id",
        "country",
        "city",
        "website",
        "phone",
        "email",
        "industry",
        "notes",
        "group_id",
        "category_code",
        "counterparty_id",
        "extra_fields",
    ],
    "lead": [
        "name",
        "contact_email",
        "contact_phone",
        "source",
        "owner_id",
        "pipeline_id",
        "stage_id",
        "status",
        "tags",
        "notes",
        "extra_fields",
    ],
}


_MODEL_MAP = {
    "counterparty": Counterparty,
    "contact": Contact,
    "company": Company,
    "lead": Lead,
}


async def _get_or_404(
    session: AsyncSession, model: type, entity_id: int, label: str
) -> object:
    obj = (
        await session.execute(select(model).where(model.id == entity_id))
    ).scalar_one_or_none()
    if obj is None:
        raise HTTPException(404, f"{label} {entity_id} не найден")
    return obj


def _apply_choices(
    primary: object,
    secondary: object,
    field_choices: dict[str, str],
    allowed_fields: list[str],
) -> dict[str, dict[str, object]]:
    """Применить choices: для каждого поля из field_choices взять значение
    либо primary, либо secondary, записать в primary.

    Возвращает map изменённых полей: {field: {"from": primary_val_old, "to": new_val}}.
    """
    changes: dict[str, dict[str, object]] = {}
    for field, source in field_choices.items():
        if field not in allowed_fields:
            raise HTTPException(400, f"Недопустимое поле для merge: {field}")
        if source not in ("primary", "secondary"):
            raise HTTPException(
                400, f"choice для {field} должен быть 'primary'|'secondary', получено {source}"
            )
        old_val = getattr(primary, field, None)
        new_val = (
            getattr(primary, field, None)
            if source == "primary"
            else getattr(secondary, field, None)
        )
        if old_val != new_val:
            setattr(primary, field, new_val)
            changes[field] = {"from": old_val, "to": new_val}
    return changes


# ---------- FK relinkers ----------


async def _relink_counterparty_fks(
    session: AsyncSession, primary_id: int, secondary_id: int
) -> dict[str, int]:
    """Перепривязать все FK Counterparty.id с secondary на primary.

    Возвращает summary {table: count_updated}.
    """
    summary: dict[str, int] = {}

    async def upd(model, col, name: str) -> None:
        res = await session.execute(
            update(model).where(col == secondary_id).values({col.key: primary_id})
        )
        summary[name] = int(res.rowcount or 0)

    await upd(Contract, Contract.counterparty_id, "contracts")
    await upd(Deal, Deal.counterparty_id, "deals")
    await upd(ClientSubscription, ClientSubscription.counterparty_id, "client_subscriptions")
    await upd(ClientNote, ClientNote.counterparty_id, "client_notes")
    await upd(ClientTask, ClientTask.counterparty_id, "client_tasks")
    await upd(LegacyContact, LegacyContact.counterparty_id, "contacts_legacy")
    await upd(Lead, Lead.converted_to_counterparty_id, "leads_converted")
    # Company.counterparty_id — обратная связь
    await upd(Company, Company.counterparty_id, "crm_companies")

    # Activities (полиморфные через target_type+target_id)
    res = await session.execute(
        update(Activity)
        .where(Activity.target_type == "counterparty", Activity.target_id == secondary_id)
        .values(target_id=primary_id)
    )
    summary["activities"] = int(res.rowcount or 0)

    return summary


async def _relink_contact_fks(
    session: AsyncSession, primary_id: int, secondary_id: int
) -> dict[str, int]:
    """Contact (crm_contacts) — только Activities полиморфные."""
    summary: dict[str, int] = {}
    res = await session.execute(
        update(Activity)
        .where(Activity.target_type == "contact", Activity.target_id == secondary_id)
        .values(target_id=primary_id)
    )
    summary["activities"] = int(res.rowcount or 0)
    return summary


async def _relink_company_fks(
    session: AsyncSession, primary_id: int, secondary_id: int
) -> dict[str, int]:
    """Company — перепривязать ВСЕ FK secondary → primary, чтобы ничего не
    осиротело при удалении secondary.

    Покрывает: Contact.company_id (legacy), Deal.company_id,
    ClientSubscription.company_id, ContactCompanyLink.company_id (с dedup по
    UNIQUE contact+company), Folder/File (полиморфный owner='company'),
    Activities (полиморфный target='company'). Мирор-Counterparty —
    предусловие в merge_entities (запрет при непустых зеркалах).
    """
    summary: dict[str, int] = {}

    # 1. Прямые FK company_id (Contact legacy, Deal, ClientSubscription).
    res = await session.execute(
        update(Contact).where(Contact.company_id == secondary_id).values(company_id=primary_id)
    )
    summary["crm_contacts"] = int(res.rowcount or 0)
    res = await session.execute(
        update(Deal).where(Deal.company_id == secondary_id).values(company_id=primary_id)
    )
    summary["deals"] = int(res.rowcount or 0)
    res = await session.execute(
        update(ClientSubscription)
        .where(ClientSubscription.company_id == secondary_id)
        .values(company_id=primary_id)
    )
    summary["client_subscriptions"] = int(res.rowcount or 0)

    # 2. ContactCompanyLink (M2M) с dedup: UNIQUE(contact_id, company_id) — если
    # у primary уже есть связь с тем же контактом, простой UPDATE нарушит
    # констрейнт. Поэтому: связи с конфликтующим contact_id — удаляем (дубль),
    # остальные — перепривязываем.
    primary_contact_ids = set((await session.execute(
        select(ContactCompanyLink.contact_id).where(
            ContactCompanyLink.company_id == primary_id
        )
    )).scalars().all())
    secondary_links = (await session.execute(
        select(ContactCompanyLink).where(
            ContactCompanyLink.company_id == secondary_id
        )
    )).scalars().all()
    relinked = 0
    dropped = 0
    for link in secondary_links:
        if link.contact_id in primary_contact_ids:
            await session.delete(link)  # дубль — у primary уже есть этот контакт
            dropped += 1
        else:
            link.company_id = primary_id
            primary_contact_ids.add(link.contact_id)
            relinked += 1
    summary["contact_company_links"] = relinked
    if dropped:
        summary["contact_company_links_dropped_dups"] = dropped

    # 3. Полиморфные Folder / File (owner_entity_type='company').
    res = await session.execute(
        update(Folder)
        .where(Folder.owner_entity_type == "company", Folder.owner_entity_id == secondary_id)
        .values(owner_entity_id=primary_id)
    )
    summary["crm_folders"] = int(res.rowcount or 0)
    res = await session.execute(
        update(CrmFile)
        .where(CrmFile.owner_entity_type == "company", CrmFile.owner_entity_id == secondary_id)
        .values(owner_entity_id=primary_id)
    )
    summary["crm_files"] = int(res.rowcount or 0)

    # 4. Activities (полиморфный target='company').
    res = await session.execute(
        update(Activity)
        .where(Activity.target_type == "company", Activity.target_id == secondary_id)
        .values(target_id=primary_id)
    )
    summary["activities"] = int(res.rowcount or 0)
    return summary


async def _relink_lead_fks(
    session: AsyncSession, primary_id: int, secondary_id: int
) -> dict[str, int]:
    """Lead — только Activities."""
    summary: dict[str, int] = {}
    res = await session.execute(
        update(Activity)
        .where(Activity.target_type == "lead", Activity.target_id == secondary_id)
        .values(target_id=primary_id)
    )
    summary["activities"] = int(res.rowcount or 0)
    return summary


_RELINKERS = {
    "counterparty": _relink_counterparty_fks,
    "contact": _relink_contact_fks,
    "company": _relink_company_fks,
    "lead": _relink_lead_fks,
}


# ---------- Public API ----------


async def merge_entities(
    session: AsyncSession,
    *,
    entity_type: str,
    primary_id: int,
    secondary_id: int,
    field_choices: dict[str, str],
    user_id: int | None,
) -> dict[str, object]:
    """Объединить две записи. secondary → primary, secondary удаляется.

    field_choices: {field_name: 'primary'|'secondary'} — для каждого поля
    указывается, чью версию оставить. Поля не из MERGE_FIELDS[entity_type] → 400.

    Все шаги в одной транзакции — commit на caller'е НЕ нужен, мы коммитим сами
    в самом конце для атомарности (если merge упадёт — rollback всего).

    Возвращает: {"merged_id": primary_id, "field_changes": {...}, "fk_relinks": {...}}.
    """
    if entity_type not in _MODEL_MAP:
        raise HTTPException(400, f"Недопустимый entity_type для merge: {entity_type}")
    if primary_id == secondary_id:
        raise HTTPException(400, "primary_id и secondary_id не могут совпадать")

    model = _MODEL_MAP[entity_type]
    label_ru = {
        "counterparty": "Контрагент",
        "contact": "Контакт",
        "company": "Компания",
        "lead": "Лид",
    }[entity_type]

    primary = await _get_or_404(session, model, primary_id, label_ru)
    secondary = await _get_or_404(session, model, secondary_id, label_ru)

    # 0. Предусловие для company: запретить merge, если у secondary есть
    # Counterparty-зеркало с привязанными договорами — иначе при удалении
    # secondary зеркало (или его данные) осиротеет / договоры потеряют сторону.
    # Без договоров зеркало секондари удалим вместе с компанией ниже.
    secondary_mirror_id: int | None = None
    if entity_type == "company":
        secondary_mirror_id = getattr(secondary, "counterparty_id", None)
        if secondary_mirror_id is not None:
            contracts_on_mirror = (await session.execute(
                select(Contract.id).where(Contract.counterparty_id == secondary_mirror_id)
            )).first()
            if contracts_on_mirror is not None:
                raise HTTPException(
                    400,
                    "Нельзя объединить: у вторичной компании есть Counterparty-зеркало "
                    "с привязанными договорами. Перенесите/удалите договоры вручную.",
                )

    # 1. Применить choices
    allowed = MERGE_FIELDS[entity_type]
    field_changes = _apply_choices(primary, secondary, field_choices, allowed)

    # 2. Перепривязать FK
    relinker = _RELINKERS[entity_type]
    fk_relinks = await relinker(session, primary_id, secondary_id)
    await session.flush()

    # 3. Удалить secondary (+ его Counterparty-зеркало, если оно без договоров —
    # проверено в шаге 0). Сначала зеркало: client_subscriptions.counterparty_id
    # на нём уже перепривязаны выше через company_id-relink, но прямых
    # counterparty-FK на secondary-mirror быть не должно. Удаляем для чистоты.
    if entity_type == "company" and secondary_mirror_id is not None:
        mirror = (await session.execute(
            select(Counterparty).where(Counterparty.id == secondary_mirror_id)
        )).scalar_one_or_none()
        if mirror is not None:
            primary_mirror_id = getattr(primary, "counterparty_id", None)
            if primary_mirror_id is not None and primary_mirror_id != secondary_mirror_id:
                # У primary есть своё зеркало — переносим ссылки на него, затем
                # удаляем секондари-зеркало (subscriptions.counterparty_id =
                # CASCADE, поэтому переносим ДО delete, иначе данные потеряются).
                await session.execute(
                    update(ClientSubscription)
                    .where(ClientSubscription.counterparty_id == secondary_mirror_id)
                    .values(counterparty_id=primary_mirror_id)
                )
                await session.execute(
                    update(ClientNote)
                    .where(ClientNote.counterparty_id == secondary_mirror_id)
                    .values(counterparty_id=primary_mirror_id)
                )
                await session.execute(
                    update(ClientTask)
                    .where(ClientTask.counterparty_id == secondary_mirror_id)
                    .values(counterparty_id=primary_mirror_id)
                )
                await session.flush()
                await session.delete(mirror)
                await session.flush()
                fk_relinks["secondary_mirror_deleted"] = secondary_mirror_id
            elif primary_mirror_id is None:
                # У primary НЕТ зеркала — нельзя поглотить ссылки. Чтобы не
                # cascade-удалить подписки/заметки/задачи, НЕ удаляем секондари-
                # зеркало, а переподвешиваем его на primary-компанию (становится
                # её зеркалом). Зеркало остаётся валидным, ничего не теряется.
                primary.counterparty_id = secondary_mirror_id
                fk_relinks["secondary_mirror_reassigned_to_primary"] = secondary_mirror_id
            # primary_mirror_id == secondary_mirror_id (общее зеркало) — ничего
            # не делаем: оно и так привязано к primary.

    await session.delete(secondary)
    await session.flush()

    # 4. Audit log
    await log_change(
        session,
        entity_type=entity_type,
        entity_id=primary_id,
        user_id=user_id,
        action="merge",
        diff_override={
            "merged_from": secondary_id,
            "field_changes": field_changes,
            "fk_relinks": fk_relinks,
        },
    )

    await session.commit()

    return {
        "merged_id": primary_id,
        "field_changes": field_changes,
        "fk_relinks": fk_relinks,
    }
