"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";

interface PageProps {
  params: { id: string };
}

/**
 * Редирект на канонический редактор воронок /admin/pipelines?pipeline_id=[id].
 * VisualCanvas теперь встроен в объединённый редактор.
 */
export default function PipelineVisualRedirect({ params }: PageProps) {
  const router = useRouter();

  useEffect(() => {
    router.replace(`/admin/pipelines?pipeline_id=${params.id}`);
  }, [router, params.id]);

  return (
    <div className="flex items-center justify-center h-full py-24 text-gray-400 text-sm">
      Перенаправление…
    </div>
  );
}
