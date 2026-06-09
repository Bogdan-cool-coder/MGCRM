"use client";

import { useEffect, useState } from "react";
import { Modal } from "@/components/Modal";
import { Field } from "@/components/Field";
import { UserSelect } from "@/components/UserSelect";
import { api, ApiError } from "@/lib/api";
import {
  LEAD_SOURCE_LABELS,
  LEAD_STATUS_LABELS,
  type Lead,
  type LeadSource,
  type LeadStatus,
  type Pipeline,
  type PipelineStage,
  type User,
} from "@/lib/types";

interface LeadFormModalProps {
  open: boolean;
  lead: Lead | null;
  leadPipelines: Pipeline[];
  stages: PipelineStage[];
  users: User[] | undefined;
  defaultPipelineId: number | null;
  defaultStageId: number | null;
  onClose: () => void;
  onSaved: () => void;
}

type FormState = {
  name: string;
  contact_email: string;
  contact_phone: string;
  source: LeadSource;
  status: LeadStatus;
  pipeline_id: string;
  stage_id: string;
  owner_id: string;
  tags: string;
  notes: string;
  score: string;
};

const SOURCE_OPTIONS = Object.entries(LEAD_SOURCE_LABELS) as [LeadSource, string][];
const STATUS_OPTIONS = Object.entries(LEAD_STATUS_LABELS) as [LeadStatus, string][];

function emptyForm(defaultPipelineId: number | null, defaultStageId: number | null): FormState {
  return {
    name: "",
    contact_email: "",
    contact_phone: "",
    source: "manual",
    status: "active",
    pipeline_id: defaultPipelineId ? String(defaultPipelineId) : "",
    stage_id: defaultStageId ? String(defaultStageId) : "",
    owner_id: "",
    tags: "",
    notes: "",
    score: "0",
  };
}

function fromLead(lead: Lead): FormState {
  return {
    name: lead.name,
    contact_email: lead.contact_email ?? "",
    contact_phone: lead.contact_phone ?? "",
    source: lead.source,
    status: lead.status,
    pipeline_id: String(lead.pipeline_id),
    stage_id: String(lead.stage_id),
    owner_id: lead.owner_id ? String(lead.owner_id) : "",
    tags: lead.tags.join(", "),
    notes: lead.notes ?? "",
    score: lead.score != null ? String(lead.score) : "0",
  };
}

export function LeadFormModal({
  open,
  lead,
  leadPipelines,
  stages,
  users,
  defaultPipelineId,
  defaultStageId,
  onClose,
  onSaved,
}: LeadFormModalProps) {
  const isEdit = !!lead;
  const [form, setForm] = useState<FormState>(emptyForm(defaultPipelineId, defaultStageId));
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!open) return;
    setError(null);
    setSaving(false);
    setForm(lead ? fromLead(lead) : emptyForm(defaultPipelineId, defaultStageId));
  }, [open, lead, defaultPipelineId, defaultStageId]);

  const visibleStages = stages.filter((s) => String(s.pipeline_id) === form.pipeline_id);

  function isValid() {
    return form.name.trim().length > 0 && !!form.source && !!form.stage_id;
  }

  async function save(): Promise<boolean> {
    if (!isValid()) {
      setError("Заполните обязательные поля");
      return false;
    }
    setSaving(true);
    setError(null);
    const tagsArr = form.tags
      .split(",")
      .map((t) => t.trim())
      .filter(Boolean);

    const scoreNum = form.score !== "" ? Number(form.score) : null;

    const body = {
      name: form.name.trim(),
      contact_email: form.contact_email.trim() || null,
      contact_phone: form.contact_phone.trim() || null,
      source: form.source,
      status: form.status,
      pipeline_id: form.pipeline_id ? Number(form.pipeline_id) : null,
      stage_id: form.stage_id ? Number(form.stage_id) : null,
      owner_id: form.owner_id ? Number(form.owner_id) : null,
      tags: tagsArr,
      notes: form.notes.trim() || null,
      score: scoreNum,
    };

    try {
      if (lead) {
        await api(`/leads/${lead.id}`, { method: "PATCH", body });
      } else {
        await api("/leads", { method: "POST", body });
      }
      onSaved();
      return true;
    } catch (err) {
      setError(err instanceof ApiError
        ? String((err.detail as { detail?: string })?.detail ?? err.message)
        : "Не удалось сохранить");
      return false;
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal
      open={open}
      title={isEdit ? "Редактирование лида" : "Новый лид"}
      onClose={onClose}
      width="md"
      footer={
        <>
          <button className="btn-secondary" onClick={onClose}>Отмена</button>
          <button
            className="btn-primary"
            onClick={async () => { if (await save()) onClose(); }}
            disabled={saving || !isValid()}
          >
            {saving ? "Сохранение…" : "Сохранить"}
          </button>
        </>
      }
    >
      <div className="space-y-3">
        {error && <div className="text-sm text-danger bg-danger/10 px-3 py-2 rounded">{error}</div>}

        <Field
          label="Имя / название"
          value={form.name}
          onChange={(v) => setForm({ ...form, name: v })}
          required
          placeholder="Иван Иванов / ООО «Ромашка»"
        />

        <div className="grid grid-cols-2 gap-3">
          <Field
            label="Email"
            value={form.contact_email}
            onChange={(v) => setForm({ ...form, contact_email: v })}
            type="email"
          />
          <Field
            label="Телефон"
            value={form.contact_phone}
            onChange={(v) => setForm({ ...form, contact_phone: v })}
            type="tel"
          />
        </div>

        <div className="grid grid-cols-2 gap-3">
          <div>
            <label className="label">Источник <span className="text-danger">*</span></label>
            <select
              className="input"
              value={form.source}
              onChange={(e) => setForm({ ...form, source: e.target.value as LeadSource })}
            >
              {SOURCE_OPTIONS.map(([value, label]) => (
                <option key={value} value={value}>{label}</option>
              ))}
            </select>
          </div>
          <div>
            <label className="label">Статус</label>
            <select
              className="input"
              value={form.status}
              onChange={(e) => setForm({ ...form, status: e.target.value as LeadStatus })}
            >
              {STATUS_OPTIONS.map(([value, label]) => (
                <option key={value} value={value} disabled={value === "converted" && !isEdit}>
                  {label}
                </option>
              ))}
            </select>
          </div>
        </div>

        {leadPipelines.length > 1 && (
          <div>
            <label className="label">Воронка</label>
            <select
              className="input"
              value={form.pipeline_id}
              onChange={(e) => setForm({ ...form, pipeline_id: e.target.value, stage_id: "" })}
            >
              {leadPipelines.map((p) => (
                <option key={p.id} value={p.id}>{p.name}</option>
              ))}
            </select>
          </div>
        )}

        <div>
          <label className="label">Этап <span className="text-danger">*</span></label>
          <select
            className="input"
            value={form.stage_id}
            onChange={(e) => setForm({ ...form, stage_id: e.target.value })}
          >
            <option value="">—</option>
            {visibleStages.map((s) => (
              <option key={s.id} value={s.id}>{s.name}</option>
            ))}
          </select>
        </div>

        <div>
          {/* Задача 12: «Владелец» → «Ответственный» */}
          <label className="label">Ответственный</label>
          <UserSelect
            value={form.owner_id}
            onChange={(v) => setForm({ ...form, owner_id: v })}
            users={users}
            placeholder="—"
          />
        </div>

        {/* Задача 9: score slider */}
        <div>
          <label className="label">Оценка лида</label>
          <div className="flex items-center gap-3">
            <input
              type="range"
              min="0"
              max="100"
              step="5"
              value={form.score || "0"}
              onChange={(e) => setForm({ ...form, score: e.target.value })}
              className="flex-1 accent-primary"
            />
            <span className="w-8 text-right text-sm tabular-nums font-medium text-primary">
              {form.score || "0"}
            </span>
          </div>
          <div className="text-xs text-gray-400 mt-1">0 — холодный, 100 — горячий</div>
        </div>

        <Field
          label="Теги"
          value={form.tags}
          onChange={(v) => setForm({ ...form, tags: v })}
          placeholder="hot, retail"
          hint="Через запятую"
        />

        <div>
          <label className="label">Заметки</label>
          <textarea
            className="input min-h-[80px]"
            value={form.notes}
            onChange={(e) => setForm({ ...form, notes: e.target.value })}
            placeholder="Дополнительные сведения, контекст разговора и т.д."
          />
        </div>
      </div>
    </Modal>
  );
}
