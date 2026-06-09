"use client";

import { useEffect } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { Suspense } from "react";

function SecurityRedirectInner() {
  const router = useRouter();
  const searchParams = useSearchParams();

  useEffect(() => {
    const params = new URLSearchParams();
    params.set("tab", "security");
    // Сохраняем ?2fa=enabled при редиректе после настройки 2FA
    const tfa = searchParams.get("2fa");
    if (tfa) params.set("2fa", tfa);
    router.replace(`/profile?${params.toString()}`);
  }, [router, searchParams]);

  return null;
}

export default function ProfileSecurityRedirect() {
  return (
    <Suspense fallback={null}>
      <SecurityRedirectInner />
    </Suspense>
  );
}
