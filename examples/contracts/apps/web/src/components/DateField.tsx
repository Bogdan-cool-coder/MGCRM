"use client";

import clsx from "clsx";
import { useState } from "react";
import { isoToRu, ruToIso } from "@/lib/dates";

/** Поле даты: показывает native календарь + хранит значение в формате ДД.ММ.ГГГГ */
export function DateField({
  label, value, onChange, required, hint, disabled,
}: {
  label: string;
  /** ДД.ММ.ГГГГ */
  value: string;
  onChange: (v: string) => void;
  required?: boolean;
  hint?: React.ReactNode;
  disabled?: boolean;
}) {
  const [touched, setTouched] = useState(false);
  const invalid = required && touched && !value;

  return (
    <div>
      <label className="label">
        {label} {required && <span className="text-danger">*</span>}
      </label>
      <input
        type="date"
        className={clsx("input", invalid && "border-danger focus:border-danger focus:ring-danger")}
        value={ruToIso(value)}
        onChange={(e) => onChange(isoToRu(e.target.value))}
        onBlur={() => setTouched(true)}
        disabled={disabled}
      />
      {invalid && <div className="text-xs text-danger mt-1">Обязательное поле</div>}
      {hint && !invalid && <div className="text-xs text-gray-500 mt-1">{hint}</div>}
    </div>
  );
}
