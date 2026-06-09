"use client";

import { useCallback, useMemo, useState } from "react";
import { useParams, useRouter } from "next/navigation";
import useSWR from "swr";
import { BlurFade } from "@/components/magicui/BlurFade";
import { EmptyState } from "@/components/EmptyState";
import { DealHero } from "@/components/Deals/Card/DealHero";
import { DealRightRail } from "@/components/Deals/Card/DealRightRail";
import { DealMainInfo } from "@/components/Deals/Card/DealMainInfo";
import { DealProductsBlock } from "@/components/Deals/Card/DealProductsBlock";
import { DealContactsCard } from "@/components/Deals/Card/DealContactsCard";
import { MoveErrorAlert } from "@/components/Deals/Card/MoveErrorAlert";
import { LostReasonModal } from "@/components/Deals/LostReasonModal";
import { SuccessGateModal } from "@/components/Deals/SuccessGateModal";
import { Timeline } from "@/components/Timeline/Timeline";
import { FilesTab } from "@/components/CRM/FilesTab";
import { api, ApiError, fetcher } from "@/lib/api";
import {
  STANDARD_DEAL_CARD_FIELDS,
  type DealCardConfig,
  type DealContactOut,
  type DealOut,
  type DealProductOut,
  type Pipeline,
  type PipelineStage,
  type RequiredFieldsMissingError,
  type WinGateFailedError,
} from "@/lib/types";

// ── Tabs ──────────────────────────────────────────────────────────────────────

type DealTab = "overview" | "timeline" | "files";

const TABS: { key: DealTab; label: string; icon: string }[] = [
  { key: "overview",  label: "Обзор",       icon: "bi-info-circle"       },
  { key: "timeline",  label: "Активности",  icon: "bi-clock-history"     },
  { key: "files",     label: "Файлы",       icon: "bi-folder"            },
];

// ── Helpers ───────────────────────────────────────────────────────────────────

// RU-подписи для кодов полей (MoveErrorAlert).
function fieldLabel(field: string, config: DealCardConfig | undefined): string {
  const fromConfig = config?.deal_card_fields.find((f) => f.field === field)?.label;
  if (fromConfig) return fromConfig;
  return STANDARD_DEAL_CARD_FIELDS.find((f) => f.field === field)?.label ?? field;
}

// ── Skeleton ──────────────────────────────────────────────────────────────────

function DealCardSkeleton() {
  return (
    <div className="flex flex-col bg-gray-50 dark:bg-gray-900">
      {/* Hero skeleton */}
      <div className="mx-8 mt-6 rounded-2xl bg-white dark:bg-gray-800 shadow-elev-2 p-6">
        <div className="flex items-start gap-5">
          <div className="w-14 h-14 rounded-xl bg-gray-100 dark:bg-gray-700 animate-pulse shrink-0" />
          <div className="flex-1 space-y-2.5">
            <div className="h-2.5 bg-gray-100 dark:bg-gray-700 rounded animate-pulse w-20" />
            <div className="h-5 bg-gray-100 dark:bg-gray-700 rounded animate-pulse w-64" />
            <div className="flex gap-3 mt-2">
              <div className="h-4 bg-gray-100 dark:bg-gray-700 rounded-full animate-pulse w-24" />
              <div className="h-4 bg-gray-100 dark:bg-gray-700 rounded-full animate-pulse w-16" />
            </div>
          </div>
        </div>
      </div>
      {/* Tabs skeleton */}
      <div className="px-8 mt-4 flex gap-4">
        {[80, 96, 64].map((w, i) => (
          <div
            key={i}
            className="h-3 bg-gray-100 dark:bg-gray-700 rounded animate-pulse"
            style={{ width: w }}
          />
        ))}
      </div>
    </div>
  );
}

// ── Page ──────────────────────────────────────────────────────────────────────

export default function DealCardPage() {
  const { id } = useParams<{ id: string }>();
  const router = useRouter();
  const dealId = Number(id);

  const dealKey = dealId ? `/deals/${dealId}` : null;
  const { data: deal, error: dealError, isLoading, mutate: mutateDeal } = useSWR<DealOut>(dealKey, fetcher);

  const { data: stages } = useSWR<PipelineStage[]>(
    deal?.pipeline_id ? `/pipelines/${deal.pipeline_id}/stages` : null,
    fetcher,
  );
  const { data: pipelines } = useSWR<Pipeline[]>("/pipelines", fetcher);
  const { data: config } = useSWR<DealCardConfig>(
    deal?.pipeline_id ? `/pipelines/${deal.pipeline_id}/deal-card-config` : null,
    fetcher,
  );

  // Позиции и контакты — нужны для авто-суммы и required-валидации contacts.
  const { data: products } = useSWR<DealProductOut[]>(dealKey ? `/deals/${dealId}/products` : null, fetcher);
  const { data: contacts } = useSWR<DealContactOut[]>(dealKey ? `/deals/${dealId}/contacts` : null, fetcher);

  // ── Tabs ──────────────────────────────────────────────────────────────────
  const [tab, setTab] = useState<DealTab>("overview");

  // ── Move error state ──────────────────────────────────────────────────────
  const [missingFields, setMissingFields] = useState<string[]>([]);
  const [lostTarget, setLostTarget] = useState<number | null>(null);
  const [winGate, setWinGate] = useState<{ stageId: number; gateInfo: WinGateFailedError } | null>(null);
  const [moveError, setMoveError] = useState<string | null>(null);

  const hasProducts = (products?.length ?? 0) > 0;
  const missingSet = useMemo(() => new Set(missingFields), [missingFields]);

  // ── Inline PATCH handlers ─────────────────────────────────────────────────
  const patchDeal = useCallback(
    async (body: Record<string, unknown>) => {
      try {
        await api(`/deals/${dealId}`, { method: "PATCH", body });
        await mutateDeal();
        setMissingFields((prev) => prev.filter((f) => !(f in body)));
      } catch (e) {
        throw new Error(
          e instanceof ApiError
            ? String((e.detail as { detail?: string })?.detail ?? e.message)
            : "Не удалось сохранить",
        );
      }
    },
    [dealId, mutateDeal],
  );

  const patchCompany = useCallback(
    async (body: Record<string, unknown>) => {
      if (!deal?.company_id) return;
      try {
        await api(`/companies/${deal.company_id}`, { method: "PATCH", body });
        await mutateDeal();
      } catch (e) {
        throw new Error(
          e instanceof ApiError
            ? String((e.detail as { detail?: string })?.detail ?? e.message)
            : "Не удалось сохранить",
        );
      }
    },
    [deal?.company_id, mutateDeal],
  );

  // ── Move handler with 3 error cases ──────────────────────────────────────
  const handleMove = useCallback(
    async (stageId: number) => {
      const target = stages?.find((s) => s.id === stageId);
      setMoveError(null);
      setMissingFields([]);

      // is_lost → модалка причины (она сама вызывает /move)
      if (target?.is_lost) {
        setLostTarget(stageId);
        return;
      }

      try {
        await api(`/deals/${dealId}/move`, { method: "POST", body: { stage_id: stageId } });
        await mutateDeal();
      } catch (e) {
        if (e instanceof ApiError) {
          const detail = e.detail as Record<string, unknown> | null;
          if (e.status === 422 && detail && detail.code === "REQUIRED_FIELDS_MISSING") {
            const d = detail as unknown as RequiredFieldsMissingError;
            setMissingFields(d.missing_fields ?? []);
            return;
          }
          if (e.status === 409 && detail && detail.code === "WIN_GATE_FAILED") {
            setWinGate({ stageId, gateInfo: detail as unknown as WinGateFailedError });
            return;
          }
          setMoveError(String((detail as { detail?: string })?.detail ?? e.message));
          return;
        }
        setMoveError("Не удалось перевести сделку");
      }
    },
    [stages, dealId, mutateDeal],
  );

  // ── Loading ───────────────────────────────────────────────────────────────
  if (isLoading) {
    return <DealCardSkeleton />;
  }

  // ── Error ─────────────────────────────────────────────────────────────────
  if (dealError || !deal) {
    return (
      <div className="flex flex-col min-h-screen bg-gray-50 dark:bg-gray-900 p-8">
        <div className="mx-8 mt-6 text-sm text-danger bg-danger-50 dark:bg-danger-500/10 px-3 py-2 rounded-lg border border-danger-500/20">
          Сделка не найдена или у вас нет доступа.
        </div>
        <button
          className="btn-ghost text-sm mt-3 ml-8 inline-flex items-center gap-1 self-start"
          onClick={() => router.back()}
        >
          <i className="bi bi-arrow-left" aria-hidden="true" /> Назад
        </button>
      </div>
    );
  }

  const winSubstages = winGate
    ? (stages ?? []).filter((s) => s.parent_stage_id === winGate.stageId)
    : [];

  return (
    <div className="flex flex-col bg-gray-50 dark:bg-gray-900">

      {/* Hero (replaces PageHeader) */}
      <DealHero
        deal={deal}
        stages={stages ?? []}
        pipelines={pipelines ?? []}
        onMove={handleMove}
        onBack={() => router.back()}
      />

      {/* Tabs — underline-стиль, как в карточке контакта */}
      <div className="px-8 border-b border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 flex gap-0 flex-wrap mt-4">
        {TABS.map((t) => (
          <button
            key={t.key}
            type="button"
            onClick={() => setTab(t.key)}
            className={`px-4 py-2.5 text-sm transition-colors duration-150 border-b-2 ${
              tab === t.key
                ? "border-primary text-primary font-medium"
                : "border-transparent text-gray-500 dark:text-gray-400 hover:text-primary dark:hover:text-primary hover:border-primary/30"
            }`}
          >
            <i className={`bi ${t.icon} mr-1`} aria-hidden="true" />
            {t.label}
          </button>
        ))}
      </div>

      {/* Content — BlurFade key=tab ремаунтит при смене вкладки */}
      <div className="flex flex-1" key={tab}>
        <BlurFade duration={0.15} className="flex-1 p-8 min-w-0">

          {/* ── Обзор ── */}
          {tab === "overview" && (
            <div className="space-y-5 max-w-3xl">

              {/* Move errors */}
              {missingFields.length > 0 && (
                <MoveErrorAlert
                  missingLabels={missingFields.map((f) => fieldLabel(f, config))}
                  onClose={() => setMissingFields([])}
                />
              )}
              {moveError && (
                <div className="rounded-lg bg-danger-50 dark:bg-danger-500/10 border border-danger-500/20 text-danger-700 dark:text-danger-500 px-4 py-3 text-sm flex items-center gap-2">
                  <i className="bi bi-exclamation-triangle shrink-0" aria-hidden="true" />
                  {moveError}
                  <button
                    className="ml-auto hover:opacity-70"
                    onClick={() => setMoveError(null)}
                    aria-label="Закрыть"
                  >
                    <i className="bi bi-x" aria-hidden="true" />
                  </button>
                </div>
              )}

              {/* Основная информация */}
              <div className="card rounded-2xl shadow-elev-1 bg-white dark:bg-gray-800">
                <DealMainInfo
                  deal={deal}
                  stages={stages ?? []}
                  pipelines={pipelines ?? []}
                  config={config}
                  missingFields={missingSet}
                  hasProducts={hasProducts}
                  onMove={handleMove}
                  patchDeal={patchDeal}
                  patchCompany={patchCompany}
                />
              </div>

              {/* Продукты */}
              <div className="card rounded-2xl shadow-elev-1 bg-white dark:bg-gray-800">
                <DealProductsBlock
                  dealId={dealId}
                  dealCurrency={deal.currency}
                  onChanged={() => void mutateDeal()}
                />
              </div>

              {/* Контакты */}
              <div
                className={
                  missingSet.has("contacts")
                    ? "ring-1 ring-danger/60 rounded-2xl"
                    : ""
                }
              >
                <div className="card rounded-2xl shadow-elev-1 bg-white dark:bg-gray-800">
                  <DealContactsCard
                    dealId={dealId}
                    hasCompany={deal.company_id != null}
                  />
                </div>
                {missingSet.has("contacts") && (contacts?.length ?? 0) === 0 && (
                  <p className="text-xs text-danger px-5 pb-3">
                    Добавьте контакт для перехода в этап
                  </p>
                )}
              </div>
            </div>
          )}

          {/* ── Активности ── */}
          {tab === "timeline" && (
            <div className="max-w-3xl">
              <div className="card rounded-2xl shadow-elev-1 p-5 bg-white dark:bg-gray-800 flex flex-col min-h-0" style={{ minHeight: 480 }}>
                <h3 className="text-sm font-semibold mb-3 text-gray-900 dark:text-gray-100 shrink-0 flex items-center gap-1.5">
                  <i className="bi bi-chat-left-text text-gray-400" aria-hidden="true" />
                  Активности
                </h3>
                <div className="flex-1 min-h-0">
                  <Timeline targetType="deal" targetId={dealId} order="asc" composer="bottom" chat />
                </div>
              </div>
            </div>
          )}

          {/* ── Файлы ── */}
          {tab === "files" && (
            <div className="max-w-3xl">
              <div className="card rounded-2xl shadow-elev-1 p-5 bg-white dark:bg-gray-800">
                <h3 className="text-sm font-semibold mb-3 text-gray-900 dark:text-gray-100 flex items-center gap-1.5">
                  <i className="bi bi-folder text-gray-400" aria-hidden="true" />
                  Файлы
                </h3>
                {/* EmptyState-fallback внутри FilesTab если файлов нет */}
                <FilesTab entityType="deal" entityId={dealId} editMode />
              </div>
            </div>
          )}

        </BlurFade>

        {/* Right rail — sticky метаданные */}
        <DealRightRail deal={deal} />
      </div>

      {/* Lost reason modal */}
      {lostTarget != null && (
        <LostReasonModal
          open
          dealId={dealId}
          targetStageId={lostTarget}
          onClose={() => setLostTarget(null)}
          onConfirmed={() => { setLostTarget(null); void mutateDeal(); }}
        />
      )}

      {/* Win gate modal */}
      {winGate && (
        <SuccessGateModal
          dealId={dealId}
          targetStageId={winGate.stageId}
          gateInfo={winGate.gateInfo}
          dealAmount={deal.amount}
          dealCurrency={deal.currency}
          substages={winSubstages}
          onClose={() => setWinGate(null)}
          onSuccess={() => { setWinGate(null); void mutateDeal(); }}
        />
      )}
    </div>
  );
}
