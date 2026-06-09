"use client";

import { useEffect, useState } from "react";
import { TwoFactorCard } from "@/components/Security/TwoFactorCard";
import { SsoAccountsCard } from "@/components/Security/SsoAccountsCard";

interface SecurityPanelProps {
  /** Передаётся из хаба — query param ?2fa=enabled */
  show2faSuccess?: boolean;
}

export function SecurityPanel({ show2faSuccess }: SecurityPanelProps) {
  const [successBanner, setSuccessBanner] = useState(show2faSuccess ?? false);

  useEffect(() => {
    if (successBanner) {
      const t = setTimeout(() => setSuccessBanner(false), 5000);
      return () => clearTimeout(t);
    }
  }, [successBanner]);

  return (
    <div className="p-6 max-w-3xl space-y-5">
      {successBanner && (
        <div className="flex items-center gap-2.5 rounded-2xl bg-success/10 border border-success/20 text-success px-4 py-3 text-sm shadow-elev-1">
          <i className="bi bi-check-circle-fill shrink-0" aria-hidden="true" />
          <span>Двухфакторная аутентификация подключена</span>
        </div>
      )}
      <TwoFactorCard />
      <SsoAccountsCard />
    </div>
  );
}
