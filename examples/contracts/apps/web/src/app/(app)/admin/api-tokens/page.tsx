"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";

export default function ApiTokensRedirectPage() {
  const router = useRouter();
  useEffect(() => {
    router.replace("/admin/integrations?tab=api-tokens");
  }, [router]);
  return null;
}
