"use client";

import { useEffect, useMemo, useState } from "react";
import useSWR from "swr";
import { useRouter } from "next/navigation";
import { Modal } from "@/components/Modal";
import { Field } from "@/components/Field";
import { SearchableSelect, type Option } from "@/components/SearchableSelect";
import { api, ApiError, fetcher } from "@/lib/api";
import type {
  Counterparty, Lead, LeadConvertResult, Pipeline, PipelineStage,
} from "@/lib/types";

// Задача 11: коды стран для country prompt
const COUNTRY_OPTIONS: { value: string; label: string }[] = [
  { value: "kz", label: "Казахстан" },
  { value: "uz", label: "Узбекистан" },
  { value: "kg", label: "Кыргызстан" },
  { value: "ru", label: "Россия" },
  { value: "ae", label: "ОАЭ" },
  { value: "by", label: "Беларусь" },
  { value: "am", label: "Армения" },
  { value: "ge", label: "Грузия" },
];

interface DuplicateCandidate {
  id: number;
  name: string;
  country_code?: string | null;
}

interface LeadConvertModalProps {
  open: boolean;
  lead: Lead | null;
  onClose: () => void;
  onConverted: (msg: string) => void;
}

type Mode = "new" | "existing";

export function LeadConvertModal({ open, lead, onClose, onConverted }: LeadConvertModalProps) {
  const router = useRouter();
  const { data: allPipelines } = useSWR<Pipeline[]>(open ? "/pipelines" : null, fetcher);

  const salesPipelines = useMemo(
    () => (allPipelines ?? []).filter((p) => p.kind === "sales" && p.is_active),
    [allPipelines],
  );

  const [mode, setMode] = useState<Mode>("new");
  const [counterpartyName, setCounterpartyName] = useState("");
  const [counterpartyId, setCounterpartyId] = useState<string>("");
  const [salesPipelineId, setSalesPipelineId] = useState<string>("");
  const [salesStageId, setSalesStageId] = useState<string>("");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Задача 11: состояния для duplicate_found и country_required
  const [duplicateCandidate, setDuplicateCandidate] = useState<DuplicateCandidate | null>(null);
  const [countryRequired, setCountryRequired] = useState(false);
  const [selectedCountry, setSelectedCountry] = useState("");

  const { data: stages } = useSWR<PipelineStage[]>(
    open && salesPipelineId ? `/pipelines/${salesPipelineId}/stages` : null,
    fetcher,
  );

  const { data: counterparties } = useSWR<Counterparty[]>(
    open && mode === "existing" ? "/counterparties" : null,
    fetcher,
  );

  useEffect(() => {
    if (!open) return;
    setMode("new");
    setCounterpartyName(lead?.name ?? "");
    setCounterpartyId("");
    setSalesStageId("");
    setError(null);
    setSaving(false);
    setDuplicateCandidate(null);
    setCountryRequired(false);
    setSelectedCountry("");
  }, [open, lead]);

  useEffect(() => {
    if (!open) return;
    if (!salesPipelineId && salesPipelines.length > 0) {
      setSalesPipelineId(String(salesPipelines[0].id));
    }
  }, [open, salesPipelineId, salesPipelines]);

  const counterpartyOptions: Option[] = useMemo(
    () => (counterparties ?? [])
      .slice()
      .sort((a, b) => a.name.localeCompare(b.name, "ru"))
      .map((cp) => ({
        value: String(cp.id),
        label: cp.name,
        hint: cp.country_code?.toUpperCase(),
      })),
    [counterparties],
  );

  function canSubmit() {
    if (!salesPipelineId) return false;
    if (countryRequired && !selectedCountry) return false;
    if (mode === "new") return counterpartyName.trim().length > 0;
    return counterpartyId !== "";
  }

  async function convert(overrides?: { confirm_create_new?: boolean; country_code?: string }) {
    if (!lead) return;
    if (!canSubmit()) {
      setError(mode === "new"
        ? "Укажите название нового контрагента"
        : "Выберите существующего контрагента");
      return;
    }
    setSaving(true);
    setError(null);
    setDuplicateCandidate(null);
    setCountryRequired(false);

    try {
      const body: Record<string, unknown> = {
        sales_pipeline_id: salesPipelineId ? Number(salesPipelineId) : null,
        sales_stage_id: salesStageId ? Number(salesStageId) : null,
      };
      if (mode === "new") {
        body.counterparty_name = counterpartyName.trim();
      } else {
        body.counterparty_id = Number(counterpartyId);
      }
      if (overrides?.confirm_create_new) {
        body.confirm_create_new = true;
      }
      if (overrides?.country_code) {
        body.country_code = overrides.country_code;
      }

      const result = await api<LeadConvertResult>(`/leads/${lead.id}/convert`, {
        method: "POST",
        body,
      });
      onConverted(
        result.created_new_counterparty
          ? "Лид сконвертирован. Создан новый контрагент — не забудьте дозаполнить страну."
          : "Лид сконвертирован и привязан к существующему контрагенту.",
      );
      onClose();
      router.push("/deals");
    } catch (err) {
      if (err instanceof ApiError) {
        // Backend returns { detail: { code: "...", ... } } — stored in err.detail
        const rawDetail = err.detail;
        // Normalize: could be { detail: { code } } or { code } directly
        const detailObj = (
          rawDetail && typeof rawDetail === "object" && "detail" in (rawDetail as object)
            ? (rawDetail as Record<string, unknown>).detail
            : rawDetail
        ) as Record<string, unknown> | null;
        const code = detailObj && typeof detailObj === "object" ? (detailObj.code as string | undefined) : undefined;

        // Задача 11: 409 duplicate_found
        if (err.status === 409 && code === "duplicate_found") {
          const candidateRaw = detailObj?.candidate;
          if (candidateRaw && typeof candidateRaw === "object") {
            const c = candidateRaw as Record<string, unknown>;
            setDuplicateCandidate({
              id: c.id as number,
              name: c.name as string,
              country_code: typeof c.country_code === "string" ? c.country_code : null,
            });
          }
          setSaving(false);
          return;
        }

        // Задача 11: 400 country_required
        if (err.status === 400 && code === "country_required") {
          setCountryRequired(true);
          setSaving(false);
          return;
        }

        setError(
          detailObj && typeof detailObj.detail === "string"
            ? detailObj.detail
            : err.message
        );
      } else {
        setError("Не удалось сконвертировать");
      }
    } finally {
      setSaving(false);
    }
  }

  async function handleLinkToExisting() {
    if (!duplicateCandidate || !lead) return;
    setSaving(true);
    setDuplicateCandidate(null);
    setError(null);
    try {
      const body: Record<string, unknown> = {
        sales_pipeline_id: salesPipelineId ? Number(salesPipelineId) : null,
        sales_stage_id: salesStageId ? Number(salesStageId) : null,
        counterparty_id: duplicateCandidate.id,
      };
      await api<LeadConvertResult>(`/leads/${lead.id}/convert`, { method: "POST", body });
      onConverted("Лид сконвертирован и привязан к существующему контрагенту.");
      onClose();
      router.push("/deals");
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Не удалось сконвертировать");
    } finally {
      setSaving(false);
    }
  }

  function handleCreateNew() {
    setDuplicateCandidate(null);
    void convert({ confirm_create_new: true });
  }

  function handleCountryRetry() {
    if (!selectedCountry) return;
    setCountryRequired(false);
    void convert({ country_code: selectedCountry });
  }

  const infoText = mode === "new"
    ? "Будет создан новый контрагент и сделка в выбранной воронке продаж. Страну контрагента нужно будет дозаполнить вручную."
    : "Лид будет привязан к выбранному контрагенту, и в воронке продаж будет создана новая сделка.";

  return (
    <Modal
      open={open}
      title="Конвертация в сделку"
      onClose={onClose}
      width="sm"
      footer={
        <>
          <button className="btn-secondary" onClick={onClose}>Отмена</button>
          {!duplicateCandidate && !countryRequired && (
            <button
              className="btn-primary"
              onClick={() => convert()}
              disabled={saving || !canSubmit()}
            >
              {saving ? "Конвертация…" : "Создать сделку и перейти"}
            </button>
          )}
        </>
      }
    >
      <div className="space-y-3">
        {error && <div className="text-sm text-danger bg-danger/10 px-3 py-2 rounded">{error}</div>}

        {/* Задача 11: duplicate_found block */}
        {duplicateCandidate && (
          <div className="border border-warning/50 bg-warning/10 rounded-lg p-3 space-y-2">
            <div className="flex items-start gap-2">
              <i className="bi bi-exclamation-triangle text-warning mt-0.5" />
              <div className="flex-1">
                <p className="text-sm font-medium">Найден похожий контрагент</p>
                <p className="text-sm text-gray-700 mt-0.5">
                  <b>{duplicateCandidate.name}</b>
                  {duplicateCandidate.country_code && (
                    <span className="ml-1 text-xs text-gray-500 uppercase">{duplicateCandidate.country_code}</span>
                  )}
                </p>
              </div>
            </div>
            <div className="flex gap-2">
              <button
                className="btn-primary text-sm flex-1"
                onClick={handleLinkToExisting}
                disabled={saving}
              >
                Привязать к {duplicateCandidate.name}
              </button>
              <button
                className="btn-secondary text-sm flex-1"
                onClick={handleCreateNew}
                disabled={saving}
              >
                Создать нового
              </button>
            </div>
          </div>
        )}

        {/* Задача 11: country_required block */}
        {countryRequired && (
          <div className="border border-info/50 bg-info/10 rounded-lg p-3 space-y-2">
            <div className="flex items-start gap-2">
              <i className="bi bi-geo-alt text-info mt-0.5" />
              <p className="text-sm">Укажи страну контрагента для продолжения</p>
            </div>
            <div>
              <label className="label">Страна</label>
              <select
                className="input"
                value={selectedCountry}
                onChange={(e) => setSelectedCountry(e.target.value)}
              >
                <option value="">—</option>
                {COUNTRY_OPTIONS.map((c) => (
                  <option key={c.value} value={c.value}>{c.label}</option>
                ))}
              </select>
            </div>
            <button
              className="btn-primary text-sm w-full"
              onClick={handleCountryRetry}
              disabled={!selectedCountry || saving}
            >
              {saving ? "Конвертация…" : "Продолжить"}
            </button>
          </div>
        )}

        {!duplicateCandidate && !countryRequired && (
          <>
            {lead && (
              <div className="text-sm text-gray-600 bg-info/10 border border-info/30 px-3 py-2 rounded">
                Из лида «<b>{lead.name}</b>»: {infoText}
              </div>
            )}

            <div>
              <label className="label">Контрагент <span className="text-danger">*</span></label>
              <div className="flex gap-3 mb-2">
                <label className="inline-flex items-center gap-2 text-sm select-none">
                  <input
                    type="radio"
                    name="cp-mode"
                    value="new"
                    checked={mode === "new"}
                    onChange={() => setMode("new")}
                  />
                  Новый
                </label>
                <label className="inline-flex items-center gap-2 text-sm select-none">
                  <input
                    type="radio"
                    name="cp-mode"
                    value="existing"
                    checked={mode === "existing"}
                    onChange={() => setMode("existing")}
                  />
                  Существующий
                </label>
              </div>

              {mode === "new" ? (
                <Field
                  label="Название контрагента"
                  value={counterpartyName}
                  onChange={setCounterpartyName}
                  required
                  hint="По умолчанию — имя лида"
                />
              ) : (
                <SearchableSelect
                  label="Контрагент"
                  value={counterpartyId}
                  onChange={setCounterpartyId}
                  options={counterpartyOptions}
                  placeholder={counterparties ? "Выберите контрагента…" : "Загрузка…"}
                  required
                  hint={counterparties && counterpartyOptions.length === 0
                    ? "Контрагентов пока нет."
                    : "Начните вводить название для поиска"}
                />
              )}
            </div>

            <div>
              <label className="label">Воронка продаж <span className="text-danger">*</span></label>
              <select
                className="input"
                value={salesPipelineId}
                onChange={(e) => { setSalesPipelineId(e.target.value); setSalesStageId(""); }}
              >
                {salesPipelines.length === 0 && <option value="">Нет активных sales-воронок</option>}
                {salesPipelines.map((p) => (
                  <option key={p.id} value={p.id}>{p.name}</option>
                ))}
              </select>
            </div>

            <div>
              <label className="label">Этап</label>
              <select
                className="input"
                value={salesStageId}
                onChange={(e) => setSalesStageId(e.target.value)}
                disabled={!stages}
              >
                <option value="">Первый этап воронки</option>
                {(stages ?? [])
                  .filter((s) => s.is_active)
                  .map((s) => (
                    <option key={s.id} value={s.id}>{s.name}</option>
                  ))}
              </select>
            </div>
          </>
        )}
      </div>
    </Modal>
  );
}
