"use client";

import { useEffect, useMemo, useRef, useState } from "react";
import useSWR from "swr";
import { api, ApiError, fetcher } from "@/lib/api";
import type { ContractPricing, Product } from "@/lib/types";

const CURRENCIES = ["KZT", "UZS", "AED", "USD", "RUB"];

// TODO(Документы): лимит скидки должен зависеть от типа документа (модуль «Документы»).
// Сейчас жёсткий потолок 100% + опциональный manager_max_discount_pct с бэкенда.
const HARD_DISCOUNT_CAP = 100;

/** Производные значения для перетока в раздел «Лицензия» (срок / сумма / валюта). */
export type ProductsDerived = {
  /** Срок в месяцах = максимум «кол-во месяцев» по позициям (обычно одинаков для всех). */
  months: number;
  /** Итог «К оплате» с учётом скидки. */
  total: number;
  currency: string;
};

type Row = { product_id: number; plan_id: number | null; qty: number };

function fmt(n: number): string {
  return (Number(n) || 0).toLocaleString("ru-RU", { maximumFractionDigits: 2 });
}

function priceFor(p: Product | undefined, planId: number | null, currency: string): number | null {
  if (!p) return null;
  if (planId != null) {
    const pl = p.plans.find((x) => x.id === planId);
    const pr = pl?.prices.find((x) => x.currency === currency);
    return pr ? pr.amount : null;
  }
  const pr = p.prices.find((x) => x.currency === currency);
  return pr ? pr.amount : null;
}

export function ProductsSection({
  contractId,
  editable,
  defaultCurrency,
  onDerived,
}: {
  contractId: number;
  editable: boolean;
  defaultCurrency?: string;
  /** Сообщает странице срок/сумму/валюту для перетока в раздел «Лицензия». */
  onDerived?: (d: ProductsDerived) => void;
}) {
  const { data: products } = useSWR<Product[]>("/products", fetcher);
  const { data: pricing, mutate } = useSWR<ContractPricing>(`/contracts/${contractId}/items`, fetcher);

  const [currency, setCurrency] = useState<string>("");
  const [discountPct, setDiscountPct] = useState<string>("0");
  const [rows, setRows] = useState<Row[]>([]);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [dirty, setDirty] = useState(false);

  // Инициализация из сохранённого состояния
  useEffect(() => {
    if (!pricing) return;
    setCurrency(pricing.currency || defaultCurrency || "KZT");
    setDiscountPct(String(pricing.discount_pct ?? 0));
    setRows(pricing.items.map((it) => ({ product_id: it.product_id, plan_id: it.plan_id, qty: it.qty })));
    setDirty(false);
  }, [pricing, defaultCurrency]);

  const byId = useMemo(() => new Map((products ?? []).map((p) => [p.id, p])), [products]);

  const lines = rows.map((r) => {
    const p = byId.get(r.product_id);
    const unit = priceFor(p, r.plan_id, currency);
    const lineTotal = unit != null ? unit * (Number(r.qty) || 0) : null;
    return { row: r, product: p, unit, lineTotal };
  });
  const subtotal = lines.reduce((s, l) => s + (l.lineTotal ?? 0), 0);
  const discountAmount = (subtotal * (Number(discountPct) || 0)) / 100;
  const total = subtotal - discountAmount;
  const cap = pricing?.manager_max_discount_pct ?? null;
  const effectiveCap = cap != null ? Math.min(cap, HARD_DISCOUNT_CAP) : HARD_DISCOUNT_CAP;
  const missingPrice = lines.some((l) => l.product && l.unit == null);

  // Срок (месяцев) = максимум «кол-во месяцев» по позициям.
  const months = rows.reduce((m, r) => Math.max(m, Number(r.qty) || 0), 0);

  // ── Переток производных значений в раздел «Лицензия» ──────────────────────────
  // Сообщаем странице срок/сумму/валюту при каждом изменении расчёта.
  const onDerivedRef = useRef(onDerived);
  onDerivedRef.current = onDerived;
  const derivedKey = `${months}|${total}|${currency}`;
  useEffect(() => {
    if (!currency) return;
    onDerivedRef.current?.({ months, total, currency });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [derivedKey]);

  function update(i: number, patch: Partial<Row>) {
    setRows((prev) => prev.map((r, idx) => (idx === i ? { ...r, ...patch } : r)));
    setDirty(true);
  }
  function addRow() {
    const first = (products ?? [])[0];
    if (!first) return;
    const plan = first.plans[0]?.id ?? null;
    setRows((prev) => [...prev, { product_id: first.id, plan_id: plan, qty: 1 }]);
    setDirty(true);
  }
  function removeRow(i: number) {
    setRows((prev) => prev.filter((_, idx) => idx !== i));
    setDirty(true);
  }
  function setDiscount(raw: string) {
    // Ограничение скидки: 0 ≤ X ≤ 100 (клампим при вводе).
    if (raw === "") {
      setDiscountPct("");
      setDirty(true);
      return;
    }
    let n = Number(raw);
    if (Number.isNaN(n)) return;
    if (n < 0) n = 0;
    if (n > HARD_DISCOUNT_CAP) n = HARD_DISCOUNT_CAP;
    setDiscountPct(String(n));
    setDirty(true);
  }

  async function save() {
    setSaving(true);
    setError(null);
    try {
      await api(`/contracts/${contractId}/items`, {
        method: "PUT",
        body: {
          currency,
          discount_pct: Number(discountPct) || 0,
          items: rows.map((r) => ({ product_id: r.product_id, plan_id: r.plan_id, qty: Number(r.qty) || 1 })),
        },
      });
      await mutate();
      setDirty(false);
    } catch (err) {
      setError(
        err instanceof ApiError
          ? String((err.detail as { detail?: string })?.detail ?? err.message)
          : "Не удалось сохранить позиции",
      );
    } finally {
      setSaving(false);
    }
  }

  if (!editable) {
    if (!pricing || pricing.items.length === 0) {
      return <p className="text-sm text-gray-500 dark:text-gray-400">Продукты не добавлены.</p>;
    }
    return (
      <div className="space-y-2 text-sm">
        {pricing.items.map((it) => (
          <div
            key={it.id}
            className="flex justify-between gap-3 border-b border-gray-100 dark:border-gray-700 py-1"
          >
            <span className="text-gray-900 dark:text-gray-100">
              {it.name_snapshot} {it.qty !== 1 ? `× ${fmt(it.qty)} мес` : ""}
            </span>
            <span className="tabular-nums text-gray-900 dark:text-gray-100">
              {fmt(it.line_total)} {it.currency}
            </span>
          </div>
        ))}
        <div className="flex justify-between pt-1">
          <span className="text-gray-600 dark:text-gray-400">Сумма</span>
          <span className="tabular-nums text-gray-900 dark:text-gray-100">{fmt(pricing.subtotal)}</span>
        </div>
        {pricing.discount_pct > 0 && (
          <div className="flex justify-between text-gray-600 dark:text-gray-400">
            <span>Скидка {fmt(pricing.discount_pct)}%</span>
            <span className="tabular-nums">− {fmt(pricing.discount_amount)}</span>
          </div>
        )}
        <div className="flex justify-between font-semibold text-primary dark:text-primary-light">
          <span>К оплате</span>
          <span className="tabular-nums">
            {fmt(pricing.total)} {pricing.currency}
          </span>
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-3">
      {/* Верхняя строка: валюта + скидка рядом */}
      <div className="flex flex-wrap items-end gap-3">
        <div className="w-40">
          <label className="label">Валюта договора</label>
          <select
            className="input"
            value={currency}
            onChange={(e) => {
              setCurrency(e.target.value);
              setDirty(true);
            }}
          >
            {CURRENCIES.map((c) => (
              <option key={c} value={c}>
                {c}
              </option>
            ))}
          </select>
        </div>
        <div className="w-32">
          <label className="label">Скидка, %</label>
          <input
            className="input"
            type="number"
            min="0"
            max={HARD_DISCOUNT_CAP}
            step="0.01"
            value={discountPct}
            onChange={(e) => setDiscount(e.target.value)}
          />
        </div>
        {cap != null && (
          <span className="text-xs text-gray-500 dark:text-gray-400 self-end pb-2.5">
            Лимит скидки для менеджера: {fmt(effectiveCap)}%
          </span>
        )}
      </div>

      {lines.length > 0 && (
        <div className="space-y-2">
          {lines.map((l, i) => {
            const p = l.product;
            return (
              <div
                key={i}
                className="flex flex-wrap items-end gap-2 border border-gray-100 dark:border-gray-700 rounded-lg p-2 bg-gray-50/60 dark:bg-gray-700/30"
              >
                <div className="flex-1 min-w-[200px]">
                  <label className="label">Продукт</label>
                  <select
                    className="input"
                    value={l.row.product_id}
                    onChange={(e) => {
                      const np = byId.get(Number(e.target.value));
                      update(i, { product_id: Number(e.target.value), plan_id: np?.plans[0]?.id ?? null });
                    }}
                  >
                    {(products ?? []).map((pp) => (
                      <option key={pp.id} value={pp.id}>
                        {pp.name}
                      </option>
                    ))}
                  </select>
                </div>
                {p && p.plans.length > 0 && (
                  <div className="min-w-[160px]">
                    <label className="label">Тариф/пакет</label>
                    <select
                      className="input"
                      value={l.row.plan_id ?? ""}
                      onChange={(e) => update(i, { plan_id: e.target.value ? Number(e.target.value) : null })}
                    >
                      {p.plans.map((pl) => (
                        <option key={pl.id} value={pl.id}>
                          {pl.name}
                        </option>
                      ))}
                    </select>
                  </div>
                )}
                <div className="w-28">
                  <label className="label">Кол-во месяцев</label>
                  <input
                    className="input"
                    type="number"
                    min="0"
                    step="1"
                    value={l.row.qty}
                    onChange={(e) => update(i, { qty: Number(e.target.value) })}
                  />
                </div>
                <div className="w-32 text-right">
                  <div className="text-xs text-gray-500 dark:text-gray-400">Цена за 1 месяц</div>
                  <div className="tabular-nums text-gray-900 dark:text-gray-100">
                    {l.unit != null ? fmt(l.unit) : <span className="text-danger">нет в {currency}</span>}
                  </div>
                </div>
                <div className="w-32 text-right">
                  <div className="text-xs text-gray-500 dark:text-gray-400">Сумма итого</div>
                  <div className="tabular-nums font-medium text-gray-900 dark:text-gray-100">
                    {l.lineTotal != null ? fmt(l.lineTotal) : "—"}
                  </div>
                </div>
                <button
                  onClick={() => removeRow(i)}
                  className="btn-ghost text-danger px-2"
                  title="Удалить строку"
                  aria-label="Удалить строку"
                >
                  <i className="bi bi-x-lg" aria-hidden="true" />
                </button>
              </div>
            );
          })}
        </div>
      )}

      <button onClick={addRow} className="btn-secondary text-sm" disabled={!products?.length}>
        <i className="bi bi-plus-lg" aria-hidden="true" /> Добавить продукт
      </button>

      <div className="flex flex-wrap items-end justify-end gap-3 pt-2 border-t border-gray-100 dark:border-gray-700">
        <div className="text-sm space-y-0.5 text-right">
          <div className="flex justify-between gap-8">
            <span className="text-gray-600 dark:text-gray-400">Сумма</span>
            <span className="tabular-nums text-gray-900 dark:text-gray-100">
              {fmt(subtotal)} {currency}
            </span>
          </div>
          {Number(discountPct) > 0 && (
            <div className="flex justify-between gap-8 text-gray-600 dark:text-gray-400">
              <span>Скидка</span>
              <span className="tabular-nums">− {fmt(discountAmount)}</span>
            </div>
          )}
          <div className="flex justify-between gap-8 font-semibold text-primary dark:text-primary-light text-base">
            <span>К оплате</span>
            <span className="tabular-nums">
              {fmt(total)} {currency}
            </span>
          </div>
        </div>
      </div>

      {missingPrice && (
        <div className="text-xs text-danger">
          Для части продуктов нет цены в валюте {currency} — выберите другую валюту или продукт.
        </div>
      )}
      {error && <div className="text-sm text-danger bg-danger/10 px-3 py-2 rounded">{error}</div>}

      <button
        onClick={save}
        disabled={saving || !dirty || missingPrice}
        className="btn-primary disabled:opacity-50"
      >
        <i className="bi bi-save" aria-hidden="true" /> {saving ? "Сохранение…" : "Сохранить позиции"}
      </button>
    </div>
  );
}
