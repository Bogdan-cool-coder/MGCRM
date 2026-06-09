"use client";

import { Modal } from "@/components/Modal";
import { GoogleDriveSetup } from "./GoogleDriveSetup";

interface Props {
  open: boolean;
  integrationId: string | null;
  onClose: () => void;
}

const TITLES: Record<string, string> = {
  google_drive: "Google Drive",
  telegram: "Telegram",
  mango: "Mango Office",
  uis: "UIS",
  whisper: "Whisper (OpenAI)",
  yandex_disk: "Яндекс Диск",
  "1c": "1С:Предприятие",
  bitrix24: "Bitrix24",
};

export function IntegrationSetupModal({ open, integrationId, onClose }: Props) {
  const title = integrationId ? (TITLES[integrationId] ?? integrationId) : "";

  return (
    <Modal
      open={open}
      title={`Настройка интеграции: ${title}`}
      onClose={onClose}
      width="md"
    >
      {integrationId === "google_drive" && <GoogleDriveSetup />}
      {integrationId === "telegram" && (
        <div className="text-sm text-gray-700 dark:text-gray-300 space-y-3">
          <p>Telegram-бот настроен через переменные окружения на сервере.</p>
          <p>
            Бот:{" "}
            <code className="bg-gray-100 dark:bg-gray-700 px-1 rounded text-xs font-mono">
              @Contract_generator_MACRO_bot
            </code>
          </p>
          <p className="text-xs text-gray-500 dark:text-gray-400">
            Для изменения настроек обратитесь к администратору сервера.
          </p>
        </div>
      )}
      {(integrationId === "mango" || integrationId === "uis") && (
        <div className="text-sm text-gray-700 dark:text-gray-300 space-y-3">
          <p>Настройка телефонии выполняется через Calldown Wizard.</p>
          <a href="/admin/integrations/calldown" className="btn-primary inline-flex">
            <i className="bi bi-arrow-right-circle mr-1" />
            Открыть мастер настройки
          </a>
        </div>
      )}
      {integrationId === "whisper" && (
        <div className="text-sm text-gray-700 dark:text-gray-300 space-y-3">
          <p>
            Whisper (OpenAI) настраивается на шаге 4 Calldown Wizard — укажите OpenAI API Key
            и параметры расшифровки.
          </p>
          <a href="/admin/integrations/calldown" className="btn-primary inline-flex">
            <i className="bi bi-arrow-right-circle mr-1" />
            Открыть мастер настройки
          </a>
        </div>
      )}
      {integrationId === "1c" && (
        <div className="text-sm text-gray-700 dark:text-gray-300 space-y-3">
          <p>Интеграция с 1С:Предприятие выполняется через Public API MACRO CRM.</p>
          <a
            href="/developers"
            target="_blank"
            rel="noopener noreferrer"
            className="btn-secondary inline-flex"
          >
            <i className="bi bi-box-arrow-up-right mr-1" />
            Документация API
          </a>
        </div>
      )}
      {(integrationId === "yandex_disk" || integrationId === "bitrix24") && (
        <div className="text-sm text-gray-700 dark:text-gray-300">
          <p>Эта интеграция появится в следующих версиях MACRO CRM.</p>
        </div>
      )}
    </Modal>
  );
}
