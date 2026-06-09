"use client";

import clsx from "clsx";
import { useState } from "react";

export function TextareaField({
  label, value, onChange, required, rows = 3, placeholder, hint,
}: {
  label: string;
  value: string;
  onChange: (v: string) => void;
  required?: boolean;
  rows?: number;
  placeholder?: string;
  hint?: React.ReactNode;
}) {
  const [touched, setTouched] = useState(false);
  const invalid = required && touched && !value.trim();

  return (
    <div>
      <label className="label">
        {label} {required && <span className="text-danger">*</span>}
      </label>
      <textarea
        className={clsx("input", invalid && "border-danger focus:border-danger focus:ring-danger")}
        rows={rows}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        onBlur={() => setTouched(true)}
        placeholder={placeholder}
      />
      {invalid && <div className="text-xs text-danger mt-1">Обязательное поле</div>}
      {hint && !invalid && <div className="text-xs text-gray-500 mt-1">{hint}</div>}
    </div>
  );
}
