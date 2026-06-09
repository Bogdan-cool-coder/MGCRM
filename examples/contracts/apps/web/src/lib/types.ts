export type UserRole = "admin" | "director" | "lawyer" | "manager" | "accountant" | "cfo";

export const RoleLabels: Record<UserRole, string> = {
  admin: "Администратор",
  director: "Руководитель",
  lawyer: "Юрист",
  manager: "Менеджер",
  accountant: "Бухгалтер",
  cfo: "CFO",
};

export interface User {
  id: number;
  email: string;
  full_name: string;
  role: UserRole;
  telegram_user_id?: number | null;
  avatar_path?: string | null;
  department_id?: number | null;
  manager_id?: number | null;
  is_active?: boolean;
  created_at?: string;
  // Эпик 13: wizard fields
  is_onboarding_wizard_shown?: boolean;
  crm_experience_level?: string | null;
  phone?: string | null;
  // Эпик 16: 2FA + SSO
  totp_enabled?: boolean;
  totp_enabled_at?: string | null;
  has_password?: boolean;
  // Эпик 21: UX Upgrade
  theme_preference?: "light" | "dark" | "system" | null;
  signature_image_url?: string | null;
  job_title?: string | null;
  locale?: string | null;
  timezone?: string | null;
}

// ── Эпик 21: Notification Center ──────────────────────────────────────

export type NotificationKind =
  | "approval_pending"
  | "approval_result"
  | "deal_stage_change"
  | "activity_due_soon"
  | "onboarding_overdue"
  | "webhook_delivery_failed";

export interface Notification {
  id: number;
  kind: string;
  title: string;
  body?: string | null;
  link?: string | null;
  is_read: boolean;
  read_at?: string | null;
  created_at: string;
  metadata?: Record<string, unknown> | null;
}

export interface NotificationListOut {
  items: Notification[];
  unread_count: number;
  total: number;
}

export type ContractStatus =
  | "draft"
  | "submitted"
  | "in_review"
  | "needs_rework"
  | "approved"
  | "signed"
  | "rejected"
  | "uploaded"
  | "archived";

export const StatusLabels: Record<ContractStatus, { label: string; color: string; dot: string }> = {
  draft:        { label: "Черновик",         color: "bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300",                  dot: "bg-gray-500" },
  submitted:    { label: "Отправлен",        color: "bg-info-50 text-info-700 dark:bg-info-500/10 dark:text-info-500",                dot: "bg-info-500" },
  in_review:    { label: "На согласовании",  color: "bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-500",    dot: "bg-warning-500" },
  needs_rework: { label: "На доработке",     color: "bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-500",    dot: "bg-warning-600" },
  approved:     { label: "Согласован",       color: "bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-500",    dot: "bg-success-500" },
  signed:       { label: "Сделка проведена", color: "bg-success-100 text-success-700 dark:bg-success-500/20 dark:text-success-500",   dot: "bg-success-600" },
  rejected:     { label: "Отклонён",         color: "bg-danger-50 text-danger-700 dark:bg-danger-500/10 dark:text-danger-500",        dot: "bg-danger-500" },
  uploaded:     { label: "В Drive",          color: "bg-info-100 text-info-700 dark:bg-info-500/20 dark:text-info-500",               dot: "bg-info-600" },
  archived:     { label: "Архив",            color: "bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400",                  dot: "bg-gray-500" },
};

/** Группа статусов договоров (`GET /api/contracts/status-groups`). */
export interface ContractStatusSubstatus {
  status: string;
  label: string;
}

export interface ContractStatusGroup {
  code: string;
  label: string;
  order: number;
  statuses: string[];
  substatuses: ContractStatusSubstatus[];
}

export interface ContractStatusGroupsResponse {
  groups: ContractStatusGroup[];
}

export interface Counterparty {
  id: number;
  name: string;
  full_legal_form?: string | null;
  legal_form?: string | null;
  gender_ending_oe?: string | null;
  country_code: string;
  director_position?: string | null;
  director_genitive?: string | null;
  director_short?: string | null;
  acts_basis?: string | null;
  tax_id_label?: string | null;
  tax_id?: string | null;
  address?: string | null;
  bank?: string | null;
  bank_code_label?: string | null;
  bank_code?: string | null;
  account?: string | null;
  phone?: string | null;
  email?: string | null;
  website?: string | null;
  notes?: string | null;
  group_id?: number | null;
  category_code?: string | null;
  turnover_rub?: number | null;
  responsible_user_id?: number | null;
  department_id?: number | null;
  extra_fields?: Record<string, unknown>;
}

export interface ClientCategory {
  id: number;
  code: string;
  name: string;
  group: string | null;
  min_amount: number;
  max_amount: number | null;
  options: string[];
  /** Эпик 23: текстовое описание категории */
  description?: string | null;
  /** Эпик 23: структурированные атрибуты ключ=значение */
  options_map?: Record<string, unknown> | null;
  color: string | null;
  sort_order: number;
  is_active: boolean;
}

export interface ClientGroup {
  id: number;
  name: string;
  category_code: string | null;
  turnover_rub: number | null;
  member_ids: number[];
}

export interface CategoryClient {
  id: number;
  name: string;
  turnover_rub: number | null;
  group_id: number | null;
}

/**
 * Legacy-контакт CRM-карточки контрагента (`/api/counterparties/{id}/contacts`).
 * Привязан 1:N к Counterparty. Используется на странице контрагента (вкладка «Контакты»).
 * Не путать с новой CRM-сущностью `Contact` (см. ниже, таблица `crm_contacts`).
 */
export interface LegacyContact {
  id: number;
  counterparty_id: number;
  name: string;
  position: string | null;
  phone: string | null;
  email: string | null;
  messenger: string | null;
  is_primary: boolean;
  note: string | null;
  created_at: string;
}

/**
 * CRM-контакт (Эпик 1.2): отдельная сущность `crm_contacts`, привязка к Company по company_id.
 * Используется на странице `/contacts` и вкладке «Контакты» в карточке компании.
 */
export interface Contact {
  id: number;
  full_name: string;
  email: string | null;
  phone: string | null;
  position: string | null;
  company_id: number | null;
  is_primary: boolean;
  owner_id: number | null;
  tg_username: string | null;
  notes: string | null;
  source?: ContactSource | null;
  tags?: string[];
  status?: string | null;
  created_at: string;
  updated_at: string;
  extra_fields?: Record<string, unknown>;
}

/**
 * CRM-компания (Эпик 1.2): отдельная сущность `crm_companies`, рядом с legacy Counterparty.
 * Связь с legacy — через `counterparty_id` (может быть null, если компания не привязана).
 */
export interface Company {
  id: number;
  /** Юридическое название. Используй name как отображаемое, legal_name — официальное. */
  name?: string | null;
  legal_name: string;
  short_name: string | null;
  tax_id: string | null;
  country: string | null;
  city: string | null;
  website: string | null;
  phone: string | null;
  email: string | null;
  industry: string | null;
  notes: string | null;
  source?: ContactSource | null;
  company_type_id?: number | null;
  owner_user_id?: number | null;
  tags?: string[];
  holding_role?: HoldingRole | null;
  group_id: number | null;
  category_code: string | null;
  counterparty_id: number | null;
  // CONTACTS 2.0 Ф0: реквизиты стороны договора (для пикера в /contracts/new)
  legal_form?: string | null;
  full_legal_form?: string | null;
  country_code?: string | null;
  tax_id_label?: string | null;
  created_at: string;
  updated_at: string;
  extra_fields?: Record<string, unknown>;
}

// ── CONTACTS 2.0 (Фаза 2) ─────────────────────────────────────────────

/**
 * Источник контакта/компании — enum-значения из backend.
 */
export type ContactSource =
  | "own_contact"
  | "cold_call"
  | "partner"
  | "internet"
  | "lead";

export const CONTACT_SOURCE_LABELS: Record<ContactSource, string> = {
  own_contact: "Свой контакт",
  cold_call: "Холодный звонок",
  partner: "Партнёр",
  internet: "Из интернета",
  lead: "Лид-заявка",
};

export const CONTACT_SOURCE_OPTIONS: { value: ContactSource; label: string }[] = [
  { value: "own_contact", label: CONTACT_SOURCE_LABELS.own_contact },
  { value: "cold_call", label: CONTACT_SOURCE_LABELS.cold_call },
  { value: "partner", label: CONTACT_SOURCE_LABELS.partner },
  { value: "internet", label: CONTACT_SOURCE_LABELS.internet },
  { value: "lead", label: CONTACT_SOURCE_LABELS.lead },
];

/**
 * Роль компании в холдинге.
 */
export type HoldingRole = "parent" | "subsidiary";

export const HOLDING_ROLE_LABELS: Record<HoldingRole, string> = {
  parent: "Основная компания",
  subsidiary: "Дочерняя",
};

/**
 * Тип компании (справочник /api/admin/company-types).
 */
export interface CompanyType {
  id: number;
  name: string;
  description?: string | null;
  is_active?: boolean;
}

/**
 * Должность контакта (справочник /api/admin/contact-positions).
 */
export interface ContactPosition {
  id: number;
  name: string;
}

/**
 * Холдинг (ClientGroup) с количеством компаний — ответ /api/holdings.
 */
export interface Holding {
  id: number;
  name: string;
  company_count?: number;
  member_ids?: number[];
}

/**
 * Элемент unified-списка контактов (/api/contacts/unified).
 * kind='person' — физлицо (Contact), kind='company' — компания.
 */
export interface UnifiedContactItem {
  id: number;
  kind: "person" | "company";
  name: string;
  phone: string | null;
  email: string | null;
  owner_id: number | null;
  source: ContactSource | null;
  tags: string[];
  /** Type-specific поля:
   *  person: { position, company_id, company_name, is_primary, tg_username, ... }
   *  company: { legal_name, tax_id, country, city, counterparty_id, company_type_id, ... }
   */
  extra: Record<string, unknown>;
}

/**
 * Ответ со списком unified контактов.
 */
export interface UnifiedContactsResponse {
  items: UnifiedContactItem[];
  total: number;
}

/**
 * Связь контакта с компанией.
 */
export interface ContactCompanyLink {
  contact_id: number;
  company_id: number;
  position: string | null;
  is_primary: boolean;
}

export interface ClientNote {
  id: number;
  counterparty_id: number;
  author_user_id: number | null;
  text: string;
  created_at: string;
}

export type TaskStatus = "open" | "done";

export interface ClientTask {
  id: number;
  counterparty_id: number;
  title: string;
  description: string | null;
  task_type: string;
  assignee_user_id: number | null;
  due_date: string | null;
  status: TaskStatus;
  done_at: string | null;
  created_by_user_id: number | null;
  created_at: string;
}

export const TASK_TYPES: { value: string; label: string }[] = [
  { value: "call", label: "Звонок" },
  { value: "meeting", label: "Встреча" },
  { value: "proposal", label: "КП" },
  { value: "contract", label: "Договор" },
  { value: "followup", label: "Follow-up" },
  { value: "other", label: "Другое" },
];

// ===== Лиды (Эпик 1.0) =====
export type LeadSource = "manual" | "form" | "import" | "api" | "email" | "tg" | "wa";
export type LeadStatus = "active" | "converted" | "archived" | "lost";

export interface Lead {
  id: number;
  name: string;
  contact_email: string | null;
  contact_phone: string | null;
  source: LeadSource;
  owner_id: number | null;
  pipeline_id: number;
  stage_id: number;
  status: LeadStatus;
  tags: string[];
  notes: string | null;
  converted_to_counterparty_id: number | null;
  /** CONTACTS 2.0 Ф3-C: company_id при конверсии (источник истины). */
  converted_to_company_id?: number | null;
  converted_at: string | null;
  created_at: string;
  updated_at: string;
  // Эпик 4.2
  score?: number | null;
  converted_deal_id?: number | null;
  extra_fields?: Record<string, unknown>;
}

export interface LeadConvertResult {
  lead: Lead;
  counterparty_id: number;
  deal_id: number;
  created_new_counterparty: boolean;
  /** CONTACTS 2.0 Ф3-C: company_id созданной/найденной Company. */
  company_id?: number | null;
}

export const LEAD_SOURCE_LABELS: Record<LeadSource, string> = {
  manual: "Вручную",
  form: "Заявка с сайта",
  import: "Импорт",
  api: "API",
  email: "Email",
  tg: "Telegram",
  wa: "WhatsApp",
};

export const LEAD_STATUS_LABELS: Record<LeadStatus, string> = {
  active: "Активный",
  converted: "Сконвертирован",
  archived: "Архив",
  lost: "Потерян",
};

// ===== Activities / Timeline (Эпик 2) =====
export type ActivityKind = "call" | "meeting" | "task" | "note";
export type ActivityTargetType =
  | "lead"
  | "contact"
  | "company"
  | "counterparty"
  | "deal"
  | "contract"
  | "subscription";

// ===== Эпик 24: Task fields =====
export type ActivityStatus = "new" | "in_progress" | "done" | "rejected";
export type ActivityPriority = "low" | "normal" | "high" | "critical";
export type CollaboratorRole = "co_executor" | "auditor" | "observer";
export type RecurrenceRule = "daily" | "weekly" | "monthly";

export interface ActivityCollaborator {
  id: number;
  user_id: number;
  user_name: string;
  role: CollaboratorRole;
}

export interface ChecklistItem {
  id: number;
  activity_id: number;
  text: string;
  is_done: boolean;
  sort_order: number;
}

export interface ActivityAttachment {
  id: number;
  activity_id: number;
  filename: string;
  url: string;
  size_bytes: number | null;
  uploaded_by_name: string | null;
  created_at: string;
}

export interface TaskCategory {
  id: number;
  name: string;
  description_template: string | null;
  color: string | null;
  default_executor_id: number | null;
  default_executor_name: string | null;
  default_co_executor_ids: number[];
  default_auditor_ids: number[];
  default_observer_ids: number[];
  checklist_template_items: { text: string; sort_order: number }[];
  required_files_count: number;
  restrict_close_without_result: boolean;
  auto_title_from_category: boolean;
  sort_order: number;
  is_active: boolean;
  checklist_items_count: number;
}

export const ACTIVITY_STATUS_LABELS: Record<ActivityStatus, string> = {
  new: "Новая",
  in_progress: "В работе",
  done: "Выполнена",
  rejected: "Отклонена",
};

export const ACTIVITY_STATUS_COLORS: Record<ActivityStatus, string> = {
  new:        "bg-info-50 text-info-700 dark:bg-info-500/10 dark:text-info-500",
  in_progress: "bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-500",
  done:       "bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-500",
  rejected:   "bg-danger-50 text-danger-700 dark:bg-danger-500/10 dark:text-danger-500",
};

export const ACTIVITY_PRIORITY_LABELS: Record<ActivityPriority, string> = {
  low: "Низкий",
  normal: "Нормальный",
  high: "Высокий",
  critical: "Критический",
};

export const ACTIVITY_PRIORITY_COLORS: Record<ActivityPriority, string> = {
  low: "bg-gray-300",
  normal: "bg-info",
  high: "bg-warning",
  critical: "bg-danger",
};

export interface Activity {
  id: number;
  kind: ActivityKind;
  target_type: ActivityTargetType | null;
  target_id: number | null;
  title: string;
  body: string | null;
  due_at: string | null;
  completed_at: string | null;
  completed_by_id: number | null;
  responsible_id: number | null;
  created_by_id: number;
  created_at: string;
  updated_at: string;
  created_by_name?: string | null;
  responsible_name?: string | null;
  completed_by_name?: string | null;
  // Эпик 10.5: FTM (First Time Meeting)
  is_first_time_meeting?: boolean | null;
  ftm_counted?: boolean | null;
  ftm_decision_maker_attended?: boolean | null;
  ftm_presentation_shown?: boolean | null;
  ftm_report_url?: string | null;
  ftm_telegram_announced?: boolean | null;
  // Эпик 24: Task fields
  status?: ActivityStatus | null;
  is_closed?: boolean;
  priority?: ActivityPriority | null;
  category_id?: number | null;
  category_name?: string | null;
  parent_activity_id?: number | null;
  progress_pct?: number | null;
  planned_hours?: number | null;
  actual_hours?: number | null;
  result_text?: string | null;
  tags?: string[];
  recurrence_rule?: RecurrenceRule | null;
  recurrence_until?: string | null;
  recurrence_parent_id?: number | null;
  is_pinned?: boolean;
  is_favorite?: boolean;
  color_label?: string | null;
  collaborators?: ActivityCollaborator[];
  checklist_items?: ChecklistItem[];
  attachments?: ActivityAttachment[];
  // Эпик 24.2: Google Calendar sync
  google_calendar_synced?: boolean | null;
}

export const ACTIVITY_KIND_LABELS: Record<ActivityKind, string> = {
  call: "Звонок",
  meeting: "Встреча",
  task: "Задача",
  note: "Заметка",
};

export const ACTIVITY_TARGET_LABELS: Record<ActivityTargetType, string> = {
  lead: "Лид",
  contact: "Контакт",
  company: "Компания",
  counterparty: "Контрагент",
  deal: "Сделка",
  contract: "Договор",
  subscription: "Подписка",
};

// ===== Воронки и сделки =====
export interface Department {
  id: number;
  name: string;
  parent_id: number | null;
  head_user_id: number | null;
  sort_order: number;
  is_active: boolean;
  children?: Department[];
  members_count?: number;
}

// Эпик 14: Visibility ACL
export type DepartmentScope = "all" | "personal" | "department" | "department_and_children";

export type VisibilityEntityType =
  | "lead" | "deal" | "contract" | "subscription" | "counterparty" | "company";

/** Роли, для которых настраивается scope (бэкенд ALLOWED_APPLIES_TO_ROLES). */
export type VisibilityRole =
  | "admin" | "director" | "lawyer" | "manager" | "accountant" | "cfo";

export interface VisibilitySetting {
  id?: number;
  entity_type: VisibilityEntityType;
  applies_to_role: VisibilityRole;
  scope: DepartmentScope;
}

/** P2-D: одна строка матрицы «видимость по ролям» — единый scope роли на все сущности. */
export interface VisibilityRoleScope {
  role: VisibilityRole;
  scope: DepartmentScope;
}

export interface Pipeline {
  id: number;
  name: string;
  kind: string;
  is_active: boolean;
  sort_order: number;
  /** DEALS 2.0: видимость по роли */
  visible_role?: string | null;
  /** DEALS 2.0: видимость по конкретным пользователям */
  visible_user_ids?: number[];
}

export interface PipelineStage {
  id: number;
  pipeline_id: number;
  name: string;
  code: string | null;
  sort_order: number;
  color: string | null;
  is_won: boolean;
  is_lost: boolean;
  is_active: boolean;
  responsible_user_ids: number[];
  task_types: string[];
  visible_department_ids: number[];
  visible_user_ids: number[];
  /** Эпик 23: описание этапа */
  description?: string | null;
  /** Эпик 23: SLA-часы (0 = без SLA) */
  sla_hours?: number | null;
  /** Эпик 23: дефолтная категория задач (Эпик 24) */
  default_task_category_id?: number | null;
  /** DEALS 2.0: скрыт по умолчанию (Холодные, Проиграна) */
  hidden_by_default?: boolean;
  /** DEALS 2.0: FK на родительский этап (подстатусы) */
  parent_stage_id?: number | null;
  /** DEALS 2.0: фичи этапа (send_presentation, meeting_report, generate_document) */
  stage_features?: string[];
  /** DEALS 2.0: допустимые категории задач для этапа */
  allowed_task_category_ids?: number[];
  /** DEALS 2.0: требовать гейт (signed_scan или payment) при переводе в is_won */
  won_gate?: boolean;
}

export interface Deal {
  id: number;
  pipeline_id: number;
  stage_id: number;
  counterparty_id: number | null;
  /** CONTACTS 2.0 Ф3-C: company_id — источник истины (заполнен миграцией 0070). */
  company_id?: number | null;
  title: string;
  amount: number | null;
  currency: string | null;
  owner_user_id: number | null;
  contract_id: number | null;
  stage_changed_at: string | null;
  closed_at: string | null;
  created_at: string;
  // Эпик 4.2
  expected_close_date?: string | null;
  lost_reason?: string | null;
  lost_reason_id?: number | null;
  extra_fields?: Record<string, unknown>;
  // DEALS 2.0
  tags?: string[];
  product?: string | null;
}

/** DEALS 2.0: задача-напоминание в карточке сделки на доске.
 * Синхронизировано с backend NextTaskOut (activity_id, title, due_at|null). */
export interface NextTask {
  activity_id: number;
  title: string;
  kind: "call" | "meeting" | "task" | "note";
  due_at: string | null;
  is_overdue: boolean;
}

/** DEALS 2.0: сделка в формате доски (обогащённый DTO от /api/deals/board) */
export interface BoardDealOut {
  id: number;
  pipeline_id: number;
  stage_id: number;
  company_id: number | null;
  counterparty_id: number | null;
  title: string;
  amount: number | null;
  currency: string | null;
  owner_user_id: number | null;
  tags: string[];
  product: string | null;
  expected_close_date: string | null;
  created_at: string;
  next_task: NextTask | null;
}

/** DEALS 2.0: строка в табличном виде (/api/deals/list) */
export interface DealListRow {
  id: number;
  company_id: number | null;
  company_name: string | null;
  stage_id: number;
  stage_name: string;
  stage_color: string | null;
  owner_user_id: number | null;
  amount: number | null;
  currency: string | null;
  product: string | null;
  city: string | null;
  next_task: NextTask | null;
  created_at: string;
}

/** DEALS 2.0: bulk-action на сделках */
export type DealBulkActionKind = "change_owner" | "change_stage" | "set_tags" | "delete";

export interface DealBulkAction {
  action: DealBulkActionKind;
  ids: number[];
  payload: Record<string, unknown>;
}

/** DEALS 2.0: результат bulk-операции. Бэк возвращает 200 даже при частичных
 * ошибках — errors[] перечисляет проблемные сделки. */
export interface DealBulkResult {
  updated: number;
  errors: string[];
}

/** DEALS 2.0: причина проигрыша из реестра */
export interface LostReason {
  id: number;
  name: string;
  sort_order?: number;
  is_active?: boolean;
}

/** DEALS 2.0 Ф2b: настройки воронки */
export interface PipelineSettings {
  auto_assign: boolean;
  duplicate_check_enabled: boolean;
  duplicate_check_fields: string[];
}

/** DEALS 2.0 Ф2b: привязка канала к воронке */
export interface PipelineChannel {
  id: number;
  name: string;
  kind: string;
  is_active: boolean;
  linked: boolean;
}

/** DEALS 2.0 Ф2b: переход между воронками */
export interface PipelineTransition {
  id: number;
  name: string;
  from_stage_id: number;
  to_pipeline_id: number;
  to_stage_id: number;
  conditions: {
    require_signed_scan?: boolean;
    require_field?: string | null;
  };
  is_active: boolean;
}

/** DEALS 2.0 Ф2b: вопрос встречи */
export interface MeetingQuestion {
  id: number;
  text: string;
  kind: "text" | "select";
  pipeline_id: number | null;
  sort_order: number;
  is_active: boolean;
  options?: MeetingQuestionOption[];
}

/** DEALS 2.0 Ф2b: вариант ответа вопроса встречи */
export interface MeetingQuestionOption {
  id: number;
  question_id: number;
  text: string;
  sort_order: number;
}

/** DEALS 2.0 Ф2b: 409-ответ при нарушении win-gate */
export interface WinGateFailedError {
  code: "WIN_GATE_FAILED";
  has_signed_scan: boolean;
  has_payment: boolean;
  contract_id: number | null;
}

export interface BoardColumn { stage: PipelineStage; deals: BoardDealOut[]; }
export interface Board { pipeline: Pipeline; columns: BoardColumn[]; }

/** Полная карточка сделки (GET /api/deals/{id}) */
export interface DealOut {
  id: number;
  pipeline_id: number;
  stage_id: number;
  counterparty_id: number | null;
  company_id: number | null;
  title: string;
  amount: number | null;
  currency: string | null;
  owner_user_id: number | null;
  contract_id: number | null;
  stage_changed_at: string | null;
  closed_at: string | null;
  lost_reason: string | null;
  expected_close_date: string | null;
  // Wave 4: ожидаемые даты подписания / оплаты
  expected_sign_date: string | null;
  expected_payment_date: string | null;
  extra_fields: Record<string, unknown>;
  created_at: string;
  tags: string[];
  product: string | null;
  lost_reason_id: number | null;
}

// ── Wave 4 (deal-card rework) ────────────────────────────────────────

/** Позиция-продукт сделки (GET/POST /api/deals/{id}/products). */
export interface DealProductOut {
  id: number;
  deal_id: number;
  product_id: number;
  plan_id: number | null;
  product_name: string | null;
  plan_name: string | null;
  quantity: number;
  unit_price: number;
  currency: string;
  amount: number;
  sort_order: number;
}

/** Контакт, привязанный к сделке (GET/POST /api/deals/{id}/contacts). */
export interface DealContactOut {
  id: number;
  contact_id: number;
  full_name: string;
  phone: string | null;
  email: string | null;
  position: string | null;
  sort_order: number;
}

/** Конфиг одного поля карточки сделки. */
export interface DealCardFieldConfig {
  field: string;
  label?: string;
  visible: boolean;
  order: number;
  required: boolean;
}

/** Конфиг карточки сделки воронки (GET/PUT /api/pipelines/{id}/deal-card-config). */
export interface DealCardConfig {
  deal_card_fields: DealCardFieldConfig[];
  stage_required_fields: Record<string, string[]>;
}

/** Стандартные поля карточки сделки — для конфигуратора (label по умолчанию). */
export const STANDARD_DEAL_CARD_FIELDS: { field: string; label: string }[] = [
  { field: "amount", label: "Сумма" },
  { field: "currency", label: "Валюта" },
  { field: "owner_user_id", label: "Ответственный" },
  { field: "expected_close_date", label: "Ожидаемое закрытие" },
  { field: "expected_sign_date", label: "Ожидаемое подписание" },
  { field: "expected_payment_date", label: "Ожидаемая оплата" },
  { field: "product", label: "Продукт (текст)" },
  { field: "tags", label: "Теги" },
  { field: "contacts", label: "Контакты" },
];

/** 422 REQUIRED_FIELDS_MISSING — структурное тело при /move. */
export interface RequiredFieldsMissingError {
  code: "REQUIRED_FIELDS_MISSING";
  message: string;
  missing_fields: string[];
}

export interface DealStageHistory {
  id: number;
  from_stage_id: number | null;
  from_stage_name: string | null;
  to_stage_id: number;
  to_stage_name: string | null;
  user_id: number | null;
  created_at: string;
}

export interface StageAnalytics {
  stage_id: number;
  name: string;
  color: string | null;
  count: number;
  amount_sum: number;
  avg_days_in_stage: number;
}

export interface FunnelAnalytics {
  stages: StageAnalytics[];
  won: number;
  lost: number;
  in_progress: number;
  conversion: number;
}

export interface Contract {
  id: number;
  number: string | null;
  title: string | null;
  product_code: string;
  country_code: string;
  city: string | null;
  counterparty_id: number | null;
  author_user_id: number;
  status: ContractStatus;
  context: Record<string, unknown>;
  template_version: string | null;
  docx_path: string | null;
  pdf_path: string | null;
  drive_folder_url: string | null;
  drive_docx_url: string | null;
  drive_pdf_url: string | null;
  archived_at: string | null;
  signed_at: string | null;
  created_at: string;
  updated_at: string;
  extra_fields?: Record<string, unknown>;
}

export interface ContractList {
  items: Contract[];
  total: number;
}

export interface ContractAttachment {
  id: number;
  contract_id: number;
  kind: string;
  original_name: string | null;
  content_type: string | null;
  uploaded_by_user_id: number | null;
  created_at: string;
}

// ===== Продукты и прайс (каталог; не путать с ProductInfo — это метаданные шаблона) =====
export interface ProductPrice {
  currency: string;
  amount: number;
  plan_id: number | null;
}

export interface ProductPlan {
  id: number;
  name: string;
  unit: string;
  sort_order: number;
  prices: ProductPrice[];
}

export type PricingType = "fixed" | "tiered" | "per_minute" | "package" | "custom";

export interface Product {
  id: number;
  code: string;
  name: string;
  description: string | null;
  /** Legacy строка группы (backend синхронизирует из имени группы при заданном group_id) */
  group: string | null;
  group_id: number | null;
  pricing_type: PricingType;
  maps_to_product_code: string | null;
  is_active: boolean;
  sort_order: number;
  prices: ProductPrice[];
  plans: ProductPlan[];
}

// ── Гео и справочники (Wave 3c) ───────────────────────────────────────────

export interface Country {
  id: number;
  /** ISO 3166-1 alpha-2, нижний регистр (например "kz") */
  code: string;
  name: string;
  name_en: string | null;
  phone_prefix: string | null;
  sort_order: number;
  is_active: boolean;
}

export interface City {
  id: number;
  country_code: string;
  name: string;
  sort_order: number;
  is_active: boolean;
}

export interface Source {
  id: number;
  code: string;
  name: string;
  sort_order: number;
  is_active: boolean;
}

export interface ProductGroup {
  id: number;
  name: string;
  description: string | null;
  sort_order: number;
  is_active: boolean;
}

export interface ContractItem {
  id: number;
  product_id: number;
  plan_id: number | null;
  name_snapshot: string;
  currency: string;
  qty: number;
  unit_price: number;
  line_total: number;
  sort_order: number;
}

export interface ContractPricing {
  currency: string | null;
  subtotal: number;
  discount_pct: number;
  discount_amount: number;
  total: number;
  items: ContractItem[];
  manager_max_discount_pct: number | null;
}

export interface ProductInfo {
  code: string;
  name: string;
  has_data_archive_clause: boolean;
  has_premium_support_option: boolean;
  has_multiple_copyright_holders: boolean;
  implementation_weeks: number;
  training_hours_default: number;
  brief_fields: { id: string; label: string }[];
  tech_fields: { id: string; label: string; default?: string }[];
  modules_table: { name: string; modules: { name: string; status: string }[] }[];
}

export interface CountryInfo {
  code: string;
  name_short: string;
  name_full: string;
  default_city: string;
  currency_name: string;
  currency_code: string;
  licensor: { name: string; legal_form: string };
}

export interface ApprovalStage {
  order: number;
  name: string;
  user_ids: number[];
  min_required: number;
}

export interface ApprovalRoute {
  id: number;
  name: string;
  product_codes: string[];
  country_codes: string[];
  approver_user_ids: number[];
  min_required: number;
  stages: ApprovalStage[];
  is_active: boolean;
}

export type ApprovalDecision = "pending" | "approved" | "rejected" | "needs_rework";

export interface ApprovalRow {
  id: number;
  user_id: number;
  stage_order: number;
  attempt: number;
  decision: ApprovalDecision;
  comment: string | null;
  decided_at: string | null;
  created_at: string;
}

export interface ApprovalStageSummary {
  order: number;
  name: string;
  user_ids: number[];
  min_required: number;
  approved: number;
  rejected: number;
  pending: number;
  is_active: boolean;
}

export interface ContractApprovalSummary {
  current_stage: number;
  total_stages: number;
  stages: ApprovalStageSummary[];
  approvals: ApprovalRow[];
  can_resubmit: boolean;
}

export interface ContractRevision {
  id: number;
  version_number: number;
  attempt: number;
  template_version: string | null;
  note: string | null;
  has_docx: boolean;
  has_pdf: boolean;
  created_by_user_id: number | null;
  created_at: string;
}

export interface ContractRemark {
  id: number;
  attempt: number;
  stage_order: number;
  author_user_id: number;
  text: string;
  is_resolved: boolean;
  resolved_at: string | null;
  created_at: string;
}

export interface LicensorBankAccount {
  id: number;
  licensor_id: number;
  currency: string;
  bank: string;
  bank_code_label: string;
  bank_code: string;
  account: string;
  swift?: string | null;
  is_primary: boolean;
  note?: string | null;
}

export interface LicensorEntity {
  id: number;
  country_code: string;
  legal_form: string;
  full_legal_form: string;
  gender_ending_oe: string;
  name: string;
  director_position: string;
  director_short: string;
  director_genitive: string;
  acts_basis: string;
  tax_id_label: string;
  tax_id: string;
  address: string;
  bank: string;
  bank_code_label: string;
  bank_code: string;
  account: string;
  phone?: string | null;
  email?: string | null;
  website?: string | null;
  training_login?: string | null;
}

export interface TemplateInfo {
  id: number;
  code: string;
  kind: "md" | "yaml";
  title: string;
  version: number;
  updated_at: string;
  // Эпик 3: категория + 4 списка привязок (см. backend /api/templates/).
  // Все поля строго возвращаются backend'ом (категория null → «Без категории»).
  category: string | null;
  product_codes: string[];
  country_codes: string[];
  client_category_codes: string[];
  department_ids: number[];
}

export interface TemplateDetail extends TemplateInfo {
  content: string;
}

/** Эпик 3: справочник категорий с /api/templates/categories. */
export interface TemplateCategoryOption {
  code: string;
  label: string;
}

export type TemplateVariableType = "text" | "textarea" | "number" | "date" | "select" | "checkbox";

export const VAR_TYPE_LABELS: Record<TemplateVariableType, string> = {
  text: "Текст (одна строка)",
  textarea: "Многострочный текст",
  number: "Число",
  date: "Дата",
  select: "Выпадающий список",
  checkbox: "Да / Нет (галочка)",
};

export const VAR_TYPE_OPTIONS: { value: TemplateVariableType; label: string }[] = [
  { value: "text", label: VAR_TYPE_LABELS.text },
  { value: "textarea", label: VAR_TYPE_LABELS.textarea },
  { value: "number", label: VAR_TYPE_LABELS.number },
  { value: "date", label: VAR_TYPE_LABELS.date },
  { value: "select", label: VAR_TYPE_LABELS.select },
  { value: "checkbox", label: VAR_TYPE_LABELS.checkbox },
];

export interface TemplateVariable {
  id: number;
  key: string;
  label: string;
  help_text: string | null;
  var_type: TemplateVariableType;
  options: string[];
  default_value: string | null;
  required: boolean;
  group: string | null;
  sort_order: number;
  product_codes: string[];
  country_codes: string[];
  is_active: boolean;
}

export const ALL_PRODUCTS = [
  { value: "macrocrm", label: "MacroCRM" },
  { value: "macrosales", label: "MacroSales" },
  { value: "macroerp", label: "MACRO (ERP suite)" },
];

export const ALL_COUNTRIES = [
  { value: "kz", label: "Казахстан" },
  { value: "uz", label: "Узбекистан" },
];

export const LICENSE_TYPES = [
  { value: "Стандартная", label: "Стандартная" },
  { value: "Премиум", label: "Премиум" },
  { value: "Корпоративная", label: "Корпоративная" },
  { value: "Стартовая", label: "Стартовая" },
];

// ===== Реестр клиентов / Customer Success (Фаза 4) =====
export interface Platform { id: number; code: string; name: string; sort_order: number; is_active: boolean; }
export interface Region { id: number; code: string; name: string; sort_order: number; }
export interface ModuleDef { id: number; code: string; name: string; platform_id: number | null; sort_order: number; }
export interface ChecklistItemTemplate {
  id: number; template_id: number; code: string; label: string;
  group: string | null; kind: string; optional: boolean; sort_order: number;
}

export interface Subscription {
  id: number;
  /** CS-hotfix (0080): counterparty_id стал nullable — company-first подписки. */
  counterparty_id: number | null;
  /** CONTACTS 2.0 Ф3-B: company_id — источник истины. */
  company_id: number | null;
  platform_id: number;
  region_id: number | null;
  external_client_id: string | null;
  lifecycle_stage_id: number | null;
  stage_changed_at: string | null;
  imp_pm_user_id: number | null;
  sup_pm_user_id: number | null;
  am_user_id: number | null;
  team_names: Record<string, unknown>;
  seats: number | null;
  fee_actual: number | null;
  fee_contract: number | null;
  fee_currency: string | null;
  tariff: string | null;
  discount_until: string | null;
  auto_prolongation: boolean;
  on_premise: boolean;
  last_fee_increase_at: string | null;
  impl_start_date: string | null;
  act_signed_date: string | null;
  impl_pct: number | null;
  qa_result: string | null;
  qa_date: string | null;
  health_tier: string | null;
  health_score: number | null;
  activity_avg: number | null;
  activity_trend_pct: number | null;
  dormant_periods: number | null;
  health_reasons: string[];
  manual_tier_override: string | null;
  health_computed_at: string | null;
  is_active: boolean;
  notes: string | null;
  created_at: string;
  updated_at: string;
  extra_fields?: Record<string, unknown>;
}

export interface RegistryRow {
  id: number;
  /** CS-hotfix (0080): counterparty_id стал nullable — company-first подписки. */
  counterparty_id: number | null;
  /** CONTACTS 2.0 Ф3-B: company_id — источник истины для ссылок /companies/[id]. */
  company_id: number | null;
  counterparty_name: string;
  country_code: string | null;
  category_code: string | null;
  platform_id: number;
  platform_name: string;
  region_id: number | null;
  region_name: string | null;
  lifecycle_stage_id: number | null;
  status_code: string | null;
  status_name: string | null;
  status_color: string | null;
  impl_pct: number | null;
  health_tier: string | null;
  activity_avg: number | null;
  activity_trend_pct: number | null;
  dormant_periods: number | null;
  attention: string[];
  sparkline: number[];
  seats: number | null;
  fee_actual: number | null;
  fee_currency: string | null;
  tariff: string | null;
  sup_pm_name: string | null;
  am_name: string | null;
  notes: string | null;
  updated_at: string;
}

export interface SubscriptionModuleRow { module_id: number; code: string; name: string; enabled: boolean; status: string | null; }

export interface ChecklistItemView {
  template_item_id: number;
  code: string;
  label: string;
  group: string | null;
  kind: string;
  optional: boolean;
  sort_order: number;
  status: string;
  num_done: number | null;
  num_total: number | null;
  pct: number | null;
  value_date: string | null;
  note: string | null;
  completion: number;
}
export interface ChecklistView { subscription_id: number; overall_pct: number; items: ChecklistItemView[]; }

export interface ActivitySnapshot { id: number; period_start: string; period_end: string | null; metric: string; value: number; source: string; }

export interface RegistryKpi {
  total: number;
  active: number;
  support: number;
  in_implementation: number;
  operating: number;
  closed: number;
  conversion_support: number;
  conversion_closed: number;
  by_code: Record<string, number>;
}

// Тиры здоровья (A1–A6 / C0): подпись + цвет (hex — для inline kanban/sparkline) + cls (soft badge)
export const TIER_META: Record<string, { label: string; color: string; cls: string }> = {
  A1: { label: "Активный (макс.)",  color: "#1F9D55", cls: "bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-500" },
  A2: { label: "Активный",          color: "#3FAE6A", cls: "bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-500" },
  A3: { label: "Сопровождение",     color: "#E6B800", cls: "bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-500" },
  A4: { label: "Низкая активность", color: "#E8853A", cls: "bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-500" },
  A5: { label: "Риск оттока",       color: "#D9682A", cls: "bg-danger-50 text-danger-700 dark:bg-danger-500/10 dark:text-danger-500" },
  A6: { label: "Спящий",            color: "#9B59B6", cls: "bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400" },
  C0: { label: "Отвал",             color: "#6B7280", cls: "bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400" },
};

export const ATTENTION_LABELS: Record<string, string> = {
  dormant: "Нет активности",
  activity_drop: "Падение активности",
  discount_expiring: "Скидка истекает",
  upsell: "Давно без повышения АП",
  low_health: "Низкое здоровье",
};

export interface ContractsAnalytics {
  total: number;
  by_product: Record<string, number>;
  by_country: Record<string, number>;
  by_manager: Record<string, number>;
  by_status: Record<string, number>;
  /** Wave 2b: счётчики по 4 группам статусов ({group_code: count}). */
  by_status_group: Record<string, number>;
  pending_count: number;
  pending_avg_age_days: number;
  avg_cycle_days: number;
  avg_time_to_approve_days: number;
  approved_sample: number;
  /**
   * Design v2: KPI sparkline + trend (backward-compatible, всегда присутствуют
   * в ответе, но фронт обязан обрабатывать undefined для старых клиентов).
   *
   * total_sparkline — последние 8 недель новых договоров, слева старые.
   * *_trend_pct — процент изменения к предыдущему периоду; null = нет данных.
   * Отрицательное значение = снижение; отображать как «−3%».
   */
  total_sparkline?: number[];
  total_trend_pct?: number | null;
  avg_cycle_trend_pct?: number | null;
  avg_time_to_approve_trend_pct?: number | null;
}

export const CHECKLIST_STATUS_OPTIONS: { value: string; label: string }[] = [
  { value: "not_started", label: "—" },
  { value: "waiting", label: "В ожидании" },
  { value: "in_progress", label: "В работе" },
  { value: "done", label: "Готово" },
  { value: "not_required", label: "Не требуется" },
  { value: "not_used", label: "Не используется" },
  { value: "not_done", label: "Не готово" },
];

// ===== PipelineAutomation (Эпик 4) =====
export type AutomationTriggerKind =
  | "on_enter_stage"
  | "idle_in_stage_days"
  | "date_field_approaching"
  | "on_create";

export type AutomationActionKind =
  | "tg_notify"
  | "create_task"
  | "set_field"
  | "generate_document"
  | "change_owner"
  | "webhook"
  | "email"
  | "start_sequence"
  /** Эпик 23: новые action_kind (backend TBD) */
  | "set_tags"
  | "complete_tasks"
  | "change_stage"
  | "create_deal";

export type AutomationTargetType = "deal" | "lead" | "subscription";

// ===== Последовательности (Sequences) (Эпик 4.1) =====
export type SequenceStepKind = "wait" | "tg_notify" | "email" | "create_task" | "if_else";

// Эпик 19: Branch условие для if_else шага
export type BranchConditionField = "deal_amount" | "lead_score" | "stage_name" | "assigned_user_id";
export type BranchConditionOperator = "eq" | "neq" | "gt" | "lt" | "contains";

export interface BranchCondition {
  field: BranchConditionField;
  operator: BranchConditionOperator;
  value: string;
}

export interface BranchConfig {
  condition: BranchCondition;
  true_steps: SequenceStep[];
  false_steps: SequenceStep[];
}

export interface SequenceStep {
  id?: number;
  order: number;
  kind: SequenceStepKind;
  delay_days: number;
  config: Record<string, unknown>;
}

// Эпик 19: EscalationLevel для SLA-правил
export interface EscalationLevel {
  after_days: number;
  notify: "owner" | "manager";
}

// Эпик 19: Execute response
export interface AutomationExecuteResponse {
  executed: number;
  skipped: number;
  errors: string[];
}

export interface Sequence {
  id: number;
  name: string;
  description: string | null;
  is_active: boolean;
  steps_json: SequenceStep[];
  steps_count?: number;
  created_at: string;
  updated_at: string;
}

export type AutomationRunStatus = "pending" | "success" | "failed" | "skipped";

export interface Automation {
  id: number;
  name: string;
  description: string | null;
  pipeline_id: number;
  stage_id: number | null;
  trigger_kind: AutomationTriggerKind;
  trigger_config: Record<string, unknown>;
  action_kind: AutomationActionKind;
  action_config: Record<string, unknown>;
  is_active: boolean;
  created_by_user_id: number | null;
  last_run_at: string | null;
  created_at: string;
  updated_at: string;
  pipeline_name: string | null;
  stage_name: string | null;
  runs_count: number;
}

export interface AutomationRun {
  id: number;
  automation_id: number;
  target_type: AutomationTargetType;
  target_id: number;
  status: AutomationRunStatus;
  started_at: string;
  finished_at: string | null;
  error_text: string | null;
  result_json: Record<string, unknown> | null;
  automation_name: string | null;
}

export interface AutomationTestPreview {
  would_execute?: boolean;
  reason?: string;
  automation_id?: number;
  action_kind?: string;
  target_type?: string;
  target_id?: number;
  target_owner_user_id?: number | null;
  recipient?: { kind: string; value: number | string | null };
  message?: string;
  task?: {
    title?: string;
    body?: string | null;
    responsible_id?: number | null;
    due_in_days?: number | null;
  };
  set_field?: {
    field?: string;
    old_value?: string | null;
    new_value?: unknown;
  };
  generate_document?: {
    template_code?: string;
    stub?: boolean;
    tbd?: string;
  };
  [k: string]: unknown;
}

export interface AutomationTestResult {
  automation_id: number;
  previews: AutomationTestPreview[];
}

// ===== Каналы / Inbox / Формы (Эпик 5 MVP) =====
export type ChannelKind = "tg" | "wa" | "email" | "web_form" | "api";

export interface Channel {
  id: number;
  name: string;
  kind: ChannelKind;
  // Маска токена (****abcd) — list/get отдают только её (C4 CRIT-3).
  secret_token_preview: string;
  // Полный токен присутствует ТОЛЬКО в ответах create / regenerate / reveal-token.
  secret_token?: string;
  config: Record<string, unknown>;
  default_lead_source: string;
  default_owner_id: number | null;
  default_pipeline_id: number | null;
  default_stage_id: number | null;
  is_active: boolean;
  created_at: string;
  updated_at: string;
}

export interface InboundMessage {
  id: number;
  channel_id: number;
  external_id: string | null;
  from_identifier: string | null;
  from_name: string | null;
  subject: string | null;
  body: string | null;
  raw_payload: Record<string, unknown> | null;
  target_lead_id: number | null;
  target_lead_created: boolean;
  // DEALS 2.0 (Ф1c): входящий поток создаёт Deal, а не Lead. target_lead_id
  // всегда null после DEALS 2.0 — UI работает с target_deal_id.
  target_deal_id: number | null;
  target_deal_created: boolean;
  // 'routed' | 'dedup' | 'failed' | null. 'failed' = не разобрано (нет воронки).
  routing_status: string | null;
  received_at: string;
}

export type FormFieldType = "text" | "email" | "phone" | "textarea" | "select";

export interface FormField {
  name: string;
  label: string;
  type: FormFieldType;
  required: boolean;
  options?: string[];
}

export interface PublicForm {
  name: string;
  fields: FormField[];
  thank_you_text: string | null;
}

export interface CrmForm {
  id: number;
  name: string;
  public_slug: string;
  fields: FormField[];
  channel_id: number | null;
  thank_you_text: string | null;
  is_active: boolean;
  created_by_user_id: number | null;
  created_at: string;
  updated_at: string;
}

export const CHANNEL_KIND_LABELS: Record<ChannelKind, string> = {
  tg: "Telegram",
  wa: "WhatsApp",
  email: "Email",
  web_form: "Веб-форма",
  api: "API",
};

export const CHANNEL_KIND_OPTIONS: { value: ChannelKind; label: string }[] = [
  { value: "tg", label: CHANNEL_KIND_LABELS.tg },
  { value: "wa", label: CHANNEL_KIND_LABELS.wa },
  { value: "email", label: CHANNEL_KIND_LABELS.email },
  { value: "web_form", label: CHANNEL_KIND_LABELS.web_form },
  { value: "api", label: CHANNEL_KIND_LABELS.api },
];

export const FORM_FIELD_TYPE_LABELS: Record<FormFieldType, string> = {
  text: "Текст",
  email: "Email",
  phone: "Телефон",
  textarea: "Длинный текст",
  select: "Выпадающий список",
};

export const FORM_FIELD_TYPE_OPTIONS: { value: FormFieldType; label: string }[] = [
  { value: "text", label: FORM_FIELD_TYPE_LABELS.text },
  { value: "email", label: FORM_FIELD_TYPE_LABELS.email },
  { value: "phone", label: FORM_FIELD_TYPE_LABELS.phone },
  { value: "textarea", label: FORM_FIELD_TYPE_LABELS.textarea },
  { value: "select", label: FORM_FIELD_TYPE_LABELS.select },
];

// ===== Bulk-задачи (Эпик 6 MVP) =====
export type BulkTaskKind = "document_generation";
export type BulkTaskStatus = "pending" | "running" | "success" | "failed" | "cancelled";
export type BulkTargetType = "counterparty" | "subscription";

export interface BulkTask {
  id: number;
  kind: BulkTaskKind;
  status: BulkTaskStatus;
  template_code: string | null;
  target_type: BulkTargetType;
  target_ids: number[];
  total_count: number;
  success_count: number;
  failed_count: number;
  result_zip_path: string | null;
  error_text: string | null;
  created_by_user_id: number | null;
  created_at: string;
  started_at: string | null;
  finished_at: string | null;
}

export const BULK_STATUS_LABELS: Record<BulkTaskStatus, string> = {
  pending: "Ожидает",
  running: "Выполняется",
  success: "Готово",
  failed: "Ошибка",
  cancelled: "Отменено",
};

export const BULK_STATUS_BADGE: Record<BulkTaskStatus, string> = {
  pending:   "bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300",
  running:   "bg-info-50 text-info-700 dark:bg-info-500/10 dark:text-info-500",
  success:   "bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-500",
  failed:    "bg-danger-50 text-danger-700 dark:bg-danger-500/10 dark:text-danger-500",
  cancelled: "bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400",
};

export const BULK_KIND_LABELS: Record<BulkTaskKind, string> = {
  document_generation: "Генерация документов",
};

export const BULK_TARGET_TYPE_LABELS: Record<BulkTargetType, string> = {
  counterparty: "Контрагенты",
  subscription: "Подписки",
};

// ===== Funnel / Forecast (Эпик 6 MVP) =====
export interface FunnelStageMetric {
  stage_id: number;
  stage_name: string;
  stage_code?: string | null;
  sort_order?: number;
  count: number;
  avg_days_in_stage: number | null;
  transition_to_next_pct?: number | null;
  conversion_pct?: number | null;
  is_won?: boolean;
  is_lost?: boolean;
  probability?: number;
}

export interface FunnelResponse {
  pipeline: { id: number; name: string; kind: string };
  stages: FunnelStageMetric[];
  total_active: number;
  total_won: number;
  total_lost: number;
}

export interface ForecastStageBreakdown {
  stage_id: number;
  stage_name: string;
  count: number;
  probability: number;
  estimated: number;
}

export interface ForecastResponse {
  pipeline: { id: number; name: string; kind?: string };
  active_deals_by_stage: Record<string, number>;
  avg_value_per_won: number;
  won_count: number;
  currency: string | null;
  probability_by_stage: Record<string, number>;
  estimated_revenue: number;
  by_stage_breakdown: ForecastStageBreakdown[];
}

// ── Эпик 8: extra_fields на сущностях ──────────────────────────────

// Добавляем extra_fields в существующие интерфейсы через module augmentation
// (поля добавлены ниже в их копиях — здесь только новые типы Эпика 8)

// ── Эпик 8: Custom Fields ──────────────────────────────────────────

export type EntityScope =
  | "lead" | "contact" | "company" | "counterparty"
  | "deal" | "contract" | "subscription";

export type CustomFieldKind =
  | "text" | "textarea" | "number" | "date"
  | "select" | "multiselect" | "url" | "checkbox";

export const ENTITY_SCOPE_LABELS: Record<EntityScope, string> = {
  lead: "Лид",
  contact: "Контакт",
  company: "Компания",
  counterparty: "Контрагент",
  deal: "Сделка",
  contract: "Договор",
  subscription: "Подписка",
};

export const CUSTOM_FIELD_KIND_LABELS: Record<CustomFieldKind, string> = {
  text: "Текст",
  textarea: "Длинный текст",
  number: "Число",
  date: "Дата",
  select: "Список",
  multiselect: "Мультисписок",
  url: "Ссылка",
  checkbox: "Галочка",
};

export interface CustomFieldDef {
  id: number;
  entity_scope: EntityScope;
  code: string;
  label_ru: string;
  kind: CustomFieldKind;
  is_required: boolean;
  default_value: string | null;
  options_json: string[];
  sort_order: number;
  is_active: boolean;
  created_at: string;
  updated_at: string;
}

// ── Эпик 8: Duplicates ──────────────────────────────────────────────

export type DuplicateEntityType = "counterparty" | "contact" | "company" | "lead";

export const DUPLICATE_ENTITY_LABELS: Record<DuplicateEntityType, string> = {
  counterparty: "Контрагенты",
  contact: "Контакты",
  company: "Компании",
  lead: "Лиды",
};

export interface DuplicateRecord {
  id: number;
  display_name: string;
  fields: Record<string, string | null>;
}

export interface DuplicateGroup {
  id: string;
  entity: DuplicateEntityType;
  records: DuplicateRecord[];
  similarity_score: number;
}

export interface DuplicateScanResponse {
  entity: DuplicateEntityType;
  groups: DuplicateGroup[];
  scanned_at: string;
}

// ── Эпик 8: Audit Log ──────────────────────────────────────────────

export type AuditAction =
  | "create"
  | "update"
  | "delete"
  | "merge"
  | "extra_fields_change"
  | "bulk_action";

export type AuditEntityType =
  | "lead" | "contact" | "company" | "counterparty"
  | "deal" | "contract" | "subscription";

export interface AuditDiff {
  fields?: Record<string, { old: unknown; new: unknown }>;
  bulk_action?: string;
}

export interface AuditLogEntry {
  id: number;
  entity_type: AuditEntityType;
  entity_id: number;
  user_id: number | null;
  user_name: string | null;
  action: AuditAction;
  diff_json: AuditDiff | null;
  occurred_at: string;
}

export interface AuditLogResponse {
  items: AuditLogEntry[];
  total: number;
}

// ── Эпик 8: Saved Filters ──────────────────────────────────────────

export type PageKey =
  | "leads" | "contacts" | "companies" | "counterparties"
  | "deals" | "registry";

export const PAGE_KEY_LABELS: Record<PageKey, string> = {
  leads: "Лиды",
  contacts: "Контакты",
  companies: "Компании",
  counterparties: "Контрагенты",
  deals: "Сделки",
  registry: "Реестр",
};

export interface SavedFilter {
  id: number;
  user_id: number | null;
  page_key: PageKey;
  name: string;
  filter_json: Record<string, unknown>;
  is_pinned: boolean;
  created_at: string;
}

// ── Эпик 8: Search ─────────────────────────────────────────────────

export type SearchEntityType =
  | "lead" | "contact" | "company" | "counterparty" | "deal" | "contract";

export const SEARCH_ENTITY_LABELS: Record<SearchEntityType, string> = {
  lead: "Лиды",
  contact: "Контакты",
  company: "Компании",
  counterparty: "Контрагенты",
  deal: "Сделки",
  contract: "Договоры",
};

export const SEARCH_ENTITY_ICONS: Record<SearchEntityType, string> = {
  lead: "bi-funnel-fill",
  contact: "bi-person",
  company: "bi-buildings",
  counterparty: "bi-people",
  deal: "bi-kanban",
  contract: "bi-file-earmark-text",
};

export const SEARCH_ENTITY_PATHS: Record<SearchEntityType, string> = {
  lead: "/leads",
  contact: "/contacts",
  company: "/companies",
  counterparty: "/counterparties",
  deal: "/deals",
  contract: "/contracts",
};

export interface SearchResultItem {
  entity_type: SearchEntityType;
  id: number;
  display_name: string;
  secondary: string | null;
  score?: number;
}

export interface SearchResultGroup {
  entity_type: SearchEntityType;
  count: number;
  items: SearchResultItem[];
}

export interface SearchResponse {
  query: string;
  items: SearchResultItem[];
  groups: SearchResultGroup[];
}

export interface SearchHistoryItem {
  entity_type: SearchEntityType;
  id: number;
  display_name: string;
  visited_at: string;
}

// ── Эпик 10: Dashboard виджеты ───────────────────────────────────────

export interface MyTask {
  id: number;
  kind: ActivityKind;
  title: string;
  due_at: string | null;
  target_type: ActivityTargetType;
  target_id: number;
  target_name?: string | null;
  is_overdue: boolean;
}

export interface HotDeal {
  id: number;
  title: string;
  amount: number | null;
  currency: string | null;
  stage_name: string;
  stage_color: string | null;
  idle_days: number;
  days_to_close: number | null;
  heat_reason: "idle" | "deadline";
  counterparty_name?: string | null;
  /** CONTACTS 2.0 Ф4: company_id — источник истины для ссылки /companies/[id] */
  company_id?: number | null;
}

// ── Эпик 10: LostReason ──────────────────────────────────────────────

export const LOST_REASON_PRESETS = [
  "Цена",
  "Конкурент",
  "Не подошло",
  "Передумали",
  "Не наш сегмент",
  "Другое",
] as const;

export type LostReasonPreset = typeof LOST_REASON_PRESETS[number];

/** DEALS 2.0: справочник причин проигрыша (`GET /api/deals/lost-reasons`) */
export interface LostReasonItem {
  id: number;
  name: string;
  sort_order: number;
  is_active: boolean;
}

// ── Эпик 11: API Tokens + Webhooks ────────────────────────────────────

export type APITokenScope =
  | "*"
  | "read:leads"   | "write:leads"
  | "read:deals"   | "write:deals"
  | "read:contacts"  | "write:contacts"
  | "read:companies" | "write:companies"
  | "read:counterparties" | "write:counterparties"
  | "read:contracts"    | "write:contracts"
  | "read:subscriptions" | "write:subscriptions"
  | "inbox:write";

export interface APIToken {
  id: number;
  user_id: number;
  name: string;
  scopes: APITokenScope[];
  expires_at: string | null;
  last_used_at: string | null;
  last_used_ip: string | null;
  is_active: boolean;
  created_at: string;
  revoked_at: string | null;
  rate_limit_per_hour: number;
}

// Эпик 16: 2FA статус
export interface TwoFactorStatus {
  enabled: boolean;
  enabled_at: string | null;
  backup_codes_remaining: number;
}

// Эпик 16: SSO ссылка провайдера
export interface SSOLink {
  provider: "google" | "yandex";
  provider_email: string;
  linked_at: string;
}

// Эпик 16: Login response union
export type LoginResponse =
  | { requires_2fa: false; id: number; email: string; full_name: string; role: string }
  | { requires_2fa: true; user_id: number };

export interface APITokenCreateResponse extends APIToken {
  plaintext_token: string;
}

export type WebhookEvent =
  | "lead.created"   | "lead.converted"
  | "deal.created"   | "deal.stage_changed" | "deal.won" | "deal.lost"
  | "contract.signed" | "contract.created"
  | "subscription.created" | "subscription.health_changed"
  | "counterparty.created"
  | "*";

export type WebhookDeliveryStatus = "pending" | "success" | "failed" | "retrying";

export interface Webhook {
  id: number;
  name: string;
  url: string;
  secret_preview: string;
  event_subscriptions: WebhookEvent[];
  is_active: boolean;
  headers: Record<string, string> | null;
  created_by_user_id: number | null;
  created_at: string;
  updated_at: string;
  // Per-webhook delivery settings (Tech Sprint)
  max_attempts?: number | null;
  backoff_seconds?: number | null;
  timeout_seconds?: number | null;
}

export interface WebhookCreateResponse extends Webhook {
  plaintext_secret: string;
}

// ── Эпик 13: Онбординг ────────────────────────────────────────────────

export type LessonKind = "theory" | "video" | "quiz";
export type CourseProgressStatus = "not_started" | "in_progress" | "completed" | "overdue";
export type QuizQuestionKind = "single" | "multi";
export type ContentBlockKind =
  | "markdown"
  | "image"
  | "drive_video"
  | "loom_video"
  | "youtube_video"
  | "callout";
export type CalloutVariant = "info" | "warning" | "success" | "danger";
export type CrmExperienceLevel = "none" | "basic" | "advanced";

// ContentBlock discriminated union по kind
export interface MarkdownBlock {
  kind: "markdown";
  content: string;
}

export interface ImageBlock {
  kind: "image";
  url: string;
  caption?: string;
}

export interface DriveVideoBlock {
  kind: "drive_video";
  drive_url: string;
  title?: string;
  duration_min?: number;
}

export interface LoomVideoBlock {
  kind: "loom_video";
  loom_id: string;
}

export interface YouTubeVideoBlock {
  kind: "youtube_video";
  youtube_id: string;
}

export interface CalloutBlock {
  kind: "callout";
  variant: CalloutVariant;
  text: string;
}

export type ContentBlock =
  | MarkdownBlock
  | ImageBlock
  | DriveVideoBlock
  | LoomVideoBlock
  | YouTubeVideoBlock
  | CalloutBlock;

export interface LessonQuizQuestion {
  id: number;
  lesson_id: number;
  kind: QuizQuestionKind;
  text: string;
  options: string[];
  // Только admin endpoints или quiz passed=true
  correct_answers?: number[];
  explanation?: string | null;
  points: number;
  order_index: number;
}

export interface CourseLesson {
  id: number;
  module_id: number;
  title: string;
  kind: LessonKind;
  duration_min: number | null;
  order_index: number;
  // Для theory: массив ContentBlock
  content_blocks?: ContentBlock[];
  // Для video: URL
  video_url?: string | null;
  video_source?: "drive" | "loom" | "youtube" | "vimeo" | null;
  // Для quiz: вопросы
  questions?: LessonQuizQuestion[];
  // Tech Sprint: случайный порядок вопросов
  randomize_questions?: boolean;
}

export interface CourseModule {
  id: number;
  course_id: number;
  title: string;
  order_index: number;
  lessons: CourseLesson[];
}

export interface Course {
  id: number;
  title: string;
  description: string | null;
  target_roles: string[];
  is_mandatory: boolean;
  deadline_days: number | null;
  passing_score_pct: number;
  cover_image_url: string | null;
  is_published: boolean;
  created_at: string;
  updated_at: string;
}

export interface CourseWithModules extends Course {
  modules: CourseModule[];
}

export interface UserCourseAssignment {
  id: number;
  user_id: number;
  course_id: number;
  course_title: string;
  assigned_by: number | null;
  assigned_at: string;
  due_at: string | null;
  is_mandatory: boolean;
}

export interface CourseProgress {
  assignment_id: number;
  course_id: number;
  course_title: string;
  status: CourseProgressStatus;
  percent: number;
  due_at: string | null;
  completed_at: string | null;
  // Поля для student summary
  lessons_total?: number;
  lessons_done?: number;
  cover_image_url?: string | null;
  description?: string | null;
  is_mandatory?: boolean;
}

export interface LessonProgress {
  lesson_id: number;
  completed_at: string | null;
  attempts_count: number;
  best_score_pct: number | null;
}

export interface QuizAttemptAnswer {
  question_id: number;
  selected_indices: number[];
  // Доступно только если attempt passed=true
  is_correct?: boolean;
  correct_answers?: number[];
  explanation?: string | null;
}

export interface QuizAttempt {
  id: number;
  lesson_id: number;
  user_id: number;
  started_at: string;
  finished_at: string | null;
  score_pct: number | null;
  passed: boolean | null;
  answers: QuizAttemptAnswer[];
}

export interface QuizSubmitResponse {
  score_pct: number;
  passed: boolean;
  n_correct: number;
  n_total: number;
  // Доступно только если passed=true
  questions?: Array<{
    id: number;
    text: string;
    is_correct: boolean;
    correct_answers?: number[];
    explanation?: string | null;
  }>;
}

// Admin: полный курс с модулями и уроками (включая correct_answers)
export interface CourseFullOut extends CourseWithModules {
  assigned_count?: number;
  completed_count?: number;
}

// Прогресс команды (для матрицы)
export interface TeamProgressUser {
  user_id: number;
  user_name: string;
  user_role: string;
  courses: Array<{
    course_id: number;
    course_title: string;
    status: CourseProgressStatus | "unassigned";
    percent: number;
    assignment_id: number | null;
    due_at: string | null;
  }>;
}

export interface TeamProgressResponse {
  courses: Array<{ id: number; title: string }>;
  users: TeamProgressUser[];
}

// Сводка для badge в сайдбаре
export interface OnboardingBadgeSummary {
  overdue_count: number;
  in_progress_count: number;
  not_started_count: number;
}

// Wizard fields в User (расширение)
export interface OnboardingWizardPatch {
  crm_experience_level?: CrmExperienceLevel;
  dismissed?: boolean;
}

export interface WebhookDelivery {
  id: number;
  webhook_id: number;
  event: string;
  payload: Record<string, unknown>;
  status: WebhookDeliveryStatus;
  attempt: number;
  next_retry_at: string | null;
  last_http_code: number | null;
  last_error: string | null;
  last_response_body: string | null;
  created_at: string;
  finished_at: string | null;
}

// ── Эпик 17: Onboarding Analytics ─────────────────────────────────────

export interface OnboardingOverviewDTO {
  total_courses: number;
  total_assignments: number;
  new_assignments_30d: number;
  total_completed: number;
  completion_rate_pct: number;
  avg_time_to_complete_hours: number | null;
  active_learners_30d: number;
  overdue_mandatory: number;
  courses_sparkline_30d: number[];
  activity_by_day_90d: Array<{ date: string; count: number }>;
  completions_by_course: Array<{ course_id: number; title: string; completed: number }>;
  status_distribution: {
    assigned: number;
    in_progress: number;
    completed: number;
    overdue: number;
  };
}

export interface OnboardingHardQuestion {
  question_id: number;
  question_text: string;
  course_id: number;
  course_title: string;
  lesson_id: number;
  lesson_title: string;
  total_attempts: number;
  success_rate_pct: number;
}

export type OnboardingFunnelStepKey =
  | "assigned"
  | "started"
  | "halfway"
  | "almost_done"
  | "completed";

export interface OnboardingFunnelStepUser {
  user_id: number;
  user_name: string;
}

export interface OnboardingFunnelStep {
  step_key: OnboardingFunnelStepKey;
  step_label: string;
  count: number;
  pct_of_total: number;
  users?: OnboardingFunnelStepUser[];
}

export interface OnboardingFunnelResponse {
  course_id: number;
  course_title: string;
  steps: OnboardingFunnelStep[];
}

export interface OnboardingTeamProgressRow {
  user_id: number;
  user_name: string;
  user_role: string;
  department_id: number | null;
  department_name: string | null;
  assigned_count: number;
  completed_count: number;
  in_progress_count: number;
  overdue_count: number;
  progress_pct: number;
}

export type OnboardingTeamProgressResponse = OnboardingTeamProgressRow[];

export interface OnboardingAnalyticsExportParams {
  department_id?: number | null;
  manager_id?: number | null;
  course_id?: number | null;
  period?: string | null;
}

// ── Эпик 22: Когортная аналитика ──────────────────────────────────────

export interface CohortRow {
  month_offset: number;
  active_count: number;
  churned_count: number;
  retention_pct: number;
}

export interface CohortData {
  cohort_month: string;
  initial_count: number;
  retention: CohortRow[];
  avg_ltv: number;
}

export interface CohortAnalyticsResponse {
  cohorts: CohortData[];
  matrix: Record<string, Record<number, number>>;
  retention_pct: Record<string, Record<number, number>>;
  avg_ltv_per_cohort: Record<string, number>;
  total_avg_ltv: number;
  projected_ltv: number;
  monthly_churn_rate: number;
  current_mrr: number;
}

export interface CohortMember {
  subscription_id: number;
  counterparty_name: string;
  cohort_start_date: string | null;
  current_stage_code: string | null;
  current_stage_name: string | null;
  churn_date: string | null;
  is_churned: boolean;
  fee_actual: number | null;
  is_active: boolean;
}

export interface CohortMembersResponse {
  cohort_month: string;
  count: number;
  members: CohortMember[];
}

// ── Эпик 14.2: Company Management ─────────────────────────────────────

export type EmploymentStatus = "active" | "on_vacation" | "dismissed";

export const EMPLOYMENT_STATUS_LABELS: Record<EmploymentStatus, string> = {
  active: "Активен",
  on_vacation: "В отпуске",
  dismissed: "Уволен",
};

export type VacationType = "vacation" | "sick_leave" | "day_off" | "business_trip";

export const VACATION_TYPE_LABELS: Record<VacationType, string> = {
  vacation: "Отпуск",
  sick_leave: "Больничный",
  day_off: "Отгул",
  business_trip: "Командировка",
};

export type VacationStatus = "pending_approval" | "approved" | "rejected";

export const VACATION_STATUS_LABELS: Record<VacationStatus, string> = {
  pending_approval: "Ожидает одобрения",
  approved: "Одобрен",
  rejected: "Отклонён",
};

export type TransferCategory =
  | "contacts"
  | "deals"
  | "tasks_assignee"
  | "tasks_creator"
  | "approvals"
  | "configs";

export const TRANSFER_CATEGORY_LABELS: Record<TransferCategory, string> = {
  contacts: "Контакты",
  deals: "Сделки",
  tasks_assignee: "Задачи как исполнитель",
  tasks_creator: "Задачи как постановщик",
  approvals: "Согласования",
  configs: "Настройки и конфиги",
};

export interface EmployeeListItem {
  id: number;
  full_name: string;
  email: string;
  role: UserRole;
  avatar_path?: string | null;
  department_id?: number | null;
  department_name?: string | null;
  manager_id?: number | null;
  manager_name?: string | null;
  employment_status: EmploymentStatus;
  substitute_user_id?: number | null;
  substitute_name?: string | null;
  dismissed_at?: string | null;
  dismissed_reason?: string | null;
}

export interface TransferPreview {
  contacts: number;
  deals: number;
  tasks_assignee: number;
  tasks_creator: number;
  approvals: number;
  configs: number;
}

export interface RightsTransfer {
  id: number;
  from_user_id: number;
  from_user_name: string;
  to_user_id: number;
  to_user_name: string;
  categories: TransferCategory[];
  reason: string | null;
  initiated_by_user_id: number;
  initiated_by_name: string | null;
  initiated_by_role: UserRole | null;
  created_at: string;
  items_count: number;
  is_reversible: boolean;
  reverted_at: string | null;
}

export interface RightsTransferItem {
  id: number;
  transfer_id: number;
  entity_type: string;
  entity_id: number;
  entity_name: string | null;
  old_owner_id: number | null;
  new_owner_id: number | null;
}

export interface WorkSchedule {
  id?: number;
  scope: "department" | "user";
  department_id?: number | null;
  user_id?: number | null;
  day_of_week: number; // 1=Mon..7=Sun
  is_working: boolean;
  start_time: string | null;
  end_time: string | null;
  meeting_slot_min: number | null;
}

export interface UserVacation {
  id: number;
  user_id: number;
  user_name?: string | null;
  start_date: string;
  end_date: string;
  vacation_type: VacationType;
  substitute_user_id: number | null;
  substitute_name?: string | null;
  status: VacationStatus;
  notes: string | null;
  created_at: string;
}

export interface CalendarVacation extends UserVacation {
  department_id?: number | null;
}

export interface AvailableSlot {
  start: string;
  end: string;
}

// ── Эпик 18: AI Features (Contract Analysis + Deal/Lead Prefill) ──────

export type AISeverity = "error" | "warning" | "info";
export type AISectionStatus = "ok" | "warning" | "missing";
export type AIConfidence = "high" | "medium" | "low";
export type AIPrefillSource =
  | "all"
  | "tg"
  | "email"
  | "notes"
  | "activities"
  | "messages";

/** Один issue из AI-анализа договора. */
export interface ContractAnalysisIssue {
  severity: AISeverity;
  quote: string;
  explanation: string;
  suggestion?: string | null;
  /** Номер пункта (например «п. 3.2») если AI смог извлечь. */
  section?: string | null;
}

/** Стандартная секция в чек-листе. */
export interface ContractAnalysisStandardSection {
  section: string;
  status: AISectionStatus;
}

/** Полный ответ POST /api/contracts/{id}/ai-analyze. */
export interface ContractAnalysis {
  contract_id: number;
  issues: ContractAnalysisIssue[];
  standard_sections: ContractAnalysisStandardSection[];
  recommendations: string[];
  model?: string | null;
  analyzed_at?: string | null;
  ai_tokens_used?: number | null;
  /** Был ли результат закэширован (cache hit). UI рендерит «N мин назад». */
  from_cache: boolean;
}

/** Одно AI-предложение поля сделки/лида. suggested_value Any (число/строка/дата). */
export interface DealPrefillSuggestion {
  field: string;
  label: string;
  suggested_value: string | number | null;
  confidence: AIConfidence;
  reasoning: string;
  source_activity_id?: number | null;
  source_text?: string | null;
}

/** Полный ответ POST /api/deals/{id}/ai-prefill. */
export interface DealPrefill {
  deal_id: number;
  source: string;
  period_days: number;
  summary: string;
  suggestions: DealPrefillSuggestion[];
  ai_tokens_used?: number | null;
  model?: string | null;
}

/** Полный ответ POST /api/leads/{id}/ai-prefill. Тот же shape, но с lead_id. */
export interface LeadPrefill {
  lead_id: number;
  source: string;
  period_days: number;
  summary: string;
  suggestions: DealPrefillSuggestion[];
  ai_tokens_used?: number | null;
  model?: string | null;
}

// ── Эпик 15: Integration Hub + Calldown ──────────────────────────────

export type MarketplaceStatus = "connected" | "available" | "coming_soon" | "docs";

export interface MarketplaceItem {
  id: string;
  status: MarketplaceStatus;
}

export type CalldownProvider = "mango" | "uis" | "custom";
export type TranscriptStatus = "done" | "pending" | "failed" | null;

export interface CalldownCall {
  id: number;
  external_call_id: string | null;
  provider: CalldownProvider | null;
  phone: string | null;
  direction: "in" | "out";
  duration_sec: number | null;
  recording_url: string | null;
  transcript: string | null;
  transcript_status: TranscriptStatus;
  owner_user_id: number | null;
  owner_name: string | null;
  deal_id: number | null;
  activity_id: number | null;
  created_at: string;
}

export interface CalldownCallsResponse {
  items: CalldownCall[];
  total: number;
}

export interface CalldownConfig {
  provider: CalldownProvider | null;
  api_key: string | null;
  api_salt: string | null;
  account_id: string | null;
  api_token_value: string | null;
  transcription_enabled: boolean;
  transcription_lang: string;
  transcription_min_duration_sec: number;
  openai_api_key: string | null;
}

export interface OAuthClient {
  id: number;
  name: string;
  client_id: string;
  redirect_uris: string[];
  scopes: string[];
  is_active: boolean;
  created_at: string;
  updated_at: string;
}

export interface OAuthClientCreateResponse extends OAuthClient {
  plaintext_secret: string;
}

export interface ApiRequestLog {
  id: number;
  token_id: number | null;
  token_name: string | null;
  method: string;
  path: string;
  status_code: number;
  duration_ms: number;
  created_at: string;
}

export interface ApiRequestLogsResponse {
  items: ApiRequestLog[];
  total: number;
}

// ── Эпик 10.5: Личный кабинет + KPI + Multi-currency + AI ─────────────

/** Данные дашборда менеджера. */
export interface MeDashboard {
  personal_income_fact: number | null;
  personal_income_plan: number | null;
  team_income_fact: number | null;
  team_income_plan: number | null;
  ftm_count_fact: number | null;
  ftm_count_plan: number | null;
  score_pct: number | null;
  personal_income_currency: string | null;
  active_deals: MeDashboardDeal[];
  today_tasks: MeDashboardTask[];
}

export interface MeDashboardDeal {
  id: number;
  title: string;
  counterparty_name: string | null;
  stage_name: string;
  amount: number | null;
  currency: string | null;
  heat_score: number | null;
}

export interface MeDashboardTask {
  id: number;
  title: string;
  due_at: string | null;
  is_overdue: boolean;
  target_type: string | null;
  target_name: string | null;
  completed_at: string | null;
}

/** Профиль пользователя расширенный (для кабинета). */
export interface MeProfile {
  id: number;
  full_name: string;
  email: string;
  role: UserRole;
  job_title: string | null;
  department_name: string | null;
  manager_name: string | null;
  manager_id: number | null;
  supervisor_name: string | null;
  supervisor_id: number | null;
  avatar_path: string | null;
  subordinates_count: number;
}

/** Мотивационная карта. */
export interface MotivationalCard {
  id: number;
  user_id: number;
  year: number;
  month: number;
  status: "draft" | "finalized" | "paid";
  base_salary_amount: number;
  base_salary_currency: string;
  personal_income_plan: number;
  personal_income_fact: number;
  personal_income_currency: string;
  commission_amount_plan: number;
  commission_amount_fact: number;
  bonus_pool_amount: number;
  fact_team_bonus_proportional_amount: number;
  fact_team_bonus_equal_amount: number;
  total_amount_local: number;
  exchange_rates_snapshot: Record<string, number>;
  fact_commission_breakdown: MkCommissionItem[];
  ftm_count_fact: number;
  ftm_count_plan: number;
}

export interface MkCommissionItem {
  client_name: string;
  contract_number: string | null;
  payment_amount: number;
  commission_amount: number;
  currency: string;
}

/** Метрики менеджера. */
export interface MeMetrics {
  sales_by_day: Array<{ date: string; amount: number }> | null;
  funnel: Array<{ stage_name: string; count: number; conversion_pct: number | null }> | null;
  avg_cycle_days: number | null;
  personal_pct: number | null;
  team_avg_pct: number | null;
  team_rank: number | null;
  team_size: number | null;
}

/** Подопечный менеджера. */
export interface MeSubordinate {
  id: number;
  full_name: string;
  department_name: string | null;
  personal_income_plan: number;
  personal_income_fact: number;
  personal_income_currency: string;
  personal_income_pct: number;
  ftm_count_fact: number;
  ftm_count_plan: number;
}

/** Курс валюты. */
export interface CurrencyRate {
  id: number;
  from_currency: string;
  to_currency: string;
  rate: number;
  rate_date: string;
  source: "exchangerate-api" | "manual" | string;
  created_at: string;
}

/** Конвертация валюты. */
export interface CurrencyConversion {
  from_currency: string;
  to_currency: string;
  amount: number;
  converted_amount: number;
  rate: number;
  rate_date: string;
}

/** Правило комиссии. */
export interface CommissionRule {
  id: number;
  name: string;
  rate_pct: number;
  base: "new_income_payments" | "all_payments" | string;
  scope: "personal" | "all" | string;
  first_payment_only: boolean;
  requires_signed_contract: boolean;
  amount_must_match_plan: boolean;
  payout_timing: "immediately" | "end_of_month" | "end_of_quarter" | string;
  payout_note: string | null;
  is_active: boolean;
  created_at: string;
}

/** Командная цель. */
export interface TeamTarget {
  id: number;
  year: number;
  month: number;
  department_name: string | null;
  pipeline_name: string | null;
  metric: "new_income" | "ftm" | string;
  target_amount: number;
  target_currency: string;
  bonus_pool_amount: number;
  bonus_pool_currency: string;
  bonus_per_extra_manager: number;
  min_threshold_pct: number;
  proportional_pct: number;
  equal_pct: number;
  is_active: boolean;
}

/** План зарплаты. */
export interface SalaryPlan {
  id: number;
  user_id: number;
  user_name: string;
  year: number;
  month: number;
  base_salary_amount: number;
  base_salary_currency: string;
  base_salary_note: string | null;
  commission_rule_id: number | null;
  commission_rule_name: string | null;
  team_target_id: number | null;
  personal_income_plan: number;
  personal_income_currency: string;
  ftm_plan: number;
  supervisor_id: number | null;
  status: "draft" | "finalized" | "paid";
  created_at: string;
  updated_at: string;
}

/** AI чат-сообщение. */
export interface AIChatMessage {
  id: number;
  role: "user" | "assistant";
  content: string;
  created_at: string;
  tool_calls?: AIChatToolCall[] | null;
  /** Предложенное действие — рендерится как карточка подтверждения. */
  proposed_action?: AIAssistantProposedAction | null;
  /** Статус карточки действия после взаимодействия. */
  action_status?: "pending" | "confirmed" | "cancelled" | null;
  /** Ссылка на созданную сущность (после подтверждения). */
  link?: string | null;
}

export interface AIChatToolCall {
  name: string;
  result: Record<string, unknown>;
}

/** Тип действия, которое AI-ассистент предлагает выполнить. */
export type AIAssistantActionType = "create_task" | "create_deal" | "create_contract";

/** Предложенное действие из /api/ai/assistant/message. */
export interface AIAssistantProposedAction {
  type: AIAssistantActionType;
  args: Record<string, unknown>;
  summary: string;
  title: string;
}

/** Ответ /api/ai/assistant/message. */
export interface AIAssistantMessageResponse {
  assistant_text?: string | null;
  proposed_action?: AIAssistantProposedAction | null;
}

/** Один ход истории в anthropic-native формате. */
export interface AIAssistantHistoryItem {
  role: "user" | "assistant";
  content: string;
}

/** Ответ /api/ai/assistant/confirm. */
export interface AIAssistantConfirmResponse {
  entity_type: string;
  entity_id: number;
  link: string;
  message: string;
}

/** Сессия тренажёра холодных звонков. */
export interface ColdCallSession {
  id: number;
  scenario_type: "cold_call" | "objection_handling" | "ceo_rejection" | "follow_up";
  company_type: string;
  company_name: string | null;
  opening_line: string;
  scenario_brief: string;
  score: number | null;
  scores: ColdCallScores | null;
  feedback: string | null;
  status: "active" | "finished";
  created_at: string;
}

export interface ColdCallScores {
  speech_clarity: number;
  empathy: number;
  objection_handling: number;
  deal_closing: number;
}

/** Результат завершения тренировки (POST .../finish). */
export interface ColdCallResult {
  score: number;
  scores: ColdCallScores;
  feedback: string;
  recommendations: string[];
  good_decisions: string[];
}

/** Сообщение в транскрипте тренировки. */
export interface ColdCallTranscriptMessage {
  role: "user" | "assistant";
  content: string;
  hints?: string[] | null;
}

/** Элемент истории тренировок (GET /me/training/sessions). */
export interface ColdCallHistoryItem {
  id: number;
  scenario_type: "cold_call" | "objection_handling" | "ceo_rejection" | "follow_up";
  company_type: string;
  company_name: string | null;
  score: number | null;
  status: "active" | "finished";
  created_at: string;
}

/** Детальная сессия тренировки (GET /me/training/sessions/{id}). */
export interface ColdCallSessionDetail {
  id: number;
  scenario_type: "cold_call" | "objection_handling" | "ceo_rejection" | "follow_up";
  company_type: string;
  company_name: string | null;
  opening_line: string;
  scenario_brief: string;
  status: "active" | "finished";
  created_at: string;
  transcript: ColdCallTranscriptMessage[];
  score: number | null;
  scores: ColdCallScores | null;
  feedback: string | null;
  recommendations: string[] | null;
  good_decisions: string[] | null;
}

/** AI разбор компании/контрагента. */
export interface CounterpartyAIAnalysis {
  counterparty_id: number;
  icp_fit: "high" | "medium" | "low";
  summary: string;
  risks: string[];
  recommendations: string[];
  relationship_health: "cold" | "warm" | "hot" | "at_risk";
  priority_score: number;
  generated_at: string;
  from_cache: boolean;
}

// ── Эпик 21.2: Notification Channels ──────────────────────────────────

export type NotificationChannel = "in_app" | "tg" | "email";

export type NotificationKindV2 =
  | "task_assigned"
  | "task_status_changed"
  | "task_extend_requested"
  | "deal_won"
  | "deal_stage_changed"
  | "approval_needed"
  | "sla_breach"
  | "course_assigned"
  | "course_completed"
  | "contract_signed"
  | "mention"
  | "system";

export interface NotificationChannelPreference {
  kind: string;
  channel: NotificationChannel;
  is_enabled: boolean;
}

export interface NotificationPreferencesResponse {
  preferences: NotificationChannelPreference[];
}

export interface QuietHours {
  start: string | null;
  end: string | null;
  enabled: boolean;
  email_enabled: boolean;
  notification_phone: string | null;
}

export type BroadcastStatus = "pending" | "running" | "completed" | "failed";

// Зеркалит BroadcastOut на бэке (app/routers/notification_broadcasts.py).
// channels — list[str], получатели — recipient_filter (singular), счётчик —
// recipients_count. scheduled_at / created_by_name бэк НЕ возвращает.
export interface NotificationBroadcast {
  id: number;
  initiated_by_user_id: number | null;
  kind: string;
  title: string | null;
  body: string | null;
  link: string | null;
  recipient_filter: Record<string, unknown> | null;
  channels: string[] | null;
  recipients_count: number | null;
  delivered_count: number;
  failed_count: number;
  status: BroadcastStatus;
  created_at: string;
  completed_at: string | null;
}

// GET /broadcasts возвращает массив BroadcastOut напрямую (без обёртки).
export type BroadcastListResponse = NotificationBroadcast[];

export interface NotificationTemplate {
  id: number;
  kind: string;
  channel: NotificationChannel;
  locale: string;
  subject: string | null;
  body_template: string;
  variables: string[];
  is_active: boolean;
  created_at: string;
  updated_at: string;
}

export interface NotificationTemplateListResponse {
  items: NotificationTemplate[];
  total: number;
}

// Эпик 24.2: Google Calendar sync
export interface GoogleCalendarSettings {
  configured: boolean;
  connected: boolean;
  sync_enabled: boolean;
  sync_meeting: boolean;
  sync_call: boolean;
  sync_only_with_time: boolean;
  calendar_id: string;
  last_sync_at: string | null;
  google_email: string | null;
  linked_events_count: number;
}

// ── Финансы (Ф0) ─────────────────────────────────────────────────────────────
// Контракт выровнен 1:1 с backend (app/schemas_finance.py, LOCKED Ф0).

/** Направление операции — backend-enum (in|out|transfer). НЕ income/expense. */
export type FinDirection = "in" | "out" | "transfer";
export type FinOpStatus =
  | "planned"
  | "to_pay"
  | "on_hold"
  | "posted"
  | "reversed"
  | "rejected"
  | "cancelled"
  | "partially_paid";
export type FinAccountType = "bank" | "cash" | "acquiring" | "ewallet";
export type FinCashflowActivity = "operating" | "investing" | "financing";
/** Направление статьи ДДС — backend-enum (inflow|outflow|both). */
export type FinCategoryDirection = "inflow" | "outflow" | "both";

export interface FinLegalEntity {
  id: number;
  name: string;
  country_code: string;
  functional_currency: string;
  vat_enabled: boolean;
  tax_regime: string;
  vat_recognition: string;
  tax_id: string | null;
  licensor_entity_id: number | null;
  is_active: boolean;
  sort_order: number;
}

export interface FinOpType {
  id: number;
  code: string;
  name: string;
  direction: string; // in|out|transfer|none
  posting_template: string;
  default_cat_id: number | null;
  default_gl_account_id: number | null;
  counts_in_pnl: boolean;
  counts_in_cashflow: boolean;
  is_internal_transfer: boolean;
  is_archived: boolean;
  sort_order: number;
}

export interface FinCashflowCategory {
  id: number;
  cat_set_id: number;
  parent_id: number | null;
  code: string;
  name: string;
  level: number;
  activity: FinCashflowActivity;
  direction: FinCategoryDirection;
  sort_order: number;
  is_active: boolean;
}

export interface FinVatRate {
  id: number;
  legal_entity_id: number | null;
  country_code: string | null;
  name: string;
  rate_pct: number;
  kind: string;
  effective_from: string | null;
  effective_to: string | null;
  is_active: boolean;
}

export interface FinMoneyAccount {
  id: number;
  legal_entity_id: number;
  gl_account_id: number;
  name: string;
  account_type: FinAccountType;
  currency: string;
  initial_balance: number;
  is_active: boolean;
  sort_order: number;
}

/** Остаток по счёту (GET /money-accounts/{id}/balance). amount_func — в функц.валюте юрлица. */
export interface FinAccountBalance {
  money_account_id: number;
  currency: string;
  amount: number;
  amount_func: number;
}

/** Строка в составе BalancesOut (GET /balances). */
export interface FinBalanceRow {
  money_account_id: number;
  account_name: string;
  currency: string;
  amount: number;
  amount_func: number;
}

export interface FinBalancesResponse {
  legal_entity_id: number;
  on_date: string | null;
  rows: FinBalanceRow[];
}

export interface FinAllocation {
  id: number;
  operation_id: number;
  cashflow_category_id: number | null;
  amount: number;
  comment: string | null;
}

export interface FinOperation {
  id: number;
  number: string | null;
  legal_entity_id: number;
  op_type_id: number | null;
  direction: FinDirection;
  status: FinOpStatus;
  op_date: string;
  due_date: string | null;
  amount: number;
  currency: string;
  to_amount: number | null;
  account_from_id: number | null;
  account_to_id: number | null;
  cashflow_category_id: number | null;
  counterparty_company_id: number | null;
  vat_rate_id: number | null;
  vat_amount: number | null;
  amount_net: number | null;
  purpose: string | null;
  journal_entry_id: number | null;
  deal_id: number | null;
  contract_id: number | null;
  subscription_id: number | null;
  is_for_management: boolean;
  rejected_reason: string | null;
  posted_at: string | null;
  created_at: string;
}

/** OperationListOut — items + total + sum-footer (Σ по направлениям). */
export interface FinOperationListResponse {
  items: FinOperation[];
  total: number;
  sum_in: number;
  sum_out: number;
}

// ── Финансы: ручные журналы ──────────────────────────────────────────────────

export type FinJournalStatus = "draft" | "posted" | "reversed";

export interface FinAccountGl {
  id: number;
  code: string;
  name: string;
  type: string;
  subtype: string | null;
  normal_side: string;
  is_money: boolean;
  requires_counterparty: boolean;
  is_active: boolean;
  sort_order: number;
}

export interface FinManualJournalLine {
  id?: number;
  account_gl_id: number;
  side: "dt" | "kt";
  amount: number;
  currency: string;
  counterparty_company_id: number | null;
  money_account_id?: number | null;
  cashflow_category_id?: number | null;
  comment?: string | null;
}

export interface FinManualJournal {
  id: number;
  number: string | null;
  legal_entity_id: number;
  date: string;
  status: FinJournalStatus;
  memo: string;
  journal_entry_id: number | null;
  posted_at: string | null;
  created_at: string;
  lines: FinManualJournalLine[];
}

// ── Финансы: ДДС (cashflow-simple) ───────────────────────────────────────────
// Backend отдаёт плоский rows-контракт; группировку по activity делает фронт.

export interface FinCashflowRow {
  category_id: number;
  category_code: string;
  category_name: string;
  activity: FinCashflowActivity;
  direction: FinCategoryDirection;
  net_func: number;
}

export interface FinCashflowReport {
  legal_entity_id: number;
  date_from: string;
  date_to: string;
  rows: FinCashflowRow[];
  inflow: number;
  outflow: number;
  net: number;
}

// ── Финансы: закрытие периодов ───────────────────────────────────────────────

export interface FinPeriodLock {
  id: number;
  legal_entity_id: number;
  year: number;
  month: number;
  locked_at: string;
  locked_by_user_id: number | null;
}

/** Результат SHADOW-сверки комиссий (GET /api/finance/integrations/shadow-reconciliation). */
export interface FinShadowRecon {
  ok: boolean;
  total_old: number;
  total_new: number;
  matched: number;
  missing_in_gl: number[];
  amount_mismatch: number[];
  orphan_in_gl: number[];
}

// ── Финансы Ф1: отчёты ───────────────────────────────────────────────────────

/** Строка P&L отчёта (GET /api/finance/reports/pnl). */
export interface FinPnlLine {
  account_id: number;
  account_code: string;
  account_name: string;
  account_type: string; // "income" | "expense"
  amount: number; // всегда положительное (sign-adjusted на backend)
}

/** P&L отчёт (GET /api/finance/reports/pnl). */
export interface FinPnlReport {
  legal_entity_id: number;
  date_from: string;
  date_to: string;
  basis: string; // accrual (по начислению) | cash (по деньгам)
  income_lines: FinPnlLine[];
  expense_lines: FinPnlLine[];
  total_income: number;
  total_expense: number;
  profit: number; // total_income - total_expense, знаковое
}

// ─────────────────────── Ф4: признание выручки / база / переоценка ───────────────────────

/** Строка плана признания выручки MRR (GET /api/finance/revenue-recognition/schedule). */
export interface FinRevenueSchedule {
  id: number;
  subscription_id: number;
  legal_entity_id: number;
  counterparty_company_id: number | null;
  period_year: number;
  period_month: number;
  amount_net: number;
  vat_amount: number;
  currency: string;
  revenue_account_code: string;
  status: string; // scheduled | recognized | skipped | reversed
  recognition_key: string;
  recognized_journal_entry_id: number | null;
  recognized_at: string | null;
}

/** Итог прогона признания (POST /api/finance/revenue-recognition/run). */
export interface FinRevenueRecognitionRun {
  period: string; // "YYYY-MM"
  processed: number;
  scheduled: number;
  recognized: number;
  existing: number;
  skipped: number;
}

/** Результат сторно строки признания (POST .../schedule/{id}/reverse). */
export interface FinRevenueReverse {
  schedule_id: number;
  reversal_entry_id: number;
  status: string;
}

/** Итог переоценки валютных остатков (POST /api/finance/revaluation/run). */
export interface FinRevaluationRun {
  period: string; // "YYYY-MM"
  processed: number;
  revalued: number;
  no_change: number;
  skipped_func_ccy: number;
}

/** Глобальные настройки модуля (GET /api/finance/settings). */
export interface FinSettings {
  base_currency: string;
  base_currency_changed_at: string | null;
  auto_approve_without_scenario: boolean;
}

/** Задание пересчёта amount_in_base при смене базы (POST /api/finance/settings/base-currency). */
export interface FinBaseRecomputeJob {
  id: number;
  target_currency: string;
  previous_currency: string | null;
  status: string; // pending | running | done | partial | failed
  total_lines: number;
  processed_lines: number;
  skipped_closed: number;
  missing_rate_lines: number;
}

/** Строка ОСВ (GET /api/finance/reports/trial-balance). */
export interface FinTrialBalanceRow {
  account_id: number;
  account_code: string;
  account_name: string;
  account_type: string;
  debit: number;  // оборот Дт, положительный
  credit: number; // оборот Кт, положительный
  balance: number; // debit - credit, знаковое
}

/** ОСВ отчёт (GET /api/finance/reports/trial-balance). */
export interface FinTrialBalanceReport {
  legal_entity_id: number;
  on_date: string | null;
  rows: FinTrialBalanceRow[];
  total_debit: number;
  total_credit: number;
  is_balanced: boolean;
}

/** Строка AR/AP отчёта (GET /api/finance/reports/ar-ap). */
export interface FinArApRow {
  counterparty_company_id: number | null;
  account_id: number;
  account_code: string;
  account_name: string;
  balance: number; // AR>0 (нам должны), AP<0 (мы должны)
}

/** AR/AP отчёт (GET /api/finance/reports/ar-ap?kind=ar|ap). */
export interface FinArApReport {
  legal_entity_id: number;
  kind: "ar" | "ap";
  on_date: string | null;
  rows: FinArApRow[];
  total: number;
}

// ── Финансы Ф1: GL-журнал (Главная книга) ────────────────────────────────────

/** Строка проводки (ledger line). */
export interface FinLedgerLine {
  id: number;
  account_gl_id: number;
  amount: number;
  currency: string;
  amount_func: number;
  amount_in_base: number | null;
  money_account_id: number | null;
  cashflow_category_id: number | null;
  counterparty_company_id: number | null;
  fx_rate: number | null;
  comment: string | null;
}

/** Проводка журнала (JournalEntry со строками). */
export interface FinJournalEntry {
  id: number;
  legal_entity_id: number;
  date: string;
  kind: string;   // "operation" | "manual_journal" | "reversal" | "opening"
  status: string; // "draft" | "posted" | "reversed"
  source: string; // "operation" | "manual_journal" | "opening" | etc.
  source_ref_id: number | null;
  reverses_entry_id: number | null;
  func_currency: string;
  memo: string | null;
  posted_at: string | null;
  lines: FinLedgerLine[];
}

/** Листинг GL-проводок (GET /api/finance/entries). */
export interface FinEntriesListOut {
  items: FinJournalEntry[];
  total: number;
}

// ── Финансы Ф1: права доступа ────────────────────────────────────────────────

/** Правило прав (GET /api/finance/permissions). */
export interface FinPermission {
  id: number;
  role: string | null;
  user_id: number | null;
  legal_entity_id: number | null;
  capability: string;
  allowed: boolean;
}

// ── Финансы Ф2: заявки ───────────────────────────────────────────────────────

export type FinRequestType =
  | "salary"
  | "commission"
  | "expense_reimbursement"
  | "payment";

export type FinRequestStatus =
  | "draft"
  | "submitted"
  | "approved"
  | "rejected"
  | "paid"
  | "cancelled";

export interface FinRequest {
  id: number;
  number: string | null;
  request_type: FinRequestType;
  legal_entity_id: number;
  requester_user_id: number | null;
  amount: string; // Decimal → string in JSON
  currency: string;
  op_type_id: number | null;
  counterparty_company_id: number | null;
  payee_user_id: number | null;
  cashflow_category_id: number | null;
  period_year: number | null;
  period_month: number | null;
  desired_date: string | null; // YYYY-MM-DD
  description: string | null;
  status: FinRequestStatus;
  resulting_operation_id: number | null;
  rejected_reason: string | null;
  submitted_at: string | null;
  decided_at: string | null;
  created_at: string;
}

// ── Финансы Ф2: реестры ──────────────────────────────────────────────────────

export type FinRegistryApprovalStatus =
  | "draft"
  | "on_review"
  | "approved"
  | "rejected";

export type FinRegistryPaymentStatus = "new" | "partial" | "paid";

export interface FinRegistry {
  id: number;
  number: string | null;
  legal_entity_id: number;
  source_account_id: number;
  registry_date: string; // YYYY-MM-DD
  title: string | null;
  approval_status: FinRegistryApprovalStatus;
  payment_status: FinRegistryPaymentStatus;
  comment: string | null;
  created_by_user_id: number | null;
  submitted_at: string | null;
  approved_at: string | null;
  posted_at: string | null;
  created_at: string;
}

export interface FinRegistryDetail extends FinRegistry {
  items: FinOperation[];
  total_amount: string; // Decimal → string in JSON
}

// ── Финансы Ф2: согласование ─────────────────────────────────────────────────

export type FinApprovalStatus = "pending" | "approved" | "rejected";
export type FinApprovalDecision = "pending" | "approved" | "rejected";

export interface FinApprovalVote {
  id: number;
  approvable_kind: string;
  approvable_id: number;
  scenario_id: number | null;
  user_id: number;
  stage_order: number;
  decision: FinApprovalDecision;
  comment: string | null;
  decided_at: string | null;
}

export interface FinApprovalStageSummary {
  order: number;
  name: string;
  mode: "any" | "all";
  user_ids: number[];
  min_required: number;
  approved: number;
  rejected: number;
  pending: number;
  is_active: boolean;
}

export interface FinApprovalSummary {
  status: FinApprovalStatus;
  active_stage: number;
  total_stages: number;
  stages: FinApprovalStageSummary[];
  scenario_id: number | null;
  votes: FinApprovalVote[];
}

// ── Финансы Ф2: сценарии согласования ───────────────────────────────────────

export interface FinScenarioStage {
  order: number;
  name: string;
  user_ids: number[];
  min_required: number;
  mode: "any" | "all";
}

export interface FinApprovalScenario {
  id: number;
  name: string;
  applies_to: "operation" | "registry" | "request" | "invoice";
  op_type_id: number | null;
  legal_entity_id: number | null;
  min_amount: string | null; // Decimal → string in JSON
  max_amount: string | null;
  stages: FinScenarioStage[];
  priority: number;
  is_active: boolean;
}

// ── Финансы Ф5: инвойсы ──────────────────────────────────────────────────────

export type FinInvoiceStatus =
  | "draft"
  | "issued"
  | "partially_paid"
  | "paid"
  | "cancelled";

export interface FinInvoiceLine {
  id: number;
  name: string;
  qty: string;           // Decimal
  unit_price: string;    // Decimal
  amount_net: string;    // Decimal
  vat_rate_id: number | null;
  vat_amount: string;    // Decimal
  amount_gross: string;  // Decimal
  revenue_account_code: string | null;
  cashflow_category_id: number | null;
  sort_order: number;
}

export interface FinInvoice {
  id: number;
  number: string | null;
  legal_entity_id: number;
  counterparty_company_id: number;
  contact_id: number | null;
  deal_id: number | null;
  contract_id: number | null;
  subscription_id: number | null;
  issue_date: string;         // YYYY-MM-DD
  due_date: string | null;
  currency: string;
  amount_net: string;         // Decimal
  vat_amount: string;
  amount_gross: string;
  paid_amount: string;
  status: FinInvoiceStatus;
  revenue_account_code: string;
  purpose: string | null;
  amount_in_words: string | null;
  document_file_id: number | null;
  journal_entry_id: number | null;
  signed_at: string | null;
  signed_by_user_id: number | null;
  issued_at: string | null;
  created_at: string;
}

export interface FinInvoiceDetail extends FinInvoice {
  lines: FinInvoiceLine[];
}

// ── Финансы Ф5: акты ─────────────────────────────────────────────────────────

export type FinActStatus =
  | "draft"
  | "issued"
  | "signed"
  | "cancelled";

export interface FinActLine {
  id: number;
  name: string;
  qty: string;
  unit_price: string;
  amount_net: string;
  vat_rate_id: number | null;
  vat_amount: string;
  amount_gross: string;
  sort_order: number;
}

export interface FinAct {
  id: number;
  number: string | null;
  legal_entity_id: number;
  counterparty_company_id: number;
  invoice_id: number | null;
  contract_id: number | null;
  subscription_id: number | null;
  act_date: string;            // YYYY-MM-DD
  period_year: number | null;
  period_month: number | null;
  currency: string;
  amount_net: string;
  vat_amount: string;
  amount_gross: string;
  status: FinActStatus;
  purpose: string | null;
  amount_in_words: string | null;
  document_file_id: number | null;
  signed_at: string | null;
  signed_by_user_id: number | null;
  created_at: string;
}

export interface FinActDetail extends FinAct {
  lines: FinActLine[];
}

// ── Финансы Ф5: вендор-счета ─────────────────────────────────────────────────

export type FinVendorBillStatus =
  | "draft"
  | "confirmed"
  | "partially_paid"
  | "paid"
  | "cancelled";

export interface FinVendorBillLine {
  id: number;
  name: string;
  qty: string;
  unit_price: string;
  amount_net: string;
  vat_rate_id: number | null;
  vat_amount: string;
  amount_gross: string;
  expense_account_code: string | null;
  cashflow_category_id: number | null;
  sort_order: number;
}

export interface FinVendorBill {
  id: number;
  number: string | null;
  bill_no: string | null;
  legal_entity_id: number;
  supplier_company_id: number;
  bill_date: string;           // YYYY-MM-DD
  due_date: string | null;
  currency: string;
  amount_net: string;
  vat_amount: string;
  amount_gross: string;
  paid_amount: string;
  status: FinVendorBillStatus;
  expense_account_code: string;
  cashflow_category_id: number | null;
  purpose: string | null;
  document_file_id: number | null;
  journal_entry_id: number | null;
  confirmed_at: string | null;
  created_at: string;
}

export interface FinVendorBillDetail extends FinVendorBill {
  lines: FinVendorBillLine[];
}

// ── Финансы Ф5: НДС-книги ────────────────────────────────────────────────────

export interface FinVatBookEntry {
  entry_id: number;
  entry_date: string;          // YYYY-MM-DD
  source: string;
  source_ref_id: number | null;
  counterparty_company_id: number | null;
  vat_amount: string;          // Decimal
  memo: string | null;
}

export interface FinVatReport {
  output_total: string;        // Decimal
  input_total: string;
  vat_payable: string;
  sales_book: FinVatBookEntry[];
  purchase_book: FinVatBookEntry[];
}

// ── Финансы Ф5: aging ────────────────────────────────────────────────────────

export type FinAgingBucket =
  | "current"
  | "0-30"
  | "31-60"
  | "61-90"
  | "90+";

export interface FinAgingDoc {
  doc_id: number;
  number: string | null;
  counterparty_company_id: number | null;
  currency: string;
  due_date: string | null;
  outstanding: string;   // Decimal
  bucket: FinAgingBucket;
}

export interface FinAgingReport {
  docs: FinAgingDoc[];
  by_bucket: Record<string, string>;  // bucket → Decimal string
  total: string;                       // Decimal
  total_amount: string;
}

// ── Финансы Ф5: DocPayment ────────────────────────────────────────────────────

export interface FinDocPaymentIn {
  money_account_id: number;
  amount: string;    // Decimal
  on_date: string;   // YYYY-MM-DD
  cashflow_category_id: number | null;
}

// ── Wave 7: базовая валюта группы (admin/currency-rates) ──────────────────────

/** Текущая базовая валюта группы (GET /admin/currency-rates/base-currency). */
export interface AdminBaseCurrency {
  base_currency: string;
  base_currency_changed_at: string | null;
}

/**
 * Ответ POST /admin/currency-rates/base-currency.
 * Бэкенд может вернуть полный FinBaseRecomputeJob (с суммаризацией пересчёта)
 * либо просто { ok }. Обрабатываем оба варианта через partial.
 */
export type AdminBaseCurrencyResponse = Partial<FinBaseRecomputeJob> & { ok?: boolean };

/** Ответ POST /admin/currency-rates/refresh. */
export interface AdminCurrencyRefreshResponse {
  ok: boolean;
  updated_pairs: number;
  message: string;       // RU-текст
  reason?: string | null; // машинный код причины (no_api_key, ...), если есть
}

// ── Wave 7: платёжный календарь (/finance/calendar) ───────────────────────────

export type FinCalendarDirection = "in" | "out";
export type FinCalendarStatus = "planned" | "paid" | "overdue";
export type FinCalendarSourceType =
  | "invoice"
  | "act"
  | "vendor_bill"
  | "request"
  | "deal";

/** Событие платёжного календаря (GET /api/finance/calendar). */
export interface FinCalendarEvent {
  date: string; // YYYY-MM-DD
  direction: FinCalendarDirection;
  title: string;
  amount: number; // всегда > 0; знак определяется direction
  currency: string;
  source_type: FinCalendarSourceType;
  source_id: number;
  status: FinCalendarStatus;
  legal_entity_id: number | null;
  counterparty_company_id: number | null;
}

/** Итог по дню (GET /api/finance/calendar → day_totals[]). */
export interface FinCalendarDayTotal {
  date: string; // YYYY-MM-DD
  currency: string;
  in_amount: number;
  out_amount: number;
}

export interface FinCalendarResponse {
  events: FinCalendarEvent[];
  day_totals: FinCalendarDayTotal[];
}
