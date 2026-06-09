import { redirect } from "next/navigation";

/**
 * MARATHON-V2-FIXES #12: AR aging объединён с AP aging в раздел «Задолженность».
 * Редирект на новую страницу с вкладкой «Дебиторская».
 */
export default function ArAgingPage() {
  redirect("/finance/reports/debt?tab=ar");
}
