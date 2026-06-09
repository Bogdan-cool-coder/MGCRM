"use client";

import { PageHeader } from "@/components/PageHeader";
import { RightsTransferTable } from "@/components/Company/RightsTransferTable";

export default function RightsTransfersPage() {
  return (
    <>
      <PageHeader
        title="Передача прав"
        description="Журнал всех передач: кто, кому, когда и что"
      />
      <div className="p-8">
        <RightsTransferTable />
      </div>
    </>
  );
}
