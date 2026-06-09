"use client";

import { useParams, useRouter } from "next/navigation";
import useSWR from "swr";
import type { CourseFullOut } from "@/lib/types";
import { fetcher } from "@/lib/api";
import { PageHeader } from "@/components/PageHeader";
import { CourseForm } from "@/components/Onboarding/Admin/CourseForm";
import { CourseStructureBuilder } from "@/components/Onboarding/Admin/CourseStructureBuilder";
import { RoleGate } from "@/components/RoleGate";
import { EmptyState } from "@/components/EmptyState";

export default function EditCoursePage() {
  const params = useParams();
  const router = useRouter();
  const courseId = Number(params.id);

  const { data: course, isLoading, mutate } = useSWR<CourseFullOut>(
    `/admin/onboarding/courses/${courseId}`,
    fetcher
  );

  function handleSaved() {
    mutate();
  }

  function handleCancel() {
    router.push("/admin/onboarding/courses");
  }

  if (isLoading) {
    return (
      <div className="px-8 py-6 space-y-8 animate-pulse max-w-3xl">
        <div className="h-7 bg-gray-200 dark:bg-gray-700 rounded w-2/3" />
        <div className="h-4 bg-gray-100 dark:bg-gray-700 rounded w-1/2" />
        <div className="space-y-4">
          {Array.from({ length: 5 }).map((_, i) => (
            <div key={i} className="h-10 bg-gray-100 dark:bg-gray-700 rounded-lg" />
          ))}
        </div>
      </div>
    );
  }

  if (!course) {
    return (
      <div className="flex items-center justify-center min-h-[400px]">
        <EmptyState icon="bi-exclamation-circle" title="Курс не найден" />
      </div>
    );
  }

  return (
    <RoleGate allowed={["admin", "director"]}>
      <div>
        <PageHeader
          title={`Редактировать: ${course.title}`}
          description="Настройки курса, структура модулей и уроков"
        />

        <div className="px-8 py-6 space-y-8">
          {/* Course settings */}
          <section>
            <h2 className="text-base font-semibold text-gray-800 mb-4">Настройки курса</h2>
            <CourseForm
              course={course}
              onSaved={handleSaved}
              onCancel={handleCancel}
            />
          </section>

          {/* Structure builder */}
          <section>
            <h2 className="text-base font-semibold text-gray-800 mb-4">Структура курса</h2>
            <CourseStructureBuilder
              courseId={courseId}
              modules={course.modules}
              onRefresh={() => mutate()}
            />
          </section>
        </div>
      </div>
    </RoleGate>
  );
}
