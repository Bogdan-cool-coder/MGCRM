"use client";

import { useRef, useState } from "react";
import useSWR from "swr";
import { Modal } from "@/components/Modal";
import { fetcher } from "@/lib/api";
import type { PipelineChannel } from "@/lib/types";

interface PresentationSendModalProps {
  dealId: number;
  companyName?: string | null;
  onClose: () => void;
}

const CHANNEL_ICONS: Record<string, string> = {
  telegram: "bi-telegram",
  whatsapp: "bi-whatsapp",
  email: "bi-envelope",
  form: "bi-ui-checks-grid",
};

const CHANNEL_LABELS: Record<string, string> = {
  telegram: "Telegram",
  whatsapp: "WhatsApp",
  email: "Email",
  form: "Форма",
};

export function PresentationSendModal({ dealId: _dealId, companyName, onClose }: PresentationSendModalProps) {
  const { data: channels } = useSWR<PipelineChannel[]>("/channels", fetcher);
  const [selectedFile, setSelectedFile] = useState<File | null>(null);
  const fileRef = useRef<HTMLInputElement>(null);

  const activeChannels = (channels ?? []).filter((c) => c.is_active && c.linked);

  function handleDownload() {
    if (!selectedFile) return;
    const url = URL.createObjectURL(selectedFile);
    const a = document.createElement("a");
    a.href = url;
    a.download = selectedFile.name;
    a.click();
    URL.revokeObjectURL(url);
  }

  return (
    <Modal
      open
      title="Отправить презентацию"
      description={companyName ? `Для компании: ${companyName}` : undefined}
      onClose={onClose}
      width="sm"
      footer={
        <button className="btn-ghost" onClick={onClose}>Закрыть</button>
      }
    >
      <div className="space-y-5">
        {/* File picker */}
        <div>
          <label className="label">Файл презентации</label>
          <div className="flex gap-2 items-center">
            <button
              className="btn-secondary text-sm"
              onClick={() => fileRef.current?.click()}
            >
              <i className="bi bi-paperclip mr-1" />
              {selectedFile ? "Сменить файл" : "Выбрать файл"}
            </button>
            {selectedFile && (
              <span className="text-sm text-gray-700 dark:text-gray-300 truncate flex-1 min-w-0">
                {selectedFile.name}
              </span>
            )}
          </div>
          <input
            ref={fileRef}
            type="file"
            className="hidden"
            accept=".pdf,.pptx,.ppt,.key"
            onChange={(e) => setSelectedFile(e.target.files?.[0] ?? null)}
          />
          <div className="text-xs text-gray-400 mt-1">PDF, PPTX, PPT, KEY</div>
        </div>

        {/* Download */}
        <div className="border border-gray-200 dark:border-gray-700 rounded-lg p-3">
          <div className="flex items-center justify-between gap-3">
            <div className="flex items-center gap-2">
              <i className="bi bi-download text-primary" />
              <div>
                <div className="text-sm font-medium text-gray-800 dark:text-gray-200">
                  Скачать файл
                </div>
                <div className="text-xs text-gray-500 dark:text-gray-400">
                  Сохранить локально для ручной отправки
                </div>
              </div>
            </div>
            <button
              className="btn-primary text-sm shrink-0 disabled:opacity-50"
              disabled={!selectedFile}
              onClick={handleDownload}
            >
              <i className="bi bi-download mr-1" />Скачать
            </button>
          </div>
        </div>

        {/* Channel send options */}
        <div>
          <div className="flex items-center justify-between mb-2">
            <label className="label mb-0">Отправить через канал</label>
            <span className="text-xs text-warning bg-warning/10 px-2 py-0.5 rounded-full">
              Скоро
            </span>
          </div>

          <div className="space-y-2">
            {(["telegram", "whatsapp", "email"] as const).map((kind) => {
              const linkedChannel = activeChannels.find((c) => c.kind === kind);
              const hasChannel = !!linkedChannel;
              return (
                <div
                  key={kind}
                  className={`flex items-center gap-3 rounded-lg border px-3 py-2.5 ${
                    hasChannel
                      ? "border-gray-200 dark:border-gray-700 opacity-60"
                      : "border-gray-100 dark:border-gray-800 opacity-40"
                  }`}
                  title={
                    !hasChannel
                      ? "Канал не настроен для этой воронки"
                      : "Отправка через канал будет доступна в следующем обновлении"
                  }
                >
                  <i className={`bi ${CHANNEL_ICONS[kind] ?? "bi-circle"} text-gray-500 text-base`} />
                  <div className="flex-1">
                    <div className="text-sm text-gray-700 dark:text-gray-300">
                      {CHANNEL_LABELS[kind] ?? kind}
                      {linkedChannel && (
                        <span className="ml-2 text-xs text-gray-400">{linkedChannel.name}</span>
                      )}
                    </div>
                    {!hasChannel && (
                      <div className="text-xs text-gray-400 mt-0.5">
                        Канал не подключён к воронке
                      </div>
                    )}
                  </div>
                  <button
                    className="btn-secondary text-xs disabled:opacity-50 disabled:cursor-not-allowed"
                    disabled
                  >
                    <i className="bi bi-send mr-1" />Отправить
                  </button>
                </div>
              );
            })}
          </div>

          <p className="text-xs text-gray-400 dark:text-gray-500 mt-2.5">
            <i className="bi bi-info-circle mr-1" />
            Прямая отправка через каналы появится после реализации отправочного сервиса.
            Пока используйте «Скачать» и отправьте вручную.
          </p>
        </div>

        {!selectedFile && (
          <div className="p-3 bg-gray-50 dark:bg-gray-700/50 rounded-lg text-xs text-gray-500 dark:text-gray-400">
            <i className="bi bi-lightbulb mr-1" />
            Выберите файл презентации, чтобы скачать его или отправить клиенту.
          </div>
        )}
      </div>
    </Modal>
  );
}
