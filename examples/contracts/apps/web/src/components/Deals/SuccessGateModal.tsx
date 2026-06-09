"use client";

import { useState } from "react";
import useSWR from "swr";
import { Modal } from "@/components/Modal";
import { DatePicker } from "@/components/ui/DatePicker";
import { api, ApiError, fetcher } from "@/lib/api";
import type { WinGateFailedError, PipelineStage } from "@/lib/types";

interface SuccessGateModalProps {
  dealId: number;
  targetStageId: number;
  gateInfo: WinGateFailedError;
  /** Сумма сделки — префилл платежа (можно переопределить вручную). */
  dealAmount?: number | null;
  /** Валюта сделки — префилл платежа. */
  dealCurrency?: string | null;
  substages: PipelineStage[];
  onClose: () => void;
  onSuccess: () => void;
}

export function SuccessGateModal({
  dealId,
  targetStageId,
  gateInfo,
  dealAmount,
  dealCurrency,
  substages,
  onClose,
  onSuccess,
}: SuccessGateModalProps) {
  const [selectedSubstageId, setSelectedSubstageId] = useState<number | null>(
    substages.length === 1 ? substages[0].id : null
  );
  const [moving, setMoving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [uploadFile, setUploadFile] = useState<File | null>(null);
  const [uploading, setUploading] = useState(false);
  const [uploadedScan, setUploadedScan] = useState(gateInfo.has_signed_scan);
  const [markedPaid, setMarkedPaid] = useState(gateInfo.has_payment);
  const [markingPaid, setMarkingPaid] = useState(false);
  const [payAmount, setPayAmount] = useState<string>(
    dealAmount != null ? String(dealAmount) : ""
  );
  const [payCurrency, setPayCurrency] = useState<string>(dealCurrency ?? "KZT");
  const [payDate, setPayDate] = useState<string>(
    () => new Date().toISOString().split("T")[0]
  );

  // Обновляем состояние из бэкенда если контракт известен
  const { mutate: revalidate } = useSWR(
    gateInfo.contract_id ? `/contracts/${gateInfo.contract_id}/attachments` : null,
    fetcher,
    { revalidateOnFocus: false }
  );

  const canProceed = uploadedScan || markedPaid;

  async function handleUploadScan() {
    if (!uploadFile || !gateInfo.contract_id) return;
    setUploading(true);
    setError(null);
    try {
      const formData = new FormData();
      formData.append("file", uploadFile);
      formData.append("kind", "signed_scan");
      // Upload как form-data через сырой fetch (api helper ожидает JSON)
      const resp = await fetch(`/api/contracts/${gateInfo.contract_id}/attachments`, {
        method: "POST",
        credentials: "same-origin",
        body: formData,
      });
      if (!resp.ok) {
        const txt = await resp.text().catch(() => "");
        throw new Error(txt || `HTTP ${resp.status}`);
      }
      setUploadedScan(true);
      setUploadFile(null);
      void revalidate();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Ошибка загрузки");
    } finally {
      setUploading(false);
    }
  }

  async function handleMarkPaid() {
    // Гейт оплаты выполняется регистрацией платежа по договору (ContractPayment).
    // Без contract_id зафиксировать оплату нельзя — гейт проходится сканом.
    if (!gateInfo.contract_id) return;
    const amountNum = Number(payAmount);
    if (!payAmount || Number.isNaN(amountNum) || amountNum <= 0) {
      setError("Укажите сумму платежа больше нуля");
      return;
    }
    setMarkingPaid(true);
    setError(null);
    try {
      await api(`/contracts/${gateInfo.contract_id}/payments`, {
        method: "POST",
        body: {
          amount: amountNum,
          currency: payCurrency,
          payment_date: payDate,
        },
      });
      setMarkedPaid(true);
      void revalidate();
    } catch (err) {
      setError(
        err instanceof ApiError
          ? String((err.detail as { detail?: string })?.detail ?? err.message)
          : "Ошибка"
      );
    } finally {
      setMarkingPaid(false);
    }
  }

  async function handleMove() {
    setMoving(true);
    setError(null);
    try {
      await api(`/deals/${dealId}/move`, {
        method: "POST",
        body: {
          stage_id: targetStageId,
          substage_id: selectedSubstageId ?? undefined,
        },
      });
      onSuccess();
    } catch (err) {
      if (err instanceof ApiError && err.status === 409) {
        setError("Требования всё ещё не выполнены. Обновите данные и повторите.");
      } else {
        setError(
          err instanceof ApiError
            ? String((err.detail as { detail?: string })?.detail ?? err.message)
            : "Не удалось перевести сделку"
        );
      }
    } finally {
      setMoving(false);
    }
  }

  return (
    <Modal
      open
      title="Гейт успеха"
      description="Перед переводом в успешный этап необходимо выполнить условия"
      onClose={onClose}
      width="sm"
      footer={
        <>
          <button className="btn-ghost" onClick={onClose}>Отмена</button>
          <button
            className="btn-primary disabled:opacity-50"
            disabled={!canProceed || moving}
            onClick={handleMove}
          >
            {moving ? "Перевод…" : "Перевести в успех"}
          </button>
        </>
      }
    >
      <div className="space-y-5">
        {error && (
          <div className="text-sm text-danger bg-danger/10 px-3 py-2 rounded flex items-start gap-2">
            <i className="bi bi-exclamation-triangle shrink-0 mt-0.5" />
            {error}
          </div>
        )}

        {/* Condition 1: Signed scan */}
        <div className={`rounded-lg border p-4 ${uploadedScan ? "border-success/50 bg-success/5" : "border-gray-200 dark:border-gray-700"}`}>
          <div className="flex items-start gap-3">
            <div className={`mt-0.5 text-lg ${uploadedScan ? "text-success" : "text-gray-400"}`}>
              <i className={`bi ${uploadedScan ? "bi-check-circle-fill" : "bi-file-earmark-check"}`} />
            </div>
            <div className="flex-1 min-w-0">
              <div className="font-medium text-sm text-gray-800 dark:text-gray-200">
                Скан подписанного договора
              </div>
              <div className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                {uploadedScan
                  ? "Загружен"
                  : "Прикрепите подписанный экземпляр договора"}
              </div>

              {!uploadedScan && (
                <div className="mt-3 space-y-2">
                  {gateInfo.contract_id ? (
                    <>
                      <label className="flex flex-col gap-1">
                        <span className="text-xs text-gray-600 dark:text-gray-400">
                          Выберите файл скана:
                        </span>
                        <input
                          type="file"
                          accept=".pdf,.jpg,.jpeg,.png"
                          className="text-xs text-gray-700 dark:text-gray-300"
                          onChange={(e) => setUploadFile(e.target.files?.[0] ?? null)}
                        />
                      </label>
                      {uploadFile && (
                        <button
                          className="btn-secondary text-xs"
                          disabled={uploading}
                          onClick={handleUploadScan}
                        >
                          {uploading ? "Загрузка…" : <><i className="bi bi-upload mr-1" />Загрузить скан</>}
                        </button>
                      )}
                    </>
                  ) : (
                    <p className="text-xs text-gray-500 dark:text-gray-400">
                      Нет связанного договора.{" "}
                      <a
                        href={`/contracts/new?company_id=`}
                        className="text-primary hover:underline"
                      >
                        Создать договор →
                      </a>
                    </p>
                  )}
                  {gateInfo.contract_id && (
                    <a
                      href={`/contracts/${gateInfo.contract_id}`}
                      className="text-xs text-primary hover:underline block"
                      target="_blank"
                      rel="noopener noreferrer"
                    >
                      <i className="bi bi-box-arrow-up-right mr-1" />
                      Открыть карточку договора
                    </a>
                  )}
                </div>
              )}
            </div>
          </div>
        </div>

        {/* Condition 2: Payment */}
        <div className={`rounded-lg border p-4 ${markedPaid ? "border-success/50 bg-success/5" : "border-gray-200 dark:border-gray-700"}`}>
          <div className="flex items-start gap-3">
            <div className={`mt-0.5 text-lg ${markedPaid ? "text-success" : "text-gray-400"}`}>
              <i className={`bi ${markedPaid ? "bi-check-circle-fill" : "bi-credit-card"}`} />
            </div>
            <div className="flex-1">
              <div className="font-medium text-sm text-gray-800 dark:text-gray-200">
                Оплата
              </div>
              <div className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                {markedPaid ? "Отмечена" : "Зафиксируйте факт получения оплаты"}
              </div>
              {!markedPaid && (
                gateInfo.contract_id ? (
                  <div className="mt-3 space-y-2">
                    <div className="flex gap-2">
                      <input
                        type="number"
                        min="0"
                        step="0.01"
                        className="input text-xs flex-1"
                        placeholder="Сумма"
                        value={payAmount}
                        onChange={(e) => setPayAmount(e.target.value)}
                      />
                      <input
                        type="text"
                        className="input text-xs w-20"
                        placeholder="KZT"
                        value={payCurrency}
                        onChange={(e) => setPayCurrency(e.target.value.toUpperCase())}
                      />
                    </div>
                    <DatePicker
                      value={payDate}
                      onChange={(v) => setPayDate(v ?? "")}
                    />
                    <button
                      className="btn-secondary text-xs"
                      disabled={markingPaid}
                      onClick={handleMarkPaid}
                    >
                      {markingPaid
                        ? "Сохранение…"
                        : <><i className="bi bi-check-lg mr-1" />Зафиксировать оплату</>}
                    </button>
                  </div>
                ) : (
                  <p className="text-xs text-gray-500 dark:text-gray-400 mt-2">
                    Нет связанного договора — зафиксировать оплату нельзя.
                    Используйте загрузку скана.
                  </p>
                )
              )}
            </div>
          </div>
        </div>

        {/* OR separator */}
        <div className="flex items-center gap-2 -my-1">
          <div className="flex-1 h-px bg-gray-200 dark:bg-gray-700" />
          <span className="text-xs text-gray-400">достаточно одного из двух</span>
          <div className="flex-1 h-px bg-gray-200 dark:bg-gray-700" />
        </div>

        {/* Substage selector */}
        {substages.length > 0 && (
          <div>
            <label className="label">Подстатус (необязательно)</label>
            <select
              className="input"
              value={selectedSubstageId ?? ""}
              onChange={(e) => setSelectedSubstageId(e.target.value ? Number(e.target.value) : null)}
            >
              <option value="">— без подстатуса —</option>
              {substages.map((s) => (
                <option key={s.id} value={s.id}>{s.name}</option>
              ))}
            </select>
          </div>
        )}

        {!canProceed && (
          <div className="text-xs text-gray-500 dark:text-gray-400 text-center">
            Выполните хотя бы одно условие выше, чтобы перевести сделку
          </div>
        )}
      </div>
    </Modal>
  );
}
