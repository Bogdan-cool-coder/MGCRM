"use client";

import clsx from "clsx";
import type { DepartmentScope } from "@/lib/types";

interface VisibilityScopeSelectProps {
  value: DepartmentScope;
  onChange: (v: DepartmentScope) => void;
  disabled?: boolean;
}

const SCOPE_OPTIONS: { value: DepartmentScope; label: string }[] = [
  { value: "all", label: "Все" },
  { value: "personal", label: "Только свои" },
  { value: "department", label: "Свой отдел" },
  { value: "department_and_children", label: "Отдел и дочерние" },
];

const SCOPE_BORDER: Record<DepartmentScope, string> = {
  all: "",
  personal: "border-warning focus:border-warning",
  department: "border-info focus:border-info",
  department_and_children: "border-primary focus:border-primary",
};

export function VisibilityScopeSelect({
  value,
  onChange,
  disabled = false,
}: VisibilityScopeSelectProps) {
  return (
    <select
      className={clsx(
        "input text-sm py-1.5 px-2",
        SCOPE_BORDER[value],
      )}
      value={value}
      onChange={(e) => onChange(e.target.value as DepartmentScope)}
      disabled={disabled}
    >
      {SCOPE_OPTIONS.map((opt) => (
        <option key={opt.value} value={opt.value}>
          {opt.label}
        </option>
      ))}
    </select>
  );
}
