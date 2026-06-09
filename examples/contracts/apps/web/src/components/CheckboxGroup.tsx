"use client";

import clsx from "clsx";

export function CheckboxGroup({
  label, options, value, onChange, required,
}: {
  label: string;
  options: { value: string; label: string }[];
  value: string[];
  onChange: (v: string[]) => void;
  required?: boolean;
}) {
  const invalid = required && value.length === 0;

  function toggle(v: string) {
    if (value.includes(v)) {
      onChange(value.filter(x => x !== v));
    } else {
      onChange([...value, v]);
    }
  }

  function toggleAll() {
    if (value.length === options.length) {
      onChange([]);
    } else {
      onChange(options.map(o => o.value));
    }
  }

  return (
    <div>
      <div className="flex items-center justify-between mb-1">
        <label className="label mb-0">
          {label} {required && <span className="text-danger">*</span>}
        </label>
        <button type="button" onClick={toggleAll} className="text-xs text-primary hover:underline">
          {value.length === options.length ? "Снять все" : "Выбрать все"}
        </button>
      </div>
      <div className={clsx(
        "border rounded-md p-2 space-y-1 max-h-48 overflow-y-auto",
        invalid ? "border-danger" : "border-gray-200",
      )}>
        {options.map((o) => {
          const checked = value.includes(o.value);
          return (
            <label key={o.value} className="flex items-center gap-2 p-1.5 rounded cursor-pointer hover:bg-gray-100 text-sm">
              <input
                type="checkbox"
                checked={checked}
                onChange={() => toggle(o.value)}
                className="accent-primary"
              />
              <span>{o.label}</span>
            </label>
          );
        })}
      </div>
      {invalid && <div className="text-xs text-danger mt-1">Выберите хотя бы один</div>}
    </div>
  );
}
