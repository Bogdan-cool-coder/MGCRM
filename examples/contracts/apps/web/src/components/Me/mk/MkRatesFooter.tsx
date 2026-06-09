"use client";

interface Props {
  rates: Record<string, number>;
  rateDate?: string | null;
}

export function MkRatesFooter({ rates, rateDate }: Props) {
  const entries = Object.entries(rates);
  if (entries.length === 0) return null;

  const label = rateDate
    ? `Курсы на ${new Date(rateDate).toLocaleDateString("ru-RU", { day: "2-digit", month: "2-digit", year: "numeric" })}`
    : "Курсы валют";

  return (
    <div className="flex flex-wrap gap-2 items-center mt-3 pt-3 border-t border-gray-100 dark:border-gray-700">
      <span className="text-xs text-gray-400">{label}:</span>
      {entries.map(([pair, rate]) => (
        <span
          key={pair}
          className="badge bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 text-xs"
        >
          {pair}: {(rate ?? 0).toLocaleString("ru-RU")}
        </span>
      ))}
    </div>
  );
}
