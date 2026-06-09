"use client";

import type {
  BranchConfig,
  BranchConditionField,
  BranchConditionOperator,
  SequenceStep,
  SequenceStepKind,
} from "@/lib/types";

interface Props {
  config: BranchConfig;
  onChange: (config: BranchConfig) => void;
  /** depth > 0 означает вложенный вызов — запрещаем if_else внутри */
  depth?: number;
}

const FIELD_OPTIONS: { value: BranchConditionField; label: string }[] = [
  { value: "deal_amount", label: "Сумма сделки" },
  { value: "lead_score", label: "Скор лида" },
  { value: "stage_name", label: "Название этапа" },
  { value: "assigned_user_id", label: "Ответственный (ID)" },
];

type OperatorOption = { value: BranchConditionOperator; label: string };

const NUMERIC_OPERATORS: OperatorOption[] = [
  { value: "eq", label: "равно" },
  { value: "neq", label: "не равно" },
  { value: "gt", label: "больше" },
  { value: "lt", label: "меньше" },
];

const STRING_OPERATORS: OperatorOption[] = [
  { value: "eq", label: "равно" },
  { value: "neq", label: "не равно" },
  { value: "contains", label: "содержит" },
];

const ID_OPERATORS: OperatorOption[] = [
  { value: "eq", label: "равно" },
  { value: "neq", label: "не равно" },
];

function getOperators(field: BranchConditionField): OperatorOption[] {
  if (field === "deal_amount" || field === "lead_score") return NUMERIC_OPERATORS;
  if (field === "assigned_user_id") return ID_OPERATORS;
  return STRING_OPERATORS;
}

const STEP_KINDS_FLAT: { value: SequenceStepKind; label: string }[] = [
  { value: "wait", label: "Задержка" },
  { value: "tg_notify", label: "Telegram-уведомление" },
  { value: "email", label: "Email" },
  { value: "create_task", label: "Создать задачу" },
];

function defaultFlatConfig(kind: SequenceStepKind): Record<string, unknown> {
  if (kind === "tg_notify") return { recipient: "owner", message: "" };
  if (kind === "email") return { subject: "", body: "" };
  if (kind === "create_task") return { title: "", responsible: "owner" };
  return {};
}

/** Мини-редактор конфига шага внутри ветки (без if_else) */
function FlatStepConfigBlock({
  kind,
  config,
  onChange,
}: {
  kind: SequenceStepKind;
  config: Record<string, unknown>;
  onChange: (next: Record<string, unknown>) => void;
}) {
  if (kind === "wait" || kind === "if_else") return null;

  if (kind === "tg_notify") {
    const recipient = typeof config.recipient === "string" ? config.recipient : "owner";
    const message = typeof config.message === "string" ? config.message : "";
    return (
      <div className="space-y-1.5 mt-2">
        <select
          className="input text-sm py-1"
          value={recipient}
          onChange={(e) => onChange({ ...config, recipient: e.target.value })}
        >
          <option value="owner">Ответственному</option>
        </select>
        <textarea
          className="input text-sm"
          rows={2}
          value={message}
          onChange={(e) => onChange({ ...config, message: e.target.value })}
          placeholder="Текст уведомления..."
        />
      </div>
    );
  }

  if (kind === "email") {
    return (
      <div className="space-y-1.5 mt-2">
        <input
          className="input text-sm"
          type="text"
          value={typeof config.subject === "string" ? config.subject : ""}
          onChange={(e) => onChange({ ...config, subject: e.target.value })}
          placeholder="Тема письма"
        />
        <textarea
          className="input text-sm"
          rows={2}
          value={typeof config.body === "string" ? config.body : ""}
          onChange={(e) => onChange({ ...config, body: e.target.value })}
          placeholder="Текст письма (Jinja-синтаксис)"
        />
      </div>
    );
  }

  if (kind === "create_task") {
    return (
      <div className="space-y-1.5 mt-2">
        <input
          className="input text-sm"
          type="text"
          value={typeof config.title === "string" ? config.title : ""}
          onChange={(e) => onChange({ ...config, title: e.target.value })}
          placeholder="Название задачи"
        />
        <select
          className="input text-sm py-1"
          value={typeof config.responsible === "string" ? config.responsible : "owner"}
          onChange={(e) => onChange({ ...config, responsible: e.target.value })}
        >
          <option value="owner">Ответственный за цель</option>
        </select>
      </div>
    );
  }

  return null;
}

/** Вложенный мини-список шагов для одной ветки */
function BranchBranchSteps({
  steps,
  onChange,
  borderClass,
}: {
  steps: SequenceStep[];
  onChange: (steps: SequenceStep[]) => void;
  borderClass: string;
}) {
  function addStep() {
    const nextOrder = steps.length > 0 ? Math.max(...steps.map((s) => s.order)) + 1 : 1;
    onChange([...steps, { order: nextOrder, kind: "wait", delay_days: 1, config: {} }]);
  }

  function removeStep(idx: number) {
    onChange(steps.filter((_, i) => i !== idx).map((s, i) => ({ ...s, order: i + 1 })));
  }

  function updateStep(idx: number, patch: Partial<SequenceStep>) {
    onChange(steps.map((s, i) => (i === idx ? { ...s, ...patch } : s)));
  }

  function changeKind(idx: number, rawKind: string) {
    const kind = rawKind as SequenceStepKind;
    if (kind === "if_else") {
      // Запрещена вложенность
      return;
    }
    updateStep(idx, { kind, config: defaultFlatConfig(kind) });
  }

  return (
    <div className={`space-y-2 pl-3 ${borderClass}`}>
      {steps.length === 0 && (
        <div className="text-xs text-gray-400 italic py-2">Нет шагов</div>
      )}
      {steps.map((step, idx) => (
        <div
          key={idx}
          className="border border-gray-200 dark:border-gray-600 rounded-md p-2.5 bg-white dark:bg-gray-800"
        >
          <div className="flex items-center gap-2 flex-wrap">
            <span className="text-xs text-gray-500">Шаг {idx + 1}</span>
            <select
              className="input text-sm py-1"
              value={step.kind}
              onChange={(e) => changeKind(idx, e.target.value)}
            >
              {STEP_KINDS_FLAT.map((k) => (
                <option key={k.value} value={k.value}>
                  {k.label}
                </option>
              ))}
            </select>
            {step.kind !== "if_else" && (
              <div className="flex items-center gap-1">
                <span className="text-xs text-gray-500">Задержка:</span>
                <input
                  type="number"
                  min={0}
                  className="input text-sm py-1 w-16"
                  value={step.delay_days}
                  onChange={(e) =>
                    updateStep(idx, { delay_days: Math.max(0, Number(e.target.value) || 0) })
                  }
                />
                <span className="text-xs text-gray-500">дн.</span>
              </div>
            )}
            <button
              type="button"
              className="btn-ghost text-danger p-1 ml-auto"
              onClick={() => removeStep(idx)}
            >
              <i className="bi bi-x-lg text-xs" />
            </button>
          </div>
          <FlatStepConfigBlock
            kind={step.kind}
            config={step.config}
            onChange={(next) => updateStep(idx, { config: next })}
          />
          {step.kind === "if_else" && (
            <div className="text-warning text-xs mt-1">
              <i className="bi bi-exclamation-triangle mr-1" />
              Вложенные ветки пока не поддерживаются
            </div>
          )}
        </div>
      ))}
      <button type="button" className="btn-ghost text-sm w-full" onClick={addStep}>
        <i className="bi bi-plus-lg mr-1" />+ Добавить шаг
      </button>
    </div>
  );
}

export function BranchStepBlock({ config, onChange, depth = 0 }: Props) {
  const { condition, true_steps, false_steps } = config;

  const operators = getOperators(condition.field);

  // Если текущий оператор недоступен для нового поля — сбросить на первый
  function handleFieldChange(field: BranchConditionField) {
    const ops = getOperators(field);
    const opExists = ops.some((o) => o.value === condition.operator);
    onChange({
      ...config,
      condition: {
        ...condition,
        field,
        operator: opExists ? condition.operator : ops[0].value,
      },
    });
  }

  function handleOperatorChange(operator: BranchConditionOperator) {
    onChange({ ...config, condition: { ...condition, operator } });
  }

  function handleValueChange(value: string) {
    onChange({ ...config, condition: { ...condition, value } });
  }

  const showValueError = !condition.value.trim();
  const showBranchWarning = true_steps.length === 0 && false_steps.length === 0;

  return (
    <div className="space-y-4 mt-2">
      {/* Условие */}
      <div>
        <div className="text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase mb-2">
          Условие
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <select
            className="input text-sm py-1"
            value={condition.field}
            onChange={(e) => handleFieldChange(e.target.value as BranchConditionField)}
          >
            {FIELD_OPTIONS.map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </select>

          <select
            className="input text-sm py-1"
            value={condition.operator}
            onChange={(e) => handleOperatorChange(e.target.value as BranchConditionOperator)}
          >
            {operators.map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </select>

          <input
            type="text"
            className="input text-sm py-1 w-32"
            value={condition.value}
            onChange={(e) => handleValueChange(e.target.value)}
            placeholder="значение"
          />
        </div>

        {showValueError && (
          <div className="text-xs text-danger mt-1">Укажи значение для сравнения</div>
        )}
      </div>

      {showBranchWarning && (
        <div className="text-xs text-warning">
          <i className="bi bi-exclamation-triangle mr-1" />
          Хотя бы одна ветка должна содержать действие
        </div>
      )}

      {depth === 0 && (
        <div className="text-xs text-gray-500">
          <i className="bi bi-info-circle mr-1" />
          Вложенные шаги не поддерживают delay
        </div>
      )}

      {/* True branch */}
      <div>
        <div className="text-xs font-semibold text-success uppercase mb-2 flex items-center gap-1">
          <i className="bi bi-check-circle" />
          Если истина
        </div>
        <BranchBranchSteps
          steps={true_steps}
          onChange={(steps) => onChange({ ...config, true_steps: steps })}
          borderClass="border-l-4 border-success/40 rounded-l"
        />
      </div>

      {/* False branch */}
      <div>
        <div className="text-xs font-semibold text-gray-500 uppercase mb-2 flex items-center gap-1">
          <i className="bi bi-dash-circle" />
          Иначе
        </div>
        <BranchBranchSteps
          steps={false_steps}
          onChange={(steps) => onChange({ ...config, false_steps: steps })}
          borderClass="border-l-4 border-gray-200 rounded-l"
        />
      </div>
    </div>
  );
}
