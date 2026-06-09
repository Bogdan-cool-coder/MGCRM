"""Bulk-генерация документов (Эпик 6 MVP).

Эндпоинт `POST /api/bulk-tasks/generate-documents` создаёт BulkTask(pending)
и запускает фоновую задачу через FastAPI BackgroundTasks. Здесь живёт
исполнитель: для каждого target_id из task.target_ids рендерится .docx (без
конверсии в PDF — LibreOffice слишком медленный для bulk), всё складывается
в ZIP-архив с manifest.json.

ВАЖНО: BackgroundTasks работает inline в процессе uvicorn. В проде api scale=2 —
задача выполняется на той реплике, которая приняла запрос. Это означает
race-condition'ы если пользователь дважды нажмёт «сгенерировать» (разные реплики
параллельно начнут работу с разными BulkTask). MVP — это допустимо: status field
монотонный (pending→running→success/failed), результаты пишутся в одну запись.

Storage layout:
    /data/storage/bulk_tasks/
        {task_id}/
            {target_id}.docx       — для каждого успешно отрендеренного
            manifest.json          — список (target_id, original_name, status, error?)
        {task_id}.zip              — финальный архив (включает manifest и .docx)

Поддерживаемые target_type'ы (MVP):
    - 'counterparty' → берём Counterparty, заполняем sublicensee из его реквизитов
    - 'subscription' → берём ClientSubscription.counterparty, аналогично

Минимальный контекст рендера: product (из template.product_codes[0] или
'macrocrm'), country (из counterparty.country_code), licensor (из БД),
sublicensee (из counterparty), contract (placeholder-номер и дата),
custom (дефолты TemplateVariable). Этого достаточно, чтобы master_skeleton
отрендерился — пользователь потом дозаполнит реальные данные в Word.

Уведомление: после завершения отправляется TG в личку created_by_user_id
(если у юзера привязан telegram_user_id).
"""
from __future__ import annotations

import json
import logging
import zipfile
from dataclasses import dataclass
from datetime import UTC, datetime
from pathlib import Path
from typing import Any

from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.config import get_settings
from app.db import SessionLocal
from app.models import (
    BulkTask,
    ClientSubscription,
    Company,
    Template,
    TemplateVariableType,
    User,
)
from app.services.party import resolve_party
from app.services.render import get_master_skeleton_path, render_docx
from app.services.templates import (
    get_licensor_for_country,
    load_active_variables,
    load_country,
    load_product,
)

logger = logging.getLogger(__name__)

# Whitelist'ы для валидации входа в роутерах (используются и в тестах).
BULK_KINDS: tuple[str, ...] = ("document_generation",)
BULK_STATUSES: tuple[str, ...] = (
    "pending", "running", "success", "failed", "cancelled",
)
BULK_TARGET_TYPES: tuple[str, ...] = ("counterparty", "subscription")


# ============ Контекст рендера на одну сущность ============


@dataclass
class ResolvedParty:
    """CONTACTS 2.0 Ф4: нормализованная сторона для bulk-рендера.

    Реквизиты (`sublicensee`) берутся из Company (источник истины) через
    `app.services.party`. Остальные поля (display_name/city/country_code) —
    для имени файла, города в шапке и подбора лицензиара.
    """

    sublicensee: dict[str, Any]
    display_name: str
    city: str
    country_code: str | None
    company_id: int | None
    counterparty_id: int | None


def _safe_filename(name: str, fallback_id: int) -> str:
    """Привести имя к безопасному имени файла (без / \\ : * ? " < > |).

    Кириллица допустима — ZIP'ы её корректно хранят как UTF-8.
    """
    bad = '/\\:*?"<>|'
    cleaned = "".join("_" if c in bad else c for c in (name or ""))
    cleaned = cleaned.strip().rstrip(".")
    if not cleaned:
        cleaned = f"id_{fallback_id}"
    return cleaned[:120]


def _company_country_code(co: Company) -> str | None:
    """Страна Company для подбора лицензиара: ISO `country` → зеркало `country_code`."""
    return (co.country or co.country_code or "").lower() or None


async def _resolve_party_for_target(
    session: AsyncSession, target_type: str, target_id: int,
) -> ResolvedParty | None:
    """CONTACTS 2.0 Ф4: резолв стороны bulk-генерации через Company.

    Для 'subscription' источник company_id/counterparty_id — сама подписка
    (она дублирует оба id, см. ensure_subscription_from_contract). Для
    'counterparty' — это id контрагента; Company резолвится из зеркала.

    Реквизиты ВСЕГДА берутся из Company, если она найдена (через
    `app.services.party.resolve_party`). Counterparty — legacy-фолбэк. None —
    если ни Company, ни Counterparty не нашлись.
    """
    company_id: int | None = None
    counterparty_id: int | None = None

    if target_type == "counterparty":
        counterparty_id = target_id
    elif target_type == "subscription":
        sub = (await session.execute(
            select(ClientSubscription).where(ClientSubscription.id == target_id)
        )).scalar_one_or_none()
        if not sub:
            return None
        # Источник истины — company_id подписки; counterparty_id как зеркало/фолбэк.
        company_id = sub.company_id
        counterparty_id = sub.counterparty_id
    else:
        return None

    company, counterparty, sublicensee = await resolve_party(
        session, company_id=company_id, counterparty_id=counterparty_id,
    )
    if sublicensee is None:
        return None

    if company is not None:
        display_name = (
            company.name or company.short_name or company.legal_name
            or (counterparty.name if counterparty else None)
            or f"company_{company.id}"
        )
        city = ((counterparty.city if counterparty else None) or "")
        country_code = _company_country_code(company) or (
            counterparty.country_code if counterparty else None
        )
        return ResolvedParty(
            sublicensee=sublicensee,
            display_name=display_name,
            city=city,
            country_code=country_code,
            company_id=company.id,
            counterparty_id=(company.counterparty_id or (counterparty.id if counterparty else None)),
        )

    # Legacy-путь: только Counterparty (Company-зеркало не нашлось).
    assert counterparty is not None  # гарантировано resolve_party при sublicensee != None
    return ResolvedParty(
        sublicensee=sublicensee,
        display_name=counterparty.name or f"cp_{counterparty.id}",
        city=counterparty.city or "",
        country_code=counterparty.country_code,
        company_id=None,
        counterparty_id=counterparty.id,
    )


async def _build_render_context(
    session: AsyncSession,
    template: Template,
    party: ResolvedParty,
) -> tuple[dict[str, Any], dict[str, Any], dict[str, Any], dict[str, Any]]:
    """Собирает (product, country, licensor, contract_data) для render_docx.

    product/country определяется привязкой шаблона (template.product_codes[0],
    country_codes[0]) с дефолтами 'macrocrm' / 'kz'. Если страна стороны
    (party.country_code, из Company) известна — берём её (чтобы licensor
    подобрался корректно). Реквизиты — party.sublicensee (из Company, Ф4).
    """
    product_code = (template.product_codes or [None])[0] or "macrocrm"
    country_code = (party.country_code or (template.country_codes or [None])[0] or "kz").lower()

    try:
        product = await load_product(session, product_code)
    except FileNotFoundError:
        product = {}
    try:
        country = await load_country(session, country_code)
    except FileNotFoundError:
        country = {}
    licensor = await get_licensor_for_country(session, country_code)

    # Кастомные переменные: подкладываем дефолты, как в preview-эндпоинте.
    custom_vars = await load_active_variables(session, product_code, country_code)
    custom = {v.key: (v.default_value or "") for v in custom_vars}
    # checkbox без значения → «Нет»
    for v in custom_vars:
        if v.var_type == TemplateVariableType.checkbox and not custom.get(v.key):
            custom[v.key] = "Нет"

    today_str = datetime.now(UTC).strftime("%d.%m.%Y")
    contract_data: dict[str, Any] = {
        "contract": {
            "number": "(bulk-черновик)",
            "date": today_str,
            "city": party.city or "",
            "total": "",
            "currency": "",
            "total_in_words": "",
        },
        "sublicensee": party.sublicensee,
        "custom": custom,
        # license namespace: реальный master_skeleton.docx итерирует
        # license.payment_schedule / license.act_schedule. Даём пустые списки,
        # чтобы циклы рендерились без строк (а не падали). SafeUndefined теперь
        # chainable, но явные пустые списки дают чистый bulk-черновик.
        "license": {"payment_schedule": [], "act_schedule": []},
        # items/pricing нет — bulk-черновик без цен; в Word юрист дозаполнит
        "items": [],
        "pricing": {},
    }
    return product, country, licensor, contract_data


# ============ Запись/чтение BulkTask ============

async def _load_task(session: AsyncSession, task_id: int) -> BulkTask | None:
    return (await session.execute(
        select(BulkTask).where(BulkTask.id == task_id)
    )).scalar_one_or_none()


async def _mark_running(session: AsyncSession, task_id: int) -> None:
    task = await _load_task(session, task_id)
    if not task:
        return
    task.status = "running"
    task.started_at = datetime.now(UTC)
    await session.commit()


async def _finalize_task(
    session: AsyncSession,
    task_id: int,
    *,
    status: str,
    success_count: int,
    failed_count: int,
    result_zip_path: str | None,
    error_text: str | None,
) -> User | None:
    """Записывает финальное состояние и возвращает автора (для TG-уведомления)."""
    task = await _load_task(session, task_id)
    if not task:
        return None
    task.status = status
    task.success_count = success_count
    task.failed_count = failed_count
    task.result_zip_path = result_zip_path
    task.error_text = error_text
    task.finished_at = datetime.now(UTC)
    author: User | None = None
    if task.created_by_user_id:
        author = (await session.execute(
            select(User).where(User.id == task.created_by_user_id)
        )).scalar_one_or_none()
    await session.commit()
    return author


# ============ ZIP packing ============

def pack_zip(
    out_zip: Path,
    docx_paths: list[Path],
    manifest: list[dict[str, Any]],
) -> int:
    """Упаковать .docx + manifest.json в ZIP. Возвращает размер байт результата.

    Чистая функция — pure I/O без БД, удобно для теста (см. test_renewal_bulk).
    """
    out_zip.parent.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(
        out_zip, "w", compression=zipfile.ZIP_DEFLATED,
    ) as zf:
        for p in docx_paths:
            if p.exists():
                zf.write(p, arcname=p.name)
        zf.writestr("manifest.json", json.dumps(manifest, ensure_ascii=False, indent=2))
    return out_zip.stat().st_size


# ============ Background task entrypoint ============

async def execute_bulk_generation(task_id: int) -> None:
    """Фоновая задача: реализация bulk-генерации .docx + ZIP.

    Запускается через FastAPI BackgroundTasks (не передаём session — она
    привязана к request lifecycle, открываем новую через SessionLocal).
    """
    settings = get_settings()
    out_dir = settings.storage_dir / "bulk_tasks" / str(task_id)
    out_dir.mkdir(parents=True, exist_ok=True)
    zip_path = settings.storage_dir / "bulk_tasks" / f"{task_id}.zip"

    docx_paths: list[Path] = []
    manifest: list[dict[str, Any]] = []
    success_count = 0
    failed_count = 0
    fatal_error: str | None = None

    template_path = get_master_skeleton_path()
    if not template_path.exists():
        # Фатальная ошибка: без шаблона никакой рендер невозможен.
        async with SessionLocal() as session:
            await _finalize_task(
                session,
                task_id,
                status="failed",
                success_count=0,
                failed_count=0,
                result_zip_path=None,
                error_text="master_skeleton.docx не найден",
            )
        return

    try:
        async with SessionLocal() as session:
            await _mark_running(session, task_id)
            task = await _load_task(session, task_id)
            if not task:
                logger.warning("execute_bulk_generation: task %s исчезла", task_id)
                return
            target_type = task.target_type
            target_ids = list(task.target_ids or [])
            template_code = task.template_code or "master_skeleton"

            template = (await session.execute(
                select(Template).where(Template.code == template_code)
            )).scalar_one_or_none()
            if not template:
                # Используем синтетический Template-«нюк» с дефолтными привязками —
                # рендер пройдёт по master_skeleton.docx с дефолтными product/country.
                template = Template(
                    code=template_code,
                    kind="docx",
                    title=template_code,
                    content="",
                )

            for tid in target_ids:
                entry: dict[str, Any] = {"target_id": tid, "status": "pending"}
                try:
                    party = await _resolve_party_for_target(session, target_type, tid)
                    if party is None:
                        entry["status"] = "skipped"
                        entry["error"] = f"{target_type} #{tid} не найден"
                        manifest.append(entry)
                        failed_count += 1
                        continue

                    product, country, licensor, contract_data = (
                        await _build_render_context(session, template, party)
                    )
                    safe_name = _safe_filename(party.display_name, tid)
                    docx_path = out_dir / f"{tid}_{safe_name}.docx"
                    render_docx(
                        template_path, product, country, licensor, contract_data, docx_path,
                    )
                    docx_paths.append(docx_path)
                    entry["status"] = "success"
                    entry["file"] = docx_path.name
                    # company_id — новый источник; counterparty_id оставлен (зеркало).
                    entry["company_id"] = party.company_id
                    entry["counterparty_id"] = party.counterparty_id
                    entry["counterparty_name"] = party.display_name
                    manifest.append(entry)
                    success_count += 1
                except Exception as e:  # noqa: BLE001
                    logger.warning(
                        "bulk: failed to render %s#%s: %s",
                        target_type, tid, e,
                    )
                    entry["status"] = "failed"
                    entry["error"] = str(e)[:500]
                    manifest.append(entry)
                    failed_count += 1
    except Exception as e:  # noqa: BLE001
        logger.exception("bulk: fatal error in task %s: %s", task_id, e)
        fatal_error = str(e)[:1000]

    # Пакуем результат, если хоть что-то сгенерилось — даже если были ошибки в части.
    zip_str: str | None = None
    if docx_paths or not fatal_error:
        try:
            pack_zip(zip_path, docx_paths, manifest)
            zip_str = str(zip_path)
        except Exception as e:  # noqa: BLE001
            logger.exception("bulk: failed to pack zip for task %s: %s", task_id, e)
            fatal_error = (fatal_error or "") + f" pack_zip: {e}"

    final_status = (
        "failed" if fatal_error and success_count == 0
        else "success"  # частичный успех — тоже success (failed_count > 0 в записи)
    )

    async with SessionLocal() as session:
        author = await _finalize_task(
            session,
            task_id,
            status=final_status,
            success_count=success_count,
            failed_count=failed_count,
            result_zip_path=zip_str,
            error_text=fatal_error,
        )

    # TG-уведомление автору (best-effort; не критично если бот выключен).
    if author and author.telegram_user_id:
        try:
            await _notify_author(author, task_id, success_count, failed_count, final_status)
        except Exception as e:  # noqa: BLE001
            logger.warning("bulk: TG-уведомление автору %s упало: %s", author.id, e)


async def _notify_author(
    author: User, task_id: int, success_count: int, failed_count: int, status: str,
) -> None:
    """Отправить TG-личку автору с результатом bulk-задачи."""
    settings = get_settings()
    if not settings.telegram_bot_token:
        return
    from app.services.telegram import get_bot
    bot = get_bot()
    if status == "failed":
        text = (
            f"❌ <b>Bulk-генерация #{task_id} провалена</b>\n"
            f"Ничего не сгенерировано. Подробности — в карточке задачи."
        )
    else:
        tail = f" / ошибок {failed_count}" if failed_count else ""
        link = f"<a href='{settings.public_base_url}/bulk-tasks/{task_id}'>Открыть →</a>"
        text = (
            f"✅ <b>Bulk-генерация #{task_id} готова</b>\n"
            f"Сгенерировано документов: <b>{success_count}</b>{tail}\n\n{link}"
        )
    await bot.send_message(chat_id=author.telegram_user_id, text=text)
