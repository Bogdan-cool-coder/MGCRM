"use client";

interface Props {
  counted: boolean;
  missingCondition?: string | null;
}

export function FtmBadge({ counted, missingCondition }: Props) {
  if (counted) {
    return (
      <span className="badge bg-success/10 text-success" title="FTM зачтена">
        <i className="bi bi-award-fill mr-1" />
        FTM зачтена
      </span>
    );
  }
  return (
    <span
      className="badge bg-warning/10 text-warning"
      title={missingCondition ?? "Условие FTM не выполнено"}
    >
      <i className="bi bi-exclamation-triangle mr-1" />
      FTM не зачтена
    </span>
  );
}
