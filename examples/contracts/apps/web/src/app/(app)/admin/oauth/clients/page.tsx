"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";

export default function OAuthClientsRedirectPage() {
  const router = useRouter();
  useEffect(() => {
    router.replace("/admin/integrations?tab=oauth");
  }, [router]);
  return null;
}
