"""ORM-модели проекта."""

from __future__ import annotations

import enum
from datetime import date, datetime, time
from decimal import Decimal
from typing import Any

from sqlalchemy import (
    JSON,
    BigInteger,
    Boolean,
    CheckConstraint,
    Date,
    DateTime,
    Enum,
    ForeignKey,
    Index,
    Integer,
    Numeric,
    String,
    Text,
    Time,
    UniqueConstraint,
    func,
    text,
)
from sqlalchemy.dialects.postgresql import ARRAY, JSONB
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.db import Base


class UserRole(str, enum.Enum):
    admin = "admin"
    director = "director"
    lawyer = "lawyer"
    manager = "manager"
    accountant = "accountant"  # бухгалтер: ввод/проведение/ручные журналы (модуль «Финансы»)
    cfo = "cfo"                # финдиректор: + закрытие периода + настройки + «для руководства»


class ContractStatus(str, enum.Enum):
    draft = "draft"
    submitted = "submitted"
    in_review = "in_review"
    # Wave 2a: «На доработке» — подстатус группы «На согласовании». Согласователь
    # вернул договор автору на доработку (мягче чем rejected: договор всё ещё в
    # активном цикле согласования, не «отклонён окончательно»). Не путать с rejected.
    needs_rework = "needs_rework"
    approved = "approved"
    signed = "signed"  # подписан клиентом, сделка проведена (требует скан подписи)
    rejected = "rejected"
    uploaded = "uploaded"  # выгружен в Google Drive
    archived = "archived"


class ApprovalDecision(str, enum.Enum):
    pending = "pending"
    approved = "approved"
    rejected = "rejected"
    # «На доработку»: согласователь вернул договор автору (мягче rejected —
    # договор остаётся в активном цикле). Хранится отдельным значением, чтобы
    # аналитика отличала возврат-на-доработку от жёсткого отклонения.
    needs_rework = "needs_rework"


class TemplateVariableType(str, enum.Enum):
    text = "text"            # однострочный текст
    textarea = "textarea"    # многострочный текст
    number = "number"        # число
    date = "date"            # дата (хранится как ДД.ММ.ГГГГ)
    select = "select"        # выпадающий список (options)
    checkbox = "checkbox"    # да/нет → подставляется как «Да» / «Нет»


class User(Base):
    __tablename__ = "users"

    id: Mapped[int] = mapped_column(primary_key=True)
    email: Mapped[str] = mapped_column(String(255), unique=True, index=True)
    password_hash: Mapped[str] = mapped_column(String(255))
    full_name: Mapped[str] = mapped_column(String(255))
    role: Mapped[UserRole] = mapped_column(Enum(UserRole, name="user_role"))
    telegram_user_id: Mapped[int | None] = mapped_column(BigInteger, unique=True, nullable=True)
    avatar_path: Mapped[str | None] = mapped_column(String(512), nullable=True)
    # Epic 14 — Departments + Visibility ACL.
    # department_id уже был с миграции 0010 (seed_pipeline). manager_id — новый
    # в миграции 0035: прямой руководитель сотрудника (используется в дереве
    # компании, в эскалации overdue курсов, и в будущем — в scope=manager).
    department_id: Mapped[int | None] = mapped_column(ForeignKey("departments.id", ondelete="SET NULL"), nullable=True)
    manager_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True,
    )
    is_active: Mapped[bool] = mapped_column(Boolean, default=True)
    # Эпик 13: уровень опыта с CRM, спрашивается в onboarding wizard на первом
    # логине. Допустимо NULL = пользователь не отвечал/пропустил. Допустимые
    # значения 'none' | 'basic' | 'advanced'; CHECK на БД-уровне.
    crm_experience_level: Mapped[str | None] = mapped_column(String(16), nullable=True)
    # Эпик 13: момент когда юзер dismiss'ил wizard (или прошёл его до конца).
    # NULL = wizard ещё не показывали — фронт показывает modal на первом логине.
    onboarding_dismissed_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True), nullable=True,
    )
    # Epic 16 — Security: 2FA TOTP (RFC 6238).
    # totp_secret_encrypted — Fernet-зашифрованный base32 seed (см.
    # app/services/totp.py::encrypt_secret/decrypt_secret). NULL = TOTP
    # не настроен. После disable стирается → NULL.
    totp_secret_encrypted: Mapped[str | None] = mapped_column(Text, nullable=True)
    # Включён ли 2FA. Меняется только в verify-setup → True; в disable → False.
    # На login если True — выдаём temp_2fa_token cookie вместо access_token.
    totp_enabled: Mapped[bool] = mapped_column(Boolean, default=False)
    # Массив bcrypt-хэшей 8-значных backup кодов. При validate стираем
    # использованный (одноразовость). PG-ARRAY (не JSON) — чтобы можно было
    # array_remove() и array_length() напрямую в SQL.
    totp_backup_codes_hashed: Mapped[list[str] | None] = mapped_column(
        ARRAY(Text), nullable=True,
    )
    # Время первого включения 2FA (для аудита).
    totp_enabled_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True), nullable=True,
    )
    # Epic 21 — UX Profile 2.0.
    # theme_preference: 'system' (default, follow ОС) | 'light' | 'dark'.
    # CHECK на БД-уровне; default обеспечивает что existing rows получают 'system'.
    theme_preference: Mapped[str] = mapped_column(
        String(16), nullable=False, default="system", server_default="system",
    )
    # signature_url: путь / URL к подписи менеджера (PNG/JPG), сохраняется в
    # /uploads/signatures/{user_id}.{ext}. NULL = не загружена.
    signature_url: Mapped[str | None] = mapped_column(Text, nullable=True)
    # locale: язык UI ('ru' default, 'en' stub до отдельного i18n-эпика).
    # CHECK на БД для защиты от опечаток.
    locale: Mapped[str] = mapped_column(
        String(8), nullable=False, default="ru", server_default="ru",
    )
    # job_title: должность сотрудника (для карточки и подписи документов).
    job_title: Mapped[str | None] = mapped_column(String(128), nullable=True)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), onupdate=func.now()
    )

    # Epic 10.5 — Мультивалютная зарплата.
    # salary_currency — валюта в которой менеджер получает зарплату (UZS/RUB/KZT/AED).
    # salary_country_code — ISO 3166-1 alpha-2 страны занятости (KZ/UZ/RU/AE).
    # employment_start_date — дата найма (для расчёта стажа, будущих бонусов).
    salary_currency: Mapped[str | None] = mapped_column(
        String(8), nullable=True, default="RUB", server_default="RUB"
    )
    salary_country_code: Mapped[str | None] = mapped_column(String(2), nullable=True)
    employment_start_date: Mapped[date | None] = mapped_column(Date, nullable=True)

    # ============ Epic 14.2 — Company Management ============
    # dismissed_at — момент увольнения; NULL = активный. Заполняется в
    # admin/users/{id}/dismiss endpoint'е, обнуляется через /restore.
    dismissed_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True), nullable=True,
    )
    # dismissed_by_user_id — admin, проводивший увольнение (для аудита).
    dismissed_by_user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True,
    )
    # dismissed_reason — свободная текстовая причина (compliance).
    dismissed_reason: Mapped[str | None] = mapped_column(Text, nullable=True)
    # substitute_user_id — общий «постоянный» заместитель (на случай отпуска
    # или ad-hoc передачи задач). Для конкретного отпускного периода
    # перебивается UserVacation.substitute_user_id.
    substitute_user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True,
    )
    # employment_status: 'active' | 'dismissed' | 'on_vacation'.
    # Дополняет is_active (которое используется для login). is_active=false
    # после увольнения — login закрыт; on_vacation оставляет is_active=true.
    employment_status: Mapped[str] = mapped_column(
        String(16), nullable=False, default="active", server_default="active",
    )

    # ============ Epic 21.2 — Notification channels ============
    # Master switch для email-канала. Дополняет per-kind preferences:
    # если False — никакие email'ы не уходят независимо от per-kind настроек.
    # default=True (existing rows получают «email включён»). UI «Настройки
    # → Уведомления → Email» меняет.
    email_notifications_enabled: Mapped[bool] = mapped_column(
        Boolean, nullable=False, default=True, server_default="true",
    )
    # Тихий час для TG-канала (например 21:00..09:00 локального TZ юзера).
    # Обе колонки NULLABLE: NULL = quiet hours отключены. Если start > end —
    # окно перекатывается через полночь (21:00..09:00 — типичный кейс).
    # Используется services/notification_dispatcher.is_in_quiet_hours.
    tg_quiet_hours_start: Mapped[time | None] = mapped_column(
        Time, nullable=True,
    )
    tg_quiet_hours_end: Mapped[time | None] = mapped_column(
        Time, nullable=True,
    )
    # Резерв под будущий SMS-канал (Эпик 21.3+). Сейчас не используется
    # dispatch'ем — просто храним если юзер захочет ввести.
    notification_phone: Mapped[str | None] = mapped_column(
        String(32), nullable=True,
    )

    # ============ Wave 2a — Customizable dashboard ============
    # dashboard_config — per-user JSON конфиг дашборда (видимость + порядок
    # виджетов). Freeform blob: схему владеет фронт (например
    # [{"id": "...", "visible": bool, "order": int}, ...] или объект-словарь).
    # Бэкенд только хранит/валидирует «это JSON object/array, ≤ 32КБ». NULL =
    # дефолтная раскладка (фронт показывает все виджеты в дефолтном порядке).
    dashboard_config: Mapped[Any | None] = mapped_column(JSONB, nullable=True)

    # Relationships для self-FK (нужны явные foreign_keys=[...] чтобы не
    # ловить AmbiguousForeignKeysError — manager_id + substitute_user_id +
    # dismissed_by_user_id все указывают на users.id).
    dismissed_by: Mapped[User | None] = relationship(  # type: ignore[name-defined]
        "User",
        foreign_keys="User.dismissed_by_user_id",
        remote_side="User.id",
        post_update=True,
    )
    substitute: Mapped[User | None] = relationship(  # type: ignore[name-defined]
        "User",
        foreign_keys="User.substitute_user_id",
        remote_side="User.id",
        post_update=True,
    )

    # Epic 16 — Security: связь с OAuth провайдерами (Google/Yandex).
    # cascade удаляется вместе с user.
    sso_links: Mapped[list[UserSSOLink]] = relationship(  # type: ignore[name-defined]
        back_populates="user",
        cascade="all, delete-orphan",
        foreign_keys="UserSSOLink.user_id",
    )


class Counterparty(Base):
    """Контрагент-клиент. Сохраняется отдельно, чтобы не вводить реквизиты повторно."""

    __tablename__ = "counterparties"

    id: Mapped[int] = mapped_column(primary_key=True)
    name: Mapped[str] = mapped_column(String(255), index=True)
    full_legal_form: Mapped[str | None] = mapped_column(String(255), nullable=True)
    legal_form: Mapped[str | None] = mapped_column(String(64), nullable=True)
    gender_ending_oe: Mapped[str | None] = mapped_column(String(16), nullable=True, default="ое")

    country_code: Mapped[str] = mapped_column(String(2))  # kz / uz
    city: Mapped[str | None] = mapped_column(String(128), nullable=True)  # для фильтрации в реестре CS

    director_position: Mapped[str | None] = mapped_column(String(128), nullable=True)
    director_genitive: Mapped[str | None] = mapped_column(String(255), nullable=True)
    director_short: Mapped[str | None] = mapped_column(String(128), nullable=True)
    acts_basis: Mapped[str | None] = mapped_column(String(64), nullable=True, default="Устава")

    tax_id_label: Mapped[str | None] = mapped_column(String(16), nullable=True)
    tax_id: Mapped[str | None] = mapped_column(String(64), nullable=True)

    address: Mapped[str | None] = mapped_column(Text, nullable=True)

    bank: Mapped[str | None] = mapped_column(String(255), nullable=True)
    bank_code_label: Mapped[str | None] = mapped_column(String(32), nullable=True)
    bank_code: Mapped[str | None] = mapped_column(String(64), nullable=True)
    account: Mapped[str | None] = mapped_column(String(64), nullable=True)

    phone: Mapped[str | None] = mapped_column(String(64), nullable=True)
    email: Mapped[str | None] = mapped_column(String(255), nullable=True)
    website: Mapped[str | None] = mapped_column(String(255), nullable=True)

    notes: Mapped[str | None] = mapped_column(Text, nullable=True)

    # Холдинг (группа юрлиц) — оборот агрегируется по группе; пусто = сам по себе
    group_id: Mapped[int | None] = mapped_column(ForeignKey("client_groups.id", ondelete="SET NULL"), nullable=True, index=True)
    # Эффективная категория (своя или унаследованная от группы) + кеш оборота в ₽
    category_code: Mapped[str | None] = mapped_column(String(8), nullable=True, index=True)
    turnover_rub: Mapped[Decimal | None] = mapped_column(Numeric(18, 2), nullable=True)
    category_recalc_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)

    # Эпик 10: ответственный менеджер за этого клиента. Заполняется явно (не
    # инферится от сделок) — это устраняет fallback в карточке КА «owner свежей
    # сделки = ответственный», который ломался когда сделок нет или их много.
    # ON DELETE SET NULL — при увольнении пользователь дропается из users,
    # ответственный обнуляется. Назначение нового — отдельной PATCH-операцией.
    responsible_user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True, index=True,
    )

    # Epic 14 — Departments + Visibility ACL.
    # owner_user_id (≠ responsible_user_id) — владелец для scope-фильтра
    # видимости (personal=owner_user_id=user.id). Семантически: «кто завёл/
    # ведёт клиента в CRM» (для scope), responsible — «к кому идти за вопросом»
    # (для UI). Часто совпадают, но не обязательно.
    owner_user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True,
    )
    # Epic 14: отдел клиента (для scope=department / department_and_children).
    # Автозаполняется из owner.department_id при create/update (если не задано).
    department_id: Mapped[int | None] = mapped_column(
        ForeignKey("departments.id", ondelete="SET NULL"), nullable=True,
    )

    # Эпик 8: кастомные поля (CustomFieldDef.scope='counterparty'). Ключ — code дефиниции.
    extra_fields: Mapped[dict[str, Any]] = mapped_column(JSONB, default=dict)

    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), onupdate=func.now()
    )

    __table_args__ = (
        Index("ix_counterparty_country", "country_code"),
    )


class ContractNumberSequence(Base):
    """Счётчик номеров договоров — порядковый № по (city_code, country_code)."""

    __tablename__ = "contract_number_sequences"

    id: Mapped[int] = mapped_column(primary_key=True)
    city_code: Mapped[str] = mapped_column(String(8))  # ТШК, АСТ и т.п.
    country_code: Mapped[str] = mapped_column(String(2))  # KZ / UZ
    start_number: Mapped[int] = mapped_column(Integer, default=220)
    current_number: Mapped[int] = mapped_column(Integer, default=220)

    __table_args__ = (
        UniqueConstraint("city_code", "country_code", name="uq_seq_city_country"),
    )


class Contract(Base):
    __tablename__ = "contracts"

    id: Mapped[int] = mapped_column(primary_key=True)
    number: Mapped[str | None] = mapped_column(String(64), index=True, nullable=True)  # ТШК-219/UZ
    title: Mapped[str | None] = mapped_column(String(512), nullable=True)

    product_code: Mapped[str] = mapped_column(String(32))   # macrocrm / macrosales / macroerp
    country_code: Mapped[str] = mapped_column(String(2))    # kz / uz
    city: Mapped[str | None] = mapped_column(String(128), nullable=True)
    city_code: Mapped[str | None] = mapped_column(String(8), nullable=True)

    counterparty_id: Mapped[int | None] = mapped_column(
        ForeignKey("counterparties.id", ondelete="RESTRICT"), nullable=True, index=True
    )
    counterparty: Mapped[Counterparty | None] = relationship()
    # CONTACTS 2.0 Ф0: новая сторона-компания (data-migration по маппингу).
    company_id: Mapped[int | None] = mapped_column(
        ForeignKey("crm_companies.id", ondelete="SET NULL"), nullable=True, index=True,
    )

    author_user_id: Mapped[int] = mapped_column(
        ForeignKey("users.id", ondelete="RESTRICT"), index=True
    )
    author: Mapped[User] = relationship()

    status: Mapped[ContractStatus] = mapped_column(
        Enum(ContractStatus, name="contract_status"), default=ContractStatus.draft
    )

    # Полный jsonb-контекст переменных (license, payments, acts, custom fields)
    context: Mapped[dict[str, Any]] = mapped_column(JSONB, default=dict)

    # Версия master_skeleton, по которой сгенерирован документ (git sha или semver)
    template_version: Mapped[str | None] = mapped_column(String(64), nullable=True)

    # Файлы
    docx_path: Mapped[str | None] = mapped_column(String(512), nullable=True)
    pdf_path: Mapped[str | None] = mapped_column(String(512), nullable=True)
    drive_folder_url: Mapped[str | None] = mapped_column(String(1024), nullable=True)
    drive_docx_url: Mapped[str | None] = mapped_column(String(1024), nullable=True)
    drive_pdf_url: Mapped[str | None] = mapped_column(String(1024), nullable=True)

    # Telegram
    telegram_message_id: Mapped[int | None] = mapped_column(BigInteger, nullable=True)

    # Архивация: непустое = в архиве (скрыт из активного реестра). Не теряет status.
    archived_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    # Сделка проведена: дата перехода в статус signed (есть скан подписи клиента)
    signed_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)

    # Прайс/итог договора (позиции — в ContractItem). Скидка — на итог (order-level %).
    currency: Mapped[str | None] = mapped_column(String(8), nullable=True)  # одна валюта на договор
    subtotal: Mapped[Decimal] = mapped_column(Numeric(18, 2), default=0)
    discount_pct: Mapped[Decimal] = mapped_column(Numeric(5, 2), default=0)
    discount_amount: Mapped[Decimal] = mapped_column(Numeric(18, 2), default=0)
    total: Mapped[Decimal] = mapped_column(Numeric(18, 2), default=0)
    # ₽-снимок на дату подписания (для категорий клиентов). Заполняется в /sign.
    total_rub: Mapped[Decimal | None] = mapped_column(Numeric(18, 2), nullable=True)
    fx_rate: Mapped[Decimal | None] = mapped_column(Numeric(18, 6), nullable=True)
    fx_rate_date: Mapped[date | None] = mapped_column(Date, nullable=True)

    # Эпик 8: кастомные поля (CustomFieldDef.scope='contract')
    extra_fields: Mapped[dict[str, Any]] = mapped_column(JSONB, default=dict)

    # Эпик 18: кэш AI-анализа договора. NULL = ещё не анализировался.
    # Структура: {"issues":[...], "standard_sections":[...], "recommendations":[...],
    # "model": "...", "ai_tokens_used": N}. force_refresh пересчитывает.
    ai_analysis_json: Mapped[dict[str, Any] | None] = mapped_column(JSONB, nullable=True)
    # Эпик 18: момент последнего AI-анализа. UI рендерит «N мин назад».
    ai_analyzed_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True), nullable=True,
    )

    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), onupdate=func.now()
    )

    __table_args__ = (
        Index("ix_contract_status", "status"),
        Index("ix_contract_product_country", "product_code", "country_code"),
    )


class ContractAttachment(Base):
    """Файлы, привязанные к договору: скан с подписью клиента, платёжки и т.п.
    Скан подписи (kind=signed_scan) — условие перевода договора в статус signed."""

    __tablename__ = "contract_attachments"

    id: Mapped[int] = mapped_column(primary_key=True)
    contract_id: Mapped[int] = mapped_column(ForeignKey("contracts.id", ondelete="CASCADE"), index=True)
    kind: Mapped[str] = mapped_column(String(32), default="signed_scan")  # signed_scan / payment / other
    path: Mapped[str] = mapped_column(String(512))
    original_name: Mapped[str | None] = mapped_column(String(255), nullable=True)
    content_type: Mapped[str | None] = mapped_column(String(128), nullable=True)
    uploaded_by_user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True
    )
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())


# ============ Продукты и прайс ============

class Product(Base):
    """Продукт из прайса MACRO. Цены — в ProductPrice (мультивалюта); тарифы/пакеты — ProductPlan."""

    __tablename__ = "products"

    id: Mapped[int] = mapped_column(primary_key=True)
    code: Mapped[str] = mapped_column(String(64), unique=True, index=True)  # slug
    name: Mapped[str] = mapped_column(String(255))
    description: Mapped[str | None] = mapped_column(Text, nullable=True)
    group: Mapped[str | None] = mapped_column(String(128), nullable=True)  # «MACRO AI», «Сервисы»… (legacy строка)
    # Wave 3: управляемая группа (ProductGroup). group (строка) держим в синхроне для отображения.
    group_id: Mapped[int | None] = mapped_column(
        ForeignKey("product_groups.id", ondelete="SET NULL"), nullable=True, index=True,
    )
    # fixed — годовая фикс-цена; tiered/per_minute/package — через ProductPlan; custom — «под проект»
    pricing_type: Mapped[str] = mapped_column(String(16), default="fixed")
    maps_to_product_code: Mapped[str | None] = mapped_column(String(32), nullable=True)  # для выбора шаблона
    is_active: Mapped[bool] = mapped_column(Boolean, default=True)
    sort_order: Mapped[int] = mapped_column(Integer, default=0)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), onupdate=func.now()
    )


class ProductPlan(Base):
    """Тариф/пакет продукта (pricing_type tiered/per_minute/package). Напр. «Start (100 минут)»."""

    __tablename__ = "product_plans"

    id: Mapped[int] = mapped_column(primary_key=True)
    product_id: Mapped[int] = mapped_column(ForeignKey("products.id", ondelete="CASCADE"), index=True)
    name: Mapped[str] = mapped_column(String(255))
    unit: Mapped[str] = mapped_column(String(32), default="year")  # year/one_time/minute/package
    sort_order: Mapped[int] = mapped_column(Integer, default=0)


class ProductPrice(Base):
    """Цена продукта (или его тарифа plan_id) в конкретной валюте."""

    __tablename__ = "product_prices"

    id: Mapped[int] = mapped_column(primary_key=True)
    product_id: Mapped[int] = mapped_column(ForeignKey("products.id", ondelete="CASCADE"), index=True)
    plan_id: Mapped[int | None] = mapped_column(ForeignKey("product_plans.id", ondelete="CASCADE"), nullable=True)
    currency: Mapped[str] = mapped_column(String(8))  # KZT/UZS/AED/USD/RUB
    amount: Mapped[Decimal] = mapped_column(Numeric(18, 2))

    __table_args__ = (
        UniqueConstraint("product_id", "plan_id", "currency", name="uq_product_price"),
    )


class ContractItem(Base):
    """Позиция договора: продукт/тариф с ценой-снимком и количеством."""

    __tablename__ = "contract_items"

    id: Mapped[int] = mapped_column(primary_key=True)
    contract_id: Mapped[int] = mapped_column(ForeignKey("contracts.id", ondelete="CASCADE"), index=True)
    product_id: Mapped[int] = mapped_column(
        ForeignKey("products.id", ondelete="RESTRICT"), index=True
    )
    plan_id: Mapped[int | None] = mapped_column(
        ForeignKey("product_plans.id", ondelete="SET NULL"), nullable=True
    )
    name_snapshot: Mapped[str] = mapped_column(String(255))  # имя продукта/тарифа на момент добавления
    currency: Mapped[str] = mapped_column(String(8))
    qty: Mapped[Decimal] = mapped_column(Numeric(18, 2), default=1)
    unit_price: Mapped[Decimal] = mapped_column(Numeric(18, 2))  # СНИМОК цены из прайса
    line_total: Mapped[Decimal] = mapped_column(Numeric(18, 2))
    sort_order: Mapped[int] = mapped_column(Integer, default=0)


class Setting(Base):
    """Key/value настройки (напр. лимит скидки manager_max_discount_pct)."""

    __tablename__ = "settings"

    key: Mapped[str] = mapped_column(String(64), primary_key=True)
    value: Mapped[str | None] = mapped_column(String(512), nullable=True)
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), onupdate=func.now()
    )


# ============ Категории клиентов ============

class ClientGroup(Base):
    """Холдинг — группа контрагентов (юрлиц), оборот которой агрегируется для категории."""

    __tablename__ = "client_groups"

    id: Mapped[int] = mapped_column(primary_key=True)
    name: Mapped[str] = mapped_column(String(255))
    category_code: Mapped[str | None] = mapped_column(String(8), nullable=True)
    turnover_rub: Mapped[Decimal | None] = mapped_column(Numeric(18, 2), nullable=True)
    category_recalc_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())


class ClientCategory(Base):
    """Категория клиента по годовому обороту в ₽ (L/M/S1/S2). Конструктор в админке."""

    __tablename__ = "client_categories"

    id: Mapped[int] = mapped_column(primary_key=True)
    code: Mapped[str] = mapped_column(String(8), unique=True, index=True)  # L/M/S1/S2
    name: Mapped[str] = mapped_column(String(128))
    group: Mapped[str | None] = mapped_column(String(16), nullable=True)  # «S» для S1/S2
    min_amount: Mapped[Decimal] = mapped_column(Numeric(18, 2), default=0)  # ₽, включительно
    max_amount: Mapped[Decimal | None] = mapped_column(Numeric(18, 2), nullable=True)  # ₽, исключ.; null = ∞
    options: Mapped[list[str]] = mapped_column(JSON, default=list)  # перки/SLA (legacy list[str])
    color: Mapped[str | None] = mapped_column(String(16), nullable=True)
    sort_order: Mapped[int] = mapped_column(Integer, default=0)
    is_active: Mapped[bool] = mapped_column(Boolean, default=True)
    # Эпик 23: визуальный конструктор категорий.
    # description — RU-текст «Кого относим к этой категории».
    description: Mapped[str | None] = mapped_column(Text, nullable=True)
    # options_meta — JSONB объект для расширенных опций. Отдельная колонка от
    # legacy `options` list[str] (не break существующий контракт фронта).
    # Структура свободная, например: {"sla_hours": 4, "auto_owner": "round_robin"}.
    options_meta: Mapped[dict[str, Any]] = mapped_column(
        JSONB, default=dict, server_default="{}"
    )


class FxRate(Base):
    """Курс валюты к рублю на дату (кеш ЦБ РФ). 1 ед. валюты = rate_to_rub ₽."""

    __tablename__ = "fx_rates"

    id: Mapped[int] = mapped_column(primary_key=True)
    rate_date: Mapped[date] = mapped_column(Date, index=True)
    currency: Mapped[str] = mapped_column(String(8))
    rate_to_rub: Mapped[Decimal] = mapped_column(Numeric(18, 6))

    __table_args__ = (
        UniqueConstraint("rate_date", "currency", name="uq_fx_date_currency"),
    )


# ============ CRM-карточка клиента (legacy, до Эпика 1.2) ============
# Это таблица контактов внутри карточки Counterparty (Фаза 3a, миграция 0013).
# В Эпике 1.2 заводим отдельные сущности Contact + Company (см. ниже): они живут
# в собственных таблицах `crm_contacts` / `crm_companies` и не пересекаются с этой.
# Здесь оставляем класс под именем LegacyContact, чтобы освободить имя `Contact`
# для новой сущности, но сохранить совместимость с миграцией 0013 и роутером
# /api/counterparties/{cp_id}/contacts.

class LegacyContact(Base):
    """Контактное лицо клиента (несколько на контрагента) — legacy CRM-карточка."""

    __tablename__ = "contacts"

    id: Mapped[int] = mapped_column(primary_key=True)
    counterparty_id: Mapped[int] = mapped_column(ForeignKey("counterparties.id", ondelete="CASCADE"), index=True)
    name: Mapped[str] = mapped_column(String(255))
    position: Mapped[str | None] = mapped_column(String(128), nullable=True)
    phone: Mapped[str | None] = mapped_column(String(64), nullable=True)
    email: Mapped[str | None] = mapped_column(String(255), nullable=True)
    messenger: Mapped[str | None] = mapped_column(String(128), nullable=True)
    is_primary: Mapped[bool] = mapped_column(Boolean, default=False)
    note: Mapped[str | None] = mapped_column(Text, nullable=True)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())


class ClientNote(Base):
    """Заметка по клиенту (лента активности)."""

    __tablename__ = "client_notes"

    id: Mapped[int] = mapped_column(primary_key=True)
    counterparty_id: Mapped[int] = mapped_column(ForeignKey("counterparties.id", ondelete="CASCADE"), index=True)
    author_user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True
    )
    text: Mapped[str] = mapped_column(Text)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())


class ClientTask(Base):
    """Задача по клиенту (тип — как в воронке: звонок/встреча/КП/договор/…)."""

    __tablename__ = "client_tasks"

    id: Mapped[int] = mapped_column(primary_key=True)
    counterparty_id: Mapped[int] = mapped_column(ForeignKey("counterparties.id", ondelete="CASCADE"), index=True)
    deal_id: Mapped[int | None] = mapped_column(ForeignKey("deals.id", ondelete="CASCADE"), nullable=True, index=True)
    title: Mapped[str] = mapped_column(String(255))
    description: Mapped[str | None] = mapped_column(Text, nullable=True)
    task_type: Mapped[str] = mapped_column(String(32), default="other")  # call/meeting/proposal/contract/followup/other
    assignee_user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True, index=True
    )
    due_date: Mapped[date | None] = mapped_column(Date, nullable=True)
    status: Mapped[str] = mapped_column(String(16), default="open")  # open / done
    done_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    created_by_user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True
    )
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())


# ============ Воронки и сделки ============

class Department(Base):
    """Отдел компании (ОП, Внедрение, Тех.поддержка). Пользователь привязан через User.department_id.

    Epic 14: расширено до иерархии (parent_id) + head_user_id + soft-delete (is_active).
    Используется в access_control.py для scope='department_and_children'.
    """

    __tablename__ = "departments"

    id: Mapped[int] = mapped_column(primary_key=True)
    name: Mapped[str] = mapped_column(String(128))
    sort_order: Mapped[int] = mapped_column(Integer, default=0)
    # Epic 14: дерево отделов. NULL = корневой отдел.
    parent_id: Mapped[int | None] = mapped_column(
        ForeignKey("departments.id", ondelete="SET NULL"), nullable=True,
    )
    # Epic 14: руководитель отдела (директор/ROP/PM-Lead). Используется для
    # эскалации overdue курсов (Эпик 13) и в визуализации дерева.
    head_user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True,
    )
    # Epic 14: soft-delete. При is_active=false отдел скрыт из выборки, но
    # FK на users.department_id не теряем (юзеры остаются с привязкой).
    is_active: Mapped[bool] = mapped_column(Boolean, default=True)
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(),
    )
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), onupdate=func.now(),
    )

    # Relationships — для tree-view и list members.
    parent: Mapped[Department | None] = relationship(
        "Department",
        remote_side="Department.id",
        foreign_keys=[parent_id],
        back_populates="children",
    )
    children: Mapped[list[Department]] = relationship(
        "Department",
        foreign_keys=[parent_id],
        back_populates="parent",
    )
    head_user: Mapped[User | None] = relationship(
        "User",
        foreign_keys=[head_user_id],
    )


class Pipeline(Base):
    """Воронка сделок. V1 — одна общая; entity оставлен для расширения."""

    __tablename__ = "pipelines"

    id: Mapped[int] = mapped_column(primary_key=True)
    name: Mapped[str] = mapped_column(String(128))
    # Назначение воронки: sales — воронка продаж (доска /deals); lifecycle — жизненный
    # цикл клиента (этапы B/A/C, рендерится в реестре CS, НЕ показывается в /deals).
    kind: Mapped[str] = mapped_column(String(16), default="sales")
    is_active: Mapped[bool] = mapped_column(Boolean, default=True)
    sort_order: Mapped[int] = mapped_column(Integer, default=0)
    # ============ DEALS 2.0 (Ф1b) — настройки и видимость воронки ============
    # settings — канон-ключи: auto_assign (авто-распределение «Неразобранное»),
    # duplicate_check_enabled, duplicate_check_fields (см.
    # services/deals_v2.normalize_pipeline_settings).
    settings: Mapped[dict[str, Any]] = mapped_column(
        JSONB, nullable=False, default=dict, server_default="{}"
    )
    # visible_role — если задано, воронка видна только этой роли. NULL = всем.
    visible_role: Mapped[str | None] = mapped_column(String(16), nullable=True)
    # visible_user_ids — whitelist пользователей (пусто = всем).
    visible_user_ids: Mapped[list[int]] = mapped_column(
        JSON, nullable=False, default=list, server_default="[]"
    )


class PipelineStage(Base):
    """Этап воронки. Видимость per-этап: расшарен на отделы/пользователей (пусто = виден всем)."""

    __tablename__ = "pipeline_stages"

    id: Mapped[int] = mapped_column(primary_key=True)
    pipeline_id: Mapped[int] = mapped_column(ForeignKey("pipelines.id", ondelete="CASCADE"), index=True)
    name: Mapped[str] = mapped_column(String(128))
    # Машинный код этапа (напр. B0/A1/C0 в воронке «Жизненный цикл») — по нему health-джоб
    # сопоставляет вычисленный тир активности этапу. Пусто у обычных воронок.
    code: Mapped[str | None] = mapped_column(String(16), nullable=True, index=True)
    sort_order: Mapped[int] = mapped_column(Integer, default=0)
    color: Mapped[str | None] = mapped_column(String(16), nullable=True)
    is_won: Mapped[bool] = mapped_column(Boolean, default=False)
    is_lost: Mapped[bool] = mapped_column(Boolean, default=False)
    is_active: Mapped[bool] = mapped_column(Boolean, default=True)
    responsible_user_ids: Mapped[list[int]] = mapped_column(JSON, default=list)
    task_types: Mapped[list[str]] = mapped_column(JSON, default=list)
    visible_department_ids: Mapped[list[int]] = mapped_column(JSON, default=list)  # пусто = всем
    visible_user_ids: Mapped[list[int]] = mapped_column(JSON, default=list)
    # Эпик 23: визуальный конструктор воронок.
    # description — текст для правой панели «карточка этапа» (RU).
    description: Mapped[str | None] = mapped_column(Text, nullable=True)
    # sla_hours — SLA в часах. Используется в idle_in_stage_scanner для
    # автоматического breach-notification (Эпик 19). NULL = SLA не настроен.
    sla_hours: Mapped[int | None] = mapped_column(Integer, nullable=True)
    # default_task_category_id — категория автоматически создаваемых задач при
    # entering этапа. FK на task_categories появится в Эпике 24; сейчас просто
    # nullable Integer (нет CASCADE/SET NULL constraint на уровне БД).
    default_task_category_id: Mapped[int | None] = mapped_column(
        Integer, nullable=True
    )

    # ============ DEALS 2.0 (Ф0) — расширение этапов ============
    # hidden_by_default — этап скрыт на канбане по умолчанию (тумблер «показать
    # скрытые» в фильтрах). Для «Сделка проиграна» / «Холодные (заморозка)».
    hidden_by_default: Mapped[bool] = mapped_column(
        Boolean, nullable=False, default=False, server_default="false"
    )
    # parent_stage_id — родительский этап для подстатусов (напр. «Ожидаем
    # оплату»/«Оплачено» под «Успешная сделка»). NULL = верхнеуровневый этап.
    parent_stage_id: Mapped[int | None] = mapped_column(
        ForeignKey("pipeline_stages.id", ondelete="SET NULL"), nullable=True, index=True
    )
    # stage_features — фичи этапа (whitelist в services/deals_v2.STAGE_FEATURES_WHITELIST):
    # 'send_presentation' | 'meeting_report' | 'generate_document'.
    stage_features: Mapped[list[str]] = mapped_column(JSON, default=list)
    # allowed_task_category_ids — список доступных типов задач этапа
    # (id из task_categories). Пусто = разрешены все категории.
    allowed_task_category_ids: Mapped[list[int]] = mapped_column(JSON, default=list)
    # won_gate — требовать для перехода в этот этап наличие signed_scan ИЛИ
    # зафиксированной оплаты по договору сделки. Включён на «Успешная сделка».
    won_gate: Mapped[bool] = mapped_column(
        Boolean, nullable=False, default=False, server_default="false"
    )

    # DEALS 2.0: self-FK на родительский этап (подстатусы).
    parent_stage: Mapped[PipelineStage | None] = relationship(
        "PipelineStage",
        remote_side="PipelineStage.id",
        foreign_keys=[parent_stage_id],
        back_populates="child_stages",
    )
    child_stages: Mapped[list[PipelineStage]] = relationship(
        "PipelineStage",
        foreign_keys=[parent_stage_id],
        back_populates="parent_stage",
    )


class Deal(Base):
    """Сделка в воронке."""

    __tablename__ = "deals"

    id: Mapped[int] = mapped_column(primary_key=True)
    pipeline_id: Mapped[int] = mapped_column(
        ForeignKey("pipelines.id", ondelete="RESTRICT"), index=True
    )
    stage_id: Mapped[int] = mapped_column(
        ForeignKey("pipeline_stages.id", ondelete="RESTRICT"), index=True
    )
    counterparty_id: Mapped[int | None] = mapped_column(ForeignKey("counterparties.id", ondelete="SET NULL"), nullable=True, index=True)
    # CONTACTS 2.0 Ф0: новая сторона-компания (заполняется data-migration'ом
    # по маппингу counterparty_id→company_id). counterparty_id остаётся как
    # safety-зеркало до финального drop.
    company_id: Mapped[int | None] = mapped_column(
        ForeignKey("crm_companies.id", ondelete="SET NULL"), nullable=True, index=True,
    )
    title: Mapped[str] = mapped_column(String(255))
    amount: Mapped[Decimal | None] = mapped_column(Numeric(18, 2), nullable=True)
    currency: Mapped[str | None] = mapped_column(String(8), nullable=True)
    owner_user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True, index=True
    )
    contract_id: Mapped[int | None] = mapped_column(ForeignKey("contracts.id", ondelete="SET NULL"), nullable=True)
    stage_changed_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    closed_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    # Эпик 4.2: amoCRM-стандарт — причина проигрыша. Свободный текст (для совместимости
    # при импорте из AmoCRM, где это произвольная строка). Заполняется на переходе в
    # этап с is_lost=True (валидация в роутере /deals/{id}/move — следующая итерация).
    lost_reason: Mapped[str | None] = mapped_column(Text, nullable=True)
    # Эпик 4.2: Pipedrive-стандарт — целевая дата закрытия. Используется в forecast
    # (Эпик 6) и в idle_in_stage_days для приоритизации.
    expected_close_date: Mapped[date | None] = mapped_column(Date, nullable=True, index=True)
    # Wave 4 (deal-card rework): ожидаемые даты подписания / оплаты. Дополняют
    # expected_close_date (план закрытия). Используются в required-field валидации
    # перехода по этапам и (план) в forecast.
    expected_sign_date: Mapped[date | None] = mapped_column(Date, nullable=True)
    expected_payment_date: Mapped[date | None] = mapped_column(Date, nullable=True)
    # Epic 14: отдел сделки для scope-фильтра. Автозаполняется из
    # owner_user_id.department_id при create/update (если не задано).
    department_id: Mapped[int | None] = mapped_column(
        ForeignKey("departments.id", ondelete="SET NULL"), nullable=True,
    )
    # DEALS 2.0 Ф1a: теги сделки (фильтрация/группировка на доске). PG ARRAY.
    tags: Mapped[list[str]] = mapped_column(
        ARRAY(Text), nullable=False, default=list, server_default="{}"
    )
    # DEALS 2.0 Ф1a: «что смотрят» — свободная строка продукта/направления.
    # Намеренно НЕ FK на products (слаги CRM vs произвольный текст продаж).
    product: Mapped[str | None] = mapped_column(Text, nullable=True)
    # DEALS 2.0 Ф1a: FK на реестр причин отказа (дополняет свободный текст
    # lost_reason). SET NULL при удалении причины.
    lost_reason_id: Mapped[int | None] = mapped_column(
        ForeignKey("lost_reasons.id", ondelete="SET NULL"), nullable=True
    )

    # Эпик 8: кастомные поля (CustomFieldDef.scope='deal')
    extra_fields: Mapped[dict[str, Any]] = mapped_column(JSONB, default=dict)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), onupdate=func.now()
    )


class DealStageHistory(Base):
    """Лог переходов сделки по этапам."""

    __tablename__ = "deal_stage_history"

    id: Mapped[int] = mapped_column(primary_key=True)
    deal_id: Mapped[int] = mapped_column(ForeignKey("deals.id", ondelete="CASCADE"), index=True)
    from_stage_id: Mapped[int | None] = mapped_column(
        ForeignKey("pipeline_stages.id", ondelete="RESTRICT"), nullable=True
    )
    to_stage_id: Mapped[int] = mapped_column(
        ForeignKey("pipeline_stages.id", ondelete="RESTRICT")
    )
    user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True, index=True
    )
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())


class DealProduct(Base):
    """Wave 4: позиция-продукт сделки (line item).

    unit_price — снимок цены (по умолчанию подтягивается из ProductPrice по
    валюте сделки и plan_id, ручной override разрешён). amount = quantity *
    unit_price (денормализация для авто-суммы Deal.amount). currency — снимок
    валюты позиции (обычно = валюта сделки).
    """

    __tablename__ = "deal_products"

    id: Mapped[int] = mapped_column(primary_key=True)
    deal_id: Mapped[int] = mapped_column(
        ForeignKey("deals.id", ondelete="CASCADE"), index=True,
    )
    product_id: Mapped[int] = mapped_column(
        ForeignKey("products.id", ondelete="RESTRICT"), index=True,
    )
    plan_id: Mapped[int | None] = mapped_column(
        ForeignKey("product_plans.id", ondelete="SET NULL"), nullable=True,
    )
    quantity: Mapped[Decimal] = mapped_column(Numeric(18, 2), default=1, server_default="1")
    unit_price: Mapped[Decimal] = mapped_column(Numeric(18, 2))
    currency: Mapped[str] = mapped_column(String(8))
    amount: Mapped[Decimal] = mapped_column(Numeric(18, 2))
    sort_order: Mapped[int] = mapped_column(Integer, default=0, server_default="0")
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())


class DealContact(Base):
    """Wave 4: связь сделка↔контакт (контактные лица сделки).

    При добавлении контакта к сделке также создаётся ContactCompanyLink между
    контактом и company сделки (если у сделки есть company_id) — чтобы контакт
    появился на карточке компании в «Сотрудники».
    """

    __tablename__ = "deal_contacts"

    id: Mapped[int] = mapped_column(primary_key=True)
    deal_id: Mapped[int] = mapped_column(
        ForeignKey("deals.id", ondelete="CASCADE"), index=True,
    )
    contact_id: Mapped[int] = mapped_column(
        ForeignKey("crm_contacts.id", ondelete="CASCADE"), index=True,
    )
    sort_order: Mapped[int] = mapped_column(Integer, default=0, server_default="0")
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())

    __table_args__ = (
        UniqueConstraint("deal_id", "contact_id", name="uq_deal_contact"),
    )


# ============ DEALS 2.0 (Ф0) — справочники и конструкторы ============

class LostReason(Base):
    """Реестр причин отказа (settings → причина при переводе сделки в is_lost-этап).

    Заполняется сидером DEFAULT_LOST_REASONS. Связь со сделкой пока через
    Deal.lost_reason (свободный текст, для совместимости с AmoCRM-импортом);
    в Ф1 добавим Deal.lost_reason_id FK при необходимости.
    """

    __tablename__ = "lost_reasons"

    id: Mapped[int] = mapped_column(primary_key=True)
    name: Mapped[str] = mapped_column(String(255), unique=True, index=True)
    sort_order: Mapped[int] = mapped_column(Integer, default=0)
    is_active: Mapped[bool] = mapped_column(
        Boolean, nullable=False, default=True, server_default="true"
    )
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now()
    )


class PipelineTransition(Base):
    """Межворонночный переход: с этапа одной воронки → этап другой воронки.

    Напр. «Успешная сделка» (Продажи) → «B0» (Жизненный цикл) при наличии
    signed_scan. conditions — JSON-предикаты, проверяемые в Ф1 executor'ом
    (напр. {"require_signed_scan": true, "require_field": "implementation_date"}).
    """

    __tablename__ = "pipeline_transitions"

    id: Mapped[int] = mapped_column(primary_key=True)
    name: Mapped[str] = mapped_column(String(255))
    from_stage_id: Mapped[int] = mapped_column(
        ForeignKey("pipeline_stages.id", ondelete="CASCADE"), index=True
    )
    to_pipeline_id: Mapped[int] = mapped_column(
        ForeignKey("pipelines.id", ondelete="CASCADE"), index=True
    )
    to_stage_id: Mapped[int] = mapped_column(
        ForeignKey("pipeline_stages.id", ondelete="CASCADE")
    )
    # Условия перехода (предикаты). Пусто = переход без ограничений.
    conditions: Mapped[dict[str, Any]] = mapped_column(JSON, default=dict)
    is_active: Mapped[bool] = mapped_column(
        Boolean, nullable=False, default=True, server_default="true"
    )
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now()
    )


class MeetingReportQuestion(Base):
    """Вопрос конструктора отчёта о встрече.

    pipeline_id NULL = глобальный вопрос (для всех воронок); иначе — привязан
    к конкретной воронке. kind: 'text' (свободный ответ) | 'select' (выбор из
    MeetingReportOption). Ответы хранятся в Activity.meeting_report_json.
    """

    __tablename__ = "meeting_report_questions"

    id: Mapped[int] = mapped_column(primary_key=True)
    pipeline_id: Mapped[int | None] = mapped_column(
        ForeignKey("pipelines.id", ondelete="CASCADE"), nullable=True, index=True
    )
    text: Mapped[str] = mapped_column(Text)
    # 'text' | 'select'
    kind: Mapped[str] = mapped_column(String(16), default="text")
    sort_order: Mapped[int] = mapped_column(Integer, default=0)
    is_active: Mapped[bool] = mapped_column(
        Boolean, nullable=False, default=True, server_default="true"
    )
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now()
    )

    options: Mapped[list[MeetingReportOption]] = relationship(
        back_populates="question",
        cascade="all, delete-orphan",
        order_by="MeetingReportOption.sort_order",
    )


class MeetingReportOption(Base):
    """Вариант ответа на вопрос отчёта о встрече (для kind='select')."""

    __tablename__ = "meeting_report_options"

    id: Mapped[int] = mapped_column(primary_key=True)
    question_id: Mapped[int] = mapped_column(
        ForeignKey("meeting_report_questions.id", ondelete="CASCADE"), index=True
    )
    text: Mapped[str] = mapped_column(String(255))
    sort_order: Mapped[int] = mapped_column(Integer, default=0)

    question: Mapped[MeetingReportQuestion] = relationship(back_populates="options")


class ApprovalRoute(Base):
    """Правило согласования: для каких продуктов и стран какие пользователи могут согласовать.

    Если stages не пуст — многоэтапное согласование (stage[1] получает уведомление только
    после полного завершения stage[0] и т.д.). Если пусто — legacy одноэтапное по approver_user_ids.
    """

    __tablename__ = "approval_routes"

    id: Mapped[int] = mapped_column(primary_key=True)
    product_code: Mapped[str] = mapped_column(String(32), default="*")  # legacy
    country_code: Mapped[str | None] = mapped_column(String(2), nullable=True)  # legacy

    product_codes: Mapped[list[str]] = mapped_column(JSON, default=list)
    country_codes: Mapped[list[str]] = mapped_column(JSON, default=list)

    name: Mapped[str] = mapped_column(String(255))
    # Legacy: один этап
    approver_user_ids: Mapped[list[int]] = mapped_column(JSON, default=list)
    min_required: Mapped[int] = mapped_column(Integer, default=1)
    # Многоэтапное согласование: [{order: 0, name: "Юристы", user_ids: [1,2], min_required: 1}, ...]
    stages: Mapped[list[dict]] = mapped_column(JSON, default=list)

    is_active: Mapped[bool] = mapped_column(Boolean, default=True)

    # Эпик 3: привязка маршрута к категории документа. Если задана — маршрут
    # применяется только к шаблонам этой категории; null = общий маршрут.
    # Значения: 'sublicense_main' / 'addendum' / 'notice' / 'act' / 'cancellation'.
    template_category: Mapped[str | None] = mapped_column(String(32), nullable=True)

    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())


class LicensorBankAccount(Base):
    """Дополнительные счета лицензиара (по разным валютам/банкам)."""

    __tablename__ = "licensor_bank_accounts"

    id: Mapped[int] = mapped_column(primary_key=True)
    licensor_id: Mapped[int] = mapped_column(ForeignKey("licensor_entities.id", ondelete="CASCADE"), index=True)
    currency: Mapped[str] = mapped_column(String(8))  # KZT / UZS / USD / EUR / RUB
    bank: Mapped[str] = mapped_column(String(255))
    bank_code_label: Mapped[str] = mapped_column(String(32))
    bank_code: Mapped[str] = mapped_column(String(64))
    account: Mapped[str] = mapped_column(String(64))
    swift: Mapped[str | None] = mapped_column(String(32), nullable=True)
    is_primary: Mapped[bool] = mapped_column(Boolean, default=False)
    note: Mapped[str | None] = mapped_column(String(255), nullable=True)


class LicensorEntity(Base):
    """Наше юр.лицо — лицензиар, привязан к стране. Подставляется в договор автоматически."""

    __tablename__ = "licensor_entities"

    id: Mapped[int] = mapped_column(primary_key=True)
    country_code: Mapped[str] = mapped_column(String(2), unique=True, index=True)  # kz / uz
    is_default: Mapped[bool] = mapped_column(Boolean, default=True)

    legal_form: Mapped[str] = mapped_column(String(64))  # ТОО / ООО
    full_legal_form: Mapped[str] = mapped_column(String(255))
    gender_ending_oe: Mapped[str] = mapped_column(String(16), default="ое")
    name: Mapped[str] = mapped_column(String(255))

    director_position: Mapped[str] = mapped_column(String(128))
    director_short: Mapped[str] = mapped_column(String(128))
    director_genitive: Mapped[str] = mapped_column(String(255))
    acts_basis: Mapped[str] = mapped_column(String(64), default="Устава")

    tax_id_label: Mapped[str] = mapped_column(String(16))
    tax_id: Mapped[str] = mapped_column(String(64))

    address: Mapped[str] = mapped_column(Text)
    bank: Mapped[str] = mapped_column(String(255))
    bank_code_label: Mapped[str] = mapped_column(String(32))
    bank_code: Mapped[str] = mapped_column(String(64))
    account: Mapped[str] = mapped_column(String(64))

    phone: Mapped[str | None] = mapped_column(String(64), nullable=True)
    email: Mapped[str | None] = mapped_column(String(255), nullable=True)
    website: Mapped[str | None] = mapped_column(String(255), nullable=True)
    training_login: Mapped[str | None] = mapped_column(String(255), nullable=True)

    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), onupdate=func.now()
    )
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())


class Template(Base):
    """Шаблоны: master_skeleton (md), product_<code> (yaml), country_<code> (yaml).

    Хранятся в БД для редактирования юристом. При старте если БД пуста — seed из файлов.
    """

    __tablename__ = "templates"

    id: Mapped[int] = mapped_column(primary_key=True)
    code: Mapped[str] = mapped_column(String(64), unique=True, index=True)  # master_skeleton / product_macrocrm / country_kz
    kind: Mapped[str] = mapped_column(String(16))  # "md" / "yaml"
    title: Mapped[str] = mapped_column(String(255))
    content: Mapped[str] = mapped_column(Text)
    version: Mapped[int] = mapped_column(Integer, default=1)
    updated_by_user_id: Mapped[int | None] = mapped_column(ForeignKey("users.id"), nullable=True)

    # Эпик 3: категория документа (sublicense_main / addendum / notice / act / cancellation).
    # null допустим для служебных YAML-шаблонов (product_*, country_*), которые сами по себе
    # не являются «договором», а служат данными для подстановки.
    category: Mapped[str | None] = mapped_column(String(32), nullable=True, index=True)
    # Эпик 3: привязки. Пустой список = «подходит ко всему» (wildcard на /contracts/new).
    product_codes: Mapped[list[str]] = mapped_column(JSON, default=list)
    country_codes: Mapped[list[str]] = mapped_column(JSON, default=list)
    client_category_codes: Mapped[list[str]] = mapped_column(JSON, default=list)
    department_ids: Mapped[list[int]] = mapped_column(JSON, default=list)

    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), onupdate=func.now()
    )
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())


class TemplateVariable(Base):
    """Кастомная переменная шаблона, заданная юристом.

    В master_skeleton.docx используется как {{ custom.<key> }}.
    Значение хранится в Contract.context['custom'][key] и заполняется
    в карточке договора через динамически построенную форму.
    """

    __tablename__ = "template_variables"

    id: Mapped[int] = mapped_column(primary_key=True)
    key: Mapped[str] = mapped_column(String(64), unique=True, index=True)  # → {{ custom.<key> }}
    label: Mapped[str] = mapped_column(String(255))
    help_text: Mapped[str | None] = mapped_column(String(512), nullable=True)
    var_type: Mapped[TemplateVariableType] = mapped_column(
        Enum(TemplateVariableType, name="template_variable_type"),
        default=TemplateVariableType.text,
    )
    options: Mapped[list[str]] = mapped_column(JSON, default=list)  # для select
    default_value: Mapped[str | None] = mapped_column(String(512), nullable=True)
    required: Mapped[bool] = mapped_column(Boolean, default=False)
    group: Mapped[str | None] = mapped_column(String(128), nullable=True)  # секция в форме
    sort_order: Mapped[int] = mapped_column(Integer, default=0)
    # Область видимости. Пусто = на всех договорах (v1 не выводит это в UI).
    product_codes: Mapped[list[str]] = mapped_column(JSON, default=list)
    country_codes: Mapped[list[str]] = mapped_column(JSON, default=list)
    is_active: Mapped[bool] = mapped_column(Boolean, default=True)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), onupdate=func.now()
    )


class Approval(Base):
    """Голос одного аппрувера по одному договору в рамках одной попытки и этапа."""

    __tablename__ = "approvals"

    id: Mapped[int] = mapped_column(primary_key=True)
    contract_id: Mapped[int] = mapped_column(ForeignKey("contracts.id", ondelete="CASCADE"), index=True)
    user_id: Mapped[int] = mapped_column(
        ForeignKey("users.id", ondelete="RESTRICT"), index=True
    )
    stage_order: Mapped[int] = mapped_column(Integer, default=0)
    attempt: Mapped[int] = mapped_column(Integer, default=1)  # инкремент при повторной отправке
    decision: Mapped[ApprovalDecision] = mapped_column(
        Enum(ApprovalDecision, name="approval_decision"), default=ApprovalDecision.pending
    )
    comment: Mapped[str | None] = mapped_column(Text, nullable=True)
    decided_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    # id сообщения бота с просьбой указать причину отклонения (для reply-флоу в Telegram).
    # Раньше хранилось в памяти процесса — терялось при рестарте api.
    reject_prompt_message_id: Mapped[int | None] = mapped_column(BigInteger, index=True, nullable=True)

    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())


class TelegramLinkToken(Base):
    """Одноразовый токен для привязки tg_id к учётной записи через deep-link бота."""

    __tablename__ = "telegram_link_tokens"

    id: Mapped[int] = mapped_column(primary_key=True)
    user_id: Mapped[int] = mapped_column(ForeignKey("users.id", ondelete="CASCADE"), index=True)
    token: Mapped[str] = mapped_column(String(64), unique=True, index=True)
    expires_at: Mapped[datetime] = mapped_column(DateTime(timezone=True))
    used_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())


class ContractRevision(Base):
    """Снимок версии договора на момент отправки на согласование.
    Позволяет видеть историю правок и что менялось между попытками."""

    __tablename__ = "contract_revisions"

    id: Mapped[int] = mapped_column(primary_key=True)
    contract_id: Mapped[int] = mapped_column(ForeignKey("contracts.id", ondelete="CASCADE"), index=True)
    version_number: Mapped[int] = mapped_column(Integer)  # 1, 2, 3...
    attempt: Mapped[int] = mapped_column(Integer, default=1)
    context_snapshot: Mapped[dict[str, Any]] = mapped_column(JSONB, default=dict)
    template_version: Mapped[str | None] = mapped_column(String(64), nullable=True)
    docx_path: Mapped[str | None] = mapped_column(String(512), nullable=True)
    pdf_path: Mapped[str | None] = mapped_column(String(512), nullable=True)
    note: Mapped[str | None] = mapped_column(String(512), nullable=True)
    created_by_user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True
    )
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())

    __table_args__ = (
        UniqueConstraint("contract_id", "version_number", name="uq_revision_contract_version"),
    )


class ContractRemark(Base):
    """Замечание от согласователя при отклонении. Автор отмечает «исправлено»."""

    __tablename__ = "contract_remarks"

    id: Mapped[int] = mapped_column(primary_key=True)
    contract_id: Mapped[int] = mapped_column(ForeignKey("contracts.id", ondelete="CASCADE"), index=True)
    attempt: Mapped[int] = mapped_column(Integer, default=1)
    stage_order: Mapped[int] = mapped_column(Integer, default=0)
    author_user_id: Mapped[int] = mapped_column(
        ForeignKey("users.id", ondelete="RESTRICT")
    )
    text: Mapped[str] = mapped_column(Text)
    is_resolved: Mapped[bool] = mapped_column(Boolean, default=False)
    resolved_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    resolved_by_user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True
    )
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())


class AuditLog(Base):
    """Аудит-лог: кто, что, когда. Для юридических документов критичен."""

    __tablename__ = "audit_log"

    id: Mapped[int] = mapped_column(primary_key=True)
    user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True
    )
    contract_id: Mapped[int | None] = mapped_column(ForeignKey("contracts.id"), nullable=True, index=True)
    action: Mapped[str] = mapped_column(String(64))  # create, edit, generate, submit, approve, reject, upload_drive
    payload: Mapped[dict[str, Any] | None] = mapped_column(JSONB, nullable=True)
    ip: Mapped[str | None] = mapped_column(String(64), nullable=True)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())


# ============ Реестр клиентов / Customer Success (Фаза 4) ============
# Оцифровка ручного Excel-реестра (Внедрение + Сопровождение). Гибрид:
# воронка ЖЦ (B0–B6 внедрение / A1–A6 сопровождение / C0 отвал) + подписка на платформу +
# чек-лист внедрения + ряд активности (time-series) + кеш здоровья (тир A1–A6).

class Platform(Base):
    """Продуктовая платформа, на которой работает клиент (MacroSales / MacroERP)."""

    __tablename__ = "platforms"

    id: Mapped[int] = mapped_column(primary_key=True)
    code: Mapped[str] = mapped_column(String(32), unique=True, index=True)  # macrosales / macroerp
    name: Mapped[str] = mapped_column(String(128))
    sort_order: Mapped[int] = mapped_column(Integer, default=0)
    is_active: Mapped[bool] = mapped_column(Boolean, default=True)


class Region(Base):
    """Регион присутствия клиента (ЦА / GCC / Кавказ)."""

    __tablename__ = "regions"

    id: Mapped[int] = mapped_column(primary_key=True)
    code: Mapped[str] = mapped_column(String(16), unique=True, index=True)  # ca / gcc / caucasus
    name: Mapped[str] = mapped_column(String(128))
    sort_order: Mapped[int] = mapped_column(Integer, default=0)


class Module(Base):
    """Модуль/спутник продукта (MacroWEB, Каталог, ДЦО, 1C, Wazzup…). platform_id null = общий."""

    __tablename__ = "modules"

    id: Mapped[int] = mapped_column(primary_key=True)
    code: Mapped[str] = mapped_column(String(48), index=True)
    name: Mapped[str] = mapped_column(String(128))
    platform_id: Mapped[int | None] = mapped_column(ForeignKey("platforms.id", ondelete="CASCADE"), nullable=True, index=True)
    sort_order: Mapped[int] = mapped_column(Integer, default=0)

    __table_args__ = (
        UniqueConstraint("platform_id", "code", name="uq_module_platform_code"),
    )


class ChecklistTemplate(Base):
    """Шаблон чек-листа внедрения для платформы (пункты — ChecklistTemplateItem). Конфигурируемый."""

    __tablename__ = "checklist_templates"

    id: Mapped[int] = mapped_column(primary_key=True)
    platform_id: Mapped[int] = mapped_column(ForeignKey("platforms.id", ondelete="CASCADE"), index=True)
    name: Mapped[str] = mapped_column(String(128))


class ChecklistTemplateItem(Base):
    """Пункт чек-листа внедрения. kind: status | fraction (X/Y) | percent (0..1) | date."""

    __tablename__ = "checklist_template_items"

    id: Mapped[int] = mapped_column(primary_key=True)
    template_id: Mapped[int] = mapped_column(ForeignKey("checklist_templates.id", ondelete="CASCADE"), index=True)
    code: Mapped[str] = mapped_column(String(64))
    label: Mapped[str] = mapped_column(String(255))
    group: Mapped[str | None] = mapped_column(String(64), nullable=True)  # «Внедрение» / «Качество»
    kind: Mapped[str] = mapped_column(String(16), default="status")
    optional: Mapped[bool] = mapped_column(Boolean, default=True)  # можно «Не требуется»
    sort_order: Mapped[int] = mapped_column(Integer, default=0)


class ClientSubscription(Base):
    """Подписка клиента на платформу (M:N клиент↔платформа). Центральная сущность реестра CS."""

    __tablename__ = "client_subscriptions"

    id: Mapped[int] = mapped_column(primary_key=True)
    # CONTACTS 2.0 / CS-hotfix (0080): company_id — источник истины, поэтому
    # counterparty_id стал NULLABLE. Новая Company без legacy-зеркала может
    # завести подписку с одним только company_id (раньше падало 400).
    counterparty_id: Mapped[int | None] = mapped_column(
        ForeignKey("counterparties.id", ondelete="CASCADE"), nullable=True, index=True,
    )
    # CONTACTS 2.0 Ф0: новая сторона-компания (data-migration по маппингу).
    company_id: Mapped[int | None] = mapped_column(
        ForeignKey("crm_companies.id", ondelete="SET NULL"), nullable=True, index=True,
    )
    platform_id: Mapped[int] = mapped_column(
        ForeignKey("platforms.id", ondelete="RESTRICT"), index=True
    )
    region_id: Mapped[int | None] = mapped_column(ForeignKey("regions.id", ondelete="SET NULL"), nullable=True, index=True)

    # Связь с центральным источником активности (ключ join — заполняется при импорте/настройке)
    external_client_id: Mapped[str | None] = mapped_column(String(128), nullable=True, index=True)

    # Жизненный цикл: этап воронки «Жизненный цикл» (код B0–B6 / A1–A6 / C0)
    lifecycle_stage_id: Mapped[int | None] = mapped_column(ForeignKey("pipeline_stages.id", ondelete="SET NULL"), nullable=True, index=True)
    stage_changed_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)

    # Команда: FK на User + текстовый фолбэк (если имя из реестра не сматчили)
    imp_pm_user_id: Mapped[int | None] = mapped_column(ForeignKey("users.id", ondelete="SET NULL"), nullable=True)  # ПМ внедрял
    sup_pm_user_id: Mapped[int | None] = mapped_column(ForeignKey("users.id", ondelete="SET NULL"), nullable=True)  # ПМ (текущий)
    am_user_id: Mapped[int | None] = mapped_column(ForeignKey("users.id", ondelete="SET NULL"), nullable=True)      # АМ
    team_names: Mapped[dict[str, Any]] = mapped_column(JSON, default=dict)  # {"imp_pm": "...", "sup_pm": "...", "am": "..."}

    # Ком.блок
    seats: Mapped[int | None] = mapped_column(Integer, nullable=True)  # УЗ / учётные записи
    fee_actual: Mapped[Decimal | None] = mapped_column(Numeric(18, 2), nullable=True)    # Абон Факт
    fee_contract: Mapped[Decimal | None] = mapped_column(Numeric(18, 2), nullable=True)  # Абонентка Дог
    fee_currency: Mapped[str | None] = mapped_column(String(8), nullable=True)
    tariff: Mapped[str | None] = mapped_column(String(64), nullable=True)  # Тариф ТП
    discount_until: Mapped[date | None] = mapped_column(Date, nullable=True)
    auto_prolongation: Mapped[bool] = mapped_column(Boolean, default=False)
    # CS-hotfix (0080): маркер генерации renewal-сделки на самой подписке.
    # Дедуп renewal-cron теперь опирается на эту метку (а не только на наличие
    # Deal в воронке) — если сделку удалили, повторной генерации в окне не будет.
    last_renewal_generated_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True), nullable=True,
    )
    on_premise: Mapped[bool] = mapped_column(Boolean, default=False)  # Коробка
    last_fee_increase_at: Mapped[date | None] = mapped_column(Date, nullable=True)  # для upsell-триггера

    # Внедрение
    impl_start_date: Mapped[date | None] = mapped_column(Date, nullable=True)
    act_signed_date: Mapped[date | None] = mapped_column(Date, nullable=True)
    impl_pct: Mapped[Decimal | None] = mapped_column(Numeric(5, 2), nullable=True)  # «Итог по графику», кеш 0..100
    qa_result: Mapped[str | None] = mapped_column(String(32), nullable=True)  # передано_ос / принято_рль / ожидает
    qa_date: Mapped[date | None] = mapped_column(Date, nullable=True)

    # Здоровье (кеш, считает recompute_health-джоб)
    health_tier: Mapped[str | None] = mapped_column(String(8), nullable=True, index=True)  # A1..A6 / C0
    health_score: Mapped[Decimal | None] = mapped_column(Numeric(8, 2), nullable=True)
    activity_avg: Mapped[Decimal | None] = mapped_column(Numeric(12, 2), nullable=True)     # ср. действий/период
    activity_trend_pct: Mapped[Decimal | None] = mapped_column(Numeric(8, 2), nullable=True)
    dormant_periods: Mapped[int | None] = mapped_column(Integer, nullable=True)
    health_reasons: Mapped[list[str]] = mapped_column(JSON, default=list)   # триггеры «требуют внимания»
    manual_tier_override: Mapped[str | None] = mapped_column(String(8), nullable=True)  # ТП зафиксировал
    health_computed_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)

    is_active: Mapped[bool] = mapped_column(Boolean, default=True)
    notes: Mapped[str | None] = mapped_column(Text, nullable=True)  # «Примечание Активности»

    # Epic 14: owner + department для scope-фильтра. owner — это «кто ответственный
    # за подписку» для visibility ACL; ПМ-имена (sup_pm_user_id / am_user_id /
    # imp_pm_user_id) остаются — это разные ролевые слоты в реестре CS.
    # Если owner_user_id не задан, автозаливаем из sup_pm_user_id при create.
    owner_user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True,
    )
    department_id: Mapped[int | None] = mapped_column(
        ForeignKey("departments.id", ondelete="SET NULL"), nullable=True,
    )

    # Эпик 8: кастомные поля (CustomFieldDef.scope='subscription')
    extra_fields: Mapped[dict[str, Any]] = mapped_column(JSONB, default=dict)

    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), onupdate=func.now()
    )

    __table_args__ = (
        UniqueConstraint("counterparty_id", "platform_id", "region_id", name="uq_subscription_cp_platform_region"),
        # CONTACTS 2.0 Ф3-B: новый уникальный ключ по company (источник истины).
        # NULL company_id не нарушает UNIQUE (Postgres семантика множественных NULL).
        UniqueConstraint("company_id", "platform_id", "region_id", name="uq_sub_company_platform_region"),
    )


class SubscriptionModule(Base):
    """Подключённый модуль/спутник на подписке (флаги True/False из реестра)."""

    __tablename__ = "subscription_modules"

    id: Mapped[int] = mapped_column(primary_key=True)
    subscription_id: Mapped[int] = mapped_column(ForeignKey("client_subscriptions.id", ondelete="CASCADE"), index=True)
    module_id: Mapped[int] = mapped_column(ForeignKey("modules.id", ondelete="CASCADE"), index=True)
    enabled: Mapped[bool] = mapped_column(Boolean, default=True)
    status: Mapped[str | None] = mapped_column(String(32), nullable=True)  # факт / договор / в ожидании

    __table_args__ = (
        UniqueConstraint("subscription_id", "module_id", name="uq_sub_module"),
    )


class ImplementationItemStatus(Base):
    """Статус пункта чек-листа внедрения для конкретной подписки."""

    __tablename__ = "implementation_item_status"

    id: Mapped[int] = mapped_column(primary_key=True)
    subscription_id: Mapped[int] = mapped_column(ForeignKey("client_subscriptions.id", ondelete="CASCADE"), index=True)
    template_item_id: Mapped[int] = mapped_column(ForeignKey("checklist_template_items.id", ondelete="CASCADE"), index=True)
    # not_started / waiting / in_progress / done / not_required / not_used / not_done
    status: Mapped[str] = mapped_column(String(16), default="not_started")
    num_done: Mapped[int | None] = mapped_column(Integer, nullable=True)   # для kind=fraction (X из Y)
    num_total: Mapped[int | None] = mapped_column(Integer, nullable=True)
    pct: Mapped[Decimal | None] = mapped_column(Numeric(5, 4), nullable=True)  # для kind=percent (0..1)
    value_date: Mapped[date | None] = mapped_column(Date, nullable=True)   # для kind=date
    note: Mapped[str | None] = mapped_column(Text, nullable=True)
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), onupdate=func.now()
    )

    __table_args__ = (
        UniqueConstraint("subscription_id", "template_item_id", name="uq_impl_item"),
    )


class ActivitySnapshot(Base):
    """Снимок активности подписки за период (time-series; метрика = действия/сделки)."""

    __tablename__ = "activity_snapshots"

    id: Mapped[int] = mapped_column(primary_key=True)
    subscription_id: Mapped[int] = mapped_column(ForeignKey("client_subscriptions.id", ondelete="CASCADE"), index=True)
    period_start: Mapped[date] = mapped_column(Date, index=True)
    period_end: Mapped[date | None] = mapped_column(Date, nullable=True)
    metric: Mapped[str] = mapped_column(String(32), default="actions")
    value: Mapped[int] = mapped_column(Integer, default=0)
    source: Mapped[str] = mapped_column(String(16), default="manual")  # central / manual / import
    ingested_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())

    __table_args__ = (
        UniqueConstraint("subscription_id", "period_start", "metric", name="uq_activity_period"),
    )


class RegistryKpiSnapshot(Base):
    """Недельный снимок агрегатов реестра (тренды дашборда — как лист «Аналитика»)."""

    __tablename__ = "registry_kpi_snapshots"

    id: Mapped[int] = mapped_column(primary_key=True)
    snapshot_date: Mapped[date] = mapped_column(Date, index=True)
    platform_id: Mapped[int | None] = mapped_column(ForeignKey("platforms.id", ondelete="CASCADE"), nullable=True)
    region_id: Mapped[int | None] = mapped_column(ForeignKey("regions.id", ondelete="SET NULL"), nullable=True)
    metrics: Mapped[dict[str, Any]] = mapped_column(JSON, default=dict)

    __table_args__ = (
        # NULLS NOT DISTINCT (migration 0107): общий срез (date, NULL, NULL) и
        # per-platform-bucket'ы с region_id=NULL должны конфликтовать сами с собой,
        # иначе force-прогон snapshot_registry_kpis задваивает строки.
        UniqueConstraint(
            "snapshot_date",
            "platform_id",
            "region_id",
            name="uq_kpi_snapshot",
            postgresql_nulls_not_distinct=True,
        ),
    )


# ============ Lead (Эпик 1.0) ============
# Входящий контакт до квалификации. Живёт в отдельной воронке (Pipeline.kind="lead").
# При конверсии создаёт Counterparty + Deal в воронке продаж и помечается status=converted.

class Lead(Base):
    """Входящий лид: до квалификации, до создания Counterparty/Deal."""

    __tablename__ = "leads"

    id: Mapped[int] = mapped_column(primary_key=True)
    name: Mapped[str] = mapped_column(String(255))
    contact_email: Mapped[str | None] = mapped_column(String(255), nullable=True, index=True)
    contact_phone: Mapped[str | None] = mapped_column(String(64), nullable=True, index=True)
    # Источник: manual / form / import / api / email / tg / wa
    source: Mapped[str] = mapped_column(String(32), default="manual")
    owner_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True, index=True
    )
    pipeline_id: Mapped[int] = mapped_column(
        ForeignKey("pipelines.id", ondelete="RESTRICT"), index=True
    )
    stage_id: Mapped[int] = mapped_column(
        ForeignKey("pipeline_stages.id", ondelete="RESTRICT"), index=True
    )
    # active / converted / archived / lost
    status: Mapped[str] = mapped_column(String(32), default="active", index=True)
    tags: Mapped[list[str]] = mapped_column(JSON, default=list)
    notes: Mapped[str | None] = mapped_column(Text, nullable=True)
    # Заполняется при /convert: на какого контрагента сконвертирован
    converted_to_counterparty_id: Mapped[int | None] = mapped_column(
        ForeignKey("counterparties.id", ondelete="SET NULL"), nullable=True
    )
    # CONTACTS 2.0 Ф0: новая сторона-компания при конверсии (data-migration по маппингу).
    converted_to_company_id: Mapped[int | None] = mapped_column(
        ForeignKey("crm_companies.id", ondelete="SET NULL"), nullable=True
    )
    # Эпик 4.2: прямая связь Lead → созданный Deal. Раньше нужно было
    # JOIN'ить через counterparty_id (медленно + теряем связь при пересоздании КА).
    converted_deal_id: Mapped[int | None] = mapped_column(
        ForeignKey("deals.id", ondelete="SET NULL"), nullable=True, index=True
    )
    converted_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)

    # Эпик 4.2: HubSpot-стандарт lead scoring (0..100). CHECK enforced на уровне БД.
    # Заполняется вручную пока (scoring engine отложен).
    score: Mapped[int | None] = mapped_column(Integer, nullable=True, index=True)

    # Epic 14: отдел лида для scope-фильтра видимости. Автозаполняется из
    # owner.department_id при create/update (если не задано явно).
    department_id: Mapped[int | None] = mapped_column(
        ForeignKey("departments.id", ondelete="SET NULL"), nullable=True,
    )

    # Эпик 8: кастомные поля (CustomFieldDef.scope='lead')
    extra_fields: Mapped[dict[str, Any]] = mapped_column(JSONB, default=dict)

    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), onupdate=func.now()
    )

    owner: Mapped[User | None] = relationship(foreign_keys=[owner_id])
    pipeline: Mapped[Pipeline] = relationship(foreign_keys=[pipeline_id])
    stage: Mapped[PipelineStage] = relationship(foreign_keys=[stage_id])
    converted_to_counterparty: Mapped[Counterparty | None] = relationship(
        foreign_keys=[converted_to_counterparty_id]
    )


# ============ Contact + Company (Эпик 1.2) ============
# Новые сущности, заводятся рядом с Counterparty (legacy) — НЕ заменяют его.
# Counterparty остаётся для совместимости с Contract / Subscription / Deal.
# Когда Company соответствует существующему Counterparty (например, при ручном
# мэппинге или импорте из AmoCRM) — обратная связь хранится в Company.counterparty_id.

class Company(Base):
    """Компания-клиент (организация). Эпик 1.2."""

    __tablename__ = "crm_companies"

    id: Mapped[int] = mapped_column(primary_key=True)
    legal_name: Mapped[str] = mapped_column(String(255))
    short_name: Mapped[str | None] = mapped_column(String(128), nullable=True)
    # CONTACTS 2.0 Ф0: обиходное (common) название, напр. «ПТС Казахстан» —
    # отдельно от legal_name (юридическое). Заполняется из Counterparty.name.
    name: Mapped[str | None] = mapped_column(String(255), nullable=True, index=True)
    # БИН / ИНН / КПП / прочие гос. идентификаторы
    tax_id: Mapped[str | None] = mapped_column(String(32), nullable=True, index=True)
    # ISO 3166-1 alpha-2 (KZ / UZ / RU / AE / ...)
    country: Mapped[str | None] = mapped_column(String(2), nullable=True, index=True)
    city: Mapped[str | None] = mapped_column(String(128), nullable=True)
    website: Mapped[str | None] = mapped_column(String(255), nullable=True)
    phone: Mapped[str | None] = mapped_column(String(64), nullable=True)
    email: Mapped[str | None] = mapped_column(String(255), nullable=True, index=True)
    industry: Mapped[str | None] = mapped_column(String(64), nullable=True)
    notes: Mapped[str | None] = mapped_column(Text, nullable=True)
    group_id: Mapped[int | None] = mapped_column(
        ForeignKey("client_groups.id", ondelete="SET NULL"), nullable=True
    )
    # Категория клиента L/M/S1/S2 — хранится строкой, ссылка на ClientCategory.code
    category_code: Mapped[str | None] = mapped_column(String(8), nullable=True, index=True)
    # Обратная связь с legacy Counterparty (если эта Company соответствует ему)
    counterparty_id: Mapped[int | None] = mapped_column(
        ForeignKey("counterparties.id", ondelete="SET NULL"), nullable=True
    )

    # ============ CONTACTS 2.0 Ф0: реквизиты стороны договора ============
    # Поглощаются из Counterparty, чтобы Company стала полноценной стороной
    # сублицензионного договора. Все nullable — заполняются data-migration'ом
    # для существующих контрагентов; новые компании могут жить без реквизитов.
    full_legal_form: Mapped[str | None] = mapped_column(String(255), nullable=True)
    legal_form: Mapped[str | None] = mapped_column(String(64), nullable=True)
    gender_ending_oe: Mapped[str | None] = mapped_column(String(16), nullable=True)
    # ISO alpha-2 в counterparty-формате (дублирует country, оставлен для
    # точного 1:1 переноса реквизитов; источник истины — country).
    country_code: Mapped[str | None] = mapped_column(String(2), nullable=True)
    director_position: Mapped[str | None] = mapped_column(String(128), nullable=True)
    director_genitive: Mapped[str | None] = mapped_column(String(255), nullable=True)
    director_short: Mapped[str | None] = mapped_column(String(128), nullable=True)
    acts_basis: Mapped[str | None] = mapped_column(String(64), nullable=True)
    tax_id_label: Mapped[str | None] = mapped_column(String(16), nullable=True)
    address: Mapped[str | None] = mapped_column(Text, nullable=True)
    bank: Mapped[str | None] = mapped_column(String(255), nullable=True)
    bank_code_label: Mapped[str | None] = mapped_column(String(32), nullable=True)
    bank_code: Mapped[str | None] = mapped_column(String(64), nullable=True)
    account: Mapped[str | None] = mapped_column(String(64), nullable=True)
    turnover_rub: Mapped[Decimal | None] = mapped_column(Numeric(18, 2), nullable=True)
    # Ответственный менеджер (≠ owner_user_id; см. Counterparty.responsible_user_id).
    responsible_user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True, index=True,
    )

    # ============ CONTACTS 2.0 Ф0: классификация ============
    # Источник: own_contact / cold_call / partner / internet / lead.
    source: Mapped[str | None] = mapped_column(String(32), nullable=True)
    # Тип компании (справочник crm_company_types).
    company_type_id: Mapped[int | None] = mapped_column(
        ForeignKey("crm_company_types.id", ondelete="SET NULL"), nullable=True,
    )
    # Теги (PG ARRAY(TEXT) — как у Activity.tags, под GIN при необходимости).
    tags: Mapped[list[str]] = mapped_column(
        ARRAY(Text), nullable=False, default=list, server_default="{}"
    )
    # Роль компании внутри холдинга (group_id = холдинг ClientGroup): parent / subsidiary.
    holding_role: Mapped[str | None] = mapped_column(String(16), nullable=True)

    # Epic 14: owner + department для scope-фильтра видимости.
    owner_user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True,
    )
    department_id: Mapped[int | None] = mapped_column(
        ForeignKey("departments.id", ondelete="SET NULL"), nullable=True,
    )

    # Эпик 8: кастомные поля (CustomFieldDef.scope='company')
    extra_fields: Mapped[dict[str, Any]] = mapped_column(JSONB, default=dict)

    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), onupdate=func.now()
    )

    group: Mapped[ClientGroup | None] = relationship(foreign_keys=[group_id])
    counterparty: Mapped[Counterparty | None] = relationship(foreign_keys=[counterparty_id])
    contacts: Mapped[list[Contact]] = relationship(  # type: ignore[name-defined]
        back_populates="company", foreign_keys="Contact.company_id"
    )
    # CONTACTS 2.0: M2M связи контакт↔компания (новая модель связей).
    contact_links: Mapped[list[ContactCompanyLink]] = relationship(  # type: ignore[name-defined]
        back_populates="company", foreign_keys="ContactCompanyLink.company_id",
        cascade="all, delete-orphan",
    )


class Contact(Base):
    """Контактное лицо (физлицо). Эпик 1.2.

    Может быть привязан к компании (`company_id`) или жить без неё (frilance / прямой клиент).
    """

    __tablename__ = "crm_contacts"

    id: Mapped[int] = mapped_column(primary_key=True)
    full_name: Mapped[str] = mapped_column(String(255))
    email: Mapped[str | None] = mapped_column(String(255), nullable=True, index=True)
    phone: Mapped[str | None] = mapped_column(String(64), nullable=True, index=True)
    position: Mapped[str | None] = mapped_column(String(128), nullable=True)
    company_id: Mapped[int | None] = mapped_column(
        ForeignKey("crm_companies.id", ondelete="SET NULL"), nullable=True, index=True
    )
    is_primary: Mapped[bool] = mapped_column(Boolean, default=False)
    owner_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True, index=True
    )
    tg_username: Mapped[str | None] = mapped_column(String(64), nullable=True)
    notes: Mapped[str | None] = mapped_column(Text, nullable=True)

    # ============ CONTACTS 2.0 Ф0 ============
    # Источник: own_contact / cold_call / partner / internet / lead.
    source: Mapped[str | None] = mapped_column(String(32), nullable=True)
    # Теги (PG ARRAY(TEXT)).
    tags: Mapped[list[str]] = mapped_column(
        ARRAY(Text), nullable=False, default=list, server_default="{}"
    )
    # Общий статус контакта: active / archived / ...
    status: Mapped[str | None] = mapped_column(String(32), nullable=True, default="active")

    # Эпик 8: кастомные поля (CustomFieldDef.scope='contact')
    extra_fields: Mapped[dict[str, Any]] = mapped_column(JSONB, default=dict)

    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), onupdate=func.now()
    )

    # NOTE: company_id / is_primary / position на этом классе — deprecated после
    # CONTACTS 2.0 (заменяются M2M `ContactCompanyLink`). Колонки оставлены для
    # отката; новый код должен ходить через contact_links.
    company: Mapped[Company | None] = relationship(
        back_populates="contacts", foreign_keys=[company_id]
    )
    owner: Mapped[User | None] = relationship(foreign_keys=[owner_id])
    company_links: Mapped[list[ContactCompanyLink]] = relationship(  # type: ignore[name-defined]
        back_populates="contact", foreign_keys="ContactCompanyLink.contact_id",
        cascade="all, delete-orphan",
    )


# ============ CONTACTS 2.0 — связи, справочники, файлы ============
# Слияние разделов Контакты/Компании/Контрагенты в единый «Контакты».
# Ф0 (backend foundation): модели + миграции + data-migration. API — следующей фазой.

class CompanyType(Base):
    """Справочник типов компаний (строительная / агентство / подрядчик / партнёр)."""

    __tablename__ = "crm_company_types"

    id: Mapped[int] = mapped_column(primary_key=True)
    name: Mapped[str] = mapped_column(String(128), unique=True)
    description: Mapped[str | None] = mapped_column(Text, nullable=True)
    sort_order: Mapped[int] = mapped_column(Integer, default=0, server_default="0")
    is_active: Mapped[bool] = mapped_column(Boolean, default=True, server_default="true")
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())


class ContactPosition(Base):
    """Справочник должностей контактных лиц."""

    __tablename__ = "crm_contact_positions"

    id: Mapped[int] = mapped_column(primary_key=True)
    name: Mapped[str] = mapped_column(String(128), unique=True)
    sort_order: Mapped[int] = mapped_column(Integer, default=0, server_default="0")
    is_active: Mapped[bool] = mapped_column(Boolean, default=True, server_default="true")
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())


class ContactCompanyLink(Base):
    """M2M связь контакт↔компания (заменяет Contact.company_id 1:N).

    Один контакт может работать в нескольких компаниях (с историей: works/left),
    с разной должностью в каждой.
    """

    __tablename__ = "crm_contact_company_links"

    id: Mapped[int] = mapped_column(primary_key=True)
    contact_id: Mapped[int] = mapped_column(
        ForeignKey("crm_contacts.id", ondelete="CASCADE"), index=True,
    )
    company_id: Mapped[int] = mapped_column(
        ForeignKey("crm_companies.id", ondelete="CASCADE"), index=True,
    )
    # Должность (свободный текст) + опционально ссылка на справочник.
    position: Mapped[str | None] = mapped_column(String(128), nullable=True)
    position_id: Mapped[int | None] = mapped_column(
        ForeignKey("crm_contact_positions.id", ondelete="SET NULL"), nullable=True,
    )
    # Статус занятости: works (работает) / left (уволился).
    employment_status: Mapped[str] = mapped_column(
        String(16), default="works", server_default="works",
    )
    is_primary: Mapped[bool] = mapped_column(Boolean, default=False, server_default="false")
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), onupdate=func.now()
    )

    contact: Mapped[Contact] = relationship(
        back_populates="company_links", foreign_keys=[contact_id],
    )
    company: Mapped[Company] = relationship(
        back_populates="contact_links", foreign_keys=[company_id],
    )

    __table_args__ = (
        UniqueConstraint("contact_id", "company_id", name="uq_contact_company_link"),
    )


# ============ Wave 3 — admin справочники (страны/города/источники/группы продуктов) ============

class Country(Base):
    """Справочник стран (ISO 3166-1 alpha-2). Источник истины для пикеров регионов."""

    __tablename__ = "crm_countries"

    id: Mapped[int] = mapped_column(primary_key=True)
    # ISO alpha-2, всегда lowercase (kz/uz/ru…). Естественный ключ для сидера.
    code: Mapped[str] = mapped_column(String(2), unique=True, index=True)
    name: Mapped[str] = mapped_column(String(128))  # RU-название
    name_en: Mapped[str | None] = mapped_column(String(128), nullable=True)
    phone_prefix: Mapped[str | None] = mapped_column(String(8), nullable=True)  # «+7»
    sort_order: Mapped[int] = mapped_column(Integer, default=0, server_default="0")
    is_active: Mapped[bool] = mapped_column(Boolean, default=True, server_default="true")
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())


class City(Base):
    """Справочник городов, привязанных к стране по code (FK→crm_countries.code)."""

    __tablename__ = "crm_cities"

    id: Mapped[int] = mapped_column(primary_key=True)
    country_code: Mapped[str] = mapped_column(
        String(2), ForeignKey("crm_countries.code", ondelete="CASCADE"), index=True,
    )
    name: Mapped[str] = mapped_column(String(128))  # RU-название
    sort_order: Mapped[int] = mapped_column(Integer, default=0, server_default="0")
    is_active: Mapped[bool] = mapped_column(Boolean, default=True, server_default="true")
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())

    __table_args__ = (
        UniqueConstraint("country_code", "name", name="uq_city_country_name"),
    )


class Source(Base):
    """Справочник источников лида/компании (own_contact/cold_call/partner/internet/lead).

    Source of truth для пикера. Company.source хранит code строкой (без FK).
    """

    __tablename__ = "crm_sources"

    id: Mapped[int] = mapped_column(primary_key=True)
    code: Mapped[str] = mapped_column(String(32), unique=True, index=True)
    name: Mapped[str] = mapped_column(String(128))  # RU-label
    sort_order: Mapped[int] = mapped_column(Integer, default=0, server_default="0")
    is_active: Mapped[bool] = mapped_column(Boolean, default=True, server_default="true")
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())


class ProductGroup(Base):
    """Управляемый справочник групп продуктов (заменяет free-text Product.group).

    Product.group_id ссылается сюда; legacy Product.group (строка) держим синхронной
    для обратной совместимости/отображения.
    """

    __tablename__ = "product_groups"

    id: Mapped[int] = mapped_column(primary_key=True)
    name: Mapped[str] = mapped_column(String(128), unique=True)
    description: Mapped[str | None] = mapped_column(Text, nullable=True)
    sort_order: Mapped[int] = mapped_column(Integer, default=0, server_default="0")
    is_active: Mapped[bool] = mapped_column(Boolean, default=True, server_default="true")
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())


class Folder(Base):
    """Папка файлов в карточке контакта/компании.

    Полиморфная привязка через (owner_entity_type, owner_entity_id) — как Activity.
    is_system=True — «Системная» папка, её нельзя удалять/переименовывать.
    """

    __tablename__ = "crm_folders"

    id: Mapped[int] = mapped_column(primary_key=True)
    owner_entity_type: Mapped[str] = mapped_column(String(16))  # contact / company
    owner_entity_id: Mapped[int] = mapped_column(Integer)
    name: Mapped[str] = mapped_column(String(255))
    is_system: Mapped[bool] = mapped_column(Boolean, default=False, server_default="false")
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())

    __table_args__ = (
        Index("ix_crm_folders_owner", "owner_entity_type", "owner_entity_id"),
    )


class File(Base):
    """Файл в папке карточки контакта/компании. Хранится на локальном томе VPS."""

    __tablename__ = "crm_files"

    id: Mapped[int] = mapped_column(primary_key=True)
    folder_id: Mapped[int] = mapped_column(
        ForeignKey("crm_folders.id", ondelete="CASCADE"), index=True,
    )
    # Денормализованная полиморфная привязка (дублирует folder.owner_*) — для
    # быстрого «все файлы карточки» без JOIN через папки.
    owner_entity_type: Mapped[str] = mapped_column(String(16))  # contact / company
    owner_entity_id: Mapped[int] = mapped_column(Integer)
    file_path: Mapped[str] = mapped_column(String(512))  # путь на локальном томе
    original_name: Mapped[str] = mapped_column(String(255))
    file_size: Mapped[int] = mapped_column(BigInteger)
    mime_type: Mapped[str | None] = mapped_column(String(255), nullable=True)
    uploaded_by_user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True,
    )
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())

    __table_args__ = (
        Index("ix_crm_files_owner", "owner_entity_type", "owner_entity_id"),
    )


# ============ Activity / Timeline (Эпик 2) ============
# Полиморфная Activity (call/meeting/task/note) для любой сущности (Lead, Contact,
# Company, Counterparty, Deal, Contract, Subscription). Связь с target — через пару
# (target_type, target_id), без direct FK: одна таблица закрывает все типы и не
# требует миграций при появлении новой сущности. Timeline для карточки строится
# запросом `WHERE target_type=? AND target_id=? ORDER BY created_at DESC`.

class Activity(Base):
    """Activity: задача / звонок / встреча / заметка по любой сущности."""

    __tablename__ = "activities"

    id: Mapped[int] = mapped_column(primary_key=True)
    # 'call' | 'meeting' | 'task' | 'note' — валидация в роутере/сервисе
    kind: Mapped[str] = mapped_column(String(16))
    # 'lead' | 'contact' | 'company' | 'counterparty' | 'deal' | 'contract' | 'subscription'
    # Эпик 24 (hotfix июнь 2026): nullable — standalone задачи без привязки
    # к CRM-сущности (личные задачи пользователя). Тогда оба поля = NULL.
    target_type: Mapped[str | None] = mapped_column(String(32), nullable=True)
    target_id: Mapped[int | None] = mapped_column(Integer, nullable=True)
    title: Mapped[str] = mapped_column(String(255))
    body: Mapped[str | None] = mapped_column(Text, nullable=True)
    # Дедлайн (для task/call/meeting); для note игнорируется при создании
    due_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    completed_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    completed_by_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True
    )
    # Кто исполняет (для task/call). nullable — необязательное поле
    responsible_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True
    )
    # Кто создал — заполняется из CurrentUser; ondelete=SET NULL чтобы удаление
    # пользователя не убивало историю активностей. nullable=True (migration 0108):
    # NOT NULL противоречил ON DELETE SET NULL — после удаления автора колонка
    # обнуляется, поэтому она обязана быть nullable.
    created_by_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True
    )

    # Epic 10.5 — FTM (First Time Meeting) поля.
    # Засчитывается FTM при: kind='meeting' AND is_first_time_meeting=True
    # AND ftm_decision_maker_attended=True AND ftm_presentation_shown=True
    # AND ftm_report_url IS NOT NULL.
    is_first_time_meeting: Mapped[bool] = mapped_column(
        Boolean, nullable=False, default=False, server_default="false"
    )
    ftm_decision_maker_attended: Mapped[bool] = mapped_column(
        Boolean, nullable=False, default=False, server_default="false"
    )
    ftm_presentation_shown: Mapped[bool] = mapped_column(
        Boolean, nullable=False, default=False, server_default="false"
    )
    ftm_report_url: Mapped[str | None] = mapped_column(Text, nullable=True)
    ftm_telegram_announced: Mapped[bool] = mapped_column(
        Boolean, nullable=False, default=False, server_default="false"
    )
    # DEALS 2.0 (Ф0): ответы конструктора отчёта о встрече. Структура (Ф1):
    # [{"question_id": int, "text": str, "answer": str}, ...]. NULL = отчёт
    # не заполнен. Привязка к meeting-активности (kind='meeting').
    meeting_report_json: Mapped[dict[str, Any] | None] = mapped_column(
        JSONB, nullable=True
    )

    # Epic 24 — Tasks v2: расширенные поля задачника.
    # category_id — категория задачи (FK добавляется в миграции 0057/0058).
    category_id: Mapped[int | None] = mapped_column(
        ForeignKey("task_categories.id", ondelete="SET NULL"), nullable=True
    )
    # Родительская задача (подзадачи).
    parent_activity_id: Mapped[int | None] = mapped_column(
        ForeignKey("activities.id", ondelete="SET NULL"), nullable=True
    )
    # Приоритет: low|normal|high|critical.
    priority: Mapped[str] = mapped_column(
        String(16), nullable=False, default="normal", server_default="normal"
    )
    # Статус задачи: new|in_progress|done|rejected.
    status: Mapped[str] = mapped_column(
        String(16), nullable=False, default="new", server_default="new"
    )
    # is_closed — финально закрыта постановщиком (после done/rejected + review).
    is_closed: Mapped[bool] = mapped_column(
        Boolean, nullable=False, default=False, server_default="false"
    )
    # % выполнения (0..100).
    progress_pct: Mapped[int] = mapped_column(
        Integer, nullable=False, default=0, server_default="0"
    )
    planned_hours: Mapped[Decimal | None] = mapped_column(Numeric(5, 2), nullable=True)
    actual_hours: Mapped[Decimal | None] = mapped_column(Numeric(5, 2), nullable=True)
    # Результат работы (required если restrict_close_without_result в категории).
    result_text: Mapped[str | None] = mapped_column(Text, nullable=True)
    # Теги (GIN-индекс в миграции 0058). PG ARRAY(TEXT) для GIN.
    tags: Mapped[list[str]] = mapped_column(
        ARRAY(Text), nullable=False, default=list, server_default="{}"
    )
    # Правило повторения: daily|weekly|monthly.
    recurrence_rule: Mapped[str | None] = mapped_column(String(16), nullable=True)
    recurrence_until: Mapped[date | None] = mapped_column(Date, nullable=True)
    # FK на шаблонную задачу серии (NULL = это шаблон ИЛИ не повторяющаяся).
    recurrence_parent_id: Mapped[int | None] = mapped_column(
        ForeignKey("activities.id", ondelete="SET NULL"), nullable=True
    )
    rejected_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    rejected_by_user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True
    )
    # Цветовая метка (hex-код, например #FF5733).
    color_label: Mapped[str | None] = mapped_column(String(8), nullable=True)
    is_favorite: Mapped[bool] = mapped_column(
        Boolean, nullable=False, default=False, server_default="false"
    )
    is_pinned: Mapped[bool] = mapped_column(
        Boolean, nullable=False, default=False, server_default="false"
    )

    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), onupdate=func.now()
    )

    # Relationships — три разных FK на users, обязателен foreign_keys=[...]
    responsible: Mapped[User | None] = relationship(foreign_keys=[responsible_id])
    completed_by: Mapped[User | None] = relationship(foreign_keys=[completed_by_id])
    created_by: Mapped[User | None] = relationship(foreign_keys=[created_by_id])
    rejected_by: Mapped[User | None] = relationship(foreign_keys=[rejected_by_user_id])  # type: ignore[misc]

    # Epic 24 relationships
    category: Mapped[TaskCategory | None] = relationship(  # type: ignore[name-defined]
        "TaskCategory", foreign_keys=[category_id]
    )
    parent_activity: Mapped[Activity | None] = relationship(  # type: ignore[name-defined]
        "Activity",
        foreign_keys=[parent_activity_id],
        remote_side="Activity.id",
        back_populates="children",
    )
    children: Mapped[list[Activity]] = relationship(  # type: ignore[name-defined]
        "Activity",
        foreign_keys=[parent_activity_id],
        back_populates="parent_activity",
    )
    recurrence_parent: Mapped[Activity | None] = relationship(  # type: ignore[name-defined]
        "Activity",
        foreign_keys=[recurrence_parent_id],
        remote_side="Activity.id",
        back_populates="recurrence_children",
    )
    recurrence_children: Mapped[list[Activity]] = relationship(  # type: ignore[name-defined]
        "Activity",
        foreign_keys=[recurrence_parent_id],
        back_populates="recurrence_parent",
    )
    collaborators: Mapped[list[ActivityCollaborator]] = relationship(  # type: ignore[name-defined]
        "ActivityCollaborator",
        foreign_keys="ActivityCollaborator.activity_id",
        cascade="all, delete-orphan",
    )
    checklist_items: Mapped[list[ActivityChecklistItem]] = relationship(  # type: ignore[name-defined]
        "ActivityChecklistItem",
        foreign_keys="ActivityChecklistItem.activity_id",
        cascade="all, delete-orphan",
        order_by="ActivityChecklistItem.sort_order",
    )
    attachments: Mapped[list[ActivityAttachment]] = relationship(  # type: ignore[name-defined]
        "ActivityAttachment",
        foreign_keys="ActivityAttachment.activity_id",
        cascade="all, delete-orphan",
    )
    related_links_from: Mapped[list[ActivityRelatedLink]] = relationship(  # type: ignore[name-defined]
        "ActivityRelatedLink",
        foreign_keys="ActivityRelatedLink.activity_id_from",
        cascade="all, delete-orphan",
    )
    related_links_to: Mapped[list[ActivityRelatedLink]] = relationship(  # type: ignore[name-defined]
        "ActivityRelatedLink",
        foreign_keys="ActivityRelatedLink.activity_id_to",
        cascade="all, delete-orphan",
    )
    # Эпик 24.2 — связи с Google Calendar events (для отображения
    # google_calendar_synced badge в UI). Cascade — чтобы при удалении
    # Activity убирались orphan-linki (Google event удаляется отдельным
    # пред-удалительным вызовом delete_gcal_event в роутере).
    gcal_event_links: Mapped[list[GoogleCalendarEventLink]] = relationship(  # type: ignore[name-defined]
        "GoogleCalendarEventLink",
        foreign_keys="GoogleCalendarEventLink.activity_id",
        back_populates="activity",
        cascade="all, delete-orphan",
    )

    __table_args__ = (
        Index("ix_activities_target_type_target_id", "target_type", "target_id"),
        Index("ix_activities_kind", "kind"),
        Index("ix_activities_responsible_id", "responsible_id"),
        Index("ix_activities_due_at", "due_at"),
        Index("ix_activities_completed_at", "completed_at"),
        Index("idx_activities_ftm", "is_first_time_meeting"),
    )


# ============ PipelineAutomation / AutomationRun (Эпик 4) ============
# Универсальный движок триггеров и действий на воронках. Работает на любой
# воронке (sales/lifecycle/lead/renewal) и оркестрирует действия в других
# доменных сервисах (Activity для create_task, Telegram для tg_notify,
# render для generate_document, прямой UPDATE для set_field).
#
# Триггеры MVP:
# - on_enter_stage         — синхронный, дёргается из PATCH /deals|/leads при смене stage_id
# - idle_in_stage_days     — cron, сущность висит в этапе ≥ N дней
# - date_field_approaching — cron, поле даты (например Subscription.discount_until)
#                            попадает в окно [today + N - 1, today + N + 1]
#
# Действия MVP:
# - tg_notify         — отправка сообщения в Telegram (owner / chat_id / user_id)
# - create_task       — создание Activity(kind='task')
# - set_field         — обновление одного поля у target (whitelist обязателен)
# - generate_document — рендер договора через render.generate_contract_files


class PipelineAutomation(Base):
    """Определение автоматизации в воронке: триггер → действие.

    stage_id NULL = автоматизация работает на ВСЕХ этапах воронки (например,
    `field_value_changed`, `on_create`). Для on_enter_stage обычно stage_id
    задан явно — это «при входе в этап X».
    """

    __tablename__ = "pipeline_automations"

    id: Mapped[int] = mapped_column(primary_key=True)
    name: Mapped[str] = mapped_column(String(255))
    description: Mapped[str | None] = mapped_column(Text, nullable=True)
    pipeline_id: Mapped[int] = mapped_column(
        ForeignKey("pipelines.id", ondelete="CASCADE"), index=True
    )
    stage_id: Mapped[int | None] = mapped_column(
        ForeignKey("pipeline_stages.id", ondelete="CASCADE"), nullable=True, index=True
    )
    # 'on_enter_stage' | 'idle_in_stage_days' | 'date_field_approaching'
    trigger_kind: Mapped[str] = mapped_column(String(32), index=True)
    # idle: {"days": 7}; date_field: {"field": "discount_until", "days": 30,
    # "target_type": "subscription"}; on_enter_stage: {} (обычно пустой)
    trigger_config: Mapped[dict[str, Any]] = mapped_column(JSON, default=dict)
    # 'tg_notify' | 'create_task' | 'set_field' | 'generate_document'
    action_kind: Mapped[str] = mapped_column(String(32))
    # tg_notify: {"recipient": "owner"|"user_id:N"|"chat_id:N", "message": "..."}
    # create_task: {"title":"...", "body":"...", "responsible":"owner"|"user_id:N", "due_days": 1}
    # set_field: {"field":"notes", "value":"..."}
    # generate_document: {"template_code":"...", "attach_to":"deal"|"counterparty"}
    action_config: Mapped[dict[str, Any]] = mapped_column(JSON, default=dict)
    is_active: Mapped[bool] = mapped_column(Boolean, default=True, index=True)
    # Эпик 19 — Automation 2: SLA flag. Отделяет SLA-правила от обычных
    # автоматизаций (отдельная UI-вкладка, дефолтный сидер). Миграция 0043.
    is_sla: Mapped[bool] = mapped_column(
        Boolean, default=False, server_default="false", nullable=False, index=True,
    )
    # Эпик 19: цепочка эскалаций. Список словарей вида
    # [{"after_hours": int, "action_kind": str, "action_config": dict}].
    # NULL = эскалация не настроена. Миграция 0043.
    escalation_chain: Mapped[list[dict[str, Any]] | None] = mapped_column(
        JSONB, nullable=True,
    )
    created_by_user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True
    )
    last_run_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True), nullable=True
    )
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now()
    )
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), onupdate=func.now()
    )

    pipeline: Mapped[Pipeline] = relationship(foreign_keys=[pipeline_id])
    stage: Mapped[PipelineStage | None] = relationship(foreign_keys=[stage_id])
    created_by: Mapped[User | None] = relationship(foreign_keys=[created_by_user_id])
    runs: Mapped[list[AutomationRun]] = relationship(  # type: ignore[name-defined]
        back_populates="automation",
        cascade="all, delete-orphan",
        foreign_keys="AutomationRun.automation_id",
    )


class AutomationRun(Base):
    """Журнал выполнений автоматизации: audit + защита от повтора.

    Для cron-триггеров (idle_in_stage_days, date_field_approaching) последняя
    success/skipped запись по (automation_id, target_type, target_id) защищает
    от повторного уведомления в течение окна (определяется в executor'е).

    status:
    - pending  — действие начато, ещё не завершилось (промежуточное состояние,
      редкое в MVP — мы пишем сразу финальный статус)
    - queued   — POST-AUDIT #4 (fire-and-forget): сетевое действие
      (tg_notify/webhook/email) заклеймлено в inline-пути запроса и отложено в
      фоновый таск; держит idem-слот. Фоновый исполнитель переводит в
      success/failed/skipped на свежей сессии.
    - success  — действие выполнено
    - failed   — действие упало, error_text заполнен
    - skipped  — условие фактически не выполнено (dedup, нет адресата и т.п.)
    """

    __tablename__ = "automation_runs"

    id: Mapped[int] = mapped_column(primary_key=True)
    automation_id: Mapped[int] = mapped_column(
        ForeignKey("pipeline_automations.id", ondelete="CASCADE"), index=True
    )
    # 'deal' | 'lead' | 'subscription'
    target_type: Mapped[str] = mapped_column(String(32))
    target_id: Mapped[int] = mapped_column(Integer)
    # Момент события, породившего run (миграция 0081). Заполняется по триггеру:
    # on_enter_stage=stage_changed_at; idle=округлённое начало окна;
    # date_field=target_date-days; on_create=created_at. NULL = ручной/legacy run
    # (без транзакционного дедупа — manual execute/retry допускают повтор).
    # Вместе с partial UNIQUE-индексом ux_automation_runs_idem даёт
    # идемпотентность на scale=2 через INSERT ... ON CONFLICT DO NOTHING.
    trigger_event_ts: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True), nullable=True
    )
    # 'pending' | 'queued' | 'success' | 'failed' | 'skipped'
    status: Mapped[str] = mapped_column(String(16))
    started_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now()
    )
    finished_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True), nullable=True
    )
    error_text: Mapped[str | None] = mapped_column(Text, nullable=True)
    result_json: Mapped[dict[str, Any] | None] = mapped_column(JSON, nullable=True)

    automation: Mapped[PipelineAutomation] = relationship(
        back_populates="runs", foreign_keys=[automation_id]
    )

    __table_args__ = (
        Index("ix_automation_runs_target", "target_type", "target_id"),
        # Идемпотентность (миграция 0081): один run на (automation, target,
        # trigger_event_ts). Partial — ручные прогоны (trigger_event_ts IS NULL)
        # не дедупим. Совпадает с DDL миграции; держим в модели, чтобы
        # autogenerate не пытался её удалить.
        Index(
            "ux_automation_runs_idem",
            "automation_id",
            "target_type",
            "target_id",
            "trigger_event_ts",
            unique=True,
            postgresql_where=text("trigger_event_ts IS NOT NULL"),
        ),
    )


# ============ Channel / InboundMessage / Form (Эпик 5 MVP) ============
# Inbox-стек: канал (tg/wa/email/web_form/api) → входящее сообщение (audit + raw)
# → авто-создание Lead. Webhook endpoint /api/inbox/webhook/{channel_id} принимает
# сигнал от внешней системы (с verify по secret_token), сервис inbox автогенерирует
# Lead в воронке kind="lead" (либо в pipeline/stage, указанных у канала).
# Публичная форма /api/forms/public/{slug} — то же самое, но без channel_id в URL
# (slug маппится на форму, а форма — на канал).

class Channel(Base):
    """Канал входящих сообщений: tg/wa/email/web_form/api.

    secret_token — для verify webhook'ов (constant-time compare). default_pipeline_id
    NULL → берётся Pipeline.kind="lead". default_stage_id NULL → первый этап
    выбранной воронки.
    """

    __tablename__ = "channels"

    id: Mapped[int] = mapped_column(primary_key=True)
    name: Mapped[str] = mapped_column(String(255))
    # 'tg' | 'wa' | 'email' | 'web_form' | 'api'
    kind: Mapped[str] = mapped_column(String(16), index=True)
    # 32-байтный URL-safe токен — генерится при create через secrets.token_urlsafe(32)
    secret_token: Mapped[str] = mapped_column(String(64), index=True)
    # Настройки канала: bot_token / imap_creds / wa_phone_id / etc.
    config: Mapped[dict[str, Any]] = mapped_column(JSON, default=dict)
    # source для авто-Lead: 'tg'|'wa'|'email'|'form'|'api'|'manual'|'import'
    default_lead_source: Mapped[str] = mapped_column(String(16), default="api")
    default_owner_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True
    )
    default_pipeline_id: Mapped[int | None] = mapped_column(
        ForeignKey("pipelines.id", ondelete="RESTRICT"), nullable=True
    )
    default_stage_id: Mapped[int | None] = mapped_column(
        ForeignKey("pipeline_stages.id", ondelete="SET NULL"), nullable=True
    )
    is_active: Mapped[bool] = mapped_column(Boolean, default=True, index=True)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), onupdate=func.now()
    )

    default_owner: Mapped[User | None] = relationship(foreign_keys=[default_owner_id])
    default_pipeline: Mapped[Pipeline | None] = relationship(foreign_keys=[default_pipeline_id])
    default_stage: Mapped[PipelineStage | None] = relationship(foreign_keys=[default_stage_id])
    messages: Mapped[list[InboundMessage]] = relationship(  # type: ignore[name-defined]
        back_populates="channel",
        cascade="all, delete-orphan",
        foreign_keys="InboundMessage.channel_id",
    )
    forms: Mapped[list[Form]] = relationship(  # type: ignore[name-defined]
        back_populates="channel",
        foreign_keys="Form.channel_id",
    )


class InboundMessage(Base):
    """Входящее сообщение из канала (с raw_payload для аудита).

    Дедуп повторных webhook-доставок: композитный индекс (channel_id, external_id).
    target_lead_id заполняется автоматически в inbox.auto_create_lead_from_message;
    target_lead_created=True означает, что Lead был создан именно из этого сообщения
    (False — например, если канал inactive или нет default Lead pipeline).
    """

    __tablename__ = "inbound_messages"

    id: Mapped[int] = mapped_column(primary_key=True)
    channel_id: Mapped[int] = mapped_column(
        ForeignKey("channels.id", ondelete="CASCADE"), index=True
    )
    # id во внешней системе — TG update_id, WA message id, email Message-ID
    external_id: Mapped[str | None] = mapped_column(String(128), nullable=True)
    # tg_username | phone | email | form submission UUID
    from_identifier: Mapped[str | None] = mapped_column(String(255), nullable=True)
    from_name: Mapped[str | None] = mapped_column(String(255), nullable=True)
    subject: Mapped[str | None] = mapped_column(String(255), nullable=True)
    body: Mapped[str | None] = mapped_column(Text, nullable=True)
    raw_payload: Mapped[dict[str, Any] | None] = mapped_column(JSON, nullable=True)
    target_lead_id: Mapped[int | None] = mapped_column(
        ForeignKey("leads.id", ondelete="SET NULL"), nullable=True, index=True
    )
    target_lead_created: Mapped[bool] = mapped_column(Boolean, default=False)
    # DEALS 2.0 (Ф1c): входящий поток создаёт Company+Deal вместо Lead.
    # target_deal_id линкует сообщение к созданной (или переиспользованной) сделке;
    # target_deal_created=True — сделка создана именно из этого сообщения.
    target_deal_id: Mapped[int | None] = mapped_column(
        ForeignKey("deals.id", ondelete="SET NULL"), nullable=True, index=True
    )
    target_deal_created: Mapped[bool] = mapped_column(Boolean, default=False)
    # Статус маршрутизации в Deal (миграция 0082):
    #   'routed'  → Deal создан/привязан,
    #   'dedup'   → external_id уже был, привязано к существующему Deal,
    #   'failed'  → Deal НЕ создан (нет sales-воронки/этапа new) — нужно
    #               ручное «разобрать»; Inbox UI показывает это сообщение.
    #   NULL      → legacy (до 0082) / не маршрутизировалось.
    routing_status: Mapped[str | None] = mapped_column(String(16), nullable=True)
    received_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), index=True
    )

    channel: Mapped[Channel] = relationship(
        back_populates="messages", foreign_keys=[channel_id]
    )
    target_lead: Mapped[Lead | None] = relationship(foreign_keys=[target_lead_id])
    target_deal: Mapped[Deal | None] = relationship(foreign_keys=[target_deal_id])

    __table_args__ = (
        # Партиальный UNIQUE (миграция 0082): дедуп webhook-доставок на уровне БД.
        # WHERE external_id IS NOT NULL — формы/сообщения без external_id не
        # конфликтуют. Гонка scale=2 + ретраи провайдера ловят IntegrityError →
        # сервис привязывается к существующему Deal (см. inbox.auto_create_deal).
        Index(
            "ux_inbound_messages_channel_external",
            "channel_id",
            "external_id",
            unique=True,
            postgresql_where=text("external_id IS NOT NULL"),
        ),
    )


class Form(Base):
    """Публичная форма с уникальным slug. Привязка к каналу — для авто-Lead.

    fields — список полей `[{name, label, type, required, options?}]`. Валидация
    submission'а — в роутере forms.public_submit (минимальная: только required).
    """

    __tablename__ = "forms"

    id: Mapped[int] = mapped_column(primary_key=True)
    name: Mapped[str] = mapped_column(String(255))
    # Публичный URL slug (генерится через secrets.token_urlsafe(8), если не указан)
    public_slug: Mapped[str] = mapped_column(String(64), unique=True)
    # Список полей формы: [{name, label, type, required, options?}]
    fields: Mapped[list[dict[str, Any]]] = mapped_column(JSON, default=list)
    channel_id: Mapped[int | None] = mapped_column(
        ForeignKey("channels.id", ondelete="SET NULL"), nullable=True, index=True
    )
    thank_you_text: Mapped[str | None] = mapped_column(
        Text, nullable=True, default="Спасибо! Мы свяжемся с вами."
    )
    is_active: Mapped[bool] = mapped_column(Boolean, default=True, index=True)
    created_by_user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True
    )
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), onupdate=func.now()
    )

    channel: Mapped[Channel | None] = relationship(
        back_populates="forms", foreign_keys=[channel_id]
    )
    created_by: Mapped[User | None] = relationship(foreign_keys=[created_by_user_id])


# ============ BulkTask (Эпик 6 MVP) ============
# Асинхронная фоновая задача: bulk-генерация документов (а в будущем — bulk-export
# / bulk-email / bulk-update). Создаётся через POST /api/bulk-tasks/*, исполняется
# через FastAPI BackgroundTasks (см. app.services.bulk_generator). target_ids —
# JSON-массив id выбранных сущностей (counterparty/subscription); template_code —
# код шаблона рендера (на MVP: master_skeleton). Прогресс пишется в total/success/
# failed_count; финальный артефакт — .zip в result_zip_path.


class BulkTask(Base):
    """Фоновая задача массовой генерации/обработки.

    status переходы:
    - pending  → задача создана, ещё не подхвачена воркером
    - running  → bulk_generator работает (start_at заполнен)
    - success  → все или часть успешно (success_count > 0, .zip готов)
    - failed   → критическая ошибка (error_text заполнен, .zip может отсутствовать)
    - cancelled → пользователь отменил через DELETE до окончания
    """

    __tablename__ = "bulk_tasks"

    id: Mapped[int] = mapped_column(primary_key=True)
    # 'document_generation' (extensible: bulk_export / bulk_email)
    kind: Mapped[str] = mapped_column(String(32))
    # 'pending' | 'running' | 'success' | 'failed' | 'cancelled'
    status: Mapped[str] = mapped_column(String(16), default="pending")
    # Для kind='document_generation' — код шаблона (на MVP: master_skeleton)
    template_code: Mapped[str | None] = mapped_column(String(64), nullable=True)
    # 'counterparty' | 'subscription' (расширяется enum-like строкой)
    target_type: Mapped[str] = mapped_column(String(32))
    target_ids: Mapped[list[int]] = mapped_column(JSON, default=list)
    total_count: Mapped[int] = mapped_column(Integer, default=0)
    success_count: Mapped[int] = mapped_column(Integer, default=0)
    failed_count: Mapped[int] = mapped_column(Integer, default=0)
    # Путь до результирующего .zip в /data/storage/bulk_tasks/
    result_zip_path: Mapped[str | None] = mapped_column(String(512), nullable=True)
    # Финальная ошибка, если status='failed'
    error_text: Mapped[str | None] = mapped_column(Text, nullable=True)
    created_by_user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True, index=True,
    )
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), index=True,
    )
    started_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True), nullable=True,
    )
    finished_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True), nullable=True,
    )

    created_by: Mapped[User | None] = relationship(foreign_keys=[created_by_user_id])

    __table_args__ = (
        Index("ix_bulk_tasks_status", "status"),
    )


# ============ Sequence / SequenceRun (Эпик 4.1) ============
# Многошаговые «cadences»: шаблон последовательности шагов (Sequence) + конкретный
# запуск для одной цели (SequenceRun) с курсором current_step_index и таймером
# next_step_at. Запускается action_kind='start_sequence' (см. PipelineAutomation)
# либо вручную через API. Cron-сканер (jobs/automation_cron.py → scan_pending_
# sequence_runs) раз в час пробегает SequenceRun со status='pending' / 'running'
# и next_step_at <= now(), выполняет текущий шаг, продвигает курсор и пересчитывает
# next_step_at = now() + delay_days текущего шага.
#
# steps_json формат:
# [
#   {"kind": "tg_notify", "config": {"recipient": "owner", "message": "..."},
#    "delay_days": 0},
#   {"kind": "wait", "config": {}, "delay_days": 3},
#   {"kind": "create_task", "config": {"title": "...", "due_days": 1}, "delay_days": 0},
#   {"kind": "email", "config": {"recipient_role": "owner", "subject_template": "..."},
#    "delay_days": 7}
# ]
# kind может быть: 'wait' | 'tg_notify' | 'email' | 'create_task'. Для wait —
# просто пауза delay_days, шаг считается success. Для остальных — делегат в
# automation_executor._action_<kind>.


class Sequence(Base):
    """Шаблон многошаговой последовательности действий.

    Создаётся вручную в админке (либо при импорте из YAML — отложено). steps_json
    хранится как list of dict: см. модуль выше.
    """

    __tablename__ = "sequences"

    id: Mapped[int] = mapped_column(primary_key=True)
    name: Mapped[str] = mapped_column(String(255))
    description: Mapped[str | None] = mapped_column(Text, nullable=True)
    # Шаги: [{kind, config, delay_days}, ...]. Хранится одним JSON, без отдельной
    # дочерней таблицы — порядок и состав часто меняются целиком.
    steps_json: Mapped[list[dict[str, Any]]] = mapped_column(JSON, default=list)
    is_active: Mapped[bool] = mapped_column(Boolean, default=True, index=True)
    created_by_user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True
    )
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now()
    )
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), onupdate=func.now()
    )

    created_by: Mapped[User | None] = relationship(foreign_keys=[created_by_user_id])
    runs: Mapped[list[SequenceRun]] = relationship(  # type: ignore[name-defined]
        back_populates="sequence",
        cascade="all, delete-orphan",
        foreign_keys="SequenceRun.sequence_id",
    )


class SequenceRun(Base):
    """Конкретный запуск Sequence для цели (deal / lead / subscription).

    status:
    - pending   — создан, ждёт первого тика cron (next_step_at <= now())
    - running   — хотя бы один шаг выполнен, ждёт следующего
    - completed — все шаги пройдены, finished_at заполнен
    - failed    — критическая ошибка (например, неизвестный action_kind в шаге)
    - cancelled — снят вручную через API

    result_json — массив результатов по каждому выполненному шагу (для аудита).
    """

    __tablename__ = "sequence_runs"

    id: Mapped[int] = mapped_column(primary_key=True)
    sequence_id: Mapped[int] = mapped_column(
        ForeignKey("sequences.id", ondelete="CASCADE"), index=True
    )
    # 'deal' | 'lead' | 'subscription'
    target_type: Mapped[str] = mapped_column(String(32))
    target_id: Mapped[int] = mapped_column(Integer)
    # Индекс СЛЕДУЮЩЕГО шага к выполнению (0 = ещё не начали)
    current_step_index: Mapped[int] = mapped_column(Integer, default=0)
    # 'pending' | 'running' | 'completed' | 'failed' | 'cancelled'
    status: Mapped[str] = mapped_column(String(16), default="pending", index=True)
    started_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now()
    )
    next_step_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True), nullable=True, index=True
    )
    finished_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True), nullable=True
    )
    result_json: Mapped[list[dict[str, Any]] | None] = mapped_column(JSON, nullable=True)

    sequence: Mapped[Sequence] = relationship(
        back_populates="runs", foreign_keys=[sequence_id]
    )

    __table_args__ = (
        Index("ix_sequence_runs_target", "target_type", "target_id"),
        # Композитный — главный путь cron сканера: WHERE status IN ('pending','running')
        # AND next_step_at <= now() ORDER BY next_step_at.
        Index("ix_sequence_runs_status_next", "status", "next_step_at"),
    )


# ============ Card 2.0 — Custom Fields / Audit / Duplicates / SavedFilters (Эпик 8) ============
# Расширения карточек сущностей:
# - CustomFieldDef          — динамические определения полей по scope (lead/contact/...).
#                             Значения хранятся в Entity.extra_fields (JSONB по code).
# - EntityAuditLog          — generic audit log (отдельно от старого AuditLog для
#                             contracts: тот привязан к contract_id, этот — generic).
# - DismissedDuplicate      — пометка «это не дубль», чтобы дублескан не показывал
#                             ту же пару снова.
# - SavedFilter             — сохранённые сегменты по page_key (user_id NULL = глобальный).


class CustomFieldDef(Base):
    """Определение кастомного поля по scope. Значения — в Entity.extra_fields[code]."""

    __tablename__ = "custom_field_defs"

    id: Mapped[int] = mapped_column(primary_key=True)
    # 'lead' | 'contact' | 'company' | 'counterparty' | 'deal' | 'contract' | 'subscription'
    entity_scope: Mapped[str] = mapped_column(String(32), index=True)
    code: Mapped[str] = mapped_column(String(64))  # snake_case
    label_ru: Mapped[str] = mapped_column(String(255))
    # 'text' | 'textarea' | 'number' | 'date' | 'select' | 'multiselect' | 'url' | 'checkbox'
    kind: Mapped[str] = mapped_column(String(16))
    is_required: Mapped[bool] = mapped_column(Boolean, default=False)
    # null допустим; для select/multiselect — строка либо массив
    default_value: Mapped[Any | None] = mapped_column(JSONB, nullable=True)
    # Массив вариантов для select/multiselect (иначе [])
    options_json: Mapped[list[Any]] = mapped_column(JSONB, default=list)
    sort_order: Mapped[int] = mapped_column(Integer, default=0)
    is_active: Mapped[bool] = mapped_column(Boolean, default=True, index=True)
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now()
    )
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), onupdate=func.now()
    )

    __table_args__ = (
        UniqueConstraint("entity_scope", "code", name="uq_cfd_scope_code"),
    )


class EntityAuditLog(Base):
    """Generic audit log по любой сущности (отдельно от AuditLog для контрактов).

    Hot WHERE — (entity_type, entity_id, occurred_at DESC). Композитный индекс
    создан в миграции 0026 с DESC на occurred_at.

    diff_json формат:
    - action='update'  → {"fields": {field_name: {"old": ..., "new": ...}}}
    - action='create'  → {"snapshot": {...}} (опционально)
    - action='delete'  → {"snapshot": {...}} (опционально)
    - action='merge'   → {"merged_from": id, "field_choices": {...}}
    - action='extra_fields_change' → {"old": {...}, "new": {...}}
    - action='bulk_action' → {"bulk_action": "...", "count": n}
    """

    __tablename__ = "entity_audit_logs"

    id: Mapped[int] = mapped_column(primary_key=True)
    entity_type: Mapped[str] = mapped_column(String(32))
    entity_id: Mapped[int] = mapped_column(Integer)
    user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True, index=True
    )
    # 'create' | 'update' | 'delete' | 'merge' | 'extra_fields_change' | 'bulk_action'
    action: Mapped[str] = mapped_column(String(32))
    diff_json: Mapped[dict[str, Any] | None] = mapped_column(JSONB, nullable=True)
    occurred_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now()
    )
    request_id: Mapped[str | None] = mapped_column(String(64), nullable=True)

    user: Mapped[User | None] = relationship(foreign_keys=[user_id])

    # Композитный (entity_type, entity_id, occurred_at DESC) — создан в миграции.


class DismissedDuplicate(Base):
    """Помечено пользователем как «не дубль» — чтобы скан не показывал ту же пару.

    entity_a_id < entity_b_id — нормализуется в сервисе перед insert'ом.
    """

    __tablename__ = "dismissed_duplicates"

    id: Mapped[int] = mapped_column(primary_key=True)
    # 'counterparty' | 'contact' | 'company' | 'lead'
    entity_type: Mapped[str] = mapped_column(String(32), index=True)
    entity_a_id: Mapped[int] = mapped_column(Integer)
    entity_b_id: Mapped[int] = mapped_column(Integer)
    dismissed_by_user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True
    )
    dismissed_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now()
    )

    __table_args__ = (
        UniqueConstraint(
            "entity_type", "entity_a_id", "entity_b_id", name="uq_dismissed_pair"
        ),
    )


class SavedFilter(Base):
    """Сохранённый сегмент по page_key. user_id NULL = глобальный (admin-only)."""

    __tablename__ = "saved_filters"

    id: Mapped[int] = mapped_column(primary_key=True)
    user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="CASCADE"), nullable=True
    )
    # 'leads' | 'contacts' | 'companies' | 'counterparties' | 'deals' | 'registry'
    page_key: Mapped[str] = mapped_column(String(64))
    name: Mapped[str] = mapped_column(String(128))
    filter_json: Mapped[dict[str, Any]] = mapped_column(JSONB, default=dict)
    is_pinned: Mapped[bool] = mapped_column(Boolean, default=False)
    # Tech Sprint Фаза 0: drag-n-drop порядок в UI. Сортировка в list endpoint:
    # is_pinned DESC, sort_order ASC, created_at DESC (старая семантика как
    # tiebreaker для незаданного sort_order).
    sort_order: Mapped[int] = mapped_column(Integer, default=0)
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now()
    )

    user: Mapped[User | None] = relationship(foreign_keys=[user_id])


# ============ Public API tokens / Webhooks (Эпик 11.1 + 11.2) ============
# Public API под Bearer-токены (opt-in поверх существующего cookie-auth для UI)
# + Outbound Webhooks с retry/HMAC. Cookie-auth для UI остаётся primary, Bearer
# — это дополнительный канал доступа к тем же endpoint'ам (через
# `get_current_user_flexible`), а также к /api/* в целом для внешних
# интеграций.
#
# APIToken: храним только SHA256(plaintext) в БД, plaintext возвращается клиенту
# единственный раз — при create. После — только показ имени/scopes/last_used_at.
# Scope-формат: "read:leads" / "write:deals" / "*" (см. api_scopes.py).
#
# Webhook: ссылка наружу + список событий, на которые подписан (
# event_subscriptions). Dispatcher создаёт WebhookDelivery на каждое событие,
# cron подхватывает pending и шлёт через httpx с HMAC-SHA256 подписью.
#
# WebhookDelivery: запись о попытке доставки. attempt — счётчик попыток
# (6 max), next_retry_at — когда брать в следующий раз. status: pending →
# success / retrying / failed. retrying = retry запланирован; failed = max
# attempt'ов исчерпан, оператор может вручную перезапустить.


class APIToken(Base):
    """Public API token: opaque Bearer (mc_<urlsafe40>), хранится только SHA256.

    Token plaintext выдаётся клиенту единственный раз — при create. Дальше в БД
    только token_hash. При запросе по Bearer ищем SHA256(plaintext) в этой
    таблице (is_active, не отозван, не истёк) и возвращаем владельца.

    scopes — список разрешённых операций ("read:leads" / "write:deals" / "*").
    "*" = admin-уровень. Whitelist scope'ов — api_scopes.py.

    ondelete CASCADE на user_id: удаление пользователя автоматически чистит
    его токены (нет «вечно живущих» токенов уволенных сотрудников).
    """

    __tablename__ = "api_tokens"

    id: Mapped[int] = mapped_column(primary_key=True)
    user_id: Mapped[int] = mapped_column(
        ForeignKey("users.id", ondelete="CASCADE"), index=True
    )
    # Человекочитаемая метка ("zapier-integration" / "billing-script") — для UI.
    name: Mapped[str] = mapped_column(String(128))
    # SHA256(plaintext) hex — 64 символа. UNIQUE на index для O(1) lookup.
    token_hash: Mapped[str] = mapped_column(String(64), unique=True, index=True)
    # ["read:leads", "write:deals", ...] либо ["*"] для admin-уровня.
    scopes: Mapped[list[str]] = mapped_column(JSON, default=list)
    # Опциональная дата истечения (NULL = бессрочный, но revoke возможен всегда).
    expires_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True), nullable=True
    )
    last_used_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True), nullable=True
    )
    # IPv6 max — 45 chars; IPv4 — 15. Для аудита «откуда последний раз ходили».
    last_used_ip: Mapped[str | None] = mapped_column(String(45), nullable=True)
    is_active: Mapped[bool] = mapped_column(Boolean, default=True, index=True)
    # Epic 16 — Security: per-token rate limit (запросов в час). Token-bucket
    # в Redis (см. app/services/rate_limit.py). Default 1000 — разумный
    # для внешних интеграций; admin может бамп'нуть до 100000 для batch.
    rate_limit_per_hour: Mapped[int] = mapped_column(Integer, default=1000)
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now()
    )
    # NULL = не отозван; не-NULL = revoked_at время (is_active обнуляется
    # тоже — двойная защита, проверка в auth-deps идёт по is_active).
    revoked_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True), nullable=True
    )

    user: Mapped[User] = relationship(foreign_keys=[user_id])


class Webhook(Base):
    """Outbound webhook: куда слать события + HMAC-секрет + список подписок.

    event_subscriptions: ["lead.created", "deal.won"] либо ["*"] для подписки
    на все события (whitelist — webhook_events.py).

    secret: общий с подписчиком. Используется для HMAC-SHA256 подписи payload'а.
    Подписчик валидирует X-Macro-Signature: sha256=<hex>.

    headers: опциональные кастомные заголовки (например, Authorization для
    внутреннего API подписчика). Сериализуются в JSON, шлются как есть.
    """

    __tablename__ = "webhooks"

    id: Mapped[int] = mapped_column(primary_key=True)
    name: Mapped[str] = mapped_column(String(128))
    url: Mapped[str] = mapped_column(String(512))
    # Общий с подписчиком secret для HMAC-SHA256.
    secret: Mapped[str] = mapped_column(String(128))
    # ["lead.created", "deal.stage_changed", ...] или ["*"] для всех.
    event_subscriptions: Mapped[list[str]] = mapped_column(JSON, default=list)
    is_active: Mapped[bool] = mapped_column(Boolean, default=True, index=True)
    # Опциональные кастомные header'ы (Authorization, X-* и т.п.).
    headers: Mapped[dict[str, Any] | None] = mapped_column(JSON, nullable=True)
    # Tech Sprint Фаза 0: per-webhook retry/timeout settings (раньше — глобальный
    # хардкод в webhook_dispatcher.py). default'ы — те же, что были в коде, кроме
    # max_attempts (6 → 5: чуть мягче, никто не дотягивал до 6-й попытки).
    max_attempts: Mapped[int] = mapped_column(Integer, default=5)
    backoff_seconds: Mapped[int] = mapped_column(Integer, default=60)
    timeout_seconds: Mapped[int] = mapped_column(Integer, default=10)
    created_by_user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True
    )
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now()
    )
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), onupdate=func.now()
    )

    created_by: Mapped[User | None] = relationship(foreign_keys=[created_by_user_id])
    deliveries: Mapped[list[WebhookDelivery]] = relationship(  # type: ignore[name-defined]
        back_populates="webhook",
        cascade="all, delete-orphan",
        foreign_keys="WebhookDelivery.webhook_id",
    )


class WebhookDelivery(Base):
    """Одна попытка доставки webhook'а: payload + статус + retry-метаданные.

    status:
    - pending  — создана dispatch'ом, ждёт первый pick cron'ом
    - retrying — попытка упала (5xx/timeout/network), запланирован retry
    - success  — 2xx ответ, finished_at заполнен
    - failed   — все 6 attempts исчерпаны или 4xx (не ретраим клиентские)

    attempt: 0 в момент создания, инкрементируется на каждый pick (1..6).
    next_retry_at: когда брать в работу. NULL = success/failed (терминальные).
    last_response_body: усечён до 2KB (см. webhook_dispatcher.MAX_BODY_SAVE).

    Композитный индекс (status, next_retry_at) — главный hot path cron'а:
    WHERE status IN ('pending','retrying') AND next_retry_at <= now()
    ORDER BY next_retry_at.
    """

    __tablename__ = "webhook_deliveries"

    id: Mapped[int] = mapped_column(primary_key=True)
    webhook_id: Mapped[int] = mapped_column(
        ForeignKey("webhooks.id", ondelete="CASCADE"), index=True
    )
    # "lead.created" / "deal.stage_changed" / etc.
    event: Mapped[str] = mapped_column(String(64), index=True)
    payload: Mapped[dict[str, Any]] = mapped_column(JSON)
    # 'pending' | 'retrying' | 'success' | 'failed'
    status: Mapped[str] = mapped_column(String(16))
    attempt: Mapped[int] = mapped_column(Integer, default=0)
    next_retry_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True), nullable=True, index=True
    )
    last_http_code: Mapped[int | None] = mapped_column(Integer, nullable=True)
    last_error: Mapped[str | None] = mapped_column(Text, nullable=True)
    # Усечённое тело ответа (для диагностики). Большие тела (>2KB) обрезаются.
    last_response_body: Mapped[str | None] = mapped_column(Text, nullable=True)
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now()
    )
    finished_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True), nullable=True
    )

    webhook: Mapped[Webhook] = relationship(
        back_populates="deliveries", foreign_keys=[webhook_id]
    )

    __table_args__ = (
        # Hot path cron'а — pending/retrying готовые к retry.
        Index("ix_webhook_deliveries_status_next", "status", "next_retry_at"),
    )


# ============ Онбординг (Эпик 13) ============
# Обучающие курсы для новых сотрудников. Богдан наполняет контент через admin
# UI после деплоя — нет seed-курсов. Auto-assign по target_roles при POST /users
# (см. services/onboarding/auto_assign.py).
#
# Иерархия:
#   Course (target_roles, is_published, passing_score_pct)
#   └── CourseModule (order_index)
#       └── CourseLesson (kind: theory | video | quiz, content_blocks JSONB)
#           └── LessonQuizQuestion (single/multi choice, points)
#
# Прогресс:
#   UserCourseAssignment (user × course, deadline_at)
#   CourseProgress (user × course, status, percent, lesson_states JSONB)
#   QuizAttempt (user × lesson, answers JSONB, score_pct, passed)
#
# Soft-gate: overdue mandatory курсы блокируют bulk-действия
# (см. services/onboarding/progress.enforce_soft_gate).


class Course(Base):
    """Обучающий курс для онбординга новых сотрудников (Эпик 13)."""

    __tablename__ = "courses"

    id: Mapped[int] = mapped_column(primary_key=True)
    title: Mapped[str] = mapped_column(String(255))
    description: Mapped[str | None] = mapped_column(Text, nullable=True)
    cover_image_url: Mapped[str | None] = mapped_column(String(512), nullable=True)
    # JSONB ?| array для поиска курсов по роли пользователя в auto_assign.
    # Значения — strings из UserRole ('admin','director','lawyer','manager').
    # Пустой список = курс на ВСЕХ ролей.
    target_roles: Mapped[list[str]] = mapped_column(JSONB, default=list)
    # Опубликован — auto-assign'ится новым юзерам и показывается им в /onboarding.
    # Неопубликованный — виден только в admin UI; не назначается; не блокирует.
    is_published: Mapped[bool] = mapped_column(Boolean, default=False, index=True)
    # Минимальный % правильных ответов для зачёта финального quiz'а курса.
    passing_score_pct: Mapped[int] = mapped_column(Integer, default=80)
    # 'informational' — курс рекомендуется, но overdue не блокирует ничего;
    # 'soft_gate' — overdue mandatory курсы блокируют bulk-действия (см.
    # enforce_soft_gate в services/onboarding/progress.py). CHECK на БД.
    completion_policy: Mapped[str] = mapped_column(String(16), default="soft_gate")
    # Сколько дней даём на прохождение от момента назначения. Используется в
    # auto_assign для расчёта deadline_at.
    deadline_days: Mapped[int] = mapped_column(Integer, default=5)
    created_by_user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True
    )
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now()
    )
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), onupdate=func.now()
    )

    created_by: Mapped[User | None] = relationship(foreign_keys=[created_by_user_id])
    modules: Mapped[list[CourseModule]] = relationship(  # type: ignore[name-defined]
        back_populates="course",
        cascade="all, delete-orphan",
        foreign_keys="CourseModule.course_id",
        order_by="CourseModule.order_index",
    )


class CourseModule(Base):
    """Модуль курса (группа уроков). Порядок задаётся order_index."""

    __tablename__ = "course_modules"

    id: Mapped[int] = mapped_column(primary_key=True)
    course_id: Mapped[int] = mapped_column(
        ForeignKey("courses.id", ondelete="CASCADE"), index=True
    )
    title: Mapped[str] = mapped_column(String(255))
    order_index: Mapped[int] = mapped_column(Integer)
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now()
    )

    course: Mapped[Course] = relationship(
        back_populates="modules", foreign_keys=[course_id]
    )
    lessons: Mapped[list[CourseLesson]] = relationship(  # type: ignore[name-defined]
        back_populates="module",
        cascade="all, delete-orphan",
        foreign_keys="CourseLesson.module_id",
        order_by="CourseLesson.order_index",
    )

    __table_args__ = (
        UniqueConstraint("course_id", "order_index", name="uq_course_module_order"),
    )


class CourseLesson(Base):
    """Урок: theory (markdown), video (embed), или quiz (вопросы).

    content_blocks — JSONB список блоков. Формат:
        [{"kind": "markdown", "text": "..."},
         {"kind": "image", "url": "...", "caption": "..."},
         {"kind": "drive_video", "drive_url": "https://drive.google.com/file/d/X/preview"},
         {"kind": "loom_video", "loom_url": "https://www.loom.com/share/X"},
         {"kind": "youtube_video", "youtube_id": "X"},
         {"kind": "callout", "style": "info|warning|success|danger", "text": "..."}]
    Для kind='quiz' content_blocks игнорируется — вопросы в LessonQuizQuestion.
    Валидация формата — в services/onboarding/courses.validate_content_blocks.
    """

    __tablename__ = "course_lessons"

    id: Mapped[int] = mapped_column(primary_key=True)
    module_id: Mapped[int] = mapped_column(
        ForeignKey("course_modules.id", ondelete="CASCADE"), index=True
    )
    title: Mapped[str] = mapped_column(String(255))
    # 'theory' | 'video' | 'quiz' — CHECK на БД.
    kind: Mapped[str] = mapped_column(String(16))
    content_blocks: Mapped[list[dict[str, Any]]] = mapped_column(JSONB, default=list)
    duration_min: Mapped[int | None] = mapped_column(Integer, nullable=True)
    order_index: Mapped[int] = mapped_column(Integer)
    # Если False — урок опциональный и не учитывается в percent курса.
    is_required: Mapped[bool] = mapped_column(Boolean, default=True)
    # Tech Sprint Фаза 0: для kind='quiz' admin может пометить «перемешать вопросы».
    # Shuffle стабилен в рамках одной попытки (seed = attempt.id), см.
    # services/onboarding/quiz.randomize_questions.
    randomize_questions: Mapped[bool] = mapped_column(Boolean, default=False)
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now()
    )
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), onupdate=func.now()
    )

    module: Mapped[CourseModule] = relationship(
        back_populates="lessons", foreign_keys=[module_id]
    )
    questions: Mapped[list[LessonQuizQuestion]] = relationship(  # type: ignore[name-defined]
        back_populates="lesson",
        cascade="all, delete-orphan",
        foreign_keys="LessonQuizQuestion.lesson_id",
        order_by="LessonQuizQuestion.order_index",
    )

    __table_args__ = (
        UniqueConstraint("module_id", "order_index", name="uq_lesson_module_order"),
        Index(
            "ix_course_lessons_module_id_order",
            "module_id",
            "order_index",
        ),
    )


class LessonQuizQuestion(Base):
    """Вопрос внутри quiz-урока.

    options: ["Вариант 1", "Вариант 2", ...] — массив строк.
    correct_answers: [0, 2] — индексы правильных вариантов (0-based).
    Для kind='single' допускается ровно 1 элемент в correct_answers.
    explanation — показывается ПОСЛЕ ответа (правильного или нет).

    SECURITY: для student API correct_answers + explanation НЕ возвращаются
    (страйп в роутере, см. apps/api/app/routers/onboarding.py).
    """

    __tablename__ = "lesson_quiz_questions"

    id: Mapped[int] = mapped_column(primary_key=True)
    lesson_id: Mapped[int] = mapped_column(
        ForeignKey("course_lessons.id", ondelete="CASCADE"), index=True
    )
    question: Mapped[str] = mapped_column(Text)
    # 'single' | 'multi' — CHECK на БД.
    kind: Mapped[str] = mapped_column(String(16))
    options: Mapped[list[str]] = mapped_column(JSONB)
    correct_answers: Mapped[list[int]] = mapped_column(JSONB)
    points: Mapped[int] = mapped_column(Integer, default=1)
    order_index: Mapped[int] = mapped_column(Integer)
    explanation: Mapped[str | None] = mapped_column(Text, nullable=True)
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now()
    )

    lesson: Mapped[CourseLesson] = relationship(
        back_populates="questions", foreign_keys=[lesson_id]
    )

    __table_args__ = (
        Index(
            "ix_lesson_quiz_questions_lesson_id_order",
            "lesson_id",
            "order_index",
        ),
    )


class UserCourseAssignment(Base):
    """Назначение курса пользователю.

    assigned_by_user_id IS NULL → auto-assign (по target_roles при POST /users
    или из миграции 0032/UI «Назначить существующим»). Не-NULL → ручное назначение.
    deadline_at IS NULL → бессрочно (например, опциональный курс).
    """

    __tablename__ = "user_course_assignments"

    id: Mapped[int] = mapped_column(primary_key=True)
    user_id: Mapped[int] = mapped_column(
        ForeignKey("users.id", ondelete="CASCADE"), index=True
    )
    course_id: Mapped[int] = mapped_column(
        ForeignKey("courses.id", ondelete="CASCADE")
    )
    assigned_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now()
    )
    assigned_by_user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True
    )
    deadline_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True), nullable=True
    )
    is_mandatory: Mapped[bool] = mapped_column(Boolean, default=True)

    user: Mapped[User] = relationship(foreign_keys=[user_id])
    course: Mapped[Course] = relationship(foreign_keys=[course_id])
    assigned_by: Mapped[User | None] = relationship(foreign_keys=[assigned_by_user_id])

    __table_args__ = (
        UniqueConstraint("user_id", "course_id", name="uq_user_course_assignment"),
    )


class CourseProgress(Base):
    """Прогресс пользователя по одному курсу.

    lesson_states формат:
        {
            "<lesson_id>": {
                "completed_at": "2026-06-01T10:00:00+00:00",
                "attempts_count": 2,
                "best_score_pct": 90
            },
            ...
        }
    Ключи строки (str(lesson_id)) — JSONB не любит int-ключи.
    percent — кеш computed от lesson_states (см. compute_course_percent).
    """

    __tablename__ = "course_progress"

    id: Mapped[int] = mapped_column(primary_key=True)
    user_id: Mapped[int] = mapped_column(
        ForeignKey("users.id", ondelete="CASCADE"), index=True
    )
    course_id: Mapped[int] = mapped_column(
        ForeignKey("courses.id", ondelete="CASCADE")
    )
    # 'not_started' | 'in_progress' | 'completed' | 'overdue' — CHECK на БД.
    status: Mapped[str] = mapped_column(String(16), default="not_started")
    started_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True), nullable=True
    )
    completed_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True), nullable=True
    )
    percent: Mapped[int] = mapped_column(Integer, default=0)
    lesson_states: Mapped[dict[str, Any]] = mapped_column(JSONB, default=dict)
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now()
    )
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), onupdate=func.now()
    )

    user: Mapped[User] = relationship(foreign_keys=[user_id])
    course: Mapped[Course] = relationship(foreign_keys=[course_id])

    __table_args__ = (
        UniqueConstraint("user_id", "course_id", name="uq_course_progress_user_course"),
        Index("ix_course_progress_course_status", "course_id", "status"),
    )


class QuizAttempt(Base):
    """Попытка прохождения quiz-урока.

    Append-only: после finish (finished_at заполнен) запись не редактируется.
    Partial UNIQUE INDEX (user_id, lesson_id) WHERE finished_at IS NULL —
    защита от двух открытых попыток одновременно (см. миграцию 0031).

    answers формат: [{"question_id": int, "selected_indices": [int, ...]}, ...]
    """

    __tablename__ = "quiz_attempts"

    id: Mapped[int] = mapped_column(primary_key=True)
    user_id: Mapped[int] = mapped_column(
        ForeignKey("users.id", ondelete="CASCADE")
    )
    lesson_id: Mapped[int] = mapped_column(
        ForeignKey("course_lessons.id", ondelete="CASCADE")
    )
    started_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now()
    )
    finished_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True), nullable=True
    )
    score_pct: Mapped[int | None] = mapped_column(Integer, nullable=True)
    answers: Mapped[list[dict[str, Any]] | None] = mapped_column(JSONB, nullable=True)
    passed: Mapped[bool] = mapped_column(Boolean, default=False)

    user: Mapped[User] = relationship(foreign_keys=[user_id])
    lesson: Mapped[CourseLesson] = relationship(foreign_keys=[lesson_id])

    __table_args__ = (
        Index("ix_quiz_attempts_user_lesson", "user_id", "lesson_id"),
    )


# ============ Visibility ACL (Epic 14) ============
# Матрица настроек видимости: одна строка на (entity_type, applies_to_role).
# Админ управляет матрицей через /api/admin/visibility-settings.
# При apply_scope_filter():
#  - сначала ищем правило для (entity_type, current_user.role),
#  - если нет — ищем правило для (entity_type, NULL) — fallback на «все роли»,
#  - если и его нет — scope='all' (бэквард-совместимое поведение).

class VisibilitySetting(Base):
    """Эпик 14: матрица доступа entity × role → scope."""

    __tablename__ = "visibility_settings"

    id: Mapped[int] = mapped_column(primary_key=True)
    # "lead" | "deal" | "contract" | "subscription" | "counterparty" | "company"
    entity_type: Mapped[str] = mapped_column(String(32), index=True)
    # "personal" | "department" | "department_and_children" | "all"
    scope: Mapped[str] = mapped_column(String(32), default="all")
    # NULL = применяется ко всем ролям (fallback). admin всегда видит всё —
    # запись для admin role игнорируется на уровне apply_scope_filter.
    applies_to_role: Mapped[str | None] = mapped_column(String(16), nullable=True)
    updated_by_user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True,
    )
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), onupdate=func.now(),
    )

    # UniqueConstraint — в миграции через partial-индекс по applies_to_role.
    # На уровне SQLAlchemy не дублируем (PG-specific).


# ============ SSO links (Эпик 16 — Security) ============
# Привязка User'а к внешнему OAuth провайдеру (Google/Yandex). Один user
# может иметь не более одной привязки на провайдер; один внешний аккаунт
# (provider, provider_user_id) → один user. На login через SSO:
# - если SSO-связь есть — берём user_id из неё → выдаём access_token
# - если нет — lookup по provider_email → создаём связь (link)
# - если и user по email нет — auto-create user без password_hash
#   (login через SSO only). См. app/routers/sso.py.


class UserSSOLink(Base):
    """Эпик 16: связь User'а с OAuth провайдером.

    UNIQUE(provider, provider_user_id) — один внешний аккаунт = один user
    (защита от race подмены при concurrent callback'ах).
    UNIQUE(user_id, provider) — один user не может иметь две привязки на
    один провайдер (нужен unlink перед сменой аккаунта).
    Удаление user CASCADE'ом удаляет связи (нет «висящих» SSO-токенов
    после увольнения).
    """

    __tablename__ = "user_sso_links"

    id: Mapped[int] = mapped_column(primary_key=True)
    user_id: Mapped[int] = mapped_column(
        ForeignKey("users.id", ondelete="CASCADE"), index=True,
    )
    # "google" | "yandex" — CHECK на БД-уровне.
    provider: Mapped[str] = mapped_column(String(16))
    # Google: sub (URL-safe ~21 chars). Yandex: id (числовой строкой).
    # 128 — щедрый лимит на будущее (Apple ID и т.п.).
    provider_user_id: Mapped[str] = mapped_column(String(128))
    # Email от провайдера (для UX «Войти как X из Google»). Может
    # отличаться от User.email при ребрендинге почты.
    provider_email: Mapped[str | None] = mapped_column(String(256), nullable=True)
    linked_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(),
    )

    user: Mapped[User] = relationship(
        back_populates="sso_links", foreign_keys=[user_id],
    )

    __table_args__ = (
        UniqueConstraint(
            "provider", "provider_user_id", name="uq_user_sso_provider_uid",
        ),
        UniqueConstraint(
            "user_id", "provider", name="uq_user_sso_user_provider",
        ),
    )


# ============ Эпик 20 — Performance Scale: async dup-scan jobs ============
# Раньше POST /api/duplicates/scan был синхронным — для 100 КА это ms, для
# 5000+ — секунды (full scan + normalize + union-find). Long polling блокирует
# uvicorn worker и сжирает API pool. Новый флоу:
# - POST /api/duplicates/scan → создаёт запись pending, запускает background
#   task через asyncio.create_task, возвращает {job_id, status} сразу.
# - GET /api/duplicates/scan/{job_id} → polling, возвращает result_json когда
#   completed.
# - GET /api/duplicates/scan/recent → последние N сканов (UI «недавние»).
# Redis-кеш живёт TTL=3600s параллельно — если есть, возвращаем immediately
# с from_cache=True (job не создаётся).


class DupScanJob(Base):
    """Async-джоба скана дублей (Эпик 20). Стартует через POST /duplicates/scan,
    обрабатывается background-таском, результат хранится в result_json."""

    __tablename__ = "dup_scan_jobs"

    id: Mapped[int] = mapped_column(primary_key=True)
    # counterparty | contact | company | lead — CHECK на БД.
    entity_type: Mapped[str] = mapped_column(String(32))
    # pending | running | completed | failed — CHECK на БД.
    status: Mapped[str] = mapped_column(String(16), default="pending")
    started_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(),
    )
    completed_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True), nullable=True,
    )
    # Полный результат (list of DuplicateGroup dict). NULL пока pending/running
    # или если failed. Хранится в БД для history даже когда Redis cache expired.
    result_json: Mapped[dict[str, Any] | None] = mapped_column(JSONB, nullable=True)
    # Текст ошибки если status=failed (ValueError или другой Exception).
    error_message: Mapped[str | None] = mapped_column(Text, nullable=True)
    # Кто запустил — для аудита. ON DELETE SET NULL чтобы увольнение
    # пользователя не теряло историю скана.
    triggered_by_user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True,
    )


# ============ Эпик 21 — UX Upgrade: in-app notifications ============
# Единая шина уведомлений в UI. НЕ заменяет TG-нотификации (они остаются
# параллельно), а дополняет: badge в шапке + выпадающий список со ссылками
# на сущности. Создаются inline в сервисах при event'ах (deal.won, task.assigned,
# approval.needed, course.assigned, contract.signed, mention, system).


class Notification(Base):
    """Эпик 21: запись в UI inbox юзера.

    kind держится строкой (whitelist на app-уровне в services/notifications.py)
    чтобы добавление нового типа не требовало миграции. Индекс
    idx_notifications_user(user_id, is_read, created_at DESC) покрывает оба
    основных query: list (sort) и count (filter).
    """

    __tablename__ = "notifications"

    id: Mapped[int] = mapped_column(primary_key=True)
    # Получатель. CASCADE — при увольнении нотификации удаляются разом
    # (нет смысла хранить inbox удалённого аккаунта).
    user_id: Mapped[int] = mapped_column(
        ForeignKey("users.id", ondelete="CASCADE"), nullable=False,
    )
    # task_assigned | deal_won | approval_needed | sla_breach |
    # course_assigned | contract_signed | mention | system
    kind: Mapped[str] = mapped_column(String(32), nullable=False)
    title: Mapped[str] = mapped_column(String(256), nullable=False)
    body: Mapped[str | None] = mapped_column(Text, nullable=True)
    # Относительный URL (например, /deals/123). Фронт сам строит absolute.
    link: Mapped[str | None] = mapped_column(Text, nullable=True)
    is_read: Mapped[bool] = mapped_column(
        Boolean, nullable=False, default=False, server_default="false",
    )
    read_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True), nullable=True,
    )
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(),
    )
    # Extension data per-kind: {deal_id, amount} для deal_won,
    # {approval_id, contract_id} для approval_needed, и т.д. JSONB → можно
    # фильтровать по ключам в будущем (например WHERE metadata->>'deal_id' = '123').
    # NB: переименовано в `meta` на Python-уровне т.к. `metadata` — зарезервированное
    # имя SQLAlchemy Base (Base.metadata). В БД колонка называется `metadata`.
    meta: Mapped[dict[str, Any] | None] = mapped_column(
        "metadata", JSONB, nullable=True,
    )


# ============ Epic 21.2 — Notification channels ============
# Расширяет эпик 21 (in-app Notification) до multi-channel fan-out.
# in_app (existing) + tg (existing telegram bot) + email (SMTP stub).
# Smiley story: один dispatch(user_id, kind) → читает preferences →
# fan-out по разрешённым каналам, использует templates для рендера.


class NotificationChannelPreference(Base):
    """Эпик 21.2: per-user × kind × channel матрица «получать ли нотификацию».

    По дефолту seed создаёт row(is_enabled=True) для каждой пары — юзер видит
    «все галочки включены» в UI «Настройки → Уведомления». Админ может
    приглушить шумную комбинацию (например `course_assigned × tg=false`).

    UniqueConstraint(user_id, kind, channel) — primary key для upsert.

    Whitelist kind/channel держим на app-уровне (см. NOTIFICATION_KINDS и
    NOTIFICATION_CHANNELS в services/notification_dispatcher.py) — добавление
    нового канала / kind НЕ требует миграции.
    """

    __tablename__ = "notification_channel_preferences"

    id: Mapped[int] = mapped_column(primary_key=True)
    # CASCADE: при увольнении preferences удаляются вместе с user (нет смысла
    # хранить настройки для несуществующего юзера).
    user_id: Mapped[int] = mapped_column(
        ForeignKey("users.id", ondelete="CASCADE"), nullable=False,
    )
    kind: Mapped[str] = mapped_column(String(32), nullable=False)
    # in_app | tg | email
    channel: Mapped[str] = mapped_column(String(16), nullable=False)
    is_enabled: Mapped[bool] = mapped_column(
        Boolean, nullable=False, default=True, server_default="true",
    )
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), nullable=False,
    )
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True),
        server_default=func.now(),
        onupdate=func.now(),
        nullable=False,
    )

    __table_args__ = (
        UniqueConstraint(
            "user_id", "kind", "channel",
            name="uq_ncp_user_kind_channel",
        ),
    )


class NotificationTemplate(Base):
    """Эпик 21.2: Jinja-шаблон уведомления per kind × channel × locale.

    body_template — основной текст с {{ переменная }}. subject — заголовок
    (для email/in_app; для tg обычно null — единый текст в body).
    variables JSONB — документация переменных для UI шаблонного редактора:
        [{"name": "task.title", "type": "string", "required": true},
         {"name": "creator.full_name", "type": "string"},
         ...]

    is_active=false → черновик / архив, не используется dispatch'ем.

    UniqueConstraint(kind, channel, locale) — один активный шаблон на
    комбинацию. Дефолтные шаблоны seedятся в seed_notification_templates.
    """

    __tablename__ = "notification_templates"

    id: Mapped[int] = mapped_column(primary_key=True)
    kind: Mapped[str] = mapped_column(String(32), nullable=False)
    channel: Mapped[str] = mapped_column(String(16), nullable=False)
    locale: Mapped[str] = mapped_column(
        String(8), nullable=False, default="ru", server_default="ru",
    )
    subject: Mapped[str | None] = mapped_column(Text, nullable=True)
    body_template: Mapped[str | None] = mapped_column(Text, nullable=True)
    # Документация переменных шаблона (для UI редактора).
    variables: Mapped[list[dict[str, Any]] | None] = mapped_column(
        JSONB, nullable=True,
    )
    is_active: Mapped[bool] = mapped_column(
        Boolean, nullable=False, default=True, server_default="true",
    )
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), nullable=False,
    )
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True),
        server_default=func.now(),
        onupdate=func.now(),
        nullable=False,
    )

    __table_args__ = (
        UniqueConstraint(
            "kind", "channel", "locale",
            name="uq_nt_kind_channel_locale",
        ),
    )


class NotificationBroadcast(Base):
    """Эпик 21.2: массовая рассылка от админа («всем менеджерам Москвы»).

    recipient_filter JSONB — комбинируемые фильтры:
        {role: 'manager'} | {department_id: 5} | {user_ids: [1,2,3]}
    Возможна комбинация {role: 'manager', department_id: 5}.

    channels JSONB list — какие каналы использовать: ["in_app"] (default)
    или ["in_app", "tg", "email"]. Если NULL → только in_app (consistent
    default).

    Статусная машина: pending → running → completed | failed.
    Счётчики delivered_count / failed_count обновляются по мере фонового
    dispatch'а; recipients_count резолвится один раз сразу после filter.

    initiated_by_user_id — кто запустил (для аудита). NULL после удаления
    юзера (compliance: лог рассылки не пропадает).
    """

    __tablename__ = "notification_broadcasts"

    id: Mapped[int] = mapped_column(primary_key=True)
    initiated_by_user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True,
    )
    # По дефолту 'system' (общее системное сообщение). Можно подменить.
    kind: Mapped[str] = mapped_column(
        String(32), nullable=False, default="system", server_default="system",
    )
    title: Mapped[str | None] = mapped_column(String(256), nullable=True)
    body: Mapped[str | None] = mapped_column(Text, nullable=True)
    link: Mapped[str | None] = mapped_column(Text, nullable=True)
    recipient_filter: Mapped[dict[str, Any] | None] = mapped_column(
        JSONB, nullable=True,
    )
    recipients_count: Mapped[int | None] = mapped_column(Integer, nullable=True)
    delivered_count: Mapped[int] = mapped_column(
        Integer, nullable=False, default=0, server_default="0",
    )
    failed_count: Mapped[int] = mapped_column(
        Integer, nullable=False, default=0, server_default="0",
    )
    # pending | running | completed | failed
    status: Mapped[str] = mapped_column(
        String(16), nullable=False, default="pending", server_default="pending",
    )
    # Список каналов: ["in_app"] / ["in_app","tg","email"] / null=only in_app
    channels: Mapped[list[str] | None] = mapped_column(JSONB, nullable=True)
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), nullable=False,
    )
    completed_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True), nullable=True,
    )


# ============ Эпик 22 — Cohort Analytics ============
# История смены lifecycle-этапов подписок. Заполняется application-level в
# registry.update_subscription при изменении lifecycle_stage_id.
# Нужна для точного cohort retention: без неё можно считать только текущий
# статус (approximation). С историей — видим когда конкретная подписка
# перешла в C0 (ушла), что позволяет восстановить retention по датам.


class SubscriptionStageHistory(Base):
    """Эпик 22: лог смены lifecycle-этапа подписки (application-level trigger).

    Пишется в PATCH /subscriptions/{id} при изменении lifecycle_stage_id.
    from_stage_code=NULL означает первую запись (backfill или начало истории).
    changed_by_user_id=NULL если изменение системное (cron/automation).
    """

    __tablename__ = "subscription_stage_history"

    id: Mapped[int] = mapped_column(primary_key=True)
    subscription_id: Mapped[int] = mapped_column(
        ForeignKey("client_subscriptions.id", ondelete="CASCADE"), nullable=False,
    )
    # NULL = первая запись (нет предыдущего этапа) или backfill
    from_stage_code: Mapped[str | None] = mapped_column(String(16), nullable=True)
    to_stage_code: Mapped[str | None] = mapped_column(String(16), nullable=False)
    changed_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), nullable=False,
    )
    changed_by_user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True,
    )

    __table_args__ = (
        # История конкретной подписки — JOIN + ORDER BY
        Index("idx_sub_history_sub", "subscription_id", "changed_at"),
        # Когортная агрегация: «все переходы в C0 за период»
        Index("idx_sub_history_stage", "to_stage_code", "changed_at"),
    )


# ============ Эпик 18 — AI Features: observability log ============

class AIRequestLog(Base):
    """Лог AI-запросов: token usage, latency, error rate per feature/user.

    Append-only: писать создаём, читать — для дашборда/аналитики и
    rate-limit countup'а. Не CRUD'им (не редактируем, не удаляем).

    NULL-семантика:
    - user_id NULL — system-call (cron). Сейчас не используется, в плане
      на эпик 7 (TG-бот с auto-announcer'ом).
    - entity_type / entity_id NULL — standalone-call без конкретной сущности
      (chat, free-form prompt). MVP — только связанные.
    - prompt_tokens / completion_tokens / model NULL — если упало до
      обращения к Anthropic API (например settings.anthropic_api_key пуст).
    """

    __tablename__ = "ai_request_log"

    id: Mapped[int] = mapped_column(primary_key=True)
    user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True,
    )
    # 'contract' | 'deal' | 'lead' | 'counterparty' | None
    entity_type: Mapped[str | None] = mapped_column(String(32), nullable=True)
    entity_id: Mapped[int | None] = mapped_column(Integer, nullable=True)
    # 'contract_analyze' | 'deal_prefill' | 'lead_prefill' | …
    feature: Mapped[str] = mapped_column(String(32))
    prompt_tokens: Mapped[int | None] = mapped_column(Integer, nullable=True)
    completion_tokens: Mapped[int | None] = mapped_column(Integer, nullable=True)
    total_tokens: Mapped[int | None] = mapped_column(Integer, nullable=True)
    model: Mapped[str | None] = mapped_column(String(64), nullable=True)
    duration_ms: Mapped[int | None] = mapped_column(Integer, nullable=True)
    # 'success' | 'error' | 'not_configured' | 'rate_limited'
    status: Mapped[str] = mapped_column(String(16), default="success")
    error_message: Mapped[str | None] = mapped_column(Text, nullable=True)
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(),
    )


# ============ Epic 10.5 — Личный кабинет менеджера / Motivational Card ============
# Мотивационная карта (МК) — документ расчёта зарплаты менеджера за месяц.
# Состоит из 3 компонентов: оклад (фикс) + комиссия (% от поступлений) +
# командный бонус (60/40% split при выполнении плана отдела).
# Мультивалютность: платежи в любой валюте → конвертация по курсу на дату.
# Источник: МК Илья Рогов ОП 2026 PDF (апрель 2026).


class CurrencyRate(Base):
    """Курс валюты на дату. Источник: exchangerate-api.com / manual / cbr_fallback.

    Пара (from_currency, to_currency, rate_date) уникальна.
    Cron обновляет ежедневно через services/currency.update_currency_rates.
    Запрос курса: SELECT WHERE date <= target_date ORDER BY date DESC LIMIT 1.
    """

    __tablename__ = "currency_rates"

    id: Mapped[int] = mapped_column(primary_key=True)
    from_currency: Mapped[str] = mapped_column(String(8), nullable=False)
    to_currency: Mapped[str] = mapped_column(String(8), nullable=False)
    rate: Mapped[Decimal] = mapped_column(Numeric(20, 8), nullable=False)
    rate_date: Mapped[date] = mapped_column(Date, nullable=False)
    # "exchangerate-api" | "manual" | "cbr_fallback"
    source: Mapped[str | None] = mapped_column(String(32), nullable=True)
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now()
    )

    __table_args__ = (
        UniqueConstraint(
            "from_currency", "to_currency", "rate_date",
            name="uq_currency_rate_from_to_date",
        ),
        Index("idx_currency_rates_date", "rate_date", "from_currency", "to_currency"),
    )


class CommissionRule(Base):
    """Правило начисления комиссии менеджеру.

    Пример: «10% от новых поступлений, зачисленных на РС. Только личные сделки.
    Первый платёж от контрагента. Подписанный договор. Сумма = план.»
    base_metric: 'new_income_payments' — сумма новых поступлений.
    scope: 'personal_deals' (только сделки owner = этот менеджер) | 'any_deal'.
    """

    __tablename__ = "commission_rules"

    id: Mapped[int] = mapped_column(primary_key=True)
    name: Mapped[str] = mapped_column(String(128), nullable=False)
    rate_pct: Mapped[Decimal] = mapped_column(Numeric(5, 2), nullable=False)
    base_metric: Mapped[str] = mapped_column(String(32), nullable=False)
    base_currency: Mapped[str] = mapped_column(
        String(8), nullable=False, default="RUB"
    )
    scope: Mapped[str] = mapped_column(String(32), nullable=False)
    applies_to_first_payment_only: Mapped[bool] = mapped_column(
        Boolean, default=True, nullable=False
    )
    requires_signed_contract: Mapped[bool] = mapped_column(
        Boolean, default=True, nullable=False
    )
    requires_amount_match_payment_plan: Mapped[bool] = mapped_column(
        Boolean, default=True, nullable=False
    )
    # "immediate" | "monthly" | "quarterly"
    payment_trigger: Mapped[str | None] = mapped_column(
        String(32), nullable=True, default="immediate"
    )
    payment_note: Mapped[str | None] = mapped_column(Text, nullable=True)
    is_active: Mapped[bool] = mapped_column(Boolean, default=True, nullable=False)
    created_by_user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True
    )
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now()
    )
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), onupdate=func.now()
    )

    created_by: Mapped[User | None] = relationship(foreign_keys=[created_by_user_id])


class TeamTarget(Base):
    """Цель команды (отдела) на месяц — используется для расчёта командного бонуса.

    Алгоритм бонуса (из PDF):
    - Условие: fact >= target_amount * bonus_min_completion_pct / 100
    - Пул: bonus_pool_amount + (n_members - 2) * bonus_per_additional_member
    - Часть 1 (60%): пропорционально вкладу менеджера в team_fact
    - Часть 2 (40%): поровну между членами команды

    UNIQUE по (department_id, pipeline_id, period_year, period_month, metric).
    """

    __tablename__ = "team_targets"

    id: Mapped[int] = mapped_column(primary_key=True)
    department_id: Mapped[int | None] = mapped_column(
        ForeignKey("departments.id", ondelete="SET NULL"), nullable=True, index=True
    )
    pipeline_id: Mapped[int | None] = mapped_column(
        ForeignKey("pipelines.id", ondelete="SET NULL"), nullable=True
    )
    period_year: Mapped[int] = mapped_column(Integer, nullable=False)
    period_month: Mapped[int] = mapped_column(Integer, nullable=False)
    # "new_income_total" | "ftm_count"
    metric: Mapped[str] = mapped_column(String(32), nullable=False)
    target_amount: Mapped[Decimal] = mapped_column(Numeric(15, 2), nullable=False)
    target_currency: Mapped[str] = mapped_column(
        String(8), nullable=False, default="RUB"
    )
    bonus_pool_amount: Mapped[Decimal | None] = mapped_column(
        Numeric(15, 2), nullable=True
    )
    bonus_pool_currency: Mapped[str | None] = mapped_column(
        String(8), nullable=True, default="KZT"
    )
    bonus_per_additional_member: Mapped[Decimal | None] = mapped_column(
        Numeric(15, 2), nullable=True, default=Decimal("100000")
    )
    bonus_min_completion_pct: Mapped[Decimal] = mapped_column(
        Numeric(5, 2), nullable=False, default=Decimal("80.00")
    )
    bonus_split_proportional_pct: Mapped[Decimal] = mapped_column(
        Numeric(5, 2), nullable=False, default=Decimal("60.00")
    )
    bonus_split_equal_pct: Mapped[Decimal] = mapped_column(
        Numeric(5, 2), nullable=False, default=Decimal("40.00")
    )
    is_active: Mapped[bool] = mapped_column(Boolean, default=True, nullable=False)
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now()
    )

    department: Mapped[Department | None] = relationship(
        foreign_keys=[department_id]
    )

    __table_args__ = (
        UniqueConstraint(
            "department_id",
            "pipeline_id",
            "period_year",
            "period_month",
            "metric",
            name="uq_team_target_dept_pipeline_period_metric",
        ),
        Index("idx_team_targets_period", "period_year", "period_month"),
    )


class SalaryPlan(Base):
    """План зарплаты конкретного менеджера на конкретный месяц.

    Создаётся администратором. Содержит: оклад + правило комиссии + командный
    план + личные KPI-планы. При расчёте МК (compute_motivational_card) делается
    snapshot этого плана в MotivationalCard.plan_snapshot_json — изменения задним
    числом не меняют зафиксированную МК.
    """

    __tablename__ = "salary_plans"

    id: Mapped[int] = mapped_column(primary_key=True)
    user_id: Mapped[int] = mapped_column(
        ForeignKey("users.id", ondelete="CASCADE"), nullable=False, index=True
    )
    period_year: Mapped[int] = mapped_column(Integer, nullable=False)
    period_month: Mapped[int] = mapped_column(Integer, nullable=False)
    supervisor_user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True
    )
    # Оклад
    base_salary_amount: Mapped[Decimal] = mapped_column(
        Numeric(15, 2), nullable=False
    )
    base_salary_currency: Mapped[str] = mapped_column(String(8), nullable=False)
    base_salary_payment_note: Mapped[str | None] = mapped_column(Text, nullable=True)
    # Комиссия
    commission_rule_id: Mapped[int | None] = mapped_column(
        ForeignKey("commission_rules.id", ondelete="SET NULL"), nullable=True
    )
    # Личный план (для расчёта % выполнения)
    personal_income_plan_amount: Mapped[Decimal | None] = mapped_column(
        Numeric(15, 2), nullable=True
    )
    personal_income_plan_currency: Mapped[str | None] = mapped_column(
        String(8), nullable=True
    )
    personal_ftm_plan: Mapped[int | None] = mapped_column(Integer, nullable=True)
    # Командный план
    team_target_id: Mapped[int | None] = mapped_column(
        ForeignKey("team_targets.id", ondelete="SET NULL"), nullable=True
    )
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now()
    )
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), onupdate=func.now()
    )

    user: Mapped[User] = relationship(foreign_keys=[user_id])
    supervisor: Mapped[User | None] = relationship(foreign_keys=[supervisor_user_id])
    commission_rule: Mapped[CommissionRule | None] = relationship(
        foreign_keys=[commission_rule_id]
    )
    team_target: Mapped[TeamTarget | None] = relationship(
        foreign_keys=[team_target_id]
    )

    __table_args__ = (
        UniqueConstraint(
            "user_id", "period_year", "period_month",
            name="uq_salary_plan_user_period",
        ),
    )


class MotivationalCard(Base):
    """Рассчитанная мотивационная карта менеджера за месяц.

    Статусы: draft (авторасчёт, можно пересчитать) → finalized (зафиксировано
    курсами и суммами, only read) → paid (оплата проведена).

    plan_snapshot_json — снимок SalaryPlan на момент финализации.
    exchange_rates_snapshot — курсы валют на exchange_rates_date.
    fact_commission_breakdown — [{contract_id, counterparty_name, payment_amount,
        payment_currency, commission_local, payment_date}, ...].
    """

    __tablename__ = "motivational_cards"

    id: Mapped[int] = mapped_column(primary_key=True)
    user_id: Mapped[int] = mapped_column(
        ForeignKey("users.id", ondelete="CASCADE"), nullable=False, index=True
    )
    period_year: Mapped[int] = mapped_column(Integer, nullable=False)
    period_month: Mapped[int] = mapped_column(Integer, nullable=False)
    # Snapshot плана на момент расчёта/финализации
    plan_snapshot_json: Mapped[dict[str, Any] | None] = mapped_column(
        JSONB, nullable=True
    )
    # Факт — оклад
    fact_base_salary_amount: Mapped[Decimal | None] = mapped_column(
        Numeric(15, 2), nullable=True
    )
    # Факт — комиссия
    fact_commission_amount: Mapped[Decimal | None] = mapped_column(
        Numeric(15, 2), nullable=True
    )
    fact_commission_currency: Mapped[str | None] = mapped_column(
        String(8), nullable=True
    )
    fact_commission_breakdown: Mapped[list[dict[str, Any]] | None] = mapped_column(
        JSONB, nullable=True
    )
    # Факт — командный бонус (60/40%)
    fact_team_bonus_proportional_amount: Mapped[Decimal | None] = mapped_column(
        Numeric(15, 2), nullable=True
    )
    fact_team_bonus_equal_amount: Mapped[Decimal | None] = mapped_column(
        Numeric(15, 2), nullable=True
    )
    # Итог в локальной валюте менеджера (UZS для Ильи, RUB по умолчанию)
    total_amount_local: Mapped[Decimal | None] = mapped_column(
        Numeric(15, 2), nullable=True
    )
    total_amount_currency_local: Mapped[str | None] = mapped_column(
        String(8), nullable=True
    )
    # Курсы на дату расчёта
    exchange_rates_snapshot: Mapped[dict[str, Any] | None] = mapped_column(
        JSONB, nullable=True
    )
    exchange_rates_date: Mapped[date | None] = mapped_column(Date, nullable=True)
    # Метрики работы (факт)
    ftm_count_fact: Mapped[int] = mapped_column(
        Integer, nullable=False, default=0
    )
    new_income_amount_fact: Mapped[Decimal | None] = mapped_column(
        Numeric(15, 2), nullable=True
    )
    new_income_currency_fact: Mapped[str | None] = mapped_column(
        String(8), nullable=True
    )
    # Статус жизненного цикла МК: draft / finalized / paid
    status: Mapped[str] = mapped_column(
        String(16), nullable=False, default="draft"
    )
    finalized_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True), nullable=True
    )
    finalized_by_user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True
    )
    paid_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True), nullable=True
    )
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now()
    )
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), onupdate=func.now()
    )

    user: Mapped[User] = relationship(foreign_keys=[user_id])
    finalized_by: Mapped[User | None] = relationship(
        foreign_keys=[finalized_by_user_id]
    )

    __table_args__ = (
        UniqueConstraint(
            "user_id", "period_year", "period_month",
            name="uq_motivational_card_user_period",
        ),
        Index("idx_motivational_cards_status", "status"),
    )


class ContractPayment(Base):
    """Платёж по договору — основа для расчёта комиссии менеджера.

    is_first_payment_from_counterparty — автоматически вычисляется при create:
    True если это первый платёж от данного контрагента (КА).
    attributed_to_user_id — кому идёт в зачёт комиссии (обычно owner сделки).
    """

    __tablename__ = "contract_payments"

    id: Mapped[int] = mapped_column(primary_key=True)
    contract_id: Mapped[int | None] = mapped_column(
        ForeignKey("contracts.id", ondelete="CASCADE"), nullable=True, index=True
    )
    counterparty_id: Mapped[int | None] = mapped_column(
        ForeignKey("counterparties.id", ondelete="SET NULL"), nullable=True
    )
    amount: Mapped[Decimal] = mapped_column(Numeric(15, 2), nullable=False)
    currency: Mapped[str] = mapped_column(String(8), nullable=False)
    payment_date: Mapped[date] = mapped_column(Date, nullable=False)
    attributed_to_user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True
    )
    is_first_payment_from_counterparty: Mapped[bool] = mapped_column(
        Boolean, nullable=False, default=False
    )
    notes: Mapped[str | None] = mapped_column(Text, nullable=True)
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now()
    )

    contract: Mapped[Contract | None] = relationship(foreign_keys=[contract_id])
    counterparty: Mapped[Counterparty | None] = relationship(
        foreign_keys=[counterparty_id]
    )
    attributed_to: Mapped[User | None] = relationship(
        foreign_keys=[attributed_to_user_id]
    )

    __table_args__ = (
        Index("idx_payments_user_date", "attributed_to_user_id", "payment_date"),
        Index("idx_payments_counterparty", "counterparty_id"),
    )


# ============ Эпик 15 — Integration Hub + Calldown + OAuth 2.0 ============


class IntegrationSettings(Base):
    """Единый admin-конфиг для всех integration-провайдеров (Эпик 15).

    Один ряд = один провайдер. Поле provider — whitelist в
    services/integrations.py::PROVIDERS. Чувствительные поля в `config`
    (например, api_key, oauth_refresh_token, client_secret) шифруются
    через services/totp.py::encrypt_secret/decrypt_secret (Fernet,
    settings.totp_encryption_key).

    webhook_secret — общий с провайдером secret для HMAC-верификации
    inbound webhook'ов (например, Mango подписывает callback'и
    HMAC-SHA256). Для outbound-only провайдеров (Whisper, AmoCRM-pull)
    остаётся NULL.

    last_sync_at — best-effort marker для cron full-sync'а. На MVP
    не используется активно, но поле есть «на вырост».
    """

    __tablename__ = "integration_settings"

    id: Mapped[int] = mapped_column(primary_key=True)
    # "calldown_mango" | "calldown_uis" | "whisper" | "amocrm" | "slack" | ...
    provider: Mapped[str] = mapped_column(String(32), unique=True)
    is_enabled: Mapped[bool] = mapped_column(Boolean, default=False, server_default="false")
    # Credentials и настройки per-provider. Чувствительные поля шифруются Fernet'ом.
    config: Mapped[dict[str, Any]] = mapped_column(JSONB, default=dict, server_default="{}")
    webhook_secret: Mapped[str | None] = mapped_column(String(64), nullable=True)
    last_sync_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True), nullable=True
    )
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now()
    )
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), onupdate=func.now()
    )


class CalldownCall(Base):
    """Лог звонка от Calldown-провайдера (Mango Office / UIS).

    Создаётся inbound webhook'ом после verify-signature → parse →
    match-counterparty → create-activity. Дедуп по (provider,
    external_call_id) — повторные доставки webhook'а не плодят дубли.

    direction: "in" (входящий) | "out" (исходящий) — CHECK на БД.
    from_number / to_number: формат E.164 (без +) или internal extension.
    transcript_status: "pending" | "processing" | "done" | "failed".

    counterparty/deal/activity — все опциональные. resolve по from_number
    (входящие) или to_number (исходящие). Если не нашлось — NULL,
    звонок всё равно сохраняется (для аудита).
    """

    __tablename__ = "calldown_calls"

    id: Mapped[int] = mapped_column(primary_key=True)
    # "calldown_mango" | "calldown_uis" — тот же whitelist что в IntegrationSettings.provider
    provider: Mapped[str] = mapped_column(String(32))
    external_call_id: Mapped[str | None] = mapped_column(String(128), nullable=True)
    # "in" | "out"
    direction: Mapped[str] = mapped_column(String(8))
    from_number: Mapped[str | None] = mapped_column(String(32), nullable=True)
    to_number: Mapped[str | None] = mapped_column(String(32), nullable=True)
    duration_seconds: Mapped[int | None] = mapped_column(Integer, nullable=True)
    started_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True), nullable=True
    )
    ended_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True), nullable=True
    )
    recording_url: Mapped[str | None] = mapped_column(Text, nullable=True)
    transcript_text: Mapped[str | None] = mapped_column(Text, nullable=True)
    # "pending" | "processing" | "done" | "failed"
    transcript_status: Mapped[str | None] = mapped_column(String(16), nullable=True)
    transcript_lang: Mapped[str | None] = mapped_column(String(8), nullable=True)
    user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True
    )
    counterparty_id: Mapped[int | None] = mapped_column(
        ForeignKey("counterparties.id", ondelete="SET NULL"), nullable=True
    )
    deal_id: Mapped[int | None] = mapped_column(
        ForeignKey("deals.id", ondelete="SET NULL"), nullable=True
    )
    activity_id: Mapped[int | None] = mapped_column(
        ForeignKey("activities.id", ondelete="SET NULL"), nullable=True
    )
    raw_payload: Mapped[dict[str, Any] | None] = mapped_column(JSONB, nullable=True)
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now()
    )


class OAuthClient(Base):
    """Third-party приложение, зарегистрированное в OAuth 2.0 провайдере MACRO CRM.

    Confidential client model (RFC 6749). client_secret_hash — bcrypt;
    plaintext возвращается ОДИН раз при создании. redirect_uris — точечный
    whitelist (без wildcards). scopes — максимально-разрешённый набор для
    приложения; при /authorize запрос не может выйти за этот subset.
    """

    __tablename__ = "oauth_clients"

    id: Mapped[int] = mapped_column(primary_key=True)
    # URL-safe идентификатор приложения (генерится сервером).
    client_id: Mapped[str] = mapped_column(String(64), unique=True)
    # bcrypt-хэш client_secret. Plain возвращается ОДИН раз при создании.
    client_secret_hash: Mapped[str] = mapped_column(String(128))
    name: Mapped[str] = mapped_column(String(128))
    redirect_uris: Mapped[list[str]] = mapped_column(ARRAY(Text))
    scopes: Mapped[list[str]] = mapped_column(ARRAY(Text))
    created_by_user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True
    )
    is_active: Mapped[bool] = mapped_column(Boolean, default=True, server_default="true")
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now()
    )


class OAuthAuthorization(Base):
    """Короткоживущий authorization code между /authorize → /token (RFC 6749 §4.1).

    Создаётся при consent screen → redirect_uri. Жизнь — 10 минут
    (expires_at). После /token exchange ставим used=true (защита от
    replay). code_challenge — PKCE S256 (RFC 7636) base64url(SHA256(verifier)).
    """

    __tablename__ = "oauth_authorizations"

    id: Mapped[int] = mapped_column(primary_key=True)
    client_id: Mapped[int] = mapped_column(
        ForeignKey("oauth_clients.id", ondelete="CASCADE")
    )
    user_id: Mapped[int] = mapped_column(
        ForeignKey("users.id", ondelete="CASCADE")
    )
    # bcrypt-хэш plaintext code'а (plain отдаём в redirect_uri?code=...)
    code_hash: Mapped[str | None] = mapped_column(String(128), nullable=True)
    # PKCE S256 challenge = base64url(SHA256(code_verifier))
    code_challenge: Mapped[str | None] = mapped_column(String(128), nullable=True)
    redirect_uri: Mapped[str] = mapped_column(Text)
    scopes: Mapped[list[str] | None] = mapped_column(ARRAY(Text), nullable=True)
    expires_at: Mapped[datetime] = mapped_column(DateTime(timezone=True))
    used: Mapped[bool] = mapped_column(Boolean, default=False, server_default="false")
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now()
    )


class OAuthToken(Base):
    """Access + refresh пара после успешного /token обмена.

    access_token_hash / refresh_token_hash — SHA256 hex plaintext'а.
    Plain возвращается клиенту в /token response один раз. На /userinfo
    или другом protected resource находим по хэшу заголовка Bearer.
    """

    __tablename__ = "oauth_tokens"

    id: Mapped[int] = mapped_column(primary_key=True)
    client_id: Mapped[int] = mapped_column(
        ForeignKey("oauth_clients.id", ondelete="CASCADE")
    )
    user_id: Mapped[int] = mapped_column(
        ForeignKey("users.id", ondelete="CASCADE")
    )
    access_token_hash: Mapped[str | None] = mapped_column(
        String(128), unique=True, nullable=True
    )
    refresh_token_hash: Mapped[str | None] = mapped_column(
        String(128), unique=True, nullable=True
    )
    scopes: Mapped[list[str] | None] = mapped_column(ARRAY(Text), nullable=True)
    expires_at: Mapped[datetime] = mapped_column(DateTime(timezone=True))
    revoked: Mapped[bool] = mapped_column(Boolean, default=False, server_default="false")
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now()
    )


# ============ Epic 24 — Tasks v2 ============
# Полноценный задачник: категории задач с дефолтными ролями и шаблонами
# чек-листов, расширение Activity (priority/status/collaborators/checklist/
# attachments/related-links/recurrence).
#
# Архитектура: расширяем существующую Activity (НЕ создаём отдельную Task).
# Новые сущности:
#   TaskCategory      — справочник категорий задач (seed/admin)
#   TaskCategoryChecklistItem — пункты шаблонного чек-листа категории
#   ActivityCollaborator — соисполнитель/проверяющий/наблюдатель на задачу
#   ActivityChecklistItem — пункт чек-листа конкретной задачи
#   ActivityAttachment  — вложение задачи (files)
#   ActivityRelatedLink — связь задач (related/blocks/blocked_by/duplicates)


class TaskCategory(Base):
    """Категория задачи с настройками по умолчанию.

    При создании задачи с category_id — apply_category_defaults() скопирует
    description_template, checklist items и collaborators в новую Activity.
    """

    __tablename__ = "task_categories"

    id: Mapped[int] = mapped_column(primary_key=True)
    name: Mapped[str] = mapped_column(String(128))
    sort_order: Mapped[int] = mapped_column(Integer, default=0, server_default="0")
    # Исполнитель по умолчанию (можно переопределить при создании задачи)
    default_executor_user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True
    )
    # Администратор категории (кто может её редактировать; не влияет на задачи)
    admin_user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True
    )
    # Jinja2-шаблон описания ({{ title }}, {{ responsible_name }} и т.п.)
    description_template: Mapped[str | None] = mapped_column(Text, nullable=True)
    # При restrict_close_without_result=True — нельзя закрыть без result_text
    restrict_close_without_result: Mapped[bool] = mapped_column(
        Boolean, default=False, server_default="false"
    )
    # При auto_title_from_category=True — title задачи = category.name (UI)
    auto_title_from_category: Mapped[bool] = mapped_column(
        Boolean, default=False, server_default="false"
    )
    # Минимум вложений для закрытия задачи (0 = не требуется)
    required_file_count: Mapped[int] = mapped_column(Integer, default=0, server_default="0")
    is_active: Mapped[bool] = mapped_column(Boolean, default=True, server_default="true")
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), onupdate=func.now()
    )

    # Relationships
    default_executor: Mapped[User | None] = relationship(
        "User", foreign_keys=[default_executor_user_id]
    )
    admin: Mapped[User | None] = relationship(
        "User", foreign_keys=[admin_user_id]
    )
    checklist_items: Mapped[list[TaskCategoryChecklistItem]] = relationship(  # type: ignore[name-defined]
        "TaskCategoryChecklistItem",
        back_populates="category",
        cascade="all, delete-orphan",
        order_by="TaskCategoryChecklistItem.sort_order",
    )
    # M2M через junction-таблицы
    co_executor_links: Mapped[list[TaskCategoryCoExecutor]] = relationship(  # type: ignore[name-defined]
        "TaskCategoryCoExecutor", cascade="all, delete-orphan"
    )
    auditor_links: Mapped[list[TaskCategoryAuditor]] = relationship(  # type: ignore[name-defined]
        "TaskCategoryAuditor", cascade="all, delete-orphan"
    )
    observer_links: Mapped[list[TaskCategoryObserver]] = relationship(  # type: ignore[name-defined]
        "TaskCategoryObserver", cascade="all, delete-orphan"
    )


class TaskCategoryCoExecutor(Base):
    """Соисполнитель по умолчанию для категории задач."""

    __tablename__ = "task_category_co_executors"

    category_id: Mapped[int] = mapped_column(
        ForeignKey("task_categories.id", ondelete="CASCADE"), primary_key=True
    )
    user_id: Mapped[int] = mapped_column(
        ForeignKey("users.id", ondelete="CASCADE"), primary_key=True
    )
    user: Mapped[User] = relationship("User")


class TaskCategoryAuditor(Base):
    """Проверяющий по умолчанию для категории задач."""

    __tablename__ = "task_category_auditors"

    category_id: Mapped[int] = mapped_column(
        ForeignKey("task_categories.id", ondelete="CASCADE"), primary_key=True
    )
    user_id: Mapped[int] = mapped_column(
        ForeignKey("users.id", ondelete="CASCADE"), primary_key=True
    )
    user: Mapped[User] = relationship("User")


class TaskCategoryObserver(Base):
    """Наблюдатель по умолчанию для категории задач."""

    __tablename__ = "task_category_observers"

    category_id: Mapped[int] = mapped_column(
        ForeignKey("task_categories.id", ondelete="CASCADE"), primary_key=True
    )
    user_id: Mapped[int] = mapped_column(
        ForeignKey("users.id", ondelete="CASCADE"), primary_key=True
    )
    user: Mapped[User] = relationship("User")


class TaskCategoryChecklistItem(Base):
    """Пункт шаблонного чек-листа категории задачи."""

    __tablename__ = "task_category_checklist_items"

    id: Mapped[int] = mapped_column(primary_key=True)
    category_id: Mapped[int] = mapped_column(
        ForeignKey("task_categories.id", ondelete="CASCADE"), index=True
    )
    title: Mapped[str] = mapped_column(String(256))
    sort_order: Mapped[int] = mapped_column(Integer, default=0, server_default="0")

    category: Mapped[TaskCategory] = relationship(
        "TaskCategory", back_populates="checklist_items"
    )


class ActivityCollaborator(Base):
    """Дополнительный участник задачи: соисполнитель / проверяющий / наблюдатель.

    role: co_executor | auditor | observer
    Уникальность — по (activity_id, user_id, role).
    """

    __tablename__ = "activity_collaborators"

    id: Mapped[int] = mapped_column(primary_key=True)
    activity_id: Mapped[int] = mapped_column(
        ForeignKey("activities.id", ondelete="CASCADE"), index=True
    )
    user_id: Mapped[int] = mapped_column(
        ForeignKey("users.id", ondelete="CASCADE")
    )
    role: Mapped[str] = mapped_column(String(16))  # co_executor|auditor|observer
    added_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())

    user: Mapped[User] = relationship("User")

    __table_args__ = (
        UniqueConstraint("activity_id", "user_id", "role", name="uq_collab_activity_user_role"),
    )


class ActivityChecklistItem(Base):
    """Пункт чек-листа конкретной задачи."""

    __tablename__ = "activity_checklist_items"

    id: Mapped[int] = mapped_column(primary_key=True)
    activity_id: Mapped[int] = mapped_column(
        ForeignKey("activities.id", ondelete="CASCADE"), index=True
    )
    title: Mapped[str] = mapped_column(String(512))
    is_done: Mapped[bool] = mapped_column(Boolean, default=False, server_default="false")
    sort_order: Mapped[int] = mapped_column(Integer, default=0, server_default="0")
    completed_by_user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True
    )
    completed_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())

    completed_by: Mapped[User | None] = relationship("User")


class ActivityAttachment(Base):
    """Вложение к задаче (загружается через multipart; хранится в /uploads/activities/)."""

    __tablename__ = "activity_attachments"

    id: Mapped[int] = mapped_column(primary_key=True)
    activity_id: Mapped[int] = mapped_column(
        ForeignKey("activities.id", ondelete="CASCADE"), index=True
    )
    file_path: Mapped[str] = mapped_column(Text)
    original_name: Mapped[str | None] = mapped_column(String(256), nullable=True)
    file_size: Mapped[int | None] = mapped_column(Integer, nullable=True)
    mime_type: Mapped[str | None] = mapped_column(String(64), nullable=True)
    uploaded_by_user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True
    )
    uploaded_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())

    uploaded_by: Mapped[User | None] = relationship("User")


class ActivityRelatedLink(Base):
    """Связь между двумя задачами (граф зависимостей / дубликатов).

    link_type: related | blocks | blocked_by | duplicates
    Уникальность — по паре (activity_id_from, activity_id_to). Нет зеркального
    ограничения — семантика направленная (A blocks B ≠ B blocks A).
    """

    __tablename__ = "activity_related_links"

    id: Mapped[int] = mapped_column(primary_key=True)
    activity_id_from: Mapped[int] = mapped_column(
        ForeignKey("activities.id", ondelete="CASCADE"), index=True
    )
    activity_id_to: Mapped[int] = mapped_column(
        ForeignKey("activities.id", ondelete="CASCADE"), index=True
    )
    link_type: Mapped[str] = mapped_column(String(16), default="related")
    created_by_user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True
    )
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())

    created_by: Mapped[User | None] = relationship("User")

    __table_args__ = (
        UniqueConstraint("activity_id_from", "activity_id_to", name="uq_related_link_pair"),
    )


# ============ Epic 14.2 — Company Management ============
# Расширение User уже выше (см. блок «Epic 14.2 — Company Management»).
# Ниже — самостоятельные сущности: rights transfer audit + work schedules +
# user vacations + production calendar.


class RightsTransferLog(Base):
    """Заголовок операции передачи прав/задач/контрагентов.

    Создаётся при увольнении (services.rights_transfer.transfer_rights в
    /admin/users/{id}/dismiss) ИЛИ вручную admin'ом через
    /admin/rights-transfers.

    categories — массив строк из whitelist:
      'contacts' | 'deals' | 'tasks_assigner' | 'tasks_executor'
      | 'approvals' | 'settings'
    reversible_until — 7 дней; после — undo вернёт 400.
    """

    __tablename__ = "rights_transfer_logs"

    id: Mapped[int] = mapped_column(primary_key=True)
    from_user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True,
    )
    to_user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True,
    )
    initiated_by_user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True,
    )
    categories: Mapped[list[str]] = mapped_column(
        JSONB, default=list, server_default="[]",
    )
    reason: Mapped[str | None] = mapped_column(Text, nullable=True)
    executed_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(),
    )
    reversible_until: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True), nullable=True,
    )
    is_reverted: Mapped[bool] = mapped_column(
        Boolean, default=False, server_default="false",
    )
    reverted_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True), nullable=True,
    )
    reverted_by_user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True,
    )

    from_user: Mapped[User | None] = relationship(
        "User", foreign_keys=[from_user_id],
    )
    to_user: Mapped[User | None] = relationship(
        "User", foreign_keys=[to_user_id],
    )
    initiated_by: Mapped[User | None] = relationship(
        "User", foreign_keys=[initiated_by_user_id],
    )
    reverted_by: Mapped[User | None] = relationship(
        "User", foreign_keys=[reverted_by_user_id],
    )
    items: Mapped[list[RightsTransferItem]] = relationship(  # type: ignore[name-defined]
        back_populates="log",
        cascade="all, delete-orphan",
    )


class RightsTransferItem(Base):
    """Детализация: какой именно entity был передан.

    entity_type — 'counterparty' | 'deal' | 'lead' | 'activity'
      | 'approval' | 'setting'
    field_name — имя поля у entity, которое меняли:
      'responsible_user_id' | 'owner_user_id' | 'author_user_id'
      | 'assigner_user_id' | 'approver_user_id' | ...
    old_owner_user_id — для отката (UPDATE entity SET field = old_owner).
    """

    __tablename__ = "rights_transfer_items"

    id: Mapped[int] = mapped_column(primary_key=True)
    transfer_log_id: Mapped[int] = mapped_column(
        ForeignKey("rights_transfer_logs.id", ondelete="CASCADE"),
    )
    entity_type: Mapped[str] = mapped_column(String(32))
    entity_id: Mapped[int] = mapped_column(Integer)
    old_owner_user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True,
    )
    new_owner_user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True,
    )
    field_name: Mapped[str] = mapped_column(String(64))
    reverted_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True), nullable=True,
    )

    log: Mapped[RightsTransferLog] = relationship(
        back_populates="items",
    )


class WorkSchedule(Base):
    """Расписание работы per scope (отдел или конкретный юзер).

    scope_type — 'department' | 'user'.
    scope_id — department.id или users.id (без FK — динамический lookup
    через `services/work_schedule.py`).
    day_of_week — 0=Monday..6=Sunday (ISO 8601).
    meeting_slot_minutes — длительность одного слота для available-slots.
    """

    __tablename__ = "work_schedules"

    id: Mapped[int] = mapped_column(primary_key=True)
    scope_type: Mapped[str] = mapped_column(String(16))
    scope_id: Mapped[int] = mapped_column(Integer)
    day_of_week: Mapped[int] = mapped_column(Integer)
    is_working: Mapped[bool] = mapped_column(
        Boolean, default=True, server_default="true",
    )
    # TIME без timezone — расписание это локальный рабочий день.
    start_time: Mapped[time | None] = mapped_column(Time(), nullable=True)
    end_time: Mapped[time | None] = mapped_column(Time(), nullable=True)
    meeting_slot_minutes: Mapped[int] = mapped_column(
        Integer, default=30, server_default="30",
    )
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(),
    )
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), onupdate=func.now(),
    )

    __table_args__ = (
        UniqueConstraint(
            "scope_type", "scope_id", "day_of_week",
            name="uq_schedule_scope_day",
        ),
    )


class UserVacation(Base):
    """Отпуск / больничный / отгул / командировка.

    vacation_type — 'vacation' | 'sick_leave' | 'day_off' | 'business_trip'.
    substitute_user_id — приоритетнее User.substitute_user_id на период
    [start_date; end_date].
    approved_by_user_id NULL = pending approval; иначе approved.
    """

    __tablename__ = "user_vacations"

    id: Mapped[int] = mapped_column(primary_key=True)
    user_id: Mapped[int] = mapped_column(
        ForeignKey("users.id", ondelete="CASCADE"),
    )
    start_date: Mapped[date] = mapped_column(Date)
    end_date: Mapped[date] = mapped_column(Date)
    vacation_type: Mapped[str] = mapped_column(
        String(16), default="vacation", server_default="vacation",
    )
    substitute_user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True,
    )
    notes: Mapped[str | None] = mapped_column(Text, nullable=True)
    approved_by_user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True,
    )
    approved_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True), nullable=True,
    )
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(),
    )

    user: Mapped[User] = relationship("User", foreign_keys=[user_id])
    substitute: Mapped[User | None] = relationship(
        "User", foreign_keys=[substitute_user_id],
    )
    approved_by: Mapped[User | None] = relationship(
        "User", foreign_keys=[approved_by_user_id],
    )


class ProductionCalendarDay(Base):
    """Производственный календарь по странам (RU/KZ/UZ/AE).

    Используется services/production_calendar.add_working_days для расчёта
    «due_at + N рабочих дней» (skip выходных + праздников).
    is_short_day — сокращённый день (для KPI'ев; на расчёт add_working_days
    не влияет, день считается рабочим).
    """

    __tablename__ = "production_calendar"

    id: Mapped[int] = mapped_column(primary_key=True)
    country_code: Mapped[str] = mapped_column(String(2))
    year: Mapped[int] = mapped_column(Integer)
    date: Mapped[date] = mapped_column(Date)
    is_holiday: Mapped[bool] = mapped_column(
        Boolean, default=True, server_default="true",
    )
    is_short_day: Mapped[bool] = mapped_column(
        Boolean, default=False, server_default="false",
    )
    name: Mapped[str | None] = mapped_column(String(128), nullable=True)

    __table_args__ = (
        UniqueConstraint(
            "country_code", "date",
            name="uq_calendar_country_date",
        ),
    )


# ============ Эпик 24.3 — TG Natural Language Intent Log ============

class TGIntentLog(Base):
    """Лог NL-парсинга сообщений Telegram.

    Каждое текстовое сообщение из приватного чата бота попадает сюда:
    intent — что попросил пользователь (create_task|close_task|search_tasks|recommend|unknown),
    parsed_params — извлечённые Claude параметры в JSON,
    result_action_taken — что реально сделал executor (created_activity_id_42 и т.п.).

    Индексы:
    - (user_id, created_at DESC) — история интентов конкретного юзера
    - (status, created_at) WHERE status != 'processed' — мониторинг сбоев / action_required
    """

    __tablename__ = "tg_intent_logs"

    id: Mapped[int] = mapped_column(primary_key=True)
    user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True
    )
    tg_user_id: Mapped[int | None] = mapped_column(BigInteger, nullable=True)
    tg_chat_id: Mapped[int | None] = mapped_column(BigInteger, nullable=True)
    raw_message: Mapped[str] = mapped_column(Text, nullable=False)
    # create_task|close_task|search_tasks|recommend|unknown
    intent: Mapped[str | None] = mapped_column(String(32), nullable=True)
    # 0.00..1.00 — уверенность Claude в интенте
    intent_confidence: Mapped[Decimal | None] = mapped_column(Numeric(3, 2), nullable=True)
    # {responsible_user_id, due_at, title, ...} — параметры зависят от intent
    parsed_params: Mapped[dict | None] = mapped_column(JSONB, nullable=True)
    # Полный текст ответа Claude (для отладки)
    claude_response_text: Mapped[str | None] = mapped_column(Text, nullable=True)
    claude_tokens_used: Mapped[int | None] = mapped_column(Integer, nullable=True)
    duration_ms: Mapped[int | None] = mapped_column(Integer, nullable=True)
    # processed|failed|action_required
    status: Mapped[str] = mapped_column(
        String(16), nullable=False, default="processed", server_default="processed"
    )
    # created_activity_id_N | closed_activity_N | search_results_count_N
    result_action_taken: Mapped[str | None] = mapped_column(String(64), nullable=True)
    error_message: Mapped[str | None] = mapped_column(Text, nullable=True)
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now()
    )

    user: Mapped[User | None] = relationship("User", foreign_keys=[user_id])

    __table_args__ = (
        Index("idx_tg_intent_user", "user_id", "created_at"),
        Index(
            "idx_tg_intent_status",
            "status",
            "created_at",
            postgresql_where="status != 'processed'",
        ),
    )


# ============ Эпик 24.2 — Google Calendar 2-way sync ============
# Per-user привязка к Google Calendar через OAuth 2.0 refresh-token grant.
# Хранит зашифрованные tokens (Fernet) + per-user настройки sync (что
# синкать, какой календарь). Каждый юзер подключает свой Google аккаунт
# через /api/me/google-calendar/connect → /callback.
#
# Sync — incremental через events.list?syncToken=... (Google Calendar API).
# При первом полном pull syncToken=None → получаем все events за окно
# time_min..now+90d. Дальше каждый pull — incremental.
#
# Push сделок Activity → Google event делается hook'ом из routers/activities
# (POST/PATCH/DELETE) + cron'ом sync_all_users (раз в 5-10 минут).


class GoogleCalendarLink(Base):
    """Привязка пользователя MACRO CRM к Google Calendar (OAuth 2.0).

    UNIQUE(user_id) — один Google аккаунт на одного MACRO юзера. Чтобы
    переподключить другой — сначала DELETE link, потом /connect.
    """

    __tablename__ = "google_calendar_links"

    id: Mapped[int] = mapped_column(primary_key=True)
    user_id: Mapped[int] = mapped_column(
        ForeignKey("users.id", ondelete="CASCADE"), nullable=False,
    )
    # `sub` claim от Google ID token (стабильный идентификатор пользователя
    # у Google, не меняется при смене email).
    google_user_id: Mapped[str | None] = mapped_column(String(64), nullable=True)
    # Email из userinfo Google (для UI и логирования; может отличаться от
    # User.email если юзер подключил рабочий gmail с другим адресом).
    google_email: Mapped[str | None] = mapped_column(String(256), nullable=True)
    # Fernet-зашифрованные tokens. Декодируются через
    # services/google_calendar.py::decrypt_token. NULL = не задан.
    access_token_encrypted: Mapped[str | None] = mapped_column(Text, nullable=True)
    refresh_token_encrypted: Mapped[str | None] = mapped_column(Text, nullable=True)
    # UTC момент истечения access_token. До этого — используем без refresh;
    # после — обмениваем refresh → новый access (см. refresh_access_token).
    token_expires_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True), nullable=True,
    )
    # Массив scope URI, выданных Google (для аудита и при необходимости
    # понимать, что нам разрешено делать).
    scopes: Mapped[list[str] | None] = mapped_column(
        ARRAY(Text), nullable=True, default=list, server_default="{}",
    )
    # Какой календарь синкаем. 'primary' — основной календарь юзера; в будущем
    # можно дать UI selector других календарей юзера. Сейчас фиксируем primary.
    calendar_id: Mapped[str] = mapped_column(
        String(128), nullable=False, default="primary", server_default="primary",
    )
    # Master switch — юзер может приостановить sync без удаления tokens.
    sync_enabled: Mapped[bool] = mapped_column(
        Boolean, nullable=False, default=True, server_default="true",
    )
    # Что синкать. По умолчанию meeting=True (встречи — командное событие,
    # их полезно показывать в Google Calendar), call=False (звонки —
    # личное; обычно менеджеру не нужно в календаре).
    sync_meeting: Mapped[bool] = mapped_column(
        Boolean, nullable=False, default=True, server_default="true",
    )
    sync_call: Mapped[bool] = mapped_column(
        Boolean, nullable=False, default=False, server_default="false",
    )
    # Синкать только Activity где due_at имеет ненулевую компоненту времени
    # (т.е. конкретный слот, а не дата-напоминание). По умолчанию True —
    # реальные встречи всегда имеют время.
    sync_only_with_time: Mapped[bool] = mapped_column(
        Boolean, nullable=False, default=True, server_default="true",
    )
    # Время последнего успешного pull/push. Для UI «синхронизировано N минут назад».
    last_sync_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True), nullable=True,
    )
    # Google Calendar API sync token — для incremental pull events.list.
    # NULL = следующий pull будет полный (за окно time_min..now+90d).
    last_sync_token: Mapped[str | None] = mapped_column(String(256), nullable=True)
    connected_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), nullable=False,
    )

    user: Mapped[User] = relationship("User", foreign_keys=[user_id])

    __table_args__ = (
        UniqueConstraint("user_id", name="uq_gcal_user"),
    )


class GoogleCalendarEventLink(Base):
    """Соответствие Activity ↔ Google Calendar event.

    Один Activity может иметь один link на конкретный calendar_id;
    если юзер сменил calendar в настройках, старые linki остаются
    orphaned (с is_active=true но возможно недоступным calendar).

    sync_direction:
    - 'to_gcal'   — только push из CRM → Google (Activity is master)
    - 'from_gcal' — только импорт из Google → CRM (event is master)
    - 'both'      — двухсторонняя (default; конфликт разрешаем по updated_at).
    """

    __tablename__ = "google_calendar_event_links"

    id: Mapped[int] = mapped_column(primary_key=True)
    activity_id: Mapped[int] = mapped_column(
        ForeignKey("activities.id", ondelete="CASCADE"), nullable=False,
    )
    google_event_id: Mapped[str] = mapped_column(String(128), nullable=False)
    google_calendar_id: Mapped[str] = mapped_column(String(128), nullable=False)
    # ETag для optimistic concurrency: при PATCH передаём If-Match: <etag>;
    # 412 от Google = на стороне Google поменялось → инвалидируем link для
    # пересинхронизации в следующем pull.
    etag: Mapped[str | None] = mapped_column(String(128), nullable=True)
    last_synced_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), nullable=False,
    )
    sync_direction: Mapped[str] = mapped_column(
        String(20), nullable=False, default="both", server_default="both",
    )
    # Soft-delete: при разрыве связи ставим False; cron почистит/удалит
    # из Google если нужно (delete_gcal_event).
    is_active: Mapped[bool] = mapped_column(
        Boolean, nullable=False, default=True, server_default="true",
    )

    activity: Mapped[Activity] = relationship(
        "Activity", foreign_keys=[activity_id],
        back_populates="gcal_event_links",
    )

    __table_args__ = (
        UniqueConstraint(
            "activity_id", "google_calendar_id",
            name="uq_gcal_event_activity_cal",
        ),
    )


# ═══════════════════════════════════════════════════════════════════════════
# МОДУЛЬ «ФИНАНСЫ» — double-entry GL (Ф0, фундамент данных). Спека: J_phase0_LOCKED.
#
# Инварианты (агент-файл finance-specialist):
#   • Источник истины = fin_journal_entry + fin_ledger_line (Дт>0 / Кт<0).
#   • Σ ledger_line.amount_func по entry == 0 (DB-триггер fin_assert_entry_balanced).
#   • Деньги — Numeric/Decimal, ROUND_HALF_UP. Курсы Numeric(20,8), проценты Numeric(5,2).
#   • posted-проводка иммутабельна → только сторно (reverses_entry_id, status=reversed).
#   • Переводы (cashflow_category_id=NULL) и reversed — вне ДДС/P&L by construction.
# Posting engine / fx / balance / API — следующие чанки (НЕ здесь).
# ═══════════════════════════════════════════════════════════════════════════


class FinSettings(Base):
    """Глобальные настройки модуля (singleton). base_currency группы = RUB (настраиваемо → Ф4)."""

    __tablename__ = "fin_settings"

    id: Mapped[int] = mapped_column(primary_key=True)
    base_currency: Mapped[str] = mapped_column(String(8), nullable=False, default="RUB")
    base_currency_changed_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True), nullable=True
    )
    #: Явный opt-out владельца: разрешить авто-апрув заявки/реестра, если под цель НЕ
    #: нашёлся сценарий согласования. По умолчанию FALSE = безопасно (нет сценария →
    #: 422, расход НЕ проводится без согласования). См. CRITICAL #1 (байпас согласования).
    auto_approve_without_scenario: Mapped[bool] = mapped_column(
        Boolean, nullable=False, default=False, server_default="false"
    )
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), nullable=False
    )
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), onupdate=func.now(), nullable=False
    )


class FinLegalEntity(Base):
    """Юрлицо группы. Функциональная валюта по стране (kz→KZT, uz→UZS). Баланс — в ней."""

    __tablename__ = "fin_legal_entity"

    id: Mapped[int] = mapped_column(primary_key=True)
    name: Mapped[str] = mapped_column(String(255), nullable=False)
    country_code: Mapped[str] = mapped_column(String(2), nullable=False)  # НЕ unique
    functional_currency: Mapped[str] = mapped_column(String(8), nullable=False)
    vat_enabled: Mapped[bool] = mapped_column(
        Boolean, nullable=False, default=False, server_default="false"
    )
    tax_regime: Mapped[str] = mapped_column(
        String(20), nullable=False, default="no_vat", server_default="no_vat"
    )  # vat_general | vat_simplified | no_vat | usn
    vat_recognition: Mapped[str] = mapped_column(
        String(12), nullable=False, default="by_shipment", server_default="by_shipment"
    )  # by_shipment | by_payment
    tax_id: Mapped[str | None] = mapped_column(String(32), nullable=True)
    licensor_entity_id: Mapped[int | None] = mapped_column(
        ForeignKey("licensor_entities.id", ondelete="SET NULL"), nullable=True, index=True
    )
    requisites_json: Mapped[dict[str, Any] | None] = mapped_column(JSONB, nullable=True)
    is_active: Mapped[bool] = mapped_column(
        Boolean, nullable=False, default=True, server_default="true"
    )
    sort_order: Mapped[int] = mapped_column(Integer, nullable=False, default=0, server_default="0")
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), nullable=False
    )
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), onupdate=func.now(), nullable=False
    )


class FinVatRate(Base):
    """Ставка НДС (настраиваемая по странам/юрлицам). KZ ҚҚС 12% / UZ 12% / «Без НДС»."""

    __tablename__ = "fin_vat_rate"

    id: Mapped[int] = mapped_column(primary_key=True)
    legal_entity_id: Mapped[int | None] = mapped_column(
        ForeignKey("fin_legal_entity.id", ondelete="CASCADE"), nullable=True, index=True
    )
    country_code: Mapped[str | None] = mapped_column(String(2), nullable=True)
    name: Mapped[str] = mapped_column(String(64), nullable=False)
    rate_pct: Mapped[Decimal] = mapped_column(Numeric(5, 2), nullable=False)
    kind: Mapped[str] = mapped_column(String(12), nullable=False)  # standard|reduced|zero|exempt
    effective_from: Mapped[date | None] = mapped_column(Date, nullable=True)
    effective_to: Mapped[date | None] = mapped_column(Date, nullable=True)
    is_active: Mapped[bool] = mapped_column(
        Boolean, nullable=False, default=True, server_default="true"
    )

    __table_args__ = (
        UniqueConstraint("name", "country_code", name="uq_fin_vat_rate_name_country"),
    )


class FinAccountGl(Base):
    """План счетов (управленческий, 39 счетов, классы 1xxx–5xxx). Общий на группу."""

    __tablename__ = "fin_account_gl"

    id: Mapped[int] = mapped_column(primary_key=True)
    code: Mapped[str] = mapped_column(String(8), nullable=False, unique=True, index=True)
    name: Mapped[str] = mapped_column(String(255), nullable=False)
    type: Mapped[str] = mapped_column(String(12), nullable=False)  # asset|liability|equity|income|expense
    subtype: Mapped[str | None] = mapped_column(String(24), nullable=True)
    normal_side: Mapped[str] = mapped_column(String(4), nullable=False)  # dt|kt|both
    is_money: Mapped[bool] = mapped_column(
        Boolean, nullable=False, default=False, server_default="false"
    )
    requires_counterparty: Mapped[bool] = mapped_column(
        Boolean, nullable=False, default=False, server_default="false"
    )
    is_active: Mapped[bool] = mapped_column(
        Boolean, nullable=False, default=True, server_default="true"
    )
    sort_order: Mapped[int] = mapped_column(Integer, nullable=False, default=0, server_default="0")

    __table_args__ = (
        CheckConstraint(
            "type IN ('asset','liability','equity','income','expense')",
            name="ck_fin_account_gl_type",
        ),
        CheckConstraint(
            "normal_side IN ('dt','kt','both')", name="ck_fin_account_gl_side"
        ),
    )


class FinMoneyAccount(Base):
    """Денежный счёт/касса (банк/наличные/эквайринг/кошелёк). Остаток — производный из ledger."""

    __tablename__ = "fin_money_account"

    id: Mapped[int] = mapped_column(primary_key=True)
    legal_entity_id: Mapped[int] = mapped_column(
        ForeignKey("fin_legal_entity.id", ondelete="RESTRICT"), nullable=False, index=True
    )
    gl_account_id: Mapped[int] = mapped_column(
        ForeignKey("fin_account_gl.id", ondelete="RESTRICT"), nullable=False
    )
    name: Mapped[str] = mapped_column(String(255), nullable=False)
    account_type: Mapped[str] = mapped_column(String(16), nullable=False)  # bank|cash|acquiring|ewallet
    currency: Mapped[str] = mapped_column(String(8), nullable=False)
    initial_balance: Mapped[Decimal] = mapped_column(
        Numeric(18, 2), nullable=False, default=0, server_default="0"
    )
    is_active: Mapped[bool] = mapped_column(
        Boolean, nullable=False, default=True, server_default="true"
    )
    sort_order: Mapped[int] = mapped_column(Integer, nullable=False, default=0, server_default="0")
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), nullable=False
    )
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), onupdate=func.now(), nullable=False
    )


class FinOpType(Base):
    """Тип операции (UX) → posting_template + дефолты статьи/счёта + флаги отчётности."""

    __tablename__ = "fin_op_type"

    id: Mapped[int] = mapped_column(primary_key=True)
    code: Mapped[str] = mapped_column(String(32), nullable=False, unique=True, index=True)
    name: Mapped[str] = mapped_column(String(128), nullable=False)
    direction: Mapped[str] = mapped_column(String(10), nullable=False)  # in|out|transfer|none
    posting_template: Mapped[str] = mapped_column(String(24), nullable=False)
    default_cat_id: Mapped[int | None] = mapped_column(
        ForeignKey("fin_cashflow_category.id", ondelete="SET NULL"), nullable=True
    )
    default_gl_account_id: Mapped[int | None] = mapped_column(
        ForeignKey("fin_account_gl.id", ondelete="SET NULL"), nullable=True
    )
    counts_in_pnl: Mapped[bool] = mapped_column(
        Boolean, nullable=False, default=True, server_default="true"
    )
    counts_in_cashflow: Mapped[bool] = mapped_column(
        Boolean, nullable=False, default=True, server_default="true"
    )
    is_internal_transfer: Mapped[bool] = mapped_column(
        Boolean, nullable=False, default=False, server_default="false"
    )
    is_archived: Mapped[bool] = mapped_column(
        Boolean, nullable=False, default=False, server_default="false"
    )
    sort_order: Mapped[int] = mapped_column(Integer, nullable=False, default=0, server_default="0")


class FinCatSet(Base):
    """Набор статей ДДС (в Ф0 — один «SaaS-набор операций»)."""

    __tablename__ = "fin_cat_set"

    id: Mapped[int] = mapped_column(primary_key=True)
    name: Mapped[str] = mapped_column(String(128), nullable=False, unique=True)
    is_active: Mapped[bool] = mapped_column(
        Boolean, nullable=False, default=True, server_default="true"
    )


class FinCashflowCategory(Base):
    """Статья ДДС (прямой метод). Иерархия 3 уровня через parent_id. Тег на денежных строках."""

    __tablename__ = "fin_cashflow_category"

    id: Mapped[int] = mapped_column(primary_key=True)
    cat_set_id: Mapped[int] = mapped_column(
        ForeignKey("fin_cat_set.id", ondelete="RESTRICT"), nullable=False, index=True
    )
    parent_id: Mapped[int | None] = mapped_column(
        ForeignKey("fin_cashflow_category.id", ondelete="RESTRICT"), nullable=True, index=True
    )
    code: Mapped[str] = mapped_column(String(24), nullable=False, index=True)
    name: Mapped[str] = mapped_column(String(255), nullable=False)
    level: Mapped[int] = mapped_column(Integer, nullable=False)
    activity: Mapped[str] = mapped_column(String(12), nullable=False)  # operating|investing|financing
    direction: Mapped[str] = mapped_column(String(8), nullable=False)  # inflow|outflow|both
    sort_order: Mapped[int] = mapped_column(Integer, nullable=False, default=0, server_default="0")
    is_active: Mapped[bool] = mapped_column(
        Boolean, nullable=False, default=True, server_default="true"
    )

    __table_args__ = (
        UniqueConstraint("cat_set_id", "code", name="uq_fin_cashflow_cat_set_code"),
    )


class FinNumberSequence(Base):
    """Нумератор финдокументов (operation/registry/invoice/act/request/journal) по юрлицу/году."""

    __tablename__ = "fin_number_sequence"

    id: Mapped[int] = mapped_column(primary_key=True)
    doc_type: Mapped[str] = mapped_column(String(16), nullable=False)
    legal_entity_id: Mapped[int | None] = mapped_column(
        ForeignKey("fin_legal_entity.id", ondelete="CASCADE"), nullable=True
    )
    year: Mapped[int] = mapped_column(Integer, nullable=False)
    prefix: Mapped[str | None] = mapped_column(String(16), nullable=True)
    next_value: Mapped[int] = mapped_column(Integer, nullable=False, default=1, server_default="1")

    __table_args__ = (
        UniqueConstraint(
            "doc_type", "legal_entity_id", "year", name="uq_fin_number_seq"
        ),
    )


class FinPermission(Base):
    """Матрица прав role(или user)×entity×capability. Ф0 — дефолты по ролям (без UI-редактора)."""

    __tablename__ = "fin_permission"

    id: Mapped[int] = mapped_column(primary_key=True)
    role: Mapped[str | None] = mapped_column(String(16), nullable=True, index=True)
    user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="CASCADE"), nullable=True, index=True
    )
    legal_entity_id: Mapped[int | None] = mapped_column(
        ForeignKey("fin_legal_entity.id", ondelete="CASCADE"), nullable=True, index=True
    )
    capability: Mapped[str] = mapped_column(String(32), nullable=False)
    allowed: Mapped[bool] = mapped_column(Boolean, nullable=False, default=True)

    __table_args__ = (
        # NULLS NOT DISTINCT (migration 0108): role/user_id/legal_entity_id —
        # nullable, обычный UNIQUE не ловит дубли с NULL-колонками (role-default
        # права имеют user_id=NULL). С NULLS NOT DISTINCT NULL трактуется как
        # один и тот же ключ — дубли блокируются.
        UniqueConstraint(
            "role", "user_id", "legal_entity_id", "capability", name="uq_fin_permission",
            postgresql_nulls_not_distinct=True,
        ),
    )


class FinPeriodLock(Base):
    """Закрытие периода (legal_entity×year×month). В закрытом — постинг/сторно запрещены."""

    __tablename__ = "fin_period_lock"

    id: Mapped[int] = mapped_column(primary_key=True)
    legal_entity_id: Mapped[int] = mapped_column(
        ForeignKey("fin_legal_entity.id", ondelete="CASCADE"), nullable=False, index=True
    )
    year: Mapped[int] = mapped_column(Integer, nullable=False)
    month: Mapped[int] = mapped_column(Integer, nullable=False)
    locked_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), nullable=False
    )
    locked_by_user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True
    )

    __table_args__ = (
        UniqueConstraint("legal_entity_id", "year", "month", name="uq_fin_period_lock"),
        CheckConstraint(
            "month BETWEEN 1 AND 12", name="ck_fin_period_lock_month"
        ),
    )


# ───────────────────────────── ядро GL ─────────────────────────────


class FinJournalEntry(Base):
    """Проводка — единица истины. ≥2 ledger_line, Σ amount_func==0 (DB-триггер)."""

    __tablename__ = "fin_journal_entry"

    id: Mapped[int] = mapped_column(primary_key=True)
    legal_entity_id: Mapped[int] = mapped_column(
        ForeignKey("fin_legal_entity.id", ondelete="RESTRICT"), nullable=False, index=True
    )
    date: Mapped[date] = mapped_column(Date, nullable=False, index=True)
    kind: Mapped[str] = mapped_column(String(20), nullable=False, index=True)
    # cash_in|cash_out|transfer|revenue_accrual|expense_accrual|vat|payroll|fx_reval|opening|adjustment|reversal
    status: Mapped[str] = mapped_column(
        String(10), nullable=False, default="draft", server_default="draft", index=True
    )  # draft|posted|reversed
    source: Mapped[str] = mapped_column(String(20), nullable=False)
    # operation|invoice|act|vendor_bill|contract_payment|request|recurring|import|manual
    source_ref_id: Mapped[int | None] = mapped_column(Integer, nullable=True, index=True)
    reverses_entry_id: Mapped[int | None] = mapped_column(
        ForeignKey("fin_journal_entry.id", ondelete="SET NULL"), nullable=True
    )
    func_currency: Mapped[str] = mapped_column(String(8), nullable=False)
    base_currency: Mapped[str | None] = mapped_column(String(8), nullable=True)
    posted_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    posted_by_user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True
    )
    memo: Mapped[str | None] = mapped_column(Text, nullable=True)
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), nullable=False
    )
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), onupdate=func.now(), nullable=False
    )

    lines: Mapped[list[FinLedgerLine]] = relationship(
        "FinLedgerLine", back_populates="entry", cascade="all, delete-orphan",
        foreign_keys="FinLedgerLine.journal_entry_id",
    )

    __table_args__ = (
        Index("ix_fin_journal_entry_le_date_status", "legal_entity_id", "date", "status"),
        Index("ix_fin_journal_entry_source", "source", "source_ref_id"),
        # Guard-индекс из migration 0096: идемпотентность accrual-проводок.
        # Декларируем в модели, чтобы autogenerate НЕ генерил op.drop_index.
        Index(
            "uq_fin_je_accrual",
            "source",
            "source_ref_id",
            "kind",
            unique=True,
            postgresql_where=text(
                "kind IN ('revenue_accrual', 'expense_accrual') "
                "AND source_ref_id IS NOT NULL"
            ),
        ),
        CheckConstraint(
            "status IN ('draft','posted','reversed')",
            name="ck_fin_journal_entry_status",
        ),
    )


class FinLedgerLine(Base):
    """Строка проводки. Дт>0 / Кт<0 в трёх валютах. money_account_id заполнен ⇔ денежная (→ДДС)."""

    __tablename__ = "fin_ledger_line"

    id: Mapped[int] = mapped_column(primary_key=True)
    journal_entry_id: Mapped[int] = mapped_column(
        ForeignKey("fin_journal_entry.id", ondelete="CASCADE"), nullable=False, index=True
    )
    legal_entity_id: Mapped[int] = mapped_column(
        ForeignKey("fin_legal_entity.id", ondelete="RESTRICT"), nullable=False, index=True
    )
    account_gl_id: Mapped[int] = mapped_column(
        ForeignKey("fin_account_gl.id", ondelete="RESTRICT"), nullable=False, index=True
    )
    amount: Mapped[Decimal] = mapped_column(Numeric(18, 2), nullable=False)  # Дт>0 / Кт<0 (валюта строки)
    currency: Mapped[str] = mapped_column(String(8), nullable=False)
    amount_func: Mapped[Decimal] = mapped_column(Numeric(18, 2), nullable=False)  # функц.валюта, Σ=0
    amount_in_base: Mapped[Decimal | None] = mapped_column(Numeric(18, 2), nullable=True)  # проекция
    base_currency: Mapped[str | None] = mapped_column(String(8), nullable=True)
    fx_rate: Mapped[Decimal | None] = mapped_column(Numeric(20, 8), nullable=True)
    fx_rate_date: Mapped[date | None] = mapped_column(Date, nullable=True)
    fx_missing: Mapped[bool] = mapped_column(
        Boolean, nullable=False, default=False, server_default="false"
    )
    money_account_id: Mapped[int | None] = mapped_column(
        ForeignKey("fin_money_account.id", ondelete="SET NULL"), nullable=True, index=True
    )
    cashflow_category_id: Mapped[int | None] = mapped_column(
        ForeignKey("fin_cashflow_category.id", ondelete="SET NULL"), nullable=True, index=True
    )
    counterparty_company_id: Mapped[int | None] = mapped_column(
        ForeignKey("crm_companies.id", ondelete="SET NULL"), nullable=True, index=True
    )
    employee_user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True
    )
    vat_rate_id: Mapped[int | None] = mapped_column(
        ForeignKey("fin_vat_rate.id", ondelete="SET NULL"), nullable=True
    )
    deal_id: Mapped[int | None] = mapped_column(
        ForeignKey("deals.id", ondelete="SET NULL"), nullable=True, index=True
    )
    contract_id: Mapped[int | None] = mapped_column(
        ForeignKey("contracts.id", ondelete="SET NULL"), nullable=True, index=True
    )
    subscription_id: Mapped[int | None] = mapped_column(
        ForeignKey("client_subscriptions.id", ondelete="SET NULL"), nullable=True, index=True
    )
    comment: Mapped[str | None] = mapped_column(Text, nullable=True)

    entry: Mapped[FinJournalEntry] = relationship(
        "FinJournalEntry", back_populates="lines", foreign_keys=[journal_entry_id]
    )

    __table_args__ = (
        Index("ix_fin_ledger_line_acc_le", "account_gl_id", "legal_entity_id"),
        Index("ix_fin_ledger_line_cp_acc", "counterparty_company_id", "account_gl_id"),
        CheckConstraint(
            "amount <> 0", name="ck_fin_ledger_line_amount_nonzero"
        ),
    )


class FinOperation(Base):
    """UX-обёртка операции (приход/расход/перевод). Постится в проводку. Без хранимых агрегатов."""

    __tablename__ = "fin_operation"

    id: Mapped[int] = mapped_column(primary_key=True)
    number: Mapped[str | None] = mapped_column(String(32), nullable=True)
    legal_entity_id: Mapped[int] = mapped_column(
        ForeignKey("fin_legal_entity.id", ondelete="RESTRICT"), nullable=False, index=True
    )
    op_type_id: Mapped[int | None] = mapped_column(
        ForeignKey("fin_op_type.id", ondelete="SET NULL"), nullable=True
    )
    direction: Mapped[str] = mapped_column(String(10), nullable=False)  # in|out|transfer
    status: Mapped[str] = mapped_column(
        String(16), nullable=False, default="planned", server_default="planned", index=True
    )  # planned|to_pay|on_hold|posted|reversed|rejected|cancelled|partially_paid
    op_date: Mapped[date] = mapped_column(Date, nullable=False, index=True)
    due_date: Mapped[date | None] = mapped_column(Date, nullable=True)
    amount: Mapped[Decimal] = mapped_column(Numeric(18, 2), nullable=False)  # >0; знак — в проводке
    currency: Mapped[str] = mapped_column(String(8), nullable=False)
    to_amount: Mapped[Decimal | None] = mapped_column(Numeric(18, 2), nullable=True)  # transfer-получатель
    account_from_id: Mapped[int | None] = mapped_column(
        ForeignKey("fin_money_account.id", ondelete="SET NULL"), nullable=True
    )
    account_to_id: Mapped[int | None] = mapped_column(
        ForeignKey("fin_money_account.id", ondelete="SET NULL"), nullable=True
    )
    cashflow_category_id: Mapped[int | None] = mapped_column(
        ForeignKey("fin_cashflow_category.id", ondelete="SET NULL"), nullable=True
    )
    counterparty_company_id: Mapped[int | None] = mapped_column(
        ForeignKey("crm_companies.id", ondelete="SET NULL"), nullable=True, index=True
    )
    vat_rate_id: Mapped[int | None] = mapped_column(
        ForeignKey("fin_vat_rate.id", ondelete="SET NULL"), nullable=True
    )
    vat_amount: Mapped[Decimal | None] = mapped_column(Numeric(18, 2), nullable=True)
    amount_net: Mapped[Decimal | None] = mapped_column(Numeric(18, 2), nullable=True)
    purpose: Mapped[str | None] = mapped_column(Text, nullable=True)
    journal_entry_id: Mapped[int | None] = mapped_column(
        ForeignKey("fin_journal_entry.id", ondelete="SET NULL"), nullable=True
    )
    source: Mapped[str | None] = mapped_column(String(20), nullable=True)
    source_ref_id: Mapped[int | None] = mapped_column(Integer, nullable=True)
    registry_id: Mapped[int | None] = mapped_column(
        ForeignKey("fin_payment_registry.id", ondelete="SET NULL"), nullable=True, index=True
    )
    fin_request_id: Mapped[int | None] = mapped_column(
        ForeignKey("fin_request.id", ondelete="SET NULL"), nullable=True, index=True
    )
    deal_id: Mapped[int | None] = mapped_column(
        ForeignKey("deals.id", ondelete="SET NULL"), nullable=True, index=True
    )
    contract_id: Mapped[int | None] = mapped_column(
        ForeignKey("contracts.id", ondelete="SET NULL"), nullable=True, index=True
    )
    subscription_id: Mapped[int | None] = mapped_column(
        ForeignKey("client_subscriptions.id", ondelete="SET NULL"), nullable=True
    )
    is_for_management: Mapped[bool] = mapped_column(
        Boolean, nullable=False, default=False, server_default="false"
    )
    rejected_reason: Mapped[str | None] = mapped_column(String(512), nullable=True)
    created_by_user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True
    )
    posted_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), nullable=False
    )
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), onupdate=func.now(), nullable=False
    )

    __table_args__ = (
        UniqueConstraint("source", "source_ref_id", name="uq_fin_operation_source"),
        # Guard-индекс из migration 0098: идемпотентность плановых поступлений подписок.
        # Декларируем в модели, чтобы autogenerate НЕ генерил op.drop_index.
        Index(
            "uq_fin_operation_sub_planned",
            "subscription_id",
            "op_date",
            unique=True,
            postgresql_where=text("source = 'subscription' AND status = 'planned'"),
        ),
        CheckConstraint(
            "amount > 0", name="ck_fin_operation_amount_pos"
        ),
        CheckConstraint(
            "direction IN ('in','out','transfer')",
            name="ck_fin_operation_direction",
        ),
        CheckConstraint(
            "status IN ('planned','to_pay','on_hold','posted','reversed',"
            "'rejected','cancelled','partially_paid')",
            name="ck_fin_operation_status",
        ),
    )


class FinAllocation(Base):
    """Разнесение операции по статьям ДДС (split). Σ allocation == amount для posted."""

    __tablename__ = "fin_allocation"

    id: Mapped[int] = mapped_column(primary_key=True)
    operation_id: Mapped[int] = mapped_column(
        ForeignKey("fin_operation.id", ondelete="CASCADE"), nullable=False, index=True
    )
    cashflow_category_id: Mapped[int | None] = mapped_column(
        ForeignKey("fin_cashflow_category.id", ondelete="SET NULL"), nullable=True
    )
    amount: Mapped[Decimal] = mapped_column(Numeric(18, 2), nullable=False)
    comment: Mapped[str | None] = mapped_column(String(255), nullable=True)


class FinManualJournal(Base):
    """Ручная adjustment-проводка (header). Права accountant/cfo. Иммутабельность+сторно+lock."""

    __tablename__ = "fin_manual_journal"

    id: Mapped[int] = mapped_column(primary_key=True)
    number: Mapped[str | None] = mapped_column(String(32), nullable=True)
    legal_entity_id: Mapped[int] = mapped_column(
        ForeignKey("fin_legal_entity.id", ondelete="RESTRICT"), nullable=False, index=True
    )
    date: Mapped[date] = mapped_column(Date, nullable=False, index=True)
    status: Mapped[str] = mapped_column(
        String(10), nullable=False, default="draft", server_default="draft"
    )  # draft|posted|reversed
    memo: Mapped[str] = mapped_column(String(512), nullable=False)  # обязательное обоснование
    journal_entry_id: Mapped[int | None] = mapped_column(
        ForeignKey("fin_journal_entry.id", ondelete="SET NULL"), nullable=True
    )
    created_by_user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True
    )
    posted_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), nullable=False
    )
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), onupdate=func.now(), nullable=False
    )

    lines: Mapped[list[FinManualJournalLine]] = relationship(
        "FinManualJournalLine", back_populates="journal", cascade="all, delete-orphan"
    )

    __table_args__ = (
        CheckConstraint(
            "status IN ('draft','posted','reversed')",
            name="ck_fin_manual_journal_status",
        ),
    )


class FinManualJournalLine(Base):
    """Строка ручной проводки (UX, до постинга). side dt/kt; знак ставит posting engine."""

    __tablename__ = "fin_manual_journal_line"

    id: Mapped[int] = mapped_column(primary_key=True)
    manual_journal_id: Mapped[int] = mapped_column(
        ForeignKey("fin_manual_journal.id", ondelete="CASCADE"), nullable=False, index=True
    )
    account_gl_id: Mapped[int] = mapped_column(
        ForeignKey("fin_account_gl.id", ondelete="RESTRICT"), nullable=False
    )
    side: Mapped[str] = mapped_column(String(2), nullable=False)  # dt|kt
    amount: Mapped[Decimal] = mapped_column(Numeric(18, 2), nullable=False)  # >0 (CHECK)
    currency: Mapped[str] = mapped_column(String(8), nullable=False)
    counterparty_company_id: Mapped[int | None] = mapped_column(
        ForeignKey("crm_companies.id", ondelete="SET NULL"), nullable=True
    )
    money_account_id: Mapped[int | None] = mapped_column(
        ForeignKey("fin_money_account.id", ondelete="SET NULL"), nullable=True
    )
    cashflow_category_id: Mapped[int | None] = mapped_column(
        ForeignKey("fin_cashflow_category.id", ondelete="SET NULL"), nullable=True
    )
    comment: Mapped[str | None] = mapped_column(String(255), nullable=True)

    journal: Mapped[FinManualJournal] = relationship(
        "FinManualJournal", back_populates="lines"
    )

    __table_args__ = (
        CheckConstraint(
            "side IN ('dt','kt')", name="ck_fin_mj_line_side"
        ),
        CheckConstraint(
            "amount > 0", name="ck_fin_mj_line_amount_pos"
        ),
    )


# ═════════════════════════ Модуль «Финансы» Ф2 ═════════════════════════
# Реестр платежей, согласование под типы операций, заявки менеджеров (G §4/§6).


class FinPaymentRegistry(Base):
    """Реестр платежей — батч расходных операций по ОДНОМУ счёту-источнику (G §Ф2).

    Состав (fin_operation.registry_id) замораживается после approval_status='on_review'
    (E/F11). approval_status ведёт согласование реестра; payment_status — производный
    от проведённости позиций (new/partial/paid), но кэшируется для листинга.
    Массовое проведение проводит все expense-операции реестра одной транзакцией.
    """

    __tablename__ = "fin_payment_registry"

    id: Mapped[int] = mapped_column(primary_key=True)
    number: Mapped[str | None] = mapped_column(String(32), nullable=True)
    legal_entity_id: Mapped[int] = mapped_column(
        ForeignKey("fin_legal_entity.id", ondelete="RESTRICT"), nullable=False, index=True
    )
    source_account_id: Mapped[int] = mapped_column(
        ForeignKey("fin_money_account.id", ondelete="RESTRICT"), nullable=False, index=True
    )
    registry_date: Mapped[date] = mapped_column(Date, nullable=False, index=True)
    title: Mapped[str | None] = mapped_column(String(255), nullable=True)
    approval_status: Mapped[str] = mapped_column(
        String(12), nullable=False, default="draft", server_default="draft"
    )  # draft|on_review|approved|rejected
    payment_status: Mapped[str] = mapped_column(
        String(10), nullable=False, default="new", server_default="new"
    )  # new|partial|paid
    comment: Mapped[str | None] = mapped_column(Text, nullable=True)
    created_by_user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True
    )
    submitted_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    approved_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    posted_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), nullable=False
    )
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), onupdate=func.now(), nullable=False
    )

    __table_args__ = (
        CheckConstraint(
            "approval_status IN ('draft','on_review','approved','rejected')",
            name="ck_fin_registry_approval_status",
        ),
        CheckConstraint(
            "payment_status IN ('new','partial','paid')",
            name="ck_fin_registry_payment_status",
        ),
    )


class FinRequest(Base):
    """Заявка менеджера на расход/ЗП/комиссию/платёж (G §6).

    Флоу: draft → submitted → (согласование по сценарию) → approved/rejected →
    бухгалтер конвертирует approved в fin_operation (expense) → status=paid,
    resulting_operation_id проставлен. Иммутабельна после submit (правка — отзыв).
    """

    __tablename__ = "fin_request"

    id: Mapped[int] = mapped_column(primary_key=True)
    number: Mapped[str | None] = mapped_column(String(32), nullable=True)
    request_type: Mapped[str] = mapped_column(String(24), nullable=False)
    # salary|commission|expense_reimbursement|payment
    legal_entity_id: Mapped[int] = mapped_column(
        ForeignKey("fin_legal_entity.id", ondelete="RESTRICT"), nullable=False, index=True
    )
    requester_user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True, index=True
    )
    amount: Mapped[Decimal] = mapped_column(Numeric(18, 2), nullable=False)  # >0 CHECK
    currency: Mapped[str] = mapped_column(String(8), nullable=False)
    op_type_id: Mapped[int | None] = mapped_column(
        ForeignKey("fin_op_type.id", ondelete="SET NULL"), nullable=True
    )
    counterparty_company_id: Mapped[int | None] = mapped_column(
        ForeignKey("crm_companies.id", ondelete="SET NULL"), nullable=True
    )
    payee_user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True
    )  # получатель-сотрудник (ЗП/комиссия)
    cashflow_category_id: Mapped[int | None] = mapped_column(
        ForeignKey("fin_cashflow_category.id", ondelete="SET NULL"), nullable=True
    )
    period_year: Mapped[int | None] = mapped_column(Integer, nullable=True)
    period_month: Mapped[int | None] = mapped_column(Integer, nullable=True)
    desired_date: Mapped[date | None] = mapped_column(Date, nullable=True)
    description: Mapped[str | None] = mapped_column(Text, nullable=True)
    status: Mapped[str] = mapped_column(
        String(12), nullable=False, default="draft", server_default="draft", index=True
    )  # draft|submitted|approved|rejected|paid|cancelled
    resulting_operation_id: Mapped[int | None] = mapped_column(
        ForeignKey("fin_operation.id", ondelete="SET NULL"), nullable=True
    )
    rejected_reason: Mapped[str | None] = mapped_column(String(512), nullable=True)
    submitted_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    decided_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), nullable=False
    )
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), onupdate=func.now(), nullable=False
    )

    __table_args__ = (
        CheckConstraint("amount > 0", name="ck_fin_request_amount_pos"),
        CheckConstraint(
            "request_type IN ('salary','commission','expense_reimbursement','payment')",
            name="ck_fin_request_type",
        ),
        CheckConstraint(
            "status IN ('draft','submitted','approved','rejected','paid','cancelled')",
            name="ck_fin_request_status",
        ),
        CheckConstraint(
            "period_month IS NULL OR (period_month >= 1 AND period_month <= 12)",
            name="ck_fin_request_period_month",
        ),
    )


class FinApprovalScenario(Base):
    """Сценарий согласования под тип операции + опц. юрлицо + порог суммы (G §4).

    stages — JSON [{order, name, user_ids, min_required, mode}] (формат ApprovalRoute,
    переиспользуется fin_approval-движком). applies_to различает цель: operation/
    registry/request/invoice. Выбор — pick_scenario (priority DESC + специфичность).
    """

    __tablename__ = "fin_approval_scenario"

    id: Mapped[int] = mapped_column(primary_key=True)
    name: Mapped[str] = mapped_column(String(128), nullable=False)
    applies_to: Mapped[str] = mapped_column(
        String(12), nullable=False, default="operation", server_default="operation"
    )  # operation|registry|request|invoice
    op_type_id: Mapped[int | None] = mapped_column(
        ForeignKey("fin_op_type.id", ondelete="CASCADE"), nullable=True, index=True
    )  # NULL = для всех типов
    legal_entity_id: Mapped[int | None] = mapped_column(
        ForeignKey("fin_legal_entity.id", ondelete="CASCADE"), nullable=True, index=True
    )  # NULL = для всех юрлиц
    min_amount: Mapped[Decimal | None] = mapped_column(Numeric(18, 2), nullable=True)
    max_amount: Mapped[Decimal | None] = mapped_column(Numeric(18, 2), nullable=True)
    stages: Mapped[list[dict[str, Any]]] = mapped_column(
        JSONB, nullable=False, default=list, server_default="[]"
    )
    priority: Mapped[int] = mapped_column(Integer, nullable=False, default=0, server_default="0")
    is_active: Mapped[bool] = mapped_column(
        Boolean, nullable=False, default=True, server_default="true"
    )
    created_by_user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True
    )
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), nullable=False
    )
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), onupdate=func.now(), nullable=False
    )

    __table_args__ = (
        CheckConstraint(
            "applies_to IN ('operation','registry','request','invoice')",
            name="ck_fin_scenario_applies_to",
        ),
    )


class FinApproval(Base):
    """Голос согласования (полиморф: operation/registry/request/invoice) (G §4).

    Полиморфизм осознанно разрешён (E/F6): 4 стабильные цели, это «голос», не финданные
    (целостность не критична как для проводок). Контрактный Approval НЕ трогаем — это
    отдельная таблица с ключом (approvable_kind, approvable_id).
    """

    __tablename__ = "fin_approval"

    id: Mapped[int] = mapped_column(primary_key=True)
    approvable_kind: Mapped[str] = mapped_column(String(12), nullable=False, index=True)
    approvable_id: Mapped[int] = mapped_column(Integer, nullable=False, index=True)
    scenario_id: Mapped[int | None] = mapped_column(
        ForeignKey("fin_approval_scenario.id", ondelete="SET NULL"), nullable=True
    )
    user_id: Mapped[int] = mapped_column(
        ForeignKey("users.id", ondelete="CASCADE"), nullable=False, index=True
    )
    stage_order: Mapped[int] = mapped_column(Integer, nullable=False, default=0)
    decision: Mapped[str] = mapped_column(
        String(10), nullable=False, default="pending", server_default="pending"
    )  # pending|approved|rejected
    comment: Mapped[str | None] = mapped_column(Text, nullable=True)
    decided_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), nullable=False
    )

    __table_args__ = (
        CheckConstraint(
            "approvable_kind IN ('operation','registry','request','invoice')",
            name="ck_fin_approval_kind",
        ),
        CheckConstraint(
            "decision IN ('pending','approved','rejected')",
            name="ck_fin_approval_decision",
        ),
        UniqueConstraint(
            "approvable_kind",
            "approvable_id",
            "stage_order",
            "user_id",
            name="uq_fin_approval_vote",
        ),
        Index("ix_fin_approval_target", "approvable_kind", "approvable_id"),
    )


# ───────────────────────────── Ф5: инвойсы / акты / вендор-счета (AR/AP + НДС) ─────────────────────────────


class FinInvoice(Base):
    """Счёт клиенту (G §9, решение 9). При issue постит accrual-проводку:
    Дт AR(1210) / Кт выручка(4xxx) / Кт НДС output(2310). Оплата гасит AR.

    paid_amount/status — производные: статус считается из AR-погашений (settlement),
    но материализуется в поле для листинга (recompute_invoice_status). Иммутабельность:
    после issue шапка/позиции не правятся (только cancel сторнирует проводку).
    """

    __tablename__ = "fin_invoice"

    id: Mapped[int] = mapped_column(primary_key=True)
    number: Mapped[str | None] = mapped_column(String(32), nullable=True)
    legal_entity_id: Mapped[int] = mapped_column(
        ForeignKey("fin_legal_entity.id", ondelete="RESTRICT"), nullable=False, index=True
    )
    counterparty_company_id: Mapped[int] = mapped_column(
        ForeignKey("crm_companies.id", ondelete="RESTRICT"), nullable=False, index=True
    )
    contact_id: Mapped[int | None] = mapped_column(
        ForeignKey("crm_contacts.id", ondelete="SET NULL"), nullable=True
    )
    deal_id: Mapped[int | None] = mapped_column(
        ForeignKey("deals.id", ondelete="SET NULL"), nullable=True, index=True
    )
    contract_id: Mapped[int | None] = mapped_column(
        ForeignKey("contracts.id", ondelete="SET NULL"), nullable=True, index=True
    )
    subscription_id: Mapped[int | None] = mapped_column(
        ForeignKey("client_subscriptions.id", ondelete="SET NULL"), nullable=True, index=True
    )
    issue_date: Mapped[date] = mapped_column(Date, nullable=False, index=True)
    due_date: Mapped[date | None] = mapped_column(Date, nullable=True, index=True)
    currency: Mapped[str] = mapped_column(String(8), nullable=False)
    amount_net: Mapped[Decimal] = mapped_column(
        Numeric(18, 2), nullable=False, default=0, server_default="0"
    )
    vat_amount: Mapped[Decimal] = mapped_column(
        Numeric(18, 2), nullable=False, default=0, server_default="0"
    )
    amount_gross: Mapped[Decimal] = mapped_column(
        Numeric(18, 2), nullable=False, default=0, server_default="0"
    )
    #: МАТЕРИАЛИЗОВАННОЕ ПРОИЗВОДНОЕ от settlement-проводок (Σ cash_in по AR этого инвойса).
    #: Истина — GL-сальдо счёта AR(1210); это поле — денормализация для листинга/aging.
    #: Обновляется ТОЛЬКО в pay_invoice под FOR UPDATE, атомарно с settlement-проводкой
    #: (один commit) — рассинхрон с GL невозможен. Инвариант проверяется тестом
    #: согласованности (paid_amount == Σ settlement по invoice).
    paid_amount: Mapped[Decimal] = mapped_column(
        Numeric(18, 2), nullable=False, default=0, server_default="0"
    )
    status: Mapped[str] = mapped_column(
        String(16), nullable=False, default="draft", server_default="draft", index=True
    )  # draft|issued|partially_paid|paid|cancelled
    #: Какой GL-счёт выручки кредитуется по умолчанию (4010 MRR и т.п.); per-line может уточнять.
    revenue_account_code: Mapped[str] = mapped_column(
        String(8), nullable=False, default="4030", server_default="4030"
    )
    purpose: Mapped[str | None] = mapped_column(Text, nullable=True)
    amount_in_words: Mapped[str | None] = mapped_column(String(512), nullable=True)
    #: Версия сгенерированного документа (монотонный счётчик). Файлы на диске:
    #: /data/storage/finance_docs/invoice/{id}/v{document_file_id}/document.docx|.pdf
    #: (см. services/finance/doc_render.py). NULL → документ ещё не сгенерирован.
    document_file_id: Mapped[int | None] = mapped_column(Integer, nullable=True)
    journal_entry_id: Mapped[int | None] = mapped_column(
        ForeignKey("fin_journal_entry.id", ondelete="SET NULL"), nullable=True
    )
    created_by_user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True
    )
    #: Approval-based «подписание» (Фаза A): кто/когда подписал. При подписи PDF
    #: перегенерируется с блоком подписи; документ становится иммутабельным.
    signed_by_user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True
    )
    signed_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    issued_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), nullable=False
    )
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), onupdate=func.now(), nullable=False
    )

    lines: Mapped[list[FinInvoiceLine]] = relationship(
        "FinInvoiceLine", back_populates="invoice", cascade="all, delete-orphan"
    )

    __table_args__ = (
        CheckConstraint(
            "status IN ('draft','issued','partially_paid','paid','cancelled')",
            name="ck_fin_invoice_status",
        ),
        Index("ix_fin_invoice_le_status", "legal_entity_id", "status"),
    )


class FinInvoiceLine(Base):
    """Позиция счёта клиенту. net+vat=gross; vat=round(net*rate). Выручка по revenue_account_code."""

    __tablename__ = "fin_invoice_line"

    id: Mapped[int] = mapped_column(primary_key=True)
    invoice_id: Mapped[int] = mapped_column(
        ForeignKey("fin_invoice.id", ondelete="CASCADE"), nullable=False, index=True
    )
    name: Mapped[str] = mapped_column(String(512), nullable=False)
    qty: Mapped[Decimal] = mapped_column(
        Numeric(18, 4), nullable=False, default=1, server_default="1"
    )
    unit_price: Mapped[Decimal] = mapped_column(Numeric(18, 2), nullable=False)
    amount_net: Mapped[Decimal] = mapped_column(Numeric(18, 2), nullable=False)
    vat_rate_id: Mapped[int | None] = mapped_column(
        ForeignKey("fin_vat_rate.id", ondelete="SET NULL"), nullable=True
    )
    vat_amount: Mapped[Decimal] = mapped_column(
        Numeric(18, 2), nullable=False, default=0, server_default="0"
    )
    amount_gross: Mapped[Decimal] = mapped_column(Numeric(18, 2), nullable=False)
    #: GL-счёт выручки по позиции (4010/4020/4030); если NULL — берётся invoice.revenue_account_code.
    revenue_account_code: Mapped[str | None] = mapped_column(String(8), nullable=True)
    cashflow_category_id: Mapped[int | None] = mapped_column(
        ForeignKey("fin_cashflow_category.id", ondelete="SET NULL"), nullable=True
    )
    sort_order: Mapped[int] = mapped_column(Integer, nullable=False, default=0, server_default="0")

    invoice: Mapped[FinInvoice] = relationship("FinInvoice", back_populates="lines")


class FinAct(Base):
    """Акт выполненных работ (G §9). Документ ВЫПОЛНЕНИЯ — НЕ постит проводку: выручка
    признаётся инвойсом (invoice_issue), акт лишь подтверждает оказание услуги, чтобы
    не задвоить признание. Связан с инвойсом/договором; подпись — под-чанк (contract-specialist).
    """

    __tablename__ = "fin_act"

    id: Mapped[int] = mapped_column(primary_key=True)
    number: Mapped[str | None] = mapped_column(String(32), nullable=True)
    legal_entity_id: Mapped[int] = mapped_column(
        ForeignKey("fin_legal_entity.id", ondelete="RESTRICT"), nullable=False, index=True
    )
    counterparty_company_id: Mapped[int] = mapped_column(
        ForeignKey("crm_companies.id", ondelete="RESTRICT"), nullable=False, index=True
    )
    invoice_id: Mapped[int | None] = mapped_column(
        ForeignKey("fin_invoice.id", ondelete="SET NULL"), nullable=True, index=True
    )
    contract_id: Mapped[int | None] = mapped_column(
        ForeignKey("contracts.id", ondelete="SET NULL"), nullable=True
    )
    subscription_id: Mapped[int | None] = mapped_column(
        ForeignKey("client_subscriptions.id", ondelete="SET NULL"), nullable=True
    )
    act_date: Mapped[date] = mapped_column(Date, nullable=False, index=True)
    period_year: Mapped[int | None] = mapped_column(Integer, nullable=True)
    period_month: Mapped[int | None] = mapped_column(Integer, nullable=True)
    currency: Mapped[str] = mapped_column(String(8), nullable=False)
    amount_net: Mapped[Decimal] = mapped_column(
        Numeric(18, 2), nullable=False, default=0, server_default="0"
    )
    vat_amount: Mapped[Decimal] = mapped_column(
        Numeric(18, 2), nullable=False, default=0, server_default="0"
    )
    amount_gross: Mapped[Decimal] = mapped_column(
        Numeric(18, 2), nullable=False, default=0, server_default="0"
    )
    status: Mapped[str] = mapped_column(
        String(12), nullable=False, default="draft", server_default="draft", index=True
    )  # draft|issued|signed|cancelled
    purpose: Mapped[str | None] = mapped_column(Text, nullable=True)
    amount_in_words: Mapped[str | None] = mapped_column(String(512), nullable=True)
    #: Версия сгенерированного документа (см. FinInvoice.document_file_id):
    #: /data/storage/finance_docs/act/{id}/v{document_file_id}/document.docx|.pdf.
    document_file_id: Mapped[int | None] = mapped_column(Integer, nullable=True)
    #: Approval-based «подписание» (Фаза A). status='signed' + signed_at/signed_by_user_id.
    signed_by_user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True
    )
    signed_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    created_by_user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True
    )
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), nullable=False
    )
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), onupdate=func.now(), nullable=False
    )

    lines: Mapped[list[FinActLine]] = relationship(
        "FinActLine", back_populates="act", cascade="all, delete-orphan"
    )

    __table_args__ = (
        CheckConstraint(
            "status IN ('draft','issued','signed','cancelled')",
            name="ck_fin_act_status",
        ),
        CheckConstraint(
            "period_month IS NULL OR (period_month >= 1 AND period_month <= 12)",
            name="ck_fin_act_period_month",
        ),
    )


class FinActLine(Base):
    """Позиция акта выполненных работ (документарная, без собственной проводки)."""

    __tablename__ = "fin_act_line"

    id: Mapped[int] = mapped_column(primary_key=True)
    act_id: Mapped[int] = mapped_column(
        ForeignKey("fin_act.id", ondelete="CASCADE"), nullable=False, index=True
    )
    name: Mapped[str] = mapped_column(String(512), nullable=False)
    qty: Mapped[Decimal] = mapped_column(
        Numeric(18, 4), nullable=False, default=1, server_default="1"
    )
    unit_price: Mapped[Decimal] = mapped_column(Numeric(18, 2), nullable=False)
    amount_net: Mapped[Decimal] = mapped_column(Numeric(18, 2), nullable=False)
    vat_rate_id: Mapped[int | None] = mapped_column(
        ForeignKey("fin_vat_rate.id", ondelete="SET NULL"), nullable=True
    )
    vat_amount: Mapped[Decimal] = mapped_column(
        Numeric(18, 2), nullable=False, default=0, server_default="0"
    )
    amount_gross: Mapped[Decimal] = mapped_column(Numeric(18, 2), nullable=False)
    sort_order: Mapped[int] = mapped_column(Integer, nullable=False, default=0, server_default="0")

    act: Mapped[FinAct] = relationship("FinAct", back_populates="lines")


class FinVendorBill(Base):
    """Входящий счёт/акт от поставщика (G §9, AP/accrual расход). При confirm постит:
    Дт расход(5xxx) + Дт НДС input(1910) / Кт AP(2110). Оплата гасит AP. Статусы.
    """

    __tablename__ = "fin_vendor_bill"

    id: Mapped[int] = mapped_column(primary_key=True)
    number: Mapped[str | None] = mapped_column(String(32), nullable=True)  # внутренний номер
    bill_no: Mapped[str | None] = mapped_column(String(64), nullable=True)  # номер поставщика
    legal_entity_id: Mapped[int] = mapped_column(
        ForeignKey("fin_legal_entity.id", ondelete="RESTRICT"), nullable=False, index=True
    )
    supplier_company_id: Mapped[int] = mapped_column(
        ForeignKey("crm_companies.id", ondelete="RESTRICT"), nullable=False, index=True
    )
    bill_date: Mapped[date] = mapped_column(Date, nullable=False, index=True)
    due_date: Mapped[date | None] = mapped_column(Date, nullable=True, index=True)
    currency: Mapped[str] = mapped_column(String(8), nullable=False)
    amount_net: Mapped[Decimal] = mapped_column(
        Numeric(18, 2), nullable=False, default=0, server_default="0"
    )
    vat_amount: Mapped[Decimal] = mapped_column(
        Numeric(18, 2), nullable=False, default=0, server_default="0"
    )
    amount_gross: Mapped[Decimal] = mapped_column(
        Numeric(18, 2), nullable=False, default=0, server_default="0"
    )
    #: МАТЕРИАЛИЗОВАННОЕ ПРОИЗВОДНОЕ от settlement-проводок (Σ cash_out по AP этого счёта).
    #: Истина — GL-сальдо счёта AP(2110); это поле — денормализация для листинга/aging.
    #: Обновляется ТОЛЬКО в pay_vendor_bill под FOR UPDATE, атомарно с settlement-проводкой.
    paid_amount: Mapped[Decimal] = mapped_column(
        Numeric(18, 2), nullable=False, default=0, server_default="0"
    )
    status: Mapped[str] = mapped_column(
        String(16), nullable=False, default="draft", server_default="draft", index=True
    )  # draft|confirmed|partially_paid|paid|cancelled
    #: GL-счёт расхода по умолчанию (5210/5990 и т.п.); per-line может уточнять.
    expense_account_code: Mapped[str] = mapped_column(
        String(8), nullable=False, default="5990", server_default="5990"
    )
    cashflow_category_id: Mapped[int | None] = mapped_column(
        ForeignKey("fin_cashflow_category.id", ondelete="SET NULL"), nullable=True
    )
    purpose: Mapped[str | None] = mapped_column(Text, nullable=True)
    document_file_id: Mapped[int | None] = mapped_column(Integer, nullable=True)
    journal_entry_id: Mapped[int | None] = mapped_column(
        ForeignKey("fin_journal_entry.id", ondelete="SET NULL"), nullable=True
    )
    created_by_user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True
    )
    confirmed_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), nullable=False
    )
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), onupdate=func.now(), nullable=False
    )

    lines: Mapped[list[FinVendorBillLine]] = relationship(
        "FinVendorBillLine", back_populates="bill", cascade="all, delete-orphan"
    )

    __table_args__ = (
        CheckConstraint(
            "status IN ('draft','confirmed','partially_paid','paid','cancelled')",
            name="ck_fin_vendor_bill_status",
        ),
        Index("ix_fin_vendor_bill_le_status", "legal_entity_id", "status"),
    )


class FinVendorBillLine(Base):
    """Позиция вендор-счёта. net+vat=gross; расход по expense_account_code (per-line override)."""

    __tablename__ = "fin_vendor_bill_line"

    id: Mapped[int] = mapped_column(primary_key=True)
    bill_id: Mapped[int] = mapped_column(
        ForeignKey("fin_vendor_bill.id", ondelete="CASCADE"), nullable=False, index=True
    )
    name: Mapped[str] = mapped_column(String(512), nullable=False)
    qty: Mapped[Decimal] = mapped_column(
        Numeric(18, 4), nullable=False, default=1, server_default="1"
    )
    unit_price: Mapped[Decimal] = mapped_column(Numeric(18, 2), nullable=False)
    amount_net: Mapped[Decimal] = mapped_column(Numeric(18, 2), nullable=False)
    vat_rate_id: Mapped[int | None] = mapped_column(
        ForeignKey("fin_vat_rate.id", ondelete="SET NULL"), nullable=True
    )
    vat_amount: Mapped[Decimal] = mapped_column(
        Numeric(18, 2), nullable=False, default=0, server_default="0"
    )
    amount_gross: Mapped[Decimal] = mapped_column(Numeric(18, 2), nullable=False)
    expense_account_code: Mapped[str | None] = mapped_column(String(8), nullable=True)
    cashflow_category_id: Mapped[int | None] = mapped_column(
        ForeignKey("fin_cashflow_category.id", ondelete="SET NULL"), nullable=True
    )
    sort_order: Mapped[int] = mapped_column(Integer, nullable=False, default=0, server_default="0")

    bill: Mapped[FinVendorBill] = relationship("FinVendorBill", back_populates="lines")


# ───────────────────────────── Ф4: accrual + переоценка + смена базы ─────────────────────────────


class FinRevenueSchedule(Base):
    """План признания выручки помесячно (MRR, G §2 реш.5).

    Одна строка = (подписка, год, месяц). Признание постит accrual-проводку
    (Дт AR / Кт выручка / Кт НДС) → переводит в 'recognized'. Идемпотентность —
    recognition_key UNIQUE (subscription_id-YYYY-MM) под scale=2.
    """

    __tablename__ = "fin_revenue_schedule"

    id: Mapped[int] = mapped_column(primary_key=True)
    subscription_id: Mapped[int] = mapped_column(
        ForeignKey("client_subscriptions.id", ondelete="CASCADE"), nullable=False, index=True
    )
    legal_entity_id: Mapped[int] = mapped_column(
        ForeignKey("fin_legal_entity.id", ondelete="RESTRICT"), nullable=False, index=True
    )
    counterparty_company_id: Mapped[int | None] = mapped_column(
        ForeignKey("crm_companies.id", ondelete="SET NULL"), nullable=True, index=True
    )
    period_year: Mapped[int] = mapped_column(Integer, nullable=False)
    period_month: Mapped[int] = mapped_column(Integer, nullable=False)
    amount_net: Mapped[Decimal] = mapped_column(Numeric(18, 2), nullable=False)
    vat_amount: Mapped[Decimal] = mapped_column(
        Numeric(18, 2), nullable=False, default=0, server_default="0"
    )
    currency: Mapped[str] = mapped_column(String(8), nullable=False)
    revenue_account_code: Mapped[str] = mapped_column(
        String(8), nullable=False, default="4010", server_default="4010"
    )
    vat_rate_id: Mapped[int | None] = mapped_column(
        ForeignKey("fin_vat_rate.id", ondelete="SET NULL"), nullable=True
    )
    cashflow_category_id: Mapped[int | None] = mapped_column(
        ForeignKey("fin_cashflow_category.id", ondelete="SET NULL"), nullable=True
    )
    #: scheduled — план; recognized — выручка признана (проводка есть);
    #: skipped — пропущено (нет суммы/вручную); reversed — признание сторнировано.
    status: Mapped[str] = mapped_column(
        String(12), nullable=False, default="scheduled", server_default="scheduled", index=True
    )
    recognition_key: Mapped[str] = mapped_column(String(64), nullable=False, unique=True)
    recognized_journal_entry_id: Mapped[int | None] = mapped_column(
        ForeignKey("fin_journal_entry.id", ondelete="SET NULL"), nullable=True
    )
    recognized_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    created_by_user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True
    )
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), nullable=False
    )
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), onupdate=func.now(), nullable=False
    )

    __table_args__ = (
        Index(
            "ix_fin_revenue_schedule_sub_period",
            "subscription_id", "period_year", "period_month",
        ),
        CheckConstraint(
            "period_month BETWEEN 1 AND 12", name="ck_fin_revenue_schedule_month"
        ),
        CheckConstraint(
            "status IN ('scheduled','recognized','skipped','reversed')",
            name="ck_fin_revenue_schedule_status",
        ),
    )


class FinBaseRecomputeJob(Base):
    """Задание пересчёта amount_in_base при смене базовой валюты (G §2 реш.1, H4).

    Идемпотентность: повтор при том же target_currency + running/done — no-op.
    Политика H4: пересчитываются ТОЛЬКО строки ОТКРЫТЫХ периодов; закрытые периоды
    сохраняют исторический base_currency/amount_in_base (period-lock философия).
    """

    __tablename__ = "fin_base_recompute_job"

    id: Mapped[int] = mapped_column(primary_key=True)
    target_currency: Mapped[str] = mapped_column(String(8), nullable=False)
    previous_currency: Mapped[str | None] = mapped_column(String(8), nullable=True)
    #: pending|running|done|partial|failed. partial — часть строк без курса (fx_missing).
    status: Mapped[str] = mapped_column(
        String(12), nullable=False, default="pending", server_default="pending", index=True
    )
    total_lines: Mapped[int] = mapped_column(Integer, nullable=False, default=0, server_default="0")
    processed_lines: Mapped[int] = mapped_column(
        Integer, nullable=False, default=0, server_default="0"
    )
    skipped_closed: Mapped[int] = mapped_column(
        Integer, nullable=False, default=0, server_default="0"
    )
    missing_rate_lines: Mapped[int] = mapped_column(
        Integer, nullable=False, default=0, server_default="0"
    )
    error: Mapped[str | None] = mapped_column(Text, nullable=True)
    started_by_user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True
    )
    started_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), nullable=False
    )
    finished_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)

    __table_args__ = (
        CheckConstraint(
            "status IN ('pending','running','done','partial','failed')",
            name="ck_fin_base_recompute_job_status",
        ),
    )


# ============ Task 8 — Call Trainer («тренажёр звонков») ============


class CallTrainingSession(Base):
    """Сессия тренажёра холодных звонков (Task 8).

    AI играет роль клиента (sonnet) по сценарию `scenario_type`. Менеджер ведёт
    диалог, на финише EVALUATOR-промпт выставляет оценку. Source-of-truth —
    эта таблица (раньше сессии жили в in-memory dict в ai_chat.py, терялись на
    рестарте). transcript / criteria_scores / recommendations / good_decisions —
    JSONB, чтобы сохранять полную историю и подсвечивать удачные решения.

    Доступ — только отдел продаж (role in manager/director/admin), гард в роутере.
    """

    __tablename__ = "call_training_sessions"

    id: Mapped[int] = mapped_column(primary_key=True)
    user_id: Mapped[int] = mapped_column(
        ForeignKey("users.id", ondelete="CASCADE"), nullable=False, index=True,
    )
    scenario_type: Mapped[str] = mapped_column(String(40), nullable=False)
    company_type: Mapped[str] = mapped_column(String(80), nullable=False)
    company_name: Mapped[str | None] = mapped_column(String(255), nullable=True)
    # active → finished
    status: Mapped[str] = mapped_column(
        String(16), nullable=False, default="active", server_default="active",
    )
    # transcript: список {role: 'user'|'assistant', content: str, ts: ISO8601}
    transcript: Mapped[list[dict[str, Any]]] = mapped_column(
        JSONB, nullable=False, default=list, server_default="[]",
    )
    # Итоговая оценка 0-10 (1 знак после запятой). NULL пока не finished.
    score: Mapped[Decimal | None] = mapped_column(Numeric(4, 1), nullable=True)
    # criteria_scores: {speech_clarity, empathy, objection_handling, deal_closing}
    criteria_scores: Mapped[dict[str, Any] | None] = mapped_column(JSONB, nullable=True)
    # recommendations: список строк (конкретные рекомендации) ИЛИ объединённый текст
    recommendations: Mapped[list[Any] | None] = mapped_column(JSONB, nullable=True)
    # good_decisions: список удачных решений менеджера (подсветить)
    good_decisions: Mapped[list[Any] | None] = mapped_column(JSONB, nullable=True)
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), nullable=False, server_default=func.now(),
    )
    finished_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True), nullable=True,
    )

    __table_args__ = (
        CheckConstraint(
            "status IN ('active','finished')",
            name="ck_call_training_session_status",
        ),
    )
