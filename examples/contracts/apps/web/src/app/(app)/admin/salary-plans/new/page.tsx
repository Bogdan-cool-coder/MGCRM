"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import useSWR from "swr";
import { api, fetcher } from "@/lib/api";
import { PageHeader } from "@/components/PageHeader";
import { RoleGate } from "@/components/RoleGate";
import { UserSelect } from "@/components/UserSelect";
import { AmountWithConversion } from "@/components/Currency/AmountWithConversion";
import { Modal } from "@/components/Modal";
import { useToast } from "@/components/ui/Toast";
import { CommissionRuleForm } from "@/components/SalaryPlans/CommissionRuleForm";
import { TeamTargetForm } from "@/components/SalaryPlans/TeamTargetForm";
import { formatCurrency } from "@/lib/format";
import type { CommissionRule, TeamTarget } from "@/lib/types";

const MONTHS_RU = [
  "Январь", "Февраль", "Март", "Апрель", "Май", "Июнь",
  "Июль", "Август", "Сентябрь", "Октябрь", "Ноябрь", "Декабрь",
];

function generateYears() {
  const now = new Date();
  const years: number[] = [];
  for (let y = now.getFullYear() + 1; y >= 2024; y--) {
    years.push(y);
  }
  return years;
}

export default function NewSalaryPlanPage() {
  const router = useRouter();
  const now = new Date();

  const { data: commissionRules, mutate: mutateRules } = useSWR<CommissionRule[]>(
    "/admin/commission-rules?active=true",
    fetcher,
  );
  const { data: teamTargets, mutate: mutateTargets } = useSWR<TeamTarget[]>(
    "/admin/team-targets?active=true",
    fetcher,
  );

  // Form state — без предустановленных числовых значений
  const [userId, setUserId] = useState("");
  const [year, setYear] = useState(String(now.getFullYear()));
  const [month, setMonth] = useState(String(now.getMonth() + 1));
  const [baseSalary, setBaseSalary] = useState<number | "">("");
  const [baseCurrency, setBaseCurrency] = useState("UZS");
  const [baseSalaryNote, setBaseSalaryNote] = useState("");
  const [commissionRuleId, setCommissionRuleId] = useState("");
  const [teamTargetId, setTeamTargetId] = useState("");
  const [personalPlan, setPersonalPlan] = useState<number | "">("");
  const [personalCurrency, setPersonalCurrency] = useState("RUB");
  const [ftmPlan, setFtmPlan] = useState("");
  const [supervisorId, setSupervisorId] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [ruleModalOpen, setRuleModalOpen] = useState(false);
  const [targetModalOpen, setTargetModalOpen] = useState(false);

  const { toast } = useToast();
  const selectedRule = commissionRules?.find((r) => String(r.id) === commissionRuleId);
  const selectedTarget = teamTargets?.find((t) => String(t.id) === teamTargetId);
  const years = generateYears();

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();

    if (!userId) {
      setError("Выберите менеджера");
      return;
    }
    if (baseSalary === "") {
      setError("Укажите базовый оклад");
      return;
    }

    setSubmitting(true);
    setError(null);

    try {
      await api(`/admin/salary-plans/${userId}/${year}/${month}`, {
        method: "PUT",
        body: {
          base_salary_amount: Number(baseSalary),
          base_salary_currency: baseCurrency,
          base_salary_note: baseSalaryNote || null,
          commission_rule_id: commissionRuleId ? Number(commissionRuleId) : null,
          team_target_id: teamTargetId ? Number(teamTargetId) : null,
          personal_income_plan: personalPlan !== "" ? Number(personalPlan) : null,
          personal_income_currency: personalCurrency,
          ftm_plan: ftmPlan !== "" ? Number(ftmPlan) : null,
          supervisor_id: supervisorId ? Number(supervisorId) : null,
          status: "draft",
        },
      });
      toast.success("План создан");
      router.push("/admin/salary-plans");
    } catch {
      const msg = "Не удалось создать план. Проверьте данные.";
      setError(msg);
      toast.error(msg);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <RoleGate allowed={["admin", "director"]}>
      <PageHeader
        title="Новый план зарплаты"
        actions={
          <a href="/admin/salary-plans" className="btn-ghost text-sm">
            <i className="bi bi-arrow-left mr-1" />
            Назад
          </a>
        }
      />

      <div className="p-6">
        <form onSubmit={handleSubmit} className="space-y-4 max-w-2xl">

          {/* Менеджер и период */}
          <div className="card p-5">
            <h3 className="text-h5 mb-4">Менеджер и период</h3>
            <div className="space-y-3">
              <div>
                <label className="label">Менеджер *</label>
                <UserSelect
                  value={userId}
                  onChange={setUserId}
                  placeholder="— выберите менеджера —"
                />
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="label">Год *</label>
                  <select
                    className="input"
                    value={year}
                    onChange={(e) => setYear(e.target.value)}
                  >
                    {years.map((y) => (
                      <option key={y} value={y}>{y}</option>
                    ))}
                  </select>
                </div>
                <div>
                  <label className="label">Месяц *</label>
                  <select
                    className="input"
                    value={month}
                    onChange={(e) => setMonth(e.target.value)}
                  >
                    {MONTHS_RU.map((m, i) => (
                      <option key={i + 1} value={i + 1}>{m}</option>
                    ))}
                  </select>
                </div>
              </div>
            </div>
          </div>

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
                <label className="label">Правило</label>
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
              <div className="mt-3 rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50 p-3 text-sm">
                <div className="font-medium text-gray-800 dark:text-gray-200">
                  {selectedRule.rate_pct}% от новых поступлений
                </div>
                <div className="text-xs text-gray-500 dark:text-gray-400 mt-1">
                  {selectedRule.first_payment_only ? "Только первый платёж" : "Все платежи"}
                  {selectedRule.requires_signed_contract ? " · Требуется договор" : ""}
                  {" · "}
                  {selectedRule.payout_timing === "immediately" ? "Сразу" : "Конец периода"}
                </div>
              </div>
            )}
          </div>

          {/* Личный план */}
          <div className="card p-5">
            <h3 className="text-h5 mb-4">Личный план</h3>
            <div className="space-y-3">
              <AmountWithConversion
                label="План по новым поступлениям"
                value={personalPlan}
                currency={personalCurrency}
                onValueChange={setPersonalPlan}
                onCurrencyChange={setPersonalCurrency}
              />
              <div>
                <label className="label">FTM план (кол-во встреч)</label>
                <input
                  type="number"
                  className="input"
                  value={ftmPlan}
                  onChange={(e) => setFtmPlan(e.target.value)}
                  min={0}
                  placeholder="5"
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
              <div className="mt-3 rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50 p-3 text-sm">
                <div className="text-gray-700 dark:text-gray-300 tabular-nums">
                  Пул:{" "}
                  <span className="font-semibold">
                    {formatCurrency(selectedTarget.bonus_pool_amount, selectedTarget.bonus_pool_currency)}
                  </span>
                </div>
                <div className="text-xs text-gray-500 dark:text-gray-400 mt-1">
                  Порог {selectedTarget.min_threshold_pct}% · Пропорция {selectedTarget.proportional_pct}%/{selectedTarget.equal_pct}%
                </div>
              </div>
            )}
          </div>

          {/* Служебная информация */}
          <div className="card p-5">
            <h3 className="text-h5 mb-4">Служебная информация</h3>
            <div>
              <label className="label">Руководитель</label>
              <UserSelect
                value={supervisorId}
                onChange={setSupervisorId}
                placeholder="— не выбран —"
              />
            </div>
          </div>

          {error && <p className="text-sm text-danger">{error}</p>}

          <div className="flex justify-end gap-2 pt-2">
            <a href="/admin/salary-plans" className="btn-ghost">Отмена</a>
            <button type="submit" className="btn-primary" disabled={submitting}>
              {submitting ? "Создание..." : "Создать план"}
            </button>
          </div>
        </form>
      </div>

      {/* Commission Rule Modal */}
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

      {/* Team Target Modal */}
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
