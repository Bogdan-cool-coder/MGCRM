"use client";

import { useEffect, useState } from "react";
import { DndContext, closestCenter, type DragEndEvent } from "@dnd-kit/core";
import {
  SortableContext,
  verticalListSortingStrategy,
  arrayMove,
} from "@dnd-kit/sortable";
import { SortableItem } from "@/components/SortableItem";
import type { CourseModule, CourseLesson, LessonKind } from "@/lib/types";
import { LessonEditorDrawer } from "./LessonEditorDrawer";
import { api, ApiError } from "@/lib/api";

type LessonKindOption = { value: LessonKind; label: string; icon: string };

const LESSON_KIND_OPTIONS: LessonKindOption[] = [
  { value: "theory", label: "Теория (текст + видео)", icon: "bi-text-left" },
  { value: "video",  label: "Видео-урок",            icon: "bi-camera-video" },
  { value: "quiz",   label: "Квиз",                  icon: "bi-question-circle" },
];

const KIND_BADGE: Record<LessonKind, string> = {
  theory: "bg-info/10 text-info",
  video:  "bg-primary/10 text-primary",
  quiz:   "bg-warning/10 text-warning",
};

const KIND_LABEL: Record<LessonKind, string> = {
  theory: "Теория",
  video:  "Видео",
  quiz:   "Квиз",
};

interface Props {
  courseId: number;
  modules: CourseModule[];
  onRefresh: () => void;
}

export function CourseStructureBuilder({ courseId, modules, onRefresh }: Props) {
  const [editingModuleId, setEditingModuleId] = useState<number | null>(null);
  const [editingModuleTitle, setEditingModuleTitle] = useState("");
  const [lessonKindDropdown, setLessonKindDropdown] = useState<number | null>(null);
  const [drawerLesson, setDrawerLesson] = useState<CourseLesson | null>(null);
  const [drawerKind, setDrawerKind] = useState<LessonKind>("theory");
  const [drawerModuleId, setDrawerModuleId] = useState<number | null>(null);
  const [drawerOpen, setDrawerOpen] = useState(false);
  const [deletingModuleId, setDeletingModuleId] = useState<number | null>(null);
  const [deletingLessonId, setDeletingLessonId] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [localModules, setLocalModules] = useState<CourseModule[]>(modules);

  // Sync from props when server data changes (после onRefresh)
  useEffect(() => {
    setLocalModules(modules);
  }, [modules]);

  async function handleAddModule() {
    try {
      await api("/admin/onboarding/modules", {
        method: "POST",
        body: { course_id: courseId, title: "Новый модуль", order_index: modules.length },
      });
      onRefresh();
    } catch (e) {
      setError(e instanceof ApiError ? String((e.detail as { detail?: string })?.detail ?? e.message) : "Ошибка");
    }
  }

  async function handleSaveModuleTitle(moduleId: number, title: string) {
    try {
      await api(`/admin/onboarding/modules/${moduleId}`, {
        method: "PATCH",
        body: { title },
      });
      onRefresh();
    } catch (e) {
      setError(e instanceof ApiError ? String((e.detail as { detail?: string })?.detail ?? e.message) : "Ошибка");
    }
    setEditingModuleId(null);
  }

  async function handleDeleteModule(moduleId: number) {
    if (!confirm("Удалить модуль и все его уроки? Прогресс студентов будет потерян.")) return;
    setDeletingModuleId(moduleId);
    try {
      await api(`/admin/onboarding/modules/${moduleId}`, { method: "DELETE" });
      onRefresh();
    } catch (e) {
      setError(e instanceof ApiError ? String((e.detail as { detail?: string })?.detail ?? e.message) : "Ошибка");
    }
    setDeletingModuleId(null);
  }

  async function handleDeleteLesson(lessonId: number) {
    if (!confirm("Удалить урок? Прогресс студентов будет потерян.")) return;
    setDeletingLessonId(lessonId);
    try {
      await api(`/admin/onboarding/lessons/${lessonId}`, { method: "DELETE" });
      onRefresh();
    } catch (e) {
      setError(e instanceof ApiError ? String((e.detail as { detail?: string })?.detail ?? e.message) : "Ошибка");
    }
    setDeletingLessonId(null);
  }

  function openAddLesson(moduleId: number, kind: LessonKind) {
    setDrawerLesson(null);
    setDrawerKind(kind);
    setDrawerModuleId(moduleId);
    setDrawerOpen(true);
    setLessonKindDropdown(null);
  }

  function openEditLesson(lesson: CourseLesson) {
    setDrawerLesson(lesson);
    setDrawerKind(lesson.kind);
    setDrawerModuleId(lesson.module_id);
    setDrawerOpen(true);
  }

  async function handleSaveLesson(data: Partial<CourseLesson>) {
    if (drawerLesson) {
      await api(`/admin/onboarding/lessons/${drawerLesson.id}`, {
        method: "PATCH",
        body: data,
      });
    } else {
      const mod = modules.find((m) => m.id === drawerModuleId);
      await api("/admin/onboarding/lessons", {
        method: "POST",
        body: {
          ...data,
          module_id: drawerModuleId,
          order_index: mod ? mod.lessons.length : 0,
        },
      });
    }
    onRefresh();
  }

  // DnD для модулей
  function handleModuleDragEnd(event: DragEndEvent) {
    const { active, over } = event;
    if (!over || active.id === over.id) return;

    const oldIdx = localModules.findIndex((m) => m.id === active.id);
    const newIdx = localModules.findIndex((m) => m.id === over.id);
    const reordered = arrayMove(localModules, oldIdx, newIdx);

    setLocalModules(reordered);

    void api(`/admin/onboarding/courses/${courseId}/modules/reorder`, {
      method: "PATCH",
      body: reordered.map((m, idx) => ({ id: m.id, sort_order: idx })),
    }).catch(() => {
      setLocalModules(modules);
    });
  }

  // DnD для уроков внутри модуля
  function handleLessonDragEnd(moduleId: number, event: DragEndEvent) {
    const { active, over } = event;
    if (!over || active.id === over.id) return;

    setLocalModules((prev) => {
      const modIdx = prev.findIndex((m) => m.id === moduleId);
      if (modIdx < 0) return prev;

      const mod = prev[modIdx];
      const oldIdx = mod.lessons.findIndex((l) => l.id === active.id);
      const newIdx = mod.lessons.findIndex((l) => l.id === over.id);
      const reorderedLessons = arrayMove(mod.lessons, oldIdx, newIdx);

      const next = [...prev];
      next[modIdx] = { ...mod, lessons: reorderedLessons };

      void api(`/admin/onboarding/courses/${courseId}/modules/${moduleId}/lessons/reorder`, {
        method: "PATCH",
        body: reorderedLessons.map((l, idx) => ({ id: l.id, sort_order: idx })),
      }).catch(() => {
        setLocalModules(modules);
      });

      return next;
    });
  }

  return (
    <div>
      {error && (
        <div className="text-danger text-sm bg-danger/10 px-3 py-2 rounded mb-3">
          {error}
          <button className="ml-2 text-xs underline" onClick={() => setError(null)}>
            Закрыть
          </button>
        </div>
      )}

      <DndContext collisionDetection={closestCenter} onDragEnd={handleModuleDragEnd}>
        <SortableContext
          items={localModules.map((m) => m.id)}
          strategy={verticalListSortingStrategy}
        >
          <div className="space-y-3">
            {localModules.map((mod) => (
              <SortableItem key={mod.id} id={mod.id}>
                <div className="card p-0 overflow-hidden flex-1">
                  {/* Module header */}
                  <div className="flex items-center gap-2 px-3 py-2.5 bg-gray-50 border-b border-gray-100">
                    {editingModuleId === mod.id ? (
                      <input
                        className="input flex-1 h-8 text-sm"
                        value={editingModuleTitle}
                        autoFocus
                        onChange={(e) => setEditingModuleTitle(e.target.value)}
                        onBlur={() => handleSaveModuleTitle(mod.id, editingModuleTitle || mod.title)}
                        onKeyDown={(e) => {
                          if (e.key === "Enter") handleSaveModuleTitle(mod.id, editingModuleTitle || mod.title);
                          if (e.key === "Escape") setEditingModuleId(null);
                        }}
                      />
                    ) : (
                      <span className="font-medium text-sm flex-1 text-gray-800">{mod.title}</span>
                    )}
                    <div className="flex items-center gap-1">
                      <button
                        type="button"
                        className="btn-ghost text-xs px-1.5 py-0.5"
                        onClick={() => {
                          setEditingModuleId(mod.id);
                          setEditingModuleTitle(mod.title);
                        }}
                        title="Переименовать"
                      >
                        <i className="bi bi-pencil" />
                      </button>
                      <button
                        type="button"
                        className={`btn-ghost text-xs px-1.5 py-0.5 text-danger hover:bg-danger/10 ${deletingModuleId === mod.id ? "opacity-50" : ""}`}
                        onClick={() => handleDeleteModule(mod.id)}
                        disabled={deletingModuleId === mod.id}
                        title="Удалить модуль"
                      >
                        <i className="bi bi-trash" />
                      </button>
                    </div>
                  </div>

                  {/* Lessons */}
                  <DndContext
                    collisionDetection={closestCenter}
                    onDragEnd={(e) => handleLessonDragEnd(mod.id, e)}
                  >
                    <SortableContext
                      items={mod.lessons.map((l) => l.id)}
                      strategy={verticalListSortingStrategy}
                    >
                      <div>
                        {mod.lessons.length === 0 && (
                          <div className="px-3 py-3 text-xs text-gray-400 italic">Уроков нет</div>
                        )}
                        {mod.lessons.map((lesson) => (
                          <SortableItem key={lesson.id} id={lesson.id}>
                            <div className="flex items-center gap-3 px-2 py-2 border-b last:border-0 border-gray-100 hover:bg-gray-50 transition-colors flex-1">
                              <span className="text-sm flex-1 text-gray-800 truncate">{lesson.title}</span>
                              <span className={`badge text-[10px] px-1.5 py-0.5 rounded-full ${KIND_BADGE[lesson.kind]}`}>
                                {KIND_LABEL[lesson.kind]}
                              </span>
                              {lesson.duration_min != null && (
                                <span className="text-xs text-gray-400 flex items-center gap-0.5">
                                  <i className="bi bi-clock text-[10px]" />
                                  {lesson.duration_min}м
                                </span>
                              )}
                              <div className="flex items-center gap-1">
                                <button
                                  type="button"
                                  className="btn-ghost text-xs px-1 py-0.5"
                                  onClick={() => openEditLesson(lesson)}
                                  title="Редактировать"
                                >
                                  <i className="bi bi-pencil text-xs" />
                                </button>
                                <button
                                  type="button"
                                  className={`btn-ghost text-xs px-1 py-0.5 text-danger hover:bg-danger/10 ${deletingLessonId === lesson.id ? "opacity-50" : ""}`}
                                  onClick={() => handleDeleteLesson(lesson.id)}
                                  disabled={deletingLessonId === lesson.id}
                                  title="Удалить урок"
                                >
                                  <i className="bi bi-trash text-xs" />
                                </button>
                              </div>
                            </div>
                          </SortableItem>
                        ))}
                      </div>
                    </SortableContext>
                  </DndContext>

                  {/* Add lesson row */}
                  <div className="px-3 py-2 bg-gray-50/50 border-t border-gray-100 relative">
                    <button
                      type="button"
                      className="btn-ghost text-xs flex items-center gap-1"
                      onClick={() => setLessonKindDropdown(lessonKindDropdown === mod.id ? null : mod.id)}
                    >
                      <i className="bi bi-plus-lg" />
                      Добавить урок
                      <i className="bi bi-caret-down-fill text-[10px]" />
                    </button>

                    {lessonKindDropdown === mod.id && (
                      <div className="absolute left-3 bottom-full mb-1 bg-white rounded-lg shadow-lg border border-gray-200 py-1 z-20 min-w-[200px]">
                        {LESSON_KIND_OPTIONS.map((opt) => (
                          <button
                            key={opt.value}
                            type="button"
                            className="w-full flex items-center gap-2 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 transition-colors"
                            onClick={() => openAddLesson(mod.id, opt.value)}
                          >
                            <i className={`bi ${opt.icon} text-gray-400`} />
                            {opt.label}
                          </button>
                        ))}
                      </div>
                    )}
                  </div>
                </div>
              </SortableItem>
            ))}
          </div>
        </SortableContext>
      </DndContext>

      <button
        type="button"
        className="btn-secondary text-sm mt-3 flex items-center gap-1"
        onClick={handleAddModule}
      >
        <i className="bi bi-plus-lg" />
        Добавить модуль
      </button>

      <LessonEditorDrawer
        lesson={drawerLesson}
        kind={drawerKind}
        isOpen={drawerOpen}
        onClose={() => setDrawerOpen(false)}
        onSave={handleSaveLesson}
      />
    </div>
  );
}
