"use client";

import { useRouter } from "next/navigation";
import useSWR from "swr";
import { fetcher } from "@/lib/api";
import { EmptyState } from "@/components/EmptyState";
import { formatDate } from "@/lib/dates";
import { formatCurrency } from "@/lib/format";

// ── Types ────────────────────────────────────────────────────────────────────

interface RelatedDeal {
  id: number;
  title: string;
  pipeline_id: number | null;
  pipeline_name: string | null;
  stage_id: number | null;
  stage_name: string | null;
  responsible_name: string | null;
  amount: number | null;
  currency: string | null;
  owner_user_id: number | null;
  company_id: number | null;
  counterparty_id: number | null;
  created_at: string | null;
}

function isRelatedDealArray(v: unknown): v is RelatedDeal[] {
  return Array.isArray(v) && (
    v.length === 0 || (typeof v[0] === "object" && v[0] !== null && "id" in v[0])
  );
}


// ── Props ─────────────────────────────────────────────────────────────────────

interface Props {
  entityType: "contact" | "company";
  entityId: number;
}

const EMPTY_LABELS: Record<"contact" | "company", string> = {
  contact: "У этого контакта пока нет сделок",
  company: "У этой компании пока нет сделок",
};

// ── Component ─────────────────────────────────────────────────────────────────

export function RelatedDealsTab({ entityType, entityId }: Props) {
  const router = useRouter();
  const endpoint = `/${entityType === "contact" ? "contacts" : "companies"}/${entityId}/deals`;
  const { data: rawDeals, error } = useSWR<unknown>(endpoint, fetcher);
  const deals = isRelatedDealArray(rawDeals) ? rawDeals : [];

  if (error) {
    return (
      <div className="text-sm text-danger bg-danger/10 px-3 py-2 rounded">
        Не удалось загрузить сделки.
      </div>
    );
  }

  if (deals.length === 0) {
    return (
      <EmptyState
        icon="bi-kanban"
        title={EMPTY_LABELS[entityType]}
        description="Контакт ещё не связан ни с одной сделкой"
      />
    );
  }

  return (
    <div className="max-w-4xl overflow-x-auto">
      <table className="w-full text-sm">
        <thead>
          <tr className="text-left text-xs text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
            <th className="py-2 pr-4 font-medium">Название</th>
            <th className="py-2 pr-4 font-medium">Воронка</th>
            <th className="py-2 pr-4 font-medium">Этап</th>
            <th className="py-2 pr-4 font-medium">Ответственный</th>
            <th className="py-2 pr-4 font-medium">Сумма</th>
            <th className="py-2 font-medium">Дата</th>
          </tr>
        </thead>
        <tbody>
          {deals.map((deal) => (
            <tr
              key={deal.id}
              onClick={() => router.push(`/deals/${deal.id}`)}
              className="border-b border-gray-100 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800/50 cursor-pointer"
            >
              <td className="py-2 pr-4 text-primary font-medium">{deal.title || `Сделка #${deal.id}`}</td>
              <td className="py-2 pr-4 text-gray-500 dark:text-gray-400">{deal.pipeline_name ?? "—"}</td>
              <td className="py-2 pr-4 text-gray-700 dark:text-gray-300">{deal.stage_name ?? "—"}</td>
              <td className="py-2 pr-4 text-gray-500 dark:text-gray-400">{deal.responsible_name ?? "—"}</td>
              <td className="py-2 pr-4 text-gray-700 dark:text-gray-300">{formatCurrency(deal.amount, deal.currency)}</td>
              <td className="py-2 text-gray-500 dark:text-gray-400 whitespace-nowrap">{formatDate(deal.created_at)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
