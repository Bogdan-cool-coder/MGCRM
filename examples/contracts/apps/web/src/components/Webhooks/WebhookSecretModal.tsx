"use client";

import { useState } from "react";
import { Modal } from "@/components/Modal";

interface Props {
  open: boolean;
  secret: string;
  onClose: () => void;
}

const PYTHON_EXAMPLE = `import hmac, hashlib

SECRET = "whsec_..."
body = request.body()
expected = hmac.new(SECRET.encode(), body, hashlib.sha256).hexdigest()
is_valid = hmac.compare_digest(
    expected,
    request.headers["X-Macro-Signature"].removeprefix("sha256=")
)`;

export function WebhookSecretModal({ open, secret, onClose }: Props) {
  const [copied, setCopied] = useState(false);

  async function copySecret() {
    try {
      await navigator.clipboard.writeText(secret);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    } catch {
      // clipboard unavailable
    }
  }

  return (
    <Modal
      open={open}
      title="Вебхук создан"
      onClose={onClose}
      isDirty={true}
      width="md"
      footer={
        <button className="btn-primary" onClick={onClose}>
          <i className="bi bi-check-lg" /> Сохранил, закрыть
        </button>
      }
    >
      <div className="flex flex-col items-center text-center gap-3 pb-2">
        <i className="bi bi-broadcast-pin text-success" style={{ fontSize: "3rem" }} />
        <p className="text-sm text-gray-700 font-medium max-w-sm">
          Сохрани секрет сейчас — больше он нигде не появится
        </p>
      </div>

      <div className="mt-4 space-y-4">
        <div>
          <div className="text-xs text-gray-500 mb-1">HMAC-секрет для верификации подписи</div>
          <div className="flex items-center gap-2 bg-gray-50 border border-gray-200 rounded-md px-3 py-2">
            <code className="flex-1 font-mono text-xs text-gray-900 break-all select-all">{secret}</code>
            <button onClick={copySecret} className="shrink-0 btn-ghost text-xs">
              <i className={`bi ${copied ? "bi-check-lg text-success" : "bi-clipboard"}`} />
              {" "}{copied ? "Скопировано!" : "Скопировать"}
            </button>
          </div>
        </div>

        <div>
          <div className="text-xs text-gray-500 mb-1">Заголовок подписи в каждом запросе</div>
          <div className="bg-gray-50 border border-gray-200 rounded-md px-3 py-2">
            <code className="font-mono text-xs text-gray-700">X-Macro-Signature: sha256=&lt;hex&gt;</code>
          </div>
        </div>

        <div>
          <div className="text-xs text-gray-500 mb-1">Верификация подписи (Python)</div>
          <div className="bg-gray-900 rounded-md p-3 overflow-x-auto">
            <code className="font-mono text-xs text-green-400 whitespace-pre">{PYTHON_EXAMPLE}</code>
          </div>
        </div>
      </div>
    </Modal>
  );
}
