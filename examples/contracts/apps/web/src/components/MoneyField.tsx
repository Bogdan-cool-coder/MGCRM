"use client";

import clsx from "clsx";
import { useState } from "react";

/** Форматирование числа с пробелами как разделителями тысяч: 200000 → "200 000" */
export function formatMoney(value: string | number): string {
  const cleaned = String(value).replace(/[^\d.,]/g, "").replace(",", ".");
  if (!cleaned) return "";
  const [intPart, fracPart] = cleaned.split(".");
  const formatted = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, " ");
  return fracPart ? `${formatted},${fracPart}` : formatted;
}

export function unformatMoney(value: string): string {
  return value.replace(/\s/g, "").replace(",", ".");
}

export function MoneyField({
  label, value, onChange, currency, required, hint, onBlur,
}: {
  label: string;
  value: string;
  onChange: (v: string) => void;
  currency?: string;
  required?: boolean;
  hint?: React.ReactNode;
  onBlur?: () => void;
}) {
  const [touched, setTouched] = useState(false);
  const invalid = required && touched && !value.trim();
  const display = formatMoney(value);

  return (
    <div>
      <label className="label">
        {label} {required && <span className="text-danger">*</span>}
      </label>
      <div className="relative">
        <input
          type="text"
          inputMode="decimal"
          className={clsx(
            "input pr-12",
            invalid && "border-danger focus:border-danger focus:ring-danger",
          )}
          value={display}
          onChange={(e) => onChange(unformatMoney(e.target.value))}
          onBlur={() => { setTouched(true); onBlur?.(); }}
          placeholder="200 000"
        />
        {currency && (
          <span className="absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 text-sm pointer-events-none">
            {currency}
          </span>
        )}
      </div>
      {invalid && <div className="text-xs text-danger mt-1">Обязательное поле</div>}
      {hint && !invalid && <div className="text-xs text-gray-500 mt-1">{hint}</div>}
    </div>
  );
}
