"use client";

import { useMemo, useState } from "react";
import Link from "next/link";
import useSWR from "swr";
import { PageHeader } from "@/components/PageHeader";
import { Modal } from "@/components/Modal";
import { Field, SelectField } from "@/components/Field";
import { TextareaField } from "@/components/TextareaField";
import { ProductGroupTable } from "@/components/Products/ProductGroupTable";
import { useToast } from "@/components/ui/Toast";
import { api, ApiError, fetcher } from "@/lib/api";
import type { PricingType, Product, ProductGroup } from "@/lib/types";

const CURRENCIES = ["KZT", "UZS", "AED", "USD", "RUB"];
const PRICING_TYPES: { value: PricingType; label: string }[] = [
  { value: "fixed", label: "Фиксированная (годовая)" },
  { value: "tiered", label: "Тарифы" },
  { value: "per_minute", label: "Поминутная" },
  { value: "package", label: "Пакеты" },
  { value: "custom", label: "Под проект / договорная" },
];

function isGroupArray(v: unknown): v is ProductGroup[] {
  return Array.isArray(v) && (v.length === 0 || (typeof v[0] === "object" && v[0] !== null && "name" in v[0]));
}

type Form = {
  id?: number;
  code: string;
  name: string;
  description: string;
  group_id: string; // "" = без группы
  pricing_type: PricingType;
  is_active: boolean;
  sort_order: number;
  prices: Record<string, string>;
};

const EMPTY: Form = {
  code: "", name: "", description: "", group_id: "", pricing_type: "fixed",
  is_active: true, sort_order: 0, prices: {},
};

function fromProduct(p: Product): Form {
  const prices: Record<string, string> = {};
  for (const pr of p.prices) if (pr.plan_id == null) prices[pr.currency] = String(pr.amount);
  return {
    id: p.id, code: p.code, name: p.name, description: p.description ?? "",
    group_id: p.group_id != null ? String(p.group_id) : "", pricing_type: p.pricing_type,
    is_active: p.is_active, sort_order: p.sort_order, prices,
  };
}

function errText(e: unknown): string {
  return e instanceof ApiError
    ? String((e.detail as { detail?: string })?.detail ?? e.message)
    : "Ошибка";
}

export default function AdminProductsPage() {
  const { data: products, mutate } = useSWR<Product[]>("/products?active_only=false", fetcher);
  const { data: rawGroups } = useSWR<unknown>("/product-groups", fetcher);
  const productGroups = isGroupArray(rawGroups) ? rawGroups : [];
  const { toast } = useToast();

  const [form, setForm] = useState<Form | null>(null);
  const [busy, setBusy] = useState(false);
  const [formErr, setFormErr] = useState<string | null>(null);

  const groups = useMemo<[string, Product[]][]>(() => {
    const map = new Map<string, Product[]>();
    for (const p of products ?? []) {
      const g = p.group || "Без группы";
      if (!map.has(g)) map.set(g, []);
      map.get(g)!.push(p);
    }
    return [...map.entries()];
  }, [products]);

  const groupOptions = useMemo(
    () => [
      { value: "", label: "Без группы" },
      ...productGroups
        .filter((g) => g.is_active)
        .map((g) => ({ value: String(g.id), label: g.name })),
    ],
    [productGroups],
  );

  function payload(f: Form) {
    return {
      code: f.code.trim() || undefined,
      name: f.name.trim(),
      description: f.description.trim() || null,
      group_id: f.group_id ? Number(f.group_id) : null,
      pricing_type: f.pricing_type,
      is_active: f.is_active,
      sort_order: Number(f.sort_order) || 0,
      prices: CURRENCIES
        .filter((c) => f.prices[c]?.trim())
        .map((c) => ({ currency: c, amount: Number(f.prices[c]) })),
      plans: [], // планы из импорта сохраняются (бэкенд не удаляет отсутствующие)
    };
  }

  async function save() {
    if (!form) return;
    if (!form.name.trim()) { setFormErr("Укажите название"); return; }
    setBusy(true); setFormErr(null);
    try {
      if (form.id) await api(`/products/${form.id}`, { method: "PATCH", body: payload(form) });
      else await api("/products", { method: "POST", body: payload(form) });
      await mutate();
      setForm(null);
      toast.success(form.id ? "Продукт обновлён" : "Продукт добавлен");
    } catch (err) {
      setFormErr(errText(err));
    } finally { setBusy(false); }
  }

  async function toggleActive(p: Product) {
    try {
      await api(`/products/${p.id}`, { method: "PATCH", body: { ...payload(fromProduct(p)), is_active: !p.is_active } });
      await mutate();
      toast.success(p.is_active ? "Продукт деактивирован" : "Продукт активирован");
    } catch (err) {
      toast.error(errText(err));
    }
  }

  async function remove(p: Product) {
    if (!confirm(`Удалить продукт «${p.name}»?`)) return;
    try {
      await api(`/products/${p.id}`, { method: "DELETE" });
      await mutate();
      toast.success("Продукт удалён");
    } catch (err) {
      toast.error(errText(err));
    }
  }

  async function reimport() {
    setBusy(true);
    try {
      const res = await api<{ inserted: number }>("/products/reimport", { method: "POST" });
      await mutate();
      toast.success(`Импортировано новых продуктов: ${res.inserted}`);
    } catch (err) {
      toast.error(errText(err));
    } finally { setBusy(false); }
  }

  return (
    <div>
      <PageHeader
        title="Продукты и прайс"
        actions={
          <div className="flex gap-2">
            <button
              onClick={() => { setFormErr(null); setForm({ ...EMPTY }); }}
              className="btn-primary text-sm"
            >
              <i className="bi bi-plus-lg mr-1" /> Добавить продукт
            </button>
            <button onClick={reimport} disabled={busy} className="btn-secondary text-sm">
              <i className="bi bi-arrow-repeat mr-1" /> Импорт прайса
            </button>
          </div>
        }
      />
      <div className="p-8 space-y-3">
        {(products ?? []).length === 0 && products !== undefined ? (
          <div className="card flex flex-col items-center justify-center py-12 text-center">
            <i className="bi bi-box-seam text-4xl text-gray-300 mb-3" />
            <p className="text-sm font-medium text-gray-500 mb-1">Нет продуктов</p>
            <p className="text-xs text-gray-400 mb-4">Нажмите «Импорт прайса» или добавьте вручную</p>
            <div className="flex gap-2">
              <button onClick={reimport} disabled={busy} className="btn-secondary text-sm">
                <i className="bi bi-arrow-repeat mr-1" /> Импорт прайса
              </button>
              <button
                onClick={() => { setFormErr(null); setForm({ ...EMPTY }); }}
                className="btn-primary text-sm"
              >
                <i className="bi bi-plus-lg mr-1" /> Добавить продукт
              </button>
            </div>
          </div>
        ) : (
          <ProductGroupTable
            groups={groups}
            onEdit={(p) => { setFormErr(null); setForm(fromProduct(p)); }}
            onToggleActive={(p) => void toggleActive(p)}
            onDelete={(p) => void remove(p)}
          />
        )}
      </div>

      {form && (
        <Modal open title={form.id ? "Редактировать продукт" : "Новый продукт"} onClose={() => setForm(null)}>
          <div className="space-y-3">
            <Field label="Название" value={form.name} onChange={(v) => setForm({ ...form, name: v })} required />
            <div className="grid grid-cols-2 gap-3">
              <div>
                <SelectField
                  label="Группа"
                  value={form.group_id}
                  onChange={(v) => setForm({ ...form, group_id: v })}
                  options={groupOptions}
                />
                <Link href="/admin/product-groups" className="text-xs text-primary dark:text-primary-light hover:underline mt-1 inline-block">
                  Управлять группами →
                </Link>
              </div>
              <SelectField label="Тип цены" value={form.pricing_type} onChange={(v) => setForm({ ...form, pricing_type: v })} options={PRICING_TYPES} />
            </div>
            <TextareaField label="Описание" value={form.description} onChange={(v) => setForm({ ...form, description: v })} />
            <div>
              <label className="label">Цены (год), по валютам</label>
              <div className="grid grid-cols-2 md:grid-cols-3 gap-2">
                {CURRENCIES.map((c) => (
                  <div key={c}>
                    <div className="text-xs text-gray-500 dark:text-gray-400">{c}</div>
                    <input className="input" type="number" min="0" step="0.01" value={form.prices[c] ?? ""}
                      onChange={(e) => setForm({ ...form, prices: { ...form.prices, [c]: e.target.value } })} />
                  </div>
                ))}
              </div>
              <div className="text-xs text-gray-400 mt-1">Пусто = нет цены в этой валюте. Тарифы/пакеты редактируются через импорт.</div>
            </div>
            <div className="grid grid-cols-2 gap-3 items-end">
              <Field label="Сортировка" type="number" value={String(form.sort_order)} onChange={(v) => setForm({ ...form, sort_order: Number(v) || 0 })} />
              <label className="flex items-center gap-2 pb-2 text-sm">
                <input type="checkbox" checked={form.is_active} onChange={(e) => setForm({ ...form, is_active: e.target.checked })} className="accent-primary" />
                Активен
              </label>
            </div>
            {formErr && <div className="text-sm text-danger bg-danger/10 rounded px-3 py-2">{formErr}</div>}
            <div className="flex justify-end gap-2 pt-2">
              <button onClick={() => setForm(null)} className="btn-ghost">Отмена</button>
              <button onClick={save} disabled={busy} className="btn-primary">{busy ? "Сохранение…" : "Сохранить"}</button>
            </div>
          </div>
        </Modal>
      )}
    </div>
  );
}
