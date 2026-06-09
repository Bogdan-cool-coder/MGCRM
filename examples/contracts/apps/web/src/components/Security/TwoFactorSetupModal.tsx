"use client";

import { useEffect, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { Modal } from "@/components/Modal";
import { BackupCodesDisplay } from "./BackupCodesDisplay";
import { api, ApiError } from "@/lib/api";

type SetupStep = 1 | 2 | 3 | 4;

interface QrData {
  qr_base64: string;
  manual_code: string;
  otpauth_uri: string;
}

interface Props {
  open: boolean;
  onClose: () => void;
  onDone: () => void;
}

const APPS = [
  {
    name: "Google Authenticator",
    appStore: "https://apps.apple.com/app/google-authenticator/id388497605",
    googlePlay: "https://play.google.com/store/apps/details?id=com.google.android.apps.authenticator2",
  },
  {
    name: "Яндекс.Ключ",
    appStore: "https://apps.apple.com/ru/app/id957324816",
    googlePlay: "https://play.google.com/store/apps/details?id=ru.yandex.key",
  },
  {
    name: "Microsoft Authenticator",
    appStore: "https://apps.apple.com/app/microsoft-authenticator/id983156458",
    googlePlay: "https://play.google.com/store/apps/details?id=com.azure.authenticator",
  },
];

const STEP_LABELS: Record<SetupStep, string> = {
  1: "Приложение",
  2: "QR-код",
  3: "Проверка",
  4: "Резервные коды",
};

export function TwoFactorSetupModal({ open, onClose, onDone }: Props) {
  const router = useRouter();
  const [step, setStep] = useState<SetupStep>(1);
  const [qrData, setQrData] = useState<QrData | null>(null);
  const [qrLoading, setQrLoading] = useState(false);
  const [qrError, setQrError] = useState<string | null>(null);
  const [showManual, setShowManual] = useState(false);
  const [copied, setCopied] = useState(false);
  const [code, setCode] = useState("");
  const [verifying, setVerifying] = useState(false);
  const [verifyError, setVerifyError] = useState<string | null>(null);
  const [backupCodes, setBackupCodes] = useState<string[]>([]);
  const codeInputRef = useRef<HTMLInputElement>(null);

  // Reset state on open/close
  useEffect(() => {
    if (open) {
      setStep(1);
      setQrData(null);
      setQrError(null);
      setShowManual(false);
      setCopied(false);
      setCode("");
      setVerifyError(null);
      setBackupCodes([]);
    }
  }, [open]);

  // Focus code input on step 3
  useEffect(() => {
    if (step === 3) {
      setTimeout(() => codeInputRef.current?.focus(), 50);
    }
  }, [step]);

  async function loadQr() {
    setQrLoading(true);
    setQrError(null);
    try {
      const data = await api<QrData>("/auth/2fa/setup", { method: "POST" });
      setQrData(data);
    } catch (err) {
      setQrError(err instanceof ApiError ? String((err.detail as { detail?: string })?.detail ?? err.message) : "Не удалось получить QR-код");
    } finally {
      setQrLoading(false);
    }
  }

  function handleNext() {
    if (step === 1) {
      setStep(2);
      void loadQr();
    } else if (step === 2) {
      setStep(3);
    }
  }

  function handleBack() {
    if (step === 2) {
      setStep(1);
      setQrData(null);
      setQrError(null);
    } else if (step === 3) {
      setStep(2);
      setCode("");
      setVerifyError(null);
    }
  }

  async function handleVerify() {
    if (code.length !== 6) return;
    setVerifying(true);
    setVerifyError(null);
    try {
      const res = await api<{ backup_codes: string[] }>("/auth/2fa/verify-setup", {
        method: "POST",
        body: { totp_code: code },
      });
      setBackupCodes(res.backup_codes);
      setStep(4);
    } catch {
      setVerifyError("Неверный код. Проверь приложение и попробуй снова.");
      setCode("");
      setTimeout(() => codeInputRef.current?.focus(), 50);
    } finally {
      setVerifying(false);
    }
  }

  function handleFinish() {
    onDone();
    onClose();
    router.push("/profile?tab=security&2fa=enabled");
  }

  function copyManual() {
    if (!qrData) return;
    void navigator.clipboard.writeText(qrData.manual_code).then(() => {
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    });
  }

  const isStep2OrLater = step >= 2;

  const footer = (
    <>
      {step < 4 && (
        <div className="flex gap-2 mr-auto">
          <button type="button" className="btn-ghost" onClick={onClose} disabled={verifying}>
            Отмена
          </button>
          {step >= 2 && (
            <button type="button" className="btn-secondary" onClick={handleBack} disabled={verifying || qrLoading}>
              Назад
            </button>
          )}
        </div>
      )}
      {step === 1 && (
        <button type="button" className="btn-primary" onClick={handleNext}>
          Далее
        </button>
      )}
      {step === 2 && (
        <button
          type="button"
          className="btn-primary"
          onClick={() => setStep(3)}
          disabled={qrLoading || !!qrError}
        >
          Далее
        </button>
      )}
      {step === 3 && (
        <button
          type="button"
          className="btn-primary"
          onClick={handleVerify}
          disabled={verifying || code.length !== 6}
        >
          {verifying ? "Проверяем…" : "Проверить"}
        </button>
      )}
      {step === 4 && (
        <button type="button" className="btn-primary" onClick={handleFinish}>
          Я сохранил коды, продолжить
        </button>
      )}
    </>
  );

  return (
    <Modal
      open={open}
      title="Подключение двухфакторной аутентификации"
      onClose={onClose}
      isDirty={isStep2OrLater}
      width="md"
      footer={footer}
    >
      {/* Прогресс-индикатор */}
      <div className="flex items-center mb-6 text-sm">
        {([1, 2, 3, 4] as SetupStep[]).map((s, i) => (
          <span key={s} className="flex items-center">
            <span className={step === s ? "text-primary font-semibold" : "text-gray-400"}>
              {s}: {STEP_LABELS[s]}
            </span>
            {i < 3 && <span className="text-gray-300 mx-2 text-xs">›</span>}
          </span>
        ))}
      </div>

      {/* Шаг 1 */}
      {step === 1 && (
        <div>
          <h4 className="font-medium text-gray-900 dark:text-gray-100 mb-2">Шаг 1. Установите приложение-аутентификатор</h4>
          <p className="text-sm text-gray-600 dark:text-gray-400 mb-4">
            Для генерации кодов нужно приложение на смартфоне. Выберите любое:
          </p>
          <div className="space-y-2">
            {APPS.map((app) => (
              <div key={app.name} className="flex items-start gap-3 p-3 rounded-lg border border-gray-200 dark:border-gray-700">
                <i className="bi bi-phone text-xl text-gray-400 dark:text-gray-500 mt-0.5" />
                <div>
                  <div className="text-sm font-medium text-gray-900 dark:text-gray-100">{app.name}</div>
                  <div className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                    <a href={app.appStore} target="_blank" rel="noopener noreferrer" className="text-primary underline">
                      App Store
                    </a>
                    {" · "}
                    <a href={app.googlePlay} target="_blank" rel="noopener noreferrer" className="text-primary underline">
                      Google Play
                    </a>
                  </div>
                </div>
              </div>
            ))}
          </div>
          <p className="text-sm text-gray-500 dark:text-gray-400 mt-4">Уже установлено? Жми Далее</p>
        </div>
      )}

      {/* Шаг 2 */}
      {step === 2 && (
        <div>
          <h4 className="font-medium text-gray-900 dark:text-gray-100 mb-2">Шаг 2. Отсканируйте QR-код</h4>
          <p className="text-sm text-gray-600 dark:text-gray-400 mb-4">
            Откройте приложение → нажмите «+» → «Сканировать QR-код» — наведите камеру на код ниже.
          </p>

          {qrLoading && (
            <div className="animate-pulse w-48 h-48 bg-gray-100 dark:bg-gray-800 rounded mx-auto mb-4" />
          )}
          {qrError && (
            <div className="text-danger text-sm mb-3">
              {qrError}
              <button
                type="button"
                className="ml-2 text-primary underline text-sm"
                onClick={() => void loadQr()}
              >
                Попробовать снова
              </button>
            </div>
          )}
          {qrData && !qrLoading && (
            <div className="flex flex-col items-center mb-4">
              <img
                src={`data:image/png;base64,${qrData.qr_base64}`}
                alt="QR-код для 2FA"
                className="w-48 h-48 border border-gray-200 dark:border-gray-600 rounded dark:bg-white"
              />
            </div>
          )}

          {qrData && (
            <div className="mt-3 space-y-2">
              <button
                type="button"
                className="text-sm text-primary underline"
                onClick={() => setShowManual((v) => !v)}
              >
                {showManual ? "Скрыть ручной ввод" : "Не можете отсканировать?"}
              </button>
              {showManual && (
                <div>
                  <p className="text-sm text-gray-600 dark:text-gray-400 mb-1">Введите код вручную:</p>
                  <span className="font-mono text-sm bg-gray-50 dark:bg-gray-800 rounded p-3 select-all tracking-widest block border border-gray-200 dark:border-gray-700 text-gray-900 dark:text-gray-100">
                    {qrData.manual_code}
                  </span>
                  <button
                    type="button"
                    className="btn-ghost text-xs mt-2"
                    onClick={copyManual}
                  >
                    <i className="bi bi-clipboard mr-1" />
                    {copied ? "Скопировано" : "Скопировать"}
                  </button>
                </div>
              )}
            </div>
          )}
        </div>
      )}

      {/* Шаг 3 */}
      {step === 3 && (
        <div>
          <h4 className="font-medium text-gray-900 dark:text-gray-100 mb-2">Шаг 3. Подтвердите настройку</h4>
          <p className="text-sm text-gray-600 dark:text-gray-400 mb-4">
            Введите 6-значный код из приложения, чтобы убедиться что всё настроено верно.
          </p>
          <div>
            <label className="label">Код из приложения <span className="text-danger">*</span></label>
            <input
              ref={codeInputRef}
              type="text"
              inputMode="numeric"
              pattern="[0-9]*"
              maxLength={6}
              placeholder="000000"
              className="input"
              value={code}
              onChange={(e) => {
                const val = e.target.value.replace(/\D/g, "");
                setCode(val);
              }}
              autoFocus
              disabled={verifying}
            />
            {verifyError && (
              <p className="text-danger text-sm mt-1">{verifyError}</p>
            )}
          </div>
        </div>
      )}

      {/* Шаг 4 */}
      {step === 4 && (
        <div>
          <h4 className="font-medium text-gray-900 dark:text-gray-100 mb-4">Шаг 4. Резервные коды восстановления</h4>
          <BackupCodesDisplay codes={backupCodes} />
        </div>
      )}
    </Modal>
  );
}
