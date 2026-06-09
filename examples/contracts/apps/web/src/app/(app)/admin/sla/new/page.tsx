"use client";

import Link from "next/link";
import { mutate } from "swr";
import { PageHeader } from "@/components/PageHeader";
import { SlaWizard } from "@/components/Sla/SlaWizard";

export default function SlaNewPage() {
  function handleSuccess() {
    void mutate("/automations?trigger_kind=idle_in_stage_days");
  }

  return (
    <>
      <PageHeader
        title="Новое SLA-правило"
        actions={
          <Link href="/admin/sla" className="btn-ghost">
            <i className="bi bi-arrow-left mr-1" />
            К списку
          </Link>
        }
      />
      <SlaWizard mode="create" onSuccess={handleSuccess} />
    </>
  );
}
