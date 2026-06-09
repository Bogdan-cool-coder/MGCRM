"use client";

import { useState, useEffect } from "react";
import { createPortal } from "react-dom";
import type { CourseLesson, ContentBlock, LessonQuizQuestion, LessonKind } from "@/lib/types";
import { ContentBlocksBuilder } from "./ContentBlocksBuilder";
import { QuizQuestionsBuilder } from "./QuizQuestionsBuilder";
import { parseDriveUrl } from "@/lib/video-parsers";

const KIND_LABELS: Record<LessonKind, string> = {
  theory: "Теория",
  video:  "Видео-урок",
  quiz:   "Квиз",
};

type VideoSource = "drive" | "loom" | "youtube" | "vimeo";

interface LessonForm {
  title: string;
  duration_min: string;
  // theory
  content_blocks: ContentBlock[];
  // video
  video_source: VideoSource;
  video_url: string;
  // quiz
  questions: LessonQuizQuestion[];
  randomize_questions: boolean;
}

interface Props {
  lesson: CourseLesson | null; // null = новый
  kind: LessonKind;
  isOpen: boolean;
  onClose: () => void;
  onSave: (data: Partial<CourseLesson>) => Promise<void>;
}

const VIDEO_SOURCES: { value: VideoSource; label: string; icon: string }[] = [
  { value: "drive",   label: "Google Drive", icon: "bi-google" },
  { value: "loom",    label: "Loom",         icon: "bi-camera-video" },
  { value: "youtube", label: "YouTube",      icon: "bi-youtube" },
  { value: "vimeo",   label: "Vimeo",        icon: "bi-play-circle-fill" },
];

export function LessonEditorDrawer({ lesson, kind, isOpen, onClose, onSave }: Props) {
  const [form, setForm] = useState<LessonForm>({
    title: "",
    duration_min: "",
    content_blocks: [],
    video_source: "youtube",
    video_url: "",
    questions: [],
    randomize_questions: false,
  });
  const [isDirty, setIsDirty] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [videoUrlError, setVideoUrlError] = useState<string | null>(null);
  const [mounted, setMounted] = useState(false);

  useEffect(() => { setMounted(true); }, []);

  useEffect(() => {
    if (!isOpen) return;
    if (lesson) {
      setForm({
        title: lesson.title,
        duration_min: lesson.duration_min != null ? String(lesson.duration_min) : "",
        content_blocks: lesson.content_blocks ?? [],
        video_source: (lesson.video_source as VideoSource | null) ?? "youtube",
        video_url: lesson.video_url ?? "",
        questions: lesson.questions ?? [],
        randomize_questions: lesson.randomize_questions ?? false,
      });
    } else {
      setForm({
        title: "",
        duration_min: "",
        content_blocks: [],
        video_source: "youtube",
        video_url: "",
        questions: [],
        randomize_questions: false,
      });
    }
    setIsDirty(false);
    setError(null);
  }, [isOpen, lesson]);

  function update<K extends keyof LessonForm>(key: K, value: LessonForm[K]) {
    setForm((f) => ({ ...f, [key]: value }));
    setIsDirty(true);
  }

  function tryClose() {
    if (isDirty) {
      if (confirm("Есть несохранённые изменения. Закрыть без сохранения?")) {
        onClose();
      }
    } else {
      onClose();
    }
  }

  function validateVideoUrl(url: string, source: VideoSource): boolean {
    if (!url) {
      setVideoUrlError("Введите ссылку на видео");
      return false;
    }
    if (source === "drive" && !parseDriveUrl(url)) {
      setVideoUrlError("Неверная ссылка Google Drive");
      return false;
    }
    setVideoUrlError(null);
    return true;
  }

  async function handleSave() {
    if (!form.title.trim()) {
      setError("Введите название урока");
      return;
    }

    if (kind === "video") {
      if (!validateVideoUrl(form.video_url, form.video_source)) return;
    }

    setSaving(true);
    setError(null);

    const payload: Partial<CourseLesson> = {
      title: form.title.trim(),
      kind,
      duration_min: form.duration_min ? Number(form.duration_min) : null,
    };

    if (kind === "theory") {
      payload.content_blocks = form.content_blocks;
    } else if (kind === "video") {
      payload.video_url = form.video_url;
      payload.video_source = form.video_source;
    } else if (kind === "quiz") {
      payload.questions = form.questions;
      payload.randomize_questions = form.randomize_questions;
    }

    try {
      await onSave(payload);
      setIsDirty(false);
      onClose();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Не удалось сохранить");
    } finally {
      setSaving(false);
    }
  }

  if (!mounted || !isOpen) return null;

  return createPortal(
    <div className="fixed inset-0 z-50 flex justify-end">
      {/* Backdrop */}
      <div className="absolute inset-0 bg-black/30" onClick={tryClose} />

      {/* Drawer */}
      <div className="relative w-[560px] h-full bg-white shadow-xl flex flex-col">
        {/* Header */}
        <div className="flex items-center justify-between px-5 py-4 border-b border-gray-200">
          <div className="flex items-center gap-3">
            <button type="button" onClick={tryClose} className="btn-ghost px-2 py-1">
              <i className="bi bi-x-lg" />
            </button>
            <h2 className="text-h5 font-semibold">
              {lesson ? "Редактировать урок" : "Новый урок"}
            </h2>
            <span className="badge bg-gray-100 text-gray-600 text-xs px-2 py-0.5 rounded-full">
              {KIND_LABELS[kind]}
            </span>
          </div>
          <button
            type="button"
            className="btn-primary text-sm"
            onClick={handleSave}
            disabled={saving}
          >
            {saving ? "Сохранение…" : "Сохранить"}
          </button>
        </div>

        {/* Body */}
        <div className="flex-1 overflow-y-auto p-5 space-y-4">
          {error && (
            <div className="text-danger text-sm bg-danger/10 px-3 py-2 rounded">
              {error}
            </div>
          )}

          {/* Title */}
          <div>
            <label className="label">Название урока</label>
            <input
              className="input"
              value={form.title}
              onChange={(e) => update("title", e.target.value)}
              placeholder="Введите название"
            />
          </div>

          {/* Duration */}
          <div>
            <label className="label">Длительность</label>
            <div className="flex items-center gap-2">
              <input
                type="number"
                className="input w-24"
                min={0}
                value={form.duration_min}
                onChange={(e) => update("duration_min", e.target.value)}
                placeholder="10"
              />
              <span className="text-sm text-gray-500">мин</span>
            </div>
          </div>

          <hr className="border-gray-100" />

          {/* Content section by kind */}
          {kind === "theory" && (
            <div>
              <h3 className="text-sm font-semibold text-gray-700 mb-3">Содержимое урока</h3>
              <ContentBlocksBuilder
                blocks={form.content_blocks}
                onChange={(blocks) => update("content_blocks", blocks)}
              />
            </div>
          )}

          {kind === "video" && (
            <div className="space-y-3">
              <h3 className="text-sm font-semibold text-gray-700">Источник видео</h3>
              <div className="flex gap-3 flex-wrap">
                {VIDEO_SOURCES.map((s) => (
                  <label key={s.value} className="flex items-center gap-1.5 text-sm cursor-pointer">
                    <input
                      type="radio"
                      name="video_source"
                      checked={form.video_source === s.value}
                      onChange={() => {
                        update("video_source", s.value);
                        update("video_url", "");
                        setVideoUrlError(null);
                      }}
                    />
                    <i className={`bi ${s.icon}`} />
                    {s.label}
                  </label>
                ))}
              </div>

              <div>
                <label className="label">Ссылка</label>
                <input
                  className="input"
                  value={form.video_url}
                  onChange={(e) => {
                    update("video_url", e.target.value);
                    setVideoUrlError(null);
                  }}
                  onBlur={() => form.video_url && validateVideoUrl(form.video_url, form.video_source)}
                  placeholder={
                    form.video_source === "drive"
                      ? "https://drive.google.com/file/d/.../view"
                      : form.video_source === "loom"
                      ? "https://www.loom.com/share/..."
                      : form.video_source === "youtube"
                      ? "https://www.youtube.com/watch?v=..."
                      : "https://vimeo.com/..."
                  }
                />
                {videoUrlError && <p className="text-danger text-xs mt-1">{videoUrlError}</p>}
              </div>

              {/* Preview for Drive */}
              {form.video_source === "drive" && form.video_url && !videoUrlError && (() => {
                const parsed = parseDriveUrl(form.video_url);
                return parsed ? (
                  <div className="aspect-video rounded-lg border border-gray-200 overflow-hidden">
                    <iframe
                      src={parsed.embedUrl}
                      className="w-full h-full"
                      allow="autoplay; encrypted-media"
                      allowFullScreen
                      title="Предпросмотр"
                    />
                  </div>
                ) : null;
              })()}
            </div>
          )}

          {kind === "quiz" && (
            <div className="space-y-4">
              {/* Настройки квиза */}
              <div className="card bg-gray-50 p-3">
                <h3 className="text-sm font-semibold text-gray-700 mb-2">Настройки квиза</h3>
                <label className="flex items-start gap-2.5 cursor-pointer select-none">
                  <input
                    type="checkbox"
                    checked={form.randomize_questions}
                    onChange={(e) =>
                      setForm((f) => ({ ...f, randomize_questions: e.target.checked }))
                    }
                    className="mt-0.5 rounded accent-primary shrink-0"
                  />
                  <div>
                    <span className="text-sm font-medium text-gray-800">
                      Перемешивать вопросы при каждой попытке
                    </span>
                    <p className="text-xs text-gray-500 mt-0.5">
                      Каждая попытка ученика будет получать вопросы в случайном порядке
                    </p>
                  </div>
                </label>
              </div>

              {/* Вопросы */}
              <div>
                <h3 className="text-sm font-semibold text-gray-700 mb-3">Вопросы квиза</h3>
                <QuizQuestionsBuilder
                  questions={form.questions}
                  onChange={(qs) => update("questions", qs)}
                />
              </div>
            </div>
          )}
        </div>

        {/* Footer */}
        <div className="px-5 py-3 border-t border-gray-200 bg-gray-50 flex items-center justify-end gap-2">
          <button type="button" className="btn-ghost" onClick={tryClose}>
            Отмена
          </button>
          <button
            type="button"
            className="btn-primary"
            onClick={handleSave}
            disabled={saving}
          >
            {saving ? "Сохранение…" : "Сохранить"}
          </button>
        </div>
      </div>
    </div>,
    document.body
  );
}
