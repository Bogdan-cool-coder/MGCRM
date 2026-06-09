"use client";

import { useEffect, useMemo, useState } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import useSWR, { mutate as globalMutate } from "swr";
import { PageHeader } from "@/components/PageHeader";
import { Field } from "@/components/Field";
import { UserSelect } from "@/components/UserSelect";
import { DateTimePicker } from "@/components/ui/DateTimePicker";
import { api, ApiError, fetcher } from "@/lib/api";
import type { Company, Pipeline, PipelineStage, Product } from "@/lib/types";

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

const CURRENCY_OPTS = ["KZT", "RUB", "USD", "UZS", "EUR"];

export default function NewDealPage() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const presetPipeline = searchParams.get("pipeline_id");

  const { data: allPipelines } = useSWR<Pipeline[]>("/pipelines", fetcher);
  const { data: companies } = useSWR<Company[]>("/companies?limit=500", fetcher);
  const { data: products } = useSWR<Product[]>("/products", fetcher);

  const pipelines = useMemo(
    () => (allPipelines ?? []).filter((p) => p.kind !== "lifecycle" && p.is_active),
    [allPipelines]
  );

  const [pipelineId, setPipelineId] = useState("");
  const { data: stages } = useSWR<PipelineStage[]>(
    pipelineId ? `/pipelines/${pipelineId}/stages` : null,
    fetcher
  );

  // Дефолтная воронка
  useEffect(() => {
    if (pipelineId) return;
    if (presetPipeline && pipelines.some((p) => String(p.id) === presetPipeline)) {
      setPipelineId(presetPipeline);
      return;
    }
    const def = pipelines.find((p) => p.kind === "sales") ?? pipelines[0];
    if (def) setPipelineId(String(def.id));
  }, [pipelines, presetPipeline, pipelineId]);

  // Поля формы
  const [title, setTitle] = useState("");
  const [companyId, setCompanyId] = useState("");
  const [companySearch, setCompanySearch] = useState("");
  const [createCompanyMode, setCreateCompanyMode] = useState(false);
  const [newCompany, setNewCompany] = useState({ legal_name: "", city: "", country: "", source: "" });

  const [stageId, setStageId] = useState("");
  const [amount, setAmount] = useState("");
  const [currency, setCurrency] = useState("KZT");
  const [ownerUserId, setOwnerUserId] = useState("");

  // Опциональный первый продукт
  const [productId, setProductId] = useState("");
  const [planId, setPlanId] = useState("");
  const [productQty, setProductQty] = useState("1");

  // Опциональная первая задача
  const [addTask, setAddTask] = useState(false);
  const [taskKind, setTaskKind] = useState<"call" | "meeting" | "task" | "note">("call");
  const [taskDue, setTaskDue] = useState("");
  const [taskTitle, setTaskTitle] = useState("");

  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Дефолтный этап при загрузке этапов
  useEffect(() => {
    if (!stages || stageId) return;
    const first = stages.find((s) => !s.is_lost && !s.is_won && !s.hidden_by_default);
    if (first) setStageId(String(first.id));
  }, [stages, stageId]);

  const filteredCompanies = (companies ?? []).filter((c) => {
    const name = c.name ?? c.legal_name ?? "";
    return name.toLowerCase().includes(companySearch.toLowerCase());
  });

  const selectedProduct = useMemo(
    () => (products ?? []).find((p) => String(p.id) === productId) ?? null,
    [products, productId]
  );

  async function handleSubmit() {
    if (!pipelineId) { setError("Выберите воронку"); return; }
    setSubmitting(true);
    setError(null);
    try {
      let resolvedCompanyId: number | null = companyId ? Number(companyId) : null;
      let resolvedCompanyName: string | null = null;

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
        resolvedCompanyName = created.name ?? created.legal_name;
        void globalMutate("/companies?limit=500");
      } else if (resolvedCompanyId != null) {
        const c = companies?.find((x) => x.id === resolvedCompanyId);
        resolvedCompanyName = c?.name ?? c?.legal_name ?? null;
      }

      const dealTitle = title.trim() || resolvedCompanyName || "Новая сделка";

      const deal = await api<{ id: number }>("/deals", {
        method: "POST",
        body: {
          pipeline_id: Number(pipelineId),
          stage_id: stageId ? Number(stageId) : undefined,
          company_id: resolvedCompanyId,
          amount: amount ? Number(amount) : null,
          currency: currency || null,
          owner_user_id: ownerUserId ? Number(ownerUserId) : null,
          title: dealTitle,
        },
      });

      // Опциональный первый продукт
      if (productId) {
        await api(`/deals/${deal.id}/products`, {
          method: "POST",
          body: {
            product_id: Number(productId),
            plan_id: planId ? Number(planId) : undefined,
            quantity: Number(productQty) || 1,
          },
        });
      }

      // Опциональная первая задача
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
      }

      // Инвалидируем доски
      void globalMutate(
        (key: unknown) => typeof key === "string" && key.startsWith("/deals/board"),
        undefined,
        { revalidate: true }
      );

      router.push(`/deals/${deal.id}`);
    } catch (err) {
      setError(
        err instanceof ApiError
          ? String((err.detail as { detail?: string })?.detail ?? err.message)
          : "Не удалось создать сделку"
      );
      setSubmitting(false);
    }
  }

  const isValid = !!pipelineId && (createCompanyMode ? newCompany.legal_name.trim().length > 0 : true);

  return (
    <div className="flex flex-col h-full">
      <PageHeader
        title="Новый лид / сделка"
        description="Заполните основные данные — карточку можно дополнить после создания"
        actions={
          <button className="btn-ghost" onClick={() => router.back()}>
            <i className="bi bi-arrow-left mr-1" /> Назад
          </button>
        }
      />

      <div className="flex-1 overflow-y-auto p-6">
        <div className="max-w-2xl space-y-5">
          {error && <div className="text-sm text-danger bg-danger/10 px-3 py-2 rounded">{error}</div>}

          {/* Воронка + этап */}
          <div className="card p-5 space-y-4">
            <h3 className="text-sm font-semibold text-gray-900 dark:text-gray-100">Воронка</h3>
            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className="label">Воронка</label>
                <select className="input" value={pipelineId} onChange={(e) => { setPipelineId(e.target.value); setStageId(""); }}>
                  <option value="">— выбрать —</option>
                  {pipelines.map((p) => (
                    <option key={p.id} value={p.id}>{p.name}</option>
                  ))}
                </select>
              </div>
              <div>
                <label className="label">Этап</label>
                <select className="input" value={stageId} onChange={(e) => setStageId(e.target.value)}>
                  <option value="">— первый этап —</option>
                  {(stages ?? []).filter((s) => s.is_active).map((s) => (
                    <option key={s.id} value={s.id}>{s.name}</option>
                  ))}
                </select>
              </div>
            </div>
          </div>

          {/* Компания */}
          <div className="card p-5 space-y-3">
            <div className="flex items-center justify-between">
              <h3 className="text-sm font-semibold text-gray-900 dark:text-gray-100">Компания</h3>
              <button
                type="button"
                className="text-xs text-primary hover:underline"
                onClick={() => { setCreateCompanyMode((v) => !v); setCompanyId(""); }}
              >
                {createCompanyMode ? "← Выбрать существующую" : "+ Создать новую"}
              </button>
            </div>

            {createCompanyMode ? (
              <div className="space-y-2">
                <Field
                  label="Название компании"
                  value={newCompany.legal_name}
                  onChange={(v) => setNewCompany({ ...newCompany, legal_name: v })}
                  required
                  placeholder="ТОО «Ромашка»"
                />
                <div className="grid grid-cols-2 gap-2">
                  <Field label="Город" value={newCompany.city} onChange={(v) => setNewCompany({ ...newCompany, city: v })} placeholder="Алматы" />
                  <div>
                    <label className="label">Страна</label>
                    <select className="input" value={newCompany.country} onChange={(e) => setNewCompany({ ...newCompany, country: e.target.value })}>
                      {COUNTRY_OPTS.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
                    </select>
                  </div>
                </div>
                <div>
                  <label className="label">Источник</label>
                  <select className="input" value={newCompany.source} onChange={(e) => setNewCompany({ ...newCompany, source: e.target.value })}>
                    {SOURCE_OPTS.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
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
                        onClick={() => { setCompanyId(String(c.id)); setCompanySearch(c.name ?? c.legal_name ?? ""); }}
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
                    <i className="bi bi-check-circle-fill" /> Компания выбрана
                    <button type="button" className="text-gray-400 hover:text-danger ml-1" onClick={() => { setCompanyId(""); setCompanySearch(""); }}>
                      <i className="bi bi-x" />
                    </button>
                  </div>
                )}
              </div>
            )}
          </div>

          {/* Параметры сделки */}
          <div className="card p-5 space-y-4">
            <h3 className="text-sm font-semibold text-gray-900 dark:text-gray-100">Параметры</h3>
            <Field label="Название сделки" value={title} onChange={setTitle} placeholder="По умолчанию — название компании" />
            <div className="grid grid-cols-3 gap-3">
              <div className="col-span-2">
                <Field label="Бюджет" type="number" value={amount} onChange={setAmount} placeholder="0" />
              </div>
              <div>
                <label className="label">Валюта</label>
                <select className="input" value={currency} onChange={(e) => setCurrency(e.target.value)}>
                  {CURRENCY_OPTS.map((c) => <option key={c} value={c}>{c}</option>)}
                </select>
              </div>
            </div>
            <div>
              <label className="label">Ответственный</label>
              <UserSelect value={ownerUserId} onChange={setOwnerUserId} placeholder="Выбрать…" />
            </div>
          </div>

          {/* Опциональный первый продукт */}
          <div className="card p-5 space-y-3">
            <h3 className="text-sm font-semibold text-gray-900 dark:text-gray-100">Первый продукт (необязательно)</h3>
            <div className="grid grid-cols-3 gap-2">
              <div>
                <label className="label">Продукт</label>
                <select className="input" value={productId} onChange={(e) => { setProductId(e.target.value); setPlanId(""); }}>
                  <option value="">— нет —</option>
                  {(products ?? []).filter((p) => p.is_active).map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                </select>
              </div>
              <div>
                <label className="label">Тариф</label>
                <select className="input" value={planId} disabled={!selectedProduct || selectedProduct.plans.length === 0} onChange={(e) => setPlanId(e.target.value)}>
                  <option value="">— без тарифа —</option>
                  {(selectedProduct?.plans ?? []).map((pl) => <option key={pl.id} value={pl.id}>{pl.name}</option>)}
                </select>
              </div>
              <div>
                <label className="label">Кол-во</label>
                <input type="number" min="1" className="input" value={productQty} disabled={!productId} onChange={(e) => setProductQty(e.target.value)} />
              </div>
            </div>
          </div>

          {/* Опциональная первая задача */}
          <div className="card p-5">
            <label className="flex items-center gap-2 text-sm cursor-pointer select-none">
              <input type="checkbox" className="w-4 h-4" checked={addTask} onChange={(e) => setAddTask(e.target.checked)} />
              <span className="font-semibold text-gray-900 dark:text-gray-100">Создать первую задачу</span>
            </label>
            {addTask && (
              <div className="mt-3 space-y-2 pl-6">
                <div className="grid grid-cols-2 gap-2">
                  <div>
                    <label className="label text-xs">Тип</label>
                    <select className="input text-sm py-1.5" value={taskKind} onChange={(e) => setTaskKind(e.target.value as "call" | "meeting" | "task" | "note")}>
                      {TASK_KIND_OPTS.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
                    </select>
                  </div>
                  <div>
                    <label className="label text-xs">Срок</label>
                    <DateTimePicker value={taskDue} onChange={setTaskDue} />
                  </div>
                </div>
                <Field label="Описание задачи" value={taskTitle} onChange={setTaskTitle} placeholder="Позвонить и уточнить потребность" />
              </div>
            )}
          </div>

          {/* Actions */}
          <div className="flex items-center gap-2 pb-6">
            <button className="btn-primary disabled:opacity-50" disabled={submitting || !isValid} onClick={() => void handleSubmit()}>
              {submitting ? "Создание…" : "Создать сделку"}
            </button>
            <button className="btn-ghost" disabled={submitting} onClick={() => router.back()}>Отмена</button>
          </div>
        </div>
      </div>
    </div>
  );
}
