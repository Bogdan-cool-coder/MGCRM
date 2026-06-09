"use client";

import Link from "next/link";
import useSWR from "swr";
import { fetcher } from "@/lib/api";
import type { Company, DealOut, User } from "@/lib/types";
import { formatDate } from "@/lib/dates";

// ── Prop ──────────────────────────────────────────────────────────────────────

interface DealRightRailProps {
  deal: DealOut;
}

// ── RailRow — строка метаданных ───────────────────────────────────────────────

function RailRow({
  label,
  children,
}: {
  label: string;
  children: React.ReactNode;
}) {
  return (
    <div className="space-y-0.5">
      <div className="text-[10px] uppercase tracking-widest text-gray-400 dark:text-gray-500 font-medium">
        {label}
      </div>
      <div className="text-sm text-gray-700 dark:text-gray-300">{children}</div>
    </div>
  );
}

// ── Component ─────────────────────────────────────────────────────────────────

export function DealRightRail({ deal }: DealRightRailProps) {
  const { data: users } = useSWR<User[]>("/users", fetcher);
  const { data: company } = useSWR<Company>(
    deal.company_id ? `/companies/${deal.company_id}` : null,
    fetcher,
  );

  const owner = deal.owner_user_id != null
    ? (users?.find((u) => u.id === deal.owner_user_id) ?? null)
    : null;

  const companyName = company?.name ?? company?.legal_name ?? null;

  return (
    <aside className="hidden lg:flex w-64 shrink-0 self-start sticky top-20">
      <div className="card rounded-2xl shadow-elev-1 p-5 space-y-5 mr-8 mt-6 w-full bg-white dark:bg-gray-800">

        {/* Ответственный */}
        <RailRow label="Ответственный">
          {owner ? (
            <span>{owner.full_name}</span>
          ) : (
            <span className="text-gray-400 dark:text-gray-500">—</span>
          )}
        </RailRow>

        {/* Компания */}
        {deal.company_id && (
          <RailRow label="Компания">
            {companyName ? (
              <Link
                href={`/companies/${deal.company_id}`}
                className="text-primary hover:underline dark:text-blue-300 truncate block"
              >
                {companyName}
              </Link>
            ) : (
              <span className="text-gray-400 dark:text-gray-500">—</span>
            )}
          </RailRow>
        )}

        {/* Продукт */}
        {deal.product && (
          <RailRow label="Продукт">
            <span className="badge badge-info">{deal.product}</span>
          </RailRow>
        )}

        {/* Теги */}
        {deal.tags && deal.tags.length > 0 && (
          <div className="space-y-1.5">
            <div className="text-[10px] uppercase tracking-widest text-gray-400 dark:text-gray-500 font-medium">
              Теги
            </div>
            <div className="flex flex-wrap gap-1">
              {deal.tags.map((tag) => (
                <span key={tag} className="badge badge-neutral">
                  {tag}
                </span>
              ))}
            </div>
          </div>
        )}

        {/* Ключевые даты */}
        <div className="space-y-3 pt-1 border-t border-gray-100 dark:border-gray-700">
          <RailRow label="Создана">
            {formatDate(deal.created_at)}
          </RailRow>

          {deal.expected_close_date && (
            <RailRow label="Ожид. закрытие">
              <span className="tabular-nums">{formatDate(deal.expected_close_date)}</span>
            </RailRow>
          )}

          {deal.expected_sign_date && (
            <RailRow label="Ожид. подписание">
              <span className="tabular-nums">{formatDate(deal.expected_sign_date)}</span>
            </RailRow>
          )}

          {deal.expected_payment_date && (
            <RailRow label="Ожид. оплата">
              <span className="tabular-nums">{formatDate(deal.expected_payment_date)}</span>
            </RailRow>
          )}

          {deal.stage_changed_at && (
            <RailRow label="Этап изменён">
              <span className="tabular-nums">{formatDate(deal.stage_changed_at)}</span>
            </RailRow>
          )}

          {deal.closed_at && (
            <RailRow label="Закрыта">
              <span className="tabular-nums">{formatDate(deal.closed_at)}</span>
            </RailRow>
          )}
        </div>

        {/* ID сделки */}
        <div className="pt-1 border-t border-gray-100 dark:border-gray-700">
          <div className="text-[10px] uppercase tracking-widest text-gray-400 dark:text-gray-500 font-medium mb-0.5">
            Сделка
          </div>
          <div className="text-xs text-gray-400 dark:text-gray-500 font-mono">
            #{deal.id}
          </div>
        </div>
      </div>
    </aside>
  );
}
