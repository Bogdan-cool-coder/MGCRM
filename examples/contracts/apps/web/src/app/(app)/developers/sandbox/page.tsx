"use client";

import { useState } from "react";
import { PageHeader } from "@/components/PageHeader";
import { SandboxPlayground } from "@/components/Developers/SandboxPlayground";
import { CreateApiTokenModal } from "@/components/ApiTokens/CreateApiTokenModal";
import type { APITokenCreateResponse } from "@/lib/types";

export const dynamic = "force-dynamic";

export default function SandboxPage() {
  const [createTokenOpen, setCreateTokenOpen] = useState(false);

  function handleTokenCreated(_token: APITokenCreateResponse) {
    setCreateTokenOpen(false);
  }

  return (
    <>
      <PageHeader
        title="API Sandbox"
        description="Тестируй запросы без риска для реальных данных"
        actions={
          <button className="btn-secondary" onClick={() => setCreateTokenOpen(true)}>
            <i className="bi bi-key-fill mr-1" />
            Создать sandbox-токен
          </button>
        }
      />
      <div className="px-8 py-6">
        <SandboxPlayground />
      </div>

      <CreateApiTokenModal
        open={createTokenOpen}
        onClose={() => setCreateTokenOpen(false)}
        onCreated={handleTokenCreated}
      />
    </>
  );
}
