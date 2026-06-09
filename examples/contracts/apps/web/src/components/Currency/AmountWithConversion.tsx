"use client";

import { useEffect, useState } from "react";
import useSWR from "swr";
import { fetcher } from "@/lib/api";
import { CurrencySelect } from "./CurrencySelect";
import type { CurrencyConversion } from "@/lib/types";
import { formatCurrency } from "@/lib/format";

interface Props {
  value: number | "";
  currency: string;
  onValueChange: (v: number | "") => void;
  onCurrencyChange: (v: string) => void;
  label?: string;
  required?: boolean;
  error?: string;
  baseCurrency?: string;
}

export function AmountWithConversion({
  value,
  currency,
  onValueChange,
  onCurrencyChange,
  label,
  required,
  error,
  baseCurrency = "UZS",
}: Props) {
  const [debouncedAmount, setDebouncedAmount] = useState<number | "">(value);

  useEffect(() => {
    const t = setTimeout(() => setDebouncedAmount(value), 300);
    return () => clearTimeout(t);
  }, [value]);

  const shouldConvert = debouncedAmount !== "" && debouncedAmount !== 0 && currency !== baseCurrency;

  const swrKey = shouldConvert
    ? `/currency-rates/convert?amount=${debouncedAmount}&from=${currency}&to=${baseCurrency}&date=today`
    : null;

  const { data: conv } = useSWR<CurrencyConversion>(swrKey, fetcher);

  return (
    <div>
      {label && (
        <label className="label">
          {label}
          {required && <span className="text-danger ml-0.5">*</span>}
        </label>
      )}
      <div className="flex gap-2">
        <input
          type="number"
          className={`input flex-1 ${error ? "border-danger" : ""}`}
          value={value}
          onChange={(e) => {
            const v = e.target.value;
            onValueChange(v === "" ? "" : Number(v));
          }}
          min={0}
          step={0.01}
          required={required}
        />
        <CurrencySelect value={currency} onChange={onCurrencyChange} className="input w-36" />
      </div>
      {conv && currency !== baseCurrency && (
        <p className="text-xs text-gray-400 mt-1">
          ≈ {formatCurrency(conv.converted_amount, baseCurrency)} (по курсу {conv.rate} на сегодня)
        </p>
      )}
      {shouldConvert && !conv && (
        <p className="text-xs text-gray-300 mt-1">нет курса</p>
      )}
      {error && <p className="text-xs text-danger mt-1">{error}</p>}
    </div>
  );
}
