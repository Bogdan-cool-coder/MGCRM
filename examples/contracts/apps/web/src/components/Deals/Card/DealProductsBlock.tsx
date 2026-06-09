"use client";

import { useMemo, useState } from "react";
import useSWR from "swr";
import { api, ApiError, fetcher } from "@/lib/api";
import { formatCurrency } from "@/lib/format";
import type { DealProductOut, Product } from "@/lib/types";

interface DealProductsBlockProps {
  dealId: number;
  dealCurrency: string | null;
  /** Вызывается после изменения позиций — родитель ревалидирует сделку (auto-сумма). */
  onChanged: () => void;
}

export function DealProductsBlock({ dealId, dealCurrency, onChanged }: DealProductsBlockProps) {
  const linesKey = `/deals/${dealId}/products`;
  const { data: lines, mutate } = useSWR<DealProductOut[]>(linesKey, fetcher);
  const { data: products } = useSWR<Product[]>("/products", fetcher);

  const [adding, setAdding] = useState(false);
  const [productId, setProductId] = useState("");
  const [planId, setPlanId] = useState("");
  const [qty, setQty] = useState("1");
  const [unitPrice, setUnitPrice] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [deletingId, setDeletingId] = useState<number | null>(null);

  const selectedProduct = useMemo(
    () => (products ?? []).find((p) => String(p.id) === productId) ?? null,
    [products, productId]
  );

  const total = useMemo(
    () => (lines ?? []).reduce((s, l) => s + l.amount, 0),
    [lines]
  );
  const totalCurrency = dealCurrency ?? lines?.[0]?.currency ?? null;

  function resetForm() {
    setAdding(false);
    setProductId("");
    setPlanId("");
    setQty("1");
    setUnitPrice("");
    setError(null);
  }

  async function handleAdd() {
    if (!productId) { setError("Выберите продукт"); return; }
    setSubmitting(true);
    setError(null);
    try {
      await api(`/deals/${dealId}/products`, {
        method: "POST",
        body: {
          product_id: Number(productId),
          plan_id: planId ? Number(planId) : undefined,
          quantity: Number(qty) || 1,
          unit_price: unitPrice ? Number(unitPrice) : undefined,
        },
      });
      await mutate();
      onChanged();
      resetForm();
    } catch (e) {
      setError(
        e instanceof ApiError
          ? String((e.detail as { detail?: string })?.detail ?? e.message)
          : "Не удалось добавить позицию"
      );
    } finally {
      setSubmitting(false);
    }
  }

  async function handleDelete(lineId: number) {
    setDeletingId(lineId);
    try {
      await api(`/deals/${dealId}/products/${lineId}`, { method: "DELETE" });
      await mutate();
      onChanged();
    } catch (e) {
      setError(
        e instanceof ApiError
          ? String((e.detail as { detail?: string })?.detail ?? e.message)
          : "Не удалось удалить позицию"
      );
    } finally {
      setDeletingId(null);
    }
  }

  return (
    <div className="p-5">
      <div className="flex items-center justify-between mb-3">
        <h3 className="text-sm font-semibold text-gray-900 dark:text-gray-100">
          <i className="bi bi-box-seam mr-1.5 text-gray-400" />
          Продукты
        </h3>
        {!adding && (
          <button type="button" className="btn-ghost text-sm" onClick={() => setAdding(true)}>
            <i className="bi bi-plus mr-1" /> Добавить продукт
          </button>
        )}
      </div>

      {error && <div className="text-sm text-danger bg-danger/10 px-3 py-2 rounded mb-3">{error}</div>}

      {/* Lines */}
      {lines && lines.length > 0 ? (
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="text-left text-xs text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                <th className="py-2 pr-3 font-medium">Продукт</th>
                <th className="py-2 pr-3 font-medium">Тариф</th>
                <th className="py-2 pr-3 font-medium text-right">Кол-во</th>
                <th className="py-2 pr-3 font-medium text-right">Цена</th>
                <th className="py-2 pr-3 font-medium text-right">Сумма</th>
                <th className="py-2 w-8" />
              </tr>
            </thead>
            <tbody>
              {lines.map((l) => (
                <tr key={l.id} className="border-b border-gray-100 dark:border-gray-800">
                  <td className="py-2 pr-3 text-gray-900 dark:text-gray-100">{l.product_name ?? `#${l.product_id}`}</td>
                  <td className="py-2 pr-3 text-gray-500 dark:text-gray-400">{l.plan_name ?? "—"}</td>
                  <td className="py-2 pr-3 text-right tabular-nums">{l.quantity}</td>
                  <td className="py-2 pr-3 text-right tabular-nums whitespace-nowrap">{formatCurrency(l.unit_price, l.currency)}</td>
                  <td className="py-2 pr-3 text-right tabular-nums whitespace-nowrap font-medium">{formatCurrency(l.amount, l.currency)}</td>
                  <td className="py-2 text-right">
                    <button
                      type="button"
                      className="btn-ghost text-xs py-1 px-1.5 text-danger disabled:opacity-50"
                      disabled={deletingId === l.id}
                      onClick={() => void handleDelete(l.id)}
                      title="Удалить позицию"
                    >
                      <i className="bi bi-trash" />
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
            <tfoot>
              <tr>
                <td colSpan={4} className="py-2 pr-3 text-right text-xs text-gray-500 dark:text-gray-400">Итого:</td>
                <td className="py-2 pr-3 text-right tabular-nums font-semibold text-gray-900 dark:text-gray-100 whitespace-nowrap">
                  {formatCurrency(total, totalCurrency)}
                </td>
                <td />
              </tr>
            </tfoot>
          </table>
        </div>
      ) : (
        !adding && (
          <div className="text-sm text-gray-400 dark:text-gray-500 text-center py-4">
            Позиций пока нет. Сумма сделки задаётся вручную.
          </div>
        )
      )}

      {/* Add form */}
      {adding && (
        <div className="mt-3 border border-gray-200 dark:border-gray-700 rounded-lg p-3 bg-gray-50 dark:bg-gray-900 space-y-2">
          <div className="grid grid-cols-2 gap-2">
            <div>
              <label className="label text-xs">Продукт</label>
              <select
                className="input text-sm py-1.5"
                value={productId}
                onChange={(e) => { setProductId(e.target.value); setPlanId(""); setUnitPrice(""); }}
              >
                <option value="">— выбрать —</option>
                {(products ?? []).filter((p) => p.is_active).map((p) => (
                  <option key={p.id} value={p.id}>{p.name}</option>
                ))}
              </select>
            </div>
            <div>
              <label className="label text-xs">Тариф</label>
              <select
                className="input text-sm py-1.5"
                value={planId}
                disabled={!selectedProduct || selectedProduct.plans.length === 0}
                onChange={(e) => setPlanId(e.target.value)}
              >
                <option value="">— без тарифа —</option>
                {(selectedProduct?.plans ?? []).map((pl) => (
                  <option key={pl.id} value={pl.id}>{pl.name}</option>
                ))}
              </select>
            </div>
          </div>
          <div className="grid grid-cols-2 gap-2">
            <div>
              <label className="label text-xs">Количество</label>
              <input
                type="number"
                min="0"
                step="1"
                className="input text-sm py-1.5"
                value={qty}
                onChange={(e) => setQty(e.target.value)}
              />
            </div>
            <div>
              <label className="label text-xs">Цена (необязательно)</label>
              <input
                type="number"
                min="0"
                step="0.01"
                className="input text-sm py-1.5"
                placeholder="из прайса"
                value={unitPrice}
                onChange={(e) => setUnitPrice(e.target.value)}
              />
            </div>
          </div>
          <div className="flex gap-1.5">
            <button
              type="button"
              className="btn-primary text-xs py-1.5 px-3 disabled:opacity-50"
              disabled={submitting}
              onClick={() => void handleAdd()}
            >
              {submitting ? "Добавление…" : "Добавить"}
            </button>
            <button type="button" className="btn-ghost text-xs py-1.5 px-3" onClick={resetForm}>
              Отмена
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
