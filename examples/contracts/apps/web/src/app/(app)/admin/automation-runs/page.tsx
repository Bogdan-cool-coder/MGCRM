"use client";

import { useEffect } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { Suspense } from "react";

/**
 * /admin/automation-runs → /admin/automations?tab=runs
 * Пробрасываем automation_id если есть в query.
 */
function AutomationRunsRedirectContent() {
  const router = useRouter();
  const searchParams = useSearchParams();

  useEffect(() => {
    const automationId = searchParams.get("automation_id");
    const dest = automationId
      ? `/admin/automations?tab=runs&automation_id=${automationId}`
      : "/admin/automations?tab=runs";
    router.replace(dest);
  }, [router, searchParams]);

  return (
    <div className="p-8 text-gray-500 dark:text-gray-400 text-sm">
      Перенаправление…
    </div>
  );
}

export default function AutomationRunsRedirectPage() {
  return (
    <Suspense fallback={<div className="p-8 text-gray-500 dark:text-gray-400">Загрузка…</div>}>
      <AutomationRunsRedirectContent />
    </Suspense>
  );
}
