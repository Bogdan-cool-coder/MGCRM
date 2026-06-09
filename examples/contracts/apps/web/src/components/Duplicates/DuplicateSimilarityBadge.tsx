"use client";

interface DuplicateSimilarityBadgeProps {
  score: number;
}

export function DuplicateSimilarityBadge({ score }: DuplicateSimilarityBadgeProps) {
  let cls = "text-xs font-semibold px-2 py-0.5 rounded-full ";
  if (score >= 90) {
    cls += "bg-danger/10 text-danger";
  } else if (score >= 70) {
    cls += "bg-warning/20 text-yellow-700";
  } else {
    cls += "bg-gray-100 text-gray-600";
  }
  return <span className={cls}>Схожесть: {score}%</span>;
}
