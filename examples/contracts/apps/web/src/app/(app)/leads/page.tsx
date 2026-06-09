"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";

/**
 * DEALS 2.0: /leads редиректит на /deals.
 * Lead = Deal в этапе «Новые лиды» sales-воронки (схлопнули в Ф0-миграции).
 */
export default function LeadsRedirectPage() {
  const router = useRouter();
  useEffect(() => {
    router.replace("/deals");
  }, [router]);
  return null;
}
