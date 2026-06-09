"use client";

import { PageHeader } from "@/components/PageHeader";
import { AbsenceCalendar } from "@/components/Company/AbsenceCalendar";

export default function AbsencesPage() {
  return (
    <>
      <PageHeader
        title="Отсутствия в компании"
        description="Кто и когда в отпуске — обзор по всей команде"
      />
      <div className="p-8 max-w-full overflow-x-hidden">
        <AbsenceCalendar />
      </div>
    </>
  );
}
