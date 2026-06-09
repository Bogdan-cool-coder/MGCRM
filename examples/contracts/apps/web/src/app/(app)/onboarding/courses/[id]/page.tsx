"use client";

import { useState, useEffect, useCallback } from "react";
import { useParams, useRouter } from "next/navigation";
import useSWR from "swr";
import type {
  CourseWithModules,
  CourseLesson,
  LessonKind,
  QuizAttempt,
  QuizSubmitResponse,
} from "@/lib/types";
import { api, ApiError, fetcher } from "@/lib/api";
import { ContentBlockRenderer } from "@/components/Onboarding/Student/ContentBlockRenderer";
import { EmptyState } from "@/components/EmptyState";
import { LordIcon } from "@/components/ui/LordIcon";
import confettiIcon from "@/lib/lordicon/confetti.json";

// ─── Lesson Viewer ────────────────────────────────────────────────────────────

interface LessonViewerProps {
  lessonId: number;
  courseId: number;
  onCompleted: () => void;
}

function LessonViewerSkeleton() {
  return (
    <div className="animate-pulse space-y-4">
      <div className="flex items-center gap-2">
        <div className="h-5 bg-gray-200 dark:bg-gray-700 rounded-full w-20" />
        <div className="h-4 bg-gray-100 dark:bg-gray-700 rounded w-16" />
      </div>
      <div className="h-7 bg-gray-200 dark:bg-gray-700 rounded w-3/4" />
      <div className="aspect-video rounded-xl bg-gray-100 dark:bg-gray-700" />
      <div className="h-4 bg-gray-100 dark:bg-gray-700 rounded w-full" />
      <div className="h-4 bg-gray-100 dark:bg-gray-700 rounded w-5/6" />
    </div>
  );
}

function LessonViewer({ lessonId, courseId: _courseId, onCompleted }: LessonViewerProps) {
  const { data: lesson, isLoading } = useSWR(
    `/onboarding/lessons/${lessonId}`,
    fetcher
  );
  const [completing, setCompleting] = useState(false);
  const [completed, setCompleted] = useState(false);

  useEffect(() => {
    setCompleted(false);
  }, [lessonId]);

  const handleComplete = useCallback(async () => {
    setCompleting(true);
    try {
      await api(`/onboarding/lessons/${lessonId}/complete`, { method: "POST" });
      setCompleted(true);
      onCompleted();
    } finally {
      setCompleting(false);
    }
  }, [lessonId, onCompleted]);

  if (isLoading) return <LessonViewerSkeleton />;

  if (!lesson) {
    return <EmptyState icon="bi-exclamation-circle" title="Урок не найден" />;
  }

  const l = lesson as CourseLesson;

  if (l.kind === "quiz") {
    return <QuizPlayer lessonId={lessonId} onCompleted={onCompleted} />;
  }

  const kindBadge = l.kind === "video"
    ? "bg-info-50 dark:bg-info-500/10 text-info-700 dark:text-info-400"
    : "bg-primary/10 text-primary";

  return (
    <div>
      {/* Lesson header */}
      <div className="mb-6">
        <div className="flex items-center gap-2 mb-2">
          <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${kindBadge}`}>
            {l.kind === "video" ? "Видео-урок" : "Теория"}
          </span>
          {l.duration_min != null && (
            <span className="text-xs text-gray-400 dark:text-gray-500 flex items-center gap-1">
              <i className="bi bi-clock" aria-hidden="true" />
              {l.duration_min} мин
            </span>
          )}
        </div>
        <h2 className="text-xl font-bold text-gray-900 dark:text-gray-100">{l.title}</h2>
      </div>

      {/* Video */}
      {l.kind === "video" && l.video_url && (
        <div className="mb-6">
          <div className="aspect-video rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden shadow-elev-1">
            {l.video_source === "youtube" && (
              <iframe
                src={`https://www.youtube-nocookie.com/embed/${
                  (() => {
                    const m = l.video_url.match(/[?&]v=([^&]+)/) ?? l.video_url.match(/youtu\.be\/([^?]+)/);
                    return m ? m[1] : l.video_url;
                  })()
                }?rel=0&modestbranding=1`}
                className="w-full h-full"
                allow="presentation"
                allowFullScreen
                sandbox="allow-same-origin allow-scripts allow-popups"
                title={l.title}
              />
            )}
            {l.video_source === "drive" && (
              <iframe
                src={l.video_url}
                className="w-full h-full"
                allow="autoplay; encrypted-media"
                allowFullScreen
                sandbox="allow-same-origin allow-scripts allow-popups"
                title={l.title}
              />
            )}
            {l.video_source === "loom" && (
              <iframe
                src={l.video_url}
                className="w-full h-full"
                allowFullScreen
                sandbox="allow-same-origin allow-scripts allow-popups"
                title={l.title}
              />
            )}
            {(!l.video_source || l.video_source === "vimeo") && (
              <iframe
                src={l.video_url}
                className="w-full h-full"
                allowFullScreen
                sandbox="allow-same-origin allow-scripts allow-popups"
                title={l.title}
              />
            )}
          </div>
        </div>
      )}

      {/* Theory */}
      {l.kind === "theory" && l.content_blocks && l.content_blocks.length > 0 && (
        <ContentBlockRenderer blocks={l.content_blocks} />
      )}

      {/* Complete action */}
      <div className="mt-8 border-t border-gray-100 dark:border-gray-700 pt-5">
        {completed ? (
          <div className="flex items-center gap-2 text-success-600 dark:text-success-500">
            <i className="bi bi-check-circle-fill text-lg" aria-hidden="true" />
            <span className="text-sm font-medium">Урок отмечен как пройденный</span>
          </div>
        ) : (
          <button
            className="btn-primary"
            onClick={handleComplete}
            disabled={completing}
          >
            {completing
              ? "Сохранение…"
              : l.kind === "video" ? "Я посмотрел" : "Отметить прочитано"}
          </button>
        )}
      </div>
    </div>
  );
}

// ─── Quiz Player ─────────────────────────────────────────────────────────────

interface QuizPlayerProps {
  lessonId: number;
  onCompleted: () => void;
}

interface QuizPlayerState {
  attemptId: number;
  answers: Record<number, number[]>;
  currentQuestionIdx: number;
}

function QuizPlayer({ lessonId, onCompleted }: QuizPlayerProps) {
  const { data: lesson, isLoading } = useSWR(`/onboarding/lessons/${lessonId}`, fetcher);
  const [state, setState] = useState<QuizPlayerState | null>(null);
  const [result, setResult] = useState<QuizSubmitResponse | null>(null);
  const [starting, setStarting] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    setState(null);
    setResult(null);
    setError(null);
  }, [lessonId]);

  async function startQuiz() {
    setStarting(true);
    setError(null);
    try {
      const attempt = await api<QuizAttempt>(`/onboarding/lessons/${lessonId}/quiz/start`, { method: "POST" });
      setState({ attemptId: attempt.id, answers: {}, currentQuestionIdx: 0 });
    } catch (e) {
      setError(e instanceof ApiError ? String((e.detail as { detail?: string })?.detail ?? e.message) : "Ошибка");
    } finally {
      setStarting(false);
    }
  }

  async function submitQuiz() {
    if (!state) return;
    const l = lesson as CourseLesson;
    if (!l.questions) return;

    setSubmitting(true);
    try {
      const answersPayload = l.questions.map((q) => ({
        question_id: q.id,
        selected_indices: state.answers[q.id] ?? [],
      }));
      const res = await api<QuizSubmitResponse>(
        `/onboarding/quiz-attempts/${state.attemptId}/submit`,
        { method: "POST", body: { answers: answersPayload } }
      );
      setResult(res);
      if (res.passed) {
        onCompleted();
      }
    } catch (e) {
      setError(e instanceof ApiError ? String((e.detail as { detail?: string })?.detail ?? e.message) : "Ошибка");
    } finally {
      setSubmitting(false);
    }
  }

  if (isLoading) return <LessonViewerSkeleton />;
  if (!lesson) return <EmptyState icon="bi-exclamation-circle" title="Квиз не найден" />;

  const l = lesson as CourseLesson;
  const questions = l.questions ?? [];

  // Start screen
  if (!state) {
    return (
      <div className="rounded-2xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800/60 shadow-elev-1 p-8 text-center">
        <div className="mb-4 flex justify-center">
          <span className="h-16 w-16 grid place-items-center rounded-2xl bg-info-50 dark:bg-info-500/10">
            <i className="bi bi-question-circle text-info-600 dark:text-info-400 text-3xl" aria-hidden="true" />
          </span>
        </div>
        <h2 className="text-xl font-bold text-gray-900 dark:text-gray-100 mb-2">{l.title}</h2>
        <p className="text-sm text-gray-500 dark:text-gray-400 mb-2">
          {questions.length} вопрос{questions.length === 1 ? "" : questions.length < 5 ? "а" : "ов"}
        </p>
        {l.duration_min && (
          <p className="text-xs text-gray-400 dark:text-gray-500 mb-6 flex items-center justify-center gap-1">
            <i className="bi bi-clock" aria-hidden="true" />
            ~{l.duration_min} мин
          </p>
        )}
        {error && (
          <p className="text-danger-600 dark:text-danger-500 text-sm mb-4 rounded-lg bg-danger-50 dark:bg-danger-500/10 px-4 py-2.5">
            {error}
          </p>
        )}
        <button
          className="btn-primary"
          onClick={startQuiz}
          disabled={starting}
        >
          {starting ? "Подготовка…" : "Начать квиз"}
        </button>
      </div>
    );
  }

  // Result
  if (result) {
    return <QuizResult result={result} onRetry={() => { setState(null); setResult(null); }} />;
  }

  const currentQ = questions[state.currentQuestionIdx];
  const total = questions.length;
  const progressPct = (state.currentQuestionIdx / total) * 100;
  const currentAnswers = state.answers[currentQ?.id] ?? [];
  const hasAnswer = currentAnswers.length > 0;
  const isLast = state.currentQuestionIdx === questions.length - 1;

  function toggleAnswer(optIdx: number) {
    if (!currentQ) return;
    setState((prev) => {
      if (!prev) return prev;
      const existing = prev.answers[currentQ.id] ?? [];
      const next: number[] = currentQ.kind === "single"
        ? [optIdx]
        : existing.includes(optIdx)
          ? existing.filter((i) => i !== optIdx)
          : [...existing, optIdx];
      return { ...prev, answers: { ...prev.answers, [currentQ.id]: next } };
    });
  }

  function goNext() {
    if (!state) return;
    if (state.currentQuestionIdx < questions.length - 1) {
      setState((prev) => prev ? { ...prev, currentQuestionIdx: prev.currentQuestionIdx + 1 } : prev);
    } else {
      submitQuiz();
    }
  }

  return (
    <div>
      {/* Progress bar */}
      <div className="mb-6">
        <div className="flex items-center justify-between text-xs text-gray-400 dark:text-gray-500 mb-1.5">
          <span>Вопрос {state.currentQuestionIdx + 1} из {total}</span>
          <span className="tabular-nums">{Math.round(progressPct)}%</span>
        </div>
        <div className="h-1.5 rounded-full bg-gray-100 dark:bg-gray-700">
          <div
            className="h-full rounded-full bg-primary transition-[width] duration-300"
            style={{ width: `${progressPct}%` }}
          />
        </div>
      </div>

      {currentQ && (
        <div className="rounded-2xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800/60 shadow-elev-1 p-6">
          <h3 className="text-base font-semibold text-gray-900 dark:text-gray-100 mb-2">{currentQ.text}</h3>
          <p className="text-xs text-gray-400 dark:text-gray-500 mb-4">
            {currentQ.kind === "single" ? "Выберите один ответ" : "Выберите все правильные ответы"}
          </p>

          <div className="space-y-2">
            {currentQ.options.map((opt, optIdx) => {
              const selected = currentAnswers.includes(optIdx);
              return (
                <button
                  key={optIdx}
                  type="button"
                  className={`w-full text-left flex items-center gap-3 px-4 py-3 rounded-xl border transition-all duration-150 ${
                    selected
                      ? "border-primary bg-primary/5 dark:bg-primary/10 text-primary shadow-sm"
                      : "border-gray-200 dark:border-gray-700 hover:border-primary/40 hover:bg-gray-50 dark:hover:bg-gray-700/50 text-gray-700 dark:text-gray-300"
                  }`}
                  onClick={() => toggleAnswer(optIdx)}
                >
                  <span
                    className={`shrink-0 w-5 h-5 rounded-${currentQ.kind === "single" ? "full" : "md"} border-2 flex items-center justify-center transition-colors ${
                      selected ? "border-primary bg-primary" : "border-gray-300 dark:border-gray-600"
                    }`}
                  >
                    {selected && <i className="bi bi-check-lg text-white text-[9px]" aria-hidden="true" />}
                  </span>
                  <span className="text-sm">{opt}</span>
                </button>
              );
            })}
          </div>

          {error && (
            <p className="text-danger-600 dark:text-danger-500 text-sm mt-4 rounded-lg bg-danger-50 dark:bg-danger-500/10 px-4 py-2.5">
              {error}
            </p>
          )}

          <div className="mt-6 flex items-center justify-between border-t border-gray-100 dark:border-gray-700 pt-4">
            <button
              type="button"
              className="btn-ghost flex items-center gap-1"
              onClick={() => setState((prev) => prev ? { ...prev, currentQuestionIdx: Math.max(0, prev.currentQuestionIdx - 1) } : prev)}
              disabled={state.currentQuestionIdx === 0}
            >
              <i className="bi bi-chevron-left" aria-hidden="true" />
              Назад
            </button>
            <button
              className="btn-primary"
              onClick={goNext}
              disabled={!hasAnswer || submitting}
            >
              {submitting ? "Отправка…" : isLast ? "Завершить" : <>Далее <i className="bi bi-chevron-right ml-1" aria-hidden="true" /></>}
            </button>
          </div>
        </div>
      )}
    </div>
  );
}

// ─── Quiz Result ──────────────────────────────────────────────────────────────

interface QuizResultProps {
  result: QuizSubmitResponse;
  onRetry: () => void;
}

function QuizResult({ result, onRetry }: QuizResultProps) {
  const [showReview, setShowReview] = useState(false);

  return (
    <div className="rounded-2xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800/60 shadow-elev-1 p-8 text-center">
      {/* Icon / confetti */}
      <div className="mb-4 flex justify-center">
        {result.passed ? (
          <LordIcon
            icon={confettiIcon}
            trigger="in"
            size={80}
            colors="primary:#1F9D55,secondary:#D1FAE5"
            fallbackIcon="bi-check-circle-fill"
          />
        ) : (
          <span className="h-16 w-16 grid place-items-center rounded-2xl bg-danger-50 dark:bg-danger-500/10">
            <i className="bi bi-x-circle-fill text-danger-500 text-3xl" aria-hidden="true" />
          </span>
        )}
      </div>

      <h2 className={`text-2xl font-bold mb-2 ${result.passed ? "text-success-700 dark:text-success-500" : "text-danger-700 dark:text-danger-500"}`}>
        {result.passed ? "Квиз пройден!" : "Не зачёт"}
      </h2>

      <p className="text-4xl font-bold tabular-nums text-gray-900 dark:text-gray-100 mb-1">
        {Math.round(result.score_pct)}%
      </p>
      <p className="text-sm text-gray-500 dark:text-gray-400 mb-6">
        Правильных ответов: {result.n_correct} из {result.n_total}
      </p>

      {!result.passed && (
        <div className="mb-6 rounded-xl bg-danger-50 dark:bg-danger-500/10 border border-danger-100 dark:border-danger-500/20 px-5 py-4">
          <p className="text-sm text-gray-600 dark:text-gray-400 mb-3">
            Попробуй ещё раз — это не страшно.
          </p>
          <button className="btn-primary" onClick={onRetry}>
            Попробовать снова
          </button>
        </div>
      )}

      {result.questions && result.questions.length > 0 && (
        <div className="mt-2 text-left">
          <button
            type="button"
            className="btn-ghost text-sm flex items-center gap-1 mx-auto mb-4"
            onClick={() => setShowReview(!showReview)}
          >
            <i className={`bi ${showReview ? "bi-chevron-up" : "bi-chevron-down"}`} aria-hidden="true" />
            {showReview ? "Скрыть разбор" : "Разбор ответов"}
          </button>

          {showReview && (
            <div className="space-y-3">
              {result.questions.map((q, idx) => (
                <div
                  key={q.id}
                  className={`rounded-xl border-l-4 p-4 bg-gray-50 dark:bg-gray-900 ${
                    q.is_correct
                      ? "border-success-500"
                      : "border-danger-500"
                  }`}
                >
                  <p className="text-sm font-medium mb-2 text-gray-800 dark:text-gray-200">
                    <span className={`mr-2 ${q.is_correct ? "text-success-600 dark:text-success-500" : "text-danger-600 dark:text-danger-500"}`}>
                      <i className={`bi ${q.is_correct ? "bi-check-circle-fill" : "bi-x-circle-fill"}`} aria-hidden="true" />
                    </span>
                    {idx + 1}. {q.text}
                  </p>
                  {q.correct_answers && (
                    <p className="text-xs text-gray-500 dark:text-gray-400">
                      Правильные ответы: индексы {q.correct_answers.join(", ")}
                    </p>
                  )}
                  {q.explanation && (
                    <p className="text-xs text-gray-500 dark:text-gray-400 mt-1 italic">{q.explanation}</p>
                  )}
                </div>
              ))}
            </div>
          )}
        </div>
      )}
    </div>
  );
}

// ─── Main Page ─────────────────────────────────────────────────────────────────

const KIND_ICONS: Record<LessonKind, string> = {
  theory: "bi-file-text",
  video:  "bi-play-circle-fill",
  quiz:   "bi-question-circle",
};

function SidebarSkeleton() {
  return (
    <div className="animate-pulse px-4 py-4 space-y-3">
      <div className="h-4 bg-gray-200 dark:bg-gray-700 rounded w-3/4" />
      <div className="h-3 bg-gray-100 dark:bg-gray-700 rounded w-full" />
      <div className="h-3 bg-gray-100 dark:bg-gray-700 rounded w-5/6" />
      <div className="h-3 bg-gray-100 dark:bg-gray-700 rounded w-full" />
      <div className="h-3 bg-gray-100 dark:bg-gray-700 rounded w-2/3" />
    </div>
  );
}

export default function CoursePage() {
  const params = useParams();
  const router = useRouter();
  const courseId = Number(params.id);

  const { data: course, isLoading, mutate } = useSWR<CourseWithModules>(
    `/onboarding/courses/${courseId}`,
    fetcher
  );

  const [selectedLessonId, setSelectedLessonId] = useState<number | null>(null);

  useEffect(() => {
    if (!course || selectedLessonId !== null) return;
    const first = course.modules.flatMap((m) => m.lessons)[0];
    if (first) setSelectedLessonId(first.id);
  }, [course, selectedLessonId]);

  const allLessons = course?.modules.flatMap((m) => m.lessons) ?? [];
  const currentIdx = selectedLessonId ? allLessons.findIndex((l) => l.id === selectedLessonId) : -1;
  const prevLesson = currentIdx > 0 ? allLessons[currentIdx - 1] : null;
  const nextLesson = currentIdx < allLessons.length - 1 ? allLessons[currentIdx + 1] : null;

  if (isLoading) {
    return (
      <div className="flex min-h-full">
        <aside className="w-[280px] shrink-0 border-r border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900">
          <SidebarSkeleton />
        </aside>
        <main className="flex-1 p-8">
          <LessonViewerSkeleton />
        </main>
      </div>
    );
  }

  if (!course) {
    return (
      <div className="flex items-center justify-center min-h-full">
        <EmptyState icon="bi-exclamation-circle" title="Курс не найден" />
      </div>
    );
  }

  return (
    <div className="flex min-h-screen">
      {/* Sidebar */}
      <aside className="w-[280px] shrink-0 border-r border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 overflow-y-auto sticky top-0 h-screen">
        {/* Back + title */}
        <div className="px-4 py-4 border-b border-gray-200 dark:border-gray-700">
          <button
            type="button"
            className="btn-ghost text-xs mb-2 flex items-center gap-1"
            onClick={() => router.push("/onboarding")}
          >
            <i className="bi bi-arrow-left" aria-hidden="true" />
            Мои курсы
          </button>
          <h2 className="font-semibold text-gray-900 dark:text-gray-100 text-sm leading-snug">
            {course.title}
          </h2>
        </div>

        {/* Progress */}
        {allLessons.length > 0 && (
          <div className="px-4 py-3 border-b border-gray-100 dark:border-gray-700/60">
            <div className="flex items-center justify-between text-[10px] text-gray-400 dark:text-gray-500 mb-1">
              <span>Прогресс</span>
              <span className="tabular-nums">{currentIdx >= 0 ? currentIdx + 1 : 0} / {allLessons.length}</span>
            </div>
            <div className="h-1 rounded-full bg-gray-100 dark:bg-gray-700">
              <div
                className="h-full rounded-full bg-primary transition-[width] duration-500"
                style={{ width: `${allLessons.length > 0 ? ((currentIdx >= 0 ? currentIdx + 1 : 0) / allLessons.length) * 100 : 0}%` }}
              />
            </div>
          </div>
        )}

        {/* Module nav */}
        <nav className="py-2">
          {course.modules.map((mod) => (
            <div key={mod.id}>
              <div className="px-4 py-2 text-[10px] font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wider">
                {mod.title}
              </div>
              {mod.lessons.map((lesson) => {
                const isActive = lesson.id === selectedLessonId;
                return (
                  <button
                    key={lesson.id}
                    type="button"
                    className={`w-full flex items-center gap-2 px-4 py-2.5 text-sm text-left transition-colors ${
                      isActive
                        ? "bg-primary/5 dark:bg-primary/10 text-primary font-medium"
                        : "text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700/50"
                    }`}
                    onClick={() => setSelectedLessonId(lesson.id)}
                  >
                    <i className={`bi ${KIND_ICONS[lesson.kind]} text-xs shrink-0 ${isActive ? "text-primary" : "text-gray-400 dark:text-gray-500"}`} aria-hidden="true" />
                    <span className="flex-1 truncate">{lesson.title}</span>
                    {lesson.duration_min != null && (
                      <span className="text-[10px] text-gray-400 dark:text-gray-500 shrink-0">{lesson.duration_min}м</span>
                    )}
                  </button>
                );
              })}
            </div>
          ))}
        </nav>
      </aside>

      {/* Main */}
      <main className="flex-1 min-w-0 flex flex-col bg-gray-50/50 dark:bg-gray-900/50">
        <div className="flex-1 p-8 max-w-3xl mx-auto w-full">
          {selectedLessonId ? (
            <LessonViewer
              key={selectedLessonId}
              lessonId={selectedLessonId}
              courseId={courseId}
              onCompleted={() => mutate()}
            />
          ) : (
            <EmptyState icon="bi-mortarboard-fill" title="Выберите урок из списка слева" />
          )}
        </div>

        {/* Bottom nav */}
        <div className="sticky bottom-0 bg-white dark:bg-gray-900 border-t border-gray-200 dark:border-gray-700 px-8 py-3 flex items-center justify-between">
          <button
            type="button"
            className="btn-ghost flex items-center gap-1.5 text-sm"
            disabled={!prevLesson}
            onClick={() => prevLesson && setSelectedLessonId(prevLesson.id)}
          >
            <i className="bi bi-chevron-left" aria-hidden="true" />
            <span className="truncate max-w-[180px]">{prevLesson ? prevLesson.title : "Предыдущий"}</span>
          </button>

          {currentIdx >= 0 && (
            <span className="text-xs text-gray-400 dark:text-gray-500 tabular-nums shrink-0 px-3">
              {currentIdx + 1} / {allLessons.length}
            </span>
          )}

          <button
            type="button"
            className="btn-ghost flex items-center gap-1.5 text-sm"
            disabled={!nextLesson}
            onClick={() => nextLesson && setSelectedLessonId(nextLesson.id)}
          >
            <span className="truncate max-w-[180px]">{nextLesson ? nextLesson.title : "Следующий"}</span>
            <i className="bi bi-chevron-right" aria-hidden="true" />
          </button>
        </div>
      </main>
    </div>
  );
}
