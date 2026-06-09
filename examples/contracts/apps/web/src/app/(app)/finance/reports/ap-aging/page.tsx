import { redirect } from "next/navigation";

/**
 * MARATHON-V2-FIXES #12: AP aging объединён с AR aging в раздел «Задолженность».
 * Редирект на новую страницу с вкладкой «Кредиторская».
 */
export default function ApAgingPage() {
  redirect("/finance/reports/debt?tab=ap");
}
