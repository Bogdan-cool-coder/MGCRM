"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import { Modal } from "@/components/Modal";
import { api, ApiError } from "@/lib/api";
import type { AISeverity, ContractAnalysis } from "@/lib/types";
import { AnalysisFlagCard } from "./AnalysisFlagCard";

type ModalState = "loading" | "result" | "error";
type Tab = "issues" | "standard" | "recommendations";

interface ContractAnalysisModalProps {
  open: boolean;
  onClose: () => void;
  contractId: number;
  contractTitle: string;
}

const SEVERITY_ORDER: Record<AISeverity, number> = {
  error: 0,
  warning: 1,
  info: 2,
};

function formatRelative(iso?: string | null): string {
  if (!iso) return "—";
  const date = new Date(iso);
  const diffMs = Date.now() - date.getTime();
  const diffMin = Math.floor(diffMs / 60_000);
  if (diffMin < 1) return "только что";
  if (diffMin < 60) return `${diffMin} мин назад`;
  const diffH = Math.floor(diffMin / 60);
  if (diffH < 24) return `${diffH} ч назад`;
  return date.toLocaleDateString("ru-RU") + " " + date.toLocaleTimeString("ru-RU", { hour: "2-digit", minute: "2-digit" });
}

export function ContractAnalysisModal({
  open,
  onClose,
  contractId,
  contractTitle,
}: ContractAnalysisModalProps) {
  const [state, setState] = useState<ModalState>("loading");
  const [analysis, setAnalysis] = useState<ContractAnalysis | null>(null);
  const [apiError, setApiError] = useState<string | null>(null);
  const [activeTab, setActiveTab] = useState<Tab>("issues");

  const runAnalysis = useCallback(
    async (forceRefresh = false) => {
      setState("loading");
      setApiError(null);
      try {
        const url = `/contracts/${contractId}/ai-analyze${forceRefresh ? "?force_refresh=true" : ""}`;
        const result = await api<ContractAnalysis>(url, { method: "POST" });
        setAnalysis(result);
        setState("result");
      } catch (e) {
        if (e instanceof ApiError) {
          if (e.status === 503) {
            setApiError("AI не настроен. Обратитесь к администратору, чтобы установить ANTHROPIC_API_KEY.");
          } else if (e.status === 502) {
            setApiError("AI временно недоступен. Попробуйте ещё раз через минуту.");
          } else {
            const detail = (e.detail as { detail?: string })?.detail ?? e.message;
            setApiError(detail);
          }
        } else {
          setApiError("Не удалось выполнить анализ");
        }
        setState("error");
      }
    },
    [contractId],
  );

  useEffect(() => {
    if (open) {
      runAnalysis(false);
    } else {
      // сбрасываем при закрытии — следующее открытие чистое
      setAnalysis(null);
      setApiError(null);
      setActiveTab("issues");
    }
  }, [open, runAnalysis]);

  const issuesSorted = useMemo(() => {
    if (!analysis) return [];
    return [...analysis.issues].sort(
      (a, b) => (SEVERITY_ORDER[a.severity] ?? 99) - (SEVERITY_ORDER[b.severity] ?? 99),
    );
  }, [analysis]);

  const issuesCount = analysis?.issues.length ?? 0;
  const standardCount = analysis?.standard_sections.length ?? 0;
  const recommendationsCount = analysis?.recommendations.length ?? 0;

  const footer = (
    <>
      <button onClick={onClose} className="btn-ghost">
        Закрыть
      </button>
    </>
  );

  return (
    <Modal
      open={open}
      onClose={onClose}
      width="lg"
      title="AI анализ договора"
      description={contractTitle}
      footer={footer}
    >
      {/* Кэш-индикатор */}
      {state === "result" && analysis?.from_cache && (
        <div className="text-xs text-gray-400 flex items-center gap-2 mb-3">
          <span>Последний анализ: {formatRelative(analysis.analyzed_at)}</span>
          <button
            type="button"
            className="btn-ghost text-xs px-2 py-0.5"
            onClick={() => runAnalysis(true)}
          >
            Обновить
          </button>
        </div>
      )}

      {/* Loading */}
      {state === "loading" && (
        <div className="flex flex-col items-center justify-center py-16 text-gray-500">
          <i className="bi bi-arrow-repeat animate-spin text-3xl text-primary mb-4" />
          <p className="font-medium dark:text-gray-200">AI анализирует договор...</p>
          <p className="text-xs text-gray-400 mt-1">Это займёт несколько секунд</p>
        </div>
      )}

      {/* Error */}
      {state === "error" && (
        <div className="flex flex-col items-center justify-center py-12 text-center">
          <i className="bi bi-exclamation-octagon text-danger text-3xl mb-3" />
          <p className="font-medium dark:text-gray-200">Не удалось выполнить анализ</p>
          {apiError && (
            <p className="text-sm text-gray-500 mb-4 mt-1 max-w-md">{apiError}</p>
          )}
          <button onClick={() => runAnalysis(false)} className="btn-secondary">
            <i className="bi bi-arrow-clockwise" /> Повторить
          </button>
        </div>
      )}

      {/* Result */}
      {state === "result" && analysis && (
        <>
          {/* Табы */}
          <div className="flex border-b border-gray-200 dark:border-gray-700 mb-4">
            <TabButton
              active={activeTab === "issues"}
              onClick={() => setActiveTab("issues")}
              label={`Замечания (${issuesCount})`}
            />
            <TabButton
              active={activeTab === "standard"}
              onClick={() => setActiveTab("standard")}
              label={`Стандартные пункты (${standardCount})`}
            />
            <TabButton
              active={activeTab === "recommendations"}
              onClick={() => setActiveTab("recommendations")}
              label={`Советы (${recommendationsCount})`}
            />
          </div>

          {/* Контент */}
          {activeTab === "issues" && (
            <div>
              {issuesSorted.length === 0 ? (
                <div className="flex flex-col items-center text-center py-12">
                  <i className="bi bi-check-circle-fill text-success text-4xl mb-3" />
                  <p className="font-medium dark:text-gray-200">Замечаний нет</p>
                  <p className="text-sm text-gray-500 mt-1 max-w-md">
                    Договор выглядит чисто — нестандартных пунктов не обнаружено
                  </p>
                </div>
              ) : (
                issuesSorted.map((it, idx) => (
                  <AnalysisFlagCard key={idx} issue={it} />
                ))
              )}
            </div>
          )}

          {activeTab === "standard" && (
            <div>
              {standardCount === 0 ? (
                <p className="text-sm text-gray-500 py-6 text-center">
                  AI не нашёл стандартных секций для проверки
                </p>
              ) : (
                analysis.standard_sections.map((s, idx) => (
                  <div
                    key={idx}
                    className="flex items-center gap-2 py-2 border-b border-gray-100 dark:border-gray-700"
                  >
                    {s.status === "ok" ? (
                      <i className="bi bi-check-lg text-success" />
                    ) : (
                      <i className="bi bi-exclamation text-warning" />
                    )}
                    <span className="text-sm dark:text-gray-200">{s.section}</span>
                  </div>
                ))
              )}
            </div>
          )}

          {activeTab === "recommendations" && (
            <div>
              {recommendationsCount === 0 ? (
                <p className="text-sm text-gray-500 py-6 text-center">
                  Дополнительных рекомендаций нет
                </p>
              ) : (
                analysis.recommendations.map((r, idx) => (
                  <div
                    key={idx}
                    className="flex items-start gap-2 py-2 border-b border-gray-100 dark:border-gray-700"
                  >
                    <i className="bi bi-lightbulb text-info flex-shrink-0 mt-0.5" />
                    <span className="text-sm dark:text-gray-200">{r}</span>
                  </div>
                ))
              )}
            </div>
          )}

          {/* Footer kontext info */}
          {analysis.model && (
            <p className="text-xs text-gray-400 mt-4 text-center">
              Модель: {analysis.model}
              {analysis.ai_tokens_used != null && ` · ${analysis.ai_tokens_used} токенов`}
            </p>
          )}
        </>
      )}
    </Modal>
  );
}

function TabButton({
  active,
  onClick,
  label,
}: {
  active: boolean;
  onClick: () => void;
  label: string;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={[
        "px-3 py-2 text-sm font-medium border-b-2 transition",
        active
          ? "border-primary text-primary dark:text-gray-100"
          : "border-transparent text-gray-500 hover:text-primary dark:hover:text-gray-200",
      ].join(" ")}
    >
      {label}
    </button>
  );
}
