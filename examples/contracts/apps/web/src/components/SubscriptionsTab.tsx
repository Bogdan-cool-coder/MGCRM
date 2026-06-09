"use client";

import { useEffect, useMemo, useState } from "react";
import useSWR from "swr";
import { HealthBadge } from "@/components/HealthBadge";
import { Sparkline, TrendArrow } from "@/components/Sparkline";
import { UserSelect } from "@/components/UserSelect";
import { DatePicker } from "@/components/ui/DatePicker";
import { api, ApiError, fetcher } from "@/lib/api";
import {
  ATTENTION_LABELS, CHECKLIST_STATUS_OPTIONS, TIER_META,
  type ActivitySnapshot, type ChecklistItemView, type ChecklistView, type Pipeline, type PipelineStage,
  type Platform, type Region, type Subscription, type SubscriptionModuleRow, type User,
} from "@/lib/types";
import { formatDate } from "@/lib/dates";

const LIFECYCLE_NAME = "Жизненный цикл клиента";

interface SubscriptionsTabProps {
  /** Legacy: ID контрагента (страница /counterparties/[id]). */
  counterpartyId?: number;
  /** CONTACTS 2.0 Ф3-B: ID компании (страница /companies/[id]). */
  companyId?: number;
}

export function SubscriptionsTab({ counterpartyId, companyId }: SubscriptionsTabProps) {
  // CONTACTS 2.0 Ф3-B: если задан companyId — используем новый эндпоинт /companies/{id}/subscriptions.
  // Иначе — legacy /counterparties/{id}/subscriptions.
  const subsKey = companyId
    ? `/companies/${companyId}/subscriptions`
    : counterpartyId
      ? `/counterparties/${counterpartyId}/subscriptions`
      : null;
  const { data: subs, mutate } = useSWR<Subscription[]>(subsKey, fetcher);
  const { data: platforms } = useSWR<Platform[]>("/platforms", fetcher);
  const { data: regions } = useSWR<Region[]>("/regions", fetcher);
  const { data: users } = useSWR<User[]>("/users", fetcher);
  const { data: pipelines } = useSWR<Pipeline[]>("/pipelines", fetcher);
  const lifecycle = pipelines?.find((p) => p.name === LIFECYCLE_NAME);
  const { data: stages } = useSWR<PipelineStage[]>(lifecycle ? `/pipelines/${lifecycle.id}/stages` : null, fetcher);

  const [adding, setAdding] = useState(false);
  const [na, setNa] = useState({ platform_id: "", region_id: "" });
  const [error, setError] = useState<string | null>(null);

  async function call<T>(fn: () => Promise<T>, after?: () => void) {
    setError(null);
    try { await fn(); after?.(); }
    catch (err) { setError(err instanceof ApiError ? String((err.detail as { detail?: string })?.detail ?? err.message) : "Ошибка"); }
  }

  const addSub = () => na.platform_id && call(
    () => api("/subscriptions", { method: "POST", body: {
      // CONTACTS 2.0 Ф3-B: передаём company_id если доступен (источник истины),
      // иначе counterparty_id (legacy). Бэкенд резолвит зеркало.
      ...(companyId ? { company_id: companyId } : { counterparty_id: counterpartyId }),
      platform_id: Number(na.platform_id),
      region_id: na.region_id ? Number(na.region_id) : null,
    } }),
    () => { setNa({ platform_id: "", region_id: "" }); setAdding(false); mutate(); },
  );

  return (
    <div className="max-w-4xl space-y-3">
      {error && <div className="text-sm text-danger bg-danger/10 px-3 py-2 rounded">{error}</div>}

      <div className="flex items-center gap-2">
        {!adding && <button onClick={() => setAdding(true)} className="btn-primary text-sm"><i className="bi bi-plus-lg" /> Подписка на платформу</button>}
        {adding && (
          <div className="flex items-end gap-2 border border-gray-200 rounded-lg p-3 w-full">
            <div className="flex-1">
              <label className="label">Платформа*</label>
              <select className="input" value={na.platform_id} onChange={(e) => setNa({ ...na, platform_id: e.target.value })}>
                <option value="">—</option>
                {(platforms ?? []).map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
              </select>
            </div>
            <div className="flex-1">
              <label className="label">Регион</label>
              <select className="input" value={na.region_id} onChange={(e) => setNa({ ...na, region_id: e.target.value })}>
                <option value="">—</option>
                {(regions ?? []).map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}
              </select>
            </div>
            <button onClick={addSub} disabled={!na.platform_id} className="btn-primary text-sm disabled:opacity-50">Создать</button>
            <button onClick={() => setAdding(false)} className="btn-ghost text-sm">Отмена</button>
          </div>
        )}
      </div>

      {(subs ?? []).map((s) => (
        <SubscriptionCard key={s.id} sub={s} platforms={platforms ?? []} regions={regions ?? []} stages={stages ?? []} users={users ?? []} onChanged={() => mutate()} />
      ))}
      {subs && subs.length === 0 && !adding && <p className="text-sm text-gray-500">Подписок нет. Добавьте платформу, на которой работает клиент.</p>}
    </div>
  );
}

function SubscriptionCard({ sub, platforms, regions, stages, users, onChanged }: {
  sub: Subscription; platforms: Platform[]; regions: Region[]; stages: PipelineStage[]; users: User[]; onChanged: () => void;
}) {
  const [section, setSection] = useState<null | "reqs" | "checklist" | "activity" | "modules">(null);
  const [busy, setBusy] = useState(false);
  const platform = platforms.find((p) => p.id === sub.platform_id);
  const region = regions.find((r) => r.id === sub.region_id);

  async function patch(body: Record<string, unknown>) {
    setBusy(true);
    try { await api(`/subscriptions/${sub.id}`, { method: "PATCH", body }); onChanged(); }
    finally { setBusy(false); }
  }

  async function remove() {
    if (!confirm(`Удалить подписку «${platform?.name ?? ""}»? Чек-лист, активность и модули будут удалены.`)) return;
    setBusy(true);
    try { await api(`/subscriptions/${sub.id}`, { method: "DELETE" }); onChanged(); }
    finally { setBusy(false); }
  }

  const sections: [typeof section, string, string][] = [
    ["reqs", "Реквизиты", "bi-sliders"],
    ["checklist", "Чек-лист", "bi-check2-square"],
    ["activity", "Активность", "bi-graph-up"],
    ["modules", "Модули", "bi-puzzle"],
  ];

  return (
    <div className="border border-gray-200 rounded-lg">
      <div className="flex items-center gap-3 px-3 py-2 flex-wrap">
        <div className="font-medium">{platform?.name ?? "—"}{region ? <span className="text-gray-500 font-normal"> · {region.name}</span> : null}</div>
        <select
          className="input py-1 text-xs w-44"
          value={sub.lifecycle_stage_id ?? ""}
          onChange={(e) => patch({ lifecycle_stage_id: e.target.value ? Number(e.target.value) : null })}
        >
          <option value="">этап ЖЦ…</option>
          {stages.map((st) => <option key={st.id} value={st.id}>{st.name}</option>)}
        </select>
        <HealthBadge tier={sub.health_tier} manual={!!sub.manual_tier_override} />
        {sub.impl_pct != null && <span className="text-xs text-gray-500">внедрение {Math.round(sub.impl_pct)}%</span>}
        <div className="flex-1" />
        <button onClick={() => call_recompute(sub.id, setBusy, onChanged)} disabled={busy} className="btn-ghost text-xs" title="Пересчитать здоровье из активности"><i className="bi bi-arrow-repeat" /></button>
        <button onClick={remove} disabled={busy} className="btn-ghost text-xs text-danger" title="Удалить подписку"><i className="bi bi-trash" /></button>
        {sub.health_reasons.length > 0 && (
          <div className="flex gap-1">{sub.health_reasons.map((a) => <span key={a} className="text-[10px] px-1.5 py-0.5 rounded bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-500">{ATTENTION_LABELS[a] ?? a}</span>)}</div>
        )}
      </div>

      <div className="border-t border-gray-100 flex flex-wrap gap-1 px-2 py-1">
        {sections.map(([key, label, icon]) => (
          <button key={label} onClick={() => setSection(section === key ? null : key)}
            className={`px-2 py-1 text-xs rounded ${section === key ? "bg-gray-100 text-primary font-medium" : "text-gray-500 hover:text-primary"}`}>
            <i className={`bi ${icon}`} /> {label}
          </button>
        ))}
      </div>

      {section === "reqs" && <ReqsEditor sub={sub} regions={regions} users={users} onSaved={onChanged} />}
      {section === "checklist" && <ChecklistEditor subId={sub.id} onSaved={onChanged} />}
      {section === "activity" && <ActivityPanel subId={sub.id} onSaved={onChanged} />}
      {section === "modules" && <ModulesPanel subId={sub.id} />}
    </div>
  );
}

async function call_recompute(subId: number, setBusy: (b: boolean) => void, onChanged: () => void) {
  setBusy(true);
  try { await api(`/subscriptions/${subId}/recompute-health`, { method: "POST" }); onChanged(); }
  finally { setBusy(false); }
}

function ReqsEditor({ sub, regions, users, onSaved }: { sub: Subscription; regions: Region[]; users: User[]; onSaved: () => void }) {
  const [form, setForm] = useState({
    region_id: sub.region_id ? String(sub.region_id) : "",
    external_client_id: sub.external_client_id ?? "",
    seats: sub.seats != null ? String(sub.seats) : "",
    fee_actual: sub.fee_actual != null ? String(sub.fee_actual) : "",
    fee_currency: sub.fee_currency ?? "",
    tariff: sub.tariff ?? "",
    discount_until: sub.discount_until ?? "",
    last_fee_increase_at: sub.last_fee_increase_at ?? "",
    sup_pm_user_id: sub.sup_pm_user_id ? String(sub.sup_pm_user_id) : "",
    am_user_id: sub.am_user_id ? String(sub.am_user_id) : "",
    manual_tier_override: sub.manual_tier_override ?? "",
    on_premise: sub.on_premise,
    auto_prolongation: sub.auto_prolongation,
    is_active: sub.is_active,
    notes: sub.notes ?? "",
  });
  const [busy, setBusy] = useState(false);

  async function save() {
    setBusy(true);
    try {
      await api(`/subscriptions/${sub.id}`, { method: "PATCH", body: {
        region_id: form.region_id ? Number(form.region_id) : null,
        external_client_id: form.external_client_id || null,
        seats: form.seats === "" ? null : Number(form.seats),
        fee_actual: form.fee_actual === "" ? null : Number(form.fee_actual),
        fee_currency: form.fee_currency || null,
        tariff: form.tariff || null,
        discount_until: form.discount_until || null,
        last_fee_increase_at: form.last_fee_increase_at || null,
        sup_pm_user_id: form.sup_pm_user_id ? Number(form.sup_pm_user_id) : null,
        am_user_id: form.am_user_id ? Number(form.am_user_id) : null,
        manual_tier_override: form.manual_tier_override || null,
        on_premise: form.on_premise,
        auto_prolongation: form.auto_prolongation,
        is_active: form.is_active,
        notes: form.notes || null,
      } });
      onSaved();
    } finally { setBusy(false); }
  }

  return (
    <div className="border-t border-gray-100 p-3 grid grid-cols-2 md:grid-cols-3 gap-2 text-sm">
      <div><label className="label">Регион</label>
        <select className="input" value={form.region_id} onChange={(e) => setForm({ ...form, region_id: e.target.value })}>
          <option value="">—</option>{regions.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}
        </select></div>
      <div><label className="label">УЗ (seats)</label><input className="input" type="number" value={form.seats} onChange={(e) => setForm({ ...form, seats: e.target.value })} /></div>
      <div><label className="label">ID в источнике</label><input className="input" value={form.external_client_id} onChange={(e) => setForm({ ...form, external_client_id: e.target.value })} /></div>
      <div><label className="label">Абонентка факт</label><input className="input" type="number" value={form.fee_actual} onChange={(e) => setForm({ ...form, fee_actual: e.target.value })} /></div>
      <div><label className="label">Валюта</label><input className="input" value={form.fee_currency} onChange={(e) => setForm({ ...form, fee_currency: e.target.value })} placeholder="KZT/UZS/RUB" /></div>
      <div><label className="label">Тариф ТП</label><input className="input" value={form.tariff} onChange={(e) => setForm({ ...form, tariff: e.target.value })} /></div>
      <div><DatePicker label="Скидка до" value={form.discount_until || null} onChange={(v) => setForm({ ...form, discount_until: v ?? "" })} clearable /></div>
      <div><DatePicker label="Повышение АП" value={form.last_fee_increase_at || null} onChange={(v) => setForm({ ...form, last_fee_increase_at: v ?? "" })} clearable /></div>
      <div><label className="label">Тир вручную</label>
        <select className="input" value={form.manual_tier_override} onChange={(e) => setForm({ ...form, manual_tier_override: e.target.value })}>
          <option value="">авто</option>{Object.keys(TIER_META).map((c) => <option key={c} value={c}>{c}</option>)}
        </select></div>
      <div><label className="label">ПМ сопровождения</label>
        <UserSelect value={form.sup_pm_user_id} onChange={(v) => setForm({ ...form, sup_pm_user_id: v })} users={users} /></div>
      <div><label className="label">Аккаунт-менеджер</label>
        <UserSelect value={form.am_user_id} onChange={(v) => setForm({ ...form, am_user_id: v })} users={users} /></div>
      <div className="flex items-end gap-3">
        <label className="flex items-center gap-1.5"><input type="checkbox" checked={form.on_premise} onChange={(e) => setForm({ ...form, on_premise: e.target.checked })} /> Коробка</label>
        <label className="flex items-center gap-1.5"><input type="checkbox" checked={form.auto_prolongation} onChange={(e) => setForm({ ...form, auto_prolongation: e.target.checked })} /> Автопролонгация</label>
      </div>
      <div className="col-span-2 md:col-span-3"><label className="label">Заметка активности</label><textarea className="input" rows={2} value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} /></div>
      <div className="col-span-2 md:col-span-3 flex items-center gap-3">
        <label className="flex items-center gap-1.5 text-xs"><input type="checkbox" checked={form.is_active} onChange={(e) => setForm({ ...form, is_active: e.target.checked })} /> Активная подписка</label>
        <button onClick={save} disabled={busy} className="btn-primary text-sm disabled:opacity-50">{busy ? "Сохранение…" : "Сохранить реквизиты"}</button>
      </div>
    </div>
  );
}

type EditItem = { template_item_id: number; status: string; num_done: string; num_total: string; pctInput: string; value_date: string };

function ChecklistEditor({ subId, onSaved }: { subId: number; onSaved: () => void }) {
  const { data, mutate } = useSWR<ChecklistView>(`/subscriptions/${subId}/checklist`, fetcher);
  const [edit, setEdit] = useState<Record<number, EditItem>>({});
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    if (!data) return;
    const m: Record<number, EditItem> = {};
    for (const it of data.items) {
      m[it.template_item_id] = {
        template_item_id: it.template_item_id,
        status: it.status,
        num_done: it.num_done != null ? String(it.num_done) : "",
        num_total: it.num_total != null ? String(it.num_total) : "",
        pctInput: it.pct != null ? String(Math.round(it.pct * 100)) : "",
        value_date: it.value_date ?? "",
      };
    }
    setEdit(m);
  }, [data]);

  const groups = useMemo(() => {
    const g: Record<string, ChecklistItemView[]> = {};
    for (const it of data?.items ?? []) (g[it.group ?? "Внедрение"] ||= []).push(it);
    return g;
  }, [data]);

  async function save() {
    setBusy(true);
    try {
      const items = Object.values(edit).map((e) => ({
        template_item_id: e.template_item_id,
        status: e.status,
        num_done: e.num_done === "" ? null : Number(e.num_done),
        num_total: e.num_total === "" ? null : Number(e.num_total),
        pct: e.pctInput === "" ? null : Number(e.pctInput) / 100,
        value_date: e.value_date || null,
      }));
      await api(`/subscriptions/${subId}/checklist`, { method: "PUT", body: { items } });
      await mutate();
      onSaved();
    } finally { setBusy(false); }
  }

  if (!data) return <div className="border-t border-gray-100 p-3 text-sm text-gray-400">Загрузка…</div>;
  return (
    <div className="border-t border-gray-100 p-3 space-y-3">
      <div className="text-xs text-gray-500">Готовность внедрения: <b>{data.overall_pct}%</b></div>
      {Object.entries(groups).map(([group, items]) => (
        <div key={group}>
          <div className="text-xs font-semibold text-gray-600 mb-1">{group}</div>
          <div className="space-y-1">
            {items.map((it) => {
              const e = edit[it.template_item_id];
              if (!e) return null;
              return (
                <div key={it.template_item_id} className="flex items-center gap-2 text-sm">
                  <span className="flex-1 min-w-0 truncate" title={it.label}>{it.label}</span>
                  <select className="input py-0.5 text-xs w-32" value={e.status} onChange={(ev) => setEdit({ ...edit, [it.template_item_id]: { ...e, status: ev.target.value } })}>
                    {CHECKLIST_STATUS_OPTIONS.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
                  </select>
                  {it.kind === "fraction" && (
                    <span className="flex items-center gap-0.5 text-xs">
                      <input className="input py-0.5 w-12 text-xs" type="number" value={e.num_done} onChange={(ev) => setEdit({ ...edit, [it.template_item_id]: { ...e, num_done: ev.target.value } })} />/
                      <input className="input py-0.5 w-12 text-xs" type="number" value={e.num_total} onChange={(ev) => setEdit({ ...edit, [it.template_item_id]: { ...e, num_total: ev.target.value } })} />
                    </span>
                  )}
                  {it.kind === "percent" && (
                    <input className="input py-0.5 w-16 text-xs" type="number" placeholder="%" value={e.pctInput} onChange={(ev) => setEdit({ ...edit, [it.template_item_id]: { ...e, pctInput: ev.target.value } })} />
                  )}
                  {it.kind === "date" && (
                    <DatePicker
                      value={e.value_date || null}
                      onChange={(v) => setEdit({ ...edit, [it.template_item_id]: { ...e, value_date: v ?? "" } })}
                      className="w-32"
                    />
                  )}
                </div>
              );
            })}
          </div>
        </div>
      ))}
      <button onClick={save} disabled={busy} className="btn-primary text-sm disabled:opacity-50">{busy ? "Сохранение…" : "Сохранить чек-лист"}</button>
    </div>
  );
}

function ActivityPanel({ subId, onSaved }: { subId: number; onSaved: () => void }) {
  const { data, mutate } = useSWR<ActivitySnapshot[]>(`/subscriptions/${subId}/activity`, fetcher);
  const [np, setNp] = useState({ period_start: "", value: "" });
  const [busy, setBusy] = useState(false);
  const values = (data ?? []).map((a) => a.value);

  async function add() {
    if (!np.period_start || np.value === "") return;
    setBusy(true);
    try {
      await api(`/subscriptions/${subId}/activity`, { method: "PUT", body: { period_start: np.period_start, value: Number(np.value) } });
      setNp({ period_start: "", value: "" });
      await mutate();
      onSaved();
    } finally { setBusy(false); }
  }

  if (!data) return <div className="border-t border-gray-100 p-3 text-sm text-gray-400">Загрузка…</div>;
  return (
    <div className="border-t border-gray-100 p-3 space-y-2">
      <div className="flex items-center gap-2"><Sparkline values={values.slice(-16)} width={160} height={32} /> <TrendArrow pct={null} /><span className="text-xs text-gray-400">метрика: действия/период</span></div>
      <div className="flex items-end gap-2">
        <div><DatePicker label="Период (дата)" value={np.period_start || null} onChange={(v) => setNp({ ...np, period_start: v ?? "" })} /></div>
        <div><label className="label">Действий</label><input className="input py-1 w-24" type="number" value={np.value} onChange={(e) => setNp({ ...np, value: e.target.value })} /></div>
        <button onClick={add} disabled={busy || !np.period_start || np.value === ""} className="btn-secondary text-sm disabled:opacity-50">Добавить/обновить</button>
      </div>
      <div className="max-h-40 overflow-y-auto text-xs">
        {[...data].reverse().slice(0, 20).map((a) => (
          <div key={a.id} className="flex justify-between border-b border-gray-50 py-0.5">
            <span className="text-gray-500">{formatDate(a.period_start)} {a.source === "manual" ? "· вручную" : a.source === "import" ? "· импорт" : ""}</span>
            <span className="tabular-nums">{a.value}</span>
          </div>
        ))}
        {data.length === 0 && <span className="text-gray-400">Нет данных активности.</span>}
      </div>
    </div>
  );
}

function ModulesPanel({ subId }: { subId: number }) {
  const { data, mutate } = useSWR<SubscriptionModuleRow[]>(`/subscriptions/${subId}/modules`, fetcher);
  const [busy, setBusy] = useState(false);

  async function toggle(m: SubscriptionModuleRow) {
    setBusy(true);
    try {
      await api(`/subscriptions/${subId}/modules`, { method: "PUT", body: { modules: [{ module_id: m.module_id, enabled: !m.enabled, status: m.status }] } });
      await mutate();
    } finally { setBusy(false); }
  }

  if (!data) return <div className="border-t border-gray-100 p-3 text-sm text-gray-400">Загрузка…</div>;
  return (
    <div className="border-t border-gray-100 p-3 flex flex-wrap gap-2">
      {data.map((m) => (
        <button key={m.module_id} onClick={() => toggle(m)} disabled={busy}
          className={`text-xs px-2 py-1 rounded-full border ${m.enabled ? "bg-primary text-white border-primary" : "bg-white text-gray-500 border-gray-300"}`}>
          {m.enabled ? <i className="bi bi-check-lg" /> : <i className="bi bi-plus" />} {m.name}
        </button>
      ))}
      {data.length === 0 && <span className="text-sm text-gray-400">Нет модулей для этой платформы.</span>}
    </div>
  );
}
