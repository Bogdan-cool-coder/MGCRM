"use client";

import { useState } from "react";
import { PageHeader } from "@/components/PageHeader";
import { CurrencyRatesTable } from "@/components/Currency/CurrencyRatesTable";
import { CurrencyRateHistoryTable } from "@/components/Currency/CurrencyRateHistoryTable";
import { ManualRateModal } from "@/components/Currency/ManualRateModal";
import { BaseCurrencyCard } from "@/components/Currency/BaseCurrencyCard";
import { RoleGate } from "@/components/RoleGate";
import { useToast } from "@/components/ui/Toast";
import { api, ApiError } from "@/lib/api";
import { useSWRConfig } from "swr";
import type { AdminCurrencyRefreshResponse } from "@/lib/types";

// Поддерживаемые валюты для MultiSelect
const CURRENCIES = ["RUB", "USD", "EUR", "KZT", "UZS", "AED"] as const;

export default function CurrencyRatesPage() {
  const { mutate } = useSWRConfig();
  const { toast } = useToast();
  const [addOpen, setAddOpen] = useState(false);
  const [refreshing, setRefreshing] = useState(false);
  // MultiSelect выбранных валют (пустой = все)
  const [selectedCurrencies, setSelectedCurrencies] = useState<string[]>([]);

  function toggleCurrency(cur: string) {
    setSelectedCurrencies((prev) =>
      prev.includes(cur) ? prev.filter((c) => c !== cur) : [...prev, cur],
    );
  }

  async function handleRefresh() {
    setRefreshing(true);
    try {
      const res = await api<AdminCurrencyRefreshResponse>("/admin/currency-rates/refresh", {
        method: "POST",
      });
      void mutate("/currency-rates");
      if (res.reason) {
        const fallback =
          res.reason === "no_api_key"
            ? "Не настроен ключ EXCHANGE_RATE_API_KEY — обратитесь к администратору"
            : "Не удалось загрузить курсы автоматически";
        toast.warning(res.message || fallback);
      } else if (res.updated_pairs > 0) {
        toast.success(`Загружено ${res.updated_pairs} курсов`);
      } else {
        toast.info("Курсы уже актуальны");
      }
    } catch (err) {
      const text =
        err instanceof ApiError && typeof err.detail === "object" && err.detail !== null && "detail" in err.detail
          ? String((err.detail as Record<string, unknown>)["detail"])
          : "Не удалось обновить курсы. Попробуйте ещё раз.";
      toast.error(text);
    } finally {
      setRefreshing(false);
    }
  }

  return (
    <RoleGate allowed={["admin", "director"]}>
      <PageHeader
        title="Курсы валют"
        actions={
          <div className="flex items-center gap-2">
            <button
              onClick={() => setAddOpen(true)}
              className="btn-primary text-sm"
            >
              <i className="bi bi-plus-lg mr-1" />
              Добавить курс
            </button>
            <button
              onClick={handleRefresh}
              className="btn-secondary text-sm"
              disabled={refreshing}
            >
              <i className={`bi bi-arrow-repeat mr-1 ${refreshing ? "animate-spin" : ""}`} />
              {refreshing ? "Обновляем..." : "Загрузить курсы"}
            </button>
          </div>
        }
      />

      <div className="p-6 space-y-4">
        {/* Базовая валюта группы — первый блок */}
        <BaseCurrencyCard />

        {/* Фильтр по валютам */}
        <div className="card lift p-4">
          <div className="flex items-center gap-3 flex-wrap">
            <span className="text-sm text-gray-600 dark:text-gray-400 font-medium shrink-0">
              Показать пары с валютами:
            </span>
            <div className="flex items-center gap-2 flex-wrap">
              {CURRENCIES.map((cur) => (
                <button
                  key={cur}
                  type="button"
                  onClick={() => toggleCurrency(cur)}
                  className={
                    "px-3 py-1 rounded-full text-xs font-semibold border transition-colors " +
                    (selectedCurrencies.includes(cur)
                      ? "bg-primary text-white border-primary"
                      : "bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-400 border-gray-300 dark:border-gray-600 hover:border-primary hover:text-primary")
                  }
                >
                  {cur}
                </button>
              ))}
              {selectedCurrencies.length > 0 && (
                <button
                  type="button"
                  onClick={() => setSelectedCurrencies([])}
                  className="text-xs text-gray-400 hover:text-danger transition-colors"
                >
                  <i className="bi bi-x-lg mr-0.5" />
                  Сбросить
                </button>
              )}
            </div>
            {selectedCurrencies.length === 0 && (
              <span className="text-xs text-gray-400">Все валюты</span>
            )}
          </div>

          <div className="mt-3 flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
            <i className="bi bi-clock-history" />
            <span>Автообновление из API: каждый день в 00:01 МСК</span>
          </div>
        </div>

        <CurrencyRatesTable
          onRefreshed={() => void mutate("/currency-rates")}
          currencyFilter={selectedCurrencies}
        />
        <CurrencyRateHistoryTable />
      </div>

      <ManualRateModal
        open={addOpen}
        onClose={() => setAddOpen(false)}
        onSaved={() => {
          void mutate("/currency-rates");
        }}
      />
    </RoleGate>
  );
}
