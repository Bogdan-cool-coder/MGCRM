"use client";

import { PageHeader } from "@/components/PageHeader";
import { BroadcastForm } from "@/components/Notifications/BroadcastForm";

export default function BroadcastCreatePage() {
  return (
    <>
      <PageHeader title="Создать рассылку" />
      <BroadcastForm />
    </>
  );
}
