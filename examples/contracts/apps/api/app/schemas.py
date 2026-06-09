"""Pydantic-схемы для входа/выхода API."""

from __future__ import annotations

from datetime import date, datetime
from typing import Any

from pydantic import BaseModel, ConfigDict, EmailStr, Field

from app.models import ApprovalDecision, ContractStatus, TemplateVariableType, UserRole


# ============ Auth ============

class LoginIn(BaseModel):
    email: EmailStr
    password: str


class LoginOut(BaseModel):
    id: int
    email: EmailStr
    full_name: str
    role: UserRole
    avatar_path: str | None = None
    telegram_user_id: int | None = None
    # Эпик 13: onboarding wizard state — фронт решает при логине показывать modal или нет
    crm_experience_level: str | None = None
    onboarding_dismissed_at: datetime | None = None
    # Эпик 16 — Security: 2FA статус и наличие пароля для UI «Безопасность аккаунта»
    # (вкладка профиля). totp_enabled=True означает, что юзер прошёл verify-setup
    # и при логине обязан ввести TOTP/backup. has_password=False — это SSO-only
    # юзер (placeholder в password_hash), форму смены пароля показывать не надо.
    totp_enabled: bool = False
    totp_enabled_at: datetime | None = None
    has_password: bool = True
    # Эпик 21 — UX: профиль 2.0. Фронт сразу применяет тему/локаль из логин-ответа,
    # без flash «system → dark» при последующем GET /me.
    theme_preference: str = "system"
    locale: str = "ru"
    job_title: str | None = None
    signature_url: str | None = None


# ============ User ============

class UserIn(BaseModel):
    email: EmailStr
    password: str
    full_name: str
    role: UserRole
    telegram_user_id: int | None = None


class UserOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    email: EmailStr
    full_name: str
    role: UserRole
    telegram_user_id: int | None = None
    avatar_path: str | None = None
    department_id: int | None = None
    # Эпик 14: прямой руководитель (для дерева компании и эскалации курсов).
    manager_id: int | None = None
    is_active: bool
    # Эпик 13: onboarding wizard state (для первого логина UI решает показывать ли modal)
    crm_experience_level: str | None = None
    onboarding_dismissed_at: datetime | None = None
    # Эпик 21 — UX: тема, локаль, должность, подпись.
    theme_preference: str = "system"
    locale: str = "ru"
    job_title: str | None = None
    signature_url: str | None = None
    created_at: datetime


class UserPickerOut(BaseModel):
    """Минимальный shape для не-привилегированных ролей (A2 WARNING).

    Отдаёт ровно то, что нужно для выбора approver/owner/substitute в UI:
    id + ФИО + роль + email (для disambiguation в пикерах) + аватар + is_active.
    НЕ содержит telegram_user_id, manager_id/department_id (оргструктура),
    signature_url, onboarding-state — чтобы рядовой менеджер не выгружал
    корпоративный справочник одним запросом. Полный UserOut — только
    admin/director (см. list_users).
    """
    model_config = ConfigDict(from_attributes=True)
    id: int
    email: EmailStr
    full_name: str
    role: UserRole
    avatar_path: str | None = None
    job_title: str | None = None
    is_active: bool


class UpdateMeIn(BaseModel):
    full_name: str | None = None
    email: EmailStr | None = None
    current_password: str | None = None
    new_password: str | None = None
    # Эпик 21 — UX: профиль 2.0. Все опциональны (partial PATCH).
    # theme_preference валидируется на app-уровне (whitelist в роутере),
    # чтобы Pydantic не отбрасывал unknown значения с 422 — сначала проверим
    # сами и вернём 400 с понятным сообщением.
    theme_preference: str | None = None
    locale: str | None = None
    job_title: str | None = None


# ============ Counterparty ============

class CounterpartyIn(BaseModel):
    name: str
    full_legal_form: str | None = None
    legal_form: str | None = None
    gender_ending_oe: str | None = "ое"
    country_code: str = Field(min_length=2, max_length=2)
    director_position: str | None = None
    director_genitive: str | None = None
    director_short: str | None = None
    acts_basis: str | None = "Устава"
    tax_id_label: str | None = None
    tax_id: str | None = None
    address: str | None = None
    bank: str | None = None
    bank_code_label: str | None = None
    bank_code: str | None = None
    account: str | None = None
    phone: str | None = None
    email: str | None = None
    website: str | None = None
    notes: str | None = None
    group_id: int | None = None  # холдинг (группа юрлиц)
    # Эпик 10: явный ответственный менеджер (вместо fallback'а из свежей сделки).
    # Опционально на in: на create/patch может быть None — назначается позже.
    responsible_user_id: int | None = None
    # Эпик 14: owner (для scope-фильтра видимости) и department (автозальётся
    # из owner.department_id, если не указан). Семантика: owner ≠ responsible
    # (см. модель Counterparty).
    owner_user_id: int | None = None
    department_id: int | None = None


class CounterpartyOut(CounterpartyIn):
    model_config = ConfigDict(from_attributes=True)
    id: int
    category_code: str | None = None  # эффективная категория (своя или группы)
    turnover_rub: float | None = None
    # Эпик 8: extra_fields (custom fields scope='counterparty')
    extra_fields: dict[str, Any] = Field(default_factory=dict)
    created_at: datetime


# ============ Contract ============

class ContractIn(BaseModel):
    product_code: str = Field(pattern="^(macrocrm|macrosales|macroerp)$")
    country_code: str = Field(min_length=2, max_length=2)
    city: str
    # CONTACTS 2.0 Ф3-A: сторона договора — Company. counterparty_id оставлен
    # для обратной совместимости (резолвится в company через зеркало).
    company_id: int | None = None
    counterparty_id: int | None = None
    title: str | None = None
    # BUG-2: при создании договора из сделки (kebab «Создать договор») передаём
    # deal_id — после создания привязываем Deal.contract_id, чтобы win-gate видел
    # вложения/платежи договора по deal.contract_id.
    deal_id: int | None = None
    context: dict[str, Any] = Field(default_factory=dict)


class ContractPatch(BaseModel):
    title: str | None = None
    city: str | None = None
    company_id: int | None = None
    counterparty_id: int | None = None
    context: dict[str, Any] | None = None


class ContractOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    number: str | None
    title: str | None
    product_code: str
    country_code: str
    city: str | None
    company_id: int | None = None
    counterparty_id: int | None
    author_user_id: int
    status: ContractStatus
    context: dict[str, Any]
    template_version: str | None
    docx_path: str | None
    pdf_path: str | None
    drive_folder_url: str | None
    drive_docx_url: str | None
    drive_pdf_url: str | None
    archived_at: datetime | None = None
    signed_at: datetime | None = None
    # Эпик 8: extra_fields (custom fields scope='contract')
    extra_fields: dict[str, Any] = Field(default_factory=dict)
    created_at: datetime
    updated_at: datetime


class ContractList(BaseModel):
    items: list[ContractOut]
    total: int


class ContractAttachmentOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    contract_id: int
    kind: str
    original_name: str | None
    content_type: str | None
    uploaded_by_user_id: int | None
    created_at: datetime


# ============ Approvals ============

class ApprovalStage(BaseModel):
    order: int
    name: str
    user_ids: list[int]
    min_required: int = 1


class ApprovalRouteIn(BaseModel):
    name: str
    product_codes: list[str]
    country_codes: list[str]
    # Опционально: для legacy одно-этапных маршрутов
    approver_user_ids: list[int] = []
    min_required: int = 1
    # Опционально: для многоэтапных маршрутов
    stages: list[ApprovalStage] = []
    # Эпик 3: маршрут для определённой категории документа (sublicense_main /
    # addendum / notice / act / cancellation). Если null — общий маршрут.
    template_category: str | None = None


class ApprovalRouteOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    name: str
    product_codes: list[str]
    country_codes: list[str]
    approver_user_ids: list[int]
    min_required: int
    stages: list[ApprovalStage]
    is_active: bool
    template_category: str | None = None


class ApprovalRowOut(BaseModel):
    """Голос одного аппрувера в карточке договора."""
    model_config = ConfigDict(from_attributes=True)
    id: int
    user_id: int
    stage_order: int
    attempt: int
    decision: ApprovalDecision
    comment: str | None
    decided_at: datetime | None
    created_at: datetime


class ContractApprovalSummary(BaseModel):
    """Сводка по согласованию договора для карточки."""
    current_stage: int
    total_stages: int
    stages: list[dict]  # [{order, name, user_ids, min_required, approved, rejected, pending, is_active}]
    approvals: list[ApprovalRowOut]
    can_resubmit: bool


class ApprovalDecisionIn(BaseModel):
    decision: ApprovalDecision  # approved или rejected
    comment: str | None = None


class ReturnForReworkIn(BaseModel):
    """«Вернуть на доработку»: согласователь возвращает договор автору.

    comment («что поправить») обязателен — валидация непустоты в роутере (422).
    """
    comment: str


class ApprovalOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    contract_id: int
    user_id: int
    decision: ApprovalDecision
    comment: str | None
    decided_at: datetime | None
    created_at: datetime


# ============ Generation ============

class GenerateRequest(BaseModel):
    pass  # body пустой, всё что нужно в БД


class GenerateResponse(BaseModel):
    contract_id: int
    number: str
    docx_url: str
    pdf_url: str | None


# ============ Templates info ============

class ProductInfo(BaseModel):
    code: str
    name: str
    has_data_archive_clause: bool
    has_premium_support_option: bool
    has_multiple_copyright_holders: bool
    implementation_weeks: int
    training_hours_default: int
    brief_fields: list[dict[str, Any]]
    tech_fields: list[dict[str, Any]]
    modules_table: list[dict[str, Any]]
    raw: dict[str, Any]


class CountryInfo(BaseModel):
    code: str
    name_short: str
    name_full: str
    default_city: str
    currency_name: str
    currency_code: str
    licensor_name: str
    raw: dict[str, Any]


# ============ Template variables (кастомные переменные) ============

class TemplateVariableIn(BaseModel):
    key: str
    label: str
    help_text: str | None = None
    var_type: TemplateVariableType = TemplateVariableType.text
    options: list[str] = []
    default_value: str | None = None
    required: bool = False
    group: str | None = None
    sort_order: int = 0
    product_codes: list[str] = []
    country_codes: list[str] = []
    is_active: bool = True


class TemplateVariablePatch(BaseModel):
    # key неизменяем после создания — иначе сломаются теги {{ custom.<key> }} в .docx
    label: str | None = None
    help_text: str | None = None
    var_type: TemplateVariableType | None = None
    options: list[str] | None = None
    default_value: str | None = None
    required: bool | None = None
    group: str | None = None
    sort_order: int | None = None
    product_codes: list[str] | None = None
    country_codes: list[str] | None = None
    is_active: bool | None = None


class TemplateVariableOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    key: str
    label: str
    help_text: str | None
    var_type: TemplateVariableType
    options: list[str]
    default_value: str | None
    required: bool
    group: str | None
    sort_order: int
    product_codes: list[str]
    country_codes: list[str]
    is_active: bool


# ============ Продукты и прайс ============

class ProductPriceIn(BaseModel):
    currency: str
    # Numeric(18,2) в БД: запрещаем отрицательные / NaN/inf / переполнение колонки.
    amount: float = Field(ge=0, le=1e14, allow_inf_nan=False)


class ProductPriceOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    currency: str
    amount: float
    plan_id: int | None = None


class ProductPlanIn(BaseModel):
    id: int | None = None
    name: str
    unit: str = "year"
    sort_order: int = 0
    prices: list[ProductPriceIn] = Field(default_factory=list)


class ProductPlanOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    name: str
    unit: str
    sort_order: int
    prices: list[ProductPriceOut] = Field(default_factory=list)


class ProductIn(BaseModel):
    code: str | None = None  # пусто → сгенерируем из name
    name: str
    description: str | None = None
    group: str | None = None  # legacy строка (синхронизируется из group_id, если задан)
    group_id: int | None = None  # Wave 3: ссылка на ProductGroup
    pricing_type: str = "fixed"
    maps_to_product_code: str | None = None
    is_active: bool = True
    sort_order: int = 0
    prices: list[ProductPriceIn] = Field(default_factory=list)  # базовые (plan_id = null)
    plans: list[ProductPlanIn] = Field(default_factory=list)


class ProductOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    code: str
    name: str
    description: str | None
    group: str | None
    group_id: int | None = None
    pricing_type: str
    maps_to_product_code: str | None
    is_active: bool
    sort_order: int
    prices: list[ProductPriceOut] = Field(default_factory=list)
    plans: list[ProductPlanOut] = Field(default_factory=list)


class ContractItemIn(BaseModel):
    product_id: int
    plan_id: int | None = None
    qty: float = 1


class ContractItemOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    product_id: int
    plan_id: int | None
    name_snapshot: str
    currency: str
    qty: float
    unit_price: float
    line_total: float
    sort_order: int


class ContractItemsUpdate(BaseModel):
    currency: str
    discount_pct: float = 0
    items: list[ContractItemIn] = Field(default_factory=list)


class ContractPricingOut(BaseModel):
    currency: str | None
    subtotal: float
    discount_pct: float
    discount_amount: float
    total: float
    items: list[ContractItemOut] = Field(default_factory=list)
    manager_max_discount_pct: float | None = None  # текущий лимит — для UI


class DiscountSettingIn(BaseModel):
    # Процент скидки 0..100 — отбиваем мусорные/отрицательные/гигантские значения.
    manager_max_discount_pct: float | None = Field(default=None, ge=0, le=100)


class DiscountSettingOut(BaseModel):
    manager_max_discount_pct: float | None = None


# ============ Категории клиентов ============

class ClientCategoryIn(BaseModel):
    code: str
    name: str
    group: str | None = None
    min_amount: float = 0
    max_amount: float | None = None
    options: list[str] = Field(default_factory=list)
    color: str | None = None
    sort_order: int = 0
    is_active: bool = True
    # Эпик 23: визуальный конструктор категорий.
    description: str | None = None
    # JSONB object для расширенных опций (sla_hours/auto_owner/discount_pct etc.)
    options_meta: dict[str, Any] = Field(default_factory=dict)


class ClientCategoryOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    code: str
    name: str
    group: str | None
    min_amount: float
    max_amount: float | None
    options: list[str]
    color: str | None
    sort_order: int
    is_active: bool
    # Эпик 23
    description: str | None = None
    options_meta: dict[str, Any] = Field(default_factory=dict)


class ClientGroupIn(BaseModel):
    name: str


class ClientGroupOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    name: str
    category_code: str | None
    turnover_rub: float | None
    member_ids: list[int] = Field(default_factory=list)


class CategoryClientOut(BaseModel):
    """Строка автосписка клиентов по категории."""
    id: int
    name: str
    turnover_rub: float | None
    group_id: int | None


class FxRateOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    rate_date: date
    currency: str
    rate_to_rub: float


# ============ CRM-карточка клиента ============

class ContactIn(BaseModel):
    name: str
    position: str | None = None
    phone: str | None = None
    email: str | None = None
    messenger: str | None = None
    is_primary: bool = False
    note: str | None = None


class ContactOut(ContactIn):
    model_config = ConfigDict(from_attributes=True)
    id: int
    counterparty_id: int
    created_at: datetime


class ClientNoteIn(BaseModel):
    text: str


class ClientNoteOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    counterparty_id: int
    author_user_id: int | None
    text: str
    created_at: datetime


class ClientTaskIn(BaseModel):
    title: str
    description: str | None = None
    task_type: str = "other"
    assignee_user_id: int | None = None
    due_date: date | None = None


class ClientTaskUpdate(BaseModel):
    status: str | None = None  # open / done
    title: str | None = None
    description: str | None = None
    task_type: str | None = None
    assignee_user_id: int | None = None
    due_date: date | None = None


class ClientTaskOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    counterparty_id: int
    title: str
    description: str | None
    task_type: str
    assignee_user_id: int | None
    due_date: date | None
    status: str
    done_at: datetime | None
    created_by_user_id: int | None
    created_at: datetime


# ============ Воронки и сделки ============

class DepartmentIn(BaseModel):
    name: str
    sort_order: int = 0


class DepartmentOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    name: str
    sort_order: int


class PipelineStageIn(BaseModel):
    id: int | None = None  # для upsert при сохранении воронки
    name: str
    code: str | None = None  # машинный код этапа (B0/A1/C0 для воронки ЖЦ)
    sort_order: int = 0
    color: str | None = None
    is_won: bool = False
    is_lost: bool = False
    is_active: bool = True
    responsible_user_ids: list[int] = Field(default_factory=list)
    task_types: list[str] = Field(default_factory=list)
    visible_department_ids: list[int] = Field(default_factory=list)
    visible_user_ids: list[int] = Field(default_factory=list)
    # Эпик 23: визуальный конструктор воронок (опционально для legacy-вызовов).
    description: str | None = None
    sla_hours: int | None = None
    default_task_category_id: int | None = None
    # DEALS 2.0 (Ф0)
    hidden_by_default: bool = False
    parent_stage_id: int | None = None
    stage_features: list[str] = Field(default_factory=list)
    allowed_task_category_ids: list[int] = Field(default_factory=list)
    won_gate: bool = False


class PipelineStageOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    pipeline_id: int
    name: str
    code: str | None = None
    sort_order: int
    color: str | None
    is_won: bool
    is_lost: bool
    is_active: bool
    responsible_user_ids: list[int]
    task_types: list[str]
    visible_department_ids: list[int]
    visible_user_ids: list[int]
    # Эпик 23: визуальный конструктор воронок.
    description: str | None = None
    sla_hours: int | None = None
    default_task_category_id: int | None = None
    # DEALS 2.0 (Ф0)
    hidden_by_default: bool = False
    parent_stage_id: int | None = None
    stage_features: list[str] = Field(default_factory=list)
    allowed_task_category_ids: list[int] = Field(default_factory=list)
    won_gate: bool = False


# Эпик 23: inline-CRUD этапа (без id=None upsert семантики).
# PATCH partial — все поля Optional, не задано = не трогать.
class PipelineStageCreate(BaseModel):
    """Body для POST /api/pipelines/{pid}/stages (inline-создание этапа)."""
    name: str
    code: str | None = None
    sort_order: int = 0
    description: str | None = None
    sla_hours: int | None = None
    default_task_category_id: int | None = None
    color: str | None = None
    is_won: bool = False
    is_lost: bool = False
    is_active: bool = True
    responsible_user_ids: list[int] = Field(default_factory=list)
    task_types: list[str] = Field(default_factory=list)
    visible_department_ids: list[int] = Field(default_factory=list)
    visible_user_ids: list[int] = Field(default_factory=list)
    # DEALS 2.0 (Ф0)
    hidden_by_default: bool = False
    parent_stage_id: int | None = None
    stage_features: list[str] = Field(default_factory=list)
    allowed_task_category_ids: list[int] = Field(default_factory=list)
    won_gate: bool = False


class PipelineStagePatch(BaseModel):
    """Body для PATCH /api/pipelines/{pid}/stages/{sid} — partial update.

    Все поля Optional; если поле не передано (None и default), оно НЕ меняется.
    Чтобы явно сбросить description / sla_hours — нужен отдельный API
    (в MVP не предусмотрено: None в payload = «не трогать»).
    """
    name: str | None = None
    code: str | None = None
    sort_order: int | None = None
    description: str | None = None
    sla_hours: int | None = None
    default_task_category_id: int | None = None
    color: str | None = None
    is_won: bool | None = None
    is_lost: bool | None = None
    is_active: bool | None = None
    responsible_user_ids: list[int] | None = None
    task_types: list[str] | None = None
    visible_department_ids: list[int] | None = None
    visible_user_ids: list[int] | None = None
    # DEALS 2.0 (Ф0)
    hidden_by_default: bool | None = None
    parent_stage_id: int | None = None
    stage_features: list[str] | None = None
    allowed_task_category_ids: list[int] | None = None
    won_gate: bool | None = None


# ============ DEALS 2.0 (Ф0) — справочники и конструкторы ============

class LostReasonOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    name: str
    sort_order: int
    is_active: bool


class LostReasonIn(BaseModel):
    name: str = Field(min_length=1, max_length=255)
    sort_order: int = 0
    is_active: bool = True


class LostReasonPatch(BaseModel):
    name: str | None = Field(default=None, min_length=1, max_length=255)
    sort_order: int | None = None
    is_active: bool | None = None


class PipelineTransitionOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    name: str
    from_stage_id: int
    to_pipeline_id: int
    to_stage_id: int
    conditions: dict[str, Any] = Field(default_factory=dict)
    is_active: bool


class PipelineTransitionIn(BaseModel):
    name: str = Field(min_length=1, max_length=255)
    from_stage_id: int
    to_pipeline_id: int
    to_stage_id: int
    conditions: dict[str, Any] = Field(default_factory=dict)
    is_active: bool = True


class PipelineTransitionPatch(BaseModel):
    name: str | None = Field(default=None, min_length=1, max_length=255)
    from_stage_id: int | None = None
    to_pipeline_id: int | None = None
    to_stage_id: int | None = None
    conditions: dict[str, Any] | None = None
    is_active: bool | None = None


class MeetingReportOptionOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    question_id: int
    text: str
    sort_order: int


class MeetingReportOptionIn(BaseModel):
    text: str = Field(min_length=1, max_length=255)
    sort_order: int = 0


class MeetingReportOptionPatch(BaseModel):
    text: str | None = Field(default=None, min_length=1, max_length=255)
    sort_order: int | None = None


class MeetingReportQuestionOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    pipeline_id: int | None = None
    text: str
    kind: str
    sort_order: int
    is_active: bool
    options: list[MeetingReportOptionOut] = Field(default_factory=list)


class MeetingReportQuestionIn(BaseModel):
    pipeline_id: int | None = None
    text: str = Field(min_length=1)
    kind: str = "text"
    sort_order: int = 0
    is_active: bool = True
    # Варианты ответа (для kind='select'); пусто для kind='text'.
    options: list[MeetingReportOptionIn] = Field(default_factory=list)


class MeetingReportQuestionPatch(BaseModel):
    pipeline_id: int | None = None
    text: str | None = Field(default=None, min_length=1)
    kind: str | None = None
    sort_order: int | None = None
    is_active: bool | None = None


class PipelineIn(BaseModel):
    name: str
    kind: str = "sales"
    is_active: bool = True
    sort_order: int = 0
    # DEALS 2.0 (Ф1b): видимость воронки (опц., для legacy create/update —
    # дефолты «видна всем»).
    visible_role: str | None = None
    visible_user_ids: list[int] = Field(default_factory=list)


class PipelineOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    name: str
    kind: str = "sales"
    is_active: bool
    sort_order: int
    # DEALS 2.0 (Ф1b): видимость воронки (settings отдаём отдельным эндпоинтом).
    visible_role: str | None = None
    visible_user_ids: list[int] = Field(default_factory=list)


# DEALS 2.0 (Ф1b): настройки воронки — GET/PATCH /api/pipelines/{id}/settings.
class PipelineSettingsOut(BaseModel):
    auto_assign: bool = False
    duplicate_check_enabled: bool = False
    duplicate_check_fields: list[str] = Field(default_factory=list)


class PipelineSettingsPatch(BaseModel):
    auto_assign: bool | None = None
    duplicate_check_enabled: bool | None = None
    duplicate_check_fields: list[str] | None = None


# DEALS 2.0 (Ф1b): привязка канал↔воронка (источники сделок).
class PipelineChannelOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    name: str
    kind: str
    is_active: bool
    # Привязан ли канал к ЭТОЙ воронке (default_pipeline_id == pid).
    linked: bool = False


class PipelineChannelLinkIn(BaseModel):
    channel_id: int
    # True — привязать канал к воронке (default_pipeline_id=pid);
    # False — отвязать (default_pipeline_id=NULL).
    is_active: bool = True


class DealIn(BaseModel):
    pipeline_id: int
    stage_id: int | None = None  # пусто → первый видимый этап
    # CONTACTS 2.0 Ф3-C: company_id — источник истины; counterparty_id — зеркало (legacy).
    # Если передан только counterparty_id — резолвим company через зеркало.
    # Если передан только company_id — counterparty_id заполняется из company.counterparty_id.
    company_id: int | None = None
    counterparty_id: int | None = None
    title: str
    amount: float | None = None
    currency: str | None = None
    owner_user_id: int | None = None
    contract_id: int | None = None
    # Эпик 4.2: Pipedrive-стандарт — целевая дата закрытия
    expected_close_date: date | None = None
    # Wave 4: ожидаемые даты подписания / оплаты
    expected_sign_date: date | None = None
    expected_payment_date: date | None = None
    # DEALS 2.0 Ф1a
    tags: list[str] = Field(default_factory=list)
    product: str | None = None


class DealPatch(BaseModel):
    title: str | None = None
    # CONTACTS 2.0 Ф3-C: company_id — источник истины при обновлении.
    company_id: int | None = None
    counterparty_id: int | None = None
    amount: float | None = None
    currency: str | None = None
    owner_user_id: int | None = None
    contract_id: int | None = None
    # Эпик 4.2: amoCRM-стандарт — причина проигрыша (свободный текст)
    lost_reason: str | None = None
    # Эпик 4.2: Pipedrive — целевая дата закрытия
    expected_close_date: date | None = None
    # Wave 4: ожидаемые даты подписания / оплаты
    expected_sign_date: date | None = None
    expected_payment_date: date | None = None
    # DEALS 2.0 Ф1a
    tags: list[str] | None = None
    product: str | None = None
    lost_reason_id: int | None = None
    # P0 security (B1 CRITICAL): stage_id УДАЛЁН из PATCH — смена этапа в обход
    # win-gate / required-fields / DealStageHistory была дырой. Этап меняется
    # ТОЛЬКО через POST /deals/{id}/move (гейты + история). Bulk change_stage
    # имеет собственный путь и не использует DealPatch.


class DealMoveIn(BaseModel):
    stage_id: int
    # DEALS 2.0 Ф1a: потерянная сделка — обязательна причина при переходе в is_lost
    lost_reason: str | None = None
    lost_reason_id: int | None = None
    # DEALS 2.0 Ф1a: подстатус при переходе в won (await_payment / paid)
    substage_id: int | None = None


class DealOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    pipeline_id: int
    stage_id: int
    counterparty_id: int | None
    # CONTACTS 2.0 Ф3-C: company_id — источник истины (заполнен миграцией 0070).
    company_id: int | None = None
    title: str
    amount: float | None
    currency: str | None
    owner_user_id: int | None
    contract_id: int | None
    stage_changed_at: datetime | None
    closed_at: datetime | None
    # Эпик 4.2: amoCRM-стандарт
    lost_reason: str | None = None
    # Эпик 4.2: Pipedrive-стандарт
    expected_close_date: date | None = None
    # Wave 4: ожидаемые даты подписания / оплаты
    expected_sign_date: date | None = None
    expected_payment_date: date | None = None
    # Эпик 8: extra_fields (custom fields scope='deal')
    extra_fields: dict[str, Any] = Field(default_factory=dict)
    created_at: datetime
    # DEALS 2.0 Ф1a
    tags: list[str] = Field(default_factory=list)
    product: str | None = None
    lost_reason_id: int | None = None


# ============ Wave 4 (deal-card rework) ============

class DealProductIn(BaseModel):
    """Добавление позиции-продукта к сделке."""
    product_id: int
    plan_id: int | None = None
    quantity: float = Field(default=1, ge=0, le=1e9, allow_inf_nan=False)
    # unit_price — ручной override снимка цены. Если None — подтянем из ProductPrice.
    unit_price: float | None = Field(default=None, ge=0, le=1e14, allow_inf_nan=False)
    currency: str | None = None  # если None — берём валюту сделки/прайса


class DealProductPatch(BaseModel):
    quantity: float | None = Field(default=None, ge=0, le=1e9, allow_inf_nan=False)
    unit_price: float | None = Field(default=None, ge=0, le=1e14, allow_inf_nan=False)


class DealProductOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    deal_id: int
    product_id: int
    plan_id: int | None
    product_name: str | None = None
    plan_name: str | None = None
    quantity: float
    unit_price: float
    currency: str
    amount: float
    sort_order: int


class DealContactIn(BaseModel):
    """Привязка контакта к сделке. Либо contact_id существующего, либо
    данные нового контакта (full_name обязателен при создании)."""
    contact_id: int | None = None
    full_name: str | None = None
    phone: str | None = None
    position: str | None = None


class DealContactOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int           # id связи DealContact
    contact_id: int
    full_name: str
    phone: str | None = None
    email: str | None = None
    position: str | None = None
    sort_order: int


class DealCardFieldConfig(BaseModel):
    field: str
    label: str | None = None
    visible: bool = True
    order: int = 0
    required: bool = False


class DealCardConfigOut(BaseModel):
    deal_card_fields: list[DealCardFieldConfig]
    stage_required_fields: dict[str, list[str]] = Field(default_factory=dict)


class DealCardConfigIn(BaseModel):
    deal_card_fields: list[DealCardFieldConfig]
    stage_required_fields: dict[str, list[str]] = Field(default_factory=dict)


# DEALS 2.0 Ф1a: ближайшая незакрытая задача на сделку (next_task в BoardDealOut)
class NextTaskOut(BaseModel):
    activity_id: int
    title: str
    kind: str  # task | call | meeting | note
    due_at: datetime | None
    is_overdue: bool


class BoardDealOut(BaseModel):
    """Расширенный DealOut для доски: + next_task."""
    model_config = ConfigDict(from_attributes=True)
    id: int
    pipeline_id: int
    stage_id: int
    counterparty_id: int | None
    company_id: int | None = None
    title: str
    amount: float | None
    currency: str | None
    owner_user_id: int | None
    contract_id: int | None
    stage_changed_at: datetime | None
    closed_at: datetime | None
    lost_reason: str | None = None
    expected_close_date: date | None = None
    extra_fields: dict[str, Any] = Field(default_factory=dict)
    created_at: datetime
    tags: list[str] = Field(default_factory=list)
    product: str | None = None
    lost_reason_id: int | None = None
    # next_task — None если нет незакрытых задач
    next_task: NextTaskOut | None = None


class BoardColumn(BaseModel):
    stage: PipelineStageOut
    deals: list[BoardDealOut]


class BoardOut(BaseModel):
    pipeline: PipelineOut
    columns: list[BoardColumn]


# DEALS 2.0 Ф1a: bulk-операции над несколькими сделками
class DealBulkIn(BaseModel):
    """Bulk-действие над списком сделок.

    action:
      change_owner — сменить владельца (payload.owner_user_id).
      change_stage — сменить этап (payload.stage_id). Won-gate НЕ проверяется
                     в bulk (только при ручном move_deal).
      set_tags     — управление тегами (mode='add'|'replace'|'remove',
                     payload.tags). mode обязателен.
      delete       — удалить (только admin/director). payload игнорируется.
    """
    action: str  # change_owner | change_stage | set_tags | delete
    ids: list[int] = Field(min_length=1)
    payload: dict[str, Any] = Field(default_factory=dict)


class DealBulkResult(BaseModel):
    updated: int
    errors: list[str] = Field(default_factory=list)


# DEALS 2.0 Ф1a: list-эндпоинт (плоская таблица)
class DealListRow(BaseModel):
    """Строка плоской таблицы сделок (list-view).

    DEALS 2.0 Ф1a (фикс): денормализованные поля для фронта — company_name,
    stage_name, stage_color, city — чтобы DealListView не показывал «—».
    """
    model_config = ConfigDict(from_attributes=True)
    id: int
    pipeline_id: int
    stage_id: int
    company_id: int | None = None
    counterparty_id: int | None
    # Денормализованные поля (фронт рендерит напрямую)
    company_name: str | None = None
    stage_name: str | None = None
    stage_color: str | None = None
    city: str | None = None
    title: str
    amount: float | None
    currency: str | None
    owner_user_id: int | None
    stage_changed_at: datetime | None
    closed_at: datetime | None
    expected_close_date: date | None = None
    lost_reason: str | None = None
    tags: list[str] = Field(default_factory=list)
    product: str | None = None
    created_at: datetime
    next_task: NextTaskOut | None = None


# DEALS 2.0 Ф1a: meeting-report
class MeetingReportIn(BaseModel):
    """Ответы на вопросы конструктора отчёта о встрече.

    answers — список [{question_id: int, answer: str}]. Дополнительная
    валидация по реестру MeetingReportQuestion делается в роутере.
    """
    answers: list[dict[str, Any]]
    # Опциональное поле — linked activity_id (если хотим прикрепить к существующей)
    activity_id: int | None = None
    # Свободный комментарий по встрече (особенно когда вопросы не настроены)
    comment: str | None = None


class MeetingReportOut(BaseModel):
    activity_id: int
    deal_id: int
    # {"answers": [...], "comment": str | None}
    meeting_report_json: dict[str, Any]


# Эпик 10: горячие сделки для дашборда. Денормализованный shape (без N+1 на
# фронте); считается на бэке через JOIN deals × pipeline_stages × counterparties.
# heat_reason — "idle" (давно не двигалась) или "deadline" (близок expected_close_date).
class HotDealOut(BaseModel):
    id: int
    title: str
    amount: float | None
    currency: str | None
    stage_name: str
    stage_color: str | None
    idle_days: int
    days_to_close: int | None
    heat_reason: str  # "idle" | "deadline"
    counterparty_name: str | None
    # CONTACTS 2.0 Ф4: company_id — источник истины для ссылки /companies/[id]
    company_id: int | None = None


class DealStageHistoryOut(BaseModel):
    id: int
    from_stage_id: int | None
    from_stage_name: str | None
    to_stage_id: int
    to_stage_name: str | None
    user_id: int | None
    created_at: datetime


class StageAnalytics(BaseModel):
    stage_id: int
    name: str
    color: str | None
    count: int
    amount_sum: float
    avg_days_in_stage: float


class FunnelAnalytics(BaseModel):
    stages: list[StageAnalytics]
    won: int
    lost: int
    in_progress: int
    conversion: float  # won / (won + lost), 0..1


# ============ Реестр клиентов / Customer Success (Фаза 4) ============

# --- справочники ---
class PlatformOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    code: str
    name: str
    sort_order: int
    is_active: bool


class PlatformIn(BaseModel):
    code: str
    name: str
    sort_order: int = 0
    is_active: bool = True


class RegionOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    code: str
    name: str
    sort_order: int


class RegionIn(BaseModel):
    code: str
    name: str
    sort_order: int = 0


class ModuleOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    code: str
    name: str
    platform_id: int | None
    sort_order: int


class ModuleIn(BaseModel):
    code: str
    name: str
    platform_id: int | None = None
    sort_order: int = 0


class ChecklistItemTemplateOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    template_id: int
    code: str
    label: str
    group: str | None
    kind: str
    optional: bool
    sort_order: int


class ChecklistItemTemplateIn(BaseModel):
    code: str
    label: str
    group: str | None = None
    kind: str = "status"
    optional: bool = True
    sort_order: int = 0


class ChecklistTemplateOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    platform_id: int
    name: str


# --- подписки ---
class SubscriptionIn(BaseModel):
    # CONTACTS 2.0 Ф3-B: принимаем company_id как источник истины.
    # counterparty_id оставлен для совместимости (SubscriptionsTab на странице
    # /counterparties/[id] ещё его отправляет). Роутер резолвит оба поля.
    company_id: int | None = None
    counterparty_id: int | None = None
    platform_id: int
    region_id: int | None = None
    external_client_id: str | None = None
    lifecycle_stage_id: int | None = None
    imp_pm_user_id: int | None = None
    sup_pm_user_id: int | None = None
    am_user_id: int | None = None
    team_names: dict[str, Any] = Field(default_factory=dict)
    seats: int | None = None
    fee_actual: float | None = None
    fee_contract: float | None = None
    fee_currency: str | None = None
    tariff: str | None = None
    discount_until: date | None = None
    auto_prolongation: bool = False
    on_premise: bool = False
    last_fee_increase_at: date | None = None
    impl_start_date: date | None = None
    act_signed_date: date | None = None
    qa_result: str | None = None
    qa_date: date | None = None
    notes: str | None = None
    # Эпик 14: owner_user_id (для scope-видимости) + department_id (autofill).
    # Если не указан — на create подменяется из sup_pm_user_id.
    owner_user_id: int | None = None
    department_id: int | None = None


class SubscriptionPatch(BaseModel):
    region_id: int | None = None
    external_client_id: str | None = None
    lifecycle_stage_id: int | None = None
    imp_pm_user_id: int | None = None
    sup_pm_user_id: int | None = None
    am_user_id: int | None = None
    team_names: dict[str, Any] | None = None
    seats: int | None = None
    fee_actual: float | None = None
    fee_contract: float | None = None
    fee_currency: str | None = None
    tariff: str | None = None
    discount_until: date | None = None
    auto_prolongation: bool | None = None
    on_premise: bool | None = None
    last_fee_increase_at: date | None = None
    impl_start_date: date | None = None
    act_signed_date: date | None = None
    qa_result: str | None = None
    qa_date: date | None = None
    manual_tier_override: str | None = None
    is_active: bool | None = None
    notes: str | None = None
    # Эпик 14: owner/department для PATCH
    owner_user_id: int | None = None
    department_id: int | None = None


class SubscriptionOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    # CS-hotfix (0080): counterparty_id стал NULLABLE (company-first подписки).
    counterparty_id: int | None = None
    # CONTACTS 2.0 Ф3-B: company_id — источник истины начиная с Ф3-B.
    company_id: int | None = None
    platform_id: int
    region_id: int | None
    external_client_id: str | None
    lifecycle_stage_id: int | None
    stage_changed_at: datetime | None
    imp_pm_user_id: int | None
    sup_pm_user_id: int | None
    am_user_id: int | None
    team_names: dict[str, Any]
    seats: int | None
    fee_actual: float | None
    fee_contract: float | None
    fee_currency: str | None
    tariff: str | None
    discount_until: date | None
    auto_prolongation: bool
    on_premise: bool
    last_fee_increase_at: date | None
    impl_start_date: date | None
    act_signed_date: date | None
    impl_pct: float | None
    qa_result: str | None
    qa_date: date | None
    health_tier: str | None
    # CS-hotfix (0080): health_score заполняется в recompute_subscription_health —
    # 0..100 из средней активности относительно верхней полосы порогов (A1).
    # None, если данных активности нет (tier=None).
    health_score: float | None
    activity_avg: float | None
    activity_trend_pct: float | None
    dormant_periods: int | None
    health_reasons: list[str]
    manual_tier_override: str | None
    health_computed_at: datetime | None
    is_active: bool
    notes: str | None
    # Эпик 14: owner + department для scope-фильтра видимости
    owner_user_id: int | None = None
    department_id: int | None = None
    # Эпик 8: extra_fields (custom fields scope='subscription')
    extra_fields: dict[str, Any] = Field(default_factory=dict)
    created_at: datetime
    updated_at: datetime


class RegistryRow(BaseModel):
    """Строка экрана «Реестр» (собирается в роутере с джойнами)."""
    id: int
    # CS-hotfix (0080): counterparty_id стал NULLABLE (company-first подписки).
    counterparty_id: int | None = None
    # CONTACTS 2.0 Ф3-B: company_id — для ссылок /companies/[id] на фронте.
    company_id: int | None = None
    counterparty_name: str
    country_code: str | None
    category_code: str | None
    platform_id: int
    platform_name: str
    region_id: int | None
    region_name: str | None
    lifecycle_stage_id: int | None
    status_code: str | None
    status_name: str | None
    status_color: str | None
    impl_pct: float | None
    health_tier: str | None
    activity_avg: float | None
    activity_trend_pct: float | None
    dormant_periods: int | None
    attention: list[str]
    sparkline: list[int]
    seats: int | None
    fee_actual: float | None
    fee_currency: str | None
    tariff: str | None
    sup_pm_name: str | None
    am_name: str | None
    notes: str | None
    updated_at: datetime


# --- модули на подписке ---
class SubscriptionModuleOut(BaseModel):
    module_id: int
    code: str
    name: str
    enabled: bool
    status: str | None


class SubscriptionModuleSetIn(BaseModel):
    module_id: int
    enabled: bool = True
    status: str | None = None


class SubscriptionModulesPut(BaseModel):
    modules: list[SubscriptionModuleSetIn]


# --- чек-лист внедрения ---
class ChecklistItemView(BaseModel):
    template_item_id: int
    code: str
    label: str
    group: str | None
    kind: str
    optional: bool
    sort_order: int
    status: str
    num_done: int | None
    num_total: int | None
    pct: float | None
    value_date: date | None
    note: str | None
    completion: float  # 0..1


class ChecklistView(BaseModel):
    subscription_id: int
    overall_pct: float
    items: list[ChecklistItemView]


class ChecklistItemStatusIn(BaseModel):
    template_item_id: int
    status: str = "not_started"
    num_done: int | None = None
    num_total: int | None = None
    pct: float | None = None
    value_date: date | None = None
    note: str | None = None


class ChecklistPut(BaseModel):
    items: list[ChecklistItemStatusIn]


# --- активность ---
class ActivitySnapshotOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    period_start: date
    period_end: date | None
    metric: str
    value: int
    source: str


class ActivityPut(BaseModel):
    period_start: date
    period_end: date | None = None
    value: int
    metric: str = "actions"


# --- дашборд KPI ---
class RegistryKpiOut(BaseModel):
    total: int
    active: int
    support: int
    in_implementation: int
    operating: int
    closed: int
    # CS-hotfix (0080): подписки без этапа ЖЦ (учтены в total/operating).
    no_stage: int = 0
    conversion_support: float
    conversion_closed: float
    by_code: dict[str, int]


# ============ Эпик 21 — Notifications (in-app inbox) ============


class NotificationOut(BaseModel):
    """Одна нотификация в UI inbox.

    Поле `metadata` (на фронт) ↔ ORM-атрибут `meta` (на бэке).
    SQLAlchemy Base резервирует `metadata` за схемой таблиц, поэтому
    атрибут модели назван `meta`, а в JSON отдаём `metadata` через alias.
    """

    model_config = ConfigDict(from_attributes=True, populate_by_name=True)
    id: int
    kind: str
    title: str
    body: str | None = None
    link: str | None = None
    is_read: bool
    read_at: datetime | None = None
    created_at: datetime
    # Extension data (deal_id, amount, approval_id и т.д. в зависимости от kind).
    metadata: dict[str, Any] | None = Field(default=None, alias="meta")


class NotificationListOut(BaseModel):
    items: list[NotificationOut]
    unread_count: int
    total: int


class NotificationCountOut(BaseModel):
    unread_count: int


# ============ Эпик 18 — AI Features: contract analysis ============

class ContractAnalysisIssue(BaseModel):
    """Одна находка анализа договора.

    severity: 'error' | 'warning' | 'info'. Whitelist на роутер-уровне.
    quote — точная цитата (для UI: italic blockquote). Может быть пустой,
    если замечание касается отсутствующей секции.
    """
    severity: str
    quote: str = ""
    explanation: str
    suggestion: str | None = None
    # Опциональный «номер пункта» договора (например «п. 3.2») — Claude
    # может извлечь, может не извлечь. UI рендерит как badge.
    section: str | None = None


class ContractAnalysisStandardSection(BaseModel):
    """Чек-лист стандартных секций договора (UI таб 2)."""
    section: str
    status: str  # 'ok' | 'warning' | 'missing'


class ContractAnalysisOut(BaseModel):
    """Полный ответ /api/contracts/{id}/ai-analyze.

    from_cache=True означает что вернули закэшированный результат (не звонили
    в Anthropic). UI рендерит «Последний анализ N мин назад».
    """
    contract_id: int
    issues: list[ContractAnalysisIssue] = Field(default_factory=list)
    standard_sections: list[ContractAnalysisStandardSection] = Field(default_factory=list)
    recommendations: list[str] = Field(default_factory=list)
    model: str | None = None
    analyzed_at: datetime | None = None
    ai_tokens_used: int | None = None
    from_cache: bool = False


# ============ Эпик 18 — AI Features: deal prefill ============

class DealPrefillSuggestion(BaseModel):
    """Одно AI-предложение поля сделки.

    suggested_value — Any (число / строка / дата ISO). UI применяет как есть
    через PATCH /deals/{id} только для выбранных пользователем полей.
    confidence — 'high' | 'medium' | 'low'. Whitelist на роутер-уровне.
    """
    field: str
    label: str
    suggested_value: Any
    confidence: str
    reasoning: str
    source_activity_id: int | None = None
    source_text: str | None = None


class DealPrefillOut(BaseModel):
    """Полный ответ /api/deals/{id}/ai-prefill."""
    deal_id: int
    source: str  # 'tg' | 'email' | 'notes' | 'activities' | 'messages' | 'all'
    period_days: int  # 0 = all-time
    summary: str = ""  # 1-2 предложения краткого резюме клиента (для шапки UI)
    suggestions: list[DealPrefillSuggestion] = Field(default_factory=list)
    ai_tokens_used: int | None = None
    model: str | None = None


class LeadPrefillOut(BaseModel):
    """Полный ответ /api/leads/{id}/ai-prefill. Тот же shape что DealPrefillOut."""
    lead_id: int
    source: str
    period_days: int
    summary: str = ""
    suggestions: list[DealPrefillSuggestion] = Field(default_factory=list)
    ai_tokens_used: int | None = None
    model: str | None = None
