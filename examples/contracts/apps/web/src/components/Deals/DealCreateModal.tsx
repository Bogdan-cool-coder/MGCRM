"use client";

import { useEffect, useState } from "react";
import useSWR, { mutate as globalMutate } from "swr";
import { Modal } from "@/components/Modal";
import { Field } from "@/components/Field";
import { UserSelect } from "@/components/UserSelect";
import { api, ApiError, fetcher } from "@/lib/api";
import type { Company, Pipeline, PipelineStage } from "@/lib/types";

interface Props {
  open: boolean;
  pipelineId: number | null;
  stages: PipelineStage[];
  onClose: () => void;
  onCreated: () => void;
}

interface NewCompanyForm {
  legal_name: string;
  city: string;
  country: string;
  source: string;
}

const COUNTRY_OPTS = [
  { value: "", label: "—" },
  { value: "kz", label: "Казахстан" },
  { value: "uz", label: "Узбекистан" },
  { value: "ru", label: "Россия" },
  { value: "by", label: "Беларусь" },
];

const SOURCE_OPTS = [
  { value: "", label: "—" },
  { value: "own_contact", label: "Свой контакт" },
  { value: "cold_call", label: "Холодный звонок" },
  { value: "partner", label: "Партнёр" },
  { value: "internet", label: "Из интернета" },
  { value: "lead", label: "Лид-заявка" },
];

const TASK_KIND_OPTS = [
  { value: "call", label: "Звонок" },
  { value: "meeting", label: "Встреча" },
  { value: "task", label: "Задача" },
  { value: "note", label: "Заметка" },
];

export function DealCreateModal({ open, pipelineId, stages, onClose, onCreated }: Props) {
  const { data: companies } = useSWR<Company[]>("/companies?limit=500", fetcher);

  // Основная форма
  const [companyId, setCompanyId] = useState("");
  const [companySearch, setCompanySearch] = useState("");
  const [product, setProduct] = useState("");
  const [amount, setAmount] = useState("");
  const [currency, setCurrency] = useState("KZT");
  const [ownerUserId, setOwnerUserId] = useState("");
  const [stageId, setStageId] = useState("");
  const [addTask, setAddTask] = useState(false);
  const [taskKind, setTaskKind] = useState<"call" | "meeting" | "task" | "note">("call");
  const [taskDue, setTaskDue] = useState("");
  const [taskTitle, setTaskTitle] = useState("");

  // Инлайн-создание компании
  const [createCompanyMode, setCreateCompanyMode] = useState(false);
  const [newCompany, setNewCompany] = useState<NewCompanyForm>({
    legal_name: "",
    city: "",
    country: "",
    source: "",
  });

  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Устанавливаем первый рабочий этап по умолчанию
  useEffect(() => {
    if (!open) return;
    const firstStage = stages.find((s) => !s.is_lost && !s.is_won && !(s.hidden_by_default));
    if (firstStage) setStageId(String(firstStage.id));
    setCompanyId("");
    setCompanySearch("");
    setProduct("");
    setAmount("");
    setCurrency("KZT");
    setOwnerUserId("");
    setAddTask(false);
    setTaskKind("call");
    setTaskDue("");
    setTaskTitle("");
    setCreateCompanyMode(false);
    setNewCompany({ legal_name: "", city: "", country: "", source: "" });
    setError(null);
  }, [open, stages]);

  // Фильтруем компании по поиску
  const filteredCompanies = (companies ?? []).filter((c) => {
    const name = c.name ?? c.legal_name ?? "";
    return name.toLowerCase().includes(companySearch.toLowerCase());
  });

  async function handleSubmit() {
    if (!pipelineId) return;
    setSubmitting(true);
    setError(null);
    try {
      let resolvedCompanyId: number | null = companyId ? Number(companyId) : null;

      // Сначала создаём компанию если режим inline
      if (createCompanyMode && newCompany.legal_name.trim()) {
        const created = await api<Company>("/companies", {
          method: "POST",
          body: {
            legal_name: newCompany.legal_name.trim(),
            city: newCompany.city.trim() || null,
            country: newCompany.country || null,
            source: newCompany.source || null,
          },
        });
        resolvedCompanyId = created.id;
        void globalMutate("/companies?limit=500");
      }

      // Создаём сделку
      const deal = await api<{ id: number }>("/deals", {
        method: "POST",
        body: {
          pipeline_id: pipelineId,
          stage_id: stageId ? Number(stageId) : undefined,
          company_id: resolvedCompanyId,
          product: product.trim() || null,
          amount: amount ? Number(amount) : null,
          currency: currency || null,
          owner_user_id: ownerUserId ? Number(ownerUserId) : null,
          title: (
            companies?.find((c) => c.id === resolvedCompanyId)?.name ??
            companies?.find((c) => c.id === resolvedCompanyId)?.legal_name ??
            (newCompany.legal_name.trim() || "Новая сделка")
          ),
        },
      });

      // Создаём первую задачу если нужно
      if (addTask && taskTitle.trim()) {
        await api("/activities", {
          method: "POST",
          body: {
            kind: taskKind,
            title: taskTitle.trim(),
            due_at: taskDue || null,
            target_type: "deal",
            target_id: deal.id,
          },
        });
        // Инвалидируем все board-ключи чтобы next_task сразу появился на канбане
        void globalMutate(
          (key: unknown) => typeof key === "string" && key.startsWith("/deals/board"),
          undefined,
          { revalidate: true }
        );
      }

      onCreated();
    } catch (err) {
      setError(
        err instanceof ApiError
          ? String((err.detail as { detail?: string })?.detail ?? err.message)
          : "Не удалось создать сделку"
      );
    } finally {
      setSubmitting(false);
    }
  }

  const isValid = createCompanyMode
    ? newCompany.legal_name.trim().length > 0
    : true; // без компании тоже можно создать сделку

  return (
    <Modal
      open={open}
      title="Новый лид / сделка"
      onClose={onClose}
      width="md"
      footer={
        <>
          <button className="btn-ghost" onClick={onClose} disabled={submitting}>Отмена</button>
          <button
            className="btn-primary disabled:opacity-50"
            disabled={submitting || !isValid}
            onClick={handleSubmit}
          >
            {submitting ? "Создание…" : "Создать"}
          </button>
        </>
      }
    >
      <div className="space-y-4">
        {error && <div className="text-sm text-danger bg-danger/10 px-3 py-2 rounded">{error}</div>}

        {/* Company section */}
        <div>
          <div className="flex items-center justify-between mb-1">
            <label className="label mb-0">Компания</label>
            <button
              type="button"
              className="text-xs text-primary hover:underline"
              onClick={() => { setCreateCompanyMode((v) => !v); setCompanyId(""); }}
            >
              {createCompanyMode ? "← Выбрать существующую" : "+ Создать новую"}
            </button>
          </div>

          {createCompanyMode ? (
            <div className="space-y-2 border border-gray-200 dark:border-gray-700 rounded-lg p-3 bg-gray-50 dark:bg-gray-900">
              <Field
                label="Название компании"
                value={newCompany.legal_name}
                onChange={(v) => setNewCompany({ ...newCompany, legal_name: v })}
                required
                placeholder="ТОО «Ромашка»"
              />
              <div className="grid grid-cols-2 gap-2">
                <Field
                  label="Город"
                  value={newCompany.city}
                  onChange={(v) => setNewCompany({ ...newCompany, city: v })}
                  placeholder="Алматы"
                />
                <div>
                  <label className="label">Страна</label>
                  <select
                    className="input"
                    value={newCompany.country}
                    onChange={(e) => setNewCompany({ ...newCompany, country: e.target.value })}
                  >
                    {COUNTRY_OPTS.map((o) => (
                      <option key={o.value} value={o.value}>{o.label}</option>
                    ))}
                  </select>
                </div>
              </div>
              <div>
                <label className="label">Источник</label>
                <select
                  className="input"
                  value={newCompany.source}
                  onChange={(e) => setNewCompany({ ...newCompany, source: e.target.value })}
                >
                  {SOURCE_OPTS.map((o) => (
                    <option key={o.value} value={o.value}>{o.label}</option>
                  ))}
                </select>
              </div>
            </div>
          ) : (
            <div>
              <input
                type="text"
                className="input mb-1"
                placeholder="Поиск компании…"
                value={companySearch}
                onChange={(e) => { setCompanySearch(e.target.value); setCompanyId(""); }}
              />
              {companySearch && filteredCompanies.length > 0 && !companyId && (
                <div className="border border-gray-200 dark:border-gray-700 rounded-lg max-h-40 overflow-y-auto bg-white dark:bg-gray-800 shadow-sm">
                  {filteredCompanies.slice(0, 10).map((c) => (
                    <button
                      key={c.id}
                      type="button"
                      className="w-full text-left px-3 py-2 text-sm hover:bg-gray-50 dark:hover:bg-gray-700 flex items-center gap-2"
                      onClick={() => {
                        setCompanyId(String(c.id));
                        setCompanySearch(c.name ?? c.legal_name ?? "");
                      }}
                    >
                      <i className="bi bi-buildings text-gray-400 shrink-0" />
                      <span className="truncate">{c.name ?? c.legal_name}</span>
                      {c.city && <span className="text-gray-400 text-xs shrink-0">· {c.city}</span>}
                    </button>
                  ))}
                </div>
              )}
              {companyId && (
                <div className="flex items-center gap-2 text-sm text-success">
                  <i className="bi bi-check-circle-fill" />
                  Компания выбрана
                  <button
                    type="button"
                    className="text-gray-400 hover:text-danger ml-1"
                    onClick={() => { setCompanyId(""); setCompanySearch(""); }}
                  >
                    <i className="bi bi-x" />
                  </button>
                </div>
              )}
            </div>
          )}
        </div>

        {/* Product */}
        <Field
          label="Продукт"
          value={product}
          onChange={setProduct}
          placeholder="MacroCRM, MacroERP…"
        />

        {/* Amount + currency */}
        <div className="grid grid-cols-3 gap-3">
          <div className="col-span-2">
            <Field
              label="Бюджет"
              type="number"
              value={amount}
              onChange={setAmount}
              placeholder="0"
            />
          </div>
          <div>
            <label className="label">Валюта</label>
            <select
              className="input"
              value={currency}
              onChange={(e) => setCurrency(e.target.value)}
            >
              <option value="KZT">KZT</option>
              <option value="RUB">RUB</option>
              <option value="USD">USD</option>
              <option value="UZS">UZS</option>
            </select>
          </div>
        </div>

        {/* Owner + Stage */}
        <div className="grid grid-cols-2 gap-3">
          <div>
            <label className="label">Ответственный</label>
            <UserSelect value={ownerUserId} onChange={setOwnerUserId} placeholder="Выбрать…" />
          </div>
          <div>
            <label className="label">Этап</label>
            <select
              className="input"
              value={stageId}
              onChange={(e) => setStageId(e.target.value)}
            >
              <option value="">— первый этап —</option>
              {stages
                .filter((s) => s.is_active)
                .map((s) => (
                  <option key={s.id} value={s.id}>{s.name}</option>
                ))}
            </select>
          </div>
        </div>

        {/* Optional task */}
        <div>
          <label className="flex items-center gap-2 text-sm cursor-pointer select-none">
            <input
              type="checkbox"
              className="w-4 h-4"
              checked={addTask}
              onChange={(e) => setAddTask(e.target.checked)}
            />
            <span>Создать первую задачу</span>
          </label>

          {addTask && (
            <div className="mt-2 space-y-2 pl-6">
              <div className="grid grid-cols-2 gap-2">
                <div>
                  <label className="label text-xs">Тип</label>
                  <select
                    className="input text-sm py-1.5"
                    value={taskKind}
                    onChange={(e) => setTaskKind(e.target.value as "call" | "meeting" | "task" | "note")}
                  >
                    {TASK_KIND_OPTS.map((o) => (
                      <option key={o.value} value={o.value}>{o.label}</option>
                    ))}
                  </select>
                </div>
                <div>
                  <label className="label text-xs">Срок</label>
                  <input
                    type="datetime-local"
                    className="input text-sm py-1.5"
                    value={taskDue}
                    onChange={(e) => setTaskDue(e.target.value)}
                  />
                </div>
              </div>
              <Field
                label="Описание задачи"
                value={taskTitle}
                onChange={setTaskTitle}
                placeholder="Позвонить и уточнить потребность"
              />
            </div>
          )}
        </div>
      </div>
    </Modal>
  );
}
