"use client";

import { useState } from "react";
import useSWR from "swr";
import { fetcher } from "@/lib/api";
import type {
  Course,
  OnboardingFunnelResponse,
  OnboardingFunnelStep,
  OnboardingFunnelStepKey,
} from "@/lib/types";

interface DrillDownProps {
  courseId: number;
  stepKey: OnboardingFunnelStepKey;
  count: number;
}

function DrillDown({ courseId, stepKey, count }: DrillDownProps) {
  const url = `/admin/onboarding/analytics/funnel/${courseId}?step=${stepKey}&include_users=true`;
  const { data, isLoading, error } = useSWR<OnboardingFunnelResponse>(url, fetcher);

  if (isLoading) {
    return (
      <div className="rounded-lg border border-gray-100 dark:border-gray-700 bg-gray-50 dark:bg-gray-900 p-3 mt-1 mb-2">
        <p className="text-xs text-gray-400">Загружаем список…</p>
      </div>
    );
  }

  if (error) {
    return (
      <div className="rounded-lg border border-gray-100 dark:border-gray-700 bg-gray-50 dark:bg-gray-900 p-3 mt-1 mb-2">
        <p className="text-xs text-danger">Не удалось загрузить список</p>
      </div>
    );
  }

  // Find step in response
  const step = data?.steps.find((s) => s.step_key === stepKey);
  const users = step?.users ?? [];

  return (
    <div className="rounded-lg border border-gray-100 dark:border-gray-700 bg-gray-50 dark:bg-gray-900 p-3 mt-1 mb-2">
      <p className="text-xs font-medium text-gray-600 dark:text-gray-400 mb-2">
        Застряли на этом этапе ({count} чел.)
      </p>
      {users.length === 0 ? (
        <p className="text-xs text-gray-400 dark:text-gray-500">Все ученики прошли дальше</p>
      ) : (
        <div className="flex flex-col gap-1.5">
          {users.map((u) => (
            <div key={u.user_id} className="flex items-center gap-2 text-xs">
              <span className="text-gray-800 dark:text-gray-200 font-medium">{u.user_name}</span>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

function FunnelRow({
  step,
  prevStep,
  isFirst,
  isExpanded,
  onToggle,
  courseId,
}: {
  step: OnboardingFunnelStep;
  prevStep: OnboardingFunnelStep | null;
  isFirst: boolean;
  isExpanded: boolean;
  onToggle: () => void;
  courseId: number;
}) {
  const delta =
    !isFirst && prevStep
      ? step.pct_of_total - prevStep.pct_of_total
      : null;

  return (
    <>
      <div
        className="flex items-center gap-3 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 rounded-md px-2 py-1.5 transition-colors"
        onClick={onToggle}
        role="button"
        tabIndex={0}
        onKeyDown={(e) => {
          if (e.key === "Enter" || e.key === " ") onToggle();
        }}
      >
        <span className="w-28 text-sm text-gray-700 dark:text-gray-300 flex-shrink-0">
          {step.step_label}
        </span>
        <div className="flex-1 h-4 bg-gray-100 dark:bg-gray-700 rounded overflow-hidden">
          <div
            className="h-full bg-primary-light rounded transition-all duration-300"
            style={{ width: `${step.pct_of_total}%` }}
          />
        </div>
        <span className="text-sm tabular-nums font-medium text-gray-800 dark:text-gray-200 w-10 text-right flex-shrink-0">
          {step.count}
        </span>
        <span className="text-sm tabular-nums text-gray-500 dark:text-gray-400 w-16 text-right flex-shrink-0">
          {(step.pct_of_total ?? 0).toFixed(1)}%
        </span>
        <span className="w-16 text-right flex-shrink-0">
          {delta !== null && (
            <span className="text-xs tabular-nums text-danger">
              {(delta ?? 0).toFixed(1)}%
            </span>
          )}
        </span>
      </div>

      {isExpanded && (
        <DrillDown
          courseId={courseId}
          stepKey={step.step_key}
          count={step.count}
        />
      )}
    </>
  );
}

export function DropOffFunnel() {
  const [selectedCourseId, setSelectedCourseId] = useState<string>("");
  const [expandedStep, setExpandedStep] = useState<OnboardingFunnelStepKey | null>(null);

  const { data: courses } = useSWR<Course[]>(
    "/admin/onboarding/courses?is_published=true",
    fetcher
  );

  const funnelKey = selectedCourseId
    ? `/admin/onboarding/analytics/funnel/${selectedCourseId}`
    : null;

  const { data: funnel, isLoading: funnelLoading, error: funnelError } = useSWR<OnboardingFunnelResponse>(
    funnelKey,
    fetcher
  );

  function toggleStep(key: OnboardingFunnelStepKey) {
    setExpandedStep((prev) => (prev === key ? null : key));
  }

  return (
    <div className="card p-5 mb-6">
      <div className="flex items-center justify-between gap-4 mb-1 flex-wrap">
        <div>
          <h3 className="text-h5">Воронка отвала</h3>
          <p className="text-xs text-gray-400 dark:text-gray-500 mt-0.5">
            Сколько учеников проходит каждый этап курса
          </p>
        </div>
        <select
          className="input text-sm py-1.5 w-auto min-w-[220px]"
          value={selectedCourseId}
          onChange={(e) => {
            setSelectedCourseId(e.target.value);
            setExpandedStep(null);
          }}
        >
          <option value="">Выбери курс</option>
          {(courses ?? []).map((c) => (
            <option key={c.id} value={String(c.id)}>
              {c.title}
            </option>
          ))}
        </select>
      </div>

      {!selectedCourseId && (
        <div className="py-8 text-center">
          <i className="bi bi-funnel text-3xl text-gray-300 dark:text-gray-600 mr-2" />
          <p className="text-sm text-gray-400 dark:text-gray-500 mt-2">
            Выбери курс, чтобы увидеть воронку отвала
          </p>
        </div>
      )}

      {selectedCourseId && funnelLoading && (
        <div className="mt-4 flex flex-col gap-2">
          {Array.from({ length: 5 }).map((_, i) => (
            <div key={i} className="animate-pulse h-6 bg-gray-100 dark:bg-gray-700 rounded" />
          ))}
        </div>
      )}

      {selectedCourseId && funnelError && (
        <p className="text-danger text-sm py-2 mt-4">Не удалось загрузить воронку</p>
      )}

      {selectedCourseId && !funnelLoading && !funnelError && funnel && (
        <>
          {funnel.steps.length === 0 || (funnel.steps[0]?.count ?? 0) === 0 ? (
            <div className="py-6 text-center">
              <i className="bi bi-funnel text-4xl text-gray-300 dark:text-gray-600" />
              <p className="text-sm text-gray-400 dark:text-gray-500 mt-2">
                У этого курса пока нет назначений
              </p>
            </div>
          ) : (
            <div className="flex flex-col gap-1 mt-4">
              {funnel.steps.map((step, idx) => (
                <FunnelRow
                  key={step.step_key}
                  step={step}
                  prevStep={idx > 0 ? funnel.steps[idx - 1] ?? null : null}
                  isFirst={idx === 0}
                  isExpanded={expandedStep === step.step_key}
                  onToggle={() => toggleStep(step.step_key)}
                  courseId={Number(selectedCourseId)}
                />
              ))}
            </div>
          )}
        </>
      )}
    </div>
  );
}
