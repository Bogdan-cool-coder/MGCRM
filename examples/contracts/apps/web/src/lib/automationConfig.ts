/**
 * Константы для PipelineAutomation UI (Эпик 4).
 *
 * Список триггеров/действий/таргетов синхронизирован с
 * `apps/api/app/services/automation_executor.py` (AUTOMATION_TRIGGERS,
 * AUTOMATION_ACTIONS, AUTOMATION_TARGET_TYPES, SET_FIELD_WHITELIST,
 * DATE_FIELDS — см. run_date_field_scanner).
 *
 * Whitelist'ы захардкожены на фронте (без отдельного backend-endpoint'а) —
 * допустимо для MVP. При расширении на backend → синхронизировать оба файла.
 */
import type {
  AutomationActionKind,
  AutomationRunStatus,
  AutomationTargetType,
  AutomationTriggerKind,
} from "@/lib/types";

/** Триггеры MVP — sync с backend AUTOMATION_TRIGGERS. */
export const TRIGGER_OPTIONS: { value: AutomationTriggerKind; label: string; description: string; isCron: boolean }[] = [
  {
    value: "on_enter_stage",
    label: "При входе на этап",
    description: "Срабатывает сразу, когда сделка/лид попадает на выбранный этап.",
    isCron: false,
  },
  {
    value: "idle_in_stage_days",
    label: "Висит на этапе N дней",
    description: "Cron-проверка каждый час. Срабатывает один раз за окно N дней.",
    isCron: true,
  },
  {
    value: "date_field_approaching",
    label: "Дата приближается",
    description: "Cron-проверка. Окно ±1 день к дате `сегодня + N`.",
    isCron: true,
  },
  {
    value: "on_create",
    label: "При создании сущности",
    description: "Срабатывает в момент создания. Полезно для авто-распределения новых лидов из Inbox.",
    isCron: false,
  },
];

export const TRIGGER_LABELS: Record<AutomationTriggerKind, string> = {
  on_enter_stage: "При входе на этап",
  idle_in_stage_days: "Висит на этапе N дней",
  date_field_approaching: "Дата приближается",
  on_create: "При создании",
};

/** Действия MVP — sync с backend AUTOMATION_ACTIONS. */
export const ACTION_OPTIONS: { value: AutomationActionKind; label: string; description: string; isNew?: boolean }[] = [
  {
    value: "tg_notify",
    label: "Уведомление в Telegram",
    description: "Отправить сообщение в Telegram владельцу, конкретному пользователю или в чат.",
  },
  {
    value: "create_task",
    label: "Создать задачу",
    description: "Создаёт Activity типа «task» с дедлайном (опционально).",
  },
  {
    value: "set_field",
    label: "Изменить поле",
    description: "Обновить поле у сделки/лида/подписки (whitelist полей).",
  },
  {
    value: "generate_document",
    label: "Сгенерировать документ",
    description: "MVP: записывает note в timeline. Полная интеграция — TBD.",
  },
  {
    value: "change_owner",
    label: "Сменить ответственного",
    description: "Автоматически назначает ответственного по правилу (round-robin, по продукту, по стране, по отделу).",
  },
  {
    value: "webhook",
    label: "Webhook",
    description: "Отправляет POST-запрос на внешний URL с payload события.",
  },
  {
    value: "email",
    label: "Email",
    description: "Отправляет письмо через настроенный SMTP (SMTP_HOST/USER/PASS/FROM в .env).",
  },
  {
    value: "start_sequence",
    label: "Запустить последовательность",
    description: "Запускает цепочку шагов (Sequence) для цели.",
  },
  // Эпик 23: новые action_kind (backend TBD — см. automation_executor.py)
  {
    value: "set_tags",
    label: "Редактировать теги",
    description: "Добавить или удалить теги у объекта. Backend: требует модели тегов.",
    isNew: true,
  },
  {
    value: "complete_tasks",
    label: "Завершить задачи",
    description: "Завершить открытые задачи (Activity.type=task) по цели.",
    isNew: true,
  },
  {
    value: "change_stage",
    label: "Сменить этап",
    description: "Перевести объект на указанный этап воронки.",
    isNew: true,
  },
  {
    value: "create_deal",
    label: "Создать сделку",
    description: "Создать новую сделку в выбранной воронке.",
    isNew: true,
  },
];

export const ACTION_LABELS: Record<AutomationActionKind, string> = {
  tg_notify: "Telegram",
  create_task: "Создать задачу",
  set_field: "Изменить поле",
  generate_document: "Сгенерировать документ",
  change_owner: "Сменить ответственного",
  webhook: "Webhook",
  email: "Email",
  start_sequence: "Запустить последовательность",
  // Эпик 23
  set_tags: "Редактировать теги",
  complete_tasks: "Завершить задачи",
  change_stage: "Сменить этап",
  create_deal: "Создать сделку",
};

/** Иконки для action_kind (используются в AddTriggerModal и AutomationInlineCard) */
export const ACTION_ICONS: Record<AutomationActionKind, string> = {
  tg_notify: "bi-telegram",
  create_task: "bi-clipboard-plus",
  set_field: "bi-pencil-square",
  generate_document: "bi-file-earmark-text",
  change_owner: "bi-person-fill-gear",
  webhook: "bi-broadcast",
  email: "bi-envelope-fill",
  start_sequence: "bi-collection-play-fill",
  set_tags: "bi-tags",
  complete_tasks: "bi-check-circle-fill",
  change_stage: "bi-arrow-right-circle-fill",
  create_deal: "bi-currency-dollar",
};

/** Цвета иконок для action_kind (text-* классы) */
export const ACTION_ICON_COLORS: Record<AutomationActionKind, string> = {
  tg_notify: "text-info",
  create_task: "text-success",
  set_field: "text-warning",
  generate_document: "text-primary",
  change_owner: "text-primary-light",
  webhook: "text-primary",
  email: "text-info",
  start_sequence: "text-primary",
  set_tags: "text-warning",
  complete_tasks: "text-success",
  change_stage: "text-primary-light",
  create_deal: "text-success",
};

export const TARGET_TYPE_OPTIONS: { value: AutomationTargetType; label: string }[] = [
  { value: "deal", label: "Сделка" },
  { value: "lead", label: "Лид" },
  { value: "subscription", label: "Подписка" },
];

export const TARGET_TYPE_LABELS: Record<AutomationTargetType, string> = {
  deal: "Сделка",
  lead: "Лид",
  subscription: "Подписка",
};

/** Опции типа цели для триггера on_create. */
export const ON_CREATE_TARGET_TYPE_OPTIONS: { value: string; label: string }[] = [
  { value: "lead", label: "Лид" },
  { value: "deal", label: "Сделка" },
  { value: "inbound_message", label: "Входящее сообщение" },
];

/** Опции фильтра action_kind на странице истории запусков. */
export const ACTION_KIND_FILTER_OPTIONS: { value: AutomationActionKind | ""; label: string }[] = [
  { value: "", label: "Любое действие" },
  { value: "tg_notify", label: "Telegram" },
  { value: "create_task", label: "Создать задачу" },
  { value: "set_field", label: "Изменить поле" },
  { value: "generate_document", label: "Сгенерировать документ" },
  { value: "change_owner", label: "Сменить ответственного" },
  { value: "webhook", label: "Webhook" },
  { value: "email", label: "Email" },
  { value: "start_sequence", label: "Запустить последовательность" },
  { value: "set_tags", label: "Редактировать теги" },
  { value: "complete_tasks", label: "Завершить задачи" },
  { value: "change_stage", label: "Сменить этап" },
  { value: "create_deal", label: "Создать сделку" },
];

/** Правила распределения ответственного для change_owner. */
export const CHANGE_OWNER_RULE_OPTIONS: { value: string; label: string; hint: string }[] = [
  { value: "round_robin", label: "По очереди (round-robin)", hint: "По очереди, цикл по pool'у" },
  { value: "by_product", label: "По продукту", hint: "Берёт product → ищет owner" },
  { value: "by_country", label: "По стране", hint: "Берёт страну → ищет owner" },
  { value: "by_department", label: "По отделу", hint: "Если у лида указан department" },
];

/** Роли пользователей (для фильтра pool'а в change_owner). */
export const USER_ROLE_OPTIONS: { value: string; label: string }[] = [
  { value: "admin", label: "Администратор" },
  { value: "director", label: "Руководитель" },
  { value: "manager", label: "Менеджер" },
  { value: "lawyer", label: "Юрист" },
];

export const RUN_STATUS_LABELS: Record<AutomationRunStatus, string> = {
  pending: "В работе",
  success: "Успех",
  failed: "Ошибка",
  skipped: "Пропущено",
};

/** Цветовая схема badge для статуса запуска. */
export const RUN_STATUS_BADGE: Record<AutomationRunStatus, { bg: string; dot: string }> = {
  pending: { bg: "bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300",                  dot: "bg-gray-500" },
  success: { bg: "bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-500",    dot: "bg-success-500" },
  failed:  { bg: "bg-danger-50 text-danger-700 dark:bg-danger-500/10 dark:text-danger-500",        dot: "bg-danger-500" },
  skipped: { bg: "bg-info-50 text-info-700 dark:bg-info-500/10 dark:text-info-500",                dot: "bg-info-500" },
};

/**
 * Whitelist полей для set_field. Sync с backend SET_FIELD_WHITELIST.
 * Расширение — только параллельно с правкой `automation_executor.py`.
 */
export const SET_FIELD_WHITELIST: Record<AutomationTargetType, { value: string; label: string }[]> = {
  deal: [
    { value: "notes", label: "Заметка (notes)" },
    { value: "title", label: "Название (title)" },
  ],
  lead: [
    { value: "notes", label: "Заметка (notes)" },
    { value: "status", label: "Статус (status)" },
  ],
  subscription: [
    { value: "notes", label: "Заметка (notes)" },
    { value: "health_tier", label: "Тариф здоровья (health_tier)" },
    { value: "manual_tier_override", label: "Ручная установка тира (manual_tier_override)" },
  ],
};

/**
 * Whitelist полей даты для триггера date_field_approaching. Sync с backend
 * DATE_FIELDS в `run_date_field_scanner`. В MVP — только subscription.
 */
export const DATE_FIELD_WHITELIST: Record<AutomationTargetType, { value: string; label: string }[]> = {
  subscription: [
    { value: "discount_until", label: "Окончание скидки (discount_until)" },
    { value: "impl_start_date", label: "Начало внедрения (impl_start_date)" },
    { value: "act_signed_date", label: "Дата акта (act_signed_date)" },
    { value: "last_fee_increase_at", label: "Последнее повышение АП (last_fee_increase_at)" },
    { value: "qa_date", label: "Дата QA (qa_date)" },
  ],
  deal: [],
  lead: [],
};

/** Target type'ы, поддерживающие idle_in_stage_days (sync с backend). */
export const IDLE_SUPPORTED_TARGET_TYPES: AutomationTargetType[] = ["deal", "lead"];

/** Target type'ы, поддерживающие date_field_approaching (sync с backend). */
export const DATE_FIELD_SUPPORTED_TARGET_TYPES: AutomationTargetType[] = ["subscription"];

/** Плейсхолдеры для шаблонов сообщений TG / задач. */
export const MESSAGE_PLACEHOLDERS: { token: string; description: string }[] = [
  { token: "{target_id}", description: "ID цели (сделки/лида/подписки)" },
  { token: "{target_type}", description: "Тип цели (сделка/лид/подписка)" },
  { token: "{target_title}", description: "Название/имя цели" },
  { token: "{owner_name}", description: "Имя владельца цели" },
];
