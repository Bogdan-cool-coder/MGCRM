"use client";

import Link from "next/link";
import useSWR from "swr";
import { fetcher } from "@/lib/api";
import type { OnboardingHardQuestion } from "@/lib/types";

function successRateColor(rate: number | null | undefined): string {
  const r = rate ?? 0;
  if (r < 40) return "bg-danger";
  if (r < 60) return "bg-warning";
  return "bg-success";
}

function successRateTextColor(rate: number | null | undefined): string {
  const r = rate ?? 0;
  if (r < 40) return "text-danger";
  if (r < 60) return "text-warning";
  return "text-success";
}

export function HardQuestionsList() {
  const { data, isLoading, error } = useSWR<OnboardingHardQuestion[]>(
    "/admin/onboarding/analytics/hard-questions?limit=5",
    fetcher
  );

  if (isLoading) {
    return (
      <div className="card p-5 mb-6">
        <div className="h-5 bg-gray-200 dark:bg-gray-700 rounded w-64 mb-4 animate-pulse" />
        <div className="flex flex-col gap-3">
          {Array.from({ length: 5 }).map((_, i) => (
            <div key={i} className="animate-pulse h-20 bg-gray-100 dark:bg-gray-700 rounded-lg" />
          ))}
        </div>
      </div>
    );
  }

  if (error) {
    return (
      <div className="card p-5 mb-6">
        <h3 className="text-h5 mb-4">Топ-5 самых сложных вопросов</h3>
        <p className="text-danger text-sm py-2">Не удалось загрузить вопросы</p>
      </div>
    );
  }

  const questions = data ?? [];

  return (
    <div className="card p-5 mb-6">
      <h3 className="text-h5 mb-1">Топ-5 самых сложных вопросов</h3>
      <p className="text-xs text-gray-400 dark:text-gray-500 mb-4">
        Вопросы с наименьшим процентом правильных ответов
      </p>

      {questions.length === 0 ? (
        <p className="text-sm text-gray-400 dark:text-gray-500 py-4 text-center">
          Данных о сложных вопросах пока нет — quiz ещё не проходили
        </p>
      ) : (
        <div className="flex flex-col gap-3">
          {questions.map((q, idx) => {
            const barColor = successRateColor(q.success_rate_pct);
            const textColor = successRateTextColor(q.success_rate_pct);
            return (
              <div key={q.question_id} className="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                {/* Header */}
                <div className="flex items-start justify-between gap-3">
                  <div className="flex items-start gap-2">
                    <span className="text-xs font-bold text-gray-400 dark:text-gray-500 mt-0.5 w-5 flex-shrink-0">
                      #{idx + 1}
                    </span>
                    <span className="text-sm font-medium text-gray-800 dark:text-gray-200 leading-snug">
                      {q.question_text}
                    </span>
                  </div>
                  <Link
                    href={`/admin/onboarding/courses/${q.course_id}/edit`}
                    className="btn-ghost text-xs flex items-center gap-1 flex-shrink-0"
                  >
                    Открыть урок
                    <i className="bi bi-arrow-right text-xs" />
                  </Link>
                </div>

                {/* Course / lesson */}
                <p className="text-xs text-gray-500 dark:text-gray-400 mt-1 ml-7">
                  {q.course_title} / {q.lesson_title}
                </p>

                {/* Progress row */}
                <div className="mt-2 ml-7 flex items-center gap-3">
                  <div className="flex-1 h-1.5 bg-gray-100 dark:bg-gray-700 rounded-full overflow-hidden">
                    <div
                      className={`h-full rounded-full ${barColor}`}
                      style={{ width: `${q.success_rate_pct}%` }}
                    />
                  </div>
                  <span className={`text-xs tabular-nums font-medium ${textColor}`}>
                    {(q.success_rate_pct ?? 0).toFixed(0)}% успешных
                  </span>
                  <span className="text-gray-300 dark:text-gray-600 text-xs mx-1">·</span>
                  <span className="text-xs text-gray-500 dark:text-gray-400">
                    {q.total_attempts} попыток
                  </span>
                </div>
              </div>
            );
          })}
        </div>
      )}
    </div>
  );
}
