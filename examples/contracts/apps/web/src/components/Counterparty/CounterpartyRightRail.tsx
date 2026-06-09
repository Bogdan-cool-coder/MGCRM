"use client";

import { useState } from "react";
import { HealthBadge } from "@/components/HealthBadge";
import { CategoryBadge } from "@/components/CategoryBadge";
import { UserSelect } from "@/components/UserSelect";
import { CounterpartyInlineField } from "./CounterpartyInlineField";
import { api } from "@/lib/api";
import type { Counterparty, Subscription, User, Department } from "@/lib/types";

interface CounterpartyRightRailProps {
  cp: Counterparty;
  subs: Subscription[] | undefined;
  dealsCount: number;
  openTasksCount: number;
  lastActivityAt: string | null;
  users: User[] | undefined;
  departments?: Department[];
  onOwnerChange: (userId: number | null) => void;
  onSaved: () => void;
}

function daysAgo(dateStr: string | null): string {
  if (!dateStr) return "нет активности";
  const diff = Math.floor((Date.now() - new Date(dateStr).getTime()) / 86400000);
  if (diff === 0) return "сегодня";
  if (diff === 1) return "вчера";
  return `${diff} дн. назад`;
}

export function CounterpartyRightRail({
  cp,
  subs,
  dealsCount,
  openTasksCount,
  lastActivityAt,
  users,
  departments,
  onOwnerChange,
  onSaved,
}: CounterpartyRightRailProps) {
  const [accordionOpen, setAccordionOpen] = useState(false);
  const [ownerValue, setOwnerValue] = useState<string>(
    cp.responsible_user_id != null ? String(cp.responsible_user_id) : "",
  );

  const dept = cp.department_id != null && departments
    ? departments.find((d) => d.id === cp.department_id) ?? null
    : null;

  async function saveField(field: string, value: string) {
    await api(`/counterparties/${cp.id}`, {
      method: "PATCH",
      body: { [field]: value || null },
    });
    onSaved();
  }

  async function saveOwner(userId: string) {
    setOwnerValue(userId);
    await api(`/counterparties/${cp.id}`, {
      method: "PATCH",
      body: { responsible_user_id: userId ? Number(userId) : null },
    });
    onOwnerChange(userId ? Number(userId) : null);
    onSaved();
  }

  // Health badge из первой подписки (если есть)
  const firstSub = subs?.[0];

  return (
    <aside className="w-80 shrink-0 border-l border-gray-200 bg-white flex flex-col p-4 gap-4 hidden lg:flex">
      {/* Название */}
      <div>
        <h2 className="text-base font-semibold text-primary leading-tight">
          {cp.legal_form ? `${cp.legal_form} ` : ""}«{cp.name}»
        </h2>
      </div>

      {/* Бейджи */}
      <div className="flex flex-wrap items-center gap-2">
        {firstSub && <HealthBadge tier={firstSub.health_tier} manual={!!firstSub.manual_tier_override} />}
        <CategoryBadge code={cp.category_code} />
        {cp.country_code && (
          <span className="text-xs bg-gray-100 text-gray-700 px-1.5 py-0.5 rounded uppercase">
            {cp.country_code}
          </span>
        )}
      </div>

      {/* Ответственный */}
      <div>
        <label className="label text-xs mb-1">Ответственный</label>
        <UserSelect
          value={ownerValue}
          onChange={(v) => void saveOwner(v)}
          users={users}
          placeholder={cp.responsible_user_id == null ? "Назначить ответственного" : "Не назначен"}
        />
      </div>

      {/* Отдел */}
      <div>
        <label className="label text-xs mb-1">Отдел</label>
        {dept ? (
          <a
            href="/admin/departments"
            className="text-sm text-primary-light hover:underline flex items-center gap-1"
          >
            <i className="bi bi-diagram-3 text-xs" />
            {dept.name}
          </a>
        ) : (
          <span className="text-sm text-gray-400">Не указан</span>
        )}
      </div>

      {/* Quick stats */}
      <div className="bg-gray-50 rounded-lg px-3 py-2 text-xs space-y-1">
        <div className="text-gray-700">
          <b>{dealsCount}</b> сделок · <b>{openTasksCount}</b> задач
        </div>
        <div className="text-gray-500">
          Последняя активность: {daysAgo(lastActivityAt)}
        </div>
      </div>

      {/* Accordion «Реквизиты» */}
      <div>
        <button
          onClick={() => setAccordionOpen((v) => !v)}
          className="flex items-center justify-between w-full text-xs font-semibold text-gray-500 uppercase tracking-wide py-1 hover:text-gray-700 transition-colors"
        >
          Реквизиты
          <i className={`bi ${accordionOpen ? "bi-chevron-up" : "bi-chevron-down"} text-gray-400`} />
        </button>

        {accordionOpen && (
          <div className="mt-2 space-y-0.5">
            <CounterpartyInlineField
              label={cp.tax_id_label ?? "Налоговый номер"}
              value={cp.tax_id ?? null}
              onSave={(v) => saveField("tax_id", v)}
            />
            <CounterpartyInlineField
              label="Подписант"
              value={cp.director_short ?? null}
              onSave={(v) => saveField("director_short", v)}
            />
            <CounterpartyInlineField
              label="Телефон"
              value={cp.phone ?? null}
              onSave={(v) => saveField("phone", v)}
              type="tel"
            />
            <CounterpartyInlineField
              label="Email"
              value={cp.email ?? null}
              onSave={(v) => saveField("email", v)}
              type="email"
            />
            <CounterpartyInlineField
              label="Адрес"
              value={cp.address ?? null}
              onSave={(v) => saveField("address", v)}
            />
            <CounterpartyInlineField
              label="Банк"
              value={cp.bank ?? null}
              onSave={(v) => saveField("bank", v)}
            />
            <CounterpartyInlineField
              label="Счёт"
              value={cp.account ?? null}
              onSave={(v) => saveField("account", v)}
            />
            <div className="pt-2">
              <span className="text-xs text-gray-400">Все реквизиты →</span>
            </div>
          </div>
        )}
      </div>
    </aside>
  );
}
