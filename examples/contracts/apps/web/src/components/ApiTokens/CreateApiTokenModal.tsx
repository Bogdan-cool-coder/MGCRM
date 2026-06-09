"use client";

import { useState } from "react";
import useSWR from "swr";
import { Modal } from "@/components/Modal";
import { RoleGate } from "@/components/RoleGate";
import { DatePicker } from "@/components/ui/DatePicker";
import { api, ApiError, fetcher } from "@/lib/api";
import type { APITokenCreateResponse, APITokenScope } from "@/lib/types";

interface ScopesResponse {
  scopes: string[];
}

// Categories for display grouping
const SCOPE_CATEGORIES: { label: string; scopes: [string, string][] }[] = [
  { label: "Лиды", scopes: [["read:leads", "Чтение"], ["write:leads", "Запись"]] },
  { label: "Сделки", scopes: [["read:deals", "Чтение"], ["write:deals", "Запись"]] },
  { label: "Контакты", scopes: [["read:contacts", "Чтение"], ["write:contacts", "Запись"]] },
  { label: "Компании", scopes: [["read:companies", "Чтение"], ["write:companies", "Запись"]] },
  { label: "Контрагенты", scopes: [["read:counterparties", "Чтение"], ["write:counterparties", "Запись"]] },
  { label: "Договоры", scopes: [["read:contracts", "Чтение"], ["write:contracts", "Запись"]] },
  { label: "Подписки", scopes: [["read:subscriptions", "Чтение"], ["write:subscriptions", "Запись"]] },
  { label: "Прочее", scopes: [["inbox:write", "Входящие (запись)"]] },
];

interface Props {
  open: boolean;
  onClose: () => void;
  onCreated: (token: APITokenCreateResponse) => void;
}

export function CreateApiTokenModal({ open, onClose, onCreated }: Props) {
  const { data: scopesData } = useSWR<ScopesResponse>(open ? "/api/api-tokens/scopes" : null, fetcher);
  const availableScopes = scopesData?.scopes ?? [];

  const [name, setName] = useState("");
  const [selectedScopes, setSelectedScopes] = useState<Set<string>>(new Set());
  const [wildcardSelected, setWildcardSelected] = useState(false);
  const [expiresAt, setExpiresAt] = useState("");
  const [rateLimitPerHour, setRateLimitPerHour] = useState<number>(1000);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  function reset() {
    setName("");
    setSelectedScopes(new Set());
    setWildcardSelected(false);
    setExpiresAt("");
    setRateLimitPerHour(1000);
    setError(null);
  }

  function handleClose() {
    reset();
    onClose();
  }

  function toggleScope(scope: string) {
    setSelectedScopes((prev) => {
      const next = new Set(prev);
      if (next.has(scope)) {
        next.delete(scope);
      } else {
        next.add(scope);
      }
      return next;
    });
  }

  function toggleWildcard() {
    setWildcardSelected((v) => !v);
    if (!wildcardSelected) {
      setSelectedScopes(new Set());
    }
  }

  async function handleSubmit() {
    if (!name.trim()) {
      setError("Введите название токена");
      return;
    }
    const scopes: string[] = wildcardSelected ? ["*"] : Array.from(selectedScopes);
    if (scopes.length === 0) {
      setError("Выберите хотя бы один доступ");
      return;
    }
    if (rateLimitPerHour < 10 || rateLimitPerHour > 100000) {
      setError("Лимит должен быть от 10 до 100 000 запросов в час");
      return;
    }

    setSubmitting(true);
    setError(null);
    try {
      const body: { name: string; scopes: string[]; expires_at?: string; rate_limit_per_hour: number } = {
        name: name.trim(),
        scopes,
        rate_limit_per_hour: rateLimitPerHour,
      };
      if (expiresAt) {
        body.expires_at = new Date(expiresAt).toISOString();
      }
      const result = await api<APITokenCreateResponse>("/api/api-tokens", { method: "POST", body });
      reset();
      onCreated(result);
    } catch (err) {
      setError(err instanceof ApiError ? String((err.detail as { detail?: string })?.detail ?? err.message) : "Ошибка создания");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal
      open={open}
      title="Создать API токен"
      onClose={handleClose}
      isDirty={name.length > 0 || selectedScopes.size > 0}
      width="md"
      footer={
        <>
          <button className="btn-ghost" onClick={handleClose} disabled={submitting}>
            Отмена
          </button>
          <button className="btn-primary" onClick={handleSubmit} disabled={submitting}>
            {submitting ? "Создание…" : "Создать"}
          </button>
        </>
      }
    >
      <div className="space-y-5">
        {error && (
          <div className="rounded-md bg-danger/10 text-danger px-4 py-2 text-sm">{error}</div>
        )}

        <div>
          <label className="label">Название <span className="text-danger">*</span></label>
          <input
            className="input"
            placeholder="Например: 1C интеграция"
            value={name}
            onChange={(e) => setName(e.target.value)}
          />
        </div>

        <div>
          <label className="label">Доступы <span className="text-danger">*</span></label>

          {/* Wildcard — только admin */}
          <RoleGate allowed={["admin"]}>
            <div className="mb-3 p-3 bg-primary-light/10 rounded-md border border-primary/20">
              <label className="flex items-center gap-2 cursor-pointer select-none">
                <input
                  type="checkbox"
                  checked={wildcardSelected}
                  onChange={toggleWildcard}
                  className="rounded"
                />
                <span className="text-sm font-medium text-primary">
                  <i className="bi bi-shield-fill mr-1" />
                  Полный доступ (*) — все текущие и будущие ресурсы
                </span>
              </label>
            </div>
          </RoleGate>

          <div className={`space-y-2 ${wildcardSelected ? "opacity-40 pointer-events-none" : ""}`}>
            {SCOPE_CATEGORIES.map((cat) => {
              // Filter to only show scopes that backend provides
              const relevant = cat.scopes.filter(([s]) => availableScopes.length === 0 || availableScopes.includes(s));
              if (relevant.length === 0) return null;
              return (
                <div key={cat.label} className="flex items-center gap-4">
                  <span className="w-28 text-xs text-gray-500 shrink-0">{cat.label}</span>
                  <div className="flex items-center gap-3 flex-wrap">
                    {relevant.map(([scope, label]) => (
                      <label key={scope} className="flex items-center gap-1.5 cursor-pointer select-none">
                        <input
                          type="checkbox"
                          checked={selectedScopes.has(scope)}
                          onChange={() => toggleScope(scope)}
                          className="rounded"
                        />
                        <span className="text-sm text-gray-700">{label}</span>
                      </label>
                    ))}
                  </div>
                </div>
              );
            })}
          </div>
        </div>

        <div>
          <DatePicker
            label="Действует до"
            value={expiresAt || null}
            onChange={(v) => setExpiresAt(v ?? "")}
            minDate={new Date().toISOString().slice(0, 10)}
            clearable
          />
          <p className="text-xs text-gray-400 mt-1">Без даты — токен бессрочный</p>
        </div>

        <div>
          <label className="label">Лимит запросов в час</label>
          <div className="flex items-center gap-2">
            <input
              type="number"
              className="input w-40"
              value={rateLimitPerHour}
              min={10}
              max={100000}
              onChange={(e) => setRateLimitPerHour(Number(e.target.value))}
            />
            <span className="text-sm text-gray-500">запросов/час</span>
          </div>
          <p className="text-xs text-gray-400 mt-1">
            Запросы, превышающие лимит, получают 429 Too Many Requests. Защита от случайных циклов в интеграциях.
          </p>
        </div>
      </div>
    </Modal>
  );
}
