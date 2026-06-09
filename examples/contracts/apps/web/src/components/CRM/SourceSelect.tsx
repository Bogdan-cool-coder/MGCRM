"use client";

import { CONTACT_SOURCE_OPTIONS } from "@/lib/types";

interface Props {
  value: string;
  onChange: (value: string) => void;
  className?: string;
  placeholder?: string;
  required?: boolean;
}

/** Переиспользуемый select «Источник» для контактов и компаний. */
export function SourceSelect({
  value,
  onChange,
  className = "input",
  placeholder = "Источник",
}: Props) {
  return (
    <select className={className} value={value} onChange={(e) => onChange(e.target.value)}>
      <option value="">{placeholder}</option>
      {CONTACT_SOURCE_OPTIONS.map((o) => (
        <option key={o.value} value={o.value}>{o.label}</option>
      ))}
    </select>
  );
}
