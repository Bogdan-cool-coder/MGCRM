"use client";

import Link from "next/link";
import useSWR from "swr";
import { fetcher } from "@/lib/api";
import { StatusBadge } from "@/components/StatusBadge";
import { EmptyState } from "@/components/EmptyState";
import type { Contract } from "@/lib/types";
import { formatDate } from "@/lib/dates";

// ── Props ─────────────────────────────────────────────────────────────────────

interface Props {
  entityType: "contact" | "company";
  entityId: number;
  counterpartyId?: number | null;
}

// ── Component ─────────────────────────────────────────────────────────────────

export function ContactDocumentsTab({ entityType, entityId }: Props) {
  const endpoint = entityType === "contact"
    ? `/contacts/${entityId}/contracts`
    : `/companies/${entityId}/contracts`;

  const { data: rawContracts, error } = useSWR<unknown>(endpoint, fetcher);
  const contracts = Array.isArray(rawContracts) ? (rawContracts as Contract[]) : [];

  if (error) {
    return (
      <div className="text-sm text-danger bg-danger/10 px-3 py-2 rounded">
        Не удалось загрузить договоры.
      </div>
    );
  }

  return (
    <div className="max-w-3xl space-y-3">
      {entityType === "company" && (
        <div className="flex justify-end">
          {/* CONTACTS 2.0 Ф3-A: компания — сторона договора, передаём company_id напрямую. */}
          <Link
            href={`/contracts/new?company_id=${entityId}`}
            className="btn-primary text-sm"
          >
            <i className="bi bi-plus-lg mr-1" /> Новый договор
          </Link>
        </div>
      )}

      {contracts.length === 0 ? (
        <EmptyState
          icon="bi-file-earmark-text"
          title={
            entityType === "contact"
              ? "У этого контакта пока нет договоров"
              : "У этой компании пока нет договоров"
          }
        />
      ) : (
        <div className="space-y-2">
          {contracts.map((c) => (
            <Link
              key={c.id}
              href={`/contracts/${c.id}`}
              className="flex items-center justify-between gap-4 border border-gray-200 dark:border-gray-700 rounded-lg px-4 py-3 hover:border-primary/40 dark:hover:border-primary/40 hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors"
            >
              <div className="min-w-0 flex-1">
                <div className="font-medium text-primary text-sm">
                  {c.number
                    ? `Договор №${c.number}`
                    : `Договор #${c.id}`}
                </div>
                <div className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                  Создан: {formatDate(c.created_at)}
                </div>
              </div>
              <StatusBadge status={c.status} />
            </Link>
          ))}
        </div>
      )}
    </div>
  );
}
