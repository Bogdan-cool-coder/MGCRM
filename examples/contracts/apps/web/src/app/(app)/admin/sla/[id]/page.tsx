"use client";

import Link from "next/link";
import { mutate } from "swr";
import useSWR from "swr";
import { useParams } from "next/navigation";
import { PageHeader } from "@/components/PageHeader";
import { SlaWizard } from "@/components/Sla/SlaWizard";
import { fetcher } from "@/lib/api";
import type { Automation } from "@/lib/types";

export default function SlaEditPage() {
  const params = useParams<{ id: string }>();
  const id = params.id;

  const { data: automation, isLoading, error } = useSWR<Automation>(
    id ? `/automations/${id}` : null,
    fetcher,
  );

  function handleSuccess() {
    void mutate("/automations?trigger_kind=idle_in_stage_days");
    void mutate(`/automations/${id}`);
  }

  return (
    <>
      <PageHeader
        title="Редактировать SLA-правило"
        actions={
          <Link href="/admin/sla" className="btn-ghost">
            <i className="bi bi-arrow-left mr-1" />
            К списку
          </Link>
        }
      />

      {isLoading && (
        <div className="p-8 max-w-2xl mx-auto space-y-6">
          {/* Stepper skeleton */}
          <div className="flex items-center gap-2">
            {[1, 2, 3].map((i) => (
              <div key={i} className="flex flex-col items-center gap-1.5 flex-1">
                <div className="w-9 h-9 rounded-full animate-pulse bg-gray-100 dark:bg-gray-800" />
                <div className="hidden sm:block h-3 w-20 rounded animate-pulse bg-gray-100 dark:bg-gray-800" />
              </div>
            ))}
          </div>
          <div className="card rounded-2xl shadow-elev-1 p-6 space-y-4">
            {[1, 2, 3].map((i) => (
              <div key={i} className="animate-pulse h-10 rounded-md bg-gray-100 dark:bg-gray-800" />
            ))}
          </div>
        </div>
      )}

      {error && (
        <div className="p-8">
          <div className="flex items-center gap-2 text-danger text-sm rounded-lg border border-danger/20 bg-danger/5 px-4 py-3 max-w-sm">
            <i className="bi bi-exclamation-triangle-fill shrink-0" />
            Не удалось загрузить правило.
          </div>
        </div>
      )}

      {automation && (
        <SlaWizard
          mode="edit"
          initialData={automation}
          onSuccess={handleSuccess}
        />
      )}
    </>
  );
}
