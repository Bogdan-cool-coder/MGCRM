"use client";

interface Props {
  value: string;
  onChange: (v: string) => void;
  className?: string;
  label?: string;
  disabled?: boolean;
}

const CURRENCIES = [
  { value: "RUB", label: "RUB (₽)" },
  { value: "USD", label: "USD ($)" },
  { value: "EUR", label: "EUR (€)" },
  { value: "KZT", label: "KZT (₸)" },
  { value: "UZS", label: "UZS (сум)" },
  { value: "AED", label: "AED (د.إ)" },
];

export function CurrencySelect({ value, onChange, className = "input", label, disabled }: Props) {
  return (
    <>
      {label && <label className="label">{label}</label>}
      <select
        className={className}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        disabled={disabled}
      >
        {CURRENCIES.map((c) => (
          <option key={c.value} value={c.value}>{c.label}</option>
        ))}
      </select>
    </>
  );
}

export { CURRENCIES };
