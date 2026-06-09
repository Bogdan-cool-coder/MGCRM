"use client";

import { useEffect } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { Suspense } from "react";

function CalendarRedirectInner() {
  const router = useRouter();
  const searchParams = useSearchParams();

  useEffect(() => {
    const params = new URLSearchParams();
    params.set("tab", "calendar");
    // Сохраняем ?connected=1 при OAuth-редиректе
    const connected = searchParams.get("connected");
    if (connected) params.set("connected", connected);
    router.replace(`/profile?${params.toString()}`);
  }, [router, searchParams]);

  return null;
}

export default function ProfileCalendarRedirect() {
  return (
    <Suspense fallback={null}>
      <CalendarRedirectInner />
    </Suspense>
  );
}
