"use client";

import { useSearchParams, useRouter } from "next/navigation";
import { PageHeader } from "@/components/PageHeader";
import { RoleGate } from "@/components/RoleGate";
import { ContactPositionsPanel } from "@/components/References/ContactPositionsPanel";
import { CompanyTypesPanel } from "@/components/References/CompanyTypesPanel";
import { TaskCategoriesPanel } from "@/components/References/TaskCategoriesPanel";
import { LostReasonsAdmin } from "@/components/PipelineSettings/LostReasonsAdmin";
import { MeetingQuestionAdmin } from "@/components/PipelineSettings/MeetingQuestionAdmin";

type TabKey =
  | "contact-positions"
  | "company-types"
  | "task-categories"
  | "lost-reasons"
  | "meeting-questions";

const TABS: { key: TabKey; label: string; icon: string }[] = [
  { key: "contact-positions", label: "Должности контактов", icon: "bi-person-badge" },
  { key: "company-types",     label: "Типы компаний",       icon: "bi-buildings" },
  { key: "task-categories",   label: "Категории задач",     icon: "bi-tags" },
  { key: "lost-reasons",      label: "Причины отказа",      icon: "bi-x-circle" },
  { key: "meeting-questions", label: "Вопросы встречи",     icon: "bi-chat-square-text" },
];

const DEFAULT_TAB: TabKey = "contact-positions";

export default function ReferencesPage() {
  const searchParams = useSearchParams();
  const router = useRouter();

  const rawTab = searchParams.get("tab");
  const activeTab: TabKey =
    TABS.some((t) => t.key === rawTab) ? (rawTab as TabKey) : DEFAULT_TAB;

  function setTab(key: TabKey) {
    router.replace(`/admin/references?tab=${key}`);
  }

  return (
    <RoleGate allowed={["admin", "director"]}>
      <div className="flex flex-col h-full">
        <PageHeader
          title="Справочники"
          description="Мелкие реестры: должности, типы компаний, категории задач, причины отказа, вопросы встречи."
        />

        {/* Tab bar — sticky */}
        <div className="sticky top-0 z-20 px-8 pt-0 border-b border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 flex gap-1 flex-wrap shrink-0">
          {TABS.map((t) => (
            <button
              key={t.key}
              onClick={() => setTab(t.key)}
              className={[
                "inline-flex items-center gap-1.5 px-3 py-2.5 text-sm rounded-t-md transition-colors",
                activeTab === t.key
                  ? "border border-b-0 border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-primary dark:text-primary-light font-medium -mb-px"
                  : "text-gray-500 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-200",
              ].join(" ")}
            >
              <i className={`bi ${t.icon} text-sm`} />
              {t.label}
            </button>
          ))}
        </div>

        {/* Panel content */}
        <div className="flex-1 overflow-y-auto px-8 py-6">
          {activeTab === "contact-positions" && <ContactPositionsPanel />}
          {activeTab === "company-types" && <CompanyTypesPanel />}
          {activeTab === "task-categories" && <TaskCategoriesPanel />}
          {activeTab === "lost-reasons" && <LostReasonsAdmin />}
          {activeTab === "meeting-questions" && <MeetingQuestionAdmin />}
        </div>
      </div>
    </RoleGate>
  );
}
