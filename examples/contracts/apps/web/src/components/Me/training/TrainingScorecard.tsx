"use client";

import type { ColdCallScores } from "@/lib/types";

interface Props {
  score: number;
  scores: ColdCallScores;
  feedback: string;
  recommendations?: string[];
  goodDecisions?: string[];
  onNewTraining?: () => void;
}

const SCORE_LABELS: (keyof ColdCallScores)[] = [
  "speech_clarity",
  "empathy",
  "objection_handling",
  "deal_closing",
];

const SCORE_DISPLAY_LABELS: Record<keyof ColdCallScores, string> = {
  speech_clarity: "Чёткость речи",
  empathy: "Эмпатия",
  objection_handling: "Работа с возражениями",
  deal_closing: "Закрытие",
};

export function TrainingScorecard({
  score,
  scores,
  feedback,
  recommendations,
  goodDecisions,
  onNewTraining,
}: Props) {
  const stars = Math.round((score / 10) * 5);

  return (
    <div className="card p-6 max-w-2xl mx-auto">
      <h3 className="text-h4 mb-6 text-center">Результаты звонка</h3>

      {/* Overall score */}
      <div className="text-center mb-6">
        <div className="flex justify-center gap-1 mb-2">
          {[1, 2, 3, 4, 5].map((i) => (
            <i
              key={i}
              className={`bi bi-star${i <= stars ? "-fill" : ""} text-warning text-2xl`}
            />
          ))}
        </div>
        <span className="text-4xl font-bold text-primary">{(score ?? 0).toFixed(1)}</span>
        <span className="text-gray-400 text-xl ml-1">/ 10</span>
      </div>

      {/* Sub-scores */}
      <div className="grid grid-cols-2 gap-4 mb-6">
        {SCORE_LABELS.map((key) => {
          const val = scores[key];
          const pct = (val / 10) * 100;
          return (
            <div key={key} className="card p-4">
              <div className="flex justify-between text-sm mb-2">
                <span className="font-medium">{SCORE_DISPLAY_LABELS[key]}</span>
                <span className="text-primary font-semibold">{val}/10</span>
              </div>
              <div className="h-2 rounded-full bg-gray-100 dark:bg-gray-700">
                <div
                  className={`h-full rounded-full ${pct >= 70 ? "bg-success" : pct >= 50 ? "bg-warning" : "bg-danger"}`}
                  style={{ width: `${pct}%` }}
                />
              </div>
            </div>
          );
        })}
      </div>

      {/* Good decisions — highlighted */}
      {goodDecisions && goodDecisions.length > 0 && (
        <div className="rounded-lg border border-success/40 bg-success/10 p-4 mb-6">
          <div className="flex items-center gap-2 text-sm font-semibold text-success mb-2">
            <i className="bi bi-check-circle-fill" />
            Удачные решения
          </div>
          <ul className="space-y-1.5">
            {goodDecisions.map((g, i) => (
              <li key={i} className="flex items-start gap-2 text-sm text-gray-700 dark:text-gray-200">
                <i className="bi bi-hand-thumbs-up-fill text-success mt-0.5 shrink-0" />
                <span>{g}</span>
              </li>
            ))}
          </ul>
        </div>
      )}

      {/* Feedback */}
      {feedback && (
        <div className="card p-4 mb-6 bg-blue-50 dark:bg-blue-900/20">
          <div className="text-sm font-medium mb-2">Обратная связь от ИИ</div>
          <p className="text-sm text-gray-600 dark:text-gray-400 whitespace-pre-line">{feedback}</p>
        </div>
      )}

      {/* Recommendations */}
      {recommendations && recommendations.length > 0 && (
        <div className="card p-4 mb-6">
          <div className="text-sm font-medium mb-2">Рекомендации</div>
          <ul className="space-y-1.5">
            {recommendations.map((r, i) => (
              <li key={i} className="flex items-start gap-2 text-sm text-gray-600 dark:text-gray-400">
                <i className="bi bi-arrow-right-circle text-primary mt-0.5 shrink-0" />
                <span>{r}</span>
              </li>
            ))}
          </ul>
        </div>
      )}

      {/* Actions */}
      {onNewTraining && (
        <div className="flex justify-end gap-2">
          <button onClick={onNewTraining} className="btn-primary">
            <i className="bi bi-arrow-repeat mr-1" />
            Новая тренировка
          </button>
        </div>
      )}
    </div>
  );
}
