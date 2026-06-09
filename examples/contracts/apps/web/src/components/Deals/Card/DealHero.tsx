"use client";

import Link from "next/link";
import useSWR from "swr";
import clsx from "clsx";
import { fetcher } from "@/lib/api";
import { formatCurrency } from "@/lib/format";
import type { DealOut, Pipeline, PipelineStage, User } from "@/lib/types";
import { StagePill } from "./StagePill";

// Генерирует инициалы из имени пользователя (аналог Avatar.tsx)
function initials(name: string): string {
  const parts = name.trim().split(/\s+/);
  if (parts.length === 0) return "?";
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
  return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}

// ── OwnerAvatar ───────────────────────────────────────────────────────────────

function OwnerAvatar({ ownerId }: { ownerId: number | null }) {
  const { data: users } = useSWR<User[]>("/users", fetcher);
  const user = ownerId != null ? (users?.find((u) => u.id === ownerId) ?? null) : null;

  if (!user) {
    return (
      <div
        className="w-7 h-7 rounded-full bg-gray-200 dark:bg-gray-600 shrink-0 flex items-center justify-center"
        aria-hidden="true"
      >
        <i className="bi bi-person text-gray-400 text-xs" />
      </div>
    );
  }

  const hasPic = Boolean(user.avatar_path);

  return (
    <div
      className="w-7 h-7 rounded-full shrink-0 overflow-hidden bg-primary text-white inline-flex items-center justify-center font-semibold select-none text-[11px]"
      title={user.full_name}
      aria-label={`Ответственный: ${user.full_name}`}
    >
      {hasPic ? (
        // eslint-disable-next-line @next/next/no-img-element
        <img
          src={`/api/users/${user.id}/avatar`}
          alt={user.full_name}
          className="w-full h-full object-cover"
        />
      ) : (
        <span>{initials(user.full_name)}</span>
      )}
    </div>
  );
}

// ── DealStatusBadge ───────────────────────────────────────────────────────────

function DealStatusBadge({ stage }: { stage: PipelineStage | undefined }) {
  if (!stage) return null;

  const color = stage.color ?? "#6B7A99";

  if (stage.is_won) {
    return (
      <span className="badge badge-success">
        <i className="bi bi-check-circle-fill text-[10px]" aria-hidden="true" />
        Выиграна
      </span>
    );
  }
  if (stage.is_lost) {
    return (
      <span className="badge badge-danger">
        <i className="bi bi-x-circle-fill text-[10px]" aria-hidden="true" />
        Проиграна
      </span>
    );
  }

  return (
    <span
      className="badge"
      style={{
        backgroundColor: `${color}18`,
        color,
      }}
    >
      <span
        className="w-1.5 h-1.5 rounded-full shrink-0"
        style={{ backgroundColor: color }}
        aria-hidden="true"
      />
      В работе
    </span>
  );
}

// ── Props ─────────────────────────────────────────────────────────────────────

interface DealHeroProps {
  deal: DealOut;
  stages: PipelineStage[];
  pipelines: Pipeline[];
  onMove: (stageId: number) => void;
  onBack: () => void;
}

// ── Component ─────────────────────────────────────────────────────────────────

export function DealHero({ deal, stages, pipelines, onMove, onBack }: DealHeroProps) {
  const currentStage = stages.find((s) => s.id === deal.stage_id);
  const pipeline = pipelines.find((p) => p.id === deal.pipeline_id);

  const { data: users } = useSWR<User[]>("/users", fetcher);
  const owner = deal.owner_user_id != null
    ? (users?.find((u) => u.id === deal.owner_user_id) ?? null)
    : null;

  const amount = formatCurrency(deal.amount, deal.currency);

  return (
    <div className="mx-8 mt-6 card rounded-2xl shadow-elev-2 p-6 bg-white dark:bg-gray-800">
      <div className="flex items-start gap-5">
        {/* Иконка-аватар сделки */}
        <div
          className={clsx(
            "w-14 h-14 rounded-xl shrink-0 mt-0.5",
            "inline-flex items-center justify-center",
            currentStage?.is_won
              ? "bg-success-50 text-success-600 dark:bg-success-500/10 dark:text-success-500"
              : currentStage?.is_lost
              ? "bg-danger-50 text-danger-600 dark:bg-danger-500/10 dark:text-danger-500"
              : "bg-primary/8 text-primary dark:bg-primary/15 dark:text-blue-300",
          )}
          aria-hidden="true"
        >
          <i
            className={clsx(
              "text-2xl",
              currentStage?.is_won
                ? "bi bi-trophy-fill"
                : currentStage?.is_lost
                ? "bi bi-x-circle"
                : "bi bi-briefcase",
            )}
          />
        </div>

        {/* Основной блок */}
        <div className="flex-1 min-w-0">
          {/* Eyebrow */}
          <div className="text-[10px] text-gray-400 dark:text-gray-500 uppercase tracking-widest mb-0.5 font-medium">
            {pipeline?.name ?? "Сделка"}
          </div>

          {/* Название */}
          <h1 className="text-xl font-semibold text-gray-900 dark:text-white truncate leading-tight">
            {deal.title || `Сделка #${deal.id}`}
          </h1>

          {/* Сумма + этап + статус */}
          <div className="flex flex-wrap items-center gap-x-4 gap-y-1.5 mt-2">
            {deal.amount != null && (
              <span className="text-lg font-bold tabular-nums text-gray-900 dark:text-white">
                {amount}
              </span>
            )}

            {/* Этап-пилюля (кликабельный) */}
            <StagePill
              stages={stages}
              currentStageId={deal.stage_id}
              onSelect={onMove}
            />

            {/* Статус-бейдж */}
            <DealStatusBadge stage={currentStage} />
          </div>

          {/* Ответственный + даты */}
          <div className="flex flex-wrap items-center gap-x-4 gap-y-1 mt-3 text-sm">
            {/* Ответственный */}
            <div className="flex items-center gap-1.5 text-gray-600 dark:text-gray-300">
              <OwnerAvatar ownerId={deal.owner_user_id} />
              <span className="text-xs">
                {owner?.full_name ?? (deal.owner_user_id ? `#${deal.owner_user_id}` : "Не назначен")}
              </span>
            </div>

            {/* Ожидаемое закрытие */}
            {deal.expected_close_date && (
              <span className="flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                <i className="bi bi-calendar3 text-gray-400 text-[10px]" aria-hidden="true" />
                Закрытие:{" "}
                {new Date(deal.expected_close_date).toLocaleDateString("ru-RU")}
              </span>
            )}

            {/* Ссылка на компанию */}
            {deal.company_id && (
              <Link
                href={`/companies/${deal.company_id}`}
                className="flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400 hover:text-primary dark:hover:text-primary transition-colors"
              >
                <i className="bi bi-building text-gray-400 text-[10px]" aria-hidden="true" />
                Открыть компанию
              </Link>
            )}
          </div>

          {/* Теги */}
          {deal.tags && deal.tags.length > 0 && (
            <div className="flex flex-wrap gap-1.5 mt-2.5">
              {deal.tags.map((tag) => (
                <span
                  key={tag}
                  className="badge badge-neutral"
                >
                  {tag}
                </span>
              ))}
            </div>
          )}
        </div>

        {/* Кнопки действий */}
        <div className="flex items-center gap-2 shrink-0 ml-2">
          <button
            type="button"
            className="btn-ghost text-sm"
            onClick={onBack}
          >
            <i className="bi bi-arrow-left mr-1" aria-hidden="true" />
            Назад
          </button>
          {deal.company_id && (
            <Link href={`/companies/${deal.company_id}`} className="btn-secondary text-sm">
              <i className="bi bi-building mr-1" aria-hidden="true" />
              Компания
            </Link>
          )}
        </div>
      </div>

      {/* Причина отказа — полоса под основным блоком */}
      {deal.lost_reason && (
        <div className="mt-4 pt-3 border-t border-gray-100 dark:border-gray-700 flex items-start gap-2 text-sm">
          <i className="bi bi-info-circle text-danger shrink-0 mt-0.5" aria-hidden="true" />
          <span className="text-danger-700 dark:text-danger-500">
            <span className="font-medium">Причина отказа:</span> {deal.lost_reason}
          </span>
        </div>
      )}
    </div>
  );
}
