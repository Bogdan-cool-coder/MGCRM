"use client";

import Link from "next/link";
import { PageHeader } from "@/components/PageHeader";
import { AutomationForm } from "@/components/Automations/AutomationForm";

export default function NewAutomationPage() {
  return (
    <>
      <PageHeader
        title="Новая автоматизация"
        description="Триггер + действие. Сохраните, затем тестовый прогон станет доступен."
        actions={
          <Link href="/admin/automations" className="btn-secondary">
            <i className="bi bi-arrow-left" /> К списку
          </Link>
        }
      />
      <AutomationForm mode="new" />
    </>
  );
}
