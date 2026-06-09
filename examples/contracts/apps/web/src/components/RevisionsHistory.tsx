"use client";

import useSWR from "swr";
import { fetcher } from "@/lib/api";
import type { ContractRevision, User } from "@/lib/types";
import { formatDateTime } from "@/lib/dates";

export function RevisionsHistory({ contractId }: { contractId: number }) {
  const { data: revisions } = useSWR<ContractRevision[]>(`/contracts/${contractId}/revisions`, fetcher);
  const { data: users } = useSWR<User[]>("/users", fetcher);

  if (!revisions || revisions.length === 0) return null;

  const userName = (id: number | null) => id ? (users?.find(u => u.id === id)?.full_name ?? `User #${id}`) : "—";

  return (
    <div className="card p-5">
      <h3 className="text-h5 mb-3">История версий</h3>
      <ul className="space-y-3">
        {revisions.map(r => (
          <li key={r.id} className="border-l-2 border-primary/30 pl-3">
            <div className="flex items-center justify-between">
              <span className="font-medium text-sm">Версия {r.version_number}</span>
              {r.has_pdf && (
                <a
                  href={`/api/contracts/${contractId}/revisions/${r.id}/pdf`}
                  target="_blank"
                  rel="noreferrer"
                  className="text-xs text-primary hover:underline"
                >
                  <i className="bi bi-file-earmark-pdf" /> PDF
                </a>
              )}
            </div>
            {r.note && <div className="text-sm text-gray-700">{r.note}</div>}
            <div className="text-xs text-gray-500 mt-0.5">
              {userName(r.created_by_user_id)} • {formatDateTime(r.created_at)}
              {r.template_version && ` • шаблон ${r.template_version}`}
            </div>
          </li>
        ))}
      </ul>
    </div>
  );
}
