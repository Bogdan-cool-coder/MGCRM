import clsx from "clsx";
import type { AIConfidence } from "@/lib/types";

const STYLES: Record<AIConfidence, { className: string; label: string }> = {
  high: { className: "bg-success/10 text-success", label: "Высокая" },
  medium: { className: "bg-warning/10 text-warning", label: "Средняя" },
  low: { className: "bg-gray-100 text-gray-500", label: "Низкая" },
};

export function ConfidenceBadge({ confidence }: { confidence: AIConfidence }) {
  const s = STYLES[confidence] ?? STYLES.low;
  return (
    <span
      className={clsx(
        "inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium",
        s.className,
      )}
    >
      {s.label}
    </span>
  );
}
