"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import useSWR from "swr";
import { api, ApiError, fetcher } from "@/lib/api";
import { ProviderStep } from "./ProviderStep";
import { CredentialsStep } from "./CredentialsStep";
import { WebhookUrlStep } from "./WebhookUrlStep";
import { TranscriptionStep } from "./TranscriptionStep";
import type { CalldownConfig, CalldownProvider } from "@/lib/types";

type Step = 1 | 2 | 3 | 4;

const STEP_LABELS: Record<Step, string> = {
  1: "Провайдер",
  2: "Ключи API",
  3: "Webhook",
  4: "Транскрипция",
};

interface FormData {
  provider: CalldownProvider | null;
  apiKey: string;
  apiSalt: string;
  accountId: string;
  apiToken: string;
  transcriptionEnabled: boolean;
  transcriptionLang: string;
  transcriptionMinDuration: number;
  openaiKey: string;
}

export function CalldownWizard() {
  const router = useRouter();
  const { data: config } = useSWR<CalldownConfig>("/integrations/calldown/config", fetcher);

  const [step, setStep] = useState<Step>(1);
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  const [form, setForm] = useState<FormData>({
    provider: null,
    apiKey: "",
    apiSalt: "",
    accountId: "",
    apiToken: "",
    transcriptionEnabled: false,
    transcriptionLang: "ru",
    transcriptionMinDuration: 30,
    openaiKey: "",
  });

  // Prefill from existing config
  useEffect(() => {
    if (config) {
      setForm((prev) => ({
        ...prev,
        provider: config.provider ?? null,
        apiKey: config.api_key ?? "",
        apiSalt: config.api_salt ?? "",
        accountId: config.account_id ?? "",
        apiToken: config.api_token_value ?? "",
        transcriptionEnabled: config.transcription_enabled,
        transcriptionLang: config.transcription_lang || "ru",
        transcriptionMinDuration: config.transcription_min_duration_sec || 30,
        openaiKey: config.openai_api_key ?? "",
      }));
    }
  }, [config]);

  function handleFieldChange(field: string, value: string | number | boolean) {
    setForm((prev) => ({ ...prev, [field]: value }));
  }

  function validateStep(): boolean {
    setError(null);
    if (step === 1) {
      if (!form.provider) {
        setError("Выбери провайдер телефонии");
        return false;
      }
    }
    if (step === 2 && form.provider === "mango") {
      if (!form.apiKey.trim() || !form.apiSalt.trim()) {
        setError("Введи API Key и API Salt");
        return false;
      }
    }
    if (step === 2 && form.provider === "uis") {
      if (!form.accountId.trim() || !form.apiToken.trim()) {
        setError("Введи Account ID и API Token");
        return false;
      }
    }
    return true;
  }

  function handleNext() {
    if (!validateStep()) return;
    setStep((s) => Math.min(4, s + 1) as Step);
  }

  function handleBack() {
    setError(null);
    setStep((s) => Math.max(1, s - 1) as Step);
  }

  async function handleSave() {
    setSaving(true);
    setError(null);
    try {
      await api("/integrations/calldown/setup", {
        method: "POST",
        body: {
          provider: form.provider,
          api_key: form.apiKey || null,
          api_salt: form.apiSalt || null,
          account_id: form.accountId || null,
          api_token: form.apiToken || null,
          transcription_enabled: form.transcriptionEnabled,
          transcription_lang: form.transcriptionLang,
          transcription_min_duration_sec: form.transcriptionMinDuration,
          openai_api_key: form.openaiKey || null,
        },
      });
      router.push("/admin/integrations?success=calldown_saved");
    } catch (err) {
      setError(
        err instanceof ApiError
          ? String((err.detail as { detail?: string })?.detail ?? err.message)
          : "Не удалось сохранить настройки"
      );
    } finally {
      setSaving(false);
    }
  }

  const steps: Step[] = [1, 2, 3, 4];

  return (
    <div className="max-w-2xl">
      {/* Stepper */}
      <div className="flex items-center gap-0 mb-8">
        {steps.map((s, idx) => (
          <div key={s} className="flex items-center flex-1 min-w-0">
            <div className="flex items-center gap-2 shrink-0">
              <div
                className={`w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold transition-colors ${
                  s < step
                    ? "bg-success text-white"
                    : s === step
                    ? "bg-primary text-white"
                    : "bg-gray-200 dark:bg-gray-700 text-gray-500 dark:text-gray-400"
                }`}
              >
                {s < step ? <i className="bi bi-check-lg" /> : s}
              </div>
              <span
                className={`text-sm hidden sm:block ${
                  s === step
                    ? "text-primary font-semibold"
                    : s < step
                    ? "text-success"
                    : "text-gray-400"
                }`}
              >
                {STEP_LABELS[s]}
              </span>
            </div>
            {idx < steps.length - 1 && (
              <div
                className={`flex-1 border-t-2 mx-2 transition-colors ${
                  s < step ? "border-success" : "border-gray-200 dark:border-gray-700"
                }`}
              />
            )}
          </div>
        ))}
      </div>

      {/* Step content */}
      <div className="card rounded-2xl shadow-elev-1 border border-gray-100 dark:border-gray-800 p-6 mb-6">
        {step === 1 && (
          <ProviderStep
            value={form.provider}
            onChange={(p) => handleFieldChange("provider", p)}
          />
        )}
        {step === 2 && form.provider && (
          <CredentialsStep
            provider={form.provider}
            apiKey={form.apiKey}
            apiSalt={form.apiSalt}
            accountId={form.accountId}
            apiToken={form.apiToken}
            onChange={handleFieldChange}
          />
        )}
        {step === 3 && form.provider && (
          <WebhookUrlStep provider={form.provider} />
        )}
        {step === 4 && (
          <TranscriptionStep
            enabled={form.transcriptionEnabled}
            lang={form.transcriptionLang}
            minDuration={form.transcriptionMinDuration}
            openaiKey={form.openaiKey}
            onChange={handleFieldChange}
          />
        )}

        {error && (
          <div className="mt-4 rounded-lg bg-danger/10 text-danger px-4 py-2.5 text-sm flex items-center gap-2">
            <i className="bi bi-exclamation-circle shrink-0" />
            {error}
          </div>
        )}
      </div>

      {/* Navigation buttons */}
      <div className="flex justify-between items-center">
        <div>
          {step === 1 && (
            <button className="btn-ghost" onClick={() => router.push("/admin/integrations")}>
              Отмена
            </button>
          )}
          {step > 1 && (
            <button className="btn-ghost" onClick={handleBack}>
              <i className="bi bi-arrow-left mr-1" />
              Назад
            </button>
          )}
        </div>
        <div>
          {step < 4 && (
            <button className="btn-primary" onClick={handleNext}>
              Далее
              <i className="bi bi-arrow-right ml-1" />
            </button>
          )}
          {step === 4 && (
            <button className="btn-primary" onClick={handleSave} disabled={saving}>
              {saving ? "Сохраняем…" : "Сохранить"}
            </button>
          )}
        </div>
      </div>
    </div>
  );
}
