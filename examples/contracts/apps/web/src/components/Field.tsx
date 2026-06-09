"use client";

import clsx from "clsx";
import { useState } from "react";

export interface FieldProps {
  label: string;
  value: string;
  onChange: (v: string) => void;
  required?: boolean;
  placeholder?: string;
  type?: "text" | "email" | "password" | "number" | "tel";
  hint?: React.ReactNode;
  onBlur?: () => void;
  inputMode?: "numeric" | "decimal" | "text" | "email";
  disabled?: boolean;
  autoComplete?: string;
}

export function Field({
  label, value, onChange, required, placeholder, type = "text",
  hint, onBlur, inputMode, disabled, autoComplete,
}: FieldProps) {
  const [touched, setTouched] = useState(false);
  const invalid = required && touched && !value.trim();

  return (
    <div>
      <label className="label">
        {label} {required && <span className="text-danger">*</span>}
      </label>
      <input
        type={type}
        className={clsx(
          "input",
          invalid && "border-danger focus:border-danger focus:ring-danger",
        )}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        onBlur={() => { setTouched(true); onBlur?.(); }}
        placeholder={placeholder}
        inputMode={inputMode}
        disabled={disabled}
        autoComplete={autoComplete}
      />
      {invalid && <div className="text-xs text-danger mt-1">Обязательное поле</div>}
      {hint && !invalid && <div className="text-xs text-gray-500 mt-1">{hint}</div>}
    </div>
  );
}


export interface SelectFieldProps<T extends string> {
  label: string;
  value: T;
  onChange: (v: T) => void;
  options: { value: T; label: string }[];
  required?: boolean;
  hint?: React.ReactNode;
  disabled?: boolean;
  placeholder?: string;
}

export function SelectField<T extends string>({
  label, value, onChange, options, required, hint, disabled, placeholder,
}: SelectFieldProps<T>) {
  const [touched, setTouched] = useState(false);
  const invalid = required && touched && !value;

  return (
    <div>
      <label className="label">
        {label} {required && <span className="text-danger">*</span>}
      </label>
      <select
        className={clsx(
          "input",
          invalid && "border-danger focus:border-danger focus:ring-danger",
        )}
        value={value}
        onChange={(e) => onChange(e.target.value as T)}
        onBlur={() => setTouched(true)}
        disabled={disabled}
      >
        {placeholder && <option value="">{placeholder}</option>}
        {options.map((o) => (
          <option key={o.value} value={o.value}>{o.label}</option>
        ))}
      </select>
      {invalid && <div className="text-xs text-danger mt-1">Обязательное поле</div>}
      {hint && !invalid && <div className="text-xs text-gray-500 mt-1">{hint}</div>}
    </div>
  );
}
