"use client";

import useSWR from "swr";
import { api, fetcher } from "@/lib/api";
import type { ContractRemark, User } from "@/lib/types";
import { formatDateTime } from "@/lib/dates";

export function RemarksChecklist({
  contractId, isAuthor, onChanged,
}: {
  contractId: number;
  isAuthor: boolean;
  onChanged?: () => void;
}) {
  const { data: remarks, mutate } = useSWR<ContractRemark[]>(`/contracts/${contractId}/remarks`, fetcher);
  const { data: users } = useSWR<User[]>("/users", fetcher);

  if (!remarks || remarks.length === 0) return null;

  const userName = (id: number) => users?.find(u => u.id === id)?.full_name ?? `User #${id}`;

  // Группируем по attempt (попытке согласования)
  const byAttempt = new Map<number, ContractRemark[]>();
  for (const r of remarks) {
    const arr = byAttempt.get(r.attempt) ?? [];
    arr.push(r);
    byAttempt.set(r.attempt, arr);
  }
  const attempts = [...byAttempt.keys()].sort((a, b) => b - a);
  const lastAttempt = attempts[0];

  async function toggle(id: number) {
    await api(`/contracts/${contractId}/remarks/${id}/resolve`, { method: "POST" });
    await mutate();
    onChanged?.();
  }

  const openCount = (byAttempt.get(lastAttempt) ?? []).filter(r => !r.is_resolved).length;

  return (
    <div className="card p-5">
      <div className="flex items-center justify-between mb-3">
        <h3 className="text-h5">Замечания</h3>
        {openCount > 0 && (
          <span className="badge bg-danger/30 text-gray-900">Открыто: {openCount}</span>
        )}
      </div>

      {attempts.map(attempt => (
        <div key={attempt} className={attempt === lastAttempt ? "" : "opacity-60 mt-3"}>
          {attempts.length > 1 && (
            <div className="text-xs text-gray-500 mb-1">
              {attempt === lastAttempt ? "Текущая попытка" : `Попытка ${attempt}`}
            </div>
          )}
          <ul className="space-y-2">
            {(byAttempt.get(attempt) ?? []).map(r => (
              <li key={r.id} className="flex items-start gap-2 text-sm">
                <input
                  type="checkbox"
                  checked={r.is_resolved}
                  disabled={!isAuthor || attempt !== lastAttempt}
                  onChange={() => toggle(r.id)}
                  className="mt-1 accent-primary"
                  title={isAuthor ? "Отметить исправленным" : "Только автор может отмечать"}
                />
                <div className={r.is_resolved ? "line-through text-gray-500" : ""}>
                  <div>{r.text}</div>
                  <div className="text-xs text-gray-500 mt-0.5">
                    {userName(r.author_user_id)} • {formatDateTime(r.created_at)}
                    {r.is_resolved && " • ✅ исправлено"}
                  </div>
                </div>
              </li>
            ))}
          </ul>
        </div>
      ))}

      {isAuthor && openCount > 0 && (
        <div className="text-xs text-gray-500 mt-3">
          Отметьте все замечания исправленными, затем нажмите «Отправить повторно» в блоке согласования.
        </div>
      )}
    </div>
  );
}
