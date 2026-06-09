"use client";

import { useState } from "react";
import { useParams, useRouter } from "next/navigation";
import useSWR from "swr";
import { fetcher, api } from "@/lib/api";
import { PageHeader } from "@/components/PageHeader";
import { RoleGate } from "@/components/RoleGate";
import { UserSelect } from "@/components/UserSelect";
import { AmountWithConversion } from "@/components/Currency/AmountWithConversion";
import { Modal } from "@/components/Modal";
import { useToast } from "@/components/ui/Toast";
import { CommissionRuleForm } from "@/components/SalaryPlans/CommissionRuleForm";
import { TeamTargetForm } from "@/components/SalaryPlans/TeamTargetForm";
import { formatCurrency } from "@/lib/format";
import type { SalaryPlan, CommissionRule, TeamTarget } from "@/lib/types";

const MONTHS_RU = [
  "Январь", "Февраль", "Март", "Апрель", "Май", "Июнь",
  "Июль", "Август", "Сентябрь", "Октябрь", "Ноябрь", "Декабрь",
];

const STATUS_BADGE: Record<string, string> = {
  draft:     "bg-info-50    text-info-700    dark:bg-info-500/10    dark:text-info-400",
  finalized: "bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400",
  paid:      "bg-primary/10 text-primary     dark:bg-primary/15     dark:text-blue-300",
};
const STATUS_LABEL: Record<string, string> = {
  draft:     "Черновик",
  finalized: "Финализирован",
  paid:      "Выплачен",
};

// Инфо-панель правила/цели
function InfoPanel({ children }: { children: React.ReactNode }) {
  return (
    <div className="mt-3 rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50 p-3 text-sm">
      {children}
    </div>
  );
}

export default function SalaryPlanDetailPage() {
  const params = useParams();
  const router = useRouter();
  const userId = params.userId as string;
  const year = params.year as string;
  const month = params.month as string;

  const swrKey = `/admin/salary-plans/${userId}/${year}/${month}`;
  const { data: plan, isLoading } = useSWR<SalaryPlan>(swrKey, fetcher);
  const { data: commissionRules, mutate: mutateRules } = useSWR<CommissionRule[]>("/admin/commission-rules?active=true", fetcher);
  const { data: teamTargets, mutate: mutateTargets } = useSWR<TeamTarget[]>("/admin/team-targets?active=true", fetcher);
  const { toast } = useToast();

  const [baseSalary, setBaseSalary] = useState<number | "">(plan?.base_salary_amount ?? "");
  const [baseCurrency, setBaseCurrency] = useState(plan?.base_salary_currency ?? "UZS");
  const [baseSalaryNote, setBaseSalaryNote] = useState(plan?.base_salary_note ?? "");
  const [commissionRuleId, setCommissionRuleId] = useState(String(plan?.commission_rule_id ?? ""));
  const [teamTargetId, setTeamTargetId] = useState(String(plan?.team_target_id ?? ""));
  const [personalPlan, setPersonalPlan] = useState<number | "">(plan?.personal_income_plan ?? "");
  const [personalCurrency, setPersonalCurrency] = useState(plan?.personal_income_currency ?? "RUB");
  const [ftmPlan, setFtmPlan] = useState(String(plan?.ftm_plan ?? "5"));
  const [supervisorId, setSupervisorId] = useState(String(plan?.supervisor_id ?? ""));
  const [status, setStatus] = useState<"draft" | "finalized" | "paid">(plan?.status ?? "draft");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [computing, setComputing] = useState(false);
  const [ruleModalOpen, setRuleModalOpen] = useState(false);
  const [targetModalOpen, setTargetModalOpen] = useState(false);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setSubmitting(true);
    setError(null);
    try {
      await api(swrKey, {
        method: "PUT",
        body: {
          base_salary_amount: Number(baseSalary),
          base_salary_currency: baseCurrency,
          base_salary_note: baseSalaryNote || null,
          commission_rule_id: commissionRuleId ? Number(commissionRuleId) : null,
          team_target_id: teamTargetId ? Number(teamTargetId) : null,
          personal_income_plan: Number(personalPlan),
          personal_income_currency: personalCurrency,
          ftm_plan: Number(ftmPlan),
          supervisor_id: supervisorId ? Number(supervisorId) : null,
          status,
        },
      });
      toast.success("План сохранён");
      router.push("/admin/salary-plans");
    } catch {
      const msg = "Не удалось сохранить план. Проверьте данные.";
      setError(msg);
      toast.error(msg);
    } finally {
      setSubmitting(false);
    }
  }

  async function handleCompute() {
    setComputing(true);
    try {
      await api(`/admin/motivational-cards/${userId}/${year}/${month}/compute`, { method: "POST" });
      toast.success("МК пересчитана");
    } catch {
      toast.error("Ошибка при пересчёте МК");
    } finally {
      setComputing(false);
    }
  }

  const selectedRule = commissionRules?.find((r) => String(r.id) === commissionRuleId);
  const selectedTarget = teamTargets?.find((t) => String(t.id) === teamTargetId);
  const monthName = MONTHS_RU[Number(month) - 1] ?? "";

  if (isLoading) {
    return (
      <div className="p-6 animate-pulse space-y-4">
        {[1, 2, 3].map((i) => (
          <div key={i} className="card h-24 bg-gray-100 dark:bg-gray-700 rounded-2xl" />
        ))}
      </div>
    );
  }

  return (
    <RoleGate allowed={["admin", "director"]}>
      <PageHeader
        title={`${plan?.user_name ?? `Пользователь #${userId}`} · ${monthName} ${year}`}
        actions={
          <div className="flex items-center gap-3">
            {plan && (
              <span className={`inline-flex items-center rounded-full px-3 py-1 text-xs font-medium ${STATUS_BADGE[plan.status] ?? ""}`}>
                {STATUS_LABEL[plan.status] ?? plan.status}
              </span>
            )}
            <button
              onClick={handleCompute}
              className="btn-secondary text-sm"
              disabled={computing}
            >
              <i className={`bi bi-calculator mr-1 ${computing ? "animate-spin" : ""}`} />
              {computing ? "Рассчитываем..." : "Пересчитать МК"}
            </button>
          </div>
        }
      />

      <div className="p-6">
        <form onSubmit={handleSubmit} className="space-y-4 max-w-2xl">

          {/* Базовый оклад */}
          <div className="card p-5">
            <h3 className="text-h5 mb-4">Базовый оклад</h3>
            <div className="space-y-3">
              <AmountWithConversion
                label="Сумма *"
                value={baseSalary}
                currency={baseCurrency}
                onValueChange={setBaseSalary}
                onCurrencyChange={setBaseCurrency}
                required
              />
              <div>
                <label className="label">Примечание</label>
                <textarea
                  className="input"
                  rows={2}
                  value={baseSalaryNote}
                  onChange={(e) => setBaseSalaryNote(e.target.value)}
                  placeholder="Выплачивается в следующем месяце за текущим"
                />
              </div>
            </div>
          </div>

          {/* Правило комиссии */}
          <div className="card p-5">
            <h3 className="text-h5 mb-4">Правило комиссии</h3>
            <div className="flex gap-2 items-end">
              <div className="flex-1">
                <label className="label">Правило *</label>
                <select
                  className="input"
                  value={commissionRuleId}
                  onChange={(e) => setCommissionRuleId(e.target.value)}
                >
                  <option value="">— не выбрано —</option>
                  {commissionRules?.map((r) => (
                    <option key={r.id} value={r.id}>{r.name}</option>
                  ))}
                </select>
              </div>
              <button
                type="button"
                onClick={() => setRuleModalOpen(true)}
                className="btn-secondary text-sm shrink-0"
              >
                + Создать правило
              </button>
            </div>
            {selectedRule && (
              <InfoPanel>
                <div className="font-medium text-gray-800 dark:text-gray-200">
                  {selectedRule.rate_pct}% от новых поступлений
                </div>
                <div className="text-xs text-gray-500 dark:text-gray-400 mt-1">
                  {selectedRule.first_payment_only ? "Только первый платёж" : "Все платежи"}
                  {selectedRule.requires_signed_contract ? " · Требуется договор" : ""}
                  {" · "}
                  {selectedRule.payout_timing === "immediately" ? "Сразу" : "Конец периода"}
                </div>
              </InfoPanel>
            )}
          </div>

          {/* Личный план */}
          <div className="card p-5">
            <h3 className="text-h5 mb-4">Личный план</h3>
            <div className="space-y-3">
              <AmountWithConversion
                label="План по новым поступлениям *"
                value={personalPlan}
                currency={personalCurrency}
                onValueChange={setPersonalPlan}
                onCurrencyChange={setPersonalCurrency}
                required
              />
              <div>
                <label className="label">FTM план (кол-во встреч)</label>
                <input
                  type="number"
                  className="input"
                  value={ftmPlan}
                  onChange={(e) => setFtmPlan(e.target.value)}
                  min={0}
                />
              </div>
            </div>
          </div>

          {/* Командный план */}
          <div className="card p-5">
            <h3 className="text-h5 mb-4">Командный план</h3>
            <div className="flex gap-2 items-end">
              <div className="flex-1">
                <label className="label">Цель команды</label>
                <select
                  className="input"
                  value={teamTargetId}
                  onChange={(e) => setTeamTargetId(e.target.value)}
                >
                  <option value="">— не выбрано —</option>
                  {teamTargets?.map((t) => (
                    <option key={t.id} value={t.id}>
                      {MONTHS_RU[t.month - 1]} {t.year} — {formatCurrency(t.target_amount, t.target_currency)}
                    </option>
                  ))}
                </select>
              </div>
              <button
                type="button"
                onClick={() => setTargetModalOpen(true)}
                className="btn-secondary text-sm shrink-0"
              >
                + Создать цель
              </button>
            </div>
            {selectedTarget && (
              <InfoPanel>
                <div className="text-gray-700 dark:text-gray-300 tabular-nums">
                  Пул:{" "}
                  <span className="font-semibold">
                    {formatCurrency(selectedTarget.bonus_pool_amount, selectedTarget.bonus_pool_currency)}
                  </span>
                </div>
                <div className="text-xs text-gray-500 dark:text-gray-400 mt-1">
                  Порог {selectedTarget.min_threshold_pct}% · Пропорция {selectedTarget.proportional_pct}%/{selectedTarget.equal_pct}%
                </div>
              </InfoPanel>
            )}
          </div>

          {/* Служебная информация */}
          <div className="card p-5">
            <h3 className="text-h5 mb-4">Служебная информация</h3>
            <div className="space-y-3">
              <div>
                <label className="label">Руководитель</label>
                <UserSelect
                  value={supervisorId}
                  onChange={setSupervisorId}
                  placeholder="— не выбран —"
                />
              </div>
              <div>
                <label className="label">Статус</label>
                <select
                  className="input"
                  value={status}
                  onChange={(e) => setStatus(e.target.value as "draft" | "finalized" | "paid")}
                >
                  <option value="draft">Черновик</option>
                  <option value="finalized">Финализирован</option>
                  <option value="paid">Выплачен</option>
                </select>
              </div>
            </div>
          </div>

          {error && (
            <div className="text-sm text-danger bg-danger/10 px-3 py-2 rounded">
              {error}
            </div>
          )}

          <div className="flex justify-end gap-2 pt-2">
            <a href="/admin/salary-plans" className="btn-ghost">Отмена</a>
            <button type="submit" className="btn-primary" disabled={submitting}>
              {submitting ? "Сохранение..." : "Сохранить"}
            </button>
          </div>
        </form>
      </div>

      <Modal
        open={ruleModalOpen}
        title="Создать правило комиссии"
        onClose={() => setRuleModalOpen(false)}
        footer={
          <>
            <button onClick={() => setRuleModalOpen(false)} className="btn-ghost">Отмена</button>
            <button form="commission-rule-form" type="submit" className="btn-primary">Создать</button>
          </>
        }
      >
        <CommissionRuleForm
          inModal
          onSaved={() => {
            setRuleModalOpen(false);
            void mutateRules();
            toast.success("Правило создано");
          }}
        />
      </Modal>

      <Modal
        open={targetModalOpen}
        title="Создать командную цель"
        onClose={() => setTargetModalOpen(false)}
        footer={
          <>
            <button onClick={() => setTargetModalOpen(false)} className="btn-ghost">Отмена</button>
            <button form="team-target-form" type="submit" className="btn-primary">Создать</button>
          </>
        }
      >
        <TeamTargetForm
          inModal
          onSaved={() => {
            setTargetModalOpen(false);
            void mutateTargets();
            toast.success("Цель создана");
          }}
        />
      </Modal>
    </RoleGate>
  );
}
