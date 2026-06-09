/**
 * ISO 3166-1 alpha-2 код страны → emoji-флаг (через regional indicator symbols).
 * Пустая строка, если код некорректный (не 2 латинских буквы).
 */
export function flagEmoji(iso2: string | null | undefined): string {
  if (!iso2) return "";
  const code = iso2.trim().toUpperCase();
  if (!/^[A-Z]{2}$/.test(code)) return "";
  const base = 0x1f1e6; // 'A' regional indicator
  const a = base + (code.charCodeAt(0) - 65);
  const b = base + (code.charCodeAt(1) - 65);
  return String.fromCodePoint(a, b);
}
