"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";

export default function WebhooksRedirectPage() {
  const router = useRouter();
  useEffect(() => {
    router.replace("/admin/integrations?tab=webhooks");
  }, [router]);
  return null;
}
