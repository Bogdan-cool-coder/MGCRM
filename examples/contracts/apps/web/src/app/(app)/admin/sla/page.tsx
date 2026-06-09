"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";

/**
 * /admin/sla → /admin/automations?tab=sla
 * Сохраняем URL для обратной совместимости (закладки, старые ссылки).
 */
export default function SlaRedirectPage() {
  const router = useRouter();

  useEffect(() => {
    router.replace("/admin/automations?tab=sla");
  }, [router]);

  return (
    <div className="p-8 text-gray-500 dark:text-gray-400 text-sm">
      Перенаправление…
    </div>
  );
}
