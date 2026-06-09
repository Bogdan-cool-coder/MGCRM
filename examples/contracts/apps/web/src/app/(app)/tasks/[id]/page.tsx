"use client";

import { useState } from "react";
import Link from "next/link";
import useSWR, { mutate as globalMutate } from "swr";
import { fetcher } from "@/lib/api";
import { EmptyState } from "@/components/EmptyState";
import { TaskDetailHeader } from "@/components/Tasks/TaskDetailHeader";
import { TaskSummaryTab } from "@/components/Tasks/TaskSummaryTab";
import { TaskChecklistTab } from "@/components/Tasks/TaskChecklistTab";
import { TaskFilesTab } from "@/components/Tasks/TaskFilesTab";
import { TaskSubtasksTab } from "@/components/Tasks/TaskSubtasksTab";
import { TaskRelatedTab } from "@/components/Tasks/TaskRelatedTab";
import { TaskChatTab } from "@/components/Tasks/TaskChatTab";
import { TaskHistoryTab } from "@/components/Tasks/TaskHistoryTab";
import { TaskDetailSidebar } from "@/components/Tasks/TaskDetailSidebar";
import type { Activity, ChecklistItem, ActivityAttachment } from "@/lib/types";

type TabKey = "summary" | "checklist" | "files" | "subtasks" | "related" | "chat" | "history";

interface RelatedLink {
  id: number;
  activity_id_to: number;
  title?: string;
}

interface TaskDetailOut extends Activity {
  checklist_items?: ChecklistItem[];
  attachments?: ActivityAttachment[];
  related_links?: RelatedLink[];
}

export default function TaskDetailPage({ params }: { params: { id: string } }) {
  const [activeTab, setActiveTab] = useState<TabKey>("summary");

  const swrKey = `/activities/${params.id}`;
  const { data: task, error, isLoading, mutate } = useSWR<TaskDetailOut>(swrKey, fetcher);

  function handleMutate() {
    void mutate();
    void globalMutate("/activities/counts-by-preset");
    void globalMutate("/activities/my-open-count");
  }

  if (isLoading) {
    return (
      <div className="animate-pulse space-y-4 p-6">
        <div className="h-8 bg-gray-200 dark:bg-gray-700 rounded w-3/4" />
        <div className="h-4 bg-gray-100 dark:bg-gray-700/50 rounded w-1/2" />
        <div className="h-32 bg-gray-100 dark:bg-gray-700/50 rounded" />
      </div>
    );
  }

  if (error) {
    if ((error as { status?: number }).status === 404) {
      return (
        <div className="flex items-center justify-center py-24">
          <EmptyState
            icon="bi-question-circle"
            title="Задача не найдена"
            description="Возможно, она была удалена"
            cta={<Link href="/tasks" className="btn-primary">К списку задач</Link>}
          />
        </div>
      );
    }
    return (
      <div className="text-danger text-sm p-6">
        Не удалось загрузить задачу. Обновить страницу.
      </div>
    );
  }

  if (!task) return null;

  const checklist = task.checklist_items ?? [];
  const attachments = task.attachments ?? [];
  const relatedLinks = task.related_links ?? [];

  // Tab counts
  const checklistDone = checklist.filter((i) => i.is_done).length;
  const checklistTotal = checklist.length;

  const tabs: { key: TabKey; label: string; count?: string }[] = [
    { key: "summary", label: "Сводка" },
    {
      key: "checklist",
      label: "Чек-лист",
      count: checklistTotal > 0 ? `${checklistDone}/${checklistTotal}` : undefined,
    },
    { key: "files", label: "Файлы", count: attachments.length > 0 ? String(attachments.length) : undefined },
    { key: "subtasks", label: "Подзадачи" },
    { key: "related", label: "Связанные", count: relatedLinks.length > 0 ? String(relatedLinks.length) : undefined },
    { key: "chat", label: "Чат" },
    { key: "history", label: "История" },
  ];

  return (
    <div className="flex flex-col h-full">
      {/* Back link */}
      <div className="px-6 pt-4 pb-2">
        <Link
          href="/tasks"
          className="flex items-center gap-1.5 text-sm text-gray-500 hover:text-primary transition-colors"
        >
          <i className="bi bi-arrow-left text-xs" />
          Задачи
        </Link>
      </div>

      {/* Header */}
      <TaskDetailHeader task={task} onMutate={handleMutate} />

      {/* Tabs */}
      <div className="border-b border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800">
        <div className="flex px-6 overflow-x-auto">
          {tabs.map((tab) => (
            <button
              key={tab.key}
              onClick={() => setActiveTab(tab.key)}
              className={
                "px-4 py-3 text-sm whitespace-nowrap border-b-2 transition-colors " +
                (activeTab === tab.key
                  ? "border-primary text-primary font-medium"
                  : "border-transparent text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-200")
              }
            >
              {tab.label}
              {tab.count && (
                <span className="ml-1.5 text-xs text-gray-500">({tab.count})</span>
              )}
            </button>
          ))}
        </div>
      </div>

      {/* Body: main panel + right sidebar */}
      <div className="flex flex-1 overflow-hidden">
        {/* Main panel */}
        <div className="flex-1 overflow-y-auto">
          {activeTab === "summary" && (
            <TaskSummaryTab task={task} onMutate={handleMutate} />
          )}
          {activeTab === "checklist" && (
            <TaskChecklistTab
              activityId={task.id}
              items={checklist}
              onMutate={handleMutate}
            />
          )}
          {activeTab === "files" && (
            <TaskFilesTab
              activityId={task.id}
              attachments={attachments}
              onMutate={handleMutate}
            />
          )}
          {activeTab === "subtasks" && (
            <TaskSubtasksTab task={task} />
          )}
          {activeTab === "related" && (
            <TaskRelatedTab
              activityId={task.id}
              relatedLinks={relatedLinks}
              onMutate={handleMutate}
            />
          )}
          {activeTab === "chat" && (
            <TaskChatTab activityId={task.id} />
          )}
          {activeTab === "history" && (
            <TaskHistoryTab activityId={task.id} />
          )}
        </div>

        {/* Right sidebar */}
        <TaskDetailSidebar task={task} onMutate={handleMutate} />
      </div>
    </div>
  );
}
