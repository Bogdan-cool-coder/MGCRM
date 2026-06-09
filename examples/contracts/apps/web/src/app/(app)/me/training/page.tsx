"use client";

import { useState } from "react";
import { mutate } from "swr";
import { PageHeader } from "@/components/PageHeader";
import { ScenarioSetup } from "@/components/Me/training/ScenarioSetup";
import { TrainingChat } from "@/components/Me/training/TrainingChat";
import { TrainingScorecard } from "@/components/Me/training/TrainingScorecard";
import { TrainingHistory } from "@/components/Me/training/TrainingHistory";
import { TrainingDetailView } from "@/components/Me/training/TrainingDetailView";
import { api, ApiError } from "@/lib/api";
import { useMe } from "@/lib/auth";
import type { ScenarioType } from "@/components/Me/training/ScenarioSetup";
import type { ColdCallResult } from "@/lib/types";

type Phase = "setup" | "chat" | "result" | "detail";

const SALES_ROLES = ["admin", "director", "manager"];

const SCENARIO_LABELS: Record<ScenarioType, string> = {
  cold_call: "Холодный звонок",
  objection_handling: "Возражение по цене",
  ceo_rejection: "Отказ ЛПР",
  follow_up: "Повторный звонок",
};

function startErrMessage(e: unknown): string {
  if (e instanceof ApiError) {
    if (e.status === 503) return "ИИ-тренажёр временно недоступен (не настроен ANTHROPIC_API_KEY).";
    if (e.status === 403) return "Тренажёр доступен только отделу продаж.";
    if (typeof e.detail === "object" && e.detail !== null && "detail" in e.detail) {
      const d = (e.detail as { detail: unknown }).detail;
      if (typeof d === "string") return d;
    }
    return `Ошибка ${e.status}. Попробуйте ещё раз.`;
  }
  return "Не удалось запустить тренировку. Попробуйте ещё раз.";
}

export default function TrainingPage() {
  const { user, isLoading: meLoading } = useMe();
  const [phase, setPhase] = useState<Phase>("setup");
  const [loading, setLoading] = useState(false);
  const [startError, setStartError] = useState<string | null>(null);
  const [sessionId, setSessionId] = useState<number | null>(null);
  const [detailId, setDetailId] = useState<number | null>(null);
  const [openingLine, setOpeningLine] = useState("");
  const [scenarioBrief, setScenarioBrief] = useState("");
  const [scenarioType, setScenarioType] = useState<ScenarioType>("cold_call");
  const [companyType, setCompanyType] = useState("");
  const [companyName, setCompanyName] = useState<string | null>(null);
  const [result, setResult] = useState<ColdCallResult | null>(null);

  const isSales = !!user && SALES_ROLES.includes(user.role);

  async function handleStart(scenario: ScenarioType, ctType: string, ctName: string) {
    setLoading(true);
    setStartError(null);
    try {
      const res = await api<{
        id: number;
        opening_line: string;
        scenario_brief: string;
      }>("/me/training/sessions", {
        method: "POST",
        body: {
          scenario_type: scenario,
          company_type: ctType,
          company_name: ctName || null,
        },
      });
      setSessionId(res.id);
      setOpeningLine(res.opening_line);
      setScenarioBrief(res.scenario_brief);
      setScenarioType(scenario);
      setCompanyType(ctType);
      setCompanyName(ctName || null);
      setPhase("chat");
    } catch (e) {
      setStartError(startErrMessage(e));
    } finally {
      setLoading(false);
    }
  }

  function handleFinish(res: ColdCallResult) {
    setResult(res);
    setPhase("result");
    mutate("/me/training/sessions");
  }

  function handleNewTraining() {
    setPhase("setup");
    setSessionId(null);
    setResult(null);
    setStartError(null);
    setOpeningLine("");
    setScenarioBrief("");
  }

  function handleOpenDetail(id: number) {
    setDetailId(id);
    setPhase("detail");
  }

  return (
    <>
      <PageHeader
        title="Тренажёр холодных звонков"
        description="Оттачивай навыки продаж в безопасной среде"
      />

      <div className="p-6">
        {meLoading ? (
          <div className="max-w-2xl mx-auto space-y-4">
            <div className="card rounded-2xl shadow-elev-1 p-6 h-40 animate-pulse bg-gray-100 dark:bg-gray-800" />
            <div className="h-4 w-48 rounded animate-pulse bg-gray-100 dark:bg-gray-800" />
          </div>
        ) : !isSales ? (
          <div className="card rounded-2xl shadow-elev-1 p-8 max-w-md mx-auto text-center">
            <div className="w-14 h-14 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center mx-auto mb-4">
              <i className="bi bi-lock text-2xl text-gray-400" />
            </div>
            <h3 className="font-semibold text-gray-900 dark:text-gray-100 mb-1">
              Доступно только отделу продаж
            </h3>
            <p className="text-sm text-gray-500">
              Тренажёр звонков предназначен для менеджеров и руководителей продаж.
            </p>
          </div>
        ) : (
          <>
            {phase === "setup" && (
              <>
                {startError && (
                  <div className="mb-4 rounded-xl border border-danger/30 bg-danger/5 dark:bg-danger/10 dark:border-danger/20 px-4 py-3 text-sm text-danger flex items-start gap-2.5">
                    <i className="bi bi-exclamation-triangle-fill mt-0.5 shrink-0 text-base" />
                    <span>{startError}</span>
                  </div>
                )}
                <div className="grid grid-cols-1 lg:grid-cols-[1fr_360px] gap-6 items-start">
                  <ScenarioSetup onStart={handleStart} loading={loading} />
                  <div className="lg:sticky lg:top-6">
                    <TrainingHistory onOpen={handleOpenDetail} />
                  </div>
                </div>
              </>
            )}

            {phase === "chat" && sessionId && (
              <div className="card p-6">
                <TrainingChat
                  sessionId={sessionId}
                  openingLine={openingLine}
                  scenarioBrief={scenarioBrief}
                  scenarioLabel={SCENARIO_LABELS[scenarioType]}
                  companyType={companyType}
                  companyName={companyName}
                  onFinish={handleFinish}
                />
              </div>
            )}

            {phase === "result" && result && (
              <TrainingScorecard
                score={result.score}
                scores={result.scores}
                feedback={result.feedback}
                recommendations={result.recommendations}
                goodDecisions={result.good_decisions}
                onNewTraining={handleNewTraining}
              />
            )}

            {phase === "detail" && detailId != null && (
              <TrainingDetailView sessionId={detailId} onBack={handleNewTraining} />
            )}
          </>
        )}
      </div>
    </>
  );
}
