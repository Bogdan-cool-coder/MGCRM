"use client";

import Link from "next/link";
import useSWR from "swr";
import { PageHeader } from "@/components/PageHeader";
import { AutomationForm } from "@/components/Automations/AutomationForm";
import { fetcher } from "@/lib/api";
import type { Automation } from "@/lib/types";

interface PageProps {
  params: { id: string };
}

export default function EditAutomationPage({ params }: PageProps) {
  const { data: automation, error, isLoading } = useSWR<Automation>(
    `/automations/${params.id}`,
    fetcher,
  );

  return (
    <>
      <PageHeader
        title={automation ? automation.name : "Автоматизация"}
        description={automation?.description ?? "Редактирование автоматизации"}
        actions={
          <div className="flex items-center gap-2">
            <Link
              href={`/admin/automation-runs?automation_id=${params.id}`}
              className="btn-secondary"
            >
              <i className="bi bi-clock-history" /> История запусков
            </Link>
            <Link href="/admin/automations" className="btn-secondary">
              <i className="bi bi-arrow-left" /> К списку
            </Link>
          </div>
        }
      />
      {isLoading && (
        <div className="p-8 space-y-4 max-w-4xl">
          {[1, 2, 3].map((i) => (
            <div key={i} className="card rounded-2xl shadow-elev-1 p-6 space-y-3 animate-pulse">
              <div className="h-4 bg-gray-200 dark:bg-gray-700 rounded w-1/4" />
              <div className="h-10 bg-gray-100 dark:bg-gray-800 rounded" />
              <div className="h-10 bg-gray-100 dark:bg-gray-800 rounded" />
            </div>
          ))}
        </div>
      )}
      {error != null && !isLoading && (
        <div className="p-8">
          <div className="card rounded-2xl shadow-elev-1 p-6 flex items-center gap-3 text-danger">
            <i className="bi bi-exclamation-triangle text-xl" />
            <span className="text-sm">Не удалось загрузить автоматизацию</span>
          </div>
        </div>
      )}
      {automation && <AutomationForm mode="edit" initialData={automation} />}
    </>
  );
}
