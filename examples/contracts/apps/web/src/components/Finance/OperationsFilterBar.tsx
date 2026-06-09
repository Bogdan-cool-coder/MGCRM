"use client";

import useSWR from "swr";
import { fetcher } from "@/lib/api";
import { DatePicker } from "@/components/ui/DatePicker";
import type { FinLegalEntity, FinMoneyAccount, FinOpType, FinCashflowCategory } from "@/lib/types";

export interface OperationsFilters {
  entity: string;
  account: string;
  direction: string;
  status: string;
  category: string;
  counterparty: string;
  op_type: string;
  date_from: string;
  date_to: string;
  q: string;
}

export const DEFAULT_OPS_FILTERS: OperationsFilters = {
  entity: "",
  account: "",
  direction: "",
  status: "",
  category: "",
  counterparty: "",
  op_type: "",
  date_from: "",
  date_to: "",
  q: "",
};

interface Props {
  filters: OperationsFilters;
  onChange: (f: OperationsFilters) => void;
}

const DIRECTION_OPTIONS = [
  { value: "", label: "Все направления" },
  { value: "in", label: "Приход" },
  { value: "out", label: "Расход" },
  { value: "transfer", label: "Перевод" },
];

const STATUS_OPTIONS = [
  { value: "", label: "Все статусы" },
  { value: "planned", label: "Запланировано" },
  { value: "to_pay", label: "К оплате" },
  { value: "posted", label: "Проведено" },
  { value: "reversed", label: "Сторнировано" },
  { value: "cancelled", label: "Отменено" },
];

export function OperationsFilterBar({ filters, onChange }: Props) {
  const { data: entities } = useSWR<FinLegalEntity[]>("/api/finance/legal-entities", fetcher);
  const { data: accounts } = useSWR<FinMoneyAccount[]>("/api/finance/money-accounts", fetcher);
  const { data: opTypes } = useSWR<FinOpType[]>("/api/finance/op-types", fetcher);
  const { data: cats } = useSWR<FinCashflowCategory[]>("/api/finance/categories", fetcher);

  function set(field: keyof OperationsFilters, value: string) {
    onChange({ ...filters, [field]: value });
  }

  return (
    <div className="card p-4 mb-4">
      <div className="flex flex-wrap gap-2">
        <select
          className="input text-sm w-auto"
          value={filters.entity}
          onChange={(e) => set("entity", e.target.value)}
        >
          <option value="">Все юрлица</option>
          {entities?.map((e) => (
            <option key={e.id} value={String(e.id)}>{e.name}</option>
          ))}
        </select>

        <select
          className="input text-sm w-auto"
          value={filters.account}
          onChange={(e) => set("account", e.target.value)}
        >
          <option value="">Все счета</option>
          {accounts?.map((a) => (
            <option key={a.id} value={String(a.id)}>{a.name}</option>
          ))}
        </select>

        <select
          className="input text-sm w-auto"
          value={filters.direction}
          onChange={(e) => set("direction", e.target.value)}
        >
          {DIRECTION_OPTIONS.map((o) => (
            <option key={o.value} value={o.value}>{o.label}</option>
          ))}
        </select>

        <select
          className="input text-sm w-auto"
          value={filters.status}
          onChange={(e) => set("status", e.target.value)}
        >
          {STATUS_OPTIONS.map((o) => (
            <option key={o.value} value={o.value}>{o.label}</option>
          ))}
        </select>

        <select
          className="input text-sm w-auto"
          value={filters.category}
          onChange={(e) => set("category", e.target.value)}
        >
          <option value="">Все статьи</option>
          {cats?.map((c) => (
            <option key={c.id} value={String(c.id)}>{c.name}</option>
          ))}
        </select>

        <input
          type="text"
          className="input text-sm w-40"
          placeholder="Контрагент…"
          value={filters.counterparty}
          onChange={(e) => set("counterparty", e.target.value)}
        />

        <select
          className="input text-sm w-auto"
          value={filters.op_type}
          onChange={(e) => set("op_type", e.target.value)}
        >
          <option value="">Все типы</option>
          {opTypes?.map((t) => (
            <option key={t.id} value={String(t.id)}>{t.name}</option>
          ))}
        </select>

        <DatePicker
          value={filters.date_from}
          onChange={(v) => set("date_from", v ?? "")}
          placeholder="Дата с"
          className="w-auto"
        />

        <DatePicker
          value={filters.date_to}
          onChange={(v) => set("date_to", v ?? "")}
          placeholder="Дата по"
          className="w-auto"
        />

        <input
          type="search"
          className="input text-sm w-44"
          placeholder="Поиск по назначению…"
          value={filters.q}
          onChange={(e) => set("q", e.target.value)}
        />
      </div>
    </div>
  );
}
