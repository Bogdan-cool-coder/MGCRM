"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";

export default function CompanyTypesRedirect() {
  const router = useRouter();
  useEffect(() => {
    router.replace("/admin/references?tab=company-types");
  }, [router]);
  return null;
}
