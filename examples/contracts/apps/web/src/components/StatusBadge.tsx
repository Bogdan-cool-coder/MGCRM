import clsx from "clsx";
import { StatusLabels, type ContractStatus } from "@/lib/types";

export function StatusBadge({ status, className }: { status: ContractStatus; className?: string }) {
  const meta = StatusLabels[status];
  return (
    <span className={clsx(
      "inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium",
      meta.color,
      className,
    )}>
      <span className={clsx("w-1.5 h-1.5 rounded-full", meta.dot)} />
      {meta.label}
    </span>
  );
}
