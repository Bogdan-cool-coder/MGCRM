"use client";

import { useState } from "react";
import { api, ApiError } from "@/lib/api";
import type { CalldownProvider } from "@/lib/types";

interface Props {
  provider: CalldownProvider;
}

const INSTRUCTIONS: Record<CalldownProvider, { title: string; steps: string[] }> = {
  mango: {
    title: "Инструкция для Mango Office",
    steps: [
      "Войди в личный кабинет Mango Office",
      "Настройки → Уведомления → Webhook",
      'Вставь URL выше в поле «URL уведомлений»',
      'Выбери события: «Конец разговора»',
      "Сохрани настройки и нажми «Тестовый webhook» ниже для проверки",
    ],
  },
  uis: {
    title: "Инструкция для UIS",
    steps: [
      "Войди в личный кабинет UIS",
      "Настройки → Интеграции → Webhook",
      "Вставь URL выше в поле URL",
      "Выбери событие окончания звонка",
      "Сохрани и протестируй",
    ],
  },
  custom: {
    title: "Инструкция для Custom Webhook",
    steps: [
      "Скопируй URL выше",
      "Вставь его в настройки своего провайдера телефонии",
      "Убедись, что провайдер отправляет POST-запрос с JSON при завершении звонка",
      "Тестовый webhook проверит доступность эндпоинта",
    ],
  },
};

export function WebhookUrlStep({ provider }: Props) {
  const [copied, setCopied] = useState(false);
  const [testing, setTesting] = useState(false);
  const [testResult, setTestResult] = useState<{ ok: boolean; msg: string } | null>(null);

  const webhookUrl =
    typeof window !== "undefined"
      ? `${window.location.origin}/api/integrations/calldown/webhook/${provider}`
      : `/api/integrations/calldown/webhook/${provider}`;

  function handleCopy() {
    void navigator.clipboard.writeText(webhookUrl);
    setCopied(true);
    setTimeout(() => setCopied(false), 2000);
  }

  async function handleTest() {
    setTesting(true);
    setTestResult(null);
    try {
      await api("/integrations/calldown/test-webhook", {
        method: "POST",
        body: { provider },
      });
      setTestResult({ ok: true, msg: "200 OK — webhook принят" });
    } catch (err) {
      const status = err instanceof ApiError ? err.status : 0;
      setTestResult({ ok: false, msg: `Ошибка: ${status} — проверь настройки` });
    } finally {
      setTesting(false);
    }
  }

  const instructions = INSTRUCTIONS[provider];

  return (
    <div>
      <h3 className="text-base font-semibold text-gray-900 dark:text-gray-100 mb-2">
        Webhook URL для провайдера
      </h3>
      <p className="text-sm text-gray-600 dark:text-gray-400 mb-4">
        Вставь этот URL в настройки{" "}
        {provider === "mango" ? "Mango Office" : provider === "uis" ? "UIS" : "своего провайдера"}:
      </p>

      <div className="flex items-center gap-2 bg-gray-50 dark:bg-gray-700 rounded-lg px-4 py-3 mb-6">
        <code className="flex-1 text-sm font-mono text-gray-700 dark:text-gray-200 break-all">
          {webhookUrl}
        </code>
        <button className="btn-ghost text-xs shrink-0" onClick={handleCopy}>
          <i className={`bi ${copied ? "bi-check-lg" : "bi-clipboard"}`} />
          {copied ? " Скопировано" : " Копировать"}
        </button>
      </div>

      <div className="mb-6">
        <h4 className="text-sm font-medium text-gray-900 dark:text-gray-100 mb-2">
          {instructions.title}:
        </h4>
        <ol className="list-decimal pl-5 space-y-1 text-sm text-gray-600 dark:text-gray-400">
          {instructions.steps.map((step, idx) => (
            <li key={idx}>{step}</li>
          ))}
        </ol>
      </div>

      <div>
        <button
          className="btn-secondary"
          onClick={handleTest}
          disabled={testing}
        >
          <i className="bi bi-play-btn mr-1" />
          {testing ? "Отправляем…" : "Отправить тестовый webhook"}
        </button>
        {testResult && (
          <p className={`text-sm mt-2 flex items-center gap-1 ${testResult.ok ? "text-success" : "text-danger"}`}>
            <i className={`bi ${testResult.ok ? "bi-check-circle" : "bi-x-circle"}`} />
            {testResult.msg}
          </p>
        )}
      </div>
    </div>
  );
}
