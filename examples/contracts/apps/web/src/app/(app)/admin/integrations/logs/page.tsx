"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";

export default function IntegrationLogsRedirectPage() {
  const router = useRouter();
  useEffect(() => {
    router.replace("/admin/integrations?tab=logs");
  }, [router]);
  return null;
}
