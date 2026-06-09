"use client";

import type { CalldownProvider } from "@/lib/types";

interface Props {
  provider: CalldownProvider;
  apiKey: string;
  apiSalt: string;
  accountId: string;
  apiToken: string;
  onChange: (field: string, value: string) => void;
}

export function CredentialsStep({ provider, apiKey, apiSalt, accountId, apiToken, onChange }: Props) {
  if (provider === "custom") {
    return (
      <div>
        <h3 className="text-base font-semibold text-gray-900 dark:text-gray-100 mb-4">
          Ключи API — Custom Webhook
        </h3>
        <div className="bg-info/10 text-info rounded-lg p-4 text-sm">
          <i className="bi bi-info-circle mr-2" />
          Для Custom Webhook дополнительные ключи не требуются. На следующем шаге ты получишь
          URL, который нужно настроить в своём провайдере.
        </div>
      </div>
    );
  }

  if (provider === "mango") {
    return (
      <div>
        <h3 className="text-base font-semibold text-gray-900 dark:text-gray-100 mb-4">
          Ключи Mango Office API
        </h3>
        <div className="space-y-4">
          <div>
            <label className="label">API Key <span className="text-danger">*</span></label>
            <input
              className="input"
              placeholder="Mango API Key"
              value={apiKey}
              onChange={(e) => onChange("apiKey", e.target.value)}
            />
          </div>
          <div>
            <label className="label">API Salt <span className="text-danger">*</span></label>
            <input
              className="input"
              type="password"
              placeholder="Mango API Salt"
              value={apiSalt}
              onChange={(e) => onChange("apiSalt", e.target.value)}
            />
          </div>
          <div className="text-xs text-gray-500 dark:text-gray-400">
            Найти ключи можно в личном кабинете Mango Office: Настройки → Интеграции → API
          </div>
        </div>
      </div>
    );
  }

  // UIS
  return (
    <div>
      <h3 className="text-base font-semibold text-gray-900 dark:text-gray-100 mb-4">
        Ключи UIS API
      </h3>
      <div className="space-y-4">
        <div>
          <label className="label">Account ID <span className="text-danger">*</span></label>
          <input
            className="input"
            placeholder="ID аккаунта UIS"
            value={accountId}
            onChange={(e) => onChange("accountId", e.target.value)}
          />
        </div>
        <div>
          <label className="label">API Token <span className="text-danger">*</span></label>
          <input
            className="input"
            type="password"
            placeholder="Токен UIS API"
            value={apiToken}
            onChange={(e) => onChange("apiToken", e.target.value)}
          />
        </div>
        <div className="text-xs text-gray-500 dark:text-gray-400">
          Найти данные можно в личном кабинете UIS: Настройки → API
        </div>
      </div>
    </div>
  );
}
