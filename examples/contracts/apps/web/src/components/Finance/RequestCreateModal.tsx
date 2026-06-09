"use client";

import { useState } from "react";
import useSWR, { mutate } from "swr";
import { Modal } from "@/components/Modal";
import { UserSelect } from "@/components/UserSelect";
import { DatePicker } from "@/components/ui/DatePicker";
import { api, fetcher } from "@/lib/api";
import type { FinLegalEntity, FinCashflowCategory, Counterparty } from "@/lib/types";

const MONTH_NAMES = [
  "Январь", "Февраль", "Март", "Апрель", "Май", "Июнь",
  "Июль", "Август", "Сентябрь", "Октябрь", "Ноябрь", "Декабрь",
];

const REQUEST_TYPE_OPTIONS = [
  { value: "salary", label: "Зарплата" },
  { value: "commission", label: "Комиссия" },
  { value: "expense_reimbursement", label: "Возмещение расходов" },
  { value: "payment", label: "Платёж" },
];

const CURRENCIES = ["KZT", "RUB", "USD", "EUR", "UZS"];

interface Props {
  open: boolean;
  onClose: () => void;
}

export function RequestCreateModal({ open, onClose }: Props) {
  const [requestType, setRequestType] = useState("");
  const [legalEntityId, setLegalEntityId] = useState("");
  const [amount, setAmount] = useState("");
  const [currency, setCurrency] = useState("KZT");
  const [desiredDate, setDesiredDate] = useState("");
  const [description, setDescription] = useState("");
  const [payeeUserId, setPayeeUserId] = useState("");
  const [counterpartyId, setCounterpartyId] = useState("");
  const [cashflowCategoryId, setCashflowCategoryId] = useState("");
  const [periodYear, setPeriodYear] = useState(String(new Date().getFullYear()));
  const [periodMonth, setPeriodMonth] = useState(String(new Date().getMonth() + 1));
  const [error, setError] = useState("");
  const [submitting, setSubmitting] = useState(false);

  const { data: legalEntities } = useSWR<FinLegalEntity[]>("/api/finance/legal-entities", fetcher);
  const { data: categories } = useSWR<FinCashflowCategory[]>("/api/finance/categories", fetcher);
  const { data: counterparties } = useSWR<Counterparty[]>("/api/counterparties", fetcher);

  const showPayee = requestType === "salary" || requestType === "commission";
  const showCounterparty = requestType === "expense_reimbursement" || requestType === "payment";
  const showCategory = requestType === "payment";
  const showPeriod = requestType === "salary" || requestType === "commission";

  function reset() {
    setRequestType("");
    setLegalEntityId("");
    setAmount("");
    setCurrency("KZT");
    setDesiredDate("");
    setDescription("");
    setPayeeUserId("");
    setCounterpartyId("");
    setCashflowCategoryId("");
    setPeriodYear(String(new Date().getFullYear()));
    setPeriodMonth(String(new Date().getMonth() + 1));
    setError("");
  }

  function handleClose() {
    reset();
    onClose();
  }

  async function handleSubmit() {
    setError("");
    if (!requestType || !legalEntityId || !amount || parseFloat(amount) <= 0 || !currency) {
      setError("Укажи тип, юрлицо и сумму");
      return;
    }

    setSubmitting(true);
    try {
      await api("/api/finance/requests", {
        method: "POST",
        body: {
          request_type: requestType,
          legal_entity_id: parseInt(legalEntityId),
          amount: parseFloat(amount),
          currency,
          desired_date: desiredDate || null,
          description: description || null,
          payee_user_id: showPayee && payeeUserId ? parseInt(payeeUserId) : null,
          counterparty_company_id: showCounterparty && counterpartyId ? parseInt(counterpartyId) : null,
          cashflow_category_id: showCategory && cashflowCategoryId ? parseInt(cashflowCategoryId) : null,
          period_year: showPeriod && periodYear ? parseInt(periodYear) : null,
          period_month: showPeriod && periodMonth ? parseInt(periodMonth) : null,
        },
      });
      await mutate("/api/finance/requests");
      handleClose();
    } catch (err: unknown) {
      if (err instanceof Error) {
        setError(err.message || "Не удалось создать заявку");
      } else {
        setError("Не удалось создать заявку");
      }
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal
      open={open}
      title="Новая заявка"
      onClose={handleClose}
      width="lg"
      footer={
        <>
          <button type="button" className="btn-ghost" onClick={handleClose}>
            Отмена
          </button>
          <button
            type="button"
            className="btn-primary"
            disabled={submitting}
            onClick={handleSubmit}
          >
            {submitting ? "Создание..." : "Создать заявку"}
          </button>
        </>
      }
    >
      <div className="space-y-4">
        {error && (
          <div className="text-danger text-sm p-3 bg-red-50 dark:bg-red-900/20 rounded">
            {error}
          </div>
        )}

        <div className="border rounded-lg p-4 space-y-4 dark:border-gray-700">
          {/* Тип заявки */}
          <div>
            <label className="label">Тип заявки *</label>
            <select
              className="input"
              value={requestType}
              onChange={(e) => {
                setRequestType(e.target.value);
                setPayeeUserId("");
                setCounterpartyId("");
                setCashflowCategoryId("");
              }}
            >
              <option value="">Выбери тип</option>
              {REQUEST_TYPE_OPTIONS.map((o) => (
                <option key={o.value} value={o.value}>{o.label}</option>
              ))}
            </select>
          </div>

          {/* Юрлицо */}
          <div>
            <label className="label">Юрлицо *</label>
            <select
              className="input"
              value={legalEntityId}
              onChange={(e) => setLegalEntityId(e.target.value)}
            >
              <option value="">Выбери юрлицо</option>
              {(legalEntities ?? []).map((le) => (
                <option key={le.id} value={String(le.id)}>{le.name}</option>
              ))}
            </select>
          </div>

          {/* Сумма + валюта */}
          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="label">Сумма *</label>
              <input
                type="number"
                className="input"
                min={0}
                step="0.01"
                value={amount}
                onChange={(e) => setAmount(e.target.value)}
                placeholder="0.00"
              />
            </div>
            <div>
              <label className="label">Валюта *</label>
              <select
                className="input"
                value={currency}
                onChange={(e) => setCurrency(e.target.value)}
              >
                {CURRENCIES.map((c) => (
                  <option key={c} value={c}>{c}</option>
                ))}
              </select>
            </div>
          </div>

          {/* Желаемая дата */}
          <div>
            <DatePicker
              label="Желаемая дата выплаты"
              value={desiredDate}
              onChange={(v) => setDesiredDate(v ?? "")}
            />
          </div>

          {/* Описание */}
          <div>
            <label className="label">Описание / назначение</label>
            <textarea
              className="input"
              rows={3}
              placeholder="Укажи назначение платежа или обоснование…"
              value={description}
              onChange={(e) => setDescription(e.target.value)}
            />
          </div>
        </div>

        {/* Условные поля */}
        {(showPayee || showCounterparty || showCategory || showPeriod) && (
          <div className="border rounded-lg p-4 space-y-4 dark:border-gray-700">
            {showPayee && (
              <div>
                <label className="label">Сотрудник-получатель</label>
                <UserSelect
                  value={payeeUserId}
                  onChange={setPayeeUserId}
                  placeholder="Выбери сотрудника"
                />
              </div>
            )}

            {showCounterparty && (
              <div>
                <label className="label">Контрагент</label>
                <select
                  className="input"
                  value={counterpartyId}
                  onChange={(e) => setCounterpartyId(e.target.value)}
                >
                  <option value="">Выбери контрагента</option>
                  {(counterparties ?? []).map((c) => (
                    <option key={c.id} value={String(c.id)}>{c.name}</option>
                  ))}
                </select>
              </div>
            )}

            {showCategory && (
              <div>
                <label className="label">Статья ДДС</label>
                <select
                  className="input"
                  value={cashflowCategoryId}
                  onChange={(e) => setCashflowCategoryId(e.target.value)}
                >
                  <option value="">Все типы</option>
                  {(categories ?? []).map((cat) => (
                    <option key={cat.id} value={String(cat.id)}>{cat.name}</option>
                  ))}
                </select>
              </div>
            )}

            {showPeriod && (
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="label">Период (год)</label>
                  <input
                    type="number"
                    className="input"
                    value={periodYear}
                    onChange={(e) => setPeriodYear(e.target.value)}
                    min={2000}
                    max={2100}
                  />
                </div>
                <div>
                  <label className="label">Период (месяц)</label>
                  <select
                    className="input"
                    value={periodMonth}
                    onChange={(e) => setPeriodMonth(e.target.value)}
                  >
                    {MONTH_NAMES.map((name, idx) => (
                      <option key={idx + 1} value={String(idx + 1)}>{name}</option>
                    ))}
                  </select>
                </div>
              </div>
            )}
          </div>
        )}
      </div>
    </Modal>
  );
}
