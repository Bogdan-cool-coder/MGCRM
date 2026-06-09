"use client";

import { useState } from "react";
import { Modal } from "@/components/Modal";

interface Props {
  open: boolean;
  token: string;
  onClose: () => void;
}

export function TokenRevealModal({ open, token, onClose }: Props) {
  const [copied, setCopied] = useState(false);

  async function copyToken() {
    try {
      await navigator.clipboard.writeText(token);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    } catch {
      // clipboard unavailable
    }
  }

  const curlExample = `curl -H "Authorization: Bearer ${token}" https://contracts.macroglobal.tech/api/leads`;

  return (
    <Modal
      open={open}
      title="Токен создан"
      onClose={onClose}
      isDirty={true}
      width="md"
      footer={
        <button className="btn-primary" onClick={onClose}>
          <i className="bi bi-check-lg" /> Сохранил, закрыть
        </button>
      }
    >
      <div className="flex flex-col items-center text-center gap-4 pb-2">
        <i className="bi bi-shield-check-fill text-success" style={{ fontSize: "3rem" }} />
        <p className="text-sm text-gray-700 font-medium max-w-sm">
          Сохрани токен сейчас — больше он нигде не появится
        </p>
      </div>

      <div className="mt-4 space-y-3">
        <div>
          <div className="text-xs text-gray-500 mb-1">Твой API токен</div>
          <div className="flex items-center gap-2 bg-gray-50 border border-gray-200 rounded-md px-3 py-2">
            <code className="flex-1 font-mono text-xs text-gray-900 break-all select-all">{token}</code>
            <button
              onClick={copyToken}
              className="shrink-0 btn-ghost text-xs"
            >
              <i className={`bi ${copied ? "bi-check-lg text-success" : "bi-clipboard"}`} />
              {" "}{copied ? "Скопировано!" : "Скопировать"}
            </button>
          </div>
        </div>

        <div>
          <div className="text-xs text-gray-500 mb-1">Пример использования (curl)</div>
          <div className="bg-gray-900 rounded-md p-3 overflow-x-auto">
            <code className="font-mono text-xs text-green-400 whitespace-pre">{curlExample}</code>
          </div>
        </div>
      </div>
    </Modal>
  );
}
