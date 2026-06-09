"use client";

import Link from "next/link";
import { CalldownWizard } from "@/components/Integrations/Calldown/CalldownWizard";

export function TelephonyPanel() {
  return (
    <>
      <div className="flex items-center justify-between mb-6">
        <div>
          <h2 className="text-base font-semibold text-gray-900 dark:text-gray-100">
            Телефония — Calldown
          </h2>
          <p className="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
            Подключи Mango Office или UIS для записи звонков
          </p>
        </div>
        <Link
          href="/admin/integrations/calldown/calls"
          className="btn-secondary"
        >
          <i className="bi bi-telephone-outbound mr-1.5" />
          История звонков
        </Link>
      </div>
      <CalldownWizard />
    </>
  );
}
