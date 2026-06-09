"use client";

import type { CalloutBlock, CalloutVariant } from "@/lib/types";

interface Props {
  block: CalloutBlock;
  onChange: (block: CalloutBlock) => void;
}

const VARIANT_OPTIONS: { value: CalloutVariant; label: string }[] = [
  { value: "info",    label: "Информация" },
  { value: "warning", label: "Предупреждение" },
  { value: "success", label: "Успех" },
  { value: "danger",  label: "Опасность" },
];

const CALLOUT_PREVIEW: Record<CalloutVariant, { border: string; bg: string; icon: string; iconColor: string }> = {
  info:    { border: "border-info",    bg: "bg-info/5",    icon: "bi-info-circle-fill",         iconColor: "text-info" },
  warning: { border: "border-warning", bg: "bg-warning/5", icon: "bi-exclamation-triangle-fill", iconColor: "text-warning" },
  success: { border: "border-success", bg: "bg-success/5", icon: "bi-check-circle-fill",         iconColor: "text-success" },
  danger:  { border: "border-danger",  bg: "bg-danger/5",  icon: "bi-exclamation-circle-fill",   iconColor: "text-danger" },
};

export function CalloutBlockEditor({ block, onChange }: Props) {
  const styles = CALLOUT_PREVIEW[block.variant];

  return (
    <div className="space-y-3">
      <div>
        <label className="label">Тип</label>
        <select
          className="input"
          value={block.variant}
          onChange={(e) => onChange({ ...block, variant: e.target.value as CalloutVariant })}
        >
          {VARIANT_OPTIONS.map((o) => (
            <option key={o.value} value={o.value}>{o.label}</option>
          ))}
        </select>
      </div>

      <div>
        <label className="label">Текст</label>
        <input
          className="input"
          value={block.text}
          onChange={(e) => onChange({ ...block, text: e.target.value })}
          placeholder="Обрати внимание на..."
        />
      </div>

      {block.text && (
        <div className={`border-l-4 ${styles.border} ${styles.bg} rounded-lg border p-3 text-sm flex gap-2 items-start`}>
          <i className={`bi ${styles.icon} ${styles.iconColor} text-base mt-0.5 shrink-0`} />
          <span>{block.text}</span>
        </div>
      )}
    </div>
  );
}
