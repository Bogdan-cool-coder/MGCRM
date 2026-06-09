"use client";

import { useState } from "react";
import useSWR from "swr";
import { EmptyState } from "@/components/EmptyState";
import { ApiTokensTable } from "@/components/ApiTokens/ApiTokensTable";
import { CreateApiTokenModal } from "@/components/ApiTokens/CreateApiTokenModal";
import { TokenRevealModal } from "@/components/ApiTokens/TokenRevealModal";
import { fetcher } from "@/lib/api";
import type { APIToken, APITokenCreateResponse } from "@/lib/types";

export function ApiTokensPanel() {
  const { data: tokens, mutate, isLoading, error } = useSWR<APIToken[]>("/api/api-tokens", fetcher);

  const [createOpen, setCreateOpen] = useState(false);
  const [revealToken, setRevealToken] = useState<string | null>(null);

  function handleCreated(result: APITokenCreateResponse) {
    setCreateOpen(false);
    setRevealToken(result.plaintext_token);
    void mutate();
  }

  return (
    <>
      <div className="flex items-center justify-between mb-6">
        <div>
          <h2 className="text-base font-semibold text-gray-900 dark:text-gray-100">API Токены</h2>
          <p className="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
            Управление токенами для внешних интеграций (1C, Bitrix, скрипты)
          </p>
        </div>
        <button className="btn-primary" onClick={() => setCreateOpen(true)}>
          <i className="bi bi-plus-lg" /> Создать токен
        </button>
      </div>

      {isLoading && (
        <div className="card rounded-2xl shadow-elev-1 overflow-hidden border border-gray-100 dark:border-gray-800 animate-pulse">
          {[1, 2, 3].map((i) => (
            <div key={i} className="h-14 border-b border-gray-100 dark:border-gray-800 last:border-0 bg-white dark:bg-gray-900" />
          ))}
        </div>
      )}

      {error && !isLoading && (
        <div className="rounded-lg bg-danger/10 text-danger px-4 py-3 text-sm flex items-center gap-2">
          <i className="bi bi-exclamation-circle shrink-0" />
          Не удалось загрузить токены
        </div>
      )}

      {!isLoading && !error && (!tokens || tokens.length === 0) && (
        <EmptyState
          icon="bi-key-fill"
          title="Нет API токенов"
          description="Создайте первый токен для подключения внешних систем"
          cta={
            <button className="btn-primary" onClick={() => setCreateOpen(true)}>
              <i className="bi bi-plus-lg" /> Создать токен
            </button>
          }
        />
      )}

      {!isLoading && tokens && tokens.length > 0 && (
        <ApiTokensTable tokens={tokens} onChanged={() => void mutate()} />
      )}

      <CreateApiTokenModal
        open={createOpen}
        onClose={() => setCreateOpen(false)}
        onCreated={handleCreated}
      />

      <TokenRevealModal
        open={revealToken !== null}
        token={revealToken ?? ""}
        onClose={() => setRevealToken(null)}
      />
    </>
  );
}
