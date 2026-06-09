"use client";

import { useEffect, useRef, useState } from "react";
import { Modal } from "@/components/Modal";
import { BackupCodesDisplay } from "./BackupCodesDisplay";
import { api, ApiError } from "@/lib/api";
import { useMe } from "@/lib/auth";

interface Props {
  open: boolean;
  mode: "disable" | "new-backup-codes";
  onClose: () => void;
  onSuccess: (backupCodes?: string[]) => void;
}

export function TwoFactorConfirmModal({ open, mode, onClose, onSuccess }: Props) {
  const { mutate } = useMe();
  const [code, setCode] = useState("");
  const [useBackup, setUseBackup] = useState(false);
  const [backupCode, setBackupCode] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [newCodes, setNewCodes] = useState<string[] | null>(null);
  const inputRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    if (open) {
      setCode("");
      setUseBackup(false);
      setBackupCode("");
      setError(null);
      setNewCodes(null);
      setTimeout(() => inputRef.current?.focus(), 50);
    }
  }, [open]);

  async function handleSubmit() {
    setError(null);
    const body: Record<string, string> = useBackup
      ? { backup_code: backupCode.toLowerCase() }
      : { totp_code: code };

    setSubmitting(true);
    try {
      if (mode === "disable") {
        await api("/auth/2fa/disable", { method: "POST", body });
        await mutate();
        onSuccess();
        onClose();
      } else {
        const res = await api<{ backup_codes: string[] }>("/auth/2fa/regenerate-backup-codes", {
          method: "POST",
          body,
        });
        setNewCodes(res.backup_codes);
        onSuccess(res.backup_codes);
      }
    } catch (err) {
      setError(err instanceof ApiError ? "Неверный код. Попробуй ещё раз." : "Ошибка. Попробуй ещё раз.");
      setCode("");
      setBackupCode("");
    } finally {
      setSubmitting(false);
    }
  }

  const title = mode === "disable" ? "Отключить 2FA" : "Сгенерировать резервные коды";
  const description = mode === "disable"
    ? "Для подтверждения введи текущий код из приложения-аутентификатора."
    : "Введи код из приложения. Текущие резервные коды станут недействительными.";

  if (newCodes) {
    return (
      <Modal
        open={open}
        title="Новые резервные коды"
        onClose={onClose}
        width="sm"
        footer={
          <button type="button" className="btn-primary" onClick={onClose}>
            Готово
          </button>
        }
      >
        <BackupCodesDisplay codes={newCodes} />
      </Modal>
    );
  }

  return (
    <Modal
      open={open}
      title={title}
      onClose={onClose}
      width="sm"
      footer={
        <>
          <button type="button" className="btn-ghost" onClick={onClose} disabled={submitting}>
            Отмена
          </button>
          {mode === "disable" ? (
            <button
              type="button"
              className="btn-secondary text-danger"
              onClick={handleSubmit}
              disabled={submitting || (!useBackup && code.length !== 6) || (useBackup && backupCode.length !== 8)}
            >
              {submitting ? "Выполняем…" : "Отключить"}
            </button>
          ) : (
            <button
              type="button"
              className="btn-primary"
              onClick={handleSubmit}
              disabled={submitting || (!useBackup && code.length !== 6) || (useBackup && backupCode.length !== 8)}
            >
              {submitting ? "Выполняем…" : "Подтвердить"}
            </button>
          )}
        </>
      }
    >
      <div className="space-y-4">
        <p className="text-sm text-gray-600">{description}</p>

        {!useBackup ? (
          <div>
            <label className="label">Код из приложения <span className="text-danger">*</span></label>
            <input
              ref={inputRef}
              type="text"
              inputMode="numeric"
              maxLength={6}
              placeholder="000000"
              className="input"
              value={code}
              onChange={(e) => setCode(e.target.value.replace(/\D/g, ""))}
              disabled={submitting}
              autoFocus
            />
          </div>
        ) : (
          <div>
            <label className="label">Резервный код <span className="text-danger">*</span></label>
            <input
              ref={inputRef}
              type="text"
              inputMode="text"
              maxLength={8}
              placeholder="a1b2c3d4"
              pattern="[a-f0-9]{8}"
              className="input font-mono tracking-widest"
              value={backupCode}
              onChange={(e) => setBackupCode(e.target.value.toLowerCase())}
              disabled={submitting}
              autoFocus
            />
          </div>
        )}

        {error && <p className="text-danger text-sm">{error}</p>}

        <p className="text-sm text-gray-500">
          Нет телефона?{" "}
          <button
            type="button"
            className="text-primary underline"
            onClick={() => {
              setUseBackup((v) => !v);
              setCode("");
              setBackupCode("");
              setError(null);
              setTimeout(() => inputRef.current?.focus(), 50);
            }}
          >
            {useBackup ? "Использовать код из приложения" : "Используй резервный код"}
          </button>
        </p>
      </div>
    </Modal>
  );
}
