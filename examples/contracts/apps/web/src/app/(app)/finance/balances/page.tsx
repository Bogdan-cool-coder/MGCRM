import { redirect } from "next/navigation";

/**
 * MARATHON-V2-FIXES #10: раздел «Остатки» объединён с «Счета» → «Счета и Баланс».
 * Редирект для обратной совместимости со старыми ссылками.
 */
export default function BalancesPage() {
  redirect("/finance/accounts");
}
