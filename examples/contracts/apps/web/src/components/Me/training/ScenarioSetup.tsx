"use client";

import { useState } from "react";

export type ScenarioType = "cold_call" | "objection_handling" | "ceo_rejection" | "follow_up";

const SCENARIOS: {
  id: ScenarioType;
  icon: string;
  label: string;
  description: string;
}[] = [
  {
    id: "cold_call",
    icon: "bi-telephone",
    label: "Холодный звонок",
    description: "Клиент тебя не ждёт, нужно установить контакт",
  },
  {
    id: "objection_handling",
    icon: "bi-shield-exclamation",
    label: "Возражение по цене",
    description: "Клиент говорит «дорого» — убеди его",
  },
  {
    id: "ceo_rejection",
    icon: "bi-person-x",
    label: "Отказ ЛПР",
    description: "Директор сказал нет — попробуй переломить ситуацию",
  },
  {
    id: "follow_up",
    icon: "bi-arrow-repeat",
    label: "Повторный звонок",
    description: "Ты уже звонил — напомни о себе",
  },
];

const COMPANY_TYPES = [
  "IT",
  "Производство",
  "Ритейл",
  "Строительство",
  "Образование",
  "Финансы",
  "Другое",
];

interface Props {
  onStart: (scenario: ScenarioType, companyType: string, companyName: string) => void;
  loading?: boolean;
}

export function ScenarioSetup({ onStart, loading }: Props) {
  const [scenario, setScenario] = useState<ScenarioType | null>(null);
  const [companyType, setCompanyType] = useState("IT");
  const [companyName, setCompanyName] = useState("");

  return (
    <div className="card rounded-2xl shadow-elev-1 p-6">
      <h3 className="text-h5 mb-4">Настрой сценарий</h3>

      <div className="mb-5">
        <label className="label mb-2">Сценарий *</label>
        <div className="grid grid-cols-2 gap-3">
          {SCENARIOS.map((s) => (
            <button
              key={s.id}
              type="button"
              onClick={() => setScenario(s.id)}
              className={
                "card text-left p-4 cursor-pointer border-2 transition-colors " +
                (scenario === s.id
                  ? "border-primary bg-primary/5"
                  : "border-gray-200 dark:border-gray-700 hover:border-primary/50")
              }
            >
              <i className={`bi ${s.icon} text-2xl text-primary mb-2`} />
              <div className="text-sm font-medium">{s.label}</div>
              <div className="text-xs text-gray-500 mt-0.5">{s.description}</div>
            </button>
          ))}
        </div>
      </div>

      <div className="mb-4">
        <label className="label">Тип компании *</label>
        <select
          className="input"
          value={companyType}
          onChange={(e) => setCompanyType(e.target.value)}
        >
          {COMPANY_TYPES.map((c) => (
            <option key={c} value={c}>{c}</option>
          ))}
        </select>
      </div>

      <div className="mb-6">
        <label className="label">Название компании (необязательно)</label>
        <input
          type="text"
          className="input"
          placeholder="Например: ACME Corp"
          value={companyName}
          onChange={(e) => setCompanyName(e.target.value)}
        />
      </div>

      <button
        type="button"
        className="btn-primary w-full"
        disabled={!scenario || !companyType || loading}
        onClick={() => scenario && onStart(scenario, companyType, companyName)}
      >
        <i className="bi bi-play-fill mr-1" />
        {loading ? "Запускаем..." : "Начать звонок"}
      </button>
    </div>
  );
}
