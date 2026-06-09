"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";

export default function FormsRedirectPage() {
  const router = useRouter();
  useEffect(() => {
    router.replace("/admin/integrations?tab=forms");
  }, [router]);
  return null;
}
