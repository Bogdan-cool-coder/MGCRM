"use client";

import { useState } from "react";
import { Modal } from "@/components/Modal";
import { api, ApiError } from "@/lib/api";
import { AttachToDealModal } from "./AttachToDealModal";
import type { CalldownCall } from "@/lib/types";
import { formatDateTime } from "@/lib/dates";

interface Props {
  open: boolean;
  call: CalldownCall | null;
  onClose: () => void;
  onChanged: () => void;
}

function formatDuration(sec: number | null): string {
  if (sec === null) return "—";
  const m = Math.floor(sec / 60);
  const s = sec % 60;
  return `${m}:${s.toString().padStart(2, "0")}`;
}

export function CallDetailModal({ open, call, onClose, onChanged }: Props) {
  const [attachOpen, setAttachOpen] = useState(false);
  const [transcribing, setTranscribing] = useState(false);
  const [transcribeError, setTranscribeError] = useState<string | null>(null);
  const [transcribeDone, setTranscribeDone] = useState(false);
  const [copied, setCopied] = useState(false);

  async function handleRetranscribe() {
    if (!call) return;
    setTranscribing(true);
    setTranscribeError(null);
    try {
      await api(`/integrations/calldown/calls/${call.id}/transcribe`, { method: "POST" });
      setTranscribeDone(true);
      onChanged();
    } catch (err) {
      setTranscribeError(
        err instanceof ApiError
          ? String((err.detail as { detail?: string })?.detail ?? err.message)
          : "Не удалось запросить расшифровку"
      );
    } finally {
      setTranscribing(false);
    }
  }

  function handleCopyTranscript() {
    if (call?.transcript) {
      void navigator.clipboard.writeText(call.transcript);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    }
  }

  if (!call) return null;

  const directionLabel = call.direction === "in" ? "Входящий" : "Исходящий";
  const directionIcon = call.direction === "in" ? "bi-telephone-inbound" : "bi-telephone-outbound";
  const directionCls = call.direction === "in" ? "text-info" : "text-warning";

  const transcriptStatusEl = (() => {
    if (!call.transcript_status) return null;
    if (call.transcript_status === "done")
      return (
        <span className="text-xs bg-success/10 text-success rounded px-2 py-0.5">
          <i className="bi bi-check-circle mr-1" />Готова
        </span>
      );
    if (call.transcript_status === "pending")
      return (
        <span className="text-xs text-gray-500 dark:text-gray-400">
          <i className="bi bi-hourglass-split mr-1" />Расшифровка…
        </span>
      );
    if (call.transcript_status === "failed")
      return (
        <span className="text-xs text-danger">
          <i className="bi bi-x-circle mr-1" />Ошибка расшифровки
        </span>
      );
    return null;
  })();

  return (
    <>
      <Modal
        open={open}
        title={`Звонок ${formatDateTime(call.created_at)}`}
        onClose={onClose}
        width="md"
      >
        {/* Metadata */}
        <div className="space-y-4">
          <div className="flex flex-wrap gap-x-6 gap-y-2 text-sm text-gray-700 dark:text-gray-300">
            <div>
              <span className="text-gray-500 dark:text-gray-400 mr-1">Номер:</span>
              <code className="font-mono">{call.phone ?? "—"}</code>
            </div>
            <div className="flex items-center gap-1">
              <span className="text-gray-500 dark:text-gray-400 mr-1">Направление:</span>
              <i className={`bi ${directionIcon} ${directionCls}`} />
              <span>{directionLabel}</span>
            </div>
            <div>
              <span className="text-gray-500 dark:text-gray-400 mr-1">Длительность:</span>
              {formatDuration(call.duration_sec)}
            </div>
            {call.owner_name && (
              <div>
                <span className="text-gray-500 dark:text-gray-400 mr-1">Менеджер:</span>
                {call.owner_name}
              </div>
            )}
            <div>
              <span className="text-gray-500 dark:text-gray-400 mr-1">Сделка:</span>
              {call.deal_id ? (
                <a href={`/deals`} className="text-primary hover:underline">#{call.deal_id}</a>
              ) : (
                "не привязана"
              )}
            </div>
          </div>

          <div>
            <button className="btn-secondary text-sm" onClick={() => setAttachOpen(true)}>
              <i className="bi bi-link-45deg mr-1" />
              Прикрепить к сделке
            </button>
          </div>

          {/* Audio */}
          <div className="border-t border-gray-200 dark:border-gray-700 pt-4">
            <h4 className="text-sm font-medium text-gray-900 dark:text-gray-100 mb-3">
              Запись разговора
            </h4>
            {call.recording_url ? (
              <div className="space-y-2">
                <audio
                  controls
                  className="w-full"
                  src={call.recording_url}
                >
                  Ваш браузер не поддерживает аудиоплеер.
                </audio>
                <a
                  href={call.recording_url}
                  download
                  className="btn-ghost text-sm inline-flex"
                >
                  <i className="bi bi-download mr-1" />
                  Скачать запись
                </a>
              </div>
            ) : (
              <p className="text-sm text-gray-500 dark:text-gray-400">Запись недоступна</p>
            )}
          </div>

          {/* Transcript */}
          <div className="border-t border-gray-200 dark:border-gray-700 pt-4">
            <div className="flex items-center justify-between mb-3">
              <h4 className="text-sm font-medium text-gray-900 dark:text-gray-100">Транскрипция</h4>
              {transcriptStatusEl}
            </div>
            {call.transcript && (
              <div className="relative">
                <pre className="text-sm bg-gray-50 dark:bg-gray-700 rounded p-3 max-h-48 overflow-y-auto whitespace-pre-wrap text-gray-700 dark:text-gray-300 font-sans">
                  {call.transcript}
                </pre>
                <button
                  className="btn-ghost text-xs mt-2"
                  onClick={handleCopyTranscript}
                >
                  <i className={`bi ${copied ? "bi-check-lg" : "bi-clipboard"} mr-1`} />
                  {copied ? "Скопировано" : "Копировать транскрипт"}
                </button>
              </div>
            )}
            {!call.transcript && call.transcript_status !== "failed" && (
              <p className="text-sm text-gray-500 dark:text-gray-400">Транскрипция недоступна</p>
            )}

            {/* Retry button */}
            {call.transcript_status === "failed" && !transcribeDone && (
              <div className="mt-3">
                <button
                  className="btn-secondary text-sm"
                  onClick={handleRetranscribe}
                  disabled={transcribing}
                >
                  <i className="bi bi-arrow-repeat mr-1" />
                  {transcribing ? "Запрашиваем…" : "Запросить расшифровку повторно"}
                </button>
                {transcribeError && (
                  <p className="text-danger text-xs mt-1">{transcribeError}</p>
                )}
              </div>
            )}
            {transcribeDone && (
              <p className="text-success text-xs mt-2">
                <i className="bi bi-check-circle mr-1" />
                Расшифровка запрошена — обновится в течение нескольких минут
              </p>
            )}
          </div>

          {/* CRM Link */}
          {call.activity_id && (
            <div className="border-t border-gray-200 dark:border-gray-700 pt-4">
              <h4 className="text-sm font-medium text-gray-900 dark:text-gray-100 mb-2">
                Связанная активность в CRM
              </h4>
              <p className="text-sm text-gray-600 dark:text-gray-400 mb-2">
                Activity #{call.activity_id} · kind=call · создана авто
              </p>
              {call.deal_id && (
                <a
                  href={`/deals`}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="btn-ghost text-sm"
                >
                  <i className="bi bi-box-arrow-up-right mr-1" />
                  Открыть в CRM
                </a>
              )}
            </div>
          )}
        </div>
      </Modal>

      <AttachToDealModal
        open={attachOpen}
        callId={call.id}
        activityId={call.activity_id}
        onClose={() => setAttachOpen(false)}
        onAttached={() => { setAttachOpen(false); onChanged(); }}
      />
    </>
  );
}
