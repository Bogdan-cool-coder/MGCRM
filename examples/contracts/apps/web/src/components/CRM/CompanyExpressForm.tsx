"use client";

import { useState } from "react";
import { api, ApiError } from "@/lib/api";
import type { Company } from "@/lib/types";

const COUNTRY_OPTS = [
  { value: "kz", label: "Казахстан" },
  { value: "uz", label: "Узбекистан" },
  { value: "ru", label: "Россия" },
  { value: "by", label: "Беларусь" },
  { value: "other", label: "Другая" },
];

interface Props {
  /** Начальное название (из поля поиска). */
  initialName?: string;
  onCreated: (company: Company) => void;
  onCancel: () => void;
}

function isCompany(v: unknown): v is Company {
  return typeof v === "object" && v !== null && "id" in v && "legal_name" in v;
}

/**
 * Inline-форма быстрого создания компании прямо в PersonForm под полем «Компания».
 * Минимальный набор полей: Название + Страна + Город.
 */
export function CompanyExpressForm({ initialName = "", onCreated, onCancel }: Props) {
  const [name, setName] = useState(initialName);
  const [country, setCountry] = useState("kz");
  const [city, setCity] = useState("");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleCreate() {
    if (!name.trim()) { setError("Введите название компании"); return; }
    if (!city.trim()) { setError("Укажите город"); return; }
    setSaving(true);
    setError(null);
    try {
      const result = await api<unknown>("/companies", {
        method: "POST",
        body: {
          legal_name: name.trim(),
          country: country || null,
          city: city.trim(),
        },
      });
      if (isCompany(result)) {
        onCreated(result);
      } else {
        setError("Неожиданный ответ сервера");
      }
    } catch (err) {
      setError(err instanceof ApiError
        ? String((err.detail as { detail?: string })?.detail ?? err.message)
        : "Не удалось создать компанию");
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="mt-2 border border-primary/30 rounded-lg p-3 bg-primary/5 dark:bg-primary/10">
      <div className="text-[10px] font-bold text-primary/70 dark:text-primary-light/70 tracking-widest uppercase mb-2">
        Быстрое создание компании
      </div>

      {error && (
        <div className="text-xs text-danger bg-danger/10 px-2 py-1.5 rounded mb-2">{error}</div>
      )}

      <div className="space-y-2">
        <input
          className="input text-sm"
          value={name}
          onChange={(e) => setName(e.target.value)}
          placeholder="Название компании *"
          autoFocus
        />
        <div className="grid grid-cols-2 gap-2">
          <select
            className="input text-sm"
            value={country}
            onChange={(e) => setCountry(e.target.value)}
          >
            {COUNTRY_OPTS.map((o) => (
              <option key={o.value} value={o.value}>{o.label}</option>
            ))}
          </select>
          <input
            className="input text-sm"
            value={city}
            onChange={(e) => setCity(e.target.value)}
            placeholder="Город *"
          />
        </div>
      </div>

      <div className="flex justify-end gap-2 mt-3">
        <button type="button" className="btn-ghost text-xs py-1 px-2" onClick={onCancel}>
          Отмена
        </button>
        <button
          type="button"
          className="btn-primary text-xs py-1 px-3"
          onClick={handleCreate}
          disabled={saving || !name.trim() || !city.trim()}
        >
          {saving ? "Создание…" : "Создать"}
        </button>
      </div>
    </div>
  );
}
