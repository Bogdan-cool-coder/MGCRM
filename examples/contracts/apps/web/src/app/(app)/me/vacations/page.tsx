"use client";

import { PageHeader } from "@/components/PageHeader";
import { VacationsList } from "@/components/Company/VacationsList";

export default function MyVacationsPage() {
  return (
    <>
      <PageHeader
        title="Мои отпуска"
        description="Заявки на отпуск, больничные и командировки"
      />
      <div className="p-8 max-w-4xl">
        <VacationsList />
      </div>
    </>
  );
}
