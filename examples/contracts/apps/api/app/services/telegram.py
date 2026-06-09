"""Telegram-уведомления и обработка callback'ов от inline-кнопок."""

from __future__ import annotations

import logging
from datetime import UTC, datetime
from typing import Any

import httpx
from aiogram import Bot, Dispatcher, F
from aiogram.client.default import DefaultBotProperties
from aiogram.enums import ParseMode
from aiogram.types import (
    BufferedInputFile,
    CallbackQuery,
    InlineKeyboardButton,
    InlineKeyboardMarkup,
    Message,
)
from sqlalchemy.future import select

from app.config import get_settings
from app.db import SessionLocal
from app.models import (
    Approval,
    ApprovalDecision,
    ApprovalRoute,
    Contract,
    ContractRemark,
    ContractStatus,
    TelegramLinkToken,
    User,
)

logger = logging.getLogger(__name__)
settings = get_settings()

_bot: Bot | None = None
_dp: Dispatcher | None = None


def get_bot() -> Bot:
    global _bot
    if _bot is None:
        if not settings.telegram_bot_token:
            raise RuntimeError("TELEGRAM_BOT_TOKEN не задан")
        _bot = Bot(
            token=settings.telegram_bot_token,
            default=DefaultBotProperties(parse_mode=ParseMode.HTML),
        )
    return _bot


def get_dispatcher() -> Dispatcher:
    global _dp
    if _dp is None:
        _dp = Dispatcher()
        _register_handlers(_dp)
    return _dp


def _register_handlers(dp: Dispatcher) -> None:

    @dp.message(F.text.startswith("/start"))
    async def cmd_start(message: Message):
        tg_id = message.from_user.id if message.from_user else None
        chat_id = message.chat.id
        chat_type = message.chat.type  # private / group / supergroup / channel

        # В группе/супергруппе — сразу даём chat_id (для настройки чата согласований)
        if chat_type in ("group", "supergroup"):
            await message.answer(
                f"👋 Бот <b>MACRO Contracts</b> готов к работе в этом чате.\n\n"
                f"<b>Chat ID:</b> <code>{chat_id}</code>\n"
                f"Тип: {chat_type}\n\n"
                f"Передайте этот Chat ID администратору для настройки уведомлений о согласовании."
            )
            return

        # /start link_<token> — привязка через deep-link из веб-приложения
        text = message.text or ""
        parts = text.split(maxsplit=1)
        if len(parts) == 2 and parts[1].startswith("link_") and tg_id:
            token_str = parts[1].removeprefix("link_")
            await _handle_link_token(message, tg_id, token_str)
            return

        # Личка без токена — привязка пользователя (показать ID)
        async with SessionLocal() as session:
            user = (await session.execute(select(User).where(User.telegram_user_id == tg_id))).scalar_one_or_none()
            if user:
                await message.answer(
                    f"Здравствуйте, <b>{user.full_name}</b>.\n"
                    f"Вы привязаны к учётной записи MACRO Contracts.\n"
                    f"Роль: <b>{user.role.value}</b>\n\n"
                    f"Ваш Telegram ID: <code>{tg_id}</code>"
                )
            else:
                await message.answer(
                    f"Здравствуйте. Ваш Telegram ID: <code>{tg_id}</code>.\n\n"
                    "Зайдите в свой профиль на сайте → раздел «Аккаунт» → "
                    "кнопка «Привязать Telegram». Бот откроется автоматически "
                    "и привяжет вашу учётку."
                )

    @dp.message(F.text.startswith("/members"))
    async def cmd_members(message: Message):
        """Только для админов группы: список tg_id всех админов чата."""
        if message.chat.type not in ("group", "supergroup"):
            await message.answer("Команда работает только в групповом чате")
            return
        try:
            admins = await message.bot.get_chat_administrators(message.chat.id)
        except Exception as e:  # noqa: BLE001
            await message.answer(f"Не удалось получить список: {e}")
            return
        lines = ["<b>Администраторы чата</b>"]
        for a in admins:
            u = a.user
            if u.is_bot:
                continue
            name = u.full_name or "—"
            username = f"@{u.username}" if u.username else ""
            lines.append(f"• {name} {username} → <code>{u.id}</code>")
        lines.append("")
        lines.append("Скопируйте Telegram ID и впишите в карточке пользователя в /admin/users.")
        await message.answer("\n".join(lines))

    @dp.message(F.text.startswith("/chatid"))
    async def cmd_chatid(message: Message):
        await message.answer(
            f"<b>Chat ID:</b> <code>{message.chat.id}</code>\n"
            f"Тип: {message.chat.type}\n"
            f"Название: {message.chat.title or '—'}"
        )

    @dp.message(F.text.startswith("/whoami"))
    async def cmd_whoami(message: Message):
        tg_id = message.from_user.id if message.from_user else None
        username = message.from_user.username if message.from_user else None
        await message.answer(
            f"<b>Ваш Telegram ID:</b> <code>{tg_id}</code>\n"
            f"Username: @{username if username else '—'}\n"
            f"Chat ID этого диалога: <code>{message.chat.id}</code>"
        )

    @dp.callback_query(F.data.startswith("decide:"))
    async def cb_decide(cb: CallbackQuery):
        # callback_data: decide:<contract_id>:<approved|rejected>
        try:
            _, contract_id_s, decision_s = cb.data.split(":")
            contract_id = int(contract_id_s)
            decision = ApprovalDecision(decision_s)
        except (ValueError, AttributeError):
            await cb.answer("Невалидный callback", show_alert=True)
            return

        from app.services.approval_engine import (
            advance_stage_if_needed,
            current_attempt as _current_attempt,
            normalize_stages,
        )
        from app.routers.approval_routes import match_route as _match_route

        tg_id = cb.from_user.id if cb.from_user else None
        notify_next_stage_order: int | None = None
        async with SessionLocal() as session:
            user = (await session.execute(select(User).where(User.telegram_user_id == tg_id))).scalar_one_or_none()
            if not user:
                await cb.answer("⛔ Ваш Telegram не привязан к учётной записи в системе", show_alert=True)
                return

            contract = (await session.execute(select(Contract).where(Contract.id == contract_id))).scalar_one_or_none()
            if not contract:
                await cb.answer("Договор не найден", show_alert=True)
                return
            if contract.status != ContractStatus.in_review:
                await cb.answer(f"Договор не на согласовании (статус: {contract.status.value})", show_alert=True)
                return

            attempt = await _current_attempt(session, contract.id)
            approval = (await session.execute(
                select(Approval)
                .where(Approval.contract_id == contract.id, Approval.user_id == user.id, Approval.attempt == attempt)
                .order_by(Approval.id.desc())
                .limit(1)
            )).scalar_one_or_none()
            if not approval:
                await cb.answer("⛔ Вы не назначены согласователем этого этапа", show_alert=True)
                return
            if approval.decision != ApprovalDecision.pending:
                await cb.answer(f"Вы уже отметили: {approval.decision.value}", show_alert=True)
                return

            if decision == ApprovalDecision.rejected:
                approval.decision = ApprovalDecision.rejected
                approval.decided_at = datetime.now(UTC)
                approval.comment = "(причина не указана)"
                contract.status = ContractStatus.rejected
                # Замечание в чек-лист автора (как в web-пути /decide)
                session.add(ContractRemark(
                    contract_id=contract.id,
                    attempt=approval.attempt,
                    stage_order=approval.stage_order,
                    author_user_id=user.id,
                    text="(причина не указана)",
                ))
                approval_id = approval.id
                await session.commit()
                await cb.answer("❌ Договор отклонён")
                reply_message_id: int | None = None
                if cb.message:
                    try:
                        await cb.message.edit_reply_markup(reply_markup=None)
                        reply_msg = await cb.message.reply(
                            f"❌ <b>{user.full_name}</b> отклонил договор.\n"
                            f"<i>Ответьте на это сообщение причиной отклонения — она появится у автора в карточке.</i>",
                        )
                        reply_message_id = reply_msg.message_id
                    except Exception:  # noqa: BLE001
                        pass
                # Персист маппинга reply→approval в БД (переживает рестарт api)
                if reply_message_id is not None:
                    async with SessionLocal() as s2:
                        appr = (await s2.execute(
                            select(Approval).where(Approval.id == approval_id)
                        )).scalar_one_or_none()
                        if appr:
                            appr.reject_prompt_message_id = reply_message_id
                            await s2.commit()
                try:
                    await notify_author(contract_id, "rejected")
                except Exception:  # noqa: BLE001
                    pass
                return

            # approved
            approval.decision = ApprovalDecision.approved
            approval.decided_at = datetime.now(UTC)
            await session.flush()

            routes = (await session.execute(select(ApprovalRoute))).scalars().all()
            route = _match_route(routes, contract.product_code, contract.country_code)
            stages = normalize_stages(route) if route else []

            if route:
                advanced, new_active = await advance_stage_if_needed(session, contract, route, attempt)
                if new_active >= len(stages):
                    contract.status = ContractStatus.approved
                elif advanced:
                    notify_next_stage_order = stages[new_active]["order"]
            await session.commit()
            final_status = contract.status

        await cb.answer("✅ Согласовано")
        if cb.message:
            try:
                await cb.message.edit_reply_markup(reply_markup=None)
                tail = ""
                if notify_next_stage_order is not None:
                    tail = "\nПередаётся на следующий этап."
                elif final_status == ContractStatus.approved:
                    tail = "\n🎉 Договор полностью согласован."
                await cb.message.reply(f"✅ <b>{user.full_name}</b> согласовал.{tail}")
            except Exception:  # noqa: BLE001
                pass

        if notify_next_stage_order is not None:
            try:
                await notify_approval_request(contract_id, stage_order=notify_next_stage_order)
            except Exception:  # noqa: BLE001
                pass
        elif final_status == ContractStatus.approved:
            try:
                await notify_author(contract_id, "approved")
            except Exception:  # noqa: BLE001
                pass

    @dp.message(F.text & ~F.text.startswith("/"))
    async def handle_nl_text(message: Message) -> None:
        """NL-парсинг свободного текста в приватных чатах бота.

        Только personal-chat (type='private'). Короткие сообщения (<10 символов)
        и сообщения в группах — игнорируем. Ответ-reply — тоже игнорируем
        (он обработается в on_reject_comment_reply ниже).
        """
        if message.chat.type != "private":
            return
        if not message.text or len(message.text.strip()) < 10:
            return
        # Reply-сообщения — пропускаем, они обработаются в on_reject_comment_reply
        if message.reply_to_message:
            return

        tg_user_id = message.from_user.id if message.from_user else None
        if not tg_user_id:
            return

        try:
            response = await _call_intent_api(
                tg_user_id=tg_user_id,
                tg_chat_id=message.chat.id,
                text=message.text.strip(),
            )
            reply_text = response.get("reply_text", "Не удалось обработать запрос.")
            inline_keyboard_data = response.get("inline_keyboard")
            keyboard = _build_aiogram_keyboard(inline_keyboard_data)
            await message.reply(reply_text, reply_markup=keyboard, parse_mode=ParseMode.HTML)
        except Exception as exc:  # noqa: BLE001
            logger.warning("NL intent handler failed for tg_user=%s: %s", tg_user_id, exc)
            await message.reply(
                "Произошла ошибка при обработке запроса. Попробуйте позже."
            )

    @dp.message(F.reply_to_message)
    async def on_reject_comment_reply(message: Message):
        """Если пользователь ответил на наш bot-message о rejected — сохраняем причину
        в Approval.comment и в соответствующее замечание чек-листа."""
        if not message.reply_to_message:
            return
        msg_id = message.reply_to_message.message_id
        text = (message.text or "").strip()[:2000]
        if not text:
            return
        async with SessionLocal() as session:
            approval = (await session.execute(
                select(Approval).where(Approval.reject_prompt_message_id == msg_id)
            )).scalar_one_or_none()
            if not approval:
                return
            approval.comment = text
            approval.reject_prompt_message_id = None  # одноразово
            # Обновляем плейсхолдер замечания на реальную причину
            remark = (await session.execute(
                select(ContractRemark)
                .where(
                    ContractRemark.contract_id == approval.contract_id,
                    ContractRemark.attempt == approval.attempt,
                    ContractRemark.stage_order == approval.stage_order,
                    ContractRemark.author_user_id == approval.user_id,
                )
                .order_by(ContractRemark.created_at.desc())
                .limit(1)
            )).scalar_one_or_none()
            if remark:
                remark.text = text
            await session.commit()
        try:
            await message.reply("💬 Причина отклонения сохранена и видна автору в карточке договора.")
        except Exception:  # noqa: BLE001
            pass


async def _handle_link_token(message: Message, tg_id: int, token_str: str) -> None:
    """Логика привязки tg_id к user через одноразовый токен."""
    async with SessionLocal() as session:
        token_row = (await session.execute(
            select(TelegramLinkToken).where(TelegramLinkToken.token == token_str)
        )).scalar_one_or_none()

        if not token_row:
            await message.answer("❌ Ссылка недействительна. Сгенерируйте новую в профиле на сайте.")
            return

        # aware comparison
        now = datetime.now(UTC)
        expires_at = token_row.expires_at
        if expires_at.tzinfo is None:
            expires_at = expires_at.replace(tzinfo=UTC)
        if token_row.used_at is not None:
            await message.answer("❌ Эта ссылка уже была использована. Сгенерируйте новую.")
            return
        if now > expires_at:
            await message.answer(f"❌ Ссылка истекла (срок 10 минут). Сгенерируйте новую.")
            return

        # Проверим что этот tg_id ещё не привязан к другому
        other = (await session.execute(
            select(User).where(User.telegram_user_id == tg_id, User.id != token_row.user_id)
        )).scalar_one_or_none()
        if other:
            await message.answer(
                f"❌ Этот Telegram уже привязан к другой учётной записи ({other.email}). "
                "Сначала отвяжите его в профиле этой учётки."
            )
            return

        user = (await session.execute(select(User).where(User.id == token_row.user_id))).scalar_one_or_none()
        if not user:
            await message.answer("❌ Учётная запись не найдена")
            return

        user.telegram_user_id = tg_id
        token_row.used_at = now
        await session.commit()

        await message.answer(
            f"✅ Готово! Telegram <code>{tg_id}</code> привязан к учётной записи "
            f"<b>{user.full_name}</b> ({user.email}).\n\n"
            "Теперь вы будете получать уведомления о договорах на согласование "
            "в общий чат и сможете аппрувить их прямо отсюда."
        )


async def notify_approval_request(contract_id: int, stage_order: int | None = None) -> None:
    """Отправить документ в чат согласования с inline-кнопками.

    stage_order задаётся при переходе на следующий этап многоэтапного маршрута —
    тогда в шапку добавляется название этапа.
    """
    if not settings.telegram_bot_token or not settings.telegram_approval_chat_id:
        logger.warning("Telegram approval chat not configured; skipping notify")
        return

    bot = get_bot()
    stage_name: str | None = None
    async with SessionLocal() as session:
        from app.models import Counterparty  # noqa: WPS433
        contract = (await session.execute(select(Contract).where(Contract.id == contract_id))).scalar_one_or_none()
        if not contract:
            return
        author = (await session.execute(select(User).where(User.id == contract.author_user_id))).scalar_one_or_none()
        counterparty = None
        if contract.counterparty_id:
            counterparty = (await session.execute(
                select(Counterparty).where(Counterparty.id == contract.counterparty_id)
            )).scalar_one_or_none()
        if stage_order is not None:
            from app.routers.approval_routes import match_route as _mr
            from app.services.approval_engine import normalize_stages as _ns
            routes = (await session.execute(select(ApprovalRoute))).scalars().all()
            route = _mr(routes, contract.product_code, contract.country_code)
            if route:
                for st in _ns(route):
                    if st.get("order") == stage_order:
                        stage_name = st.get("name")
                        break

    company_name = (counterparty.name if counterparty else None) or contract.title or "Без названия"
    product_map = {"macrocrm": "MacroCRM", "macrosales": "MacroSales", "macroerp": "MACRO (ERP)"}
    country_map = {"kz": "Казахстан", "uz": "Узбекистан"}
    product = product_map.get(contract.product_code, contract.product_code)
    country = country_map.get(contract.country_code, contract.country_code.upper())

    stage_prefix = ""
    if stage_order is not None:
        stage_prefix = (f"➡️ <b>Этап: {stage_name}</b>\n\n" if stage_name
                        else "➡️ <b>Следующий этап согласования</b>\n\n")
    text = (
        f"{stage_prefix}"
        f"📄 <b>Договор на согласование «{company_name}»</b>\n"
        f"№ <code>{contract.number or '(номер не присвоен)'}</code>\n\n"
        f"<b>Страна:</b> {country}\n"
        f"<b>Продукт:</b> {product}\n"
        f"<b>Автор:</b> {author.full_name if author else '—'}\n\n"
        f"<a href='{settings.public_base_url}/contracts/{contract.id}'>Открыть в системе →</a>"
    )
    keyboard = InlineKeyboardMarkup(inline_keyboard=[
        [
            InlineKeyboardButton(text="✅ Согласовать", callback_data=f"decide:{contract.id}:approved"),
            InlineKeyboardButton(text="❌ Отклонить", callback_data=f"decide:{contract.id}:rejected"),
        ],
    ])

    # Отправляем PDF если есть, иначе DOCX, иначе только текст
    sent = None
    if contract.pdf_path:
        with open(contract.pdf_path, "rb") as f:
            sent = await bot.send_document(
                chat_id=settings.telegram_approval_chat_id,
                document=BufferedInputFile(f.read(), filename=f"Договор {contract.number or contract.id}.pdf"),
                caption=text,
                reply_markup=keyboard,
            )
    elif contract.docx_path:
        with open(contract.docx_path, "rb") as f:
            sent = await bot.send_document(
                chat_id=settings.telegram_approval_chat_id,
                document=BufferedInputFile(f.read(), filename=f"Договор {contract.number or contract.id}.docx"),
                caption=text,
                reply_markup=keyboard,
            )
    else:
        sent = await bot.send_message(
            chat_id=settings.telegram_approval_chat_id,
            text=text,
            reply_markup=keyboard,
        )

    if sent:
        async with SessionLocal() as session:
            contract = (await session.execute(select(Contract).where(Contract.id == contract_id))).scalar_one_or_none()
            if contract:
                contract.telegram_message_id = sent.message_id
                await session.commit()


async def notify_author(contract_id: int, kind: str, reason: str | None = None) -> None:
    """Личное уведомление автору договора о результате согласования.

    kind: "approved" | "rejected". Тихо выходит, если у автора не привязан Telegram.
    """
    if not settings.telegram_bot_token:
        return
    async with SessionLocal() as session:
        from app.models import Counterparty  # noqa: WPS433
        contract = (await session.execute(select(Contract).where(Contract.id == contract_id))).scalar_one_or_none()
        if not contract:
            return
        author = (await session.execute(select(User).where(User.id == contract.author_user_id))).scalar_one_or_none()
        if not author or not author.telegram_user_id:
            return
        company = None
        if contract.counterparty_id:
            cp = (await session.execute(
                select(Counterparty).where(Counterparty.id == contract.counterparty_id)
            )).scalar_one_or_none()
            company = cp.name if cp else None
        chat_id = author.telegram_user_id
        number = contract.number or f"#{contract.id}"
        cid = contract.id
        title = company or contract.title or "Без названия"

    link = f"<a href='{settings.public_base_url}/contracts/{cid}'>Открыть карточку →</a>"
    if kind == "approved":
        text = (
            f"🎉 <b>Договор согласован</b>\n"
            f"«{title}» № <code>{number}</code> полностью прошёл согласование.\n\n{link}"
        )
    else:
        reason_line = f"\n<b>Причина:</b> {reason}" if reason else "\nПричина — в чек-листе замечаний карточки."
        text = (
            f"❌ <b>Договор отклонён</b>\n"
            f"«{title}» № <code>{number}</code>.{reason_line}\n\n{link}"
        )
    try:
        await get_bot().send_message(chat_id=chat_id, text=text)
    except Exception:  # noqa: BLE001
        logger.warning("notify_author failed for contract %s", contract_id)


async def _call_intent_api(
    tg_user_id: int,
    tg_chat_id: int,
    text: str,
) -> dict[str, Any]:
    """Вызывает /api/tg-bot/intent внутри того же процесса (bot-сервис).

    Использует httpx async client. Таймаут 30 секунд (Claude может быть медленным).
    """
    base_url = "http://localhost:8000"
    secret = settings.tg_bot_api_secret
    headers: dict[str, str] = {}
    if secret:
        headers["Authorization"] = f"Bearer {secret}"

    async with httpx.AsyncClient(timeout=30.0) as client:
        resp = await client.post(
            f"{base_url}/api/tg-bot/intent",
            json={
                "tg_user_id": tg_user_id,
                "tg_chat_id": tg_chat_id,
                "text": text,
            },
            headers=headers,
        )
        resp.raise_for_status()
        return resp.json()


def _build_aiogram_keyboard(
    inline_keyboard_data: Any,
) -> InlineKeyboardMarkup | None:
    """Конвертирует список inline-кнопок из API-ответа в aiogram InlineKeyboardMarkup."""
    if not inline_keyboard_data or not isinstance(inline_keyboard_data, list):
        return None
    rows: list[list[InlineKeyboardButton]] = []
    for row in inline_keyboard_data:
        if not isinstance(row, list):
            continue
        buttons: list[InlineKeyboardButton] = []
        for btn in row:
            if not isinstance(btn, dict):
                continue
            text_label = btn.get("text", "")
            if not text_label:
                continue
            cb = btn.get("callback_data")
            url = btn.get("url")
            if url:
                buttons.append(InlineKeyboardButton(text=text_label, url=url))
            elif cb:
                buttons.append(InlineKeyboardButton(text=text_label, callback_data=cb))
        if buttons:
            rows.append(buttons)
    if not rows:
        return None
    return InlineKeyboardMarkup(inline_keyboard=rows)


async def start_polling() -> None:
    """Запуск long-polling. Вызывается из main.py при старте FastAPI."""
    if not settings.telegram_bot_token:
        logger.warning("TELEGRAM_BOT_TOKEN не задан, бот не запущен")
        return
    bot = get_bot()
    dp = get_dispatcher()
    logger.info("Telegram bot polling started")
    await dp.start_polling(bot, handle_signals=False)
