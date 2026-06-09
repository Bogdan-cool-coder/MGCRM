"use client";

import { useRouter } from "next/navigation";
import { PageHeader } from "@/components/PageHeader";
import { CourseForm } from "@/components/Onboarding/Admin/CourseForm";
import { RoleGate } from "@/components/RoleGate";

export default function NewCoursePage() {
  const router = useRouter();

  function handleSaved(id: number) {
    router.push(`/admin/onboarding/courses/${id}/edit`);
  }

  function handleCancel() {
    router.push("/admin/onboarding/courses");
  }

  return (
    <RoleGate allowed={["admin", "director"]}>
      <div>
        <PageHeader
          title="Новый курс"
          description="Создай курс для онбординга команды"
        />
        <div className="px-8 py-6 max-w-3xl">
          <CourseForm
            course={null}
            onSaved={handleSaved}
            onCancel={handleCancel}
          />
        </div>
      </div>
    </RoleGate>
  );
}
