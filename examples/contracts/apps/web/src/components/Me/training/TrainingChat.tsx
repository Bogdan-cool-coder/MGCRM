"use client";

import { useRef, useState, useEffect } from "react";
import { api, ApiError } from "@/lib/api";
import type { ColdCallResult } from "@/lib/types";

interface TrainingMessage {
  id: number;
  role: "user" | "assistant";
  content: string;
  hints?: string[];
}

interface Props {
  sessionId: number;
  openingLine: string;
  scenarioBrief: string;
  scenarioLabel: string;
  companyType: string;
  companyName: string | null;
  onFinish: (result: ColdCallResult) => void;
}

function errMessage(e: unknown): string {
  if (e instanceof ApiError) {
    if (e.status === 503) return "ИИ-тренажёр временно недоступен (не настроен ANTHROPIC_API_KEY).";
    if (typeof e.detail === "object" && e.detail !== null && "detail" in e.detail) {
      const d = (e.detail as { detail: unknown }).detail;
      if (typeof d === "string") return d;
    }
    return `Ошибка ${e.status}. Попробуйте ещё раз.`;
  }
  return "Не удалось выполнить запрос. Попробуйте ещё раз.";
}

const SCENARIO_TIPS: Record<string, string[]> = {
  cold_call: [
    "Представься и сразу переходи к выгоде — у тебя 30 секунд",
    "Спроси разрешение поговорить: «Удобно ли 2 минуты?»",
    "Не продавай продукт — продавай следующий шаг (встречу)",
  ],
  objection_handling: [
    "Когда слышишь «нет» — уточни причину, а не убеждай сразу",
    "Сравни цену с альтернативой: «Что будет стоить не решить это?»",
    "Предложи пилот или демо вместо полной покупки",
  ],
  ceo_rejection: [
    "Не спорь с директором — запроси конкретные условия пересмотра",
    "Спроси: «Что нужно изменить, чтобы решение изменилось?»",
    "Попробуй выйти на другого ЛПР или инициативного сотрудника",
  ],
  follow_up: [
    "Напомни о предыдущем разговоре коротко",
    "Добавь новую ценность — новость, кейс, обновление",
    "Назначь конкретное следующее действие",
  ],
};

export function TrainingChat({
  sessionId,
  openingLine,
  scenarioBrief,
  scenarioLabel,
  companyType,
  companyName,
  onFinish,
}: Props) {
  const [messages, setMessages] = useState<TrainingMessage[]>([
    {
      id: 0,
      role: "assistant",
      content: openingLine,
    },
  ]);
  const [input, setInput] = useState("");
  const [sending, setSending] = useState(false);
  const [finishing, setFinishing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const endRef = useRef<HTMLDivElement>(null);
  const scenarioKey = scenarioLabel.toLowerCase().includes("холодный") ? "cold_call"
    : scenarioLabel.toLowerCase().includes("возражение") ? "objection_handling"
    : scenarioLabel.toLowerCase().includes("лпр") ? "ceo_rejection"
    : "follow_up";
  const tips = SCENARIO_TIPS[scenarioKey] ?? SCENARIO_TIPS["cold_call"];

  useEffect(() => {
    endRef.current?.scrollIntoView({ behavior: "smooth" });
  }, [messages]);

  async function handleSend() {
    if (!input.trim() || sending) return;
    const text = input.trim();
    setInput("");
    setSending(true);
    setError(null);

    const userMsg: TrainingMessage = { id: Date.now(), role: "user", content: text };
    setMessages((prev) => [...prev, userMsg]);

    try {
      const res = await api<{ content: string; hints?: string[] }>(
        `/me/training/sessions/${sessionId}/message`,
        { method: "POST", body: { content: text } },
      );
      const aiMsg: TrainingMessage = {
        id: Date.now() + 1,
        role: "assistant",
        content: res.content,
        hints: res.hints,
      };
      setMessages((prev) => [...prev, aiMsg]);
    } catch (e) {
      setError(errMessage(e));
    } finally {
      setSending(false);
    }
  }

  async function handleFinish() {
    if (!confirm("Завершить тренировку и получить оценку?")) return;
    setFinishing(true);
    setError(null);
    try {
      const res = await api<ColdCallResult>(
        `/me/training/sessions/${sessionId}/finish`,
        { method: "POST" },
      );
      onFinish(res);
    } catch (e) {
      setError(errMessage(e));
      setFinishing(false);
    }
  }

  return (
    <div className="flex flex-col md:flex-row gap-4 h-[600px]">
      {/* Sidebar tips */}
      <div className="hidden md:flex w-72 shrink-0 flex-col border-r border-gray-200 dark:border-gray-700 pr-4">
        <div className="text-sm font-medium mb-3">Советы</div>
        <div className="space-y-2">
          {tips.map((tip, i) => (
            <div key={i} className="bg-blue-50 dark:bg-blue-900/20 rounded p-3 text-xs text-gray-700 dark:text-gray-300">
              <i className="bi bi-lightbulb text-warning mr-1" />
              {tip}
            </div>
          ))}
        </div>
        <div className="mt-4 pt-4 border-t border-gray-100 dark:border-gray-700">
          <p className="text-xs text-gray-500 font-medium mb-1">{scenarioLabel}</p>
          <p className="text-xs text-gray-400">{scenarioBrief}</p>
        </div>
      </div>

      {/* Chat area */}
      <div className="flex-1 flex flex-col">
        {/* Chat header */}
        <div className="flex items-center justify-between mb-3 pb-3 border-b border-gray-200 dark:border-gray-700">
          <div className="text-sm text-gray-600 dark:text-gray-400">
            <span className="font-medium">{scenarioLabel}</span>
            <span className="mx-1">·</span>
            {companyType}{companyName ? ` «${companyName}»` : ""}
          </div>
          <button
            onClick={handleFinish}
            disabled={finishing}
            className="btn-secondary text-sm text-danger border-danger/30 hover:bg-danger/10"
          >
            <i className="bi bi-stop-circle mr-1" />
            {finishing ? "Завершаем..." : "Завершить звонок"}
          </button>
        </div>

        {/* Messages */}
        <div className="flex-1 overflow-y-auto space-y-3 mb-3">
          {messages.map((msg) => (
            <div key={msg.id} className={`flex ${msg.role === "user" ? "justify-end" : "justify-start"}`}>
              <div className="max-w-[80%]">
                <div
                  className={
                    msg.role === "user"
                      ? "bg-primary text-white rounded-2xl rounded-br-none px-4 py-2 text-sm"
                      : "bg-gray-100 dark:bg-gray-800 rounded-2xl rounded-bl-none px-4 py-2 text-sm"
                  }
                >
                  {msg.content}
                </div>
                {msg.hints && msg.hints.length > 0 && (
                  <div className="mt-1.5 space-y-1">
                    {msg.hints.map((h, i) => (
                      <div key={i} className="flex items-start gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                        <i className="bi bi-lightbulb text-warning mt-0.5 shrink-0" />
                        <span>{h}</span>
                      </div>
                    ))}
                  </div>
                )}
              </div>
            </div>
          ))}
          {sending && (
            <div className="flex justify-start">
              <div className="bg-gray-100 dark:bg-gray-800 rounded-2xl px-4 py-3">
                <div className="flex gap-1">
                  {[0, 1, 2].map((i) => (
                    <span
                      key={i}
                      className="w-2 h-2 bg-gray-400 rounded-full animate-bounce"
                      style={{ animationDelay: `${i * 150}ms` }}
                    />
                  ))}
                </div>
              </div>
            </div>
          )}
          <div ref={endRef} />
        </div>

        {/* Error banner */}
        {error && (
          <div className="mb-2 rounded-lg border border-danger/40 bg-danger/10 px-3 py-2 text-sm text-danger flex items-start gap-2">
            <i className="bi bi-exclamation-triangle-fill mt-0.5 shrink-0" />
            <span>{error}</span>
          </div>
        )}

        {/* Input */}
        <div className="flex gap-2 items-end">
          <textarea
            className="input flex-1 resize-none text-sm"
            rows={2}
            placeholder="Твоя реплика..."
            value={input}
            onChange={(e) => setInput(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === "Enter" && !e.shiftKey) {
                e.preventDefault();
                handleSend();
              }
            }}
            disabled={sending}
          />
          <button
            onClick={handleSend}
            className="btn-primary shrink-0"
            disabled={!input.trim() || sending}
          >
            <i className="bi bi-send" />
          </button>
        </div>
      </div>
    </div>
  );
}
