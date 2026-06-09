"use client";

import { useState } from "react";
import useSWR from "swr";
import { Modal } from "@/components/Modal";
import { Field } from "@/components/Field";
import { api, ApiError, fetcher } from "@/lib/api";
import {
  TASK_TYPES,
  type ClientTask, type Contract, type Deal, type DealStageHistory,
  type PipelineStage, type User,
} from "@/lib/types";

function fmtDate(s?: string | null) { return s ? new Date(s).toLocaleDateString("ru-RU") : "—"; }

export function DealModal({
  deal, stage, onClose, onChanged,
}: {
  deal: Deal;
  stage: PipelineStage | undefined;
  onClose: () => void;
  onChanged: () => void;
}) {
  const { data: users } = useSWR<User[]>("/users", fetcher);
  const { data: tasks, mutate: mTasks } = useSWR<ClientTask[]>(`/deals/${deal.id}/tasks`, fetcher);
  const { data: history } = useSWR<DealStageHistory[]>(`/deals/${deal.id}/history`, fetcher);
  // CONTACTS 2.0 Ф3-C: договоры через company_id (Ф1), fallback на counterparty_id зеркало.
  const contractsUrl = deal.company_id
    ? `/companies/${deal.company_id}/contracts`
    : deal.counterparty_id
      ? `/counterparties/${deal.counterparty_id}/contracts`
      : null;
  const { data: contracts } = useSWR<Contract[]>(contractsUrl, fetcher);

  const [title, setTitle] = useState(deal.title);
  const [amount, setAmount] = useState(deal.amount != null ? String(deal.amount) : "");
  const [owner, setOwner] = useState(deal.owner_user_id ? String(deal.owner_user_id) : "");
  const [contractId, setContractId] = useState(deal.contract_id ? String(deal.contract_id) : "");
  // Задача 8: expected_close_date
  const [closeDate, setCloseDate] = useState(deal.expected_close_date?.slice(0, 10) ?? "");
  const [error, setError] = useState<string | null>(null);

  const typeOpts = stage?.task_types?.length ? TASK_TYPES.filter((t) => stage.task_types.includes(t.value)) : TASK_TYPES;
  const [nt, setNt] = useState({ title: "", task_type: typeOpts[0]?.value ?? "other", assignee_user_id: "", due_date: "" });

  const userName = (uid: number | null) => users?.find((u) => u.id === uid)?.full_name ?? "—";

  async function wrap(fn: () => Promise<unknown>, after?: () => void) {
    setError(null);
    try { await fn(); after?.(); }
    catch (err) { setError(err instanceof ApiError ? String((err.detail as { detail?: string })?.detail ?? err.message) : "Ошибка"); }
  }

  const saveFields = () => wrap(
    () => api(`/deals/${deal.id}`, { method: "PATCH", body: {
      title: title.trim(),
      amount: amount ? Number(amount) : null,
      owner_user_id: owner ? Number(owner) : null,
      contract_id: contractId ? Number(contractId) : null,
      expected_close_date: closeDate || null,
    } }),
    onChanged,
  );
  const addTask = () => nt.title.trim() && wrap(
    () => api(`/deals/${deal.id}/tasks`, { method: "POST", body: {
      title: nt.title.trim(), task_type: nt.task_type,
      assignee_user_id: nt.assignee_user_id ? Number(nt.assignee_user_id) : null,
      due_date: nt.due_date || null,
    } }),
    () => { setNt({ ...nt, title: "", due_date: "" }); mTasks(); },
  );
  const toggleTask = (t: ClientTask) => wrap(
    () => api(`/deals/${deal.id}/tasks/${t.id}`, { method: "PATCH", body: { status: t.status === "done" ? "open" : "done" } }),
    () => mTasks(),
  );
  const delTask = (t: ClientTask) => wrap(() => api(`/deals/${deal.id}/tasks/${t.id}`, { method: "DELETE" }), () => mTasks());

  return (
    <Modal open title={deal.title} onClose={onClose} width="lg">
      <div className="space-y-4">
        {error && <div className="text-sm text-danger bg-danger/10 px-3 py-2 rounded">{error}</div>}

        <div className="grid grid-cols-2 gap-3">
          <div className="col-span-2"><Field label="Название" value={title} onChange={setTitle} /></div>
          <Field label="Сумма" type="number" value={amount} onChange={setAmount} />
          <div>
            {/* Задача 12: «Владелец» → «Ответственный» */}
            <label className="label">Ответственный</label>
            <select className="input" value={owner} onChange={(e) => setOwner(e.target.value)}>
              <option value="">—</option>
              {(users ?? []).map((u) => <option key={u.id} value={u.id}>{u.full_name}</option>)}
            </select>
          </div>
          {/* Задача 8: expected_close_date */}
          <div>
            <label className="label">Планируемая дата закрытия</label>
            <input
              type="date"
              className="input"
              value={closeDate}
              onChange={(e) => setCloseDate(e.target.value)}
            />
          </div>
          <div className="col-span-2">
            <label className="label">Связанный договор {stage?.is_won ? "(для «Успеха» нужен подписанный)" : ""}</label>
            <select className="input" value={contractId} onChange={(e) => setContractId(e.target.value)}>
              <option value="">—</option>
              {(contracts ?? []).map((c) => <option key={c.id} value={c.id}>{c.number ?? `#${c.id}`} ({c.status})</option>)}
            </select>
          </div>
        </div>
        <button onClick={saveFields} className="btn-primary text-sm"><i className="bi bi-save" /> Сохранить</button>

        <div className="border-t border-gray-100 pt-3">
          <h4 className="font-semibold text-sm mb-2">Задачи</h4>
          <div className="grid grid-cols-2 md:grid-cols-4 gap-2 items-end mb-2">
            <input className="input col-span-2 md:col-span-1" placeholder="Задача" value={nt.title} onChange={(e) => setNt({ ...nt, title: e.target.value })} />
            <select className="input" value={nt.task_type} onChange={(e) => setNt({ ...nt, task_type: e.target.value })}>
              {typeOpts.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
            </select>
            <select className="input" value={nt.assignee_user_id} onChange={(e) => setNt({ ...nt, assignee_user_id: e.target.value })}>
              <option value="">Исполнитель…</option>
              {(users ?? []).map((u) => <option key={u.id} value={u.id}>{u.full_name}</option>)}
            </select>
            <input className="input" type="date" value={nt.due_date} onChange={(e) => setNt({ ...nt, due_date: e.target.value })} />
            <button onClick={addTask} disabled={!nt.title.trim()} className="btn-secondary text-sm col-span-2 md:col-span-4 disabled:opacity-50"><i className="bi bi-plus-lg" /> Задача</button>
          </div>
          {(tasks ?? []).map((t) => (
            <div key={t.id} className={`flex items-center gap-2 text-sm py-1 ${t.status === "done" ? "opacity-60" : ""}`}>
              <input type="checkbox" checked={t.status === "done"} onChange={() => toggleTask(t)} />
              <span className={`flex-1 ${t.status === "done" ? "line-through" : ""}`}>{t.title}</span>
              <span className="text-xs text-gray-400">{userName(t.assignee_user_id)} · до {fmtDate(t.due_date)}</span>
              <button onClick={() => delTask(t)} className="text-danger text-xs"><i className="bi bi-trash" /></button>
            </div>
          ))}
          {(tasks ?? []).length === 0 && <p className="text-xs text-gray-400">Задач нет.</p>}
        </div>

        <div className="border-t border-gray-100 pt-3">
          <h4 className="font-semibold text-sm mb-2">История этапов</h4>
          {(history ?? []).map((h) => (
            <div key={h.id} className="text-xs text-gray-500 flex justify-between py-0.5">
              <span>{h.from_stage_name ? `${h.from_stage_name} → ` : ""}{h.to_stage_name ?? `#${h.to_stage_id}`}</span>
              <span>{fmtDate(h.created_at)}</span>
            </div>
          ))}
          {(history ?? []).length === 0 && <p className="text-xs text-gray-400">Переходов нет.</p>}
        </div>
      </div>
    </Modal>
  );
}
