"use client";

import Link from "next/link";
import useSWR from "swr";
import type { CourseProgress, CourseProgressStatus } from "@/lib/types";
import { fetcher } from "@/lib/api";
import { PageHeader } from "@/components/PageHeader";
import { EmptyState } from "@/components/EmptyState";
import puzzleIcon from "@/lib/lordicon/puzzle.json";

// ─── Badge configs ────────────────────────────────────────────────────────────

const STATUS_LABELS: Record<CourseProgressStatus, string> = {
  not_started: "Не начат",
  in_progress:  "В процессе",
  completed:    "Завершён",
  overdue:      "Просрочен",
};

const STATUS_BADGE: Record<CourseProgressStatus, string> = {
  not_started: "bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400",
  in_progress:  "bg-info-50   dark:bg-info-500/10   text-info-700   dark:text-info-500",
  completed:    "bg-success-50 dark:bg-success-500/10 text-success-700 dark:text-success-500",
  overdue:      "bg-danger-50  dark:bg-danger-500/10  text-danger-700  dark:text-danger-500",
};

const STATUS_BAR: Record<CourseProgressStatus, string> = {
  not_started: "bg-gray-300 dark:bg-gray-600",
  in_progress:  "bg-info-500",
  completed:    "bg-success-500",
  overdue:      "bg-danger-500",
};

// ─── Skeleton card ────────────────────────────────────────────────────────────

function CourseCardSkeleton() {
  return (
    <div className="rounded-2xl bg-white dark:bg-gray-800/60 border border-gray-200 dark:border-white/10 shadow-elev-1 p-5 flex flex-col gap-3 animate-pulse">
      <div className="h-32 rounded-xl bg-gray-100 dark:bg-gray-700" />
      <div className="flex items-center justify-between gap-2">
        <div className="h-4 bg-gray-200 dark:bg-gray-700 rounded w-3/4" />
        <div className="h-5 bg-gray-100 dark:bg-gray-700 rounded-full w-16 shrink-0" />
      </div>
      <div className="h-3 bg-gray-100 dark:bg-gray-700 rounded w-full" />
      <div className="h-3 bg-gray-100 dark:bg-gray-700 rounded w-2/3" />
      <div className="h-1.5 bg-gray-100 dark:bg-gray-700 rounded-full w-full mt-1" />
      <div className="h-8 bg-gray-100 dark:bg-gray-700 rounded-lg mt-auto" />
    </div>
  );
}

// ─── Course card ──────────────────────────────────────────────────────────────

function CourseCard({ progress }: { progress: CourseProgress }) {
  const badgeCls  = STATUS_BADGE[progress.status];
  const barCls    = STATUS_BAR[progress.status];
  const label     = STATUS_LABELS[progress.status];

  const isDue = progress.status !== "completed" && progress.due_at;
  const daysLeft = isDue
    ? Math.ceil((new Date(progress.due_at!).getTime() - Date.now()) / 86400000)
    : null;

  return (
    <div className="lift rounded-2xl bg-white dark:bg-gray-800/60 border border-gray-200 dark:border-white/10 shadow-elev-1 p-5 flex flex-col gap-3">
      {progress.cover_image_url && (
        /* eslint-disable-next-line @next/next/no-img-element */
        <img
          src={progress.cover_image_url}
          alt=""
          className="w-full h-32 object-cover rounded-xl"
        />
      )}

      {/* Title + status */}
      <div className="flex items-start justify-between gap-2">
        <h3 className="font-semibold text-gray-900 dark:text-gray-100 leading-snug text-sm">
          {progress.course_title}
        </h3>
        <span className={`shrink-0 inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${badgeCls}`}>
          {label}
        </span>
      </div>

      {progress.description && (
        <p className="text-xs text-gray-500 dark:text-gray-400 leading-relaxed line-clamp-2">
          {progress.description}
        </p>
      )}

      {/* Progress bar */}
      <div>
        <div className="flex items-center justify-between mb-1.5">
          <span className="text-xs text-gray-400 dark:text-gray-500">Прогресс</span>
          <span className="text-xs font-semibold tabular-nums text-gray-600 dark:text-gray-300">
            {progress.percent}%
          </span>
        </div>
        <div className="h-1.5 rounded-full bg-gray-100 dark:bg-gray-700">
          <div
            className={`h-full rounded-full ${barCls} transition-[width] duration-500`}
            style={{ width: `${progress.percent}%` }}
          />
        </div>
      </div>

      {/* Meta row */}
      <div className="flex flex-wrap items-center gap-2 text-xs">
        {progress.is_mandatory && (
          <span className="inline-flex items-center rounded-full bg-warning-50 dark:bg-warning-500/10 text-warning-700 dark:text-warning-400 px-2.5 py-0.5 font-medium">
            Обязательный
          </span>
        )}
        {isDue && daysLeft !== null && (
          <span className={`inline-flex items-center gap-1 ${daysLeft < 0 ? "text-danger-600 dark:text-danger-500" : "text-gray-400 dark:text-gray-500"}`}>
            <i className="bi bi-clock" aria-hidden="true" />
            {daysLeft < 0
              ? `Просрочено ${Math.abs(daysLeft)} дн.`
              : `${daysLeft} дн. до дедлайна`}
          </span>
        )}
        {progress.completed_at && (
          <span className="inline-flex items-center gap-1 text-success-600 dark:text-success-500">
            <i className="bi bi-check-circle-fill" aria-hidden="true" />
            {new Date(progress.completed_at).toLocaleDateString("ru-RU", { day: "2-digit", month: "2-digit", year: "numeric" })}
          </span>
        )}
      </div>

      {/* CTA */}
      <Link
        href={`/onboarding/courses/${progress.course_id}`}
        className="btn-primary text-sm text-center justify-center mt-auto"
      >
        {progress.status === "completed"
          ? <>
              <i className="bi bi-eye mr-1.5" aria-hidden="true" />
              Посмотреть
            </>
          : progress.status === "not_started"
          ? <>
              <i className="bi bi-play-circle-fill mr-1.5" aria-hidden="true" />
              Начать
            </>
          : <>
              <i className="bi bi-arrow-right mr-1.5" aria-hidden="true" />
              Продолжить
            </>}
      </Link>
    </div>
  );
}

// ─── Section wrapper ──────────────────────────────────────────────────────────

interface SectionProps {
  title: React.ReactNode;
  courses: CourseProgress[];
}

function Section({ title, courses }: SectionProps) {
  return (
    <section>
      <h2 className="text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500 mb-3 flex items-center gap-2">
        {title}
      </h2>
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        {courses.map((c) => <CourseCard key={c.course_id} progress={c} />)}
      </div>
    </section>
  );
}

// ─── Page ─────────────────────────────────────────────────────────────────────

export default function OnboardingPage() {
  const { data: courses, isLoading } = useSWR<CourseProgress[]>("/onboarding/my-courses", fetcher);

  const overdue     = courses?.filter((c) => c.status === "overdue")      ?? [];
  const inProgress  = courses?.filter((c) => c.status === "in_progress")  ?? [];
  const notStarted  = courses?.filter((c) => c.status === "not_started")  ?? [];
  const completed   = courses?.filter((c) => c.status === "completed")    ?? [];

  return (
    <div className="p-8 max-w-5xl mx-auto">
      <PageHeader title="Моё обучение" description="Курсы, назначенные тебе для прохождения" />

      <div className="mt-6">
        {/* Skeleton */}
        {isLoading && (
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            {Array.from({ length: 3 }).map((_, i) => <CourseCardSkeleton key={i} />)}
          </div>
        )}

        {/* Empty */}
        {!isLoading && (!courses || courses.length === 0) && (
          <EmptyState
            icon="bi-mortarboard-fill"
            title="Курсов пока нет"
            description="Как только администратор назначит обучение — оно появится здесь"
            lordIcon={{ icon: puzzleIcon, trigger: "loop", size: 72 }}
          />
        )}

        {/* Content */}
        {!isLoading && courses && courses.length > 0 && (
          <div className="space-y-10">
            {overdue.length > 0 && (
              <Section
                title={
                  <>
                    <i className="bi bi-exclamation-triangle-fill text-danger-500" aria-hidden="true" />
                    <span className="text-danger-600 dark:text-danger-500">Просроченные</span>
                    <span className="ml-1 inline-flex items-center justify-center rounded-full bg-danger-50 dark:bg-danger-500/10 text-danger-600 dark:text-danger-500 text-[10px] font-bold w-5 h-5">
                      {overdue.length}
                    </span>
                  </>
                }
                courses={overdue}
              />
            )}

            {inProgress.length > 0 && (
              <Section
                title={
                  <>
                    <i className="bi bi-play-circle-fill text-info-500" aria-hidden="true" />
                    <span className="text-info-600 dark:text-info-400">В процессе</span>
                    <span className="ml-1 inline-flex items-center justify-center rounded-full bg-info-50 dark:bg-info-500/10 text-info-600 dark:text-info-500 text-[10px] font-bold w-5 h-5">
                      {inProgress.length}
                    </span>
                  </>
                }
                courses={inProgress}
              />
            )}

            {notStarted.length > 0 && (
              <Section
                title={<span className="text-gray-500 dark:text-gray-400">Не начатые ({notStarted.length})</span>}
                courses={notStarted}
              />
            )}

            {completed.length > 0 && (
              <Section
                title={
                  <>
                    <i className="bi bi-check-circle-fill text-success-500" aria-hidden="true" />
                    <span className="text-success-600 dark:text-success-400">Завершённые</span>
                    <span className="ml-1 inline-flex items-center justify-center rounded-full bg-success-50 dark:bg-success-500/10 text-success-600 dark:text-success-500 text-[10px] font-bold w-5 h-5">
                      {completed.length}
                    </span>
                  </>
                }
                courses={completed}
              />
            )}
          </div>
        )}
      </div>
    </div>
  );
}
