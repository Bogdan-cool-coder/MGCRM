"use client";

import type { CalldownProvider } from "@/lib/types";

interface Props {
  value: CalldownProvider | null;
  onChange: (provider: CalldownProvider) => void;
}

const PROVIDERS: { id: CalldownProvider; icon: string; label: string; desc: string }[] = [
  { id: "mango", icon: "bi-telephone-fill", label: "Mango Office", desc: "Популярная IP-телефония для бизнеса" },
  { id: "uis", icon: "bi-headset", label: "UIS", desc: "Облачная АТС с аналитикой" },
  { id: "custom", icon: "bi-globe2", label: "Custom Webhook", desc: "Любой провайдер через webhook-адаптер" },
];

export function ProviderStep({ value, onChange }: Props) {
  return (
    <div>
      <h3 className="text-base font-semibold text-gray-900 dark:text-gray-100 mb-4">
        Выбери провайдер телефонии
      </h3>
      <div className="space-y-3">
        {PROVIDERS.map((p) => (
          <label
            key={p.id}
            className={
              "flex items-start gap-4 border-2 rounded-xl p-4 cursor-pointer transition-colors " +
              (value === p.id
                ? "border-primary bg-primary/5 dark:bg-primary/10"
                : "border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600")
            }
          >
            <input
              type="radio"
              name="provider"
              value={p.id}
              checked={value === p.id}
              onChange={() => onChange(p.id)}
              className="mt-1"
            />
            <i className={`bi ${p.icon} text-2xl text-primary mt-0.5`} />
            <div>
              <div className="font-medium text-gray-900 dark:text-gray-100">{p.label}</div>
              <div className="text-sm text-gray-600 dark:text-gray-400">{p.desc}</div>
            </div>
          </label>
        ))}
      </div>
    </div>
  );
}
