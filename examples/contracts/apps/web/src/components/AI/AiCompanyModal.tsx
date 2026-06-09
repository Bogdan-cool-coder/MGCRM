"use client";

import { useState } from "react";
import { Modal } from "@/components/Modal";
import { api } from "@/lib/api";
import type { CounterpartyAIAnalysis } from "@/lib/types";
import { formatDateTime } from "@/lib/dates";

interface Props {
  open: boolean;
  onClose: () => void;
  counterpartyId: number;
  counterpartyName: string;
}

function IcpBadge({ fit }: { fit: CounterpartyAIAnalysis["icp_fit"] }) {
  const map: Record<string, { label: string; cls: string }> = {
    high: { label: "Высокий", cls: "bg-success/10 text-success" },
    medium: { label: "Средний", cls: "bg-warning/10 text-warning" },
    low: { label: "Низкий", cls: "bg-danger/10 text-danger" },
  };
  const m = map[fit] ?? { label: fit, cls: "bg-gray-100 text-gray-600" };
  return <span className={`badge ${m.cls}`}>{m.label}</span>;
}

function RelationshipBadge({ health }: { health: CounterpartyAIAnalysis["relationship_health"] }) {
  const map: Record<string, { label: string; cls: string; icon?: string }> = {
    cold: { label: "Холодный", cls: "bg-info/10 text-info" },
    warm: { label: "Тёплый", cls: "bg-warning/10 text-warning" },
    hot: { label: "Горячий", cls: "bg-danger/10 text-danger" },
    at_risk: { label: "Под угрозой", cls: "bg-danger/10 text-danger", icon: "bi-exclamation-triangle" },
  };
  const m = map[health] ?? { label: health, cls: "bg-gray-100 text-gray-600" };
  return (
    <span className={`badge ${m.cls}`}>
      {m.icon && <i className={`bi ${m.icon} mr-1`} />}
      {m.label}
    </span>
  );
}

export function AiCompanyModal({ open, onClose, counterpartyId, counterpartyName }: Props) {
  const [analysis, setAnalysis] = useState<CounterpartyAIAnalysis | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [initialized, setInitialized] = useState(false);

  async function loadAnalysis(force = false) {
    setLoading(true);
    setError(null);
    try {
      if (!force) {
        // Try cache first
        try {
          const cached = await api<CounterpartyAIAnalysis>(`/counterparties/${counterpartyId}/ai-summary`);
          if (cached?.generated_at) {
            const ageMs = Date.now() - new Date(cached.generated_at).getTime();
            if (ageMs < 24 * 60 * 60 * 1000) {
              setAnalysis(cached);
              return;
            }
          }
        } catch {
          // No cache, proceed to generate
        }
      }
      // Generate new
      const result = await api<CounterpartyAIAnalysis>(`/counterparties/${counterpartyId}/ai-summary`, {
        method: "POST",
        query: force ? { force: true } : undefined,
      });
      setAnalysis(result);
    } catch {
      setError("Не удалось проанализировать. Попробуй ещё раз.");
    } finally {
      setLoading(false);
    }
  }

  // Load on open
  if (open && !initialized) {
    setInitialized(true);
    loadAnalysis();
  }
  if (!open && initialized) {
    setInitialized(false);
    setAnalysis(null);
    setError(null);
  }

  return (
    <Modal
      open={open}
      title={`AI-разбор: ${counterpartyName}`}
      onClose={onClose}
      width="lg"
      footer={
        <button onClick={onClose} className="btn-ghost">Закрыть</button>
      }
    >
      {loading && (
        <div className="flex flex-col items-center justify-center py-12 text-center">
          <i className="bi bi-stars text-3xl text-primary animate-spin mb-3" />
          <p className="text-gray-600 dark:text-gray-400 font-medium">AI анализирует клиента...</p>
          <p className="text-gray-400 text-sm mt-1">Обычно занимает 5–10 секунд</p>
        </div>
      )}

      {!loading && error && (
        <div className="flex flex-col items-center justify-center py-12 text-center">
          <i className="bi bi-exclamation-circle text-3xl text-danger mb-3" />
          <p className="text-danger font-medium">{error}</p>
          <button onClick={() => loadAnalysis()} className="btn-secondary mt-3">
            Повторить
          </button>
        </div>
      )}

      {!loading && !error && analysis && (
        <div className="space-y-4">
          {/* ICP Fit */}
          <div className="card p-4">
            <div className="flex items-center justify-between mb-2">
              <h4 className="text-sm font-semibold text-gray-700 dark:text-gray-300">ICP Fit</h4>
              <IcpBadge fit={analysis.icp_fit} />
            </div>
            <p className="text-sm text-gray-600 dark:text-gray-400">{analysis.summary}</p>
          </div>

          {/* Риски */}
          {analysis.risks.length > 0 && (
            <div className="card p-4">
              <h4 className="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2 flex items-center gap-1">
                <i className="bi bi-exclamation-triangle text-warning" />
                Риски ({analysis.risks.length})
              </h4>
              <ul className="space-y-1">
                {analysis.risks.map((r, i) => (
                  <li key={i} className="text-sm text-gray-600 dark:text-gray-400 flex items-start gap-1">
                    <span className="text-gray-400 mt-0.5">·</span>
                    {r}
                  </li>
                ))}
              </ul>
            </div>
          )}

          {/* Рекомендации */}
          {analysis.recommendations.length > 0 && (
            <div className="card p-4">
              <h4 className="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2 flex items-center gap-1">
                <i className="bi bi-arrow-right text-success" />
                Рекомендации ({analysis.recommendations.length})
              </h4>
              <ul className="space-y-1">
                {analysis.recommendations.map((r, i) => (
                  <li key={i} className="text-sm text-gray-600 dark:text-gray-400 flex items-start gap-1">
                    <span className="text-gray-400 mt-0.5">·</span>
                    {r}
                  </li>
                ))}
              </ul>
            </div>
          )}

          {/* Статус + приоритет */}
          <div className="card p-4 grid grid-cols-2 gap-4">
            <div>
              <div className="text-xs text-gray-500 mb-1">Статус отношений</div>
              <RelationshipBadge health={analysis.relationship_health} />
            </div>
            <div>
              <div className="text-xs text-gray-500 mb-1">
                Приоритет клиента: <span className="font-semibold">{analysis.priority_score} / 10</span>
              </div>
              <div className="h-2 rounded-full bg-gray-100 dark:bg-gray-700 overflow-hidden">
                <div
                  className="h-full rounded-full bg-primary"
                  style={{ width: `${(analysis.priority_score / 10) * 100}%` }}
                />
              </div>
            </div>
          </div>

          {/* Метаданные */}
          <div className="flex items-center justify-between text-xs text-gray-400 pt-2">
            <span className="flex items-center gap-1">
              <i className="bi bi-clock text-gray-300" />
              Сгенерировано {formatDateTime(analysis.generated_at)}
              {analysis.from_cache && " (кэш)"}
            </span>
            <button
              onClick={() => loadAnalysis(true)}
              className="btn-secondary text-xs"
            >
              <i className="bi bi-arrow-repeat mr-1" />
              Обновить анализ
            </button>
          </div>
        </div>
      )}
    </Modal>
  );
}
