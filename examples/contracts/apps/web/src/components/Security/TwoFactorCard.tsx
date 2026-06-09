"use client";

import { useState } from "react";
import { useMe } from "@/lib/auth";
import { TwoFactorSetupModal } from "./TwoFactorSetupModal";
import { TwoFactorConfirmModal } from "./TwoFactorConfirmModal";

export function TwoFactorCard() {
  const { user, isLoading, error, mutate } = useMe();
  const [setupOpen, setSetupOpen] = useState(false);
  const [confirmMode, setConfirmMode] = useState<"disable" | "new-backup-codes" | null>(null);

  const totpEnabled = user?.totp_enabled ?? false;
  const enabledAt = user?.totp_enabled_at ?? null;

  function formatDate(iso: string | null): string {
    if (!iso) return "";
    return new Date(iso).toLocaleDateString("ru-RU", {
      day: "numeric",
      month: "long",
      year: "numeric",
    });
  }

  if (isLoading) {
    return (
      <div className="card p-6">
        <div className="animate-pulse space-y-3">
          <div className="h-4 bg-gray-100 rounded w-2/3" />
          <div className="h-4 bg-gray-100 rounded w-1/2" />
        </div>
      </div>
    );
  }

  if (error) {
    return (
      <div className="card p-6">
        <p className="text-danger text-sm">Не удалось загрузить статус 2FA</p>
      </div>
    );
  }

  return (
    <>
      <div className="card p-6">
        <div className="flex items-start gap-3 mb-4">
          <i className={`bi ${totpEnabled ? "bi-shield-check text-success" : "bi-shield-lock text-primary"} text-2xl`} />
          <h3 className="text-h4">Двухфакторная аутентификация</h3>
        </div>

        {!totpEnabled ? (
          <>
            <p className="text-sm text-gray-600 mb-4">
              Защитите аккаунт дополнительным кодом из приложения-аутентификатора
              (Google Authenticator, Яндекс.Ключ и др.)
            </p>
            <button
              type="button"
              className="btn-primary"
              onClick={() => setSetupOpen(true)}
            >
              <i className="bi bi-shield-plus mr-1" />
              Подключить 2FA
            </button>
          </>
        ) : (
          <>
            <div className="mb-4">
              <span className="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium bg-success/10 text-success">
                <i className="bi bi-check-circle" />
                2FA активирована
              </span>
              {enabledAt && (
                <p className="text-sm text-gray-500 mt-1">
                  Подключена: {formatDate(enabledAt)}
                </p>
              )}
            </div>
            <div className="flex gap-3 flex-wrap mt-4">
              <button
                type="button"
                className="btn-secondary"
                onClick={() => setConfirmMode("new-backup-codes")}
              >
                <i className="bi bi-arrow-repeat mr-1" />
                Сгенерировать новые резервные коды
              </button>
              <button
                type="button"
                className="btn-ghost text-danger"
                onClick={() => setConfirmMode("disable")}
              >
                <i className="bi bi-shield-x mr-1" />
                Отключить 2FA
              </button>
            </div>
          </>
        )}
      </div>

      <TwoFactorSetupModal
        open={setupOpen}
        onClose={() => setSetupOpen(false)}
        onDone={() => {
          setSetupOpen(false);
          void mutate();
        }}
      />

      {confirmMode !== null && (
        <TwoFactorConfirmModal
          open={true}
          mode={confirmMode}
          onClose={() => setConfirmMode(null)}
          onSuccess={() => {
            setConfirmMode(null);
            void mutate();
          }}
        />
      )}
    </>
  );
}
