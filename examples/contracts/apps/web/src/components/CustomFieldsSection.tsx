"use client";

import { Field, SelectField } from "@/components/Field";
import { DateField } from "@/components/DateField";
import { TextareaField } from "@/components/TextareaField";
import type { TemplateVariable } from "@/lib/types";

type Values = Record<string, unknown>;

/**
 * Динамическая форма кастомных переменных шаблона.
 * Поля строятся по описанию TemplateVariable, группируются по `group`.
 * Значения пишутся в ctx.custom[key].
 */
export function CustomFieldsSection({
  variables, values, onChange, disabled,
}: {
  variables: TemplateVariable[];
  values: Values;
  onChange: (key: string, value: unknown) => void;
  disabled?: boolean;
}) {
  if (!variables.length) return null;

  // Группировка с сохранением порядка появления группы
  const groups: { name: string; vars: TemplateVariable[] }[] = [];
  for (const v of variables) {
    const name = v.group?.trim() || "Дополнительные поля";
    let g = groups.find((x) => x.name === name);
    if (!g) { g = { name, vars: [] }; groups.push(g); }
    g.vars.push(v);
  }

  return (
    <>
      {groups.map((g) => (
        <div key={g.name} className={"card p-5 " + (disabled ? "opacity-70" : "")}>
          <h3 className="text-h5 mb-4">{g.name}</h3>
          <fieldset disabled={disabled} className="grid grid-cols-1 md:grid-cols-2 gap-3">
            {g.vars.map((v) => (
              <CustomField key={v.id} v={v} value={values[v.key]} onChange={(val) => onChange(v.key, val)} />
            ))}
          </fieldset>
        </div>
      ))}
    </>
  );
}

function CustomField({
  v, value, onChange,
}: {
  v: TemplateVariable;
  value: unknown;
  onChange: (value: unknown) => void;
}) {
  const str = value === undefined || value === null ? "" : String(value);
  const hint = v.help_text || undefined;

  switch (v.var_type) {
    case "textarea":
      return (
        <div className="md:col-span-2">
          <TextareaField label={v.label} required={v.required} value={str} onChange={onChange} hint={hint} />
        </div>
      );
    case "number":
      return (
        <Field label={v.label} required={v.required} value={str} onChange={onChange}
               type="number" inputMode="decimal" hint={hint} placeholder={v.default_value ?? undefined} />
      );
    case "date":
      return <DateField label={v.label} required={v.required} value={str} onChange={onChange} hint={hint} />;
    case "select":
      return (
        <SelectField
          label={v.label}
          required={v.required}
          value={str}
          onChange={onChange}
          options={v.options.map((o) => ({ value: o, label: o }))}
          placeholder="— выберите —"
          hint={hint}
        />
      );
    case "checkbox": {
      const checked = value === true || value === "true" || value === "Да";
      return (
        <div className="flex flex-col justify-end">
          <label className="flex items-center gap-2 text-sm text-gray-800 cursor-pointer select-none py-2">
            <input
              type="checkbox"
              className="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary"
              checked={checked}
              onChange={(e) => onChange(e.target.checked)}
            />
            <span>{v.label}{v.required && <span className="text-danger"> *</span>}</span>
          </label>
          {hint && <div className="text-xs text-gray-500">{hint}</div>}
        </div>
      );
    }
    default:
      return (
        <Field label={v.label} required={v.required} value={str} onChange={onChange}
               hint={hint} placeholder={v.default_value ?? undefined} />
      );
  }
}
