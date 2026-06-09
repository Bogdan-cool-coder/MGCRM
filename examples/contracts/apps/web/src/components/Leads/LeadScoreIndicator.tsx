"use client";

interface LeadScoreIndicatorProps {
  score: number | null | undefined;
}

export function LeadScoreIndicator({ score }: LeadScoreIndicatorProps) {
  if (score == null) return null;
  if (score >= 70) {
    return <i className="bi bi-fire text-danger text-xs" title={`Оценка: ${score}`} />;
  }
  if (score >= 50) {
    return <i className="bi bi-circle-fill text-warning text-xs" title={`Оценка: ${score}`} />;
  }
  return null;
}
