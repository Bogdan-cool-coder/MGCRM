"use client";

import { useMemo, useState } from "react";
import useSWR from "swr";
import { Modal } from "@/components/Modal";
import { fetcher } from "@/lib/api";

interface VarItem {
  key: string;
  label: string;
  example: string;
  var_type?: string;
}

interface NamespaceGroup {
  namespace: string;
  label: string;
  vars: VarItem[];
}

interface FilterItem {
  name: string;
  description: string;
}

interface VariablesResponse {
  template: { code: string; title: string; category: string | null };
  namespaces: NamespaceGroup[];
  filters: FilterItem[];
}

interface Props {
  open: boolean;
  onClose: () => void;
  templateCode: string | null;
}

/**
 * Эпик 3: модалка «Доступные переменные» — справочник для юриста.
 *
 * Грузит `/templates/by-code/{code}/variables` лениво (только при open && templateCode).
 * Поиск по key/label (case-insensitive), tabs по namespace, copy-в-буфер по клику.
 * Внизу — секция доступных фильтров (money_in_words, num_in_words и др.).
 */
export function VariablesModal({ open, onClose, templateCode }: Props) {
  const swrKey = open && templateCode ? `/templates/by-code/${templateCode}/variables` : null;
  const { data, error, isLoading } = useSWR<VariablesResponse>(swrKey, fetcher);

  const [search, setSearch] = useState("");
  const [activeNs, setActiveNs] = useState<string | null>(null);
  const [copiedKey, setCopiedKey] = useState<string | null>(null);

  // Активный namespace по умолчанию — первый в списке.
  const namespaces = data?.namespaces ?? [];
  const currentNs = activeNs ?? namespaces[0]?.namespace ?? null;

  const filteredVars = useMemo<VarItem[]>(() => {
    if (!currentNs) return [];
    const group = namespaces.find((g) => g.namespace === currentNs);
    if (!group) return [];
    const q = search.trim().toLowerCase();
    if (!q) return group.vars;
    return group.vars.filter(
      (v) =>
        v.key.toLowerCase().includes(q) || v.label.toLowerCase().includes(q),
    );
  }, [namespaces, currentNs, search]);

  async function copyKey(key: string) {
    const snippet = `{{ ${key} }}`;
    try {
      await navigator.clipboard.writeText(snippet);
      setCopiedKey(key);
      setTimeout(() => setCopiedKey((cur) => (cur === key ? null : cur)), 1500);
    } catch {
      // clipboard может быть недоступен в insecure context — игнорим
    }
  }

  function handleClose() {
    setSearch("");
    setActiveNs(null);
    setCopiedKey(null);
    onClose();
  }

  return (
    <Modal
      open={open}
      onClose={handleClose}
      title="Доступные переменные"
      description={
        data
          ? `Шаблон ${data.template.title} (${data.template.code}) — кликните по переменной, чтобы скопировать тег`
          : "Загрузка…"
      }
      width="xl"
      footer={
        <button className="btn-secondary" onClick={handleClose}>
          Закрыть
        </button>
      }
    >
      {isLoading && <div className="text-gray-500">Загрузка переменных…</div>}
      {error && (
        <div className="text-danger text-sm bg-danger/10 px-3 py-2 rounded">
          Не удалось загрузить переменные
        </div>
      )}
      {data && (
        <div className="space-y-4">
          {/* Поиск */}
          <div>
            <div className="relative">
              <i className="bi bi-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
              <input
                className="input pl-9"
                type="search"
                placeholder="Поиск по названию или ключу…"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
              />
            </div>
          </div>

          {/* Tabs */}
          <div className="flex flex-wrap gap-1 border-b border-gray-200">
            {namespaces.map((g) => {
              const active = g.namespace === currentNs;
              return (
                <button
                  key={g.namespace}
                  type="button"
                  onClick={() => setActiveNs(g.namespace)}
                  className={
                    "px-3 py-2 text-sm font-medium border-b-2 -mb-px " +
                    (active
                      ? "border-primary text-primary"
                      : "border-transparent text-gray-600 hover:text-primary")
                  }
                >
                  {g.label}{" "}
                  <span className="text-xs text-gray-400">({g.vars.length})</span>
                </button>
              );
            })}
          </div>

          {/* Таблица переменных */}
          <div className="card overflow-hidden">
            <table className="w-full text-sm">
              <thead className="bg-gray-100 text-gray-700">
                <tr>
                  <th className="text-left px-4 py-2 font-semibold">Ключ</th>
                  <th className="text-left px-4 py-2 font-semibold">Название</th>
                  <th className="text-left px-4 py-2 font-semibold">Пример</th>
                  <th className="w-24" />
                </tr>
              </thead>
              <tbody>
                {filteredVars.map((v) => {
                  const copied = copiedKey === v.key;
                  return (
                    <tr
                      key={v.key}
                      className="border-t border-gray-200 hover:bg-gray-50 cursor-pointer"
                      onClick={() => copyKey(v.key)}
                    >
                      <td className="px-4 py-2 font-mono text-xs">
                        {`{{ ${v.key} }}`}
                      </td>
                      <td className="px-4 py-2">{v.label}</td>
                      <td className="px-4 py-2 text-gray-600 truncate max-w-xs">
                        {v.example || <span className="text-gray-400">—</span>}
                      </td>
                      <td className="px-4 py-2 text-right">
                        {copied ? (
                          <span className="text-xs text-success font-medium">
                            <i className="bi bi-check2" /> Скопировано
                          </span>
                        ) : (
                          <span className="text-xs text-gray-400">
                            <i className="bi bi-clipboard" /> Копировать
                          </span>
                        )}
                      </td>
                    </tr>
                  );
                })}
                {filteredVars.length === 0 && (
                  <tr>
                    <td
                      colSpan={4}
                      className="text-center py-6 text-gray-500 text-sm"
                    >
                      {search
                        ? "Ничего не найдено"
                        : "В этом разделе нет переменных"}
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>

          {/* Фильтры */}
          {data.filters.length > 0 && (
            <div>
              <h3 className="text-sm font-semibold text-primary mb-2">
                Доступные фильтры
              </h3>
              <div className="card divide-y divide-gray-200">
                {data.filters.map((f) => (
                  <div key={f.name} className="px-4 py-3 text-sm">
                    <div className="font-mono text-xs text-primary mb-0.5">
                      | {f.name}
                    </div>
                    <div className="text-gray-700">{f.description}</div>
                  </div>
                ))}
              </div>
            </div>
          )}
        </div>
      )}
    </Modal>
  );
}
