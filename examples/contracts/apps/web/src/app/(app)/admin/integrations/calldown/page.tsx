"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";

export default function CalldownRedirectPage() {
  const router = useRouter();
  useEffect(() => {
    router.replace("/admin/integrations?tab=telephony");
  }, [router]);
  return null;
}
