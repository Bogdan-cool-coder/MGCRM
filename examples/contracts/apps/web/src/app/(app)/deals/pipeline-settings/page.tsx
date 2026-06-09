"use client";

import { useEffect } from "react";
import { useRouter, useSearchParams } from "next/navigation";

/**
 * Редирект на канонический редактор воронок /admin/pipelines.
 * Сохраняем pipeline_id если он был передан.
 */
export default function PipelineSettingsRedirect() {
  const router = useRouter();
  const searchParams = useSearchParams();

  useEffect(() => {
    const pid = searchParams.get("pipeline_id");
    const dest = pid
      ? `/admin/pipelines?pipeline_id=${pid}`
      : "/admin/pipelines";
    router.replace(dest);
  }, [router, searchParams]);

  return (
    <div className="flex items-center justify-center h-full py-24 text-gray-400 text-sm">
      Перенаправление…
    </div>
  );
}
