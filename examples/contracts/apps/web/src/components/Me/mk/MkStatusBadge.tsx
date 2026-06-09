"use client";

interface Props {
  pct: number;
  className?: string;
}

export function MkStatusBadge({ pct, className = "" }: Props) {
  const rounded = Math.round(pct);
  let cls = "";
  if (pct >= 100) cls = "bg-success/10 text-success";
  else if (pct >= 80) cls = "bg-warning/10 text-warning";
  else cls = "bg-danger/10 text-danger";

  return (
    <span className={`badge ${cls} ${className}`}>
      {rounded}%
    </span>
  );
}
