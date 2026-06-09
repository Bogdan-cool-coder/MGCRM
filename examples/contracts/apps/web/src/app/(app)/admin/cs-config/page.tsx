"use client";

import { useState } from "react";
import useSWR from "swr";
import { PageHeader } from "@/components/PageHeader";
import { Modal } from "@/components/Modal";
import { Field } from "@/components/Field";
import { SimpleEntityCrud } from "@/components/SimpleEntityCrud";
import { api, ApiError, fetcher } from "@/lib/api";
import type { ChecklistItemTemplate, ModuleDef, Platform } from "@/lib/types";

type Tab = "platforms" | "regions" | "modules" | "checklists";
const TABS: { key: Tab; label: string }[] = [
  { key: "platforms", label: "Платформы" },
  { key: "regions", label: "Регионы" },
  { key: "modules", label: "Модули" },
  { key: "checklists", label: "Чек-листы" },
];

const KINDS = [
  { value: "status", label: "Статус (Готово/В работе/…)" },
  { value: "fraction", label: "Дробь (X из Y)" },
  { value: "percent", label: "Процент (0–100)" },
  { value: "date", label: "Дата" },
];

export default function CsConfigPage() {
  const [tab, setTab] = useState<Tab>("platforms");
  const [error, setError] = useState<string | null>(null);

  async function call(fn: () => Promise<unknown>, after?: () => void) {
    setError(null);
    try { await fn(); after?.(); }
    catch (err) { setError(err instanceof ApiError ? String((err.detail as { detail?: string })?.detail ?? err.message) : "Ошибка"); }
  }

  return (
    <div>
      <PageHeader title="Справочники реестра CS" description="Платформы, регионы, модули, чек-листы внедрения" />
      <div className="px-8 pt-4 border-b border-gray-200 flex gap-1">
        {TABS.map((t) => (
          <button key={t.key} onClick={() => setTab(t.key)}
            className={`px-3 py-2 text-sm rounded-t-lg ${tab === t.key ? "bg-white border border-b-0 border-gray-200 text-primary font-medium" : "text-gray-500 hover:text-primary"}`}>{t.label}</button>
        ))}
      </div>
      <div className="p-8">
        {error && <div className="text-sm text-danger bg-danger/10 px-3 py-2 rounded mb-4">{error}</div>}
        {tab === "platforms" && <SimpleEntityCrud endpoint="/platforms" title="Платформа" hasActive hasCode />}
        {tab === "regions" && <SimpleEntityCrud endpoint="/regions" title="Регион" hasCode />}
        {tab === "modules" && <Modules call={call} />}
        {tab === "checklists" && <Checklists call={call} />}
      </div>
    </div>
  );
}

type CallFn = (fn: () => Promise<unknown>, after?: () => void) => void;

function Modules({ call }: { call: CallFn }) {
  const { data: platforms } = useSWR<Platform[]>("/platforms", fetcher);
  const [pid, setPid] = useState<string>("");
  const { data, mutate } = useSWR<ModuleDef[]>(pid ? `/modules?platform_id=${pid}` : "/modules", fetcher);
  const [form, setForm] = useState<{ id?: number; code: string; name: string; platform_id: string; sort_order: string } | null>(null);
  const save = () => form && call(
    () => api(form.id ? `/modules/${form.id}` : "/modules", { method: form.id ? "PATCH" : "POST", body: { code: form.code.trim(), name: form.name.trim(), platform_id: form.platform_id ? Number(form.platform_id) : null, sort_order: Number(form.sort_order) || 0 } }),
    () => { setForm(null); mutate(); },
  );
  const del = (id: number) => call(() => api(`/modules/${id}`, { method: "DELETE" }), () => mutate());
  const pName = (id: number | null) => platforms?.find((p) => p.id === id)?.name ?? "общий";
  return (
    <div className="max-w-2xl space-y-2">
      <div className="flex items-center gap-2">
        <select className="input w-48" value={pid} onChange={(e) => setPid(e.target.value)}>
          <option value="">Все платформы</option>{(platforms ?? []).map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
        </select>
        <button onClick={() => setForm({ code: "", name: "", platform_id: pid, sort_order: "0" })} className="btn-primary text-sm"><i className="bi bi-plus-lg" /> Модуль</button>
      </div>
      {(data ?? []).map((m) => (
        <div key={m.id} className="flex items-center gap-3 border border-gray-100 rounded-lg px-3 py-2">
          <span className="font-mono text-xs text-gray-400">{m.code}</span>
          <span className="font-medium flex-1">{m.name}</span>
          <span className="text-xs text-gray-400">{pName(m.platform_id)}</span>
          <button onClick={() => setForm({ id: m.id, code: m.code, name: m.name, platform_id: m.platform_id ? String(m.platform_id) : "", sort_order: String(m.sort_order) })} className="btn-ghost text-xs"><i className="bi bi-pencil" /></button>
          <button onClick={() => del(m.id)} className="btn-ghost text-xs text-danger"><i className="bi bi-trash" /></button>
        </div>
      ))}
      {form && (
        <Modal open title={form.id ? "Модуль" : "Новый модуль"} onClose={() => setForm(null)}>
          <div className="space-y-3">
            <div className="grid grid-cols-2 gap-3">
              <Field label="Код" value={form.code} onChange={(v) => setForm({ ...form, code: v })} required />
              <Field label="Название" value={form.name} onChange={(v) => setForm({ ...form, name: v })} required />
            </div>
            <div className="grid grid-cols-2 gap-3">
              <div><label className="label">Платформа</label>
                <select className="input" value={form.platform_id} onChange={(e) => setForm({ ...form, platform_id: e.target.value })}>
                  <option value="">общий</option>{(platforms ?? []).map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                </select></div>
              <Field label="Сортировка" type="number" value={form.sort_order} onChange={(v) => setForm({ ...form, sort_order: v })} />
            </div>
            <div className="flex justify-end gap-2"><button onClick={() => setForm(null)} className="btn-ghost">Отмена</button><button onClick={save} className="btn-primary">Сохранить</button></div>
          </div>
        </Modal>
      )}
    </div>
  );
}

function Checklists({ call }: { call: CallFn }) {
  const { data: platforms } = useSWR<Platform[]>("/platforms", fetcher);
  const [pid, setPid] = useState<string>("");
  const { data, mutate } = useSWR<ChecklistItemTemplate[]>(pid ? `/platforms/${pid}/checklist-items` : null, fetcher);
  const [form, setForm] = useState<{ id?: number; code: string; label: string; group: string; kind: string; optional: boolean; sort_order: string } | null>(null);

  const save = () => form && pid && call(
    () => api(form.id ? `/checklist-items/${form.id}` : `/platforms/${pid}/checklist-items`, {
      method: form.id ? "PATCH" : "POST",
      body: { code: form.code.trim(), label: form.label.trim(), group: form.group.trim() || null, kind: form.kind, optional: form.optional, sort_order: Number(form.sort_order) || 0 },
    }),
    () => { setForm(null); mutate(); },
  );
  const del = (id: number) => call(() => api(`/checklist-items/${id}`, { method: "DELETE" }), () => mutate());

  return (
    <div className="max-w-2xl space-y-2">
      <div className="flex items-center gap-2">
        <select className="input w-48" value={pid} onChange={(e) => setPid(e.target.value)}>
          <option value="">Выберите платформу…</option>{(platforms ?? []).map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
        </select>
        {pid && <button onClick={() => setForm({ code: "", label: "", group: "Внедрение", kind: "status", optional: true, sort_order: "0" })} className="btn-primary text-sm"><i className="bi bi-plus-lg" /> Пункт</button>}
      </div>
      {!pid && <p className="text-sm text-gray-500">Выберите платформу, чтобы настроить чек-лист внедрения.</p>}
      {pid && (data ?? []).map((it) => (
        <div key={it.id} className="flex items-center gap-3 border border-gray-100 rounded-lg px-3 py-2">
          <span className="text-xs text-gray-400">{it.group}</span>
          <span className="font-medium flex-1">{it.label}</span>
          <span className="text-xs text-gray-400">{KINDS.find((k) => k.value === it.kind)?.value}{it.optional ? " · опц." : ""}</span>
          <button onClick={() => setForm({ id: it.id, code: it.code, label: it.label, group: it.group ?? "", kind: it.kind, optional: it.optional, sort_order: String(it.sort_order) })} className="btn-ghost text-xs"><i className="bi bi-pencil" /></button>
          <button onClick={() => del(it.id)} className="btn-ghost text-xs text-danger"><i className="bi bi-trash" /></button>
        </div>
      ))}
      {form && (
        <Modal open title={form.id ? "Пункт чек-листа" : "Новый пункт"} onClose={() => setForm(null)}>
          <div className="space-y-3">
            <div className="grid grid-cols-2 gap-3">
              <Field label="Код" value={form.code} onChange={(v) => setForm({ ...form, code: v })} required />
              <Field label="Название" value={form.label} onChange={(v) => setForm({ ...form, label: v })} required />
            </div>
            <div className="grid grid-cols-2 gap-3">
              <Field label="Группа" value={form.group} onChange={(v) => setForm({ ...form, group: v })} placeholder="Внедрение / Качество" />
              <div><label className="label">Тип</label>
                <select className="input" value={form.kind} onChange={(e) => setForm({ ...form, kind: e.target.value })}>
                  {KINDS.map((k) => <option key={k.value} value={k.value}>{k.label}</option>)}
                </select></div>
            </div>
            <div className="grid grid-cols-2 gap-3 items-end">
              <Field label="Сортировка" type="number" value={form.sort_order} onChange={(v) => setForm({ ...form, sort_order: v })} />
              <label className="flex items-center gap-2 pb-2"><input type="checkbox" checked={form.optional} onChange={(e) => setForm({ ...form, optional: e.target.checked })} /> Можно «Не требуется»</label>
            </div>
            <div className="flex justify-end gap-2"><button onClick={() => setForm(null)} className="btn-ghost">Отмена</button><button onClick={save} className="btn-primary">Сохранить</button></div>
          </div>
        </Modal>
      )}
    </div>
  );
}
