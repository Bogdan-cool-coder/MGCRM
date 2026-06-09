"use client";

import type { MarketplaceStatus } from "@/lib/types";

export interface IntegrationConfig {
  id: string;
  label: string;
  icon: string;
  description: string;
  category: "telephony" | "storage" | "messenger" | "erp";
  staticStatus?: MarketplaceStatus;
}

export const INTEGRATIONS_CONFIG: IntegrationConfig[] = [
  {
    id: "google_drive",
    label: "Google Drive",
    icon: "bi-google",
    description: "Автоматически выгружай подписанные договоры в папки Google Drive",
    category: "storage",
  },
  {
    id: "telegram",
    label: "Telegram",
    icon: "bi-telegram",
    description: "Уведомления о согласованиях и заданиях через бота",
    category: "messenger",
    staticStatus: "connected",
  },
  {
    id: "mango",
    label: "Mango Office",
    icon: "bi-telephone-fill",
    description: "Запись звонков и автоматическая расшифровка через Whisper",
    category: "telephony",
  },
  {
    id: "uis",
    label: "UIS",
    icon: "bi-headset",
    description: "Запись звонков и автоматическая расшифровка через Whisper",
    category: "telephony",
  },
  {
    id: "whisper",
    label: "Whisper (OpenAI)",
    icon: "bi-mic-fill",
    description: "Автоматическая расшифровка записей разговоров с помощью OpenAI Whisper",
    category: "telephony",
  },
  {
    id: "yandex_disk",
    label: "Яндекс Диск",
    icon: "bi-cloud-fill",
    description: "Хранение записей звонков",
    category: "storage",
    staticStatus: "coming_soon",
  },
  {
    id: "1c",
    label: "1С:Предприятие",
    icon: "bi-box",
    description: "Интеграция через Public API MACRO CRM",
    category: "erp",
    staticStatus: "docs",
  },
  {
    id: "bitrix24",
    label: "Bitrix24",
    icon: "bi-diagram-2",
    description: "Синхронизация контактов и компаний",
    category: "erp",
    staticStatus: "coming_soon",
  },
];

const STATUS_BADGE: Record<MarketplaceStatus, { cls: string; label: string }> = {
  connected: { cls: "bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400", label: "Подключено" },
  available: { cls: "bg-info-50 text-info-700 dark:bg-info-500/10 dark:text-info-400", label: "Доступно" },
  coming_soon: { cls: "bg-gray-100 dark:bg-gray-800 text-gray-500 dark:text-gray-400", label: "Скоро" },
  docs: { cls: "bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300", label: "Через API" },
};

interface Props {
  config: IntegrationConfig;
  apiStatus?: MarketplaceStatus;
  onClick: () => void;
}

export function IntegrationCard({ config, apiStatus, onClick }: Props) {
  const status: MarketplaceStatus = config.staticStatus ?? apiStatus ?? "available";
  const badge = STATUS_BADGE[status];
  const isDisabled = status === "coming_soon";

  return (
    <div
      className={[
        "rounded-2xl shadow-elev-1 bg-white dark:bg-gray-900 border border-gray-100 dark:border-gray-800",
        "p-5 flex flex-col gap-3",
        !isDisabled ? "lift cursor-pointer" : "opacity-60",
      ].join(" ")}
      onClick={!isDisabled ? onClick : undefined}
      role={!isDisabled ? "button" : undefined}
      tabIndex={!isDisabled ? 0 : undefined}
      onKeyDown={
        !isDisabled
          ? (e) => {
              if (e.key === "Enter" || e.key === " ") {
                e.preventDefault();
                onClick();
              }
            }
          : undefined
      }
    >
      <div className="flex items-start justify-between">
        <div className="w-10 h-10 rounded-xl bg-primary/5 dark:bg-primary/10 flex items-center justify-center">
          <i className={`bi ${config.icon} text-xl text-primary`} />
        </div>
        <span className={`badge text-[11px] font-medium px-2 py-0.5 rounded-full ${badge.cls}`}>
          {badge.label}
        </span>
      </div>
      <div className="flex-1">
        <div className="font-semibold text-gray-900 dark:text-gray-100 mb-1 text-sm">{config.label}</div>
        <div className="text-xs text-gray-500 dark:text-gray-400 leading-snug">{config.description}</div>
      </div>
      <div className="mt-auto pt-1">
        {status === "connected" && (
          <button className="btn-secondary text-xs w-full" onClick={(e) => { e.stopPropagation(); onClick(); }}>
            <i className="bi bi-gear mr-1" />
            Управлять
          </button>
        )}
        {status === "available" && (
          <button className="btn-primary text-xs w-full" onClick={(e) => { e.stopPropagation(); onClick(); }}>
            <i className="bi bi-plug mr-1" />
            Подключить
          </button>
        )}
        {status === "coming_soon" && (
          <button className="btn-secondary text-xs w-full" disabled>
            <i className="bi bi-clock mr-1" />
            Скоро
          </button>
        )}
        {status === "docs" && (
          <button className="btn-ghost text-xs w-full" onClick={(e) => { e.stopPropagation(); onClick(); }}>
            <i className="bi bi-box-arrow-up-right mr-1" />
            Документация
          </button>
        )}
      </div>
    </div>
  );
}
