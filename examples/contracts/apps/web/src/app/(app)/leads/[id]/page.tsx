"use client";

import { useMemo, useState } from "react";
import { useParams, useRouter } from "next/navigation";
import Link from "next/link";
import useSWR from "swr";
import { PageHeader } from "@/components/PageHeader";
import { SourceBadge } from "@/components/Leads/SourceBadge";
import { LeadFormModal } from "@/components/Leads/LeadFormModal";
import { LeadConvertModal } from "@/components/Leads/LeadConvertModal";
import { Timeline } from "@/components/Timeline";
import { EmptyState } from "@/components/EmptyState";
import { CustomFieldsBlock } from "@/components/CustomFields/CustomFieldsBlock";
import { AuditLogTimeline } from "@/components/AuditLog/AuditLogTimeline";
import { AIPrefillModal } from "@/components/AI/AIPrefillModal";
import { fetcher } from "@/lib/api";
import {
  LEAD_STATUS_LABELS,
  type Lead, type LeadStatus, type Pipeline, type PipelineStage, type User,
} from "@/lib/types";
import { formatDate } from "@/lib/dates";

const STATUS_COLORS: Record<LeadStatus, string> = {
  active:    "bg-info-50 text-info-700 dark:bg-info-500/10 dark:text-info-500",
  converted: "bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-500",
  archived:  "bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400",
  lost:      "bg-danger-50 text-danger-700 dark:bg-danger-500/10 dark:text-danger-500",
};

// Задача 10: табы
type LeadTab = "details" | "timeline" | "audit";
const LEAD_TABS: { key: LeadTab; label: string; icon: string }[] = [
  { key: "details", label: "Обзор", icon: "bi-info-circle" },
  { key: "timeline", label: "Активности", icon: "bi-clock-history" },
  { key: "audit", label: "История изм.", icon: "bi-journal-text" },
];

export default function LeadCardPage() {
  const id = Number(useParams().id);
  const router = useRouter();
  const [tab, setTab] = useState<LeadTab>("details");

  const { data: lead, error: leadError, mutate: mLead } =
    useSWR<Lead>(`/leads/${id}`, fetcher);
  const { data: allPipelines } = useSWR<Pipeline[]>("/pipelines", fetcher);
  const { data: stages } = useSWR<PipelineStage[]>(
    lead ? `/pipelines/${lead.pipeline_id}/stages` : null,
    fetcher,
  );
  const { data: users } = useSWR<User[]>("/users", fetcher);

  const [editOpen, setEditOpen] = useState(false);
  const [convertOpen, setConvertOpen] = useState(false);
  // Эпик 18: AI prefill для лида
  const [aiPrefillOpen, setAiPrefillOpen] = useState(false);

  const leadPipelines = useMemo(
    () => (allPipelines ?? []).filter((p) => p.kind === "lead" && p.is_active),
    [allPipelines],
  );
  const pipelineName = useMemo(
    () => allPipelines?.find((p) => p.id === lead?.pipeline_id)?.name ?? "—",
    [allPipelines, lead],
  );
  const stage = useMemo(
    () => (stages ?? []).find((s) => s.id === lead?.stage_id),
    [stages, lead],
  );
  const ownerName = useMemo(
    () => users?.find((u) => u.id === lead?.owner_id)?.full_name ?? "—",
    [users, lead],
  );

  if (leadError) {
    return (
      <div className="p-8">
        <div className="text-sm text-danger bg-danger/10 px-3 py-2 rounded">
          Не удалось загрузить лид (id={id}).
        </div>
        <Link href="/deals" className="btn-ghost text-sm mt-3 inline-flex">
          <i className="bi bi-arrow-left" /> К сделкам
        </Link>
      </div>
    );
  }

  if (!lead) {
    return <div className="p-8 text-gray-500">Загрузка…</div>;
  }

  const isConverted = lead.status === "converted";

  const details: { label: string; value: React.ReactNode }[] = [
    { label: "Источник", value: <SourceBadge source={lead.source} /> },
    {
      label: "Статус",
      value: (
        <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold ${STATUS_COLORS[lead.status]}`}>
          {LEAD_STATUS_LABELS[lead.status]}
        </span>
      ),
    },
    { label: "Воронка", value: pipelineName },
    {
      label: "Этап",
      value: stage ? (
        <span className="inline-flex items-center">
          <span className="w-2 h-2 rounded-full mr-1.5" style={{ backgroundColor: stage.color || "#6B7A99" }} />
          {stage.name}
        </span>
      ) : "—",
    },
    // Задача 12: «Владелец» → «Ответственный»
    { label: "Ответственный", value: ownerName },
    {
      label: "Email",
      value: lead.contact_email
        ? <a className="text-primary hover:underline" href={`mailto:${lead.contact_email}`}>{lead.contact_email}</a>
        : "—",
    },
    {
      label: "Телефон",
      value: lead.contact_phone
        ? <a className="text-primary hover:underline" href={`tel:${lead.contact_phone}`}>{lead.contact_phone}</a>
        : "—",
    },
    {
      label: "Оценка",
      value: lead.score != null ? (
        <div className="flex items-center gap-2">
          <div className="flex-1 h-1.5 rounded-full bg-gray-200 max-w-[80px]">
            <div
              className="h-full rounded-full bg-primary"
              style={{ width: `${lead.score}%` }}
            />
          </div>
          <span className="text-xs tabular-nums font-medium">{lead.score}</span>
        </div>
      ) : "—",
    },
    {
      label: "Теги",
      value: lead.tags.length > 0 ? (
        <div className="flex flex-wrap gap-1 justify-end">
          {lead.tags.map((t) => (
            <span key={t} className="text-xs bg-gray-100 text-gray-700 px-1.5 py-0.5 rounded">{t}</span>
          ))}
        </div>
      ) : "—",
    },
    { label: "Создан", value: formatDate(lead.created_at) },
    {
      label: "Сконвертирован",
      value: lead.converted_at
        ? (
          <span>
            {formatDate(lead.converted_at)}
            {/* CONTACTS 2.0 Ф3-C: ссылка на company (источник истины), fallback через redirect */}
            {(lead.converted_to_company_id ?? lead.converted_to_counterparty_id) && (
              <Link
                className="ml-2 text-primary hover:underline"
                href={
                  lead.converted_to_company_id
                    ? `/companies/${lead.converted_to_company_id}`
                    : `/counterparties/${lead.converted_to_counterparty_id}`
                }
              >
                → компания
              </Link>
            )}
          </span>
        )
        : "—",
    },
  ];

  return (
    <div>
      <PageHeader
        title={lead.name}
        description={`Лид · ${pipelineName}${stage ? ` · ${stage.name}` : ""}`}
        actions={
          <div className="flex items-center gap-2">
            <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold ${STATUS_COLORS[lead.status]}`}>
              {LEAD_STATUS_LABELS[lead.status]}
            </span>
            {!isConverted && (
              <button
                onClick={() => setConvertOpen(true)}
                className="btn-secondary text-sm"
                title="Сконвертировать в сделку"
              >
                <i className="bi bi-arrow-right-circle" /> Конвертировать
              </button>
            )}
            {/* Эпик 18: AI-prefill полей лида из истории активностей */}
            {!isConverted && (
              <button
                onClick={() => setAiPrefillOpen(true)}
                className="btn-secondary text-sm"
                title="AI: предзаполнить поля на основе переписки"
              >
                <i className="bi bi-magic" /> AI: заполнить
              </button>
            )}
            <button
              onClick={() => setEditOpen(true)}
              className="btn-secondary text-sm"
              title="Редактировать"
            >
              <i className="bi bi-pencil" /> Редактировать
            </button>
            <button
              onClick={() => router.push("/leads")}
              className="btn-ghost text-sm"
            >
              <i className="bi bi-arrow-left" /> К списку
            </button>
          </div>
        }
      />

      {/* Задача 10: Tabs */}
      <div className="px-8 pt-4 border-b border-gray-200 flex gap-1">
        {LEAD_TABS.map((t) => (
          <button
            key={t.key}
            onClick={() => setTab(t.key)}
            className={`px-3 py-2 text-sm rounded-t-lg ${tab === t.key ? "bg-white border border-b-0 border-gray-200 text-primary font-medium" : "text-gray-500 hover:text-primary"}`}
          >
            <i className={`bi ${t.icon}`} /> {t.label}
          </button>
        ))}
      </div>

      <div className="p-8">
        {/* Обзор */}
        {tab === "details" && (
          <div className="space-y-6">
            <div className="max-w-3xl">
              {lead.notes && (
                <div className="card p-4 mb-4 bg-gray-50">
                  <div className="text-xs uppercase tracking-wide text-gray-500 mb-1">Зам��тки</div>
                  <div className="text-sm whitespace-pre-wrap">{lead.notes}</div>
                </div>
              )}
              <div className="grid md:grid-cols-2 gap-x-6 gap-y-1">
                {details.map((d) => (
                  <div key={d.label} className="flex justify-between gap-4 border-b border-gray-100 py-2">
                    <span className="text-gray-500 text-sm">{d.label}</span>
                    <span className="text-sm text-right">{d.value}</span>
                  </div>
                ))}
              </div>
            </div>
            <div className="max-w-3xl">
              <CustomFieldsBlock
                entityScope="lead"
                entityId={id}
                extraFields={lead.extra_fields ?? {}}
                onSaved={() => mLead()}
              />
            </div>
          </div>
        )}

        {/* Активности */}
        {tab === "timeline" && (
          <div className="max-w-3xl">
            <Timeline targetType="lead" targetId={id} />
          </div>
        )}

        {/* История изменений */}
        {tab === "audit" && (
          <div className="max-w-3xl">
            <AuditLogTimeline entityType="lead" entityId={id} />
          </div>
        )}
      </div>

      <LeadFormModal
        open={editOpen}
        lead={lead}
        leadPipelines={leadPipelines}
        stages={stages ?? []}
        users={users}
        defaultPipelineId={lead.pipeline_id}
        defaultStageId={lead.stage_id}
        onClose={() => setEditOpen(false)}
        onSaved={() => mLead()}
      />

      <LeadConvertModal
        open={convertOpen}
        lead={lead}
        onClose={() => setConvertOpen(false)}
        onConverted={() => { setConvertOpen(false); mLead(); }}
      />

      {/* Эпик 18: AI-prefill полей лида */}
      <AIPrefillModal
        open={aiPrefillOpen}
        onClose={() => setAiPrefillOpen(false)}
        entityType="lead"
        entityId={lead.id}
        onApplied={() => mLead()}
      />
    </div>
  );
}
