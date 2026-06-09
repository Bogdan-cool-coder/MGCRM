// Хелперы модуля «Финансы» (Ф0). Конвертация period↔диапазон дат и т.п.

/** "YYYY-MM" → { date_from: "YYYY-MM-01", date_to: "YYYY-MM-<последний день>" }. */
export function periodToRange(period: string): { date_from: string; date_to: string } {
  const [yStr, mStr] = period.split("-");
  const year = Number(yStr);
  const month = Number(mStr); // 1..12
  const lastDay = new Date(year, month, 0).getDate(); // день 0 след. месяца = последний день текущего
  const mm = String(month).padStart(2, "0");
  const dd = String(lastDay).padStart(2, "0");
  return { date_from: `${year}-${mm}-01`, date_to: `${year}-${mm}-${dd}` };
}
